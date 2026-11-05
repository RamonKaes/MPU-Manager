<?php
declare(strict_types=1);

require_once __DIR__ . '/../logic/validator.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_session' || $action === 'edit_session') {
        $session_date = (string)($_POST['session_date'] ?? '');
        $duration_min = (int)($_POST['duration_min'] ?? 60);
        $phase_raw    = (string)($_POST['phase'] ?? '');
        $phase        = in_array($phase_raw, ['anamnese', 'analyse', 'check'], true) ? $phase_raw : '';
        $notes        = trim((string)($_POST['notes'] ?? ''));

        $validierungsfehler = [];
        if (!validate_datum($session_date)) {
            $validierungsfehler[] = 'Datum ist Pflichtfeld und muss im Format JJJJ-MM-TT übergeben werden.';
        }
        if ($duration_min < 15 || $duration_min > 480) {
            $validierungsfehler[] = 'Dauer muss zwischen 15 und 480 Minuten liegen.';
        }
        if ($phase === '') {
            $validierungsfehler[] = 'Phase ist ein Pflichtfeld.';
        }

        if (empty($validierungsfehler)) {
            if ($action === 'add_session') {
                // Aktive Fallakte des Klienten ermitteln (gleiche Priorität wie im übrigen System:
                // status='aktiv' bevorzugt, sonst die neueste). Kein Fall vorhanden -> case_id NULL.
                $stmt_case = $pdo->prepare(
                    "SELECT id FROM cases WHERE client_id = ?
                     ORDER BY CASE WHEN status = 'aktiv' THEN 0 ELSE 1 END, created_at DESC LIMIT 1"
                );
                $stmt_case->execute([$client_id]);
                $session_case_id = (int)($stmt_case->fetchColumn() ?: 0) ?: null;

                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO sessions (client_id, case_id, session_date, duration_min, phase, notes)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$client_id, $session_case_id, $session_date, $duration_min, $phase, $notes ?: null]);
                $neue_session_id = (int)$pdo->lastInsertId();
                audit_log($pdo, 'add_session', 'sessions', $neue_session_id, $client_id, "{$session_date} / {$phase} / {$duration_min} min");
                $pdo->commit();
                set_flash('success', 'Sitzung gespeichert.');
            } else {
                $session_id = (int)($_POST['session_id'] ?? 0);
                $stmt_upd = $pdo->prepare(
                    "UPDATE sessions SET session_date = ?, duration_min = ?, phase = ?, notes = ?
                     WHERE id = ? AND client_id = ?"
                );
                $pdo->beginTransaction();
                $stmt_upd->execute([$session_date, $duration_min, $phase, $notes ?: null, $session_id, $client_id]);
                if ($stmt_upd->rowCount() === 0) {
                    set_flash('warning', 'Sitzung wurde nicht gefunden oder gehört nicht zu diesem Klienten.');
                } else {
                    audit_log($pdo, 'edit_session', 'sessions', $session_id, $client_id, "{$session_date} / {$phase} / {$duration_min} min");
                    set_flash('success', 'Sitzung aktualisiert.');
                }
                $pdo->commit();
            }
            header("Location: sitzungen.php?client_id={$client_id}");
            exit;
        }
        $fehlermeldung = implode(' ', $validierungsfehler);
    } elseif ($action === 'delete_session') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        if ($session_id > 0) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sessions WHERE id = ? AND client_id = ?")
                ->execute([$session_id, $client_id]);
            audit_log($pdo, 'delete_session', 'sessions', $session_id, $client_id);
            $pdo->commit();
            set_flash('success', 'Sitzung gelöscht.');
        }
        header("Location: sitzungen.php?client_id={$client_id}");
        exit;
    }
}
