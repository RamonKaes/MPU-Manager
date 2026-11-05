<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für berechne_triage() in triage.php
 *
 * Ausführen: composer test
 *   oder:    vendor/bin/phpunit --testdox tests/TriageTest.php
 */
class TriageTest extends TestCase
{
    // ── E-5: THC-COOH ist ohne Drogen-Delikt wirkungslos ──────────────────────
    // Begründet, warum beide Handler den Wert an das Delikt koppeln dürfen:
    // ein ungegateter Wert wäre kein "zusätzlicher Befund", sondern toter
    // Ballast. Schlägt dieser Test fehl, ist die Kopplung in
    // handlers/bearbeiten_handler.php und aufnahme_handler.php neu zu bewerten.

    public function testThcCoohOhneDrogendeliktAendertNichts(): void
    {
        $mit_wert  = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.8, 'thc_cooh' => 195.0]);
        $ohne_wert = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.8, 'thc_cooh' => 0.0]);
        $this->assertEquals($ohne_wert, $mit_wert);
    }

    public function testThcCoohMitDrogendeliktWirktSehrWohl(): void
    {
        $ergebnis = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_cooh' => 195.0,
        ]);
        $this->assertStringContainsString('D2', $ergebnis['hypothese_text']);
    }

    // ── Hilfsmethode: minimale Triage-Parameter ───────────────────────────────

    /** @param array<string,mixed> $overrides */
    private function triage(array $overrides = []): array
    {
        $defaults = [
            'delikte'            => [],
            'drogen'             => [],
            'bak'                => 0.0,
            'therapy'            => false,
            'therapie_art'       => 'keine',
            'erschwerend'        => false,
            'repeat'             => false,
            'mehrfachdelikt'     => false,
            'symptoms'           => false,
            'punkte'             => 0,
            'aggression'         => false,
            'thc_wert'           => 0.0,
            'thc_cooh'           => 0.0,
            'klassen'            => [],
            'bkf_status'         => 'nein',
            'icd_kontrollverlust'=> false,
            'icd_priorisierung'  => false,
            'icd_folgeschaeden'  => false,
            'icd_craving'        => false,
            'icd_toleranz'       => false,
            'icd_entzug'         => false,
            'persoenlichkeit'    => false,
            'medizinal_beikonsum'=> false,
        ];
        $p = array_merge($defaults, $overrides);
        return berechne_triage(
            delikte:            $p['delikte'],
            drogen:             $p['drogen'],
            bak:                $p['bak'],
            therapy:            $p['therapy'],
            therapie_art:       $p['therapie_art'],
            erschwerend:        $p['erschwerend'],
            repeat:             $p['repeat'],
            mehrfachdelikt:     $p['mehrfachdelikt'],
            symptoms:           $p['symptoms'],
            punkte:             $p['punkte'],
            aggression:         $p['aggression'],
            thc_wert:           $p['thc_wert'],
            thc_cooh:           $p['thc_cooh'],
            klassen:            $p['klassen'],
            bkf_status:         $p['bkf_status'],
            icd_kontrollverlust:$p['icd_kontrollverlust'],
            icd_priorisierung:  $p['icd_priorisierung'],
            icd_folgeschaeden:  $p['icd_folgeschaeden'],
            icd_craving:        $p['icd_craving'],
            icd_toleranz:       $p['icd_toleranz'],
            icd_entzug:         $p['icd_entzug'],
            persoenlichkeit:    $p['persoenlichkeit'],
            medizinal_beikonsum:$p['medizinal_beikonsum'],
        );
    }

    // ── Leere Eingabe ─────────────────────────────────────────────────────────

    public function testLeereEingabeGibtLeeresTriage(): void
    {
        $result = $this->triage();
        $this->assertSame('', $result['hypothese_text']);
        $this->assertSame('', $result['abstinenz_primaer']);
        $this->assertEmpty($result['hypothesen']);
        // B-8: Leer-Ergebnis muss denselben Schlüsselsatz wie das Normal-Ergebnis
        // haben – die Handler lesen (int)$result['abstinenz_monate'].
        $this->assertArrayHasKey('abstinenz_monate', $result);
        $this->assertSame(0, $result['abstinenz_monate']);
    }

    // ── B-9: Reale Handler-Aufrufsignatur (ohne therapie_art/erschwerend) ────────
    // aufnahme_handler.php und bearbeiten_handler.php rufen berechne_triage() OHNE
    // die optionalen Parameter therapie_art/erschwerend auf. Dieser Smoke-Test
    // verwendet exakt diese Argumentliste und sichert gegen ein erneutes
    // Signatur-Drift ab (Regression zu B-6: ArgumentCountError → HTTP 500).

    public function testHandlerAufrufsignaturOhneOptionaleParameter(): void
    {
        $result = berechne_triage(
            delikte:             ['Alkohol'],
            drogen:              [],
            bak:                 1.2,
            therapy:             false,
            repeat:              false,
            mehrfachdelikt:      false,
            symptoms:            false,
            punkte:              0,
            aggression:          false,
            thc_wert:            0.0,
            thc_cooh:            0.0,
            klassen:             [],
            bkf_status:          'nein',
            icd_kontrollverlust: false,
            icd_priorisierung:   false,
            icd_folgeschaeden:   false,
            icd_craving:         false,
            icd_toleranz:        false,
            icd_entzug:          false,
            persoenlichkeit:     false,
        );
        $this->assertIsArray($result);
        $this->assertArrayHasKey('hypothese_text', $result);
        $this->assertArrayHasKey('abstinenz_monate', $result);
    }

    // ── Alkohol A4 (BAK < 1,6 ‰, keine erschwerenden Umstände) ───────────────
    // BK-5 A4: 6 Monate Nachweis stabiler Verhaltensänderung (kein Abstinenzgebot).
    // Das Audit-Finding 2026-04-13 hatte fälschlicherweise 10 Monate behauptet
    // und den Wert auf 10 geändert – das wurde 2026-04-14 korrigiert.

    public function testAlkoholA4BeiNiedrigerBAK(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 0.8]);
        $this->assertStringContainsString('A4', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ── Alkohol A1 (ICD-Score ≥ 3, ohne Therapie → 15 Monate) ────────────────
    // BK-5: einheitlich 15 Monate für Selbstheiler.
    // Vorgängerauflage). Korrigiert 2026-04-14 (vorher: 60 Monate).

    public function testAlkoholA1OhneTherapie(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol'],
            'bak'                => 1.8,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => false,
        ]);
        $this->assertStringContainsString('A1', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── Alkohol A1 mit Therapie → 15 Monate ──────────────────────────────────
    // BK-5: Gesamtzeitraum 15 Monate, davon mind. 6 nach Therapieabschluss.
    // Korrigiert 2026-04-14 (vorher: 18 Monate).

    public function testAlkoholA1MitTherapie(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol'],
            'bak'                => 1.8,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => true,
        ]);
        $this->assertStringContainsString('A1', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── Drogen D1 ohne Therapie → 15 Monate (BK-5) ────────────────

    public function testDrogenD1OhneTherapie(): void
    {
        $result = $this->triage([
            'delikte'            => ['Drogen'],
            'drogen'             => ['Cannabis'],
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => false,
        ]);
        $this->assertStringContainsString('D1', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── Mischkonsum: Maximum der Einzeldelikt-Monate dominiert ───────────────

    public function testMischkonsumNimmtMaximum(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol', 'Drogen'],
            'drogen'             => ['Cannabis'],
            'bak'                => 1.8,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => true,
        ]);
        $this->assertTrue($result['mischkonsum']);
        // A1 mit Therapie = 15 Mon., D1 Cannabis mit Therapie = 15 Mon. → Maximum 15
        $this->assertGreaterThanOrEqual(15, $result['abstinenz_monate']);
    }

    // ── Punkte-Delikt erzeugt eine Hypothese ─────────────────────────────────

    public function testPunkteDelikteErzeugtHypothese(): void
    {
        $result = $this->triage(['delikte' => ['Punkte'], 'punkte' => 8]);
        $this->assertNotEmpty($result['hypothesen']);
        $this->assertStringContainsString('V', $result['hypothese_text']);
    }

    // ── Rückgabestruktur vollständig ─────────────────────────────────────────

    public function testRueckgabeStrukturVollstaendig(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.0]);
        $this->assertArrayHasKey('hypothese_text',    $result);
        $this->assertArrayHasKey('abstinenz_primaer', $result);
        $this->assertArrayHasKey('abstinenz_monate',  $result);
        $this->assertArrayHasKey('hypothesen',        $result);
        $this->assertArrayHasKey('warnungen',         $result);
        $this->assertArrayHasKey('kt_hinweise',       $result);
        $this->assertArrayHasKey('max_karenz',        $result);
        $this->assertArrayHasKey('mischkonsum',       $result);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BAK-Grenzwert
    // ═══════════════════════════════════════════════════════════════════════════

    // ── A3-Label: BAK 1,3 ‰ → A3 (Gefährdung), nicht A4 ─────────────────────
    // BK-5 A3: BAK 1,1–1,6 ‰ bei Ersttätern → Hypothese A3, 6 Monate.

    public function testAlkoholA3LabelBeiBAK13(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.3]);
        $this->assertStringContainsString('A3', $result['hypothese_text']);
        $this->assertStringNotContainsString('A4', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ── A4-Label: BAK 0,8 ‰ → A4 (Missbrauch / Trennungsfehler) ─────────────
    // BK-5 A4: BAK < 1,1 ‰ → Hypothese A4, 6 Monate.

    public function testAlkoholA4LabelBeiBAK08(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 0.8]);
        $this->assertStringContainsString('A4', $result['hypothese_text']);
        $this->assertStringNotContainsString('A3', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ── BAK exakt 1,6 ‰ → A3 (BK-5: Schwelle für A2 auf 2,0 ‰ angehoben) ────
    // BK-5 A2.2N (4): Toleranzindikator von 1,6 auf 2,0 ‰ heraufgesetzt.
    // BK-5 A3.1K (8): 1,6 ‰ ist jetzt ein A3-Indikator (ausgeprägte Toleranz).
    // 1,6 ‰ fällt daher in den A3/A4-Zweig → A3, 6 Monate.

    public function testBAKGrenzwert16IstJetztA3(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.6]);
        $this->assertStringContainsString('A3', $result['hypothese_text']);
        $this->assertStringNotContainsString('A2', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ── BAK 1,6 ‰ → A3 mit Warnung "ausgeprägte Toleranz" (BK-5 A3.1K Ind. 8) ─

    public function testBAK16HatAusgepraegteToleranzWarnung(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.6]);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('ausgeprägte Toleranz', $warntext);
    }

    // ── BAK exakt 2,0 ‰ → A2/A3 Gefährdung (neue untere A2-Schwelle) ─────────
    // BK-5 A2.2N (4): ab 2,0 ‰ beginnt der A2-Toleranzindikator. Mit Therapie = 12 Monate.

    public function testBAKGrenzwert20IstA2(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 2.0, 'therapy' => true]);
        $this->assertStringContainsString('A2', $result['hypothese_text']);
        $this->assertSame(12, $result['abstinenz_monate']);
    }

    // ── BAK 1,99 ‰ → noch A3 (knapp unter der neuen 2,0-‰-Schwelle) ──────────

    public function testBAK199IstNochA3(): void
    {
        $result = $this->triage(['delikte' => ['Alkohol'], 'bak' => 1.99]);
        $this->assertStringContainsString('A3', $result['hypothese_text']);
        $this->assertStringNotContainsString('A2', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Mischkonsum (M1–M3)
    // ═══════════════════════════════════════════════════════════════════════════

    // ── Mischkonsum: Cannabis-D2 dominiert über A4 ───────────────────────────
    // A4 (BAK 0,8 ‰) = 6 Monate; D2 Cannabis (THC-COOH > 150 ng/ml) = 15 Monate (erschwerend:
    // Mischkonsum mit Alkohol wird auto-erkannt – BK-5 D 2.x-N) → Maximum 15.

    public function testMischkonsumCannabisdominiertUeberA4(): void
    {
        $result = $this->triage([
            'delikte'  => ['Alkohol', 'Drogen'],
            'drogen'   => ['Cannabis'],
            'bak'      => 0.8,
            'thc_cooh' => 200.0,
        ]);
        $this->assertTrue($result['mischkonsum']);
        $this->assertSame(15, $result['abstinenz_monate']);
        $this->assertStringContainsString('Mischkonsum', $result['abstinenz_primaer']);
    }

    // ── Mischkonsum: D1-Cannabis ohne Therapie (15 Mon.) = A1 ohne Therapie (15 Mon.) → Max 15.
    // Korrigiert 2026-04-14: D1 Selbstheiler war 60 Monate (nun 15 per BK-5).

    public function testMischkonsumD1SelbstheilerDominiertUeberA1(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol', 'Drogen'],
            'drogen'             => ['Cannabis'],
            'bak'                => 1.8,
            'therapy'            => false,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
        ]);
        $this->assertTrue($result['mischkonsum']);
        $this->assertSame(15, $result['abstinenz_monate']);
        $warnungstext = implode(' ', $result['warnungen']);
        $this->assertStringContainsString('Mischkonsum erkannt', $warnungstext);
    }

    // ── Mischkonsum: D1 mit Therapie (15 Mon.) gleich A1 mit Therapie (15 Mon.) → Max 15.
    // Korrigiert 2026-04-14: D1 Amphetamine mit Therapie war 36 Monate (nicht in BK-5 belegt).

    public function testMischkonsumD1HarteDrogenDominiertUeberA1Therapie(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol', 'Drogen'],
            'drogen'             => ['Amphetamine'],
            'bak'                => 1.8,
            'therapy'            => true,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
        ]);
        $this->assertTrue($result['mischkonsum']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Verkehrsdelikte (V1–V3)
    // ═══════════════════════════════════════════════════════════════════════════

    // ── V2/V3: Schwerer Mehrfachtäter (≥ 8 Punkte) → spezifische Warnung ─────

    public function testV2V3SchwerMehrfachtaeterWarnungAb8Punkten(): void
    {
        $result = $this->triage(['delikte' => ['Punkte'], 'punkte' => 8]);
        $this->assertStringContainsString('V', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']); // keine chemischen Belege
        $warnungstext = implode(' ', $result['warnungen']);
        $this->assertStringContainsString('ab 8 Punkten zu entziehen', $warnungstext);
    }

    // ── V3: Aggression → Label "Straftaten im Straßenverkehr" ────────────────

    public function testV3AggressionLabel(): void
    {
        $result = $this->triage([
            'delikte'    => ['Punkte'],
            'punkte'     => 5,
            'aggression' => true,
        ]);
        $this->assertStringContainsString('V3', $result['hypothese_text']);
        $this->assertStringContainsString('Straftaten im Straßenverkehr', $result['hypothese_text']);
        $warnungstext = implode(' ', $result['warnungen']);
        $this->assertStringContainsString('Aggressionspotenzial', $warnungstext);
    }

    // ── V1: Persönlichkeitsproblematik → Label V1, karenz 12 Monate ──────────
    // BK-5 Kap. B 3.1: kein Abstinenzgebot, 12 Monate Veränderungsprozess.

    public function testV1PersoenlichkeitsproblematikLabel(): void
    {
        $result = $this->triage(['persoenlichkeit' => true]);
        $this->assertStringContainsString('V1', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']);
        $this->assertSame(12, $result['max_karenz']);
    }

    // ── V2/V3: Wiederholungstäter → globale Warnung in warnungen ─────────────

    public function testV2V3WiederholungstaeterGlobaleWarnung(): void
    {
        $result = $this->triage([
            'delikte' => ['Punkte'],
            'punkte'  => 6,
            'repeat'  => true,
        ]);
        $this->assertStringContainsString('V', $result['hypothese_text']);
        $warnungstext = implode(' ', $result['warnungen']);
        $this->assertStringContainsString('Wiederholungstäter', $warnungstext);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // BKF-Sonderfälle
    // ═══════════════════════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════════════════════
    // D2/D3 Cannabis – THC-COOH-Routing (T5, 2026-04-14)
    // ═══════════════════════════════════════════════════════════════════════════

    // ── THC-COOH > 150 ng/ml → D2 (Regelkonsum), erschwerend: false → 12 Monate ──
    // BK-5 Kap. B 3, D 2.x-N: kein erschwerender Faktor → Standard 12 Monate.

    public function testCannabisD2BeiHohemCOOH(): void
    {
        $result = $this->triage([
            'delikte'    => ['Drogen'],
            'drogen'     => ['Cannabis'],
            'thc_cooh'   => 200.0,
            'erschwerend'=> false,
        ]);
        $this->assertStringContainsString('D2', $result['hypothese_text']);
        $this->assertStringNotContainsString('D3', $result['hypothese_text']);
        $this->assertSame(12, $result['abstinenz_monate']);
    }

    // ── D2 Cannabis mit erschwerendem Faktor → 15 Monate ────────────────────
    // BK-5 Kap. B 3, D 2.x-N: erschwerend=true → 15 Monate.

    public function testCannabisD2MitErschwerendGibt15Monate(): void
    {
        $result = $this->triage([
            'delikte'     => ['Drogen'],
            'drogen'      => ['Cannabis'],
            'thc_cooh'    => 200.0,
            'erschwerend' => true,
        ]);
        $this->assertStringContainsString('D2', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── D2 Cannabis: Mischkonsum auto-erschwerend → 15 Monate ───────────────
    // BK-5 D 2.x-N: Mischkonsum mit Alkohol ist erschwerender Faktor → auto-detect → 15m.

    public function testCannabisD2MischkonsumAutoErschwerendGibt15Monate(): void
    {
        $result = $this->triage([
            'delikte'  => ['Alkohol', 'Drogen'],
            'drogen'   => ['Cannabis'],
            'bak'      => 1.3,
            'thc_cooh' => 200.0,
        ]);
        $this->assertStringContainsString('D2', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Mischkonsum', $warntext);
    }

    // ── THC-COOH ≤ 150 ng/ml, reines Cannabis, kein BKF → D3 kontrollierter Konsum, 0 Monate ──
    // BK-5 Kap. B 3: Kontrollierter Cannabiskonsum erstmals als Option anerkannt.

    public function testCannabisD3KontrollierterKonsumBK5(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_cooh' => 50.0,
        ]);
        $this->assertStringContainsString('D3', $result['hypothese_text']);
        $this->assertStringNotContainsString('D2', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']);
        $kt_text = implode(' ', $result['kt_hinweise']);
        $this->assertStringContainsString('Kontrollierter Cannabiskonsum', $kt_text);
    }

    // ── Z-8: Mischkonsum sperrt die D3-Lockerung (BK-5 D3-Vorbemerkung) ──────
    // „Ausschluss der Einordnung in D3 bei Mischkonsum, wenn sich für eine Substanz
    // ein Konsumverhalten entsprechend D2 entwickelt hat." Cannabis + begleitende
    // suchtpotente Substanz (Medikamente/Benzos/Opioide) → keine 0-Monate-Lockerung.

    public function testCannabisMischkonsumMedikamenteSperrtD3Lockerung(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis', 'Medikamente'],
            'thc_cooh' => 50.0,
        ]);
        // 0-Monate-Lockerung ist gesperrt (Mischkonsum)
        $this->assertNotSame(0, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Mischkonsum', $warntext);
    }

    public function testCannabisMischkonsumBenzodiazepinSperrtD3Lockerung(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis', 'Benzodiazepine'],
            'thc_cooh' => 50.0,
        ]);
        $this->assertNotSame(0, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Mischkonsum', $warntext);
    }

    // Gegenprobe: reiner Cannabis-Konsum (kein Mischkonsum) behält die 0-Monate-Lockerung.

    public function testReinesCannabisBehaeltD3Lockerung(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_cooh' => 50.0,
        ]);
        $this->assertSame(0, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringNotContainsString('Mischkonsum', $warntext);
    }

    // ── THC-COOH ≤ 150 ng/ml, BKF aktiv → D3 klassisch, 6 Monate ────────────
    // BK-5: Kontrollierter Konsum für BKF ausgeschlossen → volle 6 Monate.

    public function testCannabisD3BKFBleibt6Monate(): void
    {
        $result = $this->triage([
            'delikte'    => ['Drogen'],
            'drogen'     => ['Cannabis'],
            'thc_cooh'   => 50.0,
            'bkf_status' => 'aktiv',
            'klassen'    => ['C'],
        ]);
        $this->assertStringContainsString('D3', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
    }

    // ── Cannabis D4: THC ≤ 3,5 ng/ml, kein BKF → Trennungsvermögen, 0 Monate ──
    // BK-5 Kap. B 2.1 + CanG: THC-Wert innerhalb Grenzwert → D4, kein Abstinenzgebot.

    public function testCannabisD4BeiNiedrigemTHC(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_wert' => 2.0,
        ]);
        $this->assertStringContainsString('D4', $result['hypothese_text']);
        $this->assertStringNotContainsString('D3', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // D2 Kokain / Harte Drogen – Therapie-Rabatt (T4, 2026-04-14)
    // ═══════════════════════════════════════════════════════════════════════════

    // ── D2 Kokain ohne erschwerenden Faktor → 12 Monate (Standard) ──────────
    // BK-5 Kap. B 3, D 2.x-N: kein erschwerender Faktor → Standard 12 Monate.

    public function testD2KokainOhneErschwerendGibt12Monate(): void
    {
        $result = $this->triage([
            'delikte'     => ['Drogen'],
            'drogen'      => ['Kokain'],
            'erschwerend' => false,
        ]);
        $this->assertStringContainsString('D2', $result['hypothese_text']);
        $this->assertSame(12, $result['abstinenz_monate']);
    }

    // ── D2 Kokain mit erschwerendem Faktor → 15 Monate ───────────────────────
    // BK-5 Kap. B 3, D 2.x-N: erschwerend (z.B. gescheiterte Abstinenzversuche) → 15 Monate.

    public function testD2KokainMitErschwerendGibt15Monate(): void
    {
        $result = $this->triage([
            'delikte'     => ['Drogen'],
            'drogen'      => ['Kokain'],
            'erschwerend' => true,
        ]);
        $this->assertStringContainsString('D2', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── BKF aktiv + niedriger BAK → spezieller A3/A4-BKF-Zweig (6 Monate) ───
    // Standardpfad A4 ohne BKF = 6 Monate; mit BKF aktiv + C-Klasse ebenfalls 6 Monate
    // (Trennungsfähigkeits-Strategie statt voller Abstinenz, kein KT erlaubt).

    public function testBKFAktivNiedrigerBAKGibt6MonateUndBKFLabel(): void
    {
        $result = $this->triage([
            'delikte'    => ['Alkohol'],
            'bak'        => 0.8,
            'bkf_status' => 'aktiv',
            'klassen'    => ['C'],
        ]);
        $this->assertSame(6, $result['abstinenz_monate']);
        $this->assertStringContainsString('BKF', $result['hypothese_text']);
        // Kein KT erlaubt – kt_hinweise müssen darauf hinweisen
        $kt_text = implode(' ', $result['kt_hinweise']);
        $this->assertStringContainsString('BKF', $kt_text);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // D1 therapie_art (stationär / ambulant / Selbstheiler)
    // ═══════════════════════════════════════════════════════════════════════════

    // ── D1 stationäre Entwöhnung → 15 Monate (DGVM-FAQ ZVS 1.2024, Indikator 7) ──
    // Korrigiert 26.04.2026 (Z-6): DGVM-FAQ belegt 15m gesamt für Indikator 7,
    // davon mind. 6 Monate nach Therapieende. Vorwert 12m war unbelegt.

    public function testD1StationaerEntwoehnungGibt15Monate(): void
    {
        $result = $this->triage([
            'delikte'            => ['Drogen'],
            'drogen'             => ['Cannabis'],
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => true,
            'therapie_art'       => 'stationaer',
        ]);
        $this->assertStringContainsString('D1', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
        $kt = $result['hypothesen'][0]['kt'];
        $this->assertStringContainsString('Indikator 7', $kt);
    }

    // ── D1 ambulante Therapie → 15 Monate (BK-5 Indikator 10) ───────────────
    // Ambulant ≠ stationär: volle 15 Monate bleiben bestehen.

    public function testD1AmbulantBleibT15Monate(): void
    {
        $result = $this->triage([
            'delikte'            => ['Drogen'],
            'drogen'             => ['Cannabis'],
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
            'therapy'            => true,
            'therapie_art'       => 'ambulant',
        ]);
        $this->assertStringContainsString('D1', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // THC-Schwellenwerte (BK-5 Kap. B 3) – neue Grenzwerte 11 ng/ml / 75 ng/ml
    // ═══════════════════════════════════════════════════════════════════════════

    // ── THC > 11 ng/ml → Warnung, Einzelfall ─────────────────────────────────
    // Kein normativer Grenzwert. Oberhalb wird D4 (Trennungsstrategie) nicht vorgeschlagen.
    // Routing: D3 mit entsprechender Warnung.

    public function testTHCUeber11ngmlWarntFehlendesTrennvermoegen(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_wert' => 15.0,
        ]);
        $this->assertStringContainsString('D3', $result['hypothese_text']);
        $this->assertStringNotContainsString('D4', $result['hypothese_text']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('im Einzelfall gegen ein Trennvermögen', $warntext);
    }

    // ── THC-COOH 75–150 ng/ml → D3 Regelkonsum-Gefährdung, 6 Monate ─────────
    // BK-5: THC-COOH im Bereich 75–150 ng/ml belegt regelmäßigen Konsum.
    // D4 ist ausgeschlossen; kontrollierter Konsum (D3 BK-5) ebenfalls nicht vertretbar.

    public function testCOOH75bis150IstD3RegelkonsumGefaehrdung(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_cooh' => 100.0,
        ]);
        $this->assertStringContainsString('D3', $result['hypothese_text']);
        $this->assertStringNotContainsString('D4', $result['hypothese_text']);
        $this->assertStringNotContainsString('D2', $result['hypothese_text']);
        $this->assertSame(6, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('75', $warntext);
    }

    // ── THC-COOH exakt 75 ng/ml → noch D3 Regelkonsum-Gefährdung (Grenze inklusiv) ──
    // Die Bedingung lautet `> 75`, d.h. exakt 75 fällt NICHT in den Gefährdungszweig.

    public function testCOOHGrenzwert75IstNochNichtGefaehrdung(): void
    {
        $result = $this->triage([
            'delikte'  => ['Drogen'],
            'drogen'   => ['Cannabis'],
            'thc_cooh' => 75.0,
        ]);
        // 75.0 ist NICHT > 75 → fällt in den normalen D3/D4-Zweig
        $this->assertStringNotContainsString('Regelkonsum-Gefährdung', $result['hypothese_text']);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // M-Hypothesen (BK-5 Kap. B 5) – F-1, 2026-04-26
    // ═══════════════════════════════════════════════════════════════════════════

    // ── M1: Medizinalcannabis (Override) → kein Abstinenzgebot, 0 Monate ─────
    // BK-5 Kap. B 5: Leistungstest PR ≥ 16 (Gr. 1) / PR ≥ 33 (BKF); kein Abstinenzgebot.

    public function testM1MedizinalcannabisGibt0Monate(): void
    {
        $result = $this->triage(['drogen' => ['Medizinalcannabis']]);
        $this->assertStringContainsString('M1', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Leistungstest', $warntext);
    }

    // ── Z-9: Missbräuchlicher Beikonsum bei Medizinalcannabis ────────────────
    // Cannabis-Beikonsum = Missbrauch nach Anlage 4 Nr. 9.2.1 FeV (Arzneimittel-
    // Missbrauch: Nr. 9.4); § 11 Abs. 7 FeV → Ungeeignetheit ohne MPU. Warnung muss
    // im M1-Zweig erscheinen und den Compliance-Ansatz durch einen Ausschluss ersetzen.

    public function testM1BeikonsumErzeugtAusschlusswarnung(): void
    {
        $result = $this->triage(['drogen' => ['Medizinalcannabis'], 'medizinal_beikonsum' => true]);
        $this->assertStringContainsString('M1', $result['hypothese_text']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Anlage 4 Nr. 9.2.1', $warntext);
        $this->assertStringContainsString('§ 11 Abs. 7', $warntext);
    }

    public function testM1OhneBeikonsumKeineAusschlusswarnung(): void
    {
        $result = $this->triage(['drogen' => ['Medizinalcannabis']]);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringNotContainsString('Anlage 4 Nr. 9.2.1', $warntext);
    }

    // Beikonsum wirkt auch im real erreichbaren generischen Medikamenten-Fallback
    // (Formular liefert 'Medikamente', nicht 'Medizinalcannabis').

    public function testMedikamentenFallbackBeikonsumErzeugtAusschlusswarnung(): void
    {
        $result = $this->triage(['drogen' => ['Medikamente'], 'medizinal_beikonsum' => true]);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Anlage 4 Nr. 9.2.1', $warntext);
    }

    // ── M2 mit Therapie → 12 Monate (analog D-Kriterien mit Therapie) ────────
    // BK-5 Kap. B 5: 12 Monate mit Therapie.

    public function testM2BenzodiazepinMitTherapieGibt12Monate(): void
    {
        $result = $this->triage(['drogen' => ['Benzodiazepine'], 'therapy' => true]);
        $this->assertStringContainsString('M2', $result['hypothese_text']);
        $this->assertSame(12, $result['abstinenz_monate']);
    }

    // ── M2 ohne Therapie (Selbstheiler) → 15 Monate ──────────────────────────
    // BK-5 Kap. B 5: 15 Monate ohne Therapie (Selbstheiler-Regel).

    public function testM2BenzodiazepinOhneTherapieGibt15Monate(): void
    {
        $result = $this->triage(['drogen' => ['Benzodiazepine'], 'therapy' => false]);
        $this->assertStringContainsString('M2', $result['hypothese_text']);
        $this->assertSame(15, $result['abstinenz_monate']);
    }

    // ── M1: Methadon in $drogen → Dauermedikation, 0 Monate ─────────────────
    // BK-5 Kap. B 5: Kein Abstinenzgebot; Compliance-Nachweis erforderlich.

    public function testM3MethadonGibt0Monate(): void
    {
        $result = $this->triage(['drogen' => ['Methadon']]);
        $this->assertStringContainsString('M1', $result['hypothese_text']);
        $this->assertSame(0, $result['abstinenz_monate']);
        $warntext = implode(' ', array_column($result['hypothesen'], 'warn'));
        $this->assertStringContainsString('Compliance', $warntext);
    }

    // ── BKF aktiv + A1 (ICD ≥ 3) → BKF-Warnung in warnungen, Monate = 15 ────
    // BKF erhöht die Monate nicht (A1 ohne Therapie = 15 Monate gem. BK-4, 4. Aufl.),
    // fügt aber zwingend eine BKF-spezifische Warnung hinzu.
    // Korrigiert 2026-04-14: war assertGreaterThanOrEqual(60, ...).

    public function testBKFAktivA1HatBKFWarnungUndMonate(): void
    {
        $result = $this->triage([
            'delikte'            => ['Alkohol'],
            'bak'                => 2.0,
            'bkf_status'         => 'aktiv',
            'klassen'            => ['C'],
            'therapy'            => false,
            'icd_kontrollverlust'=> true,
            'icd_priorisierung'  => true,
            'icd_folgeschaeden'  => true,
        ]);
        $this->assertGreaterThanOrEqual(15, $result['abstinenz_monate']);
        $warnungstext = implode(' ', $result['warnungen']);
        $this->assertStringContainsString('BKF', $warnungstext);
    }
}
