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

$tag = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
if ($tag <= 0) {
  echo json_encode(['success'=>false,'error'=>'missing_tag']); ob_end_flush(); exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT id AS tag, category, brand, model, problem, price, duration
    FROM quotes WHERE id = :id LIMIT 1
  ");
  $stmt->execute([':id'=>$tag]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    echo json_encode(['success'=>false,'error'=>'not_found']); ob_end_flush(); exit;
  }

  echo json_encode(['success'=>true,'data'=>$row]);
  ob_end_flush();
} catch (Throwable $e) {
  error_log('[get_quote] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
  ob_end_flush();
}
