<?php
declare(strict_types=1);

/**
 * MPU-Manager – Prozedurale Hilfsfunktionen
 *
 * Wiederverwendbar in akte.php und index.php (Dashboard).
 * Keine globalen Variablen, keine Seiteneffekte – nur Input → Output.
 */


// ── 0. Audit-Log ─────────────────────────────────────────────────────────────

/**
 * Schreibt einen Eintrag in audit_log.
 *
 * user_name und ip_address werden automatisch aus dem Request-Kontext
 * ermittelt (angemeldeter Session-Benutzer bzw. REMOTE_ADDR) und müssen nicht
 * explizit übergeben werden. Im CLI-Kontext (Migrationen/Cron) existiert keine
 * Session → user_name bleibt null.
 *
 * @param PDO      $pdo         Datenbankverbindung
 * @param string   $action      Kürzel der Aktion, z.B. 'create_client', 'delete_probe'
 * @param string   $table_name  Betroffene Tabelle
 * @param int      $record_id   ID des betroffenen Datensatzes
 * @param int|null $client_id   Klienten-ID (für spätere Filterung)
 * @param string   $description Optionaler Freitext-Kontext (z.B. Feld-Delta)
 */
function audit_log(PDO $pdo, string $action, string $table_name, int $record_id, ?int $client_id = null, string $description = ''): void
{
    $user_name  = !empty($_SESSION['user']) ? substr((string)$_SESSION['user'], 0, 100) : null;
    $ip_address = client_adresse() ?: null;

    $pdo->prepare(
        "INSERT INTO audit_log (action, table_name, record_id, client_id, description, user_name, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([$action, $table_name, $record_id, $client_id, $description ?: null, $user_name, $ip_address]);
}

/**
 * R3-1: Entfernt die personenbezogenen Freitexte aus dem Audit-Log eines Klienten.
 *
 * `audit_log` ist die einzige Tabelle mit `client_id`, die bewusst **keinen**
 * Foreign Key auf `clients` hat (schema.sql) – ihre Zeilen überleben ein
 * `DELETE FROM clients` und kaskadieren nicht mit. Genau das ist gewollt: der
 * Beleg, *dass* verarbeitet und gelöscht wurde, muss die Löschung überdauern
 * (Rechenschaftspflicht, Art. 5 Abs. 2 DSGVO).
 *
 * Die Spalte `description` trägt jedoch personenbezogene Inhalte, die davon
 * nicht gedeckt sind – teils besondere Kategorien nach Art. 9:
 *
 *   'Max Mustermann'                                (create_client)
 *   'Hypothese: … → Hypothese A1 (Alkoholabhängigkeit)'  (update_case)
 *   'urin / positiv / Labor XY'                     (add_probe)
 *   'MPU-Vorbereitung Sitzung 3'                    (add_note, Titel frei wählbar)
 *
 * Ohne diese Bereinigung ist jede Löschung nach Art. 17 unvollständig und die
 * Löschbestätigung an den Klienten unzutreffend.
 *
 * Bewusst **nicht** angetastet:
 *   - `user_name`  – der handelnde Mitarbeiter, kein Klientendatum, und für die
 *                    Nachvollziehbarkeit des Löschvorgangs erforderlich.
 *   - `ip_address` – Adresse des Praxisarbeitsplatzes, nicht des Klienten.
 *   - `client_id`  – nach dem Löschen der `clients`-Zeile eine verwaiste Zahl
 *                    ohne Re-Identifizierungsmöglichkeit; sie hält die
 *                    Löschhistorie eines Vorgangs zusammen.
 *
 * Aufrufreihenfolge (zwingend): erst anonymisieren, dann `DELETE FROM clients`,
 * **dann** den Löschbeleg schreiben. Andernfalls würde die Bereinigung den
 * Löschbeleg gleich wieder mit entfernen.
 *
 * @return int Anzahl der bereinigten Einträge (für den Löschbeleg)
 */
function audit_log_anonymisieren(PDO $pdo, int $client_id): int
{
    $stmt = $pdo->prepare(
        "UPDATE audit_log SET description = NULL
          WHERE client_id = ? AND description IS NOT NULL"
    );
    $stmt->execute([$client_id]);
    return $stmt->rowCount();
}

/**
 * R3-6: Gehört die Fallakte diesem Klienten?
 *
 * Die `case_id` der Schreib-Handler stammt aus der URL (`akte.php?id=…&case_id=…`).
 * Auf der Leseseite ist das unkritisch – akte.php übernimmt den Parameter nur,
 * wenn er in den Fällen dieses Klienten vorkommt, und legt sonst auf den
 * aktiven Fall zurück. Die Schreibpfade hatten diese Prüfung nicht und hängten
 * den neuen Datensatz an die `case_id`, die gerade in der Adresszeile stand.
 *
 * `case_id <= 0` gilt bewusst ebenfalls als ungültig: akte.php rendert die
 * Formulare ausnahmslos mit einer echten Fall-ID (und legt notfalls eine leere
 * Fallakte an), ein 0-Wert kann also nur aus einem defekten Request stammen.
 * Bestehende Altdatensätze mit `case_id IS NULL` bleiben davon unberührt – die
 * Ladequeries in akte.php lesen sie weiterhin mit.
 */
function fall_gehoert_zu_klient(PDO $pdo, int $case_id, int $client_id): bool
{
    if ($case_id <= 0 || $client_id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE id = ? AND client_id = ?");
    $stmt->execute([$case_id, $client_id]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Parst einen Datums-String 'Y-m-d' sicher ohne Exception.
 * Liefert null bei ungültigem Wert (z.B. '0000-00-00', Leerstring, DB-Altdaten).
 */
function parse_date(string $value): ?DateTimeImmutable
{
    if ($value === '' || $value === '0000-00-00') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt !== false ? $dt->setTime(0, 0, 0) : null;
}


// ── 1. Mettmann-Erkennung ────────────────────────────────────────────────────

/**
 * Prüft anhand von Behörde und Wohnort, ob ein Klient dem Kreis Mettmann zugeordnet ist.
 * Kreis Mettmann umfasst u.a. Velbert, Mettmann, Ratingen, Erkrath, Hilden, Langenfeld,
 * Monheim, Heiligenhaus, Haan, Wülfrath, Wermelskirchen.
 */
function is_authority_mettmann(string $behoerde, string $wohnort): bool
{
    if (stripos($behoerde, 'mettmann') !== false) {
        return true;
    }
    $mettmann_staedte = ['mettmann', 'velbert', 'ratingen', 'erkrath', 'hilden', 'langenfeld', 'monheim', 'heiligenhaus', 'haan', 'wülfrath', 'wermelskirchen'];
    return in_array(mb_strtolower(trim($wohnort), 'UTF-8'), $mettmann_staedte, true);
}

/**
 * Prüft anhand von Behörde und Wohnort, ob ein Klient der Stadt Düsseldorf zugeordnet ist.
 * Düsseldorf: Stadtgebiet inkl. aller Stadtteile (40XXX PLZ-Raum).
 * Behörde: Straßenverkehrsamt Düsseldorf, Abt. 33, Höherweg 101.
 */
function is_authority_duesseldorf(string $behoerde, string $wohnort, string $plz = ''): bool
{
    if (stripos($behoerde, 'düsseldorf') !== false || stripos($behoerde, 'duesseldorf') !== false) {
        return true;
    }
    // PLZ-Raum 40xxx = Düsseldorf Stadtgebiet
    if (!empty($plz) && preg_match('/^40\d{3}$/', $plz)) {
        return true;
    }
    $duesseldorf_staedte = ['düsseldorf', 'duesseldorf'];
    return in_array(mb_strtolower(trim($wohnort), 'UTF-8'), $duesseldorf_staedte, true);
}


// ── 1b. Behörden-Vorlauffristen (E-4) ────────────────────────────────────────

/**
 * Konfiguration der Behörden-Vorlauffristen (einmalig geladen).
 *
 * @return array{vorlauf_tage: array<string,int>, vorwarnung_tage: int, radar_wochen: int}
 */
function behoerden_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/behoerden_config.php';
    }
    return $cfg;
}

/**
 * E-4: Empfohlener Antragsvorlauf in Tagen für die zuständige Behörde.
 *
 * Düsseldorf und Mettmann brauchen nachweislich länger als der Standardfall.
 * Diese Funktion ist die einzige Stelle, die das entscheidet — davor stand die
 * Zuordnung nur inline im Markup von index.php, weshalb die Arbeitsliste
 * „Nächste Schritte" nichts davon wusste.
 */
function get_behoerden_vorlauf_tage(string $behoerde, string $wohnort, string $plz = ''): int
{
    $tage = behoerden_config()['vorlauf_tage'];
    if (is_authority_duesseldorf($behoerde, $wohnort, $plz)) {
        return (int)$tage['duesseldorf'];
    }
    if (is_authority_mettmann($behoerde, $wohnort)) {
        return (int)$tage['mettmann'];
    }
    return (int)$tage['standard'];
}

/**
 * E-4: Vorlauf direkt aus einer Akten-Zeile bestimmen.
 *
 * Fehlen die Behördenfelder (nicht jeder Aufrufer lädt sie), fällt die Funktion
 * auf den Standardwert zurück — die Ampel verhält sich dann wie vor E-4.
 */
function get_behoerden_vorlauf_aus_akte(array $akte): int
{
    return get_behoerden_vorlauf_tage(
        (string)($akte['authority_name'] ?? ''),
        (string)($akte['city']           ?? ''),
        (string)($akte['zip_code']       ?? '')
    );
}


// ── 2. Fristen-Ampel ─────────────────────────────────────────────────────────

/**
 * Wertet den Antragsstatus aus und gibt die Ampelfarbe zurück.
 *
 * Priorität der Fristen:
 *   1. suspension_end_date  – harte Rechtsfrist (Sperre läuft)
 *   2. mpu_target_date      – weiche Frist (drohende Entziehung, noch kein Sperrfristende)
 *
 * E-4: Der Umschlagpunkt auf Rot ist die Vorlauffrist der zuständigen Behörde
 * (`config/behoerden_config.php`), nicht mehr eine feste Zahl. Ohne Angabe gilt
 * `vorlauf_tage.standard`; Gelb beginnt `vorwarnung_tage` früher.
 * Beispiel Düsseldorf (84 + 30): Rot ≤ 84, Gelb 85–114, Grün > 114.
 *
 * @param string|null $sperrfrist_ende  Datum aus cases.suspension_end_date ('Y-m-d')
 * @param string      $antrag_status   Wert aus cases.antrag_status
 * @param string|null $mpu_target_date Datum aus cases.mpu_target_date ('Y-m-d'); Fallback falls keine Sperrfrist
 * @param int|null    $vorlauf_tage    Empfohlener Antragsvorlauf der Behörde; null = Standard
 * @return array{level: string, label: string, days_remaining: int|null}
 *   level: 'success' | 'warning' | 'danger' | 'secondary'
 */
function get_antrag_ampel(?string $sperrfrist_ende, string $antrag_status, ?string $mpu_target_date = null, ?int $vorlauf_tage = null): array
{
    // Antrag bereits gestellt → grün
    if ($antrag_status === 'gestellt') {
        return ['level' => 'success', 'label' => 'Antrag gestellt', 'days_remaining' => null];
    }

    // Aufforderung liegt vor → orange: Klient muss jetzt aktiv werden
    if ($antrag_status === 'aufforderung_liegt_vor') {
        return ['level' => 'warning', 'label' => 'Aufforderung liegt vor', 'days_remaining' => null];
    }

    // Antrag abgelehnt → Handlungsbedarf wie bei nicht_gestellt, aber eigenes Label
    if ($antrag_status === 'abgelehnt') {
        return ['level' => 'warning', 'label' => 'Neuer Anlauf erforderlich', 'days_remaining' => null];
    }

    $heute = new DateTimeImmutable('today');

    // ── Primäre Frist: Sperrfristende ────────────────────────────────────────
    if (!empty($sperrfrist_ende)) {
        $ende = parse_date($sperrfrist_ende);
        if ($ende === null) {
            return ['level' => 'warning', 'label' => 'Ungültiges Sperrfristdatum', 'days_remaining' => null];
        }
        $days_remaining = (int)$heute->diff($ende)->days * ($heute <= $ende ? 1 : -1);

        $b_cfg      = behoerden_config();
        $vorlauf    = $vorlauf_tage ?? (int)$b_cfg['vorlauf_tage']['standard'];
        $vorwarnung = $vorlauf + (int)$b_cfg['vorwarnung_tage'];

        if ($days_remaining < 0) {
            return ['level' => 'danger',  'label' => 'Antrag überfällig – Sperrfrist bereits abgelaufen', 'days_remaining' => $days_remaining];
        }
        if ($days_remaining > $vorwarnung) {
            return ['level' => 'success', 'label' => 'Frühzeitig – kein Handlungsbedarf',                 'days_remaining' => $days_remaining];
        }
        if ($days_remaining > $vorlauf) {
            return ['level' => 'warning', 'label' => 'Antrag vorbereiten',                                 'days_remaining' => $days_remaining];
        }
        // Unterhalb der Vorlauffrist: nach den Erfahrungswerten der Behörde
        // reicht die Bearbeitungszeit ab hier nicht mehr sicher aus.
        $label = $vorlauf_tage === null
            ? 'Dringend – Antrag sofort vorbereiten'
            : "Dringend – empfohlener Vorlauf von {$vorlauf} Tagen unterschritten";
        return           ['level' => 'danger',  'label' => $label,                                        'days_remaining' => $days_remaining];
    }

    // ── Fallback: MPU-Zielmonat (drohende Entziehung, keine aktive Sperre) ──
    if (!empty($mpu_target_date)) {
        $ziel = parse_date($mpu_target_date);
        if ($ziel === null) {
            return ['level' => 'warning', 'label' => 'Ungültiges MPU-Zieldatum', 'days_remaining' => null];
        }
        $days_remaining = (int)$heute->diff($ziel)->days * ($heute <= $ziel ? 1 : -1);

        if ($days_remaining < 0) {
            return ['level' => 'danger',  'label' => 'MPU-Zielmonat überschritten – sofort handeln',  'days_remaining' => $days_remaining];
        }
        if ($days_remaining > 90) {
            return ['level' => 'success', 'label' => 'MPU-Zielmonat: kein Handlungsbedarf',           'days_remaining' => $days_remaining];
        }
        if ($days_remaining > 60) {
            return ['level' => 'warning', 'label' => 'MPU-Zielmonat rückt näher – vorbereiten',       'days_remaining' => $days_remaining];
        }
        return           ['level' => 'danger',  'label' => 'MPU-Zielmonat kritisch – sofort handeln', 'days_remaining' => $days_remaining];
    }

    return ['level' => 'secondary', 'label' => 'Keine Frist eingetragen', 'days_remaining' => null];
}


// ── 2b. Einspruchsfrist-Ampel (F1) ───────────────────────────────────────────

/**
 * Berechnet den Ampelstatus der 2-Wochen-Einspruchsfrist (§ 410 StPO / § 67 OWiG).
 *
 * @param string|null $bussgeld_datum   Datum der Zustellung des Bußgeldbescheids ('Y-m-d')
 * @param string      $einspruch_status 'offen' | 'eingelegt' | 'abgelaufen' | 'nicht_relevant'
 * @return array{level: string, label: string, frist_ende: string|null, days_remaining: int|null}
 */
function get_einspruch_ampel(?string $bussgeld_datum, string $einspruch_status): array
{
    if ($einspruch_status === 'nicht_relevant') {
        return ['level' => 'secondary', 'label' => 'Kein Bußgeldbescheid', 'frist_ende' => null, 'days_remaining' => null];
    }
    if ($einspruch_status === 'eingelegt') {
        return ['level' => 'success', 'label' => 'Einspruch eingelegt ✓', 'frist_ende' => null, 'days_remaining' => null];
    }
    if ($einspruch_status === 'abgelaufen') {
        return ['level' => 'secondary', 'label' => 'Frist abgelaufen – Bescheid rechtskräftig', 'frist_ende' => null, 'days_remaining' => null];
    }
    // Status: 'offen' – aktive Fristenüberwachung
    if (empty($bussgeld_datum)) {
        return ['level' => 'warning', 'label' => 'Einspruchsfrist offen – Bescheiddatum fehlt', 'frist_ende' => null, 'days_remaining' => null];
    }
    $heute      = new DateTimeImmutable('today');
    $frist_ende = parse_date($bussgeld_datum);
    if ($frist_ende === null) {
        return ['level' => 'warning', 'label' => 'Einspruchsfrist offen – ungültiges Bescheiddatum', 'frist_ende' => null, 'days_remaining' => null];
    }
    $frist_ende = $frist_ende->modify('+14 days');
    $days       = (int)$heute->diff($frist_ende)->days * ($heute <= $frist_ende ? 1 : -1);

    if ($days < 0) {
        return ['level' => 'secondary', 'label' => 'Frist abgelaufen', 'frist_ende' => $frist_ende->format('Y-m-d'), 'days_remaining' => $days];
    }
    if ($days <= 3) {
        return ['level' => 'danger',  'label' => "FRIST LÄUFT AB – noch {$days} Tag(e)!", 'frist_ende' => $frist_ende->format('Y-m-d'), 'days_remaining' => $days];
    }
    if ($days <= 7) {
        return ['level' => 'warning', 'label' => "Einspruchsfrist in {$days} Tagen", 'frist_ende' => $frist_ende->format('Y-m-d'), 'days_remaining' => $days];
    }
    return ['level' => 'success', 'label' => "Frist läuft – noch {$days} Tage", 'frist_ende' => $frist_ende->format('Y-m-d'), 'days_remaining' => $days];
}


// ── 2c. R3-3: Haaranalyse-Segmentierung (CTU) ────────────────────────────────

/**
 * Konfiguration der CTU-Kriterien (einmalig geladen).
 *
 * @return array{kreatinin: array<string,float>, haar: array<string,int>, peth: array<string,float>, urin_soll: array<int,int>}
 */
function ctu_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/ctu_config.php';
    }
    return $cfg;
}

