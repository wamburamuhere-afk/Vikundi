<?php
// app/bms/customer/manage_contributions.php
ob_start();
require_once 'header.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}
// Permission check
requireViewPermission('manage_contributions');
$is_leader = isAdmin() || canEdit('manage_contributions');
$is_admin = isAdmin() || canDelete('manage_contributions');

// 1. Fetch Basic Group Settings
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$start_date = $settings_raw['contribution_start_date'] ?? $settings_raw['group_founded_date'] ?? date('Y-m-01');
$cycle = $settings_raw['cycle_type'] ?? 'monthly';
$currency = $settings_raw['currency'] ?? 'TZS';

// 2. Fetch Pending Contributions (Leaders see all, Members see only theirs)
$pending_query = "
    SELECT con.*, c.customer_name, c.first_name, c.last_name, c.phone 
    FROM contributions con 
    JOIN customers c ON con.member_id = c.customer_id 
    WHERE con.status = 'pending' 
";
if (!$is_leader) {
    $pending_query .= " AND c.user_id = ? ";
    $stmt = $pdo->prepare($pending_query . " ORDER BY con.contribution_date DESC");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->query($pending_query . " ORDER BY con.contribution_date DESC");
}
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Grid Navigation Logic (Block of 4)
$block = intval($_GET['block'] ?? 0);
$periods_per_block = 4;
$start_offset = $block * $periods_per_block;

$columns = [];
for ($i = 0; $i < $periods_per_block; $i++) {
    $idx = $start_offset + $i;
    $ts = strtotime($start_date . " +$idx " . ($cycle === 'weekly' ? 'weeks' : 'months'));
    $columns[] = [
        'idx' => $idx,
        'label' => date($cycle === 'weekly' ? 'Wk d M' : 'M Y', $ts),
        'start_ts' => date('Y-m-d 00:00:00', $ts),
        'end_ts' => date('Y-m-t 23:59:59', $ts) // Simplified for monthly
    ];
    // Adjust end date for weekly
    if ($cycle === 'weekly') {
        $columns[$i]['end_ts'] = date('Y-m-d 23:59:59', strtotime(date('Y-m-d', $ts) . " +6 days"));
    }
}

