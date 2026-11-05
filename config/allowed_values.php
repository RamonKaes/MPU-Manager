<?php
declare(strict_types=1);

/**
 * Aufbewahrungsfrist in Jahren. Frist des Betreibers, die Rechtsgrundlage prüft jeder Fork selbst.
 * Nach Ablauf dieser Frist ab Fallabschluss wird ein Löschvorschlag angezeigt.
 */
const DSGVO_AUFBEWAHRUNG_JAHRE = 10;

/** Frist für das Audit-Log. Löschbelege sollen die Aufbewahrung nicht unbegrenzt überdauern. */
const AUDIT_AUFBEWAHRUNG_JAHRE = DSGVO_AUFBEWAHRUNG_JAHRE;

/** Plausibilität der Eingabe, keine fachliche Schwelle (config/triage_config.php, punkte.eingabe_max). */
define('PUNKTE_EINGABE_MAX', (int)(require __DIR__ . '/triage_config.php')['punkte']['eingabe_max']);

/**
 * Kanonische BK-5-Hypothesenkategorien für die Human-Override-Auswahl
 * (aufnahme.php, bearbeiten.php).
 *
 * WICHTIG (Z-7): Diese Liste ist NICHT identisch mit den Auto-Labels aus
 * `triage.php`. Die Engine erzeugt pro Fall dynamische, kontextreiche Labels
 * (z. B. "Hypothese D2 (Cannabis-Regelkonsum, THC-COOH > 150 ng/ml)"), die
 * Laufzeitwerte interpolieren und daher nicht statisch abbildbar sind. Diese
 * Whitelist bildet stattdessen die 14 kanonischen BK-5-Hypothesenkategorien ab –
 * die bewusste Klassifikationsentscheidung, die eine Beraterin per Override trifft.
 *
 * Die Kategorienamen folgen der BK-5 (2026) und dürfen nur mit Blick auf diese
 * Quelle geändert werden. Das Kürzel am
 * Anfang ("Hypothese A2 (…") dient der UI zur Vorbelegung anhand des Auto-Labels.
 */
const ERLAUBTE_HYPOTHESEN = [
    // Alkohol (BK-5 Kap. B 1.1)
    'Hypothese A1 (Alkoholabhängigkeit)',
    'Hypothese A2 (Fortgeschrittene Alkoholproblematik)',
    'Hypothese A3 (Alkoholgefährdung)',
    'Hypothese A4 (Trennungsvermögen)',
    // Drogen (BK-5 Kap. B 3)
    'Hypothese D1 (Drogenabhängigkeit)',
    'Hypothese D2 (Fortgeschrittene Drogenproblematik)',
    'Hypothese D3 (Drogengefährdung)',
    'Hypothese D4 (Trennungsvermögen bei Cannabis)',
    // Verkehr / Strafrecht (BK-5 Kap. B 4)
    'Hypothese V1 (Persönlichkeits-Problematik)',
    'Hypothese V2 (Verfestigte Verhaltensmuster / Raser)',
    'Hypothese V3 (Fehleinstellungen / Straftaten im Straßenverkehr)',
    // Medikamente (BK-5 Kap. B 5). Bezeichnungen nach der öffentlichen Änderungsübersicht, am Buch zu prüfen.
    'Hypothese M1 (Dauermedikation, z. B. Medizinalcannabis)',
    'Hypothese M2 (Fehlgebrauch von Arzneimitteln)',
    'Hypothese M3 (Arzneimittelabhängigkeit)',
];

/**
 * Erlaubte Statusübergänge für cases.status.
 * Format: Altstatus => [erlaubte Neustatus]
 */
const ERLAUBTE_STATUSUEBERGAENGE = [
    'aktiv'         => ['aktiv', 'pausiert', 'abgeschlossen'],
    'pausiert'      => ['pausiert', 'aktiv', 'abgeschlossen'],
    'abgeschlossen' => ['abgeschlossen', 'aktiv', 'pausiert'],
];

/**
 * Vorschläge für die Überschrift eines Notiz-Journaleintrags (case_notes.title).
 *
 * Bewusst KEINE Whitelist: Die Liste füllt nur das Dropdown (datalist) im
 * Notizen-Tab. Eigene Überschriften bleiben zulässig – die Beraterin soll nicht
 * gezwungen sein, einen Verlauf in vorgegebene Kategorien zu pressen.
 */
const NOTIZ_TITEL_VORSCHLAEGE = [
    'Beratungsverlauf',
    'Telefonat mit Klient',
    'Behördenkontakt',
    'Laborkontakt',
    'Vereinbarung getroffen',
    'Dokumente erhalten',
    'Rückfallrisiko',
    'Fortschritt',
    'Terminabsprache',
    'Sonstiges',
];

/** Gültige Abstinenzdauer-Texte für Override-Validierung */
const ERLAUBTE_ABSTINENZ = [
    '3 Monate', '6 Monate', '9 Monate', '12 Monate',
    '15 Monate', '18 Monate', '24 Monate', '36 Monate', '60 Monate',
    'Keine (Einstellungscheck)',
];
