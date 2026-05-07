<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// PRE-FETCH FOR DATA TABLES & HEADER
$gs_stmt = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings");
$gs_stmt->execute();
$gs_data = $gs_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$group_name = $gs_data['group_name'] ?? 'KIKUNDI';
$group_logo = $gs_data['group_logo'] ?? 'logo1.png';

$logo_path = ROOT_DIR . '/assets/images/' . $group_logo;
$logo_base64 = '';
if (file_exists($logo_path)) {
    $logo_data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/' . pathinfo($logo_path, PATHINFO_EXTENSION) . ';base64,' . base64_encode($logo_data);
}

$u_id = $_SESSION['user_id'] ?? 0;
$user_stmt = $pdo->prepare("SELECT u.username, u.first_name, u.last_name, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$user_stmt->execute([$u_id]);
$u_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$username = trim(($u_data['first_name'] ?? '') . ' ' . ($u_data['last_name'] ?? ''));
if (empty($username)) $username = $u_data['username'] ?? 'User';
$user_role = $u_data['role_name'] ?? 'Staff';

// AJAX DATA FETCH FOR EDIT
if (isset($_GET['action']) && $_GET['action'] === 'get_budget_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $bid = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
    $st->execute([$bid]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if ($b) {
        $st_items = $pdo->prepare("SELECT * FROM budget_items WHERE budget_id = ?");
        $st_items->execute([$bid]);
        $b['items'] = $st_items->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $b]);
    } else { echo json_encode(['success' => false]); }
    exit;
}

includeHeader();
autoEnforcePermission('budget');

$can_view_budget = canView('budget');
$can_edit_budget = canEdit('budget');
if (!$can_view_budget) { redirectTo('dashboard'); }

$current_year = date('Y');
$current_month = date('n');
$selected_year = (isset($_GET['year']) && $_GET['year'] !== '') ? intval($_GET['year']) : null;
$selected_month = (isset($_GET['month']) && $_GET['month'] !== '') ? intval($_GET['month']) : null;

$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$years = []; for ($year = $current_year - 5; $year <= $current_year + 10; $year++) { $years[$year] = $year; }

$lang = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');

$labels = [
    'title' => $is_sw ? 'Usimamizi wa Bajeti' : 'Budget Management',
    'subtitle' => $is_sw ? 'Panga na fuatilia matumizi ya kifedha' : 'Plan, track, and manage your financial budget',
    'add_budget' => $is_sw ? 'Ongeza Bajeti' : 'Add Budget',
    'total_allocated' => 'Total Budgeted',
    'approved_amount' => 'Approved Amount',
    'pending_amount' => 'Pending Amount',
    'approved_items' => 'Approved Items',
];

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_budget') {
    $did = (int)$_POST['budget_id'];
    $pdo->prepare("DELETE FROM budget_items WHERE budget_id = ?")->execute([$did]);
    $pdo->prepare("DELETE FROM budgets WHERE budget_id = ?")->execute([$did]);
    header("Location: /accounts/budget?msg=deleted"); exit;
}

