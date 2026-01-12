<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Ripair-Reviews-Version: 1');
error_reporting(E_ALL);
ini_set('display_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'pdo_not_initialized']);
    exit;
}

function read_payload(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw ?: '', true);
        return is_array($decoded) ? $decoded : [];
    }

    if (!empty($_POST) && is_array($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    parse_str($raw, $parsed);
    return is_array($parsed) ? $parsed : [];
}

function normalize_bool(mixed $value): bool
{
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 1;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function ensure_reviews_table(PDO $pdo): void
{
    // Avoid requiring CREATE privilege on every request (some hosts reject CREATE TABLE even with IF NOT EXISTS).
    try {
        $pdo->query("SELECT 1 FROM customer_reviews LIMIT 1");
        return;
    } catch (PDOException $e) {
        // 42S02 = table not found
        if ($e->getCode() !== '42S02') {
            throw $e;
        }
    }

    try {
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
    } catch (PDOException $e) {
        // e.g. CREATE command denied / insufficient privileges on shared hosting
        throw $e;
    }
}

$payload = read_payload();

$rating = (int)($payload['rating'] ?? 0);
$comment = trim((string)($payload['comment'] ?? $payload['message'] ?? ''));
$firstName = trim((string)($payload['first_name'] ?? $payload['firstName'] ?? ''));
$lastName = trim((string)($payload['last_name'] ?? $payload['lastName'] ?? ''));
$showName = normalize_bool($payload['show_name'] ?? $payload['showName'] ?? false);
$sourcePage = trim((string)($payload['source_page'] ?? $payload['sourcePage'] ?? ''));

if ($rating < 1 || $rating > 5) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_rating']);
    exit;
}

if ($comment === '' || mb_strlen($comment) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'comment_too_short']);
    exit;
}

if (mb_strlen($comment) > 1200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'comment_too_long']);
    exit;
}

if ($showName && $firstName === '' && $lastName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'name_required_when_public']);
    exit;
}

try {
    ensure_reviews_table($pdo);

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ua = mb_substr($ua, 0, 255);

    $hashSecret = (string)env('REVIEWS_IP_HASH_SECRET', env('CANCEL_TOKEN_SECRET', ''));
    $ipHash = $ip !== ''
        ? ($hashSecret !== '' ? hash_hmac('sha256', $ip, $hashSecret) : hash('sha256', $ip))
        : null;

    $rateSeconds = 60;
    if ($ipHash) {
        $since = (new DateTimeImmutable('now'))->modify('-' . $rateSeconds . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM customer_reviews
            WHERE ip_hash = :ip_hash
              AND created_at >= :since
        ");
        $stmt->execute([
            ':ip_hash' => $ipHash,
            ':since' => $since,
        ]);
        $count = (int)$stmt->fetchColumn();
        if ($count > 0) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'too_many_requests']);
            exit;
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO customer_reviews (
            rating, comment, first_name, last_name, show_name, status,
            ip_hash, user_agent, source_page
        ) VALUES (
            :rating, :comment, :first_name, :last_name, :show_name, 'pending',
            :ip_hash, :user_agent, :source_page
        )
    ");
    $insert->execute([
        ':rating' => $rating,
        ':comment' => $comment,
        ':first_name' => $firstName !== '' ? $firstName : null,
        ':last_name' => $lastName !== '' ? $lastName : null,
        ':show_name' => $showName ? 1 : 0,
        ':ip_hash' => $ipHash,
        ':user_agent' => $ua !== '' ? $ua : null,
        ':source_page' => $sourcePage !== '' ? $sourcePage : null,
    ]);

    $id = (int)$pdo->lastInsertId();
    echo json_encode([
        'success' => true,
        'id' => $id,
        'status' => 'pending',
        'message' => 'Merci ! Votre avis a bien été envoyé et sera publié après validation.',
    ]);
} catch (Throwable $e) {
    $debugId = bin2hex(random_bytes(6));
    error_log('[submit_review][' . $debugId . '] ' . $e->getMessage());
    header('X-Ripair-Debug-Id: ' . $debugId);
    http_response_code(500);
    $payload = ['success' => false, 'error' => 'server_error', 'debug_id' => $debugId];
    if ($e instanceof PDOException) {
        $payload['db_code'] = $e->getCode();
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'create command denied') || str_contains($msg, 'permission') || str_contains($msg, 'access denied')) {
            $payload['error'] = 'db_permission_denied';
        } elseif ($e->getCode() === '42S02') {
            $payload['error'] = 'table_missing';
        }
    }
    echo json_encode($payload);
}
