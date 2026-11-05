<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für logic/mpu_helper.php
 *
 * Ausführen: composer test
 *   oder:    vendor/bin/phpunit --testdox tests/MpuHelperTest.php
 */
class MpuHelperTest extends TestCase
{
    // ── is_authority_mettmann() ───────────────────────────────────────────────

    public function testMettmannErkennungNachBehoerde(): void
    {
        $this->assertTrue(is_authority_mettmann('Straßenverkehrsamt Mettmann', ''));
    }

    public function testMettmannErkennungNachWohnort(): void
    {
        $this->assertTrue(is_authority_mettmann('', 'Velbert'));
        $this->assertTrue(is_authority_mettmann('', 'velbert'));
    }

    public function testMettmannUmlautWohnort(): void
    {
        $this->assertTrue(is_authority_mettmann('', 'Wülfrath'));
        $this->assertTrue(is_authority_mettmann('', 'wülfrath'));
    }

    public function testMettmannNegativ(): void
    {
        $this->assertFalse(is_authority_mettmann('Straßenverkehrsamt Düsseldorf', 'Düsseldorf'));
    }

    // ── is_authority_duesseldorf() ────────────────────────────────────────────

    public function testDuesseldorfErkennungNachBehoerde(): void
    {
        $this->assertTrue(is_authority_duesseldorf('Straßenverkehrsamt Düsseldorf', ''));
        $this->assertTrue(is_authority_duesseldorf('Straßenverkehrsamt Duesseldorf', ''));
    }

    public function testDuesseldorfErkennungNachWohnort(): void
    {
        $this->assertTrue(is_authority_duesseldorf('', 'Düsseldorf'));
        $this->assertTrue(is_authority_duesseldorf('', 'düsseldorf'));
    }

    public function testDuesseldorfErkennungNachPLZ(): void
    {
        $this->assertTrue(is_authority_duesseldorf('', '', '40210'));
        $this->assertFalse(is_authority_duesseldorf('', '', '50667')); // Köln
    }

    // ── get_abstinenz_luecken() ───────────────────────────────────────────────

    public function testKeineProbenKeineLuecke(): void
    {
        $result = get_abstinenz_luecken([], false);
        $this->assertEmpty($result['gaps']);
        $this->assertFalse($result['hat_positiv']);
        $this->assertNull($result['letzte_probe']);
    }

    public function testEineUrinprobKeineInterneLuecke(): void
    {
        $probes = [['probe_date' => '2025-01-01', 'method' => 'urin', 'result' => 'negativ']];
        $result = get_abstinenz_luecken($probes, false);
        $this->assertEmpty($result['gaps']);
    }

    /** CC-15: Ein Programm mit 4 Proben in 6 Monaten ist keine Lücke, auch nicht laufend. */
    public function testUrinprogrammMitDerSollzahlHatKeineLuecke(): void
    {
        $probes = [];
        foreach (['2025-01-01', '2025-02-12', '2025-03-26', '2025-05-07'] as $datum) {
            $probes[] = ['probe_date' => $datum, 'method' => 'urin', 'result' => 'negativ'];
        }
        $this->assertSame([], get_abstinenz_luecken($probes, true)['gaps']);
    }

    /** CC-21 */
    public function testKetteBeginntNachDemLetztenPositivenBefund(): void
    {
        $probes = [
            ['probe_date' => '2026-01-10', 'method' => 'urin', 'result' => 'negativ'],
            ['probe_date' => '2026-06-10', 'method' => 'urin', 'result' => 'positiv'],
            ['probe_date' => '2026-06-20', 'method' => 'urin', 'result' => 'negativ'],
        ];
        $this->assertSame('2027-06-20', get_karenzzeit_info('2030-01-01', $probes, 12)['abstinenz_ende']);
        $this->assertFalse(get_abstinenz_luecken($probes, false)['hat_positiv'], 'Alter Befund hält keine Warnung');
        $this->assertCount(1, abstinenz_belege_der_kette($probes));
        array_pop($probes);
        $this->assertTrue(get_abstinenz_luecken($probes, false)['hat_positiv']);
        $this->assertNull(get_karenzzeit_info('2030-01-01', $probes, 12));
    }

