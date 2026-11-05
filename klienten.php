<?php
declare(strict_types=1);
require_once 'db.php';
require_once __DIR__ . '/config/allowed_values.php';

// ── GET-Filter lesen und validieren ──────────────────────────────────────────
$filter_q         = trim((string)($_GET['q']         ?? ''));
$filter_hypothese = trim((string)($_GET['hypothese'] ?? ''));
$filter_status    = trim((string)($_GET['status']    ?? ''));
$filter_zahlung   = trim((string)($_GET['zahlung']   ?? ''));

if ($filter_hypothese !== '' && !in_array($filter_hypothese, ERLAUBTE_HYPOTHESEN, true)) {
    $filter_hypothese = '';
}
if ($filter_status !== '' && !in_array($filter_status, ['aktiv', 'pausiert', 'abgeschlossen'], true)) {
    $filter_status = '';
}
if ($filter_zahlung !== '' && !in_array($filter_zahlung, ['offen', 'teilweise', 'beglichen'], true)) {
    $filter_zahlung = '';
}

$filter_aktiv = ($filter_q !== '' || $filter_hypothese !== '' || $filter_status !== '' || $filter_zahlung !== '');

// ── SQL mit optionalen WHERE-Bedingungen ──────────────────────────────────────
$where  = [];
$params = [];

if ($filter_q !== '') {
    $like     = '%' . $filter_q . '%';
    $where[]  = "(c.last_name LIKE ? OR c.first_name LIKE ? OR c.city LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($filter_hypothese !== '') {
    $where[]  = "ca.suggested_hypothesis = ?";
    $params[] = $filter_hypothese;
}
if ($filter_status !== '') {
    $where[]  = "ca.status = ?";
    $params[] = $filter_status;
}
if ($filter_zahlung !== '') {
    $where[]  = "ca.payment_status = ?";
    $params[] = $filter_zahlung;
}

$where_sql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

// Alle Klienten mit ihrer aktiven (neuesten) Fallakte
// Die icd_*-Spalten werden für get_triage_vollstaendigkeit() gebraucht (UX-6):
// ohne sie kann weder die Spalte "Nächster Schritt" noch das Badge "Vorläufig"
// erkennen, ob der ICD-Quick-Check abgeschlossen ist.
// Subquery wählt pro Klient die neueste aktive Fallakte (oder neueste insgesamt als Fallback)
$sql = "
    SELECT c.id, c.first_name, c.last_name, c.date_of_birth, c.city, c.email, c.phone,
           c.created_at, c.zip_code,
           ca.authority_name,
           ca.id AS case_id, ca.client_id,
           ca.offense_category, ca.suggested_hypothesis, ca.suggested_abstinence,
           ca.antrag_status, ca.bkf_status, ca.suspension_end_date, ca.mpu_target_date,
           ca.package_type, ca.package_price, ca.hourly_rate,
           ca.payment_status, ca.payment_date,
           ca.override_active,
           ca.dokument_akte, ca.dokument_strafbefehl, ca.dokument_register,
           ca.status,
           ca.icd_kontrollverlust, ca.icd_priorisierung, ca.icd_folgeschaeden,
           ca.icd_craving, ca.icd_toleranz, ca.icd_entzug,
           (SELECT COUNT(*) FROM client_tasks ct WHERE ct.client_id = c.id) AS tasks_gesamt,
           (SELECT SUM(CASE WHEN ct.status != 'offen' THEN 1 ELSE 0 END)
            FROM client_tasks ct WHERE ct.client_id = c.id) AS tasks_erledigt
    FROM clients c
    LEFT JOIN cases ca ON ca.id = (
        SELECT id FROM cases WHERE client_id = c.id
        ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC
        LIMIT 1
    )
    {$where_sql}
    ORDER BY c.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$klienten = $stmt->fetchAll();

