<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'logic/mpu_helper.php';

$client_id = (int)($_GET['client_id'] ?? 0);
if ($client_id <= 0) {
    http_response_code(400);
    $_error_title = 'Ungültige Anfrage';
    $_error_message = 'Kein Klient ausgewählt.';
    require __DIR__ . '/partials/error.php';
    exit;
}

$fehlermeldung = '';

// ── Klient laden (Header + Existenzprüfung) ───────────────────────────────────
$stmt_client = $pdo->prepare("SELECT id, first_name, last_name FROM clients WHERE id = ?");
$stmt_client->execute([$client_id]);
$client = $stmt_client->fetch();

if (!$client) {
    http_response_code(404);
    $_error_title = 'Klient nicht gefunden';
    $_error_message = 'Der Klient konnte in der Datenbank nicht gefunden werden.';
    require __DIR__ . '/partials/error.php';
    exit;
}

// Erst nach der Existenzprüfung: Eine Sitzung zu einem unbekannten Klienten scheiterte sonst am Foreign Key.
require __DIR__ . '/handlers/sitzungen_handler.php';

// ── Sitzungen laden (alle, neueste zuerst) ────────────────────────────────────
$stmt_sessions = $pdo->prepare(
    "SELECT id, session_date, duration_min, phase, notes
     FROM sessions WHERE client_id = ?
     ORDER BY session_date DESC, id DESC"
);
$stmt_sessions->execute([$client_id]);
$sessions = $stmt_sessions->fetchAll();

$gesamt_min       = (int)array_sum(array_column($sessions, 'duration_min'));
$anzahl_sitzungen = count($sessions);

function sitzungen_dauer_text(int $min): string
{
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h > 0 && $m > 0) { return "{$h} h {$m} min"; }
    if ($h > 0) { return "{$h} h"; }
    return "{$m} min";
}

// ── Sticky Formularwerte (bei Validierungsfehler das Formular offen lassen) ──
$form_hat_fehler = $fehlermeldung !== '';
$form_action     = $form_hat_fehler ? (string)($_POST['action'] ?? 'add_session') : 'add_session';
$form_session_id = $form_hat_fehler ? (int)($_POST['session_id'] ?? 0) : 0;
$form_date       = $form_hat_fehler ? (string)($_POST['session_date'] ?? '') : date('Y-m-d');
$form_duration   = $form_hat_fehler ? (string)($_POST['duration_min'] ?? '60') : '60';
$form_phase      = $form_hat_fehler ? (string)($_POST['phase'] ?? 'anamnese') : 'anamnese';
$form_notes      = $form_hat_fehler ? (string)($_POST['notes'] ?? '') : '';
$form_heading    = $form_action === 'edit_session' ? 'Sitzung bearbeiten' : 'Neue Sitzung';
$form_submit_txt = $form_action === 'edit_session' ? 'Änderungen speichern' : 'Speichern';