    /** CC-28 */
    public function testVerduennteUrinprobeZaehltNicht(): void
    {
        $grenze = (float)ctu_config()['kreatinin']['verwertbar_ab_mg_dl'];
        $probe  = fn($k) => ['probe_date' => '2026-01-10', 'method' => 'urin', 'result' => 'negativ', 'kreatinin_mg_dl' => $k];
        $this->assertFalse(probe_ist_abstinenzbeleg($probe($grenze - 0.1)));
        $this->assertTrue(probe_ist_abstinenzbeleg($probe($grenze)));
        $this->assertTrue(probe_ist_abstinenzbeleg($probe(null)), 'Ohne Wert kein Urteil');
        $this->assertTrue(probe_ist_abstinenzbeleg(['method' => 'haar', 'result' => 'negativ', 'kreatinin_mg_dl' => 1.0]));
        $this->assertFalse(probe_ist_abstinenzbeleg(['method' => 'urin', 'result' => 'positiv']));
    }

    public function testPositiveBefundWirdMarkiert(): void
    {
        $probes = [
            ['probe_date' => '2025-01-01', 'method' => 'urin', 'result' => 'negativ'],
            ['probe_date' => '2025-01-10', 'method' => 'urin', 'result' => 'positiv'],
        ];
        $result = get_abstinenz_luecken($probes, false);
        $this->assertTrue($result['hat_positiv']);
    }

    public function testPositiveProbeNichtInLueckenberechnung(): void
    {
        // Über einen positiven Befund hinweg gibt es keine Lücke: Die Kette beginnt danach neu.
        $probes = [
            ['probe_date' => '2025-01-01', 'method' => 'haar', 'result' => 'negativ'],
            ['probe_date' => '2025-02-05', 'method' => 'haar', 'result' => 'positiv'],
            ['probe_date' => '2025-12-20', 'method' => 'haar', 'result' => 'negativ'],
        ];
        $this->assertSame([], get_abstinenz_luecken($probes, false)['gaps']);
    }

    public function testHaarLueckeNach95Tagen(): void
    {
        $probes = [
            ['probe_date' => '2025-01-01', 'method' => 'haar', 'result' => 'negativ'],
            ['probe_date' => '2025-04-20', 'method' => 'haar', 'result' => 'negativ'], // 109 Tage
        ];
        $result = get_abstinenz_luecken($probes, false);
        $this->assertCount(1, $result['gaps']);
        $this->assertSame('haar', $result['gaps'][0]['method']);
    }

    // ── parse_date() ─────────────────────────────────────────────────────────

    public function testParseDateGueltig(): void
    {
        $dt = parse_date('2025-06-15');
        $this->assertInstanceOf(DateTimeImmutable::class, $dt);
        $this->assertSame('2025-06-15', $dt->format('Y-m-d'));
    }

    public function testParseDateLeerstring(): void
    {
        $this->assertNull(parse_date(''));
    }

    public function testParseDateNullDatum(): void
    {
        $this->assertNull(parse_date('0000-00-00'));
    }

    public function testParseDateUngueltig(): void
    {
        $this->assertNull(parse_date('kein-datum'));
    }

    // ── bkf_leistung_ampel() ─────────────────────────────────────────────────

    public function testLeistungAmpelKeineTests(): void
    {
        $result = bkf_leistung_ampel([], false);
        $this->assertSame('secondary', $result['level']);
        $this->assertSame(0, $result['fail_pr16']);
        $this->assertFalse($result['kompensiert']);
    }

    public function testLeistungAmpelAlleGruen(): void
    {
        $tests = [
            ['percentile' => 40],
            ['percentile' => 50],
            ['percentile' => 60],
        ];
        $result = bkf_leistung_ampel($tests, false);
        $this->assertSame('success', $result['level']);
        $this->assertSame(0, $result['fail_pr16']);
        $this->assertFalse($result['kompensiert']);
    }

