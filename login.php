<?php
declare(strict_types=1);

require_once __DIR__ . '/logic/request.php';
session_starten();
require_once __DIR__ . '/auth.php';

// Bereits angemeldet → weiterleiten
if (angemeldeter_benutzer() !== null) {
    header('Location: index.php');
    exit;
}

$csp_nonce = base64_encode(random_bytes(16));

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
if (request_ist_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{$csp_nonce}'; " .
    "style-src 'self' 'unsafe-inline'; " .
    "font-src 'self'; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-ancestors 'none';"
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ?next= validieren: nur relativer Pfad erlaubt, kein Schema/Host
function validiere_next(string $raw): string {
    if ($raw === '') {
        return '';
    }
    // Backslashes generell verbieten: Browser normalisieren '\' → '/', wodurch
    // '/\evil.com' zur protokoll-relativen URL '//evil.com' wird (externe
    // Weiterleitung). parse_url erkennt hier weder Scheme noch Host.
    if (str_contains($raw, '\\')) {
        return '';
    }
    $teile = parse_url($raw);
    if ($teile === false || !empty($teile['scheme']) || !empty($teile['host'])) {
        return '';
    }
    $pfad = $teile['path'] ?? '';
    // Nur echte interne Pfade: '/' gefolgt von einem Nicht-Slash-Zeichen.
    // Weist '//evil.com' und '/\evil.com' zusätzlich ab (Defense-in-Depth).
    if ($pfad === '' || !preg_match('#^/(?![\\\\/])#', $pfad)) {
        return '';
    }
    $next = $pfad;
    if (!empty($teile['query'])) {
        $next .= '?' . $teile['query'];
    }
    return $next;
}

// Eigene PDO-Verbindung (db.php wäre wegen require_auth() nicht verwendbar)
require_once __DIR__ . '/logic/db_connect.php';
$login_pdo = null;
try {
    $login_pdo = mpu_pdo_connect();
} catch (\Throwable) {
    // Bei DB-Ausfall ist keine Anmeldung möglich, siehe unten: Sonst gäbe es keine Sperre.
}

$ip_adresse = client_adresse();
$gesperrt   = false;

if ($login_pdo !== null) {
    try {
        $login_pdo->prepare(
            'DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 15 MINUTE'
        )->execute();

        $stmt = $login_pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
        );
        $stmt->execute([$ip_adresse]);
        if ((int) $stmt->fetchColumn() >= 5) {
            $gesperrt = true;
            http_response_code(429);
        }
    } catch (\PDOException $e) {
        // Tabellen fehlen (Schema unvollständig): behandelt wie ein Ausfall
        $login_pdo = null;
    }
}

$fehlermeldung = '';
$grund = is_string($_GET['reason'] ?? null) ? $_GET['reason'] : '';
$next  = validiere_next(is_string($_GET['next'] ?? null) ? $_GET['next'] : '');

// Formularfelder können Arrays sein (feld[]=x), hier antwortet noch kein Fehlerhandler.
$post_text  = static fn(string $feld): string => is_string($_POST[$feld] ?? null) ? $_POST[$feld] : '';
$versuch_id = 0;

if ($login_pdo === null) {
    http_response_code(503);
    $fehlermeldung = 'Die Anmeldung ist gerade nicht möglich, die Datenbank ist nicht erreichbar.';
}

if (!$gesperrt && $login_pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $post_text('csrf_token'))) {
        http_response_code(403);
        exit('Ungültiges CSRF-Token.');
    }

    // Erst zählen, dann rechnen: Sonst passieren parallele Versuche die Sperre, bevor der erste
    // eingetragen ist. Eine gelungene Anmeldung nimmt ihren Eintrag wieder heraus.
    $login_pdo->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')->execute([$ip_adresse]);
    $versuch_id = (int)$login_pdo->lastInsertId();
    $zaehlung = $login_pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at >= NOW() - INTERVAL 15 MINUTE');
    $zaehlung->execute([$ip_adresse]);
    if ((int)$zaehlung->fetchColumn() > 5) {
        $gesperrt = true;
        http_response_code(429);
    }
}

