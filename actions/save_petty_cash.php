<?php
// actions/save_petty_cash.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/activity_logger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Inputs
$voucher_id       = $_POST['voucher_id'] ?? 0; // Present for editing
$transaction_date = $_POST['transaction_date'] ?? date('Y-m-d');
$amount           = $_POST['amount'] ?? 0;
$payee_name       = $_POST['payee_name'] ?? '';
$category         = $_POST['category'] ?? 'Other';
$other_category   = $_POST['other_category_text'] ?? '';
$description      = $_POST['description'] ?? '';

// If category is "Other", use the specific text provided by user
if ($category === 'Other' && !empty($other_category)) {
    $category = $other_category;
}

if (empty($amount) || empty($payee_name) || empty($description)) {
    echo json_encode(['success' => false, 'message' => $isSwahili ? 'Tafadhali jaza sehemu zote muhimu' : 'Please fill all required fields']);
    exit();
}

try {
    if ($voucher_id > 0) {
        // UPDATE Existing Voucher
        // Only allow edit if status is pending
        $stmt_check = $pdo->prepare("SELECT status, voucher_no FROM petty_cash_vouchers WHERE id = ?");
        $stmt_check->execute([$voucher_id]);
        $existing = $stmt_check->fetch();
        
        if (!$existing || $existing['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => $isSwahili ? 'Huwei kuhariri vocha iliyokwisha idhinishwa' : 'Cannot edit an approved/processed voucher']);
            exit();
        }
        
        $stmt = $pdo->prepare("UPDATE petty_cash_vouchers SET transaction_date = ?, payee_name = ?, amount = ?, category = ?, description = ? WHERE id = ?");
        $success = $stmt->execute([$transaction_date, $payee_name, $amount, $category, $description, $voucher_id]);
        $msg = $isSwahili ? "Vocha " . $existing['voucher_no'] . " imerekebishwa" : "Voucher " . $existing['voucher_no'] . " has been updated";
        $logAction = "Updated";
        $voucher_no = $existing['voucher_no'];
    } else {
        // CREATE New Voucher
        $voucher_no = 'PCV-' . date('ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("INSERT INTO petty_cash_vouchers (voucher_no, transaction_date, payee_name, amount, category, description, prepared_by, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $success = $stmt->execute([
            $voucher_no,
            $transaction_date,
            $payee_name,
            $amount,
            $category,
            $description,
            $_SESSION['user_id']
        ]);
        $msg = $isSwahili ? "Vocha $voucher_no imehifadhiwa" : "Voucher $voucher_no has been saved";
        $logAction = "Created";
    }

    if ($success) {
        $logDesc = $isSwahili ? "Akitenda: $logAction vocha ya petty cash $voucher_no kwa ajili ya $payee_name" : "$logAction petty cash voucher $voucher_no for $payee_name";
        logActivity($logAction, "Petty Cash", $logDesc, $voucher_no);
        echo json_encode(['success' => true, 'message' => $msg]);
    } else {
        echo json_encode(['success' => false, 'message' => $isSwahili ? 'Imefeli kuhifadhi' : 'Failed to save']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
