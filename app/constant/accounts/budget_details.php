<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// 1. PRE-FETCH ALL DATA TO PREVENT WARNINGS IN HEADER/FOOTER
$gs_stmt = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings");
$gs_stmt->execute();
$gs_data = $gs_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// These names must match what header/footer expect
$group_name = $gs_data['group_name'] ?? 'KIKUNDI';
$group_logo = $gs_data['group_logo'] ?? 'logo1.png';

$u_id = $_SESSION['user_id'] ?? 0;
$user_stmt = $pdo->prepare("SELECT u.username, u.first_name, u.last_name, r.role_name 
                            FROM users u 
                            JOIN roles r ON u.role_id = r.role_id 
                            WHERE u.user_id = ?");
$user_stmt->execute([$u_id]);
$u_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

$username = trim(($u_data['first_name'] ?? '') . ' ' . ($u_data['last_name'] ?? ''));
if (empty($username)) $username = $u_data['username'] ?? 'User';
$user_role = $u_data['role_name'] ?? 'Staff';

// Now include header - variables are now ready!
includeHeader();

// Enforce permission
autoEnforcePermission('budget');

$is_sw = isset($_SESSION['preferred_language']) && $_SESSION['preferred_language'] === 'sw';

// Get parameters
$budget_id = $_GET['id'] ?? $_GET['category_id'] ?? '';

// Labels
$labels = [
    'print' => $is_sw ? 'Chapa' : 'Print',
    'back' => $is_sw ? 'Rudi Nyuma' : 'Back',
    'grand_total' => $is_sw ? 'JUMLA KUU' : 'GRAND TOTAL',
    'notes' => $is_sw ? 'MAONI / MAELEZO' : 'REMARKS / NOTES'
];

$budget = null;
if (!empty($budget_id)) {
    $stmt = $pdo->prepare("SELECT b.*, ec.category_name, u1.username as created_by_name, COALESCE(ec.category_name, b.budget_name) as display_name FROM budgets b LEFT JOIN expense_categories ec ON b.category_id = ec.category_id LEFT JOIN users u1 ON b.created_by = u1.user_id WHERE b.budget_id = ?");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$budget) {
    echo "<div class='container mt-5 text-center'><h3>" . ($is_sw ? 'Bajeti haijapatikana' : 'Budget not found') . "</h3></div>";
    includeFooter();
    exit();
}

$items_stmt = $pdo->prepare("SELECT * FROM budget_items WHERE budget_id = ? ORDER BY item_id ASC");
$items_stmt->execute([$budget['budget_id']]);
$budget_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid py-4 px-md-4">
    <!-- NAVIGATION (No Print) -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-8">
            <h2 class="fw-bold mb-0 text-primary fs-3"><i class="bi bi-wallet2 me-2"></i><?= htmlspecialchars((string)$budget['display_name']) ?></h2>
            <p class="text-muted mb-0 small"><?= date('F Y', mktime(0, 0, 0, $budget['budget_month'], 1, $budget['budget_year'])) ?></p>
        </div>
        <div class="col-4 text-end">
            <button type="button" onclick="printBudget()" class="btn btn-white btn-print-custom rounded-pill px-4 shadow-sm border me-2">
                <i class="bi bi-printer me-2"></i> <?= $labels['print'] ?>
            </button>
            <a href="/accounts/budget" class="btn btn-outline-secondary rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> <?= $labels['back'] ?>
            </a>
        </div>
    </div>

    <!-- START OF PRINTABLE AREA -->
    <div id="printableBudget">
        <!-- CUSTOM PRINT HEADER -->
        <div class="text-center mb-4 print-header-box" style="display: none; text-align: center;">
            <img src="<?= getUrl('assets/images/') . htmlspecialchars((string)$group_logo) ?>" alt="Logo" class="mb-3" style="max-height: 115px;">
            <h1 class="fw-bold mb-0 text-uppercase" style="color: #0d6efd; font-size: 2.3rem; margin: 0;"><?= htmlspecialchars((string)$group_name) ?></h1>
            <h2 class="fw-bold text-dark mt-2 border-bottom border-dark border-3 d-inline-block pb-1" style="font-size: 1.5rem; margin-top: 5px;">
                <?= $is_sw ? 'MAELEZO YA BAJETI' : 'BUDGET PERFORMANCE REPORT' ?>
            </h2>
            <div class="mt-3 text-dark fs-5 fw-bold text-uppercase">
                BUDGET NAME: <?= htmlspecialchars((string)$budget['display_name']) ?>
            </div>
            <div class="text-muted small mt-1">
                PERIOD: <?= date('F Y', mktime(0, 0, 0, $budget['budget_month'], 1, $budget['budget_year'])) ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden print-no-shadow">
            <div class="card-body p-0">
                <table class="table table-bordered align-middle mb-0" style="width: 100%; border-collapse: collapse;">
                    <thead class="bg-light">
                        <tr class="small text-uppercase text-muted text-center">
                            <th class="ps-3" style="width: 50px; background: #f8f9fa;">S/N</th>
                            <th class="text-start" style="background: #f8f9fa;"><?= $is_sw ? 'Maelezo' : 'Description' ?></th>
                            <th style="width: 90px; background: #f8f9fa;"><?= $is_sw ? 'Vipimo' : 'Units' ?></th>
                            <th style="width: 80px; background: #f8f9fa;"><?= $is_sw ? 'Idadi' : 'Qty' ?></th>
                            <th class="text-end" style="width: 135px; background: #f8f9fa;"><?= $is_sw ? 'Bei' : 'Price' ?></th>
                            <th class="text-end pe-4" style="width: 155px; background: #f8f9fa;"><?= $is_sw ? 'Jumla' : 'Total' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($budget_items) > 0): ?>
                            <?php foreach ($budget_items as $index => $item): ?>
                            <tr>
                                <td class="ps-3 text-center fw-bold text-muted"><?= $index + 1 ?></td>
                                <td><div class="fw-semibold text-dark"><?= htmlspecialchars((string)$item['description']) ?></div></td>
                                <td class="text-center small"><?= htmlspecialchars((string)($item['units'] ?: '-')) ?></td>
                                <td class="text-center"><?= number_format($item['qty'], 0) ?></td>
                                <td class="text-end"><?= number_format($item['price_per_item'], 2) ?></td>
                                <td class="text-end pe-4 fw-bold text-primary"><?= number_format($item['total_amount'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="bg-light fw-bold" style="background: #f8f9fa;">
                        <tr>
                            <td colspan="5" class="text-end py-3 ps-4 text-uppercase border-top-2"><?= $labels['grand_total'] ?>:</td>
                            <td class="text-end pe-4 py-3 text-primary fs-5 border-top-2"><?= number_format($budget['allocated_amount'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if (!empty($budget['notes'])): ?>
        <div class="card border-0 shadow-sm rounded-4 mt-2 print-no-shadow" style="border-left: 5px solid #0d6efd !important;">
            <div class="card-body p-4 bg-light bg-opacity-10">
                <h6 class="fw-bold text-primary text-uppercase small mb-2"><?= $is_sw ? 'MAONI' : 'NOTES' ?></h6>
                <p class="mb-0 fs-6 text-dark" style="line-height: 1.6;"><?= nl2br(htmlspecialchars((string)$budget['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- CUSTOM PRINT FOOTER -->
        <div class="text-center print-footer-box" style="display: none;">
            <div style="border-top: 3px double #333; padding-top: 15px;">
                <p style="color: #6c757d; font-size: 11pt; margin-bottom: 5px;">
                    This document was Printed by <strong><?= htmlspecialchars((string)$username) ?></strong> - <strong><?= htmlspecialchars((string)$user_role) ?></strong> 
                    on <strong><?= date('d M, Y') ?></strong> at <strong><?= date('H:i:s') ?></strong>
                </p>
                <p style="color: #0d6efd; font-weight: bold; font-size: 10pt; margin-top: 12px;">
                    Powered By BJP Technologies @ 2026, All Rights Reserved
                </p>
            </div>
        </div>
    </div>
</div>

<script>
function printBudget() {
    var printContents = document.getElementById('printableBudget').innerHTML;
    var printWindow = window.open('', '', 'height=800,width=1000');
    printWindow.document.write('<html><head><title>Budget Report</title>');
    printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
    printWindow.document.write('<style>');
    printWindow.document.write('body { padding: 30px; padding-bottom: 90px; background: white; font-family: sans-serif; }');
    printWindow.document.write('.print-header-box { display: block !important; margin-bottom: 30px; }');
    printWindow.document.write('.print-footer-box { display: block !important; position: fixed; bottom: 20px; left: 0; right: 0; width: 100%; text-align: center; background: white; border-top: 2px solid #eee; }');
    printWindow.document.write('.table { border: 2.5px solid #000 !important; width: 100% !important; border-collapse: collapse; }');
    printWindow.document.write('.table th, .table td { border: 1.2px solid #000 !important; padding: 10px !important; color: #000 !important; }');
    printWindow.document.write('.print-no-shadow { box-shadow: none !important; border: 1px solid #ddd !important; }');
    printWindow.document.write('h1 { text-transform: uppercase; }');
    printWindow.document.write('.text-primary { color: #0d6efd !important; }');
    printWindow.document.write('@page { margin: 1.5cm; }');
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(printContents);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    setTimeout(function() {
        printWindow.print();
        printWindow.close();
    }, 700);
}
</script>

<style>
.btn-print-custom:hover { background: #0d6efd !important; color: #fff !important; }
.text-gradient { background: linear-gradient(45deg, #0d6efd, #0b5ed7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
@media print { 
    nav, header, footer, .sidebar, .no-print, .btn, .breadcrumb, .location-text, .header-date, .navbar, .marquee-text, .top-bar { display: none !important; }
}
</style>

<?php includeFooter(); ?>