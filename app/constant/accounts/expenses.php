<?php
/**
 * Expenses Management (Main View - Death Assistance)
 * Professional Print Layout with Base64 Branding, Stats Cards, and Persistent Footer
 */
ob_start();
global $pdo, $pdo_accounts;

require_once __DIR__ . '/../../../roots.php';

includeHeader();

// 1. PRE-FETCH BRANDING (Base64 Logo Logic from budget.php)
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
$user_role = $u_data['role_name'] ?? 'Staff';

// Permission check
requireViewPermission('death_expenses');
$can_create_death = canCreate('death_expenses');
$can_edit_death = canEdit('death_expenses');
$can_delete_death = canDelete('death_expenses');

$lang = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');

$title = $is_sw ? 'Misaada ya Misiba' : 'Death Assistance Expenses';
$subtitle = $is_sw ? 'Rekodi na dhibiti misaada kwa wanachama waliofiwa' : 'Record and manage assistance for bereaved members';
?>

<div class="container-fluid py-4" id="main-content" style="background-color: #f8f9fa; min-height: 90vh; overflow-x: hidden;">
    <!-- 1. PRINT HEADER (Visible only during print) -->
    <div class="d-none d-print-block print-header mb-4 text-center">
        <div class="border-bottom pb-4">
            <div class="mb-3">
                <img src="<?= !empty($logo_base64) ? $logo_base64 : getUrl('assets/images/') . $group_logo ?>" alt="Logo" style="height: 100px; width: auto; object-fit: contain;">
            </div>
            <h2 class="fw-bold mb-1" style="color: #0d6efd !important; text-transform: uppercase;">
                <?= htmlspecialchars($group_name) ?>
            </h2>
            <h3 class="fw-bold mb-2 text-dark" style="text-transform: uppercase;"><?= $title ?></h3>
            <p class="mb-0 small text-muted text-uppercase"><?= $is_sw ? 'Ripoti Rasmi ya Mfumo' : 'Official System Report' ?></p>
        </div>
    </div>

    <!-- UI Heading (Hidden during print) -->
    <div class="row mb-4 no-print">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-3 p-md-4 bg-white text-center text-md-start">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                        <div>
                            <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-heart-pulse-fill me-2"></i><?= $title ?></h3>
                            <p class="text-muted mb-0 small"><?= $subtitle ?></p>
                        </div>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <a href="<?= getUrl('other_expenses') ?>" class="btn btn-outline-primary rounded-pill px-3 px-md-4 shadow-sm text-dark fw-bold border-0 btn-sm">
                                <i class="bi bi-wallet2 me-2"></i> <?= $is_sw ? 'Matumizi Mengineyo' : 'Other Expenses' ?>
                            </a>
                            <button type="button" class="btn btn-primary d-flex align-items-center rounded-pill px-3 px-md-4 shadow-sm btn-sm" data-bs-toggle="modal" data-bs-target="#recordDeathModal">
                                <i class="bi bi-plus-lg me-2"></i> <?= $is_sw ? 'Rekodi Msiba Mpya' : 'Record New Death' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards Row (ALWAYS Visible, even during print) -->
    <div class="row g-2 mb-3 print-stats-row">
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100" style="background-color: #d1e7dd !important;">
                <div class="card-body p-2 text-center">
                    <h6 class="text-dark mb-1" style="font-size: 8pt; font-weight: bold; text-transform: uppercase;"><?= $is_sw ? 'Jumla ya Misaada' : 'Total Assistance' ?></h6>
                    <h5 class="fw-bold mb-0 text-dark" id="total_payouts" style="font-size: 11pt;">0.00</h5>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100" style="background-color: #d1e7dd !important;">
                <div class="card-body p-2 text-center">
                    <h6 class="text-dark mb-1" style="font-size: 8pt; font-weight: bold; text-transform: uppercase;"><?= $is_sw ? 'Idadi ya Misiba' : 'Death Cases' ?></h6>
                    <h5 class="fw-bold mb-0 text-dark" id="death_count" style="font-size: 11pt;">0</h5>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card border-0 shadow-sm h-100" style="background-color: #d1e7dd !important;">
                <div class="card-body p-2 text-center">
                    <h6 class="text-dark mb-1" style="font-size: 8pt; font-weight: bold; text-transform: uppercase;"><?= $is_sw ? 'Mwezi Huu' : 'Cases This Month' ?></h6>
                    <h5 class="fw-bold mb-0 text-dark" id="month_payouts" style="font-size: 11pt;">0</h5>
                </div>
            </div>
        </div>
    </div>

    <!-- Records Table -->
    <div class="card border-0 shadow-sm overflow-hidden bg-white">
        <div class="card-header py-3 border-bottom border-light d-flex flex-row justify-content-between align-items-center gap-2 bg-white no-print">
            <div class="d-flex gap-2 align-items-center">
                <button onclick="printAndLog()" class="btn btn-outline-secondary btn-sm shadow-sm rounded-3 px-3">
                    <i class="bi bi-printer me-1"></i> <?= $is_sw ? 'Chapa' : 'Print' ?>
                </button>
                <button onclick="logAndExport()" class="btn btn-outline-secondary btn-sm shadow-sm rounded-3 px-3">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> <?= $is_sw ? 'Pakua' : 'Export' ?>
                </button>
                <div id="lenContainer" class="ms-1"></div>
            </div>
            <h6 class="fw-bold mb-0 text-dark d-none d-md-block"><?= $is_sw ? 'Ripoti ya Misiba' : 'Death Report' ?></h6>
        </div>
        <div class="card-body p-0 d-none d-md-block d-print-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="deathExpensesTable" style="width: 100%;">
                    <thead class="bg-light text-muted small uppercase text-center">
                        <tr>
                            <th style="width:50px"><?= $is_sw ? 'Nambari' : 'S/No' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $is_sw ? 'Mwanachama' : 'Member' ?></th>
                            <th><?= $is_sw ? 'Simu' : 'Phone' ?></th>
                            <th><?= $is_sw ? 'Aliyefariki' : 'Deceased' ?></th>
                            <th><?= $is_sw ? 'Uhusiano' : 'Rel' ?></th>
                            <th><?= $is_sw ? 'Kiasi (Tsh)' : 'Amount (Tsh)' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th class="text-end pe-4 no-print"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small"></tbody>
                    <tfoot class="d-none d-print-table-footer">
                        <tr>
                            <td colspan="9" style="height: 2.8cm; border: none !important;">&nbsp;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <!-- ═══ CARD VIEW — Mobile Only (server-side; rendered by drawCallback) ═══ -->
        <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="deathCardsWrapper">
            <div id="deathCardsEmptyState" class="d-none text-center py-5">
                <i class="bi bi-heart-pulse fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0"><?= $is_sw ? 'Hakuna rekodi zilizopatikana.' : 'No records found.' ?></p>
            </div>
        </div>
    </div>

    <!-- 4. PRINT FOOTER (Persistent on every page during print) -->
    <div class="d-none d-print-block print-footer" style="position: fixed; bottom: 5mm; width: 100%; left: 0; background: white;">
        <div class="container-fluid">
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
</div>

