<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Hält die Zugriffsregeln ohne laufenden Webserver fest. `php -S` wertet `.htaccess` nicht aus,
 * darum prüft AblaufTest das nicht. Am echten Apache wurde die Regel einmalig gemessen.
 */
final class HtaccessTest extends TestCase
{
    private function regeln(): string
    {
        return (string)file_get_contents(__DIR__ . '/../.htaccess');
    }

    /** CC-3 */
    public function testNurErlaubteEndungenWerdenAusgeliefert(): void
    {
        $this->assertSame(1, preg_match('/<FilesMatch "">\s*Require all denied/', $this->regeln()), 'Grundregel: alles gesperrt');
        $this->assertSame(1, preg_match('/<FilesMatch "([^"]+)">\s*Require all granted/', $this->regeln(), $m));
        $erlaubt = '/' . str_replace('/', '\/', $m[1]) . '/';
        foreach (['', 'akte.php', 'tabler.min.css', 'x.js', 'inter.woff2', 'a.svg', 'a.png', 'favicon.ico'] as $datei) {
            $this->assertSame(1, preg_match($erlaubt, $datei), "erlaubt: {$datei}");
        }
        foreach (['backup.sql.gz', 'dump.sql', '.env', '.env.bak', 'x.zip', 'x.bak', 'error_log', 'x.csv', 'x.json', 'akte.php~', 'akte.php.bak', 'x.pdf', 'LICENSE', 'schema.sql'] as $datei) {
            $this->assertSame(0, preg_match($erlaubt, $datei), "gesperrt: {$datei}");
        }
        $this->assertStringContainsString('Options -Indexes', $this->regeln());
    }

    /** CC-42 */
    public function testUmleitungLaeuftHinterProxyNichtImKreis(): void
    {
        $this->assertMatchesRegularExpression('/RewriteCond %\{HTTPS\} off\s+RewriteCond %\{HTTP:X-Forwarded-Proto\} !\^https/', $this->regeln());
    }
}
