<?php
// File: app/audit_logs.php  (Activity Logs - Full View)
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}

if (!isAdmin()) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

require_once ROOT_DIR . '/includes/activity_logger.php';
$lang = $_SESSION['preferred_language'] ?? 'en';
$isSw = ($lang === 'sw');

// ── Filters ──────────────────────────────────────────────────────────────────
$limit = isset($_GET['limit']) ? ($_GET['limit'] === 'all' ? -1 : (int)$_GET['limit']) : 25;
if (!in_array($limit, [10, 25, 50, 100, -1])) $limit = 25;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($limit === -1) ? 0 : ($page - 1) * $limit;

$type_filter    = trim($_GET['type'] ?? '');
$user_id_filter = trim($_GET['user_id'] ?? '');
$date_from      = trim($_GET['date_from'] ?? '');
$date_to        = trim($_GET['date_to'] ?? '');

// ── Build query ───────────────────────────────────────────────────────────────
$conditions = []; $params = [];
if ($type_filter)    { $conditions[] = "al.module = :mod";     $params[':mod']  = $type_filter; }
if ($user_id_filter) { $conditions[] = "al.user_id = :uid";    $params[':uid']  = $user_id_filter; }
if ($date_from)      { $conditions[] = "al.created_at >= :df"; $params[':df']   = $date_from . ' 00:00:00'; }
if ($date_to)        { $conditions[] = "al.created_at <= :dt"; $params[':dt']   = $date_to   . ' 23:59:59'; }

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
$base  = "FROM activity_logs al LEFT JOIN users u ON al.user_id = u.user_id";

