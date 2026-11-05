<?php
declare(strict_types=1);

require_once __DIR__ . '/logic/request.php';
require_once __DIR__ . '/logic/db_connect.php';

function auth_log_event(string $event_type, ?string $username): void {
    try {
        $pdo = mpu_pdo_connect();
        $pdo->prepare(
            'INSERT INTO auth_log (event_type, username, ip_address, user_agent) VALUES (?, ?, ?, ?)'
        )->execute([
            $event_type,
            $username,
            client_adresse(),
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (\Throwable) {}
}

/**
 * Kennung einer Anmeldung: gilt nur für diesen Nutzer, diese Installation und diesen Passwort-Hash.
 * Damit reicht kein `user` in einer fremden Sitzung, und das Entfernen oder Ändern in
 * config/users.php beendet auch laufende Sitzungen.
 */
function anmelde_kennung(string $benutzer): ?string {
    $datei = __DIR__ . '/config/users.php';
    $liste = is_file($datei) ? require $datei : [];
    $hash  = $liste[$benutzer]['hash'] ?? null;
    return is_string($hash) ? hash_hmac('sha256', $benutzer . '|' . __DIR__, $hash) : null;
}

function anmeldung_eintragen(string $benutzer, string $anzeigename): void {
    session_regenerate_id(true);
    $_SESSION['user']           = $benutzer;
    $_SESSION['display_name']   = $anzeigename;
    $_SESSION['mpu_anmeldung']  = anmelde_kennung($benutzer);
    $_SESSION['last_activity']  = time();
}

/** Name des angemeldeten Nutzers oder null. Eine ungültige Anmeldung wird dabei entfernt. */
function angemeldeter_benutzer(): ?string {
    $benutzer = $_SESSION['user'] ?? null;
    if (!is_string($benutzer) || $benutzer === '') {
        return null;
    }
    $kennung = anmelde_kennung($benutzer);
    if ($kennung !== null && hash_equals($kennung, (string)($_SESSION['mpu_anmeldung'] ?? ''))) {
        return $benutzer;
    }
    unset($_SESSION['user'], $_SESSION['display_name'], $_SESSION['mpu_anmeldung'], $_SESSION['last_activity']);
    return null;
}

/**
 * Prüft Anmeldung und Leerlauffrist, zählt die Frist weiter und räumt eine abgelaufene Sitzung ab.
 * Einzige Prüfung für require_auth() und triage_ajax.php.
 *
 * @return 'ok'|'anonym'|'abgelaufen'
 */
function sitzung_pruefen(): string {
    $benutzer = angemeldeter_benutzer();
    if ($benutzer === null) {
        return 'anonym';
    }
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        auth_log_event('timeout', $benutzer);
        session_unset();
        session_destroy();
        return 'abgelaufen';
    }
    $_SESSION['last_activity'] = time();
    return 'ok';
}

function require_auth(): void {
    if (php_sapi_name() === 'cli') {
        return;
    }
    $zustand = sitzung_pruefen();
    if ($zustand === 'ok') {
        return;
    }
    if ($zustand === 'abgelaufen') {
        if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
            http_response_code(401);
            exit;
        }
        header('Location: login.php?reason=timeout');
        exit;
    }
    $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: login.php' . ($next !== '' ? '?next=' . $next : ''));
    exit;
}
