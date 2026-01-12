<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';

$expectedToken = env('OPTIONS_CACHE_TOKEN', $_ENV['OPTIONS_CACHE_TOKEN'] ?? '');
if ($expectedToken === '') {
    $expectedToken = env('RIPAIR_OPTIONS_CACHE_TOKEN', $_ENV['RIPAIR_OPTIONS_CACHE_TOKEN'] ?? '');
}
$providedToken = $_POST['token'] ?? ($_GET['token'] ?? '');

if ($expectedToken === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'token_not_configured']);
    exit;
}

if (!is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_token']);
    exit;
}

$cacheDir = __DIR__ . '/cache';
$versionFile = $cacheDir . '/options_version.json';

if (!is_dir($cacheDir)) {
    if (!@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'cache_dir_unwritable']);
        exit;
    }
}

$version = 1;

if (is_file($versionFile) && is_readable($versionFile)) {
    $raw = @file_get_contents($versionFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && isset($decoded['version'])) {
        $candidate = (int)$decoded['version'];
        if ($candidate > 0) {
            $version = $candidate;
        }
    }
}

$version++;
$payload = [
    'version' => $version,
    'updated_at' => gmdate('c'),
];

if (@file_put_contents($versionFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'write_failed']);
    exit;
}

$legacyCacheFile = sys_get_temp_dir() . '/ripair_options_cache.json';
if (is_file($legacyCacheFile)) {
    @unlink($legacyCacheFile);
}

echo json_encode([
    'success' => true,
    'version' => $version,
    'updated_at' => $payload['updated_at'],
]);
