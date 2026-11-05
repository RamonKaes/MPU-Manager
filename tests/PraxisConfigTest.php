<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Die Druckdokumente lesen die Angaben zur Beratungsstelle aus
 * config/praxis_config.php. Ein Schlüssel, den es dort nicht gibt, ergäbe eine
 * Lücke in einem rechtlich relevanten Dokument. Im Repository dürfen zudem nur
 * Platzhalter stehen, keine echten Praxisdaten.
 */
final class PraxisConfigTest extends TestCase
{
    private const DRUCKSEITEN = [
        'vertrag_druck.php',
        'datenschutz_druck.php',
        'widerruf_druck.php',
        'strategie_druck.php',
    ];

    public function testDruckseitenNutzenNurVorhandeneSchluessel(): void
    {
        $praxis = require __DIR__ . '/../config/praxis_config.php';

        foreach (self::DRUCKSEITEN as $datei) {
            $inhalt = (string)file_get_contents(__DIR__ . '/../' . $datei);
            $treffer = preg_match_all('/\$praxis\[\'([a-z_]+)\'\]/', $inhalt, $funde);

            $this->assertGreaterThan(0, $treffer, "$datei liest keine Praxisdaten aus der Konfiguration.");
            foreach ($funde[1] as $schluessel) {
                $this->assertArrayHasKey($schluessel, $praxis, "$datei nutzt einen unbekannten Schlüssel.");
            }
        }
    }

    public function testKontaktfelderSindPlatzhalter(): void
    {
        $praxis = require __DIR__ . '/../config/praxis_config.php';

        foreach (['name', 'berufsbezeichnung', 'strasse', 'plz', 'ort', 'telefon', 'email', 'aufsichtsbehoerde'] as $schluessel) {
            $this->assertMatchesRegularExpression('/^\[.+\]$/u', $praxis[$schluessel], "$schluessel ist kein Platzhalter.");
        }
    }
}
