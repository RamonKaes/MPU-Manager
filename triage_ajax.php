<?php
declare(strict_types=1);

/**
 * triage_ajax.php – HTTP-Endpunkt für die Triage-Engine
 *
 * GET  ?action=config  → Gibt ERLAUBTE_HYPOTHESEN + ERLAUBTE_ABSTINENZ als JSON zurück
 *                        (kein CSRF erforderlich – nur statische Konfigurationsdaten)
 * POST                 → Live-Triage-Berechnung; erfordert CSRF-Token
 */

require_once __DIR__ . '/triage.php';
require_once __DIR__ . '/config/allowed_values.php';
require_once __DIR__ . '/logic/request.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Authentifizierung: kein Endpunkt antwortet am Login vorbei (S-1) ──────────
session_starten();
if (sitzung_pruefen() !== 'ok') {
    http_response_code(401);
    echo json_encode(['error' => 'session_expired']);
    exit;
}

// ── GET: statische Konfiguration für Frontend-Dropdowns ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'config') {
    echo json_encode([
        'hypothesen' => ERLAUBTE_HYPOTHESEN,
        'abstinenz'  => ERLAUBTE_ABSTINENZ,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: Live-Triage-Berechnung ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF']);
    exit;
}

$delikte    = array_values(array_filter((array)($_POST['deliktart']           ?? []), 'is_string'));
$drogen     = array_values(array_filter((array)($_POST['drogenart']           ?? []), 'is_string'));
$klassen    = array_values(array_filter((array)($_POST['fuehrerscheinklasse'] ?? []), 'is_string'));
$bkf_raw      = trim((string)($_POST['bkf_status'] ?? 'nein'));
$bkf_status   = in_array($bkf_raw, ['nein', 'aktiv', 'angestrebt'], true) ? $bkf_raw : 'nein';
$therapie_raw = trim((string)($_POST['therapie_art'] ?? 'keine'));
$therapie_art = in_array($therapie_raw, ['stationaer', 'ambulant', 'keine'], true) ? $therapie_raw : 'keine';

echo json_encode(berechne_triage(
    delikte:            $delikte,
    drogen:             $drogen,
    bak:                (float)($_POST['blutalkoholkonzentration']  ?? 0),
    therapy:            !empty($_POST['suchttherapie_absolviert']),
    therapie_art:       $therapie_art,
    erschwerend:        !empty($_POST['erschwerend_faktoren']),
    repeat:             !empty($_POST['fruehere_untersuchungen']),
    mehrfachdelikt:     !empty($_POST['mehrfachdelikt']),
    symptoms:           !empty($_POST['ausfallerscheinungen_notiert']),
    punkte:             (int)($_POST['punkte_flensburg']            ?? 0),
    aggression:         !empty($_POST['aggressionspotenzial']),
    thc_wert:           (float)($_POST['tetrahydrocannabinol_wert'] ?? 0),
    thc_cooh:           (float)($_POST['thc_cooh_value']           ?? 0),
    klassen:            $klassen,
    bkf_status:         $bkf_status,
    icd_kontrollverlust:!empty($_POST['icd_kontrollverlust']),
    icd_priorisierung:  !empty($_POST['icd_priorisierung']),
    icd_folgeschaeden:  !empty($_POST['icd_folgeschaeden']),
    icd_craving:        !empty($_POST['icd_craving']),
    icd_toleranz:       !empty($_POST['icd_toleranz']),
    icd_entzug:         !empty($_POST['icd_entzug']),
    persoenlichkeit:    !empty($_POST['persoenlichkeit']),
    medizinal_beikonsum:!empty($_POST['medizinal_beikonsum'])
), JSON_UNESCAPED_UNICODE);
exit;
