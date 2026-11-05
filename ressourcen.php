<?php
declare(strict_types=1);

require_once 'db.php';

$page_title = 'MPU-Manager | Ressourcen';
require 'partials/_head.php';
$active_page = 'ressourcen';
require 'partials/_navbar.php';
?>
                <div class="page-header d-print-none">
                    <div class="container-xl">
                        <div class="row g-2 align-items-center">
                            <div class="col">
                                <h1 class="page-title">
                                    <i class="ti ti-map-pin me-2 text-primary" aria-hidden="true"></i>
                                    Regionale Ressourcen
                                </h1>
                                <p class="text-secondary mt-1 mb-0">Regionale Referenzseite — Behörden, Begutachtungsstellen, Labore und Formulare für Düsseldorf / Kreis Mettmann</p>
                            </div>
                        </div>
                    </div>
                </div>

            <main class="page-body" id="main-content">
                <div class="container-xl">

                    <!-- ══ Block 1: Fahrerlaubnisbehörden ══════════════════════════════════ -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-building-community me-2" aria-hidden="true"></i>
                                Fahrerlaubnisbehörden
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-vcenter table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Behörde</th>
                                            <th>Adresse</th>
                                            <th>Kontakt</th>
                                            <th>Besonderheiten</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold">Straßenverkehrsamt Düsseldorf<br><span class="text-secondary small">(Abt. 33/3)</span></td>
                                            <td>Höherweg 101<br>40233 Düsseldorf</td>
                                            <td>
                                                Tel: 0211 89-91<br>
                                                <a href="mailto:neuerteilung@duesseldorf.de">neuerteilung@duesseldorf.de</a>
                                            </td>
                                            <td class="small">
                                                <span class="badge bg-warning-lt me-1">Termin Pflicht</span>
                                                Online-Termin zwingend; nur bargeldlose Zahlung;<br>Bearbeitungszeit 8–12 Wochen
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Straßenverkehrsamt Mettmann<br><span class="text-secondary small">(Führerscheinstelle)</span></td>
                                            <td>Düsseldorfer Str. 26<br>40822 Mettmann</td>
                                            <td>
                                                Tel: 02104 99-1760<br>
                                                <a href="mailto:sva.fuehrerschein@kreis-mettmann.de">sva.fuehrerschein@kreis-mettmann.de</a>
                                            </td>
                                            <td class="small">
                                                Dezentrale Abgabe in Bürgerbüros möglich<br>
                                                <span class="text-danger">(außer Ratingen und Velbert)</span>;<br>
                                                Online-Dienste ab 13.02.2026 zeitweise eingeschränkt
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ══ Block 2: BfF-Begutachtungsstellen ════════════════════════════════ -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-license me-2" aria-hidden="true"></i>
                                Amtlich anerkannte Begutachtungsstellen (BfF) — Düsseldorf
                            </h3>
                            <div class="card-subtitle text-secondary small">Kreis Mettmann hat keine eigenen BfF-Standorte</div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-vcenter table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Begutachtungsstelle</th>
                                            <th>Adresse</th>
                                            <th>Telefon</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold">TÜV NORD Mobilität</td>
                                            <td>Graf-Adolf-Str. 59, 40210 Düsseldorf</td>
                                            <td>0211 9365670</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">pima-mpu GmbH</td>
                                            <td>Konrad-Adenauer-Platz 12, 40210 Düsseldorf</td>
                                            <td>0211 9365437</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">AVUS GmbH</td>
                                            <td>Friedrich-Ebert-Str. 32, 40210 Düsseldorf</td>
                                            <td>0211 17930280</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">1A Begutachtungsstelle</td>
                                            <td>Oststr. 84, 40210 Düsseldorf</td>
                                            <td>0211 92323064</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">ABV GmbH</td>
                                            <td>Charlottenstr. 49, 40210 Düsseldorf</td>
                                            <td>0211 83022074</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- ══ Block 3: Labore CTU-Screenings ══════════════════════════════════ -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="ti ti-flask me-2" aria-hidden="true"></i>
                                Akkreditierte Labore für CTU-Screenings
                            </h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-vcenter table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Labor</th>
                                            <th>Adresse</th>
                                            <th>Telefon</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="fw-bold">MLD Medizinische Laboratorien Düsseldorf</td>
                                            <td>Nordstr. 44, 40477 Düsseldorf</td>
                                            <td>0211 49780</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">UKD Institut für Rechtsmedizin</td>
                                            <td>Moorenstr. 5, 40225 Düsseldorf</td>
                                            <td>0211 8104555</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">TOLAB</td>
                                            <td>Hüttenstr. 31a, 40215 Düsseldorf</td>
                                            <td>0211 377384</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-bold">Invitalab</td>
                                            <td>Stresemannallee 4b, 41460 Neuss</td>
                                            <td>02131 1259690</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">

                        <!-- ══ Block 4: Beratungsstellen / Suchthilfe ════════════════════════ -->
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="ti ti-heart-handshake me-2" aria-hidden="true"></i>
                                        Regionale Beratungsstellen / Suchthilfe
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-3">
                                            <strong>Diakonie Suchthilfe BIZ</strong><br>
                                            <span class="text-secondary">Steinhof 3a, 40699 Erkrath</span><br>
                                            Tel: 0211 300360214
                                        </li>
                                        <li>
                                            <strong>Psychotherapeutische Praxen</strong><br>
                                            <span class="text-secondary">Suche über die Arztsuche der Kassenärztlichen Vereinigung oder die Psychotherapeutenkammer des Landes.</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- ══ Block 5: Formulare & Downloads ═══════════════════════════════ -->
                        <div class="col-lg-6">
                            <div class="card h-100">
                                <div class="card-header">
                                    <h3 class="card-title">
                                        <i class="ti ti-download me-2" aria-hidden="true"></i>
                                        Wichtige Formulare &amp; Downloads
                                    </h3>
                                </div>
                                <div class="card-body">
                                    <h4 class="small fw-bold text-uppercase text-secondary mb-2">Landeshauptstadt Düsseldorf</h4>
                                    <ul class="list-unstyled mb-3">
                                        <li class="mb-1">
                                            <a href="https://service.duesseldorf.de/suche/-/egov-bis-detail/dienstleistung/283/show" target="_blank" rel="noopener">
                                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>Informations- und Antragsseite
                                            </a>
                                        </li>
                                        <li class="mb-1">
                                            <a href="https://fahrschule-black.de/wp-content/uploads/2025/10/Fahrerlaubnisantrag-Stadt-Duesseldorf.pdf" target="_blank" rel="noopener">
                                                <i class="ti ti-file-type-pdf me-1 text-danger" aria-hidden="true"></i>Antragsformular PDF (Vorab-Download)
                                            </a>
                                        </li>
                                        <li>
                                            <a href="https://www.duesseldorf.de/kfz/fuehrerschein/dienste/merkblatt-neuerteilung/" target="_blank" rel="noopener">
                                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>Merkblatt zur Neuerteilung
                                            </a>
                                        </li>
                                    </ul>

                                    <h4 class="small fw-bold text-uppercase text-secondary mb-2">Kreis Mettmann</h4>
                                    <ul class="list-unstyled mb-3">
                                        <li class="mb-1">
                                            <a href="https://www.kreis-mettmann.de/Kreisverwaltung/Dienstleistungen/Entzogener-F%C3%BChrerschein.php" target="_blank" rel="noopener">
                                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>„Entzogener Führerschein"
                                            </a>
                                        </li>
                                        <li class="mb-1">
                                            <a href="https://www.kreis-mettmann.de/loadDocument.phtml?FID=2023.2351.1&Ext=PDF" target="_blank" rel="noopener">
                                                <i class="ti ti-file-type-pdf me-1 text-danger" aria-hidden="true"></i>Ärztliche Bescheinigung Anlage 5.1 FeV
                                            </a>
                                        </li>
                                        <li class="mb-1">
                                            <a href="https://www.kreis-mettmann.de/loadDocument.phtml?FID=2023.2201.1&Ext=PDF" target="_blank" rel="noopener">
                                                <i class="ti ti-file-type-pdf me-1 text-danger" aria-hidden="true"></i>Gesundheitsfragebogen
                                            </a>
                                        </li>
                                        <li>
                                            <a href="https://www5.kreis-mettmann.de/sva-formulare/fs-umtausch/" target="_blank" rel="noopener">
                                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>Online-Antrag Klasse B
                                                <span class="badge bg-warning-lt ms-1">ab 13.02.2026 zeitw. außer Betrieb</span>
                                            </a>
                                        </li>
                                    </ul>

                                    <h4 class="small fw-bold text-uppercase text-secondary mb-2">Allgemein</h4>
                                    <ul class="list-unstyled mb-0">
                                        <li>
                                            <a href="https://www.kba.de/DE/Themen/ZentraleRegister/ZFER/Auskunft/auskunft_akkordeon.html" target="_blank" rel="noopener">
                                                <i class="ti ti-external-link me-1" aria-hidden="true"></i>FAER-Auskunft KBA
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                    </div><!-- /.row -->

                </div><!-- /.container-xl -->
            </main>

            <?php require 'partials/_footer.php'; ?>

        </div><!-- /.page-wrapper -->
    </div><!-- /.page -->

    <script src="assets/js/tabler.min.js"></script>
</body>
</html>
