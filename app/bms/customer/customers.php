<?php
// app/bms/customer/customers.php
ob_start();

// Include the header
require_once 'header.php';

// Permission check
requireViewPermission('customers');
$can_create_members = canCreate('customers');
$can_edit_members = canEdit('customers');
$can_delete_members = canDelete('customers');
$can_approve_members = canView('member_approvals');

// Fetch members (users joined with customer details)
$query = "
    SELECT 
        u.user_id, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.status as user_status, u.user_role, u.created_at,
        c.customer_id, c.phone, c.address, c.city, c.nida_number, c.state, c.district, c.initial_savings, c.is_deceased
    FROM users u
    LEFT JOIN customers c ON u.user_id = c.user_id
    WHERE u.status IN ('active', 'pending', 'suspended') 
    AND u.user_role != 'Admin'
    AND (c.is_deceased IS NULL OR c.is_deceased = 0)
    ORDER BY u.first_name ASC, u.last_name ASC
";
$stmt = $pdo->query($query);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_kianzio_all = array_sum(array_column($members, 'initial_savings'));
// Calculate statistics (update to exclude deceased from counts)
$total_members = count($members);
$active_members = array_filter($members, function($m) { return $m['user_status'] == 'active' && $m['is_deceased'] == 0; });
$inactive_members = array_filter($members, function($m) { return ($m['user_status'] == 'inactive' || $m['user_status'] == '') && $m['is_deceased'] == 0; });
$pending_members = array_filter($members, function($m) { return $m['user_status'] == 'pending' && $m['is_deceased'] == 0; });

