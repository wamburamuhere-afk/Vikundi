<?php
/* Standard print footer HTML — includes/print_footer_html.php
   Include once just before </body> in every standalone print page,
   or anywhere after content in system-header pages.
   Calling files may pre-set $printed_by / $printed_role / $printed_at;
   if not set, sensible defaults are derived from the session. */
$_pf_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

if (empty($printed_by)) {
    $printed_by = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if ($printed_by === '') $printed_by = $_SESSION['username'] ?? 'System';
}
if (empty($printed_role)) {
    $printed_role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User';
}
if (empty($printed_at)) {
    $printed_at = date('d M, Y') . ' ' . ($_pf_sw ? 'saa' : 'at') . ' ' . date('H:i:s');
}
?>
<div class="footer-spacer"></div>
<div class="print-footer">
    <p>
        <?= $_pf_sw ? 'Nyaraka hii imechapishwa na' : 'This document was Printed by' ?>
        <strong><?= htmlspecialchars($printed_by) ?></strong> &mdash;
        <strong><?= htmlspecialchars(ucfirst($printed_role)) ?></strong>
        <?= $_pf_sw ? 'mnamo' : 'on' ?>
        <?= htmlspecialchars($printed_at) ?>
    </p>
    <p class="brand">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
</div>