$klient_name = trim($client['first_name'] . ' ' . $client['last_name']);
$page_title  = 'MPU-Manager | Sitzungsprotokoll – ' . $klient_name;
require 'partials/_head.php';
$active_page = 'akte';
require 'partials/_navbar.php';
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Sitzungsprotokoll</div>
                            <h1 class="page-title"><?= htmlspecialchars($klient_name) ?></h1>
                        </div>
                        <div class="col-auto">
                            <div class="btn-list">
                                <a href="anleitung.php#sitzungen" class="btn btn-outline-secondary" title="Erläuterungen zur Sitzungsdokumentation im Handbuch">
                                    <i class="ti ti-book me-1" aria-hidden="true"></i>Handbuch
                                </a>
                                <a href="akte.php?id=<?= (int)$client_id ?>" class="btn btn-outline-secondary">
                                    <i class="ti ti-arrow-left me-1" aria-hidden="true"></i>Zur Akte
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">

                    <?php require __DIR__ . '/partials/flash.php'; ?>

                    <?php if ($fehlermeldung !== ''): ?>
                    <div class="alert alert-danger" role="alert">
                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                        <div><?= htmlspecialchars($fehlermeldung) ?></div>
                    </div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title mb-0">
                                Sitzungen
                                <span class="text-secondary fw-normal ms-2">
                                    <?= $anzahl_sitzungen ?> Sitzung<?= $anzahl_sitzungen === 1 ? '' : 'en' ?>
                                    &middot; <?= htmlspecialchars(sitzungen_dauer_text($gesamt_min)) ?> gesamt
                                </span>
                            </h3>
                            <div class="card-actions">
                                <button type="button" id="sitzung-form-neu" class="btn btn-primary btn-sm"
                                        data-bs-toggle="collapse" data-bs-target="#sitzung-form"
                                        aria-expanded="<?= $form_hat_fehler ? 'true' : 'false' ?>" aria-controls="sitzung-form">
                                    <i class="ti ti-plus me-1" aria-hidden="true"></i>Neue Sitzung
                                </button>
                            </div>
                        </div>

                        <div class="collapse<?= $form_hat_fehler ? ' show' : '' ?>" id="sitzung-form">
                            <div class="card-body border-bottom bg-body-secondary">
                                <h4 id="sitzung-form-heading" class="mb-3"><?= htmlspecialchars($form_heading) ?></h4>
                                <form method="POST" action="sitzungen.php?client_id=<?= (int)$client_id ?>" id="sitzung-form-el">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="action" id="sitzung-action" value="<?= htmlspecialchars($form_action) ?>">
                                    <input type="hidden" name="session_id" id="sitzung-session-id" value="<?= $form_session_id > 0 ? (int)$form_session_id : '' ?>">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label" for="session_date">Datum</label>
                                            <input type="date" class="form-control" id="session_date" name="session_date"
                                                   value="<?= htmlspecialchars($form_date) ?>" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" for="duration_min">Dauer (min)</label>
                                            <input type="number" class="form-control" id="duration_min" name="duration_min"
                                                   value="<?= htmlspecialchars($form_duration) ?>" min="15" max="480" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label" for="phase">Phase</label>
                                            <select class="form-select" id="phase" name="phase" required>
                                                <?php foreach (['anamnese' => 'Anamnese', 'analyse' => 'Analyse', 'check' => 'Check'] as $val => $label): ?>
                                                    <option value="<?= $val ?>"<?= $form_phase === $val ? ' selected' : '' ?>><?= $label ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-5">
                                            <label class="form-label" for="notes">Notizen</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="4"><?= htmlspecialchars($form_notes) ?></textarea>
                                            <div class="form-hint">Die SORKC-Strukturanalyse wird in der Klientenakte (Tab „Sitzungen") erfasst und bleibt hier unverändert.</div>
                                        </div>
                                    </div>
                                    <div class="mt-3 d-flex gap-2">
                                        <button type="submit" id="sitzung-form-submit" class="btn btn-primary">
                                            <?= htmlspecialchars($form_submit_txt) ?>
                                        </button>
                                        <button type="button" id="sitzung-form-cancel" class="btn btn-outline-secondary"
                                                data-bs-toggle="collapse" data-bs-target="#sitzung-form">
                                            Abbrechen
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-vcenter table-mobile-md card-table">
                                <thead>
                                    <tr>
                                        <th>Datum</th>
                                        <th>Dauer</th>
                                        <th>Phase</th>
                                        <th>Notizen</th>
                                        <th class="w-1">Aktionen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($sessions)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-secondary py-4">
                                            <i class="ti ti-calendar-off me-2" aria-hidden="true"></i>
                                            Noch keine Sitzungen erfasst.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php foreach ($sessions as $s):
                                        $p_colors  = ['anamnese' => 'bg-blue-lt', 'analyse' => 'bg-orange-lt', 'check' => 'bg-green-lt'];
                                        $p_color   = $p_colors[$s['phase']] ?? 'bg-secondary-lt';
                                        $notiz_voll = (string)($s['notes'] ?? '');
                                        $notiz_kurz = mb_strlen($notiz_voll) > 150 ? mb_substr($notiz_voll, 0, 150) . '…' : $notiz_voll;
                                    ?>
                                    <tr>
                                        <td data-label="Datum"><?= htmlspecialchars(date('d.m.Y', strtotime($s['session_date']))) ?></td>
                                        <td data-label="Dauer" class="text-secondary"><?= htmlspecialchars(sitzungen_dauer_text((int)$s['duration_min'])) ?></td>
                                        <td data-label="Phase"><span class="badge <?= $p_color ?>"><?= htmlspecialchars(ucfirst($s['phase'])) ?></span></td>
                                        <td data-label="Notizen" class="text-secondary small">
                                            <?php if ($notiz_voll !== ''): ?>
                                                <span title="<?= htmlspecialchars($notiz_voll) ?>"><?= htmlspecialchars($notiz_kurz) ?></span>
                                            <?php else: ?>
                                                –
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Aktionen" class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-ghost-secondary js-sitzung-bearbeiten"
                                                    title="Bearbeiten" aria-label="Sitzung vom <?= htmlspecialchars(date('d.m.Y', strtotime($s['session_date']))) ?> bearbeiten"
                                                    data-id="<?= (int)$s['id'] ?>"
                                                    data-date="<?= htmlspecialchars($s['session_date']) ?>"
                                                    data-duration="<?= (int)$s['duration_min'] ?>"
                                                    data-phase="<?= htmlspecialchars($s['phase']) ?>"
                                                    data-notes="<?= htmlspecialchars($notiz_voll) ?>">
                                                <i class="ti ti-pencil" aria-hidden="true"></i>
                                            </button>
                                            <form method="POST" action="sitzungen.php?client_id=<?= (int)$client_id ?>" class="d-inline"
                                                  data-confirm="Sitzung vom <?= htmlspecialchars(date('d.m.Y', strtotime($s['session_date']))) ?> löschen?">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="action" value="delete_session">
                                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-ghost-danger" title="Löschen" aria-label="Sitzung löschen">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </main>

<?php require 'partials/_footer.php'; ?>
        </div>
    </div>

<!-- ══ MODAL: LÖSCH-BESTÄTIGUNG ═══════════════════════════════════════════════ -->
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
            _pendingForm.dataset.confirm = '';
            _pendingForm.submit();
            _pendingForm = null;
        }
    });

    document.getElementById('modal-bestaetigung').addEventListener('hidden.bs.modal', function () {
        if (_pendingForm) { _pendingForm = null; }
    });
}());

