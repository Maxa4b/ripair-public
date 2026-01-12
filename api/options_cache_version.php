<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/config.php';

$cacheDir = __DIR__ . '/cache';
$versionFile = $cacheDir . '/options_version.json';
$defaultVersion = 1;

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$version = $defaultVersion;
$updatedAt = null;

if (is_file($versionFile) && is_readable($versionFile)) {
    $raw = @file_get_contents($versionFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && isset($decoded['version'])) {
        $candidate = (int)$decoded['version'];
        if ($candidate > 0) {
            $version = $candidate;
        }
        if (!empty($decoded['updated_at'])) {
            $updatedAt = (string)$decoded['updated_at'];
        }
    }
}

if (!is_file($versionFile)) {
    $updatedAt = gmdate('c');
    @file_put_contents(
        $versionFile,
        json_encode([
            'version' => $version,
            'updated_at' => $updatedAt,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
} elseif ($updatedAt === null) {
    $updatedAt = gmdate('c');
}

echo json_encode([
    'success' => true,
    'version' => $version,
    'updated_at' => $updatedAt,
]);
