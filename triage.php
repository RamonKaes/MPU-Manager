<?php

declare(strict_types=1);

/**
 * Fachliche Grundlage: BK-5 (Stand 2026), Quellen siehe README.md.
 *
 * Änderungen an Schwellenwerten (BAK, THC, PR) oder Zeiträumen (Abstinenz)
 * sind nur zulässig, wenn eine entsprechende BK-5-Primärquelle (Kapitel/Seite)
 * vorliegt.
 */

/**
 * triage.php – Fachliche Triage-Engine (einzige Quelle der Wahrheit)
 *
 * Reine Funktionsbibliothek – kein HTTP-Code, keine Seiteneffekte.
 * HTTP-Endpunkt: triage_ajax.php (POST-Triage + GET ?action=config)
 *
 * Rückgabe von berechne_triage():
 *   hypothese_text    – kommagetrennte Hypothesen-Labels (für DB-Speicherung)
 *   abstinenz_primaer – primäre Abstinenzempfehlung (für DB-Speicherung)
 *   hypothesen        – vollständiges Array [{label, monate, abst, kt, warn, karenz}]
 *   warnungen         – aggregiertes Array aller Warnmeldungen (inkl. Mischkonsum etc.)
 *   kt_hinweise       – Array aller KT-Hinweise
 *   max_karenz        – maximale Karenzzeit in Monaten (für Fristen-Anzeige im JS)
 *   mischkonsum       – bool
 */
