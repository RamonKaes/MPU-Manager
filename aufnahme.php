<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'triage.php';
require_once 'logic/mpu_helper.php';
require_once 'config/allowed_values.php';

// UX-3: ICD-Schwelle für die Anzeigetexte aus derselben Quelle wie die Engine
// lesen (config/triage_config.php) statt sie in Prosa zu wiederholen – sonst
// laufen Text und Berechnung bei einer künftigen Schwellenänderung auseinander.
$_icd_cfg              = require 'config/triage_config.php';
$_icd_abhaengigkeit_min = (int)($_icd_cfg['icd']['abhaengigkeit_min'] ?? 3);
// E-6: AUDIT-/LAST-Schwellen aus derselben Quelle wie bearbeiten.php
$_screening_cfg = require 'config/screening_config.php';

$erfolgsmeldung = '';
$fehlermeldung  = '';


require __DIR__ . '/handlers/aufnahme_handler.php';

// Erfolgskarte nach der Weiterleitung, einmalig. Neuladen zeigt danach das leere Formular.
if (!empty($_SESSION['aufnahme_erfolg'])) {
    $_erfolg = $_SESSION['aufnahme_erfolg'];
    unset($_SESSION['aufnahme_erfolg']);
    $neue_klienten_id  = (int)$_erfolg['klient'];
    $neue_case_id      = (int)$_erfolg['fall'];
    $vorname           = (string)$_erfolg['vorname'];
    $nachname          = (string)$_erfolg['nachname'];
    $triage_vorlaeufig = (bool)$_erfolg['vorlaeufig'];
    $icd_nicht_erhoben = (array)$_erfolg['icd_offen'];
    $erfolgsmeldung    = 'gespeichert';
}
$page_title    = 'MPU-Manager | Klientenaufnahme';
$page_css_file = 'assets/css/formulare.css';
require 'partials/_head.php';
$active_page = 'aufnahme';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Akte anlegen</div>
                            <h1 class="page-title">Neue Klientenaufnahme</h1>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    
                    <?php if (!empty($erfolgsmeldung) && isset($neue_klienten_id)): ?>
                        <div class="card card-md mb-4" role="alert" aria-live="polite">
                            <div class="card-status-top bg-success"></div>
                            <div class="card-body">
                                <div class="row align-items-center mb-3">
                                    <div class="col-auto">
                                        <span class="avatar avatar-md bg-success-lt rounded-circle">
                                            <i class="ti ti-user-check fs-2" aria-hidden="true"></i>
                                        </span>
                                    </div>
                                    <div class="col">
                                        <h3 class="card-title mb-0">Klientenaufnahme abgeschlossen</h3>
                                        <p class="text-secondary mb-0">
                                            <strong><?= htmlspecialchars($vorname . ' ' . $nachname) ?></strong> wurde erfolgreich angelegt.
                                        </p>
                                    </div>
                                </div>
                                <?php if (!empty($triage_vorlaeufig)): ?>
                                <div class="alert alert-warning" role="alert">
                                    <div class="alert-icon"><i class="ti ti-hourglass icon alert-icon" aria-hidden="true"></i></div>
                                    <div>
                                        <h4 class="alert-heading">Triage vorl&auml;ufig &ndash; <?= count($icd_nicht_erhoben) ?> von 6 ICD-Kriterien noch offen</h4>
                                        <div class="alert-description">
                                            Nicht erhobene Kriterien z&auml;hlen in der Berechnung als &bdquo;nicht erf&uuml;llt&ldquo;. Die angezeigte
                                            Hypothese ist damit eine <strong>Untergrenze</strong> und kann sich beim Nachtragen noch versch&auml;rfen.
                                            Der Fall bleibt in Akte und Klientenliste als <em>vorl&auml;ufig</em> markiert.
                                            <div class="mt-2">
                                                <a href="bearbeiten.php?id=<?= (int)$neue_klienten_id ?>&amp;case_id=<?= (int)$neue_case_id ?>#icd_quickcheck" class="btn btn-sm btn-warning">
                                                    <i class="ti ti-edit me-1" aria-hidden="true"></i>Kriterien nachtragen
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <hr>
                                <p class="text-secondary small mb-2"><strong>Jetzt drucken und unterzeichnen lassen:</strong></p>
                                <div class="row g-2 mb-3">
                                    <div class="col-6 col-md-3">
                                        <a href="datenschutz_druck.php?id=<?= (int)$neue_klienten_id ?>" target="_blank"
                                           class="btn btn-outline-primary w-100">
                                            <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>Datenschutz&shy;vereinbarung
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <a href="vertrag_druck.php?id=<?= (int)$neue_klienten_id ?>" target="_blank"
                                           class="btn btn-outline-primary w-100">
                                            <i class="ti ti-writing me-1" aria-hidden="true"></i>Beratungs&shy;vertrag
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <a href="widerruf_druck.php?id=<?= (int)$neue_klienten_id ?>" target="_blank"
                                           class="btn btn-outline-primary w-100">
                                            <i class="ti ti-rotate-clockwise me-1" aria-hidden="true"></i>Widerrufs&shy;belehrung
                                        </a>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <a href="strategie_druck.php?id=<?= (int)$neue_klienten_id ?>" target="_blank"
                                           class="btn btn-outline-secondary w-100">
                                            <i class="ti ti-file-analytics me-1" aria-hidden="true"></i>Strategie&shy;bogen
                                        </a>
                                    </div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-auto">
                                        <a href="akte.php?id=<?= (int)$neue_klienten_id ?>" class="btn btn-success">
                                            <i class="ti ti-folder-check me-1" aria-hidden="true"></i>Zur digitalen Akte
                                        </a>
                                    </div>
                                    <div class="col-auto">
                                        <a href="aufnahme.php" class="btn btn-outline-secondary">
                                            <i class="ti ti-user-plus me-1" aria-hidden="true"></i>Neue Aufnahme
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($fehlermeldung)): ?>
                        <?php
                            // UX-2: Anker-Ziele, bei denen die Feld-id vom name abweicht.
                            $_fehler_anker = ['blutalkoholkonzentration' => 'bak_input'];
                        ?>
                        <div class="alert alert-danger alert-dismissible" role="alert" aria-live="assertive">
                            <div class="alert-icon"><i class="ti ti-alert-circle icon alert-icon" aria-hidden="true"></i></div>
                            <div>
                                <?php if (!empty($validierungsfehler)): ?>
                                    <strong>Bitte korrigieren Sie folgende Angaben:</strong>
                                    <ul class="mb-0 ps-3">
                                        <?php foreach ($validierungsfehler as $_feld => $_meldung): ?>
                                            <li><a href="#<?= htmlspecialchars($_fehler_anker[$_feld] ?? $_feld) ?>" class="alert-link"><?= htmlspecialchars($_meldung) ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <?= htmlspecialchars($fehlermeldung) ?>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fehlermeldung schließen"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($neue_klienten_id)): ?>
                    <?php else: ?>
                    <form id="aufnahme-form" action="aufnahme.php" method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="row row-deck row-cards">

                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header border-0"><h3 class="card-title">Stammdaten & Vertragliches</h3></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-6 mb-3"><label class="form-label required" for="vorname">Vorname</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'vorname') ?>" id="vorname" name="vorname" value="<?= htmlspecialchars($vorname ?? '') ?>" required><div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['vorname'] ?? '') ?></div></div>
                                            <div class="col-6 mb-3"><label class="form-label required" for="nachname">Nachname</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'nachname') ?>" id="nachname" name="nachname" value="<?= htmlspecialchars($nachname ?? '') ?>" required><div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['nachname'] ?? '') ?></div></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label required" for="geburtsdatum">Geburtsdatum</label>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'geburtsdatum') ?>" id="geburtsdatum" name="geburtsdatum" value="<?= htmlspecialchars($geburtsdatum ?? '') ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['geburtsdatum'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="strasse">Straße &amp; Hausnummer</label>
                                            <input type="text" class="form-control" id="strasse" name="strasse" value="<?= htmlspecialchars($strasse ?? '') ?>" placeholder="z.B. Musterstraße 12">
                                        </div>
                                        <div class="row">
                                            <div class="col-4 mb-3"><label class="form-label" for="postleitzahl">Postleitzahl</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'postleitzahl') ?>" id="postleitzahl" name="postleitzahl" value="<?= htmlspecialchars($postleitzahl ?? '') ?>"><div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['postleitzahl'] ?? '') ?></div></div>
                                            <div class="col-8 mb-3"><label class="form-label" for="wohnort_input">Wohnort</label><input type="text" class="form-control" name="wohnort" id="wohnort_input" value="<?= htmlspecialchars($wohnort ?? '') ?>"></div>
                                        </div>
                                        <div id="mettmann-warning" class="alert alert-warning small p-2" role="status" aria-live="polite" style="display:none;">
                                            <div class="alert-icon"><i class="ti ti-map-pin icon alert-icon" aria-hidden="true"></i></div>
                                            <div><strong>Hinweis:</strong> Klient aus Velbert/Mettmann. Antrag muss persönlich in Mettmann gestellt werden (BK-5 Fristen beachten).</div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-3"><label class="form-label" for="telefonnummer">Telefonnummer</label><input type="tel" class="form-control" id="telefonnummer" name="telefonnummer" value="<?= htmlspecialchars($telefonnummer ?? '') ?>"></div>
                                            <div class="col-6 mb-3"><label class="form-label" for="email_adresse">E-Mail</label><input type="email" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'email_adresse') ?>" id="email_adresse" name="email_adresse" value="<?= htmlspecialchars($email_adresse ?? '') ?>"><div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['email_adresse'] ?? '') ?></div></div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <label class="form-label" for="geschlecht">Geschlecht</label>
                                                <select class="form-select" id="geschlecht" name="geschlecht">
                                                    <option value="m" <?= ($geschlecht ?? 'm') === 'm' ? 'selected' : '' ?>>Männlich</option>
                                                    <option value="f" <?= ($geschlecht ?? '') === 'f' ? 'selected' : '' ?>>Weiblich</option>
                                                    <option value="d" <?= ($geschlecht ?? '') === 'd' ? 'selected' : '' ?>>Divers</option>
                                                </select>
                                            </div>
                                            <div class="col-6 mb-3"><label class="form-label" for="koerpergewicht">Gewicht (kg)</label><input type="number" step="0.1" class="form-control" id="koerpergewicht" name="koerpergewicht" value="<?= htmlspecialchars((string)($koerpergewicht ?? '')) ?>"></div>
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label">Honorarpaket & vereinbarter Betrag</label>
                                            <div class="row g-2">
                                                <div class="col-7">
                                                    <select class="form-select" name="abrechnungsmodell">
                                                        <option value="paket" <?= ($abrechnungsmodell ?? 'paket') === 'paket' ? 'selected' : '' ?>>Komplette Vorbereitung</option>
                                                        <option value="einzel" <?= ($abrechnungsmodell ?? '') === 'einzel' ? 'selected' : '' ?>>Abrechnung pro Sitzung</option>
                                                    </select>
                                                </div>
                                                <div class="col-5">
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" min="0" class="form-control" name="honorar_gesamt" value="<?= htmlspecialchars((string)($honorar_gesamt ?? '')) ?>" placeholder="Betrag">
                                                        <span class="input-group-text">&euro;</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small text-secondary" for="payment_amount_paid">Anzahlung / bereits bezahlt <span class="text-secondary">(optional)</span></label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.01" min="0" class="form-control" name="payment_amount_paid" id="payment_amount_paid" value="<?= htmlspecialchars((string)($payment_amount_paid ?? '')) ?>" placeholder="0,00">
                                                <span class="input-group-text">&euro;</span>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" id="btn-dsgvo-pdf" class="btn btn-outline-primary w-100" title="Datenschutzerklärung drucken">
                                                <i class="ti ti-printer me-2" aria-hidden="true"></i>Datenschutzvereinbarung drucken
                                            </button>
                                        </div>
                                        <div class="mb-2 mt-2">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="datenschutz_zugestimmt" <?= !empty($datenschutz_zugestimmt) ? 'checked' : '' ?>>
                                                <span class="form-check-label">Datenschutzerklärung unterzeichnet</span>
                                                <span class="form-hint">Bei telefonischer Aufnahme offen lassen und in der Akte nachtragen.</span>
                                            </label>
                                        </div>
                                        <hr class="my-3">
                                        <div class="mt-2">
                                            <button type="button" id="btn-beratungsvertrag" class="btn btn-outline-primary w-100" title="Beratungsvertrag drucken">
                                                <i class="ti ti-printer me-2" aria-hidden="true"></i>Beratungsvertrag drucken
                                            </button>
                                        </div>
                                        <hr class="my-3">
                                        <div class="mb-2 mt-2">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="vorzeitiger_leistungsbeginn" value="1" id="vorzeitiger_leistungsbeginn" <?= !empty($vorzeitiger_leistungsbeginn) ? 'checked' : '' ?>>
                                                <span class="form-check-label">Vorzeitiger Leistungsbeginn gewünscht <span class="text-secondary">(§&nbsp;356 Abs.&nbsp;4 BGB)</span></span>
                                            </label>
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" id="btn-widerruf" class="btn btn-outline-primary w-100" title="Widerrufsbelehrung drucken">
                                                <i class="ti ti-printer me-2" aria-hidden="true"></i>Widerrufsbelehrung drucken
                                            </button>
                                    </div>
                                        <div class="mb-2 mt-2">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="widerruf_bestaetigt" <?= !empty($widerruf_bestaetigt) ? 'checked' : '' ?>>
                                                <span class="form-check-label">Widerrufsbelehrung ausgehändigt &amp; bestätigt</span>
                                            </label>
                                        </div>
                                    </div>

                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header border-0"><h3 class="card-title">Dokumente & Fristen</h3></div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label required mb-0" for="datum_des_deliktes">Datum des Deliktes</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-datum-delikt" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'datum_des_deliktes') ?>" id="datum_des_deliktes" name="datum_des_deliktes" value="<?= htmlspecialchars($datum_des_deliktes ?? '') ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['datum_des_deliktes'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0" for="datum_rechtskraft">Datum Rechtskraft / Anordnung</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-datum-rechtskraft" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'datum_rechtskraft') ?>" id="datum_rechtskraft" name="datum_rechtskraft" value="<?= htmlspecialchars($datum_rechtskraft ?? '') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['datum_rechtskraft'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0" for="sperrfrist_ende">Ablauf der Sperrfrist</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-sperrfrist" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'sperrfrist_ende') ?>" id="sperrfrist_ende" name="sperrfrist_ende" value="<?= htmlspecialchars($sperrfrist_ende ?? '') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['sperrfrist_ende'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label mb-0" for="bussgeld_datum">
                                                Datum Bußgeldbescheid / Strafbefehl
                                                <span class="badge bg-red-lt ms-1">Einspruchsfrist!</span>
                                            </label>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'bussgeld_datum') ?>" id="bussgeld_datum" name="bussgeld_datum" value="<?= htmlspecialchars($bussgeld_datum ?? '') ?>"
                                                aria-describedby="bussgeld_hint">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['bussgeld_datum'] ?? '') ?></div>
                                            <small id="bussgeld_hint" class="form-hint">Datum der Zustellung → 14-Tage-Frist läuft ab <strong id="einspruch_frist_anzeige"></strong></small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label mb-0" for="einspruch_status">Einspruchsstatus</label>
                                            <select class="form-select" id="einspruch_status" name="einspruch_status">
                                                <option value="nicht_relevant" <?= ($einspruch_status ?? 'nicht_relevant') === 'nicht_relevant' ? 'selected' : '' ?>>Kein Bußgeldbescheid / nicht relevant</option>
                                                <option value="offen" <?= ($einspruch_status ?? '') === 'offen' ? 'selected' : '' ?>>Frist läuft – Einspruch noch nicht eingelegt</option>
                                                <option value="eingelegt" <?= ($einspruch_status ?? '') === 'eingelegt' ? 'selected' : '' ?>>Einspruch eingelegt ✓</option>
                                                <option value="abgelaufen" <?= ($einspruch_status ?? '') === 'abgelaufen' ? 'selected' : '' ?>>Frist abgelaufen – Bescheid rechtskräftig</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0">Zuständige Behörde &amp; Aktenzeichen</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-behoerde" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <div class="row g-2">
                                                <div class="col-7"><input type="text" class="form-control" id="behoerde" name="behoerde" value="<?= htmlspecialchars($behoerde ?? '') ?>" placeholder="z.B. SVA Mettmann" aria-label="Zuständige Behörde"></div>
                                                <div class="col-5"><input type="text" class="form-control" id="aktenzeichen" name="aktenzeichen" value="<?= htmlspecialchars($aktenzeichen ?? '') ?>" placeholder="Aktenzeichen" aria-label="Aktenzeichen der Behörde"></div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0" for="antrag_status">Status Neuerteilung / Anordnung</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-antrag-status" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <select class="form-select" id="antrag_status" name="antrag_status">
                                                <option value="nicht_gestellt" <?= ($antrag_status ?? 'nicht_gestellt') === 'nicht_gestellt' ? 'selected' : '' ?>>Noch nicht gestellt</option>
                                                <option value="gestellt" <?= ($antrag_status ?? '') === 'gestellt' ? 'selected' : '' ?>>Antrag gestellt</option>
                                                <option value="aufforderung_liegt_vor" <?= ($antrag_status ?? '') === 'aufforderung_liegt_vor' ? 'selected' : '' ?>>MPU-Anordnung liegt bereits vor</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0" for="mpu_zielmonat">Ziel-Monat MPU <span class="text-secondary">(für Rückwärtsterminierung)</span></label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-mpu-zielmonat" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <input type="month" class="form-control" id="mpu_zielmonat" name="mpu_zielmonat" value="<?= htmlspecialchars($_POST['mpu_zielmonat'] ?? '') ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Führerscheinklasse(n)</label>
                                            <?php $_fs_klassen = $fuehrerscheinklassen ?? []; ?>
                                            <div class="row g-1">
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="B" <?= in_array('B', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label" data-bs-toggle="tooltip" title="PKW bis 3,5 t, max. 8 Personen">B</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="BE" <?= in_array('BE', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label" data-bs-toggle="tooltip" title="B + Anhänger über 750 kg">BE</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="C" <?= in_array('C', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label fw-bold" data-bs-toggle="tooltip" title="LKW über 3,5 t – BKF-relevant">C</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="CE" <?= in_array('CE', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label fw-bold" data-bs-toggle="tooltip" title="C + Anhänger (Sattelzug) – BKF-relevant">CE</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="C1" <?= in_array('C1', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label fw-bold" data-bs-toggle="tooltip" title="LKW 3,5–7,5 t">C1</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="D" <?= in_array('D', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label fw-bold" data-bs-toggle="tooltip" title="Bus (Linienverkehr) – BKF-relevant">D</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="DE" <?= in_array('DE', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label fw-bold" data-bs-toggle="tooltip" title="D + Anhänger – BKF-relevant">DE</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="A" <?= in_array('A', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label" data-bs-toggle="tooltip" title="Motorrad unbegrenzte Leistung">A</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="A1" <?= in_array('A1', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label" data-bs-toggle="tooltip" title="A1: bis 125 ccm / A2: bis 35 kW">A1/A2</span></label></div>
                                                <div class="col-auto"><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="AM" <?= in_array('AM', $_fs_klassen, true) ? 'checked' : '' ?>><span class="form-check-label" data-bs-toggle="tooltip" title="AM: Moped (45 km/h) / L: landw. Zugmaschine">AM/L</span></label></div>
                                            </div>
                                        </div>
                                        <?php $_bkf_status_wert = $bkf_status ?? 'nein'; ?>
                                        <div id="bkf-status-block" style="display:none;" class="mb-3">
                                            <label class="form-label fw-bold">Berufliche Nutzung Klasse C/D <span class="text-danger">*</span></label>
                                            <div class="form-selectgroup form-selectgroup-boxes d-flex">
                                                <label class="form-selectgroup-item flex-fill">
                                                    <input type="radio" name="bkf_status" id="bkf_nein" value="nein" class="form-selectgroup-input" <?= $_bkf_status_wert === 'nein' ? 'checked' : '' ?>>
                                                    <span class="form-selectgroup-label">
                                                        <span class="form-selectgroup-title">Keine berufliche Nutzung</span>
                                                        <small class="text-secondary d-block">Klasse vorhanden, aber kein gewerblicher Einsatz</small>
                                                    </span>
                                                </label>
                                                <label class="form-selectgroup-item flex-fill">
                                                    <input type="radio" name="bkf_status" id="bkf_aktiv" value="aktiv" class="form-selectgroup-input" <?= $_bkf_status_wert === 'aktiv' ? 'checked' : '' ?>>
                                                    <span class="form-selectgroup-label">
                                                        <span class="form-selectgroup-title text-danger">Aktiver Berufskraftfahrer</span>
                                                        <small class="text-secondary d-block">Derzeit / zuletzt gewerblich tätig</small>
                                                    </span>
                                                </label>
                                                <label class="form-selectgroup-item flex-fill">
                                                    <input type="radio" name="bkf_status" id="bkf_angestrebt" value="angestrebt" class="form-selectgroup-input" <?= $_bkf_status_wert === 'angestrebt' ? 'checked' : '' ?>>
                                                    <span class="form-selectgroup-label">
                                                        <span class="form-selectgroup-title"><i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i>Neuerteilung C/D angestrebt</span>
                                                        <small class="text-secondary d-block">Will Klasse C/D nach MPU zurück</small>
                                                    </span>
                                                </label>
                                            </div>
                                            <div id="bkf-info" class="alert alert-danger small p-2 mt-2" role="status" style="display:none;"></div>
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label">Vorliegende behördliche Unterlagen</label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_akte" <?= !empty($dokument_akte) ? 'checked' : '' ?>><span class="form-check-label">Vollständige Führerscheinakte</span></label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_strafbefehl" <?= !empty($dokument_strafbefehl) ? 'checked' : '' ?>><span class="form-check-label">Strafbefehl oder Gerichtsurteil</span></label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_register" <?= !empty($dokument_register) ? 'checked' : '' ?>><span class="form-check-label">Auszug aus dem Fahreignungsregister</span></label>
                                        </div>
                                        <hr>
                                        <div class="mb-2">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="fruehere_untersuchungen" value="1" <?= !empty($fruehere_untersuchungen) ? 'checked' : '' ?>>
                                                <span class="form-check-label text-danger fw-bold">Wiederholungstäter (Zweit-MPU)</span>
                                                <span class="form-check-description">Klient hat bereits früher eine MPU absolviert.</span>
                                            </label>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="mehrfachdelikt" value="1" <?= !empty($mehrfachdelikt) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-bold">Mehrfachdelikt (noch keine Vor-MPU)</span>
                                                <span class="form-check-description">Mehr als ein Delikt der gleichen Art, aber bisher noch keine MPU angeordnet.</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-status-top bg-azure"></div>
                                    <div class="card-header border-0"><h3 class="card-title">Diagnostik & Triage</h3></div>
                                    <div class="card-body">
                                        <p class="text-secondary small">Wählen Sie alle zutreffenden Kategorien aus (Mehrfachauswahl möglich).</p>
                                        
                                        <?php
                                            $_delikte     = $deliktarten ?? [];
                                            $_drogen      = $drogenarten ?? [];
                                            $_hat_alkohol = in_array('Alkohol', $_delikte, true);
                                            $_hat_drogen  = in_array('Drogen', $_delikte, true);
                                            $_hat_punkte  = in_array('Punkte', $_delikte, true);
                                        ?>
                                        <label class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="deliktart[]" value="Alkohol" <?= $_hat_alkohol ? 'checked' : '' ?>>
                                            <span class="form-check-label fw-bold">Alkohol im Straßenverkehr</span>
                                        </label>
                                        <div id="alkohol-details" class="dynamic-section">
                                            <div class="d-flex align-items-center justify-content-between mb-1">
                                                <label class="form-label mb-0" for="bak_input">Blutalkoholkonzentration</label>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-widmark-aufnahme" title="Widmark-Rechner: Trinkmenge gegen BAK-Wert prüfen">
                                                    <i class="ti ti-calculator me-1" aria-hidden="true"></i>Widmark-Rechner
                                                </button>
                                            </div>
                                            <div class="input-group mb-2">
                                                <input type="number" step="0.01" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'blutalkoholkonzentration') ?>" name="blutalkoholkonzentration" id="bak_input" value="<?= htmlspecialchars($anzeige_bak ?? '') ?>">
                                                <span class="input-group-text" aria-hidden="true">‰</span>
                                                <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['blutalkoholkonzentration'] ?? '') ?></div>
                                            </div>
                                            <p class="form-hint text-secondary small mb-2">
                                                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>Bei <strong>Fahrrad-Delikten</strong> (MPU-Anlass ab <?= number_format((float)$_icd_cfg['fev_13']['promille'], 1, ',', '') ?> ‰, § 13 FeV) kann ggf. <strong>A4 / KT</strong> ausreichend sein &ndash; Human Override nutzen!
                                            </p>
                                            <div class="form-check position-relative pe-4">
                                                <input class="form-check-input" type="checkbox" name="ausfallerscheinungen_notiert" id="ausfallerscheinungen_notiert" <?= !empty($ausfallerscheinungen_notiert) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="ausfallerscheinungen_notiert">Keine Ausfallerscheinungen im Protokoll</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm position-absolute top-0 end-0" data-bs-toggle="modal" data-bs-target="#modal-info-ausfallerscheinungen" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                            <div class="form-check position-relative pe-4">
                                                <input class="form-check-input" type="checkbox" name="suchttherapie_absolviert" id="therapy_check" <?= !empty($suchttherapie_absolviert) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="therapy_check">Suchttherapie absolviert</label>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm position-absolute top-0 end-0" data-bs-toggle="modal" data-bs-target="#modal-info-suchttherapie" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                        </div>

                                        <label class="form-check mb-2 mt-3">
                                            <input class="form-check-input" type="checkbox" name="deliktart[]" value="Drogen" <?= $_hat_drogen ? 'checked' : '' ?>>
                                            <span class="form-check-label fw-bold">Betäubungsmittel (Drogen)</span>
                                        </label>
                                        <div id="drogen-details" class="dynamic-section">
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="drogenart[]" value="Cannabis" <?= in_array('Cannabis', $_drogen, true) ? 'checked' : '' ?>><span class="form-check-label">Cannabis</span></label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="drogenart[]" value="Kokain" <?= in_array('Kokain', $_drogen, true) ? 'checked' : '' ?>><span class="form-check-label text-danger">Kokain / Harte Drogen</span></label>
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="drogenart[]" value="Medikamente" <?= in_array('Medikamente', $_drogen, true) ? 'checked' : '' ?>>
                                                <span class="form-check-label text-warning">Medikamente (Rezept, z. B. Medizinalcannabis, Benzodiazepine, Opioide)</span>
                                            </label>
                                            <label class="form-check ms-4">
                                                <input class="form-check-input" type="checkbox" name="medizinal_beikonsum" <?= !empty($medizinal_beikonsum) ? 'checked' : '' ?>>
                                                <span class="form-check-label text-danger">Beikonsum (nicht verordneter Konsum)</span>
                                                <span class="form-check-description">Kann fehlende Trennung begründen, Einzelfall (Anlage 4 Nr. 9.2.1 und 9.4 FeV, § 11 Abs. 7 FeV)</span>
                                            </label>
                                            <label class="form-label mt-2" for="therapie_art">Therapieform</label>
                                                <select class="form-select" id="therapie_art" name="therapie_art">
                                                    <?php foreach (['keine' => 'Keine', 'ambulant' => 'Ambulant', 'stationaer' => 'Stationär'] as $_wert => $_text): ?>
                                                    <option value="<?= $_wert ?>" <?= ($therapie_art ?? 'keine') === $_wert ? 'selected' : '' ?>><?= $_text ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" name="erschwerend_faktoren" <?= !empty($erschwerend_faktoren) ? 'checked' : '' ?>>
                                                    <span class="form-check-label">Erschwerende Faktoren</span>
                                                    <span class="form-check-description">Gescheiterte Abstinenzversuche, schwerwiegende soziale Folgen</span>
                                                </label>
                                                <label class="form-label mt-2" for="tetrahydrocannabinol_wert">Tetrahydrocannabinol-Wert (Blut)</label>
                                            <div class="input-group"><input type="number" step="0.1" class="form-control" id="tetrahydrocannabinol_wert" name="tetrahydrocannabinol_wert" value="<?= htmlspecialchars($anzeige_thc ?? '') ?>"><span class="input-group-text" aria-hidden="true">ng/ml</span></div>
                                            <label class="form-label mt-2" for="thc_cooh_value">THC-COOH (Blutserum)</label>
                                            <div class="input-group"><input type="number" step="0.1" min="0" class="form-control" id="thc_cooh_value" name="thc_cooh_value" value="<?= htmlspecialchars($anzeige_thc_cooh ?? '') ?>" aria-describedby="thc-cooh-hint"><span class="input-group-text" aria-hidden="true">ng/ml</span></div>
                                            <div id="thc-cooh-hint" class="text-secondary small mt-1">&gt; 150 ng/ml belegt regelmäßigen Konsum (→ D2/D3)</div>
                                        </div>

                                        <label class="form-check mb-2 mt-3">
                                            <input class="form-check-input" type="checkbox" name="deliktart[]" value="Punkte" <?= $_hat_punkte ? 'checked' : '' ?>>
                                            <span class="form-check-label fw-bold">Verkehrsrecht / Strafrecht</span>
                                        </label>
                                        <div id="punkte-details" class="dynamic-section">
                                            <label class="form-label" for="punkte_flensburg">Punkte in Flensburg</label>
                                            <input type="number" class="form-control mb-1<?= feld_invalid_klasse($validierungsfehler ?? [], 'punkte_flensburg') ?>" id="punkte_flensburg" name="punkte_flensburg" value="<?= htmlspecialchars(isset($punkte_flensburg) ? (string)$punkte_flensburg : '0') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['punkte_flensburg'] ?? '') ?></div>
                                            <div id="flensburg-8-hinweis" class="alert alert-info small p-2 mb-2" style="display:none;">
                                                <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                                <div><strong>8 Punkte: Führerschein bereits entzogen.</strong> Klient beantragt Neuerteilung – ggf. MPU-Anordnung nach § 11 Abs. 3 FeV beachten.</div>
                                            </div>
                                            <label class="form-check text-danger">
                                                <input class="form-check-input" type="checkbox" name="aggressionspotenzial" <?= !empty($aggressionspotenzial) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-bold">Aggressionspotenzial</span>
                                                <span class="form-check-description">Körperverletzung, Nötigung, etc.</span>
                                            </label>
                                            <label class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="persoenlichkeit" <?= !empty($persoenlichkeit) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-bold">Schwere Persönlichkeits-Problematik (V1)</span>
                                                <span class="form-check-description">Tiefgreifende Persönlichkeitsstörung – BK-5 Kap. B 3.1</span>
                                            </label>
                                        </div>

                                        <hr class="my-3">
                                        <p class="text-secondary small mb-1" id="icd_quickcheck">
                                            <i class="ti ti-brain me-1" aria-hidden="true"></i>
                                            <strong>ICD-Quick-Check</strong> &ndash; Abhängigkeitsmerkmale
                                            <a href="anleitung.php#glossar" class="ms-1" title="Fachbegriffe im Glossar nachschlagen">
                                                <i class="ti ti-book" aria-hidden="true"></i>
                                            </a>
                                        </p>
                                        <p class="text-secondary small mb-2">
                                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                                            <em>Screening-Hilfsmittel &ndash; keine Diagnose.</em> ICD-10 F1x.2 erfordert formal <?= $_icd_abhaengigkeit_min ?>&nbsp;von&nbsp;6 Kriterien &uuml;ber&nbsp;12&nbsp;Monate. Hier werden alle 6 Kriterien abgefragt; eine klinische Diagnose obliegt dem Gutachter.
                                        </p>
                                        <?php
                                        $icd_items = [
                                            ['icd_kontrollverlust', 'Kontrollverlust beim Konsum',                          'modal-info-kontrollverlust'],
                                            ['icd_priorisierung',   'Priorisierung des Konsums vor Pflichten',               'modal-info-priorisierung'],
                                            ['icd_folgeschaeden',   'Konsum trotz körperlicher / psychischer Folgeschäden',  'modal-info-folgeschaeden'],
                                            ['icd_craving',         'Starker Wunsch / Zwang zu konsumieren (Craving)',       'modal-info-craving'],
                                            ['icd_toleranz',        'Toleranzentwicklung (Dosissteigerung für gleiche Wirkung)', 'modal-info-toleranz'],
                                            ['icd_entzug',          'Körperliches Entzugssyndrom beim Absetzen',             'modal-info-entzug'],
                                        ];
                                        foreach ($icd_items as [$fname, $label, $modal]):
                                        ?>
                                        <?php $_icd_wert = $icd_werte[$fname] ?? null; ?>
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="small me-2"><?= htmlspecialchars($label) ?></span>
                                            <div class="d-flex align-items-center gap-1">
                                                <div class="btn-group btn-group-sm" role="group" aria-label="<?= htmlspecialchars($label) ?>">
                                                    <input type="radio" class="btn-check icd-radio" name="<?= $fname ?>" id="<?= $fname ?>_ja"   value="1" <?= $_icd_wert === 1 ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-danger"    for="<?= $fname ?>_ja">Ja</label>
                                                    <input type="radio" class="btn-check icd-radio" name="<?= $fname ?>" id="<?= $fname ?>_nein" value="0" <?= $_icd_wert === 0 ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-success"   for="<?= $fname ?>_nein">Nein</label>
                                                    <input type="radio" class="btn-check icd-radio" name="<?= $fname ?>" id="<?= $fname ?>_offen" value="" <?= $_icd_wert === null ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-secondary" for="<?= $fname ?>_offen">&mdash;</label>
                                                </div>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#<?= $modal ?>" title="Details anzeigen"><i class="ti ti-info-circle" aria-hidden="true"></i></button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div id="icd-pflicht-warn" class="alert alert-warning small p-2 mb-0 mt-1" role="alert" style="display:none;">
                                            <div class="alert-icon"><i class="ti ti-hourglass icon alert-icon" aria-hidden="true"></i></div>
                                            <div><strong>Triage bleibt vorl&auml;ufig.</strong> Die Aufnahme ist trotzdem m&ouml;glich &ndash; bewertet werden kann nur, was bekannt ist. Nicht erhobene Kriterien z&auml;hlen als &bdquo;nicht erf&uuml;llt&ldquo;, die Hypothese ist damit eine Untergrenze. Sp&auml;ter in der Bearbeitung nachtragen.</div>
                                        </div>
                                        <div id="icd-hinweis" class="alert alert-warning small p-2 mb-0 mt-1" role="status" aria-live="polite" style="display:none;">
                                            <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                            <div><strong>Screening-Ergebnis: &ge;&#8239;<?= $_icd_abhaengigkeit_min ?>&nbsp;von&nbsp;6 Kriterien erfüllt &ndash;</strong> Abhängigkeit abklären &rarr; Hochstufung auf <strong>A1&nbsp;/&nbsp;D1</strong> wird empfohlen. Bitte Human Override nutzen oder Suchttherapie als Voraussetzung besprechen. <em>(Keine Diagnose &ndash; Gutachter entscheidet.)</em></div>
                                        </div>
                                        <!-- F14: AUDIT & LAST Scores -->
                                        <div class="mt-3 pt-3 border-top">
                                            <p class="small text-secondary mb-2">
                                                <strong>Screening-Scores</strong> (optional – falls bereits erhoben)
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm ms-1" data-bs-toggle="modal" data-bs-target="#modal-info-screening-scores" title="AUDIT und LAST erklärt" aria-label="Screening-Scores erklärt">
                                                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                                                </button>
                                            </p>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="audit_score">AUDIT-Score <span class="text-secondary">(0–<?= (int)$_screening_cfg['audit']['max'] ?>)</span></label>
                                                    <input type="number" class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'audit_score') ?>" id="audit_score" name="audit_score"
                                                        value="<?= htmlspecialchars((string)($audit_score ?? '')) ?>"
                                                        min="0" max="<?= (int)$_screening_cfg['audit']['max'] ?>" placeholder="—"
                                                        aria-describedby="audit_hint">
                                                    <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['audit_score'] ?? '') ?></div>
                                                    <small id="audit_hint" class="form-hint">≥ <?= (int)$_screening_cfg['audit']['riskant'] ?> riskant · ≥ <?= (int)$_screening_cfg['audit']['schaedlich'] ?> schädlich · ≥ <?= (int)$_screening_cfg['audit']['abklaeren'] ?> Abhängigkeit abklären</small>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="last_score">LAST-Score <span class="text-secondary">(0–<?= (int)$_screening_cfg['last']['max'] ?>)</span></label>
                                                    <input type="number" class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'last_score') ?>" id="last_score" name="last_score"
                                                        value="<?= htmlspecialchars((string)($last_score ?? '')) ?>"
                                                        min="0" max="<?= (int)$_screening_cfg['last']['max'] ?>" placeholder="—"
                                                        aria-describedby="last_hint">
                                                    <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['last_score'] ?? '') ?></div>
                                                    <small id="last_hint" class="form-hint">≥ <?= (int)$_screening_cfg['last']['auffaellig'] ?> = Hinweis auf Missbrauch oder beginnende Abhängigkeit</small>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                    <div class="card-body border-top bg-azure-lt" id="triage-preview" style="display:none;">
                                        <h4 class="mb-2"><span class="badge bg-azure-lt me-2"><i class="ti ti-bolt icon" aria-hidden="true"></i></span>System-Live-Triage</h4>
                                        <div id="triage-hypothese" class="h3 mb-1 text-primary"></div>
                                        <div id="triage-abst" class="fw-bold mt-1"></div>
                                        <div id="triage-kt-info" class="text-secondary mt-1" style="display:none;"></div>
                                        <div id="triage-warning" class="text-danger small mt-2 fw-bold" style="display:none;"></div>
                                        <div id="triage-fristen" class="text-secondary small mt-2 border-top pt-2" style="display:none;"></div>
                                        
                                        <!-- Hidden Fields zum Speichern der Triage-Ergebnisse -->
                                        <input type="hidden" name="triage_hypothese_hidden" id="triage_hypothese_hidden">
                                        <input type="hidden" name="triage_abstinenz_hidden" id="triage_abstinenz_hidden">

                                        <!-- Human Override -->
                                        <div class="border-top mt-3 pt-3">
                                            <label class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox" id="override_toggle" name="override_aktiv" <?= !empty($override_aktiv) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-bold"><i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i>System-Triage manuell überschreiben</span>
                                            </label>
                                        </div>
                                        <div id="override-section" style="display:none;" class="mt-2">
                                            <div class="alert alert-warning py-2 px-3 mb-3" role="alert">
                                                <i class="ti ti-replace me-1" aria-hidden="true"></i>Ein Override <strong>ersetzt den detaillierten Systemvorschlag</strong> durch die hier gewählte BK-5-Kategorie. Die feinen Zusatzangaben des Auto-Labels (z.&nbsp;B. Messwerte) gehen dabei verloren &ndash; bitte in der Begründung festhalten.
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label" for="override_hypothese">Hypothese (Berater-Entscheid)</label>
                                                <select class="form-select" name="override_hypothese" id="override_hypothese">
                                                    <option value="">– Bitte wählen –</option>
                                                    <?php foreach (ERLAUBTE_HYPOTHESEN as $h): ?>
                                                        <option value="<?= htmlspecialchars($h) ?>" <?= ($_POST['override_hypothese'] ?? '') === $h ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-hint" id="override_hypothese_hint" style="display:none;"><i class="ti ti-wand me-1" aria-hidden="true"></i>Vorbelegt anhand des aktuellen Systemvorschlags.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label" for="override_abstinenz">Richtwert Mindestabstinenz (Berater-Entscheid)</label>
                                                <select class="form-select" name="override_abstinenz" id="override_abstinenz">
                                                    <option value="">– Bitte wählen –</option>
                                                    <?php foreach (ERLAUBTE_ABSTINENZ as $a): ?>
                                                        <option value="<?= htmlspecialchars($a) ?>" <?= ($_POST['override_abstinenz'] ?? '') === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label" for="override_begruendung">Begründung der Abweichung <span class="text-danger">*</span> <span class="text-secondary small">(Pflicht – Aktennotiz nach BK-5)</span></label>
                                                <textarea class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'override_begruendung') ?>" name="override_begruendung" id="override_begruendung" rows="3" placeholder="z.B. Haaranalyse deutet auf längere Konsumgeschichte hin, daher A2 statt A4..."><?= htmlspecialchars($override_begruendung ?? '') ?></textarea>
                                                <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['override_begruendung'] ?? '') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body border-top" id="risiko-aufklaerung" style="display:none;">
                                        <h5 class="mb-2"><i class="ti ti-shield-check me-1 text-success" aria-hidden="true"></i> Risikoaufklärung</h5>
                                        <p class="text-secondary small mb-2">Beide Punkte im Gespräch ansprechen. Bei telefonischer Aufnahme beim ersten Termin nachholen.</p>
                                        <label class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="aufklaerung_strategie" id="aufklaerung_strategie">
                                            <span class="form-check-label">Klient wurde darüber aufgeklärt, dass die Abstinenzempfehlung eine <strong>strategische Prognose</strong> (Sicherheitsstrategie) darstellt.</span>
                                        </label>
                                        <label class="form-check">
                                            <input class="form-check-input" type="checkbox" name="aufklaerung_gutachter" id="aufklaerung_gutachter">
                                            <span class="form-check-label">Klient versteht, dass die <strong>endgültige Entscheidung</strong> über die Abstinenzdauer ausschließlich beim MPU-Gutachter liegt.</span>
                                        </label>
                                    </div>
                                    <div class="card-footer bg-transparent d-flex gap-2">
                                        <button type="submit" class="btn btn-primary flex-fill" id="btn-submit" disabled>
                                            <i class="ti ti-device-floppy me-2" aria-hidden="true"></i> Akte speichern
                                        </button>
                                        <button type="button" class="btn" id="btn-pdf" style="display:none;" title="Strategie-Vereinbarung drucken">
                                            <i class="ti ti-printer me-2" aria-hidden="true"></i> Strategie drucken
                                        </button>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </form>
                    <?php endif; ?>

                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>
        </div>
    </div>

    <?php require 'partials/_modals_info.php'; ?>

    <script src="assets/js/tabler.min.js"></script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
    // Tabler 1.4+ exportiert Bootstrap unter window.tabler.bootstrap, nicht window.bootstrap
    window.bootstrap = window.tabler?.bootstrap ?? window.bootstrap;
    </script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
        function toggleSection(sectionId, isChecked) {
            document.getElementById(sectionId).style.display = isChecked ? 'block' : 'none';
        }

        const BKF_KLASSEN = ['C','CE','C1','C1E','D','DE','D1','D1E'];
        // UX-3: aus config/triage_config.php gespiegelt, nicht erneut hartkodieren.
        const ICD_ABHAENGIGKEIT_MIN = <?= $_icd_abhaengigkeit_min ?>;

        // JS-S3: XSS-Schutz für innerHTML-Template-Strings mit Server-Daten
        function _escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function updateBkfHinweis() {
            const klassen = Array.from(document.querySelectorAll('input[name="fuehrerscheinklasse[]"]:checked')).map(el => el.value);
            const hatBkfKlasse = klassen.some(k => BKF_KLASSEN.includes(k));
            document.getElementById('bkf-status-block').style.display = hatBkfKlasse ? 'block' : 'none';
        }

        function checkMettmann() {
            const ort = document.getElementById('wohnort_input').value.toLowerCase();
            const warning = document.getElementById('mettmann-warning');
            if (ort.includes('mettmann') || ort.includes('velbert') || ort.includes('ratingen')) {
                warning.style.display = 'flex';
            } else {
                warning.style.display = 'none';
            }
        }

        // ── Triage AJAX ────────────────────────────────────────────
        let _triageTimer = null;

        function updateTriage() {
            // BKF-Infobox: reine UI-Logik, kein Server-Aufruf
            _updateBkfInfo();

            const delikte = Array.from(document.querySelectorAll('input[name="deliktart[]"]:checked')).map(el => el.value);

            // ICD-Hinweis und Pflicht-Warn: reine UI-Logik
            const hatKonsumDelikt = delikte.includes('Alkohol') || delikte.includes('Drogen');
            const icdNamen = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden','icd_craving','icd_toleranz','icd_entzug'];
            const icdScore = icdNamen.filter(name => {
                const el = document.querySelector(`input[name="${name}"].icd-radio:checked`);
                return el && el.value === '1';
            }).length;
            const icdOffen = icdNamen.filter(name => {
                const el = document.querySelector(`input[name="${name}"].icd-radio:checked`);
                return !el || el.value === '';
            }).length;
            document.getElementById('icd-hinweis').style.display      = (hatKonsumDelikt && icdScore >= ICD_ABHAENGIGKEIT_MIN) ? 'flex' : 'none';
            const pflichtWarn = document.getElementById('icd-pflicht-warn');
            if (pflichtWarn) pflichtWarn.style.display = (hatKonsumDelikt && icdOffen > 0) ? 'flex' : 'none';

            if (delikte.length === 0) {
                document.getElementById('triage-preview').style.display = 'none';
                return;
            }

            clearTimeout(_triageTimer);
            _triageTimer = setTimeout(_fetchTriage, 250);
        }

        function _updateBkfInfo() {
            const klassen    = Array.from(document.querySelectorAll('input[name="fuehrerscheinklasse[]"]:checked')).map(el => el.value);
            const hatBkfKlasse = klassen.some(k => BKF_KLASSEN.includes(k));
            const bkfStatusEl  = document.querySelector('input[name="bkf_status"]:checked');
            const bkfStatus    = bkfStatusEl ? bkfStatusEl.value : 'nein';
            const bkfInfo      = document.getElementById('bkf-info');
            if (!bkfInfo) return;
            if (!hatBkfKlasse) { bkfInfo.style.display = 'none'; return; }
            const liste = klassen.filter(k => BKF_KLASSEN.includes(k)).join('/');
            if (bkfStatus === 'aktiv') {
                bkfInfo.className = 'alert alert-danger small p-2 mt-2';
                bkfInfo.innerHTML = `<div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div><div><strong>Aktiver BKF (Kl. ${_escHtml(liste)}):</strong> Regeln für den Dienst prüfen (§ 24a StVG, bei Fahrgastbeförderung § 8 BOKraft). Verlust der Fahrerlaubnis zur Fahrgastbeförderung (FzF) und ggf. Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.</div>`;
                bkfInfo.style.display = 'flex';
            } else if (bkfStatus === 'angestrebt') {
                bkfInfo.className = 'alert alert-warning small p-2 mt-2';
                bkfInfo.innerHTML = `<div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div><div><strong>Neuerteilung Kl. ${_escHtml(liste)} angestrebt:</strong> Neben MPU zusätzlich Untersuchung nach Anlage 5 FeV-Tauglichkeitsuntersuchung (Arzt) erforderlich. Gutachter prüft erhöhte medizinische Mindestanforderungen.</div>`;
                bkfInfo.style.display = 'flex';
            } else {
                bkfInfo.style.display = 'none';
            }
        }

        function _fetchTriage() {
            const data = new FormData();
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) data.append('csrf_token', csrfInput.value);
            document.querySelectorAll('input[name="deliktart[]"]:checked').forEach(el => data.append('deliktart[]', el.value));
            document.querySelectorAll('input[name="drogenart[]"]:checked').forEach(el => data.append('drogenart[]', el.value));
            document.querySelectorAll('input[name="fuehrerscheinklasse[]"]:checked').forEach(el => data.append('fuehrerscheinklasse[]', el.value));
            const bkfEl = document.querySelector('input[name="bkf_status"]:checked');
            if (bkfEl) data.append('bkf_status', bkfEl.value);
            ['blutalkoholkonzentration', 'tetrahydrocannabinol_wert', 'thc_cooh_value', 'punkte_flensburg', 'therapie_art'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el) data.append(name, el.value);
            });
            ['suchttherapie_absolviert', 'fruehere_untersuchungen', 'mehrfachdelikt',
             'ausfallerscheinungen_notiert', 'aggressionspotenzial', 'persoenlichkeit',
             'medizinal_beikonsum', 'erschwerend_faktoren'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el?.checked) data.append(name, '1');
            });
            // ICD tri-state: nur explizit gesetzte Werte senden
            ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden',
             'icd_craving','icd_toleranz','icd_entzug'].forEach(name => {
                const el = document.querySelector(`input[name="${name}"].icd-radio:checked`);
                if (el && el.value !== '') data.append(name, el.value);
            });

            fetch('triage_ajax.php', { method: 'POST', body: data })
                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(_renderTriage)
                .catch(() => { /* stiller Fehler – Triage-Vorschau bleibt unverändert */ });
        }

        function _renderTriage(res) {
            document.getElementById('triage-preview').style.display = 'block';
            zeigeRisikoAufklaerung();

            const _st = (id, val, html = false) => {
                const el = document.getElementById(id);
                if (!el) return;
                if (html) el.innerHTML = val; else el.textContent = val;
                el.style.display = val ? '' : 'none';
            };

            _st('triage-hypothese', res.hypothesen.map(h => h.label).join('\n'));

            const chemH = res.hypothesen.filter(h => h.monate > 0);
            let abstHTML = '';
            if (chemH.length > 1) {
                abstHTML  = chemH.map(h => `<span class="text-secondary">${_escHtml(h.label.split(' (')[0])}: ${_escHtml(h.abst)}</span>`).join('<br>');
                abstHTML += `<br><strong class="text-primary">→ Primärempfehlung: ${_escHtml(res.abstinenz_primaer)} (Maximum aller Deliktarten)</strong>`;
            } else {
                abstHTML = `<strong>Richtwert Mindestabstinenz: voraussichtlich ${_escHtml(res.abstinenz_primaer)}</strong>`;
            }
            _st('triage-abst',     abstHTML, true);
            _st('triage-kt-info',  res.kt_hinweise.map(_escHtml).join('<br>'), true);
            _st('triage-warning',  res.warnungen.map(_escHtml).join('<br>'), true);

            _updateFristen(res.max_karenz);

            // Hidden Fields für Override-Druckfunktion aktualisieren
            const overrideToggle = document.getElementById('override_toggle');
            if (!overrideToggle?.checked) {
                document.getElementById('triage_hypothese_hidden').value = res.hypothese_text;
                document.getElementById('triage_abstinenz_hidden').value = res.abstinenz_primaer;
                // U-1: Override-Dropdown mit der Kategorie des Systemvorschlags vorbelegen,
                // solange kein manueller Override aktiv ist.
                _vorbelegeOverrideHypothese(res);
            }
        }

        // U-1: Ermittelt die dominante Auto-Hypothese und wählt die passende
        // Override-Kategorie (Kürzel A1–A4/D1–D4/V1–V3/M1–M3) im Dropdown vor.
        function _vorbelegeOverrideHypothese(res) {
            const sel  = document.getElementById('override_hypothese');
            const hint = document.getElementById('override_hypothese_hint');
            if (!sel || !Array.isArray(res.hypothesen) || res.hypothesen.length === 0) return;
            const dominant = res.hypothesen.reduce((a, b) => (b.monate ?? 0) > (a.monate ?? 0) ? b : a);
            const m = /Hypothese\s+([ADVM]\d)/.exec(dominant.label || '');
            if (!m) return;
            const kuerzel = m[1];
            const treffer = Array.from(sel.options).find(o => o.value.startsWith('Hypothese ' + kuerzel + ' '));
            if (treffer) {
                sel.value = treffer.value;
                if (hint) hint.style.display = '';
            }
        }

        function _updateFristen(maxKarenz) {
            const sperrfrist  = document.querySelector('input[name="sperrfrist_ende"]').value;
            let fristenInfo = '';
            if (sperrfrist) {
                // Vorlauf aus config/behoerden_config.php. Welche Behörde zuständig ist, entscheidet erst die Akte.
                const VORLAUF = <?= json_encode(array_map('intval', behoerden_config()['vorlauf_tage'])) ?>;
                const datum = tage => { const d = new Date(sperrfrist); d.setDate(d.getDate() - tage); return d.toLocaleDateString('de-DE'); };
                fristenInfo  = `Antrag spätestens am ${datum(VORLAUF.standard)} stellen (${VORLAUF.standard} Tage vor Sperrfristende), Düsseldorf am ${datum(VORLAUF.duesseldorf)} (${VORLAUF.duesseldorf} Tage). Erfahrungswerte, keine Rechtsfrist.<br>`;
                fristenInfo += `Karenzzeit: Planen Sie mindestens ${_escHtml(maxKarenz)} Monate stabile Bewährungsphase nach der Aufarbeitung ein.`;
            }
            const el = document.getElementById('triage-fristen');
            if (el) { el.innerHTML = fristenInfo; el.style.display = fristenInfo ? '' : 'none'; }
        }

        // Risikoaufklärungs-Block einblenden sobald Triage sichtbar wird
        function zeigeRisikoAufklaerung() {
            const block = document.getElementById('risiko-aufklaerung');
            const btnPdf = document.getElementById('btn-pdf');
            if (block) block.style.display = 'block';
            if (btnPdf) btnPdf.style.display = 'inline-block';
            pruefeSubmitButton();
        }

        function pruefeSubmitButton() {
            const btn = document.getElementById('btn-submit');
            if (!btn) return;

            // Override-Validierung: Wenn aktiv, müssen Dropdowns + Begründung befüllt sein (BK-5)
            const overrideToggle = document.getElementById('override_toggle');
            let overrideOk = true;
            if (overrideToggle && overrideToggle.checked) {
                const hyp   = document.getElementById('override_hypothese').value;
                const abst  = document.getElementById('override_abstinenz').value;
                const begr  = (document.getElementById('override_begruendung').value ?? '').trim();
                overrideOk  = hyp !== '' && abst !== '' && begr !== '';
                document.getElementById('override_hypothese').classList.toggle('is-invalid', hyp === '');
                document.getElementById('override_abstinenz').classList.toggle('is-invalid', abst === '');
                document.getElementById('override_begruendung').classList.toggle('is-invalid', begr === '');
            }

            btn.disabled = !overrideOk;
        }

        // ── Pflichtfelder für PDF-Generierung ─────────────────
        const PDF_PFLICHTFELDER = ['vorname', 'nachname', 'geburtsdatum'];
        const _savedClientId = <?= isset($neue_klienten_id) ? (int)$neue_klienten_id : 0 ?>;

        function pruefePdfButtons() {
            const alleGefuellt = PDF_PFLICHTFELDER.every(name => {
                const el = document.querySelector('[name="' + name + '"]');
                return el && el.value.trim() !== '';
            });
            document.querySelectorAll('#btn-dsgvo-pdf').forEach(btn => btn.disabled = !alleGefuellt);
        }

        function toggleHonorarFields() {
            const modell = document.querySelector('[name="abrechnungsmodell"]').value;
            const paket  = document.getElementById('section_paket');
            const einzel = document.getElementById('section_einzel');
            if (paket)  paket.style.display  = (modell === 'paket')  ? 'block' : 'none';
            if (einzel) einzel.style.display = (modell === 'einzel') ? 'block' : 'none';
        }

        function toggleOverride(isChecked) {
            document.getElementById('override-section').style.display = isChecked ? 'block' : 'none';
            if (!isChecked) {
                updateTriage();
            } else {
                applyOverride();
            }
            pruefeSubmitButton();
        }

        function applyOverride() {
            const hyp  = document.getElementById('override_hypothese').value;
            const abst = document.getElementById('override_abstinenz').value;
            if (hyp)  document.getElementById('triage_hypothese_hidden').value = hyp;
            if (abst) document.getElementById('triage_abstinenz_hidden').value  = abst;
        }

        function _formularDrucken(url) {
            const tmp = document.createElement('form');
            tmp.method = 'POST';
            tmp.action = url;
            tmp.target = '_blank';
            ['vorname','nachname','geburtsdatum','strasse','postleitzahl','wohnort',
             'telefonnummer','email_adresse','abrechnungsmodell','honorar_gesamt','stundensatz'
            ].forEach(name => {
                const src = document.querySelector('[name="' + name + '"]');
                if (!src) return;
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = name;
                inp.value = src.value;
                tmp.appendChild(inp);
            });
            const vlb    = document.getElementById('vorzeitiger_leistungsbeginn');
            const vlbInp = document.createElement('input');
            vlbInp.type  = 'hidden';
            vlbInp.name  = 'vorzeitiger_leistungsbeginn';
            vlbInp.value = (vlb && vlb.checked) ? '1' : '0';
            tmp.appendChild(vlbInp);
            const csrfSrc = document.querySelector('input[name="csrf_token"]');
            if (csrfSrc) {
                const csrfInp = document.createElement('input');
                csrfInp.type  = 'hidden';
                csrfInp.name  = 'csrf_token';
                csrfInp.value = csrfSrc.value;
                tmp.appendChild(csrfInp);
            }
            document.body.appendChild(tmp);
            tmp.submit();
            document.body.removeChild(tmp);
        }

        function druckeDatenschutz()        { _formularDrucken('datenschutz_druck.php'); }
        function druckeBeratungsvertrag()   { _formularDrucken('vertrag_druck.php'); }
        function druckeWiderrufsbelehrung() { _formularDrucken('widerruf_druck.php'); }
        function druckeStrategieVereinbarung() {
            if (_savedClientId > 0) {
                // Nach dem Speichern: Daten aus DB laden (vollständig)
                window.open('strategie_druck.php?id=' + _savedClientId, '_blank');
                return;
            }
            // Vor dem Speichern: Triage-Daten per POST übergeben
            // Bei aktivem Override: Override-Werte direkt lesen (Hidden-Fields werden nur
            // bei inaktivem Override durch _renderTriage aktualisiert, s. Bug 3d)
            const overrideToggle = document.getElementById('override_toggle');
            const isOverride = overrideToggle?.checked ?? false;
            const hyp = isOverride
                ? (document.getElementById('override_hypothese')?.value ?? '')
                : (document.getElementById('triage_hypothese_hidden')?.value ?? '');
            const abstinenz = isOverride
                ? (document.getElementById('override_abstinenz')?.value ?? '')
                : (document.getElementById('triage_abstinenz_hidden')?.value ?? '');
            if (!hyp) {
                alert('Bitte zuerst die Diagnostik (Karte 3) ausfüllen, damit die Strategie-Vereinbarung die Triage-Ergebnisse enthält.');
                return;
            }
            const tmp = document.createElement('form');
            tmp.method = 'POST';
            tmp.action = 'strategie_druck.php';
            tmp.target = '_blank';
            const bkfEl = document.querySelector('input[name="bkf_status"]:checked');
            const fields = {
                first_name:           document.querySelector('[name="vorname"]')?.value        ?? '',
                last_name:            document.querySelector('[name="nachname"]')?.value       ?? '',
                date_of_birth:        document.querySelector('[name="geburtsdatum"]')?.value   ?? '',
                suggested_hypothesis: hyp,
                suggested_abstinence: abstinenz,
                override_active:      document.getElementById('override_toggle')?.checked ? '1' : '0',
                override_reason:      document.querySelector('[name="override_begruendung"]')?.value ?? '',
                bkf_status:           bkfEl ? bkfEl.value : 'nein',
                offense_category:     JSON.stringify(
                    Array.from(document.querySelectorAll('input[name="deliktart[]"]:checked')).map(el => el.value)
                ),
                drug_types:           JSON.stringify(
                    Array.from(document.querySelectorAll('input[name="drogenart[]"]:checked')).map(el => el.value)
                ),
                // Ohne Token weist strategie_druck.php den POST mit 403 ab.
                csrf_token:           document.querySelector('input[name="csrf_token"]')?.value ?? '',
            };
            // UX-6: ICD-Dreizustand mitgeben, damit strategie_druck.php eine noch
            // unvollständige Triage als vorläufig kennzeichnen kann. Leerer Wert
            // (= "—") wird bewusst mitgesendet und dort als "nicht erhoben" gelesen.
            ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden',
             'icd_craving','icd_toleranz','icd_entzug'].forEach(name => {
                const el = document.querySelector(`input[name="${name}"].icd-radio:checked`);
                fields[name] = el ? el.value : '';
            });
            Object.entries(fields).forEach(([name, value]) => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = name; inp.value = value;
                tmp.appendChild(inp);
            });
            document.body.appendChild(tmp);
            tmp.submit();
            document.body.removeChild(tmp);
        }

        document.addEventListener('DOMContentLoaded', () => {
            // Flensburg-8-Punkte-Hinweis
            const flEl = document.getElementById('punkte_flensburg');
            const flHint = document.getElementById('flensburg-8-hinweis');
            if (flEl && flHint) {
                flEl.addEventListener('input', function () {
                    flHint.style.display = (Number(this.value) >= 8 ? 'flex' : 'none');
                });
            }

            // Override-Dropdowns ebenfalls überwachen
            const hyp  = document.getElementById('override_hypothese');
            const abst = document.getElementById('override_abstinenz');
            if (hyp)  { hyp.addEventListener('change',  pruefeSubmitButton); hyp.addEventListener('change',  applyOverride); }
            if (abst) { abst.addEventListener('change', pruefeSubmitButton); abst.addEventListener('change', applyOverride); }
            // PDF-Buttons: Aktivierung wenn Stammdaten vorhanden
            PDF_PFLICHTFELDER.forEach(name => {
                const el = document.querySelector('[name="' + name + '"]');
                if (el) el.addEventListener('input', pruefePdfButtons);
            });

            // Wohnort-Check
            const wohnortEl = document.getElementById('wohnort_input');
            if (wohnortEl) wohnortEl.addEventListener('input', checkMettmann);

            // PDF-Print-Buttons
            const btnDsgvo    = document.getElementById('btn-dsgvo-pdf');
            const btnVertrag  = document.getElementById('btn-beratungsvertrag');
            const btnWiderruf = document.getElementById('btn-widerruf');
            const btnStrategie = document.getElementById('btn-pdf');
            if (btnDsgvo)     btnDsgvo.addEventListener('click',    druckeDatenschutz);
            if (btnVertrag)   btnVertrag.addEventListener('click',  druckeBeratungsvertrag);
            if (btnWiderruf)  btnWiderruf.addEventListener('click', druckeWiderrufsbelehrung);
            if (btnStrategie) btnStrategie.addEventListener('click', druckeStrategieVereinbarung);

            // Datum-Felder → Triage
            ['datum_rechtskraft', 'sperrfrist_ende'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('change', updateTriage);
            });

            // BAK-Eingabe → Triage (input-Event für sofortige Reaktion)
            const bakEl = document.getElementById('bak_input');
            if (bakEl) bakEl.addEventListener('input', updateTriage);

            // Punkte / THC / Aggression → Triage (fehlende Listener)
            ['punkte_flensburg', 'tetrahydrocannabinol_wert', 'thc_cooh_value', 'therapie_art'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', updateTriage);
            });
            ['aggressionspotenzial', 'persoenlichkeit', 'medizinal_beikonsum', 'erschwerend_faktoren'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el) el.addEventListener('change', updateTriage);
            });

            // ICD-Radios (tri-state) → Triage
            document.querySelectorAll('input.icd-radio').forEach(el => el.addEventListener('change', updateTriage));

            // override_begruendung → pruefeSubmitButton
            const begrEl = document.getElementById('override_begruendung');
            if (begrEl) begrEl.addEventListener('input', pruefeSubmitButton);

            // Override-Toggle
            const overrideToggleEl = document.getElementById('override_toggle');
            if (overrideToggleEl) overrideToggleEl.addEventListener('change', e => toggleOverride(e.target.checked));

            // Führerscheinklassen → BKF-Hinweis + Triage
            document.querySelectorAll('input[name="fuehrerscheinklasse[]"]').forEach(el => {
                el.addEventListener('change', () => { updateBkfHinweis(); updateTriage(); });
            });

            // BKF-Status → Triage
            document.querySelectorAll('input[name="bkf_status"]').forEach(el => {
                el.addEventListener('change', updateTriage);
            });

            // Einzelne Checkboxen → Triage
            ['fruehere_untersuchungen', 'mehrfachdelikt', 'ausfallerscheinungen_notiert', 'suchttherapie_absolviert'].forEach(name => {
                document.querySelectorAll('[name="' + name + '"]').forEach(el => el.addEventListener('change', updateTriage));
            });

            // Drogenart → Triage
            document.querySelectorAll('input[name="drogenart[]"]').forEach(el => el.addEventListener('change', updateTriage));

            // Deliktart → toggleSection + Triage
            const deliktSections = { 'Alkohol': 'alkohol-details', 'Drogen': 'drogen-details', 'Punkte': 'punkte-details' };
            document.querySelectorAll('input[name="deliktart[]"]').forEach(el => {
                el.addEventListener('change', function () {
                    const section = deliktSections[el.value];
                    if (section) toggleSection(section, el.checked);
                    updateTriage();
                });
            });

            // Tooltips initialisieren (am Ende, damit ein Fehler hier keine anderen Listener blockiert)
            if (typeof bootstrap !== 'undefined') {
                document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            }

            // F1: Einspruchsfrist-Sofortanzeige bei Datumsauswahl
            const bussgeldEl    = document.getElementById('bussgeld_datum');
            const fristAnzeige  = document.getElementById('einspruch_frist_anzeige');
            if (bussgeldEl && fristAnzeige) {
                bussgeldEl.addEventListener('change', function () {
                    if (!this.value) { fristAnzeige.textContent = ''; return; }
                    const frist = new Date(this.value);
                    frist.setDate(frist.getDate() + 14);
                    const heute = new Date();
                    heute.setHours(0,0,0,0);
                    const tage = Math.round((frist - heute) / 86400000);
                    const fmt  = frist.toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' });
                    if (tage < 0) {
                        fristAnzeige.innerHTML = `<span class='text-secondary'>${fmt} (abgelaufen)</span>`;
                    } else if (tage <= 3) {
                        fristAnzeige.innerHTML = `<span class='text-danger fw-bold'>${fmt} — NUR NOCH ${tage} TAG(E)!</span>`;
                    } else if (tage <= 7) {
                        fristAnzeige.innerHTML = `<span class='text-warning fw-bold'>${fmt} — noch ${tage} Tage</span>`;
                    } else {
                        fristAnzeige.innerHTML = `<span class='text-success'>${fmt} — noch ${tage} Tage</span>`;
                    }
                });
            }

            // ICD-Bestätigung: Submit abfangen wenn Score ≥ ICD_ABHAENGIGKEIT_MIN
            let _icdBestaetigt = false;
            document.getElementById('aufnahme-form')?.addEventListener('submit', function (e) {
                if (_icdBestaetigt) return;
                const ICD_FELDER   = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden',
                    'icd_craving','icd_toleranz','icd_entzug'];
                const delikte      = Array.from(document.querySelectorAll('input[name="deliktart[]"]:checked')).map(el => el.value);
                const konsumDelikt = delikte.includes('Alkohol') || delikte.includes('Drogen');
                const icdScore     = ICD_FELDER.filter(name => document.querySelector(`input[name="${name}"].icd-radio:checked`)?.value === '1').length;
                if (konsumDelikt && icdScore >= ICD_ABHAENGIGKEIT_MIN) {
                    e.preventDefault();
                    new bootstrap.Modal(document.getElementById('modal-icd-bestaetigung')).show();
                }
            });
            document.getElementById('btn-icd-bestaetigen')?.addEventListener('click', function () {
                _icdBestaetigt = true;
                bootstrap.Modal.getInstance(document.getElementById('modal-icd-bestaetigung')).hide();
                document.getElementById('aufnahme-form').requestSubmit();
            });

            // Sticky-Reload nach Validierungsfehler: sichtbaren Zustand mit den vom
            // Server vorbelegten Werten synchronisieren (sonst bleiben abhängige
            // Abschnitte trotz angehakter Checkbox unsichtbar, s. dynamic-section).
            document.querySelectorAll('input[name="deliktart[]"]:checked').forEach(el => {
                const section = deliktSections[el.value];
                if (section) toggleSection(section, true);
            });
            updateBkfHinweis();
            checkMettmann();
            pruefePdfButtons();
            if (overrideToggleEl && overrideToggleEl.checked) {
                toggleOverride(true);
            } else {
                updateTriage();
            }
            pruefeSubmitButton();
        });
    </script>

    <!-- ICD-Bestätigungsdialog -->
    <div class="modal modal-blur fade" id="modal-icd-bestaetigung" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="modal-title-icd-bestaetigung">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="modal-title d-flex align-items-center mb-2">
                        <i class="ti ti-alert-triangle text-warning me-2 fs-2" aria-hidden="true"></i>
                        <strong id="modal-title-icd-bestaetigung">ICD-Screening: Abhängigkeit abklären</strong>
                    </div>
                    <p class="text-secondary mb-1">Es sind <strong>≥ <?= $_icd_abhaengigkeit_min ?> ICD-10-Kriterien</strong> erfüllt. Das System stuft den Klienten auf <strong>Hypothese A1 / D1</strong> hoch (Abstinenz: <strong>15 Monate</strong> gemäß BK-5).</p>
                    <p class="text-secondary small mb-0">Bitte prüfen Sie die Eingaben. Dies ist kein Ersatz für eine klinische Diagnose – die Entscheidung liegt beim Gutachter.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Zurück prüfen</button>
                    <button type="button" class="btn btn-warning" id="btn-icd-bestaetigen">
                        <i class="ti ti-check me-1" aria-hidden="true"></i>Verstanden – speichern
                    </button>
                </div>
            </div>
        </div>
    </div>

