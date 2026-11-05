<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit(1);
}

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'mpu';
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');

if ($db_user === false || $db_user === '') {
    fwrite(STDERR, "Fehler: Umgebungsvariable DB_USER ist nicht gesetzt.\n");
    exit(1);
}
if ($db_pass === false) {
    fwrite(STDERR, "Fehler: Umgebungsvariable DB_PASS ist nicht gesetzt.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Datenbankverbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        version    VARCHAR(14) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$applied = array_flip(
    $pdo->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN)
);

$migrations_dir = dirname(__DIR__) . '/migrations';
if (!is_dir($migrations_dir)) {
    echo "Keine neuen Migrationen.\n";
    exit(0);
}

$files = glob($migrations_dir . '/*.sql');
if (empty($files)) {
    echo "Keine neuen Migrationen.\n";
    exit(0);
}

sort($files);

$count = 0;

foreach ($files as $file) {
    $basename = basename($file, '.sql');
    if (!preg_match('/^(\d{8}_\d{4})/', $basename, $m)) {
        echo "Überspringe Datei ohne gültiges Versions-Format: {$basename}.sql\n";
        continue;
    }
    $version = $m[1];

    if (isset($applied[$version])) {
        continue;
    }

    echo "Wende Migration an: {$basename} ... ";

    $sql_content = file_get_contents($file);
    if ($sql_content === false) {
        fwrite(STDERR, "\nFehler: Konnte Datei nicht lesen: {$file}\n");
        exit(1);
    }

    $statements = split_sql($sql_content);

    $pdo->beginTransaction();
    try {
        foreach ($statements as $stmt) {
            $pdo->exec($stmt);
        }
        $pdo->prepare("INSERT INTO schema_migrations (version) VALUES (?)")->execute([$version]);
        // DDL statements (ALTER TABLE etc.) cause an implicit commit in MySQL,
        // leaving no active transaction — guard before calling commit().
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        echo "OK\n";
        $count++;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "\nFehler in Migration {$basename}: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Hinweis: DDL-Anweisungen (ALTER/CREATE TABLE) lassen sich nicht rückgängig machen.\n");
        exit(1);
    }
}

if ($count === 0) {
    echo "Keine neuen Migrationen.\n";
} else {
    echo "{$count} Migration(en) erfolgreich angewendet.\n";
}
exit(0);

/**
 * Zerlegt eine SQL-Datei in einzeln ausführbare Statements.
 * Erkennt Statementgrenzen anhand von Semikolons im nicht-kommentierten Teil
 * jeder Zeile. Rein kommentierte Blöcke werden nicht als eigene Statements
 * ausgegeben, sondern dem nächsten ausführbaren Statement vorangestellt
 * (MySQL ignoriert Kommentare ohne Fehler).
 */
function split_sql(string $sql): array
{
    $statements = [];
    $current    = '';

    foreach (explode("\n", $sql) as $line) {
        $current .= $line . "\n";
        $non_comment = preg_replace('/--.*$/', '', $line);
        if (str_ends_with(rtrim($non_comment), ';')) {
            $stmt = trim($current);
            if ($stmt !== '' && has_executable_sql($stmt)) {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }

    $leftover = trim($current);
    if ($leftover !== '' && has_executable_sql($leftover)) {
        $statements[] = $leftover;
    }

    return $statements;
}

/**
 * Prüft ob ein SQL-Block mindestens eine nicht-kommentierte, nicht-leere Zeile enthält.
 */
function has_executable_sql(string $stmt): bool
{
    foreach (explode("\n", $stmt) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
            return true;
        }
    }
    return false;
}