    public function testLeistungAmpelAusreisserKompensiert(): void
    {
        $tests = [
            ['percentile' => 10], // < PR 16 – Ausreißer
            ['percentile' => 55], // >= PR 50 – kompensiert
            ['percentile' => 60], // >= PR 50 – kompensiert
        ];
        $result = bkf_leistung_ampel($tests, false);
        $this->assertSame('warning', $result['level']);
        $this->assertSame(1, $result['fail_pr16']);
        $this->assertTrue($result['kompensiert']);
    }

    public function testLeistungAmpelAusreisserNichtKompensiert(): void
    {
        $tests = [
            ['percentile' => 10], // < PR 16 – Ausreißer
            ['percentile' => 30], // >= PR 16 aber < PR 50 – keine Kompensation
            ['percentile' => 55],
        ];
        $result = bkf_leistung_ampel($tests, false);
        $this->assertSame('danger', $result['level']);
        $this->assertSame(1, $result['fail_pr16']);
        $this->assertFalse($result['kompensiert']);
    }

    // ── UX-6: get_triage_vollstaendigkeit() ───────────────────────────────────

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function akteMitIcd(array $overrides = []): array
    {
        return array_merge([
            'client_id'           => 1,
            'offense_category'    => '["Alkohol"]',
            'icd_kontrollverlust' => 0,
            'icd_priorisierung'   => 0,
            'icd_folgeschaeden'   => 0,
            'icd_craving'         => 0,
            'icd_toleranz'        => 0,
            'icd_entzug'          => 0,
        ], $overrides);
    }

    public function testVollstaendigkeitAlleKriterienErhoben(): void
    {
        $result = get_triage_vollstaendigkeit($this->akteMitIcd());
        $this->assertFalse($result['unvollstaendig']);
        $this->assertSame(6, $result['erhoben']);
        $this->assertSame(0, $result['offen']);
        $this->assertSame([], $result['fehlend']);
    }

    public function testVollstaendigkeitEinKriteriumOffen(): void
    {
        $result = get_triage_vollstaendigkeit($this->akteMitIcd(['icd_craving' => null]));
        $this->assertTrue($result['unvollstaendig']);
        $this->assertSame(5, $result['erhoben']);
        $this->assertSame(1, $result['offen']);
        $this->assertSame(['Craving'], $result['fehlend']);
    }

    public function testVollstaendigkeitNeinZaehltAlsErhoben(): void
    {
        // 0 = explizit verneint ist eine Bewertung, kein fehlender Wert
        $result = get_triage_vollstaendigkeit($this->akteMitIcd(['icd_toleranz' => 0]));
        $this->assertFalse($result['unvollstaendig']);
    }

    public function testVollstaendigkeitOhneKonsumDeliktImmerVollstaendig(): void
    {
        // Ohne Alkohol/Drogen sind die ICD-Kriterien fachlich nicht einschlägig
        $result = get_triage_vollstaendigkeit($this->akteMitIcd([
            'offense_category'    => '["Punkte"]',
            'icd_kontrollverlust' => null,
            'icd_priorisierung'   => null,
            'icd_folgeschaeden'   => null,
            'icd_craving'         => null,
            'icd_toleranz'        => null,
            'icd_entzug'          => null,
        ]));
        $this->assertFalse($result['unvollstaendig']);
        $this->assertSame(6, $result['offen']); // Zählung bleibt korrekt
    }

    public function testVollstaendigkeitDrogenDeliktLoestAusEbenso(): void
    {
        $result = get_triage_vollstaendigkeit($this->akteMitIcd([
            'offense_category' => '["Drogen"]',
            'icd_entzug'       => null,
        ]));
        $this->assertTrue($result['unvollstaendig']);
        $this->assertSame(['Entzugssyndrom'], $result['fehlend']);
    }

