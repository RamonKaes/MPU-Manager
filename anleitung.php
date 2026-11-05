<?php

declare(strict_types=1);

require_once 'db.php';

// UX-3: ICD-Schwelle fuer die Erlaeuterungstexte aus derselben Quelle wie die
// Engine lesen (config/triage_config.php) statt sie in Prosa zu wiederholen.
$_icd_cfg               = require 'config/triage_config.php';
$_icd_abhaengigkeit_min = (int)($_icd_cfg['icd']['abhaengigkeit_min'] ?? 3);

// R3-2: Kreatinin-Schwellen aus config/ctu_config.php. Zwei Schwellen mit
// unterschiedlicher Folge, nie zu einer zusammenziehen.
// L1: THC-COOH-Schwellen für das Glossar. Das Glossar nannte „≥ 150", die
// Engine vergleicht `> 150` (triage.php:376) bzw. `> 75` (Z. 392) — bei exakt
// 150 ng/ml rechnet sie D3 (6 Monate), der Text sagte D2 (12 Monate).
$_cooh_regelkonsum = (int)$_icd_cfg['thc']['cooh_regelkonsum'];
$_cooh_gefaehrdung = (int)$_icd_cfg['thc']['cooh_gefaehrdung'];
$_thc_grenzwert    = number_format((float)$_icd_cfg['thc']['grenzwert_ng_ml'], 1, ',', '');
$_thc_einzelfall   = (int)$_icd_cfg['thc']['trennvermoegen_ng_ml'];

$_ctu_cfg          = require 'config/ctu_config.php';
$_kreat_verwertbar = (float)$_ctu_cfg['kreatinin']['verwertbar_ab_mg_dl'];
$_peth_grenze      = rtrim(rtrim(number_format((float)$_ctu_cfg['peth']['abstinenz_unter_ng_ml'], 1, ',', ''), '0'), ',');
$_kreat_kein_neg   = (float)$_ctu_cfg['kreatinin']['kein_negativbefund_bis_mg_dl'];

// Laborbefunde kommen in g/l, das Eingabefeld rechnet in mg/dl (Faktor 100).
// Beide Einheiten ausweisen, sonst tippt der Berater den Befund ungerechnet ein.
$_mg_dl = static fn(float $wert): string => rtrim(rtrim(number_format($wert, 1, ',', '.'), '0'), ',');
$_g_l   = static fn(float $wert): string => rtrim(number_format($wert / 100, 3, ',', '.'), '0');

$page_title = 'Hilfe & Anleitung – MPU-Manager';
require 'partials/_head.php';
$active_page = 'anleitung';
require 'partials/_navbar.php';

