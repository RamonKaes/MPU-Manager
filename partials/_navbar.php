<?php
/**
 * partials/_navbar.php
 * ─────────────────────────────────────────────────────────────────
 * Gemeinsame Top-Navbar + Sidebar-Navigation für alle Hauptseiten.
 *
 * Erwartet (vor dem require setzen):
 *   string $active_page  – Schlüssel für die aktive Nav-Position
 *                          ('index', 'klienten', 'aufnahme', 'akte', 'finanzen', 'ressourcen')
 *
 * Struktur (offizielles Tabler-Condensed-Layout):
 *   <div class="page">
 *     <header class="navbar navbar-expand-md">…</header>   (Top-Navbar)
 *     <header class="navbar-expand-md">…</header>          (Menü-Navbar)
 *     <div class="page-wrapper">                           (bleibt in jeder Seite offen)
 * page-wrapper und page werden durch das abschließende </div></div>
 * vor </body> geschlossen.
 */
declare(strict_types=1);
$active_page  = $active_page ?? '';

$auth_user     = $_SESSION['display_name'] ?? 'Benutzer';
$auth_user_esc = htmlspecialchars($auth_user, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// Initialen: erste Buchstaben der ersten zwei Wörter (max. 2 Zeichen)
$_words    = preg_split('/[\s._-]+/', $auth_user, -1, PREG_SPLIT_NO_EMPTY);
$_initials = '';
foreach (array_slice($_words, 0, 2) as $_w) {
    $_initials .= mb_strtoupper(mb_substr($_w, 0, 1, 'UTF-8'), 'UTF-8');
}
$initials_esc = htmlspecialchars($_initials !== '' ? $_initials : 'U', ENT_QUOTES | ENT_HTML5, 'UTF-8');
unset($_words, $_w, $_initials);

// Theme-Switcher-Links: bestehende Query-Parameter (z. B. id, case_id) erhalten,
// sonst würde ein blankes "?theme=dark" die Seitenkontext-Parameter verwerfen.
$_theme_params = $_GET;
unset($_theme_params['theme']);
$_theme_url_dark  = '?' . htmlspecialchars(http_build_query(array_merge($_theme_params, ['theme' => 'dark'])),  ENT_QUOTES | ENT_HTML5, 'UTF-8');
$_theme_url_light = '?' . htmlspecialchars(http_build_query(array_merge($_theme_params, ['theme' => 'light'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
unset($_theme_params);
?>
    <div class="page">
        <header class="navbar navbar-expand-md d-print-none">
            <div class="container-xl">
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu"
                        aria-controls="navbar-menu" aria-expanded="false" aria-label="Navigation umschalten">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <a href="index.php" class="navbar-brand pe-0 pe-md-3">
                    <img src="assets/img/logo.svg" alt="MPU-Logo" width="32" height="32" class="logo-light">
                    <img src="assets/img/logo-dark.svg" alt="MPU-Logo" width="32" height="32" class="logo-dark">
                    <span class="ms-2">MPU-Manager</span>
                </a>
                <div class="navbar-nav flex-row order-md-last">
                    <div class="d-none d-md-flex">
                        <a href="<?= $_theme_url_dark ?>"  class="nav-link px-0 hide-theme-dark"
                           title="Dunklen Modus aktivieren" data-bs-toggle="tooltip" data-bs-placement="bottom">
                            <i class="ti ti-moon icon" aria-hidden="true"></i>
                        </a>
                        <a href="<?= $_theme_url_light ?>" class="nav-link px-0 hide-theme-light"
                           title="Hellen Modus aktivieren" data-bs-toggle="tooltip" data-bs-placement="bottom">
                            <i class="ti ti-sun icon" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="nav-item dropdown ms-3">
                        <a href="#" class="nav-link d-flex lh-1 text-reset p-0"
                           data-bs-toggle="dropdown" aria-label="Benutzermenü öffnen">
                            <span class="avatar avatar-sm bg-primary-lt"><?= $initials_esc ?></span>
                            <div class="d-none d-xl-block ps-2">
                                <div><?= $auth_user_esc ?></div>
                                <div class="mt-1 small text-secondary">Berater</div>
                            </div>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                            <a href="logout.php" class="dropdown-item">Abmelden</a>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <header class="navbar-expand-md d-print-none">
            <div class="collapse navbar-collapse" id="navbar-menu">
                <div class="navbar">
                    <div class="container-xl">
                        <?php require __DIR__ . '/../sidebar.php'; ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="page-wrapper">