    public function testVollstaendigkeitKaputtesJsonWirdNichtZuKonsumDelikt(): void
    {
        // json_decode() liefert hier null – darf keinen TypeError und kein
        // falsches "unvollständig" erzeugen
        $result = get_triage_vollstaendigkeit($this->akteMitIcd([
            'offense_category'    => '',
            'icd_kontrollverlust' => null,
        ]));
        $this->assertFalse($result['unvollstaendig']);
    }

    public function testVollstaendigkeitFehlendeSpaltenGeltenAlsNichtErhoben(): void
    {
        $result = get_triage_vollstaendigkeit(['offense_category' => '["Alkohol"]']);
        $this->assertTrue($result['unvollstaendig']);
        $this->assertSame(6, $result['offen']);
    }

    // ── UX-6: get_client_status() Priorisierung ───────────────────────────────

    public function testClientStatusMeldetVorlaeufigeTriage(): void
    {
        $status = get_client_status($this->akteMitIcd(['icd_craving' => null]), [], []);
        $this->assertSame('warning', $status['level']);
        $this->assertStringContainsString('Triage vorläufig', $status['message']);
        $this->assertStringContainsString('bearbeiten.php?id=1', $status['action_url']);
    }

    public function testClientStatusVorlaeufigVerdraengtChecklistenHinweis(): void
    {
        // Checkliste unter 50 % (Priorität 6) darf die vorläufige Triage
        // (Priorität 5) nicht überdecken
        $tasks  = [['status' => 'offen'], ['status' => 'offen'], ['status' => 'erledigt']];
        $status = get_client_status($this->akteMitIcd(['icd_craving' => null]), [], $tasks);
        $this->assertStringContainsString('Triage vorläufig', $status['message']);
    }

    public function testClientStatusOhneIcdSpaltenKeineFalscheWarnung(): void
    {
        // Aufrufer, die die icd_*-Spalten nicht laden, dürfen keine
        // "Triage vorläufig"-Warnung bekommen
        $status = get_client_status(['client_id' => 1, 'offense_category' => '["Alkohol"]'], [], []);
        $this->assertSame('success', $status['level']);
    }

    // ── E-4: Behörden-Vorlauffristen ──────────────────────────────────────────

    public function testVorlaufDuesseldorfLaengerAlsStandard(): void
    {
        $this->assertSame(84, get_behoerden_vorlauf_tage('Führerscheinstelle Düsseldorf', 'Düsseldorf', '40210'));
        $this->assertSame(70, get_behoerden_vorlauf_tage('Straßenverkehrsamt Mettmann', 'Velbert'));
        $this->assertSame(70, get_behoerden_vorlauf_tage('Führerscheinstelle Köln', 'Köln', '50667'));
    }

    public function testVorlaufAusAkteOhneBehoerdenfelderFaelltAufStandard(): void
    {
        $this->assertSame(70, get_behoerden_vorlauf_aus_akte([]));
    }

    public function testAmpelSchlaegtAmVorlaufDerBehoerdeUm(): void
    {
        $in = static fn(int $tage): string => date('Y-m-d', strtotime("+{$tage} days"));

        // Düsseldorf (84): 85 Tage noch gelb, 84 Tage bereits rot
        $this->assertSame('warning', get_antrag_ampel($in(85), 'offen', null, 84)['level']);
        $this->assertSame('danger',  get_antrag_ampel($in(84), 'offen', null, 84)['level']);

        // Ohne Behördenangabe gilt der Standard (70)
        $this->assertSame('warning', get_antrag_ampel($in(71), 'offen', null)['level']);
        $this->assertSame('danger',  get_antrag_ampel($in(70), 'offen', null)['level']);
    }

    public function testAmpelBenenntDenUnterschrittenenVorlauf(): void
    {
        $label = get_antrag_ampel(date('Y-m-d', strtotime('+70 days')), 'offen', null, 84)['label'];
        $this->assertStringContainsString('84', $label);
    }

