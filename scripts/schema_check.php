<?php
declare(strict_types=1);

// R3-5: session_starten() statt session_start(). PHPs ini-Defaults liefern ein
// Cookie ohne HttpOnly, ohne SameSite und ohne Secure – ein abgemeldeter Aufruf
// dieser Seite setzte damit ein für JavaScript lesbares Session-Cookie, bevor
// require_auth() zum Login umleitet. Gleicher Weg wie in triage_ajax.php, das
// ebenfalls ohne db.php auskommt.
require_once __DIR__ . '/../logic/request.php';
require_once __DIR__ . '/../auth.php';
session_starten();
require_auth();

// ── Konfiguration ─────────────────────────────────────────────────────────────

// Muss deckungsgleich mit den CREATE TABLE in schema.sql sein (CLAUDE.md: jede
// DB-Änderung braucht schema.sql + migrations/ + diese Datei im selben Commit).
// Gegenprobe: grep -c "CREATE TABLE" schema.sql
const EXPECTED_TABLES = [
    'clients', 'cases', 'abstinence_probes', 'client_tasks',
    'audit_log', 'sessions', 'case_events', 'case_notes', 'login_attempts',
    'auth_log', 'schema_migrations', 'performance_tests',
];

const CRITICAL_COLUMNS = [
    'clients' => [
        'dsgvo_consent_date', 'revocation_notice_confirmed', 'revocation_confirmed', 'early_start_requested', 'street',
        'gender', 'weight_kg', 'version',
    ],
    'cases' => [
        'bkf_status', 'status', 'version', 'license_classes',
        'antrag_status', 'einspruch_status', 'bussgeld_datum',
        'audit_score', 'last_score', 'closed_at', 'payment_amount_paid',
        'thc_cooh_value', 'mpu_target_date', 'persoenlichkeit', 'medizinal_beikonsum',
        'erschwerend_faktoren', 'therapie_art',
    ],
    'audit_log'         => ['user_name', 'ip_address'],
    'sessions'          => ['sorkc_s', 'sorkc_o', 'sorkc_r', 'sorkc_k', 'sorkc_c', 'case_id'],
    'abstinence_probes' => ['kreatinin_mg_dl', 'peth_value', 'case_id'],
    'client_tasks'      => ['case_id'],
    'case_notes'        => ['case_id', 'note_date', 'title', 'body'],
    'performance_tests' => ['percentile', 'test_date', 'test_name'],
];

const CRITICAL_INDEXES = [
    'cases' => ['idx_client_id'],
];

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

/** @return array{ok:bool,label:string,detail:string,fix?:string} */
function check(bool $ok, string $label, string $detail, string $fix = ''): array
{
    return compact('ok', 'label', 'detail', 'fix');
}

// ── Prüfungen sammeln ─────────────────────────────────────────────────────────

$results   = [];
$pdo       = null;
$db_host   = getenv('DB_HOST') ?: 'localhost';
$db_name   = getenv('DB_NAME') ?: 'mpu';
$db_user   = (string)(getenv('DB_USER') ?: '');
$db_pass   = (string)(getenv('DB_PASS') ?: '');
$db_label  = $db_name . '@' . $db_host;

// 1. Umgebungsvariablen
if ($db_user === '') {
    $results[] = check(false, 'Umgebungsvariable DB_USER', 'nicht gesetzt',
        'DB_USER und DB_PASS müssen als Server-Umgebungsvariablen (z. B. via .htaccess: SetEnv DB_USER …) gesetzt sein.');
} else {
    $results[] = check(true, 'Umgebungsvariable DB_USER', 'gesetzt');
}

if ($db_pass === '') {
    $results[] = check(false, 'Umgebungsvariable DB_PASS', 'nicht gesetzt',
        'DB_USER und DB_PASS müssen als Server-Umgebungsvariablen gesetzt sein.');
} else {
    $results[] = check(true, 'Umgebungsvariable DB_PASS', 'gesetzt (Wert ausgeblendet)');
}

// 2. Datenbankverbindung
if ($db_user !== '' && $db_pass !== '') {
    try {
        $pdo = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $server_version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $results[] = check(true, 'Datenbankverbindung', "{$db_label} · {$server_version}");
        // Ohne diese Zeile meldet die Prüfung auf 10.6 und MySQL alles grün, die Seiten scheitern aber.
        $results[] = check(
            str_contains(strtolower((string)$server_version), 'mariadb') && version_compare((string)$server_version, '10.11', '>='),
            'Datenbankversion', (string)$server_version,
            'Unterstützt ist MariaDB ab 10.11 (siehe README, Voraussetzungen).'
        );
    } catch (PDOException $e) {
        $results[] = check(false, 'Datenbankverbindung', $e->getMessage(),
            "Prüfe DB_HOST (aktuell: {$db_host}), DB_NAME (aktuell: {$db_name}), DB_USER und DB_PASS. Vergewissere dich, dass der Datenbankserver läuft und der User Zugriffsrechte hat.");
        $pdo = null;
    }
}

