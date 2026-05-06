<?php
// app/constant/accounts/petty_cash.php
ob_start();
require_once HEADER_FILE;

$lang = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');
$isSwahili = $is_sw; // Maintain compatibility with existing code

// Authorization check (example roles)
$allowed_roles = ['Admin', 'Secretary', 'Katibu', 'Treasurer', 'Mhazini'];
if (!in_array($user_role, $allowed_roles)) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

// 1. PRE-FETCH BRANDING (Base64 Logo Logic)
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

// 2. FETCH USER DETAILS FOR FOOTER
$u_id = $_SESSION['user_id'] ?? 0;
$user_stmt = $pdo->prepare("SELECT u.username, u.first_name, u.last_name, r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$user_stmt->execute([$u_id]);
$u_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
$username = trim(($u_data['first_name'] ?? '') . ' ' . ($u_data['last_name'] ?? ''));
if (empty($username)) $username = $u_data['username'] ?? 'User';
$user_footer_role = $u_data['role_name'] ?? 'Staff';

$report_title = $is_sw ? 'Ripoti ya Vocha za Fedha (Petty Cash)' : 'Petty Cash Vouchers Report';


// Stats for header
$stmt_pending = $pdo->prepare("SELECT COUNT(*) FROM petty_cash_vouchers WHERE status = 'pending'");
$stmt_pending->execute();
$pending_count = $stmt_pending->fetchColumn();

$stmt_total_month = $pdo->prepare("SELECT SUM(amount) FROM petty_cash_vouchers WHERE status = 'approved' AND MONTH(transaction_date) = MONTH(CURRENT_DATE) AND YEAR(transaction_date) = YEAR(CURRENT_DATE)");
$stmt_total_month->execute();
$total_month = $stmt_total_month->fetchColumn() ?? 0;

$stmt_total_all = $pdo->prepare("SELECT SUM(amount) FROM petty_cash_vouchers WHERE status = 'approved'");
$stmt_total_all->execute();
$total_all = $stmt_total_all->fetchColumn() ?? 0;
?>

<div class="container-fluid px-0 px-md-3">
    <!-- 1. PRINT HEADER (Visible only during print) -->
    <div class="d-none d-print-block print-header mb-4 text-center">
        <div class="border-bottom pb-4">
            <div class="mb-3">
                <img src="<?= !empty($logo_base64) ? $logo_base64 : getUrl('assets/images/') . $group_logo ?>" alt="Logo" style="height: 100px; width: auto; object-fit: contain;">
            </div>
            <h2 class="fw-bold mb-1" style="color: #0d6efd !important; text-transform: uppercase;">
                <?= htmlspecialchars($group_name) ?>
            </h2>
            <h3 class="fw-bold mb-2 text-dark" style="text-transform: uppercase;"><?= $report_title ?></h3>
            <p class="mb-0 small text-muted text-uppercase"><?= $is_sw ? 'Ripoti Rasmi ya Mfumo' : 'Official System Report' ?></p>
        </div>
    </div>
    <!-- Header Section -->
    <div class="py-3 border-bottom mb-4 px-3 px-md-0">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0 fw-bold text-primary">
                    <i class="bi bi-ticket-perforated me-2"></i> 
                    <?= $isSwahili ? 'Vocha za Fedha (Petty Cash)' : 'Petty Cash Vouchers' ?>
                </h4>
                <p class="text-muted small mb-0">
                    <?= $isSwahili ? 'Simamia matumizi madogo madogo ya kikundi' : 'Manage minor group operational expenses' ?>
                </p>
            </div>
            <div>
                <button class="btn btn-primary rounded-2 shadow-sm px-3 py-2 d-flex align-items-center fw-bold" onclick="openNewVoucherModal()">
                    <i class="bi bi-plus-lg me-2"></i>
                    <span><?= $isSwahili ? 'Vocha Mpya' : 'New Voucher' ?></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards Row -->
    <div class="row g-3 mb-4 px-3 px-md-0 print-stats-row">
        <!-- Pending Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <i class="bi bi-clock-history fs-3" style="color: #0f5132;"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0 fw-bold text-uppercase border-bottom pb-1" style="color: #0f5132 !important; opacity: 0.8; letter-spacing: 0.5px;">
                                <?= $isSwahili ? 'Zinasubiri Uhakiki' : 'Pending Verification' ?>
                            </p>
                            <h3 class="fw-bold mb-0 mt-1" style="color: #0f5132;"><?= $pending_count ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- This Month Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <i class="bi bi-calendar-check fs-3" style="color: #0f5132;"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0 fw-bold text-uppercase border-bottom pb-1" style="color: #0f5132 !important; opacity: 0.8; letter-spacing: 0.5px;">
                                <?= $isSwahili ? 'Matumizi ya Mwezi Huu' : 'Expenses This Month' ?>
                            </p>
                            <h3 class="fw-bold mb-0 mt-1" style="color: #0f5132;">TSh <?= format_number($total_month, 0) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Total Approved Card -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="background-color: #d1e7dd; border-radius: 12px;">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <i class="bi bi-check-all fs-2" style="color: #0f5132;"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0 fw-bold text-uppercase border-bottom pb-1" style="color: #0f5132 !important; opacity: 0.8; letter-spacing: 0.5px;">
                                <?= $isSwahili ? 'Jumla Iliyoidhinishwa' : 'Total Approved' ?>
                            </p>
                            <h3 class="fw-bold mb-0 mt-1" style="color: #0f5132;">TSh <?= format_number($total_all, 0) ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card border-0 shadow-sm mb-4 px-3 py-3 filter-section-container" style="border-radius: 15px; border-left: 5px solid #0d6efd !important;">
        <form id="filterForm" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label x-small fw-bold text-muted"><?= $isSwahili ? 'Kuanzia Tarehe' : 'From Date' ?></label>
                <input type="date" id="from_date" class="form-control form-control-sm border-0 bg-light">
            </div>
            <div class="col-md-2">
                <label class="form-label x-small fw-bold text-muted"><?= $isSwahili ? 'Mpaka Tarehe' : 'To Date' ?></label>
                <input type="date" id="to_date" class="form-control form-control-sm border-0 bg-light">
            </div>
            <div class="col-md-2">
                <label class="form-label x-small fw-bold text-muted"><?= $isSwahili ? 'Hali (Status)' : 'Status' ?></label>
                <select id="filter_status" class="form-select form-select-sm border-0 bg-light">
                    <option value=""><?= $isSwahili ? 'Zote' : 'All Status' ?></option>
                    <option value="pending"><?= $isSwahili ? 'Inasubiri' : 'Pending' ?></option>
                    <option value="approved"><?= $isSwahili ? 'Imeidhinishwa' : 'Approved' ?></option>
                    <option value="rejected"><?= $isSwahili ? 'Imekataliwa' : 'Rejected' ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label x-small fw-bold text-muted"><?= $isSwahili ? 'Kipengele' : 'Category' ?></label>
                <select id="filter_category" class="form-select form-select-sm border-0 bg-light">
                    <option value=""><?= $isSwahili ? 'Vipengele Vyote' : 'All Categories' ?></option>
                    <option value="Office Supplies"><?= $isSwahili ? 'Mahitaji ya Ofisi' : 'Office Supplies' ?></option>
                    <option value="Transport"><?= $isSwahili ? 'Usafiri/Nauli' : 'Transport' ?></option>
                    <option value="Communication"><?= $isSwahili ? 'Mawasiliano' : 'Communication' ?></option>
                    <option value="Refreshments"><?= $isSwahili ? 'Chai/Vyakula' : 'Refreshments' ?></option>
                    <option value="Maintenance"><?= $isSwahili ? 'Matengenezo' : 'Maintenance' ?></option>
                    <option value="Other"><?= $isSwahili ? 'Mengineyo' : 'Other' ?></option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="button" id="btnFilter" class="btn btn-primary btn-sm flex-grow-1 rounded-2 shadow-sm fw-bold">
                    <i class="bi bi-funnel me-1"></i> <?= $isSwahili ? 'Chuja' : 'Filter' ?>
                </button>
                <button type="button" id="btnClear" class="btn btn-secondary btn-sm flex-grow-1 rounded-2 shadow-sm fw-bold">
                    <i class="bi bi-x-lg me-1"></i> <?= $isSwahili ? 'Sugua' : 'Clear' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Controls Container -->
    <div class="row mb-4 px-3 px-md-0">
        <div class="col-12 d-flex flex-column flex-md-row align-items-md-center gap-3">
            <div class="d-flex align-items-center gap-2 overflow-auto no-scrollbar" id="action-tools" style="flex-wrap: nowrap !important; white-space: nowrap !important;"></div>
            <div id="custom-search" class="flex-grow-1" style="max-width: 500px;"></div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card border-0 shadow-sm" style="border-radius: 15px;">
        <div class="card-body p-2 p-md-4 d-none d-md-block d-print-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="pettyCashTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th style="width: 50px;">S/NO</th>
                            <th><?= $isSwahili ? 'Namba ya Vocha' : 'Voucher No' ?></th>
                            <th><?= $isSwahili ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $isSwahili ? 'Mpokeaji (Payee)' : 'Payee Name' ?></th>
                            <th><?= $isSwahili ? 'Maelezo' : 'Description' ?></th>
                            <th class="text-center"><?= $isSwahili ? 'Kiasi (TSh)' : 'Amount (TSh)' ?></th>
                            <th class="text-center"><?= $isSwahili ? 'Hali' : 'Status' ?></th>
                            <th class="text-end pe-3"><?= $isSwahili ? 'Hatua' : 'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot class="d-none d-print-table-footer">
                        <tr>
                            <td colspan="7" style="height: 2.8cm; border: none !important;">&nbsp;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ═══ CARD VIEW — Mobile Only (server-side; rendered by drawCallback) ═══ -->
        <div class="p-3 d-md-none d-print-none" id="pettyCashCardsWrapper">
            <div id="pettyCashCardsEmptyState" class="d-none text-center py-5">
                <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0"><?= $isSwahili ? 'Hakuna vocha zilizopatikana.' : 'No vouchers found.' ?></p>
            </div>
        </div>
        <!-- ═══ END CARD VIEW ═══ -->
    </div>

    <!-- 4. PRINT FOOTER (Persistent on every page during print) -->
    <div class="d-none d-print-block print-footer" style="position: fixed; bottom: 5mm; width: 100%; left: 0; background: white;">
        <div class="row pt-2 border-top text-center">
            <div class="col-12">
                 <p class="mb-1 text-dark" style="font-size: 8.5pt;">
                        <?= $is_sw ? 'Nyaraka hii imechapishwa na' : 'This document was printed by' ?> 
                        <strong><?= htmlspecialchars($username) ?> - <?= htmlspecialchars($user_role) ?></strong> 
                        <?= $is_sw ? 'mnamo' : 'on' ?> <strong><?= date('d M, Y') ?></strong> 
                        <?= $is_sw ? 'saa' : 'at' ?> <strong id="print_time_js"><?= date('H:i:s') ?></strong>
                    </p>
                <h6 class="mb-0 fw-bold" style="color: #0d6efd !important; font-size: 9pt;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</h6>
            </div>
        </div>
    </div>
</div>

<!-- NEW VOUCHER MODAL -->
<div class="modal fade" id="newVoucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill text-primary me-2"></i><?= $isSwahili ? 'Tengeneza Vocha Mpya' : 'Create New Voucher' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="voucherForm">
                <input type="hidden" name="voucher_id" id="modalVoucherId" value="0">
                <div class="modal-body py-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold"><?= $isSwahili ? 'Tarehe' : 'Date' ?></label>
                            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold"><?= $isSwahili ? 'Kiasi (TZS)' : 'Amount (TZS)' ?></label>
                            <input type="number" name="amount" class="form-control" placeholder="0.00" step="0.01" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $isSwahili ? 'Mpokeaji (Payee)' : 'Payee Name' ?></label>
                            <input type="text" name="payee_name" class="form-control" placeholder="<?= $isSwahili ? 'Jina la anayepokea pesa' : 'Name of person receiving cash' ?>" required>
                        </div>
                        <div class="col-12" id="categoryContainer">
                            <label class="form-label small fw-bold"><?= $isSwahili ? 'Kipengele (Category)' : 'Category' ?></label>
                            
                            <!-- Standard Select -->
                            <div id="selectWrapper">
                                <select id="categorySelect" class="form-select shadow-sm" name="category">
                                    <option value="Office Supplies"><?= $isSwahili ? 'Mahitaji ya Ofisi' : 'Office Supplies' ?></option>
                                    <option value="Transport"><?= $isSwahili ? 'Usafiri/Nauli' : 'Transport' ?></option>
                                    <option value="Communication"><?= $isSwahili ? 'Mawasiliano/Muda wa Hewa' : 'Communication/Airtime' ?></option>
                                    <option value="Refreshments"><?= $isSwahili ? 'Chai/Vyakula' : 'Refreshments' ?></option>
                                    <option value="Maintenance"><?= $isSwahili ? 'Matengenezo Makogo' : 'Minor Maintenance' ?></option>
                                    <option value="Other"><?= $isSwahili ? 'Mengineyo (Andika...) ' : 'Other (Type...) ' ?></option>
                                </select>
                            </div>

                            <!-- Editable Input (Initially Hidden) -->
                            <div id="inputWrapper" style="display: none;">
                                <div class="input-group">
                                    <input type="text" id="categoryInput" class="form-control" placeholder="<?= $isSwahili ? 'Andika kipengele hapa...' : 'Type category here...' ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="btnBackToSelect" title="<?= $isSwahili ? 'Rudi kwenye orodha' : 'Back to list' ?>">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold"><?= $isSwahili ? 'Maelezo ya Matumizi' : 'Detailed Description' ?></label>
                            <textarea name="description" class="form-control" rows="3" placeholder="<?= $isSwahili ? 'Elezea kwa ufupi matumizi haya...' : 'Briefly explain the expense...' ?>" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#newVoucherModal" style="display:none;"></button> <!-- Helper -->
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal"><?= $isSwahili ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" id="btnSubmitVoucher" class="btn btn-primary rounded-pill px-4 shadow-sm"><?= $isSwahili ? 'Hifadhi Vocha' : 'Save Voucher' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VIEW VOUCHER MODAL -->
<div class="modal fade" id="viewVoucherModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header bg-light border-0 py-3" style="border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-text-fill text-primary me-2"></i><?= $isSwahili ? 'Maelezo ya Vocha' : 'Voucher Details' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="voucherDetailContent" class="p-4">
                     <!-- Content dynamic -->
                </div>
            </div>
            <div class="modal-footer border-0 bg-light py-2" style="border-radius: 0 0 20px 20px;">
                <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal"><?= $isSwahili ? 'Funga' : 'Close' ?></button>
                <button type="button" class="btn btn-primary btn-sm px-4 rounded-pill" onclick="printFromView()"><i class="bi bi-printer me-1"></i><?= $isSwahili ? 'Chapa' : 'Print' ?></button>
            </div>
        </div>
    </div>
</div>

<style>
    .x-small { font-size: 0.7rem; }
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .dt-buttons { display: flex !important; gap: 8px !important; margin: 0 !important; }
    .dt-buttons .btn, .length-menu-wrapper { background-color: #fff !important; border: 1px solid #dee2e6 !important; border-radius: 8px !important; height: 35px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .dt-buttons .btn { color: #495057 !important; font-size: 0.75rem !important; padding: 0 12px !important; min-width: 65px !important; font-weight: 500 !important; }
    .length-menu-wrapper { overflow: hidden; }
    .length-menu-icon { padding: 0 8px; background: #f1f3f5; border-right: 1px solid #dee2e6; height: 100%; display: flex; align-items: center; color: #6c757d; font-size: 0.75rem; }
    .dataTables_length select { border: none !important; outline: none !important; padding: 0 5px !important; font-size: 0.8rem !important; font-weight: 600; cursor: pointer; background: transparent !important; appearance: none !important; width: 50px !important; text-align: center; }
    .dataTables_filter { text-align: left !important; width: 100% !important; }
    .dataTables_filter input { border-radius: 12px !important; padding: 0.5rem 1.2rem !important; width: 100% !important; background-color: #f8f9fa !important; border: 1px solid #e9ecef !important; font-size: 0.9rem; }
    .dataTables_filter input:focus { background-color: #fff !important; border-color: #0d6efd !important; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1) !important; outline: none !important; }
    
    @media (max-width: 768px) {
        .dt-buttons .btn, .length-menu-wrapper { height: 30px !important; }
        #custom-search { max-width: none !important; }
    }

    @media print {
        @page { size: auto; margin: 15mm !important; margin-bottom: 30mm !important; }
        .no-print, .dt-buttons, .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate, nav, header, .navbar, .header-wrapper, .sidebar-wrapper, .main-footer, .modal, #filterForm, .py-3.border-bottom, #action-tools, #custom-search { display: none !important; }
        body { background-color: white !important; margin: 0 !important; padding-bottom: 30mm !important; overflow: visible !important; }
        .container-fluid { padding: 0 !important; max-width: 100% !important; margin: 0 !important; width: 100% !important; overflow: visible !important; }
        .card, .table-responsive { page-break-inside: auto !important; border: none !important; box-shadow: none !important; overflow: visible !important; }
        
        /* Stats Cards in Print */
        .print-stats-row { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; gap: 8px !important; margin-bottom: 0 !important; }
        .print-stats-row .col-md-4 { flex: 1 !important; max-width: 33.33% !important; width: 33.33% !important;}
        .print-stats-row .card { border: 1px solid #dee2e6 !important; background-color: #f8f9fa !important; box-shadow: none !important; margin-bottom: 0 !important; }
        .print-stats-row h3, .print-stats-row p, .print-stats-row i { color: #000 !important; }
        
        .filter-section-container, #filterForm { display: none !important; }
        
        .table { width: 100% !important; border-collapse: collapse !important; margin-top: 5px !important; }
        .d-print-table-footer { display: table-footer-group !important; }
        .table thead th { text-align: center !important; background-color: #f1f3f5 !important; border: 1px solid #dee2e6 !important; color: #000 !important; }
        .table th, .table td { border: 1px solid #dee2e6 !important; padding: 4px 6px !important; white-space: nowrap !important; }
        .table td div.text-wrap { white-space: normal !important; max-width: 180px !important; } /* Allow description to wrap slightly but restricted */
        .table th:last-child, .table td:last-child { display: none !important; }
        tr { page-break-inside: avoid !important; }
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
</style>

<script>
const isSwahili = <?= $is_sw ? 'true' : 'false' ?>;
window.onbeforeprint = function() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const timeStr = `${h}:${m}:${s}`;
    const timeSpan = document.getElementById('print_time_js');
    if (timeSpan) timeSpan.innerText = timeStr;
};

$(document).ready(function() {
    // Auto-open modal if action is 'new'
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'new') {
        openNewVoucherModal();
    }

    var table = $('#pettyCashTable').DataTable({
        serverSide: true,
        processing: true,
        responsive: false,
        ajax: { 
            url: '<?= getUrl('actions/fetch_petty_cash') ?>', 
            type: 'POST',
            data: function(d) {
                d.from_date = $('#from_date').val();
                d.to_date = $('#to_date').val();
                d.status = $('#filter_status').val();
                d.category = $('#filter_category').val();
            }
        },
        columns: [
            { data: 'sno', className: 'ps-3 fw-bold text-muted' },
            { data: 'voucher_no', className: 'fw-bold text-primary' },
            { data: 'date', className: 'small' },
            { data: 'payee' },
            { data: 'description', className: 'small' },
            { data: 'amount', className: 'text-center fw-bold' },
            { data: 'status', className: 'text-center' },
            { data: 'action', orderable: false, className: 'pe-3 text-end' }
        ],
        dom: 'Blfrtip',
        buttons: [
            { 
                text: '<i class="bi bi-printer me-1"></i> ' + (isSwahili ? 'Printi' : 'Print'), 
                className: 'btn btn-sm btn-white',
                action: function ( e, dt, node, config ) {
                    const now = new Date();
                    const h = String(now.getHours()).padStart(2, '0');
                    const m = String(now.getMinutes()).padStart(2, '0');
                    const s = String(now.getSeconds()).padStart(2, '0');
                    const timeStr = `${h}:${m}:${s}`;
                    document.getElementById('print_time_js').innerText = timeStr;
                    setTimeout(() => { window.print(); }, 100);
                }
            },
            { extend: 'excel', text: '<i class="bi bi-file-earmark-excel me-1"></i> Excel', className: 'btn btn-sm btn-white' }
        ],
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "<?= $is_sw ? 'Zote' : 'All' ?>"]],
        language: {
            search: "",
            searchPlaceholder: "<?= $isSwahili ? 'Tafuta Vocha...' : 'Search Vouchers...' ?>",
            lengthMenu: '<div class="length-menu-wrapper"><div class="length-menu-icon"><i class="bi bi-list-ul"></i></div> _MENU_</div>',
            info: "<?= $isSwahili ? '_START_ - _END_ ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_' ?>",
            paginate: { previous: "<?= $isSwahili ? 'Nyuma' : 'Prev' ?>", next: "<?= $isSwahili ? 'Mbele' : 'Next' ?>" }
        },
        order: [[2, 'desc']],
        pageLength: 25,
        drawCallback: function() { renderPettyCashCards(this.api()); },
        initComplete: function() {
            $('.dataTables_filter').appendTo('#custom-search');
            $('.dt-buttons').appendTo('#action-tools');
            $('.dataTables_length').appendTo('#action-tools');
            $('.dataTables_length label').contents().filter(function() { return this.nodeType === 3; }).remove();
        }
    });

    // Handle Filters
    $('#btnFilter').on('click', function() { table.ajax.reload(); });
    $('#btnClear').on('click', function() {
        $('#filterForm')[0].reset();
        table.ajax.reload();
    });

    // In-place Category Swap Logic
    $('#categorySelect').on('change', function() {
        if ($(this).val() === 'Other') {
            $('#selectWrapper').hide();
            $('#categorySelect').removeAttr('name');
            
            $('#inputWrapper').show();
            $('#categoryInput').attr('name', 'category').val('').focus();
        }
    });

    $('#btnBackToSelect').on('click', function() {
        $('#inputWrapper').hide();
        $('#categoryInput').removeAttr('name');
        
        $('#selectWrapper').show();
        $('#categorySelect').attr('name', 'category').val('Office Supplies');
    });

    // Handle Form Submit
    $('#voucherForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>' + (isSwahili ? 'Inahifadhi...' : 'Saving...'));

        $.ajax({
            url: '<?= getUrl('actions/save_petty_cash') ?>',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(r) {
                if(r.success) {
                    Swal.fire({ icon: 'success', title: isSwahili ? 'Imefanikiwa!' : 'Success!', text: r.message, timer: 1500, showConfirmButton: false });
                    $('#newVoucherModal').modal('hide');
                    
                    // Reset everything to Select
                    $('#inputWrapper').hide();
                    $('#categoryInput').removeAttr('name');
                    $('#selectWrapper').show();
                    $('#categorySelect').attr('name', 'category').val('Office Supplies');
                    
                    $('#voucherForm')[0].reset();
                    table.ajax.reload();
                } else {
                    Swal.fire('Error', r.message, 'error');
                }
            },
            error: function(xhr) { 
                Swal.fire('Error', isSwahili ? 'Hitilafu ya Server: ' + xhr.responseText : 'Server Error: ' + xhr.responseText, 'error'); 
            },
            complete: function() { btn.prop('disabled', false).html(isSwahili ? 'Hifadhi Vocha' : 'Save Voucher'); }
        });
    });
});

function approveVoucher(id) {
    Swal.fire({
        title: isSwahili ? 'Je, una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Uhakiki huu utaruhusu malipo haya.' : 'This will approve the payment.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: isSwahili ? 'Ndiyo,idhinisha' : 'Yes, approve',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/approve_petty_cash') ?>',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(r) {
                    if(r.success) {
                        Swal.fire(isSwahili ? 'Imethibitishwa!' : 'Approved!', r.message, 'success');
                        table.ajax.reload();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                }
            });
        }
    });
}