<!-- Record Death Modal -->
<div class="modal fade" id="recordDeathModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle-fill me-2"></i> <?= $is_sw ? 'Rekodi Msiba Mpya' : 'Record New Death' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="recordDeathForm">
                <div class="modal-body p-4 bg-white">
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Tafuta Mwanachama *' : 'Search Member *' ?></label>
                            <select class="form-select select2-member" name="member_id" required style="width: 100%;"></select>
                        </div>
                        <div class="col-md-12" id="dependent_container" style="display: none;">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Chagua Nani Amefariki? *' : 'Who Passed Away? *' ?></label>
                            <div class="list-group shadow-sm" id="dependent_list"></div>
                            <input type="hidden" name="deceased_info" id="selected_deceased">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Kiasi cha Msaada *' : 'Assistance Amount *' ?></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">Tsh</span>
                                <input type="number" class="form-control" name="amount" required step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Tarehe' : 'Date' ?></label>
                            <input type="date" class="form-control" name="expense_date" value="<?= date('Y-m-d') ?>" readonly>
                        </div>
                        <div class="col-12 mt-3 mb-2">
                             <label class="form-label fw-bold small text-muted d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-paperclip me-1"></i><?= $is_sw ? 'Supporting Documents (Death Cert, etc)' : 'Supporting Documents (Death Cert, etc)' ?></span>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2" id="add_attachment_btn" style="font-size: 0.75rem;">
                                    <i class="bi bi-plus-circle me-1"></i> Add Another
                                </button>
                            </label>
                            <div id="attachments_wrapper">
                                <div class="attachment-row row g-2 mb-2 bg-light p-2 rounded-3 border-dashed" style="border: 1px dashed #dee2e6;">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm border-0" name="attachment_names[]" placeholder="Doc name (e.g. Death cert)">
                                    </div>
                                    <div class="col-md-7">
                                        <input type="file" class="form-control form-control-sm border-0 bg-white" name="attachments[]">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted">Remarks</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Any additional remarks..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4 border-0">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-5 rounded-pill shadow-sm"><?= $is_sw ? 'Hifadhi' : 'Save' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Death Details Modal (RESTORED TO ORIGINAL LAYOUT FROM SCREENSHOT) -->
