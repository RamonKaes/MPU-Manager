<?php
declare(strict_types=1);

require_once __DIR__ . '/../logic/validator.php';

/** Eingaben eines abgewiesenen Formulars für das nächste Laden der Akte merken (akte.php füllt sie ein). */
function akte_eingaben_merken(string $aktion): void
{
    $felder = array_filter($_POST, static fn($wert, $feld) => is_string($wert) && !in_array($feld, ['csrf_token', 'action'], true), ARRAY_FILTER_USE_BOTH);
    $_SESSION['akte_eingaben'] = ['aktion' => $aktion, 'felder' => $felder];
}

// ── POST-Handler: Neue Fallakte anlegen ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'new_case') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    try {
        $pdo->beginTransaction();
        // Doppel-Absenden: Das Formular nennt den aktiven Fall, den es gesehen hat. Ist das nicht mehr
        // der aktive Fall, hat eine frühere Absendung schon einen neuen angelegt. Die Sperre auf dem
        // Klienten reiht gleichzeitige Absendungen hintereinander.
        $pdo->prepare('SELECT id FROM clients WHERE id = ? FOR UPDATE')->execute([$klienten_identifikation]);
        $existing = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND status = 'aktiv' ORDER BY created_at DESC, id DESC LIMIT 1");
        $existing->execute([$klienten_identifikation]);
        $existing_id = (int)$existing->fetchColumn();
        if ((int)($_POST['aktiver_fall_id'] ?? -1) !== $existing_id) {
            $pdo->rollBack();
            set_flash('warning', 'Es wurde keine weitere Fallakte angelegt: Der Stand hatte sich seit dem Laden der Seite geändert.');
            header("Location: akte.php?id={$klienten_identifikation}");
            exit;
        }
        // Aktive Fälle archivieren (IDs vorher holen für Audit-Einträge)
        $aktive = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND status = 'aktiv'");
        $aktive->execute([$klienten_identifikation]);
        $aktive_ids = $aktive->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->prepare("UPDATE cases SET status = 'abgeschlossen', closed_at = COALESCE(closed_at, NOW()), version = version + 1 WHERE client_id = ? AND status = 'aktiv'")
            ->execute([$klienten_identifikation]);
        foreach ($aktive_ids as $arch_id) {
            audit_log($pdo, 'archive_case', 'cases', (int)$arch_id, $klienten_identifikation, 'Fallakte archiviert (neue Fallakte angelegt)');
        }
        // Neuen leeren Fall anlegen
        $pdo->prepare("INSERT INTO cases (client_id, status) VALUES (?, 'aktiv')")
            ->execute([$klienten_identifikation]);
        $new_case_id = (int)$pdo->lastInsertId();
        audit_log($pdo, 'new_case', 'cases', $new_case_id, $klienten_identifikation, 'Neue Fallakte angelegt');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        $_error_title = 'Datenbankfehler';
        $_error_message = 'Fehler beim Anlegen der Fallakte. Bitte versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    header("Location: bearbeiten.php?id={$klienten_identifikation}&case_id={$new_case_id}");
    exit;
}

// ── POST-Handler: Klient löschen ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    try {
        $pdo->beginTransaction();
        // R3-1: erst die personenbezogenen Freitexte im Audit-Log entfernen –
        // es kaskadiert nicht mit und behielte sonst Namen und Art.-9-Daten.
        // Der Löschbeleg wird danach geschrieben, sonst löscht er sich selbst.
        $audit_bereinigt = audit_log_anonymisieren($pdo, $klienten_identifikation);
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$klienten_identifikation]);
        audit_log($pdo, 'delete_client', 'clients', $klienten_identifikation, $klienten_identifikation,
            "Klient und alle Falldaten gelöscht; {$audit_bereinigt} Audit-Beschreibung(en) anonymisiert");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        $_error_title = 'Datenbankfehler';
        $_error_message = 'Fehler beim Löschen des Klienten. Bitte versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    set_flash('warning', 'Klient und alle zugehörigen Daten wurden aus der Datenbank gelöscht. Sicherungen enthalten sie bis zu ihrer Rotation.');
    header("Location: klienten.php");
    exit;
}