function printVoucher(id) {
    window.location.href = '<?= getUrl('print_petty_cash') ?>?id=' + id;
}

function deleteVoucher(id) {
    Swal.fire({
        title: isSwahili ? 'Una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Hutaweza kurudisha hii vocha!' : 'You will not be able to recover this voucher!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: isSwahili ? 'Ndiyo, futa!' : 'Yes, delete it!',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/delete_petty_cash') ?>',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(r) {
                    if(r.success) {
                        Swal.fire(isSwahili ? 'Imefutwa!' : 'Deleted!', r.message, 'success');
                        table.ajax.reload();
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                }
            });
        }
    });
}

function openNewVoucherModal() {
    $('#modalVoucherId').val(0);
    $('#voucherForm')[0].reset();
    
    // Reset category to select
    $('#inputWrapper').hide();
    $('#categoryInput').removeAttr('name');
    $('#selectWrapper').show();
    $('#categorySelect').attr('name', 'category').val('Office Supplies');

    $('#newVoucherModal .modal-title').html('<i class="bi bi-plus-circle-fill text-primary me-2"></i>' + (isSwahili ? 'Tengeneza Vocha Mpya' : 'Create New Voucher'));
    $('#btnSubmitVoucher').html(isSwahili ? 'Hifadhi Vocha' : 'Save Voucher');
    
    $('#newVoucherModal').modal('show');
}

