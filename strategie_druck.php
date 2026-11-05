<?php

declare(strict_types=1);

/**
 * strategie_druck.php – Sicherheitsstrategie-Vereinbarung (druckbare HTML-Ansicht)
 */
require_once __DIR__ . '/db.php';
$praxis = require __DIR__ . '/config/praxis_config.php';

function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

$client_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client_id <= 0) {
    // LIVE-MODUS
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/partials/error.php';
        exit;
    }

    $d = [
        'first_name'           => trim($_POST['first_name'] ?? ''),
        'last_name'            => trim($_POST['last_name']  ?? ''),
        'date_of_birth'        => trim($_POST['date_of_birth'] ?? ''),
        'offense_category'     => $_POST['offense_category'] ?? '[]',
        'drug_types'           => $_POST['drug_types']       ?? '[]',
        'suggested_hypothesis' => trim($_POST['suggested_hypothesis'] ?? ''),
        'suggested_abstinence' => trim($_POST['suggested_abstinence'] ?? ''),
        'override_active'      => (int)($_POST['override_active'] ?? 0),
        'override_reason'      => trim($_POST['override_reason'] ?? ''),
        'bkf_status'           => in_array($_POST['bkf_status'] ?? '', ['nein', 'aktiv', 'angestrebt'])
                                    ? $_POST['bkf_status'] : 'nein',
    ];
    // UX-6: ICD-Dreizustand aus der Live-Vorschau übernehmen ('' = nicht erhoben → NULL)
    foreach (['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden',
              'icd_craving','icd_toleranz','icd_entzug'] as $_icd_feld) {
        $_raw = $_POST[$_icd_feld] ?? '';
        $d[$_icd_feld] = ($_raw === '1') ? 1 : (($_raw === '0') ? 0 : null);
    }
    unset($_icd_feld, $_raw);
    if (empty($d['first_name']) || empty($d['last_name'])) {
        http_response_code(400);
        $_error_title = 'Fehler';
        $_error_message = 'Vorname und Nachname fehlen.';
        require __DIR__ . '/partials/error.php';
        exit;
    }
} elseif ($client_id > 0) {
    // ARCHIV-MODUS
    $stmt = $pdo->prepare("
        SELECT c.*, ca.* FROM clients c
        LEFT JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
        WHERE c.id = ?
    ");
    $stmt->execute([$client_id]);
    $d = $stmt->fetch();

    if (!$d) {
        http_response_code(404);
        $_error_title = 'Fehler';
        $_error_message = 'Klient nicht gefunden.';
        require __DIR__ . '/partials/error.php';
        exit;
    }
} else {
    http_response_code(400);
    $_error_title = 'Fehler';
    $_error_message = 'Keine gültigen Daten übergeben.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// Daten aufbereiten
$klient_name  = esc(trim($d['first_name'] . ' ' . $d['last_name']));
$dt           = !empty($d['date_of_birth']) ? DateTime::createFromFormat('Y-m-d', $d['date_of_birth']) : null;
$geburtsdatum = $dt ? $dt->format('d.m.Y') : '–';
$heute        = date('d.m.Y');

$delikte     = json_decode($d['offense_category'] ?? '', true) ?: [];
$drogen      = json_decode($d['drug_types']       ?? '', true) ?: [];
$delikte_str = esc(implode(', ', $delikte) ?: '–');
$drogen_str  = esc(implode(', ', $drogen)  ?: '–');

$hypothese      = esc($d['suggested_hypothesis'] ?? '');
$abstinenz      = esc($d['suggested_abstinence'] ?? '');
$override       = (bool)($d['override_active']   ?? false);
$override_grund = esc(trim($d['override_reason'] ?? ''));
$bkf_status     = $d['bkf_status'] ?? 'nein';

// UX-6: Solange der ICD-Quick-Check unvollständig ist, ist die hier genannte
// Hypothese eine Untergrenze. Das muss auf einem Dokument stehen, das der
// Klient in die Hand bekommt – sonst liest sich die Abstinenzdauer als final.
require_once __DIR__ . '/logic/mpu_helper.php';
$abstinenz_monate = isset($d['abstinenz_monate'])
    ? (int)$d['abstinenz_monate']
    : abstinenz_monate_aus_text((string)($d['suggested_abstinence'] ?? ''));
$strategie_text = esc(strategie_zeile((string)($d['suggested_hypothesis'] ?? ''), $abstinenz_monate, (string)$bkf_status));

$triage_vollstaendigkeit = get_triage_vollstaendigkeit($d);
$vorlaeufig_block = '';
if ($triage_vollstaendigkeit['unvollstaendig']) {
    $vorlaeufig_block = '<div class="hinweis-box"><strong>Hinweis: Einstufung vorläufig</strong><br>'
        . 'Die Suchtanamnese ist noch nicht abgeschlossen ('
        . (int)$triage_vollstaendigkeit['offen'] . ' von ' . (int)$triage_vollstaendigkeit['gesamt']
        . ' Kriterien offen). Hypothese und Abstinenzdauer können sich nach dem '
        . 'vollständigen Erstgespräch noch erhöhen.</div>';
}

$override_block = '';
if ($override) {
    $grund = $override_grund ?: '(keine Begründung eingetragen)';
    $override_block = '<div class="hinweis-box"><strong>Hinweis: Berater-Override aktiv</strong><br>'
        . 'Die Empfehlung weicht vom Systemvorschlag ab. Begründung: ' . $grund . '</div>';
}

$bkf_block = '';
if ($bkf_status === 'aktiv') {
    $bkf_block = '<div class="hinweis-box"><strong>BKF-Hinweis (aktiver Berufskraftfahrer):</strong> '
        . 'Die für den Dienst geltenden Regeln (§ 24a StVG, bei Fahrgastbeförderung § 8 BOKraft) und mögliche Folgen '
        . 'für die Fahrerlaubnis zur Fahrgastbeförderung und die Gefahrgutberechtigung wurden besprochen.</div>';
} elseif ($bkf_status === 'angestrebt') {
    $bkf_block = '<div class="hinweis-box"><strong>BKF-Hinweis (Neuerteilung angestrebt):</strong> '
        . 'Die zusätzliche Untersuchung nach Anlage 5 FeV und die damit verbundenen höheren Anforderungen wurden besprochen.</div>';
}

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sicherheitsstrategie – <?= $klient_name ?></title>
<link rel="stylesheet" href="assets/fonts/cantarell.css">
<style>
@page { size: A4; margin: 22mm 25mm 20mm 25mm; }

* { box-sizing: border-box; }
body {
    font-family: 'Cantarell', sans-serif;
    font-size: 11pt;
    color: #000;
    line-height: 1.6;
    margin: 0;
    padding: 0;
    background: #fff;
}
.print-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    background: #f1f5f9;
    border-bottom: 1px solid #cbd5e1;
    font-family: sans-serif;
    font-size: 13px;
}
.print-bar button {
    padding: 6px 16px;
    background: #1d4ed8;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
}
.print-bar button:hover { background: #1e40af; }
.print-bar a {
    color: #64748b;
    text-decoration: none;
    font-size: 12px;
}
.dokument {
    max-width: 21cm;
    min-height: 29.7cm;
    margin: 0 auto;
    padding: 22mm 25mm 20mm;
    background: #fff;
}
@media screen {
    html { background: #e2e8f0; }
    .dokument {
        margin: 0 auto 40px;
        box-shadow: 0 2px 16px rgba(0,0,0,.18);
    }
    .print-bar { max-width: calc(21cm + 0px); margin: 0 auto; }
}
@media print {
    .print-bar { display: none !important; }
    .dokument { padding: 0; box-shadow: none; min-height: auto; }
    html, body { background: #fff; }
}

h1             { font-size: 18pt; font-weight: normal; text-align: center; margin: 12mm 0 3mm 0; }
.subtitle      { font-size: 10pt; color: #555; text-align: center; margin-bottom: 9mm; }
.logo-header   { display: flex; align-items: center; justify-content: space-between; }
.logo-wrap     { display: flex; align-items: center; gap: 8px; }
.logo-wrap img { width: 45px; height: 45px; }
.logo-text     { font-size: 10pt; color: #000; line-height: 1.4; }
.header-date   { font-size: 9.5pt; color: #555; }
.section-title { font-size: 11.5pt; font-weight: bold; border-bottom: 1px solid #000;
                 padding-bottom: 1.5mm; margin-top: 7mm; margin-bottom: 4mm; }
.feld-table    { border-collapse: collapse; }
.feld-table td { vertical-align: top; padding-bottom: 2mm; }
.feld-label    { font-size: 9.5pt; color: #555; width: 48mm; }
.feld-value    { font-size: 10.5pt; color: #000; }
.box           { border-left: 3px solid #333; background: #f5f5f5;
                 padding: 3.5mm 5mm; margin: 3mm 0; }
.box-label     { font-size: 9pt; color: #555; margin-bottom: 1mm; }
.box-value     { font-size: 12pt; font-weight: bold; margin: 0; }
.hinweis-box   { border-left: 3px solid #f59e0b; background: #fff8e1;
                 padding: 3mm 5mm; margin: 4mm 0; font-size: 10pt; }
.fliesstext    { font-size: 10.5pt; text-align: justify; margin-top: 3mm; }
.verpflichtung { font-size: 10.5pt; text-align: justify; margin-top: 5mm; }
.sign-label    { font-size: 10.5pt; font-weight: bold; margin-top: 10mm; margin-bottom: 12mm; }
.sign-line     { border-bottom: 1px solid #000; width: 100%; height: 0; }
.footer-note   { font-size: 9pt; color: #888; margin-top: 8mm; }
.platzhalter   { border: 1px dashed #000; padding: 4mm; margin: 4mm 0; font-size: 10.5pt; }
</style>
</head>
<body>

<div class="print-bar">
    <button type="button" id="btn-print">&#128438; Drucken / Als PDF speichern</button>
    <a href="javascript:window.close()">Schließen</a>
    <span style="margin-left:auto;color:#94a3b8;">Sicherheitsstrategie &ndash; <?= $klient_name ?></span>
</div>

<div class="dokument">

<div class="logo-header">
    <div class="logo-wrap">
        <img src="assets/img/document-logo.svg" alt="Logo">
        <span class="logo-text"><?= esc($praxis['bezeichnung']) ?><br><?= esc($praxis['name']) ?></span>
    </div>
    <span class="header-date"><?= esc($praxis['ort']) ?>, <?= $heute ?></span>
</div>

<h1>Sicherheitsstrategie-Vereinbarung</h1>
<p class="subtitle">Dokumentation der gemeinsam erarbeiteten Empfehlung</p>

<p class="section-title">Klient</p>
<table class="feld-table"><tbody>
    <tr><td class="feld-label">Name:</td>        <td class="feld-value"><?= $klient_name ?></td></tr>
    <tr><td class="feld-label">Geburtsdatum:</td><td class="feld-value"><?= $geburtsdatum ?></td></tr>
    <tr><td class="feld-label">Deliktart(en):</td><td class="feld-value"><?= $delikte_str ?></td></tr>
    <tr><td class="feld-label">Substanz(en):</td><td class="feld-value"><?= $drogen_str ?></td></tr>
</tbody></table>

<p class="section-title">Beratungsergebnis &amp; Empfehlung</p>

<div class="box">
    <p class="box-label">Ermittelte Hypothese(n)</p>
    <p class="box-value"><?= $hypothese ?><?= $triage_vollstaendigkeit['unvollstaendig'] ? ' (vorläufig)' : '' ?></p>
</div>

<div class="box">
    <p class="box-label">Empfohlene Abstinenzdauer</p>
    <p class="box-value"><?= $abstinenz ?></p>
</div>

<div class="box">
    <p class="box-label">Strategie</p>
    <p class="box-value"><?= $strategie_text ?></p>
</div>

<?= $vorlaeufig_block ?>
<?= $override_block ?>
<?= $bkf_block ?>

<p class="section-title">Vereinbarung</p>
<div class="platzhalter">[Eigenen, rechtlich geprüften Vereinbarungstext einfügen, zum Beispiel fachliche Grundlage, Vorbehalte und Bestätigung des Klienten. Dieses Repository enthält bewusst keinen.]</div>

<br><br><br>
<p class="sign-label">Datum, Ort und Unterschrift des Klienten:</p>
<div class="sign-line"></div>

<br><br><br>
<p class="sign-label">Datum, Ort und Unterschrift (Stempel) der Beratungsstelle:</p>
<div class="sign-line"></div>

<p class="footer-note">Erstellt mit MPU-Manager &middot; <?= $heute ?> &middot; Dieses Dokument ist Teil der Beratungsakte.</p>

</div><!-- .dokument -->

<script nonce="<?= $csp_nonce ?>">
document.getElementById('btn-print').addEventListener('click', function () { window.print(); });
document.fonts.ready.then(function() { window.print(); });
</script>
</body>
</html>
