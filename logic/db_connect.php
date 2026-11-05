<?php
declare(strict_types=1);

/**
 * logic/db_connect.php – Zentrale PDO-Verbindungsfabrik
 * -----------------------------------------------------------------------------
 * Einzige Stelle, an der eine PDO-Verbindung aufgebaut wird. Bewusst auth-frei
 * gehalten: login.php und auth.php dürfen db.php nicht laden, da dessen
 * require_auth() einen Redirect auslösen würde – sie nutzen diese Fabrik direkt.
 *
 * Wirft RuntimeException bei fehlenden Zugangsdaten und PDOException bei
 * Verbindungsfehlern; die Aufrufer entscheiden über die Fehlerbehandlung
 * (db.php: Fehlerseite; login.php/auth.php: stille Degradation).
 */
function mpu_pdo_connect(): PDO
{
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if ($user === false || $user === '') {
        throw new RuntimeException('Umgebungsvariable DB_USER ist nicht gesetzt.');
    }
    if ($pass === false) {
        throw new RuntimeException('Umgebungsvariable DB_PASS ist nicht gesetzt.');
    }

    return new PDO(
        sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'localhost',
            getenv('DB_NAME') ?: 'mpu'
        ),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]
    );
}
