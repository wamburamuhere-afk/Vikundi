<?php
/* Standard print footer HTML — includes/print_footer_html.php
   $username and $user_role are set by header.php from the DB.
   Calling files may pre-set $printed_by / $printed_role / $printed_at to override. */
$_pf_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

if (empty($printed_by)) {
    // $username is set by header.php: first_name + middle_name + last_name from DB
    if (!empty($username)) {
        $printed_by = $username;
    } else {
        // fallback: query DB directly using session user_id
        global $pdo;
        if (!empty($_SESSION['user_id']) && !empty($pdo)) {
            $_pf_stmt = $pdo->prepare("SELECT first_name, middle_name, last_name, username FROM users WHERE user_id = ?");
            $_pf_stmt->execute([$_SESSION['user_id']]);
            $_pf_u = $_pf_stmt->fetch(PDO::FETCH_ASSOC);
            $printed_by = trim(($_pf_u['first_name'] ?? '') . ' ' . ($_pf_u['middle_name'] ?? '') . ' ' . ($_pf_u['last_name'] ?? ''));
            if ($printed_by === '') $printed_by = $_pf_u['username'] ?? 'System';
        } else {
            $printed_by = 'System';
        }
    }
}
if (empty($printed_role)) {
    // $user_role is set by header.php: role_name from DB
    if (!empty($user_role)) {
        $printed_role = $user_role;
    } else {
        global $pdo;
        if (!empty($_SESSION['user_id']) && !empty($pdo)) {
            $_pf_r = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
            $_pf_r->execute([$_SESSION['user_id']]);
            $printed_role = $_pf_r->fetchColumn() ?: 'Member';
        } else {
            $printed_role = 'Member';
        }
    }
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
