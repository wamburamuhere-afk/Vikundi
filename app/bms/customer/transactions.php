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

// Recent transactions (all statuses) for quick confirmation after recording.
$recent = $pdo->query("
    SELECT con.contribution_id, con.amount, con.contribution_type, con.contribution_date,
           con.receipt_number, con.account, con.status, con.created_at,
           c.customer_name, c.first_name, c.last_name, c.phone
    FROM contributions con
    JOIN customers c ON con.member_id = c.customer_id
    ORDER BY con.created_at DESC, con.contribution_id DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

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

    <!-- Recent transactions -->
    <div class="card border-0 shadow-sm rounded-4 mt-3">
        <div class="card-header bg-white rounded-top-4 py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-muted"></i><?= $isSw ? 'Miamala ya Hivi Karibuni' : 'Recent Transactions' ?></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small">
                        <tr>
                            <th><?= $isSw ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $isSw ? 'Mwanachama' : 'Member' ?></th>
                            <th><?= $isSw ? 'Risiti' : 'Receipt' ?></th>
                            <th><?= $isSw ? 'Akaunti' : 'Account' ?></th>
                            <th><?= $isSw ? 'Aina' : 'Type' ?></th>
                            <th class="text-end"><?= $isSw ? 'Kiasi' : 'Amount' ?></th>
                            <th class="text-center"><?= $isSw ? 'Hali' : 'Status' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (!$recent): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4"><?= $isSw ? 'Hakuna miamala bado.' : 'No transactions yet.' ?></td></tr>
                        <?php else: foreach ($recent as $r): ?>
                            <tr>
                                <td><?= safe_output($r['contribution_date'], '—') ?></td>
                                <td><?= htmlspecialchars($r['customer_name'] ?: ($r['first_name'] . ' ' . $r['last_name'])) ?></td>
                                <td><?= safe_output($r['receipt_number'], '—') ?></td>
                                <td><?= safe_output($r['account'], '—') ?></td>
                                <td><?= safe_output($types[$r['contribution_type']] ?? $r['contribution_type'], '—') ?></td>
                                <td class="text-end fw-semibold"><?= number_format((float) $r['amount'], 0) ?></td>
                                <td class="text-center"><span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>"><?= safe_output($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
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
});
</script>

<?php
$content = ob_get_clean();
echo $content;
require_once 'footer.php';
?>