// ── POST-Handler: Einwilligung nachtragen (z. B. nach telefonischer Aufnahme) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'consent_set') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $einwilligung_datum = trim((string)($_POST['consent_date'] ?? ''));
    if (!validate_probe_date($einwilligung_datum)) {
        set_flash('danger', 'Einwilligung nicht gespeichert: Bitte ein gültiges Datum angeben, das nicht in der Zukunft liegt.');
        header("Location: akte.php?id={$klienten_identifikation}");
        exit;
    }
    $pdo->beginTransaction();
    $eintrag = $pdo->prepare('UPDATE clients SET dsgvo_consent = 1, dsgvo_consent_date = ?, version = version + 1 WHERE id = ? AND dsgvo_consent = 0');
    $eintrag->execute([$einwilligung_datum, $klienten_identifikation]);
    if ($eintrag->rowCount() > 0) {
        audit_log($pdo, 'consent_set', 'clients', $klienten_identifikation, $klienten_identifikation, "Einwilligung nachgetragen, unterzeichnet am {$einwilligung_datum}");
    }
    $pdo->commit();
    set_flash('success', 'Einwilligung wurde eingetragen.');
    header("Location: akte.php?id={$klienten_identifikation}");
    exit;
}

// ── POST-Handler: DSGVO-Widerruf bestätigen + Lösch-Workflow ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revocation_confirm') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE clients SET revocation_confirmed = 1 WHERE id = ?")
        ->execute([$klienten_identifikation]);
    audit_log($pdo, 'revocation_confirm', 'clients', $klienten_identifikation, $klienten_identifikation, 'Widerruf der DSGVO-Einwilligung bestätigt');
    $pdo->commit();
    set_flash('warning', 'Widerruf der Datenschutz-Einwilligung wurde bestätigt.');
    header("Location: akte.php?id={$klienten_identifikation}");
    exit;
}

// ── POST-Handler: DSGVO Art. 17 – Datenlöschung nach Widerruf ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dsgvo_delete') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    // Pflicht: Widerruf muss bereits bestätigt sein
    $rev = $pdo->prepare("SELECT revocation_confirmed FROM clients WHERE id = ?");
    $rev->execute([$klienten_identifikation]);
    if (!(int)$rev->fetchColumn()) {
        http_response_code(400);
        $_error_title = 'Löschung nicht möglich';
        $_error_message = 'Widerruf nicht bestätigt – Löschung nicht möglich.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    // Kaskadierendes Löschen (FOREIGN KEY ON DELETE CASCADE entfernt Fälle, Sitzungen, Proben, Tasks automatisch).
    // audit_log hat als einzige Tabelle mit client_id keinen FK und kaskadiert
    // nicht – R3-1: dessen Freitexte deshalb explizit vorher anonymisieren.
    try {
        $pdo->beginTransaction();
        $audit_bereinigt = audit_log_anonymisieren($pdo, $klienten_identifikation);
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$klienten_identifikation]);
        audit_log($pdo, 'dsgvo_delete', 'clients', $klienten_identifikation, $klienten_identifikation,
            "Art.-17-Löschung nach Widerruf der DSGVO-Einwilligung; {$audit_bereinigt} Audit-Beschreibung(en) anonymisiert");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        $_error_title = 'Datenbankfehler';
        $_error_message = 'Fehler bei der Datenlöschung. Bitte versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    set_flash('warning', 'Klient gemäß DSGVO Art. 17 aus der Datenbank gelöscht. Sicherungen enthalten die Daten bis zu ihrer Rotation.');
    header("Location: klienten.php");
    exit;
}

