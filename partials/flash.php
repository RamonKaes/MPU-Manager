<?php
// partials/flash.php – Flash-Meldung anzeigen (einmalig, Session-basiert)
// Einbinden direkt nach dem öffnenden <div class="container-xl"> o.ä.
// Gibt nichts aus, wenn keine Meldung in der Session liegt.
$_flash = get_flash();
if ($_flash === null) {
    return;
}
$_flash_type = in_array($_flash['type'], ['success', 'warning', 'danger'], true)
    ? $_flash['type']
    : 'success';
$_flash_icons = [
    'success' => 'ti-circle-check',
    'warning' => 'ti-alert-circle',
    'danger'  => 'ti-alert-triangle',
];
$_flash_icon = $_flash_icons[$_flash_type];
?>
<div id="mpu-flash"
     class="alert alert-<?= $_flash_type ?> alert-dismissible mb-3"
     role="alert">
    <div class="alert-icon"><i class="ti <?= $_flash_icon ?> icon alert-icon" aria-hidden="true"></i></div>
    <div><?= htmlspecialchars($_flash['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <button type="button"
            class="btn-close"
            data-bs-dismiss="alert"
            aria-label="Schließen"></button>
</div>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
(function () {
    var el = document.getElementById('mpu-flash');
    if (el) {
        setTimeout(function () {
            el.classList.add('fade');
            el.style.transition = 'opacity .4s';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 400);
        }, 4000);
    }
}());
</script>
<?php unset($_flash, $_flash_type, $_flash_icon, $_flash_icons); ?>