?>


    <!-- Print Header (Visible ONLY on Print) -->
    <div class="d-none d-print-block">
        <div class="text-center mb-4">
            <img src="/assets/images/<?= htmlspecialchars($group_logo ?? 'logo1.png') ?>" alt="Logo" style="height: 80px; width: auto; margin-bottom: 10px; object-fit: contain;">
            <h2 class="fw-bold mb-1 text-uppercase" style="color: #0d6efd !important;"><?= htmlspecialchars($group_name ?? 'KIKUNDI') ?></h2>
            <h4 class="fw-bold text-dark text-uppercase border-top border-bottom py-2 mt-2">
                <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'ORODHA YA WANACHAMA (MEMBERS LIST)' : 'MEMBER LIST REPORT' ?>
            </h4>
            <div class="small text-muted mt-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tarehe ya Printi:' : 'Print Date:' ?> <?= date('d M, Y H:i') ?></div>
        </div>
    </div>
    <!-- Page Header -->
    <div class="row mb-4 g-3 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center gap-2">
                <div class="d-none d-md-block">
                    <h2><i class="bi bi-people"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Wanachama (Members)' : 'Member Management' ?></h2>
                    <p class="text-muted mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Orodha na taarifa za wanachama wote wa kikundi' : 'List and details of all group members' ?></p>
                </div>
                <div class="d-block d-md-none text-start">
                    <h4 class="mb-0 fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama' : 'Members' ?></h4>
                </div>
                <div class="d-flex flex-row gap-2 flex-grow-1 justify-content-end align-items-center">
                    <?php if ($can_create_members): ?>
                    <button type="button" class="btn btn-white border shadow-sm px-2 px-md-3 py-2 fw-semibold btn-import-hover flex-fill flex-md-grow-0 text-nowrap responsive-btn-header" data-bs-toggle="modal" data-bs-target="#importMemberModal" style="background-color: #fff; color: #495057;">
                        <i class="bi bi-file-earmark-excel"></i> <span class="d-none d-sm-inline"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchakato wa Import' : 'Import Members' ?></span><span class="d-inline d-sm-none">Import</span>
                    </button>
                    <button type="button" class="btn btn-primary shadow-sm px-2 px-md-3 py-2 fw-semibold flex-fill flex-md-grow-0 text-nowrap responsive-btn-header" data-bs-toggle="modal" data-bs-target="#addMemberModal">
                        <i class="bi bi-person-plus"></i> <span class="d-none d-sm-inline"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sajili Mwanachama' : 'Register Member' ?></span><span class="d-inline d-sm-none"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sajili' : 'Register' ?></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 d-print-none">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm border-0" style="background-color: #d1e7dd !important;">
                <div class="card-body text-dark">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= $total_members ?></h4>
                            <p class="mb-0 small fw-semibold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama Wote' : 'Total Members' ?></p>
                        </div>
                        <div class="align-self-center opacity-75">
                            <i class="bi bi-people-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm border-0" style="background-color: #d1e7dd !important;">
                <div class="card-body text-dark">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= count($active_members) ?></h4>
                            <p class="mb-0 small fw-semibold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Walio Active' : 'Active Members' ?></p>
                        </div>
                        <div class="align-self-center opacity-75">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm border-0" style="background-color: #d1e7dd !important;">
                <div class="card-body text-dark">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= count($inactive_members) ?></h4>
                            <p class="mb-0 small fw-semibold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasio Active' : 'Inactive Members' ?></p>
                        </div>
                        <div class="align-self-center opacity-75">
                            <i class="bi bi-pause-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow-sm border-0" style="background-color: #d1e7dd !important;">
                <div class="card-body text-dark">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= count($pending_members) ?></h4>
                            <p class="mb-0 small fw-semibold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maombi Mapya' : 'New Requests' ?></p>
                        </div>
                        <div class="align-self-center opacity-75">
                            <i class="bi bi-clock-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Table -->
    <div class="card shadow-sm border-0 overflow-hidden" style="border-radius: 15px;">
        <div class="card-header bg-white py-4 border-bottom d-print-none">
            <div class="row g-3 align-items-center mb-3">
                <div class="col-md-5">
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border">
                        <span class="input-group-text bg-white border-0 ps-3"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" class="form-control border-0 px-2" id="searchMembers" placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tafuta mwanachama kwa jina...' : 'Search member by name...' ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select class="form-select shadow-sm rounded-pill border" id="statusFilter" onchange="applyFilters()">
                        <option value=""><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali Zote (All Status)' : 'All Status' ?></option>
                        <option value="hai"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hai (Active)' : 'Active' ?></option>
                        <option value="haitumiki"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Haitumiki (Inactive)' : 'Inactive' ?></option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
            </div>
            
            <!-- Action Row (Buttons in one row) -->
            <div class="row align-items-center">
                <div class="col-12 d-flex align-items-center flex-wrap gap-2 justify-content-center justify-content-md-start" id="action-tools">
                    <button type="button" class="btn btn-sm btn-white border shadow-sm px-3 py-2 fw-semibold" onclick="window.print()" style="background-color: #fff; color: #495057;">
                        <i class="bi bi-printer me-1 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Printi' : 'Print List' ?>
                    </button>
                    <button type="button" class="btn btn-sm btn-white border shadow-sm px-3 py-2 fw-semibold btn-import-hover" onclick="exportMembers()" style="background-color: #fff; color: #495057;">
                        <i class="bi bi-file-earmark-excel me-1 text-success"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakua (Export)' : 'Export Excel' ?>
                    </button>
                    <div class="dropdown" id="custom-length-dropdown">
                        <button class="btn btn-sm btn-white border shadow-sm px-3 py-2 fw-semibold dropdown-toggle no-caret btn-import-hover" type="button" id="lengthMenuBtn" data-bs-toggle="dropdown" style="background-color: #fff; color: #495057;">
                            <i class="bi bi-list-ol me-1 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Onyesha: ' : 'Show: ' ?> <span id="current-length">25</span>
                        </button>
                        <ul class="dropdown-menu shadow border-0 p-2" aria-labelledby="lengthMenuBtn">
                            <li><a class="dropdown-item py-2 rounded" href="javascript:void(0)" onclick="changeTableLength(10)">10</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="javascript:void(0)" onclick="changeTableLength(25)">25</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="javascript:void(0)" onclick="changeTableLength(50)">50</a></li>
                            <li><a class="dropdown-item py-2 rounded" href="javascript:void(0)" onclick="changeTableLength(100)">100</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 rounded" href="javascript:void(0)" onclick="changeTableLength(-1)"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Zote (All)' : 'All' ?></a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-body p-0 d-none d-md-block d-print-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="membersTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4" style="width: 60px;">S/NO</th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina Kamili' : 'Full Name' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'NIDA & Mawasiliano' : 'NIDA & Contact' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Makazi (Mkoa/Wilaya)' : 'Residence (Region/District)' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango (Kianzio)' : 'Initial Savings' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali (Status)' : 'Status' ?></th>
                            <th class="text-end pe-4 d-print-none"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua (Action)' : 'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $idx => $m): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-muted"><?= $idx + 1 ?></td>
                            <td class="ps-4">
                                <div>
                                    <div class="fw-bold"><?= safe_output($m['username']) ?></div>
                                    <small class="text-muted"><?= safe_output($m['user_role']) ?></small>
                                </div>
                            </td>
                            <td><?= safe_output(trim(($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?></td>
                            <td>
                                <div class="badge bg-light text-dark border mb-1"><i class="bi bi-card-text me-1"></i> ID: <?= safe_output($m['nida_number'] ?? 'N/A') ?></div>
                                <div><i class="bi bi-envelope-at me-1"></i> <?= safe_output($m['email']) ?></div>
                                <div><i class="bi bi-phone me-1"></i> <?= safe_output($m['phone'] ?? 'N/A') ?></div>
                            </td>
                            <td>
                                <div><strong><?= safe_output($m['state'] ?? 'N/A') ?></strong></div>
                                <small class="text-muted"><?= safe_output($m['district'] ?? ($m['city'] ?? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hapajajazwa' : 'Not Filled'))) ?></small>
                            </td>
                            <td>
                                <span class="fw-bold text-success"><?= number_format($m['initial_savings'] ?? 0, 0) ?> TZS</span>
                            </td>
                            <td>
                                <?php
                                $status_class = 'bg-secondary';
                                if ($m['user_status'] == 'active') $status_class = 'bg-success';
                                elseif ($m['user_status'] == 'inactive') $status_class = 'bg-danger';
                                elseif ($m['user_status'] == 'pending') $status_class = 'bg-warning text-dark';
                                elseif ($m['user_status'] == 'suspended') $status_class = 'bg-dark';
                                ?>
                                <span class="badge <?= $status_class ?> badge-pill">
                                    <?php 
                                        if (($_SESSION['preferred_language'] ?? 'en') === 'sw') {
                                            $st_map = ['active'=>'Hai (Active)', 'pending'=>'Pending', 'inactive'=>'Haitumiki', 'suspended'=>'Imesitishwa'];
                                            echo $st_map[$m['user_status']] ?? ucfirst($m['user_status']);
                                        } else {
                                            $st_map = ['active'=>'Active', 'pending'=>'Pending', 'inactive'=>'Inactive', 'suspended'=>'Suspended'];
                                            echo $st_map[$m['user_status']] ?? ucfirst($m['user_status']);
                                        }
                                    ?>
                                </span>
                            </td>
                            <td class="text-end pe-4 d-print-none">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light border dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-gear-fill text-secondary me-1"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <!-- Always show View/Statement if they can View the page at all -->
                                        <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('member_statement') ?>?id=<?= $m['customer_id'] ?>"><i class="bi bi-file-earmark-person text-success me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa ya Fedha' : 'Financial Statement' ?></a></li>
                                        <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('profile') ?>?id=<?= $m['user_id'] ?>&ref=list"><i class="bi bi-person-badge text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Angalia Wasifu' : 'View Member Details' ?></a></li>
                                        
                                        <?php if (!$m['is_deceased']): ?>
                                            <?php if ($can_edit_members): ?>
                                                <!-- Edit Member - Only if canEdit is true -->
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('profile') ?>?id=<?= $m['user_id'] ?>&edit=1&ref=list"><i class="bi bi-pencil-square text-info me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Taarifa (Edit)' : 'Edit Member' ?></a></li>
                                                <li><a class="dropdown-item py-2 text-primary fw-bold rounded" href="javascript:void(0)" onclick="openStatusModal(<?= $m['user_id'] ?>, '<?= $m['user_status'] ?>')"><i class="bi bi-check-circle-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Hali (Status)' : 'Change Status' ?></a></li>
                                            <?php endif; ?>

                                            <?php if ($can_delete_members): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item py-2 text-danger fw-bold rounded" href="javascript:void(0)" onclick="deleteMember(<?= $m['user_id'] ?>)"><i class="bi bi-trash3-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfute Mwanachama' : 'Delete Member' ?></a></li>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <!-- For Deceased -->
                                            <?php if ($can_delete_members): ?>
                                                <li><a class="dropdown-item py-2 text-danger fw-bold rounded" href="javascript:void(0)" onclick="deleteMember(<?= $m['user_id'] ?>)"><i class="bi bi-trash3-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfute Marehemu' : 'Delete Deceased' ?></a></li>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <!-- Print Spacer: Reserves space so fixed footer doesn't overlap table rows -->
                    <tfoot class="d-none d-print-table-footer">
                        <tr><td colspan="8" style="height: 2.2cm; border: none !important;">&nbsp;</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- ═══ CARD VIEW — Mobile Only (hidden on desktop + print) ═══ -->
        <div class="p-3 d-md-none d-print-none" id="memberCardsWrapper">
            <?php foreach ($members as $idx => $m):
                $_lang_c = $_SESSION['preferred_language'] ?? 'en';
                $_sw_c   = ($_lang_c === 'sw');
                $full_name_c = trim(($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));

                $c_status_class = 'bg-secondary';
                if ($m['user_status'] == 'active')    $c_status_class = 'bg-success';
                elseif ($m['user_status'] == 'inactive') $c_status_class = 'bg-danger';
                elseif ($m['user_status'] == 'pending')  $c_status_class = 'bg-warning text-dark';
                elseif ($m['user_status'] == 'suspended') $c_status_class = 'bg-dark';

                $c_st_map = $_sw_c
                    ? ['active'=>'Hai (Active)', 'pending'=>'Pending', 'inactive'=>'Haitumiki', 'suspended'=>'Imesitishwa']
                    : ['active'=>'Active',        'pending'=>'Pending', 'inactive'=>'Inactive',   'suspended'=>'Suspended'];
                $c_status_text  = $c_st_map[$m['user_status']] ?? ucfirst($m['user_status']);
                $c_avatar       = strtoupper(substr($m['first_name'] ?? 'M', 0, 1));
                $c_search_text  = strtolower(implode(' ', [
                    $full_name_c, $m['username'] ?? '', $m['email'] ?? '',
                    $m['phone'] ?? '', $m['nida_number'] ?? '',
                    $m['state'] ?? '', $m['district'] ?? '', $m['city'] ?? ''
                ]));
            ?>
            <div class="vk-member-card"
                 data-name="<?= htmlspecialchars($c_search_text) ?>"
                 data-status-text="<?= htmlspecialchars(strtolower($c_status_text)) ?>">

                <!-- Header: avatar · name · role · badge -->
                <div class="vk-card-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="vk-card-avatar"><?= $c_avatar ?></div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="fw-bold text-dark lh-sm mb-1"><?= safe_output($full_name_c ?: $m['username']) ?></div>
                            <small class="text-muted"><?= safe_output($m['username']) ?> &middot; <?= safe_output($m['user_role']) ?></small>
                        </div>
                        <span class="badge <?= $c_status_class ?> rounded-pill"><?= safe_output($c_status_text) ?></span>
                    </div>
                </div>

                <!-- Body: label on left, value on right -->
                <div class="vk-card-body">
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Jina Kamili' : 'Full Name' ?></span>
                        <span class="vk-card-value"><?= safe_output($full_name_c ?: '—') ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label">NIDA</span>
                        <span class="vk-card-value"><?= safe_output($m['nida_number'] ?? '—') ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Barua Pepe' : 'Email' ?></span>
                        <span class="vk-card-value"><?= safe_output($m['email'] ?? '—') ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Simu' : 'Phone' ?></span>
                        <span class="vk-card-value"><?= safe_output($m['phone'] ?? '—') ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Mkoa' : 'Region' ?></span>
                        <span class="vk-card-value"><?= safe_output($m['state'] ?? '—') ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Wilaya' : 'District' ?></span>
                        <span class="vk-card-value"><?= safe_output($m['district'] ?? ($m['city'] ?? '—')) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_c ? 'Kianzio' : 'Init. Savings' ?></span>
                        <span class="vk-card-value fw-semibold text-success"><?= number_format($m['initial_savings'] ?? 0, 0) ?> TZS</span>
                    </div>
                </div>

                <!-- Actions: icon-only, consistent colors -->
                <div class="vk-card-actions">
                    <a href="<?= getUrl('member_statement') ?>?id=<?= $m['customer_id'] ?>"
                       class="btn btn-sm btn-outline-info vk-btn-action"
                       title="<?= $_sw_c ? 'Taarifa ya Fedha' : 'Financial Statement' ?>">
                        <i class="bi bi-bar-chart-line-fill"></i>
                    </a>
                    <a href="<?= getUrl('profile') ?>?id=<?= $m['user_id'] ?>&ref=list"
                       class="btn btn-sm btn-outline-primary vk-btn-action"
                       title="<?= $_sw_c ? 'Angalia Wasifu' : 'View Details' ?>">
                        <i class="bi bi-eye-fill"></i>
                    </a>
                    <?php if (!$m['is_deceased'] && $can_edit_members): ?>
                    <a href="<?= getUrl('profile') ?>?id=<?= $m['user_id'] ?>&edit=1&ref=list"
                       class="btn btn-sm btn-outline-warning vk-btn-action"
                       title="<?= $_sw_c ? 'Badili Taarifa' : 'Edit Member' ?>">
                        <i class="bi bi-pencil-fill"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-secondary vk-btn-action"
                            onclick="openStatusModal(<?= $m['user_id'] ?>, '<?= $m['user_status'] ?>')"
                            title="<?= $_sw_c ? 'Badili Hali' : 'Change Status' ?>">
                        <i class="bi bi-toggles"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($can_delete_members): ?>
                    <button class="btn btn-sm btn-outline-danger vk-btn-action"
                            onclick="deleteMember(<?= $m['user_id'] ?>)"
                            title="<?= $_sw_c ? 'Mfute Mwanachama' : 'Delete Member' ?>">
                        <i class="bi bi-trash3-fill"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div id="cardsEmptyState" class="d-none text-center py-5">
                <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Hakuna matokeo ya utafutaji huu.' : 'No matching members found.' ?>
                </p>
            </div>
        </div>
        <!-- ═══ END CARD VIEW ═══ -->

    </div>
</div>

<!-- Add Member Modal -->
<?php if ($can_edit_members): ?>
<div class="modal fade" id="addMemberModal" tabindex="-1" aria-labelledby="addMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" style="margin-top: 90px;">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 0 !important;">
            <div class="modal-header bg-primary text-white py-3" style="border-radius: 0 !important;">
                <h5 class="modal-title" id="addMemberModalLabel">
                    <i class="bi bi-person-plus-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sajili Mwanachama Mpya' : 'Register New Member' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Multi-tab Progress/Navigation -->
                <ul class="nav nav-pills nav-justified bg-light p-2 mb-0" id="registrationTabs" role="tablist" style="border-radius: 0 !important;">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active py-2" id="personal-tab" data-bs-toggle="pill" 
                                data-bs-target="#personal" type="button" role="tab" style="border-radius: 0 !important;">
                            <i class="bi bi-1-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Binafsi & Makazi' : 'Personal & Residence' ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-2" id="home-tab" data-bs-toggle="pill" 
                                data-bs-target="#home" type="button" role="tab" style="border-radius: 0 !important;">
                            <i class="bi bi-2-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Familia & Wanufaika' : 'Family & Beneficiaries' ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-2" id="account-tab" data-bs-toggle="pill" 
                                data-bs-target="#account" type="button" role="tab" style="border-radius: 0 !important;">
                            <i class="bi bi-3-circle me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Akaunti & Fedha' : 'Account & Finance' ?>
                        </button>
                    </li>
                </ul>

                <form id="addMemberForm" enctype="multipart/form-data" class="p-4">
                    <div class="tab-content" id="registrationTabsContent">
                        
                        <!-- TAB 1: TAARIFA BINAFSI -->
                        <div class="tab-pane fade show active" id="personal" role="tabpanel">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kwanza' : 'First Name' ?> *</label>
                                    <input type="text" name="first_name" id="reg_first_name" class="form-control" required placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfano: Juma' : 'e.g. John' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kati' : 'Middle Name' ?></label>
                                    <input type="text" name="middle_name" class="form-control" placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kati' : 'Middle Name' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Mwisho' : 'Last Name' ?> *</label>
                                    <input type="text" name="last_name" id="reg_last_name" class="form-control" required placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfano: Ali' : 'e.g. Doe' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Barua Pepe (Email)' : 'Email Address' ?> *</label>
                                    <input type="email" name="email" class="form-control" required placeholder="example@gmail.com">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone Number' ?> *</label>
                                    <input type="text" name="phone" class="form-control" required placeholder="07XXXXXXXX">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jinsia' : 'Gender' ?></label>
                                    <select name="gender" class="form-select">
                                        <option value="Mwanaume"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanaume' : 'Male' ?></option>
                                        <option value="Mwanamke"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanamke' : 'Female' ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dini ya Mwanachama' : 'Member Religion' ?></label>
                                    <div id="religion_field_wrapper_modal">
                                        <select name="religion" id="religion_select_modal" class="form-select" onchange="handleReligionChangeModal(this)">
                                            <option value="Ukristo"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukristo' : 'Christianity' ?></option>
                                            <option value="Uislamu"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uislamu' : 'Islam' ?></option>
                                            <option value="Nyingine"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyingine' : 'Other' ?></option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Region of Birth</label>
                                    <input type="text" name="birth_region" class="form-control" placeholder="e.g. Mwanza">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Marital Status</label>
                                    <select name="marital_status" id="marital_status_admin" class="form-select" onchange="toggleFamilyFieldsAdmin(this.value)">
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Date of Birth</label>
                                    <input type="date" name="dob" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">NIDA Number</label>
                                    <input type="text" name="nida_number" class="form-control" placeholder="20XXXXXXXXXXXXX">
                                </div>

                                <div class="col-12 mt-4">
                                    <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Makazi Yako ya Sasa' : 'Current Residence' ?></h6>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nchi' : 'Country' ?></label>
                                            <input type="text" name="country" class="form-control form-control-sm" value="Tanzania">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mkoa' : 'Region/State' ?></label>
                                            <input type="text" name="state" class="form-control form-control-sm" placeholder="Region">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wilaya' : 'District' ?></label>
                                            <input type="text" name="district" class="form-control form-control-sm" placeholder="District">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kata (Ward)' : 'Ward' ?></label>
                                            <input type="text" name="ward" class="form-control form-control-sm" placeholder="Ward">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mtaa / Kijiji' : 'Street/Village' ?></label>
                                            <input type="text" name="street" class="form-control form-control-sm" placeholder="Street">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyumba Namba' : 'House Number' ?></label>
                                            <input type="text" name="house_number" class="form-control form-control-sm" placeholder="House No.">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-4">
                                    <div class="card bg-light border-0">
                                        <div class="card-body">
                                            <label class="form-label fw-bold"><i class="bi bi-camera me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakia Picha ya Passport (Optional)' : 'Upload Passport Photo (Optional)' ?></label>
                                            <input type="file" name="passport_photo" class="form-control" accept="image/*">
                                            <div class="form-text text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Aina za faili: PNG, JPG, JPEG. Ukubwa usizidi 2MB.' : 'Allowed files: PNG, JPG, JPEG. Max size: 2MB.' ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-4">
                                <button type="button" class="btn btn-primary px-4" onclick="switchTab('home')">
                                    <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Endelea' : 'Continue' ?> <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>

                         <!-- TAB 2: FAMILY & RESIDENCE -->
                         <div class="tab-pane fade" id="home" role="tabpanel">
                             
                             <!-- MAIN BENEFICIARIES HEADING -->
                             <h5 class="mt-2 mb-4 text-primary fw-bold border-bottom pb-2">
                                 <i class="bi bi-people-fill me-2"></i>
                                 <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'WANUFAIKA (BENEFICIARIES)' : 'BENEFICIARIES' ?>
                             </h5>

                             <!-- 1: PARENTS INFORMATION -->
                             <div class="mb-4 pt-2">
                                 <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-person-heart me-2"></i>1. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WAZAZI WA MWANACHAMA' : 'MEMBER\'S PARENTS INFORMATION' ?></h6>
                                 <div class="row g-4">
                                     <!-- Father -->
                                     <div class="col-md-6 border-end">
                                         <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA BABA' : 'FATHER\'S DETAILS' ?></p>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">FATHER'S NAME</label>
                                             <input type="text" name="father_name" class="form-control form-control-sm" placeholder="Full Name">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">REGION/DISTRICT WHERE LIVING</label>
                                             <input type="text" name="father_location" class="form-control form-control-sm" placeholder="Location">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">WARD/VILLAGE/STREET</label>
                                             <input type="text" name="father_sub_location" class="form-control form-control-sm" placeholder="Sub-location">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">PHONE NUMBER</label>
                                             <input type="tel" name="father_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx">
                                         </div>
                                     </div>
                                     <!-- Mother -->
                                     <div class="col-md-6">
                                         <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA MAMA' : 'MOTHER\'S DETAILS' ?></p>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">MOTHER'S NAME</label>
                                             <input type="text" name="mother_name" class="form-control form-control-sm" placeholder="Full Name">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">REGION/DISTRICT WHERE LIVING</label>
                                             <input type="text" name="mother_location" class="form-control form-control-sm" placeholder="Location">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">WARD/VILLAGE/STREET</label>
                                             <input type="text" name="mother_sub_location" class="form-control form-control-sm" placeholder="Sub-location">
                                         </div>
                                         <div class="mb-2">
                                             <label class="form-label small mb-1 fw-bold">PHONE NUMBER</label>
                                             <input type="tel" name="mother_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx">
                                         </div>
                                     </div>
                                 </div>
                             </div>

                             <div id="familyFieldsAdmin" style="display: none;">
                                <!-- 2: WIFE/HUSBAND INFORMATION -->
                                <div class="mb-4 pt-2">
                                    <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-heart-fill me-2"></i>2. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA MWENZI' : 'WIFE/HUSBAND INFORMATION' ?></h6>
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">First Name</label>
                                            <input type="text" name="spouse_first_name" class="form-control" placeholder="First Name">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">Middle Name</label>
                                            <input type="text" name="spouse_middle_name" class="form-control" placeholder="Middle Name">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">Last Name</label>
                                            <input type="text" name="spouse_last_name" class="form-control" placeholder="Last Name">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small">Email</label>
                                            <input type="email" name="spouse_email" class="form-control" placeholder="spouse@example.com">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small">Phone</label>
                                            <input type="tel" name="spouse_phone" class="form-control" placeholder="0xxxxxxxxx">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">Gender</label>
                                            <select name="spouse_gender" class="form-select">
                                                <option value="">Select...</option>
                                                <option value="Mwanaume">Male</option>
                                                <option value="Mwanamke">Female</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">DOB</label>
                                            <input type="date" name="spouse_dob" class="form-control">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold small">Religion</label>
                                            <div id="spouse_religion_wrapper_admin">
                                                <select name="spouse_religion" class="form-select" onchange="handleSpouseReligionChangeAdmin(this)">
                                                    <option value="Ukristo">Christianity</option>
                                                    <option value="Uislamu">Islam</option>
                                                    <option value="Nyingine">Other</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small">NIDA Number</label>
                                            <input type="text" name="spouse_nida" class="form-control" placeholder="NIDA Number">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold small">Region of Birth</label>
                                            <input type="text" name="spouse_birth_region" class="form-control" placeholder="Birth Region">
                                        </div>
                                    </div>
                                </div>

                                <!-- 3: CHILDREN INFORMATION -->
                                <div class="mb-4 pt-2">
                                    <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-people-fill me-2"></i>3. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WATOTO WA KUZAA WA MWANACHAMA' : 'MEMBER\'S CHILDREN INFORMATION' ?></h6>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm align-middle" id="childrenTableAdmin">
                                            <thead class="bg-light small">
                                                <tr>
                                                    <th class="text-center" style="width: 50px;">S/NO</th>
                                                    <th>CHILD NAME</th>
                                                    <th style="width: 100px;">AGE</th>
                                                    <th style="width: 150px;">GENDER</th>
                                                    <th class="text-center" style="width: 50px;">#</th>
                                                </tr>
                                            </thead>
                                            <tbody id="childrenListAdmin">
                                                <tr class="child-row-admin">
                                                    <td class="text-center fw-bold row-idx-admin">1</td>
                                                    <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Child Name"></td>
                                                    <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Age"></td>
                                                    <td>
                                                        <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                                                            <option value="Mwanaume">Male</option>
                                                            <option value="Mwanamke">Female</option>
                                                        </select>
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRowAdmin(this)"><i class="bi bi-trash"></i></button>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill mt-2" onclick="addChildRowAdmin()">
                                        <i class="bi bi-plus-circle me-1"></i> Add Child
                                    </button>
                                </div>
                             </div> <!-- End familyFieldsAdmin -->


                            <!-- 2.4: GUARANTOR INFORMATION -->
                            <div class="mb-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-shield-check me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MDHAMINI WA MWANACHAMA' : 'MEMBER\'S GUARANTOR' ?></h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">GUARANTOR'S NAME</label>
                                        <input type="text" name="guarantor_name" class="form-control" placeholder="Full Name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">PHONE NUMBER</label>
                                        <input type="tel" name="guarantor_phone" class="form-control" placeholder="0xxxxxxxxx">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">RELATIONSHIP WITH MEMBER</label>
                                        <input type="text" name="guarantor_rel" class="form-control" placeholder="Relationship">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">REGION WHERE LIVING</label>
                                        <input type="text" name="guarantor_location" class="form-control" placeholder="Location">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between mt-4">
                                <button type="button" class="btn btn-outline-secondary px-4" onclick="switchTab('personal')">
                                    <i class="bi bi-arrow-left me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyuma' : 'Back' ?>
                                </button>
                                <button type="button" class="btn btn-primary px-4" onclick="switchTab('account')">
                                    <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Endelea' : 'Continue' ?> <i class="bi bi-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>

                        <!-- TAB 3: AKAUNTI & FEDHA -->
                        <div class="tab-pane fade" id="account" role="tabpanel">
                            <div class="row g-3">
                                <!-- Username Preview at Top -->
                                <div class="col-12 mt-3">
                                    <div class="alert alert-primary py-3 px-3 small border-0 bg-primary bg-opacity-10 mb-0 shadow-sm" style="border-left: 4px solid #0d6efd !important;">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-person-badge-fill me-3 fs-5"></i>
                                            <div>
                                                <div class="fw-bold mb-1" id="username_preview_text">
                                                    <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Username itakuwa:' : 'Your Username will be:' ?> 
                                                    <span class="text-primary fs-6 ms-1" id="real_username_placeholder">----</span>
                                                </div>
                                                <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mfumo unachukua herufi ya kwanza ya jina la kwanza na jina la mwisho lote.' : 'System takes first letter of first name and full last name.' ?></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Passwords in One Row -->
                                <div class="col-md-6 mb-2">
                                    <label class="form-label fw-bold small">Initial Password *</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" name="password" id="reg_password" class="form-control" required placeholder="******" autocomplete="new-password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordAdmin('reg_password')">
                                            <i class="bi bi-eye" id="reg_password_icon"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label fw-bold small">Confirm Password *</label>
                                    <div class="input-group input-group-sm">
                                        <input type="password" id="reg_confirm_password" class="form-control" required placeholder="******" autocomplete="new-password">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordAdmin('reg_confirm_password')">
                                            <i class="bi bi-eye" id="reg_confirm_password_icon"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- Role and Status in One Row -->
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nafasi ya Mwanachama (Role)' : 'Member Role' ?></label>
                                    <select name="user_role" class="form-select form-select-sm">
                                        <option value="Member"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama (Default)' : 'Member (Default)' ?></option>
                                        <option value="Admin">Admin</option>
                                        <option value="Secretary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Katibu (Secretary)' : 'Secretary' ?></option>
                                        <option value="Treasurer"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mhasibu (Treasurer)' : 'Treasurer' ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali ya Akaunti (Status)' : 'Account Status' ?></label>
                                    <select name="status" class="form-select form-select-sm">
                                        <option value="active"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Active (Tayari)' : 'Active (Ready)' ?></option>
                                        <option value="pending"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pending (Inasubiri)' : 'Pending (Review)' ?></option>
                                    </select>
                                </div>

                                <!-- Entrance Fee in Full Width (Row end) -->
                                <div class="col-12 mt-2">
                                    <div class="card bg-light border-0">
                                        <div class="card-body p-3">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi cha Kiingilio / Kianzio' : 'Entrance Fee / Initial Savings' ?></label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text bg-white">TZS</span>
                                                        <input type="number" name="initial_savings" class="form-control" value="0">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakia Risiti ya Malipo *' : 'Upload Payment Slip *' ?></label>
                                                    <input type="file" name="kianzio_slip" id="reg_kianzio_slip" class="form-control form-control-sm" accept="image/*,.pdf">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Preferred Language Selection -->
                                <div class="col-12 mt-3">
                                    <div class="card bg-white border p-3 d-flex flex-row align-items-center justify-content-between" style="border-radius: 12px;">
                                        <div>
                                            <label class="form-label fw-bold mb-1 small"><i class="bi bi-globe2 me-2 text-primary"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Lugha Anayopendelea' : 'Preferred Language' ?></label>
                                            <p class="text-muted small mb-0"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua lugha ya akaunti kwa mwanachama huyu.' : 'Choose the account language for this member.' ?></p>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <input type="hidden" name="preferred_language" id="reg_preferred_language" value="en">
                                            <button type="button" id="reg_btn_lang_en" onclick="setRegLang('en')"
                                                class="btn btn-primary btn-sm fw-bold px-3 rounded-pill btn-lang-reg">
                                                ðŸ‡¬ðŸ‡§ English
                                            </button>
                                            <button type="button" id="reg_btn_lang_sw" onclick="setRegLang('sw')"
                                                class="btn btn-outline-primary btn-sm fw-bold px-3 rounded-pill btn-lang-reg">
                                                ðŸ‡¹ðŸ‡¿ Kiswahili
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-text small opacity-75 mt-2 ms-2">Member can change password and language after first login.</div>
                            </div>

                            <div class="d-flex justify-content-between mt-5 pt-3 border-top">
                                <button type="button" class="btn btn-outline-secondary px-4" onclick="switchTab('home')">
                                    <i class="bi bi-arrow-left me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyuma' : 'Back' ?>
                                </button>
                                <button type="submit" class="btn btn-primary btn-lg px-5 shadow">
                                    <i class="bi bi-person-plus-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'KAMILISHA USAJILI' : 'COMPLETE REGISTRATION' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Silence non-critical DataTables warnings (e.g. from nested tables in modals)
if (typeof $.fn.DataTable !== 'undefined') {
    $.fn.dataTable.ext.errMode = 'none';
}

$(document).ready(function() {
    // Dynamic Username Preview
    function updateUsernamePreview() {
        const firstName = $('#reg_first_name').val().trim();
        const lastName = $('#reg_last_name').val().trim();
        const isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
        
        if (firstName !== '' && lastName !== '') {
            const firstInitial = firstName.charAt(0).toLowerCase();
            const lastNameSlug = lastName.toLowerCase().replace(/\s+/g, '');
            const generatedUsername = firstInitial + lastNameSlug;
            
            $('#real_username_placeholder').text(generatedUsername).addClass('fw-bold');
            $('#username_preview_text').html(isSw ? `Sasa username yako ni: <span class="text-primary fs-6 ms-1" id="real_username_placeholder">${generatedUsername}</span>` : `Now your username is: <span class="text-primary fs-6 ms-1" id="real_username_placeholder">${generatedUsername}</span>`);
        } else {
            $('#real_username_placeholder').text('----').removeClass('fw-bold');
            $('#username_preview_text').html(isSw ? 'Username itakuwa:' : 'The Username will be:');
        }
    }

    $('#reg_first_name, #reg_last_name').on('input', updateUsernamePreview);

    // Initialize DataTable - destroy first if already initialized
    if ($.fn.DataTable.isDataTable('#membersTable')) {
        $('#membersTable').DataTable().destroy();
    }

    var table = $('#membersTable').DataTable({
        "retrieve": true,
        "pageLength": 25,
        "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        "order": [[0, "asc"]],
        "dom": "lrtip",
        "columns": [
            { "orderable": true,  "searchable": false },
            { "orderable": true,  "searchable": true  },
            { "orderable": true,  "searchable": true  },
            { "orderable": false, "searchable": true  },
            { "orderable": true,  "searchable": true  },
            { "orderable": true,  "searchable": false },
            { "orderable": true,  "searchable": true  },
            { "orderable": false, "searchable": false }
        ],
        "language": {
            "search": "",
            "lengthMenu": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Show _MENU_' : 'Show _MENU_' ?>",
            "info": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inaonyesha _START_ hadi _END_ kati ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_' ?>",
            "emptyTable": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna mwanachama aliyepatikana.' : 'No members found.' ?>",
            "zeroRecords": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna matokeo ya utafutaji huu.' : 'No matching members found.' ?>",
            "paginate": {
                "next": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mbele' : 'Next' ?>",
                "previous": "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyuma' : 'Previous' ?>"
            }
        },
        "initComplete": function() {
            // Native length menu is hidden, we use our custom dropdown
            $('.dataTables_length').hide();
        }
    });

    // Custom Length Changer Function
    window.changeTableLength = function(len) {
        table.page.len(len).draw();
        $('#current-length').text(len === -1 ? ( '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Zote' : 'All' ?>' ) : len);
    };

    // Custom Search — filters both DataTable and mobile card view
    $('#searchMembers').on('keyup', function() {
        var v = $(this).val();
        table.search(v).draw();
        filterMemberCards(v, $('#statusFilter').val());
    });

    // Custom Status Filter — filters both DataTable and mobile card view
    $('#statusFilter').on('change', function() {
        var v = $(this).val();
        table.column(6).search(v).draw();
        filterMemberCards($('#searchMembers').val(), v);
    });
});

function filterMemberCards(searchVal, statusVal) {
    var search = (searchVal || '').toLowerCase().trim();
    var statusFilter = (statusVal || '').toLowerCase().trim();
    var visible = 0;
    $('.vk-member-card').each(function() {
        var name       = ($(this).data('name') || '').toLowerCase();
        var statusText = ($(this).attr('data-status-text') || '').toLowerCase();
        var matchSearch = !search || name.indexOf(search) !== -1;
        var matchStatus = !statusFilter || statusText.indexOf(statusFilter) !== -1;
        var show = matchSearch && matchStatus;
        $(this).toggle(show);
        if (show) visible++;
    });
    $('#cardsEmptyState').toggleClass('d-none', visible > 0);
}

function applyFilters() {
    if ($.fn.DataTable.isDataTable('#membersTable')) {
        $('#membersTable').DataTable().draw();
    }
}
</script>

<!-- Change Status Modal -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Hali' : 'Change Status' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="statusUserId">
                <p class="text-muted small mb-3"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua hali mpya kwa mwanachama huyu:' : 'Choose new status for this member:' ?></p>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-success text-start px-3" onclick="submitStatus('active')">
                        <i class="bi bi-check-circle-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Active' : 'Set Active' ?>
                    </button>
                    <button class="btn btn-outline-danger text-start px-3" onclick="submitStatus('inactive')">
                        <i class="bi bi-pause-circle-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Inactive' : 'Set Inactive' ?>
                    </button>
                    <button class="btn btn-outline-warning text-dark text-start px-3" onclick="submitStatus('pending')">
                        <i class="bi bi-clock-history me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Rudisha Pending' : 'Restore Pending' ?>
                    </button>
                    <button class="btn btn-outline-dark text-start px-3" onclick="submitStatus('suspended')">
                        <i class="bi bi-slash-circle me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka Suspended' : 'Set Suspended' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let statusModal;
document.addEventListener('DOMContentLoaded', function() {
    statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
});

function openStatusModal(userId, currentStatus) {
    document.getElementById('statusUserId').value = userId;
    // You could highlight current status here if you want
    statusModal.show();
}

function submitStatus(newStatus) {
    const userId = document.getElementById('statusUserId').value;
    updateMemberStatus(userId, newStatus);
    statusModal.hide();
}

// Role Management Integration
let roleModal;
document.addEventListener('DOMContentLoaded', function() {
    roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
});

function openRoleModal(userId, currentRole) {
    document.getElementById('roleUserId').value = userId;
    roleModal.show();
}

function submitRole(newRole) {
    const isSwahili = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
    const userId = document.getElementById('roleUserId').value;
    $.ajax({
        url: '<?= getUrl("actions/update_user_role") ?>',
        method: 'POST',
        data: { user_id: userId, role: newRole },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                roleModal.hide();
                Swal.fire({
                    icon: 'success',
                    title: isSwahili ? 'Imekamilika!' : 'Completed!',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', isSwahili ? 'Imeshindikana kuwasiliana na server.' : 'Server communication failed.', 'error');
        }
    });
}

function updateMemberStatus(userId, newStatus) {
    $.ajax({
        url: '<?= getUrl("actions/update_user_status") ?>',
        method: 'POST',
        data: { user_id: userId, status: newStatus },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imekamilika' : 'Completed' ?>',
                    text: response.message,
                    timer: 2000,
                    showConfirmButton: false
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imeshindikana kuwasiliana na server.' : 'Server communication failed.' ?>', 'error');
        }
    });
}

function editMember(userId) {
    window.location.href = '<?= getUrl("profile") ?>?id=' + userId + '&edit=1';
}

    function handleReligionChangeModal(sel) {
        if (sel.value === 'Nyingine') {
            const wrapper = document.getElementById('religion_field_wrapper_modal');
            const isSw = <?= json_encode(($_SESSION['preferred_language'] ?? 'en') === 'sw') ?>;
            wrapper.innerHTML = `
                <div class="input-group">
                    <input type="text" name="religion" class="form-control" placeholder="${isSw ? 'Andika dini yako' : 'Type your religion'}" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetReligionSelectModal()" title="${isSw ? 'Rudia' : 'Reset'}">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            `;
        }
    }

    function resetReligionSelectModal() {
        const wrapper = document.getElementById('religion_field_wrapper_modal');
        const isSw = <?= json_encode(($_SESSION['preferred_language'] ?? 'en') === 'sw') ?>;
        wrapper.innerHTML = `
            <select name="religion" id="religion_select_modal" class="form-select" onchange="handleReligionChangeModal(this)">
                <option value="Ukristo">${isSw ? 'Ukristo' : 'Christianity'}</option>
                <option value="Uislamu">${isSw ? 'Uislamu' : 'Islam'}</option>
                <option value="Nyingine">${isSw ? 'Nyingine' : 'Other'}</option>
            </select>
        `;
    }

function switchTab(tabId) {
    const triggerEl = document.querySelector('#' + tabId + '-tab');
    if (triggerEl) {
        // Use getOrCreateInstance to prevent errors if the instance doesn't exist
        const tab = bootstrap.Tab.getOrCreateInstance(triggerEl);
        tab.show();
    }
}

// Ensure the form handles progress and submission
$(document).ready(function() {
    toggleFamilyFieldsAdmin($('#marital_status_admin').val());
    
    // Initialize tab instances
    const tabs = document.querySelectorAll('#registrationTabs button');
    tabs.forEach(tab => {
        new bootstrap.Tab(tab);
    });

    // Form Submission
    $('#addMemberForm').on('submit', function(e) {
        e.preventDefault();
        
        const form = $(this)[0];
        
        // Password matching check
        if ($('#reg_password').val() !== $('#reg_confirm_password').val()) {
            Swal.fire({
                icon: 'error',
                title: 'Password Error',
                text: 'Passwords do not match! Please verify both fields.',
                confirmButtonColor: '#d33'
            });
            return false;
        }

        // MANDATORY: Payment Slip Check
        const slipFileInput = document.getElementById('reg_kianzio_slip');
        const slipFile = slipFileInput && slipFileInput.files ? slipFileInput.files[0] : null;

        if (!slipFile) {
            Swal.fire({
                icon: 'warning',
                title: 'Receipt Required!',
                text: 'Please upload the payment slip (initial savings record) to complete this registration.',
                confirmButtonColor: '#0d6efd'
            }).then(() => {
                // Focus on the tab and the field
                switchTab('account');
                setTimeout(() => { if(slipFileInput) slipFileInput.focus(); }, 300);
            });
            return false;
        }

        const formData = new FormData(form);
        
        const submitBtn = $(this).find('button[type="submit"]');
        const originalBtnHtml = submitBtn.html();
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inasajili...' : 'Registering...' ?>');

        $.ajax({
            url: '<?= getUrl("actions/add_member") ?>',
            method: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hongera!' : 'Congratulations!' ?>',
                        text: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama amesajiliwa kikamilifu.' : 'Member registered successfully.' ?>',
                        confirmButtonColor: '#3085d6'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: response.message,
                        confirmButtonColor: '#d33'
                    });
                    submitBtn.prop('disabled', false).html(originalBtnHtml);
                }
            },
            error: function() {
                Swal.fire('Error', '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imeshindikana kuwasiliana na server.' : 'Server communication failed.' ?>', 'error');
                submitBtn.prop('disabled', false).html(originalBtnHtml);
            }
        });
    });
});

    function handleSpouseReligionChangeAdmin(select) {
        if (select.value === 'Nyingine') {
            // NOTE: No 'required' â€” field may be hidden/disabled for Single members
            document.getElementById('spouse_religion_wrapper_admin').innerHTML = `
                <div class="input-group">
                    <input type="text" name="spouse_religion" class="form-control" placeholder="Specify religion">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetSpouseReligionAdmin()" title="Reset">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            `;
        }
    }

    function resetSpouseReligionAdmin() {
        document.getElementById('spouse_religion_wrapper_admin').innerHTML = `
            <select name="spouse_religion" class="form-select" onchange="handleSpouseReligionChangeAdmin(this)">
                <option value="Ukristo">Christianity</option>
                <option value="Uislamu">Islam</option>
                <option value="Nyingine">Other</option>
            </select>
        `;
    }

    function addChildRowAdmin() {
        const tbody = document.getElementById('childrenListAdmin');
        const rowCount = tbody.getElementsByClassName('child-row-admin').length + 1;
        const newRow = document.createElement('tr');
        newRow.className = 'child-row-admin';
        newRow.innerHTML = `
            <td class="text-center fw-bold row-idx-admin">${rowCount}</td>
            <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Name"></td>
            <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Age"></td>
            <td>
                <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                    <option value="Mwanaume">Male</option>
                    <option value="Mwanamke">Female</option>
                </select>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRowAdmin(this)"><i class="bi bi-trash"></i></button>
            </td>
        `;
        tbody.appendChild(newRow);
        updateRowNumbersAdmin();
    }

    function removeRowAdmin(btn) {
        const row = btn.closest('tr');
        if (document.getElementsByClassName('child-row-admin').length > 1) {
            row.remove();
            updateRowNumbersAdmin();
        }
    }

    function updateRowNumbersAdmin() {
        const rows = document.getElementsByClassName('row-idx-admin');
        for (let i = 0; i < rows.length; i++) {
            rows[i].innerText = i + 1;
        }
    }

    function deleteMember(userId) {
    Swal.fire({
        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'JE, UNA UHAKIKA?' : 'ARE YOU SURE?' ?>',
        text: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kitendo hiki kitamfuta mwanachama huyu kabisa kwenye mfumo.' : 'This action will permanently delete this member from the system.' ?>",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ndio, Mfute!' : 'Yes, Delete!' ?>',
        cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?>'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("actions/update_user_status") ?>',
                method: 'POST',
                data: { user_id: userId, status: 'deleted' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Umefutwa!' : 'Deleted!' ?>', '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama amefutwa.' : 'Member has been deleted.' ?>', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hitilafu ya mawasiliano.' : 'Communication error.' ?>', 'error');
                }
            });
        }
    });
}

