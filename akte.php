<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'logic/mpu_helper.php';
require_once 'config/allowed_values.php';

// UX-3: ICD-Schwelle fuer Anzeigetexte aus derselben Quelle wie die Engine lesen.
$_icd_cfg               = require 'config/triage_config.php';
$_icd_abhaengigkeit_min = (int)($_icd_cfg['icd']['abhaengigkeit_min'] ?? 3);

// L2: BAK-Schwellen ebenso. Sie standen als Literale im Warntext-Block unten –
// deckungsgleich mit der Config, aber ohne Bindung an sie. Genau daraus sind
// E-4 und E-6 entstanden.
$_bak_a2_hoch        = (float)$_icd_cfg['bak']['a2_hoch'];        // 2,5 ‰
$_bak_a3_ausgepraegt = (float)$_icd_cfg['bak']['a3_ausgepraegt']; // 1,6 ‰
$_bak_giftfestigkeit = (float)$_icd_cfg['bak']['giftfestigkeit']; // 2,0 ‰
$_promille_txt       = static fn(float $w): string => number_format($w, 1, ',', '.');

$klienten_identifikation = (int)($_GET['id'] ?? 0);
$akte_case_id            = (int)($_GET['case_id'] ?? 0);

if ($klienten_identifikation <= 0) {
    http_response_code(400);
    $_error_title = 'Ungültige Anfrage';
    $_error_message = 'Keine Klientenakte ausgewählt. <a href="index.php">Zurück zur Startseite</a>';
    require __DIR__ . '/partials/error.php';
    exit;
}

// Ohne diese Prüfung liefe ein unbekannter Klient in das automatische Anlegen einer
// leeren Fallakte und scheiterte dort am Foreign Key (HTTP 500), zum Beispiel über
// einen Link oder Tab auf einen inzwischen gelöschten Klienten.
$_klient_da = $pdo->prepare('SELECT 1 FROM clients WHERE id = ?');
$_klient_da->execute([$klienten_identifikation]);
if ($_klient_da->fetchColumn() === false) {
    http_response_code(404);
    $_error_title   = 'Klient nicht gefunden';
    $_error_message = 'Diese Klientenakte gibt es nicht oder nicht mehr. <a href="klienten.php">Zur Klientenliste</a>';
    require __DIR__ . '/partials/error.php';
    exit;
}

require __DIR__ . '/handlers/akte_handler.php';

// ── Daten laden ──────────────────────────────────────────────────────────────

// 1. Alle Fallakten dieses Klienten (für Fallselektion + Switcher)
$stmt_alle_faelle = $pdo->prepare("SELECT id, status, created_at FROM cases WHERE client_id = ? ORDER BY created_at DESC, id DESC");
$stmt_alle_faelle->execute([$klienten_identifikation]);
$alle_faelle = $stmt_alle_faelle->fetchAll();
$akte_eingaben = $_SESSION['akte_eingaben'] ?? null;
unset($_SESSION['akte_eingaben']);
// Stand für das Formular „Neue Fallakte" (Schutz vor Doppel-Absenden im Handler)
$aktive_fall_id = 0;
foreach ($alle_faelle as $fall_stand) {
    if ($fall_stand['status'] === 'aktiv') {
        $aktive_fall_id = (int)$fall_stand['id'];
        break;
    }
}

// Aktive Fallakte bestimmen: URL-Parameter case_id bevorzugen, sonst älteste aktive
$aktiver_fall = null;
if ($akte_case_id > 0) {
    foreach ($alle_faelle as $fall) {
        if ((int)$fall['id'] === $akte_case_id) {
            $aktiver_fall = $fall;
            break;
        }
    }
}
if ($aktiver_fall === null) {
    foreach ($alle_faelle as $fall) {
        if ($fall['status'] === 'aktiv') { $aktiver_fall = $fall; break; }
    }
    if ($aktiver_fall === null && !empty($alle_faelle)) {
        $aktiver_fall = $alle_faelle[0];
    }
}
if ($aktiver_fall === null) {
    // Klient hat noch keinen Fall (z. B. neue Migration) – leeren Fall anlegen
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO cases (client_id, status) VALUES (?, 'aktiv')")->execute([$klienten_identifikation]);
    $neuer_fall_id = (int)$pdo->lastInsertId();
    audit_log($pdo, 'create_case', 'cases', $neuer_fall_id, $klienten_identifikation, 'Leere Fallakte automatisch angelegt (Migration)');
    $pdo->commit();
    $aktiver_fall = ['id' => $neuer_fall_id, 'status' => 'aktiv', 'client_id' => $klienten_identifikation];
    $alle_faelle  = [$aktiver_fall];
    $aktive_fall_id = $neuer_fall_id;
}
$akte_case_id = (int)$aktiver_fall['id'];

