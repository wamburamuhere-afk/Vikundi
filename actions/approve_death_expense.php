<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required
require_once __DIR__ . '/../helpers.php'; // markChildDeceasedJson()
require_once __DIR__ . '/../includes/finance.php'; // getGroupFundBalance() (audit H1)
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}
if (!canApprove('death_expenses')) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve death expenses.']); exit;
}

$id = intval($_POST['id'] ?? 0);
$user_id = $_SESSION['user_id'] ?? 0;

try {
    $pdo->beginTransaction();

    // 1. Get the expense details
    $stmt = $pdo->prepare("SELECT amount, status, member_id, deceased_type, deceased_id FROM death_expenses WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception("Gharama haikupatikana.");
    // Must be reviewed before approving
    assertApprovable($expense['status']);

    $amount = $expense['amount'];
    $member_id = $expense['member_id'];
    $deceased_type = strtolower($expense['deceased_type'] ?? '');
    $deceased_id = $expense['deceased_id'] ?? '';

    // 2. Gate on the REAL available fund, computed from records (audit H1) —
    //    not the old stale group_settings.group_balance.
    $current_balance = getGroupFundBalance($pdo);
    if ($current_balance < $amount) {
        throw new Exception("Salio la kikundi halitoshi. Salio la sasa: " . number_format($current_balance, 2));
    }
    // The fund is derived from records: approving this expense (status -> approved
    // below) reduces the computed balance automatically — no manual write needed.

    // 4. Update death expense status to 'approved' + capture signature
    $actor = workflowActorSnapshot();
    $stmt = $pdo->prepare("UPDATE death_expenses SET status = 'approved', approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$user_id, $id]);
    workflowCaptureSignature($pdo, 'death_expense', $id, 'approved',
        $user_id, $actor['name'], $actor['role']);

    // 5. REMOVE DECEASED FROM CUSTOMERS TABLE
    // The deceased is the member when deceased_id === 'member' (the stable
    // signal). Also accept the 'mwanachama' type label for older records; the
    // beneficiaries endpoint sends type='member', so keying only on the label
    // would silently miss those and never flag the member as deceased.
    if ($deceased_id === 'member' || $deceased_type === 'mwanachama') {
        // Get member email first to update users table
        $stmt = $pdo->prepare("SELECT email FROM customers WHERE customer_id = ?");
        $stmt->execute([$member_id]);
        $email = $stmt->fetchColumn();

        // Mark customer as deceased and dormant
        $stmt = $pdo->prepare("UPDATE customers SET is_active = 0, is_deceased = 1, status = 'dormant' WHERE customer_id = ?");
        $stmt->execute([$member_id]);

        if ($email) {
            // Move user to dormant so they appear in Dormant Members list
            $stmt = $pdo->prepare("UPDATE users SET status = 'dormant' WHERE email = ?");
            $stmt->execute([$email]);
        }
    } else {
        if ($deceased_id === 'spouse') {
            $pdo->prepare("UPDATE customers SET spouse_first_name = NULL, spouse_last_name = NULL WHERE customer_id = ?")->execute([$member_id]);
        } else if ($deceased_id === 'father') {
            $pdo->prepare("UPDATE customers SET father_name = NULL WHERE customer_id = ?")->execute([$member_id]);
        } else if ($deceased_id === 'mother') {
            $pdo->prepare("UPDATE customers SET mother_name = NULL WHERE customer_id = ?")->execute([$member_id]);
        } else if (strpos($deceased_id, 'child_') === 0) {
            // Mark the child as deceased instead of deleting, so the record is
            // retained and still shown (flagged) on the member's profile.
            $stmt = $pdo->prepare("SELECT children_data FROM customers WHERE customer_id = ?");
            $stmt->execute([$member_id]);
            $json = $stmt->fetchColumn();
            $idx = (int) substr($deceased_id, 6);
            $new_json = markChildDeceasedJson($json !== false ? $json : null, $idx, date('Y-m-d'));
            if ($new_json !== null && $new_json !== $json) {
                $pdo->prepare("UPDATE customers SET children_data = ? WHERE customer_id = ?")->execute([$new_json, $member_id]);
            }
        }
    }

    $pdo->commit();

    // ── Activity Log ──────────────────────────────────────────────────────────
    require_once __DIR__ . '/../includes/activity_logger.php';
    $is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
    $log_desc = $is_sw
        ? "Gharama ya msiba #$id imeidhinishwa. Kiasi: TZS " . number_format($amount, 2)
        : "Death expense #$id approved. Amount: TZS " . number_format($amount, 2);
    logActivity('Approved', 'Death Expenses', $log_desc, "DEATH#$id");
    // ─────────────────────────────────────────────────────────────────────────

    // Cash basis: approval authorises the expense; the balance drops only when
    // the treasurer marks it paid (disbursed).
    $msg = $is_sw ? 'Gharama imeidhinishwa. Salio litapungua itakapowekwa "imelipwa".' : 'Expense approved. The balance will update once it is marked paid.';
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
