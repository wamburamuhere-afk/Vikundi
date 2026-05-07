<?php
// Standard print footer — included on all printable pages.
// Requires: $username, $user_role (set globally by header.php).
$_pf_is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
?>
<style>
@media print {
    @page { margin: 1cm; }
    body { padding-bottom: 55px; }
    .std-print-footer { display: block !important; }
}
</style>
<div class="std-print-footer d-none d-print-block"
     style="position: fixed; bottom: 0; left: 0; right: 0;
            background: #fff; border-top: 1px solid #ccc;
            padding: 5px 10px; text-align: center;">
    <p style="font-size: 10px; color: #333; margin: 0 0 2px;">
        <?= $_pf_is_sw ? 'Nyaraka hii imechapishwa na' : 'This document was printed by' ?>
        <strong><?= htmlspecialchars($username ?? 'User') ?></strong> -
        <strong><?= htmlspecialchars($user_role ?? 'Member') ?></strong>
        <?= $_pf_is_sw ? 'mnamo' : 'on' ?>
        <strong><?= date('d m, Y') ?></strong>
        <?= $_pf_is_sw ? 'saa' : 'at' ?>
        <strong><?= date('H:i:s') ?></strong>
    </p>
    <p style="font-size: 10px; color: #0d6efd; font-weight: bold; margin: 0;">
        Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved
    </p>
</div>