    public function testArbeitslisteUndKarteStufenDuesseldorfGleichEin(): void
    {
        // Der Kern von E-4: bei 70 Tagen meldete die Karte den Vorlauf als
        // unterschritten, die Arbeitsliste nur "vorbereiten".
        $akte = [
            'client_id' => 1, 'status' => 'aktiv',
            'suspension_end_date' => date('Y-m-d', strtotime('+70 days')),
            'antrag_status' => 'offen', 'mpu_target_date' => null,
            'offense_category' => '[]',
            'authority_name' => 'Führerscheinstelle Düsseldorf',
            'city' => 'Düsseldorf', 'zip_code' => '40210',
        ];
        $this->assertSame('danger', get_client_status($akte, [], [])['level']);
    }

    // ── E-7: get_screening_scores() ───────────────────────────────────────────

    public function testScreeningScoresLeerWennNichtsErhoben(): void
    {
        $this->assertSame([], get_screening_scores(['audit_score' => null, 'last_score' => null]));
        $this->assertSame([], get_screening_scores([]));
    }

    public function testScreeningScoreNullIstEinWertUndKeineLuecke(): void
    {
        // 0 Punkte ist ein erhobenes Ergebnis, kein fehlender Wert
        $scores = get_screening_scores(['audit_score' => 0, 'last_score' => 0]);
        $this->assertCount(2, $scores);
        $this->assertSame('unauffällig', $scores[0]['label']);
        $this->assertSame('success', $scores[0]['level']);
    }

    public function testAuditStufen(): void
    {
        $stufe = fn(int $wert): array => get_screening_scores(['audit_score' => $wert])[0];
        $this->assertSame('unauffällig',        $stufe(7)['label']);
        $this->assertSame('riskanter Konsum',   $stufe(8)['label']);
        $this->assertSame('riskanter Konsum',   $stufe(15)['label']);
        $this->assertSame('schädlicher Konsum', $stufe(16)['label']);
        $this->assertSame('danger',             $stufe(20)['level']);
    }

    public function testAuditZwanzigFormuliertAbklaerungKeinenBefund(): void
    {
        // WHO-Manual: "warrant further diagnostic evaluation for alcohol
        // dependence" – der Wert begründet die Abklärung, nicht den Befund.
        // Das Label muss also eine Handlung benennen und darf nicht auf eine
        // Feststellung enden (früher: "abhängig").
        $label = get_screening_scores(['audit_score' => 25])[0]['label'];
        $this->assertStringContainsString('abklären', $label);
        $this->assertStringEndsWith('abklären', $label);
    }

    public function testLastCutoffLiegtBeiZwei(): void
    {
        // E-6: Testmanual Rumpf/Hapke/John – nicht bei 4
        $bei = fn(int $wert): array => get_screening_scores(['last_score' => $wert])[0];
        $this->assertSame('success', $bei(1)['level']);
        $this->assertSame('warning', $bei(2)['level']);
        $this->assertStringContainsString('Missbrauch', $bei(2)['label']);
    }

    public function testScreeningScoresTragenMaximumAusDerConfig(): void
    {
        $scores = get_screening_scores(['audit_score' => 12, 'last_score' => 3]);
        $this->assertSame(40, $scores[0]['max']);
        $this->assertSame(7,  $scores[1]['max']);
    }

    public function testClientStatusPositiverBefundSchlaegtVorlaeufigeTriage(): void
    {
        $probes = [['probe_date' => '2026-01-10', 'method' => 'Urin', 'result' => 'positiv']];
        $status = get_client_status($this->akteMitIcd(['icd_craving' => null]), $probes, []);
        $this->assertSame('danger', $status['level']);
        $this->assertStringContainsString('Positiver Abstinenz-Befund', $status['message']);
    }

    // ── R3-7: "Nächster Schritt" bei gelber Antrags-Ampel ─────────────────────

    /**
     * Akte ohne jedes andere Warnsignal, damit get_client_status() garantiert
     * in Priorität 4 landet: keine Proben, keine Tasks, ICD vollständig.
     */
    private function akteMitAntragsStatus(string $antrag_status, ?string $sperrfrist = null): array
    {
        return $this->akteMitIcd([
            'antrag_status'       => $antrag_status,
            'suspension_end_date' => $sperrfrist,
            'status'              => 'aktiv',
        ]);
    }

