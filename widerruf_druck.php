<?php

declare(strict_types=1);

/**
 * widerruf_druck.php – Widerrufsbelehrung (druckbare HTML-Ansicht)
 */
require_once __DIR__ . '/db.php';
$praxis = require __DIR__ . '/config/praxis_config.php';

function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    // ARCHIV-MODUS
    $stmt = $pdo->prepare("SELECT first_name, last_name, date_of_birth, early_start_requested FROM clients WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $akte = $stmt->fetch();
    if (!$akte) {
        http_response_code(404);
        $_error_title = 'Fehler';
        $_error_message = 'Klient nicht gefunden.';
        require __DIR__ . '/partials/error.php';
        exit;
    }
    $vorname      = $akte['first_name'];
    $nachname     = $akte['last_name'];
    $geburtsdatum = $akte['date_of_birth'];
    $vorzeitiger_leistungsbeginn = (bool)($akte['early_start_requested'] ?? false);
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
    $vorname      = trim($_POST['vorname'] ?? '');
    $nachname     = trim($_POST['nachname'] ?? '');
    $geburtsdatum = trim($_POST['geburtsdatum'] ?? '');
    $vorzeitiger_leistungsbeginn = (isset($_POST['vorzeitiger_leistungsbeginn']) && $_POST['vorzeitiger_leistungsbeginn'] === '1');
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

$klient_name   = esc(trim($vorname . ' ' . $nachname));
$klient_geburt = esc($geburtsdatum_fmt);
$checked_attr  = $vorzeitiger_leistungsbeginn ? ' checked' : '';

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Widerrufsbelehrung – <?= $klient_name ?></title>
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

h1          { font-size: 20pt; font-weight: normal; text-align: center; margin: 14mm 0 10mm 0; }
.logo-wrap  { display: flex; align-items: center; gap: 8px; }
.logo-wrap img { width: 45px; height: 45px; }
.logo-text  { font-size: 10pt; color: #000; line-height: 1.4; }
.meta-box   { border: 0.5pt solid #ccc; padding: 3mm; margin-bottom: 7mm; border-collapse: collapse; width: 100%; }
.meta-label { font-size: 9.5pt; color: #555; width: 30mm; vertical-align: top; padding: 1.5mm 2mm; }
.meta-value { font-size: 10pt; color: #000; vertical-align: top; padding: 1.5mm 2mm; font-weight: bold; }
.agb-h1     { font-size: 12pt; font-weight: bold; margin-bottom: 5mm; margin-top: 8mm; }
.para-title { font-size: 11pt; font-weight: bold; margin-bottom: 1.5mm; margin-top: 5mm; }
.para-text  { font-size: 10.5pt; color: #000; margin-bottom: 0; text-align: justify; }
.sign-label { font-size: 10.5pt; font-weight: bold; margin-top: 8mm; margin-bottom: 12mm; }
.sign-line  { border-bottom: 1px solid #000; width: 100%; height: 0; }
.erkl-box   { border: 1px solid #ccc; padding: 4mm; margin: 4mm 0; display: flex; gap: 8px; align-items: flex-start; }
.erkl-box input[type="checkbox"] { width: 13px; height: 13px; margin-top: 3px; flex-shrink: 0; }
.platzhalter    { border: 1px dashed #000; padding: 4mm; margin: 4mm 0; font-size: 10.5pt; }
</style>
</head>
<body>

<div class="print-bar">
    <button type="button" id="btn-print">&#128438; Drucken / Als PDF speichern</button>
    <a href="javascript:window.close()">Schließen</a>
    <span style="margin-left:auto;color:#94a3b8;">Widerrufsbelehrung &ndash; <?= $klient_name ?></span>
</div>

<div class="dokument">

<div class="logo-wrap">
    <img src="assets/img/document-logo.svg" alt="Logo">
    <span class="logo-text"><?= esc($praxis['bezeichnung']) ?><br><?= esc($praxis['name']) ?></span>
</div>

<h1>Widerrufsbelehrung</h1>

<table class="meta-box"><tbody>
    <tr><td class="meta-label">Klient/in:</td><td class="meta-value"><?= $klient_name ?></td></tr>
    <tr><td class="meta-label">Geburtsdatum:</td><td class="meta-value"><?= $klient_geburt ?></td></tr>
    <tr><td class="meta-label">Ausgestellt am:</td><td style="font-size:10pt;padding:1.5mm 2mm;"><?= $heute ?></td></tr>
</tbody></table>

<table class="meta-box"><tbody>
    <tr><td class="meta-label">Widerruf an:</td><td style="font-size:10pt;padding:1.5mm 2mm;"><?= esc($praxis['name']) ?> &middot; <?= esc($praxis['strasse']) ?>, <?= esc($praxis['plz']) ?> <?= esc($praxis['ort']) ?><br>Telefon: <?= esc($praxis['telefon']) ?> &middot; E-Mail: <?= esc($praxis['email']) ?></td></tr>
</tbody></table>

<div class="platzhalter">[Eigene, rechtlich geprüfte Widerrufsbelehrung einfügen, Ausgangspunkt ist das amtliche Muster (Anlage 1 zu Art. 246a § 1 Abs. 2 Satz 2 EGBGB). Dieses Repository enthält bewusst keinen Text.]</div>

<br>
<p class="sign-label">Die Widerrufsbelehrung habe ich erhalten und zur Kenntnis genommen:</p>
<p class="para-text">Datum, Ort und Unterschrift des Klienten / der Klientin:</p>
<div class="sign-line"></div>

<p class="agb-h1">Erklärung zum vorzeitigen Leistungsbeginn (§ 356 Abs. 4 BGB)</p>
<div class="erkl-box">
    <input type="checkbox"<?= $checked_attr ?>>
    <span style="font-size:10.5pt;">[Eigene, rechtlich geprüfte Erklärung zum vorzeitigen Leistungsbeginn einfügen.]</span>
</div>

<br>
<p class="sign-label">Datum, Ort und Unterschrift des Klienten / der Klientin:</p>
<div class="sign-line"></div>

</div><!-- .dokument -->

<script nonce="<?= $csp_nonce ?>">
document.getElementById('btn-print').addEventListener('click', function () { window.print(); });
document.fonts.ready.then(function() { window.print(); });
</script>
</body>
</html>
