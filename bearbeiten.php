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
// E-6: AUDIT-/LAST-Schwellen aus derselben Quelle wie aufnahme.php
$_screening_cfg = require 'config/screening_config.php';

$id      = (int)($_GET['id']      ?? 0);
$case_id = (int)($_GET['case_id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    $_error_title = 'Ungültige Anfrage';
    $_error_message = 'Ungültige Klienten-ID.';
    require __DIR__ . '/partials/error.php';
    exit;
}

$erfolgsmeldung = '';
$fehlermeldung  = '';

// 1. Bestehende Daten laden – falls case_id übergeben, genau diesen Fall laden
if ($case_id > 0) {
    $stmt = $pdo->prepare("
        SELECT
            c.id AS client_pk, c.first_name, c.last_name, c.date_of_birth, c.street,
            c.zip_code, c.city, c.phone, c.email, c.gender, c.weight_kg,
            c.dsgvo_consent, c.revocation_confirmed, c.early_start_requested,
            c.created_at AS client_created_at, c.version AS client_version,
            ca.*
        FROM clients c
        JOIN cases ca ON ca.id = ? AND ca.client_id = c.id
        WHERE c.id = ?
    ");
    $stmt->execute([$case_id, $id]);
} else {
    $stmt = $pdo->prepare("
        SELECT
            c.id AS client_pk, c.first_name, c.last_name, c.date_of_birth, c.street,
            c.zip_code, c.city, c.phone, c.email, c.gender, c.weight_kg,
            c.dsgvo_consent, c.revocation_confirmed, c.early_start_requested,
            c.created_at AS client_created_at, c.version AS client_version,
            ca.*
        FROM clients c
        LEFT JOIN cases ca ON c.id = ca.client_id AND ca.status = 'aktiv'
        WHERE c.id = ?
        ORDER BY ca.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$id]);
}
$data = $stmt->fetch();
if ($data && $case_id === 0) {
    $case_id = (int)($data['id'] ?? 0);
}

if (!$data) {
    http_response_code(404);
    $_error_title = 'Klient nicht gefunden';
    $_error_message = 'Klient konnte in der Datenbank nicht gefunden werden.';
    require __DIR__ . '/partials/error.php';
    exit;
}


require __DIR__ . '/handlers/bearbeiten_handler.php';

// UX-1: Sticky-Formularwerte bei Validierungsfehler (Analogon zu sitzungen.php).
// $data spiegelt sonst den alten DB-Stand wider und würde die gerade abgelehnte
// Eingabe verwerfen. $data['status'] bleibt bewusst unangetastet – der Wert wird
// weiter unten als data-alter-status für die Statusübergangs-Validierung im JS
// gebraucht und muss der tatsächliche, zuletzt gespeicherte Status bleiben.
$_sticky_klienten_status        = $data['status'] ?? 'aktiv';
$_sticky_persoenlichkeit        = !empty($data['persoenlichkeit']);
$_sticky_medizinal_beikonsum    = !empty($data['medizinal_beikonsum']);
$_sticky_erschwerend            = !empty($data['erschwerend_faktoren']);
$_sticky_therapie_art           = (string)($data['therapie_art'] ?? 'keine');
if ($fehlermeldung !== '') {
    $data['first_name']            = $vorname;
    $data['last_name']              = $nachname;
    $data['date_of_birth']          = $geburtsdatum;
    $data['street']                 = $strasse;
    $data['zip_code']               = $postleitzahl;
    $data['city']                   = $wohnort;
    $data['phone']                  = $telefonnummer;
    $data['email']                  = $email_adresse;
    $data['gender']                 = $geschlecht;
    $data['weight_kg']              = $koerpergewicht;
    $data['early_start_requested']  = isset($_POST['vorzeitiger_leistungsbeginn']) ? 1 : 0;
    $data['package_type']           = $abrechnungsmodell;
    $data['package_price']          = $honorar_gesamt;
    $data['hourly_rate']            = $stundensatz;
    $data['payment_status']         = $payment_status;
    $data['payment_amount_paid']    = $payment_amount_paid;
    $data['payment_date']           = $payment_date;
    $data['offense_date']           = $datum_des_deliktes;
    $data['legal_validity_date']    = $datum_rechtskraft;
    $data['suspension_end_date']    = $sperrfrist_ende;
    $data['bussgeld_datum']         = $bussgeld_datum;
    $data['einspruch_status']       = $einspruch_status;
    $data['mpu_target_date']        = $mpu_zielmonat_datum;
    $data['authority_name']         = $behoerde;
    $data['authority_file_number']  = $aktenzeichen;
    $data['antrag_status']          = $antrag_status;
    $data['bkf_status']             = $bkf_status;
    $data['dokument_akte']          = $dokument_akte;
    $data['dokument_strafbefehl']   = $dokument_strafbefehl;
    $data['dokument_register']      = $dokument_register;
    $data['offense_category']       = $deliktart_string;
    $data['drug_types']             = $drogenart_string;
    $data['license_classes']        = $fuehrerscheinklasse_string;
    // UX-1: rohe Eingabe wiederanzeigen; der gegatete $bak_raw gilt nur fuer die DB.
    $data['bak_value']              = trim((string)($_POST['blutalkoholkonzentration'] ?? ''));
    $data['symptoms_recorded']      = $ausfallerscheinungen_notiert;
    $data['therapy_completed']      = $suchttherapie_absolviert;
    $data['thc_value']              = trim((string)($_POST['tetrahydrocannabinol_wert'] ?? ''));
    $data['thc_cooh_value']         = trim((string)($_POST['thc_cooh_value'] ?? ''));
    $data['flensburg_points']       = $punkte_flensburg;
    $data['prior_mpu']              = $fruehere_untersuchungen;
    $data['mehrfachdelikt']         = $mehrfachdelikt;
    $data['aggression_involved']    = $aggressionspotenzial;
    $data['override_active']        = $override_aktiv;
    $data['override_reason']        = $override_begruendung;
    $data['audit_score']            = $audit_score;
    $data['last_score']             = $last_score;
    $data['icd_kontrollverlust']    = $icd_werte['icd_kontrollverlust'];
    $data['icd_priorisierung']      = $icd_werte['icd_priorisierung'];
    $data['icd_folgeschaeden']      = $icd_werte['icd_folgeschaeden'];
    $data['icd_craving']            = $icd_werte['icd_craving'];
    $data['icd_toleranz']           = $icd_werte['icd_toleranz'];
    $data['icd_entzug']             = $icd_werte['icd_entzug'];
    $data['suggested_hypothesis']   = $_POST['override_hypothese'] ?? '';
    $data['suggested_abstinence']   = $_POST['override_abstinenz'] ?? '';
    $_sticky_klienten_status        = $klienten_status;
    $_sticky_persoenlichkeit        = (bool)$persoenlichkeit;
    $_sticky_medizinal_beikonsum    = (bool)$medizinal_beikonsum;
    $_sticky_erschwerend            = (bool)$erschwerend_faktoren;
    $_sticky_therapie_art           = $therapie_art;
}

// Hilfsvariablen für Checkboxen/Selects
$current_delikte = json_decode($data['offense_category'] ?? '[]', true) ?: [];
$current_drogen  = json_decode($data['drug_types'] ?? '[]', true) ?: [];
$current_klassen = array_filter(explode(', ', $data['license_classes'] ?? ''));
$page_title    = 'MPU-Manager | Klientenakte bearbeiten';
$page_css_file = 'assets/css/formulare.css';
$active_page = 'akte';
require 'partials/_head.php';
require 'partials/_navbar.php';
?>
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h1 class="page-title">Akte bearbeiten: <?= htmlspecialchars($data['first_name'] . ' ' . $data['last_name']) ?></h1>
                        </div>
                        <div class="col-auto ms-auto">
                            <div class="btn-list">
                                <a href="akte.php?id=<?= $id ?>&case_id=<?= $case_id ?>" class="btn btn-outline-secondary">Abbrechen</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <main class="page-body" id="main-content">
            <div class="container-xl">
                <?php if ($fehlermeldung): ?>
                    <?php
                        // UX-2: Anker-Ziele, bei denen die Feld-id vom Fehler-Key abweicht.
                        $_fehler_anker = [
                            'blutalkoholkonzentration' => 'bak_value',
                            'audit_score'              => 'audit_score_b',
                            'last_score'               => 'last_score_b',
                            'status_reopen_bestaetigt' => 'klienten_status',
                        ];
                    ?>
                    <div class="alert alert-danger" role="alert" aria-live="assertive">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
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
                    </div>
                <?php endif; ?>

                <form id="bearbeiten-form" action="bearbeiten.php?id=<?= $id ?>&case_id=<?= $case_id ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="case_version"   value="<?= (int)($data['version'] ?? 0) ?>">
                        <input type="hidden" name="client_version" value="<?= (int)($data['client_version'] ?? 0) ?>">
                        <div class="row row-deck row-cards">
                            <!-- STAMMDATEN -->
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header border-0"><h3 class="card-title">Stammdaten</h3></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-6 mb-3"><label class="form-label required" for="vorname">Vorname</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'vorname') ?>" id="vorname" name="vorname" value="<?= htmlspecialchars($data['first_name']) ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['vorname'] ?? '') ?></div></div>
                                            <div class="col-6 mb-3"><label class="form-label required" for="nachname">Nachname</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'nachname') ?>" id="nachname" name="nachname" value="<?= htmlspecialchars($data['last_name']) ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['nachname'] ?? '') ?></div></div>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <label class="form-label required" for="geburtsdatum">Geburtsdatum</label>
                                                <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'geburtsdatum') ?>" id="geburtsdatum" name="geburtsdatum" value="<?= htmlspecialchars($data['date_of_birth'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['geburtsdatum'] ?? '') ?></div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <label class="form-label" for="geschlecht">Geschlecht</label>
                                                <select class="form-select" id="geschlecht" name="geschlecht">
                                                    <option value="m" <?= ($data['gender'] ?? 'm') === 'm' ? 'selected' : '' ?>>Männlich</option>
                                                    <option value="f" <?= ($data['gender'] ?? '') === 'f' ? 'selected' : '' ?>>Weiblich</option>
                                                    <option value="d" <?= ($data['gender'] ?? '') === 'd' ? 'selected' : '' ?>>Divers</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="strasse">Straße</label>
                                            <input type="text" class="form-control" id="strasse" name="strasse" value="<?= htmlspecialchars($data['street'] ?? '') ?>">
                                        </div>
                                        <div class="row">
                                            <div class="col-4 mb-3"><label class="form-label" for="postleitzahl">PLZ</label><input type="text" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'postleitzahl') ?>" id="postleitzahl" name="postleitzahl" value="<?= htmlspecialchars($data['zip_code'] ?? '') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['postleitzahl'] ?? '') ?></div></div>
                                            <div class="col-8 mb-3"><label class="form-label" for="wohnort">Wohnort</label><input type="text" class="form-control" name="wohnort" id="wohnort" value="<?= htmlspecialchars($data['city'] ?? '') ?>"></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="telefonnummer">Telefon</label>
                                            <input type="text" class="form-control" id="telefonnummer" name="telefonnummer" value="<?= htmlspecialchars($data['phone'] ?? '') ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="email_adresse">E-Mail</label>
                                            <input type="email" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'email_adresse') ?>" id="email_adresse" name="email_adresse" value="<?= htmlspecialchars($data['email'] ?? '') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['email_adresse'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="koerpergewicht">Gewicht (kg)</label>
                                            <input type="number" step="0.1" class="form-control" id="koerpergewicht" name="koerpergewicht" value="<?= htmlspecialchars((string)($data['weight_kg'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label" for="abrechnungsmodell">Abrechnungsmodell</label>
                                            <select class="form-select" name="abrechnungsmodell" id="abrechnungsmodell">
                                                <option value="paket" <?= ($data['package_type'] ?? 'paket') === 'paket' ? 'selected' : '' ?>>Paket (Festpreis)</option>
                                                <option value="einzel" <?= ($data['package_type'] ?? '') === 'einzel' ? 'selected' : '' ?>>Einzelstunden</option>
                                            </select>
                                        </div>
                                        <div class="mb-3" id="honorar-paket-block" style="display:<?= ($data['package_type'] ?? 'paket') !== 'einzel' ? 'block' : 'none' ?>;">
                                            <label class="form-label" for="honorar_paket">Paketpreis</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" class="form-control" name="honorar_gesamt" id="honorar_paket" value="<?= htmlspecialchars((string)($data['package_price'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                <span class="input-group-text">&euro;</span>
                                            </div>
                                        </div>
                                        <div class="mb-3" id="honorar-einzel-block" style="display:<?= ($data['package_type'] ?? '') === 'einzel' ? 'block' : 'none' ?>;">
                                            <label class="form-label" for="stundensatz">Stundensatz</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" class="form-control" name="stundensatz" id="stundensatz" value="<?= htmlspecialchars((string)($data['hourly_rate'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                <span class="input-group-text">&euro;/Std.</span>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-check">
                                                <input class="form-check-input" type="checkbox" name="vorzeitiger_leistungsbeginn" value="1" <?= !empty($data['early_start_requested']) ? 'checked' : '' ?>>
                                                <span class="form-check-label">Vorzeitiger Leistungsbeginn</span>
                                            </label>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="payment_status">Zahlungs-Status</label>
                                            <select class="form-select" id="payment_status" name="payment_status">
                                                <option value="offen"      <?= ($data['payment_status'] ?? 'offen') === 'offen'      ? 'selected' : '' ?>>Offen</option>
                                                <option value="teilweise"  <?= ($data['payment_status'] ?? '') === 'teilweise'  ? 'selected' : '' ?>>Teilweise beglichen</option>
                                                <option value="beglichen"  <?= ($data['payment_status'] ?? '') === 'beglichen'  ? 'selected' : '' ?>>Beglichen</option>
                                            </select>
                                        </div>
                                        <div class="mb-3" id="payment-amount-block" style="display:<?= in_array($data['payment_status'] ?? 'offen', ['teilweise','beglichen'], true) ? 'block' : 'none' ?>;">
                                            <label class="form-label" for="payment_amount_paid">Bereits bezahlt (€)</label>
                                            <div class="input-group">
                                                <input type="number" step="0.01" min="0" class="form-control" id="payment_amount_paid" name="payment_amount_paid"
                                                    value="<?= htmlspecialchars((string)($data['payment_amount_paid'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                                    placeholder="0,00">
                                                <span class="input-group-text">&euro;</span>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="payment_date">Zahlungsdatum</label>
                                            <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= htmlspecialchars($data['payment_date'] ?? '') ?>">
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label" for="klienten_status">Klienten-Status</label>
                                            <select class="form-select<?= feld_invalid_klasse($validierungsfehler ?? [], 'klienten_status') ?>" id="klienten_status" name="klienten_status"
                                                    data-alter-status="<?= htmlspecialchars($data['status'] ?? 'aktiv') ?>">
                                                <option value="aktiv"          <?= $_sticky_klienten_status === 'aktiv'         ? 'selected' : '' ?>>Aktiv</option>
                                                <option value="pausiert"       <?= $_sticky_klienten_status === 'pausiert'      ? 'selected' : '' ?>>Pausiert</option>
                                                <option value="abgeschlossen"  <?= $_sticky_klienten_status === 'abgeschlossen' ? 'selected' : '' ?>>Abgeschlossen</option>
                                            </select>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['klienten_status'] ?? '') ?></div>
                                            <input type="hidden" name="status_reopen_bestaetigt" id="status_reopen_bestaetigt" value="">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Beratungsnotizen <span class="text-secondary">(intern)</span></label>
                                            <div class="text-secondary small">
                                                Notizen werden als Journal in der Akte geführt – Tab
                                                <a href="akte.php?id=<?= (int)$id ?>&amp;case_id=<?= (int)$case_id ?>#notizen">Notizen</a>.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- FRISTEN & BEHÖRDEN -->
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header border-0"><h3 class="card-title">Fristen & Behörden</h3></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <label class="form-label required" for="datum_des_deliktes">Datum des Deliktes</label>
                                                <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'datum_des_deliktes') ?>" id="datum_des_deliktes" name="datum_des_deliktes" value="<?= htmlspecialchars($data['offense_date'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" required>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['datum_des_deliktes'] ?? '') ?></div>
                                            </div>
                                            <div class="col-6 mb-3">
                                                <label class="form-label" for="datum_rechtskraft">Datum Rechtskraft</label>
                                                <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'datum_rechtskraft') ?>" id="datum_rechtskraft" name="datum_rechtskraft" value="<?= htmlspecialchars($data['legal_validity_date'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['datum_rechtskraft'] ?? '') ?></div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="sperrfrist_ende">Ablauf der Sperrfrist</label>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'sperrfrist_ende') ?>" id="sperrfrist_ende" name="sperrfrist_ende" value="<?= htmlspecialchars((string)($data['suspension_end_date'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['sperrfrist_ende'] ?? '') ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label mb-0" for="bussgeld_datum">
                                                Datum Bußgeldbescheid / Strafbefehl
                                                <span class="badge bg-red-lt ms-1">Einspruchsfrist!</span>
                                            </label>
                                            <input type="date" class="form-control<?= feld_invalid_klasse($validierungsfehler ?? [], 'bussgeld_datum') ?>" id="bussgeld_datum" name="bussgeld_datum"
                                                value="<?= htmlspecialchars($data['bussgeld_datum'] ?? '') ?>"
                                                aria-describedby="bussgeld_hint_b">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['bussgeld_datum'] ?? '') ?></div>
                                            <small id="bussgeld_hint_b" class="form-hint">Datum der Zustellung → Frist endet <strong id="einspruch_frist_anzeige_b"></strong></small>
                                            <small class="form-hint text-secondary mt-1 d-block">Rechtsgrundlage: § 24a StVG / OWi → § 67 OWiG &nbsp;|&nbsp; § 316 StGB / Straftat → § 410 StPO</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label mb-0" for="einspruch_status">Einspruchsstatus</label>
                                            <select class="form-select" id="einspruch_status" name="einspruch_status">
                                                <option value="nicht_relevant" <?= ($data['einspruch_status'] ?? 'nicht_relevant') === 'nicht_relevant' ? 'selected' : '' ?>>Kein Bußgeldbescheid / nicht relevant</option>
                                                <option value="offen"          <?= ($data['einspruch_status'] ?? '') === 'offen'          ? 'selected' : '' ?>>Frist läuft – Einspruch noch nicht eingelegt</option>
                                                <option value="eingelegt"      <?= ($data['einspruch_status'] ?? '') === 'eingelegt'      ? 'selected' : '' ?>>Einspruch eingelegt ✓</option>
                                                <option value="abgelaufen"     <?= ($data['einspruch_status'] ?? '') === 'abgelaufen'     ? 'selected' : '' ?>>Frist abgelaufen – Bescheid rechtskräftig</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="mpu_zielmonat">Ziel-Monat MPU</label>
                                            <input type="month" class="form-control" id="mpu_zielmonat" name="mpu_zielmonat" value="<?= htmlspecialchars($data['mpu_target_date'] ? substr((string)$data['mpu_target_date'], 0, 7) : '', ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                        </div>
                                        <div class="row">
                                            <div class="col-8 mb-3">
                                                <label class="form-label" for="behoerde">Behörde</label>
                                                <input type="text" class="form-control" id="behoerde" name="behoerde" value="<?= htmlspecialchars($data['authority_name'] ?? '') ?>">
                                            </div>
                                            <div class="col-4 mb-3">
                                                <label class="form-label" for="aktenzeichen">Aktenzeichen</label>
                                                <input type="text" class="form-control" id="aktenzeichen" name="aktenzeichen" value="<?= htmlspecialchars($data['authority_file_number'] ?? '') ?>">
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="antrag_status">Status Neuerteilung</label>
                                            <select class="form-select" id="antrag_status" name="antrag_status">
                                                <option value="nicht_gestellt" <?= $data['antrag_status'] === 'nicht_gestellt' ? 'selected' : '' ?>>Nicht gestellt</option>
                                                <option value="gestellt" <?= $data['antrag_status'] === 'gestellt' ? 'selected' : '' ?>>Gestellt</option>
                                                <option value="aufforderung_liegt_vor" <?= $data['antrag_status'] === 'aufforderung_liegt_vor' ? 'selected' : '' ?>>Aufforderung liegt vor</option>
                                                <option value="abgelehnt" <?= $data['antrag_status'] === 'abgelehnt' ? 'selected' : '' ?>>Abgelehnt – neuer Anlauf erforderlich</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label" for="bkf_status">BKF-Status</label>
                                            <select class="form-select" id="bkf_status" name="bkf_status">
                                                <option value="nein" <?= $data['bkf_status'] === 'nein' ? 'selected' : '' ?>>Nein</option>
                                                <option value="aktiv" <?= $data['bkf_status'] === 'aktiv' ? 'selected' : '' ?>>Aktiv</option>
                                                <option value="angestrebt" <?= $data['bkf_status'] === 'angestrebt' ? 'selected' : '' ?>>Angestrebt</option>
                                            </select>
                                            <div id="bkf-info" class="alert alert-danger small p-2 mt-2" role="status" style="display:none;"></div>
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-label small text-secondary">Führerscheinklassen</label>
                                            <div class="d-flex flex-wrap gap-2">
                                                <?php foreach (['B','BE','C','CE','C1','C1E','D','DE','A','A1','AM'] as $klasse): ?>
                                                <label class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="fuehrerscheinklasse[]" value="<?= $klasse ?>" <?= in_array($klasse, $current_klassen, true) ? 'checked' : '' ?>>
                                                    <span class="form-check-label"><?= $klasse ?></span>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <hr>
                                        <div class="mb-3">
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_akte" <?= $data['dokument_akte'] ? 'checked' : '' ?>><span class="form-check-label">Führerscheinakte liegt vor</span></label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_strafbefehl" <?= $data['dokument_strafbefehl'] ? 'checked' : '' ?>><span class="form-check-label">Strafbefehl / Urteil liegt vor</span></label>
                                            <label class="form-check"><input class="form-check-input" type="checkbox" name="dokument_register" <?= $data['dokument_register'] ? 'checked' : '' ?>><span class="form-check-label">FAER-Auszug liegt vor</span></label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- TRIAGE -->
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header border-0"><h3 class="card-title">Diagnostik</h3></div>
                                    <div class="card-body">

                                        <!-- Deliktarten -->
                                        <div class="mb-1 text-secondary small fw-bold text-uppercase">Deliktart</div>
                                        <div class="mb-2">
                                            <label class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" name="deliktart[]" value="Alkohol" id="delikt-alkohol" <?= in_array('Alkohol', $current_delikte) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-medium">Alkohol</span>
                                            </label>
                                            <div id="alkohol-details" class="dynamic-section" style="display: <?= in_array('Alkohol', $current_delikte) ? 'block' : 'none' ?>;">
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1" for="bak_value">BAK (‰)</label>
                                                    <input type="number" step="0.01" min="0" max="5" class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'blutalkoholkonzentration') ?>" name="blutalkoholkonzentration" id="bak_value" value="<?= htmlspecialchars((string)($data['bak_value'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['blutalkoholkonzentration'] ?? '') ?></div>
                                                </div>
                                                <label class="form-check"><input class="form-check-input" type="checkbox" name="ausfallerscheinungen_notiert" <?= $data['symptoms_recorded'] ? 'checked' : '' ?>><span class="form-check-label small">Keine Ausfallerscheinungen</span></label>
                                                <label class="form-check"><input class="form-check-input" type="checkbox" name="suchttherapie_absolviert" <?= $data['therapy_completed'] ? 'checked' : '' ?>><span class="form-check-label small">Suchttherapie absolviert</span></label>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" name="deliktart[]" value="Drogen" id="delikt-drogen" <?= in_array('Drogen', $current_delikte) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-medium">Drogen</span>
                                            </label>
                                            <div id="drogen-details" class="dynamic-section" style="display: <?= in_array('Drogen', $current_delikte) ? 'block' : 'none' ?>;">
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1" for="thc_value">THC-Wert Blut (ng/ml)</label>
                                                    <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="tetrahydrocannabinol_wert" id="thc_value" value="<?= htmlspecialchars((string)($data['thc_value'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label small mb-1" for="thc_cooh_value">THC-COOH Blutserum (ng/ml) <span class="text-secondary">&gt; <?= (int)$_icd_cfg['thc']['cooh_regelkonsum'] ?> → D2 prüfen</span></label>
                                                    <input type="number" step="0.1" min="0" class="form-control form-control-sm" name="thc_cooh_value" id="thc_cooh_value" value="<?= htmlspecialchars((string)($data['thc_cooh_value'] ?? '')) ?>">
                                                </div>
                                                <label class="form-check"><input class="form-check-input" type="checkbox" name="drogenart[]" value="Cannabis" <?= in_array('Cannabis', $current_drogen) ? 'checked' : '' ?>><span class="form-check-label small">Cannabis</span></label>
                                                <label class="form-check"><input class="form-check-input" type="checkbox" name="drogenart[]" value="Kokain" <?= in_array('Kokain', $current_drogen) ? 'checked' : '' ?>><span class="form-check-label small text-danger">Kokain / Harte Drogen</span></label>
                                                <label class="form-check"><input class="form-check-input" type="checkbox" name="drogenart[]" value="Medikamente" <?= in_array('Medikamente', $current_drogen) ? 'checked' : '' ?>><span class="form-check-label small">Medikamente (Rezept, z. B. Medizinalcannabis, Benzodiazepine, Opioide)</span></label>
                                                <label class="form-check ms-4"><input class="form-check-input" type="checkbox" name="medizinal_beikonsum" <?= $_sticky_medizinal_beikonsum ? 'checked' : '' ?>><span class="form-check-label small text-danger">Beikonsum, nicht verordnet <span class="text-secondary">(kann fehlende Trennung begründen, Einzelfall: Anlage 4 Nr. 9.2.1 und 9.4 FeV, § 11 Abs. 7 FeV)</span></span></label>
                                                <label class="form-label small mb-1 mt-2" for="therapie_art">Therapieform</label>
                                                <select class="form-select form-select-sm" id="therapie_art" name="therapie_art">
                                                    <?php foreach (['keine' => 'Keine', 'ambulant' => 'Ambulant', 'stationaer' => 'Stationär'] as $_wert => $_text): ?>
                                                    <option value="<?= $_wert ?>" <?= $_sticky_therapie_art === $_wert ? 'selected' : '' ?>><?= $_text ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="erschwerend_faktoren" <?= $_sticky_erschwerend ? 'checked' : '' ?>><span class="form-check-label small">Erschwerende Faktoren <span class="text-secondary">(gescheiterte Abstinenzversuche, schwerwiegende soziale Folgen)</span></span></label>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-check mb-1">
                                                <input class="form-check-input" type="checkbox" name="deliktart[]" value="Punkte" id="delikt-punkte" <?= in_array('Punkte', $current_delikte) ? 'checked' : '' ?>>
                                                <span class="form-check-label fw-medium">Punkte / Verkehrsdelikte</span>
                                            </label>
                                            <div id="punkte-details" class="dynamic-section" style="display: <?= in_array('Punkte', $current_delikte) ? 'block' : 'none' ?>;">
                                                <label class="form-label small mb-1" for="punkte_flensburg">Punkte in Flensburg</label>
                                                <input type="number" min="0" max="<?= PUNKTE_EINGABE_MAX ?>" class="form-control form-control-sm mb-1<?= feld_invalid_klasse($validierungsfehler ?? [], 'punkte_flensburg') ?>" id="punkte_flensburg" name="punkte_flensburg" value="<?= (int)$data['flensburg_points'] ?>">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['punkte_flensburg'] ?? '') ?></div>
                                                <div id="flensburg-8-hinweis" class="alert alert-info small p-2 mb-1" style="display:<?= (int)$data['flensburg_points'] === 8 ? 'flex' : 'none' ?>;">
                                                    <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                                    <div><strong>8 Punkte: Führerschein bereits entzogen.</strong> Klient beantragt Neuerteilung – ggf. MPU-Anordnung nach § 11 Abs. 3 FeV beachten.</div>
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="my-2">

                                        <!-- Zusatzmerkmale -->
                                        <div class="mb-1 text-secondary small fw-bold text-uppercase">Zusatzmerkmale</div>
                                        <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="fruehere_untersuchungen" <?= $data['prior_mpu'] ? 'checked' : '' ?>><span class="form-check-label small text-danger">Frühere MPU (Wiederholungstäter)</span></label>
                                        <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="mehrfachdelikt" <?= $data['mehrfachdelikt'] ? 'checked' : '' ?>><span class="form-check-label small">Mehrfachdelikt</span></label>
                                        <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="aggressionspotenzial" <?= $data['aggression_involved'] ? 'checked' : '' ?>><span class="form-check-label small">Aggressionspotenzial</span></label>
                                        <label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="persoenlichkeit" <?= $_sticky_persoenlichkeit ? 'checked' : '' ?>><span class="form-check-label small">Schwere Persönlichkeits-Problematik (V1, BK-5 B 3.1)</span></label>

                                        <hr class="my-2">

                                        <!-- ICD-Kriterien (drei-Zustand: Ja / Nein / nicht erhoben) -->
                                        <div class="d-flex align-items-center mb-1 gap-2">
                                            <span class="text-secondary small fw-bold text-uppercase" id="icd_quickcheck">ICD-10 Kriterien</span>
                                            <a href="anleitung.php#glossar" class="text-secondary" title="Fachbegriffe im Glossar nachschlagen">
                                                <i class="ti ti-book" aria-hidden="true"></i>
                                            </a>
                                            <span id="icd-pflicht-warn" class="text-warning small fw-bold" style="display:none;">
                                                <i class="ti ti-hourglass me-1" aria-hidden="true"></i>Triage bleibt vorläufig
                                            </span>
                                        </div>
                                        <div class="text-secondary small mb-2">&ldquo;—&rdquo; = noch nicht erhoben. Nicht erhobene Kriterien z&auml;hlen in der Berechnung als &bdquo;nicht erf&uuml;llt&ldquo; &ndash; die Hypothese ist dann eine Untergrenze. Sobald etwas bekannt wird, hier nachtragen.</div>
                                        <?php
                                        $icd_definitionen = [
                                            'icd_kontrollverlust' => 'Kontrollverlust',
                                            'icd_priorisierung'   => 'Vernachlässigung / Priorisierung',
                                            'icd_folgeschaeden'   => 'Fortsetzen trotz Folgeschäden',
                                            'icd_craving'         => 'Craving (starker Konsumdrang)',
                                            'icd_toleranz'        => 'Toleranzentwicklung',
                                            'icd_entzug'          => 'Körperl. Entzugssyndrom',
                                        ];
                                        foreach ($icd_definitionen as $icd_name => $icd_label):
                                            $icd_val = $data[$icd_name]; // NULL / 0 / 1
                                        ?>
                                        <div class="d-flex align-items-center justify-content-between mb-2 icd-row" data-icd-name="<?= $icd_name ?>">
                                            <span class="small flex-fill"><?= htmlspecialchars($icd_label) ?></span>
                                            <div class="btn-group btn-group-sm ms-2" role="group" aria-label="<?= htmlspecialchars($icd_label) ?>">
                                                <input type="radio" class="btn-check icd-radio" name="<?= $icd_name ?>" id="<?= $icd_name ?>_ja" value="1" autocomplete="off" <?= $icd_val === '1' || $icd_val === 1 ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-danger" for="<?= $icd_name ?>_ja">Ja</label>
                                                <input type="radio" class="btn-check icd-radio" name="<?= $icd_name ?>" id="<?= $icd_name ?>_nein" value="0" autocomplete="off" <?= $icd_val === '0' || $icd_val === 0 ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-success" for="<?= $icd_name ?>_nein">Nein</label>
                                                <input type="radio" class="btn-check icd-radio" name="<?= $icd_name ?>" id="<?= $icd_name ?>_offen" value="" autocomplete="off" <?= $icd_val === null ? 'checked' : '' ?>>
                                                <label class="btn btn-outline-secondary" for="<?= $icd_name ?>_offen" title="Noch nicht erhoben">&mdash;</label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                        <div id="icd-hinweis" class="alert alert-warning small p-2 mb-0 mt-2" role="status" aria-live="polite" style="display:none;">
                                            <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                            <div><strong>Screening-Ergebnis: &ge;&#8239;<?= $_icd_abhaengigkeit_min ?>&nbsp;von&nbsp;6 Leitkriterien erfüllt &ndash;</strong> Abhängigkeit abklären &rarr; Hochstufung auf <strong>A1&nbsp;/&nbsp;D1</strong> empfohlen. <em>(Keine Diagnose &ndash; Gutachter entscheidet.)</em></div>
                                        </div>
                                        <!-- F14: AUDIT & LAST Scores -->
                                        <div class="mt-3 pt-2 border-top">
                                            <p class="small text-secondary mb-2">
                                                <strong>Screening-Scores</strong>
                                                <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm ms-1" data-bs-toggle="modal" data-bs-target="#modal-info-screening-scores" title="AUDIT und LAST erklärt" aria-label="Screening-Scores erklärt">
                                                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                                                </button>
                                            </p>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="audit_score_b">AUDIT <span class="text-secondary">(0–<?= (int)$_screening_cfg['audit']['max'] ?>)</span></label>
                                                    <input type="number" class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'audit_score') ?>" id="audit_score_b" name="audit_score"
                                                        min="0" max="<?= (int)$_screening_cfg['audit']['max'] ?>"
                                                        value="<?= $data['audit_score'] !== null ? (int)$data['audit_score'] : '' ?>"
                                                        placeholder="—">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['audit_score'] ?? '') ?></div>
                                                    <small class="form-hint">≥ <?= (int)$_screening_cfg['audit']['riskant'] ?> riskant · ≥ <?= (int)$_screening_cfg['audit']['schaedlich'] ?> schädlich · ≥ <?= (int)$_screening_cfg['audit']['abklaeren'] ?> Abhängigkeit abklären</small>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label small mb-1" for="last_score_b">LAST <span class="text-secondary">(0–<?= (int)$_screening_cfg['last']['max'] ?>)</span></label>
                                                    <input type="number" class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'last_score') ?>" id="last_score_b" name="last_score"
                                                        min="0" max="<?= (int)$_screening_cfg['last']['max'] ?>"
                                                        value="<?= $data['last_score'] !== null ? (int)$data['last_score'] : '' ?>"
                                                        placeholder="—">
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['last_score'] ?? '') ?></div>
                                                    <small class="form-hint">≥ <?= (int)$_screening_cfg['last']['auffaellig'] ?> Hinweis auf Missbrauch / beginnende Abhängigkeit</small>
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="my-2">

                                        <!-- Human Override -->
                                        <div class="mb-1 text-secondary small fw-bold text-uppercase">Human Override</div>
                                        <label class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="override_toggle" name="override_aktiv" <?= $data['override_active'] ? 'checked' : '' ?>>
                                            <span class="form-check-label small fw-medium"><i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i>Triage-Ergebnis manuell überschreiben</span>
                                        </label>
                                        <div id="override-details" style="display:<?= $data['override_active'] ? 'block' : 'none' ?>;">
                                            <div class="alert alert-warning py-2 px-2 mb-2 small" role="alert">
                                                <i class="ti ti-replace me-1" aria-hidden="true"></i>Override <strong>ersetzt den detaillierten Systemvorschlag</strong> durch die gewählte BK-5-Kategorie; Zusatzangaben des Auto-Labels gehen verloren &ndash; in der Begründung festhalten.
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1" for="override_hypothese">Hypothese</label>
                                                <select class="form-select form-select-sm" name="override_hypothese" id="override_hypothese">
                                                    <option value="">– Triage-Ergebnis verwenden –</option>
                                                    <?php foreach (ERLAUBTE_HYPOTHESEN as $h): ?>
                                                        <option value="<?= htmlspecialchars($h) ?>" <?= $data['suggested_hypothesis'] === $h ? 'selected' : '' ?>><?= htmlspecialchars($h) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-hint small" id="override_hypothese_hint" style="display:none;"><i class="ti ti-wand me-1" aria-hidden="true"></i>Vorbelegt anhand des aktuellen Systemvorschlags.</div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1" for="override_abstinenz">Abstinenz</label>
                                                <select class="form-select form-select-sm" name="override_abstinenz" id="override_abstinenz">
                                                    <option value="">– Triage-Ergebnis verwenden –</option>
                                                    <?php foreach (ERLAUBTE_ABSTINENZ as $a): ?>
                                                        <option value="<?= htmlspecialchars($a) ?>" <?= $data['suggested_abstinence'] === $a ? 'selected' : '' ?>><?= htmlspecialchars($a) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1 required" for="override_begruendung">Begründung <span class="text-danger small">(Pflicht – BK-5-Dokumentation)</span></label>
                                                <textarea class="form-control form-control-sm<?= feld_invalid_klasse($validierungsfehler ?? [], 'override_begruendung') ?>" name="override_begruendung" id="override_begruendung" rows="3" placeholder="Fachliche Begründung, z. B. abweichende Gutachteranforderung oder klinische Zusatzbefunde…"><?= htmlspecialchars($data['override_reason'] ?? '') ?></textarea>
                                            <div class="invalid-feedback"><?= htmlspecialchars($validierungsfehler['override_begruendung'] ?? '') ?></div>
                                                <div class="form-hint"><i class="ti ti-info-circle text-warning me-1" aria-hidden="true"></i>Wird im Audit-Log dokumentiert.</div>
                                            </div>
                                        </div>

                                        <div class="mt-3">
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="ti ti-device-floppy me-2" aria-hidden="true"></i> Änderungen speichern
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Live-Triage Vorschau -->
                        <div class="row mt-3" id="triage-preview" style="display:none;">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-body bg-azure-lt">
                                        <h4 class="mb-2"><span class="badge bg-azure-lt me-2"><i class="ti ti-bolt icon" aria-hidden="true"></i></span>System-Live-Triage</h4>
                                        <div id="triage-hypothese" class="h3 mb-1 text-primary"></div>
                                        <div id="triage-abst" class="fw-bold mt-1"></div>
                                        <div id="triage-kt-info" class="text-secondary mt-1" style="display:none;"></div>
                                        <div id="triage-warning" class="text-danger small mt-2 fw-bold" style="display:none;"></div>
                                        <div id="triage-fristen" class="text-secondary small mt-2 border-top pt-2" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>
        </div>
    </div>
    <script src="assets/js/tabler.min.js"></script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
    // Tabler 1.4+ exportiert Bootstrap unter window.tabler.bootstrap, nicht window.bootstrap
    window.bootstrap = window.tabler?.bootstrap ?? window.bootstrap;
    </script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
        function toggleSection(id, show) {
            document.getElementById(id).style.display = show ? 'block' : 'none';
        }

        const BKF_KLASSEN = ['C','CE','C1','C1E','D','DE','D1','D1E'];
        // UX-3: aus config/triage_config.php gespiegelt, nicht erneut hartkodieren.
        const ICD_ABHAENGIGKEIT_MIN = <?= $_icd_abhaengigkeit_min ?>;
        let _triageTimer = null;

        // JS-S3: XSS-Schutz für innerHTML-Template-Strings mit Server-Daten
        function _escHtml(s) {
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }

        function _updateBkfInfo() {
            const klassen     = Array.from(document.querySelectorAll('input[name="fuehrerscheinklasse[]"]:checked')).map(el => el.value);
            const hatBkfKlasse = klassen.some(k => BKF_KLASSEN.includes(k));
            const bkfInfo     = document.getElementById('bkf-info');
            if (!bkfInfo) return;
            if (!hatBkfKlasse) { bkfInfo.style.display = 'none'; return; }
            const bkfStatus = document.querySelector('select[name="bkf_status"]')?.value ?? 'nein';
            const liste     = klassen.filter(k => BKF_KLASSEN.includes(k)).join('/');
            if (bkfStatus === 'aktiv') {
                bkfInfo.className = 'alert alert-danger small p-2 mt-2';
                bkfInfo.innerHTML = `<div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div><div><strong>Aktiver BKF (Kl. ${_escHtml(liste)}):</strong> Regeln für den Dienst prüfen (§ 24a StVG, bei Fahrgastbeförderung § 8 BOKraft). Verlust der FzF und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.</div>`;
                bkfInfo.style.display = 'flex';
            } else if (bkfStatus === 'angestrebt') {
                bkfInfo.className = 'alert alert-warning small p-2 mt-2';
                bkfInfo.innerHTML = `<div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div><div><strong>Neuerteilung Kl. ${_escHtml(liste)} angestrebt:</strong> Zusätzlich Untersuchung nach Anlage 5 FeV (Arzt) erforderlich.</div>`;
                bkfInfo.style.display = 'flex';
            } else {
                bkfInfo.style.display = 'none';
            }
        }

        // Zahlungs-Block ein-/ausblenden
        (function () {
            const ps = document.getElementById('payment_status');
            const ab = document.getElementById('payment-amount-block');
            if (ps && ab) {
                ps.addEventListener('change', function () {
                    ab.style.display = (this.value === 'teilweise' || this.value === 'beglichen') ? 'block' : 'none';
                });
            }
        })();

        // Override-Begründung Pflicht-Feedback
        (function () {
            const tog = document.getElementById('override_toggle');
            const beg = document.getElementById('override_begruendung');
            if (tog && beg) {
                tog.addEventListener('change', function () {
                    beg.required = this.checked;
                });
                beg.required = tog.checked;
            }
        })();

        function updateTriage() {
            _updateBkfInfo();

            const delikte         = Array.from(document.querySelectorAll('input[name="deliktart[]"]:checked')).map(el => el.value);
            const hatKonsumDelikt = delikte.includes('Alkohol') || delikte.includes('Drogen');
            const ICD_NAMEN       = ['icd_kontrollverlust', 'icd_priorisierung', 'icd_folgeschaeden',
                                      'icd_craving', 'icd_toleranz', 'icd_entzug'];

            // Tri-State: Wert '1' = Ja
            const icdScore     = ICD_NAMEN.filter(n => document.querySelector(`[name="${n}"]:checked`)?.value === '1').length;
            // Nicht erhoben = kein Radio ausgewählt oder Wert leer
            const icdOffenAnz  = ICD_NAMEN.filter(n => {
                const sel = document.querySelector(`[name="${n}"]:checked`);
                return !sel || sel.value === '';
            }).length;

            const icdHinweis  = document.getElementById('icd-hinweis');
            const icdPflicht  = document.getElementById('icd-pflicht-warn');
            if (icdHinweis) icdHinweis.style.display  = (hatKonsumDelikt && icdScore >= ICD_ABHAENGIGKEIT_MIN) ? 'flex' : 'none';
            if (icdPflicht) icdPflicht.style.display  = (hatKonsumDelikt && icdOffenAnz > 0) ? '' : 'none';

            if (delikte.length === 0) {
                document.getElementById('triage-preview').style.display = 'none';
                return;
            }
            clearTimeout(_triageTimer);
            _triageTimer = setTimeout(_fetchTriage, 250);
        }

        function _fetchTriage() {
            const data = new FormData();
            document.querySelectorAll('input[name="deliktart[]"]:checked').forEach(el => data.append('deliktart[]', el.value));
            document.querySelectorAll('input[name="drogenart[]"]:checked').forEach(el => data.append('drogenart[]', el.value));
            document.querySelectorAll('input[name="fuehrerscheinklasse[]"]:checked').forEach(el => data.append('fuehrerscheinklasse[]', el.value));
            const bkfEl = document.querySelector('select[name="bkf_status"]');
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
            // ICD Tri-State: nur '1' senden (Ja = Kriterium erfüllt)
            ['icd_kontrollverlust', 'icd_priorisierung', 'icd_folgeschaeden',
             'icd_craving', 'icd_toleranz', 'icd_entzug'].forEach(name => {
                const sel = document.querySelector(`[name="${name}"]:checked`);
                if (sel?.value === '1') data.append(name, '1');
            });
            const csrfEl = document.querySelector('input[name="csrf_token"]');
            if (csrfEl) data.append('csrf_token', csrfEl.value);
            fetch('triage_ajax.php', { method: 'POST', body: data })
                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(_renderTriage)
                .catch(() => {});
        }

        function _renderTriage(res) {
            document.getElementById('triage-preview').style.display = 'block';
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
                abstHTML += `<br><strong class="text-primary">&rarr; Primärempfehlung: ${_escHtml(res.abstinenz_primaer)} (Maximum aller Deliktarten)</strong>`;
            } else {
                abstHTML = `<strong>Richtwert Mindestabstinenz: voraussichtlich ${_escHtml(res.abstinenz_primaer)}</strong>`;
            }
            _st('triage-abst',    abstHTML, true);
            _st('triage-kt-info', res.kt_hinweise.map(_escHtml).join('<br>'), true);
            _st('triage-warning', res.warnungen.map(_escHtml).join('<br>'), true);
            _updateFristen(res.max_karenz);
            // U-1: Override-Dropdown mit der Kategorie des Systemvorschlags vorbelegen,
            // solange kein manueller Override aktiv ist (gespeicherte Wahl bleibt erhalten).
            if (!document.getElementById('override_toggle')?.checked) {
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
            const treffer = Array.from(sel.options).find(o => o.value.startsWith('Hypothese ' + m[1] + ' '));
            if (treffer) {
                sel.value = treffer.value;
                if (hint) hint.style.display = '';
            }
        }

        function _updateFristen(maxKarenz) {
            const sperrfrist  = document.querySelector('[name="sperrfrist_ende"]')?.value  ?? '';
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

        document.addEventListener('DOMContentLoaded', function () {
            // Flensburg-8-Punkte-Hinweis
            const flEl = document.getElementById('punkte_flensburg');
            const flHint = document.getElementById('flensburg-8-hinweis');
            if (flEl && flHint) {
                flEl.addEventListener('input', function () {
                    flHint.style.display = (Number(this.value) >= 8 ? 'flex' : 'none');
                });
            }

            // Honorar-Toggles
            const abrechn = document.getElementById('abrechnungsmodell');
            if (abrechn) {
                abrechn.addEventListener('change', function () {
                    document.getElementById('honorar-paket-block').style.display  = this.value === 'paket'  ? 'block' : 'none';
                    document.getElementById('honorar-einzel-block').style.display = this.value === 'einzel' ? 'block' : 'none';
                });
            }

            // Override-Toggle
            const override = document.getElementById('override_toggle');
            if (override) override.addEventListener('change', function () { toggleSection('override-details', this.checked); });

            // Deliktart → Sektion + Triage
            const deliktSections = { 'Alkohol': 'alkohol-details', 'Drogen': 'drogen-details', 'Punkte': 'punkte-details' };
            document.querySelectorAll('input[name="deliktart[]"]').forEach(el => {
                el.addEventListener('change', function () {
                    if (deliktSections[el.value]) toggleSection(deliktSections[el.value], el.checked);
                    updateTriage();
                });
            });

            // Drogenart → Triage
            document.querySelectorAll('input[name="drogenart[]"]').forEach(el => el.addEventListener('change', updateTriage));

            // Checkboxen → Triage
            ['suchttherapie_absolviert', 'fruehere_untersuchungen', 'mehrfachdelikt',
             'ausfallerscheinungen_notiert', 'aggressionspotenzial', 'persoenlichkeit',
             'medizinal_beikonsum', 'erschwerend_faktoren'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el) el.addEventListener('change', updateTriage);
            });
            // ICD-Radios → Triage
            document.querySelectorAll('.icd-radio').forEach(el => el.addEventListener('change', updateTriage));

            // Numerische Felder → Triage
            ['blutalkoholkonzentration', 'tetrahydrocannabinol_wert', 'thc_cooh_value', 'punkte_flensburg', 'therapie_art'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el) el.addEventListener('input', updateTriage);
            });

            // Führerscheinklassen → Triage + BKF-Hinweis
            document.querySelectorAll('input[name="fuehrerscheinklasse[]"]').forEach(el => el.addEventListener('change', updateTriage));

            // BKF-Status (select) → Triage
            const bkfSelect = document.querySelector('select[name="bkf_status"]');
            if (bkfSelect) bkfSelect.addEventListener('change', updateTriage);

            // Datumfelder → Triage-Fristen
            ['datum_rechtskraft', 'sperrfrist_ende'].forEach(name => {
                const el = document.querySelector(`[name="${name}"]`);
                if (el) el.addEventListener('change', updateTriage);
            });

            // F1: Einspruchsfrist-Sofortanzeige
            const bussgeldElB   = document.getElementById('bussgeld_datum');
            const fristAnzeigeB = document.getElementById('einspruch_frist_anzeige_b');
            function updateFristAnzeige(inputEl, displayEl) {
                if (!inputEl || !displayEl) return;
                const val = inputEl.value;
                if (!val) { displayEl.textContent = ''; return; }
                const frist = new Date(val);
                frist.setDate(frist.getDate() + 14);
                const heute = new Date(); heute.setHours(0,0,0,0);
                const tage  = Math.round((frist - heute) / 86400000);
                const fmt   = frist.toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' });
                if (tage < 0) {
                    displayEl.innerHTML = `<span class='text-secondary'>${fmt} (abgelaufen)</span>`;
                } else if (tage <= 3) {
                    displayEl.innerHTML = `<span class='text-danger fw-bold'>${fmt} — NUR NOCH ${tage} TAG(E)!</span>`;
                } else if (tage <= 7) {
                    displayEl.innerHTML = `<span class='text-warning fw-bold'>${fmt} — noch ${tage} Tage</span>`;
                } else {
                    displayEl.innerHTML = `<span class='text-success'>${fmt} — noch ${tage} Tage</span>`;
                }
            }
            if (bussgeldElB) {
                bussgeldElB.addEventListener('change', () => updateFristAnzeige(bussgeldElB, fristAnzeigeB));
                updateFristAnzeige(bussgeldElB, fristAnzeigeB); // Vorausfüllung beim Laden
            }

            // Initiale Triage beim Laden der Seite
            updateTriage();

            // ICD-Bestätigung: Submit abfangen wenn Score ≥ ICD_ABHAENGIGKEIT_MIN
            let _icdBestaetigt    = false;
            let _statusBestaetigt = false;
            document.getElementById('bearbeiten-form').addEventListener('submit', function (e) {
                // 1. Statusübergang prüfen: abgeschlossen → anderes (Rücksetzer)
                if (!_statusBestaetigt) {
                    const sel        = document.getElementById('klienten_status');
                    const alterStatus = sel?.dataset.alterStatus ?? 'aktiv';
                    const neuerStatus = sel?.value ?? 'aktiv';
                    if (alterStatus === 'abgeschlossen' && neuerStatus !== 'abgeschlossen') {
                        e.preventDefault();
                        document.getElementById('status-reopen-neuer-status').textContent =
                            neuerStatus === 'aktiv' ? 'Aktiv' : 'Pausiert';
                        new bootstrap.Modal(document.getElementById('modal-status-reopen')).show();
                        return;
                    }
                }
                // 2. ICD-Bestätigung
                if (_icdBestaetigt) return;
                const delikte         = Array.from(document.querySelectorAll('input[name="deliktart[]"]:checked')).map(el => el.value);
                const hatKonsumDelikt = delikte.includes('Alkohol') || delikte.includes('Drogen');
                const icdScore        = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden',
                                          'icd_craving','icd_toleranz','icd_entzug']
                    .filter(name => document.querySelector(`[name="${name}"]:checked`)?.value === '1').length;
                if (hatKonsumDelikt && icdScore >= ICD_ABHAENGIGKEIT_MIN) {
                    e.preventDefault();
                    new bootstrap.Modal(document.getElementById('modal-icd-bestaetigung')).show();
                }
            });
            document.getElementById('btn-icd-bestaetigen').addEventListener('click', function () {
                _icdBestaetigt = true;
                bootstrap.Modal.getInstance(document.getElementById('modal-icd-bestaetigung')).hide();
                document.getElementById('bearbeiten-form').requestSubmit();
            });

            document.getElementById('btn-status-reopen-bestaetigen').addEventListener('click', function () {
                document.getElementById('status_reopen_bestaetigt').value = '1';
                _statusBestaetigt = true;
                bootstrap.Modal.getInstance(document.getElementById('modal-status-reopen')).hide();
                document.getElementById('bearbeiten-form').requestSubmit();
            });
        });
    </script>

    <?php
    // Die Erklär-Modale liegen zentral in partials/_modals_info.php (bisher nur von
    // aufnahme.php und akte.php eingebunden). Gebraucht wird hier das Modal
    // #modal-info-screening-scores; keine ID-Kollision mit den eigenen Modalen unten.
    require 'partials/_modals_info.php';
    ?>

    <!-- Status-Rücksetzer-Bestätigungsdialog -->
    <div class="modal modal-blur fade" id="modal-status-reopen" tabindex="-1" role="dialog" aria-hidden="true" aria-labelledby="modal-title-status-reopen">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="modal-title d-flex align-items-center mb-2">
                        <i class="ti ti-lock-open text-warning me-2 fs-2" aria-hidden="true"></i>
                        <strong id="modal-title-status-reopen">Abgeschlossenen Fall wieder öffnen?</strong>
                    </div>
                    <p class="text-secondary mb-1">
                        Der Fall ist als <strong>Abgeschlossen</strong> markiert.
                        Sie setzen ihn auf <strong id="status-reopen-neuer-status"></strong> zurück.
                    </p>
                    <p class="text-secondary small mb-0">
                        Dieser Schritt wird im Audit-Log festgehalten.
                        Bitte nur fortfahren, wenn ein fachlicher Grund vorliegt.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="button" class="btn btn-warning" id="btn-status-reopen-bestaetigen">
                        <i class="ti ti-lock-open me-1" aria-hidden="true"></i>Ja, Fall wieder öffnen
                    </button>
                </div>
            </div>
        </div>
    </div>

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
</body>
</html>