<!-- ══ MODAL: WIDMARK-RECHNER (F2) ══════════════════════════════════════════ -->
<div class="modal modal-blur fade" id="modal-widmark-aufnahme" tabindex="-1" role="dialog" aria-labelledby="modal-widmark-aufnahme-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-widmark-aufnahme-title">Widmark-Rechner (Trinkmengen-Analyse)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-4">Tragen Sie hier die vom Klienten behauptete Trinkmenge ein. Das System berechnet sofort den theoretischen Maximalwert nach der Widmark-Formel und vergleicht mit dem eingetragenen polizeilichen Messwert.</p>
                <div id="widmark-aufnahme-kopf" class="mb-3 small text-secondary">
                    Gewicht und Geschlecht werden automatisch aus dem Formular übernommen.
                </div>
                <div class="row g-3 align-items-center mb-3">
                    <label class="col-4 form-label" for="aufn-widmark-bier">Bier (ca. 5 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" id="aufn-widmark-bier" placeholder="0.0">
                            <span class="input-group-text" aria-hidden="true">Liter</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small">z.B. 1.5 für drei halbe Liter</div>
                </div>
                <div class="row g-3 align-items-center mb-3">
                    <label class="col-4 form-label" for="aufn-widmark-wein">Wein / Sekt (ca. 11 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" id="aufn-widmark-wein" placeholder="0.0">
                            <span class="input-group-text" aria-hidden="true">Liter</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small">z.B. 0.75 für eine Flasche</div>
                </div>
                <div class="row g-3 align-items-center mb-4">
                    <label class="col-4 form-label" for="aufn-widmark-schnaps">Spirituosen (ca. 40 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="1" class="form-control" id="aufn-widmark-schnaps" placeholder="0">
                            <span class="input-group-text" aria-hidden="true">Zentiliter (cl)</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small">z.B. 4 für einen doppelten Schnaps</div>
                </div>
                <div class="card bg-primary-lt">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h3 class="card-title mb-1">Berechneter theoretischer Wert</h3>
                                <div class="text-secondary" id="aufn-widmark-bewertung">Warten auf Eingabe…</div>
                            </div>
                            <div class="col-auto text-center">
                                <span id="aufn-widmark-ergebnis" aria-live="polite" class="fs-2 fw-bold" data-raw-value="0">0,00 ‰</span>
                                <div class="mt-1">
                                    <button type="button" class="btn btn-sm btn-primary" id="btn-copy-widmark" title="Wert in BAK-Feld übernehmen">
                                        <i class="ti ti-copy me-1" aria-hidden="true"></i>In BAK-Feld übernehmen
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// ── F2: Widmark-Rechner (Aufnahme) ────────────────────────────────────────────
(function () {
    function berechneWidmarkAufnahme() {
        const gewicht  = parseFloat(document.getElementById('koerpergewicht')?.value) || 0;
        const geschl   = document.getElementById('geschlecht')?.value ?? 'm';
        const gemessen = parseFloat(document.getElementById('bak_input')?.value)    || 0;
        const erg      = document.getElementById('aufn-widmark-ergebnis');
        const bew      = document.getElementById('aufn-widmark-bewertung');
        const kopf     = document.getElementById('widmark-aufnahme-kopf');
        if (kopf) {
            kopf.textContent = gewicht > 0
                ? `Körpergewicht: ${gewicht} kg · Reduktionsfaktor: ${geschl === 'f' ? '0,6 (Weiblich)' : (geschl === 'm' ? '0,7 (Männlich)' : '0,65 (Divers)')}${gemessen > 0 ? ' · Polizeilicher Messwert: ' + gemessen.toFixed(2).replace('.', ',') + ' ‰' : ''}`
                : 'Gewicht aus Stammdaten-Block eintragen, damit der Vergleich funktioniert.';
        }
        if (gewicht <= 0) {
            erg.textContent = '-';
            bew.textContent = 'Körpergewicht (Stammdaten) muss eingetragen sein.';
            return;
        }
        const r         = geschl === 'f' ? 0.6 : (geschl === 'm' ? 0.7 : 0.65);
        const bierL     = parseFloat(document.getElementById('aufn-widmark-bier')?.value)    || 0;
        const weinL     = parseFloat(document.getElementById('aufn-widmark-wein')?.value)    || 0;
        const schnapsCl = parseFloat(document.getElementById('aufn-widmark-schnaps')?.value) || 0;
        const gramm     = (bierL * 1000) * 0.05 * 0.8
                        + (weinL * 1000) * 0.11 * 0.8
                        + (schnapsCl * 10) * 0.40 * 0.8;
        if (gramm === 0) {
            erg.textContent = '0,00 ‰';
            bew.textContent = 'Warten auf Eingabe…';
            return;
        }
        const promille = gramm / (gewicht * r);
        erg.dataset.rawValue = promille.toFixed(2);
        erg.textContent = promille.toFixed(2).replace('.', ',') + ' ‰';
        if (gemessen > 0) {
            if (promille < gemessen - 0.2) {
                bew.innerHTML = "<span class='text-danger fw-bold'><i class='ti ti-alert-triangle me-1'></i>Massive Verharmlosung! Behauptete Menge erklärt den polizeilichen Wert nicht.</span>";
            } else if (promille > gemessen + 0.5) {
                bew.innerHTML = "<span class='text-warning'><i class='ti ti-info-circle me-1'></i>Klient gibt mehr an als gemessen (Abbauzeit bis Blutabnahme berücksichtigen).</span>";
            } else {
                bew.innerHTML = "<span class='text-success fw-bold'><i class='ti ti-check me-1'></i>Behauptete Menge ist plausibel für den gemessenen Wert.</span>";
            }
        } else {
            bew.textContent = 'Kein polizeilicher Messwert eingetragen – kein Plausibilitäts-Vergleich möglich.';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        ['aufn-widmark-bier', 'aufn-widmark-wein', 'aufn-widmark-schnaps'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', berechneWidmarkAufnahme);
        });
        const modal = document.getElementById('modal-widmark-aufnahme');
        if (modal) {
            modal.addEventListener('show.bs.modal', berechneWidmarkAufnahme);
        }
        document.getElementById('btn-copy-widmark')?.addEventListener('click', function () {
            const raw    = document.getElementById('aufn-widmark-ergebnis')?.dataset.rawValue ?? '0';
            const bakEl  = document.getElementById('bak_input');
            if (bakEl) { bakEl.value = raw; updateTriage(); }
            const bsModal = bootstrap.Modal.getInstance(document.getElementById('modal-widmark-aufnahme'));
            if (bsModal) bsModal.hide();
        });
    });
})();
</script>
</body>
</html>