<div class="modal fade" id="viewDeathDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-info-circle-fill me-2"></i> Death Assistance Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white">
                <div class="row g-4">
                    <!-- Left Column -->
                    <div class="col-md-5">
                        <div class="p-3 bg-light rounded-3 mb-3">
                            <h6 class="text-muted small uppercase fw-bold mb-3">DEATH DETAILS</h6>
                            <div id="death_highlights"></div>
                        </div>
                        <div class="p-3 h-100">
                            <h6 class="text-muted small uppercase fw-bold mb-3">LOSS HISTORY</h6>
                            <div id="loss_history" class="small text-danger fw-bold"></div>
                        </div>
                    </div>
                    <!-- Right Column -->
                    <div class="col-md-7">
                        <h6 class="text-muted small uppercase fw-bold mb-3">CURRENTLY REMAINING DEPENDENTS</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle small">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Relationship</th>
                                    </tr>
                                </thead>
                                <tbody id="remaining_dependents_table"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light p-3 border-0">
                <button type="button" class="btn btn-secondary px-4 shadow-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.uppercase { text-transform: uppercase; letter-spacing: 0.5px; }
.card { border-radius: 12px; }

@media (max-width: 768px) {
    .container-fluid { padding: 10px !important; }
}

@media print {
    /* Page Configuration */
    @page { 
        size: auto; 
        margin: 15mm !important;
        margin-bottom: 30mm !important; /* Professional margin for footer */
    }

    /* Visibility Controls */
    .no-print, .modal, .dataTables_length, .dataTables_filter, .dataTables_info, .dataTables_paginate, nav, header, .navbar, .header-wrapper, .sidebar-wrapper, .main-footer { display: none !important; }
    
    /* Layout Reset (CRITICAL: Overflow MUST be visible for print) */
    body { background-color: white !important; margin: 0 !important; padding-bottom: 30mm !important; overflow: visible !important; }
    .container-fluid { padding: 0 !important; max-width: 100% !important; margin: 0 !important; width: 100% !important; overflow: visible !important; }
    .card, .table-responsive { page-break-inside: auto !important; border: none !important; box-shadow: none !important; overflow: visible !important; }
    
    /* Stats Cards in Print */
    .print-stats-row { display: flex !important; flex-direction: row !important; flex-wrap: nowrap !important; gap: 8px !important; margin-bottom: 10px !important; }
    .print-stats-row .col-4 { flex: 1 !important; max-width: 33.33% !important; width: 33.33% !important;}
    .print-stats-row .card { border: 1px solid #dee2e6 !important; background-color: #f8f9fa !important; box-shadow: none !important; }
    
    .print-header { display: block !important; margin-top: 0 !important; margin-bottom: 10px !important; }
    .print-header .border-bottom { padding-bottom: 10px !important; }
    .print-footer { display: block !important; }
    .table { width: 100% !important; border-collapse: collapse !important; margin-top: 5px !important; }
    .d-print-table-footer { display: table-footer-group !important; }
    .table thead th { text-align: center !important; vertical-align: middle !important; background-color: #f8f9fa !important; border: 1px solid #dee2e6 !important; }
    .table th, .table td { border: 1px solid #dee2e6 !important; padding: 4px 6px !important; word-wrap: break-word !important; }
    tr { page-break-inside: avoid !important; }
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
}
</style>

<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;
$(document).ready(function() {
    $('.select2-member').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#recordDeathModal'),
        ajax: {
            url: '<?= getUrl("api/search_members_with_phone") ?>',
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data.results }),
            cache: true
        }
    });

    $('.select2-member').on('change', function() {
        const memberId = $(this).val();
        if (!memberId) return;
        $('#dependent_list').html('<div class="p-3 text-center"><span class="spinner-border spinner-border-sm text-danger me-2"></span> Loading...</div>');
        $('#dependent_container').show();
        $.get('<?= getUrl("api/get_member_dependents") ?>', { member_id: memberId }, function(res) {
            if (res.success) {
                let html = '';
                res.dependents.forEach(d => {
                    html += `<button type="button" class="list-group-item list-group-item-action py-3 px-4 border-bottom" onclick="selectDeceased(event, '${d.type}', '${d.id}', '${d.name}', '${d.relationship}')">
                        <h6 class="mb-0 fw-bold text-dark">${d.name}</h6><small class="text-muted">${d.relationship}</small>
                    </button>`;
                });
                $('#dependent_list').html(html || '<div class="p-3 text-center text-danger">Hakuna tegemezi waliobaki</div>');
            }
        });
    });

    const table = $('#deathExpensesTable').DataTable({
        responsive: true, serverSide: true, processing: true,
        ajax: {
            url: '<?= getUrl("api/get_death_expenses") ?>',
            dataSrc: json => {
                $('#total_payouts').text(parseFloat(json.totalAmount).toLocaleString('en-US', {minimumFractionDigits: 2}));
                $('#death_count').text(json.recordsTotal);
                $('#month_payouts').text(json.monthTotal);
                return json.data;
            }
        },
        columns: [
            { data: null, orderable: false, searchable: false, className: 'ps-4', render: (d, t, r, m) => m.row + 1 },
            { data: 'expense_date' },
            { data: 'member_name', render: d => `<strong>${d}</strong>` },
            { data: 'phone_number', render: d => `<span class="badge bg-light text-dark border">${d}</span>` },
            { data: 'deceased_name' },
            { data: 'deceased_relationship' },
            { data: 'amount', render: d => `<strong class="text-danger">${parseFloat(d).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong>` },
            { data: 'status', render: d => `<span class="badge bg-${d==='approved'?'success':'warning'}">${d==='approved'?(isSw?'Imeidhinishwa':'Approved'):(isSw?'Inasubiri':'Pending')}</span>` },
            {
                data: null, className: 'text-end no-print',
                render: (d, t, r) => `
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm rounded-1 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                             <li><a class="dropdown-item py-2 fw-bold text-primary" href="javascript:void(0)" onclick="viewDeathDetails(${r.id}, ${r.member_id})"><i class="bi bi-eye-fill me-2"></i> View</a></li>
                            ${r.status==='pending' ? `<li><a class="dropdown-item py-2 fw-bold text-success" href="javascript:void(0)" onclick="approveDeathExpense(${r.id})"><i class="bi bi-check-circle-fill me-2"></i> Approve</a></li>` : ''}
                            <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteDeathExpense(${r.id})"><i class="bi bi-trash-fill me-2"></i> Delete</a></li>
                        </ul>
                    </div>
                `
            }
        ],
        dom: 'lrtp',
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "<?= $is_sw ? 'Zote' : 'All' ?>"]],
        language: {
            lengthMenu: "_MENU_",
            zeroRecords: isSw ? "Hakuna data" : "No records found"
        },
        drawCallback: function() { renderDeathCards(this.api()); },
        initComplete: function() { $('.dataTables_length').appendTo('#lenContainer'); }
    });

    $('#add_attachment_btn').on('click', function() {
        const row = `<div class="attachment-row row g-2 mb-2 bg-light p-2 rounded-3" style="border: 1px dashed #dee2e6;">
                        <div class="col-md-5"><input type="text" class="form-control form-control-sm border-0" name="attachment_names[]" placeholder="Doc name"></div>
                        <div class="col-md-6"><input type="file" class="form-control form-control-sm border-0 bg-white" name="attachments[]"></div>
                        <div class="col-md-1 text-end"><button type="button" class="btn btn-sm text-danger remove-attachment p-0"><i class="bi bi-x-circle-fill"></i></button></div>
                    </div>`;
        $('#attachments_wrapper').append(row);
    });
    $(document).on('click', '.remove-attachment', function() { $(this).closest('.attachment-row').remove(); });

    $('#recordDeathForm').on('submit', function(e) {
        e.preventDefault();
        
        // Manual validation for the hidden field
        if (!$('#selected_deceased').val()) {
            Swal.fire(isSw ? 'Makosa' : 'Validation Error', isSw ? 'Tafadhali chagua aliyefariki kwanza!' : 'Please select the deceased person first!', 'warning');
            return;
        }

        const btn = $(this).find('button[submit]'); // Try to find btn
        const saveBtn = $(this).find('button[type="submit"]');
        const oldText = saveBtn.html();
        saveBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + (isSw ? 'Inahifadhi...' : 'Saving...'));

        $.ajax({
            url: '<?= getUrl("actions/process_death_expense") ?>',
            type: 'POST',
            data: new FormData(this),
            processData: false, contentType: false,
            success: response => {
                if (response.success) { 
                    Swal.fire(isSw ? 'Hongera' : 'Success', response.message, 'success'); 
                    $('#recordDeathModal').modal('hide'); 
                    $('#deathExpensesTable').DataTable().ajax.reload(); 
                    $(this)[0].reset();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: () => { Swal.fire('Error', 'Server connection failed', 'error'); },
            complete: () => { saveBtn.prop('disabled', false).html(oldText); }
        });
    });
});

