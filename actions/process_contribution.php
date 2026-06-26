<?php
// actions/process_contribution.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized submission.']);
        exit();
    }
    
    $member_id = intval($_POST['member_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $description = $_POST['description'] ?? '';

    // Transactions form fields (validated against the allowed sets).
    $allowed_types    = ['entrance', 'monthly', 'agm', 'fine', 'other'];
    $allowed_accounts = ['M-Koba', 'Bank', 'Cash', 'Mobile Money'];

    $contribution_type = in_array($_POST['contribution_type'] ?? '', $allowed_types, true)
        ? $_POST['contribution_type'] : 'monthly';

    $account = in_array($_POST['account'] ?? '', $allowed_accounts, true) ? $_POST['account'] : null;

    $receipt_number = trim($_POST['receipt_number'] ?? '');
    $receipt_number = $receipt_number !== '' ? $receipt_number : null;

    // Date is editable but defaults to today and must be a valid Y-m-d.
    $contribution_date = date('Y-m-d');
    $posted_date = trim($_POST['contribution_date'] ?? '');
    $d = $posted_date !== '' ? \DateTime::createFromFormat('Y-m-d', $posted_date) : false;
    if ($d && $d->format('Y-m-d') === $posted_date) {
        $contribution_date = $posted_date;
    }

    $status = 'pending'; // USER REQUEST: Every created contribution must be approved.

    if (!$member_id || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
        exit();
    }

    // Handle Receipt Upload (Evidence)
    $evidence_path = null;
    if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === 0) {
        $upload_dir = __DIR__ . '/../uploads/contributions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
        $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
        $target_file = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['evidence']['tmp_name'], $target_file)) {
            $evidence_path = 'uploads/contributions/' . $file_name;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO contributions (
                member_id, amount, contribution_type, contribution_date, description, status,
                receipt_number, account, evidence_path, created_by, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $member_id, $amount, $contribution_type, $contribution_date, $description, $status,
            $receipt_number, $account, $evidence_path, $_SESSION['user_id']
        ]);
        $new_id = $pdo->lastInsertId();

        // ── Activity Log ──────────────────────────────────────────────────────
        require_once __DIR__ . '/../includes/activity_logger.php';
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "Mchango mpya wa TZS " . number_format($amount, 2) . " umewasilishwa (Inasubiri idhini)"
            : "New contribution of TZS " . number_format($amount, 2) . " submitted (Pending approval)";
        logCreate('Contributions', number_format($amount, 2), "CONTRIB#$new_id");
        // ─────────────────────────────────────────────────────────────────────

        echo json_encode(['success' => true, 'message' => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchango umetumwa na unangojea uhakiki (Approval).' : 'Contribution submitted and awaiting approval.')]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    }
    exit();
}
?>