if (!$gesperrt && $login_pdo !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $next         = validiere_next($post_text('next'));
    $benutzername = trim($post_text('benutzername'));
    $passwort     = $post_text('passwort');
    $benutzer     = require __DIR__ . '/config/users.php';

    // Auch für unbekannte Namen rechnen: Sonst verrät die Antwortzeit, welche Namen es gibt.
    // Der Hash gehört zu keinem Passwort und öffnet nichts.
    $vergleichs_hash = $benutzer[$benutzername]['hash'] ?? '$2y$12$CC8Zm7Qm5fZcuqVO5MxTEuqTJcaXrAyPKBWtLUVHXJal8hbq.m5TK';
    $passwort_passt  = password_verify($passwort, $vergleichs_hash);
    if ($benutzername !== '' && isset($benutzer[$benutzername]) && $passwort_passt) {
        anmeldung_eintragen($benutzername, (string)$benutzer[$benutzername]['display_name']);
        try {
            $login_pdo->prepare('DELETE FROM login_attempts WHERE id = ?')->execute([$versuch_id]);
            $login_pdo->prepare(
                'INSERT INTO auth_log (event_type, username, ip_address, user_agent) VALUES (?, ?, ?, ?)'
            )->execute(['login_ok', $benutzername, $ip_adresse, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
        } catch (PDOException) {}
        header('Location: ' . ($next !== '' ? $next : 'index.php'));
        exit;
    }

    try {
        // Unbekannte Namen nicht speichern: Hier landet auch ein ins falsche Feld getipptes Passwort.
        $login_pdo->prepare(
            'INSERT INTO auth_log (event_type, username, ip_address, user_agent) VALUES (?, ?, ?, ?)'
        )->execute(['login_fail', isset($benutzer[$benutzername]) ? $benutzername : null, $ip_adresse, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (PDOException) {}
    usleep(random_int(200_000, 500_000));
    $fehlermeldung = 'Benutzername oder Passwort falsch.';
}

$next_esc   = htmlspecialchars($next, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$csrf_esc   = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
$fehler_esc = htmlspecialchars($fehlermeldung, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$nonce_esc  = htmlspecialchars($csp_nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<script nonce="<?= $nonce_esc ?>">
(function(){var A=['light','dark'],p=new URLSearchParams(window.location.search).get('theme'),s=localStorage.getItem('tablerTheme');var t=(A.indexOf(p)!==-1?p:null)||(A.indexOf(s)!==-1?s:null)||'light';if(p!==null&&A.indexOf(p)!==-1){localStorage.setItem('tablerTheme',p);}document.documentElement.setAttribute('data-bs-theme',t);})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmelden – MPU-Manager</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="96x96" href="assets/img/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512x512.png">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="preload" href="assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/inter.css">
    <link rel="stylesheet" href="assets/css/tabler.min.css">
    <style>
        :root { --tblr-font-sans-serif: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; }
        body  { font-feature-settings: "cv03", "cv04", "cv11"; }
        /* Logo-Umschaltung Hell-/Dunkel-Modus: passendes SVG statt CSS-Invertierung */
        .navbar-brand .logo-dark { display: none; }
        [data-bs-theme="dark"] .navbar-brand .logo-light { display: none; }
        [data-bs-theme="dark"] .navbar-brand .logo-dark  { display: inline; }
    </style>
</head>
<body class="antialiased d-flex flex-column">
    <div class="page page-center">
        <div class="container-tight py-4">
            <div class="text-center mb-4">
                <a href="." class="navbar-brand">
                    <img src="assets/img/logo.svg" alt="MPU-Logo" width="40" height="40" class="logo-light">
                    <img src="assets/img/logo-dark.svg" alt="MPU-Logo" width="40" height="40" class="logo-dark">
                    <span class="ms-2 fs-3 fw-semibold">MPU-Manager</span>
                </a>
            </div>
            <div class="card card-md">
                <div class="card-body">
                    <h2 class="h2 text-center mb-4">Anmelden</h2>
                    <?php if ($gesperrt): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                        <div>Zu viele fehlgeschlagene Anmeldeversuche. Bitte warten Sie 15 Minuten.</div>
                    </div>
                    <?php else: ?>
                    <?php if ($grund === 'timeout'): ?>
                    <div class="alert alert-info" role="alert">
                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                        <div>Sie wurden nach 30 Minuten Inaktivität automatisch abgemeldet.</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($fehler_esc !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                        <div><?= $fehler_esc ?></div>
                    </div>
                    <?php endif; ?>
                    <form method="post" action="login.php" autocomplete="on" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= $csrf_esc ?>">
                        <input type="hidden" name="next" value="<?= $next_esc ?>">
                        <div class="mb-3">
                            <label class="form-label" for="benutzername">Benutzername</label>
                            <input type="text" id="benutzername" name="benutzername"
                                   class="form-control" autocomplete="username"
                                   required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="passwort">Passwort</label>
                            <input type="password" id="passwort" name="passwort"
                                   class="form-control" autocomplete="current-password"
                                   required>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100">
                                Anmelden
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/tabler.min.js"></script>
</body>
</html>
