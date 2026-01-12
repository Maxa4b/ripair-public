<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'pdo_not_initialized']);
  ob_end_flush(); exit;
}

$category = trim($_POST['category'] ?? '');
$brand    = trim($_POST['brand'] ?? '');
$model    = trim($_POST['model'] ?? '');
$problem  = trim($_POST['problem'] ?? '');

if ($category === '' || $brand === '' || $model === '' || $problem === '') {
  echo json_encode(['success'=>false,'error'=>'missing_fields']);
  ob_end_flush(); exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT category, brand, model, problem, price, duration
    FROM repairs
    WHERE category = :category AND brand = :brand
      AND model = :model AND problem = :problem
    LIMIT 1
  ");
  $stmt->execute([
    ':category'=>$category, ':brand'=>$brand,
    ':model'=>$model, ':problem'=>$problem
  ]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    echo json_encode(['success'=>false,'error'=>'no_match']);
    ob_end_flush(); exit;
  }

  $ins = $pdo->prepare("
    INSERT INTO quotes (category, brand, model, problem, price, duration)
    VALUES (:c,:b,:m,:p,:price,:dur)
  ");
  $ins->execute([
    ':c'=>$category, ':b'=>$brand, ':m'=>$model, ':p'=>$problem,
    ':price'=>$row['price'], ':dur'=>$row['duration'] ?? null
  ]);

  $tag = (int)$pdo->lastInsertId();

  echo json_encode(['success'=>true,'data'=>$row,'tag'=>$tag]);
  ob_end_flush();
} catch (Throwable $e) {
  error_log('[get_devis] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'error'=>'server_error']);
  ob_end_flush();
}
