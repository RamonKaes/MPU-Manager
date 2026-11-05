<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prüft die Schreibwege der Handler über echtes HTTP gegen eine eigene Testdatenbank.
 *
 * Läuft nur, wenn eine Testdatenbank angegeben ist, sonst wird er übersprungen:
 *
 *   MPU_TEST_DB_NAME=mpu_test MPU_TEST_DB_USER=... MPU_TEST_DB_PASS=... composer test
 *
 * Achtung: Alle Tabellen dieser Datenbank werden gelöscht. Deshalb muss ihr Name auf
 * `_test` enden. Der Test arbeitet auf einer Kopie des Quellbaums mit eigenem Testnutzer.
 * `php -S` wertet `.htaccess` nicht aus, über Zugriffssperren sagt dieser Test nichts.
 */
final class AblaufTest extends TestCase
{
    private const PASSWORT = 'Ablauftest-Passwort-1';
    private static ?string $wurzel = null;
    private static $server = null;
    private static int $port = 0;
    private static ?PDO $pdo = null;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var list<string> */
    private array $weitererKopf = [];
    /** @var array<string,string> */
    private static array $umgebung = [];

    public static function setUpBeforeClass(): void
    {
        $name = (string)getenv('MPU_TEST_DB_NAME');
        if ($name === '') {
            return;
        }
        if (!str_ends_with($name, '_test')) {
            throw new RuntimeException('MPU_TEST_DB_NAME muss auf _test enden, alle Tabellen dieser Datenbank werden gelöscht.');
        }
        $benutzer = (string)getenv('MPU_TEST_DB_USER');
        $passwort = (string)getenv('MPU_TEST_DB_PASS');
        $host     = getenv('MPU_TEST_DB_HOST') ?: 'localhost';

        self::$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $benutzer, $passwort, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tabelle) {
            self::$pdo->exec("DROP TABLE `{$tabelle}`");
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::$pdo->exec((string)file_get_contents(__DIR__ . '/../../schema.sql'));
        self::$pdo->query('SELECT 1')->fetchAll(); // Mehrfachabfrage abschließen

        self::$wurzel = sys_get_temp_dir() . '/mpu_ablauf_' . bin2hex(random_bytes(4));
        self::kopiere(__DIR__ . '/../..', self::$wurzel);
        mkdir(self::$wurzel . '/_sitzungen');
        file_put_contents(self::$wurzel . '/config/users.php', '<?php return ' . var_export(['pruefer' => [
            'hash' => password_hash(self::PASSWORT, PASSWORD_BCRYPT, ['cost' => 4]), 'display_name' => 'Pruefer',
            // Echte Kosten: Nur dann ist die Prüfung lang genug, dass Versuche sich überholen können.
        ], 'langsam' => ['hash' => password_hash(self::PASSWORT, PASSWORD_BCRYPT, ['cost' => 12]), 'display_name' => 'Langsam']], true) . ';');

        // Mehrere Worker: Ein einzelner liefe Anfragen nacheinander ab, Rennen wären nicht zu messen.
        $umgebung = ['DB_USER' => $benutzer, 'DB_PASS' => $passwort, 'DB_NAME' => $name, 'DB_HOST' => $host, 'PATH' => (string)getenv('PATH'), 'PHP_CLI_SERVER_WORKERS' => '6'];
        $ausgabe  = [];
        self::$umgebung = $umgebung;
        $ablauf   = proc_open([PHP_BINARY, 'scripts/migrate.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ausgabe, self::$wurzel, $umgebung);
        stream_get_contents($ausgabe[1]);
        if (proc_close($ablauf) !== 0) {
            throw new RuntimeException('Migrationen sind fehlgeschlagen.');
        }

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $rohre = [];
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'opcache.enable=0', '-d', 'session.save_path=' . self::$wurzel . '/_sitzungen', '-S', '127.0.0.1:' . self::$port, '-t', self::$wurzel],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre, self::$wurzel, $umgebung
        );
        for ($i = 0; $i < 100; $i++) {
            $verbindung = @fsockopen('127.0.0.1', self::$port, $nr, $text, 0.1);
            if ($verbindung !== false) { fclose($verbindung); return; }
            usleep(50000);
        }
        throw new RuntimeException('Testserver startet nicht.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$wurzel !== null) {
            self::loesche(self::$wurzel);
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped('Keine Testdatenbank angegeben (MPU_TEST_DB_NAME).');
        }
        self::$pdo->exec('DELETE FROM login_attempts');
        $this->cookies = [];
        $this->weitererKopf = [];
    }

    private static function kopiere(string $von, string $nach): void
    {
        mkdir($nach, 0700, true);
        foreach (scandir($von) ?: [] as $eintrag) {
            if (in_array($eintrag, ['.', '..', '.git', 'vendor', 'dist', 'tests', '.env', 'ssh.txt', '_prompts', '.claude'], true) || ($eintrag === 'users.php' && basename($von) === 'config')) {
                continue;
            }
            is_dir("{$von}/{$eintrag}") ? self::kopiere("{$von}/{$eintrag}", "{$nach}/{$eintrag}") : copy("{$von}/{$eintrag}", "{$nach}/{$eintrag}");
        }
    }

    private static function loesche(string $pfad): void
    {
        foreach (scandir($pfad) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') { continue; }
            is_dir("{$pfad}/{$eintrag}") ? self::loesche("{$pfad}/{$eintrag}") : unlink("{$pfad}/{$eintrag}");
        }
        rmdir($pfad);
    }