try {
    $users = $pdo->query("SELECT user_id, first_name, last_name FROM users ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

    $base  = "FROM activity_logs al LEFT JOIN users u ON al.user_id = u.user_id LEFT JOIN roles r ON u.role_id = r.role_id";

    $cnt_stmt = $pdo->prepare("SELECT COUNT(*) $base $where");
    foreach ($params as $k => $v) $cnt_stmt->bindValue($k, $v);
    $cnt_stmt->execute();
    $total_items = (int)$cnt_stmt->fetchColumn();
    $total_pages = ($limit === -1) ? 1 : (int)ceil($total_items / max(1,$limit));

    $limit_sql = ($limit === -1) ? '' : ' LIMIT :lmt OFFSET :ofs';
    $sql = "SELECT al.id, al.action, al.module, al.description, al.reference,
                   al.ip_address, al.created_at, al.user_id as u_id,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) as full_name,
                   u.username, COALESCE(r.role_name, 'System') as role_name
            $base $where ORDER BY al.created_at DESC $limit_sql";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    if ($limit !== -1) {
        $stmt->bindValue(':lmt', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':ofs', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $types = $pdo->query("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL AND module != '' ORDER BY module")
                 ->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error = $e->getMessage();
    $activities = []; $users = []; $types = []; $total_items = 0; $total_pages = 1;
}

// ── Helper: action → badge info ───────────────────────────────────────────────
function getActionBadge(string $action, bool $isSw): array {
    return match(strtolower($action)) {
        'viewed'  => ['color' => 'info',      'label' => $isSw ? 'Tazama'  : 'View'],
        'created' => ['color' => 'success',   'label' => $isSw ? 'Ongeza'  : 'Add'],
        'updated' => ['color' => 'warning',   'label' => $isSw ? 'Hariri'  : 'Edit'],
        'deleted' => ['color' => 'danger',    'label' => $isSw ? 'Futa'    : 'Delete'],
        'login'   => ['color' => 'primary',   'label' => $isSw ? 'Ingia'   : 'Login'],
        'logout'  => ['color' => 'secondary', 'label' => $isSw ? 'Toka'    : 'Logout'],
        default   => ['color' => 'secondary', 'label' => ucfirst($action)],
    };
}

// ── Render a single row ───────────────────────────────────────────────────────
function renderActivityRow(array $a, bool $isSw, int $index): string {
    $badge    = getActionBadge($a['action'] ?? '', $isSw);
    $color    = $badge['color'];
    $label    = $badge['label'];

    // Full Name + Role for User column → "Katibu (Monica Kijembe)"
    $fullname = trim($a['full_name'] ?? '') ?: ($a['username'] ?? ($isSw ? 'Mfumo' : 'System'));
    $role     = $a['role_name'] ?? 'System';
    $user_str = htmlspecialchars("$role ($fullname)");

    $desc_raw = $a['description'] ?: '';
    if (empty($desc_raw)) {
        $action_str = strtolower($a['action'] ?? '');
        $module_str = $a['module'] ?? '';
        $desc_raw = match($action_str) {
            'viewed'  => $isSw ? "Alitazama ukurasa wa: $module_str" : "Viewed: $module_str page",
            'created' => $isSw ? "Aliunda rekodi mpya kwenye: $module_str" : "Created new record in: $module_str",
            'updated' => $isSw ? "Alibadilisha rekodi kwenye: $module_str" : "Updated record in: $module_str",
            'deleted' => $isSw ? "Alifuta rekodi kutoka: $module_str" : "Deleted record from: $module_str",
            'login'   => $isSw ? "Ameingia kwenye mfumo" : "Logged into the system",
            'logout'  => $isSw ? "Ametoka kwenye mfumo" : "Logged out of the system",
            default   => $a['action'] ?? '-',
        };
    }
    $desc_esc = htmlspecialchars($desc_raw);

    $ref_raw  = $a['reference'] ?: '-';
    $ref_esc  = htmlspecialchars($ref_raw);

    $time     = date('d/m/y H:i', strtotime($a['created_at']));
    $icon     = getBadgeIcon($label);

    return "
    <tr>
        <td class='ps-3 text-muted small'>$index.</td>
        <td><small class='fw-bold text-muted'>$time</small></td>
        <td>
            <span class='badge text-bg-$color rounded-pill px-3 py-1' style='font-size:11px'>
                <i class='bi bi-$icon me-1'></i>$label
            </span>
        </td>
        <td class='text-dark small'>$desc_esc</td>
        <td><span class='badge bg-light text-secondary border rounded-2 px-2 fw-normal small'>$ref_esc</span></td>
        <td class='pe-3 small fw-semibold text-dark'>$user_str</td>
    </tr>
    ";
}

function getBadgeIcon(string $label): string {
    return match(strtolower($label)) {
        'view', 'tazama' => 'eye',
        'add', 'ongeza'  => 'plus-circle',
        'edit', 'hariri' => 'pencil',
        'delete', 'futa' => 'trash',
        'login', 'ingia' => 'box-arrow-in-right',
        'logout', 'toka' => 'box-arrow-right',
        default          => 'activity',
    };
}

// ── AJAX handler ──────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    ob_start();
    foreach ($activities as $i => $a) {
        echo renderActivityRow($a, $isSw, ($offset + $i + 1));
    }
    if (empty($activities)) {
        echo '<tr><td colspan="5" class="text-center py-5 text-muted"><i class="bi bi-journal-x fs-3 d-block mb-2"></i>' 
           . ($isSw ? 'Hakuna shughuli zilizopatikana' : 'No activity records found') . '</td></tr>';
    }
    $rows = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'rows'    => $rows,
        'info'    => ($total_items > 0 ? $offset + 1 : 0) . ' - '
                   . ($limit === -1 ? $total_items : min($offset + $limit, $total_items))
                   . ($isSw ? " ya $total_items" : " of $total_items"),
        'total_pages' => $total_pages
    ]);
    exit;
}

require_once ROOT_DIR . '/header.php';
?>

<style>
    /* Force full screen width by overriding any parent .container constraints */
    .container, .container-lg, .container-md, .container-sm, .container-xl, .container-xxl { 
        max-width: 100% !important; 
        width: 100% !important; 
        padding-left: 20px !important; 
        padding-right: 20px !important; 
    }
    html, body { overflow-x: hidden !important; width: 100%; }
    .main-logs-content { width: 100% !important; display: block; }
    #activityTable { width: 100% !important; table-layout: auto; }
    #activityTable td { word-break: break-word; white-space: normal; vertical-align: middle; }
    
    /* Make columns flexible */
    .col-sno { width: 50px; }
    .col-time { width: 140px; }
    .col-action { width: 120px; }
    .col-ref { width: 150px; }
    .col-user { width: 220px; }
