<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'logic/mpu_helper.php';
require_once __DIR__ . '/config/checklist_templates.php';

// ── Parameter validieren ──────────────────────────────────────────────────────
$client_id = (int)($_GET['id'] ?? 0);
$case_id   = (int)($_GET['case_id'] ?? 0);

if ($client_id <= 0) {
    http_response_code(400);
    $_error_title   = 'Ungültige Anfrage';
    $_error_message = 'Ungültige oder fehlende Klienten-ID.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// ── Alle Fallakten laden ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, status, created_at FROM cases WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$client_id]);
$alle_faelle = $stmt->fetchAll();

// Aktiven Fall bestimmen: URL-Parameter bevorzugen, sonst älteste aktive
$aktiver_fall = null;
if ($case_id > 0) {
    foreach ($alle_faelle as $fall) {
        if ((int)$fall['id'] === $case_id) { $aktiver_fall = $fall; break; }
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

// ── Klient + Fallakte laden ───────────────────────────────────────────────────
if ($aktiver_fall === null) {
    http_response_code(404);
    $_error_title   = 'Nicht gefunden';
    $_error_message = 'Klient nicht gefunden.';
    require __DIR__ . '/partials/error.php';
    exit;
}
$case_id = (int)$aktiver_fall['id'];

$stmt = $pdo->prepare("
    SELECT
        c.id AS client_pk, c.first_name, c.last_name, c.date_of_birth,
        c.street, c.zip_code, c.city, c.phone, c.email, c.gender,
        c.dsgvo_consent, c.revocation_confirmed,
        c.created_at AS client_created_at,
        ca.*
    FROM clients c
    JOIN cases ca ON ca.id = ?
    WHERE c.id = ?
");
$stmt->execute([$case_id, $client_id]);
$akte = $stmt->fetch();

if (!$akte) {
    http_response_code(404);
    $_error_title   = 'Nicht gefunden';
    $_error_message = 'Klient nicht gefunden.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// ── Abstinenz-Nachweise ───────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT probe_date, method, result, lab_name, notes, peth_value, kreatinin_mg_dl
    FROM abstinence_probes
    WHERE client_id = ? AND (case_id = ? OR case_id IS NULL)
    ORDER BY probe_date ASC
");
$stmt->execute([$client_id, $case_id]);
$probes = $stmt->fetchAll();

// ── Aufgaben laden ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT task_key, status, updated_at
    FROM client_tasks
    WHERE client_id = ? AND (case_id = ? OR case_id IS NULL)
    ORDER BY task_key ASC
");
$stmt->execute([$client_id, $case_id]);
$client_tasks = $stmt->fetchAll();

// Task-Metadaten für Labels
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

// ── Warnungen + Triage ────────────────────────────────────────────────────────
$warnungen           = [];
$empfohlene_hypothese = $akte['suggested_hypothesis'] ?: '–';
$abstinenz_empfehlung = $akte['suggested_abstinence'] ?: '–';

$icd_felder = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden','icd_craving','icd_toleranz','icd_entzug'];
$icd_score  = 0;
foreach ($icd_felder as $f) {
    if ((int)($akte[$f] ?? 0) === 1) $icd_score++;
}

// UX-6: Ein ausgedrucktes Dokument darf eine vorläufige Hypothese nicht als
// abschließende Bewertung ausweisen.
$triage_vollstaendigkeit = get_triage_vollstaendigkeit($akte);

// E-7: erhobene Screening-Scores gehören auch auf den Ausdruck
$screening_scores = get_screening_scores($akte);

$offense_list = json_decode($akte['offense_category'] ?? '[]', true) ?: [];
$_bak_cfg = (require __DIR__ . '/config/triage_config.php')['bak'];
if (in_array('Alkohol', $offense_list)) {
    $promille = (float)$akte['bak_value'];
    if ($promille >= (float)$_bak_cfg['giftfestigkeit'] && !empty($akte['symptoms_recorded'])) {
        $warnungen[] = "Hinweis auf ausgeprägte Giftfestigkeit: Keine Ausfallerscheinungen trotz {$promille} Promille.";
    }
    if ($promille >= (float)$_bak_cfg['a2_hoch'] && !$akte['therapy_completed'] && empty($akte['override_active'])) {
        $warnungen[] = "BK-5 Alarm: Als 'Selbstheiler' ohne Suchttherapie sind 15 Monate Abstinenzbelege erforderlich.";
    }
    if ($promille >= (float)$_bak_cfg['a2_hoch']) {
        $warnungen[] = "Achtung: Bei Werten ab " . number_format((float)$_bak_cfg['a2_hoch'], 1, ',', '') . " Promille schlägt die Software kein kontrolliertes Trinken vor. Ausnahmen regelt die BK-5 (A 2.7 N, am Buch zu prüfen).";
    }
}
if (in_array('Punkte', $offense_list)) {
    $warnungen[] = "Punkte-Täter: Fokus auf biografischer Analyse. Reines Regelwissen reicht nicht.";
}
if ($icd_score === 1) {
    $warnungen[] = "ICD-Quick-Check: 1 von 6 Kriterien erfüllt – Abhängigkeitsverdacht prüfen.";
}
if (in_array('Alkohol', $offense_list) && in_array('Drogen', $offense_list)) {
    $warnungen[] = "Mischkonsum (Alkohol + Drogen): Massiver Erschwerungsgrund gemäß BK-5.";
}
if (!empty($akte['override_active'])) {
    $warnungen[] = "Berater-Override aktiv: Hypothese und Abstinenzdauer weichen vom Systemvorschlag ab, die Soll-Werte folgen dem Override.";
}

$abst_monate = (int)($akte['abstinenz_monate'] ?? 0);
$karenzzeit_info = get_karenzzeit_info($akte['suspension_end_date'] ?? null, $probes, $abst_monate);
if ($karenzzeit_info !== null && $karenzzeit_info['kollision']) {
    $kz_ende   = date('d.m.Y', strtotime($karenzzeit_info['abstinenz_ende']));
    $kz_puffer = $karenzzeit_info['puffer_tage'];
    $warnungen[] = "Karenzzeit-Kollision: Abstinenzende ({$kz_ende}) liegt nur {$kz_puffer} Tage vor Sperrfristende.";
}

// ── Fristen ────────────────────────────────────────────────────────────────────
$sperrfrist_ende  = $akte['suspension_end_date'] ?? null;
$bussgeld_datum   = $akte['bussgeld_datum'] ?? null;
$einspruch_status = $akte['einspruch_status'] ?? 'nicht_relevant';

// Widerspruchsfrist: 14 Tage ab Bußgelddatum (gesetzlich fest)
$widerspruch_frist = null;
if ($bussgeld_datum && $einspruch_status !== 'nicht_relevant') {
    $widerspruch_frist = date('d.m.Y', strtotime($bussgeld_datum . ' +14 days'));
}

// ── Task-Gruppen aufteilen ────────────────────────────────────────────────────
$tasks_by_status = ['offen' => [], 'angefordert' => [], 'liegt_vor' => []];
foreach ($client_tasks as $task) {
    $tasks_by_status[$task['status'] ?? 'offen'][] = $task;
}

// ── Druckdatum ────────────────────────────────────────────────────────────────
$druckdatum = date('d.m.Y \u\m H:i \U\h\r');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// Theme-Init: Muss synchron VOR CSS geladen werden, um FOUC zu vermeiden
(function(){var A=['light','dark'],p=new URLSearchParams(window.location.search).get('theme'),s=localStorage.getItem('tablerTheme');var t=(A.indexOf(p)!==-1?p:null)||(A.indexOf(s)!==-1?s:null)||'light';if(p!==null&&A.indexOf(p)!==-1){localStorage.setItem('tablerTheme',p);}document.documentElement.setAttribute('data-bs-theme',t);})();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fallakte <?= htmlspecialchars($akte['last_name'] . ', ' . $akte['first_name']) ?> – MPU-Manager</title>
    <link rel="stylesheet" href="assets/css/tabler.min.css">
    <link rel="stylesheet" href="assets/css/tabler-icons.min.css">
    <style>
        :root { --tblr-font-sans-serif: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; }
        body  { font-family: var(--tblr-font-sans-serif); }

        /* Druckoptimierungen ─────────────────────────────── */
        @media print {
            .no-print    { display: none !important; }
            .page-break  { page-break-before: always; break-before: page; }
            body         { color: #000 !important; background: #fff !important; font-size: 11pt; }
            .card        { box-shadow: none !important; border: 1px solid #ccc !important; }
            .badge       { border: 1px solid #999 !important; }
            .text-secondary  { color: #555 !important; }
            a            { text-decoration: none !important; color: inherit !important; }
        }

        @media screen {
            body { background: var(--tblr-body-bg); }
            .druck-wrapper { max-width: 900px; margin: 2rem auto; padding: 0 1rem 3rem; }
            .druck-header  { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
        }
    </style>
</head>
<body>
<div class="druck-wrapper">

    <!-- ══ Kopfzeile ══════════════════════════════════════════════════════════ -->
    <div class="druck-header">
        <div>
            <h1 class="h3 mb-0">
                Fallakte: <?= htmlspecialchars($akte['first_name'] . ' ' . $akte['last_name']) ?>
            </h1>
            <div class="text-secondary small">
                Klient #<?= str_pad((string)$client_id, 5, '0', STR_PAD_LEFT) ?>
                <?php if ($akte['date_of_birth']): ?>
                    · <?= date('d.m.Y', strtotime($akte['date_of_birth'])) ?>
                <?php endif; ?>
                · Druckdatum: <?= htmlspecialchars($druckdatum) ?>
            </div>
        </div>
        <div class="no-print">
            <button id="btn-print" class="btn btn-primary">
                <i class="ti ti-printer me-1" aria-hidden="true"></i> Drucken
            </button>
            <a href="akte.php?id=<?= $client_id ?>&amp;case_id=<?= $case_id ?>" class="btn btn-outline-secondary ms-2">
                ← Zurück zur Akte
            </a>
        </div>
    </div>

    <!-- ══ 1. Stammdaten ══════════════════════════════════════════════════════ -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Stammdaten</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Name</div>
                    <div class="fw-medium"><?= htmlspecialchars($akte['first_name'] . ' ' . $akte['last_name']) ?></div>
                </div>
                <?php if ($akte['date_of_birth']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Geburtsdatum</div>
                    <div><?= date('d.m.Y', strtotime($akte['date_of_birth'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['city']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Wohnort</div>
                    <div><?= htmlspecialchars(($akte['zip_code'] ? $akte['zip_code'] . ' ' : '') . $akte['city']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['phone']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Telefon</div>
                    <div><?= htmlspecialchars($akte['phone']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['email']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">E-Mail</div>
                    <div><?= htmlspecialchars($akte['email']) ?></div>
                </div>
                <?php endif; ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Fallstatus</div>
                    <div>
                        <?php
                        $status_labels = ['aktiv' => 'Aktiv', 'pausiert' => 'Pausiert', 'abgeschlossen' => 'Abgeschlossen'];
                        echo htmlspecialchars($status_labels[$aktiver_fall['status']] ?? $aktiver_fall['status']);
                        ?>
                    </div>
                </div>
                <?php if (!empty($offense_list)): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Deliktart(en)</div>
                    <div><?= htmlspecialchars(implode(', ', $offense_list)) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['bak_value']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">BAK-Wert</div>
                    <div><?= number_format((float)$akte['bak_value'], 2, ',', '.') ?> ‰</div>
                </div>
                <?php endif; ?>
                <?php if ($akte['authority_name']): ?>
                <div class="col-sm-6 col-md-4">
                    <div class="text-secondary small mb-1">Behörde</div>
                    <div><?= htmlspecialchars($akte['authority_name']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ 2. Triage-Empfehlung ════════════════════════════════════════════════ -->
    <div class="card mb-3 <?= !empty($warnungen) ? 'border-warning' : '' ?>">
        <div class="card-header">
            <h3 class="card-title">Triage-Empfehlung</h3>
        </div>
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="text-secondary small mb-1">Hypothese</div>
                    <div class="fw-medium">
                        <?= htmlspecialchars($empfohlene_hypothese) ?>
                        <?php if ($triage_vollstaendigkeit['unvollstaendig']): ?>
                            <span class="fw-normal">(vorl&auml;ufig)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary small mb-1">Empfohlene Abstinenz</div>
                    <div class="fw-medium"><?= htmlspecialchars($abstinenz_empfehlung) ?></div>
                </div>
                <?php if (!empty($screening_scores)): ?>
                <div class="col-12">
                    <div class="text-secondary small mb-1">Screening-Scores <span class="fw-normal">(Dokumentation &ndash; nicht Teil der Triage-Berechnung)</span></div>
                    <div class="fw-medium">
                        <?php $_sc_texte = [];
                        foreach ($screening_scores as $_sc) {
                            $_sc_texte[] = htmlspecialchars($_sc['name']) . ' ' . (int)$_sc['wert'] . '/' . (int)$_sc['max']
                                         . ' (' . htmlspecialchars($_sc['label']) . ')';
                        }
                        echo implode(' &middot; ', $_sc_texte); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($triage_vollstaendigkeit['unvollstaendig']): ?>
                <div class="col-12">
                    <div class="text-secondary small">
                        <strong>Vorl&auml;ufig:</strong> ICD-Quick-Check noch unvollst&auml;ndig
                        (<?= (int)$triage_vollstaendigkeit['offen'] ?> von <?= (int)$triage_vollstaendigkeit['gesamt'] ?> Kriterien nicht erhoben:
                        <?= htmlspecialchars(implode(', ', $triage_vollstaendigkeit['fehlend'])) ?>).
                        Nicht erhobene Kriterien gehen als &bdquo;nicht erf&uuml;llt&ldquo; in die Berechnung ein &ndash;
                        Hypothese und Abstinenzdauer sind eine Untergrenze.
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($akte['override_active']): ?>
                <div class="col-12">
                    <div class="text-secondary small mb-1">Override-Begründung</div>
                    <div class="text-warning"><?= htmlspecialchars($akte['override_reason'] ?? '–') ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($warnungen)): ?>
            <div class="alert alert-warning mb-0">
                <div class="fw-medium mb-2">Hinweise / Warnungen</div>
                <ul class="mb-0 ps-3">
                    <?php foreach ($warnungen as $w): ?>
                        <li><?= htmlspecialchars($w) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="text-success small">
                <i class="ti ti-check me-1" aria-hidden="true"></i>Keine besonderen Erschwernisgründe erkannt.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ 3. Fristen-Übersicht ════════════════════════════════════════════════ -->
    <div class="card mb-3 page-break">
        <div class="card-header">
            <h3 class="card-title">Fristen-Übersicht</h3>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Führerschein-Sperrfristende</div>
                    <div class="fw-medium">
                        <?php if ($sperrfrist_ende): ?>
                            <?= date('d.m.Y', strtotime($sperrfrist_ende)) ?>
                            <?php
                            $tage_rest = (int)ceil((strtotime($sperrfrist_ende) - time()) / 86400);
                            if ($tage_rest > 0): ?>
                                <span class="text-secondary small">(noch <?= $tage_rest ?> Tage)</span>
                            <?php else: ?>
                                <span class="text-success small">(abgelaufen)</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-secondary">–</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($akte['mpu_target_date']): ?>
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">MPU-Zieldatum</div>
                    <div><?= date('d.m.Y', strtotime($akte['mpu_target_date'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['offense_date']): ?>
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Datum des Delikts</div>
                    <div><?= date('d.m.Y', strtotime($akte['offense_date'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($akte['legal_validity_date']): ?>
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Datum der Rechtskraft</div>
                    <div><?= date('d.m.Y', strtotime($akte['legal_validity_date'])) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($widerspruch_frist): ?>
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Widerspruchsfrist (14 Tage)</div>
                    <div class="fw-medium">
                        <?= $widerspruch_frist ?>
                        <span class="badge bg-<?= $einspruch_status === 'offen' ? 'warning' : 'secondary' ?>-lt ms-1">
                            <?= htmlspecialchars($einspruch_status) ?>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($karenzzeit_info !== null): ?>
                <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Berechnetes Abstinenzende</div>
                    <div class="<?= $karenzzeit_info['kollision'] ? 'text-danger fw-medium' : '' ?>">
                        <?= date('d.m.Y', strtotime($karenzzeit_info['abstinenz_ende'])) ?>
                        <?php if ($karenzzeit_info['kollision']): ?>
                            <span class="badge bg-danger-lt ms-1">Kollision</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══ 4. Abstinenz-Nachweise ══════════════════════════════════════════════ -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">
                Abstinenz-Nachweise
                <span class="badge bg-secondary-lt ms-2"><?= count($probes) ?></span>
            </h3>
        </div>
        <?php if (empty($probes)): ?>
        <div class="card-body text-secondary">Noch keine Nachweise erfasst.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Methode</th>
                        <th>Ergebnis</th>
                        <th>Labor</th>
                        <th>PEth</th>
                        <th>Kreatinin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($probes as $probe): ?>
                    <tr>
                        <td><?= date('d.m.Y', strtotime($probe['probe_date'])) ?></td>
                        <td><?= htmlspecialchars(ucfirst($probe['method'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $probe['result'] === 'negativ' ? 'success' : 'danger' ?>-lt">
                                <?= htmlspecialchars(ucfirst($probe['result'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($probe['lab_name'] ?: '–') ?></td>
                        <td><?= $probe['peth_value'] !== null ? number_format((float)$probe['peth_value'], 1, ',', '.') . ' ng/ml' : '–' ?></td>
                        <td><?= $probe['kreatinin_mg_dl'] !== null ? number_format((float)$probe['kreatinin_mg_dl'], 1, ',', '.') . ' mg/dl' : '–' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ 5. Aufgaben-Checkliste ══════════════════════════════════════════════ -->
    <div class="card mb-3 page-break">
        <div class="card-header">
            <h3 class="card-title">Aufgaben-Checkliste</h3>
        </div>
        <div class="card-body">
            <?php
            $status_config = [
                'liegt_vor'   => ['label' => 'Liegt vor',    'icon' => 'ti-circle-check', 'color' => 'success'],
                'angefordert' => ['label' => 'Angefordert',  'icon' => 'ti-clock',        'color' => 'warning'],
                'offen'       => ['label' => 'Offen',        'icon' => 'ti-circle',       'color' => 'secondary'],
            ];
            foreach ($status_config as $st => $cfg):
                $gruppe = $tasks_by_status[$st] ?? [];
                if (empty($gruppe)) continue;
            ?>
            <h4 class="text-<?= $cfg['color'] ?> mt-3 mb-2">
                <i class="ti <?= $cfg['icon'] ?> me-1" aria-hidden="true"></i>
                <?= $cfg['label'] ?> (<?= count($gruppe) ?>)
            </h4>
            <ul class="list-unstyled mb-0">
                <?php foreach ($gruppe as $task):
                    $meta = $task_meta[$task['task_key']] ?? [];
                    $label = $meta['label'] ?? $task['task_key'];
                ?>
                <li class="mb-1">
                    <i class="ti <?= $cfg['icon'] ?> text-<?= $cfg['color'] ?> me-1" aria-hidden="true"></i>
                    <?= htmlspecialchars($label) ?>
                    <?php if (!empty($meta['description'])): ?>
                        <span class="text-secondary small"> — <?= htmlspecialchars($meta['description']) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
            <?php if (empty($client_tasks)): ?>
                <p class="text-secondary mb-0">Keine Aufgaben erfasst.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ Fußzeile ═══════════════════════════════════════════════════════════ -->
    <div class="text-secondary small text-end mt-3">
        MPU-Manager · Vertraulich · Nur für interne Verwendung
    </div>

</div>
<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
document.getElementById('btn-print').addEventListener('click', function(){ window.print(); });
</script>
</body>
</html>
