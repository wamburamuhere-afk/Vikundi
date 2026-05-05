<?php
/**
 * Death Expenses Management (Misaada ya Misiba)
 */
ob_start();
global $pdo;

require_once __DIR__ . '/../../../roots.php';
includeHeader();

$lang = $_SESSION['preferred_language'] ?? 'sw';
$is_sw = ($lang === 'sw');

$title = $is_sw ? 'Misaada ya Misiba' : 'Death Benefits & Expenses';
$subtitle = $is_sw ? 'Rekodi msaada kwa mwanachama aliyefiwa' : 'Record assistance for a member who has lost a loved one';
?>

<div class="container-fluid py-4" style="background-color: #f8f9fa; min-height: 90vh;">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-heart-pulse-fill me-2 text-primary"></i><?= $title ?></h3>
                            <p class="text-muted mb-0 small"><?= $subtitle ?></p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="/accounts/expenses" class="btn btn-outline-secondary rounded-pill px-4">
                                <i class="bi bi-arrow-left me-2"></i> <?= $is_sw ? 'Kurudi Nyuma' : 'Back' ?>
                            </a>
                            <button type="button" class="btn btn-primary d-flex align-items-center rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#uniqueDeathModal">
                                <i class="bi bi-plus-lg me-2"></i> <?= $is_sw ? 'Rekodi Msiba Mpya' : 'Record New Death' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


<div class="card border-0 shadow-sm bg-white overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom border-light px-4 no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2">
                <!-- Records Count -->
                <div class="d-flex align-items-center bg-light rounded-3 px-2 border shadow-sm">
                    <span class="small text-muted me-1 ps-1"><i class="bi bi-list-task"></i></span>
                    <select class="form-select form-select-sm border-0 bg-transparent fw-bold" id="show_entries" style="width: auto; box-shadow: none;">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="-1"><?= $is_sw ? 'Zote' : 'All' ?></option>
                    </select>
                </div>
                <!-- Print Button -->
                <button onclick="window.print()" class="btn btn-white border shadow-sm rounded-pill px-3 fw-bold small d-flex align-items-center">
                    <i class="bi bi-printer me-2 text-primary"></i> <?= $is_sw ? 'Chapisha' : 'Print' ?>
                </button>
            </div>

            <h6 class="fw-bold mb-0 text-dark"><?= $is_sw ? 'Ripoti ya Misiba na Misaada' : 'Death Assistance Report' ?></h6>
        </div>
    </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="deathExpensesTable" style="width: 100%;">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th class="ps-4" style="width:50px"><?= $is_sw ? 'Nambari' : 'S/No' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $is_sw ? 'Mwanachama' : 'Member' ?></th>
                            <th><?= $is_sw ? 'Simu' : 'Phone' ?></th>
                            <th><?= $is_sw ? 'Aliyefariki' : 'Deceased' ?></th>
                            <th><?= $is_sw ? 'Uhusiano' : 'Rel' ?></th>
                            <th><?= $is_sw ? 'Kiasi (Tsh)' : 'Amount (Tsh)' ?></th>
                            <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                            <th class="text-end pe-4"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Record Death Modal -->
<div class="modal fade" id="uniqueDeathModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" role="dialog" aria-labelledby="uniqueDeathLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle-fill me-2"></i> <?= $is_sw ? 'Rekodi Msiba na Msaada' : 'Record Death & Assistance' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" onclick="window.allowModalClose=true; $('#uniqueDeathModal').modal('hide');" aria-label="Close"></button>
            </div>
            <form id="recordDeathForm">
                <div class="modal-body p-4 bg-white">
                    <div id="modal_message" class="mb-3"></div>
                    
                    <div class="row g-4">
                        <!-- Search Member -->
                        <div class="col-md-12">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Tafuta Jina au Namba ya Simu *' : 'Search Name or Phone *' ?></label>
                            <select class="form-select select2-member" name="member_id" required style="width: 100%;">
                                <option value=""><?= $is_sw ? 'Andika jina au namba hapa...' : 'Type name or phone here...' ?></option>
                            </select>
                        </div>

                        <!-- Dependent List -->
                        <div class="col-md-12" id="dependent_container" style="display: none;">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Chagua Nani Amefariki? *' : 'Who Passed Away? *' ?></label>
                            <div class="list-group shadow-sm" id="dependent_list">
                                <!-- Dependents will appear here -->
                            </div>
                            <input type="hidden" name="deceased_info" id="selected_deceased" required>
                        </div>

                        <!-- Amount & Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Kiasi cha Msaada *' : 'Assistance Amount *' ?></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">Tsh</span>
                                <input type="number" class="form-control" name="amount" required placeholder="0.00" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Tarehe' : 'Date' ?></label>
                            <input type="date" class="form-control bg-light" name="expense_date" value="<?= date('Y-m-d') ?>" readonly>
                            <small class="text-muted"><?= $is_sw ? 'Inarekodiwa kiotomatiki' : 'Recorded automatically' ?></small>
                        </div>

                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted"><?= $is_sw ? 'Maelezo ya Ziada' : 'Remarks' ?></label>
                            <textarea class="form-control" name="description" rows="2" placeholder="<?= $is_sw ? 'Andika maelezo yoyote hapa...' : 'Any additional remarks...' ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4 border-0">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" onclick="window.allowModalClose=true; $('#uniqueDeathModal').modal('hide');"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-5 rounded-pill shadow-sm">
                        <?= $is_sw ? 'Hifadhi Kumbukumbu' : 'Save Record' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const isSw = <?= $is_sw ? 'true' : 'false' ?>;