// Alle weiteren Prüfungen nur wenn DB-Verbindung steht
if ($pdo !== null) {

    // 3. schema_migrations Tabelle
    try {
        $pdo->query("SELECT COUNT(*) FROM schema_migrations");
        $count_applied = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $results[] = check(true, 'Tabelle schema_migrations', "{$count_applied} Eintrag/Einträge");
        $migrations_table_ok = true;
    } catch (PDOException) {
        $results[] = check(false, 'Tabelle schema_migrations', 'Tabelle fehlt',
            "Spiele schema.sql ein: mysql -u\$DB_USER -p\$DB_PASS {$db_name} < schema.sql");
        $migrations_table_ok = false;
    }

    // 4. Erwartete Tabellen
    $existing_tables = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([$db_name]);
        $existing_tables = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException) {}

    foreach (EXPECTED_TABLES as $table) {
        $ok = isset($existing_tables[$table]);
        $results[] = check(
            $ok,
            "Tabelle `{$table}`",
            $ok ? 'vorhanden' : 'fehlt',
            $ok ? '' : "Spiele schema.sql ein oder führe php scripts/migrate.php aus."
        );
    }

    // 5. Migration-Dateien vs. schema_migrations
    $migrations_dir = dirname(__DIR__) . '/migrations';
    $migration_files = is_dir($migrations_dir) ? glob($migrations_dir . '/*.sql') : [];
    sort($migration_files);

    $applied_versions = [];
    if ($migrations_table_ok) {
        try {
            $applied_versions = array_flip(
                $pdo->query("SELECT version FROM schema_migrations ORDER BY version")->fetchAll(PDO::FETCH_COLUMN)
            );
        } catch (PDOException) {}
    }

    if (empty($migration_files)) {
        $results[] = check(true, 'Migrations-Verzeichnis', 'leer oder nicht vorhanden (kein Handlungsbedarf)');
    } else {
        foreach ($migration_files as $file) {
            $basename = basename($file, '.sql');
            if (!preg_match('/^(\d{8}_\d{4})/', $basename, $m)) {
                continue;
            }
            $version = $m[1];
            $ok = isset($applied_versions[$version]);
            $applied_at = '';
            if ($ok && $migrations_table_ok) {
                try {
                    $row = $pdo->prepare("SELECT applied_at FROM schema_migrations WHERE version = ?");
                    $row->execute([$version]);
                    $applied_at = (string)$row->fetchColumn();
                } catch (PDOException) {}
            }
            $detail = $ok
                ? "angewendet" . ($applied_at ? " am {$applied_at}" : '')
                : "NICHT angewendet";
            $results[] = check(
                $ok,
                "Migration {$version}",
                $detail . " · {$basename}",
                $ok ? '' : "Führe auf dem Server aus: php scripts/migrate.php\n\nOder manuell:\nmysql -u\$DB_USER -p\$DB_PASS {$db_name} < migrations/{$basename}.sql\nINSERT INTO schema_migrations (version) VALUES ('{$version}');"
            );
        }
    }

    // 6. Kritische Spalten
    foreach (CRITICAL_COLUMNS as $table => $columns) {
        if (!isset($existing_tables[$table])) {
            continue; // Tabellen-Check hat das schon gemeld
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?"
            );
            $stmt->execute([$db_name, $table]);
            $existing_cols = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException) {
            $existing_cols = [];
        }
        foreach ($columns as $col) {
            $ok = isset($existing_cols[$col]);
            $results[] = check(
                $ok,
                "Spalte `{$table}.{$col}`",
                $ok ? 'vorhanden' : 'fehlt',
                $ok ? '' : "Führe php scripts/migrate.php aus. Wenn das nicht hilft, prüfe migrations/*.sql auf den ALTER TABLE-Befehl für diese Spalte und führe ihn manuell aus."
            );
        }
    }

    // 7. Kritische Indizes
    foreach (CRITICAL_INDEXES as $table => $indexes) {
        if (!isset($existing_tables[$table])) {
            continue;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                 GROUP BY INDEX_NAME"
            );
            $stmt->execute([$db_name, $table]);
            $existing_indexes = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException) {
            $existing_indexes = [];
        }
        foreach ($indexes as $idx) {
            $ok = isset($existing_indexes[$idx]);
            $results[] = check(
                $ok,
                "Index `{$table}.{$idx}`",
                $ok ? 'vorhanden' : 'fehlt (Performance-Warnung)',
                $ok ? '' : "Führe php scripts/migrate.php aus oder manuell:\nALTER TABLE {$table} ADD INDEX IF NOT EXISTS {$idx} (client_id);"
            );
        }
    }
}

// ── Zusammenfassung ───────────────────────────────────────────────────────────

$total   = count($results);
$failed  = array_filter($results, fn($r) => !$r['ok']);
$passed  = $total - count($failed);
$all_ok  = count($failed) === 0;

