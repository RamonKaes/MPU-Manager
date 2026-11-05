<?php
declare(strict_types=1);

/**
 * config/ctu_config.php – CTU-Kriterien (Chemisch-Toxikologische Untersuchungen)
 * -----------------------------------------------------------------------------
 * Werte zu Probenvalidität und Segmentierung von Abstinenznachweisen, orientiert am
 * Methodenteil der Beurteilungskriterien (BK-5).
 *
 * WARUM EINE EIGENE DATEI:
 * Diese Werte beziehen sich auf die BK-5, speisen aber `berechne_triage()`
 * nicht. Damit passen sie in keine der bestehenden drei Configs:
 *
 *   triage_config.php    BK-5-Quelle, aber nur für Werte der Triage-Engine
 *   screening_config.php ausdrücklich für Quellen, die NICHT BK-5 sind
 *                        (WHO-AUDIT-Manual, LAST-Testmanual)
 *   behoerden_config.php frei änderbar, keine fachliche Norm
 *
 * Kreatinin lag bis 29.07.2026 in screening_config.php – unter einer
 * Änderungsregel, die für den Wert gar nicht gilt.
 *
 * ÄNDERUNGSREGEL (wie triage_config.php):
 * Nur nach Prüfung am Original der BK-5.
 *
 * Letztes Update: 2026-07-29 (R3-3: Haar-Segmentlängen ergänzt)
 */

return [

    // ── Kreatinin: Probenvalidität Urin ──────────────────────────────────────
    // Bezug: BK-5, Methodenteil, CTU-Kriterien, dort mit Verweis auf die European
    // Workplace Drug Testing Society (EWDTS, 2015).
    //
    // ZWEI Schwellen mit UNTERSCHIEDLICHER Folge, nie zu einer zusammenziehen (R3-2):
    //   ab 20 mg/dl    Probe verwertbar, darunter verdünnt und zu wiederholen
    //   bis 5,6 mg/dl  ein Negativbefund wird nicht mehr ausgewiesen
    //
    // Die zweite Folge ist kein Manipulationsvorwurf, die Wertung trifft der Gutachter.
    //
    // Einheit mg/dl – wie Spalte abstinence_probes.kreatinin_mg_dl, das
    // Eingabefeld in akte.php und die Ausgabe in akte_druck.php. Labore melden
    // in g/l (Faktor 100: 20 mg/dl = 0,2 g/l, 5,6 mg/dl = 0,056 g/l).
    // 5,6 mg/dl entspricht 0,5 mmol/l (Molmasse Kreatinin 113,12 g/mol).
    'kreatinin' => [
        'verwertbar_ab_mg_dl'          => 20.0, // >= 20   → verwertbar
        'kein_negativbefund_bis_mg_dl' => 5.6,  // <= 5,6  → kein Negativbefund
    ],

    // ── Haaranalyse: Segmentierung ───────────────────────────────────────────
    // Bezug: BK-5, Methodenteil, CTU-Kriterien.
    //
    // Für Haaranalysen rechnet die Software mit einer maximalen Segmentlänge und einem
    // lückenlos abzudeckenden Zeitraum, nicht mit einer festen Stückzahl:
    //
    //     Analysen = aufgerundet( Programmdauer in Monaten / Segment-Monate )
    //
    // Dabei gilt 1 cm Haarwachstum je Monat als Rechenwert, bewusst nicht konfigurierbar.
    //
    // Segmentlängen:
    //   Alkohol/EtG  max. 3 cm: Längere Segmente mischen Neuwachstum mit Haaren aus früheren
    //                Konsumperioden (Gefahr falsch-positiver Befunde).
    //   Drogen/BTM   max. 6 cm: Längere Segmente verdünnen seltenen Konsum (Gefahr
    //                falsch-negativer Befunde).
    //
    // MISCHFÄLLE: Ist Alkohol unter den Delikten, gilt die Alkohol-Kadenz –
    // auch wenn zusätzlich Drogen vorliegen. Der EtG-Nachweis erzwingt das
    // 3-cm-Segment, und ein 3-cm-Segment lässt sich zusätzlich auf BTM
    // analysieren. Die Regel irrt damit zur sicheren Seite: lieber eine Probe
    // zu viel verlangen als eine Beweislücke übersehen.
    // PEth als Abstinenzbeleg: Werte ab dieser Grenze gelten als auffällig. Bisher nur in der
    // internen Fachdoku belegt, Fundstelle am Buch prüfen. Nicht verwechseln mit dem Höchstwert
    // bei Programmen zum kontrollierten Trinken (nach der öffentlichen BK-5-Änderungsübersicht 60 ng/mL).
    'peth' => [
        'abstinenz_unter_ng_ml' => 20.0,
    ],

    // Urin-Sollproben je Programmlänge: Monate ab => Anzahl, absteigend.
    'urin_soll' => [
        15 => 7,
        12 => 6,
        0  => 4,
    ],

    // ── Fachwerte ───────────────────────────────────────────────────────
    'haar' => [
        'segment_monate_alkohol' => 3, // EtG, max. 3 cm
        'segment_monate_drogen'  => 6, // BTM, max. 6 cm

        // ── Rechenregel dieses Projekts ──────────────────────────────────
        //
        // Die Segmente sollen lückenlos aneinander anschließen. Die Lückenschwelle in Tagen
        // ist eine Konvention dieser Software, kein Fachwert:
        //
        //   Schwelle = Segment-Monate * tage_je_monat + luecke_puffer_tage
        //   Alkohol:  3 * 30 + 5 = 95 Tage
        //   Drogen:   6 * 30 + 5 = 185 Tage
        //
        // Die 95 Tage sind Bestand: mit ihnen hat die Lückenprüfung vorher
        // pauschal für alle Fälle gerechnet (Kommentar dort: "jede Haarprobe
        // deckt ~90 Tage ab, 5 Tage Puffer"). R3-3 hat die Formel nur auf die
        // zweite Segmentlänge angewandt, den Puffer also unverändert
        // übernommen — für Alkohol ergibt sich exakt der alte Wert.
        //
        // Wer die Schwelle strenger will, senkt den Puffer. Ein Puffer von 0
        // entspräche der Norm am engsten, würde aber jede Terminverschiebung
        // um einen Tag als Kettenbruch melden.
        'luecke_puffer_tage' => 5,
        'tage_je_monat'      => 30,
    ],
];
