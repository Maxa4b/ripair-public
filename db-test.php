<?php
require __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'CHANGE_ME') {
  http_response_code(403);
  echo "Forbidden\n";
  exit;
}

$host = env('DB_HOST');
$db   = env('DB_NAME');
$user = env('DB_USER');
$pass = env('DB_PASS');
$port = env('DB_PORT', '3306');

echo "DB_HOST={$host}\nDB_NAME={$db}\nDB_USER={$user}\nDB_PASS_LEN=" . strlen((string)$pass) . "\n";
echo "PDO_DRIVERS=" . implode(',', PDO::getAvailableDrivers()) . "\n";

try {
  $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4;port={$port}";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  ]);
  echo "CONNECT_OK\n";
} catch (Throwable $e) {
  echo "CONNECT_ERR: " . $e->getMessage() . "\n";
}

echo "SERVER_ADDR=" . ($_SERVER['SERVER_ADDR'] ?? 'n/a') . "\n";
echo "OUT_IP=" . trim(@file_get_contents('https://api.ipify.org')) . "\n";
