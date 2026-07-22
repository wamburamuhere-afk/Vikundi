<?php
// app/bms/customer/transactions.php
// Finance > Transactions — the recording hub: record a payment, bulk upload, or
// import an M-Koba statement. Contributions stays the (read-only) listing.
ob_start();
require_once 'header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}
requireViewPermission('manage_contributions');

$isSw = (($_SESSION['preferred_language'] ?? 'en') === 'sw');
$can_record = isAdmin() || canCreate('manage_contributions');

// Members for the picker (active, non-deceased).
$members = $pdo->query("
    SELECT c.customer_id, c.customer_name, c.first_name, c.last_name, c.phone
    FROM customers c
    JOIN users u ON c.user_id = u.user_id
    WHERE u.status = 'active' AND c.is_deceased = 0
    ORDER BY c.first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// The transactions list is a server-side DataTable (api/get_transactions.php):
// it pages, filters and sorts in the database, so nothing is preloaded here.
// Default the view to a recent window so the first load stays cheap even when the
// table holds a very large history.
$default_from = date('Y-m-d', strtotime('-90 days'));

$accounts = ['M-Koba', 'Bank', 'Cash', 'Mobile Money'];
$types = [
    'monthly'  => $isSw ? 'Mchango wa Mwezi' : 'Monthly',
    'entrance' => $isSw ? 'Ada ya Kujiunga' : 'Entrance',
    'agm'      => $isSw ? 'Mkutano Mkuu (AGM)' : 'AGM',
    'fine'     => $isSw ? 'Faini' : 'Fine',
    'other'    => $isSw ? 'Nyingine' : 'Other',
];
$statusBadge = [
    'pending'  => 'warning', 'reviewed' => 'info',
    'approved' => 'success', 'cancelled' => 'secondary',
];
?>

<div class="container-fluid py-3">
    <?php if (isset($_SESSION['import_response'])): $ir = $_SESSION['import_response']; unset($_SESSION['import_response']); ?>
    <div class="alert alert-<?= !empty($ir['success']) ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
        <i class="bi bi-<?= !empty($ir['success']) ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= htmlspecialchars($ir['message'] ?? '') ?>
        <?php if (!empty($ir['unmatched_count'])): ?>
        <div class="mt-2">
            <a href="<?= getUrl('actions/download_unmatched') ?>" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-download me-1"></i><?= $isSw ? 'Pakua safu zisizolingana' : 'Download unmatched rows' ?> (<?= (int) $ir['unmatched_count'] ?>)
            </a>
        </div>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-primary me-2"></i><?= $isSw ? 'Miamala' : 'Transactions' ?></h4>
            <p class="text-muted small mb-0"><?= $isSw ? 'Rekodi malipo, pakia kwa wingi, au leta taarifa za M-Koba.' : 'Record payments, bulk upload, or import an M-Koba statement.' ?></p>
        </div>
        <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-list-ul me-1"></i> <?= $isSw ? 'Orodha ya Michango' : 'Contributions list' ?>
        </a>
    </div>

    <div class="row g-3">
        <!-- Record a payment -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-primary text-white rounded-top-4 py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2"></i><?= $isSw ? 'Rekodi Malipo' : 'Record Payment' ?></h6>
                </div>
                <div class="card-body p-4">
                    <?php if (!$can_record): ?>
                        <div class="alert alert-warning small mb-0"><?= $isSw ? 'Huna ruhusa ya kurekodi miamala.' : 'You do not have permission to record transactions.' ?></div>
                    <?php else: ?>
                    <form id="recordTxnForm" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small"><?= $isSw ? 'Mwanachama' : 'Member' ?> <span class="text-danger">*</span></label>
                            <select name="member_id" id="txnMember" class="form-select" required style="width:100%;">
                                <option value=""><?= $isSw ? '-- Tafuta kwa Jina au Namba --' : '-- Search by Name or Phone --' ?></option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['customer_id'] ?>" data-phone="<?= safe_output($m['phone'], '') ?>">
                                        <?= htmlspecialchars($m['customer_name'] ?: ($m['first_name'] . ' ' . $m['last_name'])) ?> (<?= safe_output($m['phone'], 'N/A') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Namba ya Risiti' : 'Receipt Number' ?></label>
                                <input type="text" name="receipt_number" class="form-control" placeholder="<?= $isSw ? 'Hiari' : 'Optional' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Tarehe' : 'Date' ?></label>
                                <input type="date" name="contribution_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Akaunti / Chanzo' : 'Account / Source' ?></label>
                                <select name="account" class="form-select">
                                    <?php foreach ($accounts as $a): ?>
                                        <option value="<?= $a ?>"><?= $a ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Aina' : 'Type' ?></label>
                                <select name="contribution_type" class="form-select">
                                    <?php foreach ($types as $k => $label): ?>
                                        <option value="<?= $k ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Kiasi' : 'Amount' ?> <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control border-primary" required min="1" step="0.01" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small"><i class="bi bi-camera me-1"></i><?= $isSw ? 'Risiti / Uthibitisho' : 'Receipt / Proof' ?></label>
                                <input type="file" name="evidence" class="form-control" accept="image/*">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small"><?= $isSw ? 'Maelezo' : 'Description' ?></label>
                                <input type="text" name="description" class="form-control" placeholder="<?= $isSw ? 'Hiari' : 'Optional' ?>">
                            </div>
                        </div>
                        <div class="text-end mt-4">
                            <button type="submit" class="btn btn-primary rounded-pill px-5 shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> <?= $isSw ? 'HIFADHI' : 'SAVE' ?>
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bulk options -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-light rounded-top-4 py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-cloud-arrow-up me-2 text-success"></i><?= $isSw ? 'Pakia kwa Wingi' : 'Bulk Import' ?></h6>
                </div>
                <div class="card-body p-4 d-flex flex-column gap-3">
                    <button type="button" class="btn btn-outline-success rounded-3 text-start p-3" data-bs-toggle="modal" data-bs-target="#uploadReportModal" <?= $can_record ? '' : 'disabled' ?>>
                        <i class="bi bi-file-earmark-spreadsheet me-2"></i><b><?= $isSw ? 'Pakia Faili Letu' : 'Upload our template' ?></b>
                        <div class="small text-muted"><?= $isSw ? 'CSV ya michango (namba ya simu + kiasi).' : 'Contribution CSV (phone + amount).' ?></div>
                    </button>
                    <button type="button" class="btn btn-outline-primary rounded-3 text-start p-3" data-bs-toggle="modal" data-bs-target="#uploadMKobaModal" <?= $can_record ? '' : 'disabled' ?>>
                        <i class="bi bi-phone-vibrate me-2"></i><b><?= $isSw ? 'Leta Taarifa za M-Koba' : 'Import M-Koba statement' ?></b>
                        <div class="small text-muted"><?= $isSw ? 'Inalinganisha kwa namba ya simu.' : 'Matched by member phone number.' ?></div>
                    </button>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i><?= $isSw ? 'Faili za wingi huingia kama "zinasubiri" hadi zihakikiwe.' : 'Bulk entries arrive as "pending" until reviewed.' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transactions (server-side DataTable) -->
    <div class="card border-0 shadow-sm rounded-4 mt-3">
        <div class="card-header bg-white rounded-top-4 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-muted"></i><?= $isSw ? 'Miamala' : 'Transactions' ?></h6>
            <!-- Statement of the current date range (+ status): reuses the shared
                 contributions statement (print) and CSV/Excel export. -->
            <div class="btn-group">
                <button type="button" class="btn btn-sm btn-primary rounded-start-pill" onclick="txnStatement('print')"><i class="bi bi-printer me-1"></i><?= $isSw ? 'Taarifa' : 'Statement' ?></button>
                <button type="button" class="btn btn-sm btn-success rounded-end-pill" onclick="txnStatement('excel')"><i class="bi bi-file-earmark-excel me-1"></i><?= $isSw ? 'Excel' : 'Excel' ?></button>
            </div>
        </div>
        <div class="card-body">
            <!-- Filters -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= $isSw ? 'Kuanzia' : 'From' ?></label>
                    <input type="date" id="fFrom" class="form-control form-control-sm" value="<?= $default_from ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= $isSw ? 'Hadi' : 'To' ?></label>
                    <input type="date" id="fTo" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= $isSw ? 'Hali' : 'Status' ?></label>
                    <select id="fStatus" class="form-select form-select-sm">
                        <option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                        <?php foreach (['pending', 'reviewed', 'approved', 'cancelled'] as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= $isSw ? 'Aina' : 'Type' ?></label>
                    <select id="fType" class="form-select form-select-sm">
                        <option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                        <?php foreach ($types as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold mb-1"><?= $isSw ? 'Akaunti' : 'Account' ?></label>
                    <select id="fAccount" class="form-select form-select-sm">
                        <option value=""><?= $isSw ? 'Zote' : 'All' ?></option>
                        <?php foreach ($accounts as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex align-items-end">
                    <button type="button" id="fReset" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-arrow-counterclockwise me-1"></i><?= $isSw ? 'Weka upya' : 'Reset' ?></button>
                </div>
            </div>

            <div class="table-responsive">
                <table id="transactionsTable" class="table table-hover align-middle mb-0" style="width:100%">
                    <thead class="table-light small">
                        <tr>
                            <th>S/No</th>
                            <th><?= $isSw ? 'Namba ya Muamala' : 'Trans ID' ?></th>
                            <th><?= $isSw ? 'Risiti' : 'Receipt' ?></th>
                            <th><?= $isSw ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $isSw ? 'Mwanachama' : 'Member Name' ?></th>
                            <th><?= $isSw ? 'Namba ya Mwanachama' : 'Member ID' ?></th>
                            <th><?= $isSw ? 'Chanzo' : 'Source' ?></th>
                            <th><?= $isSw ? 'Lengwa' : 'Destination' ?></th>
                            <th class="text-end"><?= $isSw ? 'Kiasi' : 'Amount' ?></th>
                            <th><?= $isSw ? 'Aina ya Muamala' : 'Trans Type' ?></th>
                            <th class="text-center"><?= $isSw ? 'Hali' : 'Status' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Bulk: our template -->
<div class="modal fade" id="uploadReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2"></i><?= $isSw ? 'Pakia Michango' : 'Upload Contributions' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="reportUploadForm" action="<?= getUrl('actions/import_contributions') ?>" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="upload_type" value="existing_report">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3"><?= $isSw ? 'Pakia CSV yenye michango. Mwanachama hutambuliwa kwa Namba ya Simu.' : 'Upload a CSV of contributions. Members are matched by Phone Number.' ?></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?= $isSw ? 'Chagua Faili (CSV)' : 'Select File (CSV)' ?></label>
                        <input type="file" name="upload_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                    </div>
                    <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i><?= $isSw ? 'Hakikisha faili lina safu za: Namba ya Simu na Kiasi.' : 'Ensure the file has columns for Phone/ID and Amount.' ?></div>
                    <a href="<?= getUrl('actions/download_transactions_template') ?>" class="small text-decoration-none"><i class="bi bi-download me-1"></i><?= $isSw ? 'Pakua kiolezo (template)' : 'Download template' ?></a>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal"><?= $isSw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill"><?= $isSw ? 'Anza Kupakia' : 'Start Uploading' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk: M-Koba statement -->
<div class="modal fade" id="uploadMKobaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-phone-vibrate me-2"></i><?= $isSw ? 'Leta Taarifa za M-Koba' : 'Import M-Koba Statement' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="mkobaUploadForm" action="<?= getUrl('actions/import_contributions') ?>" method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="upload_type" value="mkoba_statement">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3"><?= $isSw ? 'Pakia taarifa za M-Koba. Mfumo utalinganisha miamala na wanachama kwa Namba za Simu.' : 'Upload your M-Koba statement. Transactions are matched to members by phone number.' ?></p>
                    <div class="mb-3">
                        <label class="form-label fw-bold"><?= $isSw ? 'Faili la Taarifa' : 'Statement File' ?></label>
                        <input type="file" name="upload_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                    </div>
                    <div class="alert alert-light border small py-2 mb-0">
                        <i class="bi bi-lightbulb text-warning me-1"></i>
                        <?= $isSw
                            ? 'Kidokezo: Pakia faili moja kwa moja kutoka M-Koba. Ikiwa limefunguliwa katika Excel, tumia <b>.xlsx</b> — au weka safu ya TRANS_ID kama <b>Text</b> — ili Namba za Muamala ndefu zisiharibike (mfano 3.8E+15).'
                            : 'Tip: upload the file straight from M-Koba. If it has been opened in Excel, prefer the <b>.xlsx</b> — or format the TRANS_ID column as <b>Text</b> — so long Trans IDs aren\'t corrupted (e.g. 3.8E+15).' ?>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal"><?= $isSw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary px-4 rounded-pill"><?= $isSw ? 'Chakata' : 'Process Statement' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(function () {
    if ($.fn.select2) {
        $('#txnMember').select2({
            placeholder: '<?= $isSw ? "-- Tafuta kwa Jina au Namba --" : "-- Search by Name or Phone --" ?>',
            allowClear: true
        });
    }

    $('#recordTxnForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('#txnMember').val()) {
            Swal.fire('<?= $isSw ? "Kosa" : "Error" ?>', '<?= $isSw ? "Tafadhali chagua mwanachama." : "Please select a member." ?>', 'error');
            return;
        }
        var form = this;
        $.ajax({
            url: '<?= getUrl("actions/process_contribution") ?>',
            type: 'POST',
            data: new FormData(form),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: '<?= $isSw ? "Imehifadhiwa" : "Saved" ?>', text: res.message, timer: 1600, showConfirmButton: false })
                        .then(function () { location.reload(); });
                } else {
                    Swal.fire('<?= $isSw ? "Kosa" : "Error" ?>', res.message || 'Error', 'error');
                }
            },
            error: function () { Swal.fire('<?= $isSw ? "Kosa" : "Error" ?>', 'Server error', 'error'); }
        });
    });

    // --- Transactions table: server-side, so the DB does the paging/filtering
    //     and the browser only ever holds one page (scales to a huge history). ---
    const TXN_TYPES = <?= json_encode($types) ?>;
    const TXN_STATUS_BADGE = <?= json_encode($statusBadge) ?>;
    function txnEsc(s){ return s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    const txnTable = $('#transactionsTable').DataTable({
        serverSide: true,
        processing: true,
        searchDelay: 400,
        deferRender: true,
        order: [[3, 'desc']], // Date, newest first (mirrors the M-Koba statement)
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        ajax: {
            url: '<?= getUrl("api/get_transactions") ?>',
            data: function (d) {
                d.status    = $('#fStatus').val();
                d.type      = $('#fType').val();
                d.account   = $('#fAccount').val();
                d.date_from = $('#fFrom').val();
                d.date_to   = $('#fTo').val();
            }
        },
        // Columns mirror the M-Koba statement 1:1 (+ our Status). M-Koba-only
        // fields are blank for hand-entered payments; the M-Koba-specific columns
        // aren't sortable (see $orderCols in api/get_transactions.php).
        columns: [
            { data: 'mkoba_sno',           orderable: false, render: d => txnEsc(d || '—') },
            { data: 'mkoba_trans_id',      orderable: false, className: 'small text-muted', render: d => txnEsc(d || '—') },
            { data: 'receipt_number',      render: d => txnEsc(d || '—') },
            { data: 'contribution_date',   render: d => d || '—' },
            { data: 'member_name',         render: d => txnEsc(d || '—') },
            { data: 'mkoba_member_id_str', orderable: false, className: 'small text-muted', render: d => txnEsc(d || '—') },
            { data: 'mkoba_source',        orderable: false, className: 'small text-muted', render: d => txnEsc(d || '—') },
            { data: 'mkoba_destination',   orderable: false, className: 'small text-muted', render: d => txnEsc(d || '—') },
            { data: 'amount', className: 'text-end fw-semibold', render: d => new Intl.NumberFormat().format(Math.round(d || 0)) },
            { data: 'mkoba_trans_type',    orderable: false, render: (d, t, row) => txnEsc(d || TXN_TYPES[row.contribution_type] || row.contribution_type || '—') },
            { data: 'status', className: 'text-center', render: d => '<span class="badge bg-' + (TXN_STATUS_BADGE[d] || 'secondary') + '">' + txnEsc(d) + '</span>' }
        ],
        language: {
            processing:   '<?= $isSw ? "Inachakata..." : "Processing..." ?>',
            search:       '<?= $isSw ? "Tafuta:" : "Search:" ?>',
            emptyTable:   '<?= $isSw ? "Hakuna miamala." : "No transactions." ?>',
            zeroRecords:  '<?= $isSw ? "Hakuna matokeo ya utafutaji huu." : "No matching transactions." ?>',
            info:         '<?= $isSw ? "Inaonyesha _START_&ndash;_END_ kati ya _TOTAL_" : "Showing _START_&ndash;_END_ of _TOTAL_" ?>',
            infoFiltered: '<?= $isSw ? "(imechujwa kutoka _MAX_)" : "(filtered from _MAX_)" ?>',
            infoEmpty:    '<?= $isSw ? "Hakuna miamala" : "No transactions" ?>',
            lengthMenu:   '<?= $isSw ? "Onyesha _MENU_" : "Show _MENU_" ?>',
            paginate:     { next: '<?= $isSw ? "Mbele" : "Next" ?>', previous: '<?= $isSw ? "Nyuma" : "Previous" ?>' }
        }
    });

    $('#fStatus, #fType, #fAccount, #fFrom, #fTo').on('change', () => txnTable.ajax.reload());
    $('#fReset').on('click', function () {
        $('#fStatus, #fType, #fAccount, #fTo').val('');
        $('#fFrom').val('<?= $default_from ?>');
        txnTable.ajax.reload();
    });

    // Statement of the current date range (+ status) — reuses the shared,
    // date-bounded contributions statement (print) and CSV/Excel export.
    window.txnStatement = function (mode) {
        var from = $('#fFrom').val(), to = $('#fTo').val();
        if (!from && !to) {
            Swal.fire('<?= $isSw ? "Kosa" : "Error" ?>', '<?= $isSw ? "Chagua angalau tarehe ya kuanzia au mwisho." : "Pick at least a From or To date." ?>', 'warning');
            return;
        }
        var qs = new URLSearchParams({ from: from, to: to, status: $('#fStatus').val() }).toString();
        var url = (mode === 'excel')
            ? '<?= getUrl("api/export_contributions_statement") ?>?' + qs
            : '<?= getUrl("contribution_statement") ?>?' + qs;
        window.open(url, '_blank');
    };
});
</script>

<?php
$content = ob_get_clean();
echo $content;
require_once 'footer.php';
?>
