<?php
declare(strict_types=1);

/** Ein echtes Kalenderdatum im Format JJJJ-MM-TT, also kein 2026-02-31. */
function validate_datum(string $val): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $val, $t)) {
        return false;
    }
    return (int)$t[1] >= 1 && checkdate((int)$t[2], (int)$t[3], (int)$t[1]);
}

function validate_probe_date(string $val): bool
{
    return validate_datum($val) && $val <= date('Y-m-d');
}

/** Zahl als Text im erlaubten Bereich. is_numeric lehnt auch NAN und INF ab. */
function validate_zahl(string $val, float $min, float $max): bool
{
    return is_numeric($val) && (float)$val >= $min && (float)$val <= $max;
}

/**
 * Prüft die Felder, die Aufnahme und Bearbeiten in dieselben Spalten schreiben.
 *
 * Beide Handler rufen diese Funktion auf, damit derselbe POST nicht je nach Formular
 * einen anderen Datensatz erzeugt. Die Grenzen folgen den Spalten in schema.sql.
 *
 * @param array<string,mixed> $post
 * @return array<string,string> Feldname => Meldung
 */
function validiere_gemeinsame_felder(array $post): array
{
    $text = static fn(string $feld): string => is_string($post[$feld] ?? null) ? trim($post[$feld]) : '';
    $fehler = [];

    if ($text('geschlecht') !== '' && !in_array($text('geschlecht'), ['m', 'f', 'd'], true)) {
        $fehler['geschlecht'] = 'Bitte ein gültiges Geschlecht wählen.';
    }
    foreach (['geburtsdatum' => 'Geburtsdatum', 'datum_des_deliktes' => 'Datum des Delikts', 'datum_rechtskraft' => 'Datum der Rechtskraft',
              'sperrfrist_ende' => 'Ende der Sperrfrist', 'bussgeld_datum' => 'Datum des Bußgeldbescheids', 'payment_date' => 'Zahlungsdatum'] as $feld => $name) {
        if ($text($feld) !== '' && !validate_datum($text($feld))) {
            $fehler[$feld] = "{$name}: Dieses Datum gibt es nicht.";
        }
    }
    if ($text('mpu_zielmonat') !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $text('mpu_zielmonat'))) {
        $fehler['mpu_zielmonat'] = 'MPU-Zielmonat: Bitte einen gültigen Monat wählen.';
    }
    foreach (['vorname' => [100, 'Vorname'], 'nachname' => [100, 'Nachname'], 'strasse' => [150, 'Straße'], 'wohnort' => [100, 'Wohnort'],
              'telefonnummer' => [50, 'Telefonnummer'], 'email_adresse' => [100, 'E-Mail-Adresse'], 'behoerde' => [150, 'Behörde'],
              'aktenzeichen' => [100, 'Aktenzeichen']] as $feld => [$max, $name]) {
        if (mb_strlen($text($feld)) > $max) {
            $fehler[$feld] = "{$name}: höchstens {$max} Zeichen.";
        }
    }
    foreach (['koerpergewicht' => [1.0, 300.0, 'Körpergewicht'], 'tetrahydrocannabinol_wert' => [0.0, 999.99, 'THC-Wert'],
              'thc_cooh_value' => [0.0, 99999.99, 'THC-COOH-Wert'], 'honorar_gesamt' => [0.0, 999999.99, 'Honorar'],
              'stundensatz' => [0.0, 999999.99, 'Stundensatz'], 'payment_amount_paid' => [0.0, 999999.99, 'Gezahlter Betrag']] as $feld => [$min, $max, $name]) {
        if ($text($feld) !== '' && !validate_zahl($text($feld), $min, $max)) {
            $fehler[$feld] = "{$name}: Bitte eine Zahl von " . number_format($min, 0, ',', '.') . ' bis ' . number_format($max, 2, ',', '.') . ' eingeben.';
        }
    }
    return $fehler;
}

function validate_method(string $val): bool
{
    return in_array($val, ['urin', 'haar', 'blut', 'peth'], true);
}

function validate_result(string $val): bool
{
    return in_array($val, ['negativ', 'positiv'], true);
}

function validate_dsgvo_export(array $export): bool
{
    foreach (['stammdaten', 'fallakten', 'notizen', 'abstinenz_nachweise', 'sitzungen', 'aufgaben', 'korrespondenz', 'leistungstests'] as $key) {
        if (!array_key_exists($key, $export)) {
            return false;
        }
    }
    return true;
}