/** Wie viele Urinproben verlangt ein Programm dieser Länge? */
function urin_pflicht_proben(int $abstinenz_monate): int
{
    foreach (ctu_config()['urin_soll'] as $ab_monate => $anzahl) {
        if ($abstinenz_monate >= $ab_monate) {
            return (int)$anzahl;
        }
    }
    return 0;
}

/** Spricht dieser PEth-Wert gegen den Abstinenzbeleg? */
function peth_auffaellig(float $wert): bool
{
    return $wert >= (float)ctu_config()['peth']['abstinenz_unter_ng_ml'];
}

/**
 * R3-3: Wie viele Monate deckt eine Haarprobe in diesem Fall ab?
 *
 * Die auswertbare Segmentlänge ist, orientiert an den CTU-Kriterien der BK-5, substanzabhängig: max. 3 cm bei Alkohol/EtG, max. 6 cm bei
 * Betäubungsmitteln. Bei 1 cm Haarwachstum je Monat entspricht das 3 bzw.
 * 6 Monaten Nachweiszeitraum je Probe.
 *
 * **Mischfälle und fehlende Angabe ergeben die Alkohol-Kadenz.** Liegt neben
 * Drogen auch ein Alkohol-Delikt vor, erzwingt der EtG-Nachweis das kürzere
 * 3-cm-Segment; ein 3-cm-Segment lässt sich zusätzlich auf BTM analysieren,
 * umgekehrt gilt das nicht. Ist die Deliktart unbekannt, gilt aus demselben
 * Grund der strengere Wert: eine Probe zu viel zu verlangen ist harmlos, eine
 * übersehene Beweislücke nicht.
 *
 * @param string|null $offense_category_json JSON aus cases.offense_category
 */
