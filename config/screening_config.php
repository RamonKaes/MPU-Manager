<?php
declare(strict_types=1);

/**
 * config/screening_config.php – Schwellenwerte der Screening-Fragebögen
 * -----------------------------------------------------------------------
 * AUDIT und LAST sind reine Dokumentationsfelder: sie fließen NICHT in
 * berechne_triage() ein. Deshalb liegen sie bewusst nicht in
 * config/triage_config.php – dessen Änderungsregel verlangt eine
 * BK-5-Primärquelle, die es für diese Werte nicht gibt und nicht geben kann.
 * Die Quellen sind hier die Testautoren bzw. die WHO.
 *
 * Zweck der Zentralisierung: die Werte werden in aufnahme.php und
 * bearbeiten.php angezeigt. Standen sie doppelt im Markup, liefen sie
 * auseinander (so geschehen: bearbeiten.php nannte die AUDIT-Stufe 16 nicht).
 *
 * ÄNDERUNGEN NUR MIT PRIMÄRQUELLE.
 *
 * NICHT hierher gehören Werte mit BK-5-Quelle – die Änderungsregel dieser
 * Datei schließt BK-5 ausdrücklich aus. CTU-Werte (Kreatinin, Haarsegmente)
 * liegen deshalb in config/ctu_config.php.
 *
 * Letztes Update: 2026-07-29 (R3-3: Kreatinin nach ctu_config.php verschoben)
 */

return [

    // ── AUDIT (Alcohol Use Disorders Identification Test) ─────────────────────
    // 10 Items à 0–4 Punkte, Summe 0–40. Bezug: WHO-Manual (Babor/Higgins-Biddle/
    // Saunders/Monteiro, 2. Aufl. 2001), Risikozonen 0–7, 8–15, 16–19 und 20–40.
    //
    // Der Score stellt KEINE Abhängigkeit fest. Die obere Zone begründet die diagnostische
    // Abklärung, das Label ist entsprechend formuliert.
    'audit' => [
        'max'       => 40,
        'riskant'   => 8,   // ab hier riskanter Konsum (WHO-Cut-off)
        'schaedlich' => 16,  // Zone III
        'abklaeren' => 20,  // Zone IV – Abhängigkeit diagnostisch abklären
    ],

    // ── LAST (Lübecker Alkoholabhängigkeits- und -missbrauchs-Screening-Test) ─
    // 7 Items, dichotom (Ja/Nein), Summe 0–7. Item 1 ist invers gepolt
    // ("Nein" gibt den Punkt), Items 2–7 zählen bei "Ja".
    //
    // Ab 2 Punkten besteht laut Testmanual ein Hinweis auf Alkoholmissbrauch oder eine
    // beginnende Abhängigkeit.
    // Bezug: Rumpf HJ, Hapke U, John U (2001): LAST. Lübecker
    // Alkoholabhängigkeits- und -missbrauchs-Screening-Test (Testmanual).
    // Göttingen: Hogrefe. Validierung: Rumpf/Hapke/John (2002),
    // Suchtmedizin in Forschung und Praxis 4(4), 237–242.
    //
    // Der Cut-off ist für die Altersgruppe 18–64 validiert.
    // Bis 2026-07-29 nannte die Oberfläche fälschlich 4 (E-6).
    'last' => [
        'max'      => 7,
        'auffaellig' => 2,  // >= 2 → Hinweis auf Missbrauch / beginnende Abhängigkeit
    ],

];
