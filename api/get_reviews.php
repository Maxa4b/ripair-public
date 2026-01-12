<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * Table used by both the public site and Helix moderation.
 */
function ensure_reviews_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rating TINYINT UNSIGNED NOT NULL,
            comment TEXT NOT NULL,
            first_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NULL,
            show_name TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            moderated_at DATETIME NULL,
            moderated_by BIGINT UNSIGNED NULL,
            admin_note VARCHAR(255) NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            source_page VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customer_reviews_status_created (status, created_at),
            INDEX idx_customer_reviews_ip_hash_created (ip_hash, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function fetch_internal_reviews(?PDO $pdo, int $limit = 60): array
{
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        return [];
    }

    ensure_reviews_table($pdo);

    $limit = max(1, min(200, $limit));
    $stmt = $pdo->prepare("
        SELECT id, rating, comment, first_name, last_name, show_name, created_at
        FROM customer_reviews
        WHERE status = 'approved'
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $reviews = [];
    foreach ($rows as $row) {
        $name = '';
        if (!empty($row['show_name'])) {
            $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        }
        if ($name === '') {
            $name = 'Client RIPAIR';
        }

        $createdAt = (string)($row['created_at'] ?? '');
        $time = $createdAt !== '' ? strtotime($createdAt) : time();

        $reviews[] = [
            'author_name' => $name,
            'rating' => (int)($row['rating'] ?? 0),
            'text' => (string)($row['comment'] ?? ''),
            'time' => $time ?: time(),
            'source' => 'ripair',
            'id' => (int)($row['id'] ?? 0),
        ];
    }

    return $reviews;
}

function fetch_google_payload(): array
{
    $apiKey = (string)env('GOOGLE_API_KEY', '');
    $placeId = (string)env('GOOGLE_PLACE_ID', '');

    if ($apiKey === '' || $placeId === '') {
        return [];
    }

    $cacheFile = sys_get_temp_dir() . '/ripair_google_reviews_cache.json';
    $cacheTTL = 6 * 3600;

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = @file_get_contents($cacheFile);
        $decoded = is_string($cached) ? json_decode($cached, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode($placeId)
        . '&fields=rating,user_ratings_total,reviews&key=' . urlencode($apiKey);

    $resp = @file_get_contents($url);
    if ($resp === false) {
        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);
            $decoded = is_string($cached) ? json_decode($cached, true) : null;
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    @file_put_contents($cacheFile, $resp);
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : [];
}

function compute_internal_stats(array $internalReviews): array
{
    $count = count($internalReviews);
    if ($count === 0) {
        return ['count' => 0, 'avg_rating' => null];
    }
    $sum = 0;
    foreach ($internalReviews as $r) {
        $sum += (int)($r['rating'] ?? 0);
    }
    $avg = $sum / max(1, $count);
    return ['count' => $count, 'avg_rating' => round($avg, 2)];
}

try {
    $internal = fetch_internal_reviews(isset($pdo) ? $pdo : null);
    $googlePayload = fetch_google_payload();

    $googleRating = null;
    $googleCount = null;
    $googleReviews = [];

    if (isset($googlePayload['result']) && is_array($googlePayload['result'])) {
        $googleRating = isset($googlePayload['result']['rating']) ? (float)$googlePayload['result']['rating'] : null;
        $googleCount = isset($googlePayload['result']['user_ratings_total']) ? (int)$googlePayload['result']['user_ratings_total'] : null;

        $raw = $googlePayload['result']['reviews'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $review) {
                if (!is_array($review)) continue;
                $review['source'] = 'google';
                $googleReviews[] = $review;
            }
        }
    }

    $merged = array_merge($internal, $googleReviews);
    usort($merged, function ($a, $b): int {
        $ta = (int)($a['time'] ?? 0);
        $tb = (int)($b['time'] ?? 0);
        return $tb <=> $ta;
    });

    $internalStats = compute_internal_stats($internal);

    $combinedCount = 0;
    $combinedRating = null;

    if (is_int($googleCount) && $googleCount > 0 && is_float($googleRating)) {
        $combinedCount = $googleCount + (int)$internalStats['count'];
        if (!empty($internalStats['count']) && is_float($internalStats['avg_rating'])) {
            $combinedRating = round((($googleRating * $googleCount) + ($internalStats['avg_rating'] * $internalStats['count'])) / max(1, $combinedCount), 2);
        } else {
            $combinedRating = round($googleRating, 2);
        }
    } else {
        $combinedCount = (int)$internalStats['count'];
        $combinedRating = $internalStats['avg_rating'];
    }

    echo json_encode([
        'success' => true,
        'meta' => [
            'rating' => $combinedRating,
            'count' => $combinedCount,
            'sources' => [
                'google' => [
                    'rating' => $googleRating,
                    'count' => $googleCount,
                ],
                'internal' => [
                    'avg_rating' => $internalStats['avg_rating'],
                    'count' => $internalStats['count'],
                ],
            ],
        ],
        'reviews' => $merged,
    ]);
} catch (Throwable $e) {
    error_log('[get_reviews] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}

