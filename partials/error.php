<!DOCTYPE html>
<html lang="de">
<script nonce="<?= $csp_nonce ?? '' ?>">
// Theme-Init: Muss synchron VOR CSS geladen werden, um FOUC zu vermeiden
(function(){var A=['light','dark'],p=new URLSearchParams(window.location.search).get('theme'),s=localStorage.getItem('tablerTheme');var t=(A.indexOf(p)!==-1?p:null)||(A.indexOf(s)!==-1?s:null)||'light';if(p!==null&&A.indexOf(p)!==-1){localStorage.setItem('tablerTheme',p);}document.documentElement.setAttribute('data-bs-theme',t);})();
</script>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fehler – MPU-Manager</title>
    <link rel="stylesheet" href="assets/css/tabler.min.css">
    <link rel="stylesheet" href="assets/css/tabler-icons.min.css">
    <style>
        :root { --tblr-font-sans-serif: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif; }
        body  { font-family: var(--tblr-font-sans-serif); }
    </style>
</head>
<body class="antialiased">
<div class="page page-center">
    <div class="container-tight py-4">
        <div class="empty">
            <div class="empty-img">
                <svg xmlns="http://www.w3.org/2000/svg" width="128" height="128"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1" stroke-linecap="round" stroke-linejoin="round"
                     class="text-danger icon icon-tabler icon-tabler-alert-circle">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0"/>
                    <path d="M12 8v4"/>
                    <path d="M12 16v.01"/>
                </svg>
            </div>
            <p class="empty-title"><?= htmlspecialchars($_error_title ?? 'Ein Fehler ist aufgetreten') ?></p>
            <p class="empty-subtitle text-secondary">
                <?= htmlspecialchars($_error_message ?? 'Bitte laden Sie die Seite neu. Wenn das Problem bestehen bleibt, wenden Sie sich an die Administration.') ?>
            </p>
            <div class="empty-action">
                <a href="index.php" class="btn btn-primary">
                    Zum Dashboard
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