</style>

<div class="main-logs-content py-4">
    <!-- Print Header -->
    <?= getPrintHeader($isSw ? 'KUMBUKUMBU ZA SHUGHULI ZA MFUMO' : 'SYSTEM ACTIVITY LOGS') ?>
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 no-print">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-activity text-primary me-2"></i>
                <?= $isSw ? 'Kumbukumbu za Shughuli' : 'Activity Logs' ?>
            </h3>
            <p class="text-muted small mb-0">
                <?= $isSw ? 'Fuatilia kila tendo linalofanyika kwenye mfumo' : 'Track every action performed in the system' ?>
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="<?= getUrl('dashboard') ?>" class="btn btn-primary px-4 rounded-pill shadow-sm fw-bold">
                <i class="bi bi-arrow-left me-1"></i><?= $isSw ? 'Rudi Nyumbani' : 'Back to Dashboard' ?>
            </a>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 no-print">
        <div class="card-body p-4">
            <form id="filterForm" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Aina' : 'Type' ?></label>
                    <select class="form-select border-0 bg-light rounded-3" name="type">
                        <option value=""><?= $isSw ? 'Zote' : 'All Types' ?></option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t ?>" <?= $type_filter == $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Mtumiaji' : 'User' ?></label>
                    <select class="form-select border-0 bg-light rounded-3" name="user_id">
                        <option value=""><?= $isSw ? 'Watumiaji Wote' : 'All Users' ?></option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $user_id_filter == $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Tarehe (Kuanzia)' : 'Date From' ?></label>
                    <input type="date" class="form-control border-0 bg-light rounded-3" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Tarehe (Hadi)' : 'Date To' ?></label>
                    <input type="date" class="form-control border-0 bg-light rounded-3" name="date_to" value="<?= $date_to ?>">
                </div>

                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-dark rounded-3 fw-bold py-2 shadow-sm">
                        <i class="bi bi-funnel me-1"></i><?= $isSw ? 'Chuja' : 'Apply' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar (Print, Export, Show) -->
    <div class="d-flex align-items-stretch gap-2 mb-3 no-print">
        <button onclick="printAndLog()" class="btn btn-sm btn-white border shadow-sm rounded-1 px-3 fw-bold" style="background: white;">
            <i class="bi bi-printer text-primary me-1"></i><?= $isSw ? 'Chapa' : 'Print' ?>
        </button>
        <button onclick="logAndExport()" class="btn btn-sm btn-white border shadow-sm rounded-1 px-3 fw-bold" style="background: white;">
            <i class="bi bi-file-earmark-arrow-down text-success me-1"></i><?= $isSw ? 'Pakua' : 'Export' ?>
        </button>
        <div style="background: white;" class="border shadow-sm rounded-1 px-2 d-flex align-items-center">
            <span class="small fw-bold text-muted me-2" style="white-space: nowrap;"><?= $isSw ? 'Idadi:' : 'Show:' ?></span>
            <select form="filterForm" name="limit" class="form-select form-select-sm border-0 fw-bold" style="cursor: pointer; box-shadow: none;" onchange="$('#filterForm').submit()">
                <?php foreach ([10=>10, 25=>25, 50=>50, 100=>100, -1=>($isSw?'Zote':'All')] as $val=>$lbl): ?>
                    <option value="<?= $val ?>" <?= $limit==$val ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Activity Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4 shadow">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="activityTable">
                    <thead class="bg-light">
                        <tr class="text-uppercase small fw-bold text-muted">
                            <th class="ps-3 py-3" style="width: 5%"><?= $isSw ? 'S/No' : 'S/No' ?></th>
                            <th class="py-3" style="min-width: 140px; width:15%"><?= $isSw ? 'Tarehe na Saa' : 'Time' ?></th>
                            <th style="min-width: 100px; width:12%"><?= $isSw ? 'Kitendo' : 'Action' ?></th>
                            <th style="min-width: 280px; width:33%"><?= $isSw ? 'Maelezo ya Shughuli' : 'Activity Description' ?></th>
                            <th style="min-width: 150px; width:15%"><?= $isSw ? 'Kumbukumbu' : 'Reference' ?></th>
                            <th class="pe-3" style="min-width: 200px; width:20%"><?= $isSw ? 'Mtumiaji (Wadhifa)' : 'User (Role)' ?></th>
                        </tr>
                    </thead>
                    <tbody id="activityRows">
                        <?php foreach ($activities as $i => $a):
                            echo renderActivityRow($a, $isSw, ($offset + $i + 1));
                        endforeach; ?>
                        <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-2 d-block mb-2"></i>
                                <?= $isSw ? 'Hakuna shughuli zilizopatikana' : 'No activity records found' ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modern Pagination -->
    <div class="mt-4 no-print">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <!-- Info -->
            <div id="paginationInfo" class="text-muted small fw-semibold px-1">
                <?php
                $showing_from = $total_items > 0 ? $offset + 1 : 0;
                $showing_to   = $limit === -1 ? $total_items : min($offset + $limit, $total_items);
                ?>
                <?= $isSw
                    ? "Inaonyesha <strong>$showing_from</strong> hadi <strong>$showing_to</strong> kati ya <strong>$total_items</strong>"
                    : "Showing <strong>$showing_from</strong> to <strong>$showing_to</strong> of <strong>$total_items</strong>"
                ?>
            </div>
            <!-- Page Buttons -->
            <?php if ($total_pages > 1): ?>
            <nav>
                <div class="d-flex align-items-center gap-1" id="paginationNav">
                    <?php
                    $window = 2;
                    $start  = max(1, $page - $window);
                    $end    = min($total_pages, $page + $window);
                    ?>
                    <!-- Prev -->
                    <button class="btn btn-sm <?= $page <= 1 ? 'btn-light text-muted disabled' : 'btn-outline-primary' ?> rounded-3 px-3"
                            onclick="loadPage(<?= max(1, $page - 1) ?>)">
                        <i class="bi bi-chevron-left"></i>
                    </button>

                    <?php if ($start > 1): ?>
                        <button class="btn btn-sm btn-outline-secondary rounded-3 px-3" onclick="loadPage(1)">1</button>
                        <?php if ($start > 2): ?>
                            <span class="px-1 text-muted">…</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <button class="btn btn-sm <?= $i == $page ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-3 px-3 page-btn"
                                onclick="loadPage(<?= $i ?>)"><?= $i ?></button>
                    <?php endfor; ?>

                    <?php if ($end < $total_pages): ?>
                        <?php if ($end < $total_pages - 1): ?>
                            <span class="px-1 text-muted">…</span>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-secondary rounded-3 px-3" onclick="loadPage(<?= $total_pages ?>)"><?= $total_pages ?></button>
                    <?php endif; ?>

                    <!-- Next -->
                    <button class="btn btn-sm <?= $page >= $total_pages ? 'btn-light text-muted disabled' : 'btn-outline-primary' ?> rounded-3 px-3"
                            onclick="loadPage(<?= min($total_pages, $page + 1) ?>)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </nav>
            <?php endif; ?>
        </div>
    <!-- Print Footer -->
    <?= getPrintFooter() ?>