// ── HTML ausgeben ─────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenbank-Diagnose · MPU Manager</title>
    <link rel="stylesheet" href="../assets/css/tabler.min.css">
    <link rel="stylesheet" href="../assets/css/tabler-icons.min.css">
    <meta name="robots" content="noindex,nofollow">
</head>
<body class="antialiased">
<div class="page">
<div class="page-wrapper">
<div class="page-body">
<div class="container-xl py-4">

    <div class="page-header mb-4">
        <div class="row align-items-center">
            <div class="col-auto">
                <h1 class="page-title">
                    <i class="ti ti-database me-2"></i>Datenbank-Diagnose
                </h1>
                <div class="text-secondary">
                    Prüft, ob das Datenbankschema und alle Migrationen auf diesem Hosting eingespielt sind.
                </div>
            </div>
            <div class="col-auto ms-auto">
                <?php if ($all_ok): ?>
                    <span class="badge bg-success text-success-fg fs-3 px-3 py-2">
                        <i class="ti ti-circle-check me-1"></i>Alle <?= $total ?> Prüfungen bestanden
                    </span>
                <?php else: ?>
                    <span class="badge bg-danger text-danger-fg fs-3 px-3 py-2">
                        <i class="ti ti-alert-triangle me-1"></i><?= count($failed) ?> von <?= $total ?> Prüfungen fehlgeschlagen
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$all_ok): ?>
    <div class="alert alert-danger mb-4" role="alert">
        <div class="d-flex">
            <div class="me-3"><i class="ti ti-tools fs-2"></i></div>
            <div>
                <h4 class="alert-title">Handlungsbedarf</h4>
                <p class="mb-2">Das Datenbankschema ist nicht vollständig. Typische Lösung:</p>
                <ol class="mb-0">
                    <li>Per SSH auf den Server verbinden</li>
                    <li><code>cd /pfad/zur/app</code></li>
                    <li><code>DB_USER=… DB_PASS=… php scripts/migrate.php</code></li>
                </ol>
                <p class="mt-2 mb-0 text-secondary small">Detaillierte Anleitungen zu jedem Problem stehen in den roten Zeilen unten.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Gruppen nach Kategorie
    $groups = [
        'Umgebung'    => [],
        'Verbindung'  => [],
        'Migrationen' => [],
        'Tabellen'    => [],
        'Spalten'     => [],
        'Indizes'     => [],
    ];
    foreach ($results as $r) {
        $label = $r['label'];
        if (str_starts_with($label, 'Umgebungsvariable'))   $groups['Umgebung'][]    = $r;
        elseif (str_starts_with($label, 'Datenbankver'))     $groups['Verbindung'][]  = $r;
        elseif (str_starts_with($label, 'Migration ') || str_starts_with($label, 'Migrations-')) $groups['Migrationen'][] = $r;
        elseif (str_starts_with($label, 'Tabelle '))         $groups['Tabellen'][]    = $r;
        elseif (str_starts_with($label, 'Spalte '))          $groups['Spalten'][]     = $r;
        elseif (str_starts_with($label, 'Index '))           $groups['Indizes'][]     = $r;
        else                                                  $groups['Verbindung'][]  = $r;
    }
    foreach ($groups as $group_name => $items):
        if (empty($items)) continue;
        $group_failed = array_filter($items, fn($r) => !$r['ok']);
    ?>
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <?php if (empty($group_failed)): ?>
                    <i class="ti ti-circle-check text-success me-2"></i>
                <?php else: ?>
                    <i class="ti ti-alert-circle text-danger me-2"></i>
                <?php endif; ?>
                <?= htmlspecialchars($group_name, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
            </h3>
            <div class="card-options">
                <span class="text-secondary small">
                    <?= count($items) - count($group_failed) ?>/<?= count($items) ?> OK
                </span>
            </div>
        </div>
        <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <tbody>
            <?php foreach ($items as $r): ?>
            <tr class="<?= $r['ok'] ? '' : 'table-danger' ?>">
                <td class="w-1">
                    <?php if ($r['ok']): ?>
                        <i class="ti ti-check text-success"></i>
                    <?php else: ?>
                        <i class="ti ti-x text-danger"></i>
                    <?php endif; ?>
                </td>
                <td class="font-monospace">
                    <?= htmlspecialchars($r['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                </td>
                <td class="text-secondary small">
                    <?= htmlspecialchars($r['detail'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                </td>
            </tr>
            <?php if (!$r['ok'] && !empty($r['fix'])): ?>
            <tr class="table-danger border-top-0">
                <td></td>
                <td colspan="2">
                    <div class="alert alert-warning alert-sm py-2 mb-0 mt-1">
                        <i class="ti ti-terminal me-1"></i>
                        <strong>Anleitung:</strong><br>
                        <pre class="mb-0 mt-1 small" style="white-space:pre-wrap"><?= htmlspecialchars($r['fix'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></pre>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="text-secondary small mt-3">
        Ausgeführt: <?= htmlspecialchars(date('d.m.Y H:i:s T'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
        · Datenbankhost: <?= htmlspecialchars($db_label, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
    </div>

</div>
</div>
</div>
</div>
</body>
</html>
