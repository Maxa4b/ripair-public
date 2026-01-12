<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ob_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  echo json_encode(['success'=>false,'error'=>'pdo_not_initialized']); ob_end_flush(); exit;
}

$name   = trim($_POST['name'] ?? '');
$email  = trim($_POST['email'] ?? '');
$phone  = trim($_POST['phone'] ?? '');
$slotId = (int)($_POST['slot_id'] ?? 0);
$tag    = (int)($_POST['tag'] ?? 0);

if ($name==='' || $email==='' || $phone==='' || $slotId<=0 || $tag<=0) {
  echo json_encode(['success'=>false,'error'=>'missing_fields']); ob_end_flush(); exit;
}

try {
  $pdo->beginTransaction();

  // Vérifier dispo
  $stmt = $pdo->prepare("SELECT is_booked FROM slots WHERE id=:id FOR UPDATE");
  $stmt->execute([':id'=>$slotId]);
  $slot = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$slot) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'slot_not_found']); ob_end_flush(); exit;
  }
  if ((int)$slot['is_booked'] === 1) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'slot_already_booked']); ob_end_flush(); exit;
  }

  // Réserver
  $upd = $pdo->prepare("UPDATE slots SET is_booked=1 WHERE id=:id");
  $upd->execute([':id'=>$slotId]);

  // Enregistrer RDV
  $ins = $pdo->prepare("
    INSERT INTO appointments (tag, slot_id, name, email, phone)
    VALUES (:tag, :slot, :name, :email, :phone)
  ");
  $ins->execute([
    ':tag'=>$tag, ':slot'=>$slotId,
    ':name'=>$name, ':email'=>$email, ':phone'=>$phone
  ]);

  $pdo->commit();
  echo json_encode(['success'=>true]);
  ob_end_flush();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[book_slot] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
  ob_end_flush();
}