// ── POST-Handler: Korrespondenz-Eintrag hinzufügen ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_event') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $ev_case_id   = (int)($_GET['case_id'] ?? 0);
    if (!fall_gehoert_zu_klient($pdo, $ev_case_id, $klienten_identifikation)) {
        http_response_code(400);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'Der Korrespondenz-Eintrag konnte keiner Fallakte dieses Klienten zugeordnet werden.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $ev_date      = (string)($_POST['event_date'] ?? '');
    $ev_type_raw  = (string)($_POST['event_type'] ?? 'sonstiges');
    $ev_type      = in_array($ev_type_raw, ['aufforderung','antrag','widerspruch','fristverlängerung','bescheid','sonstiges'], true) ? $ev_type_raw : 'sonstiges';
    $ev_desc      = trim((string)($_POST['event_description'] ?? ''));
    if (!validate_datum($ev_date)) {
        akte_eingaben_merken('add_event');
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$ev_case_id}&error=event#korrespondenz");
        exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO case_events (case_id, client_id, event_date, event_type, description) VALUES (?, ?, ?, ?, ?)")
        ->execute([$ev_case_id ?: null, $klienten_identifikation, $ev_date, $ev_type, $ev_desc ?: null]);
    audit_log($pdo, 'add_event', 'case_events', (int)$pdo->lastInsertId(), $klienten_identifikation, "{$ev_type} / {$ev_date}");
    $pdo->commit();
    set_flash('success', 'Korrespondenz-Eintrag gespeichert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$ev_case_id}#korrespondenz");
    exit;
}

// ── POST-Handler: Korrespondenz-Eintrag löschen ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $del_ev_id     = (int)($_POST['event_id']  ?? 0);
    $del_ev_case   = (int)($_GET['case_id']    ?? 0);
    if ($del_ev_id > 0) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM case_events WHERE id = ? AND client_id = ?")
            ->execute([$del_ev_id, $klienten_identifikation]);
        audit_log($pdo, 'delete_event', 'case_events', $del_ev_id, $klienten_identifikation);
        $pdo->commit();
    }
    set_flash('success', 'Korrespondenz-Eintrag gelöscht.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$del_ev_case}#korrespondenz");
    exit;
}

// ── POST-Handler: Bulk-Aufgaben-Update ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_task_update') {
    $is_ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $bulk_new_status = (string)($_POST['bulk_status'] ?? '');
    $bulk_case_id    = (int)($_GET['case_id'] ?? 0);
    if (!in_array($bulk_new_status, ['angefordert', 'liegt_vor'], true)) {
        http_response_code(400);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$bulk_case_id}#todos");
        exit;
    }
    // Nur Aufgaben hochstufen (nicht zurück auf offen via Bulk)
    $bulk_filter = $bulk_new_status === 'liegt_vor' ? ['offen','angefordert'] : ['offen'];
    $placeholders = implode(',', array_fill(0, count($bulk_filter), '?'));
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE client_tasks SET status = ? WHERE client_id = ? AND case_id = ? AND status IN ({$placeholders})")
        ->execute(array_merge([$bulk_new_status, $klienten_identifikation, $bulk_case_id], $bulk_filter));
    audit_log($pdo, 'bulk_task_update', 'client_tasks', $bulk_case_id, $klienten_identifikation, "Alle → {$bulk_new_status}");
    $pdo->commit();
    if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit; }
    set_flash('success', 'Aufgaben-Status aktualisiert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$bulk_case_id}#todos");
    exit;
}