// BUILD FILTERED QUERY
$where_clauses = [];
$params = [];
if ($selected_year) { $where_clauses[] = "budget_year = ?"; $params[] = $selected_year; }
if ($selected_month) { $where_clauses[] = "budget_month = ?"; $params[] = $selected_month; }
$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Stats
$summary_stmt = $pdo->prepare("SELECT 
    SUM(allocated_amount) as total_allocated, 
    SUM(CASE WHEN status = 'approved' THEN allocated_amount ELSE 0 END) as approved_amount,
    SUM(CASE WHEN status != 'approved' THEN allocated_amount ELSE 0 END) as pending_amount,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_count 
    FROM budgets $where_sql");
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Performance Table
$performance_stmt = $pdo->prepare("SELECT b.budget_id, b.category_id, b.budget_name, b.budget_year, b.budget_month, COALESCE(ec.category_name, b.budget_name) as category_name, b.allocated_amount, b.actual_amount, b.status FROM budgets b LEFT JOIN expense_categories ec ON b.category_id = ec.category_id $where_sql ORDER BY b.created_at DESC LIMIT 100");
$performance_stmt->execute($params);
$performance_data = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    body { overflow-x: visible !important; }
    .container-fluid { max-width: 100vw !important; overflow: visible !important; position: relative; }
    header, .navbar, footer, .main-footer { z-index: 100000 !important; }
    /* KPI CARDS STYLING */
    .stat-card-green { background-color: #d1e7dd !important; border-radius: 12px; transition: transform 0.2s; border-bottom: 3px solid #0d6efd; }
    .stat-card-green:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .stat-card-green h4 { color: #334155 !important; font-weight: 800; }
    .stat-card-green p { color: #475569 !important; letter-spacing: 0.5px; opacity: 0.9; }

    .btn-white-pure { background-color: #ffffff !important; border: 1px solid #dee2e6 !important; color: #000 !important; padding: 2px 10px !important; font-size: 0.82rem !important; }
    .modal-blue-accent { border-left: 5px solid #0d6efd !important; }
    .item-row input { border: none; background: transparent; padding: 5px; width: 100%; font-size: 0.9rem; }
    .item-row input:focus { background: #f0f7ff; outline: none; border-radius: 4px; }
    
    @media (max-width: 768px) {
        .stat-card-green h4 { font-size: 1.1rem; }
        .stat-card-green p { font-size: 0.7rem; }
        .btn-primary.rounded-3 { width: 100%; margin-top: 10px; }
    }

    @media print {
        @page { size: auto; margin: 15mm !important; }
        .no-print, .btn, .dropdown, #action-tools, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info, .display-titles, .stat-card-green, .card-body form { display: none !important; }
        body { background: white !important; margin: 0 !important; }
        .table { border-collapse: collapse !important; width: 100% !important; margin-top: 20px !important; }
        .table th, .table td { border: 1px solid #333 !important; padding: 8px !important; color: #000 !important; }
        .table thead th { background-color: #f1f3f5 !important; text-align: center !important; }
        .d-print-table-footer { display: table-footer-group !important; }
        tr { page-break-inside: avoid !important; }
        .card { border: none !important; box-shadow: none !important; }
    }
</style>

<!-- PDF LIBRARIES (CRITICAL) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<div class="container-fluid px-3 px-md-4 py-4">
    <div class="row align-items-center mb-4 g-3">
        <div class="col-12 col-md-8 display-titles">
            <h2 class="fw-bold mb-1" style="color: #0d6efd;"><i class="bi bi-wallet2 me-2"></i><?= $labels['title'] ?></h2>
            <p class="text-muted small mb-0"><?= $labels['subtitle'] ?></p>
        </div>
        <div class="col-12 col-md-4 text-center text-md-end">
            <?php if ($can_edit_budget): ?>
            <button type="button" class="btn btn-primary shadow-sm px-4 rounded-3" onclick="openAddBudgetModal()"><i class="bi bi-plus-lg me-1"></i> <?= $labels['add_budget'] ?></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="row g-2 g-md-3 mb-4 text-center">
        <?php foreach (['total_allocated', 'approved_amount', 'pending_amount', 'approved_items'] as $label): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm stat-card-green h-100 p-2 p-md-3">
                <h4 class="mb-1"><?php 
                    if ($label == 'approved_items') echo $summary['approved_count'] ?? 0;
                    else echo number_format($summary[$label] ?? 0, 0);
                ?></h4>
                <p class="small mb-0 fw-bold text-uppercase" style="font-size: 9px;"><?= $labels[$label] ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter Form -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-6 col-md-4"><label class="small fw-bold text-muted ps-1">Year</label><select class="form-select shadow-none" name="year"><option value="">Show All Years</option><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $selected_year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-4"><label class="small fw-bold text-muted ps-1">Month</label><select class="form-select shadow-none" name="month"><option value="">Show All Months</option><?php foreach ($months as $num => $name): ?><option value="<?= $num ?>" <?= $num === $selected_month ? 'selected' : '' ?>><?= $name ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 shadow-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
                    <a href="/accounts/budget" class="btn btn-secondary w-100 shadow-sm text-decoration-none d-flex align-items-center justify-content-center"><i class="bi bi-x-circle me-1"></i> Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-3 p-md-4">
            <div class="table-responsive d-none d-md-block d-print-block">
                <table id="budgetTable" class="table table-hover align-middle mb-0" style="width: 100%;">
                    <thead>
                        <tr class="small text-uppercase text-muted">
                            <th style="width: 50px;">S/NO</th>
                            <th>Category</th>
                            <th>Allocated Amount</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($performance_data as $idx => $item): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($item['category_name']) ?></td>
                            <td><?= number_format($item['allocated_amount'], 2) ?></td>
                            <td class="small fw-semibold text-muted"><?= $months[$item['budget_month']] . ', ' . $item['budget_year'] ?></td>
                            <td><span class="badge rounded-pill bg-<?= ($item['status'] == 'approved' ? 'success' : 'warning') ?> bg-opacity-10 text-<?= ($item['status'] == 'approved' ? 'success' : 'warning') ?>"><?= ucfirst($item['status']) ?></span></td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-white btn-sm border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0">
                                        <li><a class="dropdown-item py-2" href="/accounts/budget_details?id=<?= $item['budget_id'] ?>"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                                        <li><a class="dropdown-item py-2" href="#" onclick="editBudget(<?= $item['budget_id'] ?>)"><i class="bi bi-pencil text-info me-2"></i> Edit</a></li>
                                        <li><a class="dropdown-item py-2" href="#" onclick="confirmChangeStatus(<?= $item['budget_id'] ?>, '<?= $item['status'] ?>')"><i class="bi bi-arrow-repeat text-warning me-2"></i> Change Status</a></li>
                                        <li><a class="dropdown-item py-2 text-danger" href="#" onclick="confirmDeleteBudget(<?= $item['budget_id'] ?>)"><i class="bi bi-trash3 me-2"></i> Delete</a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="d-none d-print-table-footer">
                        <tr>
                            <td colspan="6" style="height: 2.8cm; border: none !important;">&nbsp;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <!-- Mobile action row: Print / Export / Show — mobile only -->
            <div class="d-flex d-md-none flex-nowrap gap-1 mb-2 align-items-center">
                <button class="btn btn-outline-secondary btn-sm px-3 py-2" title="Print" onclick="$('#budgetTable').DataTable().button('.buttons-print').trigger()">
                    <i class="bi bi-printer"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm px-3 py-2" title="Export Excel" onclick="$('#budgetTable').DataTable().button('.buttons-excel').trigger()">
                    <i class="bi bi-file-excel"></i>
                </button>
                <div class="dropdown ms-auto">
                    <button class="btn btn-outline-secondary btn-sm px-3 py-2 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-list-ul me-1"></i> Show
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick="changeBudgetLen(10);return false;">10</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changeBudgetLen(25);return false;">25</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changeBudgetLen(50);return false;">50</a></li>
                        <li><a class="dropdown-item" href="#" onclick="changeBudgetLen(-1);return false;">All</a></li>
                    </ul>
                </div>
            </div>
            <!-- ═══ CARD VIEW — Mobile Only ═══ -->
            <div class="d-md-none d-print-none vk-cards-wrapper mt-3" id="budgetCardsWrapper">
                <?php if (empty($performance_data)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-wallet2 fs-1 d-block mb-3"></i>
                    <p>No budgets found.</p>
                </div>
                <?php else: foreach ($performance_data as $item):
                    $bg_letter   = strtoupper(substr($item['category_name'] ?? 'B', 0, 1));
                    $bg_approved = ($item['status'] == 'approved');
                    $bg_av_color = $bg_approved
                        ? 'linear-gradient(135deg,#198754,#146c43)'
                        : 'linear-gradient(135deg,#ffc107,#e0a800)';
                    $bg_search   = strtolower(($item['category_name'] ?? '') . ' ' . ($months[$item['budget_month']] ?? '') . ' ' . $item['budget_year'] . ' ' . $item['status']);
                ?>
                <div class="vk-member-card" data-search="<?= htmlspecialchars($bg_search) ?>">
                    <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="vk-card-avatar" style="background:<?= $bg_av_color ?>;"><?= $bg_letter ?></div>
                            <div class="fw-bold text-dark" style="font-size:13px;"><?= htmlspecialchars($item['category_name']) ?></div>
                        </div>
                        <span class="badge bg-<?= $bg_approved ? 'success' : 'warning' ?> rounded-pill px-2 text-<?= $bg_approved ? 'white' : 'dark' ?>" style="font-size:10px;"><?= ucfirst($item['status']) ?></span>
                    </div>
                    <div class="vk-card-body">
                        <div class="vk-card-row">
                            <span class="vk-card-label">Allocated</span>
                            <span class="vk-card-value fw-bold">TZS <?= number_format($item['allocated_amount'], 2) ?></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label">Period</span>
                            <span class="vk-card-value"><?= ($months[$item['budget_month']] ?? '') . ' ' . $item['budget_year'] ?></span>
                        </div>
                    </div>
                    <div class="vk-card-actions">
                        <a href="/accounts/budget_details?id=<?= $item['budget_id'] ?>" class="btn vk-btn-action btn-outline-primary" title="View">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if ($can_edit_budget): ?>
                        <button onclick="editBudget(<?= $item['budget_id'] ?>)" class="btn vk-btn-action btn-outline-warning" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button onclick="confirmChangeStatus(<?= $item['budget_id'] ?>, '<?= $item['status'] ?>')" class="btn vk-btn-action btn-outline-secondary" title="Status">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        <button onclick="confirmDeleteBudget(<?= $item['budget_id'] ?>)" class="btn vk-btn-action btn-outline-danger" title="Delete">
                            <i class="bi bi-trash3"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <!-- Mobile Prev/Next -->
            <div class="d-flex d-md-none justify-content-end align-items-center gap-2 px-3 py-2 border-top">
                <button class="btn btn-sm btn-outline-secondary px-3 py-1" id="budgetPrevBtn" onclick="budgetTablePage('previous')">
                    <i class="bi bi-chevron-left"></i>
                </button>
                <span id="budgetPageInfo" class="small text-muted fw-semibold">1 / 1</span>
                <button class="btn btn-sm btn-outline-secondary px-3 py-1" id="budgetNextBtn" onclick="budgetTablePage('next')">
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="budgetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0 bg-primary text-white p-3 rounded-top-4">
                <h5 class="modal-title fw-bold" id="budgetModalLabel">Budget</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="budgetForm">
                <input type="hidden" name="budget_id" id="budget_id_input" value="">
                <div class="modal-body p-3 p-md-4">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="small fw-bold text-muted">Year</label><select name="budget_year" id="edit_year" class="form-select form-select-sm shadow-none"><?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y==$current_year?'selected':'' ?>><?= $y ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Month</label><select name="budget_month" id="edit_month" class="form-select form-select-sm shadow-none"><?php foreach ($months as $num => $name): ?><option value="<?= $num ?>" <?= $num==$current_month?'selected':'' ?>><?= $name ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="mb-3"><label class="small fw-bold text-muted">Budget Name</label><input type="text" name="budget_name" id="edit_name" class="form-control form-control-sm shadow-none" required></div>
                    <h6 class="fw-bold mb-2 text-primary modal-blue-accent py-1 px-2">Budget Breakdown (Items)</h6>
                    <div class="table-responsive border rounded-3 mb-3">
                        <table class="table table-sm table-items mb-0 align-middle">
                            <thead class="small fw-bold text-muted text-center"><tr><th style="width:40px;">S/No</th><th>Description*</th><th style="width:70px;">Units</th><th style="width:60px;">Qty</th><th style="width:90px;">Price</th><th style="width:100px;">Total</th><th style="width:30px;"></th></tr></thead>
                            <tbody id="item-rows" class="small"></tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3 py-1" id="add-item-btn"><i class="bi bi-plus"></i> Add Line</button>
                        <div class="text-end"><span class="small fw-bold text-muted">Grand Total: </span><span class="fw-bold text-primary" id="modal-grand-total">0.00</span></div>
                    </div>
                    <div><label class="small fw-bold text-muted">Notes</label><textarea name="notes" id="edit_notes" class="form-control form-control-sm shadow-none" rows="2"></textarea></div>
                </div>
                <div class="modal-footer border-0 p-3 bg-light rounded-bottom-4"><button type="button" class="btn btn-link text-muted text-decoration-none" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">Save Budget Report</button></div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var groupLogo = '<?= $logo_base64 ?>';
    $('#budgetTable').DataTable({
        responsive: true,
        dom: '<"d-flex flex-wrap align-items-center justify-content-between mb-3" <"d-flex align-items-center" B l > f >rtip',
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "All"]],
        drawCallback: function() {
            var api = this.api();
            var term = (api.search() || '').toLowerCase().trim();
            var info = api.page.info();
            var start = info.start;
            var end = info.end;
            var matching = [];
            $('#budgetCardsWrapper .vk-member-card').each(function() {
                var text = ($(this).data('search') || '').toLowerCase();
                if (!term || text.includes(term)) { matching.push(this); }
                $(this).hide();
            });
            for (var i = start; i < end && i < matching.length; i++) {
                $(matching[i]).show();
            }
            updateBudgetPageInfo();
        },
        buttons: [
            { 
                extend: 'collection', text: '<i class="bi bi-download me-1"></i> Export', className: 'btn btn-sm btn-primary rounded-pill px-3 d-md-none mb-2 me-2', 
                buttons: [
                    { extend: 'copyHtml5', text: 'Copy', exportOptions: { columns: [0, 1, 2, 3, 4] } }, 
                    { extend: 'excelHtml5', text: 'Excel', exportOptions: { columns: [0, 1, 2, 3, 4] } }, 
                    { extend: 'pdfHtml5', text: 'PDF', exportOptions: { columns: [0, 1, 2, 3, 4] } }, 
                    { extend: 'print', text: 'Print', exportOptions: { columns: [0, 1, 2, 3, 4] } }
                ] 
            },
            { extend: 'copyHtml5', className: 'btn btn-sm btn-white-pure border px-3 me-1 text-dark d-none d-md-inline-block', text: '<i class="bi bi-clipboard"></i> Copy', exportOptions: { columns: [0, 1, 2, 3, 4] } },
            { extend: 'excelHtml5', className: 'btn btn-sm btn-white-pure border px-3 me-1 text-dark d-none d-md-inline-block', text: '<i class="bi bi-file-excel"></i> Excel', exportOptions: { columns: [0, 1, 2, 3, 4] } },
            { 
                extend: 'pdfHtml5', 
                className: 'btn btn-sm btn-white-pure border px-3 me-1 text-dark d-none d-md-inline-block', text: '<i class="bi bi-file-pdf"></i> PDF', title: '',
                exportOptions: { columns: [0, 1, 2, 3, 4] },
                customize: function(doc) { 
                    const now = new Date();
                    const options = { day: '2-digit', month: 'short', year: 'numeric' };
                    const datePart = now.toLocaleDateString('en-GB', options);
                    const timePart = now.toLocaleTimeString('en-GB', { hour12: false });
                    const printTime = datePart + ' at ' + timePart;
                    doc.pageMargins = [40, 120, 40, 60]; 
                    doc.header = function(currentPage, pageCount) {
                        let headerStack = [];
                        if (groupLogo && groupLogo.length > 50) { headerStack.push({ alignment: 'center', image: groupLogo, width: 70 }); }
                        headerStack.push({ text: '<?= strtoupper($group_name) ?>', alignment: 'center', color: '#0d6efd', bold: true, fontSize: 16, margin: [0, 10, 0, 0] });
                        headerStack.push({ text: 'LIST OF BUDGETS', alignment: 'center', bold: true, fontSize: 12, margin: [0, 2, 0, 10] });
                        return { stack: headerStack, margin: [0, 20] };
                    };
                    doc.footer = function(currentPage, pageCount) {
                        return { stack: [
                            { text: 'This document was Printed by <?= $username ?> - <?= $user_role ?> on ' + printTime, alignment: 'center', fontSize: 8 },
                            { text: 'Powered By BJP Technologies @ ' + now.getFullYear() + ', All Rights Reserved', alignment: 'center', color: '#0d6efd', bold: true, fontSize: 8, margin: [0, 5, 0, 0] }
                        ], margin: [0, 10] };
                    };
                    var tableNode; for (var i = 0; i < doc.content.length; i++) { if (doc.content[i].table) { tableNode = doc.content[i]; break; } } 
                    if (tableNode) { tableNode.table.widths = ['10%', '35%', '25%', '30%']; tableNode.layout = 'lightHorizontalLines'; } 
                }
            },
            { 
                extend: 'print', title: '', className: 'btn btn-sm btn-white-pure border shadow-sm px-3 me-2 text-dark d-none d-md-inline-block', text: '<i class="bi bi-printer"></i> Print', 
                exportOptions: { columns: [0, 1, 2, 3, 4] },
                customize: function(win) { 
                    const now = new Date();
                    const options = { day: '2-digit', month: 'short', year: 'numeric' };
                    const datePart = now.toLocaleDateString('en-GB', options);
                    const timePart = now.toLocaleTimeString('en-GB', { hour12: false });
                    const printTime = datePart + ' at ' + timePart;
                    $(win.document.body).css({ 'font-size': '10pt', 'padding': '20px' })
                        .prepend('<div style="text-align:center; margin-bottom: 25px; border-bottom: 2px solid #eee; padding-bottom: 15px;"><img src="<?= !empty($logo_base64) ? $logo_base64 : getUrl('assets/images/') . $group_logo ?>" style="max-height: 80px; margin-bottom: 10px;" /><h2 style="color: #0d6efd; text-transform: uppercase; font-weight: 800; margin: 0;"><?= $group_name ?></h2><h3 style="font-weight: 900; text-transform: uppercase; margin-top: 5px; color: #000;">LIST OF BUDGETS</h3></div>');
                    
                    $(win.document.body).append('<div style="position: fixed; bottom: 0; left: 0; width: 100%; font-size: 8.5pt; border-top: 1px solid #dee2e6; padding: 10px 0; font-family: sans-serif; background: #fff; text-align: center;"><div>This document was <strong>Printed</strong> by <strong><?= $username ?> - <?= $user_role ?></strong> on <strong>' + printTime + '</strong></div><div style="color: #0d6efd; font-weight: bold; margin-top: 5px;">Powered By BJP Technologies @ ' + now.getFullYear() + ', All Rights Reserved</div></div>');
                    
                    $(win.document.body).find('table').addClass('compact')
                        .css({ 'border-collapse': 'collapse', 'width': '100%', 'font-size': '10pt' })
                        .find('th, td').css({ 'border': '1px solid #333', 'padding': '8px' });

                    $(win.document.body).find('table').append('<tfoot class="d-print-table-footer"><tr><td colspan="6" style="height: 2.8cm; border: none !important;">&nbsp;</td></tr></tfoot>');
                } 
            }
        ]
    });

    $('#add-item-btn').click(function() { addRow(); });
    $(document).on('click', '.remove-row', function() { if ($('#item-rows tr').length > 1) { $(this).closest('tr').remove(); reindexRows(); calculateGrandTotal(); } });
    $(document).on('input', '.qty-input, .price-input', function() {
        const row = $(this).closest('tr');
        const qty = parseFloat(row.find('.qty-input').val()) || 0;
        const price = parseFloat(row.find('.price-input').val()) || 0;
        row.find('.row-total').val((qty * price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        calculateGrandTotal();
    });

    $('#budgetForm').on('submit', function(e) {
        e.preventDefault();
        const bid = $('#budget_id_input').val();
        const url = bid ? '<?= getUrl('api/account/edit_budget.php') ?>' : '<?= getUrl('api/account/add_budget.php') ?>';
        $.post(url, $(this).serialize(), function(res) {
            if (res.success) { Swal.fire({icon: 'success', title: bid ? 'Updated!' : 'Saved!', showConfirmButton: false, timer: 1500}).then(() => { window.location.href = '/accounts/budget'; }); }
            else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
});

function addRow(desc = '', units = '', qty = 1, price = 0) {
    const rowCount = $('#item-rows tr').length + 1;
    const total = (qty * price).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const newRow = `<tr class="item-row"><td class="text-center fw-bold">${rowCount}</td><td><input type="text" name="item_description[]" value="${desc}" required placeholder="Description..."></td><td><input type="text" name="item_units[]" value="${units}" placeholder="pcs"></td><td><input type="number" step="any" name="item_qty[]" class="qty-input text-center" value="${qty}"></td><td><input type="number" step="any" name="item_price[]" class="price-input text-end" value="${price}"></td><td><input type="text" class="row-total text-end fw-bold text-dark" readonly value="${total}"></td><td><button type="button" class="btn btn-link text-danger p-0 remove-row"><i class="bi bi-trash3"></i></button></td></tr>`;
    $('#item-rows').append(newRow);
    calculateGrandTotal();
}
function reindexRows() { $('#item-rows tr').each(function(idx) { $(this).find('td:first').text(idx + 1); }); }
function calculateGrandTotal() {
    let total = 0;
    $('#item-rows tr').each(function() { total += (parseFloat($(this).find('.qty-input').val()) || 0) * (parseFloat($(this).find('.price-input').val()) || 0); });
    $('#modal-grand-total').text(total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
}
function openAddBudgetModal() {
    $('#budgetModalLabel').html('<i class="bi bi-plus-circle-fill me-2"></i>New Itemized Budget');
    $('#budget_id_input').val(''); $('#budgetForm')[0].reset(); $('#item-rows').empty(); addRow(); $('#budgetModal').modal('show');
}
function editBudget(id) {
    Swal.fire({title: 'Fetching...', didOpen: () => { Swal.showLoading(); }});
    $.get('/accounts/budget', {action: 'get_budget_details', id: id}, function(res) {
        Swal.close();
        if (res.success) {
            const b = res.data; $('#budgetModalLabel').html('<i class="bi bi-pencil-square me-2"></i>Edit Budget');
            $('#budget_id_input').val(b.budget_id); $('#edit_year').val(b.budget_year); $('#edit_month').val(b.budget_month); $('#edit_name').val(b.budget_name); $('#edit_notes').val(b.notes); $('#item-rows').empty();
            if (b.items && b.items.length > 0) { b.items.forEach(it => { addRow(it.description, it.units, it.qty, it.price_per_item); }); } else { addRow(); }
            $('#budgetModal').modal('show');
        } else { Swal.fire('Error', 'Could not fetch properties', 'error'); }
    }, 'json');
}
function confirmChangeStatus(id, currentStatus) {
    Swal.fire({ title: 'Change Status', input: 'select', inputOptions: {'pending': 'Pending', 'approved': 'Approved', 'rejected': 'Rejected'}, inputValue: currentStatus, showCancelButton: true, confirmButtonText: 'Update' }).then((result) => {
        if (result.isConfirmed) { $.post('<?= getUrl('api/account/update_budget_status.php') ?>', { budget_id: id, status: result.value }, function(res) { if (res.success) { window.location.reload(); } }, 'json'); }
    });
}
function confirmDeleteBudget(id) {
    Swal.fire({ title: 'Delete?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, Delete' }).then((result) => { if (result.isConfirmed) { $('#delete_id').val(id); $('#deleteForm').submit(); } });
}
function budgetTablePage(dir) { $('#budgetTable').DataTable().page(dir).draw('page'); }
function updateBudgetPageInfo() {
    var api = $('#budgetTable').DataTable();
    var info = api.page.info();
    var cur = info.page + 1, tot = info.pages || 1;
    $('#budgetPageInfo').text(cur + ' / ' + tot);
    $('#budgetPrevBtn').prop('disabled', info.page === 0);
    $('#budgetNextBtn').prop('disabled', info.page >= info.pages - 1);
}
function changeBudgetLen(n) { $('#budgetTable').DataTable().page.len(n).draw(); }
function exportBudget() { $('#budgetTable').DataTable().button('.buttons-print').trigger(); }
</script>

<form id="deleteForm" method="POST" style="display:none;"><input type="hidden" name="action" value="delete_budget"><input type="hidden" name="budget_id" id="delete_id"></form>
<?php includeFooter(); ?>