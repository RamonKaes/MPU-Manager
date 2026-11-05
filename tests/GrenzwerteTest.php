<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft Schwellen genau auf der Grenze und daneben, in beide Richtungen.
 *
 * Eine Gegenprobe (Vergleich oder Config-Wert gedreht) ließ hier zuvor den größten
 * Teil der Suite grün. Die Werte kommen aus der Config, damit diese Tests beim
 * Anpassen in einem Fork nicht falsch rot werden.
 */
final class GrenzwerteTest extends TestCase
{
    private static function cfg(): array
    {
        return require __DIR__ . '/../config/triage_config.php';
    }

    /** @param array<string,mixed> $ueber */
    private function triage(array $ueber): array
    {
        return berechne_triage(...array_merge([
            'delikte' => ['Alkohol'], 'drogen' => [], 'bak' => 0.0, 'therapy' => false, 'repeat' => false,
            'mehrfachdelikt' => false, 'symptoms' => false, 'punkte' => 0, 'aggression' => false,
            'thc_wert' => 0.0, 'klassen' => ['B'], 'bkf_status' => 'nein',
            'icd_kontrollverlust' => null, 'icd_priorisierung' => null, 'icd_folgeschaeden' => null,
            'icd_craving' => null, 'icd_toleranz' => null, 'icd_entzug' => null,
        ], $ueber));
    }

