<?php
declare(strict_types=1);

/**
 * Charge un fichier .env (clé=valeur), sans composer.
 * - Ignorer lignes vides et commentaires (#)
 * - Garde les variables en mémoire via putenv + $_ENV
 */
function load_dotenv(string $envPath): void {
    if (!is_file($envPath) || !is_readable($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Strip quotes éventuelles
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

$root = dirname(__DIR__);              // racine projet (../)
$envFile = $root . '/.env';
load_dotenv($envFile);

// Récup env avec fallback sûrs
$host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost';
$db   = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'ripair';
$user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
$pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: '';

$charset = 'utf8mb4';
$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        // PDO::ATTR_PERSISTENT      => true, // optionnel
    ]);
} catch (Throwable $e) {
    // Ne pas echo ici — laisser les endpoints renvoyer un JSON propre
    error_log('[database] PDO connect failed: ' . $e->getMessage());
    // Laisse $pdo non défini : les endpoints testeront sa présence et renverront un JSON d’erreur
}