// 4. Fetch All Active Members (Leaders see all, Members see themselves)
if (!$is_leader) {
    $stmt = $pdo->prepare("
        SELECT c.customer_id, c.customer_name, c.first_name, c.last_name, c.phone 
        FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE c.user_id = ? AND u.status = 'active' AND c.is_deceased = 0 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
} else {
    $stmt = $pdo->query("
        SELECT c.customer_id, c.customer_name, c.first_name, c.last_name, c.phone 
        FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE u.status = 'active' AND c.is_deceased = 0 
        ORDER BY c.first_name ASC
    ");
}
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Build Ledger Data (Intelligent Distribution)
$ledger = [];
$monthly_val = floatval($settings_raw['monthly_contribution'] ?? 10000);
$entrance_val = floatval($settings_raw['entrance_fee'] ?? 20000);

foreach ($members as $m) {
    $row = [
        'member' => $m,
        'periods' => [],
        'block_total' => 0,
        'grand_total' => 0
    ];
    
    // Total Confirmed Pot for this member
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM contributions WHERE member_id = ? AND status = 'confirmed'");
    $stmt->execute([$m['customer_id']]);
    $row['grand_total'] = floatval($stmt->fetchColumn());

    // Intelligence: Subtract Entrance first, then distribute rest to months
    $pot = $row['grand_total']; 
    $pot -= min($pot, $entrance_val); 
    
    // Fill all periods from the start up to our current view window
    for ($i = 0; $i < ($start_offset + $periods_per_block); $i++) {
        $amt_for_this_idx = min($pot, $monthly_val);
        $pot -= $amt_for_this_idx;
        
        // Only add to 'periods' if it falls within the current 4-period block view
        if ($i >= $start_offset) {
            $row['periods'][] = $amt_for_this_idx;
            $row['block_total'] += $amt_for_this_idx;
        }
    }
    
    $ledger[] = $row;
}
?>

<!-- Header Section -->
<div class="row align-items-center mb-4 g-3">
    <!-- Success/Error Feedback from Import -->
    <?php if (isset($_SESSION['import_response'])): 
        $res = $_SESSION['import_response'];
        unset($_SESSION['import_response']);
    ?>
    <div class="col-12">
        <div class="alert alert-<?= $res['success'] ? 'success' : 'danger' ?> alert-dismissible fade show shadow-sm border-0 rounded-4 p-3 mb-0" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-<?= $res['success'] ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-4 me-3"></i>
                <div>
                    <h6 class="alert-heading fw-bold mb-1"><?= $res['success'] ? 'Import Successful' : 'Import Failed' ?></h6>
                    <p class="mb-0 small"><?= $res['message'] ?></p>
                    <?php if (!empty($res['errors'])): ?>
                    <ul class="mb-0 mt-2 small ps-3">
                        <?php foreach($res['errors'] as $err): ?><li><?= $err ?></li><?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md">
        <h2 class="fw-bold text-primary mb-1"><i class="bi bi-bank2 me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchanganuo wa Michango (Ledger)' : 'Contribution Ledger' ?></h2>
        <p class="text-muted small mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tazama na uhakiki michango ya kila mwanachama.' : 'Analyze and approve member contribution records.' ?></p>
    </div>
    <div class="col-md-auto d-flex gap-2 justify-content-end align-items-center">
        <!-- Compact Navigation Group -->
        <div class="btn-group shadow-sm border rounded-2 overflow-hidden bg-white">
            <a href="?block=<?= max(0, $block - 1) ?>" class="btn btn-light border-0 px-2 px-md-3" title="Previous"><i class="bi bi-chevron-left"></i></a>
            <span class="btn btn-white border-0 disabled fw-bold py-2 d-none d-md-inline-block shadow-none"><?= $columns[0]['label'] ?> — <?= $columns[3]['label'] ?></span>
            <span class="btn btn-white border-0 disabled fw-bold py-2 d-md-none small shadow-none px-1"><?= substr($columns[0]['label'],0,3) ?>-<?= substr($columns[3]['label'],0,3) ?></span>
            <a href="?block=<?= $block + 1 ?>" class="btn btn-light border-0 px-2 px-md-3" title="Next"><i class="bi bi-chevron-right"></i></a>
        </div>
        
        <?php if ($is_leader): ?>
        <!-- Bulk Upload - Rectangle on both -->
        <div class="dropdown">
            <button class="btn btn-outline-primary rounded-2 p-2 p-md-2 px-md-3 shadow-sm dropdown-toggle" style="min-height: 42px; display: flex; align-items: center; justify-content: center;" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-cloud-upload"></i>
                <span class="d-none d-md-inline ms-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakia' : 'Bulk' ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                <li><a class="dropdown-item py-2 rounded-2" href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#uploadReportModal"><i class="bi bi-file-earmark-spreadsheet me-2 text-success"></i> Report</a></li>
                <li><a class="dropdown-item py-2 rounded-2" href="javascript:void(0)" data-bs-toggle="modal" data-bs-target="#uploadMKobaModal"><i class="bi bi-phone-vibrate me-2 text-primary"></i> M-Koba</a></li>
            </ul>
        </div>

        <!-- Record Payment - Rectangle on both -->
        <button type="button" class="btn btn-primary rounded-2 p-2 p-md-2 px-md-4 shadow-sm" style="min-height: 42px; width: auto;" data-bs-toggle="modal" data-bs-target="#manualAddModal">
            <i class="bi bi-plus-lg"></i>
            <span class="d-none d-md-inline ms-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Mchango' : 'Record Payment' ?></span>
        </button>
        <?php else: ?>
        <a href="<?= getUrl('submit_contribution') ?>" class="btn btn-primary rounded-2 p-2 p-md-2 px-md-4 shadow-sm" style="min-height: 42px; width: auto;">
            <i class="bi bi-send-plus"></i>
            <span class="d-none d-md-inline ms-2"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lipia Mchango' : 'Pay Contribution' ?></span>
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- SECTION 1: PENDING APPROVALS -->
<?php if (!empty($pending_list)): ?>
<div class="card border border-primary-subtle shadow-sm rounded-4 overflow-hidden mb-5">
    <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-hourglass-split me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango Inayosubiri Uhakiki' : 'Pending Approvals' ?></h6>
        <div class="d-flex align-items-center gap-2">
            <div id="pending-length-container"></div>
            <div id="pending-search-container"></div>
            <span class="badge bg-white text-primary rounded-pill fw-bold"><?= count($pending_list) ?></span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive p-3">
            <table class="table hover align-middle mb-0 w-100" id="pendingTable">
                <thead class="small bg-light">
                    <tr>
                        <th class="ps-4">S/No</th>
                        <th>Submitted At</th>
                        <th>Member (Phone)</th>
                        <th>Amount</th>
                        <th>Type</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $psno = 1; foreach ($pending_list as $p): ?>
                    <tr>
                        <td class="ps-4 fw-bold"><?= $psno++ ?></td>
                        <td class="small">
                            <div class="fw-bold"><?= date('d/m/Y', strtotime($p['created_at'])) ?></div>
                            <div class="text-primary small fw-bold"><i class="bi bi-clock me-1"></i> <?= date('H:i', strtotime($p['created_at'])) ?></div>
                        </td>
                        <td>
                            <div class="fw-bold"><?= htmlspecialchars($p['customer_name'] ?: ($p['first_name'] . ' ' . $p['last_name'])) ?></div>
                            <small class="text-muted"><?= $p['phone'] ?></small>
                        </td>
                        <td class="fw-bold text-primary"><?= number_format($p['amount'], 0) ?></td>
                        <td><span class="badge bg-light text-primary border border-primary-subtle"><?= strtoupper($p['contribution_type']) ?></span></td>
                        <td class="text-end pe-4">
                            <div class="dropdown">
                                <button class="btn btn-primary btn-sm rounded-1 dropdown-toggle px-3 shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="bi bi-gear-fill me-1"></i> Action
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="z-index: 1050 !important;">
                                    <?php if ($is_leader): ?>
                                    <li><a class="dropdown-item py-2 fw-bold text-primary rounded mb-1" href="javascript:void(0)" onclick="approveContribution(<?= $p['contribution_id'] ?>)"><i class="bi bi-check-circle-fill me-2"></i> Approve Now</a></li>
                                    <?php endif; ?>
                                    <?php if (!empty($p['evidence_path'])): ?>
                                    <li><a class="dropdown-item py-2 rounded mb-1" href="<?= getUrl($p['evidence_path']) ?>" target="_blank"><i class="bi bi-receipt me-2 text-primary"></i> View Receipt</a></li>
                                    <?php endif; ?>
                                    <?php if ($is_leader): ?>
                                    <li><hr class="dropdown-divider opacity-10"></li>
                                    <li><a class="dropdown-item py-2 rounded text-secondary" href="javascript:void(0)" onclick="rejectContribution(<?= $p['contribution_id'] ?>)"><i class="bi bi-x-circle me-2"></i> Reject Entry</a></li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- SECTION 2: DYNAMIC GRID LEDGER -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jedwali la Michango' : 'Contribution Analysis Grid' ?></h6>
        <div class="small text-muted italic">* Confirmed totals for the selected 4-period block.</div>
    </div>
    <div class="card-body p-4">
        <!-- STANDALONE TOOLS (WHITE & RECTANGULAR) -->
        <div class="row g-1 g-md-2 mb-4 align-items-center no-print">
            <div class="col-auto d-flex align-items-center gap-1" id="ledger-tools-left">
                <!-- PRINT and CSV -->
            </div>
            <div class="col-auto" id="ledger-length-container">
                <!-- SHOW Rows -->
            </div>
            <div class="col ms-auto text-end" id="ledger-search-container">
                <!-- SEARCH -->
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center mb-0" id="ledgerTable" style="width: 100% !important;">
                <thead class="small bg-light fw-bold text-uppercase">
                    <tr>
                        <th class="py-3 px-3 text-center">S/No</th>
                        <th class="text-center"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member' ?></th>
                        <th class="text-center" style="min-width: 110px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone No' ?></th>
                        
                        <?php foreach ($columns as $idx => $col): ?>
                            <th class="bg-primary-subtle text-primary border-primary border-opacity-25 text-center" style="min-width: 100px;"><?= $col['label'] ?></th>
                        <?php endforeach; ?>
                        
                        <th class="bg-white text-dark text-center fw-bolder" style="min-width: 120px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla ya Robo' : 'Block Total' ?></th>
                        <th class="bg-white text-primary text-center fw-bolder" style="min-width: 130px;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla Kuu' : 'Grand Total' ?></th>
                        <th class="bg-light text-center no-print"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Loaded via AJAX -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Manual Add Modal -->
<div class="modal fade" id="manualAddModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 15px;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Mchango wa Mwanachama' : 'New Contribution Form' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="manualAddForm" enctype="multipart/form-data">
                <div class="modal-body p-4">
                    <div class="mb-4">
                        <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua Mwanachama' : 'Select Member' ?></label>
                        <select name="member_id" id="member_select2" class="form-select" required style="width: 100%;">
                            <option value=""><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '-- Tafuta kwa Jina au Namba --' : '-- Search by Name or Phone --' ?></option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= $m['customer_id'] ?>" data-phone="<?= $m['phone'] ?>">
                                    <?= htmlspecialchars($m['customer_name'] ?: ($m['first_name'] . ' ' . $m['last_name'])) ?> (<?= $m['phone'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="member_lookup_result" class="mt-2 small"></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 text-start">
                            <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi (Amount)' : 'Amount' ?></label>
                            <input type="number" name="amount" class="form-control border-primary" required placeholder="0.00">
                        </div>
                        <div class="col-12 text-start">
                            <label class="form-label fw-bold text-primary"><i class="bi bi-camera-fill me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakia Risiti / Uthibitisho' : 'Upload Receipt / Proof' ?></label>
                            <input type="file" name="evidence" class="form-control bg-light" accept="image/*">
                            <small class="text-muted small italic">Select an image from your device.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-5 shadow"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'HIFADHI SASA' : 'SUBMIT PAYMENT' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 1: Upload from Existing Report -->
<div class="modal fade" id="uploadReportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Upload Contribution Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="reportUploadForm" action="<?= getUrl('actions/import_contributions') ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="upload_type" value="existing_report">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">Upload a CSV or Excel file containing member contributions. Identification will be done using <b>Phone Number</b> or <b>Member ID</b>.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select File (CSV/Excel)</label>
                        <input type="file" name="upload_file" class="form-control" accept=".csv, .xls, .xlsx" required>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i> Ensure the file has columns for: <b>Phone/ID</b> and <b>Amount</b>.
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4 rounded-pill">Start Uploading</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal 2: Upload from M-Koba Statement -->
<div class="modal fade" id="uploadMKobaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-phone-vibrate me-2"></i>Upload M-Koba Statement</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="mkobaUploadForm" action="<?= getUrl('actions/import_contributions') ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="upload_type" value="mkoba_statement">
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">Upload your M-Koba transaction statement. The system will automatically map transactions to <b>Active Members</b> based on their Phone Numbers.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Statement File</label>
                        <input type="file" name="upload_file" class="form-control" accept=".csv, .xls, .xlsx, .pdf" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4 rounded-pill">Process Statement</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select2 Initialization for Member Selection
$('#manualAddModal').on('shown.bs.modal', function () {
    $('#member_select2').select2({
        dropdownParent: $('#manualAddModal'),
        placeholder: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '-- Tafuta kwa Jina au Namba --' : '-- Search by Name or Phone --' ?>',
        allowClear: true
    });
});

// Update lookup result visual when selection changes
$('#member_select2').on('change', function() {
    const val = $(this).val();
    const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    if(val) {
        const text = $("#member_select2 option:selected").text();
        $('#member_lookup_result').html(`<div class="alert alert-success py-1 mt-2 small border-success text-start"><i class="bi bi-person-check-fill me-1"></i> ${text}</div>`);
    } else {
        $('#member_lookup_result').empty();
    }
});

// Pending Approvals DataTable Initialization
$(document).ready(function() {
    const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    $('#pendingTable').DataTable({
        "paging": true,
        "ordering": true,
        "info": false,
        "responsive": true,
        "pageLength": 5,
        "dom": 'rtp', // Only table and pagination
        "language": {
            "search": "_INPUT_",
            "searchPlaceholder": isSw ? "Tafuta inayosubiri..." : "Search pending...",
            "url": 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/' + (isSw ? 'sw.json' : 'en-GB.json')
        }
    });

    // Custom Search for Pending Table
    let pendingSearch = $('<div class="input-group input-group-sm" style="width: 200px;"><span class="input-group-text bg-transparent border-white text-white"><i class="bi bi-search"></i></span><input type="text" class="form-control bg-transparent border-white text-white placeholder-white" placeholder="Search..."></div>');
    $('#pending-search-container').append(pendingSearch);
    pendingSearch.find('input').on('keyup', function() {
        $('#pendingTable').DataTable().search($(this).val()).draw();
    });
});

// DataTables AJAX Initialization
$(document).ready(function() {
    const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    const currentUsername = '<?= htmlspecialchars($username) ?>';
    const currentUserRole = '<?= htmlspecialchars($user_role) ?>';
    const currentDate = new Date().toLocaleDateString(isSw ? 'sw-TZ' : 'en-US', { day: 'numeric', month: 'long', year: 'numeric' });
    const currentTime = new Date().toLocaleTimeString(isSw ? 'sw-TZ' : 'en-US', { hour: '2-digit', minute: '2-digit' });

    let ledgerTable = $('#ledgerTable').DataTable({
        "processing": true,
        "serverSide": true,
        "responsive": true,
        "ajax": {
            "url": "<?= getUrl('api/get_contribution_ledger') ?>?block=<?= $block ?>",
            "type": "GET"
        },
        "columns": [
            { "data": "sno", "width": "30px" },
            { "data": "name", "className": "text-start fw-bold" }, /* Let name be flexible but dominant */
            { "data": "phone", "className": "small text-muted", "width": "100px" },
            { "data": "periods.0", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.1", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.2", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "periods.3", "render": (v) => v > 0 ? '<b>'+v.toLocaleString()+'</b>' : '<span class="opacity-25">—</span>' },
            { "data": "block_total", "render": (v) => '<b style="color:black; font-weight:900;">'+v.toLocaleString()+'</b>', "className": "bg-white text-center" },
            { "data": "grand_total", "render": (v) => '<b style="color:#0d6efd; font-weight:900;">'+v.toLocaleString()+'</b>', "className": "bg-white text-center fs-6" },
            { 
                "data": "customer_id", 
                "className": "no-print",
                "render": (id) => `
                    <div class="dropdown no-print">
                        <button class="btn btn-white btn-sm border shadow-sm dropdown-toggle rounded-2 px-3" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear-fill me-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                            <li><a class="dropdown-item py-2 rounded-2 small fw-bold" href="<?= getUrl('member_statement') ?>?id=${id}"><i class="bi bi-eye me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'View (Tazama)' : 'View Statement' ?></a></li>
                        </ul>
                    </div>
                ` 
            }
        ],
        "dom": 'Brtip', // Buttons, Table, Info, Pagination (Removed default Length 'l' and Filter 'f')
        "buttons": [
            {
                extend: 'print',
                title: '', /* Remove default title completely */
                header: true,
                text: '<i class="bi bi-printer me-1"></i> PRINT',
                className: 'btn btn-white border shadow-sm rounded-2 px-3 py-1 small fw-bold text-dark',
                exportOptions: {
                    columns: ':not(.no-print)'
                },
                customize: function (win) {
                    // 1. HIDDEN BROWSER HEADERS & CUSTOM VIRTUAL MARGINS
                    $(win.document.head).append(`
                        <style>
                            /* Kill browser-auto headers (Title/URL) */
                            @page { margin: 0 !important; size: auto; }
                            
                            /* Force absolute symmetry and full width */
                            * { box-sizing: border-box !important; }
                            html, body { width: 100% !important; margin: 0 !important; padding: 0 !important; }
                            
                            body { 
                                padding: 1.0cm 0.3cm 1.8cm 0.3cm !important; 
                                font-size: 11pt !important;
                                background: white !important;
                                overflow: hidden !important;
                            }
                            
                            /* Force Table to occupy 100% Symmetrically */
                            table { 
                                width: 100% !important; 
                                border-collapse: collapse !important; 
                                margin: 0 !important;
                            }
                            table th, table td { 
                                border: 1px solid #000 !important; 
                                padding: 6px 4px !important; 
                                text-align: center !important; 
                                vertical-align: middle !important;
                                font-size: 10.5pt !important;
                            }
                            table th { background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; text-transform: uppercase; font-size: 11pt !important; }
                            table td { word-break: break-all !important; }

                            /* Persistent Branded Footer */
                            .print-footer {
                                position: fixed;
                                bottom: 0.5cm; 
                                left: 0.3cm;
                                right: 0.3cm;
                                text-align: center;
                                background: white !important;
                                border-top: 1px solid #dee2e6;
                                font-size: 8pt;
                                page-break-after: avoid !important;
                            }
                            
                            tfoot.print-spacer { display: table-footer-group; }
                            tfoot.print-spacer td { height: 15px !important; border: none !important; }
                            
                            .no-print { display: none !important; }
                            table { page-break-after: auto !important; margin-bottom: 0 !important; }
                        </style>
                    `);

                    // 2. Clear out any browser-injected title
                    $(win.document.body).find('h1').remove();

                    // 3. Branded Header (Positioned within manual margins)
                    $(win.document.body).prepend(`
                        <div class="text-center mb-5">
                            <img src="/assets/images/<?= $group_logo ?>" style="height: 85px; width: auto; margin-bottom: 12px;">
                            <h2 class="fw-bold mb-1" style="color: #0d6efd !important; text-transform: uppercase; font-family: sans-serif;"><?= $group_name ?></h2>
                            <h4 class="fw-bold text-dark border-top border-bottom py-2 mt-2" style="font-family: sans-serif;"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MCHANGANUO WA MICHANGO (LEDGER)' : 'CONTRIBUTION ANALYSIS LEDGER' ?></h4>
                             <p class="small text-muted mt-2"><b>Block Period:</b> <?= $columns[0]['label'] ?> — <?= $columns[3]['label'] ?></p>
                        </div>
                    `);

                   <!-- 4. PRINT FOOTER (Visible only during print) -->
<div class="d-none d-print-block print-footer">
    <div class="row pt-2 text-center">
        <div class="col-12">
            <p class="mb-1 text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyaraka hii imechapishwa na' : 'This document was printed by' ?> <strong><?= htmlspecialchars($username ?? $_SESSION['username']) ?></strong> - <strong><?= htmlspecialchars($user_role ?? 'Member') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'mnamo' : 'on' ?> <strong><?= date('d M, Y') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'saa' : 'at' ?> <strong><?= date('H:i:s') ?></strong></p>
            <h6 class="mb-0 fw-bold" style="color: #0d6efd !important;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</h6>
        </div>
    </div>
</div>

                    // 5. Spacer for multi-page data integrity
                    $(win.document.body).find('table').append('<tfoot class="print-spacer"><tr><td colspan="10">&nbsp;</td></tr></tfoot>');
                    
                    $(win.document.body).find('.no-print').remove();
                }
            },
            {
                extend: 'csv',
                text: '<i class="bi bi-filetype-csv me-1"></i> CSV',
                className: 'btn btn-white border shadow-sm rounded-2 px-3 py-1 small fw-bold text-dark'
            }
        ],
        "pageLength": 25,
        "language": {
            "url": 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/' + (isSw ? 'sw.json' : 'en-GB.json')
        }
    });

    // Custom Length Menu for Ledger
    let ledgerLength = $(`
        <div class="input-group input-group-sm shadow-sm border rounded-2 bg-white overflow-hidden">
            <span class="input-group-text bg-white border-0"><i class="bi bi-view-list"></i></span>
            <select class="form-select border-0 bg-transparent fw-bold" style="cursor: pointer;">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="-1">All</option>
            </select>
        </div>
    `);
    $('#ledger-length-container').append(ledgerLength);
    ledgerLength.find('select').on('change', function() {
        ledgerTable.page.len($(this).val()).draw();
    });

    // Custom Search for Ledger
    let ledgerSearch = $(`<div class="input-group shadow-sm border rounded-2 bg-white overflow-hidden"><span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span><input type="text" class="form-control border-0 px-3" placeholder="${isSw ? 'Tafuta...' : 'Search...'}"></div>`);
    $('#ledger-search-container').append(ledgerSearch);
    ledgerSearch.find('input').on('keyup', function() {
        ledgerTable.search($(this).val()).draw();
    });

    // Move buttons
    setTimeout(() => {
        $('.dt-buttons').appendTo('#ledger-tools-left');
    }, 50);
});

// Removed auto-lookup keyup trigger to ensure modal stability
$('#manualAddForm').on('submit', function(e) {
    e.preventDefault();
    if (!$('#member_select2').val()) { 
        Swal.fire(
            (<?= json_encode($_SESSION['preferred_language'] ?? 'en') ?> === 'sw') ? 'Kosa' : 'Error', 
            (<?= json_encode($_SESSION['preferred_language'] ?? 'en') ?> === 'sw') ? 'Tafadhali chagua mwanachama sahihi kwanza.' : 'Please select a valid member.', 
            'error'
        ); 
        return; 
    }
    
    let formData = new FormData(this);
    
    $.ajax({
        url: '<?= getUrl("actions/process_contribution") ?>',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                Swal.fire('Submitted!', res.message, 'info').then(() => location.reload());
            } else { Swal.fire('Error', res.message, 'error'); }
        }
    });
});

function approveContribution(id) {
    Swal.fire({
        title: 'Approve Payment?',
        text: 'This will move the payment to confirmed status.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd'
    }).then((res) => {
        if (res.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("actions/update_contribution") ?>',
                type: 'POST',
                data: { id: id, status: 'confirmed' },
                dataType: 'json',
                success: function(r) { location.reload(); }
            });
        }
    });
}