// ── Sitzungsformular: Bearbeiten-Buttons befüllen es, Abbrechen setzt es zurück ──
(function () {
    var collapseEl = document.getElementById('sitzung-form');
    var collapse   = new bootstrap.Collapse(collapseEl, { toggle: false });
    var actionEl   = document.getElementById('sitzung-action');
    var idEl       = document.getElementById('sitzung-session-id');
    var dateEl     = document.getElementById('session_date');
    var durationEl = document.getElementById('duration_min');
    var phaseEl    = document.getElementById('phase');
    var notesEl    = document.getElementById('notes');
    var headingEl  = document.getElementById('sitzung-form-heading');
    var submitEl   = document.getElementById('sitzung-form-submit');
    var todayIso   = <?= json_encode(date('Y-m-d')) ?>;

    function resetForm() {
        actionEl.value   = 'add_session';
        idEl.value       = '';
        dateEl.value     = todayIso;
        durationEl.value = '60';
        phaseEl.value    = 'anamnese';
        notesEl.value    = '';
        headingEl.textContent = 'Neue Sitzung';
        submitEl.textContent  = 'Speichern';
    }

    document.querySelectorAll('.js-sitzung-bearbeiten').forEach(function (btn) {
        btn.addEventListener('click', function () {
            actionEl.value   = 'edit_session';
            idEl.value       = btn.dataset.id;
            dateEl.value     = btn.dataset.date;
            durationEl.value = btn.dataset.duration;
            phaseEl.value    = btn.dataset.phase;
            notesEl.value    = btn.dataset.notes;
            headingEl.textContent = 'Sitzung bearbeiten';
            submitEl.textContent  = 'Änderungen speichern';
            collapse.show();
            dateEl.focus();
        });
    });

    document.getElementById('sitzung-form-neu').addEventListener('click', function () {
        if (actionEl.value === 'edit_session') { resetForm(); }
    });

    document.getElementById('sitzung-form-cancel').addEventListener('click', resetForm);
}());
</script>
</body>
</html>