// 2. Klient + gewählte Fallakte als $akte (für Kompatibilität mit restlichem Code)
$statement = $pdo->prepare("
    SELECT
        c.id AS client_pk, c.first_name, c.last_name, c.date_of_birth, c.street,
        c.zip_code, c.city, c.phone, c.email, c.gender, c.weight_kg,
        c.dsgvo_consent, c.dsgvo_consent_date, c.revocation_notice_confirmed, c.revocation_confirmed, c.early_start_requested,
        c.created_at AS client_created_at,
        ca.*
    FROM clients c
    JOIN cases ca ON ca.id = ?
    WHERE c.id = ?
");
$statement->execute([$akte_case_id, $klienten_identifikation]);
$akte = $statement->fetch();

if (!$akte) {
    http_response_code(404);
    $_error_title = 'Klient nicht gefunden';
    $_error_message = 'Klient konnte in der Datenbank nicht gefunden werden.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// 3. Abstinenz-Nachweise (fallspezifisch)
$stmt_probes = $pdo->prepare("SELECT id, client_id, case_id, probe_date, method, result, lab_name, notes, kreatinin_mg_dl, peth_value, created_at FROM abstinence_probes WHERE client_id = ? AND (case_id = ? OR case_id IS NULL) ORDER BY probe_date ASC");
$stmt_probes->execute([$klienten_identifikation, $akte_case_id]);
$probes = $stmt_probes->fetchAll();

// 4. Tasks provisionieren (idempotent – ergänzt fehlende Tasks via INSERT IGNORE)
provision_client_tasks(
    $pdo,
    $klienten_identifikation,
    $akte_case_id,
    (string)($akte['offense_category'] ?? '[]'),
    $akte['suspension_end_date'] ?? null,
    (string)($akte['antrag_status'] ?? 'nicht_gestellt')
);

// 5. Tasks laden (nach Provisionierung, fallspezifisch)
$stmt_tasks = $pdo->prepare("SELECT id, client_id, case_id, task_key, status, updated_at FROM client_tasks WHERE client_id = ? AND (case_id = ? OR case_id IS NULL) ORDER BY task_key ASC");
$stmt_tasks->execute([$klienten_identifikation, $akte_case_id]);
$client_tasks = $stmt_tasks->fetchAll();

// 6. Sitzungen laden (fallspezifisch)
$stmt_sessions = $pdo->prepare("SELECT id, client_id, case_id, session_date, duration_min, phase, notes, sorkc_s, sorkc_o, sorkc_r, sorkc_k, sorkc_c, created_at FROM sessions WHERE client_id = ? AND (case_id = ? OR case_id IS NULL) ORDER BY session_date ASC");
$stmt_sessions->execute([$klienten_identifikation, $akte_case_id]);
$sessions = $stmt_sessions->fetchAll();

// 6b. Notiz-Journal laden (fallspezifisch, neueste zuerst)
$stmt_notes = $pdo->prepare("SELECT id, note_date, title, body, updated_at FROM case_notes WHERE client_id = ? AND case_id = ? ORDER BY note_date DESC, id DESC");
$stmt_notes->execute([$klienten_identifikation, $akte_case_id]);
$case_notes = $stmt_notes->fetchAll();

// 7. Korrespondenz-Ereignisse laden
$stmt_events = $pdo->prepare("SELECT id, event_date, event_type, description, created_at FROM case_events WHERE client_id = ? AND (case_id = ? OR case_id IS NULL) ORDER BY event_date DESC, id DESC");
$stmt_events->execute([$klienten_identifikation, $akte_case_id]);
$case_events = $stmt_events->fetchAll();

// 8. Leistungstests laden (fallspezifisch, nur für BKF-Fälle)
$performance_tests = [];
if (($akte['bkf_status'] ?? 'nein') !== 'nein') {
    $stmt_perf = $pdo->prepare("SELECT id, test_date, test_name, percentile, notes, created_at FROM performance_tests WHERE client_id = ? AND case_id = ? ORDER BY test_date ASC");
    $stmt_perf->execute([$klienten_identifikation, $akte_case_id]);
    $performance_tests = $stmt_perf->fetchAll();
}

// ── Diagnostik-Algorithmus ───────────────────────────────────────────────────
$warnungen = [];
$empfohlene_hypothese = $akte['suggested_hypothesis'] ?: "Noch nicht ausreichend Daten vorhanden";
$abstinenz_empfehlung = $akte['suggested_abstinence'] ?: "Unbekannt";

// Nur explizit bejahte Kriterien (1) zählen für den Score.
// NULL = nicht erhoben darf nicht als 0 (Nein) gewertet werden.
$icd_felder_akte = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden','icd_craving','icd_toleranz','icd_entzug'];
$icd_score_gesamt  = 0; // Anzahl bejahter Kriterien (= 1)
$icd_score_erhoben = 0; // Anzahl erhobener Kriterien (nicht NULL)
foreach ($icd_felder_akte as $_icd_feld) {
    $v = $akte[$_icd_feld] ?? null;
    if ($v !== null) { $icd_score_erhoben++; }
    if ((int)$v === 1) { $icd_score_gesamt++; }
}
unset($_icd_feld, $v);

// UX-6: Fälle dürfen ohne vollständigen ICD-Quick-Check angelegt werden.
// Solange Kriterien fehlen, ist die ausgewiesene Hypothese eine Untergrenze.
$triage_vollstaendigkeit = get_triage_vollstaendigkeit($akte);

// E-7: dokumentierte Screening-Scores sichtbar machen (fließen nicht in die Triage ein)
$screening_scores = get_screening_scores($akte);

$offense_list = json_decode($akte['offense_category'] ?? '', true) ?: [];
if (in_array('Alkohol', $offense_list)) {
    $promille = (float)$akte['bak_value'];
    if ($promille >= $_bak_giftfestigkeit && !empty($akte['symptoms_recorded'])) {
        $warnungen[] = "Keine Ausfallerscheinungen trotz " . $promille . " Promille: Hinweis auf ausgeprägte Giftfestigkeit. Die Wertung trifft der Gutachter.";
    }
    if (!$akte['suggested_hypothesis'] || $akte['suggested_hypothesis'] === "Noch nicht ausreichend Daten vorhanden") {
        if ($promille >= $_bak_a2_hoch) {
            $empfohlene_hypothese = "Hypothese A2 (Fortgeschrittene Problematik)";
            $abstinenz_empfehlung = "15 Monate (BK-5)";
        } elseif ($promille >= $_bak_a3_ausgepraegt) {
            $empfohlene_hypothese = "Hypothese A2/A3 (Gefährdung)";
            $abstinenz_empfehlung = "12 Monate empfohlen";
        }
    }
    if ($promille >= $_bak_a2_hoch && !$akte['therapy_completed'] && empty($akte['override_active'])) {
        $warnungen[] = "BK-5 Alarm: Als 'Selbstheiler' ohne Suchttherapie sind nun zwingend 15 Monate Abstinenzbelege erforderlich.";
    }
    if ($promille >= $_bak_a2_hoch) {
        $warnungen[] = "Achtung: Bei Werten ab " . $_promille_txt($_bak_a2_hoch) . " Promille schlägt die Software kein kontrolliertes Trinken vor. Ausnahmen regelt die BK-5 (A 2.7 N, am Buch zu prüfen).";
    }
}
if (in_array('Punkte', $offense_list)) {
    $warnungen[] = "Punkte-Täter: Der Fokus muss extrem auf der biografischen Analyse liegen. Reines Regelwissen reicht für ein positives Gutachten nicht aus.";
}
// ICD-Score = 1: Verdacht dokumentieren, aber kein A1-Schwellenwert erreicht
if ($icd_score_gesamt === 1) {
    $warnungen[] = "ICD-Quick-Check: 1 von 6 Abhängigkeitskriterien erfüllt – Abhängigkeitsverdacht nicht ausgeschlossen. Suchtanamnese vertiefen; bei Zunahme der Kriterien Hypothese auf A1 anheben.";
}
// Mischkonsum Alkohol + Drogen
if (in_array('Alkohol', $offense_list) && in_array('Drogen', $offense_list)) {
    $warnungen[] = "Mischkonsum (Alkohol + Drogen): Massiver Erschwerungsgrund gemäß BK-5 – Gutachter setzen die jeweils höchste Abstinenzdauer beider Deliktfelder an.";
}
// Doppelte Auffälligkeit Alkohol + Punkte
if (in_array('Alkohol', $offense_list) && in_array('Punkte', $offense_list)) {
    $warnungen[] = "Alkohol + Punkte-Kombination: Doppelte Auffälligkeit im Straßenverkehr. Gutachter prüfen Suchtproblematik UND Einstellungsmuster unabhängig – beide Bereiche biografisch vollständig aufarbeiten.";
}
if (!empty($akte['override_active'])) {
    $warnungen[] = "Berater-Override aktiv: Hypothese und Abstinenzdauer weichen vom Systemvorschlag ab, die Soll-Werte folgen dem Override.";
}
// Karenzzeit-Warnung wird weiter unten nach Berechnung von $abst_monate und $probes ergänzt

// ── Helper-Auswertungen ───────────────────────────────────────────────────────
$abst_monate = (int)($akte['abstinenz_monate'] ?? 0);

$antrag_ampel      = get_antrag_ampel(
    $akte['suspension_end_date'] ?? null,
    (string)($akte['antrag_status'] ?? ''),
    $akte['mpu_target_date'] ?? null,
    get_behoerden_vorlauf_aus_akte($akte) // E-4: Behörden-Vorlauf statt fester 60 Tage
);
$abstinenz_info    = get_abstinenz_luecken($probes, true, (string)($akte['offense_category'] ?? ''));
$naechster_schritt = get_client_status($akte, $probes, $client_tasks);
$karenzzeit_info   = get_karenzzeit_info($akte['suspension_end_date'] ?? null, $probes, $abst_monate);
if ($karenzzeit_info !== null && $karenzzeit_info['kollision']) {
    $kz_ende    = date('d.m.Y', strtotime($karenzzeit_info['abstinenz_ende']));
    $kz_puffer  = $karenzzeit_info['puffer_tage'];
    $warnungen[] = "Karenzzeit-Kollision: Berechnetes Abstinenzende ({$kz_ende}) liegt nur {$kz_puffer} Tage vor Sperrfristende – weniger als die erforderlichen 90 Tage Bewährungszeit. MPU-Termin und Abstinenzende zu nah beieinander.";
}

// ── Checkliste: Metadaten + Fortschritt ──────────────────────────────────────
$templates_raw = require __DIR__ . '/config/checklist_templates.php';
$task_meta = [];
foreach ($templates_raw as $gruppe) {
    foreach ($gruppe as $t) {
        $task_meta[$t['task_key']] = $t;
    }
}
usort($client_tasks, function ($a, $b) use ($task_meta) {
    $ma = $task_meta[$a['task_key']] ?? [];
    $mb = $task_meta[$b['task_key']] ?? [];
    $ga = $ma['group_priority'] ?? 9;
    $gb = $mb['group_priority'] ?? 9;
    if ($ga !== $gb) return $ga <=> $gb;
    return ($ma['priority'] ?? 9) <=> ($mb['priority'] ?? 9);
});
$tasks_gesamt  = count($client_tasks);
$tasks_offen   = count(array_filter($client_tasks, fn($t) => $t['status'] === 'offen'));
$tasks_prozent = $tasks_gesamt > 0 ? (int)(($tasks_gesamt - $tasks_offen) / $tasks_gesamt * 100) : 100;

// ── Nächste offene Frist (für Fristen-Wächter) ───────────────────────────────
$naechste_frist = null;
if (!empty($akte['suspension_end_date'])) {
    $sf_ts = strtotime($akte['suspension_end_date']);
    $kandidat_ts = PHP_INT_MAX;
    foreach ($client_tasks as $t) {
        if ($t['status'] === 'liegt_vor') continue;
        $m = $task_meta[$t['task_key']] ?? [];
        if (!array_key_exists('days_before_end', $m)) continue;
        $frist_ts = $sf_ts - $m['days_before_end'] * 86400;
        if ($frist_ts < $kandidat_ts) {
            $kandidat_ts    = $frist_ts;
            $naechste_frist = ['label' => $m['label'], 'datum' => date('d.m.Y', $frist_ts), 'status' => $t['status']];
        }
    }
}

// ── Sitzungen: Fortschritt ────────────────────────────────────────────────────
$sessions_count   = count($sessions);
$sessions_max     = 10;
$sessions_prozent = min(100, (int)($sessions_count / $sessions_max * 100));
$phase_labels     = ['anamnese' => 'Anamnese & Diagnostik', 'analyse' => 'Ursachenanalyse & Veränderung', 'check' => 'Stabilisierung & Simulation'];
// Phase aus der zuletzt erfassten Sitzung ableiten (chronologisch letzte nach session_date).
// Fallback auf Anzahl-Heuristik wenn noch keine Sitzungen vorhanden.
if ($sessions_count > 0) {
    $letzte_sitzung    = array_reduce($sessions, fn($carry, $s) => ($carry === null || $s['session_date'] >= $carry['session_date']) ? $s : $carry, null);
    $current_phase_key = $letzte_sitzung['phase'] ?? 'anamnese';
} else {
    $current_phase_key = 'anamnese';
}

// ── R3-2: Kreatinin-Schwellen für das Eingabefeld ────────────────────────────
// Werte aus config/ctu_config.php, die Texte interpolieren sie.
$_kreat_cfg          = require __DIR__ . '/config/ctu_config.php';
$_fmt_mg_dl          = static fn(float $w): string => rtrim(rtrim(number_format($w, 1, ',', '.'), '0'), ',');
$kreat_verwertbar_txt = $_fmt_mg_dl((float)$_kreat_cfg['kreatinin']['verwertbar_ab_mg_dl']);
$kreat_kein_neg_txt   = $_fmt_mg_dl((float)$_kreat_cfg['kreatinin']['kein_negativbefund_bis_mg_dl']);
$peth_grenze_txt      = $_fmt_mg_dl((float)$_kreat_cfg['peth']['abstinenz_unter_ng_ml']);

// ── Abstinenz: Probe-Zähler ───────────────────────────────────────────────────
$pflicht_urin = urin_pflicht_proben($abst_monate);
// R3-3: Haar-Soll aus der Segmentlänge rechnen statt fest verdrahten. Die BK-5
// nennt keine Stückzahl, nur eine max. Segmentlänge (3 cm Alkohol / 6 cm
// Drogen) und einen lückenlos zu belegenden Zeitraum. Die alte Staffel
// (15 → 5, 12 → 4, sonst 2) war die Alkohol-Ableitung und galt auch für
// Drogenfälle – dort verlangte sie doppelt so viele Analysen wie nötig.
$haar_segment_monate = haar_segment_monate((string)($akte['offense_category'] ?? ''));
$pflicht_haar        = haar_pflicht_analysen($abst_monate, $haar_segment_monate);
$belege_kette = abstinenz_belege_der_kette($probes);
$actual_urin  = count(array_filter($belege_kette, fn($p) => $p['method'] === 'urin'));
$actual_haar  = count(array_filter($belege_kette, fn($p) => $p['method'] === 'haar'));

// ── F12: Screening-Frequenz-Ampel (grün / gelb / rot) ────────────────────────
$ampel_urin = screening_ampel($actual_urin, $pflicht_urin, $probes, $abst_monate, (string)($akte['created_at'] ?? ''));
$ampel_haar = screening_ampel($actual_haar, $pflicht_haar, $probes, $abst_monate, (string)($akte['created_at'] ?? ''));

// ── F-2: BKF-Leistungstest-Ampel ─────────────────────────────────────────────
$ist_bkf        = in_array($akte['bkf_status'] ?? 'nein', ['aktiv', 'angestrebt'], true);
$leistung_ampel = bkf_leistung_ampel($performance_tests, $ist_bkf);

?>
<?php
$page_title = 'MPU-Manager | Akte: ' . ($akte['first_name'] . ' ' . $akte['last_name']);
require 'partials/_head.php';
$active_page = 'akte';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Digitale Klientenakte #<?= (int)$akte['client_id'] ?></div>
                            <h1 class="page-title">
                                <?= htmlspecialchars($akte['first_name'] . ' ' . $akte['last_name']) ?>
                                <span class="badge badge-sm bg-blue-lt ms-3"><?= htmlspecialchars(implode(', ', $offense_list) ?: '–') ?></span>
                                <?php
                                    $status_map = ['aktiv' => ['bg-green-lt','Aktiv'], 'abgeschlossen' => ['bg-secondary-lt','Abgeschlossen'], 'pausiert' => ['bg-warning-lt','Pausiert']];
                                    [$st_bg, $st_txt] = $status_map[$akte['status'] ?? 'aktiv'] ?? ['bg-secondary-lt','–'];
                                ?>
                                <span class="badge badge-sm <?= $st_bg ?> ms-1"><?= $st_txt ?></span>
                            </h1>
                        </div>
                        <div class="col-auto ms-auto d-print-none">
                            <div class="btn-list">
                                <?php if (count($alle_faelle) > 1): ?>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <i class="ti ti-folders me-1" aria-hidden="true"></i>
                                        Fall <?= (int)$akte_case_id ?>
                                        <?php if (($aktiver_fall['status'] ?? '') === 'abgeschlossen'): ?>
                                            <span class="badge bg-secondary-lt ms-1">Archiv</span>
                                        <?php endif; ?>
                                    </button>
                                    <div class="dropdown-menu">
                                        <?php foreach ($alle_faelle as $fall):
                                            $is_current = (int)$fall['id'] === $akte_case_id;
                                            $fall_datum  = date('d.m.Y', strtotime($fall['created_at']));
                                            $fall_status = ['aktiv' => 'Aktiv', 'abgeschlossen' => 'Archiv', 'pausiert' => 'Pausiert'][$fall['status']] ?? '–';
                                        ?>
                                        <a class="dropdown-item <?= $is_current ? 'active' : '' ?>"
                                           href="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= (int)$fall['id'] ?>">
                                            Fall vom <?= $fall_datum ?> <span class="text-secondary ms-1">(<?= $fall_status ?>)</span>
                                        </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <a href="akte_druck.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>"
                                   target="_blank" rel="noopener"
                                   class="btn btn-sm btn-outline-secondary d-none d-sm-inline-block"
                                   title="Druckansicht öffnen">
                                    <i class="ti ti-printer me-2" aria-hidden="true"></i> Drucken
                                </a>
                                <a href="bearbeiten.php?id=<?= (int)$akte['client_id'] ?>&case_id=<?= $akte_case_id ?>" class="btn btn-sm btn-outline-secondary d-none d-sm-inline-block">
                                    <i class="ti ti-edit me-2" aria-hidden="true"></i> Akte bearbeiten
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger d-none d-sm-inline-block" data-bs-toggle="modal" data-bs-target="#modal-klient-loeschen">
                                    <i class="ti ti-trash me-2" aria-hidden="true"></i> Löschen
                                </button>
                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline" data-confirm="Aktuelle Fallakte archivieren und neue anlegen?">
                                    <input type="hidden" name="action" value="new_case">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="aktiver_fall_id" value="<?= $aktive_fall_id ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary d-none d-sm-inline-block" title="Neue Fallakte anlegen">
                                        <i class="ti ti-folder-plus me-2" aria-hidden="true"></i> Neue Fallakte
                                    </button>
                                </form>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Verträge und Dokumente drucken">
                                        <i class="ti ti-printer me-2" aria-hidden="true"></i> Dokumente drucken
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a class="dropdown-item" href="vertrag_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener">Beratungsvertrag</a>
                                        <a class="dropdown-item" href="datenschutz_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>
                                        <a class="dropdown-item" href="widerruf_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener">Widerrufsbelehrung</a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item" href="strategie_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener">Strategie-Vereinbarung</a>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                            <input type="hidden" name="action" value="dsgvo_export">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <button type="submit" class="dropdown-item">
                                                <i class="ti ti-shield-lock me-2 text-warning" aria-hidden="true"></i>DSGVO-Export (Art. 20)
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary d-none d-sm-inline-block" disabled>
                                    <i class="ti ti-file-certificate me-2" aria-hidden="true"></i> Teilnahmebescheinigung generieren
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                        <?php require __DIR__ . '/partials/flash.php'; ?>

                        <?php if (!empty($akte['revocation_confirmed'])): ?>
                        <div class="alert alert-danger mb-3" role="alert">
                            <div class="alert-icon"><i class="ti ti-shield-off icon alert-icon" aria-hidden="true"></i></div>
                            <div>
                                <strong>DSGVO-Einwilligung widerrufen</strong> – Der Klient hat seine Einwilligung gemäß Art. 7 Abs. 3 DSGVO widerrufen. Nach Art. 17 DSGVO besteht ein Recht auf Löschung.
                                <div class="mt-2 d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#modal-dsgvo-delete">
                                        <i class="ti ti-trash me-1" aria-hidden="true"></i> Klient und alle Daten löschen (Art. 17 DSGVO)
                                    </button>
                                    <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                        <input type="hidden" name="action" value="dsgvo_export">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <i class="ti ti-download me-1" aria-hidden="true"></i>DSGVO-Export vor Löschung
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (($aktiver_fall['status'] ?? '') === 'abgeschlossen'): ?>
                        <div class="alert alert-warning mb-3" role="alert">
                            <div class="alert-icon"><i class="ti ti-archive icon alert-icon" aria-hidden="true"></i></div>
                            <div>
                                <strong>Archivierte Fallakte</strong> — Diese Akte ist abgeschlossen.
                                <?php foreach ($alle_faelle as $fall):
                                    if ($fall['status'] === 'aktiv'): ?>
                                <a href="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= (int)$fall['id'] ?>" class="alert-link ms-2">Zur aktiven Fallakte wechseln →</a>
                                <?php break; endif; endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($akte['dsgvo_consent']) && empty($akte['revocation_confirmed'])): ?>
                        <div class="alert alert-warning mb-3" role="alert" id="einwilligung">
                            <div class="alert-icon"><i class="ti ti-file-alert icon alert-icon" aria-hidden="true"></i></div>
                            <div class="flex-fill">
                                <strong>Einwilligung zum Datenschutz fehlt.</strong>
                                Die Datenschutzerklärung ist noch nicht unterzeichnet. Das ist nach telefonischer Aufnahme normal, muss aber vor der weiteren Verarbeitung nachgeholt werden.
                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>" class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <input type="hidden" name="action" value="consent_set">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                    <label class="form-label mb-0" for="consent_date">Unterzeichnet am</label>
                                    <input type="date" class="form-control form-control-sm w-auto" id="consent_date" name="consent_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                                    <button type="submit" class="btn btn-sm btn-warning">Einwilligung eintragen</button>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- ══ NÄCHSTER SCHRITT ══════════════════════════════════════════════ -->
                        <?php
                            $ns_level = $naechster_schritt['level'];
                            $ns_icon  = ['danger' => 'ti-alert-triangle', 'warning' => 'ti-alert-circle', 'success' => 'ti-circle-check'][$ns_level] ?? 'ti-info-circle';
                            $ns_color = $ns_level === 'success' ? 'success' : ($ns_level === 'danger' ? 'danger' : 'warning');
                        ?>
                        <div class="alert alert-important alert-<?= $ns_color ?> mb-3" role="status">
                            <div class="alert-icon"><i class="ti <?= $ns_icon ?> icon alert-icon" aria-hidden="true"></i></div>
                            <div>
                                <strong>Nächster Schritt:</strong>
                                <?= htmlspecialchars($naechster_schritt['message']) ?>
                            </div>
                            <?php if ($ns_level !== 'success'): ?>
                            <a href="<?= htmlspecialchars($naechster_schritt['action_url']) ?>" class="btn btn-sm btn-<?= $ns_color ?> ms-auto flex-shrink-0">
                                Zum Detail <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i>
                            </a>
                            <?php endif; ?>
                        </div>

                    <div class="row row-deck row-cards">

                        <!-- ══ DIAGNOSTISCHE AUSWERTUNG ═══════════════════════════════════════ -->
                        <div class="col-lg-12">
                            <div class="card">
                                <div class="card-status-top bg-primary"></div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-6">
                                            <h3 class="card-title text-primary mb-3"><i class="ti ti-brain me-2" aria-hidden="true"></i>Orientierung der Software (keine Diagnose)</h3>
                                            <?php $_hypothese_anker = anleitung_anchor_fuer_hypothese($empfohlene_hypothese); ?>
                                            <div class="mb-3">
                                                <strong>System-Prognose:</strong><br>
                                                <div class="h1 text-primary mb-0">
                                                    <?= htmlspecialchars($empfohlene_hypothese) ?>
                                                    <?php if ($triage_vollstaendigkeit['unvollstaendig']): ?>
                                                        <span class="badge bg-warning-lt align-middle fs-4" title="ICD-Quick-Check noch nicht abgeschlossen"><i class="ti ti-hourglass" aria-hidden="true"></i> Vorläufig</span>
                                                    <?php endif; ?>
                                                    <?php if ($_hypothese_anker !== ''): ?>
                                                        <a href="anleitung.php#<?= htmlspecialchars($_hypothese_anker) ?>" class="btn btn-icon btn-ghost-secondary btn-sm align-middle" title="Fachliche Erläuterung im Handbuch öffnen">
                                                            <i class="ti ti-info-circle" aria-hidden="true"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div>
                                                <strong>Notwendige Abstinenz:</strong><br>
                                                <span class="h3 d-inline-block mt-1 mb-0"><?= htmlspecialchars($abstinenz_empfehlung) ?></span>
                                            </div>
                                        </div>
                                        <div class="col-12 col-md-6 align-self-start">
                                            <strong class="d-block mb-2">Fachliche Warnhinweise:</strong>
                                            <?php if (empty($warnungen)): ?>
                                                <div class="alert alert-success alert-minor" role="alert">
                                                    <div class="alert-icon"><i class="ti ti-check icon alert-icon" aria-hidden="true"></i></div>
                                                    <div>Keine besonderen Erschwernisgründe erkannt.</div>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($warnungen as $warnung): ?>
                                                    <div class="alert alert-danger alert-minor" role="alert">
                                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                        <div><?= htmlspecialchars($warnung) ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($screening_scores)): ?>
                                    <div class="mt-3 pt-3 border-top">
                                        <div class="d-flex align-items-center flex-wrap gap-3">
                                            <span class="text-secondary small fw-bold text-uppercase">Screening-Scores</span>
                                            <?php foreach ($screening_scores as $_sc): ?>
                                            <span>
                                                <span class="text-secondary small"><?= htmlspecialchars($_sc['name']) ?></span>
                                                <span class="badge bg-<?= htmlspecialchars($_sc['level']) ?>-lt ms-1"><?= (int)$_sc['wert'] ?> / <?= (int)$_sc['max'] ?></span>
                                                <span class="text-secondary small ms-1"><?= htmlspecialchars($_sc['label']) ?></span>
                                            </span>
                                            <?php endforeach; ?>
                                            <button type="button" class="btn btn-icon btn-ghost-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-info-screening-scores" title="AUDIT und LAST erklärt" aria-label="Screening-Scores erklärt">
                                                <i class="ti ti-info-circle" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                        <div class="text-secondary small mt-1">
                                            Dokumentation &ndash; fließt nicht in die oben berechnete Hypothese ein.
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($triage_vollstaendigkeit['unvollstaendig']): ?>
                                    <div class="alert alert-warning mt-3 mb-0" role="alert">
                                        <div class="alert-icon"><i class="ti ti-hourglass icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Triage vorläufig – ICD-Quick-Check unvollständig</h4>
                                            <div class="alert-description">
                                                Noch nicht erhoben: <strong><?= htmlspecialchars(implode(', ', $triage_vollstaendigkeit['fehlend'])) ?></strong>.
                                                Nicht erhobene Kriterien gehen in die Berechnung als „nicht erfüllt" ein – die oben genannte
                                                Hypothese ist damit eine <strong>Untergrenze</strong> und kann sich beim Nachtragen noch verschärfen.
                                                <a href="bearbeiten.php?id=<?= (int)$klienten_identifikation ?>&amp;case_id=<?= (int)$akte_case_id ?>#icd_quickcheck" class="ms-1">Jetzt nachtragen</a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($icd_score_erhoben >= 1): ?>
                                    <div class="alert alert-info mt-3 mb-0" role="note">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">ICD-Quick-Check (<?= $icd_score_gesamt ?> von <?= $icd_score_erhoben ?> erhobenen Kriterien positiv)</h4>
                                            <div class="alert-description">Dieses Tool ist ein <strong>Screening-Hilfsmittel</strong>, kein diagnostisches Instrument. ICD-10 F1x.2 erfordert formal <?= $_icd_abhaengigkeit_min ?>&nbsp;von&nbsp;6 Kriterien über&nbsp;12&nbsp;Monate – eine klinische Diagnose obliegt ausschließlich dem Gutachter.</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- ══ COCKPIT: FRISTEN-AMPEL + ABSTINENZ-SUMMARY ════════════════════ -->
                        <div class="col-lg-12">
                            <div class="row row-cards">

                                <!-- Fristen-Ampel -->
                                <div class="col-md-6 col-lg-4">
                                    <div class="card card-sm h-100">
                                        <div class="card-status-start bg-<?= $antrag_ampel['level'] ?>"></div>
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="bg-<?= $antrag_ampel['level'] ?> text-<?= $antrag_ampel['level'] ?>-fg avatar">
                                                        <i class="ti ti-calendar-event" aria-hidden="true"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="fw-medium">
                                                        Fristen-Wächter
                                                        <a href="#" class="ms-1 text-secondary"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modal-info-fristen-ampel"
                                                            data-ampel-level="<?= $antrag_ampel['level'] ?>"
                                                            data-antrag-status="<?= htmlspecialchars($akte['antrag_status'] ?? '') ?>"
                                                            title="Erläuterungen &amp; Ressourcen zur Wiedererteilung"
                                                        ><i class="ti ti-info-circle" aria-hidden="true"></i></a>
                                                    </div>
                                                    <div class="text-secondary small">
                                                        <?php if ($akte['suspension_end_date']): ?>
                                                            Sperrfrist-Ende: <?= date('d.m.Y', strtotime($akte['suspension_end_date'])) ?>
                                                        <?php else: ?>
                                                            Keine Sperrfrist eingetragen
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <div class="alert alert-<?= $antrag_ampel['level'] ?> alert-important mb-0" role="alert">
                                                    <div class="alert-icon"><i class="ti ti-clock icon alert-icon" aria-hidden="true"></i></div>
                                                    <div>
                                                        <?= htmlspecialchars($antrag_ampel['label']) ?>
                                                        <?php if ($antrag_ampel['days_remaining'] !== null): ?>
                                                            &nbsp;–&nbsp;<?php if ($antrag_ampel['days_remaining'] < 0): ?>vor <?= abs($antrag_ampel['days_remaining']) ?> Tagen abgelaufen<?php else: ?>noch <?= $antrag_ampel['days_remaining'] ?> Tage<?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($naechste_frist !== null): ?>
                                            <div class="mt-2 d-flex align-items-center text-secondary small">
                                                <i class="ti ti-arrow-right me-1 flex-shrink-0" aria-hidden="true"></i>
                                                <span>
                                                    <span class="fw-medium text-body"><?= htmlspecialchars($naechste_frist['label']) ?></span>
                                                    <?php if ($naechste_frist['status'] === 'angefordert'): ?>
                                                        <span class="badge bg-warning-lt ms-1">angefordert</span>
                                                    <?php endif; ?>
                                                    <br>bis <strong><?= $naechste_frist['datum'] ?></strong>
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($naechste_frist === null && !empty($akte['suspension_end_date']) && !empty($client_tasks)): ?>
                                            <div class="mt-2 text-success small">
                                                <i class="ti ti-circle-check me-1" aria-hidden="true"></i>Alle Aufgaben erledigt
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Abstinenz-Summary -->
                                <div class="col-md-6 col-lg-4">
                                    <?php
                                        $abst_has_gap = !empty($abstinenz_info['gaps']);
                                        $abst_has_pos = $abstinenz_info['hat_positiv'];
                                        $abst_level   = $abst_has_pos ? 'danger' : ($abst_has_gap ? 'warning' : 'success');
                                    ?>
                                    <div class="card card-sm h-100">
                                        <div class="card-status-start bg-<?= $abst_level ?>"></div>
                                        <div class="card-body">
                                            <div class="row align-items-center mb-2">
                                                <div class="col-auto">
                                                    <span class="bg-<?= $abst_level ?> text-<?= $abst_level ?>-fg avatar">
                                                        <i class="ti ti-test-pipe" aria-hidden="true"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="fw-medium">Abstinenz-Monitor</div>
                                                    <div class="text-secondary small">Vorgabe: <?= htmlspecialchars((string)($akte['suggested_abstinence'] ?: 'noch nicht gesetzt')) ?></div>
                                                </div>
                                            </div>
                                            <?php if ($abst_monate > 0): ?>
                                            <div class="row g-2 text-center mt-1">
                                                <div class="col-6">
                                                    <div class="p-2 rounded bg-<?= $ampel_urin ?>-lt" title="<?= $ampel_urin === 'success' ? 'Soll erfüllt' : ($ampel_urin === 'warning' ? 'In Bearbeitung – Prognose gut' : 'Rückstand – Untererfüllung droht') ?>">
                                                        <div class="fs-4 fw-bold text-<?= $ampel_urin ?>"><?= $actual_urin ?>/<?= $pflicht_urin ?></div>
                                                        <div class="small text-secondary">Urin-Proben (neg.)</div>
                                                        <div class="small text-<?= $ampel_urin ?>">
                                                            <?php if ($ampel_urin === 'success'): ?><i class="ti ti-circle-check" aria-label="Erfüllt"></i>
                                                            <?php elseif ($ampel_urin === 'warning'): ?><i class="ti ti-clock" aria-label="In Bearbeitung"></i>
                                                            <?php else: ?><i class="ti ti-alert-triangle" aria-label="Rückstand"></i><?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="p-2 rounded bg-<?= $ampel_haar ?>-lt" title="<?= $ampel_haar === 'success' ? 'Soll erfüllt' : ($ampel_haar === 'warning' ? 'In Bearbeitung – Prognose gut' : 'Rückstand – Untererfüllung droht') ?>">
                                                        <div class="fs-4 fw-bold text-<?= $ampel_haar ?>"><?= $actual_haar ?>/<?= $pflicht_haar ?></div>
                                                        <div class="small text-secondary" title="Orientiert an den CTU-Kriterien der BK-5: je Analyse max. <?= $haar_segment_monate === 3 ? '3 cm bei Alkohol/EtG' : '6 cm bei Betäubungsmitteln' ?>, bei 1 cm Haarwachstum je Monat also <?= $haar_segment_monate ?> Monate Abdeckung. <?= $abst_monate ?> Monate ÷ <?= $haar_segment_monate ?> = <?= $pflicht_haar ?> Analysen.">Haar-Proben (neg.)<br><span class="text-secondary">à <?= $haar_segment_monate ?> Mon.</span></div>
                                                        <div class="small text-<?= $ampel_haar ?>">
                                                            <?php if ($ampel_haar === 'success'): ?><i class="ti ti-circle-check" aria-label="Erfüllt"></i>
                                                            <?php elseif ($ampel_haar === 'warning'): ?><i class="ti ti-clock" aria-label="In Bearbeitung"></i>
                                                            <?php else: ?><i class="ti ti-alert-triangle" aria-label="Rückstand"></i><?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php if ($abst_has_gap): ?>
                                            <div class="mt-2 small text-warning fw-bold"><i class="ti ti-alert-circle me-1" aria-hidden="true"></i><?= count($abstinenz_info['gaps']) ?> Lücke(n) erkannt</div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <div class="text-secondary small">Abstinenz-Vorgabe noch nicht gesetzt.</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sitzungs-Fortschritt -->
                                <div class="col-md-12 col-lg-4">
                                    <div class="card card-sm h-100">
                                        <div class="card-body">
                                            <div class="row align-items-center mb-2">
                                                <div class="col-auto">
                                                    <span class="bg-purple text-purple-fg avatar">
                                                        <i class="ti ti-users" aria-hidden="true"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="fw-medium">Sitzungs-Fortschritt</div>
                                                    <div class="text-secondary small">Phase: <?= htmlspecialchars($phase_labels[$current_phase_key]) ?></div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center mt-2">
                                                <div class="flex-fill">
                                                    <div class="progress progress-sm" role="progressbar" aria-label="Sitzungs-Fortschritt" aria-valuenow="<?= $sessions_prozent ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="progress-bar bg-purple" style="width: <?= $sessions_prozent ?>%"></div>
                                                    </div>
                                                </div>
                                                <div class="ms-3 text-secondary small fw-bold"><?= $sessions_count ?>/<?= $sessions_max ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <?php if (($akte['bkf_status'] ?? 'nein') !== 'nein'): ?>
                                <!-- BKF Leistungstest-Ampel -->
                                <div class="col-md-12 col-lg-4">
                                    <div class="card card-sm h-100">
                                        <div class="card-status-start bg-<?= $leistung_ampel['level'] ?>"></div>
                                        <div class="card-body">
                                            <div class="row align-items-center mb-2">
                                                <div class="col-auto">
                                                    <span class="bg-<?= $leistung_ampel['level'] ?> text-<?= $leistung_ampel['level'] ?>-fg avatar">
                                                        <i class="ti ti-activity" aria-hidden="true"></i>
                                                    </span>
                                                </div>
                                                <div class="col">
                                                    <div class="fw-medium">BKF-Leistungstests</div>
                                                    <div class="text-secondary small">BK-5 Kap. C 4 – PR ≥ 16 / Mehrheit ≥ 33</div>
                                                </div>
                                            </div>
                                            <?php if (!empty($performance_tests)): ?>
                                            <div class="row g-2 text-center mt-1">
                                                <div class="col-6">
                                                    <div class="p-2 rounded bg-<?= $leistung_ampel['fail_pr16'] === 0 ? 'success' : 'danger' ?>-lt">
                                                        <div class="fs-4 fw-bold text-<?= $leistung_ampel['fail_pr16'] === 0 ? 'success' : 'danger' ?>"><?= $leistung_ampel['fail_pr16'] ?></div>
                                                        <div class="small text-secondary">Tests &lt; PR 16</div>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <div class="p-2 rounded bg-<?= $leistung_ampel['fail_pr33'] === 0 ? 'success' : 'warning' ?>-lt">
                                                        <div class="fs-4 fw-bold text-<?= $leistung_ampel['fail_pr33'] === 0 ? 'success' : 'warning' ?>"><?= $leistung_ampel['fail_pr33'] ?></div>
                                                        <div class="small text-secondary">Tests &lt; PR 33</div>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <div class="mt-2 small text-<?= $leistung_ampel['level'] ?>">
                                                <?php if ($leistung_ampel['level'] === 'success'): ?>
                                                    <i class="ti ti-circle-check me-1" aria-hidden="true"></i>
                                                <?php elseif ($leistung_ampel['level'] === 'warning'): ?>
                                                    <i class="ti ti-alert-circle me-1" aria-hidden="true"></i>
                                                <?php elseif ($leistung_ampel['level'] === 'danger'): ?>
                                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                                                <?php else: ?>
                                                    <i class="ti ti-circle-dashed me-1" aria-hidden="true"></i>
                                                <?php endif; ?>
                                                <?= htmlspecialchars($leistung_ampel['warn'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div>
                        </div>

                        <!-- ══ TOXIKOLOGIE + PSYCHOLOGISCHE AUFARBEITUNG ══════════════════════ -->
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header border-0">
                                    <h3 class="card-title">Toxikologie & Medizin</h3>
                                </div>
                                <div class="card-body">
                                    <div class="datagrid">
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Blutalkoholkonzentration</div>
                                            <div class="datagrid-content"><?= $akte['bak_value'] ? $akte['bak_value'] . ' Promille' : 'Nicht erfasst' ?></div>
                                        </div>
                                        <div class="datagrid-item">
                                            <div class="datagrid-title">Körpergewicht</div>
                                            <div class="datagrid-content"><?= $akte['weight_kg'] ? $akte['weight_kg'] . ' Kilogramm' : 'Fehlt' ?></div>
                                        </div>
                                    </div>
                                    <hr>
                                    <h4>Trinkmengen-Berechnung nach Widmark</h4>
                                    <p class="text-secondary small">Abgleich der polizeilich gemessenen Werte mit der Behauptung des Klienten, um Verharmlosungen aufzudecken.</p>
                                    <button type="button" class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#modal-widmark">
                                        <i class="ti ti-calculator me-2" aria-hidden="true"></i> Widmark-Rechner starten
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header border-0">
                                    <h3 class="card-title">Psychologische Aufarbeitung</h3>
                                </div>
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item">
                                        <div class="row align-items-center g-2">
                                            <div class="col-auto"><i class="ti ti-writing icon text-secondary" aria-hidden="true"></i></div>
                                            <div class="col text-truncate">
                                                <div class="text-body d-block">Strukturanalyse nach Stimulus, Organismus, Reaktion, Kontingenz, Konsequenz</div>
                                                <small class="text-secondary">Dekonstruktion des Tathergangs</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="list-group-item">
                                        <div class="row align-items-center g-2">
                                            <div class="col-auto"><i class="ti ti-clipboard-list icon text-secondary" aria-hidden="true"></i></div>
                                            <div class="col text-truncate">
                                                <div class="text-body d-block">Trinkverhalten analysieren</div>
                                                <small class="text-secondary">Ermittlung von Suchtmerkmalen über Fragebogen</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ══ TABS ════════════════════════════════════════════════════════════ -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <ul class="nav nav-tabs card-header-tabs" id="akte-tabs" data-bs-toggle="tabs">
                                        <li class="nav-item">
                                            <a href="#todos" class="nav-link active" data-bs-toggle="tab">
                                                <i class="ti ti-checklist me-1" aria-hidden="true"></i>
                                                To-Dos & Dokumente
                                                <?php if ($tasks_offen > 0): ?>
                                                    <span class="badge bg-danger-lt ms-1"><?= $tasks_offen ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#abstinenz" class="nav-link" data-bs-toggle="tab">
                                                <i class="ti ti-test-pipe me-1" aria-hidden="true"></i>
                                                Abstinenz-Nachweise
                                                <?php if (count($probes) > 0): ?>
                                                    <span class="badge bg-secondary-lt ms-1" title="Erfasste Nachweise"><?= count($probes) ?></span>
                                                <?php endif; ?>
                                                <?php if ($abst_has_gap || $abst_has_pos): ?>
                                                    <span class="badge bg-danger-lt ms-1"><i class="ti ti-alert-triangle" aria-hidden="true"></i></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <?php if (($akte['bkf_status'] ?? 'nein') !== 'nein'): ?>
                                        <li class="nav-item">
                                            <a href="#leistungstests" class="nav-link" data-bs-toggle="tab">
                                                <i class="ti ti-activity me-1" aria-hidden="true"></i>
                                                Leistungstests
                                                <?php if (count($performance_tests) > 0): ?>
                                                    <span class="badge bg-secondary-lt ms-1" title="Erfasste Leistungstests"><?= count($performance_tests) ?></span>
                                                <?php endif; ?>
                                                <?php if ($leistung_ampel['level'] === 'danger'): ?>
                                                    <span class="badge bg-danger-lt ms-1"><i class="ti ti-alert-triangle" aria-hidden="true"></i></span>
                                                <?php elseif ($leistung_ampel['level'] === 'warning'): ?>
                                                    <span class="badge bg-warning-lt ms-1"><i class="ti ti-alert-circle" aria-hidden="true"></i></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <?php else: ?>
                                        <li class="nav-item" title="Leistungstests sind nur bei Berufskraftfahrer-Fällen (BKF) relevant.">
                                            <a class="nav-link disabled" aria-disabled="true" tabindex="-1">
                                                <i class="ti ti-activity me-1" aria-hidden="true"></i>
                                                Leistungstests
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <li class="nav-item">
                                            <a href="#sitzungen" class="nav-link" data-bs-toggle="tab">
                                                <i class="ti ti-users me-1" aria-hidden="true"></i>
                                                Sitzungen
                                                <?php if ($sessions_count > 0): ?>
                                                    <span class="badge bg-secondary-lt ms-1" title="Dokumentierte Sitzungen"><?= $sessions_count ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#notizen" class="nav-link" data-bs-toggle="tab">
                                                <i class="ti ti-notes me-1" aria-hidden="true"></i>
                                                Notizen
                                                <?php if (!empty($akte['revocation_confirmed'])): ?>
                                                    <span class="badge bg-danger-lt ms-1" title="Widerruf der Datenschutz-Einwilligung bestätigt"><i class="ti ti-shield-off" aria-hidden="true"></i></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#korrespondenz" class="nav-link" data-bs-toggle="tab">
                                                <i class="ti ti-mail me-1" aria-hidden="true"></i>
                                                Korrespondenz
                                                <?php if (!empty($case_events)): ?>
                                                    <span class="badge bg-blue-lt ms-1"><?= count($case_events) ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                                <div class="card-body">
                                    <div class="tab-content">

                                        <!-- ── TAB 1: TO-DOS & DOKUMENTE ────────────────────── -->
                                        <div class="tab-pane active show" id="todos" role="tabpanel">

                                            <div class="text-end mb-2">
                                                <a href="anleitung.php#checklisten" class="text-secondary small" title="Erläuterungen zu den Checklisten im Handbuch">
                                                    <i class="ti ti-book me-1" aria-hidden="true"></i>Handbuch: Checklisten
                                                </a>
                                            </div>

                                            <!-- Checkliste Fortschritt -->
                                            <div class="d-flex align-items-center mb-3 gap-3">
                                                <div class="flex-fill">
                                                    <div class="progress progress-sm" role="progressbar" aria-label="Checklisten-Fortschritt" aria-valuenow="<?= $tasks_prozent ?>" aria-valuemin="0" aria-valuemax="100">
                                                        <div class="progress-bar bg-<?= $tasks_prozent >= 100 ? 'success' : ($tasks_prozent >= 50 ? 'warning' : 'danger') ?>" style="width:<?= $tasks_prozent ?>%"></div>
                                                    </div>
                                                </div>
                                                <span class="text-secondary small text-nowrap"><?= $tasks_prozent ?> % erledigt</span>
                                                <!-- K-A: Bulk-Update-Buttons -->
                                                <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="Alle Aufgaben markieren">
                                                    <button type="button" class="btn btn-outline-warning bulk-task-btn"
                                                        data-bulk-status="angefordert"
                                                        data-client-id="<?= (int)$klienten_identifikation ?>"
                                                        data-case-id="<?= $akte_case_id ?>"
                                                        data-csrf="<?= htmlspecialchars($csrf_token) ?>"
                                                        title="Alle offenen Aufgaben auf ‚Angefordert' setzen">
                                                        <i class="ti ti-clock me-1" aria-hidden="true"></i>Alle anfordern
                                                    </button>
                                                    <button type="button" class="btn btn-outline-success bulk-task-btn"
                                                        data-bulk-status="liegt_vor"
                                                        data-client-id="<?= (int)$klienten_identifikation ?>"
                                                        data-case-id="<?= $akte_case_id ?>"
                                                        data-csrf="<?= htmlspecialchars($csrf_token) ?>"
                                                        title="Alle Aufgaben auf ‚Liegt vor' setzen">
                                                        <i class="ti ti-checks me-1" aria-hidden="true"></i>Alle erledigt
                                                    </button>
                                                </div>
                                            </div>

                                            <?php if (empty($client_tasks)): ?>
                                                <p class="text-secondary">Keine Aufgaben gefunden.</p>
                                            <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-vcenter card-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Aufgabe</th>
                                                            <th class="d-none d-md-table-cell">Hinweis</th>
                                                            <th>Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php
                                                    $aktuelle_gruppe  = null;
                                                    $ist_mettmann     = is_authority_mettmann($akte['authority_name'] ?? '', $akte['city'] ?? '');
                                                    $sperrfrist_ts    = !empty($akte['suspension_end_date']) ? strtotime($akte['suspension_end_date']) : null;
                                                    foreach ($client_tasks as $task):
                                                        $meta        = $task_meta[$task['task_key']] ?? ['label' => $task['task_key'], 'description' => '', 'group' => '', 'group_priority' => 9];
                                                        $gruppe      = $meta['group'] ?? '';
                                                        $zieldatum   = null;
                                                        if ($sperrfrist_ts !== null && array_key_exists('days_before_end', $meta)) {
                                                            $zieldatum = date('d.m.Y', $sperrfrist_ts - $meta['days_before_end'] * 86400);
                                                        }
                                                        $status_cfg  = [
                                                            'offen'       => ['btn-danger',  'ti-circle', 'Offen'],
                                                            'angefordert' => ['btn-warning',  'ti-clock',  'Angefordert'],
                                                            'liegt_vor'   => ['btn-success',  'ti-check',  'Liegt vor'],
                                                        ];
                                                        [$btn_cls, $icon_cls, $status_label] = $status_cfg[$task['status']] ?? ['btn-secondary', 'ti-question-mark', $task['status']];
                                                        $next_status = ['offen' => 'angefordert', 'angefordert' => 'liegt_vor', 'liegt_vor' => 'offen'][$task['status']] ?? 'offen';
                                                        $zeige_mettmann_hint = $ist_mettmann && $task['task_key'] === 'antrag_stellen';
                                                    ?>
                                                    <?php if ($gruppe !== '' && $gruppe !== $aktuelle_gruppe): $aktuelle_gruppe = $gruppe; ?>
                                                    <tr class="table-group-divider">
                                                        <td colspan="3" class="fw-bold text-secondary small py-1">
                                                            <i class="ti ti-tag me-1" aria-hidden="true"></i><?= htmlspecialchars($gruppe) ?>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <tr>
                                                        <td class="fw-medium">
                                                            <?= htmlspecialchars($meta['label']) ?>
                                                            <?php
                                                            $info_modal_body = $meta['info'] ?? $meta['description'] ?? '';
                                                            if ($zeige_mettmann_hint) {
                                                                // R3-4: Vorlauf aus config/behoerden_config.php statt inline –
                                                                // sonst nennt der Hinweis eine andere Zahl als die Ampel daneben.
                                                                $mm_tage   = behoerden_config()['vorlauf_tage']['mettmann'];
                                                                $mm_wochen = (int)round($mm_tage / 7);
                                                                $info_modal_body .= "\n\nHinweis Kreis Mettmann: Nur persönliche Vorsprache möglich – Kreisverwaltung Mettmann, Düsseldorfer Str. 26. Online-Dienste bis auf Weiteres eingeschränkt (Systemumstellung 2026). Bitte physische Passfotos (35 × 45 mm, biometrisch) mitbringen – digitale Fotos per QR-Code werden nicht akzeptiert. Empfehlung: mind. {$mm_wochen} Wochen ({$mm_tage} Tage) vor Sperrfristende vorstellig werden.";
                                                            }
                                                            if (!empty($info_modal_body)):
                                                            ?>
                                                            <button type="button"
                                                                    class="btn btn-sm p-0 ms-1 align-text-bottom task-info-btn"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#taskInfoModal"
                                                                    data-info-title="<?= htmlspecialchars($meta['label']) ?>"
                                                                    data-info-body="<?= htmlspecialchars($info_modal_body) ?>"
                                                                    aria-label="Informationen zu <?= htmlspecialchars($meta['label']) ?>">
                                                                <i class="ti ti-info-circle text-azure" aria-hidden="true"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            <?php if ($zeige_mettmann_hint): ?>
                                                                <span class="badge bg-warning-lt ms-1"><i class="ti ti-map-pin me-1" aria-hidden="true"></i>Mettmann</span>
                                                            <?php endif; ?>
                                                            <?php if ($zieldatum !== null): ?>
                                                                <div class="text-secondary small mt-1">
                                                                    <i class="ti ti-calendar me-1" aria-hidden="true"></i>bis <?= $zieldatum ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-secondary small d-none d-md-table-cell">
                                                            <?= htmlspecialchars($meta['description']) ?>
                                                            <?php if ($zeige_mettmann_hint): ?>
                                                                <div class="text-warning mt-1">
                                                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                                                                    <strong>Kreis Mettmann:</strong> Nur persönliche Vorsprache – Kreisverwaltung Mettmann, Düsseldorfer&nbsp;Str.&nbsp;26. Online-Dienste bis auf Weiteres eingeschränkt (Systemumstellung 2026). <strong>Bitte physische Passfotos mitbringen</strong> – digitale Fotos (QR-Code) werden nicht akzeptiert.
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <button type="button"
                                                                    class="btn btn-sm <?= $btn_cls ?> task-toggle"
                                                                    data-task-key="<?= htmlspecialchars($task['task_key']) ?>"
                                                                    data-current-status="<?= htmlspecialchars($task['status']) ?>"
                                                                    data-client-id="<?= (int)$klienten_identifikation ?>"
                                                                    data-csrf="<?= htmlspecialchars($csrf_token) ?>">
                                                                <i class="ti <?= $icon_cls ?> me-1" aria-hidden="true"></i><?= $status_label ?>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>

                                            <hr>
                                            <h4 class="mb-3">Dokumente drucken</h4>
                                            <div class="row g-2">
                                                <div class="col-auto"><a href="vertrag_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="ti ti-printer me-1" aria-hidden="true"></i>Beratungsvertrag</a></div>
                                                <div class="col-auto"><a href="datenschutz_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="ti ti-printer me-1" aria-hidden="true"></i>Datenschutzerklärung</a></div>
                                                <div class="col-auto"><a href="widerruf_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="ti ti-printer me-1" aria-hidden="true"></i>Widerrufsbelehrung</a></div>
                                                <div class="col-auto"><a href="strategie_druck.php?id=<?= (int)$akte['client_id'] ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="ti ti-printer me-1" aria-hidden="true"></i>Strategie-Vereinbarung</a></div>
                                            </div>
                                        </div>

                                        <!-- ── TAB 2: ABSTINENZ-NACHWEISE ───────────────────── -->
                                        <div class="tab-pane" id="abstinenz" role="tabpanel">

                                            <div class="text-end mb-2">
                                                <a href="anleitung.php#abstinenz" class="text-secondary small" title="Erläuterungen zur Abstinenzdokumentation im Handbuch">
                                                    <i class="ti ti-book me-1" aria-hidden="true"></i>Handbuch: Abstinenz-Nachweise
                                                </a>
                                            </div>

                                            <?php if (!empty($_GET['error']) && $_GET['error'] === 'probe'): ?>
                                            <div class="alert alert-danger mb-3" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                <div>Ungültige Eingabe – bitte alle Pflichtfelder korrekt ausfüllen.</div>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($abstinenz_info['hat_positiv']): ?>
                                            <div class="alert alert-important alert-danger mb-3" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                <div><strong>Positiver Befund dokumentiert!</strong> Die Abstinenzkette ist unterbrochen. Sofortmaßnahme erforderlich.</div>
                                            </div>
                                            <?php endif; ?>

                                            <?php foreach ($abstinenz_info['gaps'] as $gap): ?>
                                            <div class="alert alert-warning mb-2" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-circle icon alert-icon" aria-hidden="true"></i></div>
                                                <div><strong>Lücke (<?= htmlspecialchars($gap['method']) ?>):</strong> <?= date('d.m.Y', strtotime($gap['von'])) ?> → <?= date('d.m.Y', strtotime($gap['bis'])) ?> – <strong><?= $gap['tage'] ?> Tage</strong> Abstand (Grenzwert: <?= $gap['method'] === 'urin' ? '14' : '95' ?> Tage)</div>
                                            </div>
                                            <?php endforeach; ?>

                                            <!-- CTU-Analytik-Hinweise (BK-5 Kap. C / CTU) -->
                                            <div class="alert alert-info mb-3" role="note">
                                                <div class="alert-icon"><i class="ti ti-flask icon alert-icon" aria-hidden="true"></i></div>
                                                <div>
                                                        <h4 class="alert-heading mb-1">
                                                            <a class="text-reset text-decoration-none" data-bs-toggle="collapse" href="#ctu-hinweise" role="button" aria-expanded="false" aria-controls="ctu-hinweise">
                                                                Gerichtsfeste Analytik – Prüfhinweise <i class="ti ti-chevron-down small" aria-hidden="true"></i>
                                                            </a>
                                                        </h4>
                                                        <div class="collapse" id="ctu-hinweise">
                                                            <ul class="mb-0 mt-2 ps-3">
                                                                <li><strong>Haaranalyse:</strong> Nur mit <strong>THC-COOH-Nachweis</strong> gerichtsfest – der bloße THC-Nachweis im Haar reicht nicht (mögliche externe Kontamination). Für Drogenabstinenz gelten 6 cm = 6 Monate Nachweiszeitraum.</li>
                                                                <li><strong>Urin – Kreatinin:</strong> &lt; <?= $kreat_verwertbar_txt ?> mg/dl = Qualitätsmangel (verdünnte Probe), &le; <?= $kreat_kein_neg_txt ?> mg/dl = kein Negativbefund mehr, die Wertung trifft der Gutachter. Bei zu niedrigem Kreatinin ist eine <strong>chromatographische Bestätigung (GC/LC-MS)</strong> zwingend.</li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                            </div>

                                            <!-- Nachweis hinzufügen -->
                                            <div class="mb-4">
                                                <h4 class="mb-3">Nachweis erfassen</h4>
                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                                    <input type="hidden" name="action" value="add_probe">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <div class="row g-2">
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="probe_date">Datum</label>
                                                            <input type="date" class="form-control" id="probe_date" name="probe_date" required max="<?= date('Y-m-d') ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="probe_method">Methode</label>
                                                            <select class="form-select" id="probe_method" name="method" required>
                                                                <option value="urin">Urin</option>
                                                                <option value="haar">Haar</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="probe_result">Ergebnis</label>
                                                            <select class="form-select" id="probe_result" name="result" required>
                                                                <option value="negativ">Negativ</option>
                                                                <option value="positiv">Positiv</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="probe_lab">Labor</label>
                                                            <input type="text" class="form-control" id="probe_lab" name="lab_name" placeholder="z.B. PIMA MPU Düsseldorf" maxlength="150" list="labor-vorschlaege" autocomplete="off">
                                                            <datalist id="labor-vorschlaege">
                                                                <!-- Düsseldorf -->
                                                                <option value="PIMA MPU GmbH – Düsseldorf">
                                                                <option value="AVUS GmbH – Düsseldorf">
                                                                <option value="MPU Point Düsseldorf">
                                                                <option value="ABV GmbH – Düsseldorf">
                                                                <option value="MVZ Labor Krone – Düsseldorf">
                                                                <option value="Institut für Rechtsmedizin / UKD – Düsseldorf">
                                                                <!-- Kreis Mettmann -->
                                                                <option value="InVitaLab – Neuss">
                                                            </datalist>
                                                        </div>
                                                        <div class="col-md-1">
                                                            <label class="form-label" for="peth_value" title="PEth (ng/ml) – auffällig ab <?= $peth_grenze_txt ?> ng/ml">PEth <span class="text-secondary small">ng/ml</span></label>
                                                            <input type="number" class="form-control" id="peth_value" name="peth_value"
                                                                min="0" max="9999" step="0.1" placeholder="—"
                                                                aria-label="PEth-Wert in ng/ml (auffällig ab <?= $peth_grenze_txt ?>)">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="kreatinin_mg_dl" title="Kreatinin in mg/dl – Laborbefunde kommen meist in g/l: Wert × 100 (0,2 g/l = <?= $kreat_verwertbar_txt ?> mg/dl). Unter <?= $kreat_verwertbar_txt ?> mg/dl gilt die Probe als nicht verwertbar (verdünnt, wiederholen); bei ≤ <?= $kreat_kein_neg_txt ?> mg/dl wird kein Negativbefund mehr ausgewiesen (CTU-Kriterien der BK-5).">Kreatinin <span class="text-secondary small">mg/dl</span></label>
                                                            <input type="number" class="form-control" id="kreatinin_mg_dl" name="kreatinin_mg_dl"
                                                                min="0" max="500" step="0.1" placeholder="—"
                                                                aria-label="Kreatinin-Wert der Urinprobe in mg/dl">
                                                        </div>
                                                        <div class="col-md-1">
                                                            <label class="form-label" for="probe_notes">Notiz</label>
                                                            <input type="text" class="form-control" id="probe_notes" name="probe_notes" placeholder="Optional" maxlength="255">
                                                        </div>
                                                        <div class="col-md-1 d-flex align-items-end">
                                                            <button type="submit" class="btn btn-icon btn-primary w-100" aria-label="Abstinenz-Nachweis hinzufügen"><i class="ti ti-plus" aria-hidden="true"></i></button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Probe-Tabelle -->
                                            <?php if (empty($probes)): ?>
                                                <p class="text-secondary">Noch keine Nachweise erfasst.</p>
                                            <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-vcenter card-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Datum</th>
                                                            <th>Methode</th>
                                                            <th>Ergebnis</th>
                                                            <th>Labor</th>
                                                            <th class="d-none d-md-table-cell">PEth / Kreatinin</th>
                                                            <th class="d-none d-lg-table-cell">Notiz</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($probes as $probe):
                                                        $peth   = isset($probe['peth_value'])      ? (float)$probe['peth_value']      : null;
                                                        $kreat  = isset($probe['kreatinin_mg_dl'])  ? (float)$probe['kreatinin_mg_dl']  : null;
                                                    ?>
                                                    <tr>
                                                        <td><?= date('d.m.Y', strtotime($probe['probe_date'])) ?></td>
                                                        <td><?= htmlspecialchars(ucfirst($probe['method'])) ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= $probe['result'] === 'negativ' ? 'success' : 'danger' ?>-lt text-<?= $probe['result'] === 'negativ' ? 'success' : 'danger' ?>">
                                                                <?= $probe['result'] === 'negativ' ? 'Negativ' : 'Positiv' ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-secondary"><?= htmlspecialchars($probe['lab_name'] ?? '–') ?></td>
                                                        <td class="text-secondary small d-none d-md-table-cell">
                                                            <?php if ($peth !== null): ?>
                                                                <?php if (peth_auffaellig($peth)): ?>
                                                                    <span class="text-danger fw-bold" title="PEth ab <?= $peth_grenze_txt ?> ng/ml: Hinweis auf Alkoholkonsum, die Wertung trifft der Gutachter">
                                                                        PEth: <?= number_format($peth, 1) ?> <i class="ti ti-alert-triangle ms-1" aria-hidden="true"></i>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-success" title="PEth unter <?= $peth_grenze_txt ?> ng/ml">PEth: <?= number_format($peth, 1) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <?php if ($kreat !== null): ?>
                                                                <?php if ($kreat <= (float)$_kreat_cfg['kreatinin']['kein_negativbefund_bis_mg_dl']): ?>
                                                                    <span class="text-danger fw-bold d-block" title="Kreatinin &le; <?= $kreat_kein_neg_txt ?> mg/dl: kein Negativbefund mehr, die Wertung trifft der Gutachter">
                                                                        Krea: <?= number_format($kreat, 1) ?> <i class="ti ti-circle-x ms-1" aria-hidden="true"></i>
                                                                    </span>
                                                                <?php elseif ($kreat < (float)$_kreat_cfg['kreatinin']['verwertbar_ab_mg_dl']): ?>
                                                                    <span class="text-warning d-block" title="Kreatinin &lt; <?= $kreat_verwertbar_txt ?> mg/dl: Probenqualität fraglich">
                                                                        Krea: <?= number_format($kreat, 1) ?> <i class="ti ti-alert-triangle ms-1" aria-hidden="true"></i>
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span class="text-secondary d-block">Krea: <?= number_format($kreat, 1) ?></span>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            <?php if ($peth === null && $kreat === null): ?>–<?php endif; ?>
                                                        </td>
                                                        <td class="text-secondary small d-none d-lg-table-cell"><?= htmlspecialchars($probe['notes'] ?? '–') ?></td>
                                                        <td>
                                                            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline" data-confirm="Nachweis vom <?= date('d.m.Y', strtotime($probe['probe_date'])) ?> löschen?">
                                                                <input type="hidden" name="action" value="delete_probe">
                                                                <input type="hidden" name="probe_id" value="<?= (int)$probe['id'] ?>">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <button type="submit" class="btn btn-sm btn-ghost-danger" title="Löschen" aria-label="Nachweis löschen"><i class="ti ti-trash" aria-hidden="true"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (($akte['bkf_status'] ?? 'nein') !== 'nein'): ?>
                                        <!-- ── TAB: LEISTUNGSTESTS (nur BKF) ────────────────── -->
                                        <div class="tab-pane" id="leistungstests" role="tabpanel">

                                            <?php if (!empty($_GET['error']) && $_GET['error'] === 'leistung'): ?>
                                            <div class="alert alert-danger mb-3" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                <div>Ungültige Eingabe – bitte Pflichtfelder korrekt ausfüllen (Datum ≤ heute, Testname, PR 1–99).</div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Leistungsdiagnostik-Info (BK-5 Kap. C 4) -->
                                            <div class="alert alert-info mb-3" role="note">
                                                <div class="alert-icon"><i class="ti ti-brain icon alert-icon" aria-hidden="true"></i></div>
                                                <div>
                                                        <h4 class="alert-heading mb-1">
                                                            <a class="text-reset text-decoration-none" data-bs-toggle="collapse" href="#leistung-hinweise" role="button" aria-expanded="false" aria-controls="leistung-hinweise">
                                                                Psychometrische Leistungsdiagnostik – Anforderungen <i class="ti ti-chevron-down small" aria-hidden="true"></i>
                                                            </a>
                                                        </h4>
                                                        <div class="collapse" id="leistung-hinweise">
                                                            <p class="mb-1 mt-2">Leistungstests (z. B. Wiener Testsystem / TAP-M) prüfen Reaktion, Konzentration und Aufmerksamkeit. Das Ergebnis wird als <strong>Prozentrang (PR)</strong> gegen die Normstichprobe angegeben (PR 16 = besser als 16 % der Vergleichsgruppe).</p>
                                                            <ul class="mb-0 ps-3">
                                                                <li><strong>Gruppe 1 (PKW):</strong> PR ≥ 16 in allen relevanten Tests.</li>
                                                                <li><strong>Gruppe 2 (BKF, LKW/Bus):</strong> PR ≥ 33 in der Mehrzahl der Tests.</li>
                                                                <li><strong>Kompensationshürde (neu BK-5):</strong> Liegt ein Test unter PR 16, müssen die übrigen Tests zur Kompensation ohne Fahrverhaltensbeobachtung (FVB) einen <strong>PR ≥ 50</strong> erreichen.</li>
                                                            </ul>
                                                            <p class="mb-0 mt-2 text-secondary small">Reine Aufklärung – kein triage-relevanter Rechenwert. Quelle: BK-5 Kap. C 4.</p>
                                                        </div>
                                                    </div>
                                            </div>

                                            <!-- Test erfassen -->
                                            <div class="mb-4">
                                                <h4 class="mb-3">Leistungstest erfassen</h4>
                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                                    <input type="hidden" name="action" value="add_performance_test">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <div class="row g-2">
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="lt_test_date">Datum</label>
                                                            <input type="date" class="form-control" id="lt_test_date" name="test_date" required max="<?= date('Y-m-d') ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="lt_test_name">Testname</label>
                                                            <input type="text" class="form-control" id="lt_test_name" name="test_name" required maxlength="100" placeholder="z.B. Reaktionstest, Konzentrationstest">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="lt_percentile" title="Prozentrang 1–99 (BK-5: ≥ 16 Pflicht, ≥ 33 BKF-Mehrheit)">Prozentrang (PR)</label>
                                                            <input type="number" class="form-control" id="lt_percentile" name="percentile" required min="1" max="99" placeholder="1–99">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="lt_notes">Notiz</label>
                                                            <input type="text" class="form-control" id="lt_notes" name="perf_notes" maxlength="255" placeholder="Optional">
                                                        </div>
                                                        <div class="col-md-1 d-flex align-items-end">
                                                            <button type="submit" class="btn btn-icon btn-primary w-100" aria-label="Leistungstest hinzufügen"><i class="ti ti-plus" aria-hidden="true"></i></button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Test-Tabelle -->
                                            <?php if (empty($performance_tests)): ?>
                                                <p class="text-secondary">Noch keine Leistungstests erfasst.</p>
                                            <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-vcenter card-table">
                                                    <thead>
                                                        <tr>
                                                            <th>Datum</th>
                                                            <th>Testname</th>
                                                            <th>Prozentrang</th>
                                                            <th class="d-none d-lg-table-cell">Notiz</th>
                                                            <th></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($performance_tests as $lt):
                                                        $lt_pr = (int)$lt['percentile'];
                                                        $lt_color = $lt_pr >= 33 ? 'success' : ($lt_pr >= 16 ? 'warning' : 'danger');
                                                    ?>
                                                    <tr>
                                                        <td><?= date('d.m.Y', strtotime($lt['test_date'])) ?></td>
                                                        <td><?= htmlspecialchars($lt['test_name'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= $lt_color ?>-lt text-<?= $lt_color ?> fw-bold" title="<?= $lt_pr >= 33 ? '≥ PR 33 (BKF)' : ($lt_pr >= 16 ? 'PR 16–32 (Mindest ok)' : '< PR 16 (Nicht bestanden)') ?>">
                                                                PR <?= $lt_pr ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-secondary small d-none d-lg-table-cell"><?= htmlspecialchars($lt['notes'] ?? '–', ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                        <td>
                                                            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline" data-confirm="Leistungstest vom <?= date('d.m.Y', strtotime($lt['test_date'])) ?> löschen?">
                                                                <input type="hidden" name="action" value="delete_performance_test">
                                                                <input type="hidden" name="perf_id" value="<?= (int)$lt['id'] ?>">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <button type="submit" class="btn btn-sm btn-ghost-danger" title="Löschen" aria-label="Leistungstest löschen"><i class="ti ti-trash" aria-hidden="true"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- ── TAB 3: SITZUNGEN ─────────────────────────────── -->
                                        <div class="tab-pane" id="sitzungen" role="tabpanel">

                                            <div class="d-flex justify-content-end mb-3">
                                                <a href="sitzungen.php?client_id=<?= (int)$klienten_identifikation ?>" class="btn btn-outline-secondary btn-sm">
                                                    <i class="ti ti-list-details me-1" aria-hidden="true"></i>Alle Sitzungen &rarr;
                                                </a>
                                            </div>

                                            <?php if (!empty($_GET['error']) && $_GET['error'] === 'session'): ?>
                                            <div class="alert alert-danger mb-3" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                <div>Ungültige Eingabe – bitte alle Pflichtfelder korrekt ausfüllen.</div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Fortschrittsbalken -->
                                            <div class="mb-4">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="fw-medium">Sitzung <?= $sessions_count ?> von <?= $sessions_max ?></span>
                                                    <span class="text-secondary small">Phase: <?= htmlspecialchars($phase_labels[$current_phase_key]) ?></span>
                                                </div>
                                                <div class="progress progress-sm mb-2" role="progressbar" aria-label="Sitzungs-Fortschritt" aria-valuenow="<?= $sessions_prozent ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <div class="progress-bar bg-purple" style="width:<?= $sessions_prozent ?>%"></div>
                                                </div>
                                                <div class="row text-center small text-secondary">
                                                    <div class="col-4">1–3: Anamnese</div>
                                                    <div class="col-4">4–7: Analyse</div>
                                                    <div class="col-4">8–10: Check</div>
                                                </div>
                                            </div>

                                            <!-- Sitzung hinzufügen -->
                                            <div class="mb-4">
                                                <h4 class="mb-3">Sitzung erfassen</h4>
                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                                    <input type="hidden" name="action" value="add_session">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <div class="row g-2">
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="session_date">Datum</label>
                                                            <input type="date" class="form-control" id="session_date" name="session_date" required max="<?= date('Y-m-d') ?>">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="session_duration">Dauer (min)</label>
                                                            <input type="number" class="form-control" id="session_duration" name="duration_min" value="60" min="15" max="480">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="session_phase">Phase</label>
                                                            <select class="form-select" id="session_phase" name="phase" required>
                                                                <option value="anamnese">Anamnese</option>
                                                                <option value="analyse">Analyse</option>
                                                                <option value="check">Check</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="session_notes">Sitzungsnotiz</label>
                                                            <textarea class="form-control" id="session_notes" name="session_notes" rows="4" placeholder="Themen, Beobachtungen, Vereinbarungen ..."></textarea>
                                                        </div>
                                                    </div>
                                                    <!-- SORKC-Strukturanalyse (optional) -->
                                                    <div class="mt-2">
                                                        <a class="text-secondary small" data-bs-toggle="collapse" href="#sorkc-form" role="button" aria-expanded="false" aria-controls="sorkc-form">
                                                            <i class="ti ti-chevron-down me-1" aria-hidden="true"></i>SORKC-Strukturanalyse (optional)
                                                        </a>
                                                        <div class="collapse mt-2" id="sorkc-form">
                                                            <div class="card card-body border">
                                                                <div class="row g-2">
                                                                    <div class="col-12">
                                                                        <label class="form-label fw-medium mb-1" for="sorkc_s">S — Stimulus <span class="text-secondary fw-normal">(auslösende Situation)</span></label>
                                                                        <textarea class="form-control form-control-sm" name="sorkc_s" id="sorkc_s" rows="2" placeholder="z. B. Feierabend, Stress, Streit ..."></textarea>
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <label class="form-label fw-medium mb-1" for="sorkc_o">O — Organismus <span class="text-secondary fw-normal">(biologische / psychische Disposition)</span></label>
                                                                        <textarea class="form-control form-control-sm" name="sorkc_o" id="sorkc_o" rows="2" placeholder="z. B. Grundangst, Schlafmangel, Craving ..."></textarea>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-medium mb-1" for="sorkc_r">R — Reaktion <span class="text-secondary fw-normal">(Verhalten)</span></label>
                                                                        <textarea class="form-control form-control-sm" name="sorkc_r" id="sorkc_r" rows="2" placeholder="z. B. Konsum, Vermeidung, Aggression ..."></textarea>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label fw-medium mb-1" for="sorkc_k">K — Kontingenz <span class="text-secondary fw-normal">(Häufigkeit / Muster)</span></label>
                                                                        <textarea class="form-control form-control-sm" name="sorkc_k" id="sorkc_k" rows="2" placeholder="z. B. täglich abends, jedes Wochenende ..."></textarea>
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <label class="form-label fw-medium mb-1" for="sorkc_c">C — Konsequenz <span class="text-secondary fw-normal">(kurz- und langfristig)</span></label>
                                                                        <textarea class="form-control form-control-sm" name="sorkc_c" id="sorkc_c" rows="2" placeholder="z. B. kurzfristig: Entspannung; langfristig: Führerscheinverlust ..."></textarea>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex justify-content-end mt-3">
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Sitzung speichern
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Sitzungsliste -->
                                            <?php if (empty($sessions)): ?>
                                                <p class="text-secondary">Noch keine Sitzungen erfasst.</p>
                                            <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-vcenter card-table">
                                                    <thead>
                                                        <tr>
                                                            <th style="width: 2rem;"></th>
                                                            <th>#</th>
                                                            <th>Datum</th>
                                                            <th>Dauer</th>
                                                            <th>Phase</th>
                                                            <th class="d-none d-md-table-cell">Notiz</th>
                                                            <th style="width: 6rem;"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($sessions as $i => $session):
                                                        $p_colors   = ['anamnese' => 'bg-blue-lt', 'analyse' => 'bg-orange-lt', 'check' => 'bg-green-lt'];
                                                        $p_color    = $p_colors[$session['phase']] ?? 'bg-secondary-lt';
                                                        $has_sorkc  = !empty($session['sorkc_s']) || !empty($session['sorkc_o']) || !empty($session['sorkc_r']) || !empty($session['sorkc_k']) || !empty($session['sorkc_c']);
                                                        $notiz_voll = trim((string)($session['notes'] ?? ''));
                                                        $hat_detail = $has_sorkc || $notiz_voll !== '';
                                                        $detail_id  = 'session-detail-' . (int)$session['id'];
                                                        // Vorschau: erste Zeile, hart gekürzt – die Tabellenzeile darf nicht auseinanderlaufen.
                                                        $notiz_kurz = $notiz_voll === '' ? '' : (string)preg_split('/\R/', $notiz_voll)[0];
                                                        if (mb_strlen($notiz_kurz) > 60) {
                                                            $notiz_kurz = mb_substr($notiz_kurz, 0, 60) . '…';
                                                        } elseif (str_contains($notiz_voll, "\n")) {
                                                            $notiz_kurz .= ' …';
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <?php if ($hat_detail): ?>
                                                                <a href="#<?= $detail_id ?>" data-bs-toggle="collapse" class="text-secondary" title="Notiz und SORKC anzeigen" aria-expanded="false" aria-controls="<?= $detail_id ?>">
                                                                    <i class="ti ti-chevron-right" aria-hidden="true"></i>
                                                                </a>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-secondary"><?= $i + 1 ?></td>
                                                        <td><?= date('d.m.Y', strtotime($session['session_date'])) ?></td>
                                                        <td class="text-secondary"><?= (int)$session['duration_min'] ?> min</td>
                                                        <td>
                                                            <span class="badge <?= $p_color ?>"><?= htmlspecialchars(ucfirst($session['phase'])) ?></span>
                                                            <?php if ($has_sorkc): ?>
                                                                <span class="badge bg-secondary-lt ms-1" title="SORKC-Strukturanalyse erfasst">SORKC</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-secondary small d-none d-md-table-cell">
                                                            <?= $notiz_kurz !== '' ? htmlspecialchars($notiz_kurz, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') : '–' ?>
                                                        </td>
                                                        <td class="text-nowrap">
                                                            <button type="button"
                                                                    class="btn btn-sm btn-ghost-secondary js-session-edit"
                                                                    title="Sitzung bearbeiten" aria-label="Sitzung bearbeiten"
                                                                    data-bs-toggle="modal" data-bs-target="#modal-sitzung"
                                                                    data-id="<?= (int)$session['id'] ?>"
                                                                    data-date="<?= htmlspecialchars($session['session_date'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-duration="<?= (int)$session['duration_min'] ?>"
                                                                    data-phase="<?= htmlspecialchars($session['phase'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-notes="<?= htmlspecialchars($notiz_voll, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-sorkc-s="<?= htmlspecialchars((string)($session['sorkc_s'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-sorkc-o="<?= htmlspecialchars((string)($session['sorkc_o'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-sorkc-r="<?= htmlspecialchars((string)($session['sorkc_r'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-sorkc-k="<?= htmlspecialchars((string)($session['sorkc_k'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-sorkc-c="<?= htmlspecialchars((string)($session['sorkc_c'] ?? ''), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                                            </button>
                                                            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline" data-confirm="Sitzung vom <?= date('d.m.Y', strtotime($session['session_date'])) ?> löschen?">
                                                                <input type="hidden" name="action" value="delete_session">
                                                                <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <button type="submit" class="btn btn-sm btn-ghost-danger" title="Löschen" aria-label="Sitzung löschen"><i class="ti ti-trash" aria-hidden="true"></i></button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                    <?php if ($hat_detail): ?>
                                                    <tr class="collapse" id="<?= $detail_id ?>">
                                                        <td colspan="7" class="border-start border-3 ps-4 pe-3 py-2 small">
                                                            <?php if ($notiz_voll !== ''): ?>
                                                            <div class="mb-2">
                                                                <div class="fw-medium mb-1">Sitzungsnotiz</div>
                                                                <div class="text-secondary ws-pre-wrap"><?= htmlspecialchars($notiz_voll, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                            </div>
                                                            <?php endif; ?>
                                                            <?php if ($has_sorkc): ?>
                                                            <div class="row g-2 text-secondary">
                                                                <?php foreach ([
                                                                    'S' => ['Stimulus',   $session['sorkc_s']],
                                                                    'O' => ['Organismus', $session['sorkc_o']],
                                                                    'R' => ['Reaktion',   $session['sorkc_r']],
                                                                    'K' => ['Kontingenz', $session['sorkc_k']],
                                                                    'C' => ['Konsequenz', $session['sorkc_c']],
                                                                ] as $key => [$label, $val]):
                                                                    if (empty($val)) continue; ?>
                                                                <div class="col-12 col-md-6">
                                                                    <span class="badge bg-secondary-lt me-1"><?= $key ?></span>
                                                                    <strong><?= $label ?>:</strong> <span class="ws-pre-wrap"><?= htmlspecialchars($val, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                                </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ── TAB 4: NOTIZEN ───────────────────────────────── -->
                                        <div class="tab-pane" id="notizen" role="tabpanel">

                                            <!-- Notiz erfassen -->
                                            <div class="mb-4">
                                                <h4 class="mb-3">Notiz erfassen</h4>
                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                                    <input type="hidden" name="action" value="add_note">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <div class="row g-2">
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="note_date">Datum</label>
                                                            <input type="date" class="form-control" id="note_date" name="note_date" required value="<?= date('Y-m-d') ?>">
                                                        </div>
                                                        <div class="col-md-9">
                                                            <label class="form-label" for="note_title">Überschrift</label>
                                                            <input type="text" class="form-control" id="note_title" name="note_title" list="notiz-titel-vorschlaege"
                                                                   maxlength="120" required placeholder="Auswählen oder frei eingeben ...">
                                                            <datalist id="notiz-titel-vorschlaege">
                                                                <?php foreach (NOTIZ_TITEL_VORSCHLAEGE as $vorschlag): ?>
                                                                    <option value="<?= htmlspecialchars($vorschlag, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
                                                                <?php endforeach; ?>
                                                            </datalist>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label" for="note_body">Notiz</label>
                                                            <textarea class="form-control" id="note_body" name="note_body" rows="5" required placeholder="Freitext – Verlauf, Telefonate, Vereinbarungen ..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex justify-content-end mt-3">
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="ti ti-plus me-1" aria-hidden="true"></i>Notiz speichern
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Journal -->
                                            <?php if (empty($case_notes)): ?>
                                                <p class="text-secondary">Noch keine Notizen zu dieser Fallakte erfasst.</p>
                                            <?php else: ?>
                                            <div class="list-group list-group-flush mb-3">
                                                <?php foreach ($case_notes as $notiz): ?>
                                                <div class="list-group-item px-0">
                                                    <div class="d-flex align-items-start">
                                                        <div class="flex-fill">
                                                            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                                                <span class="badge bg-blue-lt"><?= date('d.m.Y', strtotime($notiz['note_date'])) ?></span>
                                                                <span class="fw-medium"><?= htmlspecialchars($notiz['title'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                                                            </div>
                                                            <div class="text-secondary ws-pre-wrap"><?= htmlspecialchars($notiz['body'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="text-nowrap ms-3">
                                                            <button type="button"
                                                                    class="btn btn-sm btn-ghost-secondary js-note-edit"
                                                                    title="Notiz bearbeiten" aria-label="Notiz bearbeiten"
                                                                    data-bs-toggle="modal" data-bs-target="#modal-notiz"
                                                                    data-id="<?= (int)$notiz['id'] ?>"
                                                                    data-date="<?= htmlspecialchars($notiz['note_date'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-title="<?= htmlspecialchars($notiz['title'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                                                    data-body="<?= htmlspecialchars($notiz['body'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                                            </button>
                                                            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline"
                                                                  data-confirm="Notiz „<?= htmlspecialchars($notiz['title'], ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>“ vom <?= date('d.m.Y', strtotime($notiz['note_date'])) ?> löschen?">
                                                                <input type="hidden" name="action" value="delete_note">
                                                                <input type="hidden" name="note_id" value="<?= (int)$notiz['id'] ?>">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                <button type="submit" class="btn btn-sm btn-ghost-danger" title="Löschen" aria-label="Notiz löschen"><i class="ti ti-trash" aria-hidden="true"></i></button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>

                                            <hr class="my-3">
                                            <h4 class="mb-2">DSGVO-Status</h4>
                                            <div class="datagrid mb-3">
                                                <div class="datagrid-item">
                                                    <div class="datagrid-title">Einwilligung erteilt</div>
                                                    <div class="datagrid-content"><?php if ($akte['dsgvo_consent']): ?><span class="text-success">Ja<?= !empty($akte['dsgvo_consent_date']) ? ', am ' . date('d.m.Y', strtotime((string)$akte['dsgvo_consent_date'])) : '' ?></span><?php else: ?><span class="text-secondary">Nein</span><?php endif; ?></div>
                                                </div>
                                                <div class="datagrid-item">
                                                    <div class="datagrid-title">Widerrufsbelehrung ausgehändigt</div>
                                                    <div class="datagrid-content"><?= !empty($akte['revocation_notice_confirmed']) ? '<span class="text-success">Ja</span>' : '<span class="text-secondary">Nein</span>' ?></div>
                                                </div>
                                                <div class="datagrid-item">
                                                    <div class="datagrid-title">Einwilligung widerrufen</div>
                                                    <div class="datagrid-content"><?= $akte['revocation_confirmed'] ? '<span class="text-danger fw-bold">Ja – Löschung ausstehend</span>' : '<span class="text-secondary">Nein</span>' ?></div>
                                                </div>
                                            </div>
                                            <?php if (!$akte['revocation_confirmed']): ?>
                                            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" data-confirm="Widerruf der DSGVO-Einwilligung bestätigen? Nach der Bestätigung erscheint eine Aufforderung zur Datenlöschung.">
                                                <input type="hidden" name="action" value="revocation_confirm">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <button type="submit" class="btn btn-outline-warning btn-sm">
                                                    <i class="ti ti-shield-off me-1" aria-hidden="true"></i> Widerruf der Einwilligung bestätigen
                                                </button>
                                            </form>
                                            <?php else: ?>
                                            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modal-dsgvo-delete">
                                                <i class="ti ti-trash me-1" aria-hidden="true"></i> Klient nach Art. 17 DSGVO löschen
                                            </button>
                                            <?php endif; ?>
                                        </div>

                                        <!-- ── TAB: KORRESPONDENZ ──────────────────────────── -->
                                        <div class="tab-pane" id="korrespondenz" role="tabpanel">

                                            <?php if (!empty($_GET['error']) && $_GET['error'] === 'event'): ?>
                                            <div class="alert alert-danger mb-3" role="alert">
                                                <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                                <div>Ungültige Eingabe – Datum prüfen.</div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Neuen Eintrag erfassen -->
                                            <div class="mb-4">
                                                <h4 class="mb-3">Eintrag erfassen</h4>
                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                                                    <input type="hidden" name="action" value="add_event">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <div class="row g-2 align-items-end">
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="ev_date">Datum</label>
                                                            <input type="date" class="form-control" id="ev_date" name="event_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
                                                        </div>
                                                        <div class="col-md-3">
                                                            <label class="form-label" for="ev_type">Typ</label>
                                                            <select class="form-select" id="ev_type" name="event_type">
                                                                <option value="antrag">Antrag gestellt</option>
                                                                <option value="aufforderung">MPU-Aufforderung erhalten</option>
                                                                <option value="bescheid">Bescheid / Entscheidung</option>
                                                                <option value="widerspruch">Widerspruch eingelegt</option>
                                                                <option value="fristverlängerung">Fristverlängerung beantragt</option>
                                                                <option value="sonstiges" selected>Sonstiges</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-5">
                                                            <label class="form-label" for="ev_desc">Beschreibung</label>
                                                            <input type="text" class="form-control" id="ev_desc" name="event_description" placeholder="z. B. Antrag auf Neuerteilung bei Fahrerlaubnisbehörde Mettmann eingereicht">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <button type="submit" class="btn btn-primary w-100">
                                                                <i class="ti ti-plus me-1" aria-hidden="true"></i>Eintragen
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>

                                            <!-- Zeitleiste -->
                                            <?php if (empty($case_events)): ?>
                                                <p class="text-secondary">Noch keine Korrespondenz erfasst.</p>
                                            <?php else: ?>
                                            <?php
                                            $ev_typ_cfg = [
                                                'aufforderung'    => ['bg-red-lt',  'ti-mail-forward', 'Aufforderung'],
                                                'antrag'          => ['bg-blue-lt',   'ti-send',         'Antrag'],
                                                'widerspruch'     => ['bg-orange-lt','ti-scale',        'Widerspruch'],
                                                'fristverlängerung'=> ['bg-yellow-lt','ti-clock-pause', 'Fristverlängerung'],
                                                'bescheid'        => ['bg-green-lt','ti-file-check',   'Bescheid'],
                                                'sonstiges'       => ['bg-muted-lt text-secondary',  'ti-notes',        'Sonstiges'],
                                            ];
                                            ?>
                                            <div class="timeline">
                                            <?php foreach ($case_events as $ev):
                                                [$ev_cls, $ev_icon, $ev_lbl] = $ev_typ_cfg[$ev['event_type']] ?? $ev_typ_cfg['sonstiges'];
                                            ?>
                                                <div class="timeline-event">
                                                    <div class="timeline-event-icon <?= $ev_cls ?>">
                                                        <i class="ti <?= $ev_icon ?>" aria-hidden="true"></i>
                                                    </div>
                                                    <div class="card timeline-event-card">
                                                        <div class="card-body py-2">
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <div>
                                                                    <span class="badge <?= $ev_cls ?> me-1"><?= htmlspecialchars($ev_lbl) ?></span>
                                                                    <span class="text-secondary small"><?= date('d.m.Y', strtotime($ev['event_date'])) ?></span>
                                                                </div>
                                                                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline" data-confirm="Diesen Korrespondenz-Eintrag löschen?">
                                                                    <input type="hidden" name="action" value="delete_event">
                                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                                    <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                                                                    <button type="submit" class="btn btn-ghost-secondary btn-icon btn-sm" title="Eintrag löschen" aria-label="Korrespondenz-Eintrag löschen">
                                                                        <i class="ti ti-x" aria-hidden="true"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                            <?php if (!empty($ev['description'])): ?>
                                                            <div class="mt-1 text-body small"><?= htmlspecialchars($ev['description']) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                    </div><!-- /.tab-content -->
                                </div><!-- /.card-body -->
                            </div><!-- /.card -->
                        </div><!-- /.col-12 tabs -->

                    </div><!-- /.row -->
                </div><!-- /.container-xl -->
            </main>

            <?php require 'partials/_footer.php'; ?>
        </div>
    </div>


<!-- ══ MODAL: DSGVO ART. 17 – LÖSCHUNG ══════════════════════════════════════ -->
<div class="modal modal-blur fade" id="modal-dsgvo-delete" tabindex="-1" role="dialog" aria-labelledby="modal-dsgvo-delete-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-status bg-danger"></div>
            <div class="modal-header">
                <h5 class="modal-title" id="modal-dsgvo-delete-title"><i class="ti ti-shield-off me-2" aria-hidden="true"></i>Art. 17 DSGVO – Datenlöschung</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-3">
                    <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                    <div>
                        <strong>Diese Aktion ist unwiderruflich.</strong> Alle Daten des Klienten werden dauerhaft gelöscht:
                        Stammdaten, Fallakten, Sitzungsprotokolle, Abstinenz-Nachweise, Aufgaben und Korrespondenz.
                    </div>
                </div>
                <p>Empfehlung: Erst einen <strong>DSGVO-Export (Art. 20)</strong> erstellen und dem Klienten aushändigen. Selbst keine Kopie behalten.</p>
                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" id="form-dsgvo-delete">
                    <input type="hidden" name="action" value="dsgvo_delete">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>" class="d-inline">
                    <input type="hidden" name="action" value="dsgvo_export">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="ti ti-download me-1" aria-hidden="true"></i>Export zuerst
                    </button>
                </form>
                <button type="submit" form="form-dsgvo-delete" class="btn btn-danger">
                    <i class="ti ti-trash me-1" aria-hidden="true"></i>Endgültig löschen
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: TASK-INFO ══════════════════════════════════════════════════════ -->
<div class="modal modal-blur fade" id="taskInfoModal" tabindex="-1" role="dialog" aria-labelledby="taskInfoModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="taskInfoModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body" id="taskInfoModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ TOAST: FEHLER-FEEDBACK ════════════════════════════════════════════════ -->
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="toast-fehler" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="toast-header">
            <i class="ti ti-alert-triangle icon text-danger me-2" aria-hidden="true"></i>
            <strong class="me-auto">Fehler</strong>
            <button type="button" class="ms-2 btn-close" data-bs-dismiss="toast" aria-label="Schließen"></button>
        </div>
        <div class="toast-body" id="toast-fehler-text">Fehler beim Speichern.</div>
    </div>
</div>

<!-- ══ MODAL: GENERISCHE LÖSCH-BESTÄTIGUNG ══════════════════════════════════ -->
<div class="modal modal-blur fade" id="modal-bestaetigung" tabindex="-1" role="dialog" aria-labelledby="modal-bestaetigung-title" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-bestaetigung-title">Löschen bestätigen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p id="modal-bestaetigung-text"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                <button type="button" class="btn btn-danger" id="modal-bestaetigung-ok">Löschen</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: KLIENT LÖSCHEN ════════════════════════════════════════════════ -->
<div class="modal modal-blur fade" id="modal-klient-loeschen" tabindex="-1" role="dialog" aria-labelledby="modal-loeschen-title" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-loeschen-title">Klienten löschen?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <p>Akte von <strong><?= htmlspecialchars($akte['first_name'] . ' ' . $akte['last_name']) ?></strong> unwiderruflich löschen?</p>
                <p class="text-secondary small">Alle zugehörigen Falldaten werden ebenfalls gelöscht (ON DELETE CASCADE).</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger">Endgültig löschen</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: WIDMARK-RECHNER ═══════════════════════════════════════════════ -->
<div class="modal modal-blur fade" id="modal-widmark" tabindex="-1" role="dialog" aria-labelledby="modal-widmark-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-widmark-title">Widmark-Rechner (Trinkmengen-Analyse)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Körpergewicht:</strong> <?= $akte['weight_kg'] ? $akte['weight_kg'] . ' Kilogramm' : '<span class="text-danger">Fehlt in Stammdaten!</span>' ?><br>
                        <strong>Reduktionsfaktor:</strong> <?= ($akte['gender'] === 'f') ? '0,6 (Weiblich)' : (($akte['gender'] === 'm') ? '0,7 (Männlich)' : '0,65 (Divers)') ?>
                    </div>
                    <div class="col-md-6 text-end">
                        <strong>Polizeilich gemessen:</strong><br>
                        <span class="text-danger fs-4 fw-bold"><?= $akte['bak_value'] ?? '0,00' ?> Promille</span>
                    </div>
                </div>
                <hr>
                <p class="text-secondary mb-4">Tragen Sie hier die vom Klienten behauptete Trinkmenge ein. Das System berechnet sofort den theoretischen Maximalwert nach der Widmark-Formel.</p>
                <div class="row g-3 align-items-center mb-3">
                    <label class="col-4 form-label" for="widmark-bier">Bier (ca. 5 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" id="widmark-bier" placeholder="0.0" aria-describedby="hint-bier">
                            <span class="input-group-text" aria-hidden="true">Liter</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small" id="hint-bier">Zum Beispiel 1.5 für drei halbe Liter</div>
                </div>
                <div class="row g-3 align-items-center mb-3">
                    <label class="col-4 form-label" for="widmark-wein">Wein / Sekt (ca. 11 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="0.1" class="form-control" id="widmark-wein" placeholder="0.0" aria-describedby="hint-wein">
                            <span class="input-group-text" aria-hidden="true">Liter</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small" id="hint-wein">Zum Beispiel 0.75 für eine Flasche</div>
                </div>
                <div class="row g-3 align-items-center mb-4">
                    <label class="col-4 form-label" for="widmark-schnaps">Spirituosen (ca. 40 Vol.-%)</label>
                    <div class="col-4">
                        <div class="input-group">
                            <input type="number" step="1" class="form-control" id="widmark-schnaps" placeholder="0" aria-describedby="hint-schnaps">
                            <span class="input-group-text" aria-hidden="true">Zentiliter (cl)</span>
                        </div>
                    </div>
                    <div class="col-4 text-secondary small" id="hint-schnaps">Zum Beispiel 4 für einen doppelten Schnaps</div>
                </div>
                <div class="card bg-primary-lt">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col">
                                <h3 class="card-title mb-1">Berechneter theoretischer Wert</h3>
                                <div class="text-secondary" id="widmark-bewertung">Warten auf Eingabe...</div>
                            </div>
                            <div class="col-auto">
                                <span id="widmark-ergebnis" aria-live="polite" class="fs-2 fw-bold">0,00 ‰</span>
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

<!-- Modal: Sitzung bearbeiten (inkl. SORKC) ─────────────────────────────────── -->
<div class="modal modal-blur fade" id="modal-sitzung" tabindex="-1" role="dialog" aria-labelledby="modal-sitzung-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                <input type="hidden" name="action" value="edit_session">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="session_id" id="ms-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-sitzung-title">Sitzung bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label" for="ms-date">Datum</label>
                            <input type="date" class="form-control" id="ms-date" name="session_date" required max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ms-duration">Dauer (min)</label>
                            <input type="number" class="form-control" id="ms-duration" name="duration_min" min="15" max="480">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="ms-phase">Phase</label>
                            <select class="form-select" id="ms-phase" name="phase" required>
                                <option value="anamnese">Anamnese</option>
                                <option value="analyse">Analyse</option>
                                <option value="check">Check</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="ms-notes">Sitzungsnotiz</label>
                            <textarea class="form-control" id="ms-notes" name="session_notes" rows="6"></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a class="text-secondary small" data-bs-toggle="collapse" href="#ms-sorkc" role="button" aria-expanded="false" aria-controls="ms-sorkc">
                            <i class="ti ti-chevron-down me-1" aria-hidden="true"></i>SORKC-Strukturanalyse
                        </a>
                        <div class="collapse mt-2" id="ms-sorkc">
                            <div class="card card-body border">
                                <div class="row g-2">
                                    <div class="col-12">
                                        <label class="form-label fw-medium mb-1" for="ms-sorkc-s">S — Stimulus</label>
                                        <textarea class="form-control form-control-sm" name="sorkc_s" id="ms-sorkc-s" rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-medium mb-1" for="ms-sorkc-o">O — Organismus</label>
                                        <textarea class="form-control form-control-sm" name="sorkc_o" id="ms-sorkc-o" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-medium mb-1" for="ms-sorkc-r">R — Reaktion</label>
                                        <textarea class="form-control form-control-sm" name="sorkc_r" id="ms-sorkc-r" rows="2"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-medium mb-1" for="ms-sorkc-k">K — Kontingenz</label>
                                        <textarea class="form-control form-control-sm" name="sorkc_k" id="ms-sorkc-k" rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-medium mb-1" for="ms-sorkc-c">C — Konsequenz</label>
                                        <textarea class="form-control form-control-sm" name="sorkc_c" id="ms-sorkc-c" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Notiz bearbeiten ──────────────────────────────────────────────────── -->
<div class="modal modal-blur fade" id="modal-notiz" tabindex="-1" role="dialog" aria-labelledby="modal-notiz-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="POST" action="akte.php?id=<?= (int)$klienten_identifikation ?>&case_id=<?= $akte_case_id ?>">
                <input type="hidden" name="action" value="edit_note">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="note_id" id="mn-id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-notiz-title">Notiz bearbeiten</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label" for="mn-date">Datum</label>
                            <input type="date" class="form-control" id="mn-date" name="note_date" required>
                        </div>
                        <div class="col-md-9">
                            <label class="form-label" for="mn-title">Überschrift</label>
                            <input type="text" class="form-control" id="mn-title" name="note_title" list="notiz-titel-vorschlaege" maxlength="120" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="mn-body">Notiz</label>
                            <textarea class="form-control" id="mn-body" name="note_body" rows="8" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Änderungen speichern</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// ── Bearbeiten-Modals: Werte aus der angeklickten Zeile übernehmen ───────────
document.querySelectorAll('.js-session-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const d = btn.dataset;
        document.getElementById('ms-id').value       = d.id;
        document.getElementById('ms-date').value     = d.date;
        document.getElementById('ms-duration').value = d.duration;
        document.getElementById('ms-phase').value    = d.phase;
        document.getElementById('ms-notes').value    = d.notes;
        document.getElementById('ms-sorkc-s').value  = d.sorkcS;
        document.getElementById('ms-sorkc-o').value  = d.sorkcO;
        document.getElementById('ms-sorkc-r').value  = d.sorkcR;
        document.getElementById('ms-sorkc-k').value  = d.sorkcK;
        document.getElementById('ms-sorkc-c').value  = d.sorkcC;
        // SORKC-Bereich nur aufklappen, wenn dort etwas steht
        const hatSorkc = [d.sorkcS, d.sorkcO, d.sorkcR, d.sorkcK, d.sorkcC].some(function (v) { return v !== ''; });
        const bereich  = document.getElementById('ms-sorkc');
        bereich.classList.toggle('show', hatSorkc);
    });
});

document.querySelectorAll('.js-note-edit').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const d = btn.dataset;
        document.getElementById('mn-id').value    = d.id;
        document.getElementById('mn-date').value   = d.date;
        document.getElementById('mn-title').value = d.title;
        document.getElementById('mn-body').value  = d.body;
    });
});
</script>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// ── Widmark-Rechner ──────────────────────────────────────────────────────────
function berechneWidmark() {
    const gewicht   = <?= (float)($akte['weight_kg'] ?? 0) ?>;
    const geschlecht = "<?= $akte['gender'] ?? 'm' ?>";
    const gemessen  = <?= (float)($akte['bak_value'] ?? 0) ?>;
    if (gewicht <= 0) {
        document.getElementById('widmark-ergebnis').innerHTML = "-";
        document.getElementById('widmark-bewertung').innerHTML = "<span class='text-danger'>Fehler: Körpergewicht in Stammdaten fehlt!</span>";
        return;
    }
    let r = (geschlecht === 'f') ? 0.6 : (geschlecht === 'm' ? 0.7 : 0.65);
    let bierL      = parseFloat(document.getElementById('widmark-bier').value)    || 0;
    let weinL      = parseFloat(document.getElementById('widmark-wein').value)    || 0;
    let schnapsCl  = parseFloat(document.getElementById('widmark-schnaps').value) || 0;
    let grammBier   = (bierL * 1000)   * 0.05 * 0.8;
    let grammWein   = (weinL * 1000)   * 0.11 * 0.8;
    let grammSchnaps = (schnapsCl * 10) * 0.40 * 0.8;
    let gesamtGramm = grammBier + grammWein + grammSchnaps;
    if (gesamtGramm === 0) {
        document.getElementById('widmark-ergebnis').innerHTML = "0,00 ‰";
        document.getElementById('widmark-bewertung').innerHTML = "Warten auf Eingabe...";
        return;
    }
    let promille = gesamtGramm / (gewicht * r);
    document.getElementById('widmark-ergebnis').innerHTML = promille.toFixed(2).replace('.', ',') + " ‰";
    let bewertungHtml = "";
    if (promille < (gemessen - 0.2)) {
        bewertungHtml = "<span class='text-danger fw-bold'><i class='ti ti-alert-triangle me-1'></i> Massive Verharmlosung! Behauptete Menge erklärt den polizeilichen Wert nicht annähernd.</span>";
    } else if (promille > (gemessen + 0.5)) {
        bewertungHtml = "<span class='text-warning'><i class='ti ti-info-circle me-1'></i> Klient gibt mehr an, als gemessen wurde (Eventuell Abbauzeit bis zur Blutabnahme berücksichtigen).</span>";
    } else {
        bewertungHtml = "<span class='text-success fw-bold'><i class='ti ti-check me-1'></i> Behauptete Menge ist plausibel für den gemessenen Wert.</span>";
    }
    document.getElementById('widmark-bewertung').innerHTML = bewertungHtml;
}
document.addEventListener('DOMContentLoaded', function () {
    ['widmark-bier', 'widmark-wein', 'widmark-schnaps'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', berechneWidmark);
    });
});
</script>

<script src="assets/js/tabler.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// Tabler 1.4+ exportiert Bootstrap unter window.tabler.bootstrap, nicht window.bootstrap
window.bootstrap = window.tabler?.bootstrap ?? window.bootstrap;
</script>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// ── Lösch-Bestätigung für Formulare mit data-confirm (Bootstrap Modal) ───────
(function () {
    let _pendingForm = null;
    const bsModal = new bootstrap.Modal(document.getElementById('modal-bestaetigung'));

    document.addEventListener('submit', function (e) {
        const msg = e.target.dataset.confirm;
        if (!msg) return;
        e.preventDefault();
        _pendingForm = e.target;
        document.getElementById('modal-bestaetigung-text').textContent = msg;
        bsModal.show();
    });

    document.getElementById('modal-bestaetigung-ok').addEventListener('click', function () {
        bsModal.hide();
        if (_pendingForm) {
            _pendingForm.dataset.confirm = ''; // disable guard for re-submission
            _pendingForm.submit();
            _pendingForm = null;
        }
    });

    document.getElementById('modal-bestaetigung').addEventListener('hidden.bs.modal', function () {
        if (_pendingForm) { _pendingForm = null; } // abgebrochen → nichts tun
    });
}());

// ── Tab-Persistenz via URL-Hash ──────────────────────────────────────────────
function activateTabFromHash(hash) {
    if (!hash) return false;
    var tab = document.querySelector('#akte-tabs a[href="' + CSS.escape(hash) + '"]');
    if (tab) { tab.click(); return true; }
    return false;
}
document.addEventListener('DOMContentLoaded', function () {
    // ── Tab-Persistenz via URL-Hash ──────────────────────────────────────────
    if (!activateTabFromHash(window.location.hash)) {
        const first = document.querySelector('#akte-tabs a[data-bs-toggle="tab"]');
        if (first && !first.classList.contains('active')) { first.click(); }
    }
    document.querySelectorAll('#akte-tabs a[data-bs-toggle="tab"]').forEach(function (el) {
        el.addEventListener('shown.bs.tab', function (e) {
            history.replaceState(null, '', e.target.getAttribute('href'));
        });
    });

    // ── Task-Info Modal ──────────────────────────────────────────────────────
    const modal = document.getElementById('taskInfoModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function (e) {
            const btn   = e.relatedTarget;
            const title = btn ? (btn.getAttribute('data-info-title') || '') : '';
            const body  = btn ? (btn.getAttribute('data-info-body')  || '') : '';
            document.getElementById('taskInfoModalTitle').textContent = title;
            const bodyEl = document.getElementById('taskInfoModalBody');
            bodyEl.innerHTML = '';
            body.split('\n\n').forEach(function (para) {
                if (para.trim() === '') return;
                const p = document.createElement('p');
                p.className = 'mb-2';
                p.textContent = para.trim();
                bodyEl.appendChild(p);
            });
        });
    }

    // ── Bulk-Task-Update (AJAX) ──────────────────────────────────────────────
    document.querySelectorAll('.bulk-task-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const bulkStatus = this.dataset.bulkStatus;
            const clientId   = this.dataset.clientId;
            const caseId     = this.dataset.caseId;
            const csrf       = this.dataset.csrf;
            const label      = bulkStatus === 'liegt_vor' ? 'alle als erledigt markieren' : 'alle anfordern';
            if (!confirm('Alle offenen Aufgaben ' + label + '?')) return;

            const self = this;
            self.disabled = true;
            const body = new FormData();
            body.append('action',      'bulk_task_update');
            body.append('bulk_status', bulkStatus);
            body.append('csrf_token',  csrf);

            fetch('akte.php?id=' + clientId + '&case_id=' + caseId, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: body
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) { window.location.reload(); }
                else { self.disabled = false; }
            })
            .catch(function () { self.disabled = false; });
        });
    });

    // ── Task-Toggle (AJAX) ───────────────────────────────────────────────────
    const nextStatus  = {offen: 'angefordert', angefordert: 'liegt_vor', liegt_vor: 'offen'};
    const btnClasses  = {offen: 'btn-danger',  angefordert: 'btn-warning', liegt_vor: 'btn-success'};
    const iconClasses = {offen: 'ti-circle',   angefordert: 'ti-clock',    liegt_vor: 'ti-check'};
    const labels      = {offen: 'Offen',       angefordert: 'Angefordert', liegt_vor: 'Liegt vor'};

    document.querySelectorAll('.task-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const key       = this.dataset.taskKey;
            const current   = this.dataset.currentStatus;
            const clientId  = this.dataset.clientId;
            const csrf      = this.dataset.csrf;
            const newStatus = nextStatus[current];
            const self      = this;

            self.disabled = true;

            const body = new FormData();
            body.append('action',     'update_task');
            body.append('task_key',   key);
            body.append('status',     newStatus);
            body.append('csrf_token', csrf);

            fetch('akte.php?id=' + clientId, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: body
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    self.classList.remove('btn-danger', 'btn-warning', 'btn-success');
                    self.classList.add(btnClasses[data.new_status]);
                    self.dataset.currentStatus = data.new_status;
                    self.innerHTML = '<i class="ti ' + iconClasses[data.new_status] + ' me-1" aria-hidden="true"></i>' + labels[data.new_status];
                }
                self.disabled = false;
            })
            .catch(function () {
                self.disabled = false;
                document.getElementById('toast-fehler-text').textContent = 'Status konnte nicht gespeichert werden. Bitte Seite neu laden.';
                new bootstrap.Toast(document.getElementById('toast-fehler')).show();
            });
        });
    });
});
</script>

<?php require 'partials/_modals_info.php'; ?>

<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// A-1: Kontext-adaptives Akkordeon im Fristen-Modal
// (nach _modals_info.php eingebunden, damit das Modal-Element im DOM existiert)
document.addEventListener('DOMContentLoaded', function () {
    const fristenModal = document.getElementById('modal-info-fristen-ampel');
    if (!fristenModal) return;
    fristenModal.addEventListener('show.bs.modal', function (e) {
        const trigger        = e.relatedTarget;
        const level          = trigger?.dataset?.ampelLevel   ?? 'secondary';
        const status         = trigger?.dataset?.antragStatus ?? '';
        const antragGestellt = ['gestellt', 'aufforderung_liegt_vor'].includes(status);

        const targetId = (level === 'danger' || level === 'warning')
            ? 'ac-check'
            : (level === 'success' && antragGestellt ? 'ac-unterlagen' : 'ac-ampel');

        document.querySelectorAll('#accordion-fristen .accordion-collapse').forEach(function (el) {
            bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).hide();
        });
        bootstrap.Collapse.getOrCreateInstance(
            document.getElementById(targetId), { toggle: false }
        ).show();
    });
});
</script>
<?php if (!empty($akte_eingaben)): ?>
    <script nonce="<?= $csp_nonce ?>">
        // Abgewiesenes Formular wieder füllen, die Werte kommen aus der eigenen Sitzung.
        (function () {
            const stand = <?= json_encode($akte_eingaben, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
            const form = document.querySelector('input[name="action"][value="' + stand.aktion + '"]')?.closest('form');
            if (!form) return;
            Object.entries(stand.felder).forEach(([feld, wert]) => {
                const el = form.elements[feld];
                if (el && el.type !== 'hidden') el.value = wert;
            });
        })();
    </script>
<?php endif; ?>
</body>
</html>