// ── POST-Handler: Abstinenz-Nachweis hinzufügen ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_probe') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $probe_date  = (string)($_POST['probe_date']   ?? '');
    $method      = (string)($_POST['method']       ?? '');
    $result      = (string)($_POST['result']       ?? '');
    $lab_name    = trim((string)($_POST['lab_name']    ?? ''));
    $probe_notes = trim((string)($_POST['probe_notes'] ?? ''));
    $peth_value      = isset($_POST['peth_value'])      && $_POST['peth_value']      !== '' ? (float)$_POST['peth_value']      : null;
    $kreatinin_value = isset($_POST['kreatinin_mg_dl']) && $_POST['kreatinin_mg_dl'] !== '' ? (float)$_POST['kreatinin_mg_dl'] : null;

    $probe_case_id = (int)($_GET['case_id'] ?? 0);
    if (!fall_gehoert_zu_klient($pdo, $probe_case_id, $klienten_identifikation)) {
        http_response_code(400);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'Der Abstinenznachweis konnte keiner Fallakte dieses Klienten zugeordnet werden.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    if (
        !validate_probe_date($probe_date) ||
        !validate_method($method) ||
        !validate_result($result) ||
        mb_strlen($lab_name) > 150 ||
        ($peth_value !== null && ($peth_value < 0 || $peth_value > 99999.99)) ||
        ($kreatinin_value !== null && ($kreatinin_value < 0 || $kreatinin_value > 9999.99))
    ) {
        akte_eingaben_merken('add_probe');
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$probe_case_id}&error=probe#abstinenz");
        exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO abstinence_probes (client_id, case_id, probe_date, method, result, lab_name, notes, peth_value, kreatinin_mg_dl) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$klienten_identifikation, $probe_case_id ?: null, $probe_date, $method, $result, $lab_name ?: null, $probe_notes ?: null, $peth_value, $kreatinin_value]);
    audit_log($pdo, 'add_probe', 'abstinence_probes', (int)$pdo->lastInsertId(), $klienten_identifikation, "{$method} / {$probe_date}");
    $pdo->commit();
    set_flash('success', 'Abstinenznachweis gespeichert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$probe_case_id}#abstinenz");
    exit;
}

// ── POST-Handler: Abstinenz-Nachweis löschen ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_probe') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $probe_id      = (int)($_POST['probe_id']  ?? 0);
    $del_probe_cid = (int)($_GET['case_id']    ?? 0);
    if ($probe_id > 0) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM abstinence_probes WHERE id = ? AND client_id = ?")
            ->execute([$probe_id, $klienten_identifikation]);
        audit_log($pdo, 'delete_probe', 'abstinence_probes', $probe_id, $klienten_identifikation);
        $pdo->commit();
    }
    set_flash('success', 'Abstinenznachweis gelöscht.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$del_probe_cid}#abstinenz");
    exit;
}

// ── POST-Handler: Leistungstest hinzufügen ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_performance_test') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $perf_case_id = (int)($_GET['case_id'] ?? 0);
    if (!fall_gehoert_zu_klient($pdo, $perf_case_id, $klienten_identifikation)) {
        http_response_code(400);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'Der Leistungstest konnte keiner Fallakte dieses Klienten zugeordnet werden.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $test_date    = trim((string)($_POST['test_date']  ?? ''));
    $test_name    = trim((string)($_POST['test_name']  ?? ''));
    $percentile   = (string)($_POST['percentile']      ?? '');
    $perf_notes   = trim((string)($_POST['perf_notes'] ?? ''));
    $pr_int       = (int)$percentile;

    if (
        !validate_probe_date($test_date) ||
        $test_name === '' || mb_strlen($test_name) > 100 ||
        $pr_int < 1 || $pr_int > 99 ||
        $perf_case_id <= 0
    ) {
        akte_eingaben_merken('add_performance_test');
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$perf_case_id}&error=leistung#leistungstests");
        exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO performance_tests (client_id, case_id, test_date, test_name, percentile, notes) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$klienten_identifikation, $perf_case_id, $test_date, $test_name, $pr_int, $perf_notes ?: null]);
    audit_log($pdo, 'add_performance_test', 'performance_tests', (int)$pdo->lastInsertId(), $klienten_identifikation, $test_date);
    $pdo->commit();
    set_flash('success', 'Leistungstest gespeichert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$perf_case_id}#leistungstests");
    exit;
}