function haar_segment_monate(?string $offense_category_json): int
{
    $haar = ctu_config()['haar'];
    $delikte = json_decode((string)$offense_category_json, true);
    if (!is_array($delikte)) {
        return (int)$haar['segment_monate_alkohol'];
    }
    if (in_array('Alkohol', $delikte, true)) {
        return (int)$haar['segment_monate_alkohol'];
    }
    if (in_array('Drogen', $delikte, true)) {
        return (int)$haar['segment_monate_drogen'];
    }
    return (int)$haar['segment_monate_alkohol'];
}

/**
 * R3-3: Ab welchem Abstand zwischen zwei Haarproben klafft eine Lücke?
 *
 * Die Segmente sollen lückenlos aneinander anschließen. Gerechnet mit 30 Tagen je
 * Monat plus Terminierungspuffer.
 *
 * Alkohol ergibt 95 Tage — exakt der Wert, mit dem die Lückenprüfung vorher
 * pauschal für alle Fälle gerechnet hat. Für Drogen sind es 185 Tage; dort
 * meldete die alte Regel korrekte 6-cm-Ketten als Lücke.
 */
function haar_luecke_tage(int $segment_monate): int
{
    $haar = ctu_config()['haar'];
    return $segment_monate * (int)$haar['tage_je_monat'] + (int)$haar['luecke_puffer_tage'];
}

