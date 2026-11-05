<?php

declare(strict_types=1);

/**
 * logic/request.php
 * ----------------------------------------
 * Eigenschaften des HTTP-Requests und einheitlicher Session-Start.
 *
 * Bewusst ohne Abhängigkeiten: login.php und logout.php binden diese Datei
 * ein, bevor db.php geladen wird (und damit vor require_auth()).
 */

/**
 * Standard-Vertrauensgrenze für X-Forwarded-Proto, wenn die Umgebung nichts
 * anderes vorgibt.
 *
 * Nur Loopback: terminiert ein Reverse Proxy auf demselben Host das TLS
 * (lokal Caddy vor Apache:8088), ist REMOTE_ADDR die Loopback-Adresse. Ein
 * Client aus dem Netz darf die HTTPS-Erkennung nie selbst steuern können.
 */
const VERTRAUTE_PROXY_IPS = ['127.0.0.1', '::1'];

/** Leerlauffrist der Anmeldung. Hier, weil auch der Session-Start sie braucht. */
const SESSION_IDLE_TIMEOUT = 1800;

/**
 * Vertrauenswürdige Proxy-Adressen für diesen Host.
 *
 * `TRUSTED_PROXY_IPS` (kommaseparriert) überschreibt die Voreinstellung. Zwei
 * Fälle, die die reine Loopback-Regel nicht abdeckt:
 *
 *   TRUSTED_PROXY_IPS="10.0.0.5,10.0.0.6"  Proxy liegt auf einem anderen Host
 *   TRUSTED_PROXY_IPS=""                   Header nie auswerten (härteste Stufe)
 *
 * @return string[] leere Liste = X-Forwarded-Proto wird ignoriert
 */
function vertraute_proxy_ips(): array {
    $roh = getenv('TRUSTED_PROXY_IPS');
    if ($roh === false) {
        return VERTRAUTE_PROXY_IPS;
    }
    return array_values(array_filter(
        array_map('trim', explode(',', (string)$roh)),
        static fn(string $ip): bool => $ip !== ''
    ));
}

/**
 * Hat der Betreiber erklärt, dass diese Installation ausschließlich über
 * HTTPS ausgeliefert wird?
 *
 * `APP_FORCE_HTTPS=1` ist die deterministische Variante für Produktion: sie
 * kommt ohne jede Header-Auswertung aus und ist damit unabhängig davon, ob
 * der Hoster einen Proxy vorschaltet, welche IP dieser hat und ob er
 * X-Forwarded-Proto überhaupt setzt.
 *
 * ACHTUNG: Auf einer Installation, die tatsächlich über HTTP erreichbar ist,
 * sperrt das die Anmeldung aus — das Session-Cookie bekommt das Secure-Flag
 * und wird vom Browser über HTTP nicht mehr gesendet.
 */
function umgebung_erzwingt_https(): bool {
    return in_array(strtolower(trim((string)getenv('APP_FORCE_HTTPS'))), ['1', 'true', 'on', 'yes'], true);
}

/**
 * Kam der Request beim Browser über HTTPS an?
 *
 * Terminiert ein Reverse Proxy das TLS, sieht Apache nur eine
 * HTTP-Verbindung und $_SERVER['HTTPS'] bleibt leer. Ohne Auswertung von
 * X-Forwarded-Proto verliert das Session-Cookie dann still sein Secure-Flag
 * und der HSTS-Header entfällt — ohne jede Fehlermeldung.
 *
 * Reihenfolge der Erkennung:
 *   1. echtes TLS an diesem Server        ($_SERVER['HTTPS'])
 *   2. Erklärung des Betreibers           (APP_FORCE_HTTPS)
 *   3. Header eines vertrauten Proxys     (X-Forwarded-Proto)
 *
 * Zu Stufe 3: der Header wird nur von Adressen aus vertraute_proxy_ips()
 * akzeptiert. Auf dem Standardwert (Loopback) heißt das, dass jeder Prozess
 * auf demselben Host ihn setzen kann — nachgewiesen. Tragbar ist das, weil
 * der Fehlerfall nach der sicheren Seite offen ist: ein fälschlich als HTTPS
 * erkannter Request setzt das Secure-Flag, wodurch das Cookie über HTTP nicht
 * mehr gesendet wird. Das kostet die Session, kann sie aber nicht preisgeben.
 * Umgekehrt wäre der Fehler gravierend. Wem das nicht reicht, setzt
 * TRUSTED_PROXY_IPS="" und arbeitet über APP_FORCE_HTTPS.
 */
function request_ist_https(): bool {
    if (isset($_SERVER['HTTPS']) && strcasecmp((string)$_SERVER['HTTPS'], 'off') !== 0) {
        return true;
    }
    if (umgebung_erzwingt_https()) {
        return true;
    }
    $vertraut = vertraute_proxy_ips();
    if ($vertraut === [] || !in_array($_SERVER['REMOTE_ADDR'] ?? '', $vertraut, true)) {
        return false;
    }
    // Bei verketteten Proxys steht das ursprüngliche Protokoll an erster Stelle.
    $protokoll = explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0];
    return strcasecmp(trim($protokoll), 'https') === 0;
}

/**
 * Adresse des Clients. Hinter einem vertrauten Proxy ist REMOTE_ADDR dessen Adresse, ohne diese
 * Auswertung sperrten fünf Fehlversuche alle Nutzer. Von rechts wird der erste nicht vertraute
 * Eintrag genommen: Was links davon steht, kann der Client selbst geschrieben haben.
 */
function client_adresse(): string {
    $direkt   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $vertraut = vertraute_proxy_ips();
    if (!in_array($direkt, $vertraut, true)) {
        return $direkt;
    }
    $kette = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    foreach ($kette as $eintrag) {
        if (filter_var($eintrag, FILTER_VALIDATE_IP) !== false && !in_array($eintrag, $vertraut, true)) {
            return $eintrag;
        }
    }
    return $direkt;
}

/**
 * Cookie-Parameter der Session. Einzige Quelle für db.php, login.php und
 * logout.php: weichen die Parameter zwischen den drei Einsprungpunkten ab,
 * entstehen zwei getrennte Sessions und jeder POST scheitert an der
 * CSRF-Prüfung.
 *
 * @return array<string, mixed>
 */
function session_cookie_parameter(): array {
    return [
        'path'     => '/',
        'secure'   => request_ist_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

/**
 * Startet die Session mit diesen Parametern, falls noch keine läuft.
 *
 * Der Cookie-Name hängt an der Installation: Zwei Anwendungen oder Installationen auf
 * einer Domain teilen sich sonst über den Pfad `/` dieselbe Sitzung.
 */
function session_starten(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    session_name('MPU_' . substr(hash('sha256', dirname(__DIR__)), 0, 12));
    // PHP-Vorgabe sind 24 Minuten: Die Sitzungsdatei wäre weg, bevor die 30 Minuten um sind.
    ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_TIMEOUT + 300));
    session_set_cookie_params(['lifetime' => 0] + session_cookie_parameter());
    session_start();
}
