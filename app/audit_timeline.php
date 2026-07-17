<?php
// File: app/audit_timeline.php  (Per-user activity timeline)
// Reads the same activity_logs data as the Activity Logs page, but presents ONE
// person's activity as a readable session story: when they signed in, each
// action with the exact time, and when they signed out — instead of hunting
// through the flat chronological list.
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
require_once ROOT_DIR . '/includes/activity_sessions.php';
$lang = $_SESSION['preferred_language'] ?? 'en';
$isSw = ($lang === 'sw');

// ── Inputs ────────────────────────────────────────────────────────────────────
$sel_user   = (int) ($_GET['user_id'] ?? 0);
$days       = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 0], true)) $days = 30; // 0 = all time
$show_views = isset($_GET['show_views']) && $_GET['show_views'] == '1';

// ── Badge helpers (kept local so this page is self-contained) ─────────────────
function tl_badge(string $action, bool $isSw): array {
    return match (strtolower($action)) {
        'viewed'       => ['color' => 'info',      'icon' => 'eye',                 'label' => $isSw ? 'Tazama'     : 'View'],
        'created'      => ['color' => 'success',   'icon' => 'plus-circle',         'label' => $isSw ? 'Ongeza'     : 'Add'],
        'updated'      => ['color' => 'warning',   'icon' => 'pencil',              'label' => $isSw ? 'Hariri'     : 'Edit'],
        'deleted'      => ['color' => 'danger',    'icon' => 'trash',               'label' => $isSw ? 'Futa'       : 'Delete'],
        'login'        => ['color' => 'primary',   'icon' => 'box-arrow-in-right',  'label' => $isSw ? 'Ingia'      : 'Login'],
        'logout'       => ['color' => 'secondary', 'icon' => 'box-arrow-right',     'label' => $isSw ? 'Toka'       : 'Logout'],
        'login failed' => ['color' => 'danger',    'icon' => 'shield-exclamation',  'label' => $isSw ? 'Imeshindwa' : 'Failed Login'],
        default        => ['color' => 'secondary', 'icon' => 'activity',            'label' => ucfirst($action)],
    };
}

function tl_duration(int $seconds, bool $isSw): string {
    if ($seconds < 60) return $seconds . ($isSw ? ' sek' : 's');
    $m = intdiv($seconds, 60);
    $h = intdiv($m, 60);
    $m = $m % 60;
    if ($h > 0) return $h . 'h ' . $m . 'm';
    return $m . ($isSw ? ' dak' : 'm');
}