/**
 * R3-3: Wie viele Haaranalysen deckt ein Abstinenzprogramm dieser Länge ab?
 *
 * Die Software rechnet für Haaranalysen **ohne feste Stückzahl**, mit einer
 * maximale Segmentlänge und einen lückenlos zu belegenden Zeitraum. Die Anzahl
 * ist deshalb ein Rechenergebnis und keine Tabelle:
 *
 *     Analysen = aufgerundet( Programmdauer / Segmentlänge )
 *
 * Alkohol (3 Monate/Probe):  6 → 2,  12 → 4,  15 → 5
 * Drogen  (6 Monate/Probe):  6 → 1,  12 → 2,  15 → 3
 *
 * Bis 29.07.2026 stand in akte.php die fest verdrahtete Staffel 15 → 5,
 * 12 → 4, sonst 2. Sie ist die Alkohol-Ableitung und dort korrekt, galt aber
 * ebenso für Drogenfälle und verlangte dort doppelt so viele Analysen wie
 * nötig (R3-3).
 */
function haar_pflicht_analysen(int $abstinenz_monate, int $segment_monate): int
{
    if ($abstinenz_monate <= 0 || $segment_monate <= 0) {
        return 0;
    }
    return (int)ceil($abstinenz_monate / $segment_monate);
}


/**
 * Teilt das Soll einer Fallakte auf: was eingegangen ist und was offen bleibt.
 * „beglichen“ ohne Betrag heißt: voll bezahlt. Mit Betrag zählt der Betrag.
 *
 * @return array{eingang: float, offen: float}
 */
function zahlung_aufteilen(float $soll, string $status, ?float $bezahlt): array
{
    if ($status === 'beglichen') {
        return ['eingang' => $bezahlt ?? $soll, 'offen' => 0.0];
    }
    if ($status === 'teilweise') {
        $eingang = min($bezahlt ?? 0.0, $soll);
        return ['eingang' => $eingang, 'offen' => $soll - $eingang];
    }
    return ['eingang' => 0.0, 'offen' => $soll];
}

/**
 * Monate aus einem Abstinenztext. Nur am Anfang: „Kein Abstinenzgebot – 12 Monate Veränderungsprozess"
 * ist keine Abstinenzdauer.
 */
function abstinenz_monate_aus_text(string $text): int
{
    return preg_match('/^(?:Vorläufig\s+)?(\d+)\s+Monate/u', trim($text), $m) === 1 ? (int)$m[1] : 0;
}

/**
 * Strategiezeile der Vereinbarung. Muss zur Abstinenzzeile desselben Dokuments passen.
 * Die Kürzel stehen am Anfang der Engine-Labels und der Override-Liste.
 */
function strategie_zeile(string $hypothese, int $abstinenz_monate, string $bkf_status): string
{
    if ($abstinenz_monate <= 0) {
        return 'Kein Abstinenzgebot – Vorgehen im Beratungsgespräch festgehalten';
    }
    if ($bkf_status === 'aktiv') {
        return 'Vollständige Abstinenz (bei aktivem Berufskraftfahrer kein kontrollierter Konsum)';
    }
    preg_match_all('/Hypothese ([ADVM]\d)(?: \/ ([ADVM]\d))?/u', $hypothese, $treffer, PREG_SET_ORDER);
    $streng = false;
    $doppel = false;
    $kt     = false;
    foreach ($treffer as $t) {
        if (!empty($t[2])) {
            $doppel = true;
        } elseif (in_array($t[1], ['A1', 'A2', 'D1', 'D2'], true)) {
            $streng = true;
        } elseif (in_array($t[1], ['A3', 'A4', 'D3', 'D4'], true)) {
            $kt = true;
        }
    }
    if ($streng) {
        return 'Vollständige Abstinenz (zwingend)';
    }
    if ($doppel) {
        return 'Vollständige Abstinenz (dringend empfohlen)';
    }
    return $kt
        ? 'Abstinenz (empfohlen) oder kontrollierter Konsum (KT) – Entscheidung im Beratungsgespräch festgehalten'
        : 'Abstinenz (Nachweis laut Empfehlung)';
}

// ── 3. Abstinenz-Lücken-Rechner ──────────────────────────────────────────────

function letzter_positiver_befund(array $probes): ?string
{
    $daten = array_column(array_filter($probes, static fn($p) => ($p['result'] ?? '') === 'positiv'), 'probe_date');
    return $daten === [] ? null : max($daten);
}

/** Zählt die Probe als Abstinenzbeleg? Eine verdünnte Urinprobe zählt nicht, sie ist zu wiederholen. */
function probe_ist_abstinenzbeleg(array $probe): bool
{
    if (($probe['result'] ?? '') !== 'negativ') {
        return false;
    }
    $kreatinin = $probe['kreatinin_mg_dl'] ?? null;
    return ($probe['method'] ?? '') !== 'urin' || $kreatinin === null || $kreatinin === ''
        || (float)$kreatinin >= (float)ctu_config()['kreatinin']['verwertbar_ab_mg_dl'];
}

/**
 * Belege der laufenden Kette, aufsteigend nach Datum: gültige negative Proben nach dem letzten
 * positiven Befund. Am selben Tag wie ein positiver Befund genommene Proben zählen nicht.
 *
 * @return list<array<string,mixed>>
 */
function abstinenz_belege_der_kette(array $probes): array
{
    $positiv = letzter_positiver_befund($probes);
    $belege  = array_values(array_filter($probes, static fn($p) => probe_ist_abstinenzbeleg($p)
        && ($positiv === null || $p['probe_date'] > $positiv)));
    usort($belege, static fn($a, $b) => strcmp($a['probe_date'], $b['probe_date']));
    return $belege;
}

/**
 * Berechnet Lücken in der Abstinenzkette.
 *
 * Regeln:
 *   Urin: keine Lückenprüfung. Kontrollen werden unangekündigt und unregelmäßig abgerufen, das
 *         Programm verlangt eine Probenzahl (config/ctu_config.php, urin_soll), keinen Abstand.
 *         Eine frühere Regel „> 14 Tage = Lücke" meldete jedes reguläre Programm als gebrochen.
 *   Haar: R3-3 – substanzabhängig, weil die auswertbare Segmentlänge es ist
 *         (orientiert an den CTU-Kriterien der BK-5): 3 cm bei Alkohol/EtG,
 *         6 cm bei Betäubungsmitteln, bei 1 cm Wachstum je Monat also
 *         3 bzw. 6 Monate Abdeckung je Probe.
 *         Alkohol > 95 Tage = Lücke, Drogen > 185 Tage = Lücke.
 *         Auch: letzte Probe liegt länger zurück = laufende Lücke.
 *
 * Bis 29.07.2026 galten 95 Tage für alle Fälle. Ein Drogenklient mit zwei
 * korrekten 6-cm-Analysen im Abstand von rund 180 Tagen erzeugte damit eine
 * gemeldete Lücke, die keine ist — und zwar auf `danger`-Priorität, weil
 * get_client_status() Abstinenz-Lücken auf Priorität 2 einstuft.
 *
 * @param array       $probes  Zeilen aus abstinence_probes (assoz. Arrays mit 'probe_date', 'method', 'result')
 * @param string|null $offense_category_json JSON aus cases.offense_category; null = strengere Alkohol-Kadenz
 * @return array{gaps: list<array{von: string, bis: string, tage: int, method: string}>, hat_positiv: bool, letzte_probe: string|null}
 */