// ── POST-Handler: Leistungstest löschen ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_performance_test') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $perf_id      = (int)($_POST['perf_id']  ?? 0);
    $del_perf_cid = (int)($_GET['case_id']   ?? 0);
    if ($perf_id > 0) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM performance_tests WHERE id = ? AND client_id = ?")
            ->execute([$perf_id, $klienten_identifikation]);
        audit_log($pdo, 'delete_performance_test', 'performance_tests', $perf_id, $klienten_identifikation);
        $pdo->commit();
    }
    set_flash('success', 'Leistungstest gelöscht.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$del_perf_cid}#leistungstests");
    exit;
}

// ── POST-Handler: Aufgaben-Status aktualisieren (AJAX + Fallback) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_task') {
    $is_ajax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'CSRF']); exit; }
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $task_key      = (string)($_POST['task_key'] ?? '');
    $new_status    = (string)($_POST['status']   ?? '');
    $task_case_id  = (int)($_GET['case_id']      ?? 0);
    if (!in_array($new_status, ['offen', 'angefordert', 'liegt_vor'], true) || empty($task_key)) {
        http_response_code(400);
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok' => false]); exit; }
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$task_case_id}#todos");
        exit;
    }
    $task_id_row = $pdo->prepare("SELECT id FROM client_tasks WHERE client_id = ? AND case_id = ? AND task_key = ? LIMIT 1");
    $task_id_row->execute([$klienten_identifikation, $task_case_id, $task_key]);
    $task_id = $task_id_row->fetchColumn();
    if ($task_id === false) {
        http_response_code(404);
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'task_not_found']);
            exit;
        }
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$task_case_id}#todos");
        exit;
    }
    $task_id = (int)$task_id;
    $stmt_update = $pdo->prepare("UPDATE client_tasks SET status = ? WHERE client_id = ? AND case_id = ? AND task_key = ?");
    $pdo->beginTransaction();
    $stmt_update->execute([$new_status, $klienten_identifikation, $task_case_id, $task_key]);
    if ($stmt_update->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'task_not_found']);
            exit;
        }
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$task_case_id}#todos");
        exit;
    }
    audit_log($pdo, 'update_task', 'client_tasks', $task_id, $klienten_identifikation, "{$task_key} → {$new_status}");
    $pdo->commit();
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'new_status' => $new_status]);
        exit;
    }
    set_flash('success', 'Aufgabe gespeichert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$task_case_id}#todos");
    exit;
}

// ── POST-Handler: Sitzung hinzufügen ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_session') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $session_date    = (string)($_POST['session_date']   ?? '');
    $duration_min    = max(1, (int)($_POST['duration_min']  ?? 60));
    $phase           = (string)($_POST['phase']          ?? '');
    $session_notes   = trim((string)($_POST['session_notes'] ?? ''));
    $sorkc_s         = trim((string)($_POST['sorkc_s'] ?? '')) ?: null;
    $sorkc_o         = trim((string)($_POST['sorkc_o'] ?? '')) ?: null;
    $sorkc_r         = trim((string)($_POST['sorkc_r'] ?? '')) ?: null;
    $sorkc_k         = trim((string)($_POST['sorkc_k'] ?? '')) ?: null;
    $sorkc_c         = trim((string)($_POST['sorkc_c'] ?? '')) ?: null;
    $session_case_id = (int)($_GET['case_id'] ?? 0);

    if (!fall_gehoert_zu_klient($pdo, $session_case_id, $klienten_identifikation)) {
        http_response_code(400);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'Die Sitzung konnte keiner Fallakte dieses Klienten zugeordnet werden.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    if (
        !validate_datum($session_date) ||
        $duration_min < 15 || $duration_min > 480 ||
        !in_array($phase, ['anamnese', 'analyse', 'check'], true)
    ) {
        akte_eingaben_merken('add_session');
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$session_case_id}&error=session#sitzungen");
        exit;
    }
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO sessions (client_id, case_id, session_date, duration_min, phase, notes, sorkc_s, sorkc_o, sorkc_r, sorkc_k, sorkc_c) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$klienten_identifikation, $session_case_id ?: null, $session_date, $duration_min, $phase, $session_notes ?: null, $sorkc_s, $sorkc_o, $sorkc_r, $sorkc_k, $sorkc_c]);
    audit_log($pdo, 'add_session', 'sessions', (int)$pdo->lastInsertId(), $klienten_identifikation, "{$session_date} / {$phase} / {$duration_min} min");
    $pdo->commit();
    set_flash('success', 'Sitzung gespeichert.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$session_case_id}#sitzungen");
    exit;
}

