<?php
// actions/delete_loan.php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/config.php';
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Hujaingia kwenye mfumo.']);
    exit();
}

$loan_id = $_POST['loan_id'] ?? 0;

if (!$loan_id) {
    echo json_encode(['success' => false, 'message' => 'Mkopo haukutambuliwa.']);
    exit();
}

try {
    // Check if loan exists
    $stmt = $pdo->prepare("SELECT status FROM loans WHERE loan_id = ?");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch();

    if (!$loan) {
        echo json_encode(['success' => false, 'message' => 'Mkopo haupatikani.']);
        exit();
    }

    // Capture the current status and convert to lowercase for uniform check
    $status = strtolower($loan['status']);

    if ($status !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Kumbuka: Huwa tunamfuta tu mkopo ambao bado inasubiri (Pending). Mikopo yenye hali ya ' . $loan['status'] . ' haifutiki kwa ajili ya usalama wa kutaarifa.']);
        exit();
    }

    // Delete the loan
    $pdo->prepare("DELETE FROM loans WHERE loan_id = ?")->execute([$loan_id]);

    echo json_encode(['success' => true, 'message' => 'Mkopo umefutwa kwa mafanikio.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
}
?>