function get_abstinenz_luecken(array $probes, bool $laufend_pruefen = true, ?string $offense_category_json = null): array
{
    $haar_luecke = haar_luecke_tage(haar_segment_monate($offense_category_json));

    $gaps         = [];
    $letzte_probe = $probes === [] ? null : max(array_column($probes, 'probe_date'));
    $positiv      = letzter_positiver_befund($probes);

    // Ein positiver Befund beendet die Kette, danach beginnt eine neue. Ein alter Befund darf
    // keine Warnung mehr halten, sobald ein gültiger Beleg nachgekommen ist.
    $belege      = abstinenz_belege_der_kette($probes);
    $hat_positiv = $positiv !== null && $belege === [];

    $heute = new DateTimeImmutable('today');

    // Haar-Lücken prüfen
    $haar_list = array_values(array_filter($belege, static fn($p) => $p['method'] === 'haar'));
    for ($i = 0; $i < count($haar_list) - 1; $i++) {
        $von = parse_date($haar_list[$i]['probe_date']);
        $bis = parse_date($haar_list[$i + 1]['probe_date']);
        if ($von === null || $bis === null) { continue; }
        $tage = (int)$von->diff($bis)->days;
        if ($tage > $haar_luecke) {
            $gaps[] = ['von' => $haar_list[$i]['probe_date'], 'bis' => $haar_list[$i + 1]['probe_date'], 'tage' => $tage, 'method' => 'haar'];
        }
    }
    // Laufende Haar-Lücke – nur wenn Klient noch aktiv
    if ($laufend_pruefen && !empty($haar_list)) {
        $letzte_haar = parse_date(end($haar_list)['probe_date']);
        if ($letzte_haar !== null) {
            $tage_seit = (int)$letzte_haar->diff($heute)->days;
            if ($tage_seit > $haar_luecke) {
                $gaps[] = ['von' => end($haar_list)['probe_date'], 'bis' => $heute->format('Y-m-d'), 'tage' => $tage_seit, 'method' => 'haar'];
            }
        }
    }

    return ['gaps' => $gaps, 'hat_positiv' => $hat_positiv, 'letzte_probe' => $letzte_probe];
}


// ── 3. Checklisten-Tasks provisionieren ─────────────────────────────────────

/**
 * Stellt sicher, dass alle delikt-relevanten Tasks in client_tasks vorhanden sind.
 * Idempotent: INSERT IGNORE verhindert Duplikate bei wiederholtem Aufruf.
 *
 * @param PDO         $pdo
 * @param int         $client_id
 * @param int         $case_id               ID der aktiven Fallakte
 * @param string      $offense_category_json  JSON-String aus cases.offense_category
 * @param string|null $sperrfrist_ende        Datum aus cases.suspension_end_date ('Y-m-d')
 * @param string      $antrag_status          Wert aus cases.antrag_status
 */
function provision_client_tasks(PDO $pdo, int $client_id, int $case_id, string $offense_category_json, ?string $sperrfrist_ende = null, string $antrag_status = 'nicht_gestellt'): void
{
    $templates    = require __DIR__ . '/../config/checklist_templates.php';
    $offense_list = json_decode($offense_category_json, true) ?: [];

    // Relevante Kategorien bestimmen
    $kategorien = ['alle'];
    if (in_array('Alkohol', $offense_list, true)) $kategorien[] = 'alkohol';
    if (in_array('Drogen',  $offense_list, true)) $kategorien[] = 'drogen';
    if (in_array('Punkte',  $offense_list, true)) $kategorien[] = 'punkte';

    // Wiedererteilungs-Tasks: Sperrfrist eingetragen oder Antrag bereits eingeleitet
    if (!empty($sperrfrist_ende) || !in_array($antrag_status, ['nicht_gestellt', ''], true)) {
        $kategorien[] = 'wiedererteilung';
    }

    // Alle Task-Keys sammeln die aktuell gültig sind
    $gueltige_keys = [];
    $gesehen = [];
    foreach ($kategorien as $kat) {
        foreach ($templates[$kat] ?? [] as $task) {
            if (!in_array($task['task_key'], $gesehen, true)) {
                $gueltige_keys[] = $task['task_key'];
                $gesehen[]       = $task['task_key'];
            }
        }
    }

    // Neue Tasks einfügen (idempotent)
    $stmt_insert = $pdo->prepare(
        "INSERT IGNORE INTO client_tasks (client_id, case_id, task_key) VALUES (?, ?, ?)"
    );
    foreach ($gueltige_keys as $key) {
        $stmt_insert->execute([$client_id, $case_id, $key]);
    }

    // Veraltete Tasks entfernen: nur Tasks löschen die noch auf 'offen' stehen
    // (angefordert/liegt_vor wurden bereits bearbeitet – die bewusst behalten)
    if (!empty($gueltige_keys)) {
        $platzhalter = implode(',', array_fill(0, count($gueltige_keys), '?'));
        $pdo->prepare(
            "DELETE FROM client_tasks
             WHERE client_id = ?
               AND case_id = ?
               AND status = 'offen'
               AND task_key NOT IN ({$platzhalter})"
        )->execute(array_merge([$client_id, $case_id], $gueltige_keys));
    }
}


// ── 4. Karenzzeit-Prüfung ────────────────────────────────────────────────────

/**
 * Prüft ob zwischen dem berechneten Abstinenz-Ende und dem Sperrfrist-Ende
 * weniger als 90 Tage (3 Monate) Puffer bleiben.
 *
 * Abstinenz-Beginn = Datum des ersten Belegs nach dem letzten positiven Befund.
 * Abstinenz-Ende   = Abstinenz-Beginn + abstinenz_monate.
 *
 * @param string|null $sperrfrist_ende  Datum aus cases.suspension_end_date ('Y-m-d')
 * @param array       $probes           Zeilen aus abstinence_probes
 * @param int         $abstinenz_monate Pflicht-Abstinenzdauer in Monaten
 * @return array{kollision: bool, abstinenz_ende: string, puffer_tage: int}|null
 *   null wenn nicht genug Daten vorhanden
 */
function get_karenzzeit_info(?string $sperrfrist_ende, array $probes, int $abstinenz_monate): ?array
{
    if (empty($sperrfrist_ende) || $abstinenz_monate <= 0 || empty($probes)) {
        return null;
    }

    $belege = abstinenz_belege_der_kette($probes);
    if ($belege === []) {
        return null; // Keine gültige Probe in der laufenden Kette: Beginn nicht bestimmbar
    }
    $abstinenz_beginn = parse_date($belege[0]['probe_date']);
    if ($abstinenz_beginn === null) {
        return null;
    }
    $abstinenz_ende   = $abstinenz_beginn->modify("+{$abstinenz_monate} months");
    $sperrfrist = parse_date($sperrfrist_ende);
    if ($sperrfrist === null) {
        return null;
    }

    $diff        = $abstinenz_ende->diff($sperrfrist);
    $puffer_tage = (int)$diff->days * ($abstinenz_ende <= $sperrfrist ? 1 : -1);

    return [
        'kollision'      => $puffer_tage < 90,
        'abstinenz_ende' => $abstinenz_ende->format('Y-m-d'),
        'puffer_tage'    => $puffer_tage,
    ];
}


// ── 4b. UX-6: Vollständigkeit der Triage-Grundlage ──────────────────────────

