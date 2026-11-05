<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für logic/request.php – HTTPS-Erkennung hinter Reverse Proxys.
 *
 * Hintergrund E-1: von request_ist_https() hängen das Secure-Flag des
 * Session-Cookies und der HSTS-Header ab. Eine falsch-negative Erkennung
 * liefert ein Cookie ohne Secure-Flag — der Fehler, der wehtut.
 *
 * Ausführen: composer test
 *   oder:    vendor/bin/phpunit --testdox tests/RequestTest.php
 */
class RequestTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server_sicherung;

    protected function setUp(): void
    {
        $this->server_sicherung = $_SERVER;
        putenv('TRUSTED_PROXY_IPS');
        putenv('APP_FORCE_HTTPS');
        unset($_SERVER['HTTPS'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server_sicherung;
        putenv('TRUSTED_PROXY_IPS');
        putenv('APP_FORCE_HTTPS');
    }

    // ── Stufe 1: echtes TLS am Server ─────────────────────────────────────────

    public function testEchtesTlsWirdErkannt(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(request_ist_https());
    }

    public function testHttpsOffGiltNichtAlsTls(): void
    {
        $_SERVER['HTTPS']      = 'off';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(request_ist_https());
    }

    // ── Stufe 2: Erklärung des Betreibers ─────────────────────────────────────

    public function testForceHttpsWirktOhneJedenHeader(): void
    {
        // Der Produktionsfall: Proxy mit unbekannter IP, kein X-Forwarded-Proto
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        putenv('APP_FORCE_HTTPS=1');
        $this->assertTrue(request_ist_https());
    }

    public function testForceHttpsAkzeptiertUeblicheSchreibweisen(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        foreach (['1', 'true', 'TRUE', 'on', 'yes'] as $wert) {
            putenv("APP_FORCE_HTTPS={$wert}");
            $this->assertTrue(request_ist_https(), "Wert '{$wert}' sollte greifen");
        }
    }

    public function testForceHttpsAusGiltNicht(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        putenv('APP_FORCE_HTTPS=0');
        $this->assertFalse(request_ist_https());
    }

    // ── Stufe 3: X-Forwarded-Proto von vertrauter Adresse ─────────────────────

    public function testLoopbackProxyDarfProtokollMelden(): void
    {
        $_SERVER['REMOTE_ADDR']            = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(request_ist_https());
    }

    public function testFremdeAdresseDarfProtokollNichtMelden(): void
    {
        // Der Kern von E-1: ein Client aus dem Netz steuert die Erkennung nicht
        $_SERVER['REMOTE_ADDR']            = '203.0.113.9';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse(request_ist_https());
    }

    public function testVerketteteProxysErstesProtokollZaehlt(): void
    {
        $_SERVER['REMOTE_ADDR']            = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
        $this->assertTrue(request_ist_https());

        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http, https';
        $this->assertFalse(request_ist_https());
    }

    // ── Konfigurierbare Vertrauensgrenze ──────────────────────────────────────

    public function testEigeneProxyListeErsetztLoopback(): void
    {
        putenv('TRUSTED_PROXY_IPS=10.0.0.5, 10.0.0.6');
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $_SERVER['REMOTE_ADDR'] = '10.0.0.6';
        $this->assertTrue(request_ist_https(), 'gelistete Adresse muss zählen');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertFalse(request_ist_https(), 'Loopback ist nicht mehr automatisch vertraut');
    }

    public function testLeereListeSchaltetHeaderAuswertungAb(): void
    {
        putenv('TRUSTED_PROXY_IPS=');
        $_SERVER['REMOTE_ADDR']            = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertFalse(request_ist_https());
    }

    public function testLeereListeSchlaegtForceHttpsNichtAus(): void
    {
        // Die härteste Kombination bleibt bedienbar: Header aus, Betreiber setzt HTTPS
        putenv('TRUSTED_PROXY_IPS=');
        putenv('APP_FORCE_HTTPS=1');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue(request_ist_https());
    }

    // ── Session-Cookie-Parameter hängen daran ─────────────────────────────────

    public function testCookieBekommtSecureFlagNurBeiHttps(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertFalse(session_cookie_parameter()['secure']);

        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue(session_cookie_parameter()['secure']);
    }

    public function testCookieImmerHttponlyUndSamesiteStrict(): void
    {
        $parameter = session_cookie_parameter();
        $this->assertTrue($parameter['httponly']);
        $this->assertSame('Strict', $parameter['samesite']);
    }
}