    private function tage(int $n): string
    {
        return (new DateTimeImmutable('today'))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    // ── R4-2: Vor-MPU oder Mehrfachdelikt unter der Giftfestigkeitsgrenze ──────────────

    public function testVorMpuUnterGiftfestigkeitNenntDieMonate(): void
    {
        $cfg = self::cfg();
        $bak = (float)$cfg['bak']['giftfestigkeit'] - 0.5;
        $ohne = $this->triage(['bak' => $bak, 'repeat' => true]);
        $mit  = $this->triage(['bak' => $bak, 'repeat' => true, 'therapy' => true]);
        $this->assertSame((int)$cfg['A2_gefaehrdung']['monate_selbstheiler'], $ohne['abstinenz_monate']);
        $this->assertSame((int)$cfg['A2_gefaehrdung']['monate_therapie'], $mit['abstinenz_monate']);
        $this->assertStringContainsString($cfg['A2_gefaehrdung']['monate_selbstheiler'] . ' Mon.', implode(' ', $ohne['warnungen']));
        $this->assertStringContainsString('Vor-MPU', implode(' ', $ohne['warnungen']));
    }

    public function testMehrfachdeliktUnterGiftfestigkeitNenntDieMonate(): void
    {
        $cfg = self::cfg();
        $r = $this->triage(['bak' => (float)$cfg['bak']['giftfestigkeit'] - 0.5, 'mehrfachdelikt' => true, 'therapy' => true]);
        $this->assertStringContainsString($cfg['A2_gefaehrdung']['monate_therapie'] . ' Mon.', implode(' ', $r['warnungen']));
        $this->assertStringContainsString('Mehrfachdelikt', implode(' ', $r['warnungen']));
    }

    /** Ein falscher Schlüssel ist nur eine Warnung, unter db.php aber ein Absturz der Seite. */
    public function testJederConfigZugriffDerTriageExistiert(): void
    {
        $cfg = self::cfg();
        preg_match_all("/\\\$cfg\['([a-zA-Z0-9_]+)'\]\['([a-zA-Z0-9_]+)'\]/", (string)file_get_contents(__DIR__ . '/../triage.php'), $funde, PREG_SET_ORDER);
        $this->assertNotEmpty($funde);
        foreach ($funde as $f) {
            $this->assertArrayHasKey($f[1], $cfg, $f[0]);
            $this->assertArrayHasKey($f[2], $cfg[$f[1]], $f[0]);
        }
    }

    // ── Triage-Grenzen ─────────────────────────────────────────────────────────────────

    public function testBakGiftfestigkeitGiltAbDemWert(): void
    {
        $g = (float)self::cfg()['bak']['giftfestigkeit'];
        $this->assertStringContainsString('A2 / A3', $this->triage(['bak' => $g])['hypothese_text']);
        $this->assertStringNotContainsString('A2', $this->triage(['bak' => $g - 0.01])['hypothese_text']);
    }

    public function testBakA2HochGiltAbDemWert(): void
    {
        $g = (float)self::cfg()['bak']['a2_hoch'];
        $this->assertStringContainsString('Fortgeschrittene', $this->triage(['bak' => $g])['hypothese_text']);
        $this->assertStringNotContainsString('Fortgeschrittene', $this->triage(['bak' => $g - 0.01])['hypothese_text']);
    }

    public function testThcCoohRegelkonsumGiltErstUeberDemWert(): void
    {
        $g = (float)self::cfg()['thc']['cooh_regelkonsum'];
        $basis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis'], 'thc_wert' => 2.0];
        $this->assertStringNotContainsString('D2', $this->triage($basis + ['thc_cooh' => $g])['hypothese_text']);
        $this->assertStringContainsString('D2', $this->triage($basis + ['thc_cooh' => $g + 0.01])['hypothese_text']);
    }

    public function testThcWarntextFolgtDerTrennvermoegenGrenze(): void
    {
        $g = (float)self::cfg()['thc']['trennvermoegen_ng_ml'];
        $basis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis']];
        $this->assertStringNotContainsString('im Einzelfall', implode(' ', $this->triage($basis + ['thc_wert' => $g])['warnungen']));
        $this->assertStringContainsString('im Einzelfall', implode(' ', $this->triage($basis + ['thc_wert' => $g + 0.01])['warnungen']));
    }

    public function testPunkteKritischGiltAbDemWert(): void
    {
        $g = (int)self::cfg()['punkte']['kritisch'];
        $this->assertStringContainsString('Stufe der Verwarnung', implode(' ', $this->triage(['delikte' => ['Punkte'], 'punkte' => $g])['warnungen']));
        $this->assertStringNotContainsString('Stufe der Verwarnung', implode(' ', $this->triage(['delikte' => ['Punkte'], 'punkte' => $g - 1])['warnungen']));
    }

    // ── Fristen ────────────────────────────────────────────────────────────────────────

    public function testAntragsAmpelSchaltetAufDenTagGenau(): void
    {
        $vorlauf    = 70;
        $vorwarnung = $vorlauf + (int)behoerden_config()['vorwarnung_tage'];
        $level = fn(int $n): string => get_antrag_ampel($this->tage($n), 'nicht_gestellt', null, $vorlauf)['level'];
        $this->assertSame('danger', $level(-1));
        $this->assertSame('danger', $level(0));
        $this->assertSame('danger', $level($vorlauf));
        $this->assertSame('warning', $level($vorlauf + 1));
        $this->assertSame('warning', $level($vorwarnung));
        $this->assertSame('success', $level($vorwarnung + 1));
        $this->assertStringContainsString('überfällig', get_antrag_ampel($this->tage(-1), 'nicht_gestellt', null, $vorlauf)['label']);
        $this->assertStringNotContainsString('überfällig', get_antrag_ampel($this->tage(0), 'nicht_gestellt', null, $vorlauf)['label']);
    }

    public function testMpuZielmonatSchaltetAufDenTagGenau(): void
    {
        $level = fn(int $n): string => get_antrag_ampel(null, 'nicht_gestellt', $this->tage($n))['level'];
        $this->assertSame('danger', $level(60));
        $this->assertSame('warning', $level(61));
        $this->assertSame('warning', $level(90));
        $this->assertSame('success', $level(91));
    }

    public function testEinspruchsAmpelSchaltetAufDenTagGenau(): void
    {
        // Frist = Zustellung + 14 Tage: Rest n bedeutet Zustellung vor (14 - n) Tagen.
        $level = fn(int $rest): string => get_einspruch_ampel($this->tage($rest - 14), 'offen')['level'];
        $this->assertSame('secondary', $level(-1));
        $this->assertSame('danger', $level(0));
        $this->assertSame('danger', $level(3));
        $this->assertSame('warning', $level(4));
        $this->assertSame('warning', $level(7));
        $this->assertSame('success', $level(8));
    }

    // ── Abstinenzkette ─────────────────────────────────────────────────────────────────

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private function zweiProben(string $methode, int $abstand): array
    {
        $a = new DateTimeImmutable('2024-01-01');
        return [
            ['probe_date' => $a->format('Y-m-d'), 'method' => $methode, 'result' => 'negativ'],
            ['probe_date' => $a->modify("+{$abstand} days")->format('Y-m-d'), 'method' => $methode, 'result' => 'negativ'],
        ];
    }

    public function testHaarLueckeFolgtDerSegmentlaenge(): void
    {
        foreach (['["Alkohol"]', '["Drogen"]'] as $delikt) {
            $grenze = haar_luecke_tage(haar_segment_monate($delikt));
            $this->assertCount(0, get_abstinenz_luecken($this->zweiProben('haar', $grenze), false, $delikt)['gaps'], $delikt);
            $this->assertCount(1, get_abstinenz_luecken($this->zweiProben('haar', $grenze + 1), false, $delikt)['gaps'], $delikt);
        }
    }

    public function testKarenzKollidiertUnter90TagenPuffer(): void
    {
        $proben = [['probe_date' => '2024-01-01', 'result' => 'negativ']];
        $ende   = (new DateTimeImmutable('2024-01-01'))->modify('+6 months');
        $this->assertFalse(get_karenzzeit_info($ende->modify('+90 days')->format('Y-m-d'), $proben, 6)['kollision']);
        $this->assertTrue(get_karenzzeit_info($ende->modify('+89 days')->format('Y-m-d'), $proben, 6)['kollision']);
        $this->assertSame(-1, get_karenzzeit_info($ende->modify('-1 days')->format('Y-m-d'), $proben, 6)['puffer_tage']);
    }

    // ── Leistungstests ─────────────────────────────────────────────────────────────────

    public function testKompensationBrauchtPr50BeiAllenUebrigen(): void
    {
        $t = static fn(int ...$pr): array => array_map(static fn(int $p): array => ['percentile' => $p], $pr);
        $this->assertSame('warning', bkf_leistung_ampel($t(15, 50, 50), false)['level']);
        $this->assertSame('danger', bkf_leistung_ampel($t(15, 49, 50), false)['level']);
        $this->assertSame('success', bkf_leistung_ampel($t(16, 16), false)['level']);
    }

    public function testBkfMehrheitBrauchtMehrAlsDieHaelfte(): void
    {
        $t = static fn(int ...$pr): array => array_map(static fn(int $p): array => ['percentile' => $p], $pr);
        $this->assertSame('warning', bkf_leistung_ampel($t(33, 32), true)['level']);
        $this->assertSame('success', bkf_leistung_ampel($t(33, 33, 32), true)['level']);
        $this->assertSame('success', bkf_leistung_ampel($t(33, 32), false)['level']);
    }

    // ── R4-17: § 24a StVG ──────────────────────────────────────────────────────────────

    /** Das Gesetz sagt "3,5 ng/ml oder mehr": Genau auf dem Grenzwert ist D4 nicht mehr möglich. */
    public function testThcGenauAufDemGrenzwertIstKeinD4(): void
    {
        $g = (float)self::cfg()['thc']['grenzwert_ng_ml'];
        $basis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis']];
        $this->assertStringContainsString('D4', $this->triage($basis + ['thc_wert' => $g - 0.01])['hypothese_text']);
        $auf = $this->triage($basis + ['thc_wert' => $g]);
        $this->assertStringNotContainsString('D4', $auf['hypothese_text']);
        $this->assertStringContainsString('Erreicht oder überschreitet', implode(' ', $auf['warnungen']));
        $this->assertStringNotContainsString('Erreicht', implode(' ', $this->triage($basis + ['thc_wert' => $g - 0.01])['warnungen']));
    }

    public function testBkfTexteNennenDasGesetzRichtig(): void
    {
        $r = $this->triage(['bak' => 1.2, 'klassen' => ['C'], 'bkf_status' => 'aktiv']);
        $text = implode(' ', $r['warnungen']) . ' ' . implode(' ', array_column($r['hypothesen'], 'warn'));
        $this->assertStringContainsString('0,5 ‰ bzw. 0,25 mg/l', $text);
        $this->assertStringContainsString('§ 8 BOKraft', $text);
    }

    /** Ein Fehler dieser Art lag in zwölf Texten gleichzeitig und widersprach nichts im Projekt. */
    public function testKeinTextBehauptetDieFruehereFalscheRechtslage(): void
    {
        $dateien = array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../partials/*.php') ?: [], glob(__DIR__ . '/../config/*.php') ?: []);
        $this->assertNotEmpty($dateien);
        foreach ($dateien as $datei) {
            $inhalt = (string)file_get_contents($datei);
            $this->assertDoesNotMatchRegularExpression('/0,25(\s|&nbsp;)*‰/u', $inhalt, $datei);
            $this->assertStringNotContainsString('strafbewehrt', $inhalt, $datei);
            $this->assertStringNotContainsString('Nulltoleranz', $inhalt, $datei);
        }
    }

    // ── Weitere Triage-Grenzen ─────────────────────────────────────────────────────────

    public function testGiftfestigkeitsHinweisNurAbGrenzeMitSymptomfeld(): void
    {
        $g = (float)self::cfg()['bak']['giftfestigkeit'];
        $hinweis = fn(float $bak): string => implode(' ', array_column($this->triage(['bak' => $bak, 'symptoms' => true])['hypothesen'], 'warn')) . implode(' ', $this->triage(['bak' => $bak, 'symptoms' => true])['warnungen']);
        $this->assertStringContainsString('Giftfestigkeit', $hinweis($g));
        $this->assertStringNotContainsString('Giftfestigkeit', $hinweis($g - 0.01));
    }

    public function testIcdGiftfestigkeitsHinweisNurAbGrenze(): void
    {
        $g = (float)self::cfg()['bak']['giftfestigkeit'];
        $icd = ['icd_kontrollverlust' => true, 'icd_priorisierung' => true, 'icd_folgeschaeden' => true, 'symptoms' => true];
        $text = fn(float $bak): string => implode(' ', array_column($this->triage($icd + ['bak' => $bak])['hypothesen'], 'warn')) . implode(' ', $this->triage($icd + ['bak' => $bak])['warnungen']);
        $this->assertStringContainsString('Giftfestigkeit', $text($g));
        $this->assertStringNotContainsString('Giftfestigkeit', $text($g - 0.01));
    }

    public function testBakFehltNurUnterEinemHundertstel(): void
    {
        $this->assertStringContainsString('nicht eingetragen', $this->triage(['bak' => 0.0])['hypothese_text']);
        $this->assertStringNotContainsString('nicht eingetragen', $this->triage(['bak' => 0.01])['hypothese_text']);
    }

    public function testBkfCannabisOhneThcWertHatEigenenHinweis(): void
    {
        $basis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis'], 'klassen' => ['C'], 'bkf_status' => 'aktiv'];
        $text = fn(float $thc): string => implode(' ', array_column($this->triage($basis + ['thc_wert' => $thc])['hypothesen'], 'warn'));
        $this->assertStringContainsString('THC-Wert 0.01', $text(0.01));
        $this->assertStringNotContainsString('THC-Wert', $text(0.0));
    }

    public function testMischkonsumHebtNurEchteMonateAn(): void
    {
        $r = $this->triage(['delikte' => ['Alkohol', 'Drogen'], 'drogen' => ['Cannabis'], 'bak' => 1.2, 'thc_wert' => 2.0]);
        $this->assertTrue($r['mischkonsum']);
        $this->assertGreaterThan(0, $r['abstinenz_monate']);
    }

    // ── Weitere Grenzen in logic/ ──────────────────────────────────────────────────────

    public function testFallzuordnungLehntNullUndNegativAbOhneDatenbank(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 0, 1));
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 1, 0));
        $this->assertFalse(fall_gehoert_zu_klient($pdo, -1, 1));
    }

    public function testVorzeichenDerTagesdifferenz(): void
    {
        $this->assertSame(0, get_antrag_ampel($this->tage(0), 'nicht_gestellt')['days_remaining']);
        $this->assertSame(-1, get_antrag_ampel($this->tage(-1), 'nicht_gestellt')['days_remaining']);
        $this->assertSame(1, get_antrag_ampel($this->tage(1), 'nicht_gestellt')['days_remaining']);
        $this->assertSame(0, get_antrag_ampel(null, 'nicht_gestellt', $this->tage(0))['days_remaining']);
        $this->assertSame(-1, get_antrag_ampel(null, 'nicht_gestellt', $this->tage(-1))['days_remaining']);
        $this->assertSame('danger', get_antrag_ampel(null, 'nicht_gestellt', $this->tage(-1))['level']);
        $this->assertStringContainsString('überschritten', get_antrag_ampel(null, 'nicht_gestellt', $this->tage(-1))['label']);
        $this->assertStringNotContainsString('überschritten', get_antrag_ampel(null, 'nicht_gestellt', $this->tage(0))['label']);
        $this->assertSame(0, get_einspruch_ampel($this->tage(-14), 'offen')['days_remaining']);
        $this->assertSame(-1, get_einspruch_ampel($this->tage(-15), 'offen')['days_remaining']);
    }

    public function testHaarAnalysenBeiNullUndGrenzwerten(): void
    {
        $this->assertSame(0, haar_pflicht_analysen(0, 3));
        $this->assertSame(0, haar_pflicht_analysen(6, 0));
        $this->assertSame(1, haar_pflicht_analysen(1, 3));
        $this->assertSame(1, haar_pflicht_analysen(3, 3));
        $this->assertSame(2, haar_pflicht_analysen(4, 3));
    }

    public function testLetzteProbeIstDieJuengste(): void
    {
        $proben = [['probe_date' => '2024-03-01', 'method' => 'urin', 'result' => 'negativ'], ['probe_date' => '2024-01-01', 'method' => 'urin', 'result' => 'negativ'], ['probe_date' => '2024-02-01', 'method' => 'urin', 'result' => 'positiv']];
        $r = get_abstinenz_luecken($proben, false);
        $this->assertSame('2024-03-01', $r['letzte_probe']);
    }

    public function testHaarLaufendeLueckeZaehltBisHeute(): void
    {
        $d = '["Drogen"]';
        $grenze = haar_luecke_tage(haar_segment_monate($d));
        $haar = fn(int $vor): array => [['probe_date' => $this->tage(-$vor), 'method' => 'haar', 'result' => 'negativ']];
        $this->assertCount(0, get_abstinenz_luecken($haar($grenze), true, $d)['gaps']);
        $this->assertCount(1, get_abstinenz_luecken($haar($grenze + 1), true, $d)['gaps']);
    }

    public function testKarenzOhneDatenGibtNull(): void
    {
        $p = [['probe_date' => '2024-01-01', 'result' => 'negativ']];
        $this->assertNull(get_karenzzeit_info('2025-01-01', $p, 0));
        $this->assertNull(get_karenzzeit_info(null, $p, 6));
        $this->assertNull(get_karenzzeit_info('2025-01-01', [], 6));
        $this->assertSame(0, get_karenzzeit_info('2024-07-01', $p, 6)['puffer_tage']);
    }

    public function testLeistungstestGrenzen(): void
    {
        $t = static fn(int ...$pr): array => array_map(static fn(int $p): array => ['percentile' => $p], $pr);
        $this->assertStringContainsString('Ungültiger', bkf_leistung_ampel($t(0), false)['warn']);
        $this->assertStringContainsString('Ungültiger', bkf_leistung_ampel($t(100), false)['warn']);
        $this->assertStringNotContainsString('Ungültiger', bkf_leistung_ampel($t(1), false)['warn']);
        $this->assertStringNotContainsString('Ungültiger', bkf_leistung_ampel($t(99), false)['warn']);
    }

    public function testBegruendungNennenDenAnlassNurUnterDerGrenze(): void
    {
        $g = (float)self::cfg()['bak']['giftfestigkeit'];
        $vor = fn(array $o): string => implode(' ', $this->triage($o)['warnungen']);
        $this->assertStringContainsString('wegen der Vor-MPU', $vor(['bak' => $g - 0.01, 'repeat' => true]));
        $this->assertStringNotContainsString('wegen der Vor-MPU', $vor(['bak' => $g, 'repeat' => true]));
        $this->assertStringContainsString('wegen des Mehrfachdelikts', $vor(['bak' => $g - 0.01, 'mehrfachdelikt' => true]));
        $this->assertStringNotContainsString('wegen des Mehrfachdelikts', $vor(['bak' => $g, 'mehrfachdelikt' => true]));
    }

    public function testChecklisteWarntUnterDerHaelfte(): void
    {
        $akte = ['client_id' => 1, 'suspension_end_date' => null, 'mpu_target_date' => null, 'antrag_status' => 'gestellt', 'offense_category' => '[]'];
        $aufgabe = static fn(string $status): array => ['status' => $status, 'task_key' => 'x'];
        $this->assertSame('success', get_client_status($akte, [], [$aufgabe('offen'), $aufgabe('liegt_vor')])['level']);
        $this->assertSame('warning', get_client_status($akte, [], [$aufgabe('offen'), $aufgabe('offen'), $aufgabe('liegt_vor')])['level']);
        $this->assertSame('warning', get_client_status($akte, [], [$aufgabe('offen')])['level']);
        $this->assertSame('success', get_client_status($akte, [], [])['level']);
    }

    public function testScreeningAmpelGrenzen(): void
    {
        $alt = [['probe_date' => '2020-01-01']];
        $this->assertSame('secondary', screening_ampel(0, 0, [], 6, '2024-01-01'));
        $this->assertSame('success', screening_ampel(4, 4, $alt, 6, '2020-01-01'));
        $this->assertSame('danger', screening_ampel(3, 4, $alt, 6, '2020-01-01'));
        // Programm endet genau heute: abgelaufen, Soll nicht erfüllt.
        $start = (new DateTimeImmutable('today'))->modify('-6 months')->format('Y-m-d');
        if ((new DateTimeImmutable($start))->modify('+6 months')->format('Y-m-d') === (new DateTimeImmutable('today'))->format('Y-m-d')) {
            $this->assertSame('danger', screening_ampel(4, 5, [['probe_date' => $start]], 6, $start));
        }
        // Programm beginnt heute: Noch nichts erwartet, also Vorwarnung.
        $heute = $this->tage(0);
        $this->assertSame('warning', screening_ampel(0, 4, [['probe_date' => $heute]], 6, $heute));
    }

    // ── Eingabeprüfung ─────────────────────────────────────────────────────────────────

    public function testDatumMussEsGeben(): void
    {
        $this->assertTrue(validate_datum('2024-02-29'));
        $this->assertFalse(validate_datum('2025-02-29'));
        $this->assertFalse(validate_datum('2026-13-01'));
        $this->assertFalse(validate_datum('2026-01-32'));
        $this->assertFalse(validate_datum('0000-01-01'));
        $this->assertFalse(validate_datum('2026-1-1'));
        $this->assertFalse(validate_datum("2026-01-01\n"));
        $this->assertFalse(validate_probe_date('2024-02-31'));
    }

    public function testGemeinsameFelderPruefenGrenzen(): void
    {
        $this->assertSame([], validiere_gemeinsame_felder([
            'geschlecht' => 'd', 'koerpergewicht' => '300', 'tetrahydrocannabinol_wert' => '999.99', 'thc_cooh_value' => '0', 'honorar_gesamt' => '999999.99',
            'vorname' => str_repeat('ä', 100), 'mpu_zielmonat' => '2026-12', 'geburtsdatum' => '1985-03-15',
        ]));
        foreach ([['geschlecht', 'x'], ['koerpergewicht', '300.01'], ['koerpergewicht', '0.5'], ['koerpergewicht', 'NAN'], ['tetrahydrocannabinol_wert', '1000'], ['honorar_gesamt', '-1'], ['honorar_gesamt', '1e30'],
                  ['vorname', str_repeat('ä', 101)], ['mpu_zielmonat', '2026-13'], ['geburtsdatum', '2026-02-31'], ['payment_date', 'abc']] as [$feld, $wert]) {
            $this->assertArrayHasKey($feld, validiere_gemeinsame_felder([$feld => $wert]), "{$feld}={$wert}");
        }
    }

    public function testPethUndUrinSollKommenAusDerConfig(): void
    {
        $g = (float)ctu_config()['peth']['abstinenz_unter_ng_ml'];
        $this->assertTrue(peth_auffaellig($g));
        $this->assertFalse(peth_auffaellig($g - 0.1));
        foreach ([[15, 7], [14, 6], [12, 6], [11, 4], [6, 4], [0, 4]] as [$monate, $soll]) {
            $this->assertSame($soll, urin_pflicht_proben($monate), (string)$monate);
        }
    }

    // ── R4-1 und R4-3: am Quelltext gehalten, Handler laufen hier nicht ───────────────

    public function testAufnahmeSchreibtKeinenWiderrufDerEinwilligung(): void
    {
        $quelle = (string)file_get_contents(__DIR__ . '/../handlers/aufnahme_handler.php');
        $this->assertStringContainsString('revocation_notice_confirmed', $quelle);
        $this->assertDoesNotMatchRegularExpression('/\brevocation_confirmed\b/', $quelle);
    }

    /** Das Original lag nicht vor: Seitenangaben und angebliche Wortlaute täuschen eine Prüfung vor. */
    public function testKeineSeitenangabenOderWortlauteAusDenQuellen(): void
    {
        $dateien = array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../partials/*.php') ?: [], glob(__DIR__ . '/../config/*.php') ?: [], glob(__DIR__ . '/../logic/*.php') ?: []);
        $this->assertNotEmpty($dateien);
        foreach ($dateien as $datei) {
            $inhalt = (string)file_get_contents($datei);
            $this->assertDoesNotMatchRegularExpression('/\bS\.(\s|&nbsp;)?\d{2,3}/u', $inhalt, $datei);
            $this->assertDoesNotMatchRegularExpression('/Wortlaut|laut Quelle|aus der Quelle zitiert|Grenze der Verwertbarkeit/u', $inhalt, $datei);
        }
    }

    /** Texte unklarer Herkunft dürfen nicht zurückkehren, die Seiten sind nur noch Gerüste. */
    public function testDruckseitenEnthaltenKeineRechtstexte(): void
    {
        foreach (['vertrag_druck.php', 'datenschutz_druck.php', 'widerruf_druck.php', 'strategie_druck.php'] as $seite) {
            $inhalt = (string)file_get_contents(__DIR__ . '/../' . $seite);
            $this->assertStringContainsString('class="platzhalter"', $inhalt, $seite);
            foreach (['Allgemeine Geschäftsbedingungen', 'Gerichtsstand', 'binnen vierzehn Tagen', 'Erfolgsgarantie', 'Werkvertragsrecht', 'Ich verpflichte mich'] as $altext) {
                $this->assertStringNotContainsString($altext, $inhalt, $seite);
            }
        }
    }

    /** Art. 15 und 20: Jede Klientenspalte gehört in den Export. audit_log ist Betreiberinterna. */
    public function testExportEnthaeltJedeKlientenspalte(): void
    {
        $schema = (string)file_get_contents(__DIR__ . '/../schema.sql');
        $migr   = '';
        foreach (glob(__DIR__ . '/../migrations/*.sql') ?: [] as $d) { $migr .= file_get_contents($d); }
        $handler = (string)file_get_contents(__DIR__ . '/../handlers/akte_handler.php');
        $export  = substr($handler, (int)strpos($handler, "=== 'dsgvo_export'"));

        preg_match_all('/CREATE TABLE IF NOT EXISTS `?(\w+)`?\s*\((.*?)\n\)/s', $schema, $tabellen, PREG_SET_ORDER);
        $this->assertNotEmpty($tabellen);
        $geprueft = 0;
        foreach ($tabellen as [, $tabelle, $inhalt]) {
            if ($tabelle === 'audit_log' || ($tabelle !== 'clients' && !preg_match('/^\s*client_id\b/m', $inhalt))) {
                continue;
            }
            $this->assertStringContainsString("FROM {$tabelle} ", $export, "Tabelle {$tabelle} fehlt im Export");
            preg_match_all('/^\s*`?(\w+)`?\s+(?:INT|BIGINT|VARCHAR|TEXT|DATE|DATETIME|TIMESTAMP|TINYINT|DECIMAL|ENUM|SMALLINT|MEDIUMTEXT|LONGTEXT|CHAR|FLOAT)\b/mi', $inhalt, $spalten);
            foreach ($spalten[1] as $spalte) {
                if (in_array($spalte, ['id', 'client_id', 'case_id', 'version'], true)) { continue; }
                $this->assertMatchesRegularExpression('/\b' . $spalte . '\b/', $export, "{$tabelle}.{$spalte} fehlt im Export");
                $geprueft++;
            }
        }
        $this->assertGreaterThan(50, $geprueft);
    }

    // ── Cross-Check: Aussagen, die falsch waren und nicht zurückkommen dürfen ─────────

    /** @return array<string,string> Datei => Text */
    private function anzeigetexte(): array
    {
        $texte = [];
        foreach (['anleitung.php', 'klienten_leitfaden.php', 'aufnahme.php', 'bearbeiten.php', 'akte.php', 'triage.php', 'ressourcen.php'] as $datei) {
            $texte[$datei] = (string)file_get_contents(__DIR__ . '/../' . $datei);
        }
        return $texte;
    }

    public function testFalscheAussagenKommenNichtZurueck(): void
    {
        foreach ($this->anzeigetexte() as $datei => $text) {
            foreach ([
                'gesetzliche Vermutung', 'gesetzl. Regelkonsum', 'THC-COOH Urin', 'Urin-Metabolit',              // CC-16
                'entfällt unmittelbar', 'Missbrauchstatbestand', 'Fahrungeeignetheit ohne MPU',                   // CC-22
                'E-Scooter-Delikten', 'Trennvermögen wird unterstellt', 'regelmäßiger Konsum belegt', 'Regelmäßiger Konsum belegt',                           // CC-25, CC-27
                'neu starten', 'Abstinenz ungültig', 'gesamte Abstinenz', 'Höchstens <strong>14 Tage',            // CC-15
                'unterhalb</em> des laborseitigen', 'Dipl.-Psych.', 'M.Sc. Psych.',                               // CC-29, CC-2
                'PEth/THC-Monitoring', '&lt; 8 ng/ml', '< 8 ng/ml', '&lt; 7 pg/mg', '&lt; 15 ng/ml',              // CC-26
            ] as $aussage) {
                $this->assertStringNotContainsString($aussage, $text, "{$datei}: {$aussage}");
            }
        }
    }

    /** CC-23: Das Kürzel und die Bedeutung folgen der Übersicht, M3 vergibt nur der Override. */
    public function testHypothesenMFolgenDerSystematik(): void
    {
        $this->assertContains('Hypothese M3 (Arzneimittelabhängigkeit)', ERLAUBTE_HYPOTHESEN);
        foreach ([['Methadon'], ['Medizinalcannabis'], ['Benzodiazepine'], ['Medikamente']] as $drogen) {
            $this->assertStringNotContainsString('Hypothese M3', $this->triage(['delikte' => ['Drogen'], 'drogen' => $drogen])['hypothese_text']);
        }
        $this->assertStringNotContainsString('D3', $this->triage(['delikte' => ['Drogen'], 'drogen' => ['Medikamente']])['hypothese_text']);
    }

    /** CC-20 und CC-18 */
    public function testMonateNurAusDemAnfangDesTextes(): void
    {
        $this->assertSame(6, abstinenz_monate_aus_text('6 Monate'));
        $this->assertSame(6, abstinenz_monate_aus_text('Vorläufig 6 Monate – bitte nachtragen'));
        $this->assertSame(0, abstinenz_monate_aus_text('Kein Abstinenzgebot – 12 Monate nachgewiesener Veränderungsprozess'));
        $this->assertSame(0, abstinenz_monate_aus_text('Keine (Einstellungscheck)'));
    }

    /** CC-18: Strategiezeile gegen die Monate der Engine, je Konstellation. */
    public function testStrategiezeilePasstZurEngine(): void
    {
        $fall = function (array $p, string $bkf = 'nein'): string {
            $r = $this->triage($p + ['bkf_status' => $bkf, 'klassen' => ['C']]);
            return strategie_zeile($r['hypothese_text'], $r['abstinenz_monate'], $bkf);
        };
        $cannabis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis']];
        foreach ([
            ['Kein Abstinenzgebot', ['delikte' => ['Punkte']]],
            ['Kein Abstinenzgebot', $cannabis + ['thc_wert' => 2.0]],
            ['Kein Abstinenzgebot', ['delikte' => ['Drogen'], 'drogen' => ['Medikamente']]],
            ['kontrollierter Konsum', ['delikte' => ['Alkohol'], 'bak' => 0.8]],
            ['kontrollierter Konsum', ['delikte' => ['Alkohol'], 'bak' => 1.3]],
            ['(zwingend)', ['delikte' => ['Alkohol'], 'bak' => 2.6]],
            ['(zwingend)', $cannabis + ['thc_cooh' => 200.0]],
            ['(dringend empfohlen)', ['delikte' => ['Alkohol'], 'bak' => 2.1]],
        ] as [$erwartet, $p]) {
            $this->assertStringContainsString($erwartet, $fall($p), json_encode($p));
        }
        $this->assertStringContainsString('kein kontrollierter Konsum', $fall(['delikte' => ['Alkohol'], 'bak' => 0.8], 'aktiv'));
    }

    /** CC-17 und CC-27 */
    public function testCannabisOhnePassendeWerteIstNichtGuenstig(): void
    {
        $cannabis = ['delikte' => ['Drogen'], 'drogen' => ['Cannabis']];
        $ohne = $this->triage($cannabis);
        $this->assertStringContainsString('nachtragen', $ohne['hypothese_text']);
        $this->assertSame((int)self::cfg()['D3_cannabis']['monate'], $ohne['abstinenz_monate']);
        $hoch = $this->triage($cannabis + ['thc_wert' => 30.0]);
        $this->assertStringNotContainsString('kontrollierter Konsum', $hoch['hypothese_text'] . $hoch['abstinenz_primaer']);
        $this->assertSame((int)self::cfg()['D3_cannabis']['monate'], $hoch['abstinenz_monate']);
        $wert = $this->triage($cannabis + ['thc_cooh' => 50.0]);
        $this->assertSame(0, $wert['abstinenz_monate']);
        $this->assertStringContainsString((string)self::cfg()['D3_cannabis']['monate'] . ' Monate', $wert['abstinenz_primaer']);
    }

    /** CC-47 */
    public function testZahlungWirdAufgeteilt(): void
    {
        $this->assertSame(['eingang' => 100.0, 'offen' => 900.0], zahlung_aufteilen(1000.0, 'teilweise', 100.0));
        $this->assertSame(['eingang' => 0.0, 'offen' => 1000.0], zahlung_aufteilen(1000.0, 'teilweise', null));
        $this->assertSame(['eingang' => 1000.0, 'offen' => 0.0], zahlung_aufteilen(1000.0, 'teilweise', 1200.0));
        $this->assertSame(['eingang' => 1000.0, 'offen' => 0.0], zahlung_aufteilen(1000.0, 'beglichen', null));
        $this->assertSame(['eingang' => 0.0, 'offen' => 0.0], zahlung_aufteilen(1000.0, 'beglichen', 0.0));
        $this->assertSame(['eingang' => 0.0, 'offen' => 1000.0], zahlung_aufteilen(1000.0, 'offen', 50.0));
    }

    /** CC-52: Die CSP sperrt Inline-Handler, ein solcher Knopf tut nichts. */
    public function testKeineInlineHandler(): void
    {
        foreach (array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../partials/*.php') ?: []) as $datei) {
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=\s*["\']/i', (string)file_get_contents($datei), basename($datei));
        }
    }

    /** CC-36: Über alle Delikte und Therapieangaben. Zwei Kriterien dürfen nie weniger ergeben als keins. */
    public function testIcdNachtragSenktDieMonateNie(): void
    {
        foreach ([['Alkohol'], ['Drogen'], ['Alkohol', 'Drogen']] as $delikte) {
            foreach ([['Cannabis'], ['Kokain'], ['Amphetamine'], ['Cannabis', 'Kokain']] as $drogen) {
                foreach ([0.0, 1.3, 2.1, 2.6] as $bak) {
                    foreach ([0.0, 100.0, 200.0] as $cooh) {
                        foreach ([false, true] as $therapie) {
                            foreach ([false, true] as $erschwerend) {
                                $p = compact('delikte', 'drogen', 'bak') + ['thc_cooh' => $cooh, 'therapy' => $therapie, 'erschwerend' => $erschwerend];
                                $ohne = $this->triage($p)['abstinenz_monate'];
                                foreach ([['icd_craving' => true], ['icd_craving' => true, 'icd_toleranz' => true], ['icd_craving' => true, 'icd_toleranz' => true, 'icd_entzug' => true]] as $icd) {
                                    $this->assertGreaterThanOrEqual($ohne, $this->triage($p + $icd)['abstinenz_monate'], json_encode($p + $icd));
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /** CC-31: Eine Schwelle, auch über der oberen BAK-Stufe. */
    public function testGiftfestigkeitGiltAbDerSchwelleDurchgehend(): void
    {
        $g = (float)self::cfg()['bak']['giftfestigkeit'];
        $text = fn(float $bak): string => implode(' ', $this->triage(['delikte' => ['Alkohol'], 'bak' => $bak, 'symptoms' => true])['warnungen']);
        $this->assertStringNotContainsString('Giftfestigkeit', $text($g - 0.01));
        $this->assertStringContainsString('Giftfestigkeit', $text($g));
        $this->assertStringContainsString('Giftfestigkeit', $text((float)self::cfg()['bak']['a2_hoch'] + 0.5));
    }

    /** CC-38 */
    public function testAktiverBkfOhneKlasseWirdGemeldet(): void
    {
        $text = fn(array $klassen): string => implode(' ', $this->triage(['delikte' => ['Drogen'], 'drogen' => ['Cannabis'], 'thc_wert' => 2.0, 'bkf_status' => 'aktiv', 'klassen' => $klassen])['warnungen']);
        $this->assertStringContainsString('ohne Klasse C oder D', $text(['B']));
        $this->assertStringNotContainsString('ohne Klasse C oder D', $text(['C']));
    }

    /** Aussagen aus CC-10, CC-14, CC-30, CC-32, CC-35, die falsch waren. */
    public function testWeitereFalscheAussagenKommenNichtZurueck(): void
    {
        $dateien = array_merge(glob(__DIR__ . '/../*.php') ?: [], glob(__DIR__ . '/../scripts/*.php') ?: [], glob(__DIR__ . '/../config/*.php') ?: []);
        $this->assertNotEmpty($dateien);
        foreach ($dateien as $datei) {
            if (basename($datei) === 'users.php') { continue; }
            $text = (string)file_get_contents($datei);
            foreach (['630f', 'Kopie der Daten aufzubewahren', 'direkt in der Datenbank löschen', 'revisionssicher', 'Revisionssicherheit', 'HTTP-Authentifizierung',
                      'Entziehungsgrenze', 'CanG-Grenzwert', 'sofort und dauerhaft', 'die Fahrerlaubnis ruht', 'Anlage-5-FeV', '§ 20 Abs. 3', 'Führerscheinentzug war zwingend',
                      'Abhängigkeit wahrscheinlich', 'Diagnostische System', 'getDate() - 70', 'rechtskraft || sperrfrist', 'fachlich ausgeschlossen'] as $aussage) {
                $this->assertStringNotContainsString($aussage, $text, basename($datei) . ': ' . $aussage);
            }
        }
    }

    /** CC-39: Akte und Handbuch vergeben K und C gleich. */
    public function testSorkcIstUeberallGleich(): void
    {
        $handbuch = (string)file_get_contents(__DIR__ . '/../anleitung.php');
        $this->assertMatchesRegularExpression('#<strong>K</strong></td>\s*<td>Kontingenz#', $handbuch);
        $this->assertMatchesRegularExpression('#<strong>C</strong></td>\s*<td>Konsequenz#', $handbuch);
        $this->assertStringContainsString('K — Kontingenz', (string)file_get_contents(__DIR__ . '/../akte.php'));
    }

    /** CC-51 */
    public function testSitzungHaeltBisZurFristUndWarntVorher(): void
    {
        $this->assertStringContainsString("ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_TIMEOUT", (string)file_get_contents(__DIR__ . '/../logic/request.php'));
        $this->assertGreaterThanOrEqual(1800, SESSION_IDLE_TIMEOUT);
        $this->assertStringContainsString('sitzung-verlaengern', (string)file_get_contents(__DIR__ . '/../partials/_footer.php'));
    }

    /** CC-5: Adresse hinter einem Proxy. Was der Client selbst schreibt, zählt nicht. */
    public function testClientAdresseFolgtNurVertrautenProxys(): void
    {
        $alt = $_SERVER;
        try {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
            $this->assertSame('203.0.113.9', client_adresse());
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $this->assertSame('198.51.100.1', client_adresse());
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.9.9, 198.51.100.1';
            $this->assertSame('198.51.100.1', client_adresse());
            $_SERVER['HTTP_X_FORWARDED_FOR'] = 'unsinn';
            $this->assertSame('127.0.0.1', client_adresse());
        } finally {
            $_SERVER = $alt;
        }
    }

    /** CC-48: Sonderzeichen überstehen den Weg in die .htaccess. */
    public function testZugangsdatenLandenSicherInDerHtaccess(): void
    {
        $datei = tempnam(sys_get_temp_dir(), 'hta');
        file_put_contents($datei, "# SetEnv DB_USER    x\n# SetEnv DB_PASS    x\n# SetEnv DB_HOST    x\n# SetEnv DB_NAME    x\n# SetEnv APP_FORCE_HTTPS 1\n");
        $rohre = [];
        $ablauf = proc_open(['python3', '-I', __DIR__ . '/../scripts/env_in_htaccess.py', $datei], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, null,
            ['DB_USER' => 'u', 'DB_PASS' => 'a&b c/d"e\\f#g', 'DB_HOST' => 'h', 'DB_NAME' => 'n', 'PATH' => (string)getenv('PATH')]);
        stream_get_contents($rohre[1]);
        $this->assertSame(0, proc_close($ablauf));
        $text = (string)file_get_contents($datei);
        unlink($datei);
        $this->assertStringContainsString('SetEnv DB_PASS "a&b c/d\\"e\\\\f#g"', $text);
        $this->assertStringContainsString("\nSetEnv APP_FORCE_HTTPS 1", $text);
    }

    /** CC-49: Grenzen der Alkohol-Stufen, die Label unterscheiden sich an der Schwelle. */
    public function testA3BeginntAnDerSchwelle(): void
    {
        $g = (float)self::cfg()['bak']['a3_grenze'];
        $this->assertStringContainsString('A4', $this->triage(['delikte' => ['Alkohol'], 'bak' => $g - 0.01])['hypothese_text']);
        $this->assertStringContainsString('A3', $this->triage(['delikte' => ['Alkohol'], 'bak' => $g])['hypothese_text']);
    }

    /** CC-49: Mischkonsum nennt das Maximum, ohne Monate keinen Satz dazu. */
    public function testMischkonsumNenntDasMaximum(): void
    {
        $r = $this->triage(['delikte' => ['Alkohol', 'Drogen'], 'drogen' => ['Kokain'], 'bak' => 0.8]);
        $this->assertSame($r['abstinenz_monate'] . ' Monate (Mischkonsum – Maximum aller Deliktarten)', $r['abstinenz_primaer']);
        $ohne = $this->triage(['delikte' => ['Punkte'], 'punkte' => 3]);
        $this->assertStringNotContainsString('Mischkonsum', $ohne['abstinenz_primaer']);
    }

    /** CC-49 */
    public function testExportPruefungErkenntFehlendeTeile(): void
    {
        $teile = array_fill_keys(['stammdaten', 'fallakten', 'abstinenz_nachweise', 'sitzungen', 'aufgaben', 'korrespondenz', 'leistungstests', 'notizen'], []);
        $this->assertTrue(validate_dsgvo_export($teile));
        foreach (array_keys($teile) as $teil) {
            $ohne = $teile;
            unset($ohne[$teil]);
            $this->assertFalse(validate_dsgvo_export($ohne), $teil);
        }
    }
}
