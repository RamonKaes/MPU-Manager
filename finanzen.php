<?php
declare(strict_types=1);

require_once 'db.php';
require_once __DIR__ . '/logic/validator.php';
require_once __DIR__ . '/logic/mpu_helper.php';

// ── U-C: Inline-AJAX für Zahlungsstatus ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment') {
    header('Content-Type: application/json');
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit;
    }
    $upd_client_id  = (int)($_POST['client_id'] ?? 0);
    $upd_status_raw = (string)($_POST['payment_status'] ?? '');
    $upd_status     = in_array($upd_status_raw, ['offen','teilweise','beglichen'], true) ? $upd_status_raw : null;
    $upd_amount     = isset($_POST['payment_amount_paid']) && $_POST['payment_amount_paid'] !== '' ? (float)$_POST['payment_amount_paid'] : null;
    $upd_date       = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
    $upd_case_id = (int)($_POST['case_id'] ?? 0);
    $upd_version = (int)($_POST['case_version'] ?? -1);
    if (!$upd_client_id || !$upd_status || $upd_case_id <= 0 || $upd_version < 0
        || ($upd_date !== null && !validate_datum((string)$upd_date))
        || ($upd_amount !== null && ($upd_amount < 0 || $upd_amount > 999999.99))) {
        http_response_code(400); echo json_encode(['ok' => false]); exit;
    }
    // Fall und Stand nennt das Formular: Dazwischen kann ein neuer Fall entstanden oder der Fall
    // geändert worden sein, das Geld gehört dann nicht einfach zur „aktuellen“ Akte (CC-46).
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE cases SET payment_status = ?, payment_amount_paid = ?, payment_date = ?, version = version + 1
        WHERE id = ? AND client_id = ? AND version = ?");
    $upd->execute([$upd_status, $upd_amount, $upd_date, $upd_case_id, $upd_client_id, $upd_version]);
    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'konflikt']);
        exit;
    }
    audit_log($pdo, 'update_payment', 'cases', $upd_case_id, $upd_client_id, "Zahlungsstatus → {$upd_status}" . ($upd_amount !== null ? " ({$upd_amount} €)" : ''));
    $pdo->commit();
    echo json_encode(['ok' => true, 'new_status' => $upd_status, 'version' => $upd_version + 1]);
    exit;
}

// ── Daten laden ──────────────────────────────────────────────────────────────