    public function testAufforderungLiegtVorNenntKeineFrageZeichenFrist(): void
    {
        $status = get_client_status($this->akteMitAntragsStatus('aufforderung_liegt_vor', '2027-06-30'), [], []);
        $this->assertSame('warning', $status['level']);
        $this->assertStringNotContainsString('?', $status['message']);
        $this->assertStringNotContainsString('Antrag vorbereiten', $status['message']);
        $this->assertStringContainsString('Aufforderung liegt vor', $status['message']);
        // Der Antrag ist gegenstandslos – Sprung in die Korrespondenz, nicht in die Checkliste.
        $this->assertStringEndsWith('#korrespondenz', $status['action_url']);
    }

    public function testAbgelehnterAntragNenntNeuenAnlauf(): void
    {
        $status = get_client_status($this->akteMitAntragsStatus('abgelehnt', '2027-06-30'), [], []);
        $this->assertSame('warning', $status['level']);
        $this->assertStringNotContainsString('?', $status['message']);
        $this->assertStringContainsString('Neuer Anlauf erforderlich', $status['message']);
        $this->assertStringEndsWith('#todos', $status['action_url']);
    }

    public function testAntragVorbereitenBehaeltTagesangabe(): void
    {
        // Standard-Vorlauf 70 Tage + 30 Tage Vorwarnung: 85 Tage liegen im gelben Band.
        $sperrfrist = (new DateTimeImmutable('today'))->modify('+85 days')->format('Y-m-d');
        $status = get_client_status($this->akteMitAntragsStatus('nicht_gestellt', $sperrfrist), [], []);
        $this->assertSame('warning', $status['level']);
        $this->assertStringContainsString('Antrag vorbereiten', $status['message']);
        $this->assertStringContainsString('noch 85 Tage', $status['message']);
    }

    public function testMpuZielmonatBehauptetKeineSperrfrist(): void
    {
        // Ohne Sperrfrist greift der Zielmonat-Fallback – von "Sperrfrist-Ende"
        // darf dort keine Rede sein.
        $ziel   = (new DateTimeImmutable('today'))->modify('+75 days')->format('Y-m-d');
        $akte   = $this->akteMitAntragsStatus('nicht_gestellt', null);
        $akte['mpu_target_date'] = $ziel;
        $status = get_client_status($akte, [], []);
        $this->assertSame('warning', $status['level']);
        $this->assertStringContainsString('MPU-Zielmonat', $status['message']);
        $this->assertStringNotContainsString('Sperrfrist', $status['message']);
    }

    // ── R3-3: Haar-Segmentierung (CTU-Kriterien der BK-5) ────────────

    public function testHaarSegmentAlkoholDreiMonate(): void
    {
        $this->assertSame(3, haar_segment_monate('["Alkohol"]'));
    }

    public function testHaarSegmentDrogenSechsMonate(): void
    {
        $this->assertSame(6, haar_segment_monate('["Drogen"]'));
    }

    public function testHaarSegmentMischkonsumFolgtDerStrengerenAlkoholKadenz(): void
    {
        // Der EtG-Nachweis erzwingt das 3-cm-Segment; ein 3-cm-Segment lässt
        // sich zusätzlich auf BTM analysieren, umgekehrt nicht.
        $this->assertSame(3, haar_segment_monate('["Alkohol","Drogen"]'));
        $this->assertSame(3, haar_segment_monate('["Drogen","Alkohol"]'));
    }

    public function testHaarSegmentOhneAngabeIrrtZurSicherenSeite(): void
    {
        $this->assertSame(3, haar_segment_monate(null));
        $this->assertSame(3, haar_segment_monate(''));
        $this->assertSame(3, haar_segment_monate('kein json'));
        $this->assertSame(3, haar_segment_monate('["Punkte"]'));
    }