/**
 * UX-6: Bewertet, ob der ICD-Quick-Check für einen Fall vollständig erhoben ist.
 *
 * Fachliche Grundlage der Dreizustands-Logik: bewertet werden kann nur, was
 * bekannt ist. Beim Erstkontakt (oft telefonisch) liegen die 6 Kriterien in
 * der Regel noch nicht vor. Die Aufnahme wird deshalb nicht blockiert – der
 * Fall wird stattdessen als **vorläufig** geführt, bis die Kriterien in
 * `bearbeiten.php` nachgetragen sind.
 *
 * Wichtig für die Interpretation des Triage-Ergebnisses: `berechne_triage()`
 * wertet nur explizit bejahte Kriterien (= 1). Ein noch nicht erhobenes
 * Kriterium (NULL) geht als "nicht erfüllt" ein. Solange `unvollstaendig`
 * true ist, kann der ICD-Score also nur nach oben korrigiert werden – die
 * ausgewiesene Hypothese ist eine Untergrenze, keine abschließende Bewertung.
 *
 * Ohne Konsum-Delikt (weder Alkohol noch Drogen) sind die Kriterien fachlich
 * nicht einschlägig; die Triage gilt dann als vollständig.
 *
 * @param array $akte Zeile aus cases (braucht offense_category + die 6 icd_*-Spalten)
 * @return array{unvollstaendig: bool, erhoben: int, offen: int, gesamt: int, fehlend: string[]}
 *   fehlend: Klartext-Labels der noch nicht erhobenen Kriterien
 */
function get_triage_vollstaendigkeit(array $akte): array
{
    $labels = [
        'icd_kontrollverlust' => 'Kontrollverlust',
        'icd_priorisierung'   => 'Priorisierung',
        'icd_folgeschaeden'   => 'Folgeschäden',
        'icd_craving'         => 'Craving',
        'icd_toleranz'        => 'Toleranz',
        'icd_entzug'          => 'Entzugssyndrom',
    ];

    $delikte = json_decode((string)($akte['offense_category'] ?? ''), true);
    if (!is_array($delikte)) { $delikte = []; }
    $hat_konsum_delikt = in_array('Alkohol', $delikte, true) || in_array('Drogen', $delikte, true);

    $fehlend = [];
    foreach ($labels as $spalte => $label) {
        if (($akte[$spalte] ?? null) === null) { $fehlend[] = $label; }
    }
    $offen   = count($fehlend);
    $gesamt  = count($labels);

    return [
        'unvollstaendig' => $hat_konsum_delikt && $offen > 0,
        'erhoben'        => $gesamt - $offen,
        'offen'          => $offen,
        'gesamt'         => $gesamt,
        'fehlend'        => $fehlend,
    ];
}


// ── 4c. E-7: Screening-Scores (AUDIT / LAST) für die Anzeige aufbereiten ────

/**
 * E-7: Wertet die dokumentierten Screening-Scores für die Anzeige aus.
 *
 * AUDIT und LAST werden erfasst, fließen aber bewusst nicht in
 * berechne_triage() ein. Damit sie nicht nur im Bearbeiten-Formular
 * wiederauffindbar sind, liefert diese Funktion je Score Wert, Einstufung und
 * Ampelfarbe — genutzt von akte.php (Bildschirm) und akte_druck.php (Papier),
 * damit beide dieselbe Einstufung zeigen.
 *
 * Schwellen und ihre Quellen: config/screening_config.php.
 * Die Labels sind bewusst als Anlass formuliert, nicht als Befund: das
 * WHO-Manual begründet mit AUDIT ≥ 20 die diagnostische Abklärung, nicht die
 * Feststellung einer Abhängigkeit.
 *
 * @param array $akte Zeile aus cases (braucht audit_score, last_score)
 * @return list<array{name: string, wert: int, max: int, label: string, level: string}>
 *   leer, wenn kein Score erhoben wurde
 */
function get_screening_scores(array $akte): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/screening_config.php';
    }

    $ergebnis = [];

    $audit = $akte['audit_score'] ?? null;
    if ($audit !== null && $audit !== '') {
        $audit = (int)$audit;
        if ($audit >= $cfg['audit']['abklaeren']) {
            $label = 'Abhängigkeit diagnostisch abklären';
            $level = 'danger';
        } elseif ($audit >= $cfg['audit']['schaedlich']) {
            $label = 'schädlicher Konsum';
            $level = 'warning';
        } elseif ($audit >= $cfg['audit']['riskant']) {
            $label = 'riskanter Konsum';
            $level = 'warning';
        } else {
            $label = 'unauffällig';
            $level = 'success';
        }
        $ergebnis[] = [
            'name'  => 'AUDIT',
            'wert'  => $audit,
            'max'   => (int)$cfg['audit']['max'],
            'label' => $label,
            'level' => $level,
        ];
    }

    $last = $akte['last_score'] ?? null;
    if ($last !== null && $last !== '') {
        $last = (int)$last;
        $auffaellig = $last >= $cfg['last']['auffaellig'];
        $ergebnis[] = [
            'name'  => 'LAST',
            'wert'  => $last,
            'max'   => (int)$cfg['last']['max'],
            'label' => $auffaellig
                ? 'Hinweis auf Missbrauch oder beginnende Abhängigkeit'
                : 'unauffällig',
            'level' => $auffaellig ? 'warning' : 'success',
        ];
    }

    return $ergebnis;
}


// ── 5. Haupt-Heuristik: "Nächster Schritt" ──────────────────────────────────

/**
 * Ermittelt den dringendsten Handlungsbedarf für einen Klienten.
 * Prüft alle Signale in absteigender Priorität und gibt den ersten Treffer zurück.
 *
 * @param array  $akte    Zeile aus clients JOIN cases
 * @param array  $probes  Zeilen aus abstinence_probes für diesen Klienten
 * @param array  $tasks   Zeilen aus client_tasks für diesen Klienten
 * @return array{level: string, message: string, action_url: string}
 *   level: 'danger' | 'warning' | 'success'
 */