?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Dokumentation</div>
                            <h1 class="page-title">Benutzerhandbuch</h1>
                            <p class="text-secondary">Vollständige Anleitung für den MPU-Manager</p>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    <div class="row g-3">
                        <!-- Sidebar: Inhaltsverzeichnis -->
                        <div class="col-lg-3 mb-3">
                            <div class="card sticky-top">
                                <div class="card-body">
                                    <h2 class="card-title mb-3"><i class="ti ti-book me-2 text-primary" aria-hidden="true"></i>Inhaltsverzeichnis</h2>
                                    <nav id="seitennavigation" class="nav flex-column nav-pills">
                                        <a href="#start" class="nav-link">Erste Schritte</a>
                                        <a href="#klienten" class="nav-link">Klientenverwaltung</a>
                                        <a href="#faelle" class="nav-link">Fallverwaltung</a>
                                        <a href="#akte" class="nav-link">Die Klientenakte</a>
                                        <a href="#bkf" class="nav-link">Berufskraftfahrer (BKF)</a>
                                        <a href="#sitzungen" class="nav-link">Sitzungsprotokolle</a>
                                        <a href="#abstinenz" class="nav-link">Abstinenz-Nachweise</a>
                                        <a href="#checklisten" class="nav-link">Aufgaben &amp; Checklisten</a>
                                        <a href="#finanzen" class="nav-link">Finanzen</a>
                                        <a href="#pdf" class="nav-link">PDF-Export</a>
                                        <a href="#faq" class="nav-link">FAQ</a>
                                        <a href="#glossar" class="nav-link">Glossar</a>
                                        <a href="#troubleshooting" class="nav-link">Troubleshooting</a>
                                        <a href="#tutorial" class="nav-link">Tutorial: Kompletter Fall</a>
                                        <a href="#best-practices" class="nav-link">Best Practices</a>
                                    </nav>
                                </div>
                            </div>
                        </div>

                        <!-- Hauptinhalt -->
                        <div class="col-lg-9" data-bs-spy="scroll" data-bs-target="#seitennavigation" tabindex="0">

                            <!-- 1. START -->
                            <div class="card mb-4" id="start">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-rocket me-2 text-primary" aria-hidden="true"></i>Erste Schritte</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Willkommen!</h3>
                                    <p>Der <strong>MPU-Manager</strong> ist eine spezialisierte Verwaltungssoftware für MPU-Beratungsstellen. Er hilft dir bei der Verwaltung von Klienten, Fällen, Sitzungen und Abstinenz-Nachweisen.</p>

                                    <h3 class="mt-4">Das Menü auf einen Blick</h3>
                                    <ul>
                                        <li><strong>Startseite:</strong> Dashboard mit Fristen-Warnungen, KPIs und anstehenden MPU-Terminen.</li>
                                        <li><strong>Klienten:</strong> Übersicht, Suche sowie Anlage von Klienten und Fällen.</li>
                                        <li><strong>Finanzen:</strong> Pakete, Zahlungen und Monatsumsätze.</li>
                                        <li><strong>Ressourcen:</strong> Regionale Referenzliste – Behörden, Begutachtungsstellen (BfF), Labore und Formulare.</li>
                                        <li><strong>Hilfe &amp; Anleitung:</strong> Dieses Handbuch.</li>
                                        <li><strong>Klienten-Leitfaden:</strong> Druckbare Anleitung für Klienten – der gesamte Weg von der Tat bis zur bestandenen MPU inklusive aller Fristen. Zum Aushändigen in der Erstberatung.</li>
                                    </ul>

                                    <h3 class="mt-4">Die Datenstruktur auf einen Blick</h3>
                                    <p>Alles in der Software baut aufeinander auf – verstehe diese Hierarchie, bevor du startest:</p>
                                    <div class="alert alert-info py-2 mb-2">
                                        <code><i class="ti ti-users me-1" aria-hidden="true"></i>Klient</code>
                                        <span class="text-secondary mx-2">→</span>
                                        <code><i class="ti ti-folder me-1" aria-hidden="true"></i>Fall (Delikt + Triage)</code>
                                        <span class="text-secondary mx-2">→</span>
                                        <code><i class="ti ti-message me-1" aria-hidden="true"></i>Sitzungen</code>
                                        <span class="text-secondary mx-2">&amp;</span>
                                        <code><i class="ti ti-flask me-1" aria-hidden="true"></i>CTU-Proben</code>
                                    </div>
                                    <p class="text-secondary small mb-3">Sitzungen und CTU-Proben kannst du <strong>nur innerhalb eines Falls</strong> anlegen. Ohne Fall gibt es keinen Platz für Dokumentation. Ein Klient kann mehrere Fälle haben (z.B. zuerst ein Alkohol-Delikt, später ein Drogen-Delikt).</p>

                                    <h3 class="mt-4">5-Minuten-Quickstart</h3>
                                    <ol>
                                        <li><strong>Klient anlegen:</strong> Menü „Klienten" → „Neue Klientin/Neuer Klient" → Name eingeben → Speichern</li>
                                        <li><strong>Fall erstellen:</strong> Klientenprofil öffnen → „Neuer Fall" → <em>Delikttyp, BAK-Wert, Behörde</em> eingeben → Speichern</li>
                                        <li><strong>Triage lesen:</strong> Die Software berechnet sofort Hypothese + Abstinenzfrist aus deinen Eingaben (Delikttyp, BAK, ICD-Score, BKF-Flag)</li>
                                        <li><strong>Sitzung dokumentieren:</strong> Fall öffnen → „Neue Sitzung" → Phase wählen → SORKC ausfüllen → Speichern</li>
                                        <li><strong>CTU-Probe eintragen:</strong> Fall öffnen → „Neue Abstinenzprobe" → Urin/Haar + Laborwerte → Speichern</li>
                                        <li><strong>Dashboard nutzen:</strong> Zeigt automatisch Warnungen, wenn Fristen nahen oder Lücken erkannt werden</li>
                                    </ol>

                                    <div class="alert alert-info" role="note">
                                        <div class="alert-icon"><i class="ti ti-hourglass icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Was tun, wenn beim Erstkontakt Angaben fehlen?</h4>
                                            <div class="alert-description">
                                                <p class="mb-2">Am Telefon liegen die 6 ICD-Kriterien selten vollständig vor. Das ist kein Grund, die Aufnahme
                                                zu verschieben: Setze unbekannte Kriterien auf &bdquo;&mdash;&ldquo; (nicht erhoben) und lege den Fall an.
                                                Bewertet werden kann nur, was bekannt ist.</p>
                                                <p class="mb-0">Der Fall wird dann in Akte und Klientenliste als
                                                <span class="badge bg-warning-lt"><i class="ti ti-hourglass" aria-hidden="true"></i> Vorl&auml;ufig</span> gekennzeichnet und
                                                erscheint unter &bdquo;N&auml;chste Schritte&ldquo;, bis du die fehlenden Kriterien nachgetragen hast.
                                                Wichtig zu wissen: nicht erhobene Kriterien z&auml;hlen in der Berechnung als &bdquo;nicht erf&uuml;llt&ldquo;.
                                                Die angezeigte Hypothese ist deshalb eine <strong>Untergrenze</strong> &ndash; sie kann sich beim
                                                Nachtragen versch&auml;rfen, aber nie abschw&auml;chen.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Dashboard verstehen</h3>
                                    <p>Das Startseiten-Dashboard ist deine Kommandozentrale. Hier siehst du auf einen Blick:</p>

                                    <h4 class="mt-3"><i class="ti ti-alert-triangle text-danger" aria-hidden="true"></i> Dringende Fristen &amp; Warnungen</h4>
                                    <p>Zeigt <strong>rote Warnungen</strong> für Sperrfristen, die in den nächsten 12 Wochen enden. <strong>Warum wichtig?</strong> Wenn die Sperrfrist endet, kann der Klient theoretisch wieder fahren – ohne MPU ist das illegal und könnte zu weiteren Problemen führen.</p>
                                    <p><strong>Beispiel-Warnung:</strong> „<i class="ti ti-alert-triangle text-warning" aria-hidden="true"></i> Anna Schmidt – Sperrfrist endet in 8 Wochen (15.06.2026)" → Du solltest die Beratung beschleunigen!</p>

                                    <h4 class="mt-3"><i class="ti ti-scale text-warning" aria-hidden="true"></i> Einspruchsfrist-Radar</h4>
                                    <p>Zeigt Bußgeldbescheide mit Fristen ≤ 7 Tagen. <strong>Kritisch!</strong> Nach Ablauf kann kein Einspruch mehr eingelegt werden. Informiere den Klienten sofort!</p>

                                    <h4 class="mt-3"><i class="ti ti-chart-bar text-info" aria-hidden="true"></i> KPI-Statistiken (Key Performance Indicators)</h4>
                                    <ul>
                                        <li><strong>Gesamtklienten:</strong> Alle registrierten Klienten (inkl. abgeschlossene Fälle)</li>
                                        <li><strong>Aktive Fälle:</strong> Fälle mit Status „aktiv" oder „in Beratung"</li>
                                        <li><strong>Umsatz (Monat):</strong> Summe aller Zahlungen im aktuellen Monat</li>
                                        <li><strong>Fehlende Dokumente:</strong> Anzahl offener Checklisten-Punkte (z.B. fehlende Abstinenz-Nachweise)</li>
                                        <li><strong>Offene MPU-Anträge:</strong> Fälle, bei denen der Antrag gestellt, aber noch kein Termin bekannt ist</li>
                                    </ul>

                                    <h4 class="mt-3"><i class="ti ti-clipboard-list text-primary" aria-hidden="true"></i> Klienten-Tabelle</h4>
                                    <p>Zeigt die wichtigsten Klienten-Infos:</p>
                                    <ul>
                                        <li><strong>Name:</strong> Vor- und Nachname</li>
                                        <li><strong>Aktueller Fall:</strong> Delikttyp (Alkohol/Drogen/Verkehr) + Hypothese (z.B. „A2")</li>
                                        <li><strong>Sperrfrist:</strong> Enddatum der Sperre (rot = kritisch)</li>
                                        <li><strong>Nächste Sitzung:</strong> Geplanter Termin (falls eingegeben)</li>
                                        <li><strong>Status:</strong> „Aktiv", „In Beratung", „MPU bestanden", etc.</li>
                                    </ul>

                                    <h4 class="mt-3"><i class="ti ti-calendar text-success" aria-hidden="true"></i> MPU-Termine</h4>
                                    <p>Zeigt geplante MPU-Termine in den nächsten 6 Monaten. Nutze das, um zu prüfen, ob die Beratung rechtzeitig abgeschlossen werden kann.</p>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp:</h4>
                                            <div class="alert-description">Das Dashboard ist dein Ausgangspunkt. Überprüfe es täglich, besonders die roten Warnungen bei Sperrfristen!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. KLIENTENVERWALTUNG -->
                            <div class="card mb-4" id="klienten">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-users me-2 text-primary" aria-hidden="true"></i>Klientenverwaltung</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Neue Klientin/Neuer Klient anlegen</h3>
                                    <ol>
                                        <li>Klicke auf <strong>„Klienten"</strong> im Menü</li>
                                        <li>Klicke <strong>„Neue Klientin/Neuer Klient"</strong></li>
                                        <li>Fülle die Stammdaten aus</li>
                                        <li>Klicke <strong>„Speichern"</strong></li>
                                    </ol>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Feld</th>
                                                    <th>Erforderlich?</th>
                                                    <th>Beispiel</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Vorname</strong></td>
                                                    <td><span class="badge bg-green text-green-fg">Ja</span></td>
                                                    <td>Anna</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Nachname</strong></td>
                                                    <td><span class="badge bg-green text-green-fg">Ja</span></td>
                                                    <td>Schmidt</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Geburtsdatum</strong></td>
                                                    <td><span class="badge bg-secondary-lt">Nein</span></td>
                                                    <td>1990-03-15</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Geschlecht</strong></td>
                                                    <td><span class="badge bg-secondary-lt">Nein</span></td>
                                                    <td>weiblich, männlich, divers</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>E-Mail</strong></td>
                                                    <td><span class="badge bg-secondary-lt">Nein</span></td>
                                                    <td>anna@example.com</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Klient suchen</h3>
                                    <p>Nutze die <strong>Suchbox</strong> auf dem Dashboard. Sie sucht nach:</p>
                                    <ul>
                                        <li>Vorname (z. B. „Anna")</li>
                                        <li>Nachname (z. B. „Schmidt")</li>
                                        <li>Vollname (z. B. „Anna Schmidt")</li>
                                        <li>Aktenzeichen der Fälle (z. B. „FZB-2024-123")</li>
                                    </ul>

                                    <h3 class="mt-4">DSGVO-Einwilligung & Datenschutz</h3>
                                    <p>Auf dem Klientenprofil siehst du den <strong>DSGVO-Einwilligungs-Block</strong>:</p>
                                    <ul>
                                        <li><strong>Status:</strong> „Einwilligung erteilt" oder „Nicht erteilt"</li>
                                        <li><strong>Eingangsdatum:</strong> Wann wurde unterschrieben?</li>
                                        <li><strong>PDF-Link:</strong> Datenschutzerklärung zum Ausdrucken</li>
                                    </ul>

                                    <div class="alert alert-warning" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Wichtig:</h4>
                                            <div class="alert-description">Stelle sicher, dass die Datenschutzerklärung unterschrieben ist, bevor du persönliche Daten verarbeitest!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 3. FALLVERWALTUNG -->
                            <div class="card mb-4" id="faelle">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-folder me-2 text-primary" aria-hidden="true"></i>Fallverwaltung &amp; Triage (BK-5)</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Was ist ein Fall?</h3>
                                    <p>Ein <strong>Fall</strong> ist eine <strong>MPU-Episode</strong> einer Klientin/eines Klient. Eine Person kann <strong>mehrere Fälle</strong> haben:</p>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Beispiel:</h4>
                                            <div class="alert-description">
                                                Anna Schmidt hat 2 Fälle:
                                                <ul class="mb-0">
                                                    <li>Fall 1: Alkoholdelikt 2024 (abgeschlossen)</li>
                                                    <li>Fall 2: Drogendelikt 2025 (aktuell)</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Neuen Fall anlegen</h3>
                                    <ol>
                                        <li>Öffne eine Klientin/einen Klient</li>
                                        <li>Klicke <strong>„Neuer Fall"</strong></li>
                                        <li>Fülle die Falldaten aus</li>
                                    </ol>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Feld</th>
                                                    <th>Erforderlich?</th>
                                                    <th>Beispiel</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Delikttyp</strong></td>
                                                    <td><span class="badge bg-green text-green-fg">Ja</span></td>
                                                    <td>Alkohol, Drogen, Verkehr, Medikation</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Behördenname</strong></td>
                                                    <td><span class="badge bg-green text-green-fg">Ja</span></td>
                                                    <td>Fahrerlaubnisbehörde München</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Aktenzeichen</strong></td>
                                                    <td><span class="badge bg-green text-green-fg">Ja</span></td>
                                                    <td>FZB-2025-123456</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Sperrfristende</strong></td>
                                                    <td><span class="badge bg-secondary-lt">Nein</span></td>
                                                    <td>2026-06-15</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>MPU-Zieldatum</strong></td>
                                                    <td><span class="badge bg-secondary-lt">Nein</span></td>
                                                    <td>2026-08-20</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Automatische Triage (BK-5 2026)</h3>
                                    <p>Nachdem du einen Fall anlegst, <strong>berechnet die Software automatisch</strong>:</p>
                                    <ul>
                                        <li><strong>Hypothese</strong> – z. B. A1, A2, D1, D2, V3</li>
                                        <li><strong>Abstinenzanforderung</strong> – z. B. „15 Monate Abstinenz" oder „6 Monate Stabilitätsnachweis"</li>
                                    </ul>

                                    <h4 class="mt-3">Was bedeuten die Hypothesen?</h4>
                                    <p><strong>BK-5</strong> sind die "Beurteilungskriterien für Fahreignung" (5. Auflage, 2026) – der wissenschaftliche Standard für MPU-Gutachter in Deutschland.</p>
                                    
                                    <div class="table-responsive mt-3">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>Hypothese</th>
                                                    <th>Bedeutung</th>
                                                    <th>Abstinenz</th>
                                                    <th>Typisch bei</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr class="table-danger">
                                                    <td><strong>A1</strong></td>
                                                    <td>Alkoholabhängigkeit</td>
                                                    <td>15 Monate</td>
                                                    <td>Diagnose Abhängigkeit, mehrfache Vorfälle</td>
                                                </tr>
                                                <tr class="table-warning">
                                                    <td><strong>A2</strong></td>
                                                    <td>Fortgeschrittene Alkoholproblematik</td>
                                                    <td>12 Mon. (Therapie) / 15 Mon. (ohne)</td>
                                                    <td>BAK ≥ 2,0 ‰ (Giftfestigkeit)</td>
                                                </tr>
                                                <tr class="table-info">
                                                    <td><strong>A3</strong></td>
                                                    <td>Alkoholgefährdung</td>
                                                    <td>6 Monate</td>
                                                    <td>BAK 1,1–2,0 ‰, Ersttäter</td>
                                                </tr>
                                                <tr class="table-success">
                                                    <td><strong>A4</strong></td>
                                                    <td>Trennungsvermögen fehlt</td>
                                                    <td>Keine (6 Mon. Stabilität)</td>
                                                    <td>BAK < 1,1 ‰</td>
                                                </tr>
                                                <tr class="table-danger">
                                                    <td><strong>D1</strong></td>
                                                    <td>Drogenabhängigkeit</td>
                                                    <td>15 Monate (einheitlich, Ind. 7 &amp; 10)</td>
                                                    <td>Diagnose nach DSM-5/ICD-10; harte Drogen, Mehrfachkonsum</td>
                                                </tr>
                                                <tr class="table-warning">
                                                    <td><strong>D2</strong></td>
                                                    <td>Fortg. Drogenproblematik</td>
                                                    <td>12 Mon. (Standard) / 15 Mon. (erschwerend)</td>
                                                    <td>THC-COOH im Blutserum &gt; <?= $_cooh_regelkonsum ?> ng/ml (regelmäßiger Konsum)</td>
                                                </tr>
                                                <tr class="table-info">
                                                    <td><strong>D3</strong></td>
                                                    <td>Drogengefährdung</td>
                                                    <td>6 Mon. / <strong>0 Mon. Abstinenz bei reinem Cannabis-Delikt ohne BKF, wenn die Laborwerte dazu passen</strong></td>
                                                    <td>THC-COOH im Blutserum <?= $_cooh_gefaehrdung ?>–<?= $_cooh_regelkonsum ?> ng/ml (häufigerer Konsum); gelegentl. Konsum; Cannabis-Neuerung BK-5</td>
                                                </tr>
                                                <tr class="table-success">
                                                    <td><strong>D4</strong></td>
                                                    <td>Trennungsvermögen Cannabis (CanG)</td>
                                                    <td>Kein Abstinenzgebot – 6 Mon. Trennungsnachweis</td>
                                                    <td>THC ≤ 3,5 ng/ml im Serum; reines Cannabis-Delikt</td>
                                                </tr>
                                                <tr class="table-secondary">
                                                    <td><strong>V1</strong></td>
                                                    <td>Persönlichkeitsproblematik (Verkehr)</td>
                                                    <td>12 Monate Veränderungsprozess</td>
                                                    <td>Schwere Gewalt-/Sexualdelikte im Straßenverkehr; Impulskontrolle</td>
                                                </tr>
                                                <tr class="table-secondary">
                                                    <td><strong>V2</strong></td>
                                                    <td>Verfestigte Verhaltensmuster</td>
                                                    <td>6–12 Monate Bewährung</td>
                                                    <td>Raser/Poser; wiederholte grobe Verstöße</td>
                                                </tr>
                                                <tr class="table-secondary">
                                                    <td><strong>V3</strong></td>
                                                    <td>Fehleinstellungen (Punkte)</td>
                                                    <td>6 Monate stabile Bewährung</td>
                                                    <td>Falsche Überzeugungen, Punktekarte Flensburg</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>M1</strong></td>
                                                    <td>Dauermedikation / Medizinalcannabis</td>
                                                    <td>Kein Abstinenzgebot – Leistungstest PR ≥ 16 (Gr. 1) / PR ≥ 33 (BKF)</td>
                                                    <td>Therapie-Compliance nachgewiesen; ärztl. Verordnung</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>M2/M3</strong></td>
                                                    <td>Medikamentenmissbrauch</td>
                                                    <td>12 Mon. (Therapie) / 15 Mon. (Selbstheiler) – analog D-Kriterien</td>
                                                    <td>Missbrauch verschreibungspfl. Mittel (Benzodiazepine, Opioide etc.)</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Beispiel:</h4>
                                            <div class="alert-description">
                                                Delikt: Alkohol + BAK 2,1 ‰<br>
                                                → Hypothese: <strong>A2</strong> (Fortgeschrittene Alkoholproblematik)<br>
                                                → Abstinenz: <strong>12 Monate (mit Therapie)</strong> oder <strong>15 Monate (ohne)</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hintergrundinfos Gruppe A -->
                                    <h4 class="mt-4 text-danger"><i class="ti ti-beer me-2" aria-hidden="true"></i>Hintergrund: Hypothesengruppe A – Alkohol (BK-5 Kap. B 1.1)</h4>
                                    <div class="accordion" id="accordionA">
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a1detail">A1 – Alkoholabhängigkeit</button>
                                            </h5>
                                            <div id="a1detail" class="accordion-collapse collapse" data-bs-parent="#accordionA">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Klinisch manifestierte Abhängigkeit oder schwere Substanzgebrauchsstörung nach DSM-5/ICD-10 (ICD-Score ≥ <?= $_icd_abhaengigkeit_min ?> von 6 Kriterien). Typische Zeichen: Kontrollverlust, Entzugserscheinungen, Toleranzentwicklung, sozialer Rückzug.</p>
                                                    <p><strong>BK-5-Referenz:</strong> Kapitel B 1.1, Kriterium A 1.3 N.</p>
                                                    <p><strong>Abstinenzanforderung:</strong></p>
                                                    <ul>
                                                        <li><strong>Mit stationärer Therapie:</strong> 15 Monate Gesamtabstinenz (davon mind. 6 Monate nach Therapieabschluss nachgewiesen).</li>
                                                        <li><strong>Selbstheiler (ohne Therapie):</strong> Zwingend <strong>15 Monate</strong> lückenlos dokumentierte Abstinenz – kein Spielraum.</li>
                                                    </ul>
                                                    <p><strong>Historischer Fehler:</strong> Früher wurden im Code 60 Monate verwendet. Dieser Wert stammt aus der juristischen Tilgungsfrist (§ 29 StVG) – er hat keine medizinische Grundlage. BK-5 bestätigt: maximal 15 Monate.</p>
                                                    <div class="alert alert-warning py-2 mb-0"><small><i class="ti ti-alert-triangle me-1" aria-hidden="true"></i> Bei BAK ≥ 1,6 ‰ mit Zeichen ausgeprägter Toleranz kann die Triage-Engine auch A2 anzeigen – prüfe im Einzelfall!</small></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2detail">A2 – Fortgeschrittene Alkoholproblematik (Giftfestigkeit)</button>
                                            </h5>
                                            <div id="a2detail" class="accordion-collapse collapse" data-bs-parent="#accordionA">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Ausgeprägte Giftfestigkeit – der Klient war bei BAK ≥ 2,0 ‰ noch handlungs- und steuerungsfähig. Dies belegt eine massive Toleranzentwicklung und den Verlust zuverlässiger Kontrolle.</p>
                                                    <p><strong>Schwellenwert:</strong> BAK ≥ 2,0 ‰ (BK-5, Indikator A2.2N (4)). Hinweis: In der BK-4 lag die Grenze noch bei 1,6 ‰ – dieser Wert ist seit März 2026 veraltet.</p>
                                                    <p><strong>Abstinenzanforderung:</strong></p>
                                                    <ul>
                                                        <li><strong>Mit Therapie (ambulant oder stationär):</strong> 12 Monate.</li>
                                                        <li><strong>Selbstheiler:</strong> 15 Monate.</li>
                                                        <li><strong>Kontrolliertes Trinken (KT):</strong> Nur in seltenen, begründeten Ausnahmefällen (A 2.7 N) nach 6-monatiger Erprobungsphase möglich. <em>Nicht für BKF geeignet.</em></li>
                                                    </ul>
                                                    <p><strong>Triage-Logik:</strong> Die Software setzt A2, wenn <code>bak_wert ≥ 2,0</code> und kein ICD-Score-Abhängigkeitsbefund vorliegt. Bei ICD-Score ≥ 3 greift A1.</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3detail">A3 – Alkoholgefährdung (gesteigerte Gewöhnung)</button>
                                            </h5>
                                            <div id="a3detail" class="accordion-collapse collapse" data-bs-parent="#accordionA">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Gesteigerte Gewöhnung ohne verfestigte Abhängigkeit. Der Klient trinkt problematisch, hat aber noch Kontrollpotenzial.</p>
                                                    <p><strong>Schwellenwerte:</strong> BAK 1,1–2,0 ‰. Bei BAK 1,6–2,0 ‰ liegt eine „ausgeprägte Toleranz" vor (BK-5, Indikator A3.1K (8)) – die Exploration muss besonders sorgfältig erfolgen.</p>
                                                    <p><strong>Abstinenzanforderung:</strong> 6 Monate Nachweis einer Verhaltensänderung. Instrument: Trinktagebuch, PEth-Kontrollen.</p>
                                                    <p><strong>Alternative:</strong> Kontrolliertes Trinken ist bei A3 grundsätzlich möglich, wenn nach 6 Monaten Erprobung stabile Kontrolle belegt ist.</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4detail">A4 – Trennungsvermögen fehlt (Situatives Trennungsdefizit)</button>
                                            </h5>
                                            <div id="a4detail" class="accordion-collapse collapse" data-bs-parent="#accordionA">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Kein Abhängigkeitsproblem, aber fehlende Fähigkeit oder Bereitschaft, situativ zwischen Konsum und Fahrt zu trennen. Typisch bei niedrigen BAK-Werten (&lt; 1,1 ‰) ohne andere Auffälligkeiten.</p>
                                                    <p><strong>Abstinenzanforderung:</strong> <strong>Kein Abstinenzgebot</strong>, aber 6 Monate Nachweis stabiler Trennstrategien (z.B. kein Fahren nach Alkohol, Trinktagebuch).</p>
                                                    <p><strong>Wichtiger Hinweis (Audit-Korrektur 13.04.2026):</strong> Eine frühere Anforderung von 10 Monaten für BKF war fachlich falsch. Die BK-5 bestätigt ausdrücklich die 6-Monats-Frist auch für Berufskraftfahrer – lediglich die Prüfdichte der Exploration ist erhöht.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hintergrundinfos Gruppe D -->
                                    <h4 class="mt-4 text-warning"><i class="ti ti-leaf me-2" aria-hidden="true"></i>Hintergrund: Hypothesengruppe D – Drogen (BK-5 Kap. B 3)</h4>
                                    <div class="accordion" id="accordionD">
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d1detail">D1 – Drogenabhängigkeit</button>
                                            </h5>
                                            <div id="d1detail" class="accordion-collapse collapse" data-bs-parent="#accordionD">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Klinisch manifeste Abhängigkeit nach DSM-5/ICD-10. Betrifft alle Drogen: Cannabis bei Schwerstkonsum, harte Drogen (Kokain, Heroin, Amphetamine, MDMA), Mischkonsum.</p>
                                                    <p><strong>BK-5-Referenz:</strong> Kapitel B 3, Kriterien D 1.1 N bis D 1.3 N.</p>
                                                    <p><strong>Abstinenzanforderung (BK-5 Indikator 7 &amp; 10):</strong> Einheitlich <strong>15 Monate</strong> – unabhängig von der Therapieform.</p>
                                                    <ul>
                                                        <li><strong>Mit Therapie (Indikator 7):</strong> 15 Monate Gesamtabstinenz, davon mind. 6 Monate nach Therapieende nachgewiesen.</li>
                                                        <li><strong>Selbstheiler ohne formale Therapie (Indikator 10):</strong> 15 Monate lückenlos nachgewiesene Abstinenz.</li>
                                                    </ul>
                                                    <p><strong>Triage-Logik:</strong> D1 ergibt in allen Fällen 15 Monate. Der Parameter <code>therapie_art</code> steuert nur noch die Formulierung des Therapienachweises, nicht die Dauer (Korrektur Z-6 vom 27.04.2026 – der frühere 12-Monats-Wert für stationäre Therapie war fachlich unbelegt).</p>
                                                    <p><strong>Historischer Fehler:</strong> Früher wurden 24/36/60 Monate verwendet – diese Werte haben keine Grundlage in den BK (1–5). BK-5 bestätigt: maximal 15 Monate.</p>
                                                    <p><strong>Besonderheit harte Drogen:</strong> Bereits ein einmaliger Labor-Nachweis von Kokain, Heroin, Amphetaminen oder MDMA führt in der Regel zur MPU. Die Dokumentation muss lückenlos sein.</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d2detail">D2 – Fortgeschrittene Drogenproblematik</button>
                                            </h5>
                                            <div id="d2detail" class="accordion-collapse collapse" data-bs-parent="#accordionD">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Problematischer Konsum ohne manifeste Abhängigkeit, aber mit Kontrollverlust oder Konsum zur Problembewältigung.</p>
                                                    <p><strong>Schlüsselschwellenwert:</strong> THC-COOH im Blutserum &gt; <?= $_cooh_regelkonsum ?> ng/ml – spricht für regelmäßigen Konsum (Positionspapier DGVP/DGVM Nr. 12), eine gesetzliche Schwelle ist das nicht.</p>
                                                    <p><strong>Abstinenzanforderung:</strong> <strong>12 Monate</strong> im Standardfall, <strong>15 Monate bei erschwerenden Faktoren</strong>. Erschwerend sind: Mischkonsum mit Alkohol, gescheiterte frühere Abstinenzversuche, schwerwiegende soziale Folgen des Konsums.</p>
                                                    <p><strong>So rechnet die Software:</strong> Der Split 12/15 hängt bei D2 an den <em>erschwerenden Faktoren</em> (Feld im Formular), nicht an der Therapie. Mischkonsum mit Alkohol gilt automatisch als erschwerend. Ob die BK-5 den Split anders zieht, ist am Buch zu prüfen.</p>
                                                    <p><strong>Wichtig:</strong> Bei D2 ist kontrollierter Cannabis-Konsum <em>ausgeschlossen</em> – volle Abstinenz zwingend.</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d3detail">D3 – Drogengefährdung (Cannabis-Neuerung BK-5!)</button>
                                            </h5>
                                            <div id="d3detail" class="accordion-collapse collapse" data-bs-parent="#accordionD">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Gelegentlicher Konsum oder Probierphase ohne Abhängigkeitszeichen.</p>
                                                    <p><strong>Schwellenwert:</strong> THC-COOH im Blutserum <?= $_cooh_gefaehrdung ?>–<?= $_cooh_regelkonsum ?> ng/ml (häufigerer Konsum, D4 wird nicht vorgeschlagen); oder THC ab <?= $_thc_grenzwert ?> ng/ml im Serum (Trennvermögen fraglich, über <?= $_thc_einzelfall ?> ng/ml spricht der Wert im Einzelfall dagegen). Fehlen beide Werte, ist die Einstufung vorläufig.</p>
                                                    <p><strong>Abstinenzanforderung:</strong></p>
                                                    <ul>
                                                        <li><strong>Andere Drogen:</strong> 6 Monate Abstinenz.</li>
                                                        <li><strong>Cannabis – NEU in BK-5:</strong> Bei einem reinen Cannabis-Delikt ohne Begleitsubstanzen, ohne BKF-Status und mit nachgewiesen stabilem Konsummuster: <strong>0 Monate Abstinenz</strong> bei kontrolliertem Konsum möglich (Konsumtagebuch + ggf. THC-Monitoring). Dies ist die erste MPU-Anerkennung von kontrolliertem Cannabis-Konsum analog zum kontrollierten Trinken bei A3.</li>
                                                        <li><strong>Cannabis mit BKF-Status oder Mischkonsum:</strong> Weiterhin 6 Monate Abstinenz.</li>
                                                    </ul>
                                                    <p><strong>Triage-Logik:</strong> Die Software erkennt reine Cannabis-Delikte ohne BKF-Flag und setzt Abstinenz = 0 Monate mit Hinweis auf Konsumtagebuch-Pflicht.</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item">
                                            <h5 class="accordion-header">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#d4detail">D4 – Trennungsvermögen Cannabis (CanG 2024)</button>
                                            </h5>
                                            <div id="d4detail" class="accordion-collapse collapse" data-bs-parent="#accordionD">
                                                <div class="accordion-body">
                                                    <p><strong>Klinisches Bild:</strong> Kein Abhängigkeitsproblem, kein Regelkonsum – aber fehlende Trennung zwischen Cannabis-Konsum und Fahren. Tritt seit dem Cannabis-Gesetz (CanG, 05.07.2024) häufiger auf.</p>
                                                    <p><strong>Schwellenwert:</strong> THC unter 3,5 ng/ml im Serum (§ 24a Abs. 1a StVG gilt ab 3,5 ng/ml). Kein Abstinenzgebot.</p>
                                                    <p><strong>Abstinenzanforderung:</strong> Kein Abstinenzgebot, aber <strong>6 Monate Nachweis stabilen Trennverhaltens</strong> (kein Fahren nach Konsum, Konsumtagebuch, THC-Monitoring).</p>
                                                    <p><strong>Wichtig:</strong> D4 ist ausgeschlossen, wenn THC-COOH &gt; 75 ng/ml nachgewiesen ist (dann mindestens D3).</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Hintergrundinfos Gruppe V & M -->
                                    <h4 class="mt-4 text-secondary"><i class="ti ti-car me-2" aria-hidden="true"></i>Hintergrund: Gruppe V (Verkehr) &amp; M (Medikation) (BK-5 Kap. B 4 &amp; B 5)</h4>
                                    <div class="row g-3 mt-1">
                                        <div class="col-md-6">
                                            <div class="card card-sm border">
                                                <div class="card-body">
                                                    <h5 class="card-title">V1 – Persönlichkeitsproblematik</h5>
                                                    <p class="text-secondary small">Schwere Gewalt- oder Sexualdelikte im Straßenverkehr. Fokus auf Impulskontrolle. <strong>12 Monate</strong> dokumentierter Veränderungsprozess. (BK-5 Kap. B 4)</p>
                                                    <h5 class="card-title mt-2">V2 – Verfestigte Muster (Raser/Poser)</h5>
                                                    <p class="text-secondary small">Wiederholte grobe Verstöße. <strong>6–12 Monate</strong> Bewährung. Fahrverhaltensbeobachtung (FVB) kann angeordnet werden. (BK-5 Kap. B 4)</p>
                                                    <h5 class="card-title mt-2">V3 – Fehleinstellungen (Punktekarte)</h5>
                                                    <p class="text-secondary small">Falsche Überzeugungen gegenüber Verkehrsregeln (z.B. Raserei = Kompetenz). <strong>6 Monate</strong> stabile Einstellungsänderung. (BK-5 Kap. B 4)</p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card card-sm border">
                                                <div class="card-body">
                                                    <h5 class="card-title">M1 – Dauermedikation / Medizinalcannabis</h5>
                                                    <p class="text-secondary small">Therapie-Compliance belegt, ärztliche Verordnung vorhanden. <strong>Kein Abstinenzgebot.</strong> Leistungstest: PR ≥ 16 (Gruppe 1, PKW) bzw. PR ≥ 33 in der Mehrzahl der Tests (Gruppe 2, BKF). (BK-5 Kap. B 5)</p>
                                                    <p class="text-secondary small mb-0"><strong class="text-danger">Achtung – Beikonsum:</strong> <em>Nicht verordneter</em> Cannabis-Konsum neben der Verordnung kann fehlende Trennung begründen (Anlage 4 Nr. 9.2.1 FeV), das ist eine Einzelfallfrage. Steht die Nichteignung fest, unterbleibt die MPU-Anordnung (§ 11 Abs. 7 FeV). Im Aufnahme-/Bearbeiten-Formular über die Checkbox <em>„Beikonsum"</em> erfassbar; die Triage zeigt dann einen Hinweis, den Belegzeitraum setzt du per Override.</p>
                                                    <h5 class="card-title mt-2">M2 Fehlgebrauch / M3 Arzneimittelabhängigkeit</h5>
                                                    <p class="text-secondary small">Bezeichnungen nach der öffentlichen Änderungsübersicht zur BK-5, am Buch zu prüfen. Das Formular kennt nur „Medikamente“: Die Software berechnet dann keine Dauer, M1 bis M3 setzt du per Override. Fehlgebrauch verschreibungspflichtiger Mittel (Benzodiazepine, Z-Substanzen): Abstinenzanforderungen analog zu D-Kriterien: <strong>12 Mon. (mit Therapie) / 15 Mon. (Selbstheiler)</strong>. (BK-5 Kap. B 5)</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Triage überschreiben</h3>
                                    <p>Falls du die automatische Hypothese <strong>nicht zustimmst</strong>:</p>
                                    <ol>
                                        <li>Öffne den Fall</li>
                                        <li>Klicke <strong>„Hypothese überschreiben"</strong></li>
                                        <li>Wähle die richtige Hypothese</li>
                                        <li>Optional: Notiz hinzufügen</li>
                                        <li>Speichern</li>
                                    </ol>

                                    <div class="alert alert-success" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp:</h4>
                                            <div class="alert-description">Das Audit-Log hält fest, wer den Fall wann geändert hat. Die Begründung steht in der Akte.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 3b. DIE KLIENTENAKTE -->
                            <div class="card mb-4" id="akte">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-folder-open me-2 text-primary" aria-hidden="true"></i>Die Klientenakte im Detail</h2>
                                </div>
                                <div class="card-body">
                                    <p>Die <strong>Klientenakte</strong> (Öffnen über die Klienten-Tabelle oder die Fall-Übersicht) ist die zentrale Arbeitsansicht für einen einzelnen Fall. Sie besteht aus zwei Bereichen:</p>
                                    <ul>
                                        <li><strong>Kopfbereich (oben):</strong> Stammdaten, Status-Ampeln, Warnhinweise, Druck- und Aktionsmenüs. Diese Karten sind immer sichtbar.</li>
                                        <li><strong>Tab-Bereich (unten):</strong> Die eigentliche Fallarbeit ist auf sechs Registerkarten (Tabs) verteilt. <strong>Jeder Tab enthält Inhalt</strong> – auch wenn beim Öffnen zunächst nur der erste Tab angezeigt wird.</li>
                                    </ul>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Wichtig für neue Mitarbeitende</h4>
                                            <div class="alert-description">Die Tabs sind wie Reiter in einem Aktenordner: Standardmäßig liegt der Reiter <strong>„To-Dos &amp; Dokumente"</strong> obenauf. Alle anderen Bereiche (Abstinenz, Sitzungen, Notizen …) erreichst du mit einem Klick auf den jeweiligen Reiter – dort verbergen sich keine leeren Seiten, sondern die vollständige Fallarbeit.</div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Die sechs Reiter im Überblick</h3>
                                    <div class="table-responsive">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Reiter</th>
                                                    <th>Inhalt</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><i class="ti ti-checklist me-1 text-primary" aria-hidden="true"></i><strong>To-Dos &amp; Dokumente</strong></td>
                                                    <td>Aufgaben-Checkliste und Dokumentenstatus des Falls. Standardmäßig geöffnet.</td>
                                                </tr>
                                                <tr>
                                                    <td><i class="ti ti-test-pipe me-1 text-primary" aria-hidden="true"></i><strong>Abstinenz-Nachweise</strong></td>
                                                    <td>Erfasste Urin- und Haarproben (CTU), Lückenkontrolle und Screening-Frequenz.</td>
                                                </tr>
                                                <tr>
                                                    <td><i class="ti ti-activity me-1 text-primary" aria-hidden="true"></i><strong>Leistungstests</strong></td>
                                                    <td>Psychometrische Testergebnisse. <strong>Nur bei Berufskraftfahrer-Fällen (BKF)</strong> aktiv – sonst ausgegraut (siehe unten).</td>
                                                </tr>
                                                <tr>
                                                    <td><i class="ti ti-users me-1 text-primary" aria-hidden="true"></i><strong>Sitzungen</strong></td>
                                                    <td>Sitzungsprotokolle inkl. SORKC-Modell und Phasenfortschritt. Die Sitzungsnotiz ist mehrzeilig; in der Liste wird nur die erste Zeile angerissen – der Pfeil links klappt Volltext und SORKC auf. Über das Stift-Symbol lässt sich eine Sitzung nachträglich ändern (inkl. SORKC).</td>
                                                </tr>
                                                <tr>
                                                    <td><i class="ti ti-notes me-1 text-primary" aria-hidden="true"></i><strong>Notizen</strong></td>
                                                    <td>Notiz-Journal des Falls: datierte Einträge mit Überschrift (Vorschläge im Dropdown, eigene Überschriften erlaubt), direkt im Tab anlegbar, bearbeitbar und löschbar – unabhängig von den Sitzungsprotokollen. Dazu der DSGVO-Status (Einwilligung/Widerruf).</td>
                                                </tr>
                                                <tr>
                                                    <td><i class="ti ti-mail me-1 text-primary" aria-hidden="true"></i><strong>Korrespondenz</strong></td>
                                                    <td>Chronik der Fall-Ereignisse und des Schriftverkehrs.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Die Zahlen und Symbole auf den Reitern</h3>
                                    <p>Jeder Reiter kann kleine Markierungen (Badges) tragen, die dir <em>ohne Klick</em> einen Überblick geben:</p>
                                    <ul>
                                        <li><span class="badge bg-secondary-lt">3</span> &nbsp;<strong>Graue Zahl:</strong> Anzahl der erfassten Einträge (z.&nbsp;B. Sitzungen, Nachweise, Leistungstests).</li>
                                        <li><span class="badge bg-danger-lt">2</span> &nbsp;<strong>Rote Zahl:</strong> Anzahl noch <strong>offener</strong> To-Dos – hier besteht Handlungsbedarf.</li>
                                        <li><span class="badge bg-blue-lt">5</span> &nbsp;<strong>Blaue Zahl:</strong> Anzahl der Korrespondenz-Einträge.</li>
                                        <li><span class="badge bg-danger-lt"><i class="ti ti-alert-triangle" aria-hidden="true"></i></span> &nbsp;<strong>Warnsymbol:</strong> In diesem Bereich gibt es ein Problem (z.&nbsp;B. Abstinenzlücke, positiver Befund, kritisches Testergebnis). Reiter anklicken für Details.</li>
                                    </ul>

                                    <div class="alert alert-warning" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Ausgegrauter Reiter „Leistungstests"</h4>
                                            <div class="alert-description">Ist der Fall <strong>kein BKF-Fall</strong>, erscheint der Reiter „Leistungstests" bewusst ausgegraut und nicht anklickbar – Leistungstests sind dort fachlich nicht erforderlich. So bleibt die Reiter-Anordnung über alle Akten hinweg gleich. Sobald du im Fall den BKF-Status setzt, wird der Reiter aktiv.</div>
                                        </div>
                                    </div>

                                    <div class="alert alert-success" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp: Reiter merken sich ihren Zustand</h4>
                                            <div class="alert-description">Der gerade geöffnete Reiter wird in der Adresszeile vermerkt (z.&nbsp;B. <code>…/akte.php?id=1#sitzungen</code>). Du kannst einen Link auf einen bestimmten Reiter also direkt weitergeben oder als Lesezeichen speichern.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 4. BERUFSKRAFTFAHRER (BKF) -->
                            <div class="card mb-4" id="bkf">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-truck me-2 text-primary" aria-hidden="true"></i>Berufskraftfahrer (BKF) – Verschärfte Anforderungen (BK-5 Kap. C 4)</h2>
                                </div>
                                <div class="card-body">
                                    <div class="alert alert-danger" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">BKF = Fahrerlaubnisklasse C/D/CE/DE</h4>
                                            <div class="alert-description">Berufskraftfahrer (LKW, Bus, Taxi mit Beförderungspflicht) unterliegen der <strong>Fahrerlaubnisgruppe 2</strong> und haben deutlich strengere Eignungsanforderungen als PKW-Fahrer (Gruppe 1).</div>
                                        </div>
                                    </div>

                                    <h3>Regeln für Substanzen im Dienst</h3>
                                    <ul>
                                        <li><strong>Alkohol:</strong> § 24a StVG gilt ab 0,5 ‰ bzw. 0,25 mg/l Atemalkohol und enthält keine Sonderregel für Berufskraftfahrer. Bei Fahrgastbeförderung gilt im Dienst ein Alkoholverbot (§ 8 BOKraft). Für Fahranfänger und unter 21 Jahren gilt § 24c StVG.</li>
                                        <li><strong>Drogen:</strong> § 24a Abs. 2 StVG erfasst die Substanzen seiner Anlage, THC regelt Abs. 1a. Bei Fahrgastbeförderung gilt § 8 BOKraft auch für andere Mittel.</li>
                                        <li><strong>Kontrolliertes Trinken:</strong> Für aktive BKF <em>ausgeschlossen</em>. Nur vollständige Abstinenz ist akzeptabel.</li>
                                        <li><strong>Kontrollierter Cannabis-Konsum (D3-Neuerung):</strong> Die Software empfiehlt aktiven BKF Abstinenz, denn Trennverhalten ist bei nicht planbaren Einsätzen kritisch (BK-5 D 4.2 N).</li>
                                        <li><strong>Harte Drogen:</strong> Gefährden die Fahrerlaubnis zur Fahrgastbeförderung (§ 48 FeV) und die Gefahrgutberechtigung, im Einzelfall prüfen.</li>
                                    </ul>

                                    <h3 class="mt-4">Leistungstests – Gruppe 2 (BK-5 Kap. C 4)</h3>
                                    <p>BKF müssen in psychophysischen Leistungstests höhere Mindestanforderungen erfüllen:</p>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-bordered table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Anforderung</th>
                                                    <th>Gruppe 1 (PKW)</th>
                                                    <th>Gruppe 2 (BKF)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Mindestwert alle Tests</td>
                                                    <td>PR ≥ 16</td>
                                                    <td><strong>PR ≥ 16</strong> (absolute Untergrenze)</td>
                                                </tr>
                                                <tr>
                                                    <td>Normalanforderung</td>
                                                    <td>PR ≥ 16 genügt</td>
                                                    <td><strong>PR ≥ 33 in der Mehrzahl der Tests</strong></td>
                                                </tr>
                                                <tr>
                                                    <td>Kompensationshürde (BK-5 Neu)</td>
                                                    <td>–</td>
                                                    <td>Falls ein Test unter PR 16: Restliche Tests müssen <strong>PR ≥ 50</strong> erzielen (BK-4: PR 16 genügte)</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-secondary small mt-1">PR = Prozentrang (Vergleich mit Normstichprobe). PR 16 = besser als 16 % der Vergleichsgruppe.</p>

                                    <h3 class="mt-4">Abstinenzfristen für BKF</h3>
                                    <p>Die Abstinenzfristen sind identisch mit Gruppe 1 – es gibt <strong>keine verlängerten Fristen</strong> für BKF in der BK-5. Die Verschärfung liegt allein in der erhöhten Prüfdichte und den Leistungstest-Anforderungen.</p>
                                    <ul>
                                        <li><strong>A4 für BKF:</strong> 6 Monate Trennungsnachweis (nicht 10 Monate – das war ein historischer Fehler, korrigiert am 13.04.2026).</li>
                                        <li><strong>Exploration:</strong> Bei BKF wird die berufliche Exposition (Nachtfahrten, Gefahrgut, Fahrgastbeförderung) als erschwerender Faktor gewertet – die Tiefe der psychologischen Exploration ist erhöht.</li>
                                    </ul>

                                    <div class="alert alert-info mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp für die Praxis:</h4>
                                            <div class="alert-description">Trage im Fall das Flag „BKF" explizit ein. Die Triage-Engine berücksichtigt dieses Flag automatisch und schließt kontrolliertes Trinken / kontrollierten Cannabiskonsum als Option aus.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. SITZUNGSPROTOKOLLE -->
                            <div class="card mb-4" id="sitzungen">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-message me-2 text-primary" aria-hidden="true"></i>Sitzungsprotokolle</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Was ist ein Sitzungsprotokoll?</h3>
                                    <p>Ein Sitzungsprotokoll dokumentiert deine <strong>psychologische/beratende Sitzung</strong> mit einer Klientin/einem Klient. Es ist in <strong>drei Phasen</strong> strukturiert:</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Phase</th>
                                                    <th>Zeitpunkt</th>
                                                    <th>Fokus</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>1. Anamnese</strong></td>
                                                    <td>1.–3. Sitzung</td>
                                                    <td>Lebensgeschichte, Substanzgebrauchshistorie</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>2. Analyse</strong></td>
                                                    <td>Mittlere Sitzungen</td>
                                                    <td>Psychologische Einschätzung, Motivationen, Risikofaktoren</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>3. Check</strong></td>
                                                    <td>Letzte Sitzungen</td>
                                                    <td>Überprüfung der Fortschritte, CTU-Ergebnisse</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Neue Sitzung anlegen</h3>
                                    <ol>
                                        <li>Öffne eine Klientin/einen Klient</li>
                                        <li>Öffne einen Fall</li>
                                        <li>Klicke <strong>„Neue Sitzung"</strong></li>
                                        <li>Fülle die Metadaten aus (Sitzungsnummer, Datum, Phase)</li>
                                        <li>Fülle die phasenspezifischen Felder (SORKC-Modell)</li>
                                        <li>Speichern</li>
                                    </ol>

                                    <h3 class="mt-4">Das SORKC-Modell</h3>
                                    <p>Jede Phase nutzt das <strong>SORKC-Modell</strong> (ein verhaltenstherapeutisches Konzept):</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Kürzel</th>
                                                    <th>Bedeutung</th>
                                                    <th>Beispiel (Alkohol)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>S</strong></td>
                                                    <td>Stimulus (Auslöser)</td>
                                                    <td>Stress bei der Arbeit, Freunde in der Kneipe</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>O</strong></td>
                                                    <td>Organismus (innerer Zustand)</td>
                                                    <td>War müde, frustriert, gestresst</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>R</strong></td>
                                                    <td>Response (Verhalten/Handlung)</td>
                                                    <td>Trank 5 Biere in 3 Stunden</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>K</strong></td>
                                                    <td>Kontingenz (Regelmäßigkeit)</td>
                                                    <td>Täglich abends nach der Arbeit</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>C</strong></td>
                                                    <td>Konsequenz (Folge)</td>
                                                    <td>Kurzfristig Entspannung, langfristig Schlaf gestört</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="alert alert-success mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Best Practice:</h4>
                                            <div class="alert-description">
                                                <ul class="mb-0">
                                                    <li>Unmittelbar nach der Sitzung ausfüllen</li>
                                                    <li>Konkrete Beispiele statt Allgemeinplätze nutzen</li>
                                                    <li>SORKC-Struktur konsequent verwenden</li>
                                                    <li>Neutral formulieren (keine persönlichen Wertungen)</li>
                                                    <li>Vergleiche zu früheren Sitzungen notieren</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 5. ABSTINENZ-NACHWEISE -->
                            <div class="card mb-4" id="abstinenz">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-flask me-2 text-primary" aria-hidden="true"></i>Abstinenz-Nachweise (CTU)</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Überblick: CTU-Systeme</h3>
                                    <p><strong>CTU</strong> = Chemisch-Toxikologische Untersuchung. Die Software unterstützt zwei Nachweisverfahren:</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Verfahren</th>
                                                    <th>Nachweisbereich</th>
                                                    <th>Kosten</th>
                                                    <th>Häufigkeit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Urinprobe</strong></td>
                                                    <td>bis ~14 Tage</td>
                                                    <td>Günstig (€10–30)</td>
                                                    <td>Häufig alle 2 Wochen</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Haaranalyse</strong></td>
                                                    <td>bis ~95 Tage</td>
                                                    <td>Teuer (€100–300)</td>
                                                    <td>Seltener, z. B. abschließend</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Neue CTU-Probe registrieren</h3>
                                    <ol>
                                        <li>Öffne einen Fall</li>
                                        <li>Klicke <strong>„Neue Abstinenzprobe"</strong></li>
                                        <li>Wähle Probentyp (Urin oder Haar)</li>
                                        <li>Gib die Daten ein (Probedatum, Laboreingangsdatum, Laborergebnis-Datum)</li>
                                        <li>Gib die Laborwerte ein (Kreatinin, PEth, THC-COOH, etc.)</li>
                                        <li>Speichern</li>
                                    </ol>

                                    <h3 class="mt-4">Laborwerte eingeben</h3>
                                    <p>Je nach Delikt und Hypothese werden unterschiedliche Laborwerte benötigt:</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Substanz</th>
                                                    <th>Marker</th>
                                                    <th>Normbereich</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Alkohol</strong></td>
                                                    <td>Phosphatidylethanol (PEth)</td>
                                                    <td>&lt; <?= $_peth_grenze ?> ng/ml</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Cannabis (Urin)</strong></td>
                                                    <td>THC-COOH</td>
                                                    <td>Unter dem Cut-off des Labors, siehe Befund</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Cannabis (Serum)</strong></td>
                                                    <td>THC</td>
                                                    <td>Kein Normbereich; § 24a Abs. 1a StVG gilt ab <?= $_thc_grenzwert ?> ng/ml</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Drogen</strong></td>
                                                    <td>Amphetamine, Kokain, Opiate, MDMA</td>
                                                    <td>Negativ</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="alert alert-warning mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Kreatinin-Korrektur (wichtig für Urin!):</h4>
                                            <div class="alert-description">
                                                Bei Urinproben MUSS die <strong>Kreatinin-Konzentration</strong> überprüft werden. Die Software unterscheidet dafür, orientiert an den CTU-Kriterien der BK-5, <strong>zwei</strong> Schwellen mit unterschiedlicher Folge:
                                                <ul class="mb-2 mt-2">
                                                    <li>Unter <strong><?= $_mg_dl($_kreat_verwertbar) ?> mg/dl</strong> (<?= $_g_l($_kreat_verwertbar) ?> g/l) gilt die Probe als <strong>nicht verwertbar</strong> – sie ist verdünnt und zählt nicht für die Abstinenzkette. Sie muss wiederholt werden.</li>
                                                    <li>Bei <strong>≤ <?= $_mg_dl($_kreat_kein_neg) ?> mg/dl</strong> (<?= $_g_l($_kreat_kein_neg) ?> g/l) wird kein Negativbefund mehr ausgewiesen.</li>
                                                </ul>
                                                <strong>Wichtig:</strong> Die zweite Schwelle ist kein automatischer Manipulationsvorwurf – die Wertung trifft der Gutachter. Die Labore melden in <strong>g/l</strong>, das Eingabefeld in der Akte erwartet <strong>mg/dl</strong> (Faktor 100). Informiere Klienten: Normal trinken, nicht extra viel Wasser am Proben-Tag.
                                            </div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Screening-Häufigkeit nach Abstinenz­zeitraum (BK-5 Kap. C)</h3>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Abstinenz­zeitraum</th>
                                                    <th>Urin­proben (Mindest­anzahl)</th>
                                                    <th>Haar­analyse (Mindest­länge)</th>
                                                    <th>Typische Hypothesen</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>6 Monate</strong></td>
                                                    <td><?= urin_pflicht_proben(6) ?> Proben, Abruf unangekündigt</td>
                                                    <td>3 cm (= 3 Monate Alkohol/EtG)</td>
                                                    <td>A3, A4, D3, D4, V2, V3</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>12 Monate</strong></td>
                                                    <td><?= urin_pflicht_proben(12) ?> Proben, Abruf unangekündigt</td>
                                                    <td>6 cm (= 6 Monate Drogen)</td>
                                                    <td>A2 (mit Therapie), D2 (Standard)</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>15 Monate</strong></td>
                                                    <td><?= urin_pflicht_proben(15) ?> Proben, Abruf unangekündigt</td>
                                                    <td>6 cm abschließend empfohlen</td>
                                                    <td>A1, A2 (Selbstheiler), D1 (alle Fälle)</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-secondary small">Hinweis: Die Software meldet, wenn Haaranalysen den Zeitraum nicht lückenlos abdecken. Bei Urin zählt sie die Proben gegen das Soll: Urinkontrollen werden unangekündigt und unregelmäßig abgerufen, einen festen Abstand gibt es nicht. Ob ein Nachweis anerkannt wird, entscheidet die Begutachtungsstelle.</p>

                                    <h3 class="mt-4">Biomarker im Detail</h3>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Biomarker</th>
                                                    <th>Substanz</th>
                                                    <th>Nachweis­zeitraum</th>
                                                    <th>Grenzwert (Abstinenz­beleg)</th>
                                                    <th>Besonderheit</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>PEth</strong> (Phosphatidylethanol)</td>
                                                    <td>Alkohol (Blut)</td>
                                                    <td>2–4 Wochen</td>
                                                    <td>&lt; <?= $_peth_grenze ?> ng/ml</td>
                                                    <td>Spezifischster Alkohol-Langzeitmarker; verdrängt CDT bei MPU</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>EtG</strong> (Ethylglucuronid)</td>
                                                    <td>Alkohol (Haar)</td>
                                                    <td>3 cm = 3 Monate</td>
                                                    <td>Unter dem Cut-off des Labors, siehe Befund</td>
                                                    <td>Haar-Marker für Alkohol; ideal als abschließender Nachweis</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>CDT</strong> (Carbohydrat-defizientes Transferrin)</td>
                                                    <td>Alkohol (Blut)</td>
                                                    <td>2–3 Wochen</td>
                                                    <td>&lt; 1,7 % oder &lt; 2,0 %</td>
                                                    <td>Weniger sensibel als PEth; noch in Verwendung, aber rückläufig</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>THC-COOH</strong></td>
                                                    <td>Cannabis (Urin, Blutserum)</td>
                                                    <td>Tage–Wochen je nach Konsum</td>
                                                    <td>Abstinenz: unter dem Cut-off des Labors. Im Blutserum (keine Abstinenzfrage): ≤ <?= $_cooh_gefaehrdung ?> ng/ml D4 möglich, ≤ <?= $_cooh_regelkonsum ?> ng/ml D3</td>
                                                    <td>Konsumhäufigkeits-Indikator; im Blutserum &gt; <?= $_cooh_regelkonsum ?> ng/ml = D2 (regelmäßiger Konsum)</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>THC</strong> (Serum)</td>
                                                    <td>Cannabis (Blut)</td>
                                                    <td>Stunden</td>
                                                    <td>§ 24a Abs. 1a StVG gilt ab <?= $_thc_grenzwert ?> ng/ml</td>
                                                    <td>Über <?= $_thc_einzelfall ?> ng/ml spricht der Wert im Einzelfall gegen Trennvermögen (kein normativer Grenzwert); D4 wird nicht vorgeschlagen</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Kreatinin</strong></td>
                                                    <td>Urin-Probenvalidität</td>
                                                    <td>–</td>
                                                    <td>≥ <?= $_mg_dl($_kreat_verwertbar) ?> mg/dl (<?= $_g_l($_kreat_verwertbar) ?> g/l)</td>
                                                    <td>&lt; <?= $_mg_dl($_kreat_verwertbar) ?> mg/dl: nicht verwertbar, Probe verdünnt → wiederholen. ≤ <?= $_mg_dl($_kreat_kein_neg) ?> mg/dl (<?= $_g_l($_kreat_kein_neg) ?> g/l): kein Negativbefund mehr (CTU-Kriterien der BK-5)</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- 6. AUFGABEN & CHECKLISTEN -->
                            <div class="card mb-4" id="checklisten">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-checklist me-2 text-primary" aria-hidden="true"></i>Aufgaben &amp; Checklisten</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Automatische Checklisten</h3>
                                    <p>Die Software generiert automatisch <strong>deliktabhängige Checklisten</strong> pro Fall.</p>

                                    <h4 class="mt-3">Beispiel: Alkohol-Fall (A1/A2)</h4>
                                    <ul>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Dokumentation biographischer Daten</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Nachweis Therapie-Beginn (falls empfohlen)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Regelmäßige Urinproben (12–15, je nach Hypothese)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Optional: Haaranalyse (als abschließender Nachweis)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Sitzungsprotokolle alle Phasen (Anamnese, Analyse, Check)</li>
                                    </ul>

                                    <h4 class="mt-3">Beispiel: Drogen-Fall (D2/D3)</h4>
                                    <ul>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Dokumentation Substanzgebrauchshistorie</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> THC-COOH-Wert-Bestimmung (zu Beginn)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Regelmäßige Urinproben (12–15 für D2, 6 für D3)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Check auf Kreatinin-Werte (Urin-Verdünnung)</li>
                                        <li><i class="ti ti-square me-1 text-secondary" aria-hidden="true"></i> Sitzungsprotokolle</li>
                                    </ul>

                                    <h3 class="mt-4">Checklisten-Status verfolgen</h3>
                                    <p>Auf der Fallseite siehst du die <strong>Aufgaben &amp; Checklisten</strong>:</p>
                                    <ul>
                                        <li><i class="ti ti-circle-check me-1 text-success" aria-hidden="true"></i> Grünes Häkchen = Erledigt</li>
                                        <li><i class="ti ti-circle-x me-1 text-danger" aria-hidden="true"></i> Rotes Kreuz = Fehlt noch</li>
                                        <li>Klicke zum Abhaken, wenn du eine Aufgabe erledigt hast</li>
                                    </ul>
                                    <div class="alert alert-success mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp:</h4>
                                            <div class="alert-description">Nutze die Checklisten als Workflow-Hilfe. Sie helfen, keine wichtigen Dokumente zu vergessen!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 7. FINANZEN -->
                            <div class="card mb-4" id="finanzen">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-coin me-2 text-primary" aria-hidden="true"></i>Finanzen &amp; Abrechnung</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Paket vs. Einzelabrechnung</h3>
                                    <p>Die Software unterstützt zwei Abrechnungsmodelle pro Fall:</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Modell</th>
                                                    <th>Beschreibung</th>
                                                    <th>Beispiel</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Paket</strong></td>
                                                    <td>Fest definierte Summe für die ganze Beratung</td>
                                                    <td>€1.500 für komplette A1-Beratung</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Einzelabrechnung</strong></td>
                                                    <td>Stundensätze für Sitzungen, Schreiben, Tests</td>
                                                    <td>€100/Stunde für Sitzung</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Paketpreis setzen</h3>
                                    <ol>
                                        <li>Öffne einen Fall</li>
                                        <li>Scrolle zu „Finanzen"</li>
                                        <li>Wähle <strong>„Paket"</strong></li>
                                        <li>Gib den Paketpreis ein (z. B. 1500)</li>
                                        <li>Speichern</li>
                                    </ol>

                                    <h3 class="mt-4">Zahlungsstatus verfolgen</h3>
                                    <p>Auf der Fallseite siehst du:</p>
                                    <ul>
                                        <li><strong>Betrag:</strong> Gesamtpreis (Paket oder berechnet)</li>
                                        <li><strong>Zahlungsstatus:</strong> „Offen", „Teilweise bezahlt", „Bezahlt"</li>
                                        <li><strong>Zahlungsdatum:</strong> Wann wurde bezahlt?</li>
                                    </ul>
                                    <div class="alert alert-success mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp:</h4>
                                            <div class="alert-description">Regelmäßig überprüfen, ob Zahlungen eingegangen sind!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 8. PDF-EXPORT -->
                            <div class="card mb-4" id="pdf">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-printer me-2 text-primary" aria-hidden="true"></i>Drucken &amp; Exports</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Verfügbare Druckdokumente</h3>
                                    <p>Die Software öffnet folgende Dokumente als druckbare Seite im Browser. Der Browser druckt sie zu Papier oder speichert sie als PDF.</p>

                                    <div class="table-responsive mt-3">
                                        <table class="table table-vcenter">
                                            <thead>
                                                <tr>
                                                    <th>Dokument</th>
                                                    <th>Inhalt</th>
                                                    <th>Zeitpunkt</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Beratungsvertrag</strong></td>
                                                    <td>Vereinbarung zwischen Praxis und Klient/in</td>
                                                    <td>Vor 1. Sitzung</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Datenschutzerklärung</strong></td>
                                                    <td>DSGVO-konform, zur Unterschrift</td>
                                                    <td>Vor 1. Sitzung</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Widerruf-Formular</strong></td>
                                                    <td>Für Widerruf der Einwilligung</td>
                                                    <td>Auf Anfrage</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Strategiepapier</strong></td>
                                                    <td>Zusammenfassung der Empfehlungen für die MPU</td>
                                                    <td>Vor der MPU</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Dokumente drucken</h3>
                                    <ol>
                                        <li>Öffne eine Klientin/einen Klient oder einen Fall</li>
                                        <li>Klicke auf <strong>„Drucken"</strong> und wähle das gewünschte Dokument</li>
                                        <li>Das Dokument öffnet sich als neue Seite – der Browser startet automatisch den Druckdialog</li>
                                        <li>Wähle einen Drucker oder <strong>„Als PDF speichern"</strong></li>
                                    </ol>
                                    <div class="alert alert-info mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>Tipp: Im Browser-Druckdialog unter <strong>Weitere Einstellungen → Hintergrundgrafiken drucken</strong> aktivieren, damit die Hintergrundfarben korrekt erscheinen.</div>
                                    </div>

                                    <h3 class="mt-4">DSGVO-Export (Art. 20)</h3>
                                    <p>Falls eine Klientin/ein Klient ihre/seine <strong>Daten anfordert</strong>:</p>
                                    <ol>
                                        <li>Öffne das Klientenprofil</li>
                                        <li>Klicke <strong>„DSGVO-Export"</strong></li>
                                        <li>Eine JSON-Datei wird heruntergeladen mit allen Daten</li>
                                    </ol>
                                    <div class="alert alert-warning mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Wichtig:</h4>
                                            <div class="alert-description">Der DSGVO-Export enthält <strong>NICHT</strong> das Audit-Log (interne Betriebsdaten). Das ist beabsichtigt und DSGVO-konform!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 9. FAQ -->
                            <div class="card mb-4" id="faq">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-help-circle me-2 text-primary" aria-hidden="true"></i>Häufig gestellte Fragen (FAQ)</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Kann ich mehrere Fälle pro Klient haben?</h3>
                                    <p><strong>Ja!</strong> Beispiel: Anna Schmidt hat einen Alkoholfall (2024) und einen Drogenfall (2025). Die Software zeigt immer den aktuellen Fall als Hauptfall.</p>

                                    <h3 class="mt-4">Woher kommen die Abstinenzempfehlungen?</h3>
                                    <p>Das System berechnet diese automatisch basierend auf <strong>BK-5 (2026)</strong> – der aktuellen Auflage der Beurteilungskriterien. Du kannst die Hypothese jederzeit überschreiben.</p>

                                    <h3 class="mt-4">Wie sicher sind meine Daten?</h3>
                                    <p>Die Software speichert Daten in einer MariaDB-Datenbank und ist durch eine <strong>Anmeldung mit Benutzername und Passwort</strong> geschützt. Ein <strong>Audit-Log</strong> protokolliert die Schreibvorgänge über Formulare.</p>

                                    <h3 class="mt-4">Kann ich Daten exportieren?</h3>
                                    <p><strong>Ja, auf zwei Arten:</strong></p>
                                    <ul>
                                        <li><strong>PDFs:</strong> Verträge, Datenschutzerklärungen, Strategiepapiere (zum Ausdrucken)</li>
                                        <li><strong>DSGVO-JSON:</strong> Alle Klientendaten (Art. 20 DSGVO)</li>
                                    </ul>

                                    <h3 class="mt-4">Was ist das Audit-Log?</h3>
                                    <p>Ein <strong>Protokoll</strong> der Schreibvorgänge über Formulare (Erstellen, Ändern, Löschen): wer, wann, welche Art von Eintrag, ohne Inhalte. Es liegt in der Datenbank, die Software zeigt es nicht an, und es ist kein manipulationssicherer Speicher.</p>

                                    <h3 class="mt-4">Kann ich eine Sitzung nachträglich bearbeiten?</h3>
                                    <p><strong>Ja!</strong> Klicke auf die Sitzung, dann „Bearbeiten". Das Audit-Log zeigt, dass und wann die Änderung gemacht wurde.</p>

                                    <h3 class="mt-4">Kann ich Einträge löschen?</h3>
                                    <p><strong>Ja, aber mit Vorsicht!</strong> Löschungen sind möglich, werden aber im Audit-Log protokolliert. Aus Datenschutzgründen (DSGVO Art. 17 "Recht auf Löschung") kannst du Klienten komplett löschen – aber alle verknüpften Daten (Fälle, Sitzungen, CTU-Ergebnisse) werden mitgelöscht!</p>

                                    <h3 class="mt-4">Was ist die BK-5?</h3>
                                    <p>Die <strong>5. Auflage der Beurteilungskriterien (BK-5, März 2026)</strong> ist der aktuelle wissenschaftliche Standard für MPU-Gutachter in Deutschland. Sie definiert Hypothesen, Abstinenzfristen, Tests, etc.</p>

                                    <h3 class="mt-4">Gibt es eine Suchfunktion?</h3>
                                    <p><strong>Ja!</strong> Nutze die Suchbox auf dem Dashboard. Sie sucht nach Vornamen, Nachnamen, Vollname oder Aktenzeichen.</p>

                                    <h3 class="mt-4">Was ist der Unterschied zwischen A1 und A2?</h3>
                                    <p><strong>A1</strong> setzt eine klinische Abhängigkeitsdiagnose voraus (ICD-Score ≥ <?= $_icd_abhaengigkeit_min ?> von 6). <strong>A2</strong> beschreibt eine fortgeschrittene Alkoholproblematik mit Giftfestigkeit (BAK ≥ 2,0 ‰), aber ohne vollständige Abhängigkeit. Beide erfordern 15 Monate Abstinenz (Selbstheiler), A2 erlaubt mit Therapie 12 Monate.</p>

                                    <h3 class="mt-4">Darf ein Klient bei D3 (Cannabis) weiterhin konsumieren?</h3>
                                    <p>Das ist die zentrale <strong>Neuerung der BK-5 (2026)</strong>: Bei einem reinen Cannabis-Delikt ohne Begleitsubstanzen und ohne BKF-Status ist kontrollierter Cannabiskonsum erstmals als Option anerkannt – analogierechtlich zum kontrollierten Trinken (A3). Voraussetzung: stabiles Konsummuster, Konsumtagebuch, ggf. THC-Monitoring. Aktiven BKF empfiehlt die Software Abstinenz, denn Trennverhalten ist bei nicht planbaren Einsätzen kritisch (BK-5 D 4.2 N).</p>

                                    <h3 class="mt-4">Was ist der Unterschied zwischen Urinprobe und Haaranalyse?</h3>
                                    <p><strong>Urinprobe:</strong> Günstig (€10–30), kurzer Nachweiszeitraum (~14 Tage), muss häufig wiederholt werden. <strong>Haaranalyse:</strong> Teuer (€100–300), langer Nachweiszeitraum (3 cm = 3 Monate; für Drogen: 6 cm = 6 Monate), ideal als abschließender Gesamtnachweis. Beide Methoden ergänzen sich – Urin für laufende Überwachung, Haar zur Bestätigung.</p>

                                    <h3 class="mt-4">Was bedeutet „Selbstheiler" in der Triage?</h3>
                                    <p>Ein <strong>Selbstheiler</strong> ist ein Klient, der Abstinenz ohne therapeutische Begleitung (kein Entzug, keine Therapie) nachweist. Da das Rückfallrisiko ohne professionelle Unterstützung höher ist, gelten in der Regel <strong>längere Abstinenzfristen</strong> (z.B. 15 statt 12 Monate bei A2). Im Fall setzt du das Feld <em>Therapieform</em> bzw. den Haken <em>Suchttherapie absolviert</em>. Bei D2 hängt die Dauer an den erschwerenden Faktoren, nicht an der Therapie.</p>

                                    <h3 class="mt-4">Was passiert, wenn eine Abstinenzlücke erkannt wird?</h3>
                                    <p>Die Software meldet bei Haaranalysen einen nicht abgedeckten Zeitraum (nach der Segmentlänge). Das ist ein Hinweis, keine Entscheidung über den Nachweis. Kläre mit dem Labor, ob sich der Zeitraum noch abdecken lässt. Erst ein positiver Befund unterbricht die Kette, gezählt wird dann ab der nächsten negativen Probe. Prüfe zuerst: Ist eine Probe in der Software nicht eingetragen worden (Tippfehler, fehlende Eingabe)?</p>

                                    <h3 class="mt-4">Welche Anforderungen gelten für Berufskraftfahrer (BKF)?</h3>
                                    <p>BKF (Klasse C/D) unterliegen der <strong>Fahrerlaubnisgruppe 2</strong> mit verschärften Anforderungen: Leistungstests mit PR ≥ 33 (statt PR ≥ 16), kein kontrolliertes Trinken oder Cannabiskonsum. Harte Drogen gefährden die Berechtigungen für den Beruf. Siehe den Abschnitt <a href="#bkf">„Berufskraftfahrer (BKF)"</a> für Details.</p>

                                    <div class="alert alert-success mt-4" role="alert">
                                        <div class="alert-icon"><i class="ti ti-bulb icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Weitere Fragen?</h4>
                                            <div class="alert-description">Kontaktiere deinen Systemadministrator oder die Entwickler des MPU-Managers. Für fachliche Fragen konsultiere die BK-5 oder erfahrene MPU-Kollegen.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 10. GLOSSAR -->
                            <div class="card mb-4" id="glossar">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-book-2 me-2 text-primary" aria-hidden="true"></i>Glossar & Fachbegriffe</h2>
                                </div>
                                <div class="card-body">
                                    <p>Alle wichtigen Fachbegriffe, die in der MPU-Beratung verwendet werden:</p>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h4 class="mt-3">Allgemeine Begriffe</h4>
                                            <dl>
                                                <dt>MPU</dt>
                                                <dd>Medizinisch-Psychologische Untersuchung. Umgangssprachlich "Idiotentest". Prüfung der Fahreignung nach schweren Verkehrsverstößen.</dd>
                                                
                                                <dt>BK-5</dt>
                                                <dd>Beurteilungskriterien für Fahreignung, 5. Auflage (2026). Wissenschaftlicher Standard für MPU-Gutachter.</dd>
                                                
                                                <dt>Sperrfrist</dt>
                                                <dd>Zeitraum, in dem keine neue Fahrerlaubnis erteilt werden darf (§ 69a StGB). Wird vom Gericht verhängt (z.B. 6, 12 oder 24 Monate). Die alte Fahrerlaubnis ist mit der Rechtskraft erloschen (§ 69 Abs. 3 StGB).</dd>
                                                
                                                <dt>Fahrerlaubnisbehörde (FEB)</dt>
                                                <dd>Behörde, die Führerscheine ausstellt und entzieht. Oft beim Landratsamt oder Bürgeramt angesiedelt.</dd>
                                                
                                                <dt>Hypothese</dt>
                                                <dd>Vermutung des Gutachters über die Ursache der Fahruntauglichkeit (z.B. A1 = Alkoholabhängigkeit).</dd>
                                                
                                                <dt>Triage</dt>
                                                <dd>Automatische Einschätzung der Hypothese basierend auf den Falldaten (BAK, Delikttyp, etc.).</dd>
                                            </dl>
                                            
                                            <h4 class="mt-3">Alkohol-Begriffe</h4>
                                            <dl>
                                                <dt>BAK</dt>
                                                <dd>Blutalkoholkonzentration in Promille (‰). Beispiel: 1,5 ‰ = 1,5 Gramm Alkohol pro Liter Blut.</dd>
                                                
                                                <dt>PEth (Phosphatidylethanol)</dt>
                                                <dd>Alkohol-Langzeitmarker im Blut. Weist Konsum in den letzten 2–4 Wochen nach. Auffällig ab <?= $_peth_grenze ?> ng/ml.</dd>
                                                
                                                <dt>CDT (Carbohydrat-defizientes Transferrin)</dt>
                                                <dd>Alkohol-Marker, der chronischen Konsum anzeigt. Weniger sensibel als PEth.</dd>
                                                
                                                <dt>Giftfestigkeit / Toleranz</dt>
                                                <dd>Gewöhnung an Alkohol. Hohe BAK (≥ 2,0 ‰) ohne bewusstlos zu sein = Hinweis auf Toleranzentwicklung.</dd>
                                            </dl>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <h4 class="mt-3">Drogen-Begriffe</h4>
                                            <dl>
                                                <dt>THC</dt>
                                                <dd>Tetrahydrocannabinol – psychoaktive Substanz in Cannabis. Grenzwert (CanG): 3,5 ng/ml im Serum.</dd>
                                                
                                                <dt>THC-COOH</dt>
                                                <dd>Abbauprodukt von THC. Zeigt Konsumhäufigkeit an. Schwellen im Blutserum, D2 (regelmäßiger Konsum): <strong>&gt; <?= $_cooh_regelkonsum ?></strong> ng/ml, D3: <strong>&gt; <?= $_cooh_gefaehrdung ?> bis <?= $_cooh_regelkonsum ?></strong> ng/ml. Die Vergleiche sind <em>echt größer</em> — bei exakt <?= $_cooh_regelkonsum ?> ng/ml gilt D3, nicht D2.</dd>
                                                
                                                <dt>CanG</dt>
                                                <dd>Cannabis-Gesetz (2024). Erlaubt privaten Konsum, aber nicht vor/beim Fahren (Grenzwert 3,5 ng/ml).</dd>
                                                
                                                <dt>Harte Drogen</dt>
                                                <dd>Kokain, Heroin, Amphetamine, Methamphetamin, MDMA (Ecstasy). Bereits einmaliger Nachweis → meist MPU.</dd>
                                            </dl>
                                            
                                            <h4 class="mt-3">Abstinenz & CTU</h4>
                                            <dl>
                                                <dt>CTU</dt>
                                                <dd>Chemisch-Toxikologische Untersuchung. Urin- oder Haarproben zum Nachweis von Abstinenz.</dd>
                                                
                                                <dt>Urinprobe</dt>
                                                <dd>Nachweis von Substanzen bis ~14 Tage zurück. Günstig (€10–30), Abruf unangekündigt und unregelmäßig.</dd>
                                                
                                                <dt>Haaranalyse</dt>
                                                <dd>Nachweis von Substanzen bis ~3 Monate zurück (3 cm Haar). Teurer (€100–300), aber seltener nötig.</dd>
                                                
                                                <dt>Kreatinin</dt>
                                                <dd>Stoff im Urin zur Prüfung der Probenvalidität. Unter <?= $_mg_dl($_kreat_verwertbar) ?> mg/dl (<?= $_g_l($_kreat_verwertbar) ?> g/l) gilt die Probe als verdünnt und nicht verwertbar, sie muss wiederholt werden. Bei ≤ <?= $_mg_dl($_kreat_kein_neg) ?> mg/dl (<?= $_g_l($_kreat_kein_neg) ?> g/l) werden negative Drogenbefunde nicht mehr mitgeteilt. Labore melden in g/l, das Eingabefeld erwartet mg/dl (Faktor 100).</dd>
                                                
                                                <dt>Lückenlose Abstinenz</dt>
                                                <dd>Der Programmzeitraum ist belegt: bei Urin mit der Probenzahl des Programms, bei Haar lückenlos nach Segmentlänge.</dd>
                                            </dl>
                                            
                                            <h4 class="mt-3">Psychologie & Methodik</h4>
                                            <dl>
                                                <dt>SORKC-Modell</dt>
                                                <dd>Verhaltenstherapeutisches Analysemodell: Stimulus, Organismus, Reaktion, Kontingenz, Konsequenz. Strukturiert die Dokumentation von Rückfall-Situationen und Verhaltensänderungen.</dd>
                                                
                                                <dt>Anamnese</dt>
                                                <dd>Erfassung der Lebensgeschichte, Substanzhistorie (meist 1.–3. Sitzung). Grundlage für alle weiteren Beratungsschritte.</dd>
                                                
                                                <dt>ICD-Score</dt>
                                                <dd>Abhängigkeits-Score nach ICD-10/DSM-5: ≥ <?= $_icd_abhaengigkeit_min ?> von 6 Kriterien erfüllt = Abhängigkeit abklären (→ A1 oder D1), keine Diagnose. Kriterien: Craving, Kontrollverlust, Entzug, Toleranz, Vernachlässigung anderer Aktivitäten, Fortsetzen trotz Schaden.</dd>
                                                
                                                <dt>KT (Kontrolliertes Trinken)</dt>
                                                <dd>Alternative zur Abstinenz bei A3 oder A2 (nur in Ausnahmefällen). Nachweis über 6 Monate Trinktagebuch + PEth-Kontrollen. Für BKF ausgeschlossen.</dd>

                                                <dt>FVB (Fahrverhaltensbeobachtung)</dt>
                                                <dd>Praktische Fahrprobe unter Beobachtung durch einen Gutachter. Kann bei V2 oder als Kompensation bei grenzwertigen Leistungstests angeordnet werden.</dd>

                                                <dt>Selbstheiler</dt>
                                                <dd>Klient, der Abstinenz ohne therapeutische Begleitung nachweist. Erfordert in der Regel längere Abstinenznachweise (z.B. 15 statt 12 Monate), da die Therapie als Risikominimierungsfaktor fehlt.</dd>
                                            </dl>

                                            <h4 class="mt-3">Rechtliche & normative Begriffe</h4>
                                            <dl>
                                                <dt>BGL 2026 (Begutachtungsleitlinien)</dt>
                                                <dd>Begutachtungsleitlinien zur Kraftfahreignung, Neufassung 31.01.2026. Herausgegeben von der Bundesanstalt für Straßenwesen (BASt). Ergänzt die BK-5 um formale Anforderungen.</dd>

                                                <dt>FeV (Fahrerlaubnis-Verordnung)</dt>
                                                <dd>Fahrerlaubnis-Verordnung – regelt Erwerb, Entzug und Wiedererteilung der Fahrerlaubnis. Anlage 4 (Eignung), Anlage 4a (Wissenschaftliche Grundsätze). Wurde 2024 durch CanG-Anpassungen (§ 13a) ergänzt.</dd>

                                                <dt>DSM-5 / ICD-10</dt>
                                                <dd>Diagnostische Klassifikationssysteme für psychische Störungen: DSM-5 (amerikanisch) und ICD-10 (international, WHO). Beide definieren Substanzgebrauchsstörungen und Abhängigkeit – maßgeblich für A1/D1-Diagnose.</dd>

                                                <dt>BKF (Berufskraftfahrer)</dt>
                                                <dd>Fahrerlaubnisklassen C, CE, D, DE. BKF unterliegen der Fahrerlaubnisgruppe 2 mit verschärften Eignungsanforderungen (Leistungstests). Kein kontrolliertes Trinken oder Cannabiskonsum im Dienst.</dd>

                                                <dt>PR (Prozentrang)</dt>
                                                <dd>Leistungstest-Ergebnis im Vergleich zur Normstichprobe. PR 16 = besser als 16 % der Vergleichsgruppe. Gruppe 1 (PKW): PR ≥ 16. Gruppe 2 (BKF): PR ≥ 33 in der Mehrzahl der Tests.</dd>

                                                <dt>EtG (Ethylglucuronid)</dt>
                                                <dd>Alkohol-Haar-Marker. 3 cm Haar = 3 Monate Nachweiszeitraum. Für den Abstinenzbeleg gilt der Cut-off des Labors, siehe Befund. Ideal als abschließender Nachweis nach Urinprobe-Phase.</dd>

                                                <dt>ADR (Gefahrgutberechtigung)</dt>
                                                <dd>Berechtigung zur Beförderung gefährlicher Güter. Bei harten Drogen im Einzelfall prüfen.</dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 11. TROUBLESHOOTING -->
                            <div class="card mb-4" id="troubleshooting">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-tool me-2 text-primary" aria-hidden="true"></i>Troubleshooting & Häufige Probleme</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Problem: Ich finde einen Klienten nicht in der Suche</h3>
                                    <p><strong>Lösung:</strong></p>
                                    <ol>
                                        <li>Prüfe Schreibweise (Groß-/Kleinschreibung wird ignoriert)</li>
                                        <li>Suche nur nach Vorname ODER Nachname, nicht beides gleichzeitig</li>
                                        <li>Versuche die Suche nach Aktenzeichen (z.B. "FZB-2024-123")</li>
                                        <li>Scrolle in der Klienten-Tabelle nach unten – nicht alle werden oben angezeigt</li>
                                    </ol>
                                    
                                    <h3 class="mt-4">Problem: Die Triage-Hypothese ist meiner Meinung nach falsch</h3>
                                    <p><strong>Lösung:</strong> Das ist normal! Die automatische Triage ist nur eine <strong>Empfehlung</strong>.</p>
                                    <ol>
                                        <li>Öffne den Fall</li>
                                        <li>Klicke "Hypothese überschreiben"</li>
                                        <li>Wähle die richtige Hypothese aus</li>
                                        <li>Notiere den Grund (für deine Dokumentation)</li>
                                        <li>Speichern</li>
                                    </ol>
                                    <p><em>Hinweis: Die Überschreibung wird im Audit-Log dokumentiert.</em></p>
                                    
                                    <h3 class="mt-4">Problem: Ich habe versehentlich falsche Daten eingegeben</h3>
                                    <p><strong>Lösung:</strong> Fast alle Daten können nachträglich bearbeitet werden.</p>
                                    <ul>
                                        <li><strong>Klientendaten:</strong> Öffne Klient → "Bearbeiten" → Ändern → Speichern</li>
                                        <li><strong>Falldaten:</strong> Öffne Fall → "Bearbeiten" → Ändern → Speichern</li>
                                        <li><strong>Sitzungen:</strong> Öffne Sitzung → "Bearbeiten" → Ändern → Speichern</li>
                                        <li><strong>CTU-Proben:</strong> Öffne Probe → "Bearbeiten" → Ändern → Speichern</li>
                                    </ul>
                                    <p><em>Alle Änderungen werden im Audit-Log protokolliert (wer, wann, was).</em></p>
                                    
                                    <h3 class="mt-4">Problem: Ein Klient möchte seine Daten gelöscht haben (DSGVO)</h3>
                                    <p><strong>Lösung:</strong> Das "Recht auf Löschung" (DSGVO Art. 17) ist wichtig.</p>
                                    <ol>
                                        <li>Prüfe, ob rechtliche Aufbewahrungspflichten bestehen (meist 10 Jahre nach Abschluss)</li>
                                        <li>Falls keine Pflichten: Öffne Klientenprofil → "Klient löschen"</li>
                                        <li>Bestätige die Löschung (Warnung beachten!)</li>
                                        <li><strong>Achtung:</strong> Alle verknüpften Daten (Fälle, Sitzungen, CTU, Finanzen) werden mitgelöscht!</li>
                                    </ol>
                                    
                                    <h3 class="mt-4">Problem: Abstinenz-Berechnung zeigt "Lücke erkannt"</h3>
                                    <p><strong>Lösung:</strong> Eine Lücke meldet die Software nur bei Haaranalysen: Zwischen zwei Proben liegt mehr Zeit, als ein Haarsegment abdeckt (nach Segmentlänge und Deliktart). Urinproben werden gegen das Soll gezählt, nicht nach Abstand.</p>
                                    <p><strong>Was tun?</strong></p>
                                    <ol>
                                        <li>Prüfe, ob alle Proben eingegeben sind (vielleicht fehlt eine?)</li>
                                        <li>Prüfe die Daten der Proben (Tippfehler?)</li>
                                        <li>Falls tatsächlich eine Lücke besteht: Mit dem Labor klären, ob sich der Zeitraum noch abdecken lässt, und den Fall der Begutachtungsstelle offen darstellen.</li>
                                    </ol>
                                    
                                    <h3 class="mt-4">Problem: PDF wird nicht generiert / zeigt Fehler</h3>
                                    <p><strong>Lösung:</strong></p>
                                    <ol>
                                        <li>Prüfe, ob alle Pflichtfelder ausgefüllt sind (Name, Adresse des Klienten)</li>
                                        <li>Prüfe die PHP-Fehlerlog-Datei (frage Admin)</li>
                                        <li>Möglicherweise fehlen PHP-Erweiterungen (mbstring, gd)</li>
                                        <li>Kontaktiere den Systemadministrator</li>
                                    </ol>
                                    
                                    <h3 class="mt-4">Problem: Dashboard zeigt keine Warnungen, obwohl Fristen bald ablaufen</h3>
                                    <p><strong>Lösung:</strong> Prüfe deine Falldaten:</p>
                                    <ul>
                                        <li>Ist das <strong>Sperrfristende</strong> im Fall eingetragen?</li>
                                        <li>Ist der Fall-Status "aktiv"? (Abgeschlossene Fälle werden nicht gewarnt)</li>
                                        <li>Aktualisiere die Seite (F5 / Strg+R)</li>
                                    </ul>
                                    
                                    <div class="alert alert-info mt-3" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Tipp:</h4>
                                            <div class="alert-description">Bei technischen Problemen (Datenbankfehler, Software-Bugs) kontaktiere deinen Systemadministrator. Für fachliche Fragen (Hypothesen, Abstinenzfristen) konsultiere die BK-5 oder erfahrene Kollegen.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 12. TUTORIAL: KOMPLETTER FALL -->
                            <div class="card mb-4" id="tutorial">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-route me-2 text-primary" aria-hidden="true"></i>Tutorial: Kompletter Fall von A bis Z</h2>
                                </div>
                                <div class="card-body">
                                    <p>Dieses Tutorial zeigt dir <strong>Schritt für Schritt</strong>, wie ein typischer MPU-Fall in der Software abgewickelt wird.</p>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-user icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Beispiel-Klient:</h4>
                                            <div class="alert-description">
                                                <strong>Name:</strong> Max Mustermann<br>
                                                <strong>Delikt:</strong> Alkohol am Steuer (BAK 1,8 ‰)<br>
                                                <strong>Sperrfrist:</strong> 12 Monate (bis 15.10.2026)<br>
                                                <strong>Ziel:</strong> MPU bestehen und Führerschein zurückbekommen
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h3 class="mt-4">Phase 1: Erstkontakt &amp; Anlegen (Tag 1)</h3>
                                    <h4>Schritt 1: Klient anlegen</h4>
                                    <ol>
                                        <li>Klicke „Neue Klientin/Neuer Klient"</li>
                                        <li>Fülle aus: Vorname Max, Nachname Mustermann, Geburtsdatum 15.03.1985, E-Mail max.mustermann@example.com, Telefon 0171 1234567, Adresse: Hauptstraße 42, 80539 München</li>
                                        <li>Speichern → Klient #K-00042 erstellt <i class="ti ti-check text-success" aria-hidden="true"></i></li>
                                    </ol>

                                    <h4>Schritt 2: DSGVO-Einwilligung einholen</h4>
                                    <ol>
                                        <li>Öffne das Klientenprofil</li>
                                        <li>Klicke „Datenschutzerklärung PDF" → Drucken</li>
                                        <li>Lass Max unterschreiben</li>
                                        <li>Trage in der Software ein: „DSGVO-Einwilligung erteilt" + Datum</li>
                                    </ol>

                                    <h4>Schritt 3: Fall anlegen</h4>
                                    <ol>
                                        <li>Klicke auf der Klienten-Seite auf <strong>„Neuer Fall"</strong></li>
                                        <li>Fülle die Felder aus:<br>
                                            <ul class="mt-1">
                                                <li><strong>Delikttyp:</strong> Alkohol</li>
                                                <li><strong>BAK-Wert:</strong> <code>1,8</code> ‰ ← <em>dieser Wert steuert die Triage!</em></li>
                                                <li><strong>ICD-Score:</strong> <code>1</code> (kein Abhängigkeitsbefund bei Max)</li>
                                                <li><strong>BKF:</strong> Nein (Max fährt privat)</li>
                                                <li><strong>Behörde:</strong> Fahrerlaubnisbehörde München</li>
                                                <li><strong>Aktenzeichen:</strong> FEB-MUC-2025-789</li>
                                                <li><strong>Sperrfristende:</strong> 15.10.2026</li>
                                                <li><strong>MPU-Zieldatum:</strong> 01.10.2026</li>
                                            </ul>
                                        </li>
                                        <li>Klicke <strong>„Speichern"</strong></li>
                                        <li>Die <strong>Triage-Engine berechnet automatisch:</strong><br>
                                            BAK 1,8 ‰ + ICD-Score &lt; 3 + kein BKF → <span class="badge bg-info text-info-fg">Hypothese A3</span> (Alkoholgefährdung) → Abstinenz: <strong>6 Monate</strong><br>
                                            <span class="text-secondary small">Die Checkliste für A3 wird automatisch generiert und auf der Fallseite angezeigt.</span>
                                        </li>
                                    </ol>
                                    <div class="alert alert-success py-2 mb-0">
                                        <small><i class="ti ti-bulb me-1" aria-hidden="true"></i><strong>Triage falsch?</strong> Kein Problem – öffne den Fall, klicke „Hypothese überschreiben", wähle die richtige Hypothese und notiere den Grund. Die Änderung steht im Audit-Log, die Begründung in der Akte.</small>
                                    </div>

                                    <h3 class="mt-4">Phase 2: Beratung &amp; Dokumentation (Monat 1–6)</h3>
                                    <h4>Schritt 4: Erste Sitzung (Anamnese)</h4>
                                    <ol>
                                        <li>Am 20.04.2026: Klicke „Neue Sitzung"</li>
                                        <li>Fülle aus: Sitzungsnummer 1, Datum 20.04.2026, Dauer 90 Min, Phase Anamnese</li>
                                        <li>SORKC: S „Feierabend, Kneipe", O „Gestresst", R „4 Bier getrunken", K „Jedes Wochenende", C „Kurzfristig gelöst, dann BAK 1,8 ‰ und Führerscheinverlust"</li>
                                        <li>Speichern <i class="ti ti-check text-success" aria-hidden="true"></i></li>
                                    </ol>

                                    <h4>Schritt 5: Abstinenz beginnen</h4>
                                    <ol>
                                        <li>Vereinbare mit Max: 6 Monate Urinproben (alle 2 Wochen)</li>
                                        <li>Erste Probe 25.04.2026: Kreatinin 120 mg/dl (Laborbefund: 1,2 g/l), PEth unauffällig → Negativ <i class="ti ti-check text-success" aria-hidden="true"></i></li>
                                        <li>Weitere 11 Proben dokumentieren (im 2-Wochen-Rhythmus)</li>
                                    </ol>

                                    <h4>Schritt 6: Analyse-Sitzungen anlegen (Sitzung 2–5, Monat 1–5)</h4>
                                    <p>Alle 4 Wochen eine Sitzung, jeweils so anlegen:</p>
                                    <ol>
                                        <li>Klientenprofil öffnen → Fall öffnen → <strong>„Neue Sitzung"</strong> klicken</li>
                                        <li>Metadaten ausfüllen:
                                            <ul class="mt-1">
                                                <li><strong>Sitzungsnummer:</strong> 2, 3, 4 oder 5 (fortlaufend)</li>
                                                <li><strong>Datum:</strong> z.B. 20.05.2026, 20.06.2026, 20.07.2026, 20.08.2026</li>
                                                <li><strong>Dauer:</strong> z.B. 60 Min</li>
                                                <li><strong>Phase:</strong> <em>Analyse</em> wählen</li>
                                            </ul>
                                        </li>
                                        <li>SORKC für diese Sitzung ausfüllen – typische Analyse-Themen:
                                            <ul class="mt-1">
                                                <li><strong>S:</strong> Welche neuen Risikosituationen gab es? (z.B. „Fußballabend mit Kollegen, Betriebsfeier")</li>
                                                <li><strong>O:</strong> Wie hat sich Max' Befindlichkeit verändert? (z.B. „Weniger Stress, aber Schlafprobleme")</li>
                                                <li><strong>R:</strong> Welche Bewältigungsstrategien hat Max angewendet? (z.B. „Früher gegangen, Wasser getrunken, Sport")</li>
                                                <li><strong>K:</strong> Wie regelmäßig tritt das auf? (z.B. „Seit drei Monaten jede Woche ohne Alkohol")</li>
                                                <li><strong>C:</strong> Was war die Folge? (z.B. „Kein Alkohol, positiveres Selbstbild, entspannt auch ohne Alkohol")</li>
                                            </ul>
                                        </li>
                                        <li>Freitext: Risikofaktoren (Beruf, Familie, soziales Umfeld) und Fortschritte notieren</li>
                                        <li>Speichern <i class="ti ti-check text-success" aria-hidden="true"></i></li>
                                    </ol>
                                    <div class="alert alert-info py-2 mb-0">
                                        <small><i class="ti ti-flask me-1" aria-hidden="true"></i><strong>Parallel dazu:</strong> Jede Urinprobe eintragen, sobald der Befund vorliegt: Fall öffnen → „Neue Abstinenzprobe" → Typ „Urin" → Kreatinin eingeben → Speichern. Die Software zählt die Proben gegen das Soll des Programms.</small>
                                    </div>

                                    <h3 class="mt-4">Phase 3: MPU-Vorbereitung (Monat 6)</h3>
                                    <h4>Schritt 7: Check-Sitzung</h4>
                                    <p>Nach 6 Monaten: Letzte Sitzung (Phase Check). Überprüfung: Alle Urinproben laut Soll negativ <i class="ti ti-check text-success" aria-hidden="true"></i>, Keine Lücken <i class="ti ti-check text-success" aria-hidden="true"></i>, Stabile Verhaltensänderung <i class="ti ti-check text-success" aria-hidden="true"></i>, MPU-Termin gebucht <i class="ti ti-check text-success" aria-hidden="true"></i></p>

                                    <h4>Schritt 8: Strategiepapier generieren</h4>
                                    <p>Klicke „Strategiepapier PDF" → PDF mit Anamnese-Zusammenfassung, Abstinenz-Nachweis, Empfehlungen wird generiert → Drucken und Max mitgeben</p>

                                    <h3 class="mt-4">Phase 4: Abschluss (nach MPU)</h3>
                                    <h4>Schritt 9: MPU-Ergebnis eintragen</h4>
                                    <p>Max besteht am 01.10.2026 <i class="ti ti-confetti text-success" aria-hidden="true"></i> → Fall bearbeiten → Status „MPU bestanden", MPU-Datum 01.10.2026 → Speichern <i class="ti ti-check text-success" aria-hidden="true"></i></p>

                                    <h4>Schritt 10: Abrechnung</h4>
                                    <p>Paketpreis 1.200 €, Zahlungsstatus „Bezahlt", Zahlungsdatum 15.10.2026 → Fall abschließen → Status „Abgeschlossen" <i class="ti ti-check text-success" aria-hidden="true"></i></p>
                                    <div class="alert alert-success mt-4" role="alert">
                                        <div class="alert-icon"><i class="ti ti-trophy icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Herzlichen Glückwunsch!</h4>
                                            <div class="alert-description">Du hast deinen ersten Fall erfolgreich dokumentiert. Max hat seinen Führerschein zurück und du hast eine lückenlose Dokumentation.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- 13. BEST PRACTICES -->
                            <div class="card mb-4" id="best-practices">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-star me-2 text-primary" aria-hidden="true"></i>Best Practices &amp; Expertentipps</h2>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h4><i class="ti ti-calendar text-primary" aria-hidden="true"></i> Dashboard täglich checken</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Öffne morgens das Dashboard und prüfe rote Warnungen (Sperrfristen, Einspruchsfristen).</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Tipp: Setze dir einen täglichen Reminder (z.B. 9:00 Uhr).</p>

                                            <h4 class="mt-4"><i class="ti ti-pencil text-info" aria-hidden="true"></i> Sofort nach Sitzung dokumentieren</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Fülle Sitzungsprotokoll direkt danach aus, solange alles frisch ist.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Tipp: Plane 15 Min Pufferzeit nach jeder Sitzung ein.</p>

                                            <h4 class="mt-4"><i class="ti ti-brain text-purple" aria-hidden="true"></i> SORKC konsequent nutzen</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Das SORKC-Modell hilft, systematisch zu denken und dokumentieren.</p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> Vermeide vage Formulierungen wie „Klient hat Alkoholprobleme".</p>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Stattdessen: „S: Stress, O: Frustriert, R: 5 Biere, K: Jedes Wochenende, C: Kurzfristig Entspannung, dann BAK 1,8 ‰"</p>

                                            <h4 class="mt-4"><i class="ti ti-flask text-warning" aria-hidden="true"></i> Abstinenz-Lücken vermeiden</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Plane festen Rhythmus (z.B. jeden 2. Montag Urinprobe).</p>
                                            <p><i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i> Nicht abgedeckte Zeiträume bei Haaranalysen und fehlende Urinproben gefährden die Anerkennung. Darüber entscheidet die Begutachtungsstelle.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Nutze die automatische Lücken-Erkennung der Software.</p>

                                            <h4 class="mt-4"><i class="ti ti-droplet text-cyan" aria-hidden="true"></i> Kreatinin immer prüfen</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Kreatinin &lt; <?= $_mg_dl($_kreat_verwertbar) ?> mg/dl (<?= $_g_l($_kreat_verwertbar) ?> g/l) = verdünnte Probe, nicht verwertbar → wiederholen. Erst bei ≤ <?= $_mg_dl($_kreat_kein_neg) ?> mg/dl entfällt der Negativbefund ganz – die Wertung als Manipulation trifft der Gutachter, nicht die Software.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Informiere Klienten: „Trinken Sie normal, nicht extra viel Wasser!"</p>
                                        </div>

                                        <div class="col-md-6">
                                            <h4><i class="ti ti-bulb text-yellow" aria-hidden="true"></i> Triage kritisch hinterfragen</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Automatik ist nicht perfekt – nutze deine fachliche Einschätzung.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Bei Grenzfällen (z.B. BAK 1,9 ‰) Hypothese manuell überschreiben und begründen.</p>

                                            <h4 class="mt-4"><i class="ti ti-checkbox text-success" aria-hidden="true"></i> Checklisten abarbeiten</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Deliktabhängige Checklisten helfen, nichts zu vergessen.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Drucke Checkliste aus und hänge sie in die Papierakte.</p>

                                            <h4 class="mt-4"><i class="ti ti-lock text-danger" aria-hidden="true"></i> DSGVO ernst nehmen</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Datenschutzerklärung vor Beratung unterschreiben lassen.</p>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> Klientendaten nach 10 Jahren löschen (sofern keine rechtlichen Gründe dagegen).</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Audit-Log schützt dich bei Streitfällen.</p>

                                            <h4 class="mt-4"><i class="ti ti-database text-secondary" aria-hidden="true"></i> Backup regelmäßig durchführen</h4>
                                            <p><i class="ti ti-alert-triangle text-warning me-1" aria-hidden="true"></i> Datenverlust vermeiden: Stelle sicher, dass tägliche Datenbank-Backups laufen.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Bitte Admin, automatisches Backup-Skript einzurichten.</p>

                                            <h4 class="mt-4"><i class="ti ti-books text-indigo" aria-hidden="true"></i> Schulung &amp; Fortbildung</h4>
                                            <p><i class="ti ti-check text-success me-1" aria-hidden="true"></i> BK-5 ist aktuell, aber Rechtsprechung ändert sich.</p>
                                            <p><i class="ti ti-bulb text-yellow me-1" aria-hidden="true"></i> Besuche jährlich MPU-Fortbildung und tausche dich mit Kollegen aus.</p>
                                        </div>
                                    </div>

                                    <div class="alert alert-info mt-4" role="alert">
                                        <div class="alert-icon"><i class="ti ti-list-check icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Zusammenfassung:</h4>
                                            <div class="alert-description">Sei systematisch, dokumentiere lückenlos, nutze die Software-Tools konsequent und hinterfrage die Automatik kritisch. So baust du eine hochwertige Praxis auf!</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </main>

            <?php require 'partials/_footer.php'; ?>

        </div><!-- /.page-wrapper -->
    </div><!-- /.page -->

    <script src="assets/js/tabler.min.js"></script>
</body>
</html>
