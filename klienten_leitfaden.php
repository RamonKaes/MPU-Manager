<?php

declare(strict_types=1);

/**
 * klienten_leitfaden.php – Klienten-Leitfaden „Von der Tat bis zur bestandenen MPU"
 * ────────────────────────────────────────────────────────────────────────────────
 * Druckbare Anleitung, die dem Klienten ausgehändigt wird.
 *
 * Alle Abstinenz- und Karenzzeiten werden aus config/triage_config.php gelesen,
 * die Rückwärtsplanung der Wiedererteilung aus config/checklist_templates.php.
 * Beide Dateien bleiben damit die einzige Quelle der Wahrheit – der Leitfaden
 * kann nicht fachlich veralten, ohne dass die Config mitgeändert wird.
 *
 * Fachliche Basis: BK-5 (2026), Quellen siehe README.md.
 */

require_once 'db.php';

$cfg        = require __DIR__ . '/config/triage_config.php';
$checklists = require __DIR__ . '/config/checklist_templates.php';
$behoerden  = require __DIR__ . '/config/behoerden_config.php';

// R3-4: Behörden-Vorlauf in Wochen, wie ihn der Leitfaden ausweist. Die Tage
// sind die Quelle (config/behoerden_config.php), die Wochenangabe wird daraus
// gerechnet – sonst laufen Leitfaden und Fristen-Ampel auseinander.
$vorlauf_wochen = static fn(string $behoerde): int
    => (int)round($behoerden['vorlauf_tage'][$behoerde] / 7);

/**
 * Formatiert eine Abstinenzdauer für die Klienten-Tabelle.
 * Sind beide Werte gleich, wird nur eine Zahl ausgegeben.
 */
function leitfaden_dauer(int $mit_therapie, int $ohne_therapie): string
{
    if ($mit_therapie === $ohne_therapie) {
        return $mit_therapie . ' Monate';
    }
    return "{$mit_therapie} Monate (mit Therapie) / {$ohne_therapie} Monate (ohne Therapie)";
}

// Abstinenz-Übersicht – Reihenfolge = Beratungsrelevanz (Alkohol, Drogen, Verkehr, Medikamente)
$abstinenz_tabelle = [
    ['Alkoholabhängigkeit (A1)',            'Diagnose einer Abhängigkeit (ICD/DSM-Kriterien erfüllt)',                 leitfaden_dauer($cfg['A1']['monate_therapie'], $cfg['A1']['monate_selbstheiler']),                     $cfg['A1']['karenz']],
    ['Fortgeschrittene Alkoholproblematik (A2)', 'Sehr hohe Blutalkoholwerte (ab ' . number_format($cfg['bak']['giftfestigkeit'], 1, ',', '.') . ' ‰) – Giftfestigkeit', leitfaden_dauer($cfg['A2_hoch']['monate_therapie'], $cfg['A2_hoch']['monate_selbstheiler']),           $cfg['A2_hoch']['karenz']],
    ['Alkoholgefährdung (A2/A3)',           'Wiederholungstat oder deutliche Gewöhnung (ab ' . number_format($cfg['bak']['a3_ausgepraegt'], 1, ',', '.') . ' ‰)', leitfaden_dauer($cfg['A2_gefaehrdung']['monate_therapie'], $cfg['A2_gefaehrdung']['monate_selbstheiler']), $cfg['A2_gefaehrdung']['karenz']],
    ['Trennungsvermögen Alkohol (A4)',      'Einmalige Trunkenheitsfahrt unter ' . number_format($cfg['bak']['a3_grenze'], 1, ',', '.') . ' ‰ ohne Erschwernis', $cfg['A4']['monate'] . ' Monate stabiler Nachweis (keine Abstinenzpflicht)',                             $cfg['A4']['karenz']],
    ['Drogenabhängigkeit (D1)',             'Diagnose einer Abhängigkeit',                                             leitfaden_dauer($cfg['D1']['monate_therapie'], $cfg['D1']['monate_selbstheiler']),                     $cfg['D1']['karenz']],
    ['Harte Drogen (D2)',                   'Kokain, Amphetamine, Heroin, MDMA u. a.',                                 leitfaden_dauer($cfg['D2_hart']['standard_monate'], $cfg['D2_hart']['erschwerend_monate']),            $cfg['D2_hart']['karenz']],
    ['Cannabis – Regelkonsum (D2)',         'THC-COOH im Blutserum über ' . number_format($cfg['thc']['cooh_regelkonsum'], 0, ',', '.') . ' ng/ml (spricht für regelmäßigen Konsum)', leitfaden_dauer($cfg['D2_cannabis']['standard_monate'], $cfg['D2_cannabis']['erschwerend_monate']),     $cfg['D2_cannabis']['karenz']],
    ['Cannabis – Gefährdung (D3)',          'Gelegentlicher Konsum',                                                   $cfg['D3_cannabis']['monate'] . ' Monate (bei reinem Cannabis-Delikt ggf. kontrollierter Konsum statt Abstinenz)', $cfg['D3_cannabis']['karenz']],
    ['Cannabis – Trennungsvermögen (D4)',   'THC bis ' . number_format($cfg['thc']['grenzwert_ng_ml'], 1, ',', '.') . ' ng/ml, reines Cannabis-Delikt', $cfg['D4']['monate'] . ' Monate Abstinenz – stattdessen 6 Monate Nachweis, dass Konsum und Fahren getrennt werden', $cfg['D4']['karenz']],
    ['Persönlichkeitsproblematik (V1)',     'Schwere Gewalt- oder Aggressionsdelikte im Straßenverkehr',               'Keine Abstinenz – ' . $cfg['V1']['karenz'] . ' Monate nachweisbarer Veränderungsprozess',              $cfg['V1']['karenz']],
    ['Punkte / Fehleinstellungen (V2/V3)',  'Punktestand, Raserei, wiederholte Verkehrsverstöße',                      'Keine Abstinenz – ' . $cfg['V']['karenz_standard'] . '–' . $cfg['V']['karenz_aggression'] . ' Monate beanstandungsfreies Fahrverhalten', $cfg['V']['karenz_aggression']],
    ['Medizinalcannabis (M1)',              'Ärztlich verordnete Dauermedikation',                                     'Keine Abstinenz – Nachweis der Therapietreue und Fahrsicherheit',                                      $cfg['M1']['karenz']],
    ['Fehlgebrauch von Arzneimitteln (M2)',         'Benzodiazepine, Schlaf- und Beruhigungsmittel',                           leitfaden_dauer($cfg['M2']['monate_therapie'], $cfg['M2']['monate_selbstheiler']),                     $cfg['M2']['karenz']],
    ['Substitution (M1)',                   'Laufende Methadon-/Opioid-Substitution',                                  'Keine Abstinenz – Compliance- und Stabilitätsnachweis',                                                $cfg['M3']['karenz']],
];

