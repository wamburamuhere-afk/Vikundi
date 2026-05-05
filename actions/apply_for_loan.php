<?php
// actions/apply_for_loan.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

// Ensure database connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Ombi si sahihi.']); 
    exit();
}

try {
    $customer_id   = intval($_POST['customer_id'] ?? 0);
    $amount        = floatval($_POST['amount'] ?? 0);
    $interest_rate = floatval($_POST['interest_rate'] ?? 10);
    $term_months   = intval($_POST['term_months'] ?? 3);
    $purpose       = trim((string)($_POST['purpose'] ?? 'Mkopo wa VICoBA'));
    $app_date      = $_POST['application_date'] ?? date('Y-m-d');

    if (!$customer_id || $amount <= 0 || $term_months <= 0) {
        echo json_encode(['success' => false, 'message' => 'Jaza kiasi na mwanachama sahihi.']); 
        exit();
    }

    // Check multiplier limit
    $gs = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $multiplier = floatval($gs['loan_multiplier'] ?? 3);

    // Calculate savings
    $savings_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM contributions WHERE member_id=? AND status='confirmed'");
    $savings_stmt->execute([$customer_id]);
    $total_savings = floatval($savings_stmt->fetchColumn());
    $max_loan = $total_savings * $multiplier;

    // Boundary check for first time members or strict limits
    if ($multiplier > 0 && $total_savings > 0 && $amount > $max_loan) {
        echo json_encode(['success' => false, 'message' => "Mkopo unazidi kikomo chako cha TZS " . number_format($max_loan) . " (3x Akiba yako ya TZS ".number_format($total_savings).")."]); 
        exit();
    }

    // Calculations
    $total_interest   = $amount * ($interest_rate / 100) * $term_months;
    $total_repayment  = $amount + $total_interest;
    $monthly_payment  = $total_repayment / $term_months;
    $loan_start_date  = $app_date;
    $loan_end_date    = date('Y-m-d', strtotime($app_date . " +{$term_months} months"));
    $ref_number       = 'LN-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

    $pdo->beginTransaction();

    // FIXED INSERT: Matching columns correctly
    $sql = "INSERT INTO loans (
                customer_id, amount, interest_rate, total_interest, total_repayment, balance, 
                term_length, purpose, status, reference_number, application_date, 
                loan_start_date, loan_end_date, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $customer_id,   // 1
        $amount,        // 2
        $interest_rate, // 3
        $total_interest,// 4
        $total_repayment,// 5
        $total_repayment,// 6 (balance starts with total)
        $term_months,   // 7
        $purpose,       // 8
        'Pending',      // 9
        $ref_number,    // 10
        $app_date,      // 11
        $loan_start_date,// 12
        $loan_end_date, // 13
        $_SESSION['user_id'] // 14
    ]);
    
    $loan_id = $pdo->lastInsertId();

    // REAL LOGGING: Add entry to activity_logs
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, created_at) VALUES (?, ?, 'Loans', NOW())");
    $log_stmt->execute([$_SESSION['user_id'], "Ameomba mkopo mpya: {$ref_number} wa TZS " . number_format($amount)]);

    // Optional: Repayment schedule
    $schedule_stmt = $pdo->prepare("INSERT INTO loan_repayments (loan_id, due_date, amount, cycle_type, status, created_at) VALUES (?, ?, ?, 'monthly', 'Pending', NOW())");
    for ($i = 1; $i <= $term_months; $i++) {
        $due = date('Y-m-d', strtotime($loan_start_date . " +{$i} months"));
        $schedule_stmt->execute([$loan_id, $due, round($monthly_payment, 2)]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Ombi la mkopo {$ref_number} limetumwa!", 'loan_id' => $loan_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Hitilafu: ' . $e->getMessage()]);
}
?>