// ── POST-Handler: Sitzung bearbeiten ─────────────────────────────────────────
// Schreibt bewusst auch die SORKC-Felder mit: eine einmal falsch erfasste
// Strukturanalyse war bisher nur durch Löschen und Neuanlage korrigierbar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_session') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $session_id      = (int)($_POST['session_id'] ?? 0);
    $session_date    = (string)($_POST['session_date'] ?? '');
    $duration_min    = max(1, (int)($_POST['duration_min'] ?? 60));
    $phase           = (string)($_POST['phase'] ?? '');
    $session_notes   = trim((string)($_POST['session_notes'] ?? ''));
    $sorkc_s         = trim((string)($_POST['sorkc_s'] ?? '')) ?: null;
    $sorkc_o         = trim((string)($_POST['sorkc_o'] ?? '')) ?: null;
    $sorkc_r         = trim((string)($_POST['sorkc_r'] ?? '')) ?: null;
    $sorkc_k         = trim((string)($_POST['sorkc_k'] ?? '')) ?: null;
    $sorkc_c         = trim((string)($_POST['sorkc_c'] ?? '')) ?: null;
    $session_case_id = (int)($_GET['case_id'] ?? 0);

    if (
        $session_id <= 0 ||
        !validate_datum($session_date) ||
        $duration_min < 15 || $duration_min > 480 ||
        !in_array($phase, ['anamnese', 'analyse', 'check'], true)
    ) {
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$session_case_id}&error=session#sitzungen");
        exit;
    }

    $stmt_sess = $pdo->prepare(
        "UPDATE sessions
            SET session_date = ?, duration_min = ?, phase = ?, notes = ?,
                sorkc_s = ?, sorkc_o = ?, sorkc_r = ?, sorkc_k = ?, sorkc_c = ?
          WHERE id = ? AND client_id = ?"
    );
    $pdo->beginTransaction();
    $stmt_sess->execute([
        $session_date, $duration_min, $phase, $session_notes ?: null,
        $sorkc_s, $sorkc_o, $sorkc_r, $sorkc_k, $sorkc_c,
        $session_id, $klienten_identifikation,
    ]);

    if ($stmt_sess->rowCount() === 0) {
        set_flash('warning', 'Sitzung nicht gefunden oder unverändert.');
    } else {
        audit_log($pdo, 'edit_session', 'sessions', $session_id, $klienten_identifikation, "{$session_date} / {$phase} / {$duration_min} min");
        set_flash('success', 'Sitzung aktualisiert.');
    }
    $pdo->commit();
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$session_case_id}#sitzungen");
    exit;
}

// ── POST-Handler: Sitzung löschen ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_session') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $session_id    = (int)($_POST['session_id'] ?? 0);
    $del_sess_cid  = (int)($_GET['case_id']     ?? 0);
    if ($session_id > 0) {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM sessions WHERE id = ? AND client_id = ?")
            ->execute([$session_id, $klienten_identifikation]);
        audit_log($pdo, 'delete_session', 'sessions', $session_id, $klienten_identifikation);
        $pdo->commit();
    }
    set_flash('success', 'Sitzung gelöscht.');
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$del_sess_cid}#sitzungen");
    exit;
}

