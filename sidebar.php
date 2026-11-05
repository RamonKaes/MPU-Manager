<?php

declare(strict_types=1);

/**
 * sidebar.php
 * ----------------------------------------
 * Gemeinsame Navigation für den MPU-Manager.
 *
 * Erwartet die Variable $active_page:
 *   'index', 'klienten', 'aufnahme', 'akte', 'finanzen', 'ressourcen'
 *
 * Für die Akte-Seite wird automatisch der "Aktuelle Akte"-Eintrag
 * eingeblendet. Die Variable $active_page wird oben in der jeweiligen
 * Seite gesetzt, bevor diese Datei eingebunden wird.
 */
$active_page = $active_page ?? '';

// DSGVO-Löschung: Menüpunkt nur einblenden, wenn fällige Klienten existieren.
// Guarded – die Navbar darf an dieser Prüfung niemals scheitern.
$dsgvo_faellig_count = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    require_once __DIR__ . '/logic/mpu_helper.php';
    require_once __DIR__ . '/config/allowed_values.php';
    try {
        $dsgvo_faellig_count = dsgvo_faellige_anzahl($pdo);
    } catch (Throwable $e) {
        $dsgvo_faellig_count = 0;
    }
}

function nav_class(string $page, string $active_page, string $extra = ''): string
{
    $classes = 'nav-item';
    if ($extra) {
        $classes .= ' ' . $extra;
    }
    if ($page === $active_page) {
        $classes .= ' active';
    }
    return $classes;
}

function nav_aria_current(string $page, string $active_page): string
{
    return $page === $active_page ? ' aria-current="page"' : '';
}
?>
<ul class="navbar-nav">
    <li class="<?= nav_class('index', $active_page) ?>">
        <a class="nav-link" href="index.php"<?= nav_aria_current('index', $active_page) ?> title="Zur Startseite navigieren">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-home icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Startseite</span>
        </a>
    </li>
    <li class="<?= nav_class('klienten', $active_page) ?>">
        <a class="nav-link" href="klienten.php"<?= nav_aria_current('klienten', $active_page) ?> title="Gesamtübersicht aller Klienten anzeigen">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-users icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Klienten</span>
        </a>
    </li>

    <?php if ($active_page === 'akte'): ?>
    <li class="<?= nav_class('akte', $active_page) ?>">
        <span class="nav-link" aria-current="page" title="Aktuell geöffnete Klientenakte">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-folder-open icon" aria-hidden="true"></i></span>
            <span class="nav-link-title"><strong>Aktuelle Akte</strong></span>
        </span>
    </li>
    <?php endif; ?>

    <li class="<?= nav_class('finanzen', $active_page) ?>">
        <a class="nav-link" href="finanzen.php"<?= nav_aria_current('finanzen', $active_page) ?> title="Finanzen und Rechnungen verwalten">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-coin icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Finanzen</span>
        </a>
    </li>
    <li class="<?= nav_class('ressourcen', $active_page) ?>">
        <a class="nav-link" href="ressourcen.php"<?= nav_aria_current('ressourcen', $active_page) ?> title="Regionale Referenzseite: Behörden, BfF, Labore, Formulare">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-map-pin icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Ressourcen</span>
        </a>
    </li>
    <li class="<?= nav_class('anleitung', $active_page) ?>">
        <a class="nav-link" href="anleitung.php"<?= nav_aria_current('anleitung', $active_page) ?> title="Benutzerhandbuch und Dokumentation">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-help-circle icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Hilfe & Anleitung</span>
        </a>
    </li>
    <li class="<?= nav_class('leitfaden', $active_page) ?>">
        <a class="nav-link" href="klienten_leitfaden.php"<?= nav_aria_current('leitfaden', $active_page) ?> title="Klienten-Leitfaden: Von der Tat bis zur bestandenen MPU (druckbar)">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-route icon" aria-hidden="true"></i></span>
            <span class="nav-link-title">Klienten-Leitfaden</span>
        </a>
    </li>
    <?php if ($dsgvo_faellig_count > 0): ?>
    <li class="<?= nav_class('dsgvo_loeschen', $active_page) ?>">
        <a class="nav-link" href="dsgvo_loeschen.php"<?= nav_aria_current('dsgvo_loeschen', $active_page) ?> title="Klienten mit abgelaufener Aufbewahrungsfrist löschen (DSGVO Art. 17)">
            <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-trash icon text-danger" aria-hidden="true"></i></span>
            <span class="nav-link-title">DSGVO-Löschung <span class="badge bg-red text-red-fg ms-2"><?= (int)$dsgvo_faellig_count ?></span></span>
        </a>
    </li>
    <?php endif; ?>
</ul>
