<?php
/**
 * partials/_footer.php
 * ─────────────────────────────────────────────────────────────────
 * Gemeinsame Copyright-Fußzeile für alle Seiten des MPU-Managers.
 *
 * Muss innerhalb von <div class="page-wrapper"> eingebunden werden,
 * direkt nach dem schließenden </main>.
 */
declare(strict_types=1);
?>
            <footer class="footer footer-transparent d-print-none">
                <div class="container-xl">
                    <div class="row text-center align-items-center flex-row-reverse">
                        <div class="col-12 col-lg-auto mt-3 mt-lg-0">
                            <ul class="list-inline list-inline-dots mb-0 text-secondary">
                                <li class="list-inline-item">MPU-Manager &copy; <?= date('Y') ?></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </footer>
            <?php if (isset($csp_nonce)): ?>
            <div id="sitzung-warnung" class="alert alert-warning position-fixed bottom-0 end-0 m-3 d-print-none" role="alert" aria-live="assertive" hidden>
                <div>
                    <strong>Die Sitzung läuft in 5 Minuten ab.</strong> Nicht gespeicherte Eingaben gingen dann verloren.
                    <button type="button" class="btn btn-sm btn-warning ms-2" id="sitzung-verlaengern">Sitzung verlängern</button>
                </div>
            </div>
            <script nonce="<?= htmlspecialchars((string)$csp_nonce, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') ?>">
                (function () {
                    const warnung = document.getElementById('sitzung-warnung');
                    const frist = (<?= (int)SESSION_IDLE_TIMEOUT ?> - 300) * 1000;
                    let uhr = setTimeout(() => { warnung.hidden = false; }, frist);
                    document.getElementById('sitzung-verlaengern').addEventListener('click', () => {
                        // Jeder angemeldete Aufruf setzt die Frist zurück.
                        fetch('triage_ajax.php?action=config', { credentials: 'same-origin' }).then(r => {
                            if (!r.ok) { location.reload(); return; }
                            warnung.hidden = true;
                            clearTimeout(uhr);
                            uhr = setTimeout(() => { warnung.hidden = false; }, frist);
                        });
                    });
                })();
            </script>
            <?php endif; ?>