function editVoucher(id) {
    $.getJSON('<?= getUrl('actions/get_petty_cash') ?>', { id: id }, function(r) {
        if(r.success) {
            const v = r.data;
            $('#modalVoucherId').val(v.id);
            $('[name="transaction_date"]').val(v.transaction_date);
            $('[name="amount"]').val(v.amount);
            $('[name="payee_name"]').val(v.payee_name);
            $('[name="description"]').val(v.description);
            
            // Check if category exists in select list
            let inList = false;
            $('#categorySelect option').each(function() {
                if ($(this).val() === v.category) inList = true;
            });
            
            if (inList) {
                $('#inputWrapper').hide();
                $('#categoryInput').removeAttr('name');
                $('#selectWrapper').show();
                $('#categorySelect').attr('name', 'category').val(v.category);
            } else {
                $('#selectWrapper').hide();
                $('#categorySelect').removeAttr('name');
                $('#inputWrapper').show();
                $('#categoryInput').attr('name', 'category').val(v.category);
            }

            $('#newVoucherModal .modal-title').html('<i class="bi bi-pencil-square text-primary me-2"></i>' + (isSwahili ? 'Hariri Vocha' : 'Edit Voucher'));
            $('#btnSubmitVoucher').html(isSwahili ? 'Rekebisha Vocha' : 'Update Voucher');
            $('#newVoucherModal').modal('show');
        } else {
            Swal.fire('Error', r.message, 'error');
        }
    });
}

