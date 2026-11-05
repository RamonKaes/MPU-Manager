<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'logic/mpu_helper.php';
require_once 'config/allowed_values.php';

try {

// 1. Fristen-Radar: laufende Fälle ohne gestellten Antrag, Zeitfenster aus config/behoerden_config.php
$stmt_urgent = $pdo->prepare("
    SELECT c.id, c.first_name, c.last_name, c.city, c.zip_code, ca.suspension_end_date,
           ca.authority_name,
           DATEDIFF(ca.suspension_end_date, CURDATE()) AS days_left
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.suspension_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? WEEK)
      AND ca.status = 'aktiv'
      AND ca.antrag_status <> 'gestellt'
    ORDER BY ca.suspension_end_date ASC
    LIMIT 25
");
$radar_wochen = (int)behoerden_config()['radar_wochen'];
$stmt_urgent->execute([$radar_wochen]);
$urgent_clients = $stmt_urgent->fetchAll();

// 1b. Einspruchsfrist-Radar (F1): offene Bußgeldbescheide mit Frist ≤ 7 Tage
$einspruch_radar = $pdo->query("
    SELECT c.id, c.first_name, c.last_name,
           ca.bussgeld_datum, ca.einspruch_status,
           DATE_ADD(ca.bussgeld_datum, INTERVAL 14 DAY) AS frist_ende,
           DATEDIFF(DATE_ADD(ca.bussgeld_datum, INTERVAL 14 DAY), CURDATE()) AS days_left
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.einspruch_status = 'offen'
      AND ca.bussgeld_datum IS NOT NULL
      AND DATE_ADD(ca.bussgeld_datum, INTERVAL 14 DAY) >= CURDATE()
      AND DATE_ADD(ca.bussgeld_datum, INTERVAL 14 DAY) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY frist_ende ASC
    LIMIT 25
")->fetchAll();

// 2. KPI-Statistiken für die Dashboard-Kacheln
$kpis = $pdo->query("
    SELECT
        SUM(CASE WHEN (ca.dokument_akte = 0 OR ca.dokument_strafbefehl = 0 OR ca.dokument_register = 0) THEN 1 ELSE 0 END) AS fehlende_dokumente,
        SUM(CASE WHEN ca.antrag_status = 'nicht_gestellt' THEN 1 ELSE 0 END) AS offene_antraege
    FROM clients c
    LEFT JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
")->fetch();

// 3. Klienten-Tabelle (Suche + Filter, F-8)
$suche = trim((string)($_GET['q'] ?? ''));

// Filter-Whitelists – ungültige GET-Werte werden still ignoriert (in_array).
$ERLAUBTE_DELIKTE    = ['Alkohol', 'Drogen', 'Punkte'];
$ERLAUBTE_ANTRAG     = ['nicht_gestellt', 'gestellt', 'aufforderung_liegt_vor', 'abgelehnt'];
$ERLAUBTE_FALLSTATUS = ['aktiv', 'pausiert', 'abgeschlossen'];
$ANTRAG_LABELS = [
    'nicht_gestellt' => 'Nicht gestellt', 'gestellt' => 'Gestellt',
    'aufforderung_liegt_vor' => 'Aufforderung liegt vor', 'abgelehnt' => 'Abgelehnt',
];
$FALLSTATUS_LABELS = ['aktiv' => 'Aktiv', 'pausiert' => 'Pausiert', 'abgeschlossen' => 'Abgeschlossen'];

$filter_kat    = in_array($_GET['kat']    ?? '', $ERLAUBTE_DELIKTE,    true) ? (string)$_GET['kat']    : '';
$filter_antrag = in_array($_GET['antrag'] ?? '', $ERLAUBTE_ANTRAG,     true) ? (string)$_GET['antrag'] : '';
$filter_status = in_array($_GET['status'] ?? '', $ERLAUBTE_FALLSTATUS, true) ? (string)$_GET['status'] : '';
$plz_roh       = trim((string)($_GET['plz'] ?? ''));
$filter_plz    = preg_match('/^\d{1,5}$/', $plz_roh) ? $plz_roh : '';

// WHERE ausschließlich über Platzhalter aufbauen – keine Interpolation von Eingaben.
$where_parts = [];
$params      = [];
if ($suche !== '') {
    $where_parts[] = "(c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR ca.authority_file_number LIKE ?)";
    $such_muster = '%' . $suche . '%';
    array_push($params, $such_muster, $such_muster, $such_muster, $such_muster);
}
if ($filter_kat !== '') {
    // offense_category ist ein JSON-Array, z. B. ["Alkohol","Drogen"] → auf "Alkohol" matchen
    $where_parts[] = "ca.offense_category LIKE ?";
    $params[]      = '%"' . $filter_kat . '"%';
}
if ($filter_antrag !== '') {
    $where_parts[] = "ca.antrag_status = ?";
    $params[]      = $filter_antrag;
}
if ($filter_status !== '') {
    $where_parts[] = "ca.status = ?";
    $params[]      = $filter_status;
}
if ($filter_plz !== '') {
    $where_parts[] = "c.zip_code LIKE ?";
    $params[]      = $filter_plz . '%';
}
$hat_filter = !empty($where_parts);

$sql_clients = "
    SELECT c.id, c.first_name, c.last_name,
           ca.offense_category, ca.suggested_hypothesis, ca.suggested_abstinence,
           ca.package_type, ca.package_price, ca.hourly_rate,
           ca.antrag_status, ca.suspension_end_date,
           ca.dokument_akte, ca.dokument_strafbefehl, ca.dokument_register
    FROM clients c
    LEFT JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)"
    . ($hat_filter
        ? "\n    WHERE " . implode("\n      AND ", $where_parts) . "\n    ORDER BY c.last_name ASC, c.first_name ASC\n    LIMIT 50"
        : "\n    ORDER BY c.created_at DESC\n    LIMIT 10");
$stmt_clients = $pdo->prepare($sql_clients);
$stmt_clients->execute($params);
$recent_clients = $stmt_clients->fetchAll();

// Aktive Filter als Badges: Schlüssel => Anzeigetext (für ×-Entfernen-Links)
$aktive_filter = [];
if ($filter_kat !== '')    { $aktive_filter['kat']    = 'Delikt: ' . $filter_kat; }
if ($filter_antrag !== '') { $aktive_filter['antrag'] = 'Antrag: ' . $ANTRAG_LABELS[$filter_antrag]; }
if ($filter_status !== '') { $aktive_filter['status'] = 'Status: ' . $FALLSTATUS_LABELS[$filter_status]; }
if ($filter_plz !== '')    { $aktive_filter['plz']    = 'PLZ: ' . $filter_plz . '…'; }

// Baut den aktuellen Such-/Filter-Query-String, optional ohne einen Schlüssel.
$filter_query = function (?string $ohne = null) use ($suche, $filter_kat, $filter_antrag, $filter_status, $filter_plz): string {
    $p = array_filter([
        'q' => $suche, 'kat' => $filter_kat, 'antrag' => $filter_antrag,
        'status' => $filter_status, 'plz' => $filter_plz,
    ], fn($v) => $v !== '');
    if ($ohne !== null) { unset($p[$ohne]); }
    return $p ? '?' . http_build_query($p) : '';
};

// 3b. MPU-Termine in den nächsten 6 Monaten
$mpu_termine = $pdo->query("
    SELECT c.id, c.first_name, c.last_name,
           ca.mpu_target_date, ca.suspension_end_date, ca.suggested_hypothesis,
           DATEDIFF(ca.mpu_target_date, CURDATE()) AS days_left
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.mpu_target_date IS NOT NULL
      AND ca.mpu_target_date >= CURDATE()
      AND ca.mpu_target_date <= DATE_ADD(CURDATE(), INTERVAL 6 MONTH)
    ORDER BY ca.mpu_target_date ASC
    LIMIT 10
")->fetchAll();

// U-D: Fristen-Ampel – aktive Fälle mit MPU-Zieltermin ≤60 Tage
// (Sperrfristende wird bereits im Widget "Dringende Fristen & Warnungen" abgedeckt)
$fristen_alarm = $pdo->query("
    SELECT c.id, c.first_name, c.last_name,
           ca.mpu_target_date,
           DATEDIFF(ca.mpu_target_date, CURDATE()) AS days_until_nearest
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.status = 'aktiv'
      AND ca.mpu_target_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY ca.mpu_target_date ASC
    LIMIT 20
")->fetchAll();

// 4. Dynamische To-Dos: Fehlende Dokumente (max. 5 Klienten)
$todos_dokumente = $pdo->query("
    SELECT c.id, c.first_name, c.last_name,
           ca.dokument_akte, ca.dokument_strafbefehl, ca.dokument_register
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.dokument_akte = 0 OR ca.dokument_strafbefehl = 0 OR ca.dokument_register = 0
    ORDER BY c.created_at DESC
    LIMIT 5
")->fetchAll();

// 5. Dynamische To-Dos: Offene Anträge, sobald der Vorlauf der zuständigen Behörde erreicht ist (max. 5)
$todos_antraege = array_slice(array_values(array_filter($pdo->query("
    SELECT c.id, c.first_name, c.last_name, c.city, c.zip_code, ca.authority_name, ca.suspension_end_date,
           DATEDIFF(ca.suspension_end_date, CURDATE()) AS days_left
    FROM clients c
    JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
    WHERE ca.antrag_status = 'nicht_gestellt'
      AND ca.status = 'aktiv'
      AND ca.suspension_end_date IS NOT NULL
    ORDER BY ca.suspension_end_date ASC
")->fetchAll(), static fn(array $k): bool => (int)$k['days_left'] <= get_behoerden_vorlauf_tage(
    (string)$k['authority_name'], (string)$k['city'], (string)$k['zip_code']
))), 0, 5);

// Z-4: Statistik-Kacheln – Sperrfristen ≤ 30 Tage, Einspruchsfristen ≤ 7 Tage.
// Aktive Klienten und Offenes Honorar am 29.07.2026 entfernt (Schultersurfen).
$stat_kacheln = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM cases
         WHERE status = 'aktiv'
           AND suspension_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ) AS sperrfristen_30,
        (SELECT COUNT(*) FROM cases
         WHERE einspruch_status = 'offen'
           AND bussgeld_datum IS NOT NULL
           AND DATE_ADD(bussgeld_datum, INTERVAL 14 DAY) >= CURDATE()
           AND DATE_ADD(bussgeld_datum, INTERVAL 14 DAY) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ) AS einspruch_7
")->fetch();

// 6. DSGVO-Radar: Klienten mit abgelaufener Aufbewahrungsfrist
$stmt_dsgvo = $pdo->prepare("
    SELECT c.id, c.first_name, c.last_name,
           MAX(ca.closed_at) AS letzter_abschluss,
           FLOOR(DATEDIFF(CURDATE(), MAX(ca.closed_at)) / 365) AS jahre_seit_abschluss
    FROM clients c
    JOIN cases ca ON ca.client_id = c.id
    GROUP BY c.id, c.first_name, c.last_name
    HAVING SUM(CASE WHEN ca.status IN ('aktiv', 'pausiert') THEN 1 ELSE 0 END) = 0
       AND MAX(ca.closed_at) IS NOT NULL
       AND MAX(ca.closed_at) <= DATE_SUB(CURDATE(), INTERVAL ? YEAR)
    ORDER BY jahre_seit_abschluss DESC
");
$stmt_dsgvo->execute([DSGVO_AUFBEWAHRUNG_JAHRE]);
$dsgvo_loeschfaellig = $stmt_dsgvo->fetchAll();

// UX-5: Priorisierte Arbeitsliste "Nächster Schritt" über alle aktiven Klienten.
// Nutzt bewusst get_client_status() (logic/mpu_helper.php) statt die
// Prioritätslogik in SQL zu duplizieren – Single Source of Truth bleibt dort.
// Probes/Tasks werden für alle Klienten gebündelt geladen (2 Queries statt N),
// dann pro Klient in PHP gefiltert und die bestehende Funktion aufgerufen.
$naechste_schritte_rohdaten = $pdo->query("
    SELECT c.first_name, c.last_name, c.city, c.zip_code, ca.*
    FROM clients c
    JOIN cases ca ON ca.id = (
        SELECT id FROM cases WHERE client_id = c.id
        ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1
    )
    WHERE ca.status IN ('aktiv', 'pausiert')
")->fetchAll();

$naechste_schritte = [];
if (!empty($naechste_schritte_rohdaten)) {
    $ns_client_ids  = array_column($naechste_schritte_rohdaten, 'client_id');
    $ns_case_map    = array_combine($ns_client_ids, array_column($naechste_schritte_rohdaten, 'id'));
    $ns_platzhalter = implode(',', array_fill(0, count($ns_client_ids), '?'));

    $ns_probes_stmt = $pdo->prepare(
        "SELECT id, client_id, case_id, probe_date, method, result, lab_name, notes, kreatinin_mg_dl, peth_value, created_at
         FROM abstinence_probes WHERE client_id IN ({$ns_platzhalter}) ORDER BY probe_date ASC"
    );
    $ns_probes_stmt->execute($ns_client_ids);
    $ns_probes_je_klient = [];
    foreach ($ns_probes_stmt->fetchAll() as $ns_probe) {
        $ns_cid = (int)$ns_probe['client_id'];
        if ($ns_probe['case_id'] !== null && (int)$ns_probe['case_id'] !== (int)$ns_case_map[$ns_cid]) { continue; }
        $ns_probes_je_klient[$ns_cid][] = $ns_probe;
    }

    $ns_tasks_stmt = $pdo->prepare(
        "SELECT id, client_id, case_id, task_key, status, updated_at
         FROM client_tasks WHERE client_id IN ({$ns_platzhalter})"
    );
    $ns_tasks_stmt->execute($ns_client_ids);
    $ns_tasks_je_klient = [];
    foreach ($ns_tasks_stmt->fetchAll() as $ns_task) {
        $ns_cid = (int)$ns_task['client_id'];
        if ($ns_task['case_id'] !== null && (int)$ns_task['case_id'] !== (int)$ns_case_map[$ns_cid]) { continue; }
        $ns_tasks_je_klient[$ns_cid][] = $ns_task;
    }

    $ns_level_gewicht = ['danger' => 0, 'warning' => 1, 'success' => 2];
    foreach ($naechste_schritte_rohdaten as $ns_akte) {
        $ns_cid    = (int)$ns_akte['client_id'];
        $ns_status = get_client_status(
            $ns_akte,
            $ns_probes_je_klient[$ns_cid] ?? [],
            $ns_tasks_je_klient[$ns_cid] ?? []
        );
        if ($ns_status['level'] === 'success') { continue; } // Arbeitsliste, kein "alles ok"-Rauschen
        $ns_status['client_name'] = $ns_akte['first_name'] . ' ' . $ns_akte['last_name'];
        $naechste_schritte[] = $ns_status;
    }
    usort($naechste_schritte, fn($a, $b) => $ns_level_gewicht[$a['level']] <=> $ns_level_gewicht[$b['level']]);
}

} catch (PDOException $e) {
    error_log('Dashboard-Datenbankfehler: ' . $e->getMessage());
    http_response_code(500);
    $_error_title = 'Datenbankfehler';
    $_error_message = 'Datenbankfehler beim Laden des Dashboards. Bitte Seite neu laden oder Administrator kontaktieren.';
    require __DIR__ . '/partials/error.php';
    exit;
}

$page_title = 'MPU-Manager | Dashboard';
require 'partials/_head.php';
$active_page = 'index';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Übersicht</div>
                            <h1 class="page-title">Globale Kommandozentrale</h1>
                        </div>
                        <div class="col-auto ms-auto d-print-none">
                            <div class="btn-list">
                                <a href="aufnahme.php" class="btn btn-primary d-none d-sm-inline-block">
                                    <i class="ti ti-plus me-2" aria-hidden="true"></i> Neuer Klient
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    <div class="row row-deck row-cards">

<?php
// KPI-Kacheln. Bewusst nur operative Zahlen: Das Dashboard ist die Seite, die
// beim Klientengespräch offen auf dem Bildschirm liegt. Geschäftskennzahlen
// (Umsatz, offenes Honorar, Klientenzahl) stehen in finanzen.php bzw.
// klienten.php – dort ruft sie der Berater gezielt auf, statt sie einem Blick
// über die Schulter auszusetzen.
//
// Bewusst als PHP-Kommentar: eine Begründung gehört in den Quelltext, nicht in
// das an den Browser ausgelieferte HTML.
?>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Offene Anträge</div>
                                    <div class="h1 mb-0 <?= (int)$kpis['offene_antraege'] > 0 ? 'text-warning' : '' ?>"><?= (int)$kpis['offene_antraege'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Fehlende Dokumente</div>
                                    <div class="h1 mb-0 <?= (int)$kpis['fehlende_dokumente'] > 0 ? 'text-danger' : '' ?>"><?= (int)$kpis['fehlende_dokumente'] ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Sperren ≤ 30 Tage</div>
                                    <div class="h1 mb-0<?= (int)$stat_kacheln['sperrfristen_30'] > 0 ? ' text-danger' : '' ?>"><?= (int)$stat_kacheln['sperrfristen_30'] ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-sm-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Einspruch ≤ 7 Tage</div>
                                    <div class="h1 mb-0<?= (int)$stat_kacheln['einspruch_7'] > 0 ? ' text-danger' : '' ?>"><?= (int)$stat_kacheln['einspruch_7'] ?></div>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($dsgvo_loeschfaellig)): ?>
                        <div class="col-12">
                            <div class="alert alert-warning" role="alert">
                                <div class="alert-icon"><i class="ti ti-shield-lock icon alert-icon" aria-hidden="true"></i></div>
                                <div>
                                    <strong><?= count($dsgvo_loeschfaellig) ?> Klient<?= count($dsgvo_loeschfaellig) !== 1 ? 'en haben' : ' hat' ?> die DSGVO-Aufbewahrungsfrist überschritten</strong>
                                    und <?= count($dsgvo_loeschfaellig) !== 1 ? 'können' : 'kann' ?> gelöscht werden
                                    (Aufbewahrungsfrist des Betreibers: <?= DSGVO_AUFBEWAHRUNG_JAHRE ?> Jahre, Rechtsgrundlage selbst prüfen).
                                    <a href="klienten.php?status=abgeschlossen" class="alert-link ms-1">
                                        Zur Klientenliste <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- UX-5: Priorisierte Arbeitsliste "Nächste Schritte" -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-status-top bg-primary"></div>
                                <div class="card-header border-0">
                                    <h3 class="card-title">
                                        <i class="ti ti-list-check me-2" aria-hidden="true"></i>Nächste Schritte
                                        <?php if (!empty($naechste_schritte)): ?>
                                            <span class="badge bg-primary-lt ms-2"><?= count($naechste_schritte) ?></span>
                                        <?php endif; ?>
                                    </h3>
                                </div>
                                <?php if (empty($naechste_schritte)): ?>
                                <div class="card-body">
                                    <div class="alert alert-success mb-0" role="status">
                                        <div class="alert-icon"><i class="ti ti-check icon alert-icon" aria-hidden="true"></i></div>
                                        <div>Alles im grünen Bereich – keine dringenden Maßnahmen bei aktiven Klienten.</div>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table">
                                        <tbody>
                                        <?php foreach (array_slice($naechste_schritte, 0, 15) as $ns): ?>
                                            <tr>
                                                <td class="w-1">
                                                    <span class="badge bg-<?= $ns['level'] ?>-lt">
                                                        <i class="ti <?= $ns['level'] === 'danger' ? 'ti-alert-triangle' : 'ti-alert-circle' ?>" aria-hidden="true"></i>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= htmlspecialchars($ns['client_name']) ?></div>
                                                    <div class="text-secondary small"><?= htmlspecialchars($ns['message']) ?></div>
                                                </td>
                                                <td class="w-1">
                                                    <a href="<?= htmlspecialchars($ns['action_url']) ?>" class="btn btn-sm btn-<?= $ns['level'] ?>">
                                                        Zum Detail <i class="ti ti-arrow-right ms-1" aria-hidden="true"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($naechste_schritte) > 15): ?>
                                <div class="card-footer text-center">
                                    <span class="text-secondary small">
                                        <?= count($naechste_schritte) - 15 ?> weitere Klienten mit offenen Schritten –
                                        <a href="klienten.php">vollständige Klientenübersicht</a>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card">
                                <div class="card-status-top bg-danger"></div>
                                <div class="card-header border-0">
                                    <h3 class="card-title text-danger"><i class="ti ti-alert-triangle me-2" aria-hidden="true"></i>Dringende Fristen & Warnungen</h3>
                                </div>
                                <div class="list-group list-group-flush list-group-hoverable">
                                    <?php if (empty($urgent_clients)): ?>
                                        <div class="list-group-item">
                                            <div class="text-secondary"><i class="ti ti-check text-success me-2" aria-hidden="true"></i>Aktuell laufen keine Sperrfristen in den nächsten <?= (int)$radar_wochen ?> Wochen ab.</div>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($urgent_clients as $client): 
                                            $weeks_left   = max(0, (int)floor($client['days_left'] / 7));
                                            $is_mettmann  = is_authority_mettmann($client['authority_name'] ?? '', $client['city'] ?? '');
                                            $is_duesseldorf = is_authority_duesseldorf($client['authority_name'] ?? '', $client['city'] ?? '', $client['zip_code'] ?? '');
                                            // E-4: Vorlauffristen aus config/behoerden_config.php – dieselbe Quelle,
                                            // aus der get_client_status() die Arbeitsliste speist. Standen die Werte
                                            // hier inline, wusste die Arbeitsliste nichts davon.
                                            $warn_threshold = get_behoerden_vorlauf_tage(
                                                $client['authority_name'] ?? '', $client['city'] ?? '', $client['zip_code'] ?? ''
                                            );
                                            $badge_farbe = $client['days_left'] <= $warn_threshold / 2
                                                ? 'bg-red text-red-fg'
                                                : ($client['days_left'] <= $warn_threshold ? 'bg-orange text-orange-fg' : 'bg-yellow text-yellow-fg');
                                        ?>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="badge <?= $badge_farbe ?>">Noch <?= $weeks_left ?> Woche<?= $weeks_left !== 1 ? 'n' : '' ?></span>
                                                </div>
                                                <div class="col text-truncate">
                                                    <span class="text-body d-block fw-bold">
                                                        <?= htmlspecialchars($client['first_name'] . ' ' . $client['last_name']) ?>
                                                    </span>
                                                    <small class="text-secondary text-truncate mt-n1">
                                                        Sperrfrist endet am: <?= date('d.m.Y', strtotime($client['suspension_end_date'])) ?>.
                                                    </small>
                                                    
                                                    <?php if ($is_mettmann): ?>
                                                        <div class="text-danger small mt-1">
                                                            <i class="ti ti-map-pin text-warning" aria-hidden="true"></i> <strong>Mettmann:</strong> Persönliche Vorsprache erforderlich. Empfohlen: Antrag ≥ <?= (int)$warn_threshold ?> Tage vor Sperrfristende.
                                                        </div>
                                                    <?php elseif ($is_duesseldorf): ?>
                                                        <div class="text-warning small mt-1">
                                                            <i class="ti ti-map-pin text-warning" aria-hidden="true"></i> <strong>Düsseldorf:</strong> Online-Termin zwingend. Bearbeitungszeit 8–12 Wochen → Antrag ≥ <strong><?= (int)$warn_threshold ?> Tage</strong> vor Sperrfristende empfohlen!
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-auto">
                                                    <a href="akte.php?id=<?= $client['id'] ?>" class="btn btn-outline-secondary btn-sm">Akte öffnen</a>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- U-D: Fristen-Ampel (MPU-Termin / Sperrfristende ≤ 60 Tage) -->
                        <?php if (!empty($fristen_alarm)): ?>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-status-top bg-warning"></div>
                                <div class="card-header border-0">
                                    <h3 class="card-title">
                                        <i class="ti ti-clock-exclamation text-warning me-2" aria-hidden="true"></i>MPU-Termin in ≤&nbsp;60&nbsp;Tagen
                                    </h3>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Klient</th>
                                                <th>MPU-Zieltermin</th>
                                                <th>Verbleibend</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($fristen_alarm as $fa):
                                            $fa_days  = (int)$fa['days_until_nearest'];
                                            $fa_color = $fa_days <= 14 ? 'danger' : ($fa_days <= 30 ? 'warning' : 'orange');
                                            $fa_datum = $fa['mpu_target_date'] ? date('d.m.Y', strtotime($fa['mpu_target_date'])) : '–';
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="akte.php?id=<?= (int)$fa['id'] ?>" class="fw-medium text-reset">
                                                    <?= htmlspecialchars($fa['first_name'] . ' ' . $fa['last_name']) ?>
                                                </a>
                                            </td>
                                            <td><?= $fa_datum ?></td>
                                            <td>
                                                <span class="text-<?= $fa_color ?> fw-bold">
                                                    <?php if ($fa_days <= 0): ?>
                                                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>Abgelaufen
                                                    <?php else: ?>
                                                        <?= $fa_days ?> Tage
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Einspruchsfrist-Radar (F1) -->
                        <?php if (!empty($einspruch_radar)): ?>
                        <div class="col-12">
                            <div class="card border-danger">
                                <div class="card-status-top bg-danger"></div>
                                <div class="card-header border-0">
                                    <h3 class="card-title text-danger">
                                        <i class="ti ti-gavel me-2" aria-hidden="true"></i>Einspruchsfristen laufen ab (§ 410 StPO / § 67 OWiG)
                                    </h3>
                                </div>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($einspruch_radar as $er):
                                        $badge  = $er['days_left'] <= 3 ? 'bg-red text-red-fg' : 'bg-orange text-orange-fg';
                                    ?>
                                    <div class="list-group-item">
                                        <div class="row align-items-center">
                                            <div class="col-auto">
                                                <span class="badge <?= $badge ?>">Noch <?= (int)$er['days_left'] ?> Tag<?= (int)$er['days_left'] !== 1 ? 'e' : '' ?></span>
                                            </div>
                                            <div class="col text-truncate">
                                                <span class="fw-bold text-body"><?= htmlspecialchars($er['first_name'] . ' ' . $er['last_name']) ?></span>
                                                <small class="d-block text-danger mt-n1">
                                                    Einspruchsfrist endet: <strong><?= date('d.m.Y', strtotime($er['frist_ende'])) ?></strong>
                                                    — Bescheid vom <?= date('d.m.Y', strtotime($er['bussgeld_datum'])) ?>
                                                </small>
                                            </div>
                                            <div class="col-auto">
                                                <a href="akte.php?id=<?= (int)$er['id'] ?>" class="btn btn-danger btn-sm">
                                                    <i class="ti ti-folder-open me-1" aria-hidden="true"></i>Akte
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- MPU-Termine -->
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header border-0">
                                    <h3 class="card-title">
                                        <i class="ti ti-calendar-check me-2 text-blue" aria-hidden="true"></i>MPU-Termine (nächste 6 Monate)
                                    </h3>
                                    <div class="card-actions">
                                        <a href="klienten.php" class="btn btn-sm btn-outline-secondary">Alle Klienten</a>
                                    </div>
                                </div>
                                <?php if (empty($mpu_termine)): ?>
                                <div class="card-body text-secondary small">
                                    <i class="ti ti-calendar-off me-1" aria-hidden="true"></i>Keine MPU-Termine in den nächsten 6 Monaten eingetragen.
                                </div>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-vcenter card-table">
                                        <thead>
                                            <tr>
                                                <th>Klient</th>
                                                <th>MPU-Termin</th>
                                                <th>Verbleibend</th>
                                                <th>Hypothese</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($mpu_termine as $t):
                                                $wochen = max(0, (int)floor($t['days_left'] / 7));
                                                $badge  = $t['days_left'] < 28
                                                    ? 'bg-red text-red-fg'
                                                    : ($t['days_left'] < 56 ? 'bg-orange text-orange-fg' : 'bg-blue-lt');
                                            ?>
                                            <tr data-href="akte.php?id=<?= (int)$t['id'] ?>"
                                                tabindex="0" role="button"
                                                aria-label="Akte öffnen für <?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?>">
                                                <td class="fw-medium"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></td>
                                                <td><?= date('d.m.Y', strtotime($t['mpu_target_date'])) ?></td>
                                                <td><span class="badge <?= $badge ?>"><?= $wochen ?> Woche<?= $wochen !== 1 ? 'n' : '' ?></span></td>
                                                <td class="text-secondary small"><?= htmlspecialchars($t['suggested_hypothesis'] ?: '–') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header border-0 d-block">
                                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                        <h3 class="card-title mb-0">
                                            <?php if ($suche !== ''): ?>
                                                Suchergebnisse für „<?= htmlspecialchars($suche) ?>"
                                                <span class="badge bg-secondary-lt ms-2"><?= count($recent_clients) ?></span>
                                            <?php elseif ($hat_filter): ?>
                                                Gefilterte Klienten
                                                <span class="badge bg-secondary-lt ms-2"><?= count($recent_clients) ?></span>
                                            <?php else: ?>
                                                Aktive Klienten (Schnellzugriff)
                                            <?php endif; ?>
                                        </h3>
                                    </div>
                                    <form action="index.php" method="GET" class="row g-2" role="search">
                                        <div class="col-12 col-md">
                                            <input type="search" name="q" class="form-control form-control-sm"
                                                placeholder="Name oder Aktenzeichen …"
                                                value="<?= htmlspecialchars($suche) ?>" aria-label="Klienten suchen">
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <select name="kat" class="form-select form-select-sm" aria-label="Deliktkategorie">
                                                <option value="">Delikt: Alle</option>
                                                <?php foreach ($ERLAUBTE_DELIKTE as $d): ?>
                                                    <option value="<?= htmlspecialchars($d) ?>"<?= $filter_kat === $d ? ' selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <select name="antrag" class="form-select form-select-sm" aria-label="Antrag-Status">
                                                <option value="">Antrag: Alle</option>
                                                <?php foreach ($ANTRAG_LABELS as $val => $lbl): ?>
                                                    <option value="<?= htmlspecialchars($val) ?>"<?= $filter_antrag === $val ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <select name="status" class="form-select form-select-sm" aria-label="Fall-Status">
                                                <option value="">Status: Alle</option>
                                                <?php foreach ($FALLSTATUS_LABELS as $val => $lbl): ?>
                                                    <option value="<?= htmlspecialchars($val) ?>"<?= $filter_status === $val ? ' selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6 col-md-auto">
                                            <input type="text" name="plz" class="form-control form-control-sm" style="max-width: 7rem"
                                                maxlength="5" pattern="\d{1,5}" inputmode="numeric" placeholder="PLZ"
                                                value="<?= htmlspecialchars($filter_plz) ?>" aria-label="PLZ-Präfix">
                                        </div>
                                        <div class="col-12 col-md-auto d-flex gap-2">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="ti ti-filter me-1" aria-hidden="true"></i>Filtern
                                            </button>
                                            <?php if ($suche !== '' || $hat_filter): ?>
                                                <a href="index.php" class="btn btn-sm btn-outline-danger" aria-label="Alle Filter zurücksetzen">
                                                    <i class="ti ti-x" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </form>
                                    <?php if (!empty($aktive_filter)): ?>
                                    <div class="mt-2">
                                        <span class="text-secondary small me-1">Aktive Filter:</span>
                                        <?php foreach ($aktive_filter as $key => $label): ?>
                                            <a href="index.php<?= htmlspecialchars($filter_query($key)) ?>"
                                               class="badge bg-blue-lt text-decoration-none me-1"
                                               aria-label="Filter entfernen: <?= htmlspecialchars($label) ?>">
                                                <?= htmlspecialchars($label) ?> <i class="ti ti-x" aria-hidden="true"></i>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-vcenter table-mobile-md card-table">
                                        <thead>
                                            <tr>
                                                <th>Klient</th>
                                                <th>Delikt / Hypothese</th>
                                                <th>Abstinenz</th>
                                                <th>Antrag</th>
                                                <th class="w-1">Abrechnung</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($recent_clients)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-secondary py-4">
                                                    <i class="ti ti-search-off me-2" aria-hidden="true"></i>
                                                    <?php if ($suche !== ''): ?>
                                                        Keine Klienten für „<?= htmlspecialchars($suche) ?>" gefunden.
                                                    <?php elseif ($hat_filter): ?>
                                                        Keine Klienten für die gewählten Filter gefunden.
                                                    <?php else: ?>
                                                        Noch keine Klienten erfasst.
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php foreach ($recent_clients as $row):
                                                $initials = mb_substr($row['first_name'], 0, 1) . mb_substr($row['last_name'], 0, 1);
                                                $akte_url = 'akte.php?id=' . (int)$row['id'];
                                                $antrag_map = [
                                                    'nicht_gestellt'         => ['bg-warning-lt', 'Offen'],
                                                    'gestellt'               => ['bg-blue-lt',    'Gestellt'],
                                                    'aufforderung_liegt_vor' => ['bg-green-lt',   'Aufforderung'],
                                                ];
                                                [$ab_bg, $ab_txt] = $antrag_map[$row['antrag_status'] ?? 'nicht_gestellt'] ?? ['bg-secondary-lt', '–'];
                                            ?>
                                            <tr data-href="<?= $akte_url ?>" tabindex="0" role="button" aria-label="Akte öffnen für <?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?>">
                                                <td data-label="Name">
                                                    <div class="d-flex py-1 align-items-center">
                                                        <div class="flex-fill">
                                                            <div class="fw-medium"><?= htmlspecialchars($row['first_name'] . ' ' . $row['last_name']) ?></div>
                                                            <div class="text-secondary small">#<?= str_pad((string)$row['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td data-label="Delikt / Hypothese">
                                                    <div><?= htmlspecialchars(implode(', ', json_decode($row['offense_category'] ?? '', true) ?: []) ?: 'Nicht definiert') ?></div>
                                                    <div class="text-secondary small"><?= htmlspecialchars($row['suggested_hypothesis'] ?: 'Triage ausstehend') ?></div>
                                                </td>
                                                <td data-label="Abstinenz">
                                                    <?php if ($row['suggested_abstinence']): ?>
                                                        <span class="badge bg-green-lt"><?= htmlspecialchars($row['suggested_abstinence']) ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning-lt">Ausstehend</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Antrag">
                                                    <span class="badge <?= $ab_bg ?>"><?= $ab_txt ?></span>
                                                    <?php if ($row['suspension_end_date']): ?>
                                                        <div class="text-secondary small"><?= date('d.m.Y', strtotime($row['suspension_end_date'])) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Abrechnung">
                                                    <?php if ($row['package_type'] === 'paket' && $row['package_price']): ?>
                                                        <span class="badge bg-cyan-lt">Paket</span>
                                                        <div class="text-secondary small"><?= number_format((float)$row['package_price'], 0, ',', '.') ?>&nbsp;€</div>
                                                    <?php elseif ($row['package_type'] === 'einzel' && $row['hourly_rate']): ?>
                                                        <span class="badge bg-teal-lt">Einzel</span>
                                                        <div class="text-secondary small"><?= number_format((float)$row['hourly_rate'], 0, ',', '.') ?>&nbsp;€/Std.</div>
                                                    <?php else: ?>
                                                        <span class="text-secondary">–</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header border-0">
                                    <h3 class="card-title">
                                        <i class="ti ti-bell-ringing me-2 text-warning" aria-hidden="true"></i>Aktionsliste
                                    </h3>
                                </div>
                                <div class="list-group list-group-flush list-group-hoverable">
                                    <?php
                                    $todos_gesamt = 0;

                                    foreach ($todos_dokumente as $klient):
                                        $todos_gesamt++;
                                        $fehlend = [];
                                        if (!$klient['dokument_akte'])        $fehlend[] = 'Behördenakte';
                                        if (!$klient['dokument_strafbefehl']) $fehlend[] = 'Strafbefehl';
                                        if (!$klient['dokument_register'])    $fehlend[] = 'Registerauszug';
                                    ?>
                                    <a href="akte.php?id=<?= (int)$klient['id'] ?>" class="list-group-item list-group-item-action">
                                        <div class="row align-items-center g-2">
                                            <div class="col-auto">
                                                <i class="ti ti-file-alert icon text-warning" aria-hidden="true"></i>
                                            </div>
                                            <div class="col text-truncate">
                                                <div class="fw-medium"><?= htmlspecialchars($klient['first_name'] . ' ' . $klient['last_name']) ?></div>
                                                <div class="text-secondary small text-truncate">Fehlt: <?= htmlspecialchars(implode(', ', $fehlend)) ?></div>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>

                                    <?php foreach ($todos_antraege as $klient):
                                        $todos_gesamt++;
                                        $wochen_rest = (int)floor($klient['days_left'] / 7);
                                    ?>
                                    <a href="akte.php?id=<?= (int)$klient['id'] ?>" class="list-group-item list-group-item-action">
                                        <div class="row align-items-center g-2">
                                            <div class="col-auto">
                                                <i class="ti ti-clock-exclamation icon text-danger" aria-hidden="true"></i>
                                            </div>
                                            <div class="col text-truncate">
                                                <div class="fw-medium"><?= htmlspecialchars($klient['first_name'] . ' ' . $klient['last_name']) ?></div>
                                                <div class="text-secondary small text-truncate">
                                                    Antrag offen &ndash; Frist in <?= $wochen_rest ?> Woche<?= $wochen_rest !== 1 ? 'n' : '' ?>
                                                    (<?= date('d.m.Y', strtotime($klient['suspension_end_date'])) ?>)
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>

                                    <?php if ($todos_gesamt === 0): ?>
                                    <div class="list-group-item">
                                        <div class="text-secondary text-center py-2">
                                            <i class="ti ti-circle-check text-success me-1" aria-hidden="true"></i>
                                            Keine offenen Aktionen
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($dsgvo_loeschfaellig)): ?>
                        <!-- DSGVO-Radar -->
                        <div class="col-12">
                            <div class="card border-warning">
                                <div class="card-header border-0">
                                    <h3 class="card-title">
                                        <i class="ti ti-shield-exclamation text-warning me-2" aria-hidden="true"></i>
                                        DSGVO-Löschvorschlag (Art. 17) –
                                        Aufbewahrungsfrist > <?= DSGVO_AUFBEWAHRUNG_JAHRE ?> Jahre abgelaufen
                                    </h3>
                                </div>
                                <div class="list-group list-group-flush list-group-hoverable">
                                    <?php foreach ($dsgvo_loeschfaellig as $klient): ?>
                                    <a href="akte.php?id=<?= (int)$klient['id'] ?>" class="list-group-item list-group-item-action">
                                        <div class="row align-items-center g-2">
                                            <div class="col-auto">
                                                <i class="ti ti-trash-x icon text-warning" aria-hidden="true"></i>
                                            </div>
                                            <div class="col text-truncate">
                                                <div class="fw-medium"><?= htmlspecialchars($klient['first_name'] . ' ' . $klient['last_name']) ?></div>
                                                <div class="text-secondary small">
                                                    Abgeschlossen: <?= htmlspecialchars(date('d.m.Y', strtotime($klient['letzter_abschluss']))) ?>
                                                    &ndash; <strong><?= (int)$klient['jahre_seit_abschluss'] ?> Jahre</strong> seit Fallabschluss
                                                </div>
                                            </div>
                                            <div class="col-auto">
                                                <span class="badge bg-warning text-warning-fg">Löschen prüfen</span>
                                            </div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="card-footer text-secondary small">
                                    <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                                    Bitte prüfen, ob die gesetzliche Aufbewahrungspflicht erfüllt ist,
                                    und den DSGVO-Datenexport (Art. 20) vor der Löschung erstellen.
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>
        </div>
    </div>

    <script src="assets/js/tabler.min.js"></script>
    <script nonce="<?= htmlspecialchars($csp_nonce) ?>">
    document.addEventListener('click', function (e) {
        var row = e.target.closest('[data-href]');
        if (row) window.location.href = row.dataset.href;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            var row = e.target.closest('[data-href]');
            if (row) { e.preventDefault(); window.location.href = row.dataset.href; }
        }
    });
    </script>
</body>
</html>