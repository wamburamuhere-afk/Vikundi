<?php
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;

try {
    $pdo->beginTransaction();

    // 1. Get the expense details
    $stmt = $pdo->prepare("SELECT amount, status, member_id, deceased_type, deceased_id FROM death_expenses WHERE id = ?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$expense) throw new Exception("Gharama haikupatikana.");
    if ($expense['status'] === 'approved') throw new Exception("Gharama hii imeshashidhinishwa tayari.");

    $amount = $expense['amount'];
    $member_id = $expense['member_id'];
    $deceased_type = strtolower($expense['deceased_type'] ?? '');
    $deceased_id = $expense['deceased_id'] ?? '';

    // 2. Fetch current group balance from settings
    $stmt = $pdo->query("SELECT setting_value FROM group_settings WHERE setting_key = 'group_balance'");
    $current_balance = (float)$stmt->fetchColumn();

    if ($current_balance < $amount) {
        throw new Exception("Salio la kikundi halitoshi. Salio la sasa: " . number_format($current_balance, 2));
    }

    // 3. Update group balance
    $new_balance = $current_balance - $amount;
    $stmt = $pdo->prepare("UPDATE group_settings SET setting_value = ? WHERE setting_key = 'group_balance'");
    $stmt->execute([$new_balance]);

    // 4. Update death expense status to 'approved'
    $final_status = 'approved';
    $stmt = $pdo->prepare("UPDATE death_expenses SET status = ?, approved_by = ?, approved_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$final_status, $user_id, $id]);

    // 5. REMOVE DECEASED FROM CUSTOMERS TABLE
    if ($deceased_type === 'mwanachama') {
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
            $stmt = $pdo->prepare("SELECT children_data FROM customers WHERE customer_id = ?");
            $stmt->execute([$member_id]);
            $json = $stmt->fetchColumn();
            if ($json) {
                $children = json_decode($json, true);
                $idx = (int)substr($deceased_id, 6);
                if (isset($children[$idx])) {
                    unset($children[$idx]);
                    $new_json = json_encode(array_values($children)); // re-index
                    $pdo->prepare("UPDATE customers SET children_data = ? WHERE customer_id = ?")->execute([$new_json, $member_id]);
                }
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

    $msg = $is_sw ? 'Gharama imeidhinishwa na salio la kikundi limepunguzwa.' : 'Expense approved and group balance updated.';
    echo json_encode(['success' => true, 'message' => $msg]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