let currentVoucherId = 0;

function printFromView() {
    if (currentVoucherId > 0) {
        window.location.href = '<?= getUrl('print_petty_cash') ?>?id=' + currentVoucherId;
    }
}

function viewVoucher(id) {
    currentVoucherId = id; // Set current ID for printing from modal
    $.getJSON('<?= getUrl('actions/get_petty_cash') ?>', { id: id }, function(r) {
        if(r.success) {
            const v = r.data;
            let statusClass = 'secondary';
            if(v.status === 'approved') statusClass = 'success';
            if(v.status === 'pending') statusClass = 'warning';
            if(v.status === 'rejected') statusClass = 'danger';

            let html = `
                <div class="mb-4 d-flex justify-content-between align-items-center border-bottom pb-3">
                    <h6 class="fw-bold mb-0">${isSwahili ? 'Namba ya Vocha' : 'Voucher No'}: <span class="text-primary">${v.voucher_no}</span></h6>
                    <span class="badge bg-${statusClass} px-3 py-2 uppercase small">${v.status}</span>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="small text-muted d-block text-uppercase fw-bold" style="font-size: 10px;">${isSwahili ? 'Tarehe' : 'Date'}</label>
                        <p class="fw-bold">${v.transaction_date}</p>
                    </div>
                    <div class="col-6">
                        <label class="small text-muted d-block text-uppercase fw-bold" style="font-size: 10px;">${isSwahili ? 'Kiasi' : 'Amount'}</label>
                        <p class="fw-bold text-dark">TSh ${parseFloat(v.amount).toLocaleString()}</p>
                    </div>
                    <div class="col-12">
                        <label class="small text-muted d-block text-uppercase fw-bold" style="font-size: 10px;">${isSwahili ? 'Mpokeaji' : 'Payee'}</label>
                        <p class="fw-bold">${v.payee_name}</p>
                    </div>
                    <div class="col-12">
                        <label class="small text-muted d-block text-uppercase fw-bold" style="font-size: 10px;">${isSwahili ? 'Kundi' : 'Category'}</label>
                        <p class="fw-bold">${v.category}</p>
                    </div>
                    <div class="col-12">
                        <label class="small text-muted d-block text-uppercase fw-bold" style="font-size: 10px;">${isSwahili ? 'Maelezo' : 'Description'}</label>
                        <p class="bg-light p-3 rounded-3" style="font-size: 0.9rem; word-wrap: break-word; overflow-wrap: break-word; white-space: pre-line;">${v.description}</p>
                    </div>
                </div>
            `;
            $('#voucherDetailContent').html(html);
            $('#viewVoucherModal').modal('show');
        } else {
            Swal.fire('Error', r.message, 'error');
        }
    });
}

