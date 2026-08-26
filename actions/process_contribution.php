<?php
// actions/process_contribution.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/member_savings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401); // audit: refusal must not return HTTP 200
        echo json_encode(['success' => false, 'message' => 'Unauthorized submission.']);
        exit();
    }

    $member_id = intval($_POST['member_id'] ?? 0);
    // Leadership may record a contribution for any member; anyone else may only
    // submit their OWN, so a member cannot file contributions against others.
    if (!canCreate('manage_contributions')) {
        $member_id = (int) (vk_member_customer_id($pdo, (int) $_SESSION['user_id']) ?? 0);
    }
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
    //
    // This used to build the stored filename from the CLIENT's extension:
    //
    //     $file_ext = pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION);
    //     $file_name = 'receipt_' . time() . '_' . uniqid() . '.' . $file_ext;
    //
    // with no whitelist and no content check, into a directory the web server
    // serves. A file named receipt.php landed as receipt.php. The uploads
    // directory carries an .htaccess guard, but that guard is one AllowOverride
    // away from being ignored and must not be the only thing between an upload
    // and code execution.
    //
    // vk_api_store_upload() takes the extension from the whitelist key, sniffs
    // the bytes with finfo, and caps the size — the same helper the mobile API
    // uses, so the two doors cannot drift apart.
    $evidence_path = null;
    if (isset($_FILES['evidence']) && (int) ($_FILES['evidence']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/../includes/api_upload.php';
        [$stored, $upload_error] = vk_api_store_upload(
            $_FILES['evidence'],
            __DIR__ . '/../uploads/contributions',
            'receipt'
        );
        if ($upload_error !== null) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $upload_error]);
            exit();
        }
        $evidence_path = 'uploads/contributions/' . $stored;
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