function selectDeceased(e, type, id, name, rel) {
    $('#selected_deceased').val(`${type}|${id}|${name}|${rel}`);
    $('.list-group-item').removeClass('active bg-primary text-white');
    $(e.currentTarget).addClass('active bg-primary text-white');
}

function printAndLog() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-GB', { hour12: false });
    const timeSpan = document.getElementById('print_time_js');
    if (timeSpan) timeSpan.innerText = timeStr;
    $.post('<?= getUrl("api/log_action") ?>', { action: 'Printed', module: 'Expenses', description: 'Printed report' }).always(() => { window.print(); });
}

function viewDeathDetails(id, memberId) {
    const table = $('#deathExpensesTable').DataTable();
    const rowData = table.rows().data().toArray().find(r => r.id == id);
    if (!rowData) return;

    // Highlights (Left Card)
    $('#death_highlights').html(`
        <div class="mb-2 text-muted">Deceased: <strong class="text-primary">${rowData.deceased_relationship} (${rowData.deceased_name})</strong></div>
        <div class="mb-2 text-muted">Amount: <strong class="text-dark">Tsh ${parseFloat(rowData.amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></div>
        <div class="small text-muted"><i class="bi bi-info-circle me-1"></i> ${rowData.description || '-'}</div>
    `);

    $('#viewDeathDetailsModal').modal('show');
    $('#remaining_dependents_table').html('<tr><td colspan="2" class="text-center font-italic">Loading...</td></tr>');

    // Fetch History & Remaining
    $.get('<?= getUrl("api/get_member_death_history") ?>', { member_id: memberId }, res => {
        if (res.success && res.history.length > 0) {
            $('#loss_history').html(`Already lost: ${res.history.join(', ')}`);
        } else {
            $('#loss_history').html('No other losses recorded.');
        }
    });

    $.get('<?= getUrl("api/get_member_dependents") ?>', { member_id: memberId }, res => {
        if (res.success) {
            let html = '';
            res.dependents.forEach(d => {
                if (d.name !== rowData.deceased_name) {
                    html += `<tr><td class="fw-bold text-dark">${d.name}</td><td><span class="badge bg-light text-muted border">${d.relationship}</span></td></tr>`;
                }
            });
            $('#remaining_dependents_table').html(html || '<tr><td colspan="2" class="text-center">No more dependents.</td></tr>');
        }
    });
}

