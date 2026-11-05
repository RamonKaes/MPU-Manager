<?php
/**
 * partials/_head.php
 * ─────────────────────────────────────────────────────────────────
 * Gemeinsamer <head>-Block für alle Seiten des MPU-Managers.
 *
 * Erwartet (vor dem require setzen):
 *   string $page_title    – vollständiger <title>-Inhalt
 *   string $page_css_file – (optional) Pfad zu einer seitenspezifischen CSS-Datei
 *
 * Gibt aus: DOCTYPE → <html> → <head> → </head> → <body> → Skip-Link → theme-init
 */
declare(strict_types=1);
$page_title    = $page_title    ?? 'MPU-Manager';
$page_css_file = $page_css_file ?? '';
?>
<!DOCTYPE html>
<html lang="de">
<script nonce="<?= htmlspecialchars($csp_nonce) ?>">
// Theme-Init: Muss synchron VOR CSS geladen werden, um FOUC zu vermeiden
(function(){var A=['light','dark'],p=new URLSearchParams(window.location.search).get('theme'),s=localStorage.getItem('tablerTheme');var t=(A.indexOf(p)!==-1?p:null)||(A.indexOf(s)!==-1?s:null)||'light';if(p!==null&&A.indexOf(p)!==-1){localStorage.setItem('tablerTheme',p);}document.documentElement.setAttribute('data-bs-theme',t);})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>

    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <link rel="icon" type="image/png" sizes="96x96" href="assets/img/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/img/favicon-192x192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="assets/img/favicon-512x512.png">
    <link rel="icon" href="favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
    <link rel="preload" href="assets/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/fonts/inter.css">
    <link rel="stylesheet" href="assets/css/tabler.min.css">
    <link rel="stylesheet" href="assets/css/tabler-icons.min.css">
<?php if ($page_css_file !== ''): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($page_css_file) ?>">
<?php endif; ?>

    <style>
        :root { --tblr-font-sans-serif: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; }
        body  { font-feature-settings: "cv03", "cv04", "cv11"; }
        /* Logo-Umschaltung Hell-/Dunkel-Modus: passendes SVG statt CSS-Invertierung */
        .navbar-brand .logo-dark { display: none; }
        [data-bs-theme="dark"] .navbar-brand .logo-light { display: none; }
        [data-bs-theme="dark"] .navbar-brand .logo-dark  { display: inline; }
        /* Font-Icons in nav-link-icon wie SVG-Icons behandeln: kein line-height-Überhang */
        .nav-link-icon .ti { display: block; line-height: 1; }
    </style>
</head>
<body>
    <a href="#main-content" class="visually-hidden-focusable skip-link">Zum Hauptinhalt springen</a>
