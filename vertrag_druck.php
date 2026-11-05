<?php

declare(strict_types=1);

/**
 * vertrag_druck.php – Beratungsvertrag (druckbare HTML-Ansicht)
 *
 * Enthält bewusst keinen Vertragstext: Jeder Fork setzt einen eigenen, rechtlich
 * geprüften Text ein.
 */
require_once __DIR__ . '/db.php';
$praxis = require __DIR__ . '/config/praxis_config.php';

function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    // ARCHIV-MODUS
    $stmt = $pdo->prepare("
        SELECT c.*, ca.package_type, ca.package_price, ca.hourly_rate
        FROM clients c
        LEFT JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
        WHERE c.id = ?
    ");
    $stmt->execute([(int)$_GET['id']]);
    $akte = $stmt->fetch();
    if (!$akte) {
        http_response_code(404);
        $_error_title = 'Fehler';
        $_error_message = 'Klient nicht gefunden.';
        require __DIR__ . '/partials/error.php';
        exit;
    }

    $vorname           = $akte['first_name'];
    $nachname          = $akte['last_name'];
    $geburtsdatum      = $akte['date_of_birth'];
    $postleitzahl      = $akte['zip_code'];
    $wohnort           = $akte['city'];
    $telefonnummer     = $akte['phone'];
    $email             = $akte['email'];
    $abrechnungsmodell = $akte['package_type'] ?? 'paket';
    $honorar_gesamt    = $akte['package_price'] ? number_format((float)$akte['package_price'], 2, ',', '.') . ' €' : '';
    $stundensatz       = $akte['hourly_rate'] ? number_format((float)$akte['hourly_rate'], 2, ',', '.') . ' €' : '';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/partials/error.php';
        exit;
    }

    // LIVE-MODUS
    $vorname           = trim($_POST['vorname'] ?? '');
    $nachname          = trim($_POST['nachname'] ?? '');
    $geburtsdatum      = trim($_POST['geburtsdatum'] ?? '');
    $postleitzahl      = trim($_POST['postleitzahl'] ?? '');
    $wohnort           = trim($_POST['wohnort'] ?? '');
    $telefonnummer     = trim($_POST['telefonnummer'] ?? '');
    $email             = trim($_POST['email_adresse'] ?? '');
    $abrechnungsmodell = $_POST['abrechnungsmodell'] ?? 'paket';
    $honorar_gesamt    = !empty($_POST['honorar_gesamt']) ? number_format((float)$_POST['honorar_gesamt'], 2, ',', '.') . ' €' : '';
    $stundensatz       = !empty($_POST['stundensatz']) ? number_format((float)$_POST['stundensatz'], 2, ',', '.') . ' €' : '';
} else {
    http_response_code(400);
    $_error_title = 'Fehler';
    $_error_message = 'Keine Daten übergeben.';
    require __DIR__ . '/partials/error.php';
    exit;
}

$heute = date('d.m.Y');

if ($geburtsdatum !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $geburtsdatum);
    $geburtsdatum_fmt = $dt ? $dt->format('d.m.Y') : esc($geburtsdatum);
} else { $geburtsdatum_fmt = ''; }

$plz_ort       = trim(esc($postleitzahl) . ' ' . esc($wohnort));
$klient_name   = esc(trim($vorname . ' ' . $nachname));
$klient_geburt = esc($geburtsdatum_fmt);
$klient_tel    = esc($telefonnummer);
$klient_email  = esc($email);

if ($abrechnungsmodell === 'einzel') {
    $verguetung_label = 'Stundensatz:';
    $verguetung_wert  = esc($stundensatz);
} else {
    $verguetung_label = 'Pauschalhonorar:';
    $verguetung_wert  = esc($honorar_gesamt);
}

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Beratungsvertrag – <?= $klient_name ?></title>
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

