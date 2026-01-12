<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../config/config.php';
load_env(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/database.php';


if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'pdo_not_initialized']);
    ob_end_flush();
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT category, brand, model, problem, price, duration
        FROM repairs
        ORDER BY category, brand, model, problem
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lastUpdated = null;
    try {
        $tsStmt = $pdo->query("SELECT MAX(updated_at) AS last_update FROM repairs");
        $meta = $tsStmt->fetch(PDO::FETCH_ASSOC);
        if ($meta && !empty($meta['last_update'])) {
            $lastUpdated = $meta['last_update'];
        }
    } catch (Throwable $metaErr) {
        error_log('[get_options_full] last_update query failed: ' . $metaErr->getMessage());
    }

    echo json_encode([
        'success'      => true,
        'count'        => count($rows),
        'generated_at' => gmdate('c'),
        'last_update'  => $lastUpdated,
        'data'         => $rows,
    ]);
    ob_end_flush();
} catch (Throwable $e) {
    error_log('[get_options_full] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    ob_end_flush();
}