// ── POST-Handler: Notiz-Journal (anlegen / bearbeiten / löschen) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_note', 'edit_note', 'delete_note'], true)) {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }
    $notiz_action  = (string)$_POST['action'];
    $notiz_case_id = (int)($_GET['case_id'] ?? 0);

    // Die case_id stammt aus der URL – Zugehörigkeit zum Klienten prüfen, bevor
    // ein Journaleintrag daran gehängt wird. (R3-6: dieselbe Prüfung wie auf
    // den übrigen Schreibpfaden, ausgelagert nach logic/mpu_helper.php.)
    if (!fall_gehoert_zu_klient($pdo, $notiz_case_id, $klienten_identifikation)) {
        http_response_code(400);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = 'Die Notiz konnte keiner Fallakte dieses Klienten zugeordnet werden.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }

    if ($notiz_action === 'delete_note') {
        $notiz_id = (int)($_POST['note_id'] ?? 0);
        if ($notiz_id > 0) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM case_notes WHERE id = ? AND client_id = ? AND case_id = ?")
                ->execute([$notiz_id, $klienten_identifikation, $notiz_case_id]);
            audit_log($pdo, 'delete_note', 'case_notes', $notiz_id, $klienten_identifikation);
            $pdo->commit();
            set_flash('success', 'Notiz gelöscht.');
        }
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$notiz_case_id}#notizen");
        exit;
    }

    $notiz_datum = (string)($_POST['note_date'] ?? '');
    $notiz_titel = trim((string)($_POST['note_title'] ?? ''));
    $notiz_text  = trim((string)($_POST['note_body']  ?? ''));

    if (
        !validate_datum($notiz_datum) ||
        $notiz_titel === '' || mb_strlen($notiz_titel) > 120 ||
        $notiz_text === ''
    ) {
        set_flash('warning', 'Notiz nicht gespeichert – Datum, Überschrift und Text sind Pflichtfelder (Überschrift max. 120 Zeichen).');
        header("Location: akte.php?id={$klienten_identifikation}&case_id={$notiz_case_id}#notizen");
        exit;
    }

    if ($notiz_action === 'add_note') {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO case_notes (client_id, case_id, note_date, title, body) VALUES (?, ?, ?, ?, ?)"
        )->execute([$klienten_identifikation, $notiz_case_id, $notiz_datum, $notiz_titel, $notiz_text]);
        audit_log($pdo, 'add_note', 'case_notes', (int)$pdo->lastInsertId(), $klienten_identifikation, $notiz_datum);
        $pdo->commit();
        set_flash('success', 'Notiz gespeichert.');
    } else {
        $notiz_id = (int)($_POST['note_id'] ?? 0);
        $stmt_note = $pdo->prepare(
            "UPDATE case_notes SET note_date = ?, title = ?, body = ?
              WHERE id = ? AND client_id = ? AND case_id = ?"
        );
        $pdo->beginTransaction();
        $stmt_note->execute([$notiz_datum, $notiz_titel, $notiz_text, $notiz_id, $klienten_identifikation, $notiz_case_id]);
        if ($stmt_note->rowCount() === 0) {
            set_flash('warning', 'Notiz nicht gefunden oder unverändert.');
        } else {
            audit_log($pdo, 'edit_note', 'case_notes', $notiz_id, $klienten_identifikation, $notiz_datum);
            set_flash('success', 'Notiz aktualisiert.');
        }
        $pdo->commit();
    }
    header("Location: akte.php?id={$klienten_identifikation}&case_id={$notiz_case_id}#notizen");
    exit;
}

