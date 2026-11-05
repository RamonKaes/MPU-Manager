# DESIGN.md — Tabler UI/UX Compliance Audit

> **Referenz:** [Tabler UI v1.4.0](https://preview.tabler.io/) · [Dokumentation](https://docs.tabler.io/)
> **Ziel:** 100 % Einhaltung der Tabler-Komponentenstandards in allen Projektdateien.
> **Format:** Jeder Eintrag ist ein eigenständiger, sofort umsetzbarer Prompt.
> **Stand:** 26.07.2026

---

## ✅ Keine offenen Findings

Alle Findings wurden erfolgreich korrigiert. Nächster Audit-Lauf kann jederzeit gestartet werden.

---

### Erledigte Findings (entfernt)

Folgende 73 Findings aus den vorherigen Versionen wurden erfolgreich korrigiert:

**Runde 1 (22):**
1.1 `bearbeiten.php` eigene Navbar → nutzt jetzt `_navbar.php` ·
1.2 `d-print-footer` → `d-print-block` ·
1.3 `!important` auf Font in `_head.php` → entfernt ·
2.1 `role="document"` auf 25 Modals → entfernt ·
2.2 Modal-Header Inline-Farbe → `modal-status` ·
3.1 `navbar-divider` → korrigiert ·
4.1 Inkonsistente Alert-Strukturen → vereinheitlicht ·
4.2 ICD-Alert ohne `aria-hidden` → ergänzt ·
4.3 Korrespondenz-Alert ohne `aria-hidden` → ergänzt ·
5.1 `bg-default-lt` → `bg-secondary-lt` ·
5.2 Unicode-Emoji in `akte.php` → Tabler-Icons ·
6.1 `font-weight-medium` → `fw-medium` ·
7.1 Inline-Height auf Progress → `progress-xs` ·
8.1 Icon-only Submit-Buttons → `btn-icon` + `aria-label` ·
9.1 Toast z-index → `toast-container` ·
9.2 `text-bg-danger` → `bg-danger text-white` ·
10.1 `table-responsive` Klammerung → korrigiert ·
12.1 `href="#"` auf List-Group-Items → `<div>` ·
13.1 `confirm()` im Korrespondenz-Tab → `data-confirm` ·
14.2 Progress ohne `aria-label` → ergänzt ·
16.1 `bg-light-subtle` → entfernt ·
17.1/17.2 KPI-Row + `for="q"` Mismatch → korrigiert

**Runde 2 (18):**
1.1–1.3 `aria-labelledby` auf 18 Modals → ergänzt ·
2.1 `aria-hidden` auf Icons in `bearbeiten.php` → ergänzt ·
3.1–3.4 `aria-label` auf Icon-only Buttons → ergänzt ·
4.1 `href="#"` Teilnahmebescheinigung → `<button disabled>` ·
4.2 `href="#"` in `sidebar.php` → korrigiert ·
5.1 `onclick confirm()` Widerruf → `data-confirm` ·
5.2 Fehler-Alert in `bearbeiten.php` → Icon ergänzt ·
5.3–5.4 `form-label` auf Widmark-Labels → ergänzt ·
6.1 `align-self:start` Inline-Style → Utility-Klasse ·
7.1 Unicode `⚠` in `index.php` → entfernt ·
7.2 Icon-Spacing `me-1` → `me-2` ·
8.1 ScrollSpy in `anleitung.php` → implementiert

**Runde 3 (14):**
1.1 Skip-Link `id="main-content"` in `bearbeiten.php` → ergänzt ·
2.1 5× Labels `for` in `bearbeiten.php` (Input hat `id`) → ergänzt ·
2.2 5× Labels `for` + Input `id` in `bearbeiten.php` → ergänzt ·
2.3 4× Labels `for` in `aufnahme.php` → ergänzt ·
2.4 5× SORKC-Textareas `id` + Labels `for` in `akte.php` → ergänzt ·
3.1 20× `die()` mit `color:#b91c1c` in 6 Dateien → Error-Template ·
4.1 `font-size:1rem` Inline-Style in `akte.php` → entfernt ·
4.2 3× `cursor:pointer` Inline-Style → CSS-Regel ·
4.3 Redundantes `small` + `font-size:0.75rem` in `aufnahme.php` → bereinigt ·
5.1 `!important` in `error.php` → entfernt ·
6.1 Theme-Init-Script in `_head.php` → inline im `<head>` (FOUC-Fix) ·
6.2 `error.php` Theme-Init → ergänzt ·
6.3 `logout.php` Dark-Mode-Styles → ergänzt ·
7.1 13× `table-danger`/`-warning`/`-info`/`-success` in `anleitung.php` → Dark-Mode-kompatibel

**Runde 4 (1):**
6.1 Theme-Init-Script in `_head.php` ohne CSP-Nonce → `nonce`-Attribut ergänzt

**Runde 5 (7):**
1.1 `for="q"` → `for="suchfeld"` in `klienten.php` ·
1.2 `for="bkf_status"` + `id="bkf_status"` in `bearbeiten.php` → ergänzt ·
2.1 3× 📍-Emoji → `ti ti-map-pin` in `index.php`, `aufnahme.php` ·
3.1 7× `die()` in PDF-Dateien → Error-Template ·
4.1 `akte_druck.php`: Theme-Init, `!important` entfernt, `var(--tblr-body-bg)` ·
5.1 4× Inline `min-width`/`max-width`/`top` → CSS-Klassen ·
6.1 `theme-init.js` gelöscht (Dead Code)

**Runde 6 (8):**
1.1 `onclick="window.print()"` in `akte_druck.php` → `<script nonce>` Event-Listener ·
1.2 Theme-Init-Script in `akte_druck.php` → `nonce`-Attribut ergänzt ·
2.1 `.no-print` global → nur noch in `@media print` ·
2.2 `tabler-icons.min.css` in `akte_druck.php` → eingebunden ·
2.3 3× `die()` in `akte_druck.php` → Error-Template ·
3.1 Icon-Button `aria-label` in `klienten.php` → ergänzt ·
3.2 9 `<td>` / 8 `<th>` in `klienten.php` → 2. `<th>` ergänzt ·
4.1 `white-space: pre-wrap` in `akte.php` → CSS-Klasse

**Runde 7 (1):**
1.1 `javascript:location.reload()` in `error.php` → `href=""` (CSP-Fix)

**Runde 8 (2):**
1.1 Karte „Psychologische Aufarbeitung" in `akte.php`: 2 Icons ohne `.icon`-Klasse (erbten 0,875rem statt
1,25rem) und `.row` ohne `g-2` → an die Aktionsliste in `index.php` angeglichen ·
1.2 Badge des Notizen-Tabs in `akte.php`: deckendes `bg-danger` als einziges von neun → `bg-danger-lt`,
zusätzlich `title` ergänzt (Badge enthält nur ein `aria-hidden`-Icon und war für Screenreader stumm)