$(document).ready(function() {
    // Initialize Select2 with phone numbers
    // DETACHING from dropdownParent to solve the focus-war bug once and for all
    const memberSelect = $('.select2-member').select2({
        theme: 'bootstrap-5',
        dropdownAutoWidth: true,
        ajax: {
            url: '/api/search_members_with_phone',
            dataType: 'json',
            delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data.results }),
            cache: true
        }
    });

    // MASTER CSS FIX: Ensure Select2 dropdown stays visible above the modal
    // This is the most stable way to use Select2 with Bootstrap Modals
    $(document).on('select2:open', () => {
        $('.select2-container--open').css('z-index', '9999');
    });

    // THE ULTIMATE LOCK: Prevent the modal from ever hiding unless we explicitly want it to
    $('#uniqueDeathModal').on('hide.bs.modal', function (e) {
        // If the user is still interacting with the form, don't let it close!
        if (document.activeElement && 
           (document.activeElement.classList.contains('select2-search__field') || 
            document.activeElement.closest('.select2-container') ||
            $(e.target).find('form').length > 0 && !window.allowModalClose)) {
            
            // Only allow close if Save button was pressed or Cancel specifically
            if (!window.allowModalClose) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }
    });

    // Handle member selection
    $('.select2-member').on('change', function(e) {
        e.stopPropagation(); 
        const memberId = $(this).val();
        if (!memberId) {
            $('#dependent_container').hide();
            return;
        }

        $('#dependent_list').html('<div class="p-3 text-center"><span class="spinner-border spinner-border-sm text-danger me-2"></span> Loading...</div>');
        $('#dependent_container').show();

        $.get('/api/get_member_dependents', { member_id: memberId }, function(res) {
            if (res.success) {
                let html = '';
                res.dependents.forEach(d => {
                    html += `
                        <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3" onclick="selectDeceased(event, '${d.type}', '${d.id}', '${d.name}', '${d.relationship}')">
                            <div>
                                <h6 class="mb-0 fw-bold">${d.name}</h6>
                                <small class="text-muted">${d.relationship}</small>
                            </div>
                            <i class="bi bi-circle text-muted" id="icon_${d.id}"></i>
                        </button>
                    `;
                });
                $('#dependent_list').html(html);
            } else {
                $('#dependent_list').html(`<div class="alert alert-danger">${res.message}</div>`);
            }
        });
    });

    const table = $('#deathExpensesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '/api/get_death_expenses',
            dataSrc: json => {
                $('#total_payouts').text(formatCurrency(json.totalAmount));
                $('#death_count').text(json.recordsTotal);
                $('#month_payouts').text(json.monthTotal);
                return json.data;
            }
        },
        columns: [
            { 
                data: null,
                orderable: false,
                searchable: false,
                render: (data, type, row, meta) => `<strong>${meta.row + 1}</strong>`
            },
            { data: 'expense_date' },
            { data: 'member_name', render: d => `<strong>${d}</strong>` },
            { data: 'phone_number', render: d => `<span class="badge bg-light text-dark border">${d}</span>` },
            { data: 'deceased_name' },
            { data: 'deceased_relationship', render: d => `<span class="small text-muted">${d}</span>` },
            { data: 'amount', render: d => `<strong class="text-danger">${formatCurrency(d)}</strong>` },
            { 
                data: 'status',
                render: d => {
                    let badge = d === 'approved' ? 'success' : 'warning';
                    let label = d === 'approved' ? (isSw ? 'Imeidhinishwa' : 'Approved') : (isSw ? 'Inasubiri' : 'Pending');
                    return `<span class="badge bg-${badge}">${label}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `
                    <div class="dropdown">
                        <button class="btn btn-white btn-sm border shadow-sm dropdown-toggle px-3 d-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="z-index: 1050 !important;">
                    `;
                    if (row.status === 'pending') {
                        html += `<li><a class="dropdown-item py-2 fw-bold text-primary rounded mb-1" href="javascript:void(0)" onclick="approveDeathExpense(${row.id})"><i class="bi bi-check-circle-fill me-2"></i> ${isSw ? 'Idhinisha' : 'Approve'}</a></li>`;
                        html += `<li><hr class="dropdown-divider opacity-10"></li>`;
                    }
                    html += `<li><a class="dropdown-item py-2 rounded text-danger" href="javascript:void(0)" onclick="deleteDeathExpense(${row.id})"><i class="bi bi-trash-fill me-2"></i> ${isSw ? 'Futa Hii' : 'Delete Entry'}</a></li>`;
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        dom: 'rtp',
    });

    // Custom Show Entries
    $('#show_entries').on('change', function() {
        table.page.len($(this).val()).draw();
    });

    // Form Submission
    $('#recordDeathForm').on('keydown', function(e) {
        if (e.keyCode == 13 && !$(e.target).is('textarea') && !$(e.target).is('button')) {
            e.preventDefault();
            return false;
        }
    });

    $('#recordDeathForm').on('submit', function(e) {
        e.preventDefault();
        if (!$('#selected_deceased').val()) {
            Swal.fire(isSw?'Kosa':'Error', isSw?'Tafadhali chagua nani amefariki.':'Please select who passed away.', 'error');
            return;
        }

        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Saving...');

        $.ajax({
            url: '/actions/process_death_expense',
            type: 'POST',
            data: $(this).serialize(),
            success: response => {
                if (response.success) {
                    Swal.fire(isSw?'Imefanikiwa':'Success', response.message, 'success');
                    window.allowModalClose = true;
                    $('#uniqueDeathModal').modal('hide');
                    $('#recordDeathForm')[0].reset();
                    $('#dependent_container').hide();
                    table.ajax.reload();
                } else {
                    Swal.fire(isSw?'Kosa':'Error', response.message, 'error');
                }
                $btn.prop('disabled', false).text(isSw ? 'Hifadhi Kumbukumbu' : 'Save Record');
            }
        });
    });

    window.selectDeceased = function(e, type, id, name, rel) {
        e.preventDefault();
        e.stopPropagation();
        $('#selected_deceased').val(`${type}|${id}|${name}|${rel}`);
        $('.list-group-item').removeClass('active');
        $('.list-group-item i').removeClass('bi-check-circle-fill').addClass('bi-circle');
        $(e.currentTarget).addClass('active');
        $(e.currentTarget).find('i').removeClass('bi-circle').addClass('bi-check-circle-fill');
    };

    window.approveDeathExpense = function(id) {
        Swal.fire({
            title: isSw ? 'Idhinisha Msaada huu?' : 'Approve this assistance?',
            text: isSw ? 'Hali itabadilika kuwa Imeidhinishwa na salio la kikundi litapungua.' : 'The status will change to Approved and group balance will decrease.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: isSw ? 'Ndio, Idhinisha!' : 'Yes, Approve!'
        }).then((result) => {
            if (result.isConfirmed) {
                $.post('/actions/approve_death_expense', { id: id }, function(res) {
                    if (res.success) {
                        Swal.fire(isSw?'Imefanikiwa':'Success', res.message, 'success');
                        table.ajax.reload();
                    } else {
                        Swal.fire(isSw?'Salio Halitoshi':'Inadequate balance', res.message, 'error');
                    }
                });
            }
        });
    };

    function formatCurrency(v) { return parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}); }
});
</script>

<style>
.uppercase { text-transform: uppercase; letter-spacing: 0.5px; }
.card { transition: transform 0.2s; }
.card:hover { transform: translateY(-3px); }
.list-group-item { cursor: pointer; transition: all 0.2s; }
.list-group-item.active { border-color: #0d6efd !important; background-color: #0d6efd !important; color: white !important; }
</style>

<?php 
includeFooter(); 
ob_end_flush();
?>
