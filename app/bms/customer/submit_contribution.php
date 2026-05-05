<?php
// app/bms/customer/submit_contribution.php
ob_start();
require_once 'header.php';

// Ensure user is logged in and has a recognizable role
if (!str_contains($user_role_lower, 'member') && 
    !str_contains($user_role_lower, 'mwanachama') && 
    !str_contains($user_role_lower, 'mjumbe') && 
    !in_array($user_role_lower, ['admin', 'chairman', 'mwenyekiti'])) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

// Get customer info
$stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$customer_id = $stmt->fetchColumn();

if (!$customer_id) {
    die("Customer record not found.");
}

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
?>


    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-send-plus"></i> <?= $is_sw ? 'Ripoti Mchango Mpya' : 'Submit Contribution' ?></h5>
                </div>
                <div class="card-body p-4">
                    <form id="contributionForm" action="actions/process_contribution.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="member_id" value="<?= $customer_id ?>">
                        
                        <div class="mb-3">
                            <label for="amount" class="form-label fw-bold"><?= $is_sw ? 'Kiasi cha Fedha' : 'Amount TSh' ?> <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">TSh</span>
                                <input type="number" class="form-control form-control-lg fw-bold" id="amount" name="amount" required placeholder="<?= $is_sw ? 'Mfano: 50,000' : 'e.g. 50,000' ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="contribution_date" class="form-label fw-bold"><?= $is_sw ? 'Tarehe ya Malipo' : 'Payment Date' ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="contribution_date" name="contribution_date" required value="<?= date('Y-m-d') ?>">
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label fw-bold"><?= $is_sw ? 'Maelezo / Madhumuni' : 'Description / Purpose' ?></label>
                            <textarea class="form-control" id="description" name="description" rows="2" placeholder="<?= $is_sw ? 'Mfano: Mchango wa mwezi wa tatu' : 'Example: Monthly contribution' ?>"></textarea>
                        </div>

                        <div class="card bg-light border-0 mb-4" style="border-radius: 12px;">
                            <div class="card-body p-3">
                                <h6 class="fw-bold text-success mb-3"><i class="bi bi-phone me-2"></i> <?= $is_sw ? 'Maelezo ya Muamala (M-Koba)' : 'Transaction Details (M-Koba)' ?></h6>
                                <div class="row g-2">
                                    <div class="col-md-6 mb-2">
                                        <label class="small fw-bold">ID YA MUAMALA (TRANS ID)</label>
                                        <input type="text" name="mkoba_trans_id" class="form-control form-control-sm" placeholder="e.g. PP2303...">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="small fw-bold">NAMBA YA RISITI (RECEIPT)</label>
                                        <input type="text" name="mkoba_receipt" class="form-control form-control-sm" placeholder="e.g. 54321">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="small fw-bold">ILIKOTOKA (SOURCE)</label>
                                        <input type="text" name="mkoba_source" class="form-control form-control-sm" placeholder="e.g. 0712...">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="small fw-bold">ILIKOENDA (DESTINATION)</label>
                                        <input type="text" name="mkoba_destination" class="form-control form-control-sm" placeholder="e.g. Group Acc">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="evidence" class="form-label fw-bold"><?= $is_sw ? 'Uthibitisho (Slip/Screenshot)' : 'Evidence (Upload Slip/Screenshot)' ?></label>
                            <input type="file" class="form-control" id="evidence" name="evidence" accept="image/*,.pdf">
                            <div class="form-text mt-2 text-muted"><?= $is_sw ? 'Pakia picha ya risiti au picha ya simu (Max 5MB).' : 'Upload the receipt image or phone screenshot (Max 5MB).' ?></div>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> <?= $is_sw ? 'Tuma Ombi la Mchango' : 'Submit Contribution Request' ?>
                            </button>
                            <a href="<?= getUrl('my_contributions') ?>" class="btn btn-outline-secondary"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>


<?php
require_once 'footer.php';
ob_end_flush();
?>
