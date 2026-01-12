<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','0');

require_once __DIR__ . '/../config/database.php';
if (!isset($pdo)) { echo json_encode(['success'=>false,'error'=>'pdo_not_initialized']); exit; }

$tz = new DateTimeZone('Europe/Paris');

$startDate  = $_GET['start'] ?? date('Y-m-d');
$days       = max(1, min(14, (int)($_GET['days'] ?? 7)));
$duration   = (int)($_GET['duration_min'] ?? 0);
$leadMin    = (int)($_GET['lead_min'] ?? 180); // 3h par défaut

// Variante : si tu préfères passer un tag de devis
$tag = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
if ($tag > 0 && $duration === 0) {
  $st = $pdo->prepare("SELECT duration_min FROM quotes WHERE id=:id");
  $st->execute([':id'=>$tag]);
  $row = $st->fetch();
  if ($row && (int)$row['duration_min'] > 0) $duration = (int)$row['duration_min'];
}

if ($duration <= 0) {
  echo json_encode(['success'=>false,'error'=>'missing_duration']); exit;
}

try {
  // Horaires hebdo
  $ho = $pdo->query("SELECT weekday, morning_start, morning_end, afternoon_start, afternoon_end, slot_step_min FROM opening_hours");
  $openByDay = [];
  foreach ($ho as $h) $openByDay[(int)$h['weekday']] = $h;

  // Promos
  $pr = $pdo->query("SELECT weekday, start_time, end_time, discount_pct FROM promo_rules");
  $promoByDay = [];
  foreach ($pr as $p) $promoByDay[(int)$p['weekday']][] = $p;

  $endDate = (new DateTimeImmutable($startDate, $tz))->modify("+{$days} day")->format('Y-m-d');

  // RDV existants dans l’intervalle
  $ap = $pdo->prepare("
    SELECT start_datetime, end_datetime
    FROM appointments
    WHERE status='booked'
      AND start_datetime >= :d1
      AND start_datetime <  :d2
  ");
  $ap->execute([':d1'=>"$startDate 00:00:00", ':d2'=>"$endDate 00:00:00"]);
  $bookedByDate = [];
  foreach ($ap as $a) {
    $d = substr($a['start_datetime'],0,10);
    $bookedByDate[$d][] = ['start'=>$a['start_datetime'], 'end'=>$a['end_datetime']];
  }

  // Blocs d'indisponibilité Helix (verrouillage de créneaux)
  // On récupère tous les blocs non "open" qui chevauchent l'intervalle demandé
  $d1Bound = "$startDate 00:00:00";
  $d2Bound = "$endDate 00:00:00"; // borne exclusive
  $hb = $pdo->prepare(
    "SELECT start_datetime, end_datetime, type, notes
       FROM helix_availability_blocks
      WHERE type <> 'open'
        AND start_datetime < :d2
        AND end_datetime   > :d1"
  );
  $hb->execute([':d1'=>$d1Bound, ':d2'=>$d2Bound]);
  $helixBlocks = $hb->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $now = new DateTimeImmutable('now', $tz);
  $leadLimit = $now->modify("+{$leadMin} minutes");

  $out = [];

  for ($i=0; $i<$days; $i++) {
    $date = (new DateTimeImmutable($startDate, $tz))->modify("+{$i} day");
    $dateStr = $date->format('Y-m-d');
    $w = (int)$date->format('w'); // 0..6

    $cfg = $openByDay[$w] ?? null;
    if (!$cfg) { $out[] = ['date'=>$dateStr,'slots'=>[]]; continue; }

    $slots = [];
    foreach ([['morning_start','morning_end'], ['afternoon_start','afternoon_end']] as [$sKey,$eKey]) {
      if (!$cfg[$sKey] || !$cfg[$eKey]) continue;
      $step = (int)$cfg['slot_step_min'];

      $openS = new DateTimeImmutable("$dateStr {$cfg[$sKey]}", $tz);
      $openE = new DateTimeImmutable("$dateStr {$cfg[$eKey]}", $tz);

      for ($t=$openS; $t <= $openE; $t = $t->modify("+{$step} minutes")) {
        $slotStart = $t;
        $slotEnd   = $slotStart->modify("+{$duration} minutes");
        if ($slotEnd > $openE) break;               // sort du cadre
        if ($slotStart < $leadLimit) continue;      // lead time

        // chevauchement RDV existants ?
        $conflict = false;
        foreach ($bookedByDate[$dateStr] ?? [] as $rdv) {
          $rdvS = new DateTimeImmutable($rdv['start'], $tz);
          $rdvE = new DateTimeImmutable($rdv['end'],   $tz);
          if ($slotStart < $rdvE && $slotEnd > $rdvS) { $conflict = true; break; }
        }
        if ($conflict) continue;

        // bloc Helix ? (slot masqué selon type de bloc)
        $blocked = false;
        foreach ($helixBlocks as $b) {
          $bS = new DateTimeImmutable($b['start_datetime'], $tz);
          $bE = new DateTimeImmutable($b['end_datetime'],   $tz);
          $note = $b['notes'] ?? '';

          if ($note === 'slot_toggle') {
            // Pour un bloc créé via Helix (toggle d'un créneau), on ne retire
            // que le créneau dont l'heure de début correspond exactement.
            if ($slotStart->format('Y-m-d H:i:s') === $bS->format('Y-m-d H:i:s')) {
              $blocked = true;
              break;
            }
            continue;
          }

          if ($slotStart < $bE && $slotEnd > $bS) { $blocked = true; break; }
        }
        if ($blocked) continue;

        // promo ?
        $discount = 0;
        foreach ($promoByDay[$w] ?? [] as $p) {
          $pS = new DateTimeImmutable("$dateStr {$p['start_time']}", $tz);
          $pE = new DateTimeImmutable("$dateStr {$p['end_time']}",   $tz);
          if ($slotStart >= $pS && $slotEnd <= $pE) { $discount = (int)$p['discount_pct']; break; }
        }

        $slots[] = [
          'time'     => $slotStart->format('H:i'),
          'start'    => $slotStart->format('Y-m-d H:i:s'),
          'end'      => $slotEnd->format('Y-m-d H:i:s'),
          'discount' => $discount
        ];
      }
    }
    $out[] = ['date'=>$dateStr, 'slots'=>$slots];
  }

  echo json_encode(['success'=>true, 'data'=>$out]);

} catch (Throwable $e) {
  error_log('[slots] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
}