// Alle Klienten mit Abrechnungsdaten + Sitzungsminuten (für Einzel-Kalkulation)
try {
    $klienten = $pdo->query("
        SELECT c.id, c.first_name, c.last_name,
               ca.id AS case_id, ca.version AS case_version, ca.package_type, ca.package_price, ca.hourly_rate,
               ca.payment_status, ca.payment_date, ca.payment_amount_paid,
               ca.status,
               ca.created_at,
               COALESCE(SUM(s.duration_min), 0) AS sitzungen_min
        FROM clients c
        LEFT JOIN cases ca ON ca.id = (SELECT id FROM cases WHERE client_id = c.id ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1)
        LEFT JOIN sessions s ON s.client_id = c.id AND (ca.id IS NULL OR s.case_id = ca.id)
        GROUP BY c.id, c.first_name, c.last_name,
                 ca.id, ca.version, ca.package_type, ca.package_price, ca.hourly_rate,
                 ca.payment_status, ca.payment_date, ca.payment_amount_paid, ca.status, ca.created_at
        ORDER BY ca.created_at DESC
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('Finanzen-Datenbankfehler: ' . $e->getMessage());
    http_response_code(500);
    $_error_title = 'Datenbankfehler';
    $_error_message = 'Datenbankfehler beim Laden der Finanzdaten. Bitte Seite neu laden oder Administrator kontaktieren.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// ── Aggregat berechnen ────────────────────────────────────────────────────────

$gesamt_soll     = 0.0;
$gesamt_beglichen = 0.0;
$gesamt_offen    = 0.0;
$anzahl_teilweise = 0;

foreach ($klienten as $k) {
    $soll = null;
    if ($k['package_type'] === 'paket' && $k['package_price'] !== null) {
        $soll = (float)$k['package_price'];
    } elseif ($k['package_type'] === 'einzel' && $k['hourly_rate'] !== null && $k['sitzungen_min'] > 0) {
        $soll = round((float)$k['hourly_rate'] * ($k['sitzungen_min'] / 60), 2);
    }

    if ($soll !== null) {
        $gesamt_soll += $soll;
        $status  = $k['payment_status'] ?? 'offen';
        $bezahlt = $k['payment_amount_paid'] !== null ? (float)$k['payment_amount_paid'] : null;
        $teile   = zahlung_aufteilen($soll, (string)$status, $bezahlt);
        $gesamt_beglichen += $teile['eingang'];
        $gesamt_offen     += $teile['offen'];
        if ($status === 'teilweise') {
            $anzahl_teilweise++;
        }
    }
}
$page_title = 'MPU-Manager | Finanzen';
require 'partials/_head.php';
$active_page = 'finanzen';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Verwaltung</div>
                            <h1 class="page-title">Honorar-Übersicht</h1>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">

                    <!-- Aggregat-Karten -->
                    <div class="row row-deck row-cards mb-4">
                        <div class="col-sm-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3">Gesamt-Soll</div>
                                    <div class="h1 mb-0"><?= number_format($gesamt_soll, 2, ',', '.') ?> €</div>
                                    <div class="text-secondary small mt-1">kalkulierbare Fälle</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3 text-green">Beglichen</div>
                                    <div class="h1 mb-0 text-green"><?= number_format($gesamt_beglichen, 2, ',', '.') ?> €</div>
                                    <?php if ($gesamt_soll > 0): ?>
                                        <div class="text-secondary small mt-1"><?= round($gesamt_beglichen / $gesamt_soll * 100) ?> % des Solls</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader mb-3 text-red">Offen / Ausstehend</div>
                                    <div class="h1 mb-0 text-red"><?= number_format($gesamt_offen, 2, ',', '.') ?> €</div>
                                    <?php if ($anzahl_teilweise > 0): ?>
                                        <div class="text-secondary small mt-1">+ <?= $anzahl_teilweise ?> Klient<?= $anzahl_teilweise !== 1 ? 'en' : '' ?> teilweise beglichen</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Klientenliste -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Einzelübersicht</h3>
                            <div class="card-actions">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Status filtern">
                                    <button type="button" class="btn btn-outline-secondary pay-filter active" data-filter="alle">Alle</button>
                                    <button type="button" class="btn btn-outline-secondary pay-filter" data-filter="offen">Offen</button>
                                    <button type="button" class="btn btn-outline-secondary pay-filter" data-filter="teilweise">Teilweise</button>
                                    <button type="button" class="btn btn-outline-secondary pay-filter" data-filter="beglichen">Beglichen</button>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th>Klient</th>
                                        <th>Modell</th>
                                        <th class="text-end">Honorar (Soll)</th>
                                        <th>Zahlungs-Status</th>
                                        <th>Bezahlt / Datum</th>
                                        <th class="w-1"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($klienten as $k):
                                    // Soll berechnen
                                    $soll = null;
                                    $soll_label = '–';
                                    if ($k['package_type'] === 'paket' && $k['package_price'] !== null) {
                                        $soll = (float)$k['package_price'];
                                        $soll_label = number_format($soll, 2, ',', '.') . ' €';
                                    } elseif ($k['package_type'] === 'einzel' && $k['hourly_rate'] !== null) {
                                        if ($k['sitzungen_min'] > 0) {
                                            $soll = round((float)$k['hourly_rate'] * ($k['sitzungen_min'] / 60), 2);
                                            $std  = round($k['sitzungen_min'] / 60, 1);
                                            $soll_label = number_format($soll, 2, ',', '.') . ' € (' . number_format($std, 1, ',', '.') . ' Std.)';
                                        } else {
                                            $soll_label = number_format((float)$k['hourly_rate'], 2, ',', '.') . ' €/Std. (0 Std.)';
                                        }
                                    }

                                    $pay_status = $k['payment_status'] ?? 'offen';
                                    $pay_cfg = [
                                        'offen'     => ['bg-danger-lt',  'Offen'],
                                        'teilweise' => ['bg-warning-lt', 'Teilweise beglichen'],
                                        'beglichen' => ['bg-success-lt', 'Beglichen'],
                                    ];
                                    [$pay_bg, $pay_lbl] = $pay_cfg[$pay_status];
                                ?>
                                <tr class="pay-row" data-status="<?= htmlspecialchars($pay_status) ?>" data-client-id="<?= (int)$k['id'] ?>">
                                    <td>
                                        <a href="akte.php?id=<?= (int)$k['id'] ?>" class="fw-medium text-reset">
                                            <?= htmlspecialchars($k['first_name'] . ' ' . $k['last_name']) ?>
                                        </a>
                                        <?php if (($k['status'] ?? 'aktiv') === 'abgeschlossen'): ?>
                                            <span class="badge bg-secondary-lt ms-1">Abgeschlossen</span>
                                        <?php endif; ?>
                                        <div class="text-secondary small">#<?= str_pad((string)$k['id'], 5, '0', STR_PAD_LEFT) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($k['package_type'] === 'paket'): ?>
                                            <span class="badge bg-cyan-lt">Paket</span>
                                        <?php elseif ($k['package_type'] === 'einzel'): ?>
                                            <span class="badge bg-teal-lt">Einzel</span>
                                            <?php if ($k['hourly_rate']): ?>
                                                <div class="text-secondary small"><?= number_format((float)$k['hourly_rate'], 2, ',', '.') ?> €/Std.</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-secondary">–</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-medium"><?= $soll_label ?></td>
                                    <td>
                                        <!-- Inline-Dropdown Zahlungsstatus -->
                                        <select class="form-select form-select-sm pay-status-select min-w-9"
                                            data-client-id="<?= (int)$k['id'] ?>"
                                            data-case-id="<?= (int)$k['case_id'] ?>"
                                            data-case-version="<?= (int)$k['case_version'] ?>"
                                            data-csrf="<?= htmlspecialchars($csrf_token) ?>"
                                            data-soll="<?= $soll !== null ? htmlspecialchars((string)$soll) : '' ?>">
                                            <option value="offen"     <?= $pay_status === 'offen'     ? 'selected' : '' ?>>Offen</option>
                                            <option value="teilweise" <?= $pay_status === 'teilweise' ? 'selected' : '' ?>>Teilweise</option>
                                            <option value="beglichen" <?= $pay_status === 'beglichen' ? 'selected' : '' ?>>Beglichen</option>
                                        </select>
                                        <!-- Betrag-Feld (nur bei teilweise/beglichen sichtbar) -->
                                        <div class="pay-amount-wrap mt-1" style="display:<?= in_array($pay_status, ['teilweise','beglichen']) ? '' : 'none' ?>;">
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.01" min="0" class="form-control pay-amount-input"
                                                    placeholder="Bezahlt (€)"
                                                    value="<?= htmlspecialchars($k['payment_amount_paid'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                <span class="input-group-text">&euro;</span>
                                            </div>
                                            <?php if ($soll !== null && $k['payment_amount_paid'] !== null): ?>
                                            <div class="text-secondary small mt-1 pay-rest-label">
                                                Rest: <?= number_format(max(0, $soll - (float)$k['payment_amount_paid']), 2, ',', '.') ?> €
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-secondary">
                                        <input type="date" class="form-control form-control-sm pay-date-input min-w-8"
                                            value="<?= htmlspecialchars($k['payment_date'] ?? '') ?>"
                                            data-client-id="<?= (int)$k['id'] ?>">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary pay-save-btn"
                                            data-client-id="<?= (int)$k['id'] ?>"
                                            data-case-id="<?= (int)$k['case_id'] ?>"
                                            data-case-version="<?= (int)$k['case_version'] ?>"
                                            data-csrf="<?= htmlspecialchars($csrf_token) ?>"
                                            title="Speichern"
                                            aria-label="Zahlungsdaten speichern">
                                            <i class="ti ti-device-floppy" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($klienten)): ?>
                                    <tr><td colspan="6" class="text-center text-secondary py-4">Keine Klienten erfasst.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
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
        // Filter-Buttons
        const filterBtns = document.querySelectorAll('.pay-filter');
        const rows       = document.querySelectorAll('.pay-row');
        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                filterBtns.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                const filter = btn.dataset.filter;
                rows.forEach(function (row) {
                    row.style.display = (filter === 'alle' || row.dataset.status === filter) ? '' : 'none';
                });
            });
        });

        // Betrag-Wrap ein-/ausblenden wenn Status wechselt
        document.querySelectorAll('.pay-status-select').forEach(function (sel) {
            sel.addEventListener('change', function () {
                var wrap = this.closest('td').querySelector('.pay-amount-wrap');
                if (wrap) wrap.style.display = (this.value === 'teilweise' || this.value === 'beglichen') ? '' : 'none';
            });
        });

        // Speichern-Button: AJAX POST
        document.querySelectorAll('.pay-save-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var row      = this.closest('tr');
                var clientId = this.dataset.clientId;
                var csrf     = this.dataset.csrf;
                var sel      = row.querySelector('.pay-status-select');
                var amtInput = row.querySelector('.pay-amount-input');
                var dtInput  = row.querySelector('.pay-date-input');
                var self     = this;
                self.disabled = true;

                var body = new FormData();
                body.append('action',        'update_payment');
                body.append('csrf_token',     csrf);
                body.append('client_id',      clientId);
                body.append('case_id',        self.dataset.caseId);
                body.append('case_version',   self.dataset.caseVersion);
                body.append('payment_status', sel ? sel.value : 'offen');
                if (amtInput && amtInput.value !== '') { body.append('payment_amount_paid', amtInput.value); }
                if (dtInput  && dtInput.value  !== '') { body.append('payment_date',        dtInput.value); }

                fetch('finanzen.php', {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                    body: body
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    self.disabled = false;
                    if (data.error === 'konflikt') {
                        window.alert('Der Fall wurde inzwischen geändert. Bitte die Seite neu laden und die Zahlung erneut eintragen.');
                        return;
                    }
                    if (data.ok) {
                        self.dataset.caseVersion = data.version;
                        row.dataset.status = data.new_status;
                        row.classList.add('table-success');
                        setTimeout(function () { row.classList.remove('table-success'); }, 1200);
                        var sollRaw = sel ? sel.dataset.soll : '';
                        var soll    = parseFloat(sollRaw);
                        var paid    = amtInput ? parseFloat(amtInput.value) : NaN;
                        var restEl  = row.querySelector('.pay-rest-label');
                        if (restEl && !isNaN(soll) && !isNaN(paid)) {
                            var rest = Math.max(0, soll - paid);
                            restEl.textContent = 'Rest: ' + rest.toFixed(2).replace('.', ',') + ' \u20ac';
                        }
                    }
                })
                .catch(function () { self.disabled = false; });
            });
        });
    })();
    </script>
</body>
</html>
