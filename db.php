<?php

declare(strict_types=1);

/**
 * db.php
 * ----------------------------------------
 * Zentrale PDO-Datenbankverbindung
 * für den MPU-Manager
 */

// ==============================
// 0. Globales Error-Handling
// ==============================

/**
 * Zeigt die generische Fehlerseite, loggt den Fehler und beendet die Ausgabe.
 * Wird vom Exception-Handler und vom PDO-Catch-Block verwendet.
 */
function mpu_show_error_page(string $log_message): never {
    global $csp_nonce;
    error_log('[MPU Fehler] ' . $log_message);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    require __DIR__ . '/partials/error.php';
    exit;
}

// Wandelt PHP-Warnungen (E_WARNING und aufwärts) in ErrorExceptions um,
// damit sie vom Exception-Handler erfasst werden.
// Respektiert den @-Operator (error_reporting() & $errno).
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}, E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR);

// Fängt alle nicht abgefangenen Exceptions und Fatal Errors ab.
set_exception_handler(function (Throwable $e): void {
    mpu_show_error_page(
        get_class($e) . ': ' . $e->getMessage() .
        ' in ' . $e->getFile() . ':' . $e->getLine()
    );
});

// ==============================
// 1. Session & Sicherheits-Token
// ==============================
require_once __DIR__ . '/logic/request.php';
session_starten();

// CSRF-Token: einmal pro Session erzeugen
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

require_once __DIR__ . '/auth.php';
require_auth();

// ── Flash-Meldungen ───────────────────────────────────────────────────────────
// Schreibt eine einmalige Meldung in die Session; wird beim nächsten Request
// von get_flash() ausgelesen und sofort gelöscht.
// $type: 'success' | 'warning' | 'danger'
function set_flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

// Liest die Flash-Meldung aus der Session und löscht sie.
// Gibt null zurück, wenn keine Meldung vorhanden ist.
function get_flash(): ?array {
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// CSP-Nonce: pro Request neu (schützt Inline-Scripts)
$csp_nonce = base64_encode(random_bytes(16));

// ==============================
// HTTP-Security-Header
// ==============================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
if (request_ist_https()) {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
}
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$csp_nonce}'; " .
    "style-src 'self' 'unsafe-inline'; " .   // Tabler nutzt inline-styles via JS
    "font-src 'self'; " .
    "img-src 'self' data:; " .                // data: für SVG-Inline-Logos
    "connect-src 'self'; " .
    "frame-ancestors 'none';"
);

// ==============================
// 1. Datenbankverbindung (zentrale Fabrik, siehe logic/db_connect.php)
// ==============================
require_once __DIR__ . '/logic/db_connect.php';

try {
    $pdo = mpu_pdo_connect();
} catch (PDOException $e) {
    mpu_show_error_page('PDOException: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}