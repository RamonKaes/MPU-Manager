<?php

declare(strict_types=1);

require_once 'db.php';
require_once __DIR__ . '/config/allowed_values.php';
require_once __DIR__ . '/logic/mpu_helper.php';

// ── POST-Verarbeitung (zweistufig: Vorschau → Ausführen) ──────────────────────
$modus    = 'uebersicht'; // 'uebersicht' | 'vorschau'
$selected  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title   = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/partials/error.php';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // Ausgewählte IDs normalisieren …
    $selected = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['client_ids'] ?? [])),
        static fn(int $id): bool => $id > 0
    )));

    // … und gegen die tatsächlich fälligen Klienten absichern (Defense-in-Depth:
    // ein nicht-fälliger oder aktiver Klient kann so niemals gelöscht werden).
    $faellige_ids = array_map(static fn(array $k): int => (int)$k['id'], dsgvo_faellige_klienten($pdo));
    $selected     = array_values(array_intersect($selected, $faellige_ids));

    if ($action === 'confirm_preview') {
        if ($selected === []) {
            set_flash('warning', 'Keine löschbaren Klienten ausgewählt.');
            header('Location: dsgvo_loeschen.php');
            exit;
        }
        $modus = 'vorschau';
    } elseif ($action === 'execute') {
        if ($selected === []) {
            set_flash('warning', 'Keine löschbaren Klienten ausgewählt.');
            header('Location: dsgvo_loeschen.php');
            exit;
        }
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM clients WHERE id = ?");
            foreach ($selected as $cid) {
                // R3-1: audit_log hat keinen FK auf clients und kaskadiert daher
                // nicht – der Beleg bleibt erhalten, seine Freitexte
                // trügen sonst aber Namen und Art.-9-Daten über die Löschung
                // hinaus. Reihenfolge: anonymisieren → löschen → belegen.
                // Der Löschbeleg entsteht zuletzt, sonst räumt ihn die
                // Anonymisierung im selben Durchlauf gleich wieder ab.
                $audit_bereinigt = audit_log_anonymisieren($pdo, $cid);
                $del->execute([$cid]);
                audit_log($pdo, 'dsgvo_delete', 'clients', $cid, $cid,
                    "DSGVO-Löschung nach Fristablauf; {$audit_bereinigt} Audit-Beschreibung(en) anonymisiert");
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        set_flash('success', count($selected) . ' Klient(en) aus der Datenbank gelöscht (inkl. aller verknüpften Daten). Sicherungen enthalten sie bis zu ihrer Rotation.');
        header('Location: dsgvo_loeschen.php');
        exit;
    }
}

// ── Fällige Klienten laden (Übersicht + Vorschau) ─────────────────────────────
$faellige = dsgvo_faellige_klienten($pdo);
$vorschau = $modus === 'vorschau'
    ? array_values(array_filter($faellige, static fn(array $k): bool => in_array((int)$k['id'], $selected, true)))
    : [];

$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

$page_title = 'DSGVO-Löschung – MPU-Manager';
require 'partials/_head.php';
$active_page = 'dsgvo_loeschen';
require 'partials/_navbar.php';

