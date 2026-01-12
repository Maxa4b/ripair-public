<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','0');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
  echo json_encode(['success'=>false,'error'=>'pdo_not_initialized']); exit;
}

$cancelSecret = env('CANCEL_TOKEN_SECRET', $_ENV['CANCEL_TOKEN_SECRET'] ?? '');
if ($cancelSecret === '') {
  error_log('[book] missing CANCEL_TOKEN_SECRET');
  echo json_encode(['success'=>false,'error'=>'cancel_secret_missing']); exit;
}
$cancelTtlHours = (int)env('CANCEL_TOKEN_TTL_HOURS', $_ENV['CANCEL_TOKEN_TTL_HOURS'] ?? 48);
if ($cancelTtlHours <= 0) $cancelTtlHours = 48;

// Récup champs du nouveau modèle
$fields = [
  'service_label'  => trim($_POST['service_label'] ?? ''),
  'duration_min'   => (int)($_POST['duration_min'] ?? 0),
  'start_datetime' => trim($_POST['start_datetime'] ?? ''),
  'name'           => trim($_POST['name'] ?? ''),
  'email'          => trim($_POST['email'] ?? ''),
  'phone'          => trim($_POST['phone'] ?? ''),
];

// Check manquants (debug friendly)
$missing = [];
foreach ($fields as $k => $v) {
  if ($k === 'duration_min') { if ($v <= 0) $missing[] = $k; }
  else { if ($v === '') $missing[] = $k; }
}
if ($missing) {
  echo json_encode(['success'=>false,'error'=>'missing_fields','missing'=>$missing]); exit;
}

try {
  $tz = new DateTimeZone('Europe/Paris');
  $startDT = new DateTimeImmutable($fields['start_datetime'], $tz);
  $endDT   = $startDT->modify('+' . $fields['duration_min'] . ' minutes');

  $pdo->beginTransaction();

  // Empêcher les chevauchements (capacité = 1)
  $q = $pdo->prepare("
    SELECT COUNT(*) FROM appointments
    WHERE status='booked'
      AND NOT (end_datetime <= :start OR start_datetime >= :end)
    FOR UPDATE
  ");
  $q->execute([
    ':start' => $startDT->format('Y-m-d H:i:s'),
    ':end'   => $endDT->format('Y-m-d H:i:s'),
  ]);
  if ((int)$q->fetchColumn() > 0) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'slot_taken']); exit;
  }

  // Enregistrer le RDV
  $ins = $pdo->prepare("
    INSERT INTO appointments (
      service_label, duration_min, start_datetime, end_datetime,
      customer_name, customer_email, customer_phone, status
    ) VALUES (
      :lbl, :dur, :st, :en, :n, :e, :p, 'booked'
    )
  ");
  $ins->execute([
    ':lbl' => $fields['service_label'],
    ':dur' => $fields['duration_min'],
    ':st'  => $startDT->format('Y-m-d H:i:s'),
    ':en'  => $endDT->format('Y-m-d H:i:s'),
    ':n'   => $fields['name'],
    ':e'   => $fields['email'],
    ':p'   => $fields['phone'],
  ]);

  $appointmentId = (int)$pdo->lastInsertId();
  if ($appointmentId <= 0) {
    throw new RuntimeException('unable_to_fetch_insert_id');
  }

  $now = new DateTimeImmutable('now', $tz);
  $cancelCreatedAt = $now;
  $expiresFromTtl  = $now->modify('+' . $cancelTtlHours . ' hours');
  $cancelExpiresAt = $startDT < $expiresFromTtl ? $startDT : $expiresFromTtl;
  $tokenPayload = $appointmentId . '|' . $startDT->format('Y-m-d H:i:s');
  $cancelToken = hash_hmac('sha256', $tokenPayload, $cancelSecret);

  $upd = $pdo->prepare("
    UPDATE appointments
    SET cancel_token = :token,
        cancel_token_created_at = :created,
        cancel_token_expires_at = :expires
    WHERE id = :id
  ");
  $upd->execute([
    ':token'   => $cancelToken,
    ':created' => $cancelCreatedAt->format('Y-m-d H:i:s'),
    ':expires' => $cancelExpiresAt->format('Y-m-d H:i:s'),
    ':id'      => $appointmentId,
  ]);
  if ($upd->rowCount() !== 1) {
    throw new RuntimeException('cancel_token_update_failed');
  }

  $pdo->commit();
  $baseCancelUrl = env('CANCEL_URL_BASE', $_ENV['CANCEL_URL_BASE'] ?? 'https://ripair.shop/api/cancel_appointment.php');
  $separator = (strpos($baseCancelUrl, '?') === false) ? '?' : '&';
  $cancelUrl = rtrim($baseCancelUrl, '&?') . $separator . 'token=' . urlencode($cancelToken);
  echo json_encode([
    'success' => true,
    'cancel_token' => $cancelToken,
    'cancel_token_expires_at' => $cancelExpiresAt->format(DATE_ATOM),
    'cancel_url' => $cancelUrl,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[book] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
}