// UX-5: "Nächster Schritt" pro Zeile – gleiche Batch-Strategie wie index.php
// (2 zusätzliche Queries statt N), gleiche Funktion get_client_status() als
// einzige Quelle der Priorisierungslogik. Nur für aktive/pausierte Fälle
// berechnet; abgeschlossene Klienten brauchen keine Handlungsanweisung mehr.
require_once __DIR__ . '/logic/mpu_helper.php';
$naechster_schritt_je_klient = [];
$_ns_relevante = array_filter($klienten, fn($k) => in_array($k['status'] ?? '', ['aktiv', 'pausiert'], true) && $k['case_id'] !== null);
if (!empty($_ns_relevante)) {
    $_ns_client_ids  = array_column($_ns_relevante, 'id');
    $_ns_case_map    = array_combine($_ns_client_ids, array_column($_ns_relevante, 'case_id'));
    $_ns_platzhalter = implode(',', array_fill(0, count($_ns_client_ids), '?'));

    $_ns_probes_stmt = $pdo->prepare(
        "SELECT id, client_id, case_id, probe_date, method, result FROM abstinence_probes WHERE client_id IN ({$_ns_platzhalter}) ORDER BY probe_date ASC"
    );
    $_ns_probes_stmt->execute($_ns_client_ids);
    $_ns_probes_je_klient = [];
    foreach ($_ns_probes_stmt->fetchAll() as $_ns_probe) {
        $_ns_cid = (int)$_ns_probe['client_id'];
        if ($_ns_probe['case_id'] !== null && (int)$_ns_probe['case_id'] !== (int)$_ns_case_map[$_ns_cid]) { continue; }
        $_ns_probes_je_klient[$_ns_cid][] = $_ns_probe;
    }

    $_ns_tasks_stmt = $pdo->prepare(
        "SELECT id, client_id, case_id, task_key, status FROM client_tasks WHERE client_id IN ({$_ns_platzhalter})"
    );
    $_ns_tasks_stmt->execute($_ns_client_ids);
    $_ns_tasks_je_klient = [];
    foreach ($_ns_tasks_stmt->fetchAll() as $_ns_task) {
        $_ns_cid = (int)$_ns_task['client_id'];
        if ($_ns_task['case_id'] !== null && (int)$_ns_task['case_id'] !== (int)$_ns_case_map[$_ns_cid]) { continue; }
        $_ns_tasks_je_klient[$_ns_cid][] = $_ns_task;
    }

    foreach ($_ns_relevante as $_ns_klient) {
        $_ns_cid = (int)$_ns_klient['id'];
        $naechster_schritt_je_klient[$_ns_cid] = get_client_status(
            $_ns_klient,
            $_ns_probes_je_klient[$_ns_cid] ?? [],
            $_ns_tasks_je_klient[$_ns_cid] ?? []
        );
    }
}

// Schnellstatistiken (ebenfalls nur aktive Fallakten)
$sql_stats = "
    SELECT
        COUNT(DISTINCT c.id) AS gesamt,
        SUM(CASE WHEN JSON_OVERLAPS(ca.offense_category, JSON_ARRAY('Alkohol')) THEN 1 ELSE 0 END) AS alkohol,
        SUM(CASE WHEN JSON_OVERLAPS(ca.offense_category, JSON_ARRAY('Drogen')) THEN 1 ELSE 0 END) AS drogen,
        SUM(CASE WHEN JSON_OVERLAPS(ca.offense_category, JSON_ARRAY('Punkte', 'Verkehr')) THEN 1 ELSE 0 END) AS verkehr,
        SUM(CASE WHEN ca.bkf_status != 'nein' THEN 1 ELSE 0 END) AS bkf,
        -- Vom Dashboard hierher verlegt (29.07.2026): die Zahl gehört dorthin,
        -- wo Klienten verwaltet werden, und nicht auf die Seite, die beim
        -- Klientengespräch offen auf dem Bildschirm liegt.
        SUM(CASE WHEN ca.status = 'aktiv' THEN 1 ELSE 0 END) AS aktiv
    FROM clients c
    LEFT JOIN cases ca ON ca.id = (
        SELECT id FROM cases WHERE client_id = c.id
        ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC
        LIMIT 1
    )
";
$stats = $pdo->query($sql_stats)->fetch();

