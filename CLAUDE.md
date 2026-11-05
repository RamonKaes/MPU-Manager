# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer analyse                     # PHPStan (phpstan.neon)
bash scripts/pruefe_zugriff.sh dist  # .htaccess am echten Apache messen, braucht einen Build
composer test                        # PHPUnit-Tests, HTTP-Abläufe nur mit MPU_TEST_DB_NAME=..._test (siehe README)
vendor/bin/phpunit --filter TestName # Einzelnen Test ausführen
php -l <datei.php>                   # PHP-Syntax prüfen
mysql -u$DB_USER -p$DB_PASS $DB_NAME < schema.sql  # Schema anlegen / migrieren
bash scripts/backup.sh               # Manuelles Backup (Pfade: MPU_BACKUP_DIR, MPU_BACKUP_LOG)
bash scripts/build.sh                # FTP-Build: erzeugt dist/ (Assets optimiert, ohne vendor/, tests/, Docs)
```

Kein Bundler, kein Transpiler. Die Entwicklung läuft direkt aus dem Quellbaum, Assets sind lokal vendored.
Die CI (`.github/workflows/ci.yml`) führt Syntax, PHPStan, `composer audit`, die Tests auf PHP 8.4/8.5 und MariaDB 10.11/11.8 sowie Build und Zugriffsregeln aus.
Die App hat keine PHP-Laufzeitabhängigkeiten. `vendor/` enthält nur PHPUnit und PHPStan (lokal/dev) und darf nicht auf den Server hochgeladen werden.
Deployment = `.env` anlegen (Inhalt: README, Schritt 1), `bash scripts/build.sh`, dann `dist/` hochladen, `schema.sql` einspielen.

**Der Build optimiert die Assets** (`scripts/optimize_assets.py`, läuft nur auf `dist/`, nie auf dem Quellbaum):
Er subsettet die Tabler-Icon-Font auf die tatsächlich benutzten Icons und entfernt die Legacy-Font-Formate
(eot/ttf/woff — kein aktueller Browser lädt sie). Das setzt `pyftsubset` voraus: `pip install 'fonttools[woff]==4.62.1'` (4.68.0 bricht an der Icon-Schrift ab).

**Folge für neue Icons:** Der Scanner liest die `ti-*`-Klassen aus den PHP- und projekteigenen CSS-Dateien in `dist/`.
Ein Icon, das es im Set nicht gibt, **bricht den Build ab** — statt später stumm als leeres Kästchen zu erscheinen.
Tritt das auf, ist es fast immer ein Tippfehler im Icon-Namen. Dynamisch zusammengebaute Klassennamen müssen
ihre Literale im Code stehen haben (z. B. in einer Config-Tabelle), sonst findet der Scanner sie nicht.

Datenbank: MariaDB ab 10.11 (`JSON_OVERLAPS`, `ADD COLUMN IF NOT EXISTS`). `scripts/schema_check.php` meldet andere Server.

Pflicht-Umgebungsvariablen: `DB_USER`, `DB_PASS`. Optional: `DB_HOST` (default `localhost`), `DB_NAME` (default `mpu`).

Optional zur HTTPS-Erkennung (`logic/request.php`, siehe unten):
`APP_FORCE_HTTPS=1` erklärt die Installation als reine HTTPS-Installation — **auf Produktion
setzen.** `TRUSTED_PROXY_IPS` überschreibt die Liste der Proxys, deren `X-Forwarded-Proto`
ausgewertet wird (Default: Loopback; leerer Wert schaltet die Auswertung ganz ab).

## Architektur

**Kein Framework, kein Router.** Jede PHP-Datei ist eine eigenständige Seite (Direktaufruf via URL). Die App ist prozedurales PHP 8 mit einer einheitlichen Seitenstruktur:

```
require_once 'db.php'                  // Bootstrap: DB, Session, CSRF, Security-Header, Flash
require 'handlers/<seite>_handler.php' // POST-Verarbeitung (nur bei Seiten mit Formularen)
require 'partials/_head.php'           // DOCTYPE → </head> → <body>
$active_page = 'navkey';
require 'partials/_navbar.php'         // Navigation, öffnet .page > .page-wrapper
// ... Seiten-HTML mit eingebettetem PHP ...
require 'partials/_footer.php'         // Footer, schließt page-wrapper
```

### Handler-Pattern

POST-Logik liegt in `handlers/`. Ein Handler wird oben auf der Seite `require`d, prüft `$_SERVER['REQUEST_METHOD'] === 'POST'` und dispatchet ggf. auf `$_POST['action']`. Bei Erfolg: `header("Location: ...")` + `exit`. Bei Fehler: `$fehlermeldung` setzen, die Seite rendert weiter.

Jeder Handler beginnt mit einer CSRF-Prüfung:
```php
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { /* 403 */ }
```

### Datenbankzugriff

`$pdo` (global, aus `db.php`) ist der einzige Datenbankzugang. Immer Prepared Statements mit positionellen `?`-Platzhaltern. Kein String-Interpolation in SQL.

**Optimistic Locking** auf `clients` und `cases`: jedes UPDATE prüft `AND version = ?` und zählt `version = version + 1` hoch. `rowCount() === 0` → Konflikt (HTTP 409).

**Audit-Log**: jede schreibende Operation (`INSERT`, `UPDATE`, `DELETE`) muss `audit_log($pdo, $action, $table, $record_id, $client_id, $description)` aufrufen. Parameterreihenfolge ist zwingend einzuhalten.

Mehrschrittige Schreiboperationen werden immer in eine Transaktion gewickelt.

### Sicherheit

`db.php` setzt pro Request einen `$csp_nonce` (16 random bytes, base64). Jedes `<script>`-Tag braucht `nonce="<?= $csp_nonce ?>"`. Kein Inline-Script ohne Nonce.

Ausgabe-Escaping: ausnahmslos `htmlspecialchars($val, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8')`.

`$_POST`- und `$_GET`-Werte werden vor der Verwendung normalisiert: `trim()`, Null-Coalescing `??`, Whitelist-Prüfung via `in_array()`, Regex für Datumsfelder, `(int)$_GET['id']` mit anschließendem `> 0`-Check.

Authentifizierung: formularbasierter Session-Login (`login.php` + `auth.php`), Passwort-Hashes (bcrypt) in `config/users.php`.
Kein HTTP Basic Auth — das wurde entfernt. `db.php` ruft `require_auth()` auf; jeder neue Endpunkt bekommt damit
automatisch Zugangsschutz, solange er `db.php` einbindet. Endpunkte, die das nicht tun (z. B. `triage_ajax.php`),
rufen `sitzung_pruefen()` auf. `$_SESSION['user']` allein ist keine Anmeldung: `angemeldeter_benutzer()` prüft die
Kennung aus Nutzer, Installation und Passwort-Hash. Fremde Sitzungen, entfernte Nutzer und geänderte Passwörter gelten
damit nicht. Den Cookie-Namen bildet `session_starten()` je Installation (`MPU_…`).

Die `.htaccess` liefert nur erlaubte Endungen aus (`php`, `css`, `js`, `woff2`, `svg`, `png`, `ico`). Eine neue
Asset-Art muss dort und in `tests/HtaccessTest.php` eingetragen werden. Am echten Apache gemessen, `php -S` wertet sie nicht aus.

**Session-Cookies und HTTPS-Erkennung** liegen in `logic/request.php`, das bewusst abhängigkeitsfrei ist
(`login.php` und `logout.php` öffnen die Session vor `db.php`):

- `session_starten()` ist der **einzige** erlaubte Weg, eine Session zu öffnen. Nie direkt `session_start()`
  aufrufen — PHPs ini-Defaults liefern hier ein Cookie ohne `HttpOnly` und ohne `SameSite`.
- `request_ist_https()` statt `$_SERVER['HTTPS']` abfragen. Hinter einem TLS-terminierenden Reverse Proxy
  (lokal Caddy vor Apache:8088) sieht Apache nur HTTP. Wird für das `secure`-Flag und den HSTS-Header
  gebraucht. Erkennung in drei Stufen: echtes TLS am Server → `APP_FORCE_HTTPS` → `X-Forwarded-Proto`
  von einer Adresse aus `TRUSTED_PROXY_IPS` (Default Loopback).
  **Auf Produktion `APP_FORCE_HTTPS=1` setzen.** Sonst hängt das `secure`-Flag daran, ob der Hoster
  einen Proxy vorschaltet, welche IP der hat und ob er den Header überhaupt setzt — fehlt eines davon,
  fällt das Flag samt HSTS still weg. Die Erkennung irrt bewusst nach der sicheren Seite: fälschlich
  erkanntes HTTPS kostet die Session, fälschlich erkanntes HTTP gäbe das Cookie über die Leitung.

### Triage-Engine

`triage.php` ist die fachliche Single Source of Truth für die MPU-Fallklassifikation (BK-5). Die zentrale Funktion ist `berechne_triage()` mit Named Arguments.

**Kein Schwellenwert steht im Code oder im Anzeigetext.** Alle liegen in `config/` — aufgeteilt nach
Herkunft, weil die Änderungsregel je Herkunft eine andere ist:

| Datei | Inhalt | Änderung erlaubt, wenn … |
|---|---|---|
| `config/triage_config.php` | Werte, die `berechne_triage()` speisen (BAK, THC, ICD-Score, Abstinenzmonate) | die Prüfung am Original der BK-5 im Commit belegt ist |
| `config/screening_config.php` | AUDIT/LAST — reine Dokumentationsfelder, fließen **nicht** in die Triage ein | Primärquelle genannt ist (WHO-Manual bzw. Testmanual, **nicht** BK-5) |
| `config/ctu_config.php` | CTU-Kriterien (Probenvalidität Kreatinin, Haar-Segmentlängen, PEth, Urin-Soll) — BK-5-Quelle, speisen aber `berechne_triage()` nicht | wie `triage_config.php`: Prüfung am Original der BK-5 im Commit belegt ist |
| `config/behoerden_config.php` | Antrags-Vorlauffristen der Führerscheinstellen | frei — Erfahrungswissen über Bearbeitungsdauern, keine fachliche Norm |

Ein Schwellenwert gehört in **genau eine** dieser Dateien und wird überall sonst interpoliert —
auch in Fließtext, Hinweiszeilen und JS. Zwei Fehler dieser Art sind belegt: die ICD-Schwelle stand
als Prosa in mehreren Seiten (UX-3), der LAST-Cut-off doppelt im Markup zweier Formulare, wo er
auseinanderlief und zusätzlich sachlich falsch war (E-6).

Einzige erlaubte zweite Fundstelle ist `tests/ConfigStandTest.php`: Sie hält die Werte fest, damit eine Änderung
sichtbar wird, und wird bewusst mit nachgezogen. Verhaltenstests lesen ihre Werte aus der Config.

Keine Seitenangaben und keine angeblichen Wortlaute aus Quellen in Code und Anzeigetexten, Verweise nennen nur Werk und
Abschnitt (`GrenzwerteTest`). Rechtsangaben in Anzeigetexten werden am Gesetzestext geprüft, nicht an anderen Stellen des Projekts. Belegt: „0,25 ‰
nach § 24a StVG“ stand in zwölf Texten und widersprach nichts im Projekt, das Gesetz nennt 0,25 mg/l oder 0,5 ‰ (R4-17).

**Schreibwege:** Datenänderung und `audit_log()` stehen immer in einer Transaktion, auch bei einem einzelnen Statement.
Audit-Beschreibungen enthalten nur Metadaten (Datum, Art), keine Inhalte wie Labor, Befund oder Notiztitel. Die Aufnahme
leitet nach dem Speichern weiter (303), Fehleingaben im Akte-Formular laufen über `akte_eingaben_merken()`. Die Zahlung
in `finanzen.php` prüft Fall und Version wie `bearbeiten.php`. Inline-Handler (`onclick=`) sperrt die CSP.

**Anmeldung:** Der Versuch wird vor der Passwortprüfung gezählt, ohne Datenbank gibt es keine Anmeldung (503). Die Adresse
kommt aus `client_adresse()`, nicht aus `REMOTE_ADDR`. Unbekannte Benutzernamen landen nicht im `auth_log`.

**Abstinenzkette** (`logic/mpu_helper.php`): Sie beginnt nach dem letzten positiven Befund, Beleg ist eine negative
Probe, bei Urin nur mit verwertbarem Kreatinin (`abstinenz_belege_der_kette()`). Urinproben werden gegen das Soll gezählt,
nicht nach Abstand, Lücken gibt es nur bei Haar. Handler speichern alle Eingaben der Triage, damit Formular, AJAX und
gespeicherter Stand dasselbe rechnen. Ein Override bestimmt `abstinenz_monate`.

**Angaben zur Beratungsstelle** (Name, Anschrift, Kontakt, Aufsichtsbehörde) liegen in
`config/praxis_config.php` und werden auf den Druckseiten interpoliert. Sie stehen nie im Markup;
im Repository enthält die Datei nur Platzhalter in eckigen Klammern (`tests/PraxisConfigTest.php`).

## Konventionen

- `declare(strict_types=1)` in jeder PHP-Datei
- Variablen, Funktionen und Konstanten auf **Deutsch** (Domänen-Vokabular: `$fehlermeldung`, `berechne_triage()`, `ERLAUBTE_HYPOTHESEN`)
- SQL-Spaltennamen und Tabler-CSS-Klassen auf Englisch
- UI: **Tabler UI v1.4.0**, lokal vendored in `assets/`. Kein CDN. Icons: `ti ti-*` aus Tabler Icons
- Icons in Listenzeilen brauchen zusätzlich die Klasse `icon` (`class="ti ti-x icon"`). Ohne sie erben sie die
  Textgröße der Liste (0,875rem) statt der vorgesehenen 1,25rem und wirken zu klein
- Badges in einer Tab- oder Button-Gruppe durchgängig in derselben Variante halten — im Projekt die aufgehellte
  `bg-*-lt`. Ein einzelnes deckendes `bg-danger` fällt als Inkonsistenz auf
- Fonts: nur `woff2`. Inter (Fließtext) und Cantarell (Druckdokumente) je als Variable Font mit Gewichtsachse,
  eingebunden über `assets/fonts/inter.css` bzw. `assets/fonts/cantarell.css`
- Kein jQuery, keine externen JS-Libraries. Reines Vanilla JS mit CSP-Nonce
- Dynamisch per JS gebaute POST-Formulare müssen das `csrf_token` aus dem DOM mitkopieren, sonst antwortet der
  Endpunkt mit 403
- Keine veralteten PHP-Funktionen (`utf8_encode`/`utf8_decode` — DB ist durchgängig `utf8mb4`)
- Druckbare Seiten heißen `*_druck.php` (`akte_`, `vertrag_`, `strategie_`, `datenschutz_`,
  `widerruf_`). Sie erzeugen **kein** PDF, sondern HTML mit `@page`-CSS und `window.print()`;
  das PDF entsteht im Druckdialog des Browsers. Kein `_pdf`-Suffix für neue Seiten
- Eine `logic/`-Datei, die getestet werden soll, gehört in `composer.json` unter
  `autoload-dev.files` — sonst scheitern die Tests an „undefined function", statt fachlich rot zu werden