    /** @return array{status:int, ort:string, text:string} */
    private function anfrage(string $pfad, ?array $daten = null): array
    {
        $kopf = $this->weitererKopf;
        if ($this->cookies !== []) {
            $kopf[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies));
        }
        $opt = ['method' => 'GET', 'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0, 'timeout' => 20];
        if ($daten !== null) {
            $opt['method']  = 'POST';
            $opt['content'] = http_build_query($daten);
            $kopf[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opt['header'] = $kopf;
        $text = (string)@file_get_contents('http://127.0.0.1:' . self::$port . '/' . $pfad, false, stream_context_create(['http' => $opt]));
        $status = 0; $ort = '';
        foreach (http_get_last_response_headers() ?? [] as $zeile) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $zeile, $m)) { $status = (int)$m[1]; }
            if (stripos($zeile, 'Location:') === 0) { $ort = trim(substr($zeile, 9)); }
            if (preg_match('/^Set-Cookie: ([^=]+)=([^;]*)/i', $zeile, $m)) { $this->cookies[$m[1]] = $m[2]; }
        }
        return ['status' => $status, 'ort' => $ort, 'text' => $text];
    }

    private function token(string $pfad): string
    {
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $this->anfrage($pfad)['text'], $m);
        return $m[1] ?? '';
    }

    private function anmelden(): void
    {
        $this->cookies = [];
        $antwort = $this->anfrage('login.php', ['csrf_token' => $this->token('login.php'), 'benutzername' => 'pruefer', 'passwort' => self::PASSWORT]);
        $this->assertSame(302, $antwort['status'], 'Anmeldung');
    }

    private function wert(string $sql): string
    {
        return (string)self::$pdo->query($sql)->fetchColumn();
    }

    /** @return array{0:int,1:int,2:string} Klient, Fall, Akten-Adresse */
    private function klientAnlegen(array $ueber = []): array
    {
        $this->anmelden();
        $antwort = $this->anfrage('aufnahme.php', array_merge([
            'csrf_token' => $this->token('aufnahme.php'), 'vorname' => 'Max', 'nachname' => 'Muster', 'geburtsdatum' => '1985-03-15',
            'postleitzahl' => '40822', 'wohnort' => 'Mettmann', 'geschlecht' => 'm', 'koerpergewicht' => '80', 'datenschutz_zugestimmt' => '1',
            'widerruf_bestaetigt' => '1', 'fuehrerscheinklasse' => ['B'], 'deliktart' => ['Alkohol'], 'blutalkoholkonzentration' => '1.8',
        ], $ueber));
        $this->assertSame(303, $antwort['status'], 'Nach dem Anlegen weiterleiten');
        $this->assertStringContainsString('erfolgreich angelegt', $this->anfrage($antwort['ort'])['text']);
        $klient = (int)$this->wert('SELECT MAX(id) FROM clients');
        $fall   = (int)$this->wert("SELECT MAX(id) FROM cases WHERE client_id = {$klient}");
        return [$klient, $fall, "akte.php?id={$klient}&case_id={$fall}"];
    }

    /** @return array<string,mixed> Felder für bearbeiten.php mit aktuellen Versionen */
    private function bearbeitenFormular(int $klient, int $fall, array $ueber = []): array
    {
        $seite = $this->anfrage("bearbeiten.php?id={$klient}&case_id={$fall}")['text'];
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $seite, $t);
        preg_match('/name="case_version"\s+value="(\d+)"/', $seite, $cv);
        preg_match('/name="client_version"\s+value="(\d+)"/', $seite, $kv);
        return array_merge(['csrf_token' => $t[1], 'case_version' => $cv[1], 'client_version' => $kv[1], 'vorname' => 'Max', 'nachname' => 'Muster',
            'geburtsdatum' => '1985-03-15', 'geschlecht' => 'm', 'klienten_status' => 'aktiv', 'deliktart' => ['Alkohol'], 'blutalkoholkonzentration' => '1.8'], $ueber);
    }

    public function testOhneAnmeldungKeinZugriff(): void
    {
        foreach (['index.php', 'klienten.php', 'finanzen.php', 'akte.php?id=1', 'bearbeiten.php?id=1', 'sitzungen.php?client_id=1', 'vertrag_druck.php?id=1', 'dsgvo_loeschen.php', 'scripts/schema_check.php'] as $seite) {
            $this->assertSame(302, $this->anfrage($seite)['status'], $seite);
            $this->assertSame(302, $this->anfrage($seite, ['action' => 'delete'])['status'], $seite);
        }
        $this->assertSame(401, $this->anfrage('triage_ajax.php?action=config')['status']);
    }

    public function testAnmeldungVertraegtKaputteFormenUndBeantwortetFalscheDatenGleich(): void
    {
        $this->assertSame(200, $this->anfrage('login.php?next[]=x')['status']);
        $this->assertSame(200, $this->anfrage('login.php?reason[]=x')['status']);
        $t = $this->token('login.php');
        $this->assertSame(403, $this->anfrage('login.php', ['csrf_token' => [$t]])['status']);
        $this->assertSame(200, $this->anfrage('login.php', ['csrf_token' => $t, 'benutzername' => ['a'], 'passwort' => ['b']])['status']);
        $a = $this->anfrage('login.php', ['csrf_token' => $t, 'benutzername' => 'pruefer', 'passwort' => 'falsch']);
        $b = $this->anfrage('login.php', ['csrf_token' => $t, 'benutzername' => 'gibtsnicht', 'passwort' => 'falsch']);
        $this->assertSame($a['status'], $b['status']);
        $this->assertSame(strlen($a['text']), strlen($b['text']));
    }

    public function testFremdeWeiterleitungNachAnmeldungWirdVerworfen(): void
    {
        foreach (['//evil.example', '/\\evil.example', 'https://evil.example/'] as $ziel) {
            $this->cookies = [];
            $t = $this->token('login.php');
            $a = $this->anfrage('login.php', ['csrf_token' => $t, 'benutzername' => 'pruefer', 'passwort' => self::PASSWORT, 'next' => $ziel]);
            $this->assertSame('index.php', $a['ort'], $ziel);
        }
    }

    /** R4-1 */
    public function testBelehrungBedeutetKeinenWiderruf(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $this->assertSame('1', $this->wert("SELECT revocation_notice_confirmed FROM clients WHERE id = {$klient}"));
        $this->assertSame('0', $this->wert("SELECT revocation_confirmed FROM clients WHERE id = {$klient}"));
        $this->assertStringNotContainsString('DSGVO-Einwilligung widerrufen', $this->anfrage($akte)['text']);
        $t = $this->token($akte);
        $this->assertSame(400, $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'dsgvo_delete'])['status']);
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM clients WHERE id = {$klient}"));
    }

    /** R4-2 */
    public function testAufnahmeMitVorMpuUnterGiftfestigkeit(): void
    {
        $this->klientAnlegen(['blutalkoholkonzentration' => '1.2', 'fruehere_untersuchungen' => '1']);
        $this->klientAnlegen(['blutalkoholkonzentration' => '1.2', 'mehrfachdelikt' => '1']);
        $this->addToAssertionCount(1);
    }

    public function testAufnahmeUndBearbeitenWeisenDieselbenFehlerAb(): void
    {
        [$klient, $fall] = $this->klientAnlegen();
        foreach ([['geburtsdatum', '2026-02-31'], ['sperrfrist_ende', '2026-13-01'], ['mpu_zielmonat', 'abc'], ['koerpergewicht', '99999'], ['geschlecht', 'x'],
                  ['tetrahydrocannabinol_wert', 'abc'], ['honorar_gesamt', '-5'], ['vorname', str_repeat('x', 101)], ['aktenzeichen', str_repeat('x', 101)]] as [$feld, $wert]) {
            $ueber = [$feld => $wert, 'deliktart' => ['Drogen']];
            $vorher = $this->wert('SELECT COUNT(*) FROM clients');
            $a = $this->anfrage('aufnahme.php', array_merge(['csrf_token' => $this->token('aufnahme.php'), 'vorname' => 'Neu', 'nachname' => 'N', 'geburtsdatum' => '1985-03-15'], $ueber));
            $this->assertSame(200, $a['status'], "Aufnahme {$feld}");
            $this->assertStringNotContainsString('Fehler beim Speichern', $a['text'], "Aufnahme {$feld}");
            $this->assertSame($vorher, $this->wert('SELECT COUNT(*) FROM clients'), "Aufnahme {$feld}");
            $b = $this->anfrage("bearbeiten.php?id={$klient}&case_id={$fall}", $this->bearbeitenFormular($klient, $fall, $ueber));
            $this->assertSame(400, $b['status'], "Bearbeiten {$feld}");
            $this->assertStringNotContainsString('Fehler beim Speichern', $b['text'], "Bearbeiten {$feld}");
        }
    }

    public function testEingabenBleibenNachFehlerErhalten(): void
    {
        $this->anmelden();
        $a = $this->anfrage('aufnahme.php', ['csrf_token' => $this->token('aufnahme.php'), 'vorname' => 'Behalten', 'nachname' => 'Wert', 'geburtsdatum' => '2026-02-31', 'wohnort' => 'Ortsname']);
        $this->assertStringContainsString('value="Behalten"', $a['text']);
        $this->assertStringContainsString('Ortsname', $a['text']);
    }

    /** Anlegen, Ändern und Löschen, jeweils mit CSRF-Sperre und Audit-Eintrag. */
    public function testAkteSchreibwegeWirkenUndWerdenProtokolliert(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $wege = [
            ['case_events', 'add_event', ['event_date' => '2026-02-01', 'event_type' => 'antrag', 'event_description' => 'x'], 'delete_event', 'event_id'],
            ['abstinence_probes', 'add_probe', ['probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ'], 'delete_probe', 'probe_id'],
            ['performance_tests', 'add_performance_test', ['test_date' => '2026-02-03', 'test_name' => 'T', 'percentile' => '30'], 'delete_performance_test', 'perf_id'],
            ['sessions', 'add_session', ['session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check'], 'delete_session', 'session_id'],
            ['case_notes', 'add_note', ['note_date' => '2026-02-05', 'note_title' => 'T', 'note_body' => 'B'], 'delete_note', 'note_id'],
        ];
        foreach ($wege as [$tabelle, $anlegen, $felder, $loeschen, $idfeld]) {
            $anzahl = fn(): string => $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}");
            $this->assertSame(403, $this->anfrage($akte, $felder + ['action' => $anlegen])['status'], "{$anlegen} ohne Token");
            $this->assertSame(403, $this->anfrage($akte, $felder + ['action' => $anlegen, 'csrf_token' => 'falsch'])['status'], "{$anlegen} falsches Token");
            $this->assertSame('0', $anzahl(), $anlegen);
            $this->assertSame(302, $this->anfrage($akte, $felder + ['action' => $anlegen, 'csrf_token' => $t])['status'], $anlegen);
            $this->assertSame('1', $anzahl(), $anlegen);
            $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM audit_log WHERE action = '{$anlegen}' AND client_id = {$klient}"), $anlegen);
            $id = $this->wert("SELECT MAX(id) FROM {$tabelle} WHERE client_id = {$klient}");
            $this->assertSame(302, $this->anfrage($akte, [$idfeld => $id, 'action' => $loeschen, 'csrf_token' => $t])['status'], $loeschen);
            $this->assertSame('0', $anzahl(), $loeschen);
            $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM audit_log WHERE action = '{$loeschen}' AND client_id = {$klient}"), $loeschen);
        }
    }

    public function testAkteAendernSpeichert(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check']);
        $id = $this->wert("SELECT MAX(id) FROM sessions WHERE client_id = {$klient}");
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'edit_session', 'session_id' => $id, 'session_date' => '2026-02-05', 'duration_min' => '90', 'phase' => 'analyse', 'sorkc_s' => 'S']);
        $this->assertSame('90', $this->wert("SELECT duration_min FROM sessions WHERE id = {$id}"));
        $this->assertSame('S', $this->wert("SELECT sorkc_s FROM sessions WHERE id = {$id}"));
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'Alt', 'note_body' => 'B']);
        $nid = $this->wert("SELECT MAX(id) FROM case_notes WHERE client_id = {$klient}");
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'edit_note', 'note_id' => $nid, 'note_date' => '2026-02-06', 'note_title' => 'Neu', 'note_body' => 'C']);
        $this->assertSame('Neu', $this->wert("SELECT title FROM case_notes WHERE id = {$nid}"));
    }

    public function testAkteWeistUngueltigeWerteOhneServerfehlerAb(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        foreach ([
            ['sessions', ['action' => 'add_session', 'session_date' => '2026-99-99', 'duration_min' => '60', 'phase' => 'check']],
            ['sessions', ['action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '5', 'phase' => 'check']],
            ['sessions', ['action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '481', 'phase' => 'check']],
            ['abstinence_probes', ['action' => 'add_probe', 'probe_date' => '2026-02-31', 'method' => 'urin', 'result' => 'negativ']],
            ['abstinence_probes', ['action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ', 'peth_value' => '1e30']],
            ['abstinence_probes', ['action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ', 'lab_name' => str_repeat('x', 151)]],
            ['performance_tests', ['action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => str_repeat('x', 101), 'percentile' => '30']],
            ['case_events', ['action' => 'add_event', 'event_date' => '2026-99-99', 'event_type' => 'antrag']],
            ['case_notes', ['action' => 'add_note', 'note_date' => '2026-99-99', 'note_title' => 't', 'note_body' => 'b']],
        ] as [$tabelle, $felder]) {
            $a = $this->anfrage($akte, $felder + ['csrf_token' => $t]);
            $this->assertSame(302, $a['status'], json_encode($felder));
            $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}"), json_encode($felder));
        }
    }

    public function testFremderFallWirdNichtBeschrieben(): void
    {
        [$klient] = $this->klientAnlegen();
        [, $fremd] = $this->klientAnlegen();
        $t = $this->token('aufnahme.php');
        $this->assertSame(400, $this->anfrage("akte.php?id={$klient}&case_id={$fremd}", ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'x', 'note_body' => 'y'])['status']);
        $this->assertSame(404, $this->anfrage("bearbeiten.php?id={$klient}&case_id={$fremd}")['status']);
    }

    /** R4-3 und Art. 17 */
    public function testExportUndLoeschungSindVollstaendig(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => 'Testname', 'percentile' => '30']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'Max Notiz', 'note_body' => 'B']);
        $json = json_decode($this->anfrage($akte, ['csrf_token' => $t, 'action' => 'dsgvo_export'])['text'], true);
        $this->assertSame('Testname', $json['leistungstests'][0]['test_name']);
        $this->assertArrayHasKey('payment_amount_paid', $json['fallakten'][0]);
        $this->assertArrayNotHasKey('audit_log', $json);

        $this->assertSame(302, $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'revocation_confirm'])['status']);
        $this->assertStringContainsString('DSGVO-Einwilligung widerrufen', $this->anfrage($akte)['text']);
        $this->assertSame(302, $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'dsgvo_delete'])['status']);
        foreach (['cases', 'sessions', 'abstinence_probes', 'client_tasks', 'case_events', 'case_notes', 'performance_tests'] as $tabelle) {
            $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}"), $tabelle);
        }
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM audit_log WHERE client_id = {$klient} AND description LIKE '%Max%'"));
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM audit_log WHERE client_id = {$klient} AND action = 'dsgvo_delete'"));
        $this->assertSame(404, $this->anfrage($akte)['status']);
    }

    /** R4-4 */
    public function testWiedereroeffnenSetztAbschlussZurueck(): void
    {
        [$klient, $fall] = $this->klientAnlegen(['vorname' => 'Frist', 'nachname' => 'Pruefung']);
        $u = "bearbeiten.php?id={$klient}&case_id={$fall}";
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['klienten_status' => 'abgeschlossen', 'vorname' => 'Frist', 'nachname' => 'Pruefung']));
        self::$pdo->exec("UPDATE cases SET closed_at = '2010-01-01' WHERE id = {$fall}");
        $this->assertStringContainsString('Pruefung, Frist', $this->anfrage('dsgvo_loeschen.php')['text']);
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['klienten_status' => 'aktiv', 'status_reopen_bestaetigt' => '1', 'vorname' => 'Frist', 'nachname' => 'Pruefung']));
        $this->assertSame('', $this->wert("SELECT closed_at FROM cases WHERE id = {$fall}"));
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['klienten_status' => 'abgeschlossen', 'vorname' => 'Frist', 'nachname' => 'Pruefung']));
        $this->assertStringNotContainsString('Pruefung, Frist', $this->anfrage('dsgvo_loeschen.php')['text']);
    }

    /** R4-5 */
    public function testVeraltetesFormularUeberschreibtNichts(): void
    {
        [$klient, $fall] = $this->klientAnlegen();
        $u = "bearbeiten.php?id={$klient}&case_id={$fall}";
        $alt = $this->bearbeitenFormular($klient, $fall, ['vorname' => 'Veraltet']);
        $t = $alt['csrf_token'];
        $zahlung = ['csrf_token' => $t, 'action' => 'update_payment', 'client_id' => $klient, 'case_id' => $fall, 'case_version' => $alt['case_version']];
        $this->assertSame(200, $this->anfrage('finanzen.php', $zahlung + ['payment_status' => 'beglichen', 'payment_amount_paid' => '100'])['status']);
        $a = $this->anfrage($u, $alt);
        $this->assertSame(409, $a['status']);
        $this->assertStringContainsString('value="Veraltet"', $a['text']);
        $this->assertSame('beglichen', $this->wert("SELECT payment_status FROM cases WHERE id = {$fall}"));
        $this->assertSame('Max', $this->wert("SELECT first_name FROM clients WHERE id = {$klient}"));
        $this->assertSame(400, $this->anfrage('finanzen.php', $zahlung + ['payment_status' => 'offen', 'payment_date' => '2026-02-31'])['status']);
    }

    /** R4-9 */
    public function testAbgelaufeneSitzungGiltAuchImAjax(): void
    {
        $this->anmelden();
        $this->assertSame(200, $this->anfrage('index.php')['status']);
        $datei = $this->sitzungsdatei();
        file_put_contents($datei, (string)preg_replace('/last_activity\|i:\d+/', 'last_activity|i:' . (time() - 7200), (string)file_get_contents($datei)));
        $this->assertSame(401, $this->anfrage('triage_ajax.php?action=config')['status']);
        $this->assertSame(302, $this->anfrage('index.php')['status']);
    }

    private function sitzungsdatei(): string
    {
        $namen = array_values(array_filter(array_keys($this->cookies), static fn(string $n): bool => str_starts_with($n, 'MPU_')));
        $this->assertCount(1, $namen, 'Die Sitzung braucht einen eigenen Cookie-Namen je Installation.');
        return self::$wurzel . '/_sitzungen/sess_' . $this->cookies[$namen[0]];
    }

    /** CC-1: Was eine andere Anwendung im selben Ablageort hinterlegt, ist keine Anmeldung. */
    public function testFremdeSitzungGiltNichtAlsAnmeldung(): void
    {
        $this->anfrage('login.php');
        $datei = $this->sitzungsdatei();
        file_put_contents($datei, 'user|s:7:"pruefer";last_activity|i:' . time() . ';');
        $this->assertSame(302, $this->anfrage('klienten.php')['status']);
        file_put_contents($datei, 'user|s:7:"pruefer";last_activity|i:' . time() . ';');
        $this->assertSame(401, $this->anfrage('triage_ajax.php?action=config')['status']);
        $this->assertSame(200, $this->anfrage('login.php')['status'], 'Keine Umleitungsschleife zwischen Anmeldung und Startseite.');
    }

    /** CC-1: Der Zugang endet mit dem Eintrag in config/users.php, auch für laufende Sitzungen. */
    public function testEntfernterOderGeaenderterNutzerVerliertSeineSitzung(): void
    {
        $datei = self::$wurzel . '/config/users.php';
        $stand = (string)file_get_contents($datei);
        try {
            $this->anmelden();
            $this->assertSame(200, $this->anfrage('klienten.php')['status']);
            file_put_contents($datei, '<?php return [];');
            $this->assertSame(302, $this->anfrage('klienten.php')['status'], 'entfernt');
            file_put_contents($datei, $stand);

            $this->anmelden();
            $this->assertSame(200, $this->anfrage('klienten.php')['status']);
            file_put_contents($datei, '<?php return ' . var_export(['pruefer' => ['hash' => password_hash('anderes', PASSWORD_BCRYPT, ['cost' => 4]), 'display_name' => 'Pruefer']], true) . ';');
            $this->assertSame(401, $this->anfrage('triage_ajax.php?action=config')['status'], 'Passwort geändert');
        } finally {
            file_put_contents($datei, $stand);
        }
    }

    /** CC-15 und CC-28: Soll-Anzeige und Warnungen aus der Seite, nicht aus der Hilfsfunktion. */
    public function testUrinprogrammZaehltGegenDasSollOhneLuecke(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        self::$pdo->exec("UPDATE cases SET abstinenz_monate = 6 WHERE id = {$fall}");
        $t = $this->token($akte);
        $start = new DateTimeImmutable('-20 days');
        $probe = fn(int $vor, array $mehr = []) => $this->anfrage($akte, $mehr + ['csrf_token' => $t, 'action' => 'add_probe', 'probe_date' => $start->modify("-{$vor} days")->format('Y-m-d'), 'method' => 'urin', 'result' => 'negativ']);
        $probe(42);
        $probe(0);
        $seite = $this->anfrage($akte)['text'];
        $this->assertStringNotContainsString('Lücke(n) erkannt', $seite);
        $this->assertStringNotContainsString('Abstinenz-Lücke erkannt', $seite);
        $this->assertMatchesRegularExpression('/>2\/4</', preg_replace('/\s+/', '', $seite) ?? '');

        $probe(10, ['kreatinin_mg_dl' => '1.2']);
        $this->assertMatchesRegularExpression('/>2\/4</', preg_replace('/\s+/', '', $this->anfrage($akte)['text']) ?? '', 'Verdünnt zählt nicht');
        $probe(11, ['kreatinin_mg_dl' => '20']);
        $this->assertMatchesRegularExpression('/>3\/4</', preg_replace('/\s+/', '', $this->anfrage($akte)['text']) ?? '', 'Verwertbar zählt');
    }

    /** CC-19: Was die Triage braucht, bleibt beim Bearbeiten erhalten, und AJAX rechnet wie der Handler. */
    public function testTriageEingabenBleibenUndRechnenGleich(): void
    {
        $felder = ['deliktart' => ['Drogen'], 'drogenart' => ['Kokain'], 'blutalkoholkonzentration' => '', 'persoenlichkeit' => '1', 'erschwerend_faktoren' => '1', 'therapie_art' => 'ambulant', 'medizinal_beikonsum' => '1'];
        [$klient, $fall] = $this->klientAnlegen($felder);
        $vorher = $this->wert("SELECT suggested_hypothesis FROM cases WHERE id = {$fall}");
        $this->assertStringContainsString('V1', $vorher);
        $this->assertSame('15', $this->wert("SELECT abstinenz_monate FROM cases WHERE id = {$fall}"));
        $this->assertSame('1', $this->wert("SELECT therapy_completed FROM cases WHERE id = {$fall}"));

        // Die Seite muss die Werte vorbelegen. Also nur das schicken, was als gesetzt ausgeliefert wurde.
        $seite = $this->anfrage("bearbeiten.php?id={$klient}&case_id={$fall}")['text'];
        $gesetzt = [];
        foreach (['persoenlichkeit', 'erschwerend_faktoren', 'medizinal_beikonsum'] as $feld) {
            if (preg_match('/<input[^>]*name="' . $feld . '"[^>]*\bchecked\b/', $seite) === 1) {
                $gesetzt[$feld] = '1';
            }
        }
        $this->assertCount(3, $gesetzt, 'Vorbelegung fehlt');
        $this->assertSame(1, preg_match('/<option value="ambulant" selected/', $seite));
        $u = "bearbeiten.php?id={$klient}&case_id={$fall}";
        $a = $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, $gesetzt + ['deliktart' => ['Drogen'], 'drogenart' => ['Kokain'], 'blutalkoholkonzentration' => '', 'therapie_art' => 'ambulant']));
        $this->assertSame(302, $a['status']);
        $this->assertSame($vorher, $this->wert("SELECT suggested_hypothesis FROM cases WHERE id = {$fall}"));

        $t = $this->token($u);
        $json = json_decode($this->anfrage('triage_ajax.php', $felder + ['csrf_token' => $t])['text'], true);
        $this->assertSame((int)$this->wert("SELECT abstinenz_monate FROM cases WHERE id = {$fall}"), $json['abstinenz_monate']);
        $this->assertSame($vorher, $json['hypothese_text']);
    }

    /** CC-20 */
    public function testOverrideBestimmtDasSoll(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen(['blutalkoholkonzentration' => '2.6', 'override_aktiv' => '1', 'override_begruendung' => 'Prüfung', 'override_hypothese' => 'Hypothese A4 (Trennungsvermögen)', 'override_abstinenz' => '6 Monate']);
        $this->assertSame('6', $this->wert("SELECT abstinenz_monate FROM cases WHERE id = {$fall}"));
        $seite = preg_replace('/\s+/', '', $this->anfrage($akte)['text']) ?? '';
        $this->assertMatchesRegularExpression('/>0\/4</', $seite);
        $this->assertStringNotContainsString('BK-5 Alarm', $this->anfrage($akte)['text']);
        $this->assertStringContainsString('Berater-Override aktiv', $this->anfrage($akte)['text']);

        $u = "bearbeiten.php?id={$klient}&case_id={$fall}";
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['blutalkoholkonzentration' => '2.6', 'override_aktiv' => '1', 'override_begruendung' => 'Prüfung', 'override_hypothese' => 'Hypothese A3 (Alkoholgefährdung)', 'override_abstinenz' => '12 Monate']));
        $this->assertSame('12', $this->wert("SELECT abstinenz_monate FROM cases WHERE id = {$fall}"));
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['blutalkoholkonzentration' => '2.6', 'override_aktiv' => '1', 'override_begruendung' => 'Prüfung', 'override_hypothese' => 'Hypothese A3 (Alkoholgefährdung)', 'override_abstinenz' => 'Keine (Einstellungscheck)']));
        $this->assertSame('0', $this->wert("SELECT abstinenz_monate FROM cases WHERE id = {$fall}"));
    }

    /** CC-18: Beide Zeilen stehen auf demselben Unterschriftsdokument. */
    public function testStrategieDokumentPasstZurEmpfehlung(): void
    {
        foreach ([
            ['Kein Abstinenzgebot', ['deliktart' => ['Punkte'], 'punkte_flensburg' => '8', 'blutalkoholkonzentration' => '']],
            ['kontrollierter Konsum', ['blutalkoholkonzentration' => '0.8']],
            ['(zwingend)', ['blutalkoholkonzentration' => '2.6']],
            ['kein kontrollierter Konsum', ['blutalkoholkonzentration' => '0.8', 'bkf_status' => 'aktiv', 'fuehrerscheinklasse' => ['C']]],
        ] as [$erwartet, $ueber]) {
            [$klient] = $this->klientAnlegen($ueber);
            $this->assertStringContainsString($erwartet, $this->anfrage("strategie_druck.php?id={$klient}")['text'], json_encode($ueber));
        }
    }

    /** CC-16 */
    public function testThcCoohHeisstBlutserum(): void
    {
        $this->anmelden();
        $this->assertStringContainsString('THC-COOH (Blutserum)', $this->anfrage('aufnahme.php')['text']);
        [$klient, $fall] = $this->klientAnlegen();
        $this->assertStringContainsString('THC-COOH Blutserum', $this->anfrage("bearbeiten.php?id={$klient}&case_id={$fall}")['text']);
    }

    /** CC-45 */
    public function testNeuladenNachAufnahmeLegtNichtsNeuAn(): void
    {
        $this->klientAnlegen();
        $vorher = $this->wert('SELECT COUNT(*) FROM clients');
        $seite = $this->anfrage('aufnahme.php')['text'];
        $this->assertStringNotContainsString('erfolgreich angelegt', $seite, 'Erfolgskarte nur einmal');
        $this->assertStringContainsString('name="nachname"', $seite);
        $this->assertSame($vorher, $this->wert('SELECT COUNT(*) FROM clients'));
    }

    /** Lässt das Protokoll scheitern, wie es eine Störung der Datenbank täte. */
    private function mitGestoertemProtokoll(callable $pruefung): void
    {
        self::$pdo->exec("CREATE TRIGGER stoerung BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Testabbruch'");
        try {
            $pruefung();
        } finally {
            self::$pdo->exec('DROP TRIGGER stoerung');
        }
    }

    /** CC-43 */
    public function testFormularBleibtWennDieAufnahmeScheitert(): void
    {
        $this->anmelden();
        $t = $this->token('aufnahme.php');
        $vorher = $this->wert('SELECT COUNT(*) FROM clients');
        $this->mitGestoertemProtokoll(function () use ($t, $vorher): void {
            $a = $this->anfrage('aufnahme.php', ['csrf_token' => $t, 'vorname' => 'Bleibt', 'nachname' => 'Stehen', 'geburtsdatum' => '1985-03-15', 'deliktart' => ['Alkohol'], 'blutalkoholkonzentration' => '1.8']);
            $this->assertStringContainsString('Fehler beim Speichern', $a['text']);
            $this->assertStringContainsString('name="nachname"', $a['text']);
            $this->assertStringContainsString('value="Bleibt"', $a['text']);
            $this->assertSame($vorher, $this->wert('SELECT COUNT(*) FROM clients'));
        });
    }

    /** CC-44: Ohne Protokolleintrag wird nichts geschrieben. */
    public function testOhneProtokollKeineAenderung(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'T', 'note_body' => 'B']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_event', 'event_date' => '2026-02-01', 'event_type' => 'antrag']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => 'T', 'percentile' => '30']);
        $id = fn(string $tabelle): string => $this->wert("SELECT MAX(id) FROM {$tabelle} WHERE client_id = {$klient}");
        $wege = [
            ['case_events', ['action' => 'add_event', 'event_date' => '2026-02-01', 'event_type' => 'antrag']],
            ['case_events', ['action' => 'delete_event', 'event_id' => $id('case_events')]],
            ['abstinence_probes', ['action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ']],
            ['abstinence_probes', ['action' => 'delete_probe', 'probe_id' => $id('abstinence_probes')]],
            ['performance_tests', ['action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => 'T', 'percentile' => '30']],
            ['performance_tests', ['action' => 'delete_performance_test', 'perf_id' => $id('performance_tests')]],
            ['sessions', ['action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check']],
            ['sessions', ['action' => 'delete_session', 'session_id' => $id('sessions')]],
            ['case_notes', ['action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'T', 'note_body' => 'B']],
            ['case_notes', ['action' => 'delete_note', 'note_id' => $id('case_notes')]],
        ];
        $sitzung = "sitzungen.php?client_id={$klient}";
        $this->mitGestoertemProtokoll(function () use ($wege, $akte, $t, $klient, $sitzung): void {
            foreach ($wege as [$tabelle, $felder]) {
                $vorher = $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}");
                $this->assertSame(500, $this->anfrage($akte, $felder + ['csrf_token' => $t])['status'], $felder['action']);
                $this->assertSame($vorher, $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}"), $felder['action']);
            }
            $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'revocation_confirm']);
            $this->assertSame('0', $this->wert("SELECT revocation_confirmed FROM clients WHERE id = {$klient}"));
            $vorher = $this->wert("SELECT COUNT(*) FROM sessions WHERE client_id = {$klient}");
            $this->anfrage($sitzung, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check']);
            $this->assertSame($vorher, $this->wert("SELECT COUNT(*) FROM sessions WHERE client_id = {$klient}"), 'Sitzungsseite');
        });
    }

    /** CC-46 */
    public function testZahlungTrifftDenGesehenenFallUndStand(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen(['honorar_gesamt' => '1000']);
        $seite = $this->anfrage('finanzen.php')['text'];
        $this->assertSame(1, preg_match('/data-client-id="' . $klient . '"\s+data-case-id="(\d+)"\s+data-case-version="(\d+)"/', $seite, $m));
        $t = $this->token('aufnahme.php');
        $senden = fn(array $mehr): array => $this->anfrage('finanzen.php', $mehr + ['csrf_token' => $t, 'action' => 'update_payment', 'client_id' => $klient, 'case_id' => $m[1], 'case_version' => $m[2], 'payment_status' => 'teilweise', 'payment_amount_paid' => '100']);

        $this->assertSame(400, $this->anfrage('finanzen.php', ['csrf_token' => $t, 'action' => 'update_payment', 'client_id' => $klient, 'payment_status' => 'offen'])['status']);
        $this->assertSame(200, $senden([])['status']);
        $this->assertSame(409, $senden([])['status'], 'Zweiter Tab mit altem Stand');
        $this->assertSame(409, $senden(['client_id' => 999999])['status'], 'Falscher Klient');

        preg_match('/name="action" value="new_case">\s*<input[^>]*name="csrf_token" value="([^"]+)">\s*<input[^>]*name="(\w+)" value="(\d+)">/', $this->anfrage($akte)['text'], $n);
        $this->anfrage($akte, ['action' => 'new_case', 'csrf_token' => $n[1], $n[2] => $n[3]]);
        $this->assertSame(409, $senden(['case_version' => $this->wert("SELECT version FROM cases WHERE id = {$fall}") . '9'])['status']);
        $neu = $this->wert("SELECT id FROM cases WHERE client_id = {$klient} AND status = 'aktiv'");
        $this->assertSame('offen', $this->wert("SELECT COALESCE(payment_status, 'offen') FROM cases WHERE id = {$neu}"));
    }

    /** CC-50 */
    public function testAbgewiesenesAkteFormularBehaeltEingaben(): void
    {
        [, , $akte] = $this->klientAnlegen();
        $seite = $this->anfrage($akte)['text'];
        $this->assertStringContainsString('name="duration_min" value="60" min="15"', $seite);
        $this->assertStringNotContainsString('name="duration_min" min="1"', $seite);
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '5', 'phase' => 'check', 'sorkc_s' => 'Merkwert-Sitzung']);
        $this->assertStringContainsString('Merkwert-Sitzung', $this->anfrage($akte)['text']);
        $this->assertStringNotContainsString('Merkwert-Sitzung', $this->anfrage($akte)['text'], 'Nur einmal');
        foreach ([['add_probe', ['probe_date' => '2026-99-99', 'method' => 'urin', 'result' => 'negativ', 'lab_name' => 'Merkwert-Labor'], 'Merkwert-Labor'],
                  ['add_event', ['event_date' => '2026-99-99', 'event_type' => 'antrag', 'event_description' => 'Merkwert-Ereignis'], 'Merkwert-Ereignis'],
                  ['add_performance_test', ['test_date' => '2026-99-99', 'test_name' => 'Merkwert-Test', 'percentile' => '30'], 'Merkwert-Test']] as [$aktion, $felder, $marke]) {
            $this->anfrage($akte, $felder + ['csrf_token' => $t, 'action' => $aktion]);
            $this->assertStringContainsString($marke, $this->anfrage($akte)['text'], $aktion);
        }
    }

    /** CC-8 */
    public function testProtokollEnthaeltKeineInhalte(): void
    {
        [$klient, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'positiv', 'lab_name' => 'LABGEHEIM']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'TITELGEHEIM', 'note_body' => 'B']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => 'TESTGEHEIM', 'percentile' => '30']);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM audit_log WHERE client_id = {$klient} AND (description LIKE '%GEHEIM%' OR description LIKE '%positiv%')"));
    }

    /** CC-5 */
    public function testSperreZaehltVorDerPruefungUndNurDieAdresse(): void
    {
        $falsch = fn(string $adresse): array => $this->anfrageVon($adresse, ['benutzername' => 'pruefer', 'passwort' => 'falsch']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $falsch('198.51.100.1')['status'], "Versuch {$i}");
        }
        $this->assertSame(429, $this->anfrageVon('198.51.100.1', ['benutzername' => 'pruefer', 'passwort' => self::PASSWORT])['status'], 'Gesperrt auch mit richtigem Passwort');
        $this->assertSame(302, $this->anfrageVon('198.51.100.2', ['benutzername' => 'pruefer', 'passwort' => self::PASSWORT])['status'], 'Andere Adresse');

        self::$pdo->exec('DELETE FROM login_attempts');
        $this->assertSame(302, $this->anfrageVon('198.51.100.3', ['benutzername' => 'pruefer', 'passwort' => self::PASSWORT])['status']);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '198.51.100.3'"), 'Gelungene Anmeldung zählt nicht');
        self::$pdo->exec('DELETE FROM login_attempts');
        $falsch('198.51.100.4');
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '198.51.100.4'"));
    }

    /** CC-5: Gleichzeitige Versuche dürfen die Sperre nicht überholen. Eigene Sitzungen, sonst reiht PHP sie ein. */
    public function testGleichzeitigeVersucheUeberholenDieSperreNicht(): void
    {
        $adresse = '198.51.100.7';
        $griffe = [];
        $mehrfach = curl_multi_init();
        for ($i = 0; $i < 20; $i++) {
            $this->cookies = [];
            $token = $this->token('login.php');
            $griff = curl_init('http://127.0.0.1:' . self::$port . '/login.php');
            curl_setopt_array($griff, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $token, 'benutzername' => 'langsam', 'passwort' => 'falsch']),
                CURLOPT_HTTPHEADER => ["X-Forwarded-For: {$adresse}", 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($this->cookies), $this->cookies))]]);
            curl_multi_add_handle($mehrfach, $griff);
            $griffe[] = $griff;
        }
        do {
            curl_multi_exec($mehrfach, $laufend);
            curl_multi_select($mehrfach, 0.1);
        } while ($laufend > 0);
        foreach ($griffe as $griff) {
            curl_multi_remove_handle($mehrfach, $griff);
        }
        curl_multi_close($mehrfach);
        $fehlversuche = (int)$this->wert("SELECT COUNT(*) FROM auth_log WHERE ip_address = '{$adresse}' AND event_type = 'login_fail'");
        // Null ist richtig: Treffen alle zugleich ein, zählt jeder schon die anderen mit und wird gesperrt.
        $this->assertGreaterThan(0, (int)$this->wert("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '{$adresse}'"), 'Die Versuche müssen angekommen sein');
        $this->assertLessThanOrEqual(5, $fehlversuche);
    }

    /** Anmeldeversuch hinter dem vertrauten Testproxy (Loopback). */
    private function anfrageVon(string $adresse, array $felder): array
    {
        $this->cookies = [];
        $this->weitererKopf = ["X-Forwarded-For: {$adresse}"];
        try {
            return $this->anfrage('login.php', $felder + ['csrf_token' => $this->token('login.php')]);
        } finally {
            $this->weitererKopf = [];
        }
    }

    /** CC-5: Ohne Datenbank keine Sperre, also auch keine Anmeldung. */
    public function testOhneDatenbankKeineAnmeldung(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $rohre = [];
        $server = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'session.save_path=' . self::$wurzel . '/_sitzungen', '-S', '127.0.0.1:' . $port, '-t', self::$wurzel],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rohre, self::$wurzel, ['DB_PASS' => 'falsches-passwort'] + self::$umgebung
        );
        try {
            for ($i = 0; $i < 100 && ($v = @fsockopen('127.0.0.1', $port, $nr, $text, 0.1)) === false; $i++) { usleep(50000); }
            $kontext = ['http' => ['ignore_errors' => true, 'timeout' => 20]];
            $seite = (string)@file_get_contents("http://127.0.0.1:{$port}/login.php", false, stream_context_create($kontext));
            $this->assertStringContainsString(' 503', (string)(http_get_last_response_headers()[0] ?? ''));
            $this->assertStringContainsString('nicht erreichbar', $seite);
            preg_match('/^Set-Cookie: ([^=]+=[^;]*)/mi', implode("\n", http_get_last_response_headers() ?? []), $c);
            preg_match('/name="csrf_token"\s+value="([^"]+)"/', $seite, $t);
            $kontext['http'] += ['method' => 'POST', 'follow_location' => 0, 'header' => ["Cookie: {$c[1]}", 'Content-Type: application/x-www-form-urlencoded'],
                'content' => http_build_query(['csrf_token' => $t[1], 'benutzername' => 'pruefer', 'passwort' => self::PASSWORT])];
            @file_get_contents("http://127.0.0.1:{$port}/login.php", false, stream_context_create($kontext));
            $this->assertStringContainsString(' 503', (string)(http_get_last_response_headers()[0] ?? ''), 'Richtiges Passwort hilft nicht');
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /** CC-8 */
    public function testFehleingabeLandetNichtImAuthLog(): void
    {
        $this->anfrageVon('198.51.100.5', ['benutzername' => 'GEHEIMESPASSWORT', 'passwort' => 'x']);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM auth_log WHERE username LIKE '%GEHEIM%'"));
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM auth_log WHERE ip_address = '198.51.100.5' AND event_type = 'login_fail' AND username IS NULL"));
        $this->anfrageVon('198.51.100.6', ['benutzername' => 'pruefer', 'passwort' => 'x']);
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM auth_log WHERE ip_address = '198.51.100.6' AND username = 'pruefer'"));
    }

    /** CC-8 */
    public function testBereinigungKenntEineFristFuerDasProtokoll(): void
    {
        self::$pdo->exec("INSERT INTO audit_log (action, table_name, record_id, created_at) VALUES ('alt', 't', 1, '2001-01-01 00:00:00'), ('neu', 't', 1, NOW())");
        $rohre = [];
        $ablauf = proc_open([PHP_BINARY, self::$wurzel . '/scripts/cleanup.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rohre, self::$wurzel, self::$umgebung);
        stream_get_contents($rohre[1]);
        $this->assertSame(0, proc_close($ablauf));
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM audit_log WHERE action = 'alt'"));
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM audit_log WHERE action = 'neu'"));
    }

    /** CC-11 */
    public function testExportOrdnetEintraegeDemFallZu(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ']);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'T', 'note_body' => 'B']);
        $json = json_decode($this->anfrage($akte, ['csrf_token' => $t, 'action' => 'dsgvo_export'])['text'], true);
        $this->assertSame($fall, (int)$json['fallakten'][0]['fall_id']);
        $this->assertSame($fall, (int)$json['abstinenz_nachweise'][0]['fall_id']);
        $this->assertArrayNotHasKey('exportiert_von', $json['_meta']);
    }

    /** CC-33 */
    public function testDashboardZeigtNurFaelleMitHandlungsbedarf(): void
    {
        $frist = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
        [$klient, $fall] = $this->klientAnlegen(['nachname' => 'Gestelltname', 'sperrfrist_ende' => $frist]);
        self::$pdo->exec("UPDATE cases SET antrag_status = 'gestellt' WHERE id = {$fall}");
        [, $fall2] = $this->klientAnlegen(['nachname' => 'Abgeschlossenname', 'sperrfrist_ende' => $frist]);
        self::$pdo->exec("UPDATE cases SET status = 'abgeschlossen', closed_at = NOW() WHERE id = {$fall2}");
        $this->klientAnlegen(['nachname' => 'Offenname', 'sperrfrist_ende' => $frist]);
        $seite = $this->anfrage('index.php')['text'];
        $start = (int)strpos($seite, 'Dringende Frist');
        $seite = substr($seite, $start, (int)strpos($seite, 'card-title', $start + 20) - $start);
        $this->assertStringContainsString('Offenname', $seite);
        $this->assertStringNotContainsString('Abgeschlossenname', $seite);
        $this->assertStringNotContainsString('Gestelltname', $seite);
    }

    /** CC-30: Das Register kann über 8 Punkte liegen. */
    public function testMehrAlsAchtPunkteSindErfassbar(): void
    {
        [, $fall] = $this->klientAnlegen(['deliktart' => ['Punkte'], 'blutalkoholkonzentration' => '', 'punkte_flensburg' => '12']);
        $this->assertSame('12', $this->wert("SELECT flensburg_points FROM cases WHERE id = {$fall}"));
    }

    /** CC-52 */
    public function testDruckKnopfHatEinenHandler(): void
    {
        [$klient] = $this->klientAnlegen();
        foreach (['vertrag_druck.php', 'datenschutz_druck.php', 'widerruf_druck.php', 'strategie_druck.php'] as $seite) {
            $text = $this->anfrage("{$seite}?id={$klient}")['text'];
            $this->assertStringContainsString('id="btn-print"', $text, $seite);
            $this->assertStringContainsString("getElementById('btn-print').addEventListener", $text, $seite);
        }
    }

    /** CC-49: Fremder Fall in der Adresse, alle fünf Schreibwege der Akte. */
    public function testSchreibwegeWeisenFremdenFallAb(): void
    {
        [$klient] = $this->klientAnlegen();
        [, $fremd] = $this->klientAnlegen();
        $u = "akte.php?id={$klient}&case_id={$fremd}";
        $t = $this->token('aufnahme.php');
        foreach ([
            ['case_events', ['action' => 'add_event', 'event_date' => '2026-02-01', 'event_type' => 'antrag']],
            ['abstinence_probes', ['action' => 'add_probe', 'probe_date' => '2026-02-02', 'method' => 'urin', 'result' => 'negativ']],
            ['performance_tests', ['action' => 'add_performance_test', 'test_date' => '2026-02-03', 'test_name' => 'T', 'percentile' => '30']],
            ['sessions', ['action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check']],
            ['case_notes', ['action' => 'add_note', 'note_date' => '2026-02-05', 'note_title' => 'T', 'note_body' => 'B']],
        ] as [$tabelle, $felder]) {
            $this->assertSame(400, $this->anfrage($u, $felder + ['csrf_token' => $t])['status'], $felder['action']);
            $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM {$tabelle} WHERE client_id = {$klient}"), $felder['action']);
        }
    }

    /** CC-49: Jeder Löschweg entfernt die Freitexte aus dem Protokoll. */
    public function testLoeschenBereinigtDasProtokoll(): void
    {
        [$klient, , $akte] = $this->klientAnlegen(['vorname' => 'Protokollname']);
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'delete']);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM clients WHERE id = {$klient}"));
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM audit_log WHERE client_id = {$klient} AND description LIKE '%Protokollname%'"), 'Einzellöschung');

        [$klient2, $fall2] = $this->klientAnlegen(['vorname' => 'Fristname']);
        self::$pdo->exec("UPDATE cases SET status = 'abgeschlossen', closed_at = '2000-01-01' WHERE id = {$fall2}");
        $this->assertStringContainsString('Fristname', $this->anfrage('dsgvo_loeschen.php')['text']);
        $this->anfrage('dsgvo_loeschen.php', ['csrf_token' => $this->token('aufnahme.php'), 'action' => 'execute', 'client_ids' => [$klient2]]);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM clients WHERE id = {$klient2}"), 'Fristlöschung');
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM audit_log WHERE client_id = {$klient2} AND description LIKE '%Fristname%'"));
    }

    /** CC-49: Die Sitzungskennung wechselt bei der Anmeldung. */
    public function testAnmeldungWechseltDieSitzungskennung(): void
    {
        $this->cookies = [];
        $t = $this->token('login.php');
        $vorher = $this->cookies;
        $this->anfrage('login.php', ['csrf_token' => $t, 'benutzername' => 'pruefer', 'passwort' => self::PASSWORT]);
        $this->assertNotSame($vorher, $this->cookies);
        $this->assertSame(200, $this->anfrage('klienten.php')['status']);
    }

    /** CC-49: Der Export ist nicht nur vorhanden, sondern gefüllt. */
    public function testExportEnthaeltKorrespondenzUndAufgaben(): void
    {
        [, , $akte] = $this->klientAnlegen();
        $t = $this->token($akte);
        $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'add_event', 'event_date' => '2026-02-01', 'event_type' => 'antrag', 'event_description' => 'Exportmarke']);
        $json = json_decode($this->anfrage($akte, ['csrf_token' => $t, 'action' => 'dsgvo_export'])['text'], true);
        $this->assertSame('Exportmarke', $json['korrespondenz'][0]['description']);
        $this->assertArrayHasKey('aufgaben', $json);
    }

    /** CC-49: THC-Werte ohne Drogendelikt werden beim Bearbeiten nicht gespeichert. */
    public function testThcWerteNurBeiDrogendelikt(): void
    {
        [$klient, $fall] = $this->klientAnlegen();
        $u = "bearbeiten.php?id={$klient}&case_id={$fall}";
        $this->anfrage($u, $this->bearbeitenFormular($klient, $fall, ['tetrahydrocannabinol_wert' => '9', 'thc_cooh_value' => '200']));
        $this->assertSame('', $this->wert("SELECT thc_cooh_value FROM cases WHERE id = {$fall}"));
        $this->assertSame('', $this->wert("SELECT thc_value FROM cases WHERE id = {$fall}"));
    }

    /** CC-4 */
    public function testTelefonaufnahmeOhneEinwilligungLaesstSichNachtragen(): void
    {
        [$klient, , $akte] = $this->klientAnlegen(['datenschutz_zugestimmt' => null]);
        $this->assertSame('0', $this->wert("SELECT dsgvo_consent FROM clients WHERE id = {$klient}"));
        $seite = $this->anfrage($akte)['text'];
        $this->assertStringContainsString('name="consent_date"', $seite, 'Warnband mit Formular');
        $this->assertStringContainsString('Einwilligung zum Datenschutz fehlt – unterzeichnen', $seite, 'Nächster Schritt');
        $t = $this->token($akte);

        $this->assertSame(403, $this->anfrage($akte, ['action' => 'consent_set', 'consent_date' => '2026-02-01'])['status']);
        foreach (['2026-02-31', '2999-01-01', ''] as $falsch) {
            $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'consent_set', 'consent_date' => $falsch]);
            $this->assertSame('0', $this->wert("SELECT dsgvo_consent FROM clients WHERE id = {$klient}"), $falsch);
        }
        $this->assertSame(302, $this->anfrage($akte, ['csrf_token' => $t, 'action' => 'consent_set', 'consent_date' => '2026-02-01'])['status']);
        $this->assertSame('2026-02-01', $this->wert("SELECT dsgvo_consent_date FROM clients WHERE id = {$klient} AND dsgvo_consent = 1"));
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM audit_log WHERE action = 'consent_set' AND client_id = {$klient}"));
        $this->assertStringNotContainsString('Einwilligung zum Datenschutz fehlt', $this->anfrage($akte)['text']);
        $this->assertStringContainsString('01.02.2026', $this->anfrage($akte)['text']);
    }

    /** CC-4: Das Formular darf die Aufnahme am Telefon nicht im Browser verhindern. */
    public function testAufnahmeformularVerlangtKeineEinwilligung(): void
    {
        $this->anmelden();
        $seite = $this->anfrage('aufnahme.php')['text'];
        foreach (['datenschutz_zugestimmt', 'aufklaerung_strategie', 'aufklaerung_gutachter'] as $feld) {
            $this->assertSame(1, preg_match('/<input[^>]*name="' . $feld . '"[^>]*>/', $seite, $m), $feld);
            $this->assertStringNotContainsString('required', $m[0], $feld);
        }
    }

    /** CC-40: Formularfelder stammen aus der ausgelieferten Seite. */
    public function testNeueFallakteLegtGenauEinenFallAn(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        $seite = $this->anfrage($akte)['text'];
        $this->assertSame(1, preg_match('/name="action" value="new_case">\s*<input[^>]*name="csrf_token" value="([^"]+)">\s*<input[^>]*name="(\w+)" value="(\d+)">/', $seite, $m));
        $felder = ['action' => 'new_case', 'csrf_token' => $m[1], $m[2] => $m[3]];

        $a = $this->anfrage($akte, $felder);
        $this->assertSame(302, $a['status']);
        $neu = (int)$this->wert("SELECT id FROM cases WHERE client_id = {$klient} AND status = 'aktiv'");
        $this->assertNotSame($fall, $neu);
        $this->assertStringContainsString("case_id={$neu}", $a['ort']);
        $this->assertSame('abgeschlossen', $this->wert("SELECT status FROM cases WHERE id = {$fall}"));

        $doppelt = $this->anfrage($akte, $felder);
        $this->assertSame(302, $doppelt['status']);
        $this->assertSame('2', $this->wert("SELECT COUNT(*) FROM cases WHERE client_id = {$klient}"), 'Doppel-Absenden');
        $this->assertStringContainsString('keine weitere Fallakte', $this->anfrage("akte.php?id={$klient}")['text']);
    }

    /** CC-40: Auch die Ansicht eines archivierten Falls legt einen neuen an. */
    public function testNeueFallakteAusArchivierterAnsicht(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        preg_match('/name="action" value="new_case">\s*<input[^>]*name="csrf_token" value="([^"]+)">\s*<input[^>]*name="(\w+)" value="(\d+)">/', $this->anfrage($akte)['text'], $m);
        $this->anfrage($akte, ['action' => 'new_case', 'csrf_token' => $m[1], $m[2] => $m[3]]);
        preg_match('/name="action" value="new_case">\s*<input[^>]*name="csrf_token" value="([^"]+)">\s*<input[^>]*name="(\w+)" value="(\d+)">/', $this->anfrage($akte)['text'], $m);
        $this->assertSame(302, $this->anfrage($akte, ['action' => 'new_case', 'csrf_token' => $m[1], $m[2] => $m[3]])['status']);
        $this->assertSame('3', $this->wert("SELECT COUNT(*) FROM cases WHERE client_id = {$klient}"));
        $this->assertSame('1', $this->wert("SELECT COUNT(*) FROM cases WHERE client_id = {$klient} AND status = 'aktiv'"));
    }

    /** R4-10 und Sitzungen */
    public function testUnbekannterKlientErgibt404(): void
    {
        $this->anmelden();
        $t = $this->token('aufnahme.php');
        $this->assertSame(404, $this->anfrage('akte.php?id=999999')['status']);
        $this->assertSame(404, $this->anfrage('akte.php?id=999999', ['csrf_token' => $t, 'action' => 'add_note'])['status']);
        $this->assertSame(404, $this->anfrage('sitzungen.php?client_id=999999', ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check'])['status']);
        $this->assertSame(404, $this->anfrage('vertrag_druck.php?id=999999')['status']);
    }

    public function testSitzungsseiteAnlegenAendernLoeschen(): void
    {
        [$klient] = $this->klientAnlegen();
        $u = "sitzungen.php?client_id={$klient}";
        $t = $this->token($u);
        $this->assertSame(403, $this->anfrage($u, ['action' => 'add_session'])['status']);
        $this->assertSame(302, $this->anfrage($u, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-04', 'duration_min' => '60', 'phase' => 'check', 'notes' => 'N'])['status']);
        $id = $this->wert("SELECT MAX(id) FROM sessions WHERE client_id = {$klient}");
        $this->assertSame(302, $this->anfrage($u, ['csrf_token' => $t, 'action' => 'edit_session', 'session_id' => $id, 'session_date' => '2026-02-05', 'duration_min' => '90', 'phase' => 'analyse'])['status']);
        $this->assertSame('90', $this->wert("SELECT duration_min FROM sessions WHERE id = {$id}"));
        $this->assertSame(302, $this->anfrage($u, ['csrf_token' => $t, 'action' => 'delete_session', 'session_id' => $id])['status']);
        $this->assertSame('0', $this->wert("SELECT COUNT(*) FROM sessions WHERE client_id = {$klient}"));
        $this->assertSame(200, $this->anfrage($u, ['csrf_token' => $t, 'action' => 'add_session', 'session_date' => '2026-02-31', 'duration_min' => '60', 'phase' => 'check'])['status']);
    }

    public function testDruckseitenZeigenPlatzhalterUndWerteOhneRohesHtml(): void
    {
        [$klient] = $this->klientAnlegen(['vorname' => '<script>m</script>', 'honorar_gesamt' => '1500']);
        foreach (['vertrag_druck.php', 'datenschutz_druck.php', 'widerruf_druck.php', 'strategie_druck.php', 'akte_druck.php'] as $seite) {
            $a = $this->anfrage("{$seite}?id={$klient}");
            $this->assertSame(200, $a['status'], $seite);
            $this->assertStringNotContainsString('<script>m</script>', $a['text'], $seite);
        }
        $this->assertStringContainsString('1.500,00', $this->anfrage("vertrag_druck.php?id={$klient}")['text']);
        $this->assertStringContainsString('Vertragstext einfügen', $this->anfrage("vertrag_druck.php?id={$klient}")['text']);
        $strategie = $this->anfrage("strategie_druck.php?id={$klient}")['text'];
        $this->assertStringContainsString('Vereinbarungstext einfügen', $strategie);
    }

    public function testAlleSeitenAntwortenMitKlientenbestand(): void
    {
        [$klient, $fall, $akte] = $this->klientAnlegen();
        foreach (['index.php', 'klienten.php', 'klienten.php?q=Max', 'aufnahme.php', 'anleitung.php', 'klienten_leitfaden.php', 'ressourcen.php', 'finanzen.php', 'dsgvo_loeschen.php', 'scripts/schema_check.php', $akte, "bearbeiten.php?id={$klient}&case_id={$fall}", "sitzungen.php?client_id={$klient}"] as $seite) {
            $this->assertSame(200, $this->anfrage($seite)['status'], $seite);
        }
    }

    public function testSchemaPruefungMeldetKeineFehler(): void
    {
        $this->anmelden();
        $text = $this->anfrage('scripts/schema_check.php')['text'];
        $this->assertStringNotContainsString('FEHLT', $text);
        $this->assertStringNotContainsString('fehlt', strip_tags($text));
    }
}