// ── User picker list ──────────────────────────────────────────────────────────
try {
    $users = $pdo->query("
        SELECT u.user_id, u.first_name, u.last_name, COALESCE(r.role_name, '') AS role_name
        FROM users u LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE u.status != 'deleted'
        ORDER BY u.first_name, u.last_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

// ── Build the selected user's sessions ────────────────────────────────────────
$sessions      = [];
$sel_name      = '';
$sel_role      = '';
$total_actions = 0;
$last_seen     = null;

if ($sel_user) {
    foreach ($users as $u) {
        if ((int) $u['user_id'] === $sel_user) {
            $sel_name = trim($u['first_name'] . ' ' . $u['last_name']);
            $sel_role = $u['role_name'];
            break;
        }
    }

    $cond   = ['al.user_id = :uid'];
    $params = [':uid' => $sel_user];
    if ($days > 0) {
        $cond[] = 'al.created_at >= :since';
        $params[':since'] = date('Y-m-d H:i:s', strtotime("-$days days"));
    }
    if (!$show_views) {
        $cond[] = "al.action <> 'Viewed'";
    }
    $where = 'WHERE ' . implode(' AND ', $cond);

    try {
        $stmt = $pdo->prepare("
            SELECT al.action, al.module, al.description, al.reference, al.ip_address, al.created_at
            FROM activity_logs al $where
            ORDER BY al.created_at ASC
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $events = [];
    }

    // Group into sign-in sessions (see includes/activity_sessions.php). Returned
    // oldest-first; reverse so the most recent session shows at the top.
    $sessions      = vk_group_activity_sessions($events, 30 * 60);
    $total_actions = array_sum(array_map(fn($s) => count($s['events']), $sessions));
    $sessions      = array_reverse($sessions);
    $last_seen     = $sessions[0]['last'] ?? null;
}

require_once ROOT_DIR . '/header.php';
?>

<style>
    .vk-tl-page { max-width: 1100px; }
    .vk-session { border: 1px solid #e4e9ef; border-radius: 14px; background: #fff; box-shadow: 0 1px 4px rgba(20,40,60,.06); margin-bottom: 18px; overflow: hidden; }
    .vk-session-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 18px; padding: 14px 18px; background: #f8fbff; border-bottom: 1px solid #eef2f7; }
    .vk-session-date { font-weight: 800; color: #0f4c81; }
    .vk-session-meta { font-size: .8rem; color: #5b6b7a; }
    .vk-session-meta b { color: #1b2733; }
    .vk-out { margin-left: auto; font-size: .72rem; font-weight: 700; padding: 3px 10px; border-radius: 20px; }
    .vk-out-logout { background: #e9ecef; color: #495057; }
    .vk-out-open   { background: #fff3cd; color: #8a6d00; }
    /* timeline body */
    .vk-tl { list-style: none; margin: 0; padding: 10px 18px 16px; position: relative; }
    .vk-tl:before { content: ''; position: absolute; left: 30px; top: 8px; bottom: 12px; width: 2px; background: #eef2f7; }
    .vk-tl li { position: relative; padding: 8px 0 8px 44px; }
    .vk-tl-dot { position: absolute; left: 22px; top: 12px; width: 16px; height: 16px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 1px #e4e9ef; display: flex; }
    .vk-tl-time { font-variant-numeric: tabular-nums; font-weight: 700; color: #1b2733; font-size: .82rem; }
    .vk-tl-desc { color: #3a4855; font-size: .88rem; }
    .vk-tl-sub  { color: #8a97a5; font-size: .72rem; }
    .vk-stat { border: 1px solid #e4e9ef; border-left: 5px solid #1769aa; border-radius: 12px; background: #fff; padding: 12px 16px; }
    .vk-stat .v { font-size: 1.35rem; font-weight: 800; color: #1769aa; line-height: 1; }
    .vk-stat .l { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; font-weight: 700; }
    @media print { .no-print { display: none !important; } }
</style>

<div class="vk-tl-page mx-auto py-4">
    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3 no-print">
        <div>
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-person-lines-fill text-primary me-2"></i>
                <?= $isSw ? 'Ratiba ya Shughuli za Mtumiaji' : 'User Activity Timeline' ?>
            </h3>
            <p class="text-muted small mb-0">
                <?= $isSw ? 'Fuatilia shughuli za mtumiaji mmoja kwa vipindi vya kuingia' : 'Follow one person\'s activity, grouped into sign-in sessions' ?>
            </p>
        </div>
        <a href="<?= getUrl('audit-logs') ?>" class="btn btn-outline-primary px-4 rounded-pill fw-bold">
            <i class="bi bi-list-ul me-1"></i><?= $isSw ? 'Kumbukumbu Zote' : 'All Activity Logs' ?>
        </a>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 no-print">
        <div class="card-body p-4">
            <form id="tlForm" method="get" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Mtumiaji' : 'Member / User' ?></label>
                    <select class="form-select border-0 bg-light rounded-3" name="user_id" onchange="document.getElementById('tlForm').submit()">
                        <option value="0"><?= $isSw ? '— Chagua mtumiaji —' : '— Choose a user —' ?></option>
                        <?php foreach ($users as $u): $nm = trim($u['first_name'] . ' ' . $u['last_name']); ?>
                            <option value="<?= (int) $u['user_id'] ?>" <?= $sel_user === (int) $u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nm) ?><?= $u['role_name'] ? ' — ' . htmlspecialchars($u['role_name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted"><?= $isSw ? 'Kipindi' : 'Period' ?></label>
                    <select class="form-select border-0 bg-light rounded-3" name="days" onchange="document.getElementById('tlForm').submit()">
                        <?php foreach ([7 => ($isSw ? 'Siku 7 zilizopita' : 'Last 7 days'),
                                        30 => ($isSw ? 'Siku 30 zilizopita' : 'Last 30 days'),
                                        90 => ($isSw ? 'Siku 90 zilizopita' : 'Last 90 days'),
                                        0 => ($isSw ? 'Muda wote' : 'All time')] as $d => $lbl): ?>
                            <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="d-flex align-items-center gap-2 mb-0 mt-2" style="cursor:pointer;">
                        <input type="checkbox" name="show_views" value="1" class="form-check-input mt-0"
                               <?= $show_views ? 'checked' : '' ?> onchange="document.getElementById('tlForm').submit()">
                        <span class="small fw-bold text-muted"><?= $isSw ? 'Onyesha mionekano ya kurasa' : 'Include page views' ?></span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$sel_user): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-person-lines-fill fs-1 d-block mb-2"></i>
            <?= $isSw ? 'Chagua mtumiaji hapo juu ili kuona ratiba yao ya shughuli.' : 'Choose a user above to see their activity timeline.' ?>
        </div>
    <?php elseif (empty($sessions)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
            <?= $isSw ? 'Hakuna shughuli zilizorekodiwa kwa mtumiaji huyu katika kipindi hiki.' : 'No recorded activity for this user in the selected period.' ?>
        </div>
    <?php else: ?>
        <!-- Person summary -->
        <div class="row g-2 mb-4">
            <div class="col-12 col-md-4">
                <div class="vk-stat h-100">
                    <div class="l"><?= $isSw ? 'Mtumiaji' : 'User' ?></div>
                    <div class="fw-bold text-dark mt-1" style="font-size:1.05rem;"><?= htmlspecialchars($sel_name ?: ('#' . $sel_user)) ?></div>
                    <div class="vk-tl-sub"><?= htmlspecialchars($sel_role ?: ($isSw ? 'Hakuna wadhifa' : 'No role')) ?></div>
                </div>
            </div>
            <div class="col-4 col-md-2"><div class="vk-stat h-100"><div class="v"><?= count($sessions) ?></div><div class="l"><?= $isSw ? 'Vipindi' : 'Sessions' ?></div></div></div>
            <div class="col-4 col-md-2"><div class="vk-stat h-100"><div class="v"><?= number_format($total_actions) ?></div><div class="l"><?= $isSw ? 'Vitendo' : 'Actions' ?></div></div></div>
            <div class="col-4 col-md-4">
                <div class="vk-stat h-100">
                    <div class="l"><?= $isSw ? 'Mara ya mwisho' : 'Last seen' ?></div>
                    <div class="fw-bold text-dark mt-1" style="font-size:1.0rem;"><?= $last_seen ? date('d/m/Y H:i:s', strtotime($last_seen)) : '—' ?></div>
                </div>
            </div>
        </div>

        <!-- Sessions -->
        <?php foreach ($sessions as $s):
            $dur   = tl_duration(max(0, $s['last_ts'] - $s['start_ts']), $isSw);
            $count = count($s['events']);
        ?>
        <div class="vk-session">
            <div class="vk-session-head">
                <span class="vk-session-date"><i class="bi bi-calendar3 me-1"></i><?= date('D, d M Y', $s['start_ts']) ?></span>
                <span class="vk-session-meta">
                    <i class="bi bi-clock me-1"></i><b><?= date('H:i:s', $s['start_ts']) ?></b> &rarr; <b><?= date('H:i:s', $s['last_ts']) ?></b>
                    &nbsp;·&nbsp; <?= $isSw ? 'Muda' : 'Duration' ?>: <b><?= $dur ?></b>
                    &nbsp;·&nbsp; <b><?= $count ?></b> <?= $isSw ? 'vitendo' : ($count === 1 ? 'action' : 'actions') ?>
                </span>
                <?php if ($s['ended'] === 'logout'): ?>
                    <span class="vk-out vk-out-logout"><i class="bi bi-box-arrow-right me-1"></i><?= $isSw ? 'Alitoka' : 'Signed out' ?></span>
                <?php else: ?>
                    <span class="vk-out vk-out-open"><i class="bi bi-dash-circle me-1"></i><?= $isSw ? 'Hakuna kutoka' : 'No logout recorded' ?></span>
                <?php endif; ?>
            </div>
            <ul class="vk-tl">
                <?php foreach ($s['events'] as $e):
                    $b = tl_badge($e['action'] ?? '', $isSw);
                    $desc = $e['description'] ?: trim(($e['action'] ?? '') . ' · ' . ($e['module'] ?? ''));
                    $sub  = [];
                    if (!empty($e['module']))    $sub[] = $e['module'];
                    if (!empty($e['reference'])) $sub[] = $e['reference'];
                    if (!empty($e['ip_address'])) $sub[] = 'IP ' . $e['ip_address'];
                ?>
                <li>
                    <span class="vk-tl-dot text-bg-<?= $b['color'] ?>"></span>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="vk-tl-time"><?= date('H:i:s', strtotime($e['created_at'])) ?></span>
                        <span class="badge text-bg-<?= $b['color'] ?> rounded-pill" style="font-size:10px;">
                            <i class="bi bi-<?= $b['icon'] ?> me-1"></i><?= $b['label'] ?>
                        </span>
                    </div>
                    <div class="vk-tl-desc"><?= htmlspecialchars($desc) ?></div>
                    <?php if ($sub): ?><div class="vk-tl-sub"><?= htmlspecialchars(implode(' · ', $sub)) ?></div><?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once ROOT_DIR . '/footer.php'; ?>
