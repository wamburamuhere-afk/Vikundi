<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';
date_default_timezone_set('Africa/Nairobi');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$title = $is_sw ? 'Wanachama Wasiofanya Kazi' : 'Dormant Member Management';
$subtitle = $is_sw ? 'Orodha na maelezo ya wanachama wote dormant' : 'List and details of all Dormant members';

if (!defined('ROOT_DIR')) {
    exit('No direct script access allowed');
}

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$title = $is_sw ? 'Wanachama Wasiofanya Kazi / Dormant' : 'Dormant Members';

// Fetch dormant members
$query = "
    SELECT 
        u.user_id, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.status as user_status, u.user_role, u.created_at,
        c.customer_id, c.phone, c.address, c.nida_number, c.is_deceased
    FROM users u
    LEFT JOIN customers c ON u.user_id = c.user_id
    WHERE (u.status NOT IN ('active', 'pending', 'suspended', 'deleted') OR u.status IS NULL OR u.status = '' OR c.is_deceased = 1) 
    AND u.user_role != 'Admin'
    ORDER BY u.first_name ASC
";
$stmt = $pdo->query($query);
$dormant_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats for cards
$total_dormant = count($dormant_members);
$deceased_count = count(array_filter($dormant_members, function($m) { return $m['is_deceased'] == 1; }));
$other_dormant = $total_dormant - $deceased_count;

// Breadcrumbs
$breadcrumbs = [
    ['label' => $is_sw ? 'Usimamizi' : 'Management', 'url' => getUrl('dashboard')],
    ['label' => $is_sw ? 'Wanachama' : 'Members', 'url' => getUrl('customers')],
    ['label' => $title, 'active' => true]
];
?>

