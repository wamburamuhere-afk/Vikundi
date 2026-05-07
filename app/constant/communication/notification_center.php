<?php
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('notification_center');
require_once __DIR__ . '/../../../header.php';

// Get current user ID
$user_id = $_SESSION['user_id'];

// Get notification types for filtering
$types_stmt = $pdo->query("SELECT DISTINCT type FROM notifications ORDER BY type");
$notification_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get user notification preferences
$preferences = [];
$stmt = $pdo->prepare("SELECT notification_preferences FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($user_data['notification_preferences'])) {
    $preferences = json_decode($user_data['notification_preferences'], true);
} else {
    // Default preferences
    $preferences = [
        'email_notifications' => true,
        'push_notifications' => true,
        'sms_notifications' => false,
        'contribution_alerts' => true,
        'member_alerts' => true,
        'system_alerts' => true,
        'report_alerts' => false,
        'quiet_hours_enabled' => false,
        'quiet_hours_start' => '22:00',
        'quiet_hours_end' => '07:00'
    ];
}
?>

<?php
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-bell"></i> <?= $is_sw ? 'Kituo cha Arifa' : 'Notification Center' ?></h2>
                    <p class="text-muted mb-0"><?= $is_sw ? 'Simamia arifa zako na mipangilio ya ujumbe' : 'Manage your alerts and notification preferences' ?></p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#preferencesModal">
                        <i class="bi bi-gear"></i> <?= $is_sw ? 'Mipangilio' : 'Settings' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-2">
        <div class="col-6 col-md-6 col-xl-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0 overflow-hidden">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h5 class="mb-0 fw-bold" id="total-notifications">0</h5>
                            <p class="mb-0 small fw-bold text-truncate"><?= $is_sw ? 'Arifa Zote' : 'Total Notifications' ?></p>
                        </div>
                        <div class="opacity-75 ms-1 flex-shrink-0">
                            <i class="bi bi-bell" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-6 col-xl-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0 overflow-hidden">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h5 class="mb-0 fw-bold" id="unread-count">0</h5>
                            <p class="mb-0 small fw-bold text-truncate"><?= $is_sw ? 'Zisizofunguliwa' : 'Unread Alerts' ?></p>
                        </div>
                        <div class="opacity-75 ms-1 flex-shrink-0">
                            <i class="bi bi-envelope-exclamation" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-6 col-xl-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0 overflow-hidden">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h5 class="mb-0 fw-bold" id="high-priority-unread">0</h5>
                            <p class="mb-0 small fw-bold text-truncate"><?= $is_sw ? 'Muhimu Zaidi' : 'High Priority' ?></p>
                        </div>
                        <div class="opacity-75 ms-1 flex-shrink-0">
                            <i class="bi bi-exclamation-triangle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-6 col-md-6 col-xl-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0 overflow-hidden">
                <div class="card-body p-2 p-md-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h5 class="mb-0 fw-bold" id="today-count">0</h5>
                            <p class="mb-0 small fw-bold text-truncate"><?= $is_sw ? 'Za Leo' : "Today's Alerts" ?></p>
                        </div>
                        <div class="opacity-75 ms-1 flex-shrink-0">
                            <i class="bi bi-calendar-check" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Notifications List -->
        <div class="col-lg-9">
            <div class="card shadow-sm mb-4 border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-list-ul"></i> <?= $is_sw ? 'Kumbukumbu za Arifa' : 'Activity Log' ?></h5>
                    <div class="btn-group shadow-sm">
                        <button type="button" class="btn btn-success btn-sm px-2" onclick="bulkAction('mark_all_read')" title="<?= $is_sw ? 'Soma Zote' : 'Mark All Read' ?>">
                            <i class="bi bi-check-all"></i> <span class="d-none d-md-inline"><?= $is_sw ? 'Soma Zote' : 'Mark All Read' ?></span>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm px-2" onclick="bulkAction('clear_all_read')" title="<?= $is_sw ? 'Futa Zilizosomwa' : 'Clear Read' ?>">
                            <i class="bi bi-trash"></i> <span class="d-none d-md-inline"><?= $is_sw ? 'Futa Zilizosomwa' : 'Clear Read' ?></span>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block d-print-block">
                        <table id="notificationsTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th class="ps-4">S/NO</th>
                                    <th><?= $is_sw ? 'Yaliyomo' : 'Content' ?></th>
                                    <th><?= $is_sw ? 'Aina' : 'Type' ?></th>
                                    <th><?= $is_sw ? 'Umuhimu' : 'Priority' ?></th>
                                    <th><?= $is_sw ? 'Hali' : 'Status' ?></th>
                                    <th><?= $is_sw ? 'Tarehe' : 'Date' ?></th>
                                    <th class="text-end pe-4"><?= $is_sw ? 'Hatua' : 'Actions' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>

                    <!-- ═══ CARD VIEW — Mobile Only ═══ -->
                    <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="notifCardsWrapper">
                        <div id="notifCardsEmptyState" class="d-none text-center py-5">
                            <i class="bi bi-bell-slash fs-1 text-muted d-block mb-3"></i>
                            <p class="text-muted mb-0"><?= $is_sw ? 'Hakuna arifa zilizopatikana.' : 'No notifications found.' ?></p>
                        </div>
                    </div>

                    <!-- Mobile Prev / Next — after card view, mobile only -->
                    <div class="d-flex d-md-none justify-content-end align-items-center gap-2 px-3 py-2 border-top">
                        <button class="btn btn-sm btn-outline-secondary px-3 fw-semibold" id="notifPrevBtn" onclick="notifTablePage('previous')" disabled>
                            <i class="bi bi-chevron-left"></i> <?= $is_sw ? 'Nyuma' : 'Prev' ?>
                        </button>
                        <span class="text-muted small" id="notifPageInfo" style="min-width:48px;text-align:center;">1 / 1</span>
                        <button class="btn btn-sm btn-primary px-3 fw-semibold" id="notifNextBtn" onclick="notifTablePage('next')">
                            <?= $is_sw ? 'Mbele' : 'Next' ?> <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar - Filters -->
        <div class="col-lg-3">
            <div class="card shadow-sm mb-4 border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> <?= $is_sw ? 'Chuja Arifa' : 'Filter Alerts' ?></h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small text-muted"><?= $is_sw ? 'Aina ya Arifa' : 'Notification Type' ?></label>
                        <select class="form-select form-select-sm filter-input" id="filter_type">
                            <option value=""><?= $is_sw ? '-- Zote --' : 'All Types' ?></option>
                            <?php foreach ($notification_types as $type): ?>
                                <option value="<?= htmlspecialchars($type) ?>"><?= ucfirst($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted"><?= $is_sw ? 'Umuhimu (Priority)' : 'Priority' ?></label>
                        <select class="form-select form-select-sm filter-input" id="filter_priority">
                            <option value=""><?= $is_sw ? '-- Zote --' : 'All Priorities' ?></option>
                            <option value="low"><?= $is_sw ? 'Chini' : 'Low' ?></option>
                            <option value="medium"><?= $is_sw ? 'Wastani' : 'Medium' ?></option>
                            <option value="high"><?= $is_sw ? 'Ya Juu' : 'High' ?></option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small text-muted"><?= $is_sw ? 'Hali (Status)' : 'Status' ?></label>
                        <select class="form-select form-select-sm filter-input" id="filter_status">
                            <option value=""><?= $is_sw ? '-- Zote --' : 'All Status' ?></option>
                            <option value="0"><?= $is_sw ? 'Zisizofunguliwa' : 'Unread Only' ?></option>
                            <option value="1"><?= $is_sw ? 'Zilizosomwa' : 'Read Only' ?></option>
                        </select>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-primary btn-sm rounded-pill" onclick="refreshTable()">
                            <?= $is_sw ? 'Tekeleza' : 'Apply Filters' ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill" onclick="resetFilters()">
                            <?= $is_sw ? 'Rudisha' : 'Reset' ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stats Chart -->
            <div class="card shadow-sm">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up"></i> Overview</h6>
                </div>
                <div class="card-body">
                    <div style="height: 200px;">
                        <canvas id="notificationChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preferences Modal -->
<div class="modal fade" id="preferencesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><?= $is_sw ? 'Mipangilio ya Arifa' : 'Notification Settings' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="preferencesForm">
                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2 fw-bold"><?= $is_sw ? 'Njia za Arifa' : 'Channels' ?></h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="email_notifications" id="pref_email" <?= ($preferences['email_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_email"><?= $is_sw ? 'Barua Pepe' : 'Email Alerts' ?></label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="push_notifications" id="pref_push" <?= ($preferences['push_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_push"><?= $is_sw ? 'Push za Browser' : 'Browser Push' ?></label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="sms_notifications" id="pref_sms" <?= ($preferences['sms_notifications'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_sms"><?= $is_sw ? 'Ujumbe (SMS)' : 'SMS Notifications' ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="border-bottom pb-2 fw-bold"><?= $is_sw ? 'Aina za Arifa' : 'Alert Types' ?></h6>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="contribution_alerts" id="pref_contrib" <?= ($preferences['contribution_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_contrib"><?= $is_sw ? 'Michango na Misaada' : 'Contributions & Assistance' ?></label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="member_alerts" id="pref_members" <?= ($preferences['member_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_members"><?= $is_sw ? 'Taarifa za Wanachama' : 'Member Updates' ?></label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="system_alerts" id="pref_system" <?= ($preferences['system_alerts'] ?? false) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pref_system"><?= $is_sw ? 'Maboresho ya Mfumo' : 'System Maintenance' ?></label>
                            </div>
                        </div>
                        <div class="col-12 mt-4">
                            <h6 class="border-bottom pb-2 fw-bold"><?= $is_sw ? 'Muda wa Utulivu' : 'Quiet Hours' ?></h6>
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="quiet_hours_enabled" id="pref_quiet" <?= ($preferences['quiet_hours_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="pref_quiet"><?= $is_sw ? 'Washa Kipindi Hiki' : 'Enable Quiet Period' ?></label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <input type="time" name="quiet_hours_start" class="form-control form-control-sm" value="<?= $preferences['quiet_hours_start'] ?? '22:00' ?>">
                                </div>
                                <div class="col-md-4">
                                    <input type="time" name="quiet_hours_end" class="form-control form-control-sm" value="<?= $preferences['quiet_hours_end'] ?? '07:00' ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= $is_sw ? 'Funga' : 'Close' ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= $is_sw ? 'Hifadhi' : 'Save Changes' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }
.custom-stat-card h4, .custom-stat-card p, .custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.custom-table-header { border-bottom: 2px solid #e9ecef; }
.notification-unread { border-left: 3px solid #0d6efd !important; background-color: rgba(13, 110, 253, 0.02); }
.badge-subtle { border: 1px solid currentColor; }

/* Prevent dropdown clipping in DataTables responsive */
.table-responsive {
    overflow: visible !important;
    position: relative;
    padding-bottom: 50px; /* Buffer for open dropdowns */
}
.dropdown-menu.show {
    display: block !important;
    z-index: 10000 !important;
}
</style>

<!-- Chart.js -->
<script src="/assets/js/chart.js"></script>

<script>
let notificationChart = null;
const isSw = <?= $is_sw ? 'true' : 'false' ?>;

$(document).ready(function() {
    const table = $('#notificationsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/get_notifications.php',
            data: function(d) {
                d.type = $('#filter_type').val();
                d.priority = $('#filter_priority').val();
                d.is_read = $('#filter_status').val();
            },
            dataSrc: function(json) {
                if (json.stats) {
                    $('#total-notifications').text(json.stats.total_notifications);
                    $('#unread-count').text(json.stats.unread_count);
                    $('#high-priority-unread').text(json.stats.high_priority_unread);
                    $('#today-count').text(json.stats.today_count);
                    updateChart(json.stats);
                }
                return json.data;
            }
        },
        columns: [
            {
                data: null,
                className: 'ps-4 text-muted small',
                orderable: false,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                }
            },
            { 
                data: 'title',
                render: function(data, type, row) {
                    let related = '';
                    if (row.related_loan_id) {
                        related = `<br><small class="text-primary"><i class="bi bi-link-45deg"></i> Loan #${row.related_loan_id} ${row.customer_name ? ' - ' + row.customer_name : ''}</small>`;
                    }
                    return `<div class="${row.is_read == 0 ? 'fw-bold' : 'text-muted'}">
                                ${escapeHtml(data)}
                                <div class="small text-muted text-truncate" style="max-width: 400px;">${row.message}</div>
                                ${related}
                            </div>`;
                }
            },
            { 
                data: 'type',
                render: data => `<span class="badge bg-light text-dark border">${data}</span>`
            },
            { 
                data: 'priority',
                render: data => {
                    const colors = { high: 'danger', medium: 'warning', low: 'info' };
                    const trans = { 
                        high: isSw ? 'Juu' : 'High', 
                        medium: isSw ? 'Wastani' : 'Medium', 
                        low: isSw ? 'Chini' : 'Low' 
                    };
                    const color = colors[data] || 'secondary';
                    const label = trans[data] || data;
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3">${label}</span>`;
                }
            },
            { 
                data: 'is_read',
                render: data => data == 1 
                    ? `<span class="badge bg-light text-muted border px-3">${isSw ? 'Imesomwa' : 'Read'}</span>` 
                    : `<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3">${isSw ? 'Mpya' : 'New'}</span>`
            },
            { 
                data: 'created_at',
                render: data => {
                    const date = new Date(data);
                    return `<span class="small text-muted">${date.toLocaleString(isSw ? 'sw-TZ' : 'en-US')}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-end pe-4',
                render: function(data, type, row) {
                    let actions = `<div class="dropdown">
                                    <button class="btn btn-light btn-sm rounded-1 shadow-sm border border-secondary-subtle" 
                                            type="button" 
                                            data-bs-toggle="dropdown" 
                                            data-bs-display="static" 
                                            aria-expanded="false">
                                        <i class="bi bi-gear"></i> <i class="bi bi-caret-down-fill small"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-1" style="z-index: 9999;">`;
                    
                    if (row.is_read == 0) {
                        actions += `<li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="markRead(${row.notification_id})">
                                        <i class="bi bi-check2-circle text-success me-2"></i> ${isSw ? 'Weka Isomwe' : 'Mark Read'}
                                    </a></li>`;
                    }
                    
                    if (row.action_url) {
                        actions += `<li><a class="dropdown-item py-2" href="${row.action_url}">
                                        <i class="bi bi-box-arrow-up-right text-primary me-2"></i> ${isSw ? 'Fungua' : 'Open'}
                                    </a></li>`;
                    }
                    
                    if (actions.includes('<li>')) actions += `<li><hr class="dropdown-divider my-1"></li>`;
                    
                    actions += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteNotification(${row.notification_id})">
                                    <i class="bi bi-trash me-2"></i> ${isSw ? 'Futa' : 'Delete'}
                                </a></li>`;
                    
                    actions += `</ul></div>`;
                    return actions;
                }
            }
        ],
        order: [[5, 'desc']],
        language: {
            emptyTable: isSw ? 'Hakuna arifa zilizopatikana' : 'No notifications found',
            info: isSw ? 'Inaonyesha _START_ hadi _END_ kati ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_ entries',
            paginate: { next: '>', previous: '<' }
        },
        rowCallback: function(row, data) {
            if (data.is_read == 0) {
                $(row).addClass('notification-unread');
            }
        },
        drawCallback: function() {
            renderNotifCards(this.api());
            updateNotifPageInfo();
        }
    });

    window.notifTablePage = function(dir) { table.page(dir).draw('page'); };

    function updateNotifPageInfo() {
        var info = table.page.info();
        $('#notifPageInfo').text((info.page + 1) + ' / ' + (info.pages || 1));
        $('#notifPrevBtn').prop('disabled', info.page === 0);
        $('#notifNextBtn').prop('disabled', info.page >= info.pages - 1);
    }

    function renderNotifCards(api) {
        var $wrapper = $('#notifCardsWrapper');
        $wrapper.find('.vk-member-card').remove();
        var rows = api.rows({page: 'current'}).data();
        $('#notifCardsEmptyState').toggleClass('d-none', rows.length > 0);
        var avatarBg = { high: '#dc3545', medium: '#fd7e14', low: '#0dcaf0' };
        var avatarFg = { high: '#fff',     medium: '#fff',     low: '#000' };
        var priorityLabel = {
            high:   isSw ? 'Juu'     : 'High',
            medium: isSw ? 'Wastani' : 'Medium',
            low:    isSw ? 'Chini'   : 'Low'
        };
        var priorityColor = { high: 'danger', medium: 'warning', low: 'info' };
        rows.each(function(row) {
            var bg  = avatarBg[row.priority]  || '#6c757d';
            var fg  = avatarFg[row.priority]  || '#fff';
            var pc  = priorityColor[row.priority] || 'secondary';
            var pl  = priorityLabel[row.priority] || row.priority;
            var av  = escapeHtml((row.title || 'N').charAt(0).toUpperCase());
            var ttl = escapeHtml(row.title || '');
            var msg = escapeHtml((row.message || '').substring(0, 70)) + ((row.message || '').length > 70 ? '…' : '');
            var dt  = new Date(row.created_at).toLocaleDateString(isSw ? 'sw-TZ' : 'en-US');
            var statusBadge = row.is_read == 1
                ? `<span class="badge bg-light text-muted border">${isSw ? 'Imesomwa' : 'Read'}</span>`
                : `<span class="badge bg-primary-subtle text-primary border border-primary-subtle">${isSw ? 'Mpya' : 'New'}</span>`;
            var html = `<div class="vk-member-card">
                <div class="vk-card-header">
                    <div class="d-flex align-items-center gap-2">
                        <div class="vk-card-avatar" style="background:${bg};color:${fg};">${av}</div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="${row.is_read == 0 ? 'fw-bold' : 'text-muted'} lh-sm text-truncate">${ttl}</div>
                            <small class="text-muted">${escapeHtml(row.type || '')}</small>
                        </div>
                        ${statusBadge}
                    </div>
                </div>
                <div class="vk-card-body">
                    <div class="vk-card-row">
                        <span class="vk-card-label">${isSw ? 'Ujumbe' : 'Content'}</span>
                        <span class="vk-card-value text-muted">${msg}</span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label">${isSw ? 'Umuhimu' : 'Priority'}</span>
                        <span class="vk-card-value"><span class="badge bg-${pc}-subtle text-${pc} border border-${pc}-subtle">${pl}</span></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label">${isSw ? 'Tarehe' : 'Date'}</span>
                        <span class="vk-card-value">${dt}</span>
                    </div>
                </div>
                <div class="vk-card-actions">`;
            if (row.is_read == 0) {
                html += `<button class="btn btn-sm btn-outline-success vk-btn-action" onclick="markRead(${row.notification_id})" title="${isSw ? 'Weka Isomwe' : 'Mark Read'}"><i class="bi bi-check2-circle"></i></button>`;
            }
            if (row.action_url) {
                html += `<a href="${escapeHtml(row.action_url)}" class="btn btn-sm btn-outline-primary vk-btn-action" title="${isSw ? 'Fungua' : 'Open'}"><i class="bi bi-box-arrow-up-right"></i></a>`;
            }
            html += `<button class="btn btn-sm btn-outline-danger vk-btn-action" onclick="deleteNotification(${row.notification_id})" title="${isSw ? 'Futa' : 'Delete'}"><i class="bi bi-trash"></i></button>
                </div>
            </div>`;
            $wrapper.append(html);
        });
    }

    // Delegated click listener for dropdowns in DataTables - Bulletproof Bootstrap 5 approach
    $('#notificationsTable').on('click', '[data-bs-toggle="dropdown"]', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        // Initializing or getting the dropdown instance
        const dropdown = bootstrap.Dropdown.getOrCreateInstance(this, {
            popperConfig(defaultConfig) {
                return {
                    ...defaultConfig,
                    strategy: 'fixed' // This forces the menu to pop out of any container/table
                };
            }
        });
        
        dropdown.toggle();
    });

    // Close all dropdowns when clicking elsewhere
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.dropdown').length) {
            $('.dropdown-menu.show').each(function() {
                const toggle = $(this).siblings('[data-bs-toggle="dropdown"]')[0];
                if (toggle) {
                    const instance = bootstrap.Dropdown.getInstance(toggle);
                    if (instance) instance.hide();
                }
            });
        }
    });

    // Handle Preferences Form
    $('#preferencesForm').on('submit', function(e) {
        e.preventDefault();
        $.post('/api/save_notification_preferences.php', $(this).serialize(), function(res) {
            if (res.success) {
                $('#preferencesModal').modal('hide');
                Swal.fire({ icon: 'success', title: isSw ? 'Imekamilika' : 'Saved', text: res.message, timer: 2000 });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        });
    });

    initChart();
});

function refreshTable() {
    $('#notificationsTable').DataTable().ajax.reload();
}

function resetFilters() {
    $('.filter-input').val('');
    refreshTable();
}

function markRead(id) {
    $.post('/api/mark_notification_read.php', { notification_id: id }, function(res) {
        if (res.success) refreshTable();
    });
}

function deleteNotification(id) {
    Swal.fire({
        title: isSw ? 'Una uhakika?' : 'Are you sure?',
        text: isSw ? 'Arifa hii itafutwa kabisa!' : 'This notification will be deleted!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: isSw ? 'Ndiyo, Futa' : 'Yes, Delete',
        cancelButtonText: isSw ? 'Hapana' : 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_notification.php', { notification_id: id }, function(res) {
                if (res.success) refreshTable();
            });
        }
    });
}

function bulkAction(action) {
    const msg = action === 'mark_all_read' 
        ? (isSw ? 'Weka arifa zote kuwa zimesomwa?' : 'Mark all notifications as read?') 
        : (isSw ? 'Futa arifa zote zilizosomwa?' : 'Delete all read notifications?');
        
    Swal.fire({
        title: isSw ? 'Thibitisha' : 'Confirm',
        text: msg,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: isSw ? 'Ndiyo' : 'Yes',
        cancelButtonText: isSw ? 'Hapana' : 'No'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/notification_bulk_actions.php', { action: action }, function(res) {
                if (res.success) refreshTable();
            });
        }
    });
}

function initChart() {
    const ctx = document.getElementById('notificationChart').getContext('2d');
    notificationChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Read', 'Unread'],
            datasets: [{
                data: [0, 0],
                backgroundColor: ['#1cc88a', '#4e73df'],
                hoverBackgroundColor: ['#17a673', '#2e59d9'],
                hoverBorderColor: "rgba(234, 236, 244, 1)",
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, padding: 15 } }
            },
            cutout: '70%',
        }
    });
}

function updateChart(stats) {
    if (!notificationChart) return;
    const read = stats.total_notifications - stats.unread_count;
    notificationChart.data.datasets[0].data = [read, stats.unread_count];
    notificationChart.update();
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}

function showToast(type, message) {
    if (typeof parent.showToast === 'function') {
        parent.showToast(type, message);
    } else {
        alert(message);
    }
}
</script>

<?php include __DIR__ . '/../../../footer.php'; ?>
