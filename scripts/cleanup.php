<?php
declare(strict_types=1);
// Bereinigt auth_log-Einträge älter als 1 Jahr, audit_log nach AUDIT_AUFBEWAHRUNG_JAHRE und
// login_attempts älter als 1 Stunde.
// Aufruf: php scripts/cleanup.php
// Kein Webzugriff: .htaccess sperrt scripts/.

require_once __DIR__ . '/../config/allowed_values.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'mpu';

if ($db_user === false || $db_user === '') {
    fwrite(STDERR, "Fehler: Umgebungsvariable DB_USER ist nicht gesetzt.\n");
    exit(1);
}
if ($db_pass === false) {
    fwrite(STDERR, "Fehler: Umgebungsvariable DB_PASS ist nicht gesetzt.\n");
    exit(1);
}

try {
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $db_host, $db_name);
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);

    $stmt = $pdo->exec("DELETE FROM auth_log WHERE created_at < NOW() - INTERVAL 1 YEAR");
    $auth_log_deleted = $stmt;
    echo "auth_log: {$auth_log_deleted} Zeile(n) gelöscht.\n";

    $audit = $pdo->prepare("DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ? YEAR");
    $audit->execute([AUDIT_AUFBEWAHRUNG_JAHRE]);
    echo "audit_log: {$audit->rowCount()} Zeile(n) gelöscht.\n";

    $stmt = $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 HOUR");
    $login_attempts_deleted = $stmt;
    echo "login_attempts: {$login_attempts_deleted} Zeile(n) gelöscht.\n";

} catch (PDOException $e) {
    fwrite(STDERR, "DB-Fehler: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
