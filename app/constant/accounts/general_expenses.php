<?php
// File: app/constant/accounts/general_expenses.php
// Copied from original expenses.php
ob_start();
global $pdo, $pdo_accounts;
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Permission check
requireViewPermission('expenses');
$can_create_expense = canCreate('expenses');
$can_edit_expense = canEdit('expenses');
$can_delete_expense = canDelete('expenses');
?>

<div class="container-fluid py-4" style="background-color: #f8f9fa; min-height: 90vh; overflow-x: hidden;">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-3 p-md-4 bg-white">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                        <div class="text-center text-md-start">
                            <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-cart-dash-fill me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Matumizi Mengineyo (General)' : 'General Expenses' ?></h3>
                            <p class="text-muted mb-0 small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Simamia na urekodi matumizi mengineyo ya kikundi' : 'Manage and record other group expenses' ?></p>
                        </div>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <a href="<?= getUrl('expenses') ?>" class="btn btn-outline-primary rounded-pill px-3 px-md-4 shadow-sm text-dark fw-bold border-0 btn-sm btn-md-base">
                                <i class="bi bi-arrow-left me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Misaada ya Misiba' : 'Death Expenses' ?>
                            </a>
                            <button type="button" class="btn btn-primary d-flex align-items-center rounded-pill px-3 px-md-4 shadow-sm btn-sm btn-md-base" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                                <i class="bi bi-plus-lg me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rekodi Matumizi' : 'Add Expense' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold text-primary" id="stat-total-expenses">0.00</h4>
                            <p class="mb-0 text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla ya Matumizi' : 'Total Expenses' ?></p>
                        </div>
                        <div class="align-self-center text-primary-light">
                            <i class="bi bi-cash-stack" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold text-success" id="stat-month-total">0.00</h4>
                            <p class="mb-0 text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwezi Huu' : 'This Month' ?></p>
                        </div>
                        <div class="align-self-center text-success-light">
                            <i class="bi bi-calendar-month" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold text-info" id="stat-year-total">0.00</h4>
                            <p class="mb-0 text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwaka Huu' : 'This Year' ?></p>
                        </div>
                        <div class="align-self-center text-info-light">
                            <i class="bi bi-calendar-year" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold text-dark" id="stat-total-records">0</h4>
                            <p class="mb-0 text-muted small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idadi ya Kumbukumbu' : 'Total Records' ?></p>
                        </div>
                        <div class="align-self-center text-secondary-light">
                            <i class="bi bi-receipt" style="font-size: 2rem; opacity: 0.3;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-funnel me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Vichujio na Utafutaji' : 'Filters & Search' ?></h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali (Status)' : 'Status' ?></label>
                    <select class="form-select" id="statusFilter">
                        <option value=""><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali Zote' : 'All Status' ?></option>
                        <option value="pending"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inasubiri' : 'Pending' ?></option>
                        <option value="approved"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imeidhinishwa' : 'Approved' ?></option>
                        <option value="rejected"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imekataliwa' : 'Rejected' ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kuanzia Tarehe' : 'Date From' ?></label>
                    <input type="date" class="form-control" id="dateFromFilter">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hadi Tarehe' : 'Date To' ?></label>
                    <input type="date" class="form-control" id="dateToFilter">
                </div>
                <div class="col-md-12 d-flex justify-content-end border-top pt-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Suka Upya' : 'Clear' ?>
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="applyFilters()">
                        <i class="bi bi-filter"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tumia Vichujio' : 'Apply Filters' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Expenses Table Card -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom border-light">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                <div class="d-flex flex-wrap align-items-center justify-content-center gap-2">
                    <button type="button" class="btn btn-white border shadow-sm btn-sm rounded-pill px-3 fw-bold" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chapa' : 'Print' ?>
                    </button>
                    <button type="button" class="btn btn-white border shadow-sm btn-sm rounded-pill px-3 fw-bold" onclick="exportExpenses()">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakua' : 'Export' ?>
                    </button>
                    <div id="lenContainer" class="ms-md-2"></div>
                </div>
                <div class="d-flex align-items-center justify-content-center">
                    <h6 class="mb-0 fw-bold text-dark d-none d-lg-block me-3"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kumbukumbu za Matumizi' : 'Expense Records' ?></h6>
                    <span class="badge bg-primary-subtle text-primary py-2 px-3 border border-primary-subtle rounded-pill" id="stat-total-records-badge">
                        0 <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'kumbukumbu' : 'records' ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive d-none d-md-block d-print-block">
                <table id="expensesTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase text-center">
                        <tr>
                            <th style="width:50px"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nambari' : 'S/No' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tarehe' : 'Date' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maelezo' : 'Description' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi' : 'Amount' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali' : 'Status' ?></th>
                            <th class="text-end no-print"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small"></tbody>
                </table>
            </div>

            <!-- Mobile Card View -->
            <div class="p-3 d-md-none vk-cards-wrapper" id="expensesCardsWrapper">
                <div id="expensesCardsEmptyState" class="d-none text-center py-5">
                    <i class="bi bi-wallet2 fs-1 text-muted d-block mb-3"></i>
                    <p class="text-muted mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna rekodi zilizopatikana.' : 'No records found.' ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Section (Same as expenses.php) -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title" id="addExpenseModalLabel">
                    <i class="bi bi-plus-circle me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ongeza Matumizi Mapya' : 'Add New Expense' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addExpenseForm">
                <div class="modal-body p-4 bg-light-subtle">
                    <div id="add-expense-message" class="mb-3"></div>
                    <div class="row g-3">
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tarehe ya Matumizi' : 'Expense Date' ?> <span class="text-danger">*</span></label>
                            <input type="date" class="form-control shadow-sm border-0" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label small fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi' : 'Amount' ?> <span class="text-danger">*</span></label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text border-0 bg-white">TZS</span>
                                <input type="number" class="form-control border-0" name="amount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                        
                        <!-- Attachments Section -->
                        <div class="col-12 mb-3">
                            <label class="form-label small fw-bold text-dark d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-paperclip me-1"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyaraka za Ambatisho (Risiti, n.k)' : 'Supporting Documents (Receipts, etc)' ?></span>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 px-2" id="add_attachment_btn" style="font-size: 0.75rem;">
                                    <i class="bi bi-plus-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ongeza Nyingine' : 'Add Another' ?>
                                </button>
                            </label>
                            <div id="attachments_wrapper">
                                <div class="attachment-row row g-2 mb-2 bg-white p-2 rounded-3 border-dashed">
                                    <div class="col-md-5">
                                        <input type="text" class="form-control form-control-sm border-0 bg-light" name="attachment_names[]" placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la hati (mfano: Risiti)' : 'Doc name (e.g. Receipt)' ?>">
                                    </div>
                                    <div class="col-md-7">
                                        <input type="file" class="form-control form-control-sm border-0" name="attachments[]">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 mb-3">
                            <label class="form-label small fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maelezo / Matumizi ya nini?' : 'Description / What was this for?' ?> <span class="text-danger">*</span></label>
                            <textarea class="form-control shadow-sm border-0" name="description" rows="3" required placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Andika matumizi haya yalikuwa ya nini...' : 'What was this expense for?' ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm rounded-pill px-4" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm rounded-pill px-4 shadow-sm">
                        <i class="bi bi-check-circle me-1"></i> <span id="btnText"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Matumizi' : 'Save Expense' ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const isSw = <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'true' : 'false' ?>;