    public function testPflichtAnalysenSindProgrammdauerGeteiltDurchSegment(): void
    {
        // Alkohol (3 Monate je Probe) – deckt sich mit der bisherigen Staffel
        $this->assertSame(2, haar_pflicht_analysen(6,  3));
        $this->assertSame(4, haar_pflicht_analysen(12, 3));
        $this->assertSame(5, haar_pflicht_analysen(15, 3));
        // Drogen (6 Monate je Probe) – die Hälfte, 15 Monate wird aufgerundet
        $this->assertSame(1, haar_pflicht_analysen(6,  6));
        $this->assertSame(2, haar_pflicht_analysen(12, 6));
        $this->assertSame(3, haar_pflicht_analysen(15, 6));
    }

    public function testPflichtAnalysenOhneProgrammIstNull(): void
    {
        $this->assertSame(0, haar_pflicht_analysen(0, 3));
        $this->assertSame(0, haar_pflicht_analysen(12, 0));
    }

    /**
     * Der Kern von R3-3: Zwei korrekte 6-cm-Analysen im Abstand von rund einem
     * halben Jahr sind bei Drogenabstinenz lückenlos – die alte pauschale
     * 95-Tage-Regel meldete hier eine Lücke, und zwar auf danger-Priorität.
     */
    public function testDrogenHaarketteMitSechsMonatsAbstandIstLueckenlos(): void
    {
        $probes = [
            ['probe_date' => '2026-01-10', 'method' => 'haar', 'result' => 'negativ'],
            ['probe_date' => '2026-07-09', 'method' => 'haar', 'result' => 'negativ'], // 180 Tage
        ];
        $result = get_abstinenz_luecken($probes, false, '["Drogen"]');
        $this->assertSame([], $result['gaps']);
    }

    public function testAlkoholHaarketteMitSechsMonatsAbstandBleibtLuecke(): void
    {
        // Gegenprobe: derselbe Abstand ist bei Alkohol sehr wohl eine Lücke.
        $probes = [
            ['probe_date' => '2026-01-10', 'method' => 'haar', 'result' => 'negativ'],
            ['probe_date' => '2026-07-09', 'method' => 'haar', 'result' => 'negativ'],
        ];
        $result = get_abstinenz_luecken($probes, false, '["Alkohol"]');
        $this->assertCount(1, $result['gaps']);
        $this->assertSame('haar', $result['gaps'][0]['method']);
        $this->assertSame(180, $result['gaps'][0]['tage']);
    }

    public function testDrogenHaarketteUeberSechsMonateBleibtLuecke(): void
    {
        // 190 Tage überschreiten auch die Drogen-Schwelle von 185.
        $probes = [
            ['probe_date' => '2026-01-10', 'method' => 'haar', 'result' => 'negativ'],
            ['probe_date' => '2026-07-19', 'method' => 'haar', 'result' => 'negativ'],
        ];
        $result = get_abstinenz_luecken($probes, false, '["Drogen"]');
        $this->assertCount(1, $result['gaps']);
        $this->assertSame(190, $result['gaps'][0]['tage']);
    }

    // ── R3-6: fall_gehoert_zu_klient() ────────────────────────────────────────

    private function faelle_db(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE cases (id INTEGER PRIMARY KEY, client_id INTEGER NOT NULL)");
        $pdo->exec("INSERT INTO cases (id, client_id) VALUES (10, 1), (11, 1), (20, 2)");
        return $pdo;
    }

    public function testEigenerFallWirdAkzeptiert(): void
    {
        $pdo = $this->faelle_db();
        $this->assertTrue(fall_gehoert_zu_klient($pdo, 10, 1));
        $this->assertTrue(fall_gehoert_zu_klient($pdo, 11, 1));
    }

