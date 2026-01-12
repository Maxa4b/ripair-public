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

try {
  $stmt = $pdo->query("
    SELECT id, date, time
    FROM slots
    WHERE is_booked = 0
    ORDER BY date, time
    LIMIT 100
  ");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['success'=>true,'data'=>$rows]);
  ob_end_flush();
} catch (Throwable $e) {
  error_log('[get_slots] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
  ob_end_flush();
}