$(document).ready(function() {
    
    const table = $('#expensesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '/api/get_general_expenses',
            data: d => {
                d.status = $('#statusFilter').val();
                d.date_from = $('#dateFromFilter').val();
                d.date_to = $('#dateToFilter').val();
            },
            dataSrc: json => {
                $('#stat-total-expenses').text(formatCurrency(json.totalExpenses));
                $('#stat-month-total').text(formatCurrency(json.monthTotal));
                $('#stat-year-total').text(formatCurrency(json.yearTotal));
                $('#stat-total-records').text(json.recordsTotal);
                $('#stat-total-records-badge').text(json.recordsTotal + ' ' + (isSw ? 'kumbukumbu' : 'records'));
                return json.data;
            }
        },
        columns: [
            { data: null, render: (d, t, r, m) => `<strong>${m.row + 1}</strong>` },
            { data: 'expense_date', render: d => `<strong>${new Date(d).toLocaleDateString()}</strong>` },
            { data: 'description' },
            { data: 'amount', render: d => `<strong class="text-danger">${formatCurrency(d)}</strong>` },
            { data: 'status', render: d => `<span class="badge bg-${getStatusBadgeClass(d)}">${d.toUpperCase()}</span>` },
            {
                data: null, className: 'text-end',
                render: (d, t, r) => `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            ${r.status==='pending'?`<li><a class="dropdown-item" href="javascript:void(0)" onclick="approveGeneralExpense(${r.id})"><i class="bi bi-check-circle text-success"></i> ${isSw?'Idhinisha':'Approve'}</a></li>`:''}
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="confirmDeleteGeneralExpense(${r.id})"><i class="bi bi-trash"></i> ${isSw?'Futa':'Delete'}</a></li>
                        </ul>
                    </div>
                `
            }
        ],
        drawCallback: function() { renderExpenseCards(this.api()); },
        dom: 'lrtp',
        initComplete: function() {
            $('.dataTables_length').appendTo('#lenContainer');
            $('.dataTables_length select').addClass('form-select-sm border-0 shadow-sm bg-white').css('width', 'auto');
        },
        language: {
            lengthMenu: isSw ? "Onyesha _MENU_" : "Show _MENU_",
            paginate: { next: '>', previous: '<' },
            processing: isSw ? "Inachakata..." : "Processing..."
        }
    });

    $('#add_attachment_btn').on('click', function() {
        const row = `
            <div class="attachment-row row g-2 mb-2 bg-white p-2 rounded-3 border-dashed">
                <div class="col-md-5">
                    <input type="text" class="form-control form-control-sm border-0 bg-light" name="attachment_names[]" placeholder="${isSw ? 'Jina la hati' : 'Doc name'}">
                </div>
                <div class="col-md-6">
                    <input type="file" class="form-control form-control-sm border-0" name="attachments[]">
                </div>
                <div class="col-md-1 text-end">
                    <button type="button" class="btn btn-sm text-danger remove-attachment p-0 m-0"><i class="bi bi-x-circle-fill"></i></button>
                </div>
            </div>
        `;
        $('#attachments_wrapper').append(row);
    });

    $(document).on('click', '.remove-attachment', function() {
        $(this).closest('.attachment-row').remove();
    });

    $('#addExpenseForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        const originalHtml = $btn.html();
        
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Saving...');
        
        const formData = new FormData(this);
        
        $.ajax({
            url: '/api/add_general_expense',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: response => {
                if (response.success) {
                    Swal.fire(isSw?'Imefanikiwa':'Success', response.message, 'success');
                    $('#addExpenseModal').modal('hide');
                    table.ajax.reload();
                    
                    // Reset Form
                    $('#addExpenseForm')[0].reset();
                    $('#attachments_wrapper').html(`
                        <div class="attachment-row row g-2 mb-2 bg-white p-2 rounded-3 border-dashed">
                            <div class="col-md-5">
                                <input type="text" class="form-control form-control-sm border-0 bg-light" name="attachment_names[]" placeholder="${isSw ? 'Jina la hati (mfano: Risiti)' : 'Doc name (e.g. Receipt)'}">
                            </div>
                            <div class="col-md-7">
                                <input type="file" class="form-control form-control-sm border-0" name="attachments[]">
                            </div>
                        </div>
                    `);
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
                $btn.prop('disabled', false).html(originalHtml);
            },
            error: function() {
                Swal.fire('Error', 'An unexpected error occurred.', 'error');
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });
});

function applyFilters() { $('#expensesTable').DataTable().ajax.reload(); }
function clearFilters() { $('#statusFilter').val(''); $('#dateFromFilter, #dateToFilter').val(''); applyFilters(); }
function formatCurrency(v) { return parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}); }
function getStatusBadgeClass(s) {
    return s === 'approved' ? 'success' : s === 'pending' ? 'warning' : s === 'rejected' ? 'danger' : 'secondary';
}
function approveGeneralExpense(id) {
    $.post('/api/approve_general_expense', { id: id }, function(res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: isSw ? 'Imekamilika' : 'Success', text: res.message, timer: 2000 });
            $('#expensesTable').DataTable().ajax.reload();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    });
}
function confirmDeleteGeneralExpense(id) {
    Swal.fire({
        title: isSw ? 'Una uhakika?' : 'Are you sure?',
        text: isSw ? 'Rekodi hii itafutwa kabisa!' : 'This record will be deleted permanently!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: isSw ? 'Ndiyo, Futa' : 'Yes, delete it!',
        cancelButtonText: isSw ? 'Hapana' : 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_general_expense', { id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: isSw ? 'Imefutwa!' : 'Deleted!', text: res.message, timer: 2000 });
                    $('#expensesTable').DataTable().ajax.reload();
                }
            });
        }
    });
}

