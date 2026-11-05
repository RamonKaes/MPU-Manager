<?php
declare(strict_types=1);

/**
 * config/behoerden_config.php – Vorlauffristen der Führerscheinstellen
 * -----------------------------------------------------------------------
 * Wie viele Tage vor Sperrfristende der Antrag gestellt sein sollte. Das ist
 * keine Rechtsfrist, sondern Erfahrungswissen der Praxis über die
 * Bearbeitungsdauer der jeweiligen Behörde.
 *
 * Deshalb liegen die Werte NICHT in config/triage_config.php: sie haben keine
 * BK-5-Grundlage und ändern sich, wenn eine Behörde ihre Bearbeitungszeit
 * ändert — das darf ohne fachliche Prüfung angepasst werden.
 *
 * Bis 2026-07-29 standen 70 und 84 nur inline in index.php. Folge: die
 * Arbeitsliste „Nächste Schritte" kannte sie nicht und stufte Düsseldorfer
 * Fälle zwischen 61 und 84 Tagen als bloßes „Antrag vorbereiten" ein, während
 * die Karte daneben den Vorlauf bereits als unterschritten meldete (E-4).
 *
 * E-4 hat damals nur die Rechenseite umgestellt; die Hinweistexte nannten die
 * Zahlen weiter wörtlich (R3-4). Seit 2026-07-29 lesen auch sie hier:
 *
 *   index.php              Karte „Dringende Fristen & Warnungen" (Tage)
 *   akte.php               Mettmann-Hinweis im Aufgaben-Modal (Wochen + Tage)
 *   klienten_leitfaden.php Terminvergabe und Rückwärtsplanung (Wochen)
 *
 * Wer hier einen Wert ändert, ändert damit alle vier Anzeigen zugleich.
 * NICHT hierher gehört `days_before_end` in config/checklist_templates.php –
 * das ist die Rückwärtsplanung der gesamten Wiedererteilungskette und nicht
 * der behördenspezifische Vorlauf, auch wenn beide zufällig 84 kennen.
 *
 * Zuordnung einer Behörde: is_authority_mettmann() / is_authority_duesseldorf()
 * in logic/mpu_helper.php (Behördenname, Wohnort, PLZ).
 */

return [

    // Tage vor Sperrfristende, zu denen der Antrag gestellt sein sollte
    'vorlauf_tage' => [
        // Düsseldorf: Online-Termin zwingend, Bearbeitungszeit 8–12 Wochen
        'duesseldorf' => 84,
        // Mettmann: persönliche Vorsprache erforderlich
        'mettmann'    => 70,

        // Alle übrigen Behörden.
        //
        // Hier gab es vor E-4 zwei stille, widersprüchliche Vorgaben: die
        // Dashboard-Karte rechnete für jede Nicht-Düsseldorfer Behörde mit 70
        // Tagen, get_antrag_ampel() dagegen mit 60. Vereinheitlicht auf 70 —
        // der vorsichtigere der beiden Werte und der, den die täglich benutzte
        // Karte ohnehin schon anlegte. Wirkung: die Fristen-Ampel in akte.php
        // schlägt zwischen 61 und 70 Tagen nun ebenfalls auf Rot um.
        //
        // Für einen Einzugsbereich mit schnelleren Behörden hier auf 60 zurück.
        'standard'    => 70,
    ],

    // Vorwarnzeit: so viele Tage vor Erreichen des Vorlaufs wird die Ampel gelb.
    // 30 Tage entspricht dem bisherigen Abstand (Rot ≤ 60, Gelb bis 90).
    'vorwarnung_tage' => 30,

    // Zeitfenster der Dashboard-Karte „Dringende Fristen & Warnungen"
    'radar_wochen' => 12,
];