</div>

<style>
.table thead th { font-size: 11px; text-transform: uppercase; font-weight: 700; color: #64748b; letter-spacing: .05em; }
.pagination .page-link { color: #475569; font-weight: 700; }
.pagination .page-item.active .page-link { background: #2563eb; color: #fff; border-color: #2563eb; }

@media print {
    /* ── Hide non-printable elements ── */
    .no-print,
    nav, header, footer,
    #paginationNav, #filterForm,
    .pagination { display: none !important; }

    /* ── Layout reset ── */
    body, html { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    .main-logs-content { padding: 0 !important; }
    .container, .container-fluid { max-width: 100% !important; padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
    .card-body { padding: 0 !important; }
    .table-responsive { overflow: visible !important; }

    /* Standard print header/footer classes are handled by helpers.php */

    /* ── Table styles ── */
    #activityTable {
        width: 100% !important;
        font-size: 9px !important;
        border-collapse: collapse !important;
        table-layout: fixed !important;
    }
    #activityTable thead tr { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; }
    #activityTable th,
    #activityTable td {
        padding: 4px 5px !important;
        border: 1px solid #cbd5e1 !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        white-space: normal !important;
        vertical-align: top !important;
    }

    /* ── Column proportions (work for both landscape & portrait) ── */
    #activityTable th:nth-child(1), #activityTable td:nth-child(1) { width: 4% !important; }   /* S/No */
    #activityTable th:nth-child(2), #activityTable td:nth-child(2) { width: 13% !important; }  /* Time */
    #activityTable th:nth-child(3), #activityTable td:nth-child(3) { width: 10% !important; }  /* Action */
    #activityTable th:nth-child(4), #activityTable td:nth-child(4) { width: 36% !important; }  /* Description */
    #activityTable th:nth-child(5), #activityTable td:nth-child(5) { width: 15% !important; }  /* Reference */
    #activityTable th:nth-child(6), #activityTable td:nth-child(6) { width: 22% !important; }  /* User (Role) */

    /* ── Badge print fix ── */
    .badge { border: 1px solid #ccc !important; color: #000 !important; background: #f8f8f8 !important;
             padding: 1px 4px !important; border-radius: 3px !important; font-size: 8px !important; }
}
</style>

<script>
var currentPage = <?= $page ?>;
function loadPage(page) {
    currentPage = page;
    const fdata = $('#filterForm').serialize();
    $.get('<?= getUrl('activity-logs') ?>?ajax=1&page=' + page + '&' + fdata, function(res) {
        if (res.success) {
            $('#activityRows').html(res.rows);
            $('#paginationInfo').text(res.info);
            $('#paginationNav li').removeClass('active');
            $('#paginationNav li').each(function(i) {
                if (i + 1 === page) $(this).addClass('active');
            });
        }
    });
}
$('#filterForm').submit(function(e) {
    e.preventDefault();
    loadPage(1);
});

// ── Activity Log: Print & Export ──────────────────────────────────────────────
function printAndLog() {
    $.post('<?= getUrl("api/log_action") ?>', {
        action: 'Printed',
        module: 'Activity Logs',
        description: '<?= $isSw ? "Alipiga chapa ripoti ya shughuli za mfumo" : "Printed activity logs report" ?>',
        reference: 'ACTIVITY-LOGS'
    }).always(function() { window.print(); });
}

function logAndExport() {
    $.post('<?= getUrl("api/log_action") ?>', {
        action: 'Exported',
        module: 'Activity Logs',
        description: '<?= $isSw ? "Alipakua ripoti ya shughuli (CSV)" : "Exported activity logs report (CSV)" ?>',
        reference: 'ACTIVITY-LOGS'
    }).always(function() { exportTableToCSV(); });
}

window.exportTableToCSV = () => {
    let csv = [];
    const rows = document.querySelectorAll("#activityTable tr");
    for (const row of rows) {
        let cols = row.querySelectorAll("td, th");
        let data = [];
        for (let i = 0; i < cols.length; i++) {
            data.push('"' + cols[i].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(data.join(","));
    }
    const blob = new Blob([csv.join("\n")], { type: "text/csv" });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.setAttribute("href", url);
    a.setAttribute("download", "<?= $isSw ? 'kumbukumbu_za_shughuli.csv' : 'activity_logs.csv' ?>");
    a.click();
};
// ─────────────────────────────────────────────────────────────────────────────

</script>

<?php require_once ROOT_DIR . '/footer.php'; ?>