// Hilfsfunktion: Delikte als lesbarer Text aus JSON oder plain string
if (!function_exists('delikte_text')) {
    function delikte_text(string $raw): string {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return implode(', ', $decoded);
        }
        return $raw ?: '–';
    }
}
$page_css_file = 'assets/css/klienten.css';
$page_title    = 'MPU-Manager | Klienten';
require 'partials/_head.php';
$active_page = 'klienten';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Verwaltung</div>
                            <h1 class="page-title">Klientenübersicht</h1>
                        </div>
                        <div class="col-auto ms-auto d-print-none">
                            <div class="btn-list">
                                <a href="aufnahme.php" class="btn btn-primary d-none d-sm-inline-block">
                                    <i class="ti ti-plus me-2" aria-hidden="true"></i> Neuer Klient
                                </a>
                                <a href="aufnahme.php" class="btn btn-primary d-sm-none btn-icon" aria-label="Neuer Klient">
                                    <i class="ti ti-plus" aria-hidden="true"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    <?php require __DIR__ . '/partials/flash.php'; ?>

                    <!-- Schnellstatistiken -->
                    <div class="row row-deck row-cards mb-3">
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Klienten gesamt</div>
                                    <div class="h1 mb-0"><?= (int)$stats['gesamt'] ?></div>
                                    <div class="text-secondary small mt-1">davon <?= (int)$stats['aktiv'] ?> aktiv</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Alkohol-Fälle</div>
                                    <div class="h1 mb-0 text-red"><?= (int)$stats['alkohol'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Drogen-Fälle</div>
                                    <div class="h1 mb-0 text-orange"><?= (int)$stats['drogen'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">BKF-Klienten</div>
                                    <div class="h1 mb-0 text-blue"><?= (int)$stats['bkf'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Suche & Filter -->
                    <form method="GET" action="klienten.php" class="card mb-3">
                        <div class="card-body">
                            <div class="row g-2 align-items-end">
                                <div class="col-12 col-md">
                                    <label class="form-label" for="suchfeld">Suche</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="ti ti-search" aria-hidden="true"></i></span>
                                        <input type="text" id="suchfeld" name="q" class="form-control"
                                               placeholder="Name oder Ort …"
                                               value="<?= htmlspecialchars($filter_q) ?>"
                                               aria-label="Klienten durchsuchen">
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6 col-md-auto">
                                    <label class="form-label" for="hypothese">Hypothese</label>
                                    <select id="hypothese" name="hypothese" class="form-select">
                                        <option value="">Alle Hypothesen</option>
                                        <?php foreach (ERLAUBTE_HYPOTHESEN as $h): ?>
                                            <option value="<?= htmlspecialchars($h) ?>"
                                                    <?= $filter_hypothese === $h ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($h) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-3 col-md-auto">
                                    <label class="form-label" for="status">Status</label>
                                    <select id="status" name="status" class="form-select">
                                        <option value="">Alle</option>
                                        <option value="aktiv"         <?= $filter_status === 'aktiv'         ? 'selected' : '' ?>>Aktiv</option>
                                        <option value="pausiert"      <?= $filter_status === 'pausiert'      ? 'selected' : '' ?>>Pausiert</option>
                                        <option value="abgeschlossen" <?= $filter_status === 'abgeschlossen' ? 'selected' : '' ?>>Abgeschlossen</option>
                                    </select>
                                </div>
                                <div class="col-6 col-sm-3 col-md-auto">
                                    <label class="form-label" for="zahlung">Zahlung</label>
                                    <select id="zahlung" name="zahlung" class="form-select">
                                        <option value="">Alle</option>
                                        <option value="offen"     <?= $filter_zahlung === 'offen'     ? 'selected' : '' ?>>Offen</option>
                                        <option value="teilweise" <?= $filter_zahlung === 'teilweise' ? 'selected' : '' ?>>Teilweise</option>
                                        <option value="beglichen" <?= $filter_zahlung === 'beglichen' ? 'selected' : '' ?>>Beglichen</option>
                                    </select>
                                </div>
                                <div class="col-auto">
                                    <label class="form-label d-none d-md-block">&nbsp;</label>
                                    <div class="d-flex gap-1">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="ti ti-filter me-1" aria-hidden="true"></i>Filtern
                                        </button>
                                        <?php if ($filter_aktiv): ?>
                                            <a href="klienten.php" class="btn btn-outline-secondary" title="Filter zurücksetzen">
                                                <i class="ti ti-x" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Klientenliste -->
                    <div class="card">
                        <div class="table-responsive">
                            <table class="table table-vcenter table-mobile-md card-table" id="klienten-tabelle">
                                <thead>
                                    <tr>
                                        <th>Klient</th>
                                        <th>Wohnort</th>
                                        <th>Delikt / Hypothese</th>
                                        <th>Abstinenz</th>
                                        <th>Antragsstatus</th>
                                        <th>BKF</th>
                                        <th>Abrechnung</th>
                                        <th>Nächster Schritt</th>
                                        <th class="w-1"></th>
                                        <th class="w-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($klienten)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-secondary py-4">
                                            <i class="ti ti-user-off fs-2 d-block mb-2" aria-hidden="true"></i>
                                            Noch keine Klienten erfasst. <a href="aufnahme.php">Jetzt ersten Klienten anlegen.</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($klienten as $k):
                                        $initials = mb_strtoupper(mb_substr($k['first_name'], 0, 1) . mb_substr($k['last_name'], 0, 1));
                                        $delikte  = delikte_text($k['offense_category'] ?? '');
                                        $delikte_lower = mb_strtolower($delikte);
                                        $ist_bkf  = ($k['bkf_status'] ?? 'nein') !== 'nein';

                                        // Antragsstatus
                                        $antrag_labels = [
                                            'nicht_gestellt'          => ['bg-warning-lt', 'Nicht gestellt'],
                                            'gestellt'                => ['bg-blue-lt',    'Gestellt'],
                                            'aufforderung_liegt_vor'  => ['bg-green-lt',   'Aufforderung liegt vor'],
                                        ];
                                        [$antrag_bg, $antrag_txt] = $antrag_labels[$k['antrag_status'] ?? 'nicht_gestellt'] ?? ['bg-secondary-lt', 'Unbekannt'];

                                        // Fehlende Dokumente zählen
                                        $fehlende_dokumente = (int)!$k['dokument_akte'] + (int)!$k['dokument_strafbefehl'] + (int)!$k['dokument_register'];

                                        // Formalitäten-Fortschritt
                                        $tasks_gesamt  = (int)($k['tasks_gesamt']   ?? 0);
                                        $tasks_erledigt = (int)($k['tasks_erledigt'] ?? 0);
                                        $tasks_prozent = $tasks_gesamt > 0 ? (int)($tasks_erledigt / $tasks_gesamt * 100) : -1;
                                        $tasks_color   = $tasks_prozent >= 100 ? 'success' : ($tasks_prozent >= 50 ? 'warning' : 'danger');
                                    ?>
                                    <tr class="klient-zeile"
                                        data-search="<?= htmlspecialchars(mb_strtolower($k['first_name'] . ' ' . $k['last_name'] . ' ' . $k['city'])) ?>"
                                        data-href="akte.php?id=<?= (int)$k['id'] ?>"
                                        tabindex="0" role="button"
                                        aria-label="Akte öffnen für <?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?>">
                                        <td data-label="Klient">
                                            <div class="d-flex py-1 align-items-center">
                                                <div class="flex-fill">
                                                    <div class="fw-medium">
                                                        <?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?>
                                                        <?php if (($k['status'] ?? 'aktiv') === 'abgeschlossen'): ?>
                                                            <span class="badge bg-secondary-lt ms-1">Abgeschlossen</span>
                                                        <?php elseif (($k['status'] ?? '') === 'pausiert'): ?>
                                                            <span class="badge bg-warning-lt ms-1">Pausiert</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-secondary small">
                                                        #<?= str_pad((string)$k['id'], 5, '0', STR_PAD_LEFT) ?>
                                                        <?php if ($k['date_of_birth']): ?>
                                                            · <?= date('d.m.Y', strtotime($k['date_of_birth'])) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($tasks_prozent >= 0): ?>
                                                        <div class="d-flex align-items-center gap-2 mt-1">
                                                            <div class="progress progress-sm flex-fill max-w-80" role="progressbar" aria-label="Formalitäten-Fortschritt" aria-valuenow="<?= $tasks_prozent ?>" aria-valuemin="0" aria-valuemax="100" title="<?= $tasks_erledigt ?>/<?= $tasks_gesamt ?> Formalitäten erledigt">
                                                                <div class="progress-bar bg-<?= $tasks_color ?>" style="width:<?= $tasks_prozent ?>%"></div>
                                                            </div>
                                                            <span class="text-secondary small"><?= $tasks_prozent ?>%</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-label="Wohnort">
                                            <?= htmlspecialchars($k['city'] ?: '–') ?>
                                        </td>
                                        <td data-label="Delikt / Hypothese">
                                            <div><?= htmlspecialchars($delikte ?: '–') ?></div>
                                            <?php if ($k['suggested_hypothesis']): ?>
                                                <div class="text-secondary small"><?= htmlspecialchars($k['suggested_hypothesis']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($k['override_active']): ?>
                                                <span class="badge bg-yellow-lt ms-1" title="Human Override aktiv"><i class="ti ti-edit-circle" aria-hidden="true"></i> Override</span>
                                            <?php endif; ?>
                                            <?php
                                                // UX-6: Hypothese ist eine Untergrenze, solange ICD-Kriterien fehlen
                                                $_tv = $k['case_id'] !== null ? get_triage_vollstaendigkeit($k) : ['unvollstaendig' => false];
                                            ?>
                                            <?php if ($_tv['unvollstaendig']): ?>
                                                <span class="badge bg-warning-lt ms-1" title="<?= (int)$_tv['offen'] ?> von <?= (int)$_tv['gesamt'] ?> ICD-Kriterien noch nicht erhoben – Hypothese kann sich noch verschärfen"><i class="ti ti-hourglass" aria-hidden="true"></i> Vorläufig</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Abstinenz">
                                            <?php if ($k['suggested_abstinence']): ?>
                                                <span class="badge bg-green-lt"><?= htmlspecialchars($k['suggested_abstinence']) ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary">–</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Antragsstatus">
                                            <span class="badge <?= $antrag_bg ?>"><?= $antrag_txt ?></span>
                                            <?php if ($k['suspension_end_date']): ?>
                                                <div class="text-secondary small">Frist: <?= date('d.m.Y', strtotime($k['suspension_end_date'])) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="BKF">
                                            <?php if ($ist_bkf): ?>
                                                <?php $bkf_label = ['aktiv' => 'Aktiv', 'angestrebt' => 'Angestrebt']; ?>
                                                <span class="badge bg-purple-lt"><?= $bkf_label[$k['bkf_status']] ?? $k['bkf_status'] ?></span>
                                            <?php else: ?>
                                                <span class="text-secondary">–</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Abrechnung">
                                            <?php if ($k['package_type'] === 'paket' && $k['package_price']): ?>
                                                <span class="badge bg-cyan-lt">Paket</span>
                                                <div class="text-secondary small"><?= number_format((float)$k['package_price'], 2, ',', '.') ?> €</div>
                                            <?php elseif ($k['package_type'] === 'einzel' && $k['hourly_rate']): ?>
                                                <span class="badge bg-teal-lt">Einzel</span>
                                                <div class="text-secondary small"><?= number_format((float)$k['hourly_rate'], 2, ',', '.') ?> €/Std.</div>
                                            <?php else: ?>
                                                <span class="text-secondary">–</span>
                                            <?php endif; ?>
                                            <?php
                                                $pay_cfg = [
                                                    'offen'     => ['bg-danger-lt',  'Offen'],
                                                    'teilweise' => ['bg-warning-lt', 'Teilweise'],
                                                    'beglichen' => ['bg-success-lt', 'Beglichen'],
                                                ];
                                                [$pay_bg, $pay_lbl] = $pay_cfg[$k['payment_status'] ?? 'offen'] ?? ['bg-secondary-lt', '–'];
                                            ?>
                                            <div class="mt-1"><span class="badge <?= $pay_bg ?>"><?= $pay_lbl ?></span></div>
                                        </td>
                                        <td data-label="Nächster Schritt">
                                            <?php $_ns = $naechster_schritt_je_klient[(int)$k['id']] ?? null; ?>
                                            <?php if ($_ns === null): ?>
                                                <span class="text-secondary">–</span>
                                            <?php else: ?>
                                                <span class="badge bg-<?= $_ns['level'] ?>-lt"><?= $_ns['level'] === 'success' ? 'Erledigt' : ($_ns['level'] === 'danger' ? 'Dringend' : 'Bald') ?></span>
                                                <?php if ($_ns['level'] !== 'success'): ?>
                                                    <div class="text-secondary small"><?= htmlspecialchars($_ns['message']) ?></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-list flex-nowrap">
                                                <a href="bearbeiten.php?id=<?= (int)$k['id'] ?>" class="btn btn-icon btn-ghost-secondary" title="Bearbeiten" aria-label="Klient bearbeiten">
                                                    <i class="ti ti-edit" aria-hidden="true"></i>
                                                </a>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($fehlende_dokumente > 0): ?>
                                                <span class="badge bg-red text-red-fg" title="<?= $fehlende_dokumente ?> Dokument(e) fehlen">
                                                    <i class="ti ti-file-alert" aria-hidden="true"></i> <?= $fehlende_dokumente ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer d-flex align-items-center">
                            <p class="m-0 text-secondary" id="ergebnis-zaehler">
                                <span id="sichtbare-anzahl"><?= count($klienten) ?></span>
                                <?= $filter_aktiv ? 'gefilterte' : '' ?> Klienten
                            </p>
                        </div>
                    </div>

                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>
        </div>
    </div>

    <script src="assets/js/tabler.min.js"></script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
    (function () {
        const suchfeld = document.getElementById('suchfeld');
        const zeilen   = document.querySelectorAll('.klient-zeile');
        const zaehler  = document.getElementById('sichtbare-anzahl');

        // Live-Text-Filter: zusätzlich zur Server-Filterung sofortige Einschränkung
        // der geladenen Zeilen bei Texteingabe (ohne Seitenneuladen).
        function liveFilter() {
            const term = suchfeld.value.toLowerCase().trim();
            let n = 0;
            zeilen.forEach(function (z) {
                const match = term === '' || (z.dataset.search || '').includes(term);
                z.classList.toggle('hidden-row', !match);
                if (match) n++;
            });
            zaehler.textContent = n;
        }
        suchfeld.addEventListener('input', liveFilter);

        // Zeilenklick öffnet Akte
        document.addEventListener('click', function (e) {
            const row = e.target.closest('[data-href]');
            if (row) window.location.href = row.dataset.href;
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                const row = e.target.closest('[data-href]');
                if (row) { e.preventDefault(); window.location.href = row.dataset.href; }
            }
        });

        // Hotkey-Navigation: Alt+N = nächster Klient, Alt+P = vorheriger Klient
        (function () {
            let cursor = -1;
            function sichtbareZeilen() {
                return Array.from(document.querySelectorAll('.klient-zeile:not(.hidden-row)'));
            }
            function markiere(liste, index) {
                liste.forEach(function (z) { z.classList.remove('table-active'); z.removeAttribute('aria-current'); });
                if (index >= 0 && index < liste.length) {
                    liste[index].classList.add('table-active');
                    liste[index].setAttribute('aria-current', 'true');
                    liste[index].scrollIntoView({ block: 'nearest' });
                }
            }
            document.addEventListener('keydown', function (e) {
                if (!e.altKey || e.shiftKey || e.ctrlKey || e.metaKey) return;
                if (e.key !== 'n' && e.key !== 'p' && e.key !== 'N' && e.key !== 'P') return;
                e.preventDefault();
                const liste = sichtbareZeilen();
                if (!liste.length) return;
                cursor = e.key === 'n' || e.key === 'N'
                    ? Math.min(cursor + 1, liste.length - 1)
                    : Math.max(cursor - 1, 0);
                markiere(liste, cursor);
            });
            suchfeld.addEventListener('input', function () { cursor = -1; });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                const aktuell = document.querySelector('.klient-zeile[aria-current="true"]');
                if (aktuell && aktuell.dataset.href) {
                    e.preventDefault();
                    window.location.href = aktuell.dataset.href;
                }
            });
        })();
    })();
    </script>
</body>
</html>