function vkEscape(s) {
    if (s === null || s === undefined) return '—';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderExpenseCards(api) {
    var $wrapper = $('#expensesCardsWrapper');
    var $empty   = $('#expensesCardsEmptyState');
    
    // Clear existing cards
    $wrapper.find('.vk-member-card').remove();
    
    var rows = api.rows({ page: 'current' }).data();
    if (rows.length === 0) { 
        $empty.removeClass('d-none'); 
        return; 
    }
    
    $empty.addClass('d-none');
    var html = '';
    
    rows.each(function(d) {
        var status = d.status || 'pending';
        var id     = parseInt(d.id);
        var amount = formatCurrency(d.amount);
        var badge  = getStatusBadgeClass(status);
        var date   = d.expense_date ? new Date(d.expense_date).toLocaleDateString() : '—';
        
        var approveBtn = status === 'pending' ? `
            <button class="btn btn-sm btn-success vk-btn-action" onclick="approveGeneralExpense(${id})" title="${isSw ? 'Idhinisha' : 'Approve'}">
                <i class="bi bi-check-circle-fill"></i>
            </button>` : '';

        html += `<div class="vk-member-card">
            <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="vk-card-avatar" style="background:linear-gradient(135deg,#6610f2,#6f42c1);"><i class="bi bi-cash-stack"></i></div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="fw-bold text-dark lh-sm" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${vkEscape(d.description)}</div>
                        <small class="text-muted">${date}</small>
                    </div>
                </div>
                <span class="badge bg-${badge} rounded-pill px-2" style="font-size:10px;">${status.toUpperCase()}</span>
            </div>
            <div class="vk-card-body">
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Kiasi' : 'Amount'}</span>
                    <span class="vk-card-value fw-bold text-danger">Tsh ${amount}</span>
                </div>
                <div class="vk-card-row">
                    <span class="vk-card-label">${isSw ? 'Hali' : 'Status'}</span>
                    <span class="vk-card-value"><span class="badge bg-${badge}-subtle text-${badge} border border-${badge}-subtle rounded-pill px-2">${status}</span></span>
                </div>
            </div>
            <div class="vk-card-actions">
                ${approveBtn}
                <button class="btn btn-sm btn-danger vk-btn-action" onclick="confirmDeleteGeneralExpense(${id})" title="${isSw ? 'Futa' : 'Delete'}">
                    <i class="bi bi-trash3-fill"></i>
                </button>
            </div>
        </div>`;
    });
    
    $wrapper.html(html);
}
</script>

<style>
.custom-stat-card { 
    background-color: #d1e7dd !important; 
    border-radius: 12px;
    transition: transform 0.2s;
}
.custom-stat-card:hover { transform: translateY(-5px); }
.custom-stat-card h4, .custom-stat-card p, .custom-stat-card i {
    color: #0c4128 !important;
}
.btn-white { background-color: white !important; color: #495057 !important; }
.btn-white:hover { background-color: #f8f9fa !important; }
.table thead th { background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-size: 11px; }
.dataTables_length label { 
    display: inline-flex !important; 
    align-items: center; 
    gap: 8px; 
    white-space: nowrap; 
    font-size: 0.85rem;
    color: #6c757d;
}
.dataTables_length select {
    border: 1px solid #dee2e6 !important;
    border-radius: 20px !important;
    padding: 2px 8px !important;
}
    @media (max-width: 768px) {
        .container-fluid { padding: 10px !important; }
        h3 { font-size: 1.4rem !important; }
        .btn-md-base { font-size: 0.85rem !important; padding: 8px 15px !important; }
        .card-header h6 { font-size: 0.9rem; }
        .table-responsive { display: none !important; }
        .table-responsive.d-print-block { display: none !important; }
        .vk-cards-wrapper { display: block !important; }
    }
    
    @media print {
        .table-responsive.d-print-block { display: block !important; }
        .vk-cards-wrapper { display: none !important; }
    }

    /* ═══ CARD VIEW STYLES (Professional System Standard) ═══ */
    .vk-cards-wrapper { display: none; }
    .vk-member-card {
        background: #fff;
        border-radius: 12px;
        border-left: 4px solid #0d6efd;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        margin-bottom: 15px;
        padding: 15px;
        transition: all 0.2s;
        border: 1px solid #f1f5f9;
        border-left: 4px solid #0d6efd;
    }
    .vk-card-header { margin-bottom: 12px; border-bottom: 1px solid #f8f9fa; padding-bottom: 10px; }
    .vk-card-avatar {
        width: 35px; height: 35px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: bold; font-size: 14px;
    }
    .vk-card-body { margin-bottom: 12px; }
    .vk-card-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 13px; }
    .vk-card-label { color: #64748b; font-weight: 500; }
    .vk-card-value { color: #1e293b; font-weight: 600; text-align: right; }
    .vk-card-actions { display: flex; gap: 8px; border-top: 1px solid #f8f9fa; pt-10; margin-top: 10px; padding-top: 10px; }
    .vk-btn-action { flex: 1; border-radius: 8px; padding: 6px; font-size: 14px; }

@media print {
    .no-print, .navbar, .card-header button, .dropdown, #lenContainer, .card-header .d-flex { display: none !important; }
    .card { border: none !important; shadow: none !important; }
    .card-header h5, .card-header h6 { display: block !important; }
}
</style>

<?php includeFooter(); ob_end_flush(); ?>
