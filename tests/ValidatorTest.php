<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für logic/validator.php
 *
 * Ausführen: composer test
 *   oder:    vendor/bin/phpunit --testdox tests/ValidatorTest.php
 */
class ValidatorTest extends TestCase
{
    // ── validate_probe_date() ─────────────────────────────────────────────────

    public function testProbeDateLeerIstUngueltig(): void
    {
        $this->assertFalse(validate_probe_date(''));
    }

    public function testProbeDateGueltigesVergangenesDatum(): void
    {
        $this->assertTrue(validate_probe_date('2025-01-15'));
    }

    public function testProbeDateZukunftIstUngueltig(): void
    {
        $future = date('Y-m-d', strtotime('+1 day'));
        $this->assertFalse(validate_probe_date($future));
    }

    public function testProbeDateFalschesFormatIstUngueltig(): void
    {
        $this->assertFalse(validate_probe_date('15.01.2025'));
    }

    // ── validate_method() ────────────────────────────────────────────────────

    public function testMethodUrinIstGueltig(): void
    {
        $this->assertTrue(validate_method('urin'));
    }

    public function testMethodUnbekannterWertIstUngueltig(): void
    {
        $this->assertFalse(validate_method('atemluft'));
    }

    // ── validate_result() ────────────────────────────────────────────────────

    public function testResultNegativIstGueltig(): void
    {
        $this->assertTrue(validate_result('negativ'));
    }

    public function testResultUnbekannterWertIstUngueltig(): void
    {
        $this->assertFalse(validate_result('unbekannt'));
    }

    // ── validate_dsgvo_export() ───────────────────────────────────────────────

    public function testDsgvoExportVollstaendigIstGueltig(): void
    {
        $export = [
            'stammdaten'          => [],
            'fallakten'           => [], 'notizen' => [],
            'abstinenz_nachweise' => [],
            'sitzungen'           => [],
            'aufgaben'            => [],
            'korrespondenz'       => [],
            'leistungstests'      => [],
        ];
        $this->assertTrue(validate_dsgvo_export($export));
    }

    public function testDsgvoExportFehlenderKeyKorrespondenzIstUngueltig(): void
    {
        $export = [
            'stammdaten'          => [],
            'fallakten'           => [],
            'abstinenz_nachweise' => [],
            'sitzungen'           => [],
            'aufgaben'            => [],
        ];
        $this->assertFalse(validate_dsgvo_export($export));
    }
}
