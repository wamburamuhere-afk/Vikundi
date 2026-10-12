<?php
/**
 * database/add_loan_permissions.php
 * ---------------------------------
 * Register the two permission page-keys the loans module gates on, and grant
 * them to leadership:
 *
 *   approve_loan   approve / reject a loan, and see every member's loans
 *   disburse_loan  pay a loan out, and record repayments
 *
 * THE KEYS WERE NEVER IN A FRESH DATABASE. The loans module (includes/loans.php)
 * was built and verified against a database that still carried them from the
 * inherited BMS data, so it worked there. A clean install has neither row, and
 * without them only an admin-role user (the Chairperson) passes the checks —
 * the Secretary and the Treasurer cannot open the Loans list, approve, pay out
 * or record a repayment. Found by installing into an empty database before the
 * first new-group deployment.
 *
 * Members are not granted either key (both are in vk_member_hidden_keys()); a
 * member reaches their own loans through My Loans, which needs no page-key.
 *
 * Existing rows and grants are never overwritten. Idempotent and safe to
 * re-run. Registered in database/migrate.php BEFORE seed_vicoba_roles.php.
 *
 * Run manually:  php database/add_loan_permissions.php
 */

require_once __DIR__ . '/../includes/config.php';

$keys = [
    'approve_loan'  => ['Approve Loans', 'Approve or reject loan applications and view all loans'],
    'disburse_loan' => ['Disburse Loans', 'Pay out approved loans and record repayments'],
];

$roleNames = ['admin', 'administrator', 'chairperson', 'mwenyekiti', 'chairman',
              'secretary', 'sekretari', 'katibu', 'treasurer', 'mweka hazina'];
$in = implode(',', array_fill(0, count($roleNames), '?'));
$roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ($in)");
$roleStmt->execute($roleNames);
$roleIds = $roleStmt->fetchAll(PDO::FETCH_COLUMN);

$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$add   = $pdo->prepare('INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?, ?, ?, ?, ?)');
$has   = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?');
$grant = $pdo->prepare(
    'INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
     VALUES (?, ?, 1, 1, 1, 1, 1, 1)'
);

foreach ($keys as $key => [$name, $description]) {
    $check->execute([$key]);
    $permId = (int) ($check->fetchColumn() ?: 0);
    if ($permId === 0) {
        $add->execute(['', $key, $name, $description, 'Loans']);
        $permId = (int) $pdo->lastInsertId();
        echo "  Added '$key' permission.\n";
    } else {
        echo "  '$key' permission already present.\n";
    }

    $added = 0;
    foreach ($roleIds as $rid) {
        $has->execute([$rid, $permId]);
        if ((int) $has->fetchColumn() === 0) {
            $grant->execute([$rid, $permId]);
            $added++;
        }
    }
    echo "  Granted '$key' to $added leadership role(s).\n";
}
