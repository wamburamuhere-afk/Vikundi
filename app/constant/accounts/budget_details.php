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
    $stmt = $pdo->prepare("
        SELECT b.*, ec.category_name,
               COALESCE(ec.category_name, b.budget_name) as display_name,
               TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
               uc.username AS creator_username, rc.role_name AS creator_role,
               TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
               rr.role_name AS reviewer_role,
               TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
               ra.role_name AS approver_role
          FROM budgets b
          LEFT JOIN expense_categories ec ON b.category_id = ec.category_id
          LEFT JOIN users uc ON b.created_by  = uc.user_id
          LEFT JOIN roles rc ON uc.role_id     = rc.role_id
          LEFT JOIN users ur ON b.reviewed_by  = ur.user_id
          LEFT JOIN roles rr ON ur.role_id     = rr.role_id
          LEFT JOIN users ua ON b.approved_by  = ua.user_id
          LEFT JOIN roles ra ON ua.role_id     = ra.role_id
         WHERE b.budget_id = ?
    ");
    $stmt->execute([$budget_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
}

$can_review_budget  = canReview('budget');
$can_approve_budget = canApprove('budget');

if (!$budget) {
    echo "<div class='container mt-5 text-center'><h3>" . ($is_sw ? 'Bajeti haijapatikana' : 'Budget not found') . "</h3></div>";
    includeFooter();
    exit();
}

$items_stmt = $pdo->prepare("SELECT * FROM budget_items WHERE budget_id = ? ORDER BY item_id ASC");
$items_stmt->execute([$budget['budget_id']]);
$budget_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
$b_status      = $budget['status'] ?? 'pending';
$creator_name  = trim($budget['creator_name'])  ?: ($budget['creator_username'] ?? '—');
$reviewer_name = trim($budget['reviewer_name']) ?: '';
$approver_name = trim($budget['approver_name']) ?: '';
$wf = [
    'created_by_name'  => $creator_name,
    'created_by_role'  => $budget['creator_role']  ?? '',
    'created_at'       => $budget['created_at']    ?? '',
    'reviewed_by_name' => $reviewer_name,
    'reviewed_by_role' => $budget['reviewer_role'] ?? '',
    'reviewed_at'      => $budget['reviewed_at']   ?? '',
    'approved_by_name' => $approver_name,
    'approved_by_role' => $budget['approver_role'] ?? '',
    'approved_at'      => $budget['approved_at']   ?? '',
];
?>

<div class="container-fluid py-4 px-md-4">
    <!-- NAVIGATION (No Print) -->
    <div class="row mb-3 align-items-center d-print-none">
        <div class="col-8">
            <h2 class="fw-bold mb-0 text-primary fs-3"><i class="bi bi-wallet2 me-2"></i><?= htmlspecialchars((string)$budget['display_name']) ?></h2>
            <p class="text-muted mb-0 small"><?= date('F Y', mktime(0, 0, 0, $budget['budget_month'], 1, $budget['budget_year'])) ?></p>
        </div>
        <div class="col-4 text-end">
            <?php if ($b_status === 'pending' && $can_review_budget): ?>
            <button class="btn btn-primary btn-sm rounded-pill me-1 d-print-none" onclick="reviewBudget(<?= $budget['budget_id'] ?>)">
                <i class="bi bi-clipboard-check me-1"></i><?= $is_sw?'Pitia':'Mark Reviewed' ?>
            </button>
            <?php endif; ?>
            <?php if ($b_status === 'reviewed' && $can_approve_budget): ?>
            <button class="btn btn-success btn-sm rounded-pill me-1 d-print-none" onclick="approveBudget(<?= $budget['budget_id'] ?>)">
                <i class="bi bi-check2-circle me-1"></i><?= $is_sw?'Idhinisha':'Approve' ?>
            </button>
            <?php endif; ?>
            <a href="<?= getUrl('print_budget') ?>?id=<?= $budget['budget_id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill me-1 d-print-none">
                <i class="bi bi-printer me-1"></i><?= $labels['print'] ?>
            </a>
            <a href="<?= getUrl('accounts/budget') ?>" class="btn btn-outline-secondary rounded-pill px-3 d-print-none btn-sm">
                <i class="bi bi-arrow-left me-1"></i><?= $labels['back'] ?>
            </a>
        </div>
    </div>

    <div class="d-print-none mb-3"><?php require WORKFLOW_AUDIT_PANEL_FILE; ?></div>

    <!-- START OF PRINTABLE AREA -->
    <div id="printableBudget">
        <?php PrintHeader::css(); ?>
        <!-- PRINT HEADER (Visible only during print) -->
        <div class="d-none d-print-block">
            <?php PrintHeader::render($pdo,
                $is_sw ? 'MAELEZO YA BAJETI' : 'BUDGET PERFORMANCE REPORT',
                htmlspecialchars((string)$budget['display_name']) . ' — ' . date('F Y', mktime(0, 0, 0, $budget['budget_month'], 1, $budget['budget_year']))
            ); ?>
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

    </div>
</div>


<style>
.btn-print-custom:hover { background: #0d6efd !important; color: #fff !important; }
.text-gradient { background: linear-gradient(45deg, #0d6efd, #0b5ed7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
@media print {
    nav, header, footer, .sidebar, .no-print, .btn, .breadcrumb, .location-text, .header-date, .navbar, .marquee-text, .top-bar, .header-wrapper { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>

<script>
function _budgetPost(url, id, msg) {
    Swal.fire({ title: msg, didOpen: () => Swal.showLoading() });
    $.post(url, { budget_id: id }, function(r) {
        if (r.success) {
            Swal.fire({ icon:'success', title:'Done', text:r.message, timer:1400, showConfirmButton:false }).then(() => location.reload());
        } else { Swal.fire('Error', r.message, 'error'); }
    }, 'json').fail(() => Swal.fire('Error', 'Server error', 'error'));
}
function reviewBudget(id) {
    Swal.fire({ title:'<?= $is_sw?"Pitia bajeti hii?":"Mark as Reviewed?" ?>', icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio":"Yes, Reviewed" ?>'
    }).then(r => { if (r.isConfirmed) _budgetPost('<?= getUrl('api/account/review_budget.php') ?>', id, '<?= $is_sw?"Inatuma...":"Submitting..." ?>'); });
}
function approveBudget(id) {
    Swal.fire({ title:'<?= $is_sw?"Idhinisha bajeti hii?":"Approve this Budget?" ?>', icon:'question', showCancelButton:true,
        confirmButtonText:'<?= $is_sw?"Ndio, Idhinisha":"Yes, Approve" ?>', confirmButtonColor:'#198754'
    }).then(r => { if (r.isConfirmed) _budgetPost('<?= getUrl('api/account/approve_budget.php') ?>', id, '<?= $is_sw?"Inaidhinisha...":"Approving..." ?>'); });
}
</script>
<?php includeFooter(); ?>