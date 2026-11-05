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

    // 1. Stammdaten & Kontakt
    $vorname        = trim($_POST['vorname']   ?? '');
    $nachname       = trim($_POST['nachname']  ?? '');
    $geburtsdatum   = !empty($_POST['geburtsdatum'])   ? $_POST['geburtsdatum']   : NULL;
    $strasse        = trim($_POST['strasse']   ?? '');
    $postleitzahl   = trim($_POST['postleitzahl']   ?? '');
    $wohnort        = trim($_POST['wohnort']        ?? '');
    $telefonnummer  = trim($_POST['telefonnummer']  ?? '');
    $email_adresse  = trim($_POST['email_adresse']  ?? '');
    $geschlecht_raw = $_POST['geschlecht'] ?? 'm';
    $geschlecht     = in_array($geschlecht_raw, ['m', 'f', 'd'], true) ? $geschlecht_raw : 'm';
    $kgw_raw = !empty($_POST['koerpergewicht']) ? (float)$_POST['koerpergewicht'] : null;
    $koerpergewicht = ($kgw_raw !== null && $kgw_raw > 0 && $kgw_raw <= 300) ? $kgw_raw : null;

    // Verträge & Finanzen
    $abrechnungsmodell_raw  = $_POST['abrechnungsmodell'] ?? 'paket';
    $abrechnungsmodell      = in_array($abrechnungsmodell_raw, ['paket', 'einzel']) ? $abrechnungsmodell_raw : 'paket';
    $honorar_gesamt         = !empty($_POST['honorar_gesamt'])  ? (float)$_POST['honorar_gesamt']  : null;
    $stundensatz            = !empty($_POST['stundensatz'])     ? (float)$_POST['stundensatz']     : null;
    $datenschutz_zugestimmt      = isset($_POST['datenschutz_zugestimmt'])      ? 1 : 0;
    $widerruf_bestaetigt         = isset($_POST['widerruf_bestaetigt'])         ? 1 : 0;
    $vorzeitiger_leistungsbeginn = ($_POST['vorzeitiger_leistungsbeginn'] ?? '0') === '1' ? 1 : 0;

    // 2. Fristen & Behörden
    $datum_des_deliktes  = !empty($_POST['datum_des_deliktes'])  ? $_POST['datum_des_deliktes']  : NULL;
    $datum_rechtskraft   = !empty($_POST['datum_rechtskraft'])   ? $_POST['datum_rechtskraft']   : NULL;
    $sperrfrist_ende     = !empty($_POST['sperrfrist_ende'])     ? $_POST['sperrfrist_ende']     : NULL;

    // F1: Einspruchsfrist
    $bussgeld_datum     = !empty($_POST['bussgeld_datum']) ? $_POST['bussgeld_datum'] : NULL;
    $einspruch_status_raw = $_POST['einspruch_status'] ?? 'nicht_relevant';
    $einspruch_status   = in_array($einspruch_status_raw, ['offen','eingelegt','abgelaufen','nicht_relevant'], true)
                            ? $einspruch_status_raw : 'nicht_relevant';
    $fuehrerscheinklassen       = $_POST['fuehrerscheinklasse'] ?? [];
    $fuehrerscheinklasse_string  = implode(', ', $fuehrerscheinklassen);
    $bkf_status_raw = $_POST['bkf_status'] ?? 'nein';
    $bkf_status     = in_array($bkf_status_raw, ['nein', 'aktiv', 'angestrebt']) ? $bkf_status_raw : 'nein';
    $behoerde           = trim($_POST['behoerde']    ?? '');
    $aktenzeichen       = trim($_POST['aktenzeichen'] ?? '');
    $antrag_status_raw  = $_POST['antrag_status'] ?? 'nicht_gestellt';
    $antrag_status      = in_array($antrag_status_raw, ['nicht_gestellt', 'gestellt', 'aufforderung_liegt_vor'])
                            ? $antrag_status_raw : 'nicht_gestellt';
    // YYYY-MM aus <input type="month"> → YYYY-MM-01 für DATE-Spalte
    $mpu_zielmonat = !empty($_POST['mpu_zielmonat']) ? $_POST['mpu_zielmonat'] . '-01' : NULL;

    // Dokumenten-Status
    $dokument_akte        = isset($_POST['dokument_akte'])        ? 1 : 0;
    $dokument_strafbefehl = isset($_POST['dokument_strafbefehl']) ? 1 : 0;
    $dokument_register    = isset($_POST['dokument_register'])    ? 1 : 0;

    $fruehere_untersuchungen = isset($_POST['fruehere_untersuchungen']) ? 1 : 0;
    $mehrfachdelikt           = isset($_POST['mehrfachdelikt'])           ? 1 : 0;

    // 3. Deliktspezifische Daten (Checkboxes)
    $deliktarten      = $_POST['deliktart'] ?? [];
    $deliktart_string = json_encode($deliktarten, JSON_UNESCAPED_UNICODE);

    // Alkohol – BAK nur übernehmen wenn Alkohol-Delikt gewählt
    $blutalkoholkonzentration     = (in_array('Alkohol', $deliktarten, true) && !empty($_POST['blutalkoholkonzentration']))
                                    ? $_POST['blutalkoholkonzentration'] : NULL;
    $ausfallerscheinungen_notiert = isset($_POST['ausfallerscheinungen_notiert']) ? 1 : 0;
    $suchttherapie_absolviert     = isset($_POST['suchttherapie_absolviert'])     ? 1 : 0;

    // Drogen – THC nur übernehmen wenn Drogen-Delikt gewählt
    $drogenarten      = $_POST['drogenart'] ?? [];
    $drogenart_string = json_encode($drogenarten, JSON_UNESCAPED_UNICODE);
    $tetrahydrocannabinol_wert = (in_array('Drogen', $deliktarten, true) && !empty($_POST['tetrahydrocannabinol_wert']))
                                 ? $_POST['tetrahydrocannabinol_wert'] : NULL;
    $thc_cooh_value = (in_array('Drogen', $deliktarten, true) && isset($_POST['thc_cooh_value']) && $_POST['thc_cooh_value'] !== '')
                      ? (float)$_POST['thc_cooh_value'] : NULL;

    // UX-1: Anzeigewerte getrennt von den DB-Werten halten.
    // Das Nullen oben ist eine DB-Regel (kein BAK auf einem Nicht-Alkohol-Fall)
    // und darf die Eingabe des Beraters nicht loeschen, wenn das Formular wegen
    // eines anderen Fehlers neu rendert. Gespeichert wird weiterhin nur der
    // gegatete Wert; hier geht es ausschliesslich um die Wiederanzeige.
    $anzeige_bak      = trim((string)($_POST['blutalkoholkonzentration'] ?? ''));
    $anzeige_thc      = trim((string)($_POST['tetrahydrocannabinol_wert'] ?? ''));
    $anzeige_thc_cooh = trim((string)($_POST['thc_cooh_value'] ?? ''));

    // Verkehr/Strafrecht
    $punkte_flensburg    = !empty($_POST['punkte_flensburg']) ? (int)$_POST['punkte_flensburg'] : 0;
    $aggressionspotenzial = isset($_POST['aggressionspotenzial']) ? 1 : 0;
    $persoenlichkeit      = isset($_POST['persoenlichkeit'])      ? 1 : 0;
    $medizinal_beikonsum  = isset($_POST['medizinal_beikonsum'])  ? 1 : 0;
    $erschwerend_faktoren = isset($_POST['erschwerend_faktoren']) ? 1 : 0;
    $therapie_art         = in_array($_POST['therapie_art'] ?? '', ['keine', 'ambulant', 'stationaer'], true) ? $_POST['therapie_art'] : 'keine';
    $suchttherapie_absolviert = ($suchttherapie_absolviert || $therapie_art !== 'keine') ? 1 : 0;

    // F14: AUDIT- und LAST-Scores
    $audit_score = isset($_POST['audit_score']) && $_POST['audit_score'] !== '' ? (int)$_POST['audit_score'] : NULL;
    $last_score  = isset($_POST['last_score'])  && $_POST['last_score']  !== '' ? (int)$_POST['last_score']  : NULL;

    // ICD-Quick-Check – Dreizustand: NULL = nicht erhoben, 0 = nein, 1 = ja
    $icd_felder = ['icd_kontrollverlust','icd_priorisierung','icd_folgeschaeden','icd_craving','icd_toleranz','icd_entzug'];
    $icd_werte  = [];
    foreach ($icd_felder as $feld) {
        $raw = $_POST[$feld] ?? null;
        if ($raw === '1')     { $icd_werte[$feld] = 1; }
        elseif ($raw === '0') { $icd_werte[$feld] = 0; }
        else                  { $icd_werte[$feld] = null; }
    }
    $icd_kontrollverlust = $icd_werte['icd_kontrollverlust'];
    $icd_priorisierung   = $icd_werte['icd_priorisierung'];
    $icd_folgeschaeden   = $icd_werte['icd_folgeschaeden'];
    $icd_craving         = $icd_werte['icd_craving'];
    $icd_toleranz        = $icd_werte['icd_toleranz'];
    $icd_entzug          = $icd_werte['icd_entzug'];

    // Human Override
    $override_aktiv       = isset($_POST['override_aktiv']) ? 1 : 0;
    $override_begruendung = trim($_POST['override_begruendung'] ?? '');

    // payment_amount_paid
    $payment_amount_paid = isset($_POST['payment_amount_paid']) && $_POST['payment_amount_paid'] !== '' ? (float)$_POST['payment_amount_paid'] : null;

    // Serverseitige Validierung
    // UX-2: feldname => Meldung statt einer reinen String-Liste, damit die Seite
    // pro Fehler auf das betroffene Feld verlinken und es markieren kann.
    $validierungsfehler = [];

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
    if ($blutalkoholkonzentration !== NULL) {
        if (!is_numeric($blutalkoholkonzentration)) {
            $validierungsfehler['blutalkoholkonzentration'] = 'Der BAK-Wert muss eine Dezimalzahl sein.';
        } else {
            $bak_float = (float)$blutalkoholkonzentration;
            if ($bak_float < 0.0 || $bak_float > 6.0) {
                $validierungsfehler['blutalkoholkonzentration'] = 'Der BAK-Wert muss zwischen 0,0 und 6,0 ‰ liegen.';
            }
        }
    }
    if ($punkte_flensburg < 0 || $punkte_flensburg > PUNKTE_EINGABE_MAX) {
        $validierungsfehler['punkte_flensburg'] = 'Die Flensburg-Punkte müssen zwischen 0 und ' . PUNKTE_EINGABE_MAX . ' liegen.';
    }
    // E-6: Wertebereiche aus config/screening_config.php – dieselbe Quelle, aus der
    // die Formulare ihre max-Attribute und Hinweistexte ziehen.
    $_screening_grenzen = require __DIR__ . '/../config/screening_config.php';
    $_audit_max = (int)$_screening_grenzen['audit']['max'];
    $_last_max  = (int)$_screening_grenzen['last']['max'];
    if ($audit_score !== NULL && ($audit_score < 0 || $audit_score > $_audit_max)) {
        $validierungsfehler['audit_score'] = "AUDIT-Score muss zwischen 0 und {$_audit_max} liegen.";
    }
    if ($last_score !== NULL && ($last_score < 0 || $last_score > $_last_max)) {
        $validierungsfehler['last_score'] = "LAST-Score muss zwischen 0 und {$_last_max} liegen.";
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

    // UX-6: ICD-Quick-Check blockiert die Aufnahme nicht mehr.
    // Beim Erstkontakt liegen die 6 Kriterien oft noch nicht vor; bewertet
    // werden kann nur, was bekannt ist. Fehlende Kriterien werden in
    // bearbeiten.php nachgetragen. Bis dahin gilt die Triage als vorläufig –
    // sichtbar gemacht über get_triage_vollstaendigkeit() in Akte und Listen.
    $hat_konsum_delikt = !empty(array_intersect($deliktarten, ['Alkohol', 'Drogen']));
    $icd_nicht_erhoben = array_filter($icd_werte, fn($v) => $v === null);
    $triage_vorlaeufig = $hat_konsum_delikt && !empty($icd_nicht_erhoben);

    // Override-Begründungspflicht (BK-5)
    if ($override_aktiv && empty($override_begruendung)) {
        $validierungsfehler['override_begruendung'] = 'Human Override aktiv: Eine Begründung ist Pflicht (Dokumentationspflicht nach BK-5).';
    }

    $validierungsfehler += validiere_gemeinsame_felder($_POST);

    if (!empty($validierungsfehler)) {
        $fehlermeldung = implode(' ', $validierungsfehler);
    }

    if (empty($fehlermeldung)) {
        // Triage erst nach Validierung berechnen – saubere Eingaben sind gesichert
        $triage_result = berechne_triage(
            delikte:            $deliktarten,
            drogen:             $drogenarten,
            bak:                (float)($blutalkoholkonzentration ?? 0),
            therapy:            (bool)$suchttherapie_absolviert,
            therapie_art:       $therapie_art,
            erschwerend:        (bool)$erschwerend_faktoren,
            repeat:             (bool)$fruehere_untersuchungen,
            mehrfachdelikt:     (bool)$mehrfachdelikt,
            symptoms:           (bool)$ausfallerscheinungen_notiert,
            punkte:             (int)$punkte_flensburg,
            aggression:         (bool)$aggressionspotenzial,
            thc_wert:           (float)($tetrahydrocannabinol_wert ?? 0),
            thc_cooh:           (float)($thc_cooh_value ?? 0),
            klassen:            $fuehrerscheinklassen,
            bkf_status:         $bkf_status,
            icd_kontrollverlust:$icd_werte['icd_kontrollverlust'] === 1,
            icd_priorisierung:  $icd_werte['icd_priorisierung']   === 1,
            icd_folgeschaeden:  $icd_werte['icd_folgeschaeden']   === 1,
            icd_craving:        $icd_werte['icd_craving']         === 1,
            icd_toleranz:       $icd_werte['icd_toleranz']        === 1,
            icd_entzug:         $icd_werte['icd_entzug']          === 1,
            persoenlichkeit:    (bool)$persoenlichkeit,
            medizinal_beikonsum:(bool)$medizinal_beikonsum
        );

        if ($override_aktiv) {
            // Override: Werte nur aus der validierten Dropdown-Liste akzeptieren
            $override_hyp  = trim($_POST['override_hypothese'] ?? '');
            $override_abst = trim($_POST['override_abstinenz'] ?? '');
            $triage_hypothese = in_array($override_hyp,  ERLAUBTE_HYPOTHESEN, true)
                ? $override_hyp
                : $triage_result['hypothese_text'];
            if (in_array($override_abst, ERLAUBTE_ABSTINENZ, true)) {
                $triage_abstinenz = $override_abst;
                $triage_result['abstinenz_monate'] = abstinenz_monate_aus_text($override_abst);
            } else {
                $triage_abstinenz = $triage_result['abstinenz_primaer'];
            }
        } else {
            $triage_hypothese = $triage_result['hypothese_text'];
            $triage_abstinenz = $triage_result['abstinenz_primaer'];
        }

        // Beide INSERTs in einer Transaktion – verhindert verwaiste Datensätze bei Fehler
        try {
            $pdo->beginTransaction();

            $sql_einfuegen = "INSERT INTO clients
                (first_name, last_name, date_of_birth, street, zip_code, city, phone, email,
                 gender, weight_kg, dsgvo_consent, dsgvo_consent_date, revocation_notice_confirmed, early_start_requested)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement = $pdo->prepare($sql_einfuegen);
            $statement->execute([
                $vorname, $nachname, $geburtsdatum, $strasse, $postleitzahl, $wohnort,
                $telefonnummer, $email_adresse, $geschlecht, $koerpergewicht,
                $datenschutz_zugestimmt, $datenschutz_zugestimmt ? date('Y-m-d') : null, $widerruf_bestaetigt, $vorzeitiger_leistungsbeginn
            ]);

            $neue_klienten_id = $pdo->lastInsertId();

            $sql_delikt = "INSERT INTO cases
                (client_id, offense_date, legal_validity_date, suspension_end_date, prior_mpu,
                 offense_category, license_classes, bkf_status, bak_value, symptoms_recorded, therapy_completed,
                 drug_types, thc_value, thc_cooh_value, flensburg_points, aggression_involved,
                 suggested_hypothesis, suggested_abstinence, abstinenz_monate, override_active, override_reason,
                 authority_name, authority_file_number, antrag_status, mpu_target_date,
                 package_type, package_price, hourly_rate,
                 dokument_akte, dokument_strafbefehl, dokument_register,
                 icd_kontrollverlust, icd_priorisierung, icd_folgeschaeden,
                 icd_craving, icd_toleranz, icd_entzug, mehrfachdelikt,
                 persoenlichkeit, medizinal_beikonsum, erschwerend_faktoren, therapie_art,
                 bussgeld_datum, einspruch_status, audit_score, last_score, payment_amount_paid)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $statement_delikt = $pdo->prepare($sql_delikt);
            $statement_delikt->execute([
                $neue_klienten_id, $datum_des_deliktes, $datum_rechtskraft, $sperrfrist_ende, $fruehere_untersuchungen,
                $deliktart_string, $fuehrerscheinklasse_string, $bkf_status, $blutalkoholkonzentration, $ausfallerscheinungen_notiert, $suchttherapie_absolviert,
                $drogenart_string, $tetrahydrocannabinol_wert, $thc_cooh_value, $punkte_flensburg, $aggressionspotenzial,
                $triage_hypothese, $triage_abstinenz, (int)$triage_result['abstinenz_monate'], $override_aktiv, $override_begruendung,
                $behoerde, $aktenzeichen, $antrag_status, $mpu_zielmonat,
                $abrechnungsmodell, $honorar_gesamt, $stundensatz,
                $dokument_akte, $dokument_strafbefehl, $dokument_register,
                $icd_kontrollverlust, $icd_priorisierung, $icd_folgeschaeden,
                $icd_craving, $icd_toleranz, $icd_entzug, $mehrfachdelikt,
                $persoenlichkeit, $medizinal_beikonsum, $erschwerend_faktoren, $therapie_art,
                $bussgeld_datum, $einspruch_status, $audit_score, $last_score, $payment_amount_paid
            ]);
            $neue_case_id = (int)$pdo->lastInsertId();

            audit_log($pdo, 'create_client', 'clients', (int)$neue_klienten_id, (int)$neue_klienten_id, "{$vorname} {$nachname}");
            audit_log($pdo, 'create_case', 'cases', $neue_case_id, (int)$neue_klienten_id, "Neue Akte angelegt: {$vorname} {$nachname}");
            $pdo->commit();
            // Weiterleiten: Sonst legt Neuladen denselben Klienten ein zweites Mal an (CC-45).
            $_SESSION['aufnahme_erfolg'] = [
                'klient' => (int)$neue_klienten_id, 'fall' => $neue_case_id, 'vorname' => $vorname, 'nachname' => $nachname,
                'vorlaeufig' => !empty($triage_vorlaeufig), 'icd_offen' => array_keys($icd_nicht_erhoben),
            ];
            header('Location: aufnahme.php', true, 303);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            // Sonst blendet die Seite das Formular aus, als wäre gespeichert worden (CC-43).
            unset($neue_klienten_id, $neue_case_id);
            $fehlermeldung = 'Fehler beim Speichern der Akte. Bitte versuchen Sie es erneut.';
        }
    }
}