function get_client_status(array $akte, array $probes, array $tasks): array
{
    $client_id = (int)$akte['client_id'];

    // ── Priorität 1: Positiver Abstinenz-Befund ──────────────────────────────
    // Bei abgeschlossenen Klienten keine laufende Lücke berechnen (kein Dauerwarnhinweis).
    $laufend_pruefen = ($akte['status'] ?? 'aktiv') !== 'abgeschlossen';
    // R3-3: Deliktart mitgeben – die Haar-Lückenschwelle hängt an der
    // zulässigen Segmentlänge (3 Monate Alkohol / 6 Monate Drogen).
    $abstinenz = get_abstinenz_luecken(
        $probes,
        $laufend_pruefen,
        isset($akte['offense_category']) ? (string)$akte['offense_category'] : null
    );
    if ($abstinenz['hat_positiv']) {
        return [
            'level'      => 'danger',
            'message'    => 'Sofortmaßnahme: Positiver Abstinenz-Befund dokumentiert – Abstinenzkette unterbrochen.',
            'action_url' => "akte.php?id={$client_id}#abstinenz",
        ];
    }

    // ── Priorität 2: Lücke in der Abstinenzkette ─────────────────────────────
    if (!empty($abstinenz['gaps'])) {
        $letzte = $abstinenz['letzte_probe']
            ? date('d.m.Y', strtotime($abstinenz['letzte_probe']))
            : 'unbekannt';
        return [
            'level'      => 'danger',
            'message'    => "Abstinenz-Lücke erkannt – letzte Probe: {$letzte}. Nächste Probe überfällig.",
            'action_url' => "akte.php?id={$client_id}#abstinenz",
        ];
    }

    // ── Priorität 3: Antragsfrist kritisch (rot) ─────────────────────────────
    // E-4: mit der Vorlauffrist der zuständigen Behörde, damit die Arbeitsliste
    // dieselbe Dringlichkeit zeigt wie die Karte "Dringende Fristen & Warnungen".
    $ampel = get_antrag_ampel(
        $akte['suspension_end_date'] ?? null,
        (string)($akte['antrag_status'] ?? ''),
        $akte['mpu_target_date'] ?? null,
        get_behoerden_vorlauf_aus_akte($akte)
    );
    if ($ampel['level'] === 'danger') {
        if ($ampel['days_remaining'] !== null) {
            $tage = $ampel['days_remaining'] >= 0
                ? " (noch {$ampel['days_remaining']} Tage)"
                : " (vor " . abs($ampel['days_remaining']) . " Tagen abgelaufen)";
        } else {
            $tage = '';
        }
        return [
            'level'      => 'danger',
            'message'    => "Antrag bei der Führerscheinstelle sofort stellen{$tage}.",
            'action_url' => "akte.php?id={$client_id}#todos",
        ];
    }

    // ── Einwilligung offen (nach telefonischer Aufnahme) ─────────────────────
    if (array_key_exists('dsgvo_consent', $akte) && empty($akte['dsgvo_consent']) && empty($akte['revocation_confirmed'])) {
        return [
            'level'      => 'warning',
            'message'    => 'Einwilligung zum Datenschutz fehlt – unterzeichnen lassen und in der Akte eintragen.',
            'action_url' => "akte.php?id={$client_id}#einwilligung",
        ];
    }

    // ── Priorität 4: Antragsfrist bald (gelb) ────────────────────────────────
    // R3-7: get_antrag_ampel() liefert 'warning' in sechs Konstellationen, und
    // nur in zweien davon ist days_remaining gesetzt. Der frühere Einheitssatz
    // "Antrag vorbereiten – noch {$tage} Tage bis Sperrfrist-Ende" war deshalb
    // in vier Fällen falsch: bei vorliegender MPU-Anordnung und bei abgelehntem
    // Antrag stand dort ein wörtliches "?", beim MPU-Zielmonat-Zweig wurde eine
    // Sperrfrist behauptet, die es in dieser Konstellation gar nicht gibt.
    //
    // Das Label der Ampel benennt die Lage bereits zutreffend ("Aufforderung
    // liegt vor", "Neuer Anlauf erforderlich", "MPU-Zielmonat rückt näher …")
    // und wird übernommen statt verworfen. Aufbau der Tagesangabe wie in
    // Priorität 3: nur anhängen, wenn sie existiert.
    if ($ampel['level'] === 'warning') {
        $tage = $ampel['days_remaining'] !== null
            ? " (noch {$ampel['days_remaining']} Tage)"
            : '';
        // Liegt die Aufforderung bereits vor, ist der Antrag gegenstandslos –
        // der nächste Schritt ist die Reaktion darauf, nicht die Checkliste.
        $sprungziel = ($akte['antrag_status'] ?? '') === 'aufforderung_liegt_vor'
            ? 'korrespondenz'
            : 'todos';
        return [
            'level'      => 'warning',
            'message'    => "{$ampel['label']}{$tage}.",
            'action_url' => "akte.php?id={$client_id}#{$sprungziel}",
        ];
    }

    // ── Priorität 5: Triage nur vorläufig (UX-6) ─────────────────────────────
    // Der Fall durfte ohne vollständigen ICD-Quick-Check angelegt werden.
    // Bis er nachgetragen ist, sind Hypothese und Abstinenzdauer eine
    // Untergrenze – das muss vor der Feinplanung geklärt sein.
    // Nur prüfen, wenn der Aufrufer die icd_*-Spalten überhaupt geladen hat.
    if (array_key_exists('icd_kontrollverlust', $akte)) {
        $vollstaendigkeit = get_triage_vollstaendigkeit($akte);
        if ($vollstaendigkeit['unvollstaendig']) {
            $offen = $vollstaendigkeit['offen'];
            return [
                'level'      => 'warning',
                'message'    => "Triage vorläufig – {$offen} von {$vollstaendigkeit['gesamt']} ICD-Kriterien noch nicht erhoben. Hypothese kann sich noch verschärfen.",
                'action_url' => "bearbeiten.php?id={$client_id}#icd_quickcheck",
            ];
        }
    }

    // ── Priorität 6: Checkliste unter 50 % erledigt ──────────────────────────
    if (!empty($tasks)) {
        $offen    = count(array_filter($tasks, fn($t) => $t['status'] === 'offen'));
        $gesamt   = count($tasks);
        $prozent  = (int)(($gesamt - $offen) / $gesamt * 100);
        if ($prozent < 50) {
            return [
                'level'      => 'warning',
                'message'    => "Administrative Aufgaben abschließen – {$offen} von {$gesamt} Punkten noch offen ({$prozent} % erledigt).",
                'action_url' => "akte.php?id={$client_id}#todos",
            ];
        }
    }

    // ── Alles im grünen Bereich ───────────────────────────────────────────────
    return [
        'level'      => 'success',
        'message'    => 'Alles im grünen Bereich – keine dringenden Maßnahmen.',
        'action_url' => "akte.php?id={$client_id}",
    ];
}

/**
 * Screening-Frequenz-Ampel (F12)
 *
 * Bewertet, ob die bisher vorliegenden Abstinenz-Proben im Zeitverlauf ausreichen.
 *
 * @param int    $actual       Tatsächliche negative Proben dieser Methode
 * @param int    $soll         Pflichtanzahl laut Abstinenzprogramm
 * @param array  $probes       Alle Proben des Klienten (älteste zuerst)
 * @param int    $abst_monate  Abstinenz-Programmlänge in Monaten
 * @param string $created_at   Fallanlagedatum (ISO-Format) als Fallback-Startpunkt
 * @return string Bootstrap-Color-Level: 'success' | 'warning' | 'danger' | 'secondary'
 */
function screening_ampel(int $actual, int $soll, array $probes, int $abst_monate, string $created_at): string
{
    if ($soll === 0) return 'secondary';
    if ($actual >= $soll) return 'success';
    // Programm-Start ermitteln
    $start_str = !empty($probes) ? $probes[0]['probe_date'] : substr($created_at, 0, 10);
    try {
        $start = new DateTimeImmutable($start_str);
        $end   = $start->modify("+{$abst_monate} months");
        $today = new DateTimeImmutable('today');
    } catch (Exception) {
        return 'warning'; // Kein Urteil möglich
    }
    if ($today >= $end) return 'danger'; // Programm abgelaufen, Soll nicht erfüllt
    $tage_gesamt    = (int)$start->diff($end)->days;
    $tage_vergangen = (int)$start->diff($today)->days;
    if ($tage_gesamt === 0) return 'warning';
    $erwartet = $soll * ($tage_vergangen / $tage_gesamt);
    return ($actual >= $erwartet * 0.8) ? 'warning' : 'danger';
}

