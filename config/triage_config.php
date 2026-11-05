<?php
declare(strict_types=1);

/**
 * config/triage_config.php – Fachliche Schwellenwerte der Triage-Engine
 * -----------------------------------------------------------------------
 * Alle Magic Numbers aus triage.php sind hier zentral hinterlegt.
 *
 * ÄNDERUNGEN NUR MIT BK-5-PRIMÄRQUELLE.
 *
 * Letztes Update: 2026-04-14 (BK-5 Migration; D3 Cannabis kontr. Konsum)
 */

return [

    // ── ICD-10-Score-Schwellen ────────────────────────────────────────────────
    // >= abhaengigkeit_min → A1/D1 (Abhängigkeit)
    // == gefaehrdung       → A2/D2 (Gefährdung, zwei Kriterien)
    // == 1                 → nur Warnhinweis (kein eigener Hypothesen-Zweig)
    'icd' => [
        'abhaengigkeit_min' => 3,
        'gefaehrdung'       => 2,
    ],

    // ── BAK-Schwellen (Promille) ──────────────────────────────────────────────
    // BK-5 Kap. B 1.1 (Änderung A 2.2 N Indikator 4: Schwellen von 2,0/1,6 auf 2,5/2,0 ‰ angehoben)
    'bak' => [
        'a2_hoch'        => 2.5,  // >= 2.5 ‰ → A2 fortgeschrittene Problematik (BK-5 obere Schwelle)
        'giftfestigkeit' => 2.0,  // >= 2.0 ‰ → A2-Toleranzindikator (BK-5; war BK-4: 1.6 ‰)
        'a3_ausgepraegt' => 1.6,  // >= 1.6 ‰ → A3 ausgeprägte Toleranz (BK-5 A3.1K Indikator 8)
        'a3_grenze'      => 1.1,  // >= 1.1 ‰ → A3 (Gefährdung); darunter → A4
    ],

    // ── THC / Cannabis-Schwellen ──────────────────────────────────────────────
    // BK-5 Kap. B 3 + DGVM/DGVP FAQ (CanG, 05.07.2024)
    // § 24a Abs. 1 StVG (Ordnungswidrigkeit), geprüft am Gesetzestext am 04.11.2026:
    // "0,25 mg/l oder mehr Alkohol in der Atemluft oder 0,5 Promille oder mehr Alkohol im Blut".
    // Eine Sonderregel für Berufskraftfahrer enthält die Norm nicht. Für Fahrgastbeförderung
    // gilt im Dienst das Alkoholverbot aus § 8 Abs. 3 BOKraft.
    'stvg_24a' => [
        'promille'  => 0.5,
        'atem_mg_l' => 0.25,
    ],

    // § 13 Satz 1 Nr. 2 Buchst. c FeV: MPU-Anlass ab diesem Wert. Gilt für jedes Fahrzeug, wichtig beim
    // Fahrrad. E-Scooter sind Kraftfahrzeuge (§ 1 eKFV), für sie gilt zusätzlich § 24a StVG.
    'fev_13' => [
        'promille' => 1.6,
    ],

    'thc' => [
        // § 24a Abs. 1a StVG: Der Grenzwert gilt ab "3,5 ng/ml oder mehr". D4 setzt deshalb THC < 3,5 voraus.
        'grenzwert_ng_ml'    => 3.5,
        'trennvermoegen_ng_ml' => 11.0, // Kein normativer Grenzwert: Darüber spricht der Wert im Einzelfall gegen Trennvermögen (Warntext)
        'cooh_gefaehrdung'   => 75.0,  // im Blutserum (Positionspapier DGVP/DGVM Nr. 12): häufigerer Konsum, D3, D4 wird nicht vorgeschlagen
        'cooh_regelkonsum'   => 150.0, // im Blutserum (Positionspapier DGVP/DGVM Nr. 12): regelmäßiger Konsum → D2
    ],

    // ── Punkte-Schwellen (Fahreignungsregister) ───────────────────────────────
    'punkte' => [
        'schwer'   => 8, // >= 8 → Fahrerlaubnis ist zu entziehen (§ 4 Abs. 5 StVG)
        'kritisch' => 7, // >= 7 → eine weitere Zuwiderhandlung kann zur Entziehung führen (§ 4 Abs. 5 StVG: ab 8)
        'eingabe_max' => 99, // Nur Plausibilität der Eingabe. Das Register kann über 8 Punkte liegen.
    ],

    // ── Alkohol ───────────────────────────────────────────────────────────────

    // A1: Alkoholabhängigkeit (ICD-Score >= 3)
    // BK-5 Kap. B 1.1, Kriterium A 1.3 N – einheitlich 15 Monate
    // Mit Therapie: Gesamtzeitraum mind. 15 Monate, davon mind. 6 nach Therapieabschluss.
    // Selbstheiler: ebenfalls 15 Monate (BK-5 bestätigt BK-4-Präzisierung).
    'A1' => [
        'monate_therapie'     => 15,
        'monate_selbstheiler' => 15,
        'karenz'              => 9,
    ],

    // A2 fortgeschrittene Problematik (BAK >= 2.5 ‰)
    // BK-5 Kap. B 1.1, Kriterien A 2.1 N – A 2.7 N
    'A2_hoch' => [
        'monate_therapie'     => 12,
        'monate_selbstheiler' => 15,
        'karenz'              => 6,
    ],

    // A2/A3 Gefährdung (ICD-Score == 2 oder BAK >= 1.6 ‰ / Wiederholungstäter)
    // BK-5 Kap. B 1.1: Normalfall 12 Monate; Selbstheiler 15 Monate
    'A2_gefaehrdung' => [
        'monate_therapie'     => 12,
        'monate_selbstheiler' => 15,
        'karenz'              => 6,
    ],

    // A4: Trennungsvermögen / Missbrauch (0 < BAK < 1.6 ‰, keine Erschwerung)
    // BK-5 Kap. B 1.1, Hypothese A4 – 6 Monate Nachweis stabiler Trennstrategien
    'A4' => [
        'monate' => 6,
        'karenz' => 3,
    ],

    // A2/A3 bei fehlendem BAK-Wert (Näherung)
    'A_bak_fehlt_erschwerend' => [
        'monate' => 12,
        'karenz' => 6,
    ],
    'A_bak_fehlt' => [
        'monate' => 6,
        'karenz' => 3,
    ],

    // ── Drogen ────────────────────────────────────────────────────────────────

    // D1: Drogenabhängigkeit (ICD-Score >= 3)
    // BK-5 Kap. B 3 + DGVM/DGVP FAQ + Validierungsdossier April 2026
    // Indikator 7 (stat./amb. Therapie) + Indikator 10 (Selbstheiler): einheitlich 15 Monate.
    // therapie_art='stationaer' steuert nur die Formulierung des Abstinenzbelegs, nicht die Dauer.
    'D1' => [
        'monate_stationaer'   => 15,  // BK-5 Ind. 7: 15m gesamt, davon mind. 6m nach Therapieende (OVG/DGVM-FAQ ZVS 1.2024 bestätigt)
        'monate_therapie'     => 15,  // BK-5 Ind. 10: ambulante Therapie
        'monate_selbstheiler' => 15,  // BK-5 Ind. 10: ohne formale Therapie
        'karenz'              => 9,
    ],

    // D2/D3 Gefährdung (ICD-Score == 2)
    // BK-5 Kap. B 3: analog A2 – 12m (Therapie) / 15m (Selbstheiler)
    'D2_gefaehrdung' => [
        'monate_therapie'     => 12,
        'monate_selbstheiler' => 15,
        'karenz'              => 6,
    ],

    // D2 Harte Drogen (Kokain)
    // BK-5 Kap. B 3, D 2.x-N: Standard 12m / erschwerend 15m (korrigiert 26.04.2026)
    'D2_kokain' => [
        'standard_monate'    => 12,
        'erschwerend_monate' => 15,
        'karenz'             => 6,
    ],

    // D2 Sonstige harte Drogen (Heroin, Opioide, Amphetamine, MDMA etc.)
    // BK-5 Kap. B 3, D 2.x-N: Standard 12m / erschwerend 15m (korrigiert 26.04.2026)
    'D2_hart' => [
        'standard_monate'    => 12,
        'erschwerend_monate' => 15,
        'karenz'             => 6,
    ],

    // D2 Cannabis (THC-COOH > 150 ng/ml → Regelkonsum belegt)
    // BK-5 Kap. B 3, D 2.x-N: Standard 12m / erschwerend 15m (korrigiert 26.04.2026)
    'D2_cannabis' => [
        'standard_monate'    => 12,
        'erschwerend_monate' => 15,
        'karenz'             => 3,
    ],

    // D3 Cannabis (THC-COOH ≤ 150 ng/ml → Gefährdung / gelegentlicher Konsum)
    // BK-5 Kap. B 3: 6 Monate (Regelfall); 0 Monate Abstinenz bei kontr. Konsum (nur Cannabis, kein BKF).
    // Auch dann gelten laut öffentlicher Änderungsübersicht zur BK-5 (D 3.6 N) zumindest diese Dauer
    // der Konsumänderung und toxikologische Belege vor der Begutachtung. Am Buch zu prüfen.
    'D3_cannabis' => [
        'monate' => 6,
        'karenz' => 3,
        'belege_monate_vor_begutachtung' => '3–4',
    ],

    // D4 Cannabis (Trennungsvermögen: THC < 3,5 ng/ml, reines Cannabis-Delikt, kein BKF)
    // BK-5 Kap. B 3 + CanG (05.07.2024): 0 Monate Abstinenz, Nachweis Trennvermögen
    'D4' => [
        'monate' => 0,
        'karenz' => 3,
    ],

    // ── Verkehr / Punkte (V1–V3) ──────────────────────────────────────────────

    // V1: Persönlichkeitsproblematik (schwere Gewalt-/Sexualdelikte im Straßenverkehr)
    // BK-5 Kap. B 4: kein Abstinenzgebot, 12 Monate Veränderungsprozess als Karenz
    'V1' => [
        'monate' => 0,
        'karenz' => 12,
    ],

    'V' => [
        'monate'           => 0,
        'karenz_standard'  => 3,
        'karenz_aggression' => 6,
    ],

    // ── Medikamente / Substitution (M1–M3) ───────────────────────────────────

    // Zuordnung M1 Dauermedikation, M2 Fehlgebrauch, M3 Arzneimittelabhängigkeit laut öffentlicher
    // Änderungsübersicht zur BK-5, am Buch zu prüfen. Die Engine vergibt M3 nicht selbst: Das ist eine
    // Einzelfallentscheidung und läuft über den Override.
    // M1: Medizinalcannabis (BTM-Rezept) – kein Abstinenzgebot
    // BK-5 Kap. B 5: Leistungstest PR ≥ 16 (Gruppe 1) / PR ≥ 33 (BKF)
    'M1' => [
        'monate' => 0,
        'karenz' => 0,
    ],

    // M2: Benzodiazepin-Abhängigkeit/-Missbrauch – analog D-Kriterien
    // BK-5 Kap. B 5: 12 Monate mit Therapie / 15 Monate Selbstheiler
    'M2' => [
        'monate_therapie'     => 12,
        'monate_selbstheiler' => 15,
        'karenz'              => 6,
    ],

    // Opioid-/Methadon-Dauermedikation (Hypothese M1) – kein Abstinenzgebot
    // BK-5 Kap. B 5: Compliance-Nachweis Substitution; Fahrtüchtigkeit belegen
    'M3' => [
        'monate' => 0,
        'karenz' => 6,
    ],

    // Generische Dauermedikation (Fallback wenn M1/M2/M3 nicht eindeutig)
    'M' => [
        'monate' => 0,
        'karenz' => 6,
    ],
];