function berechne_triage(
    // ── Pflichtparameter (müssen vor allen optionalen stehen – PHP 8) ──────────
    array  $delikte,
    array  $drogen,
    float  $bak,
    bool   $therapy,
    bool   $repeat,
    bool   $mehrfachdelikt,
    bool   $symptoms,
    int    $punkte,
    bool   $aggression,
    float  $thc_wert,
    array  $klassen,
    string $bkf_status,
    ?bool  $icd_kontrollverlust,
    ?bool  $icd_priorisierung,
    ?bool  $icd_folgeschaeden,
    ?bool  $icd_craving,
    ?bool  $icd_toleranz,
    ?bool  $icd_entzug,
    // ── Optionale Parameter (Defaults) – alle Aufrufer nutzen Named Arguments ──
    string $therapie_art       = 'keine',
    bool   $erschwerend        = false,
    float  $thc_cooh           = 0.0,
    bool   $persoenlichkeit    = false,
    bool   $medizinal_beikonsum = false
): array {
    // ── Konfiguration (einmalig laden, dann gecacht) ──────────────────────────
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config/triage_config.php';
    }

    // Eine angegebene Therapieform ist eine Therapie, auch wenn der Haken dazu fehlt.
    $therapy           = $therapy || $therapie_art !== 'keine';
    $BKF_KLASSEN       = ['C','CE','C1','C1E','D','DE','D1','D1E'];
    $ist_aktiver_bkf   = ($bkf_status === 'aktiv');
    $ist_angestrebter  = ($bkf_status === 'angestrebt');
    $hat_bkf_klasse    = !empty(array_intersect($klassen, $BKF_KLASSEN));
    $bkf_klassen_liste = implode('/', array_values(array_intersect($klassen, $BKF_KLASSEN)));
    $icd_score         = (int)$icd_kontrollverlust + (int)$icd_priorisierung + (int)$icd_folgeschaeden
                       + (int)$icd_craving + (int)$icd_toleranz + (int)$icd_entzug;

    // § 24a StVG und BOKraft, Fundstellen in config/triage_config.php
    $recht_alkohol = '§ 24a StVG: ' . number_format((float)$cfg['stvg_24a']['promille'], 1, ',', '') . ' ‰ bzw. '
                   . number_format((float)$cfg['stvg_24a']['atem_mg_l'], 2, ',', '') . ' mg/l Atemalkohol; bei Fahrgastbeförderung Alkoholverbot im Dienst (§ 8 BOKraft)';
    $recht_thc     = '§ 24a Abs. 1a StVG gilt ab ' . number_format((float)$cfg['thc']['grenzwert_ng_ml'], 1, ',', '') . ' ng/ml, bei Fahrgastbeförderung zusätzlich § 8 BOKraft';
    $bkf_cannabis  = 'Trennverhalten ist bei nicht planbaren Einsätzen kritisch (BK-5 D 4.2 N). Die Software empfiehlt hier Abstinenz.';

    $hypothesen = [];

    // ── Alkohol ──────────────────────────────────────────────────────────────
    if (in_array('Alkohol', $delikte, true)) {
        if ($icd_score >= $cfg['icd']['abhaengigkeit_min']) {
            $giftfestigkeit = ($bak >= $cfg['bak']['giftfestigkeit'] && $symptoms);
            $warn_parts = ["ICD-Quick-Check: Kriterien für Hypothese A1 (Abhängigkeit) erreicht, keine Diagnose. Diagnostische Abklärung und ggf. Suchttherapie besprechen."];
            if ($giftfestigkeit) {
                $warn_parts[] = "Zusätzlich: Keine Ausfallerscheinungen trotz hoher BAK. Hinweis auf ausgeprägte Giftfestigkeit, die Wertung trifft der Gutachter.";
            }
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $warn_parts[] = "BKF: Soweit vorhanden, Fahrerlaubnis zur Fahrgastbeförderung und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.";
            }
            $a1_monate = $therapy
                ? $cfg['A1']['monate_therapie']
                : $cfg['A1']['monate_selbstheiler'];
            $a1_abst = $therapy
                ? "{$cfg['A1']['monate_therapie']} Monate (Abhängigkeit + Therapie; davon mind. 6 Monate nach Therapieabschluss – BK-5)"
                : "{$cfg['A1']['monate_selbstheiler']} Monate (Abhängigkeit – Selbstheiler-Regel BK-5)";
            $a1_warn = implode(' ', $warn_parts);
            if (!$therapy) {
                $a1_warn .= " SELBSTHEILER: Orientierung an der BK-5: {$cfg['A1']['monate_selbstheiler']} Monate Abstinenzbeleg.";
            }
            $hypothesen[] = [
                'label'  => "Hypothese A1 (Alkoholabhängigkeit – ICD-Kriterien erfüllt)",
                'monate' => $a1_monate,
                'abst'   => $a1_abst,
                'kt'     => "KT bei A1 grundsätzlich ausgeschlossen.",
                'warn'   => $a1_warn,
                'karenz' => $cfg['A1']['karenz'],
            ];
        } elseif ($icd_score === $cfg['icd']['gefaehrdung']) {
            // UX-3: Schwellen aus $cfg interpolieren statt in Prosa zu wiederholen –
            // sonst laufen Warnhinweis und Berechnung bei künftiger Config-Änderung auseinander.
            $warn_parts = ["ICD-Quick-Check: {$cfg['icd']['gefaehrdung']} von 6 Kriterien erfüllt – Abhängigkeitsschwelle (ICD-10: ≥ {$cfg['icd']['abhaengigkeit_min']} Kriterien) noch nicht erreicht. Kein automatisches A1. Suchtanamnese vertiefen; bei Zunahme der Symptome auf A1 anheben."];
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $warn_parts[] = "BKF: Erhöhte Gefährdung. Trennungsfähigkeit zwingend nachweisen.";
            }
            $a2g_monate = $therapy
                ? $cfg['A2_gefaehrdung']['monate_therapie']
                : $cfg['A2_gefaehrdung']['monate_selbstheiler'];
            $hypothesen[] = [
                'label'  => "Hypothese A2 / A3 (Alkoholgefährdung – Abhängigkeitsverdacht, {$cfg['icd']['gefaehrdung']} ICD-Kriterien)",
                'monate' => $a2g_monate,
                'abst'   => "{$a2g_monate} Monate (Abhängigkeitsgefährdung – konservative Empfehlung)",
                'kt'     => "KT kritisch zu prüfen – {$cfg['icd']['gefaehrdung']} ICD-Kriterien erfüllt. Gutachter können bei ungünstiger Anamnese dennoch auf Abstinenz bestehen.",
                'warn'   => implode(' ', $warn_parts),
                'karenz' => $cfg['A2_gefaehrdung']['karenz'],
            ];
        } elseif ($bak < 0.01) {
            $bkf_fehlt = ($ist_aktiver_bkf && $hat_bkf_klasse)
                ? " BKF: Bitte BAK-Wert unbedingt nachtragen!"
                : "";
            $ist_erschwerend = $repeat || $mehrfachdelikt;
            $hypothesen[] = [
                'label'  => $ist_erschwerend
                    ? "Hypothese A2 / A3 (Alkoholgefährdung – Wiederholungstäter / Mehrfachdelikt)"
                    : "Hypothese A3 / A4 (BAK nicht eingetragen – bitte nachtragen)",
                'monate' => $ist_erschwerend
                    ? $cfg['A_bak_fehlt_erschwerend']['monate']
                    : $cfg['A_bak_fehlt']['monate'],
                'abst'   => $ist_erschwerend
                    ? "{$cfg['A_bak_fehlt_erschwerend']['monate']} Monate (dringend empfohlen)"
                    : "Vorläufig {$cfg['A_bak_fehlt']['monate']} Monate – bitte BAK-Wert eintragen",
                'kt'     => ($ist_aktiver_bkf && $hat_bkf_klasse) ? "KT für aktive BKF ausgeschlossen." : "",
                'warn'   => "Hinweis: BAK-Wert fehlt. Triage ist nur ein Näherungswert – bitte BAK nachtragen." . $bkf_fehlt,
                'karenz' => $ist_erschwerend
                    ? $cfg['A_bak_fehlt_erschwerend']['karenz']
                    : $cfg['A_bak_fehlt']['karenz'],
            ];
        } elseif ($bak >= $cfg['bak']['a2_hoch']) {
            $bkf_warn = ($ist_aktiver_bkf && $hat_bkf_klasse)
                ? " BKF: Soweit vorhanden, Fahrerlaubnis zur Fahrgastbeförderung und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen."
                : "";
            $a2h_monate = $therapy
                ? $cfg['A2_hoch']['monate_therapie']
                : $cfg['A2_hoch']['monate_selbstheiler'];
            $hypothesen[] = [
                'label'  => "Hypothese A2 (Fortgeschrittene Problematik)",
                'monate' => $a2h_monate,
                'abst'   => $therapy
                    ? "{$cfg['A2_hoch']['monate_therapie']} Monate"
                    : "{$cfg['A2_hoch']['monate_selbstheiler']} Monate (Selbstheiler-Regel BK-5)",
                'kt'     => ($ist_aktiver_bkf && $hat_bkf_klasse) ? "KT für aktive BKF ausgeschlossen." : "",
                'warn'   => trim((!$therapy ? "Erhöhte Forderung ({$cfg['A2_hoch']['monate_selbstheiler']} Mon.), da keine Suchttherapie vorliegt." : "") . $bkf_warn
                    . ($symptoms ? " Keine Ausfallerscheinungen trotz {$bak} ‰: Hinweis auf ausgeprägte Giftfestigkeit, die Wertung trifft der Gutachter." : "")),
                'karenz' => $cfg['A2_hoch']['karenz'],
            ];
        } elseif ($bak >= $cfg['bak']['giftfestigkeit'] || $repeat || $mehrfachdelikt) {
            $a2g_monate = $therapy
                ? $cfg['A2_gefaehrdung']['monate_therapie']
                : $cfg['A2_gefaehrdung']['monate_selbstheiler'];
            if ($repeat && $bak < $cfg['bak']['giftfestigkeit']) {
                $warn = "Hinweis: Einstufung A2/A3 ({$a2g_monate} Mon.) erfolgte wegen der Vor-MPU, nicht wegen der BAK. Falls das Vordelikt andersartig war (z.B. Punkte), bitte per Human Override prüfen, ob A4 ({$cfg['A4']['monate']} Mon.) angemessener ist.";
            } elseif ($mehrfachdelikt && !$repeat && $bak < $cfg['bak']['giftfestigkeit']) {
                $warn = "Hinweis: Einstufung A2/A3 ({$a2g_monate} Mon.) erfolgte wegen des Mehrfachdelikts (wiederholte Auffälligkeit ohne Vor-MPU). Falls die Delikte unterschiedlicher Natur sind, bitte per Human Override prüfen.";
            } else {
                $warn = "";
            }
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $warn .= ($warn ? ' ' : '') . "BKF: Alkoholdelikt bei aktivem Berufskraftfahrer ({$recht_alkohol}). Soweit vorhanden, Fahrerlaubnis zur Fahrgastbeförderung und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.";
            }
            if ($bak >= $cfg['bak']['giftfestigkeit'] && $symptoms) {
                $warn .= ($warn ? ' ' : '') . "Keine Ausfallerscheinungen trotz {$bak} ‰: Hinweis auf ausgeprägte Giftfestigkeit, die Wertung trifft der Gutachter.";
            }
            $hypothesen[] = [
                'label'  => "Hypothese A2 / A3 (Alkoholgefährdung)",
                'monate' => $a2g_monate,
                'abst'   => "{$a2g_monate} Monate (dringend empfohlen)",
                'kt'     => ($ist_aktiver_bkf && $hat_bkf_klasse)
                    ? "KT für aktive BKF ausgeschlossen."
                    : "KT wird bei dieser Konstellation meist als unzureichend abgelehnt.",
                'warn'   => $warn,
                'karenz' => $cfg['A2_gefaehrdung']['karenz'],
            ];
        } else {
            // 0 < BAK < 2,0 ‰ (BK-5: giftfestigkeit-Schwelle auf 2,0 ‰ angehoben)
            // A3: BAK >= 1,1 ‰ (Gefährdung / gesteigerte Gewöhnung)
            // A4: BAK < 1,1 ‰ (Trennungsfehler / Missbrauch)
            // A3.1K Indikator 8 (BK-5 neu): BAK >= 1,6 ‰ → ausgeprägte Toleranz kennzeichnen
            $ist_a3 = ($bak >= $cfg['bak']['a3_grenze']);
            $a3_ausgepraegt_warn = ($ist_a3 && $bak >= $cfg['bak']['a3_ausgepraegt'])
                ? " Hinweis: BAK {$bak} ‰ liegt im Bereich ausgeprägte Toleranz (BK-5 A3.1K) – Gutachter prüfen, ob A2 trotz fehlender weiterer Indikatoren in Betracht kommt."
                : "";
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $hypothesen[] = [
                    'label'  => $ist_a3
                        ? "Hypothese A3 (BKF-Alkohol-Delikt)"
                        : "Hypothese A4 (BKF-Alkohol-Delikt)",
                    'monate' => $cfg['A4']['monate'],
                    'abst'   => "{$cfg['A4']['monate']} Monate – Trennungsfähigkeit Pflicht (kein KT vertretbar)",
                    'kt'     => "KT für aktive BKF ausgeschlossen: Gutachter erwartet vollständige Trennungsfähigkeit.",
                    'warn'   => "BAK {$bak} ‰ ({$recht_alkohol}). Trennungsfähigkeit muss lückenlos nachgewiesen werden. Soweit vorhanden, Fahrerlaubnis zur Fahrgastbeförderung und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen." . $a3_ausgepraegt_warn,
                    'karenz' => $cfg['A4']['karenz'],
                ];
            } else {
                $hypothesen[] = [
                    'label'  => $ist_a3
                        ? "Hypothese A3 (Alkoholgefährdung / Trennungsfehler)"
                        : "Hypothese A4 (Alkoholmissbrauch / Trennungsfehler)",
                    'monate' => $cfg['A4']['monate'],
                    'abst'   => "{$cfg['A4']['monate']} Monate (Nachweis stabiler Verhaltensänderung gem. BK-5, " . ($ist_a3 ? "A3" : "A4") . ")",
                    'kt'     => "Kontrolliertes Trinken (KT) möglich, bleibt aber ein Gutachterrisiko.",
                    'warn'   => $a3_ausgepraegt_warn,
                    'karenz' => $cfg['A4']['karenz'],
                ];
            }
        }
    }

    // ── Drogen ───────────────────────────────────────────────────────────────
    if (in_array('Drogen', $delikte, true)) {
        if ($icd_score >= $cfg['icd']['abhaengigkeit_min']) {
            $warn_parts = ["ICD-Quick-Check: Kriterien für Hypothese D1 (Abhängigkeit) erreicht, keine Diagnose. Diagnostische Abklärung und ggf. Suchttherapie besprechen."];
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $warn_parts[] = "BKF: Soweit vorhanden, Fahrerlaubnis zur Fahrgastbeförderung und Gefahrgutberechtigung prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.";
            }
            // Indikator 7 (stationär) → 15m (DGVM-FAQ ZVS 1.2024; Z-6 offen bis BK-5 Kap. B 3 vorliegt).
            // Indikator 10 (ambulant/Selbsth.) → 15m (BK-5 Kap. B 3). Vorwert 60m war ohne Primärquelle (Korrektur 14.04.2026).
            $d1_monate = ($therapie_art === 'stationaer')
                ? $cfg['D1']['monate_stationaer']
                : ($therapy ? $cfg['D1']['monate_therapie'] : $cfg['D1']['monate_selbstheiler']);
            $d1_abst = ($therapie_art === 'stationaer')
                ? "{$cfg['D1']['monate_stationaer']} Monate (stationäre Entwöhnung – mind. 6 Monate nach Therapieende – BK-5 Indikator 7)"
                : ($therapy
                    ? "{$cfg['D1']['monate_therapie']} Monate Gesamtabstinenz (ambulante Therapie – mind. 6 Monate nach Therapieabschluss – BK-5 Indikator 10)"
                    : "{$cfg['D1']['monate_selbstheiler']} Monate (Abhängigkeit ohne formale Therapie – BK-5 Indikator 10)"
                );
            $hypothesen[] = [
                'label'  => "Hypothese D1 (Drogenabhängigkeit – ICD-Kriterien erfüllt)",
                'monate' => $d1_monate,
                'abst'   => $d1_abst,
                'kt'     => ($therapie_art === 'stationaer')
                    ? "Stationäre Entwöhnung: Therapieabschluss-Nachweis + mind. 6 Monate forensische Abstinenzbelege danach (BK-5 Indikator 7)."
                    : "Abstinenz zwingend. Suchttherapie als Pflichtvoraussetzung. Bei Selbstheilung: 15 Monate Abstinenzbelege (BK-5 Indikator 10).",
                'warn'   => implode(' ', $warn_parts),
                'karenz' => $cfg['D1']['karenz'],
            ];
        } elseif ($icd_score === $cfg['icd']['gefaehrdung']) {
            // UX-3: Schwellen aus $cfg interpolieren statt in Prosa zu wiederholen –
            // sonst laufen Warnhinweis und Berechnung bei künftiger Config-Änderung auseinander.
            $warn_parts = ["ICD-Quick-Check: {$cfg['icd']['gefaehrdung']} von 6 Kriterien erfüllt – Abhängigkeitsschwelle (ICD-10: ≥ {$cfg['icd']['abhaengigkeit_min']} Kriterien) noch nicht erreicht. Kein automatisches D1. Suchtanamnese vertiefen; bei Zunahme auf D1 anheben."];
            if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                $warn_parts[] = "BKF: Harte Drogen-Gefährdung – sofortiger Handlungsbedarf. Vorgehen gegenüber der Fahrerlaubnisbehörde mit dem Klienten besprechen.";
            }
            $d2g_monate = $therapy
                ? $cfg['D2_gefaehrdung']['monate_therapie']
                : $cfg['D2_gefaehrdung']['monate_selbstheiler'];
            // Nachgetragene ICD-Kriterien dürfen nie senken, was das Delikt allein schon ergibt.
            if ($erschwerend || in_array('Alkohol', $delikte, true)) {
                foreach ([
                    [in_array('Kokain', $drogen, true), 'D2_kokain'],
                    [!empty(array_intersect(['Heroin', 'Opioide', 'Amphetamine', 'MDMA', 'Methadon', 'Fentanyl'], $drogen)), 'D2_hart'],
                    [in_array('Cannabis', $drogen, true) && $thc_cooh > $cfg['thc']['cooh_regelkonsum'], 'D2_cannabis'],
                ] as [$trifft_zu, $zweig]) {
                    if ($trifft_zu) {
                        $d2g_monate = max($d2g_monate, $cfg[$zweig]['erschwerend_monate']);
                    }
                }
            }
            $hypothesen[] = [
                'label'  => "Hypothese D2 / D3 (Drogenabhängigkeitsgefährdung – Verdacht, {$cfg['icd']['gefaehrdung']} ICD-Kriterien)",
                'monate' => $d2g_monate,
                'abst'   => "{$d2g_monate} Monate (Abhängigkeitsgefährdung – konservative Empfehlung)",
                'kt'     => "Abstinenz dringend empfohlen – {$cfg['icd']['gefaehrdung']} ICD-Kriterien erfüllt. Bei weiterer Symptomatik auf D1 anheben.",
                'warn'   => implode(' ', $warn_parts),
                'karenz' => $cfg['D2_gefaehrdung']['karenz'],
            ];
        } else {
            // D2-Erschwerend-Erkennung (BK-5 Kap. B 3, Indikatoren D 2.x-N)
            // Trennlinie: Standard 12m (keine erschwerenden Faktoren) /
            //             Erschwerend 15m (mind. ein Faktor: Mischkonsum mit Alkohol,
            //             gescheiterte Abstinenzversuche, schwerwiegende soziale Folgen)
            $ist_mischkonsum_d2   = in_array('Alkohol', $delikte, true);
            $ist_erschwerend_d2   = $erschwerend || $ist_mischkonsum_d2;
            if ($ist_mischkonsum_d2 && !$erschwerend) {
                $erschwerend_d2_warn = "Mischkonsum mit Alkohol erkannt – erschwerender Faktor (BK-5 D 2.x-N): 15 Monate Abstinenz.";
            } elseif (!$ist_erschwerend_d2) {
                $erschwerend_d2_warn = "Standardfall (12 Monate). Bei erschwerenden Faktoren (gescheiterte frühere Abstinenzversuche, schwerwiegende soziale Folgen) auf 15 Monate anheben."
                    . ($repeat ? " Vor-MPU erkannt – prüfen ob frühere Abstinenzversuche gescheitert sind." : "");
            } else {
                $erschwerend_d2_warn = "";
            }

            if (in_array('Kokain', $drogen, true)) {
                if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                    $warn = "BKF: Harte Drogen gefährden die Fahrerlaubnis zur Fahrgastbeförderung (§ 48 FeV) und die Gefahrgutberechtigung, bitte prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.";
                    $kt   = "BKF: Abstinenz zwingend – keine Gutachtertoleranz bei harten Drogen.";
                } elseif ($ist_angestrebter && $hat_bkf_klasse) {
                    $warn = "Neuerteilung BKF-Klasse angestrebt: Harte Drogen schließen die Fahreignung regelmäßig aus (Anlage 4 Nr. 9.1 FeV), zusätzlich Untersuchungen nach Anlage 5 FeV.";
                    $kt   = "";
                } else {
                    $warn = "";
                    $kt   = "";
                }
                $d2k_monate = $ist_erschwerend_d2
                    ? $cfg['D2_kokain']['erschwerend_monate']
                    : $cfg['D2_kokain']['standard_monate'];
                $d2k_suffix = $ist_erschwerend_d2 ? " – erschwerend" : " – Standard";
                $hypothesen[] = [
                    'label'  => "Hypothese D2 (Harte Drogen / Kokain)",
                    'monate' => $d2k_monate,
                    'abst'   => "{$d2k_monate} Monate (Kokain{$d2k_suffix} – BK-5 D 2.x-N)",
                    'kt'     => $kt,
                    'warn'   => trim($warn . ($warn && $erschwerend_d2_warn ? ' ' : '') . $erschwerend_d2_warn),
                    'karenz' => $cfg['D2_kokain']['karenz'],
                ];
            }
            // Harte Drogen (ohne Kokain) – generischer Zweig
            $HARTE_DROGEN = ['Heroin', 'Opioide', 'Amphetamine', 'MDMA', 'Methadon', 'Fentanyl'];
            $hat_sonstige_harte = !empty(array_intersect($HARTE_DROGEN, $drogen))
                && !in_array('Kokain', $drogen, true);
            if ($hat_sonstige_harte) {
                if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                    $hd_warn = "BKF: Harte Drogen gefährden die Fahrerlaubnis zur Fahrgastbeförderung (§ 48 FeV) und die Gefahrgutberechtigung, bitte prüfen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen.";
                    $hd_kt   = "BKF: Abstinenz zwingend – keine Gutachtertoleranz bei harten Drogen.";
                } elseif ($ist_angestrebter && $hat_bkf_klasse) {
                    $hd_warn = "Neuerteilung BKF-Klasse angestrebt: Harte Drogen schließen die Fahreignung regelmäßig aus (Anlage 4 Nr. 9.1 FeV), zusätzlich Untersuchungen nach Anlage 5 FeV.";
                    $hd_kt   = "";
                } else {
                    $hd_warn = "";
                    $hd_kt   = "";
                }
                $d2h_monate = $ist_erschwerend_d2
                    ? $cfg['D2_hart']['erschwerend_monate']
                    : $cfg['D2_hart']['standard_monate'];
                $d2h_suffix = $ist_erschwerend_d2 ? " – erschwerend" : " – Standard";
                $hypothesen[] = [
                    'label'  => "Hypothese D2 (Harte Drogen – Missbrauch)",
                    'monate' => $d2h_monate,
                    'abst'   => "{$d2h_monate} Monate (Harte Drogen{$d2h_suffix} – lückenlose Abstinenzdokumentation)",
                    'kt'     => $hd_kt,
                    'warn'   => trim($hd_warn . ($hd_warn ? ' ' : '') . "Harte Drogen: Lückenlose Abstinenzdokumentation erforderlich." . ($erschwerend_d2_warn ? ' ' . $erschwerend_d2_warn : '')),
                    'karenz' => $cfg['D2_hart']['karenz'],
                ];
            }

            if (in_array('Cannabis', $drogen, true)) {
                // Z-8: BK-5 D3-Vorbemerkung – „Ausschluss der Einordnung in D3 bei
                // Mischkonsum, wenn sich für eine Substanz ein Konsumverhalten
                // entsprechend D2 entwickelt hat." + D2-Vorbemerkung: „bei Mischkonsum
                // [führt] der jeweils problematischste Drogenkonsum zur Einordnung in D2
                // (keine selektive Betrachtung)." (Quelle: DGVP-Änderungsübersicht BK-5,
                // 03/2026; FAQ CanG 22.04.2024). Eine begleitende suchtpotente Substanz
                // (Kokain/Medikamente/Benzos/Opioide) oder Alkohol im Delikt gilt daher
                // als D2-fähiger Mischkonsum und schließt die günstige D3-/D4-Bewertung
                // (kontrollierter Konsum, Trennungsvermögen) aus.
                $begleit_droge_d2 = in_array('Kokain', $drogen, true)
                    || in_array('Medikamente', $drogen, true)
                    || in_array('Benzodiazepine', $drogen, true)
                    || !empty(array_intersect(['Methadon', 'Fentanyl', 'Opioide'], $drogen));
                $ist_mischkonsum_cannabis = $begleit_droge_d2 || in_array('Alkohol', $delikte, true);
                $nur_cannabis  = !$ist_mischkonsum_cannabis;
                $kann_trennung = $nur_cannabis && !($ist_aktiver_bkf && $hat_bkf_klasse);
                if ($thc_wert > 0) {
                    if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                        $thc_hinweis = "THC-Wert {$thc_wert} ng/ml: {$recht_thc}. {$bkf_cannabis}";
                    } elseif ($thc_wert > $cfg['thc']['trennvermoegen_ng_ml']) {
                        // Kein normativer Grenzwert, der Wert steuert nur diesen Text
                        $thc_hinweis = "THC-Wert {$thc_wert} ng/ml: Über {$cfg['thc']['trennvermoegen_ng_ml']} ng/ml spricht der Wert im Einzelfall gegen ein Trennvermögen, die Wertung trifft der Gutachter. Trennungsstrategie (D4) wird nicht vorgeschlagen.";
                    } elseif ($thc_wert >= $cfg['thc']['grenzwert_ng_ml']) {
                        $thc_hinweis = "THC-Wert {$thc_wert} ng/ml: Erreicht oder überschreitet den Grenzwert nach § 24a Abs. 1a StVG ({$cfg['thc']['grenzwert_ng_ml']} ng/ml). Trennungsvermögen fraglich.";
                    } else {
                        $thc_hinweis = "THC-Wert {$thc_wert} ng/ml: Unterhalb des Grenzwerts nach § 24a Abs. 1a StVG ({$cfg['thc']['grenzwert_ng_ml']} ng/ml). Trennungsstrategie ggf. vertretbar.";
                    }
                } elseif ($ist_aktiver_bkf && $hat_bkf_klasse) {
                    $thc_hinweis = "BKF: {$recht_thc}. {$bkf_cannabis}";
                } else {
                    $thc_hinweis = "";
                }
                if ($ist_aktiver_bkf && $hat_bkf_klasse) {
                    $kt = "BKF: {$bkf_cannabis}";
                } elseif ($ist_angestrebter && $hat_bkf_klasse) {
                    $kt = "Neuerteilung BKF-Klasse angestrebt: Zusätzlich Untersuchungen nach Anlage 5 FeV, Trennungsstrategie mit Gutachter abstimmen.";
                } elseif ($kann_trennung) {
                    $kt = "Hinweis: Bei reinem Cannabis-Delikt ohne Begleitsubstanzen kann ggf. eine Trennungsvermögen-Strategie geprüft werden. Individuelle Gutachter-Anforderungen beachten.";
                } else {
                    $kt = "";
                }
                if ($thc_cooh > $cfg['thc']['cooh_regelkonsum']) {
                    // D2: THC-COOH im Blutserum > 150 ng/ml spricht für regelmäßigen Konsum
                    // BK-5 Kap. B 3, D 2.x-N: Standard 12m / erschwerend 15m
                    $d2c_monate = $ist_erschwerend_d2
                        ? $cfg['D2_cannabis']['erschwerend_monate']
                        : $cfg['D2_cannabis']['standard_monate'];
                    $d2c_suffix = $ist_erschwerend_d2 ? " – erschwerend" : " – Standard";
                    $d2c_warn   = trim(($thc_hinweis ? $thc_hinweis . ' ' : '') . $erschwerend_d2_warn);
                    $hypothesen[] = [
                        'label'  => "Hypothese D2 (Cannabis-Regelkonsum, THC-COOH im Serum > {$cfg['thc']['cooh_regelkonsum']} ng/ml)",
                        'monate' => $d2c_monate,
                        'abst'   => "{$d2c_monate} Monate (Cannabis-Regelkonsum{$d2c_suffix} – BK-5 D 2.x-N)",
                        'kt'     => $kt,
                        'warn'   => $d2c_warn,
                        'karenz' => $cfg['D2_cannabis']['karenz'],
                    ];
                } elseif ($thc_cooh > $cfg['thc']['cooh_gefaehrdung']) {
                    // D3: häufigerer Konsum (Positionspapier DGVP/DGVM Nr. 12: ab 75 häufiger, ab 150 regelmäßig, jeweils Blutserum)
                    $warn_cooh = "THC-COOH {$thc_cooh} ng/ml im Blutserum (Bereich {$cfg['thc']['cooh_gefaehrdung']}–{$cfg['thc']['cooh_regelkonsum']} ng/ml): Spricht für häufigeren Konsum, die Wertung trifft der Gutachter. Trennungsstrategie (D4) wird nicht vorgeschlagen.";
                    $hypothesen[] = [
                        'label'  => "Hypothese D3 (Cannabis – häufigerer Konsum, THC-COOH {$thc_cooh} ng/ml)",
                        'monate' => $cfg['D3_cannabis']['monate'],
                        'abst'   => "{$cfg['D3_cannabis']['monate']} Monate (Cannabis, häufigerer Konsum – THC-COOH {$cfg['thc']['cooh_gefaehrdung']}–{$cfg['thc']['cooh_regelkonsum']} ng/ml im Serum)",
                        'kt'     => $kann_trennung
                            ? "Trennungsstrategie bei THC-COOH über {$cfg['thc']['cooh_gefaehrdung']} ng/ml kritisch – individuelle Gutachter-Entscheidung."
                            : ($kt ?: ""),
                        'warn'   => $warn_cooh . ($thc_hinweis ? ' ' . $thc_hinweis : ''),
                        'karenz' => $cfg['D3_cannabis']['karenz'],
                    ];
                } elseif ($kann_trennung && $thc_wert > 0.0 && $thc_wert < $cfg['thc']['grenzwert_ng_ml']) {
                    // D4: THC < 3,5 ng/ml (§ 24a Abs. 1a StVG gilt ab 3,5), reines Cannabis-Delikt, kein BKF, COOH ≤ 75 ng/ml → Trennungsvermögen
                    $hypothesen[] = [
                        'label'  => "Hypothese D4 (Cannabis-Trennungsvermögen, THC {$thc_wert} ng/ml < {$cfg['thc']['grenzwert_ng_ml']} ng/ml)",
                        'monate' => $cfg['D4']['monate'],
                        'abst'   => "Kein Abstinenzgebot – Nachweis stabilen Trennvermögens (Grenzwert nach § 24a Abs. 1a StVG eingehalten – BK-5)",
                        'kt'     => $kt,
                        'warn'   => $thc_hinweis,
                        'karenz' => $cfg['D4']['karenz'],
                    ];
                } else {
                    // Reines Cannabis ohne BKF: Der Weg ohne Abstinenz setzt Laborwerte voraus, die dazu passen.
                    if ($kann_trennung && $thc_wert <= 0.0 && $thc_cooh <= 0.0) {
                        // Gegenstück zu „BAK nicht eingetragen“: Ohne Werte gibt es keine günstige Einstufung.
                        $hypothesen[] = [
                            'label'  => "Hypothese D3 (Cannabis – Laborwerte nicht eingetragen, bitte nachtragen)",
                            'monate' => $cfg['D3_cannabis']['monate'],
                            'abst'   => "Vorläufig {$cfg['D3_cannabis']['monate']} Monate – bitte THC- und THC-COOH-Wert eintragen",
                            'kt'     => $kt,
                            'warn'   => "Hinweis: THC- und THC-COOH-Wert fehlen. Die Einstufung ist vorläufig. Mit den Werten kann sich ergeben, dass kein Abstinenzgebot besteht.",
                            'karenz' => $cfg['D3_cannabis']['karenz'],
                        ];
                    } elseif ($kann_trennung && $thc_wert >= $cfg['thc']['grenzwert_ng_ml']) {
                        $hypothesen[] = [
                            'label'  => "Hypothese D3 (Cannabis – Trennvermögen fraglich, THC {$thc_wert} ng/ml)",
                            'monate' => $cfg['D3_cannabis']['monate'],
                            'abst'   => "{$cfg['D3_cannabis']['monate']} Monate (Cannabis-Gefährdung – BK-5)",
                            'kt'     => $kt,
                            'warn'   => $thc_hinweis,
                            'karenz' => $cfg['D3_cannabis']['karenz'],
                        ];
                    } elseif ($kann_trennung) {
                        // BK-5 Kap. B 3: Kontrollierter Konsum als Option, stabile Änderung vorausgesetzt
                        $d3_dauer  = $cfg['D3_cannabis']['monate'];
                        $d3_belege = $cfg['D3_cannabis']['belege_monate_vor_begutachtung'];
                        $hypothesen[] = [
                            'label'  => "Hypothese D3 (Cannabis – kontrollierter Konsum möglich, BK-5)",
                            'monate' => 0,
                            'abst'   => "Kein Abstinenzgebot – Konsumänderung über zumindest {$d3_dauer} Monate, toxikologische Belege {$d3_belege} Monate vor der Begutachtung (BK-5 D 3.6 N, am Buch zu prüfen).",
                            'kt'     => "BK-5 Kap. B 3: Kontrollierter Cannabiskonsum als Option. Nachweis über Konsumtagebuch und toxikologische Belege. Individuelle Gutachter-Anforderungen beachten.",
                            'warn'   => $thc_wert <= 0.0 ? "Hinweis: THC-Wert fehlt. Bitte nachtragen, die Einstufung hängt daran." : $thc_hinweis,
                            'karenz' => $cfg['D3_cannabis']['karenz'],
                        ];
                    } else {
                        $misch_warn = $ist_mischkonsum_cannabis
                            ? "Mischkonsum erkannt: Neben Cannabis liegt eine weitere suchtpotente Substanz bzw. Alkohol vor. Nach BK-5 (D3-Vorbemerkung) ist bei Mischkonsum mit D2-Muster keine D3-Lockerung (kontrollierter Konsum) möglich – es gilt der jeweils problematischste Konsum (keine selektive Betrachtung)."
                            : "";
                        $hypothesen[] = [
                            'label'  => "Hypothese D3 (Cannabis-Gefährdung)",
                            'monate' => $cfg['D3_cannabis']['monate'],
                            'abst'   => "{$cfg['D3_cannabis']['monate']} Monate (Cannabis-Gefährdung – BK-5)",
                            'kt'     => $kt,
                            'warn'   => trim($misch_warn . ($thc_hinweis ? ' ' . $thc_hinweis : '')),
                            'karenz' => $cfg['D3_cannabis']['karenz'],
                        ];
                    }
                }
            }
        }
    }

    // ── M1: Medizinalcannabis (BTM-Rezept, BK-5 Kap. B 5) ────────────────────
    if (in_array('Medizinalcannabis', $drogen, true)) {
        $m1_warn = 'Leistungstest erforderlich: PR ≥ 16 (Gruppe 1) / PR ≥ 33 (BKF). '
                 . 'Kein Abstinenzgebot, aber stabiler Medikationsverlauf dokumentieren.';
        if ($ist_aktiver_bkf && $hat_bkf_klasse) {
            $m1_warn .= ' BKF: PR ≥ 33 in Mehrzahl der Tests zwingend erforderlich.';
        }
        // Beikonsum (nicht verordneter Konsum neben der Verordnung) kann nach Anlage 4 Nr. 9.2.1 FeV
        // Zweifel an der Trennung begründen. Das ist eine Einzelfallfrage, die Verordnung selbst
        // fällt unter Nr. 9.6. § 11 Abs. 7 FeV gilt nur, wenn die Nichteignung feststeht.
        $m1_abst = 'Kein Abstinenzgebot – Leistungstest und Medikationsnachweise erforderlich (BK-5 Kap. B 5)';
        $m1_kt   = 'Nachweis stabiler Medikamenteneinnahme, ärztliche Verordnung und Fahrtauglichkeit unter Medizinalcannabis zwingend.';
        if ($medizinal_beikonsum) {
            $m1_warn = 'ACHTUNG – Beikonsum: Nicht verordneter Cannabis-Konsum neben der Verordnung kann fehlende '
                     . 'Trennung nach Anlage 4 Nr. 9.2.1 FeV begründen. Das ist eine Einzelfallfrage. Steht die '
                     . 'Nichteignung fest, unterbleibt die MPU-Anordnung (§ 11 Abs. 7 FeV). Bitte den Fall prüfen '
                     . 'und einen Belegzeitraum für das Ende des Beikonsums per Override festlegen.';
            $m1_abst = 'Noch offen – Belegzeitraum für das Ende des nicht verordneten Konsums per Override festlegen.';
            $m1_kt   = 'Ende des Beikonsums belegen, zusätzlich ärztliche Verordnung und Fahrtauglichkeit unter der verordneten Medikation.';
        }
        $hypothesen[] = [
            'label'  => 'Hypothese M1 (Medizinalcannabis – verschreibungspflichtig)',
            'monate' => $cfg['M1']['monate'],
            'abst'   => $m1_abst,
            'kt'     => $m1_kt,
            'warn'   => $m1_warn,
            'karenz' => $cfg['M1']['karenz'],
        ];
    }

    // ── M2: Fehlgebrauch von Benzodiazepinen (BK-5 Kap. B 5) ─────────────────
    if (in_array('Benzodiazepine', $drogen, true)) {
        $m2_monate = $therapy ? $cfg['M2']['monate_therapie'] : $cfg['M2']['monate_selbstheiler'];
        $hypothesen[] = [
            'label'  => 'Hypothese M2 (Fehlgebrauch von Arzneimitteln – Benzodiazepine)',
            'monate' => $m2_monate,
            'abst'   => "{$m2_monate} Monate Abstinenz (BK-5 Kap. B 5 – analog D-Kriterien)",
            'kt'     => 'Psychiatrische Stellungnahme und lückenloser Abstinenznachweis erforderlich.',
            'warn'   => 'Benzodiazepine: Abstinenz + psychiatrische Stellungnahme erforderlich. Bei Abhängigkeit M3 per Override setzen.',
            'karenz' => $cfg['M2']['karenz'],
        ];
    }

    // ── M1: Opioid-/Methadon-Dauermedikation (BK-5 Kap. B 5) ────────────────
    $OPIOID_STOFFE = ['Methadon', 'Fentanyl', 'Opioide'];
    if (!empty(array_intersect($OPIOID_STOFFE, $drogen))) {
        $hypothesen[] = [
            'label'  => 'Hypothese M1 (Opioid-/Methadon-Dauermedikation)',
            'monate' => $cfg['M3']['monate'],
            'abst'   => 'Kein Abstinenzgebot – Compliance-Nachweis Substitution und Nachweis stabiler Fahreignung (BK-5 Kap. B 5)',
            'kt'     => 'Ärztliche Verordnung, lückenlose Compliance-Dokumentation und Nachweis stabiler Fahreignung zwingend erforderlich.',
            'warn'   => 'Substitutionsbehandlung: Compliance-Nachweis und Legalität der Verordnung nachweisen. '
                      . 'Kein Abstinenzgebot, aber Fahrtüchtigkeit unter Substitution zu belegen.',
            'karenz' => $cfg['M3']['karenz'],
        ];
    }

    // ── Generische Dauermedikation (Fallback wenn kein spezifisches M erkannt) ─
    if (in_array('Medikamente', $drogen, true)
        && !in_array('Medizinalcannabis', $drogen, true)
        && !in_array('Benzodiazepine', $drogen, true)
        && empty(array_intersect($OPIOID_STOFFE, $drogen))
    ) {
        $med_warn = 'Medikamente erkannt, noch keine Dauer berechnet: Bitte Hypothese M1, M2 oder M3 per Override setzen. Benzodiazepine und andere suchtpotente Mittel können eine Abstinenz erfordern.';
        if ($medizinal_beikonsum) {
            // Anlage 4 Nr. 9.2.1 FeV (Cannabis) bzw. Nr. 9.4 FeV (sonstige psychoaktive Arzneimittel), siehe M1-Zweig
            $med_warn = 'ACHTUNG – Beikonsum: Nicht verordneter Konsum neben der Medikation kann fehlende Trennung nach '
                      . 'Anlage 4 Nr. 9.2.1 FeV (Cannabis) bzw. Fehlgebrauch nach Nr. 9.4 FeV begründen. Das ist eine '
                      . 'Einzelfallfrage. Steht die Nichteignung fest, unterbleibt die MPU-Anordnung (§ 11 Abs. 7 FeV). '
                      . 'Hypothese und Belegzeitraum bitte per Override setzen.';
        }
        $hypothesen[] = [
            'label'  => 'Hypothese M (Medikamente – Zuordnung M1 bis M3 offen)',
            'monate' => $cfg['M']['monate'],
            'abst'   => 'Noch offen – Zuordnung M1 bis M3 per Override',
            'kt'     => 'Nachweis der ärztlichen Verordnung, Compliance und regelmäßigen Kontrollen zwingend erforderlich!',
            'warn'   => $med_warn,
            'karenz' => $cfg['M']['karenz'],
        ];
    }

    // ── Punkte / Verkehrsrecht ────────────────────────────────────────────────
    if (in_array('Punkte', $delikte, true)) {
        $v_warn = "";
        if ($punkte >= $cfg['punkte']['schwer']) {
            $v_warn = "{$punkte} Punkte: Die Fahrerlaubnis ist ab {$cfg['punkte']['schwer']} Punkten zu entziehen (§ 4 Abs. 5 StVG). Gutachter erwartet tiefgreifende Verhaltensänderung.";
        } elseif ($punkte >= $cfg['punkte']['kritisch']) {
            $v_warn = "{$punkte} Punkte: Stufe der Verwarnung, Entziehung ab {$cfg['punkte']['schwer']} Punkten (§ 4 Abs. 5 StVG). Intensiver Einstellungscheck erforderlich.";
        }
        if ($aggression) {
            $v_warn .= ($v_warn ? ' ' : '') . "Aggressionspotenzial dokumentiert (Körperverletzung / Nötigung): Empfehle psychologische Zusatzbegutachtung.";
        }
        if ($ist_aktiver_bkf && $hat_bkf_klasse) {
            $v_warn .= ($v_warn ? ' ' : '') . "BKF: Punktedelikt kann, soweit vorhanden, auch die Fahrerlaubnis zur Fahrgastbeförderung und die Gefahrgutberechtigung betreffen. Mögliche Folgen für die Beschäftigung mit dem Klienten besprechen. Untersuchung nach Anlage 5 FeV für Neuerteilung erforderlich.";
        } elseif ($ist_angestrebter && $hat_bkf_klasse) {
            $v_warn .= ($v_warn ? ' ' : '') . "Neuerteilung BKF-Klasse {$bkf_klassen_liste} angestrebt: Die Punktehistorie kann die Neuerteilung erschweren, zusätzlich Untersuchungen nach Anlage 5 FeV.";
        }
        $hypothesen[] = [
            'label'  => $aggression
                ? "Hypothese V3 (Straftaten im Straßenverkehr – Aggression)"
                : "Hypothese V2 / V3 (Verkehrsrecht)",
            'monate' => $cfg['V']['monate'],
            'abst'   => "Keine chemischen Belege erforderlich",
            'kt'     => "Radikaler Einstellungscheck und Verhaltensänderung im Vordergrund.",
            'warn'   => $v_warn,
            'karenz' => $aggression ? $cfg['V']['karenz_aggression'] : $cfg['V']['karenz_standard'],
        ];
    }

    // ── V1: Persönlichkeitsproblematik ────────────────────────────────────────
    if ($persoenlichkeit) {
        $hypothesen[] = [
            'label'  => "Hypothese V1 (Persönlichkeits-Problematik)",
            'monate' => $cfg['V1']['monate'],
            'abst'   => "Kein Abstinenzgebot – 12 Monate nachgewiesener Veränderungsprozess erforderlich (BK-5 Kap. B 4)",
            'kt'     => "Tiefgreifende psychologische Begutachtung zwingend. Verhaltens- und Einstellungsänderung über mind. 12 Monate dokumentieren.",
            'warn'   => "V1 (Persönlichkeitsproblematik) erkannt: schwere Gewalt- oder Sexualdelikte im Straßenverkehr. Gutachter erwartet lückenlose Dokumentation des Veränderungsprozesses.",
            'karenz' => $cfg['V1']['karenz'],
        ];
    }

    // ── Leeres Ergebnis ───────────────────────────────────────────────────────
    if (empty($hypothesen)) {
        return [
            'hypothese_text'    => '',
            'abstinenz_primaer' => '',
            'abstinenz_monate'  => 0,
            'hypothesen'        => [],
            'warnungen'         => [],
            'kt_hinweise'       => [],
            'max_karenz'        => 3,
            'mischkonsum'       => false,
        ];
    }

    // ── Primäre Abstinenz: Maximum ────────────────────────────────────────────
    $monate_werte = array_column($hypothesen, 'monate');
    $max_monate   = max($monate_werte);
    $haupt        = null;
    foreach ($hypothesen as $h) {
        if ($h['monate'] === $max_monate) { $haupt = $h; break; }
    }
    $primar_abstinenz = $haupt ? $haupt['abst'] : '';
    $mischkonsum = in_array('Alkohol', $delikte, true) && in_array('Drogen', $delikte, true);
    if ($mischkonsum && $max_monate > 0) {
        $primar_abstinenz = "{$max_monate} Monate (Mischkonsum – Maximum aller Deliktarten)";
    }

    // ── Warnungen & KT-Hinweise aggregieren ──────────────────────────────────
    $warnungen = array_values(array_filter(array_column($hypothesen, 'warn')));
    if ($mischkonsum) {
        $warnungen[] = "Mischkonsum erkannt: Massiver Erschwerungsgrund gemäß BK-5!";
        $nicht_dominante = array_filter($hypothesen, fn($h) => $h['monate'] > 0 && $h['monate'] < $max_monate);
        if (!empty($nicht_dominante)) {
            $kuerzere_labels = implode(' und ', array_column(array_values($nicht_dominante), 'label'));
            $warnungen[] = 'Hinweis zur dominierenden Hypothese: Die ' . $max_monate . ' Monate ergeben sich aus "' . $haupt['label'] . '". ' . $kuerzere_labels . ' würde(n) für sich allein eine kürzere Abstinenzzeit erfordern.';
        }
    }
    if ($repeat) {
        $warnungen[] = "Achtung Wiederholungstäter (Zweit-MPU): Gutachter fordern oft striktere Abstinenzzeiträume und tiefere Aufarbeitungen, als das reine Delikt vermuten lässt.";
    }
    if ($mehrfachdelikt) {
        $warnungen[] = "Mehrfachdelikt ohne Vor-MPU: Der Gutachter wertet die wiederholte Auffälligkeit als erschwerendes Merkmal. Besonders stabile Abstinenzdokumentation und glaubhafte Einsicht einplanen.";
    }
    if ($ist_aktiver_bkf && $hat_bkf_klasse && in_array('Alkohol', $delikte, true)) {
        $warnungen[] = "Aktiver BKF (Kl. {$bkf_klassen_liste}): {$recht_alkohol}. Fahrerlaubnis zur Fahrgastbeförderung und Gefahrguttransport separat prüfen.";
    } elseif ($ist_angestrebter && $hat_bkf_klasse) {
        $warnungen[] = "Neuerteilung Kl. {$bkf_klassen_liste} angestrebt: Zusätzliche Untersuchung nach Anlage 5 FeV erforderlich.";
    }

    // ── ICD-Score = 1: Abhängigkeitsverdacht ─────────────────────────────────
    // Score >= 3 führt zu A1/D1, Score = 2 erzeugt eigene Gefährdungs-Hypothese.
    // Score = 1 liegt unter beiden Schwellen – als Warnsignal dokumentieren (BK-5, Kap. 3.11).
    if ($icd_score === 1) {
        $warnungen[] = "ICD-Quick-Check: Ein Abhängigkeitskriterium erfüllt – Abhängigkeitsverdacht nicht ausgeschlossen. Suchtanamnese in den Sitzungen vertiefen; bei Zunahme der Kriterien Hypothese auf A1/D1 anheben.";
    }

    // Die BKF-Regeln hängen an der Klasse. Taxi und Mietwagen laufen oft auf Klasse B.
    if ($ist_aktiver_bkf && !$hat_bkf_klasse) {
        $warnungen[] = "Aktiver Berufskraftfahrer ohne Klasse C oder D erfasst: Die strengeren BKF-Regeln sind hier nicht angewendet. Bei Fahrgastbeförderung mit Klasse B (z. B. Taxi) bitte Einstufung prüfen und ggf. per Override anpassen.";
    }

    // ── Alkohol + Punkte: doppelte Auffälligkeit ─────────────────────────────
    if (in_array('Alkohol', $delikte, true) && in_array('Punkte', $delikte, true)) {
        $warnungen[] = "Alkohol + Punkte-Kombination: Doppelte Auffälligkeit im Straßenverkehr. Gutachter prüfen Suchtproblematik UND Einstellungsmuster unabhängig voneinander – beide Bereiche müssen biografisch vollständig aufgearbeitet werden.";
    }

    return [
        'hypothese_text'    => implode(', ', array_column($hypothesen, 'label')),
        'abstinenz_primaer' => $primar_abstinenz,
        'abstinenz_monate'  => $max_monate,
        'hypothesen'        => $hypothesen,
        'warnungen'         => $warnungen,
        'kt_hinweise'       => array_values(array_filter(array_column($hypothesen, 'kt'))),
        'max_karenz'        => max(array_column($hypothesen, 'karenz')),
        'mischkonsum'       => $mischkonsum,
    ];
}