    public function testFremderFallWirdAbgelehnt(): void
    {
        // Der Kern von R3-6: case_id aus der Adresszeile eines anderen Klienten.
        $pdo = $this->faelle_db();
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 20, 1));
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 10, 2));
    }

    public function testNichtExistenterUndLeererFallWerdenAbgelehnt(): void
    {
        $pdo = $this->faelle_db();
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 999, 1), 'Fall existiert nicht');
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 0, 1),   'fehlender case_id-Parameter');
        $this->assertFalse(fall_gehoert_zu_klient($pdo, -1, 1),  'negativer Wert');
        $this->assertFalse(fall_gehoert_zu_klient($pdo, 10, 0),  'fehlende client_id');
    }

    // ── R3-1: audit_log_anonymisieren() ───────────────────────────────────────

    /**
     * Minimales audit_log in SQLite. Die Funktion nutzt bewusst portables SQL,
     * damit die Art.-17-Zusicherung ohne MySQL-Instanz prüfbar ist.
     */
    private function audit_log_db(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(
            "CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL, table_name TEXT NOT NULL, record_id INTEGER NOT NULL,
                client_id INTEGER, description TEXT, user_name TEXT, ip_address TEXT
             )"
        );
        $einfuegen = $pdo->prepare(
            "INSERT INTO audit_log (action, table_name, record_id, client_id, description, user_name, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        // Klient 1 – die vier real vorkommenden Freitext-Muster
        $einfuegen->execute(['create_client', 'clients',           1, 1, 'Max Mustermann',                                 'berater', '10.0.0.1']);
        $einfuegen->execute(['update_case',   'cases',             7, 1, 'Hypothese: → Hypothese A1 (Alkoholabhängigkeit)', 'berater', '10.0.0.1']);
        $einfuegen->execute(['add_probe',     'abstinence_probes', 3, 1, 'urin / positiv / Labor XY',                       'berater', '10.0.0.1']);
        $einfuegen->execute(['add_note',      'case_notes',        9, 1, null,                                             'berater', '10.0.0.1']);
        // Klient 2 – darf unberührt bleiben
        $einfuegen->execute(['create_client', 'clients',           2, 2, 'Erika Musterfrau',                                'berater', '10.0.0.1']);
        return $pdo;
    }

    public function testAnonymisierenEntferntAlleFreitexteDesKlienten(): void
    {
        $pdo = $this->audit_log_db();
        $this->assertSame(3, audit_log_anonymisieren($pdo, 1), 'nur die 3 befüllten Beschreibungen zählen');

        $rest = $pdo->query("SELECT description FROM audit_log WHERE client_id = 1")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([null, null, null, null], $rest);
    }

    public function testAnonymisierenLaesstNachweisspurUndFremdeKlientenIntakt(): void
    {
        $pdo = $this->audit_log_db();
        audit_log_anonymisieren($pdo, 1);

        // Der Beleg, DASS verarbeitet wurde, überlebt (Art. 5 Abs. 2).
        $spur = $pdo->query("SELECT action, client_id, user_name FROM audit_log WHERE client_id = 1")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(4, $spur);
        $this->assertSame('create_client', $spur[0]['action']);
        $this->assertSame('berater',       $spur[0]['user_name']);

        // Kein Kollateralschaden am Nachbarklienten.
        $this->assertSame('Erika Musterfrau', $pdo->query("SELECT description FROM audit_log WHERE client_id = 2")->fetchColumn());
    }

    /**
     * Sichert die Reihenfolge ab, von der die Korrektheit abhängt: wird der
     * Löschbeleg – wie vor R3-1 – VOR der Anonymisierung geschrieben, räumt
     * diese ihn im selben Vorgang wieder ab.
     */
    public function testLoeschbelegNachAnonymisierungUeberlebt(): void
    {
        $pdo = $this->audit_log_db();
        audit_log_anonymisieren($pdo, 1);
        $pdo->prepare("INSERT INTO audit_log (action, table_name, record_id, client_id, description) VALUES (?, ?, ?, ?, ?)")
            ->execute(['dsgvo_delete', 'clients', 1, 1, 'Art.-17-Löschung nach Widerruf der DSGVO-Einwilligung']);

        $beleg = $pdo->query("SELECT description FROM audit_log WHERE client_id = 1 AND action = 'dsgvo_delete'")->fetchColumn();
        $this->assertSame('Art.-17-Löschung nach Widerruf der DSGVO-Einwilligung', $beleg);
    }
}