h1              { font-size: 22pt; font-weight: normal; text-align: center; margin: 18mm 0 12mm 0; }
.logo-wrap      { display: flex; align-items: center; gap: 8px; }
.logo-wrap img  { width: 45px; height: 45px; }
.logo-text      { font-size: 10pt; color: #000; line-height: 1.4; }
.parteien-titel { font-size: 12pt; font-weight: bold; margin-bottom: 6mm; }
.partei-label   { font-size: 10pt; color: #000; margin-bottom: 2mm; }
.partei-name    { font-size: 12pt; font-weight: bold; margin-bottom: 3mm; }
.parteien-grid  { display: grid; grid-template-columns: 1fr 1fr; gap: 0 8mm; margin-bottom: 4mm; }
.feld-table     { border-collapse: collapse; }
.feld-table td  { vertical-align: top; padding-bottom: 1.5mm; }
.feld-label     { font-size: 9.5pt; color: #555; width: 32mm; }
.feld-value     { font-size: 10pt; color: #000; }
.agb-h1         { font-size: 13pt; font-weight: bold; margin-bottom: 7mm; }
.para-title     { font-size: 11pt; font-weight: bold; margin-bottom: 1.5mm; margin-top: 5mm; }
.para-text      { font-size: 10.5pt; color: #000; margin-bottom: 0; text-align: justify; }
.sign-label     { font-size: 11pt; font-weight: bold; margin-top: 10mm; margin-bottom: 14mm; }
.sign-line      { border-bottom: 1px solid #000; width: 100%; height: 0; }
.pagebreak      { break-after: page; page-break-after: always; }
.platzhalter    { border: 1px dashed #000; padding: 4mm; margin: 4mm 0; font-size: 10.5pt; }
</style>
</head>
<body>

<div class="print-bar">
    <button type="button" id="btn-print">&#128438; Drucken / Als PDF speichern</button>
    <a href="javascript:window.close()">Schließen</a>
    <span style="margin-left:auto;color:#94a3b8;">Beratungsvertrag &ndash; <?= $klient_name ?></span>
</div>

<div class="dokument">

<!-- SEITE 1 – Deckblatt -->
<div class="logo-wrap">
    <img src="assets/img/document-logo.svg" alt="Logo">
    <span class="logo-text"><?= esc($praxis['bezeichnung']) ?><br><?= esc($praxis['name']) ?></span>
</div>

<h1>Beratungsvertrag</h1>

<p class="parteien-titel">Vertragsparteien:</p>

<div class="parteien-grid">
    <div>
        <p class="partei-label">Klient/in</p>
        <p class="partei-name"><?= $klient_name ?></p>
        <table class="feld-table"><tbody>
            <tr><td class="feld-label">Geburtsdatum:</td><td class="feld-value"><?= $klient_geburt ?></td></tr>
            <tr><td class="feld-label">Anschrift:</td><td class="feld-value"><?= $plz_ort ?></td></tr>
            <tr><td class="feld-label">Telefon:</td><td class="feld-value"><?= $klient_tel ?></td></tr>
            <tr><td class="feld-label">E-Mail Adresse:</td><td class="feld-value"><?= $klient_email ?></td></tr>
        </tbody></table>
    </div>
    <div>
        <p class="partei-label"><?= esc($praxis['berufsbezeichnung']) ?></p>
        <p class="partei-name"><?= esc($praxis['name']) ?></p>
        <table class="feld-table"><tbody>
            <tr><td class="feld-label">Anschrift:</td><td class="feld-value"><?= esc($praxis['strasse']) ?><br><?= esc($praxis['plz']) ?> <?= esc($praxis['ort']) ?></td></tr>
            <tr><td class="feld-label">Telefon:</td><td class="feld-value"><?= esc($praxis['telefon']) ?></td></tr>
            <tr><td class="feld-label">E-Mail Adresse:</td><td class="feld-value"><?= esc($praxis['email']) ?></td></tr>
        </tbody></table>
    </div>
</div>

<div class="pagebreak"></div>

<!-- SEITE 2 – Vertragstext des eigenen Forks -->
<p class="agb-h1">Vertragsbedingungen</p>

<table class="feld-table"><tbody>
    <tr><td class="feld-label"><?= $verguetung_label ?></td><td class="feld-value"><?= $verguetung_wert ?></td></tr>
</tbody></table>

<div class="platzhalter">[Eigenen, rechtlich geprüften Vertragstext einfügen. Dieses Repository enthält bewusst keinen.]</div>

<br><br><br>
<p class="sign-label">Datum, Ort und Unterschrift des Auftraggebers:</p>
<div class="sign-line"></div>

<br><br><br>
<p class="sign-label">Datum, Ort und Unterschrift (Stempel) des Beraters:</p>
<div class="sign-line"></div>

</div><!-- .dokument -->

<script nonce="<?= $csp_nonce ?>">
document.getElementById('btn-print').addEventListener('click', function () { window.print(); });
document.fonts.ready.then(function() { window.print(); });
</script>
</body>
</html>
