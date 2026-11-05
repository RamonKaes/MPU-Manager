<?php
declare(strict_types=1);

require_once __DIR__ . '/../logic/validator.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Prüfung
    if (!hash_equals($csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $_error_title = 'Ungültige Anfrage';
        $_error_message = empty($_POST['csrf_token'])
            ? 'Kein CSRF-Token im Formular gefunden. Das Formular wurde vermutlich nicht korrekt geladen – bitte die Seite neu laden und das Formular erneut ausfüllen.'
            : 'CSRF-Token ungültig oder abgelaufen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.';
        require __DIR__ . '/../partials/error.php';
        exit;
    }

    // Stammdaten
    $vorname        = trim($_POST['vorname']   ?? '');
    $nachname       = trim($_POST['nachname']  ?? '');
    $geburtsdatum   = !empty($_POST['geburtsdatum']) ? $_POST['geburtsdatum'] : NULL;
    $strasse        = trim($_POST['strasse']   ?? '');
    $postleitzahl   = trim($_POST['postleitzahl']   ?? '');
    $wohnort        = trim($_POST['wohnort']        ?? '');
    $telefonnummer  = trim($_POST['telefonnummer']  ?? '');
    $email_adresse  = trim($_POST['email_adresse']  ?? '');
    $geschlecht     = $_POST['geschlecht']     ?? 'm';
    $koerpergewicht = !empty($_POST['koerpergewicht']) ? (float)$_POST['koerpergewicht'] : NULL;

    // Triage & Delikte
    $deliktarten      = array_values(array_filter((array)($_POST['deliktart'] ?? []), 'is_string'));
    $deliktart_string = json_encode($deliktarten, JSON_UNESCAPED_UNICODE);
    $drogenarten      = array_values(array_filter((array)($_POST['drogenart'] ?? []), 'is_string'));
    $drogenart_string = json_encode($drogenarten, JSON_UNESCAPED_UNICODE);
    $fuehrerscheinklassen      = array_values(array_filter((array)($_POST['fuehrerscheinklasse'] ?? []), 'is_string'));
    $fuehrerscheinklasse_string = implode(', ', $fuehrerscheinklassen);

    $bkf_status_raw = trim((string)($_POST['bkf_status'] ?? 'nein'));
    $bkf_status     = in_array($bkf_status_raw, ['nein', 'aktiv', 'angestrebt'], true) ? $bkf_status_raw : 'nein';

    $klienten_status_raw = trim((string)($_POST['klienten_status'] ?? 'aktiv'));
    $klienten_status     = in_array($klienten_status_raw, ['aktiv', 'abgeschlossen', 'pausiert'], true) ? $klienten_status_raw : 'aktiv';

    // alter_status für Statusübergangs-Validierung (weiter unten nach $validierungsfehler = [])
    $alter_status = (string)($data['status'] ?? 'aktiv');

    $antrag_status_raw = trim((string)($_POST['antrag_status'] ?? 'nicht_gestellt'));
    $antrag_status     = in_array($antrag_status_raw, ['nicht_gestellt', 'gestellt', 'aufforderung_liegt_vor', 'abgelehnt'], true)
                            ? $antrag_status_raw : 'nicht_gestellt';

    $payment_status_raw = trim((string)($_POST['payment_status'] ?? 'offen'));
    $payment_status     = in_array($payment_status_raw, ['offen', 'teilweise', 'beglichen'], true) ? $payment_status_raw : 'offen';
    $payment_date       = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;

    $stundensatz        = !empty($_POST['stundensatz'])    ? (float)$_POST['stundensatz']    : null;
    $honorar_gesamt     = !empty($_POST['honorar_gesamt']) ? (float)$_POST['honorar_gesamt'] : null;

    $override_aktiv       = isset($_POST['override_aktiv']) ? 1 : 0;
    $override_begruendung = trim($_POST['override_begruendung'] ?? '');

    $abrechnungsmodell_raw = trim((string)($_POST['abrechnungsmodell'] ?? 'paket'));
    $abrechnungsmodell     = in_array($abrechnungsmodell_raw, ['paket', 'einzel'], true) ? $abrechnungsmodell_raw : 'paket';

    $punkte_flensburg = (int)($_POST['punkte_flensburg'] ?? 0);

    // Fristen & Behörden (Q6: als Variablen extrahiert statt direkter $\_POST-Zugriff im execute())
    $datum_des_deliktes      = !empty($_POST['datum_des_deliktes']) ? $_POST['datum_des_deliktes'] : null;
    $datum_rechtskraft       = !empty($_POST['datum_rechtskraft'])  ? $_POST['datum_rechtskraft']  : null;
    $sperrfrist_ende         = !empty($_POST['sperrfrist_ende'])    ? $_POST['sperrfrist_ende']    : null;
    $behoerde                = trim($_POST['behoerde']     ?? '');
    $aktenzeichen            = trim($_POST['aktenzeichen'] ?? '');
    $mpu_zielmonat_raw       = !empty($_POST['mpu_zielmonat']) ? $_POST['mpu_zielmonat'] : null;
    $mpu_zielmonat_datum     = $mpu_zielmonat_raw ? $mpu_zielmonat_raw . '-01' : null;
    $tetrahydrocannabinol_wert = !empty($_POST['tetrahydrocannabinol_wert']) ? $_POST['tetrahydrocannabinol_wert'] : null;
    $thc_cooh_value = (isset($_POST['thc_cooh_value']) && $_POST['thc_cooh_value'] !== '') ? (float)$_POST['thc_cooh_value'] : null;

    // F1: Einspruchsfrist
    $bussgeld_datum       = !empty($_POST['bussgeld_datum']) ? $_POST['bussgeld_datum'] : NULL;
    $einspruch_status_raw = trim((string)($_POST['einspruch_status'] ?? 'nicht_relevant'));
    $einspruch_status     = in_array($einspruch_status_raw, ['offen','eingelegt','abgelaufen','nicht_relevant'], true)
                              ? $einspruch_status_raw : 'nicht_relevant';

    // F14: AUDIT- und LAST-Scores
    $audit_score = isset($_POST['audit_score']) && $_POST['audit_score'] !== '' ? (int)$_POST['audit_score'] : NULL;
    $last_score  = isset($_POST['last_score'])  && $_POST['last_score']  !== '' ? (int)$_POST['last_score']  : NULL;

    // F-B: ICD drei-Zustand – NULL = nicht erhoben, 0 = verneint, 1 = bejaht
    // Nur wenn der Klient ein Konsum-Delikt hat, müssen ICD-Kriterien erhoben sein.
    $hat_konsum_delikt = in_array('Alkohol', $deliktarten, true) || in_array('Drogen', $deliktarten, true);
    $icd_felder = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden','icd_craving','icd_toleranz','icd_entzug'];
    $icd_werte  = [];
    foreach ($icd_felder as $feld) {
        $raw = $_POST[$feld] ?? null;
        if ($raw === '1')  { $icd_werte[$feld] = 1; }
        elseif ($raw === '0') { $icd_werte[$feld] = 0; }
        else               { $icd_werte[$feld] = null; } // nicht erhoben
    }
    $icd_nicht_erhoben = array_filter($icd_werte, fn($v) => $v === null);
    $payment_amount_paid = isset($_POST['payment_amount_paid']) && $_POST['payment_amount_paid'] !== '' ? (float)$_POST['payment_amount_paid'] : null;

    // Checkbox-Felder einheitlich als Variablen extrahieren
    $fruehere_untersuchungen      = isset($_POST['fruehere_untersuchungen'])      ? 1 : 0;
    $ausfallerscheinungen_notiert = isset($_POST['ausfallerscheinungen_notiert']) ? 1 : 0;
    $suchttherapie_absolviert     = isset($_POST['suchttherapie_absolviert'])     ? 1 : 0;
    $aggressionspotenzial         = isset($_POST['aggressionspotenzial'])         ? 1 : 0;
    $persoenlichkeit              = isset($_POST['persoenlichkeit'])              ? 1 : 0;
    $medizinal_beikonsum          = isset($_POST['medizinal_beikonsum'])          ? 1 : 0;
    $erschwerend_faktoren         = isset($_POST['erschwerend_faktoren'])         ? 1 : 0;
    $therapie_art                 = in_array($_POST['therapie_art'] ?? '', ['keine', 'ambulant', 'stationaer'], true) ? $_POST['therapie_art'] : 'keine';
    $suchttherapie_absolviert     = ($suchttherapie_absolviert || $therapie_art !== 'keine') ? 1 : 0;
    $dokument_akte                = isset($_POST['dokument_akte'])                ? 1 : 0;
    $dokument_strafbefehl         = isset($_POST['dokument_strafbefehl'])         ? 1 : 0;
    $dokument_register            = isset($_POST['dokument_register'])            ? 1 : 0;
    $mehrfachdelikt               = isset($_POST['mehrfachdelikt'])               ? 1 : 0;

    // Serverseitige Validierung
    // UX-2: feldname => Meldung statt reiner String-Liste, damit die Seite pro
    // Fehler auf das betroffene Feld verlinken und es markieren kann.
    $validierungsfehler = [];

    // Statusübergang-Validierung
    if (!in_array($klienten_status, ERLAUBTE_STATUSUEBERGAENGE[$alter_status] ?? [], true)) {
        $validierungsfehler['klienten_status'] = 'Ungueltiger Statusuebergang von "' . $alter_status . '" nach "' . $klienten_status . '".';
    }
    if ($alter_status === 'abgeschlossen' && $klienten_status !== 'abgeschlossen'
        && empty($_POST['status_reopen_bestaetigt'])) {
        $validierungsfehler['status_reopen_bestaetigt'] = 'Statusruecksetzer von Abgeschlossen wurde nicht bestaetigt.';
    }

    if (empty($vorname))      { $validierungsfehler['vorname']      = 'Vorname ist ein Pflichtfeld.'; }
    if (empty($nachname))     { $validierungsfehler['nachname']     = 'Nachname ist ein Pflichtfeld.'; }
    if (empty($geburtsdatum)) { $validierungsfehler['geburtsdatum'] = 'Geburtsdatum ist ein Pflichtfeld.'; }
    if ($geburtsdatum !== NULL && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $geburtsdatum)) {
        $validierungsfehler['geburtsdatum'] = 'Geburtsdatum muss im Format JJJJ-MM-TT übergeben werden.';
    }
    if (!empty($email_adresse) && !filter_var($email_adresse, FILTER_VALIDATE_EMAIL)) {
        $validierungsfehler['email_adresse'] = 'Die E-Mail-Adresse ist ungültig.';
    }
    if (!empty($postleitzahl) && !preg_match('/^\d{5}$/', $postleitzahl)) {
        $validierungsfehler['postleitzahl'] = 'Die Postleitzahl muss genau 5 Ziffern enthalten.';
    }
    // BAK nur übernehmen wenn Alkohol-Delikt gewählt; sonst auf NULL setzen
    $bak_raw = in_array('Alkohol', $deliktarten, true) ? ($_POST['blutalkoholkonzentration'] ?? '') : '';
    if ($bak_raw !== '') {
        if (!is_numeric($bak_raw)) {
            $validierungsfehler['blutalkoholkonzentration'] = 'Der BAK-Wert muss eine Dezimalzahl sein.';
        } else {
            $bak_float = (float)$bak_raw;
            if ($bak_float < 0.0 || $bak_float > 6.0) {
                $validierungsfehler['blutalkoholkonzentration'] = 'Der BAK-Wert muss zwischen 0,0 und 6,0 ‰ liegen.';
            }
        }
    }
    // E-5: THC und THC-COOH nur übernehmen wenn Drogen-Delikt gewählt.
    // THC-COOH war bis 29.07.2026 ungegated und wich damit von aufnahme.php ab
    // (dort Z. 84). Der Wert war ohne Drogen-Delikt ohnehin wirkungslos: die
    // COOH-Zweige der Triage liegen vollständig innerhalb von
    // `if (in_array('Drogen', ...))` in triage.php – gespeichert wurde also
    // Ballast, den nichts liest und den der andere Eingabeweg verweigert.
    if (!in_array('Drogen', $deliktarten, true)) {
        $tetrahydrocannabinol_wert = null;
        $thc_cooh_value            = null;
    }
    if ($punkte_flensburg < 0 || $punkte_flensburg > PUNKTE_EINGABE_MAX) {
        $validierungsfehler['punkte_flensburg'] = 'Die Flensburg-Punkte müssen zwischen 0 und ' . PUNKTE_EINGABE_MAX . ' liegen.';
    }
    // E-6: Wertebereiche aus config/screening_config.php (siehe aufnahme_handler.php)
    $_screening_grenzen = require __DIR__ . '/../config/screening_config.php';
    $_audit_max = (int)$_screening_grenzen['audit']['max'];
    $_last_max  = (int)$_screening_grenzen['last']['max'];
    if ($audit_score !== NULL && ($audit_score < 0 || $audit_score > $_audit_max)) {
        $validierungsfehler['audit_score'] = "AUDIT-Score muss zwischen 0 und {$_audit_max} liegen.";
    }
    if ($last_score !== NULL && ($last_score < 0 || $last_score > $_last_max)) {
        $validierungsfehler['last_score'] = "LAST-Score muss zwischen 0 und {$_last_max} liegen.";
    }
    // UX-6: Der ICD-Quick-Check blockiert das Speichern nicht (mehr).
    // Er darf es auch gar nicht: sonst wäre ein nach UX-6 unvollständig
    // angelegter Klient in bearbeiten.php gesperrt, bis genau die Werte
    // eingetragen sind, die noch niemand kennt – Stammdaten und Fristen wären
    // dann nicht pflegbar. Stattdessen: Fall bleibt vorläufig, sichtbar über
    // get_triage_vollstaendigkeit().
    $triage_vorlaeufig = $hat_konsum_delikt && !empty($icd_nicht_erhoben);
    // L-B: Override-Begründungspflicht – wenn Override aktiv und keine strukturierte Begründung
    if ($override_aktiv && empty($override_begruendung)) {
        $validierungsfehler['override_begruendung'] = 'Human Override aktiv: Eine Begründung ist Pflicht (Dokumentationspflicht nach BK-5).';
    }
    $date_felder_optional = [
        'datum_des_deliktes' => $datum_des_deliktes,
        'datum_rechtskraft'  => $datum_rechtskraft,
        'sperrfrist_ende'    => $sperrfrist_ende,
        'bussgeld_datum'     => $bussgeld_datum,
    ];
    foreach ($date_felder_optional as $_df_name => $_df_wert) {
        if ($_df_wert !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $_df_wert)) {
            $validierungsfehler[$_df_name] = 'Datumsfehler: ' . $_df_name . ' muss im Format JJJJ-MM-TT sein.';
        }
    }
    unset($_df_name, $_df_wert, $date_felder_optional);
    $validierungsfehler += validiere_gemeinsame_felder($_POST);
    if (!empty($validierungsfehler)) {
        http_response_code(400);
        $fehlermeldung = implode(' ', $validierungsfehler);
    }

    if (empty($fehlermeldung)) {
    // Triage erst nach Validierung berechnen – saubere Eingaben sind gesichert
    $triage_result = berechne_triage(
        delikte:            $deliktarten,
        drogen:             $drogenarten,
        bak:                (float)($bak_raw ?: 0),
        therapy:            (bool)$suchttherapie_absolviert,
        therapie_art:       $therapie_art,
        erschwerend:        (bool)$erschwerend_faktoren,
        repeat:             (bool)$fruehere_untersuchungen,
        mehrfachdelikt:     (bool)$mehrfachdelikt,
        symptoms:           (bool)$ausfallerscheinungen_notiert,
        punkte:             $punkte_flensburg,
        aggression:         (bool)$aggressionspotenzial,
        thc_wert:           (float)($tetrahydrocannabinol_wert ?? 0),
        thc_cooh:           (float)($thc_cooh_value                       ?? 0),
        klassen:            $fuehrerscheinklassen,
        bkf_status:         $bkf_status,
        icd_kontrollverlust: $icd_werte['icd_kontrollverlust'] === 1 ?: null,
        icd_priorisierung:   $icd_werte['icd_priorisierung']   === 1 ?: null,
        icd_folgeschaeden:   $icd_werte['icd_folgeschaeden']   === 1 ?: null,
        icd_craving:         $icd_werte['icd_craving']         === 1 ?: null,
        icd_toleranz:        $icd_werte['icd_toleranz']        === 1 ?: null,
        icd_entzug:          $icd_werte['icd_entzug']          === 1 ?: null,
        persoenlichkeit:     (bool)$persoenlichkeit,
        medizinal_beikonsum: (bool)$medizinal_beikonsum
    );

    if ($override_aktiv) {
        $override_hyp  = trim($_POST['override_hypothese'] ?? '');
        $override_abst = trim($_POST['override_abstinenz'] ?? '');
        if (in_array($override_hyp, ERLAUBTE_HYPOTHESEN, true)) {
            $triage_result['hypothese_text'] = $override_hyp;
        }
        if (in_array($override_abst, ERLAUBTE_ABSTINENZ, true)) {
            $triage_result['abstinenz_primaer'] = $override_abst;
            $triage_result['abstinenz_monate']  = abstinenz_monate_aus_text($override_abst);
        }
    }

    try {
        $pdo->beginTransaction();

        // Optimistic-Locking-Versionen aus dem Formular lesen
        $form_case_version   = (int)($_POST['case_version']   ?? 0);
        $form_client_version = (int)($_POST['client_version'] ?? 0);

        // Update Clients (mit version-Prüfung)
        $sql_client = "UPDATE clients SET
            first_name = ?, last_name = ?, date_of_birth = ?, street = ?, zip_code = ?, city = ?,
            phone = ?, email = ?, gender = ?, weight_kg = ?, early_start_requested = ?,
            version = version + 1
            WHERE id = ? AND version = ?";
        $stmt_client = $pdo->prepare($sql_client);
        $stmt_client->execute([
            $vorname, $nachname, $geburtsdatum, $strasse, $postleitzahl, $wohnort,
            $telefonnummer, $email_adresse, $geschlecht, $koerpergewicht,
            isset($_POST['vorzeitiger_leistungsbeginn']) ? 1 : 0, $id, $form_client_version
        ]);
        if ($stmt_client->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(409);
            $fehlermeldung = 'Die Stammdaten wurden seit dem Öffnen der Seite von jemand anderem geändert. Bitte laden Sie die Seite neu und wiederholen Sie Ihre Eingabe.';
        }

        if (empty($fehlermeldung)) {
        // Update Cases (mit version-Prüfung)
        $sql_case = "UPDATE cases SET
            offense_date = ?, legal_validity_date = ?, suspension_end_date = ?, prior_mpu = ?,
            offense_category = ?, license_classes = ?, bkf_status = ?, bak_value = ?,
            symptoms_recorded = ?, therapy_completed = ?, drug_types = ?, thc_value = ?, thc_cooh_value = ?,
            flensburg_points = ?, aggression_involved = ?, suggested_hypothesis = ?,
            suggested_abstinence = ?, abstinenz_monate = ?, override_active = ?, override_reason = ?,
            authority_name = ?, authority_file_number = ?,
            antrag_status = ?, mpu_target_date = ?, package_type = ?, package_price = ?,
            hourly_rate = ?, payment_amount_paid = ?,
            dokument_akte = ?, dokument_strafbefehl = ?, dokument_register = ?,
            icd_kontrollverlust = ?, icd_priorisierung = ?, icd_folgeschaeden = ?,
            icd_craving = ?, icd_toleranz = ?, icd_entzug = ?, mehrfachdelikt = ?,
            persoenlichkeit = ?, medizinal_beikonsum = ?, erschwerend_faktoren = ?, therapie_art = ?,
            status = ?, payment_status = ?, payment_date = ?,
            bussgeld_datum = ?, einspruch_status = ?, audit_score = ?, last_score = ?,
            version = version + 1
            WHERE id = ? AND client_id = ? AND version = ?";
        $stmt_case = $pdo->prepare($sql_case);
        $stmt_case->execute([
            $datum_des_deliktes, $datum_rechtskraft, $sperrfrist_ende,
            $fruehere_untersuchungen,
            $deliktart_string, $fuehrerscheinklasse_string, $bkf_status,
            ($bak_raw !== '') ? $bak_raw : NULL,
            $ausfallerscheinungen_notiert,
            $suchttherapie_absolviert,
            $drogenart_string,
            $tetrahydrocannabinol_wert,
            $thc_cooh_value,
            $punkte_flensburg,
            $aggressionspotenzial,
            $triage_result['hypothese_text'], $triage_result['abstinenz_primaer'], (int)$triage_result['abstinenz_monate'],
            $override_aktiv, !empty($override_begruendung) ? $override_begruendung : null,
            $behoerde, $aktenzeichen,
            $antrag_status,
            $mpu_zielmonat_datum,
            $abrechnungsmodell,
            $honorar_gesamt,
            $stundensatz,
            $payment_amount_paid,
            $dokument_akte,
            $dokument_strafbefehl,
            $dokument_register,
            $icd_werte['icd_kontrollverlust'],
            $icd_werte['icd_priorisierung'],
            $icd_werte['icd_folgeschaeden'],
            $icd_werte['icd_craving'],
            $icd_werte['icd_toleranz'],
            $icd_werte['icd_entzug'],
            $mehrfachdelikt,
            $persoenlichkeit, $medizinal_beikonsum, $erschwerend_faktoren, $therapie_art,
            $klienten_status, $payment_status, $payment_date,
            $bussgeld_datum, $einspruch_status, $audit_score, $last_score,
            $case_id, $id, $form_case_version
        ]);
        if ($stmt_case->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(409);
            $fehlermeldung = 'Die Falldaten wurden seit dem Öffnen der Seite geändert. Bitte laden Sie die Seite neu und wiederholen Sie Ihre Eingabe.';
        }
        } // end client-version check

        // closed_at folgt dem Status: Beim Abschluss gesetzt, beim Wiedereröffnen gelöscht.
        // Die Aufbewahrungsfrist in dsgvo_faellige_klienten() zählt ab diesem Datum. Bliebe
        // ein altes Datum stehen, wäre ein gerade neu abgeschlossener Fall sofort löschbar.
        if (empty($fehlermeldung)) {
            $pdo->prepare(
                $klienten_status === 'abgeschlossen'
                    ? "UPDATE cases SET closed_at = COALESCE(closed_at, NOW()) WHERE id = ? AND client_id = ?"
                    : "UPDATE cases SET closed_at = NULL WHERE id = ? AND client_id = ?"
            )->execute([$case_id, $id]);
        }

        // Feld-Delta für Audit-Log: kritische Felder auf Änderungen prüfen
        if (empty($fehlermeldung)) {
        $audit_delta = [];
        $old_hyp  = (string)($data['suggested_hypothesis'] ?? '');
        $old_abst = (string)($data['suggested_abstinence'] ?? '');
        if ($old_hyp !== $triage_result['hypothese_text']) {
            $audit_delta[] = 'Hypothese: ' . $old_hyp . ' → ' . $triage_result['hypothese_text'];
        }
        if ($old_abst !== $triage_result['abstinenz_primaer']) {
            $audit_delta[] = 'Abstinenz: ' . $old_abst . ' → ' . $triage_result['abstinenz_primaer'];
        }
        $audit_desc = empty($audit_delta) ? 'Akte gespeichert' : implode(' | ', $audit_delta);
        audit_log($pdo, 'update_case', 'cases', $case_id, $id, $audit_desc);
        $pdo->commit();

        $flash_hyp = $triage_result['hypothese_text'] ?? '';
        $flash_msg = 'Akte gespeichert.';
        if ($flash_hyp !== '') {
            $abst = $triage_result['abstinenz_primaer'] ?? '';
            $flash_msg .= ' Triage: ' . $flash_hyp . ($abst !== '' ? ' – ' . $abst : '') . '.';
        }
        // UX-6: Solange Kriterien fehlen, ist das Ergebnis eine Untergrenze
        if ($triage_vorlaeufig) {
            $flash_msg .= ' Vorläufig – ' . count($icd_nicht_erhoben) . ' von 6 ICD-Kriterien noch nicht erhoben.';
        }
        set_flash('success', $flash_msg);
        header("Location: akte.php?id=$id&case_id=$case_id");
        exit;
        } // end empty($fehlermeldung) check for delta+commit
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Aktualisieren-Fehler (bearbeiten_handler): ' . $e->getMessage());
        $fehlermeldung = 'Fehler beim Speichern der Akte. Bitte versuchen Sie es erneut.';
    }
    } // end if (empty($fehlermeldung))
}