// Rückwärtsplanung: aus den Wiedererteilungs-Tasks (days_before_end) erzeugt.
// Sortierung: der zeitlich früheste Schritt (größter Abstand zum Sperrfristende) zuerst.
$wiedererteilung = array_filter(
    $checklists['wiedererteilung'],
    static fn(array $t): bool => isset($t['days_before_end'])
);
usort($wiedererteilung, static fn(array $a, array $b): int => $b['days_before_end'] <=> $a['days_before_end']);

$page_title    = 'Klienten-Leitfaden – MPU-Manager';
$page_css_file = 'assets/css/leitfaden.css';
require 'partials/_head.php';
$active_page = 'leitfaden';
require 'partials/_navbar.php';

$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
?>

            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <div class="page-pretitle">Für Klientinnen und Klienten</div>
                            <h1 class="page-title">Von der Tat bis zur bestandenen MPU</h1>
                            <p class="text-secondary">Der komplette Weg mit allen Fristen – zum Ausdrucken und Aushändigen</p>
                        </div>
                        <div class="col-auto ms-auto">
                            <button type="button" id="btn-drucken" class="btn btn-primary">
                                <i class="ti ti-printer me-1" aria-hidden="true"></i> Leitfaden drucken
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">
                    <div class="row g-3">

                        <!-- Inhaltsverzeichnis (im Druck ausgeblendet) -->
                        <div class="col-lg-3 mb-3 leitfaden-toc d-print-none">
                            <div class="card sticky-top">
                                <div class="card-body">
                                    <h2 class="card-title mb-3"><i class="ti ti-list-numbers me-2 text-primary" aria-hidden="true"></i>Inhalt</h2>
                                    <nav id="leitfaden-nav" class="nav flex-column nav-pills">
                                        <a href="#ueberblick"  class="nav-link">Der Weg im Überblick</a>
                                        <a href="#phase-0"     class="nav-link">1. Die Tat (Tag 0)</a>
                                        <a href="#phase-1"     class="nav-link">2. Recht &amp; Sperrfrist</a>
                                        <a href="#phase-2"     class="nav-link">3. Abstinenz &amp; Aufarbeitung</a>
                                        <a href="#phase-3"     class="nav-link">4. Antrag &amp; Rückwärtsplanung</a>
                                        <a href="#phase-4"     class="nav-link">5. Der MPU-Tag</a>
                                        <a href="#phase-5"     class="nav-link">6. Gutachten &amp; Führerschein</a>
                                        <a href="#negativ"     class="nav-link">Wenn es nicht klappt</a>
                                        <a href="#fristen"     class="nav-link">Alle Fristen auf einen Blick</a>
                                        <a href="#zeitplan"    class="nav-link">Mein persönlicher Zeitplan</a>
                                        <a href="#kontakte"    class="nav-link">Adressen &amp; Kontakte</a>
                                        <a href="#fehler"      class="nav-link">Die häufigsten Fehler</a>
                                    </nav>
                                    <div class="mt-3">
                                        <button type="button" id="btn-drucken-2" class="btn btn-outline-primary w-100">
                                            <i class="ti ti-printer me-1" aria-hidden="true"></i> Drucken
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Leitfaden -->
                        <div class="col-lg-9 leitfaden-inhalt" data-bs-spy="scroll" data-bs-target="#leitfaden-nav" tabindex="0">

                            <!-- Druck-Kopfzeile: nur auf Papier sichtbar -->
                            <div class="d-none d-print-block mb-3">
                                <h1 class="h2 mb-1">Von der Tat bis zur bestandenen MPU</h1>
                                <p class="mb-0">Ihr persönlicher Leitfaden – alle Schritte, alle Fristen. Stand: <?= $e(date('d.m.Y')) ?></p>
                                <hr>
                            </div>

                            <!-- ═══ ÜBERBLICK ═══════════════════════════════════════ -->
                            <div class="card mb-4" id="ueberblick">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-route me-2 text-primary" aria-hidden="true"></i>Der Weg im Überblick</h2>
                                </div>
                                <div class="card-body">
                                    <p>Zwischen der Tat und dem neuen Führerschein liegen sechs Phasen. Sie laufen <strong>teilweise parallel</strong> – das ist der wichtigste Punkt dieses Leitfadens: Wer erst mit der Abstinenz beginnt, wenn die Sperrfrist fast abgelaufen ist, verliert ein ganzes Jahr.</p>

                                    <div class="mb-3">
                                        <div class="leitfaden-schritt mb-3">
                                            <h3 class="h4 mb-1">Phase 1 – Die Tat (Tag 0)</h3>
                                            <p class="mb-0">Blutprobe, Führerschein weg. Ab jetzt zählt jeder Tag: Der frühestmögliche Start Ihrer Abstinenz bestimmt den frühestmöglichen MPU-Termin.</p>
                                        </div>
                                        <div class="leitfaden-schritt mb-3">
                                            <h3 class="h4 mb-1">Phase 2 – Recht (Wochen 1 bis ca. 8)</h3>
                                            <p class="mb-0">Bußgeldbescheid oder Strafbefehl, Einspruchsfrist von <strong>2 Wochen</strong>, dann Rechtskraft. Mit der Rechtskraft beginnt die <strong>Sperrfrist</strong>.</p>
                                        </div>
                                        <div class="leitfaden-schritt mb-3">
                                            <h3 class="h4 mb-1">Phase 3 – Abstinenz &amp; Aufarbeitung (0 bis 15 Monate)</h3>
                                            <p class="mb-0">Die längste Phase. Sie läuft <em>während</em> der Sperrfrist. Beginnt sie zu spät, verschiebt sich alles Weitere.</p>
                                        </div>
                                        <div class="leitfaden-schritt mb-3">
                                            <h3 class="h4 mb-1">Phase 4 – Antrag bei der Führerscheinstelle (ca. 3 Monate vor Sperrfristende)</h3>
                                            <p class="mb-0">Antrag, Führungszeugnis, Wahl der Begutachtungsstelle, Aktenversand.</p>
                                        </div>
                                        <div class="leitfaden-schritt mb-3">
                                            <h3 class="h4 mb-1">Phase 5 – Der MPU-Tag</h3>
                                            <p class="mb-0">Medizinische Untersuchung, Leistungstest, psychologisches Gespräch – alles an einem Termin.</p>
                                        </div>
                                        <div class="leitfaden-schritt">
                                            <h3 class="h4 mb-1">Phase 6 – Gutachten &amp; Führerschein</h3>
                                            <p class="mb-0">Gutachten nach 2–4 Wochen, Einreichung bei der Behörde, Aushändigung des Führerscheins nach Ablauf der Sperrfrist.</p>
                                        </div>
                                    </div>

                                    <div class="alert alert-important alert-warning" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alert-triangle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Die eine Regel, die über alles entscheidet</h4>
                                            <div class="alert-description">Die Sperrfrist und Ihre Abstinenzzeit laufen <strong>nicht nacheinander, sondern gleichzeitig</strong>. Ist die Sperrfrist am Ende, Ihre Abstinenz aber noch nicht vollständig, bekommen Sie den Führerschein trotzdem nicht zurück – Sie warten dann zusätzlich. Deshalb: Abstinenz so früh wie möglich beginnen und <strong>lückenlos</strong> dokumentieren lassen.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ PHASE 0: DIE TAT ════════════════════════════════ -->
                            <div class="card mb-4" id="phase-0">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-alert-octagon me-2 text-danger" aria-hidden="true"></i>1. Die Tat (Tag 0)</h2>
                                </div>
                                <div class="card-body">
                                    <h3>Was jetzt passiert</h3>
                                    <ul>
                                        <li>Bei der Kontrolle wird eine <strong>Blutprobe</strong> (Alkohol: BAK-Wert; Drogen: THC-, THC-COOH- oder andere Werte) entnommen. Dieser Wert entscheidet später darüber, wie lange Sie Abstinenz nachweisen müssen.</li>
                                        <li>Bei Straftaten (z. B. Trunkenheitsfahrt ab 1,1 ‰) wird der Führerschein meist sofort <strong>vorläufig entzogen</strong> (§ 111a StPO). Diese Zeit wird später auf die Sperrfrist angerechnet.</li>
                                        <li>Bei Ordnungswidrigkeiten oder Punktefällen läuft ein <strong>Verwaltungsverfahren</strong> der Führerscheinstelle – der Entzug kommt dann per Bescheid.</li>
                                    </ul>

                                    <h3 class="mt-4">Was Sie in den ersten Tagen tun sollten</h3>
                                    <ol>
                                        <li><strong>Keine Angaben zur Sache ohne anwaltlichen Rat.</strong> Sie müssen sich nicht selbst belasten.</li>
                                        <li><strong>Alle Schreiben aufbewahren</strong> – mit dem Datum des Briefumschlags. Fristen laufen ab <em>Zustellung</em>, nicht ab dem Datum im Brief.</li>
                                        <li><strong>Konsum sofort einstellen.</strong> Der Tag, an dem Sie aufhören, ist nicht der Tag, ab dem Ihre Abstinenz zählt – gezählt wird ab dem <strong>ersten negativen Laborbefund</strong> in einem anerkannten Abstinenzprogramm. Je länger Sie mit der Anmeldung warten, desto später beginnt die Uhr.</li>
                                        <li><strong>Beratungstermin vereinbaren</strong>, bevor Sie einen Vertrag mit einem Labor unterschreiben. Die Wahl von Programmdauer und Methode (Urin oder Haar) muss zu Ihrer Fallkonstellation passen – ein falsch gewähltes Programm ist verlorene Zeit und verlorenes Geld.</li>
                                    </ol>

                                    <div class="alert alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-clock-hour-4 icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Warum der Start so wichtig ist</h4>
                                            <div class="alert-description">Ein Beispiel: Die Sperrfrist beträgt 12 Monate, Ihr Fall verlangt 12 Monate Abstinenz. Starten Sie das Abstinenzprogramm sofort, endet beides ungefähr zeitgleich. Starten Sie erst nach 6 Monaten, ist die Sperrfrist zwar abgelaufen – die MPU können Sie aber erst ein halbes Jahr später bestehen.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ PHASE 1: RECHT ══════════════════════════════════ -->
                            <div class="card mb-4" id="phase-1">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-gavel me-2 text-primary" aria-hidden="true"></i>2. Recht &amp; Sperrfrist (Wochen 1–8)</h2>
                                </div>
                                <div class="card-body">

                                    <h3>Die 2-Wochen-Frist: Einspruch</h3>
                                    <p>Sobald ein <strong>Bußgeldbescheid</strong> (§ 67 OWiG) oder ein <strong>Strafbefehl</strong> (§ 410 StPO) zugestellt wird, läuft eine Frist von <strong>zwei Wochen</strong>. Sie beginnt am Tag <em>nach</em> der Zustellung.</p>
                                    <ul>
                                        <li><strong>Wofür gilt sie?</strong> Nur für den Einspruch gegen die Entscheidung selbst – nicht gegen die MPU.</li>
                                        <li><strong>Was passiert, wenn sie verstreicht?</strong> Der Bescheid wird <strong>rechtskräftig</strong>. Er ist dann nicht mehr angreifbar, und die Sperrfrist beginnt zu laufen.</li>
                                        <li><strong>Was ist zu tun?</strong> Sofort anwaltlich prüfen lassen, ob ein Einspruch sinnvoll ist. Ein Einspruch verzögert die Rechtskraft – und damit auch den Beginn der Sperrfrist. Das kann von Vorteil sein (mehr Zeit für die Aufarbeitung) oder von Nachteil (die Sperrfrist beginnt später und endet später).</li>
                                    </ul>

                                    <h3 class="mt-4">Die Sperrfrist (§ 69a StGB)</h3>
                                    <table class="table table-bordered">
                                        <tbody>
                                            <tr>
                                                <th style="width: 28%;">Ab wann läuft sie?</th>
                                                <td>Ab <strong>Rechtskraft</strong> des Urteils oder Strafbefehls. Eine bereits vorläufige Entziehung (§ 111a StPO) wird angerechnet.</td>
                                            </tr>
                                            <tr>
                                                <th>Wie lange dauert sie?</th>
                                                <td>Gesetzlich <strong>6 Monate bis 5 Jahre</strong>; bei erstmaliger Trunkenheitsfahrt liegt sie in der Praxis häufig bei 9–12 Monaten. Das genaue Datum steht in Ihrem Urteil bzw. Strafbefehl.</td>
                                            </tr>
                                            <tr>
                                                <th>Wofür gilt sie?</th>
                                                <td>Während der Sperrfrist darf Ihnen <strong>keine</strong> neue Fahrerlaubnis erteilt werden – auch nicht mit positivem MPU-Gutachten.</td>
                                            </tr>
                                            <tr>
                                                <th>Was ist zu tun?</th>
                                                <td>Das Sperrfristende ist Ihr <strong>Zielanker</strong>. Von diesem Datum aus wird rückwärts geplant (siehe Abschnitt 4). Die Sperrfrist läuft nicht automatisch aus – Sie müssen die Neuerteilung <strong>selbst beantragen</strong>.</td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <h3 class="mt-4">Die MPU-Anordnung</h3>
                                    <p>Die Führerscheinstelle entscheidet, ob eine MPU verlangt wird – die Rechtsgrundlagen sind § 13 FeV (Alkohol), § 13a FeV (Cannabis), § 14 FeV (andere Drogen) und § 11 FeV (allgemein, u. a. Punkte). Sie erhalten eine schriftliche <strong>Beibringungsaufforderung</strong>.</p>
                                    <ul>
                                        <li><strong>Frist:</strong> Die Behörde setzt Ihnen selbst eine Frist zur Vorlage des Gutachtens (häufig 2–3 Monate). Sie steht in dem Schreiben – lesen Sie sie genau.</li>
                                        <li><strong>Reicht die Frist nicht?</strong> Beantragen Sie <strong>schriftlich und vor Fristablauf</strong> eine Verlängerung, z. B. weil Ihr Abstinenzprogramm noch läuft. Formlos, mit Begründung.</li>
                                        <li><strong>Achtung, größte Falle:</strong> Legen Sie das angeforderte Gutachten nicht vor, darf die Behörde nach <strong>§ 11 Abs. 8 FeV</strong> unterstellen, dass Sie ungeeignet sind. Ein Nicht-Antreten wirkt wie ein negatives Gutachten.</li>
                                    </ul>

                                    <h3 class="mt-4">Akteneinsicht (§ 29 VwVfG)</h3>
                                    <p>Sie haben Anspruch darauf, die vollständige Akte der Führerscheinstelle einzusehen. Beantragen Sie sie <strong>schriftlich und früh</strong> – die Behörde braucht dafür in der Regel bis zu einem Monat. Nur so kennen Sie die Werte (BAK, THC), auf die sich der Gutachter später stützt. Ohne diese Werte kann Ihre Vorbereitung nicht präzise geplant werden.</p>
                                </div>
                            </div>

                            <!-- ═══ PHASE 2: ABSTINENZ ══════════════════════════════ -->
                            <div class="card mb-4 seitenumbruch" id="phase-2">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-flask me-2 text-primary" aria-hidden="true"></i>3. Abstinenz &amp; Aufarbeitung (die längste Frist)</h2>
                                </div>
                                <div class="card-body">

                                    <h3>Wann beginnt die Abstinenzzeit?</h3>
                                    <div class="alert alert-warning" role="alert">
                                        <div class="alert-icon"><i class="ti ti-calendar-event icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <div class="alert-description"><strong>Nicht</strong> an dem Tag, an dem Sie aufhören zu konsumieren. Gezählt wird ab dem <strong>ersten negativen Befund</strong> in einem forensisch anerkannten Abstinenzprogramm, für das Sie einen Vertrag mit einem akkreditierten Labor abgeschlossen haben. Selbst geführte Tagebücher, Hausarzt-Bescheinigungen oder Apothekentests zählen nicht.</div>
                                        </div>
                                    </div>

                                    <h3 class="mt-4">Wie lange dauert sie? (nach BK-5)</h3>
                                    <p>Die Dauer hängt davon ab, wie Ihr Fall eingeordnet wird. Ihre Beraterin oder Ihr Berater teilt Ihnen die für Sie geltende Konstellation mit. Die letzte Spalte – die <strong>Karenzzeit</strong> – ist die anschließende Bewährungsphase, in der Sie das neue Verhalten stabil zeigen müssen; Gutachter erwarten sie zusätzlich zur reinen Abstinenz.</p>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th style="width: 24%;">Fallkonstellation</th>
                                                    <th style="width: 26%;">Wann trifft das zu?</th>
                                                    <th style="width: 32%;">Nachweisdauer</th>
                                                    <th style="width: 18%;">Karenzzeit danach</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($abstinenz_tabelle as [$konstellation, $anlass, $dauer, $karenz]): ?>
                                                <tr>
                                                    <td><strong><?= $e($konstellation) ?></strong></td>
                                                    <td><?= $e($anlass) ?></td>
                                                    <td><?= $e($dauer) ?></td>
                                                    <td><?= $karenz > 0 ? $e((string)$karenz . ' Monate') : '–' ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-secondary small">Quelle: Beurteilungskriterien BK-5 (2026). Die Einordnung Ihres Falls nimmt die Beratungsstelle vor; das letzte Wort hat der Gutachter.</p>

                                    <h3 class="mt-4">Die Spielregeln des Abstinenzprogramms (CTU-Kriterien)</h3>
                                    <p>Das Programm ist erst dann verwertbar, wenn es <strong>lückenlos</strong> ist. Ein einziger versäumter Termin kann alles Bisherige entwerten – Sie beginnen dann von vorn.</p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th style="width: 22%;">Regel</th>
                                                    <th style="width: 22%;">Frist</th>
                                                    <th>Was das für Sie bedeutet</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Urinproben</strong></td>
                                                    <td>Kein fester Abstand: Der Abruf erfolgt <strong>unangekündigt und unregelmäßig</strong></td>
                                                    <td>Sie werden <strong>kurzfristig einbestellt</strong> und müssen sehr zeitnah erscheinen. Fragen Sie Ihr Labor nach der genauen Erscheinensfrist und halten Sie sich erreichbar. 6 Monate = 4 Proben, 12 Monate = 6 Proben, 15 Monate = 7 Proben.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Haaranalysen</strong></td>
                                                    <td>Höchstens <strong>95 Tage</strong> Abstand</td>
                                                    <td>1 cm Haar ≈ 1 Monat Nachweiszeitraum. 3 cm decken 3 Monate ab, 6 cm sechs Monate. Kopfhaar nicht zu kurz schneiden, nicht blondieren, keine Chemie – sonst ist die Probe unbrauchbar.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Nicht erscheinen</strong></td>
                                                    <td>Sofortwirkung</td>
                                                    <td>Ein versäumter Termin wird in der Regel <strong>wie ein positiver Befund</strong> gewertet – auch bei Krankheit oder Urlaub. Im Zweifel hingehen und ein Attest nachreichen.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Verdünnte Probe</strong></td>
                                                    <td>Sofortwirkung</td>
                                                    <td>Ein zu niedriger Kreatininwert kann die Probe unverwertbar machen und den Abstinenznachweis gefährden. Trinken Sie vor der Probe <strong>normal</strong>, nicht literweise Wasser.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Cannabis im D4-Programm</strong></td>
                                                    <td>Dauerhaft</td>
                                                    <td>Im laufenden Kontrollprogramm gilt ein <strong>strengerer</strong> Grenzwert als im Straßenverkehr: Der Verkehrsgrenzwert von <?= $e(number_format($cfg['thc']['grenzwert_ng_ml'], 1, ',', '.')) ?> ng/ml ist <strong>kein</strong> Freibrief. Wer sich daran orientiert, verliert das Programm.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Parallel dazu: die Aufarbeitung</h3>
                                    <p>Abstinenz allein besteht keine MPU. Der Gutachter will verstehen, <em>warum</em> es passiert ist und <em>was sich geändert hat</em>. Nutzen Sie die Monate der Abstinenz für:</p>
                                    <ul>
                                        <li><strong>Verkehrspsychologische Beratung</strong> – die Sitzungen bei uns; sie sind der Kern der Vorbereitung.</li>
                                        <li><strong>Selbsthilfegruppe</strong> (AA, Kreuzbund, NA, Freundeskreise) – regelmäßige Teilnahme, schriftlich bestätigt. Gutachter werten das als Stabilitätsnachweis.</li>
                                        <li><strong>Ärztliche Befunde</strong> – z. B. Leberwerte, PEth-Basisbefund zu Beginn und Verlaufswerte nach 3, 6 und 12 Monaten.</li>
                                        <li><strong>Erste-Hilfe-Kurs und Sehtest</strong> – formale Voraussetzungen; früh erledigen, Plätze sind oft Wochen im Voraus ausgebucht.</li>
                                    </ul>
                                </div>
                            </div>

                            <!-- ═══ PHASE 3: ANTRAG ═════════════════════════════════ -->
                            <div class="card mb-4 seitenumbruch" id="phase-3">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-file-text me-2 text-primary" aria-hidden="true"></i>4. Antrag &amp; Rückwärtsplanung</h2>
                                </div>
                                <div class="card-body">
                                    <p>Der Antrag auf Neuerteilung (§ 20 FeV) muss <strong>persönlich</strong> bei der Führerscheinstelle Ihres Hauptwohnsitzes gestellt werden. Die Behörde erteilt die Fahrerlaubnis nicht von selbst, wenn die Sperrfrist endet.</p>

                                    <div class="alert alert-important alert-danger" role="alert">
                                        <div class="alert-icon"><i class="ti ti-hourglass icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Rechnen Sie rückwärts, nicht vorwärts</h4>
                                            <div class="alert-description">Zwischen Antrag und neuem Führerschein liegen Bearbeitungszeiten von insgesamt rund <strong>drei Monaten</strong>: Behörde, Führungszeugnis, Aktenversand, Wartezeit auf den MPU-Termin, Gutachtenerstellung. Wer erst kurz vor Sperrfristende beginnt, wartet danach noch monatelang.</div>
                                        </div>
                                    </div>

                                    <h3>Ihr Zeitplan vor dem Sperrfristende</h3>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th style="width: 20%;">Wann</th>
                                                    <th style="width: 30%;">Schritt</th>
                                                    <th>Worauf es ankommt</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($wiedererteilung as $task): ?>
                                                <?php $wochen = (int)round($task['days_before_end'] / 7); ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($wochen > 0): ?>
                                                            <strong><?= $e((string)$wochen) ?> Wochen</strong><br>
                                                            <span class="text-secondary small">vor Sperrfristende</span>
                                                        <?php else: ?>
                                                            <strong>Bis Sperrfristende</strong>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><strong><?= $e($task['label']) ?></strong></td>
                                                    <td><?= $e($task['description']) ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Was Sie zum Antrag mitbringen</h3>
                                    <ul>
                                        <li>Personalausweis oder Reisepass</li>
                                        <li><strong>Biometrisches Passfoto</strong> (35 × 45 mm). <strong>Kreis Mettmann:</strong> nur physische Papierfotos – QR-Codes und digitale Fotos werden abgelehnt.</li>
                                        <li>Alter Führerschein, falls noch vorhanden</li>
                                        <li>Antragsgebühr 35–50 € (<strong>Düsseldorf:</strong> nur bargeldlos – Girocard oder Kreditkarte, kein Bargeld)</li>
                                        <li><strong>Führungszeugnis Belegart „O"</strong> – direkt beim Bürgeramt mitbeantragen, geht direkt an die Behörde. 13 €, Bearbeitung 2–3 Wochen.</li>
                                        <li>Bei LKW/Bus (Klassen C/D): ärztliche Bescheinigung und Sehtest nach Anlage 5 FeV</li>
                                    </ul>

                                    <h3 class="mt-4">Terminvergabe der Behörden</h3>
                                    <ul>
                                        <li><strong>Landeshauptstadt Düsseldorf</strong> (Höherweg 101): Online-Termin <strong>zwingend</strong>, keine Spontanvorsprache. Bearbeitungszeit derzeit 8–12 Wochen → mindestens <strong><?= $vorlauf_wochen('duesseldorf') ?> Wochen</strong> vor Sperrfristende beantragen.</li>
                                        <li><strong>Kreis Mettmann</strong> (Düsseldorfer Str. 26): Online-Termin empfohlen; Abgabe in vielen Bürgerbüros möglich – <strong>außer</strong> in Ratingen und Velbert, dort nur direkt in Mettmann. Empfehlung: mindestens <strong><?= $vorlauf_wochen('mettmann') ?> Wochen</strong> vorher.</li>
                                    </ul>

                                    <h3 class="mt-4">Begutachtungsstelle (BfF) wählen</h3>
                                    <p><strong>Sie</strong> wählen die Begutachtungsstelle – nicht die Behörde. Teilen Sie Ihre Wahl der Führerscheinstelle mit, damit die Akte dorthin versandt wird. Fragen Sie nach <strong>2–4 Wochen</strong> bei der BfF nach, ob die Akte angekommen ist; das ist der häufigste Punkt, an dem Verfahren still stehen. Die Wartezeit auf einen Untersuchungstermin beträgt typischerweise <strong>4–8 Wochen</strong>.</p>
                                </div>
                            </div>

                            <!-- ═══ PHASE 4: MPU-TAG ════════════════════════════════ -->
                            <div class="card mb-4" id="phase-4">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-clipboard-check me-2 text-primary" aria-hidden="true"></i>5. Der MPU-Tag</h2>
                                </div>
                                <div class="card-body">
                                    <p>Die Untersuchung dauert je nach Fragestellung etwa drei bis vier Stunden und besteht aus drei Bausteinen:</p>
                                    <ol>
                                        <li><strong>Medizinische Untersuchung:</strong> Körperliche Untersuchung, Blutwerte, Fragen zu Vorerkrankungen und Konsumgeschichte. Bei Drogenfällen häufig zusätzlich eine Urinprobe vor Ort.</li>
                                        <li><strong>Leistungstest am Computer:</strong> Reaktionsfähigkeit, Konzentration, Aufmerksamkeit. Bewertet wird der Prozentrang (PR). Erforderlich ist in allen Tests <strong>PR ≥ 16</strong>. Für <strong>Berufskraftfahrer</strong> (LKW/Bus) gilt: in der Mehrzahl der Tests <strong>PR ≥ 33</strong>. Fällt ein Test unter PR 16, müssen die übrigen Tests mindestens <strong>PR 50</strong> erreichen – andernfalls ist eine Fahrverhaltensbeobachtung nötig. Ausgeschlafen und nüchtern erscheinen; keine beruhigenden Medikamente ohne Rücksprache.</li>
                                        <li><strong>Psychologisches Gespräch:</strong> Der Kern. Erwartet wird, dass Sie Ihre Tat, Ihre Konsumgeschichte und Ihre Veränderung <strong>ohne Beschönigung</strong> und ohne auswendig gelernte Formeln schildern können. Widersprüche zwischen Ihren Angaben und der Aktenlage (BAK-Wert, Vorfälle, FAER-Einträge) fallen sofort auf.</li>
                                    </ol>

                                    <h3 class="mt-4">Was Sie mitbringen</h3>
                                    <ul>
                                        <li>Personalausweis</li>
                                        <li><strong>Alle Original-Abstinenzbelege</strong>, chronologisch sortiert</li>
                                        <li>Ärztliche Befunde, Therapie- oder Teilnahmebescheinigungen</li>
                                        <li>FAER-Auszug (nicht älter als 3 Monate), Erste-Hilfe-Nachweis, Sehtest</li>
                                        <li>Brille oder Kontaktlinsen, falls Sie welche brauchen</li>
                                    </ul>

                                    <div class="alert alert-warning" role="alert">
                                        <div class="alert-icon"><i class="ti ti-alarm icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <div class="alert-description"><strong>Pünktlich erscheinen.</strong> Verspätung oder Nichterscheinen kann als Verweigerung gewertet werden – mit denselben Folgen wie ein negatives Gutachten.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ PHASE 5: GUTACHTEN ══════════════════════════════ -->
                            <div class="card mb-4" id="phase-5">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-license me-2 text-success" aria-hidden="true"></i>6. Gutachten &amp; Führerschein zurück</h2>
                                </div>
                                <div class="card-body">

                                    <div class="alert alert-important alert-info" role="alert">
                                        <div class="alert-icon"><i class="ti ti-shield-check icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <h4 class="alert-heading">Der wichtigste Satz auf dem gesamten MPU-Weg</h4>
                                            <div class="alert-description">Lassen Sie das Gutachten in der Schweigepflichtentbindung <strong>zuerst an sich selbst</strong> senden, nicht direkt an die Behörde. Nur dann können Sie bei einem negativen Ergebnis den Antrag zurückziehen, bevor die Behörde es sieht. Liegt ein negatives Gutachten erst bei der Behörde, wird es aktenkundig und Ihr Antrag abgelehnt.</div>
                                        </div>
                                    </div>

                                    <h3>Der Ablauf nach der Untersuchung</h3>
                                    <table class="table table-bordered">
                                        <tbody>
                                            <tr>
                                                <th style="width: 30%;">Gutachten wird erstellt</th>
                                                <td><strong>2–4 Wochen</strong> nach dem Untersuchungstermin. Es geht an die Adresse, die Sie in der Schweigepflichtentbindung angegeben haben.</td>
                                            </tr>
                                            <tr>
                                                <th>Positives Gutachten einreichen</th>
                                                <td>Im <strong>Original</strong>, persönlich mit Empfangsbestätigung oder per Einschreiben mit Rückschein. <strong>Kopie behalten.</strong> Erst durch die Einreichung läuft das Verfahren weiter.</td>
                                            </tr>
                                            <tr>
                                                <th>Behörde entscheidet</th>
                                                <td><strong>2–4 Wochen</strong> nach Einreichung ergeht der Wiedererteilungsbescheid.</td>
                                            </tr>
                                            <tr>
                                                <th>Führerschein abholen</th>
                                                <td>Erst wenn der Bescheid vorliegt <strong>und</strong> die Sperrfrist abgelaufen ist (§ 69a Abs. 1 StGB). Vorher darf die Behörde ihn nicht aushändigen – auch nicht bei bestandener MPU.</td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <h3 class="mt-4">Danach: die Tilgungsfristen (§ 29 StVG)</h3>
                                    <p>Ihre Eintragungen im Fahreignungsregister verschwinden nicht sofort. Solange sie gespeichert sind, wirken sie bei einem erneuten Vorfall verschärfend:</p>
                                    <ul>
                                        <li><strong>2 Jahre 6 Monate</strong> – Ordnungswidrigkeiten mit einem Punkt</li>
                                        <li><strong>5 Jahre</strong> – Ordnungswidrigkeiten mit zwei Punkten sowie Straftaten ohne Entziehung der Fahrerlaubnis</li>
                                        <li><strong>10 Jahre</strong> – Straftaten mit Entziehung der Fahrerlaubnis oder Sperre</li>
                                    </ul>
                                    <p class="text-secondary small">Die konkrete Frist Ihres Eintrags erfahren Sie über einen kostenpflichtigen Auszug aus dem Fahreignungsregister beim Kraftfahrt-Bundesamt (kba.de).</p>
                                </div>
                            </div>

                            <!-- ═══ NEGATIVES GUTACHTEN ═════════════════════════════ -->
                            <div class="card mb-4" id="negativ">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-refresh me-2 text-warning" aria-hidden="true"></i>Wenn es nicht klappt</h2>
                                </div>
                                <div class="card-body">
                                    <p>Ein negatives Gutachten ist kein Endpunkt – aber es kostet Zeit. Wichtig ist, richtig darauf zu reagieren:</p>
                                    <ol>
                                        <li><strong>Gutachten nicht einreichen</strong>, wenn es negativ ist. Ziehen Sie den Antrag zurück (das ist möglich, solange die Behörde es nicht hat). Ein zurückgezogener Antrag hat keine Sperrwirkung – Sie können jederzeit erneut beantragen.</li>
                                        <li><strong>Das Gutachten in der Beratung auswerten.</strong> Es benennt konkret, woran es gescheitert ist: unzureichende Abstinenzdauer, unglaubwürdige Angaben, fehlende Problemeinsicht, Leistungsdefizite.</li>
                                        <li><strong>Es gibt keine gesetzliche Wartezeit</strong> bis zur nächsten MPU. In der Praxis ist ein neuer Anlauf aber erst sinnvoll, wenn der im Gutachten benannte Mangel behoben ist – das dauert je nach Befund mehrere Monate. Eine sofortige Wiederholung mit demselben Vorbereitungsstand endet fast immer wieder negativ.</li>
                                        <li><strong>Empfiehlt das Gutachten einen Kurs</strong> zur Wiederherstellung der Kraftfahreignung (§ 70 FeV), kann dieser der schnellste Weg sein. Wir prüfen das gemeinsam.</li>
                                    </ol>
                                </div>
                            </div>

                            <!-- ═══ FRISTEN-MASTERTABELLE ═══════════════════════════ -->
                            <div class="card mb-4 seitenumbruch" id="fristen">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-calendar-stats me-2 text-primary" aria-hidden="true"></i>Alle Fristen auf einen Blick</h2>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th style="width: 20%;">Frist</th>
                                                    <th style="width: 18%;">Läuft ab wann?</th>
                                                    <th style="width: 16%;">Wie lange?</th>
                                                    <th style="width: 22%;">Wofür gilt sie?</th>
                                                    <th>Was ist zu tun?</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><strong>Einspruch Bußgeldbescheid</strong><br><span class="text-secondary small">§ 67 OWiG</span></td>
                                                    <td>Ab Zustellung des Bescheids</td>
                                                    <td><strong>2 Wochen</strong></td>
                                                    <td>Anfechtung des Bescheids</td>
                                                    <td>Sofort anwaltlich prüfen lassen. Danach ist der Bescheid rechtskräftig.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Einspruch Strafbefehl</strong><br><span class="text-secondary small">§ 410 StPO</span></td>
                                                    <td>Ab Zustellung</td>
                                                    <td><strong>2 Wochen</strong></td>
                                                    <td>Anfechtung von Strafe und Sperrfrist</td>
                                                    <td>Anwalt einschalten. Einspruch verschiebt die Rechtskraft – und damit den Beginn der Sperrfrist.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Sperrfrist</strong><br><span class="text-secondary small">§ 69a StGB</span></td>
                                                    <td>Ab Rechtskraft (vorläufige Entziehung wird angerechnet)</td>
                                                    <td><strong>6 Monate – 5 Jahre</strong><br>(Ersttat oft 9–12 Monate)</td>
                                                    <td>In dieser Zeit darf keine Fahrerlaubnis erteilt werden</td>
                                                    <td>Enddatum notieren – es ist Ihr Planungsanker. Abstinenz sofort beginnen, damit beide Fristen parallel laufen.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Beibringungsfrist für das Gutachten</strong><br><span class="text-secondary small">§ 11 FeV</span></td>
                                                    <td>Ab Zugang der MPU-Anordnung</td>
                                                    <td>Von der Behörde gesetzt, häufig <strong>2–3 Monate</strong></td>
                                                    <td>Vorlage des MPU-Gutachtens</td>
                                                    <td>Reicht sie nicht, <strong>vor Ablauf</strong> schriftlich Verlängerung beantragen. Keine Vorlage = Ungeeignetheit wird unterstellt (§ 11 Abs. 8 FeV).</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Akteneinsicht</strong><br><span class="text-secondary small">§ 29 VwVfG</span></td>
                                                    <td>Antrag jederzeit möglich</td>
                                                    <td>Behörde braucht ca. <strong>1 Monat</strong></td>
                                                    <td>Kenntnis der eigenen Werte und Vorwürfe</td>
                                                    <td>So früh wie möglich schriftlich beantragen – ohne Akte keine präzise Vorbereitung.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Abstinenzprogramm</strong></td>
                                                    <td>Ab dem <strong>ersten negativen Laborbefund</strong> – nicht ab dem letzten Konsumtag</td>
                                                    <td><strong>0, 6, 12 oder 15 Monate</strong> je nach Fallkonstellation</td>
                                                    <td>Nachweis der Verhaltensänderung gegenüber dem Gutachter</td>
                                                    <td>Vertrag mit akkreditiertem Labor abschließen. Erst nach Beratung – die Programmwahl muss passen.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Urinprobe</strong></td>
                                                    <td>Ab der vorigen Probe</td>
                                                    <td>Kein fester Abstand, Abruf unangekündigt</td>
                                                    <td>Lückenlosigkeit der Abstinenzkette</td>
                                                    <td>Erreichbar bleiben, kurzfristige Einbestellung einhalten. Versäumt = wie positiv gewertet.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Haaranalyse</strong></td>
                                                    <td>Ab der vorigen Probe</td>
                                                    <td>Höchstens <strong>95 Tage</strong> Abstand</td>
                                                    <td>Lückenlosigkeit über längere Zeiträume</td>
                                                    <td>Haar nicht zu kurz schneiden (mind. 3 cm), nicht färben oder blondieren.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Karenz-/Bewährungszeit</strong></td>
                                                    <td>Nach Ende der Abstinenzphase</td>
                                                    <td><strong>3–12 Monate</strong> je nach Fall</td>
                                                    <td>Nachweis, dass die Veränderung stabil ist</td>
                                                    <td>Einplanen! Sie kommt zur Abstinenzdauer hinzu und wird oft vergessen.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Puffer bis Sperrfristende</strong></td>
                                                    <td>Zwischen Abstinenzende und Sperrfristende</td>
                                                    <td>Mindestens <strong>90 Tage</strong></td>
                                                    <td>Zeit für Antrag, MPU-Termin und Gutachten</td>
                                                    <td>Ist der Puffer kleiner, verzögert sich die Wiedererteilung. Frühzeitig mit der Beratung gegensteuern.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Antrag auf Neuerteilung</strong><br><span class="text-secondary small">§ 20 FeV</span></td>
                                                    <td>Vor dem Sperrfristende</td>
                                                    <td>Düsseldorf: <strong>≥ <?= $vorlauf_wochen('duesseldorf') ?> Wochen</strong> vorher<br>Mettmann: <strong>≥ <?= $vorlauf_wochen('mettmann') ?> Wochen</strong> vorher</td>
                                                    <td>Einleitung des Wiedererteilungsverfahrens</td>
                                                    <td>Persönlich vorsprechen (Düsseldorf: Online-Termin zwingend). Passfoto und Gebühr mitbringen.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Führungszeugnis Belegart O</strong></td>
                                                    <td>Ab Beantragung beim Bürgeramt</td>
                                                    <td>Bearbeitung <strong>2–3 Wochen</strong></td>
                                                    <td>Pflichtbestandteil des Antrags</td>
                                                    <td>Am selben Tag wie den Antrag beantragen. Geht direkt an die Behörde, nicht an Sie.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>FAER-Auszug (Flensburg)</strong></td>
                                                    <td>Ab Ausstellung</td>
                                                    <td>Zum MPU-Termin höchstens <strong>3 Monate</strong> alt</td>
                                                    <td>Nachweis des aktuellen Punktestands</td>
                                                    <td>Rechtzeitig beim Kraftfahrt-Bundesamt anfordern (10,20 € online / 13 € postalisch).</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Wartezeit auf den MPU-Termin</strong></td>
                                                    <td>Ab Akteneingang bei der Begutachtungsstelle</td>
                                                    <td>Typisch <strong>4–8 Wochen</strong></td>
                                                    <td>Terminvergabe der Begutachtungsstelle</td>
                                                    <td>Nach 2–4 Wochen nachfragen, ob die Akte angekommen ist. Sonst steht das Verfahren still.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Gutachtenerstellung</strong></td>
                                                    <td>Ab dem Untersuchungstermin</td>
                                                    <td><strong>2–4 Wochen</strong></td>
                                                    <td>Erstellung und Zustellung des Gutachtens</td>
                                                    <td>Zustellung an sich selbst vereinbaren – nicht an die Behörde.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Entscheidung der Behörde</strong></td>
                                                    <td>Ab Einreichung des Gutachtens</td>
                                                    <td><strong>2–4 Wochen</strong></td>
                                                    <td>Wiedererteilungsbescheid</td>
                                                    <td>Original einreichen (Einschreiben), Kopie behalten. Führerschein erst nach Sperrfristablauf.</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Tilgungsfristen</strong><br><span class="text-secondary small">§ 29 StVG</span></td>
                                                    <td>Ab Rechtskraft der Entscheidung</td>
                                                    <td><strong>2,5 / 5 / 10 Jahre</strong></td>
                                                    <td>Speicherung im Fahreignungsregister</td>
                                                    <td>Bis zur Tilgung wirken Eintragungen bei neuen Vorfällen verschärfend.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ PERSÖNLICHER ZEITPLAN ═══════════════════════════ -->
                            <div class="card mb-4" id="zeitplan">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-calendar-plus me-2 text-primary" aria-hidden="true"></i>Mein persönlicher Zeitplan</h2>
                                </div>
                                <div class="card-body">
                                    <p>Tragen Sie Ihre Daten gemeinsam mit Ihrer Beraterin oder Ihrem Berater ein und hängen Sie das Blatt sichtbar auf.</p>
                                    <div class="table-responsive">
                                        <table class="table table-bordered leitfaden-zeitplan">
                                            <tbody>
                                                <tr><th style="width: 45%;">Datum der Tat</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Rechtskraft (Urteil / Bescheid)</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Ende der Sperrfrist</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Frist der Behörde zur Gutachtenvorlage</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Meine Fallkonstellation (Hypothese)</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Erforderliche Abstinenzdauer</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Erster negativer Befund (= Start der Abstinenz)</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Voraussichtliches Ende der Abstinenz</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Labor (Name, Telefon)</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Antrag bei der Führerscheinstelle bis spätestens</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Gewählte Begutachtungsstelle</th><td><span class="feld"></span></td></tr>
                                                <tr><th>MPU-Termin</th><td><span class="feld"></span></td></tr>
                                                <tr><th>Meine Beratungsstelle (Kontakt)</th><td><span class="feld"></span></td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <!-- ═══ KONTAKTE ════════════════════════════════════════ -->
                            <div class="card mb-4" id="kontakte">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-map-pin me-2 text-primary" aria-hidden="true"></i>Adressen &amp; Kontakte (Region Düsseldorf / Mettmann)</h2>
                                </div>
                                <div class="card-body">

                                    <h3>Führerscheinstellen</h3>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th style="width: 30%;">Behörde</th><th style="width: 35%;">Anschrift</th><th>Kontakt &amp; Besonderheit</th></tr></thead>
                                            <tbody>
                                                <tr>
                                                    <td>Straßenverkehrsamt Düsseldorf (Abt. 33/3)</td>
                                                    <td>Höherweg 101<br>40233 Düsseldorf</td>
                                                    <td>0211 89-91 · neuerteilung@duesseldorf.de<br><strong>Online-Termin zwingend, nur bargeldlose Zahlung.</strong></td>
                                                </tr>
                                                <tr>
                                                    <td>Straßenverkehrsamt Kreis Mettmann</td>
                                                    <td>Düsseldorfer Str. 26<br>40822 Mettmann</td>
                                                    <td>02104 99-1760 · sva.fuehrerschein@kreis-mettmann.de<br><strong>Nur physische Passfotos – keine QR-Codes.</strong></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Begutachtungsstellen für Fahreignung (BfF)</h3>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th style="width: 30%;">Stelle</th><th style="width: 45%;">Anschrift</th><th>Telefon</th></tr></thead>
                                            <tbody>
                                                <tr><td>TÜV NORD Mobilität</td><td>Graf-Adolf-Str. 59, 40210 Düsseldorf</td><td>0211 9365670</td></tr>
                                                <tr><td>pima-mpu GmbH</td><td>Konrad-Adenauer-Platz 12, 40210 Düsseldorf</td><td>0211 9365437</td></tr>
                                                <tr><td>AVUS GmbH</td><td>Friedrich-Ebert-Str. 32, 40210 Düsseldorf</td><td>0211 17930280</td></tr>
                                                <tr><td>1A Begutachtungsstelle</td><td>Oststr. 84, 40210 Düsseldorf</td><td>0211 92323064</td></tr>
                                                <tr><td>ABV GmbH</td><td>Charlottenstr. 49, 40210 Düsseldorf</td><td>0211 83022074</td></tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <h3 class="mt-4">Akkreditierte Labore (Abstinenznachweise)</h3>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th style="width: 30%;">Labor</th><th style="width: 45%;">Anschrift</th><th>Telefon</th></tr></thead>
                                            <tbody>
                                                <tr><td>MLD Medizinische Laboratorien Düsseldorf</td><td>Nordstr. 44, 40477 Düsseldorf</td><td>0211 49780</td></tr>
                                                <tr><td>UKD Institut für Rechtsmedizin</td><td>Moorenstr. 5, 40225 Düsseldorf</td><td>0211 8104555</td></tr>
                                                <tr><td>TOLAB</td><td>Hüttenstr. 31a, 40215 Düsseldorf</td><td>0211 377384</td></tr>
                                                <tr><td>Invitalab</td><td>Stresemannallee 4b, 41460 Neuss</td><td>02131 1259690</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="text-secondary small mt-2">Schließen Sie den Abstinenzvertrag erst nach Rücksprache mit Ihrer Beratungsstelle ab – Methode und Laufzeit müssen zu Ihrer Fallkonstellation passen.</p>
                                </div>
                            </div>

                            <!-- ═══ HÄUFIGE FEHLER ══════════════════════════════════ -->
                            <div class="card mb-4" id="fehler">
                                <div class="card-header">
                                    <h2 class="card-title"><i class="ti ti-alert-triangle me-2 text-danger" aria-hidden="true"></i>Die häufigsten Fehler – und was sie kosten</h2>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Zu spät mit der Abstinenz beginnen.</strong><br><span class="text-secondary">Kostet bis zu 15 Monate zusätzliche Wartezeit nach Ablauf der Sperrfrist.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Einen Labortermin versäumen.</strong><br><span class="text-secondary">Wird wie ein positiver Befund gewertet – das gesamte Programm beginnt von vorn.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Vor der Urinprobe viel Wasser trinken.</strong><br><span class="text-secondary">Die Probe kann dadurch unverwertbar werden und den Abstinenznachweis gefährden.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Das Gutachten direkt an die Behörde schicken lassen.</strong><br><span class="text-secondary">Bei negativem Ergebnis kein Rückzug mehr möglich – Ablehnung wird aktenkundig.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Die Beibringungsfrist verstreichen lassen.</strong><br><span class="text-secondary">Die Behörde darf dann von Ungeeignetheit ausgehen (§ 11 Abs. 8 FeV).</span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Den Antrag erst nach Sperrfristende stellen.</strong><br><span class="text-secondary">Verlängert die Wartezeit um weitere 3 Monate Bearbeitung.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Im Gespräch beschönigen oder auswendig Gelerntes aufsagen.</strong><br><span class="text-secondary">Gutachter erkennen es sofort; Widersprüche zur Akte führen zum negativen Gutachten.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Sich am Verkehrsgrenzwert für THC orientieren.</strong><br><span class="text-secondary">Im laufenden Kontrollprogramm gilt ein deutlich strengerer Wert – das Programm ist sonst verloren.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Haare färben oder blondieren.</strong><br><span class="text-secondary">Die Haaranalyse wird unbrauchbar, der abgedeckte Zeitraum fehlt.</span></p>
                                            <p><i class="ti ti-x text-danger me-1" aria-hidden="true"></i> <strong>Keine Akteneinsicht nehmen.</strong><br><span class="text-secondary">Sie kennen die Werte nicht, auf die der Gutachter sich stützt – und bereiten sich am falschen Fall vor.</span></p>
                                        </div>
                                    </div>

                                    <div class="alert alert-info mt-3 mb-0" role="alert">
                                        <div class="alert-icon"><i class="ti ti-info-circle icon alert-icon" aria-hidden="true"></i></div>
                                        <div>
                                            <div class="alert-description"><strong>Rechtlicher Hinweis:</strong> Dieser Leitfaden erklärt den typischen Ablauf und die üblichen Fristen. Er ist <strong>keine Rechtsberatung</strong> und ersetzt weder die anwaltliche Vertretung noch die individuelle verkehrspsychologische Beratung. Maßgeblich sind immer die Schreiben Ihrer Behörde und Ihres Gerichts. Stand: <?= $e(date('d.m.Y')) ?> (BK-5, 2026).</div>
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
    <script nonce="<?= $e($csp_nonce) ?>">
        document.querySelectorAll('#btn-drucken, #btn-drucken-2').forEach(function (btn) {
            btn.addEventListener('click', function () { window.print(); });
        });
    </script>
</body>
</html>