// ── POST-Handler: DSGVO Art. 20 – Personendaten-Export ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dsgvo_export') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }

    $export = [];

    $s = $pdo->prepare("
        SELECT first_name, last_name, date_of_birth, street, zip_code, city,
               phone, email, gender, weight_kg,
               dsgvo_consent, dsgvo_consent_date, revocation_notice_confirmed, revocation_confirmed, early_start_requested, created_at
        FROM clients WHERE id = ?
    ");
    $s->execute([$klienten_identifikation]);
    $export['stammdaten'] = $s->fetch();

    $s = $pdo->prepare("
        SELECT id AS fall_id, offense_date, legal_validity_date, suspension_end_date, prior_mpu,
               offense_category, license_classes, bkf_status, bak_value,
               symptoms_recorded, therapy_completed, drug_types, thc_value, thc_cooh_value,
               flensburg_points, aggression_involved,
               suggested_hypothesis, suggested_abstinence, abstinenz_monate,
               override_active, override_reason,
               authority_name, authority_file_number,
               antrag_status, mpu_target_date,
               package_type, package_price, hourly_rate, payment_status, payment_amount_paid, payment_date,
               dokument_akte, dokument_strafbefehl, dokument_register,
               icd_kontrollverlust, icd_priorisierung, icd_folgeschaeden,
               icd_craving, icd_toleranz, icd_entzug, mehrfachdelikt,
               persoenlichkeit, medizinal_beikonsum, erschwerend_faktoren, therapie_art,
               status, bussgeld_datum, einspruch_status,
               audit_score, last_score, created_at, closed_at
        FROM cases WHERE client_id = ? ORDER BY created_at
    ");
    $s->execute([$klienten_identifikation]);
    $export['fallakten'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, note_date, title, body, created_at, updated_at
        FROM case_notes WHERE client_id = ? ORDER BY note_date, id
    ");
    $s->execute([$klienten_identifikation]);
    $export['notizen'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, probe_date, method, result, lab_name, notes,
               peth_value, kreatinin_mg_dl, created_at
        FROM abstinence_probes WHERE client_id = ? ORDER BY probe_date
    ");
    $s->execute([$klienten_identifikation]);
    $export['abstinenz_nachweise'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, session_date, duration_min, phase, notes,
               sorkc_s, sorkc_o, sorkc_r, sorkc_k, sorkc_c, created_at
        FROM sessions WHERE client_id = ? ORDER BY session_date
    ");
    $s->execute([$klienten_identifikation]);
    $export['sitzungen'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, task_key, status, updated_at
        FROM client_tasks WHERE client_id = ? ORDER BY id
    ");
    $s->execute([$klienten_identifikation]);
    $export['aufgaben'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, event_date, event_type, description, created_at
        FROM case_events WHERE client_id = ? ORDER BY event_date
    ");
    $s->execute([$klienten_identifikation]);
    $export['korrespondenz'] = $s->fetchAll();

    $s = $pdo->prepare("
        SELECT case_id AS fall_id, test_date, test_name, percentile, notes, created_at
        FROM performance_tests WHERE client_id = ? ORDER BY test_date
    ");
    $s->execute([$klienten_identifikation]);
    $export['leistungstests'] = $s->fetchAll();

    $export['_meta'] = [
        'exportiert_am'   => date('c'),
        'client_id'       => $klienten_identifikation,
        'rechtsgrundlage' => 'Art. 20 DSGVO, Grundlage für Auskünfte nach Art. 15 DSGVO',
    ];

    if (!validate_dsgvo_export($export)) {
        http_response_code(500);
        $_error_title = 'Exportfehler';
        $_error_message = 'Der Export ist unvollständig. Bitte versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }

    audit_log($pdo, 'dsgvo_export', 'clients', $klienten_identifikation, $klienten_identifikation, 'Art.-20-Datenexport erstellt');

    $name_slug = preg_replace('/[^a-z0-9]/i', '_', ($export['stammdaten']['first_name'] ?? '') . '_' . ($export['stammdaten']['last_name'] ?? ''));
    $filename  = 'dsgvo_export_' . $name_slug . '_' . date('Y-m-d') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: no-store');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