function rejectContribution(id) {
    Swal.fire({
        title: 'Reject Payment?',
        text: 'This will remove/cancel this entry.',
        icon: 'warning',
        confirmButtonColor: '#6c757d'
    }).then((res) => {
        if (res.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("actions/update_contribution") ?>',
                type: 'POST',
                data: { id: id, status: 'cancelled' },
                dataType: 'json',
                success: function(r) { location.reload(); }
            });
        }
    });
}
</script>

<style>
.table-responsive { scrollbar-width: thin; }
th { font-size: 0.7rem !important; }
#ledgerTable td { font-size: 0.85rem; }
#ledgerTable th:nth-child(2), #ledgerTable td:nth-child(2) {
    min-width: 140px !important; /* Member column iwe na nafasi ya kutosha lakini isizidi */
}
#ledgerTable th:nth-child(3), #ledgerTable td:nth-child(3) {
    white-space: nowrap !important; /* Phone No isikatwe */
}
.bg-primary-subtle { background-color: #e7f1ff !important; }

/* Autocomplete Premium Styles */
#autocomplete_results .list-group-item {
    border-left: 0; border-right: 0;
    transition: all 0.2s ease;
    cursor: pointer;
    font-size: 0.85rem;
}
#autocomplete_results .list-group-item:hover {
    background-color: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding-left: 0.75rem !important;
}
.bg-success-subtle { background-color: #d1e7dd !important; }
.bg-warning-subtle { background-color: #fff3cd !important; }

/* White Buttons for DataTables */
.btn-white {
    background: #fff !important;
    border: 1px solid #dee2e6 !important;
    color: #333 !important;
    font-size: 0.75rem !important;
    text-transform: uppercase;
    transition: all 0.3s;
}
.btn-white:hover {
    background: #f8f9fa !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1) !important;
    transform: translateY(-1px);
}
.dt-buttons { gap: 10px; display: flex; }
.dataTables_length select {
    border-radius: 5px;
    padding: 0.15rem 1.75rem 0.15rem 0.75rem;
    border: 1px solid #dee2e6;
    font-size: 0.75rem;
    font-weight: bold;
    color: #333;
    background-color: #fff;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}
.dataTables_length label {
    font-size: 0.75rem;
    font-weight: bold;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}
.dataTables_length select {
    margin: 0 !important;
}
@media print {
    .no-print { display: none !important; }
}

/* Mobile View Optimization (Kwa ajili ya simu tu) */
@media (max-width: 768px) {
    * { box-sizing: border-box !important; }
    .table-responsive {
        overflow-x: hidden !important; /* Kata scrolling ya pembeni */
        padding: 0 !important;
        margin: 0 !important;
    }
    .table {
        width: 100% !important;
        max-width: 100% !important;
        font-size: 0.72rem !important; /* Punguza kidogo zaidi kutoshea vizuri */
        margin-bottom: 0 !important;
    }
    .table th, .table td {
        padding: 8px 2px !important; /* Bana nafasi pembeni sana kuzuia kukatwa */
        white-space: normal !important; 
        word-break: break-all !important;
    }
    .table th {
        font-size: 0.65rem !important;
        white-space: nowrap !important; /* Headers zibaki mstari mmoja kama inawezekana */
    }
    /* Zuia kukata herufi (Truncation protection) */
    .table td div, .table td span {
        overflow: visible !important;
        text-overflow: clip !important;
        white-space: normal !important;
    }

    /* Compact Buttons and Selects for Mobile */
    .btn-white {
        padding: 4px 10px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        display: flex !important;
        align-items: center !important;
    }
    .dt-buttons {
        gap: 4px !important;
        display: flex !important;
        align-items: center !important;
    }
    #ledger-length-container .input-group {
        width: auto !important;
        height: 28px !important;
        flex-wrap: nowrap !important;
    }
    #ledger-length-container .form-select {
        padding: 0 20px 0 8px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        min-height: 28px !important;
        border: none !important;
    }
    #ledger-length-container .input-group-text {
        padding: 0 6px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
        background: white !important;
        border: none !important;
    }
    #ledger-search-container .input-group {
        width: 150px !important; /* Made slightly longer for mobile */
        height: 28px !important;
    }
    #ledger-search-container input {
        padding: 4px 8px !important;
        font-size: 0.65rem !important;
        height: 28px !important;
    }

    /* Buttons na Inputs ziwe rafiki kwa simu */
    h2 { font-size: 1.4rem !important; }
    .card-body { padding: 10px !important; }
    #pending-search-container { display: none !important; } /* Hide compact search on mobile to save space */
}

/* Web View Specific Overrides */
@media (min-width: 769px) {
    #ledger-search-container .input-group {
        width: 220px !important; /* Keep search bar short on web */
        float: right;
    }
}

.placeholder-white::placeholder { color: rgba(255,255,255,0.7) !important; }
</style>

<?php
$content = ob_get_clean();
echo $content;
require_once 'footer.php';
?>