<div class="vk-dashboard px-3 px-md-4 py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center bg-white p-3 rounded-4 shadow-sm border-start border-primary border-5">
                <div>
                    <h2 class="mb-1 fw-bold text-dark"><i class="bi bi-person-x text-primary"></i> <?= $title ?></h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <?php foreach ($breadcrumbs as $bc): ?>
                                <?php if ($bc['active'] ?? false): ?>
                                    <li class="breadcrumb-item active" aria-current="page"><?= $bc['label'] ?></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item"><a href="<?= $bc['url'] ?>"><?= $bc['label'] ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= getUrl('customers') ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                        <i class="bi bi-people-fill me-2"></i> <?= $is_sw ? 'Wanachama Walio Hai' : 'Active Members' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-6 mb-3">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background-color: #d1e7dd !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold text-dark"><?= $total_dormant ?></h4>
                            <p class="mb-0 small fw-bold text-muted"><?= $is_sw ? 'Jumla ya Dormant' : 'Total Dormant' ?></p>
                        </div>
                        <div class="p-3 rounded-circle" style="background-color: rgba(255,255,255,0.4);">
                            <i class="bi bi-people-fill text-success fs-3" style="opacity: 0.6;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 mb-3">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background-color: #d1e7dd !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold text-dark"><?= $deceased_count ?></h4>
                            <p class="mb-0 small fw-bold text-muted"><?= $is_sw ? 'Wanachama Waliokufa' : 'Deceased Members' ?></p>
                        </div>
                        <div class="p-3 rounded-circle" style="background-color: rgba(255,255,255,0.4);">
                            <i class="bi bi-heart-pulse-fill text-success fs-3" style="opacity: 0.6;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-md-6 mb-3">
            <div class="card shadow-sm border-0 h-100 rounded-4" style="background-color: #d1e7dd !important;">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold text-dark"><?= $other_dormant ?></h4>
                            <p class="mb-0 small fw-bold text-muted"><?= $is_sw ? 'Sababu Nyingine (Dormant)' : 'Other Reasons' ?></p>
                        </div>
                        <div class="p-3 rounded-circle" style="background-color: rgba(255,255,255,0.4);">
                            <i class="bi bi-exclamation-triangle-fill text-success fs-3" style="opacity: 0.6;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold text-dark Uppercase"><i class="bi bi-list-ul me-2"></i><?= $is_sw ? 'Orodha ya Dormant' : 'Dormant List' ?></h6>
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" id="dormantSearch" class="form-control border-start-0 bg-light shadow-none" placeholder="<?= $is_sw ? 'Tafuta mwanachama...' : 'Search dormant...' ?>">
                </div>
            </div>
            
            <!-- ACTION BUTTONS ROW -->
            <div class="d-flex align-items-center gap-2">
                <!-- 1. Print Button -->
                <button onclick="dtPrint()" class="btn btn-sm btn-white border shadow-sm px-3 py-2 text-dark" title="<?= $is_sw ? 'Chapa' : 'Print' ?>">
                    <i class="bi bi-printer fw-bold"></i>
                </button>

                <!-- 2. Export (Excel) -->
                <button onclick="dtExport()" class="btn btn-sm btn-white border shadow-sm px-3 py-2 text-dark" title="<?= $is_sw ? 'Pakua' : 'Export' ?>">
                    <i class="bi bi-file-earmark-excel fw-bold"></i>
                </button>

                <!-- 3. Length Menu (Show) -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-white border shadow-sm px-3 py-2 text-dark dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-list me-1 fw-bold"></i> <span id="lenText" class="fw-bold">10</span>
                    </button>
                    <ul class="dropdown-menu shadow border-0" id="lenMenu">
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="changeLen(10)">10</a></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="changeLen(25)">25</a></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="changeLen(50)">50</a></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="changeLen(100)">100</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item small" href="javascript:void(0)" onclick="changeLen(-1)"><?= $is_sw ? 'Zote' : 'All' ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive d-none d-md-block d-print-block">
                <table class="table table-hover align-middle mb-0" id="dormantTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 border-0 py-3" style="width: 60px;">S/NO</th>
                            <th class="border-0 py-3"><?= $is_sw ? 'Mwanachama' : 'Member' ?></th>
                            <th class="border-0 py-3"><?= $is_sw ? 'Simu' : 'Phone' ?></th>
                            <th class="border-0 py-3"><?= $is_sw ? 'Sababu' : 'Reason' ?></th>
                            <th class="border-0 py-3"><?= $is_sw ? 'Kuingia Mfumo' : 'Registered' ?></th>
                            <th class="pe-4 border-0 py-3 text-end"><?= $is_sw ? 'Maelezo' : 'Details' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dormant_members)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <div class="opacity-25 mb-2"><i class="bi bi-person-x" style="font-size: 3rem;"></i></div>
                                <?= $is_sw ? 'Hakuna mwanachama dormant kwa sasa.' : 'No dormant members found.' ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($dormant_members as $idx => $m): ?>
                        <tr>
                            <td class="ps-4 py-3 fw-bold text-muted"><?= $idx + 1 ?></td>
                            <td class="py-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-light p-2 rounded-circle me-3 text-primary fw-bold shadow-sm d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; border: 1px solid #e5e7eb;">
                                        <?= strtoupper(substr($m['first_name'] ?? 'U', 0, 1) . substr($m['last_name'] ?? 'N', 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($m['username'] ?? 'N/A') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="small fw-semibold text-dark"><?= htmlspecialchars($m['phone'] ?? '-') ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($m['email'] ?? '-') ?></div>
                            </td>
                            <td>
                                <?php if ($m['is_deceased']): ?>
                                <span class="badge bg-dark text-white rounded-pill px-3 py-2 small shadow-sm"><i class="bi bi-heart-pulse me-1"></i> <?= $is_sw ? 'Amefariki (Msiba)' : 'Deceased' ?></span>
                                <?php else: ?>
                                <span class="badge bg-primary text-white rounded-pill px-3 py-2 small shadow-sm"><i class="bi bi-clock-history me-1"></i> <?= $is_sw ? 'CONTRIBUTION DELAY' : 'CONTRIBUTION DELAY' ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted py-3"><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                            <td class="pe-4 text-end py-3">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-white border dropdown-toggle shadow-sm px-3" type="button" data-bs-toggle="dropdown" style="background-color: #fff; color: #495057;">
                                        <i class="bi bi-gear-fill text-secondary me-1"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('member_statement') ?>?id=<?= $m['customer_id'] ?>"><i class="bi bi-file-earmark-person text-success me-2"></i> <?= $is_sw ? 'Taarifa ya Fedha' : 'Financial Statement' ?></a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item py-2 text-danger fw-bold rounded" href="javascript:void(0)" onclick="deleteDormant(<?= $m['user_id'] ?>)"><i class="bi bi-trash3-fill me-2"></i> <?= $is_sw ? 'Mfute Kabisa' : 'Delete Member' ?></a></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- ═══ CARD VIEW — Mobile Only ═══ -->
            <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="dormantCardsWrapper">
                <?php if (empty($dormant_members)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-person-x fs-1 d-block mb-3"></i>
                    <p><?= $is_sw ? 'Hakuna mwanachama dormant kwa sasa.' : 'No dormant members found.' ?></p>
                </div>
                <?php else: foreach ($dormant_members as $m):
                    $dm_initials = strtoupper(substr($m['first_name'] ?? 'U', 0, 1) . substr($m['last_name'] ?? 'N', 0, 1));
                    $dm_deceased = !empty($m['is_deceased']);
                    $dm_av_color = $dm_deceased
                        ? 'linear-gradient(135deg,#343a40,#212529)'
                        : 'linear-gradient(135deg,#0d6efd,#0a58ca)';
                    $dm_search   = strtolower(
                        ($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '') . ' ' .
                        ($m['username'] ?? '') . ' ' . ($m['phone'] ?? '') . ' ' . ($m['email'] ?? '')
                    );
                ?>
                <div class="vk-member-card" data-search="<?= htmlspecialchars($dm_search) ?>">
                    <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <div class="vk-card-avatar" style="background:<?= $dm_av_color ?>;"><?= $dm_initials ?></div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:13px;"><?= htmlspecialchars(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($m['username'] ?? '') ?></small>
                            </div>
                        </div>
                        <?php if ($dm_deceased): ?>
                        <span class="badge bg-dark text-white rounded-pill px-2" style="font-size:10px;"><i class="bi bi-heart-pulse me-1"></i><?= $is_sw ? 'Amefariki' : 'Deceased' ?></span>
                        <?php else: ?>
                        <span class="badge bg-primary text-white rounded-pill px-2" style="font-size:10px;"><?= $is_sw ? 'MICHANGO' : 'CONTRIB.' ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="vk-card-body">
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Simu' : 'Phone' ?></span>
                            <span class="vk-card-value"><?= htmlspecialchars($m['phone'] ?? '—') ?></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Barua Pepe' : 'Email' ?></span>
                            <span class="vk-card-value small text-muted"><?= htmlspecialchars($m['email'] ?? '—') ?></span>
                        </div>
                        <div class="vk-card-row">
                            <span class="vk-card-label"><?= $is_sw ? 'Kuingia' : 'Registered' ?></span>
                            <span class="vk-card-value"><?= date('d M Y', strtotime($m['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="vk-card-actions">
                        <a href="<?= getUrl('member_statement') ?>?id=<?= $m['customer_id'] ?>" class="btn vk-btn-action btn-primary" title="<?= $is_sw ? 'Taarifa' : 'Statement' ?>">
                            <i class="bi bi-file-earmark-person"></i>
                        </a>
                        <button onclick="deleteDormant(<?= $m['user_id'] ?>)" class="btn vk-btn-action btn-danger" title="<?= $is_sw ? 'Mfute' : 'Delete' ?>">
                            <i class="bi bi-trash3-fill"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'footer.php';
ob_end_flush();
?>

<script>
let dormantTable;

$(document).ready(function() {
    // Initialize DataTable
    dormantTable = $('#dormantTable').DataTable({
        paging: true,
        pageLength: 10,
        ordering: true,
        info: true,
        responsive: true,
        dom: 'rtip', // Hide default search and length
        buttons: [
            {
                extend: 'excel',
                exportOptions: { columns: [0, 1, 2, 3, 4] },
                title: 'Dormant_Members_List'
            },
            {
                extend: 'print',
                exportOptions: { columns: [0, 1, 2, 3, 4] },
                title: '',
                customize: function (win) {
                    // 1. Add Custom Styles for Fixed Footer on Every Page
                    $(win.document.head).append(`
                        <style>
                            @page { margin: 1cm 1cm 2.5cm 1cm; }
                            body { font-size: 11pt; padding-bottom: 2cm; }
                            
                            /* Force Visible Borders on Table */
                            table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important; }
                            table th, table td { border: 1px solid #000 !important; padding: 8px !important; }
                            table th { text-align: center !important; background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; }
                            table td { text-align: left !important; }

                            /* Persistent Footer Style */
                            .print-footer {
                                position: fixed;
                                bottom: 0;
                                left: 0;
                                right: 0;
                                text-align: center;
                                padding: 10px 0;
                                background: white !important;
                                border-top: 1px solid #dee2e6;
                                font-size: 10pt;
                                z-index: 1000;
                            }
                            
                            /* Table Foot Spacer to prevent overlap */
                            tfoot.print-spacer { display: table-footer-group; }
                            tfoot.print-spacer td { height: 60px; border: none !important; }
                        </style>
                    `);

                    // 2. Add Branded Header
                    $(win.document.body).prepend(`
                        <div class="text-center mb-4">
                            <img src="/assets/images/<?= $group_logo ?>" style="height: 80px; width: auto; margin-bottom: 10px;">
                            <h2 class="fw-bold mb-1" style="color: #0d6efd !important; text-transform: uppercase;"><?= $group_name ?></h2>
                            <h4 class="fw-bold text-dark border-top border-bottom py-2 mt-2"><?= $title ?></h4>
                        </div>
                    `);

                    // 3. Add Persistent Footer (Div with fixed position)
                    let now = new Date();
                    let timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                                  now.getMinutes().toString().padStart(2, '0') + ':' + 
                                  now.getSeconds().toString().padStart(2, '0');
                    let dateStr = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

                    $(win.document.body).append(`
                        <div class="print-footer">
                            <p class="mb-1 text-dark">This document was printed by <strong><?= $username ?></strong> - <strong><?= $user_role ?></strong> on <strong>${dateStr}</strong> at <strong>${timeStr}</strong></p>
                            <h6 class="mb-0 fw-bold" style="color: #0d6efd !important;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</h6>
                        </div>
                    `);

                    // 4. Inject TFOOT Spacer into the table to handle multiple pages
                    $(win.document.body).find('table').append(`
                        <tfoot class="print-spacer">
                            <tr><td colspan="5">&nbsp;</td></tr>
                        </tfoot>
                    `);
                }
            }
        ],
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'sw.json' : 'en-GB.json' ?>'
        },
        drawCallback: function() {
            filterDormantCards($('#dormantSearch').val());
        }
    });

    // Custom Search
    $('#dormantSearch').on('keyup', function() {
        dormantTable.search(this.value).draw();
        filterDormantCards(this.value);
    });
});

function changeLen(len) {
    dormantTable.page.len(len).draw();
    $('#lenText').text(len == -1 ? '<?= $is_sw ? 'Zote' : 'All' ?>' : len);
}

function dtPrint() {
    dormantTable.button('.buttons-print').trigger();
}

function dtExport() {
    dormantTable.button('.buttons-excel').trigger();
}

function filterDormantCards(val) {
    var term = (val || '').toLowerCase().trim();
    $('#dormantCardsWrapper .vk-member-card').each(function() {
        var text = ($(this).data('search') || '').toLowerCase();
        $(this).toggle(!term || text.includes(term));
    });
}

function deleteDormant(userId) {
    Swal.fire({
        title: '<?= $is_sw ? 'Una uhakika?' : 'Are you sure?' ?>',
        text: '<?= $is_sw ? 'Ukifuta mwanachama huyu, hutoweza kumrudisha tena!' : 'You will not be able to recover this member record!' ?>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<?= $is_sw ? 'Ndio, Mfute!' : 'Yes, delete it!' ?>',
        cancelButtonText: '<?= $is_sw ? 'Hapana, ghairi' : 'No, cancel' ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'index.php?page=customers&delete=' + userId;
        }
    });
}
</script>

<style>
.bg-danger-subtle { background-color: #fee2e2 !important; }
.bg-warning-subtle { background-color: #fef3c7 !important; }
.btn-white { background-color: #fff; color: #374151; }
.btn-white:hover { background-color: #f9fafb; border-color: #d1d5db; }

/* Mobile View Optimization */
@media (max-width: 768px) {
    #dormantTable {
        width: 100% !important;
        font-size: 0.75rem !important; /* Smaller text for mobile */
    }
    #dormantTable td, #dormantTable th {
        padding: 5px !important;
    }
    #dormantTable th {
        font-size: 0.7rem !important;
    }
    .table-responsive {
        overflow-x: hidden !important; /* Prevent double scrollbars */
        padding: 0 5px !important;
    }
    .card-header .input-group {
        width: 100% !important; /* Search takes full width on mobile */
    }
}
</style>