function approveDeathExpense(id) {
    Swal.fire({ title: 'Approve?', icon: 'warning', showCancelButton: true }).then(result => {
        if (result.isConfirmed) { $.post('<?= getUrl("actions/approve_death_expense") ?>', { id: id }, res => { if (res.success) { Swal.fire('Approved!', '', 'success'); $('#deathExpensesTable').DataTable().ajax.reload(); } }); }
    });
}
function deleteDeathExpense(id) {
    Swal.fire({ 
        title: isSw ? 'Futa?' : 'Delete?', 
        text: isSw ? 'Hutaweza kurudisha kumbukumbu hii!' : 'You will not be able to recover this!',
        icon: 'warning', 
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: isSw ? 'Ndio, Futa!' : 'Yes, Delete!',
        cancelButtonText: isSw ? 'Ghairi' : 'Cancel'
    }).then(result => {
        if (result.isConfirmed) { 
            $.post('<?= getUrl("actions/delete_death_expense") ?>', { id: id }, res => { 
                if (res.success) { 
                    Swal.fire(isSw ? 'Imefutwa!' : 'Deleted!', isSw ? 'Kumbukumbu imefutwa.' : 'Record deleted.', 'success'); 
                    $('#deathExpensesTable').DataTable().ajax.reload(); 
                } else {
                    Swal.fire(isSw ? 'Imeshindwa' : 'Error', res.message, 'error');
                }
            }); 
        }
    });
}
function vkEscape(s) {
    if (s === null || s === undefined) return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderDeathCards(api) {
    var $wrapper = $('#deathCardsWrapper');
    var $empty   = $('#deathCardsEmptyState');
    $wrapper.find('.vk-member-card').remove();
    var rows = api.rows({ page: 'current' }).data();
    if (rows.length === 0) { $empty.removeClass('d-none'); return; }
    $empty.addClass('d-none');
    var html = '';
    rows.each(function(d) {
        var status   = d.status || 'pending';
        var id       = parseInt(d.id);
        var memberId = parseInt(d.member_id);
        var avatar   = vkEscape((d.member_name || 'M').charAt(0).toUpperCase());
        var amount   = parseFloat(d.amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2 });
        var badge    = status === 'approved' ? 'bg-success text-white' : 'bg-warning text-dark';
        var badgeLbl = status === 'approved' ? (isSw ? 'Imeidhinishwa' : 'Approved') : (isSw ? 'Inasubiri' : 'Pending');
        var approveBtn = status === 'pending' ? `
            <button class="btn btn-sm btn-success vk-btn-action" onclick="approveDeathExpense(${id})" title="${isSw ? 'Idhinisha' : 'Approve'}">
                <i class="bi bi-check-circle-fill"></i>
            </button>` : '';
        html += `<div class="vk-member-card">
            <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="vk-card-avatar" style="background:linear-gradient(135deg,#dc3545,#b02a37);">${avatar}</div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="fw-bold text-dark lh-sm" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${vkEscape(d.member_name)}</div>
                        <small class="text-muted">${vkEscape(d.expense_date)}</small>
                    </div>
                </div>
                <span class="badge ${badge} rounded-pill px-2" style="font-size:10px;">${badgeLbl}</span>
            </div>
            <div class="vk-card-body">
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Simu' : 'Phone'}</span>
                    <span class="vk-card-value"><span class="badge bg-light text-dark border">${vkEscape(d.phone_number)}</span></span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Aliyefariki' : 'Deceased'}</span>
                    <span class="vk-card-value">${vkEscape(d.deceased_name)}</span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Uhusiano' : 'Relation'}</span>
                    <span class="vk-card-value">${vkEscape(d.deceased_relationship)}</span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Kiasi' : 'Amount'}</span>
                    <span class="vk-card-value fw-bold text-danger">${amount}</span>
                </div>
            </div>
            <div class="vk-card-actions">
                <button class="btn btn-sm btn-primary vk-btn-action" onclick="viewDeathDetails(${id},${memberId})" title="${isSw ? 'Tazama' : 'View'}">
                    <i class="bi bi-eye-fill"></i>
                </button>
                ${approveBtn}
                <button class="btn btn-sm btn-danger vk-btn-action" onclick="deleteDeathExpense(${id})" title="${isSw ? 'Futa' : 'Delete'}">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </div>
        </div>`;
    });
    $wrapper.prepend(html);
}

function logAndExport() {
    const table = $('#deathExpensesTable').DataTable();
    const data = table.rows().data().toArray();
    let csv = [];

    // Headers
    const headers = [
        isSw ? 'Nambari' : 'S/No',
        isSw ? 'Tarehe' : 'Date',
        isSw ? 'Mwanachama' : 'Member',
        isSw ? 'Simu' : 'Phone',
        isSw ? 'Aliyefariki' : 'Deceased',
        isSw ? 'Uhusiano' : 'Rel',
        isSw ? 'Kiasi' : 'Amount',
        isSw ? 'Hali' : 'Status'
    ];
    csv.push(headers.join(','));

    // Rows
    data.forEach((r, i) => {
        const row = [
            i + 1,
            r.expense_date,
            `"${r.member_name}"`,
            r.phone_number,
            `"${r.deceased_name}"`,
            r.deceased_relationship,
            r.amount,
            r.status
        ];
        csv.push(row.join(','));
    });

    const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", `Death_Expenses_Report_${new Date().toISOString().slice(0,10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php includeFooter(); ob_end_flush(); ?>