?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Datenschutz</div>
                            <h1 class="page-title">DSGVO-Löschung</h1>
                            <p class="text-secondary mb-0">Klienten mit abgelaufener Aufbewahrungsfrist (<?= (int) DSGVO_AUFBEWAHRUNG_JAHRE ?> Jahre, Frist des Betreibers) endgültig löschen.</p>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    <?php require __DIR__ . '/partials/flash.php'; ?>

                    <div class="alert alert-info" role="alert">
                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                        <div>
                            <h4 class="alert-heading">So funktioniert die Löschung</h4>
                            <div class="alert-description">
                                Angezeigt werden nur Klienten, deren <strong>Fälle alle abgeschlossen</strong> sind und deren
                                letzter Fallabschluss länger als <strong><?= (int) DSGVO_AUFBEWAHRUNG_JAHRE ?> Jahre</strong> zurückliegt.
                                Beim Löschen werden <strong>alle verknüpften Daten</strong> (Fälle, Sitzungen, Abstinenz-Proben,
                                Aufgaben, Behörden-Events, Leistungstests) mitgelöscht. Im Audit-Log bleibt der
                                <strong>Nachweis des Löschvorgangs</strong> erhalten; die dortigen Klartext-Beschreibungen
                                (Name, Hypothese, Befunde) werden dabei <strong>entfernt</strong>.
                                <strong>Diese Aktion ist unwiderruflich.</strong>
                            </div>
                        </div>
                    </div>

                    <?php if ($modus === 'vorschau'): ?>

                    <!-- SCHRITT 2: Vorschau / Bestätigung -->
                    <div class="card border-danger">
                        <div class="card-header bg-danger-lt">
                            <h2 class="card-title"><i class="ti ti-alert-triangle me-2 text-danger" aria-hidden="true"></i>Löschung bestätigen</h2>
                        </div>
                        <div class="card-body">
                            <p>Die folgenden <strong><?= count($vorschau) ?> Klient(en)</strong> und <strong>alle</strong> ihre verknüpften Datensätze werden endgültig gelöscht:</p>
                            <div class="table-responsive">
                                <table class="table table-vcenter">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Letzter Abschluss</th>
                                            <th class="text-center">Sitzungen</th>
                                            <th class="text-center">Abstinenz-Proben</th>
                                            <th class="text-center">Audit-Einträge</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($vorschau as $k): ?>
                                        <tr>
                                            <td><strong><?= $e($k['last_name'] . ', ' . $k['first_name']) ?></strong></td>
                                            <td><?= $k['letzter_abschluss'] ? $e(date('d.m.Y', strtotime((string)$k['letzter_abschluss']))) : '–' ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_sitzungen'] ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_proben'] ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_audit'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <form method="POST" action="dsgvo_loeschen.php" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= $e($csrf_token) ?>">
                                <input type="hidden" name="action" value="execute">
                                <?php foreach ($vorschau as $k): ?>
                                <input type="hidden" name="client_ids[]" value="<?= (int)$k['id'] ?>">
                                <?php endforeach; ?>
                                <div class="btn-list">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="ti ti-trash me-2" aria-hidden="true"></i><?= count($vorschau) ?> Klient(en) endgültig löschen
                                    </button>
                                    <a href="dsgvo_loeschen.php" class="btn btn-link">Abbrechen</a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php elseif ($faellige === []): ?>

                    <!-- Keine fälligen Klienten -->
                    <div class="card">
                        <div class="card-body">
                            <div class="empty">
                                <div class="empty-icon"><i class="ti ti-checks text-success" aria-hidden="true"></i></div>
                                <p class="empty-title">Keine fälligen Klienten</p>
                                <p class="empty-subtitle text-secondary">
                                    Derzeit hat kein Klient die Aufbewahrungsfrist von <?= (int) DSGVO_AUFBEWAHRUNG_JAHRE ?> Jahren überschritten.
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php else: ?>

                    <!-- SCHRITT 1: Übersicht mit Auswahl -->
                    <form method="POST" action="dsgvo_loeschen.php">
                        <input type="hidden" name="csrf_token" value="<?= $e($csrf_token) ?>">
                        <input type="hidden" name="action" value="confirm_preview">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title"><?= count($faellige) ?> fällige(r) Klient(en)</h2>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-vcenter card-table">
                                    <thead>
                                        <tr>
                                            <th class="w-1"></th>
                                            <th>Name</th>
                                            <th>Letzter Abschluss</th>
                                            <th class="text-center">Sitzungen</th>
                                            <th class="text-center">Abstinenz-Proben</th>
                                            <th class="text-center">Audit-Einträge</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($faellige as $k): ?>
                                        <tr>
                                            <td>
                                                <label class="form-check mb-0">
                                                    <input class="form-check-input" type="checkbox" name="client_ids[]" value="<?= (int)$k['id'] ?>" aria-label="Klient <?= $e($k['last_name'] . ', ' . $k['first_name']) ?> zur Löschung auswählen">
                                                </label>
                                            </td>
                                            <td><strong><?= $e($k['last_name'] . ', ' . $k['first_name']) ?></strong></td>
                                            <td><?= $k['letzter_abschluss'] ? $e(date('d.m.Y', strtotime((string)$k['letzter_abschluss']))) : '–' ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_sitzungen'] ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_proben'] ?></td>
                                            <td class="text-center"><?= (int)$k['anzahl_audit'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer">
                                <button type="submit" class="btn btn-danger">
                                    <i class="ti ti-trash me-2" aria-hidden="true"></i>Auswahl prüfen und löschen …
                                </button>
                                <span class="text-secondary ms-2 small">Nur angehakte Klienten werden in die Vorschau übernommen.</span>
                            </div>
                        </div>
                    </form>

                    <?php endif; ?>
                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>

        </div><!-- /.page-wrapper -->
    </div><!-- /.page -->

    <script src="assets/js/tabler.min.js"></script>
</body>
</html>
