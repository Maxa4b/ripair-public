<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors','0');
ob_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
  echo json_encode(['success'=>false,'error'=>'pdo_not_initialized']);
  ob_end_flush(); exit;
}

// paramètres
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$brand    = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$model    = isset($_GET['model']) ? trim($_GET['model']) : '';

try {
  if ($category === '' && $brand === '' && $model === '') {
    // catégories
    $rows = $pdo->query("SELECT DISTINCT category FROM repairs ORDER BY category")
                ->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['success'=>true,'data'=>$rows]); ob_end_flush(); exit;
  }

  if ($category !== '' && $brand === '' && $model === '') {
    // marques
    $stmt = $pdo->prepare("SELECT DISTINCT brand FROM repairs WHERE category=:c ORDER BY brand");
    $stmt->execute([':c'=>$category]);
    echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_COLUMN)]); ob_end_flush(); exit;
  }

  if ($category !== '' && $brand !== '' && $model === '') {
    // modèles
    $stmt = $pdo->prepare("
      SELECT DISTINCT model FROM repairs
      WHERE category=:c AND brand=:b
      ORDER BY model
    ");
    $stmt->execute([':c'=>$category, ':b'=>$brand]);
    echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_COLUMN)]); ob_end_flush(); exit;
  }

  if ($category !== '' && $brand !== '' && $model !== '') {
    // problèmes
    $stmt = $pdo->prepare("
      SELECT DISTINCT problem FROM repairs
      WHERE category=:c AND brand=:b AND model=:m
      ORDER BY problem
    ");
    $stmt->execute([':c'=>$category, ':b'=>$brand, ':m'=>$model]);
    echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_COLUMN)]); ob_end_flush(); exit;
  }

  echo json_encode(['success'=>false,'error'=>'bad_params']);
  ob_end_flush();
} catch (Throwable $e) {
  error_log('[get_options] '.$e->getMessage());
  echo json_encode(['success'=>false,'error'=>'server_error']);
  ob_end_flush();
}
