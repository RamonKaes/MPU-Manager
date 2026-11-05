# Sicherheitshinweise

## Schwachstellen

Dieses Repository ist archiviert und wird nicht gepflegt. Meldungen werden nicht entgegengenommen und Fixes nicht
veröffentlicht. Schwachstellen behebt jeder in seinem eigenen Fork. Wer die Software einsetzt, ist selbst für
Updates und Absicherung verantwortlich.

## Betrieb

Die Software verarbeitet Gesundheitsdaten nach Art. 9 DSGVO. Dieses Repository ist nicht für den direkten
Produktiveinsatz gedacht, sondern zum Forken. Vor dem Einsatz eines eigenen Forks gilt:

- **Nur über HTTPS betreiben** und `APP_FORCE_HTTPS=1` setzen.
- **Apache mit aktivem `.htaccess` und `mod_rewrite` verwenden.** Der Zugriffsschutz für `config/`, `logic/`,
  `handlers/`, `partials/` und `scripts/` liegt in `.htaccess`. Außerdem liefert sie nur `php`, `css`, `js`, `woff2`, `svg`, `png` und `ico`
  aus, alles andere ist gesperrt, auch Dumps und Kopien im Webroot. Andere Webserver wie nginx werten diese Datei
  nicht aus und müssen dieselben Regeln selbst umsetzen, sonst sind diese Dateien unter Umständen öffentlich abrufbar.
- **Eigene Benutzer anlegen:** `config/users.php.example` enthält keinen gültigen Hash, ohne eigene
  Einträge ist keine Anmeldung möglich.
- **Datensicherung, Löschfristen und Zugriffsrechte** selbst einrichten und prüfen. Die
  datenschutzrechtliche Verantwortung liegt bei der jeweiligen Beratungsstelle.