/**
 * BKF-Leistungstest-Ampel (F-2)
 *
 * Bewertet, ob die erfassten psychophysischen Testergebnisse die BKF-Anforderungen
 * (BK-5 Kap. C 4) erfüllen.
 *
 * @param array $tests   Alle Testergebnisse des Falls (aus performance_tests)
 * @param bool  $ist_bkf True bei bkf_status 'aktiv' oder 'angestrebt'
 * @return array{level: string, fail_pr16: int, fail_pr33: int, kompensiert: bool, warn: string}
 */
function bkf_leistung_ampel(array $tests, bool $ist_bkf): array
{
    if (empty($tests)) {
        return [
            'level'       => 'secondary',
            'fail_pr16'   => 0,
            'fail_pr33'   => 0,
            'kompensiert' => false,
            'warn'        => 'Noch keine Leistungstests erfasst.',
        ];
    }

    $percentiles = array_map(fn($t) => (int)$t['percentile'], $tests);

    foreach ($percentiles as $pr) {
        if ($pr < 1 || $pr > 99) {
            return [
                'level'       => 'danger',
                'fail_pr16'   => 0,
                'fail_pr33'   => 0,
                'kompensiert' => false,
                'warn'        => 'Ungültiger PR-Wert erkannt – Eingabe prüfen.',
            ];
        }
    }

    $fail_pr16 = count(array_filter($percentiles, fn($p) => $p < 16));
    $fail_pr33 = count(array_filter($percentiles, fn($p) => $p < 33));

    $level       = 'success';
    $kompensiert = false;
    $warn        = '';

    if ($fail_pr16 > 0) {
        $uebrige = array_values(array_filter($percentiles, fn($p) => $p >= 16));
        if (empty($uebrige) || count(array_filter($uebrige, fn($p) => $p < 50)) > 0) {
            $level = 'danger';
            $warn  = 'Test(s) unter PR 16 ohne ausreichende Kompensation.';
        } else {
            $level       = 'warning';
            $kompensiert = true;
            $warn        = 'PR-16-Ausreißer durch übrige Tests (≥ PR 50) kompensiert.';
        }
    }

    if ($ist_bkf) {
        $anzahl = count($percentiles);
        $gte33  = count(array_filter($percentiles, fn($p) => $p >= 33));
        if ($gte33 * 2 <= $anzahl) {
            if ($level === 'success') {
                $level = 'warning';
                $warn  = 'BKF: Mehrheit der Tests erreicht PR ≥ 33 nicht.';
            }
        }
    }

    if ($warn === '') {
        $warn = 'Alle Mindestanforderungen erfüllt.';
    }

    return [
        'level'       => $level,
        'fail_pr16'   => $fail_pr16,
        'fail_pr33'   => $fail_pr33,
        'kompensiert' => $kompensiert,
        'warn'        => $warn,
    ];
}

/**
 * Liefert alle Klienten mit abgelaufener DSGVO-Aufbewahrungsfrist
 * (DSGVO_AUFBEWAHRUNG_JAHRE): sämtliche Fälle abgeschlossen (kein aktiver oder
 * pausierter Fall) und der letzte Fallabschluss liegt länger als die Frist
 * zurück. Inklusive Zähler der verknüpften Datensätze. Deckt sich mit der Logik
 * aus scripts/dsgvo_check.php.
 *
 * Voraussetzung: Konstante DSGVO_AUFBEWAHRUNG_JAHRE ist geladen
 * (config/allowed_values.php).
 *
 * @return list<array{id:int,first_name:string,last_name:string,letzter_abschluss:?string,anzahl_audit:int,anzahl_proben:int,anzahl_sitzungen:int}>
 */
function dsgvo_faellige_klienten(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.first_name, c.last_name,
                MAX(ca.closed_at) AS letzter_abschluss,
                (SELECT COUNT(*) FROM audit_log        al WHERE al.client_id = c.id) AS anzahl_audit,
                (SELECT COUNT(*) FROM abstinence_probes ap WHERE ap.client_id = c.id) AS anzahl_proben,
                (SELECT COUNT(*) FROM sessions          s  WHERE s.client_id  = c.id) AS anzahl_sitzungen
         FROM clients c
         JOIN cases ca ON ca.client_id = c.id
         GROUP BY c.id, c.first_name, c.last_name
         HAVING SUM(CASE WHEN ca.status IN ('aktiv','pausiert') THEN 1 ELSE 0 END) = 0
            AND MAX(ca.closed_at) IS NOT NULL
            AND MAX(ca.closed_at) <= DATE_SUB(CURDATE(), INTERVAL ? YEAR)
         ORDER BY letzter_abschluss ASC"
    );
    $stmt->execute([DSGVO_AUFBEWAHRUNG_JAHRE]);
    return $stmt->fetchAll();
}

/**
 * Leichtgewichtige Anzahl fälliger Klienten (für die Navbar-Sichtbarkeit).
 * Voraussetzung: Konstante DSGVO_AUFBEWAHRUNG_JAHRE ist geladen.
 */
function dsgvo_faellige_anzahl(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
             SELECT c.id
             FROM clients c
             JOIN cases ca ON ca.client_id = c.id
             GROUP BY c.id
             HAVING SUM(CASE WHEN ca.status IN ('aktiv','pausiert') THEN 1 ELSE 0 END) = 0
                AND MAX(ca.closed_at) IS NOT NULL
                AND MAX(ca.closed_at) <= DATE_SUB(CURDATE(), INTERVAL ? YEAR)
         ) t"
    );
    $stmt->execute([DSGVO_AUFBEWAHRUNG_JAHRE]);
    return (int) $stmt->fetchColumn();
}

/**
 * UX-4: Leitet aus einem Hypothesen-Label (z.B. "Hypothese A2 (Fortgeschrittene
 * Alkoholproblematik)") den passenden Anker in anleitung.php ab.
 *
 * A1–A4 und D1–D4 haben eigene Detail-Abschnitte (#a1detail … #d4detail).
 * V1–V3 und M1–M3 haben keinen eigenen Abschnitt – sie stehen nur in der
 * zusammenfassenden Übersichtstabelle (#faelle), dorthin wird stattdessen
 * verlinkt. Kein erkennbarer Code (z.B. leeres Label) → leerer String,
 * Aufrufer verlinkt dann ohne Anker auf anleitung.php.
 */
/**
 * UX-2: Tabler-Standard `is-invalid` fuer ein Feld mit Validierungsfehler.
 * Gemeinsam genutzt von aufnahme.php und bearbeiten.php.
 *
 * @param array<string,string> $fehler feldname => Meldung
 */
function feld_invalid_klasse(array $fehler, string $feld): string
{
    return isset($fehler[$feld]) ? ' is-invalid' : '';
}

function anleitung_anchor_fuer_hypothese(string $hypothese): string
{
    if (!preg_match('/\b([AD][1-4]|[VM][1-3])\b/', $hypothese, $treffer)) {
        return '';
    }
    $code = strtolower($treffer[1]);
    return in_array($treffer[1][0], ['A', 'D'], true) ? "{$code}detail" : 'faelle';
}