// ── Mobile card rendering — called by DataTable drawCallback after every AJAX draw ──
function renderPettyCashCards(api) {
    var $wrapper = $('#pettyCashCardsWrapper');
    var $empty   = $('#pettyCashCardsEmptyState');
    $wrapper.find('.vk-member-card').remove();

    var rows = api.rows({ page: 'current' }).data();
    if (rows.length === 0) { $empty.removeClass('d-none'); return; }
    $empty.addClass('d-none');

    var badgeMap = { pending: 'bg-warning text-dark', approved: 'bg-success text-white', rejected: 'bg-danger text-white' };
    var labelEn  = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' };
    var labelSw  = { pending: 'Inasubiri', approved: 'Imeidhinishwa', rejected: 'Imekataliwa' };

    var html = '';
    rows.each(function(d) {
        var status = d.raw_status || 'pending';
        var badge  = badgeMap[status] || 'bg-secondary text-white';
        var label  = (isSwahili ? labelSw : labelEn)[status] || status;
        var id     = parseInt(d.raw_id);
        var avatar = vkEscape((d.raw_payee || 'P').charAt(0).toUpperCase());
        var amount = parseFloat(d.raw_amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });

        var pendingActions = status === 'pending' ? `
            <button class="btn btn-sm btn-outline-warning vk-btn-action" onclick="editVoucher(${id})" title="${isSwahili ? 'Hariri' : 'Edit'}">
                <i class="bi bi-pencil-fill"></i>
            </button>
            <button class="btn btn-sm btn-outline-success vk-btn-action" onclick="approveVoucher(${id})" title="${isSwahili ? 'Idhinisha' : 'Approve'}">
                <i class="bi bi-check-circle-fill"></i>
            </button>` : '';

        html += `
        <div class="vk-member-card">
            <div class="vk-card-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="vk-card-avatar" style="background:linear-gradient(135deg,#6f42c1,#5a32a3);">${avatar}</div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="fw-bold text-dark lh-sm mb-1">${vkEscape(d.raw_payee)}</div>
                        <small class="text-muted">${vkEscape(d.raw_voucher_no)} &middot; ${vkEscape(d.raw_category)}</small>
                    </div>
                    <span class="badge rounded-pill ${badge} px-3">${label}</span>
                </div>
            </div>
            <div class="vk-card-body">
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSwahili ? 'Tarehe' : 'Date'}</span>
                    <span class="vk-card-value">${vkEscape(d.date)}</span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSwahili ? 'Maelezo' : 'Description'}</span>
                    <span class="vk-card-value">${vkEscape(d.raw_description)}</span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSwahili ? 'Kiasi' : 'Amount'}</span>
                    <span class="vk-card-value fw-bold text-success">TSh ${amount}</span>
                </div>
            </div>
            <div class="vk-card-actions">
                <button class="btn btn-sm btn-outline-primary vk-btn-action" onclick="viewVoucher(${id})" title="${isSwahili ? 'Tazama' : 'View'}">
                    <i class="bi bi-eye-fill"></i>
                </button>
                ${pendingActions}
                <button class="btn btn-sm btn-outline-secondary vk-btn-action" onclick="printVoucher(${id})" title="${isSwahili ? 'Chapa' : 'Print'}">
                    <i class="bi bi-printer-fill"></i>
                </button>
                <button class="btn btn-sm btn-outline-danger vk-btn-action" onclick="deleteVoucher(${id})" title="${isSwahili ? 'Futa' : 'Delete'}">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </div>
        </div>`;
    });

    $wrapper.prepend(html);
}

function vkEscape(s) {
    if (s === null || s === undefined) return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once FOOTER_FILE; ?>
