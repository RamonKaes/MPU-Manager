#!/usr/bin/env php
<?php
/**
 * scripts/dsgvo_check.php – DSGVO-Löscherinnerung (CLI)
 * -------------------------------------------------------
 * Liest Klienten mit abgelaufener Aufbewahrungsfrist aus der DB.
 * Gibt eine strukturierte Ausgabe nach STDOUT (kein HTML, kein HTTP-Output).
 *
 * Verwendung:
 *   php scripts/dsgvo_check.php
 *
 * Cron-Beispiel (monatlich, 1. des Monats 07:00):
 *   0 7 1 * * php /pfad/zur/installation/scripts/dsgvo_check.php >> /var/log/mpu_dsgvo.log 2>&1
 *
 * Credentials werden aus Umgebungsvariablen gelesen:
 *   DB_NAME, DB_USER, DB_PASS, DB_HOST (optional, Default: localhost)
 *
 * Exit-Codes:
 *   0 – kein fälliger Klient gefunden (oder Prüfung erfolgreich)
 *   1 – fällige Klienten gefunden (ermöglicht Cron-Alert via Monitoring)
 *   2 – Fehler (DB-Verbindung, fehlende Credentials)
 */

declare(strict_types=1);

// ── Sicherstellen, dass das Skript nur per CLI ausgeführt wird ────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

// ── Pfad zur Projektkonfiguration ─────────────────────────────────────────────
$project_root = dirname(__DIR__);
require_once $project_root . '/config/allowed_values.php';

// ── Credentials aus Umgebungsvariablen ────────────────────────────────────────
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_host = getenv('DB_HOST') ?: 'localhost';

foreach (['DB_NAME' => $db_name, 'DB_USER' => $db_user, 'DB_PASS' => $db_pass] as $varname => $val) {
    if ($val === false || $val === '') {
        fwrite(STDERR, "Fehler: Umgebungsvariable {$varname} ist nicht gesetzt.\n");
        exit(2);
    }
}

// ── Datenbankverbindung ───────────────────────────────────────────────────────
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Datenbankfehler: " . $e->getMessage() . "\n");
    exit(2);
}

// ── Fällige Klienten abfragen ─────────────────────────────────────────────────
// Klient ist fällig wenn:
//   – alle seine Fälle abgeschlossen sind (kein aktiver/pausierter Fall)
//   – der letzte Abschluss mehr als DSGVO_AUFBEWAHRUNG_JAHRE zurückliegt
$stmt = $pdo->prepare("
    SELECT c.id, c.first_name, c.last_name,
           MAX(ca.closed_at) AS letzter_abschluss,
           FLOOR(DATEDIFF(CURDATE(), MAX(ca.closed_at)) / 365) AS jahre_seit_abschluss
    FROM clients c
    JOIN cases ca ON ca.client_id = c.id
    GROUP BY c.id, c.first_name, c.last_name
    HAVING SUM(CASE WHEN ca.status IN ('aktiv', 'pausiert') THEN 1 ELSE 0 END) = 0
       AND MAX(ca.closed_at) IS NOT NULL
       AND MAX(ca.closed_at) <= DATE_SUB(CURDATE(), INTERVAL ? YEAR)
    ORDER BY letzter_abschluss ASC
");
$stmt->execute([DSGVO_AUFBEWAHRUNG_JAHRE]);
$faellig = $stmt->fetchAll();

// ── Ausgabe ───────────────────────────────────────────────────────────────────
$timestamp = date('Y-m-d H:i:s');
$anzahl    = count($faellig);

echo "=== DSGVO-Löscherinnerung · {$timestamp} ===\n";
echo "Aufbewahrungsfrist: " . DSGVO_AUFBEWAHRUNG_JAHRE . " Jahre (Frist des Betreibers)\n";
echo "\n";

if ($anzahl === 0) {
    echo "Ergebnis: Keine fälligen Klienten gefunden. Keine Aktion erforderlich.\n";
    exit(0);
}

echo "Ergebnis: {$anzahl} Klient(en) haben die Aufbewahrungsfrist überschritten.\n";
echo str_repeat('-', 60) . "\n";
printf("%-6s  %-30s  %-12s  %s\n", 'ID', 'Name', 'Abgeschlossen', 'Jahre');
echo str_repeat('-', 60) . "\n";

foreach ($faellig as $k) {
    $abschluss   = $k['letzter_abschluss'] ? date('d.m.Y', strtotime($k['letzter_abschluss'])) : '–';
    $jahre       = (int)$k['jahre_seit_abschluss'];
    printf("%-6s  %-12s  %d\n", '#' . str_pad((string)$k['id'], 5, '0', STR_PAD_LEFT), $abschluss, $jahre);
}

echo str_repeat('-', 60) . "\n";
echo "\nHinweis: Kein automatisches Löschen. Bitte fällige Klienten über dsgvo_loeschen.php löschen.\n";
echo "Direkt in der Datenbank blieben Texte im Audit-Log zurück.\n";

exit(1); // Exit-Code 1: fällige Klienten gefunden (ermöglicht Cron-Monitoring)
