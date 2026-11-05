# MPU-Manager

Webbasierte Praxisverwaltungssoftware für MPU-Beratungsstellen (Medizinisch-Psychologische Untersuchung).

> **Nicht für den direkten Produktiveinsatz.** Dieses Repository ist eine Ausgangsbasis zum Forken. Setze
> es nicht unverändert mit echten Klientendaten ein. Ein Einsatz ist erst sinnvoll, nachdem ein eigener
> Fork geprüft und angepasst wurde, siehe [Vor dem Einsatz: eigener Fork](#vor-dem-einsatz-eigener-fork).
>
> **Regionaler Bezug: Kreis Mettmann und Düsseldorf.** Diese Version ist für eine
> Beratungsstelle im Kreis Mettmann entstanden. Behördenlogik, Vorlauffristen, Kontakte
> und der Klienten-Leitfaden sind auf die Führerscheinstellen Kreis Mettmann und
> Düsseldorf zugeschnitten, siehe [Geltungsbereich und Anpassung](#geltungsbereich-und-anpassung).

## Projektstatus

- **Keine Weiterentwicklung:** Der Herausgeber setzt die Software nicht mehr ein und
  entwickelt sie nicht weiter. Es gibt keine Updates und keinen Support. Das Repository ist
  archiviert und nimmt keine Issues und Pull Requests an.
- **Prüfstand:** Nach dem letzten unabhängigen Review wurde der Stand noch umfangreich geändert. Diese
  Änderungen sind durch Tests belegt, ein erneuter unabhängiger Lauf hat nicht stattgefunden.
- **Frei verfügbar, ohne kommerzielles Interesse:** Die Software ist unentgeltlich und
  verfolgt keine kommerziellen Zwecke. Es werden weder bezahlte Leistungen angeboten noch
  Spenden oder sonstige Zahlungen angenommen.
- **Forken ist der vorgesehene Weg:** Wer die Software nutzen will, forkt sie im Rahmen der
  [Lizenz](#lizenz), passt sie an und führt sie in eigener Verantwortung weiter.

## Geltungsbereich und Anpassung

Für den Einsatz außerhalb der Region musst du mindestens diese Stellen anpassen:

| Stelle | Regionaler Inhalt |
|---|---|
| `config/behoerden_config.php` | Vorlauffristen der Führerscheinstellen |
| `logic/mpu_helper.php` | Zuordnung von Wohnort und Behörde (`is_authority_mettmann()`, `is_authority_duesseldorf()`) |
| `aufnahme.php`, `akte.php`, `index.php` | Hinweise und Warnungen zu den beiden Behörden |
| `klienten_leitfaden.php`, `ressourcen.php` | Ablauf vor Ort, Behördenkontakte und lokale Hilfsangebote |
| `config/checklist_templates.php` | Regionale Hinweise in den Aufgaben |
| `config/praxis_config.php` | Angaben zur Beratungsstelle und zuständige Datenschutz-Aufsichtsbehörde |
| `tests/MpuHelperTest.php` | Tests der regionalen Zuordnung |

## Wichtige Hinweise

- **Zweckbestimmung:** Die Software ist eine Organisations- und Dokumentationshilfe für
  Beratungsstellen in der MPU-Vorbereitung. Sie ist nicht dafür bestimmt, Krankheiten zu
  diagnostizieren, zu überwachen, zu behandeln oder ihnen vorzubeugen. ICD-Quick-Check und
  Triage stellen keine Diagnose.
- **Keine Fach- oder Rechtsberatung:** Triage und Fristen sind Arbeitshilfen. Sie ersetzen
  weder die fachliche Beurteilung im Einzelfall noch eine Rechtsberatung.
- **Fachstand und Pflicht nach dem Forken:** Schwellenwerte, Hinweise, Fristen und Vorlagen geben den
  Stand von 2026 wieder und werden nicht mehr nachgezogen. Wer die Software einsetzt, muss sie nach
  dem Forken **selbst gegen die aktuellen Quellen kontrollieren, anpassen und aktualisieren**. Dafür
  werden die Originalwerke benötigt, das Repository ersetzt sie nicht.
- **Dokumentvorlagen:** Vertrag, Datenschutzinformation, Widerrufsbelehrung und Strategie-Vereinbarung enthalten
  **bewusst keinen Rechtstext**, sondern sichtbare Platzhalter.
- **Regulatorik:** Wer die Software einsetzt, prüft selbst, welche Vorgaben für den eigenen
  Einsatz gelten, insbesondere DSGVO und gegebenenfalls Medizinprodukterecht.
- **Sicherheit:** Siehe [SECURITY.md](SECURITY.md).

## Funktionsumfang

- **Klientenverwaltung** – Stammdaten, DSGVO-Einwilligung, Widerruf
- **Fallakten** – MPU-Dossier je Klient mit Delikt, Fristen, Behördenkontakten und Korrespondenz-Events
- **System-Triage (BK-5)** – automatische Hypothesen- und Abstinenzempfehlung auf Basis der Falldaten; manuell überschreibbar (Human-Override mit Begründung)
- **Sitzungsprotokolle** – phasenbasierte Dokumentation (Anamnese, Analyse, Check) inkl. SORKC-Felder
- **Abstinenz-Nachweise** – Urin-/Haarproben mit Kreatinin- und PEth-Werten
- **BKF-Leistungstests** – psychophysische Testergebnisse (Prozentränge) mit Kompensationslogik
- **Aufgaben-Checklisten** – deliktabhängige Dokumenten-Checklisten je Fall
- **Finanzen** – Paket- und Einzelabrechnung, Zahlungsstatus
- **Dokumenten-Druck** – Gerüste für Vertrag, Datenschutzinformation, Widerruf und Strategie-Vereinbarung mit Platzhaltern für eigene Texte (Browser-Druck / PDF-Speicherung)
- **Audit-Log** – Protokoll der Schreibzugriffe durch Benutzer
- **DSGVO** – JSON-Export der Klientendaten je Klient (Art. 20, Grundlage für Auskünfte nach Art. 15) sowie UI-gestützte Löschroutine nach Ablauf der Aufbewahrungsfrist (Art. 17)

## Voraussetzungen

- PHP 8.4+
- MariaDB ab 10.11, gemessen auf 10.11 und 11.8. MariaDB 10.6 und MySQL werden nicht unterstützt
  (`JSON_OVERLAPS` fehlt in 10.6, MySQL kennt `ADD COLUMN IF NOT EXISTS` nicht)
- Apache mit aktivem `.htaccess`. Andere Webserver müssen die dortigen Zugriffssperren selbst
  umsetzen, siehe [SECURITY.md](SECURITY.md)

Die App hat **keine PHP-Laufzeitabhängigkeiten** – `vendor/` enthält nur PHPUnit (ausschließlich für lokale Tests) und muss **nicht** auf den Server hochgeladen werden.

Nur für den Build (lokal, nicht auf dem Server): `pyftsubset` aus dem Python-Paket
`fonttools` – `pip install 'fonttools[woff]==4.62.1'`. Neuere Versionen brechen an der Icon-Schrift ab (gemessen mit 4.68.0).

## Bekannte Einschränkungen

Diese Punkte sind bekannt und bewusst nicht gelöst, weil sie vom Betrieb oder von Entscheidungen der
Beratungsstelle abhängen. Jeder Fork entscheidet selbst darüber:

- **Widerruf:** Ein bestätigter Widerruf sperrt die Akte nicht, lässt sich nicht zurücknehmen und ist nur in der Akte
  sichtbar. Weiteres Schreiben bleibt möglich.
- **Sicherungen:** Gelöschte Daten bleiben in Sicherungen, bis diese rotiert sind. `scripts/backup.sh` zählt dafür Dateien,
  nicht Tage. Wer eine Sicherung zurückspielt, holt gelöschte Klienten zurück und muss die Löschungen wiederholen.
- **Abbrecher:** Aktive oder pausierte Fälle ohne Aktivität werden nie zur Löschung fällig. Fällig wird nur ein abgeschlossener Fall.
- **Adressen im Log:** Suchbegriffe und Filter stehen in der URL und damit im Access-Log des Webservers, darunter
  Klientennamen. Das gehört ins Verarbeitungsverzeichnis, das Log braucht eine kurze Frist.
- **Lesezugriffe:** Das Audit-Log erfasst nur Schreibvorgänge. Das Ansehen und Drucken von Akten wird nicht protokolliert,
  ein Massenabruf fällt also nicht auf.
- **Handbuch:** `anleitung.php` beschreibt die Oberfläche stellenweise anders, als sie ist, und enthält Zahlen, die nicht aus
  `config/` kommen. Es ist Arbeitsgrundlage, keine geprüfte Dokumentation. Auch `CLAUDE.md` nennt das als Regel, nicht als erreichten Stand.
- **Anmeldesperre:** Sie zählt je Adresse. Wer viele Adressen nutzt, wird nicht gebremst.

## Vor dem Einsatz: eigener Fork

Erledige im eigenen Fork mindestens diese Punkte, bevor echte Klientendaten eingegeben werden:

1. **Fachinhalte:** Schwellenwerte, Hypothesen, Warntexte, Fristen, Handbuch und Klienten-Leitfaden gegen
   die aktuellen [Quellen](#fachliche-quellen) und die aktuelle Rechtslage kontrollieren und anpassen.
2. **Region:** Behörden, Fristen und Kontakte auf die eigene Region umstellen, siehe
   [Geltungsbereich und Anpassung](#geltungsbereich-und-anpassung).
3. **Beratungsstelle:** Die Platzhalter in `config/praxis_config.php` ersetzen.
4. **Dokumentvorlagen:** In `vertrag_druck.php`, `datenschutz_druck.php`, `widerruf_druck.php` und
   `strategie_druck.php` eigene, rechtlich geprüfte Texte statt der Platzhalter einsetzen.
5. **Sicherheit und Betrieb:** Den eigenen Stand prüfen und die Punkte aus [SECURITY.md](SECURITY.md)
   umsetzen. Sicherungen einrichten und das Zurückspielen testen.
6. **Datenschutz und Regulatorik:** Für den eigenen Betrieb prüfen, was gilt, siehe
   [Wichtige Hinweise](#wichtige-hinweise).
7. **Tests:** Fachliche Anpassungen mit Tests absichern, `composer test` muss grün sein. Ohne Testdatenbank
   werden die Schreibwege übersprungen, siehe [Tests](#tests). `tests/ConfigStandTest.php` hält alle Fachwerte
   fest: Wer einen Wert ändert, zieht ihn dort bewusst nach und prüft dabei die Quelle.

## Installation

Die folgenden Schritte richten eine Installation **aus dem eigenen Fork** ein. Zum Ausprobieren und
Entwickeln funktionieren sie genauso, dann aber nur mit erfundenen Testdaten.

**Schritt 1 – Build erzeugen und hochladen:**

```bash
bash scripts/build.sh
```

Das Skript braucht im Projektordner eine Datei `.env` mit den Datenbankzugangsdaten und
trägt sie in `dist/.htaccess` ein:

```bash
DB_USER=datenbankbenutzer
DB_PASS=sicheres-passwort-min-20-zeichen
DB_HOST=localhost
DB_NAME=mpu
```

Das Skript legt `dist/` an: alle Dateien, die auf den Server gehören, ohne `vendor/`,
`tests/` und interne Dokumentation. Dabei werden die Assets optimiert – die
Tabler-Icon-Font wird auf die tatsächlich benutzten Icons reduziert und die
Legacy-Font-Formate (eot/ttf/woff) entfallen, die kein aktueller Browser lädt.
Das verkleinert den Upload von rund 9 MB auf etwa 2,8 MB.

Anschließend den **Inhalt von `dist/`** per FTP/SFTP auf den Server kopieren.

> `config/users.php` wird bewusst **nicht** mitgebaut (enthält Passwort-Hashes) und
> muss einmalig direkt auf dem Server angelegt werden – siehe Schritt 4.

**Schritt 2 – Datenbank anlegen und Migrationen einspielen** (auf dem Server):

```bash
mysql -u DB_USER -p -e 'CREATE DATABASE IF NOT EXISTS DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
mysql -u DB_USER -p DB_NAME < schema.sql
DB_USER=... DB_PASS=... DB_NAME=... php scripts/migrate.php
```

**Schritt 3 – Datenbankverbindung konfigurieren:**

Umgebungsvariablen `DB_USER`, `DB_PASS` (sowie optional `DB_HOST`, `DB_NAME`) setzen,
z. B. über `SetEnv` in `.htaccess`.

**Schritt 3b – HTTPS erklären (wichtig):**

```apache
SetEnv APP_FORCE_HTTPS 1
```

Davon hängen das `secure`-Flag des Session-Cookies und der HSTS-Header ab. Ohne diese
Angabe versucht die App, HTTPS selbst zu erkennen: erst über `$_SERVER['HTTPS']`, dann
über `X-Forwarded-Proto` von einer Adresse aus `TRUSTED_PROXY_IPS` (Voreinstellung:
Loopback). Terminiert der Hoster TLS auf einem vorgelagerten Proxy mit anderer IP oder
setzt er den Header nicht, schlägt die Erkennung fehl — **still**, ohne Fehlermeldung, und
das Session-Cookie geht ohne `secure` über die Leitung.

Setze `APP_FORCE_HTTPS` nur, wenn die Installation tatsächlich ausschließlich über HTTPS
erreichbar ist. Auf einer über HTTP erreichbaren Installation sperrt die Angabe die
Anmeldung aus: der Browser sendet das Cookie dann nicht mehr.

`TRUSTED_PROXY_IPS` (kommaseparriert) passt die Vertrauensgrenze an, falls der Proxy auf
einem anderen Host liegt. Ein leerer Wert schaltet die Header-Auswertung ganz ab — die
härteste Einstellung, sinnvoll zusammen mit `APP_FORCE_HTTPS=1`.

**Schritt 4 – Login-Benutzer anlegen:**

`config/users.php.example` nach `config/users.php` kopieren und für jeden Benutzer
einen bcrypt-Hash setzen. Der Platzhalter ist kein gültiger Hash, ohne eigenen Eintrag ist keine
Anmeldung möglich (`config/users.php` ist gitignored und wird nie
hochgeladen, sondern direkt auf dem Server angelegt):

```bash
cp config/users.php.example config/users.php
php -r "echo password_hash('IhrPasswort', PASSWORD_BCRYPT, ['cost' => 12]) . PHP_EOL;"
# Ausgabe als 'hash' in config/users.php eintragen
```

**Schritt 4b – Angaben zur Beratungsstelle eintragen:**

In `config/praxis_config.php` alle Werte in eckigen Klammern ersetzen: Name, Anschrift,
Kontaktdaten und die zuständige Datenschutz-Aufsichtsbehörde. Sie erscheinen auf Vertrag,
Datenschutzinformation, Widerrufsbelehrung und Strategie-Vereinbarung. Eigene Rechtstexte
gehören in die Druckseiten, siehe [Vor dem Einsatz](#vor-dem-einsatz-eigener-fork).

**Schritt 5 – Deployment verifizieren:**

`scripts/schema_check.php` im Browser aufrufen. Die Seite prüft, ob Schema und
alle Migrationen eingespielt sind, und meldet fehlende Tabellen/Spalten. Erst wenn
alle Prüfungen bestanden sind, ist das Deployment vollständig.

> **Migrationsregel:** Bereits angewendete Migrationen (`migrations/*.sql`) niemals
> nachträglich editieren – `migrate.php` überspringt sie dauerhaft, die Änderung
> käme auf Bestands-DBs nie an. Schemaänderungen immer als **neue** Migration.

## Konfiguration

| Datei | Inhalt |
|---|---|
| `db.php` | Datenbankverbindung, Session-Start, Security-Header |
| `logic/request.php` | HTTPS-Erkennung hinter Reverse Proxy, einheitliche Session-Cookie-Parameter |
| `.htaccess` | HTTPS-Erzwingung, Zugriffsschutz, Kompression (`mod_deflate`), Cache-Header |
| `config/allowed_values.php` | Erlaubte Enum-Werte, Aufbewahrungsfrist, Hypothesen-Whitelist |
| `config/checklist_templates.php` | Aufgaben-Templates je Delikttyp |
| `config/triage_config.php` | Numerische BK-5-Schwellenwerte der Triage-Engine |
| `config/ctu_config.php` | CTU-Kriterien (Probenvalidität, Haar-Segmentlängen) |
| `config/screening_config.php` | Grenzwerte der Screening-Fragebögen AUDIT und LAST |
| `config/behoerden_config.php` | Antrags-Vorlauffristen der Führerscheinstellen |
| `config/praxis_config.php` | Angaben zur Beratungsstelle auf den Druckdokumenten |
| `config/users.php` | Login-Benutzer (bcrypt-Hashes) – gitignored, nie im Repo/`dist` |

## Tests

```bash
composer install  # einmalig, lokal – installiert PHPUnit und PHPStan
composer test
composer analyse
```

Die Schreibwege der Handler prüft `tests/Http/AblaufTest.php` über echtes HTTP. Er läuft nur mit einer eigenen,
leeren Testdatenbank. **Alle Tabellen darin werden gelöscht**, deshalb muss ihr Name auf `_test` enden:

```bash
MPU_TEST_DB_NAME=mpu_test MPU_TEST_DB_USER=... MPU_TEST_DB_PASS=... composer test
```

Über die Zugriffssperren aus `.htaccess` sagt dieser Test nichts, denn er nutzt den eingebauten PHP-Server. Sie misst
`bash scripts/pruefe_zugriff.sh dist` an einem echten Apache. Die GitHub-CI (`.github/workflows/ci.yml`) führt das alles
aus, die Tests auf PHP 8.4 und 8.5 und auf MariaDB 10.11 und 11.8. Sie läuft in jedem Fork mit.

## Updates einspielen

Schema-Änderungen nach der Erstinstallation werden über `scripts/migrate.php` eingespielt:

```bash
php scripts/migrate.php
```

Das Skript scannt das Verzeichnis `migrations/` nach `*.sql`-Dateien (Namensformat
`YYYYMMDD_NNNN_beschreibung.sql`), führt alle noch nicht angewendeten Migrationen
in alphabetischer Reihenfolge aus und protokolliert sie in der Tabelle
`schema_migrations`. Zweimaliges Ausführen ist sicher – bereits angewendete
Migrationen werden übersprungen.

Neue Migrationen kommen als `migrations/YYYYMMDD_NNNN_name.sql` hinzu.
Jede Datei enthält valide SQL-Statements, die mit `;` abgeschlossen werden.

## Datenbankstruktur

| Tabelle | Beschreibung |
|---|---|
| `clients` | Klienten-Stammdaten |
| `cases` | MPU-Fallakten (1:n pro Klient) |
| `sessions` | Sitzungsprotokolle |
| `abstinence_probes` | Abstinenz-Einzelnachweise |
| `client_tasks` | Aufgaben-Checklisten |
| `case_events` | Behörden-Korrespondenz-Log |
| `performance_tests` | BKF-Leistungstests |
| `audit_log` | Revisionsprotokoll (Schreibzugriffe) |
| `auth_log` | Authentifizierungs-Log (Login/Logout/Timeout) |
| `login_attempts` | Brute-Force-Schutz |
| `schema_migrations` | Angewendete Datenbank-Migrationen |

## Sicherheit

- Formularbasierter Login (`login.php`) mit bcrypt-Hashes (`config/users.php`), Session-Idle-Timeout und Brute-Force-Schutz (`login_attempts`)
- CSRF-Token auf allen schreibenden Formularen; CSP-Nonce für Inline-Scripts
- Prepared Statements (PDO) durchgehend, Optimistic Locking auf `clients`/`cases`
- Security-Header: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, HSTS (nur über HTTPS)
- Session-Cookie mit `HttpOnly`, `SameSite=Strict` und – über HTTPS – `secure`. Die HTTPS-Erkennung
  (`logic/request.php`) berücksichtigt `X-Forwarded-Proto` von Loopback-Adressen, damit das `secure`-Flag
  hinter einem TLS-terminierenden Reverse Proxy nicht still wegfällt
- Audit-Log der Schreibvorgänge über Formulare, ohne Inhalte, mit Frist in `scripts/cleanup.php`. Kein manipulationssicherer
  Speicher und keine Anzeige in der Software. Das automatische Anlegen der Aufgabenliste beim Öffnen einer Akte wird nicht protokolliert
- Separates Auth-Log (`auth_log`), unbekannte Benutzernamen werden nicht gespeichert
- Der DSGVO-Export enthält das Audit-Log nicht. Ob er für eine Auskunft nach Art. 15 reicht, prüft jeder Fork selbst

## Backup & Betrieb

### Automatisches Datenbank-Backup

Das Skript `scripts/backup.sh` erstellt einen komprimierten MySQL-Dump und
rotiert ältere Sicherungen automatisch.

**Einstellungen** (Umgebungsvariablen):

| Variable | Pflicht | Standard |
|---|---|---|
| `DB_NAME`, `DB_USER`, `DB_PASS` | ja | dieselben wie für die Webanwendung |
| `DB_HOST` | nein | `localhost` |
| `MPU_BACKUP_DIR` | nein | `/var/backups/mpu` |
| `MPU_BACKUP_LOG` | nein | `/var/log/mpu_backup.log` |
| `MPU_CLEANUP_LOG` | nein, nur für den Cron-Job | `/var/log/mpu_cleanup.log` |

Für die Standardpfade braucht der Benutzer Schreibrechte, die es auf Shared Hosting
meist nicht gibt. Setze dort eigene Pfade. **Der Sicherungsordner muss außerhalb des
Webroots liegen**, denn die Dumps enthalten Gesundheitsdaten.

**Manueller Aufruf:**

```bash
bash scripts/backup.sh
```

Es entsteht `mpu_YYYYMMDD_HHMMSS.sql.gz` im Sicherungsordner, nur für den eigenen Benutzer
lesbar. Ein abgebrochener Dump wird verworfen und zählt nicht als Sicherung. Es werden stets
die **30 neuesten** Dateien behalten, ältere werden automatisch gelöscht. Jeder Lauf schreibt
eine Status-Zeile ins Log (Zeitstempel und Dateigröße oder Fehlercode). Ist das Log nicht
schreibbar, erscheint sie auf der Fehlerausgabe. Das Skript endet mit einem Fehlercode, wenn keine
gültige Sicherung entstanden ist.

**Integritätsprüfung:**

```bash
gunzip -t <Sicherungsordner>/mpu_YYYYMMDD_HHMMSS.sql.gz
```

### Cron-Job einrichten

Cron startet ohne die Umgebungsvariablen der Webanwendung. Lege deshalb eine Einstellungsdatei an, nur
für den eigenen Benutzer lesbar (`chmod 600`) und außerhalb des Webroots. Werte mit Sonderzeichen
gehören in einfache Anführungszeichen:

```bash
DB_NAME=mpu
DB_USER=datenbankbenutzer
DB_PASS='sicheres-passwort-min-20-zeichen'
MPU_BACKUP_DIR=/pfad/ausserhalb/des/webroots/backups
MPU_BACKUP_LOG=/pfad/ausserhalb/des/webroots/mpu_backup.log
MPU_CLEANUP_LOG=/pfad/ausserhalb/des/webroots/mpu_cleanup.log
```

Richte die Jobs dann mit dem Pfad zu dieser Datei ein:

```bash
MPU_ENV_FILE=/pfad/zur/einstellungsdatei bash scripts/install_cron.sh
```

Ohne `MPU_ENV_FILE` gilt `.env` im Projektordner. Das Skript legt für den aktuellen Benutzer die täglichen
Jobs für Backup (02:00 Uhr) und Bereinigung (03:00 Uhr) an, sofern noch keine existieren. Im Crontab steht
nur der Pfad, kein Passwort. Mehrfaches Ausführen ist sicher, ändert aber vorhandene Einträge nicht:
Einträge aus einer älteren Version musst du selbst löschen und neu anlegen lassen.

---

## Fachliche Quellen

Die Fachlogik beruht auf diesen Werken. Sie sind nicht Teil des Repositorys und nicht von dessen
Lizenz erfasst. Verweise im Code nennen das Werk und den Abschnitt, sie sind keine Zitate:

- Deutsche Gesellschaft für Verkehrspsychologie (DGVP) und Deutsche Gesellschaft für Verkehrsmedizin
  (DGVM) (Hrsg.): *Urteilsbildung in der Fahreignungsbegutachtung – Beurteilungskriterien*, 5. Auflage,
  Kirschbaum Verlag, 2026 (BK-5)
- *Fahrerlaubnis-Verordnung* (FeV), *Straßenverkehrsgesetz* (StVG), insbesondere § 24a und seine Anlage, sowie
  *Verordnung über den Betrieb von Kraftfahrunternehmen im Personenverkehr* (BOKraft), § 8
- Bundesanstalt für Straßenwesen: *Begutachtungsleitlinien zur Kraftfahreignung*
- Babor, Higgins-Biddle, Saunders, Monteiro: *AUDIT. The Alcohol Use Disorders Identification Test*,
  2. Auflage, WHO, 2001
- Rumpf, Hapke, John: *LAST. Lübecker Alkoholabhängigkeits- und -missbrauchs-Screening-Test*, Hogrefe, 2001

## Drittkomponenten

Lokal im Repository enthalten, die Lizenztexte liegen neben den Dateien:

| Komponente | Lizenz | Lizenztext |
|---|---|---|
| Tabler UI | MIT | `assets/css/LICENSE-Tabler` |
| Tabler Icons | MIT | `assets/fonts/LICENSE-Tabler-Icons` |
| Inter | SIL Open Font License 1.1 | `assets/fonts/LICENSE-Inter` |
| Cantarell | SIL Open Font License 1.1 | `assets/fonts/LICENSE-Cantarell` |

## Lizenz

© Die Autorinnen und Autoren des MPU-Managers. Lizenziert unter der EUPL v. 1.2, siehe
[LICENSE](LICENSE). Die Software wird ohne Gewährleistung bereitgestellt, soweit gesetzlich
zulässig (Ziffern 7 und 8 der Lizenz). Die Lizenz gilt nicht für die genannten Fachquellen
und nicht für die in [Drittkomponenten](#drittkomponenten) genannten Bestandteile.