function exportMembers() {
    // Basic CSV Export implementation
    let csv = [];
    let rows = document.querySelectorAll("#membersTable tr");
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length - 1; j++) { // Exclude Action column
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/,/g, ";");
            row.push(text);
        }
        csv.push(row.join(","));
    }

    let csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    let downloadLink = document.createElement("a");
    downloadLink.download = "VICOBA_Members_List.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function openRoleModal(userId) {
    document.getElementById('roleUserId').value = userId;
    const roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    roleModal.show();
}

function submitRole(role) {
    const userId = document.getElementById('roleUserId').value;
    $.ajax({
        url: '<?= getUrl("actions/update_user_role") ?>',
        method: 'POST',
        data: { user_id: userId, role: role },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hongera!' : 'Success!' ?>',
                    text: response.message,
                    timer: 2000
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

// Handle registration language toggle
function setRegLang(lang) {
    $('#reg_preferred_language').val(lang);
    if (lang === 'en') {
        $('#reg_btn_lang_en').removeClass('btn-outline-primary').addClass('btn-primary');
        $('#reg_btn_lang_sw').removeClass('btn-primary').addClass('btn-outline-primary');
    } else {
        $('#reg_btn_lang_sw').removeClass('btn-outline-primary').addClass('btn-primary');
        $('#reg_btn_lang_en').removeClass('btn-primary').addClass('btn-outline-primary');
    }
}

function togglePasswordAdmin(fieldId) {
        const input = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + '_icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }

    function toggleFamilyFieldsAdmin(status) {
        const familyDiv = document.getElementById('familyFieldsAdmin');
        const inputs = familyDiv.querySelectorAll('input, select');
        if (status === 'Married') {
            familyDiv.style.display = 'block';
            inputs.forEach(i => i.disabled = false);
        } else {
            familyDiv.style.display = 'none';
            inputs.forEach(i => i.disabled = true);
        }
    }
</script>

<!-- Assign Role Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badili Nafasi' : 'Assign Role' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="roleUserId">
                <p class="text-muted small mb-3"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua nafasi mpya ya kiongozi kwa mwanachama huyu:' : 'Assign a new leadership role for this member:' ?></p>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary text-start px-3 py-2" onclick="submitRole('Member')">
                        <i class="bi bi-person-fill me-2 text-secondary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama wa Kawaida' : 'Regular Member' ?>
                    </button>
                    <button class="btn btn-outline-primary text-start px-3 py-2" onclick="submitRole('Secretary')">
                        <i class="bi bi-pen-fill me-2 text-info"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Katibu (Secretary)' : 'Secretary' ?>
                    </button>
                    <button class="btn btn-outline-primary text-start px-3 py-2" onclick="submitRole('Treasurer')">
                        <i class="bi bi-cash-stack me-2 text-success"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mhasibu (Treasurer)' : 'Treasurer' ?>
                    </button>
                    <button class="btn btn-outline-primary text-start px-3 py-2" onclick="submitRole('Katibu')">
                        <i class="bi bi-person-check-fill me-2 text-warning"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Katibu (Swahili)' : 'Katibu' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.text-purple { color: #6f42c1 !important; }
.card { border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
.table thead th { font-weight: 600; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; background-color: #f8f9fa !important; }
.badge { padding: 0.5em 0.8em; font-weight: 500; border-radius: 6px; }
.no-caret::after { display: none !important; }
.btn-import-hover { background-color: #fff !important; border: 1px solid #dee2e6; color: #495057 !important; transition: 0.2s; }
.btn-import-hover:hover { background-color: #0d6efd !important; border-color: #0d6efd; color: #fff !important; box-shadow: 0 4px 8px rgba(13, 110, 253, 0.2) !important; }
.btn-import-hover:hover i { color: #fff !important; }

/* Custom styles for mobile header optimization */
@media (max-width: 576px) {
    .responsive-btn-header { 
        font-size: 0.75rem !important; 
        padding-left: 0.5rem !important; 
        padding-right: 0.5rem !important;
    }
    h2 { font-size: 1.4rem !important; }
}

/* HIGH-PERFORMANCE PRINT OPTIMIZATION */
@media print {
    /* Hide ALL UI elements that are not part of the data table */
    .header-wrapper, .navbar, .top-header, .bottom-header, .d-print-none, .btn, .modal, 
    .dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter {
        display: none !important;
    }
    
    /* Reset body spacing for print */
    body {
        padding-top: 0 !important;
        margin: 0 !important;
        background: white !important;
        font-size: 11px;
    }
    
    /* Ensure the container takes full width with padding */
    .container-fluid, .container {
        width: 100% !important;
        max-width: none !important;
        padding-left: 20px !important;
        padding-right: 20px !important;
        margin: 0 !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
    }

    /* Print Footer Positioning & Overlap Prevention */
    .print-footer {
        position: fixed;
        bottom: 0.8cm;
        left: 0;
        right: 0;
        width: 100%;
        background: white !important;
        font-size: 10px;
        z-index: 9999;
        text-align: center;
        padding-top: 15px;
        border-top: 1px solid #dee2e6;
    }

    /* Print Footer Logic (using tfoot for perfect breaks) */
    .d-print-table-footer {
        display: table-footer-group !important;
    }

    /* VERY GENEROUS BOTTOM MARGIN */
    @page {
        margin: 1.5cm 1.5cm 2.5cm 1.5cm; 
    }
    
    /* FLEXIBLE TABLE */
    .table-responsive {
        overflow: visible !important;
    }
    
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
    }
    
    /* FLEXIBLE TABLE: Handle row breaking */
    .table-responsive {
        overflow: visible !important;
    }
    
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        page-break-inside: auto;
    }

    tr {
        page-break-inside: avoid; /* Don't split a member's row across pages */
        page-break-after: auto;
    }
    
    /* Force text wrapping to prevent horizontal overflow */
    .table td, .table th {
        word-wrap: break-word !important;
        white-space: normal !important;
        padding: 6px 4px !important;
        border: 1px solid #dee2e6 !important;
    }
    
    .table thead th {
        background-color: #f8f9fa !important;
        -webkit-print-color-adjust: exact;
        color: #000 !important;
        text-align: center !important;
    }
    
    /* Show serial numbers clearly */
    .fw-bold.text-muted { color: #000 !important; }
}
</style>


<!-- Import Member Modal -->
<?php if ($can_create_members): ?>
<div class="modal fade" id="importMemberModal" tabindex="-1" aria-labelledby="importMemberModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 15px !important;">
            <div class="modal-header bg-primary text-white py-3" style="border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h5 class="modal-title" id="importMemberModalLabel">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Import Wanachama Wengi' : 'Batch Member Import' ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-info border-0 shadow-sm small mb-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-info-circle-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maelekezo Muhimu:' : 'Important Instructions:' ?></h6>
                    <ul class="ps-3 mb-0">
                        <li><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Faili lazima liwe na column 41 kwa mpangilio sahihi.' : 'File must have 41 columns in the correct order.' ?></li>
                        <li><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za lazima: Majina Matatu, Simu, na Kianzio.' : 'Mandatory: Three Names, Phone, and Entrance Fee.' ?></li>
                        <li><strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Username: Herufi ya kwanza + Jina la mwisho. Password: username@123' : 'Username: 1st initial + Last name. Password: username@123' ?></strong></li>
                    </ul>
                </div>

                <div class="card bg-light border-0 mb-4">
                    <div class="card-body p-3">
                        <p class="fw-bold small mb-2 text-muted uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MPANGILIO WA MAKUNDI (BLOCKS):' : 'COLUMN BLOCKS ORDER:' ?></p>
                        <ul class="small ps-3 mb-0">
                            <li><strong>Block 1:</strong> Personal & Finance (Col 1-12)</li>
                            <li><strong>Block 2:</strong> Residence (Col 13-18)</li>
                            <li><strong>Block 3:</strong> Parents (Col 19-26)</li>
                            <li><strong>Block 4:</strong> Spouse (Col 27-36)</li>
                            <li><strong>Block 5:</strong> Children & Guarantor (Col 37-41)</li>
                        </ul>
                    </div>
                </div>

                <form id="importMemberForm">
                    <div class="mb-4">
                        <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua Faili (CSV pekee)' : 'Choose File (CSV only)' ?></label>
                        <input type="file" name="import_file" id="import_file" class="form-control" accept=".csv" required>
                        <div class="form-text mt-2">
                             <a href="javascript:void(0)" onclick="downloadTemplate()" class="text-success text-decoration-none fw-bold">
                                <i class="bi bi-download me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Pakua Template hapa' : 'Download Template here' ?>
                             </a>
                        </div>
                    </div>
                    
                    <div class="d-grid mt-2">
                        <button type="submit" class="btn btn-primary btn-lg shadow-sm" id="btnImportSubmit">
                            <i class="bi bi-cloud-arrow-up-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'ANZA KU-IMPORT' : 'START IMPORT' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#importMemberForm').on('submit', function(e) {
        e.preventDefault();
        
        let formData = new FormData(this);
        let btn = $('#btnImportSubmit');
        let originalText = btn.html();
        let isSw = '<?= ($_SESSION['preferred_language'] ?? 'en') ?>' === 'sw';
        
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> ' + (isSw ? 'Inasindika...' : 'Processing...'));

        $.ajax({
            url: 'ajax/process_member_import.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).html(originalText);
                
                if (resp.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: isSw ? 'Fanikiwa!' : 'Success!',
                        text: resp.message,
                        confirmButtonColor: '#198754'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: isSw ? 'Kuna Shida' : 'Error',
                        text: resp.message,
                        confirmButtonColor: '#dc3545'
                    });
                }
            },
            error: function() {
                btn.prop('disabled', false).html(originalText);
                Swal.fire({
                    icon: 'error',
                    title: isSw ? 'Tatizo la Mfumo' : 'System Error',
                    text: isSw ? 'Imeshindwa kuwasiliana na server.' : 'Failed to communicate with the server.',
                    confirmButtonColor: '#dc3545'
                });
            }
        });
    });
});

function downloadTemplate() {
    const headers = [
        "S/NO", 
        "First Name*", "Middle Name*", "Last Name*", "Email", "Phone*", "Gender", "Date of Birth (YYYY-MM-DD)*", "NIDA Number", "Religion", "Birth Region", "Marital Status", "Entrance Fee*",
        "Country", "Region", "District", "Ward", "Street", "House Number",
        "Father Name", "Father Region/District", "Father Ward/Street", "Father Phone",
        "Mother Name", "Mother Region/District", "Mother Ward/Street", "Mother Phone",
        "Spouse First Name", "Spouse Middle Name", "Spouse Last Name", "Spouse Email", "Spouse Phone", "Spouse Gender", "Spouse Date of Birth", "Spouse NIDA", "Spouse Religion", "Spouse Birth Region",
        "Children (Name-Age-Gender separated by comma)",
        "Guarantor Name", "Guarantor Phone", "Guarantor Relationship", "Guarantor Location"
    ];
    // Use semicolon to avoid comma conflicts with large numbers
    const csvContent = "sep=;\n" + headers.join(";") + "\n";
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "vikundi_batch_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
<?php endif; ?>

    <!-- Print Footer (Visible ONLY on Print) -->
    <div class="d-none d-print-block print-footer">
        <div class="row pt-2">
            <div class="col-12 text-center">
                <p class="mb-1 text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyaraka hii imechapishwa na' : 'This document was printed by' ?> <strong><?= htmlspecialchars($username ?? $_SESSION['username']) ?></strong> - <strong><?= htmlspecialchars($user_role ?? 'Member') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'mnamo' : 'on' ?> <strong><?= date('d M, Y') ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'saa' : 'at' ?> <strong><?= date('H:i:s') ?></strong></p>
                <h6 class="mb-0 fw-bold" style="color: #0d6efd !important;">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</h6>
            </div>
        </div>
    </div>

<?php
include("footer.php");
ob_end_flush();
?>