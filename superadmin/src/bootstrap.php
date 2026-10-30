<?php
/**
 * superadmin/src/bootstrap.php — config, database, session, login guard and the
 * page layout for the super-admin panel (admin.vikundi.bjptechnologies.co.tz).
 *
 * The panel has its own Apache site whose document root is superadmin/public.
 * It never loads includes/tenant_config.php and never opens a group's database.
 *
 * Reads /etc/vikundi/admin.php (or $VIKUNDI_ADMIN_CONFIG):
 *   dsn, user, password   the vikundi_admin MySQL login (narrow rights on vikundi_control)
 *   base_domain           vikundi.bjptechnologies.co.tz
 *   data_root             /var/lib/vikundi (for last backup and disk use)
 *   insecure_cookie       true only for local testing over plain http
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../../includes/admin_requests.php';

date_default_timezone_set('Africa/Dar_es_Salaam'); // times are shown in Tanzania time

foreach (vk_admin_security_headers() as $k => $v) {
    header("$k: $v");
}

function admin_config(): array
{
    static $conf = null;
    if ($conf === null) {
        $file = getenv('VIKUNDI_ADMIN_CONFIG') ?: '/etc/vikundi/admin.php';
        $conf = is_file($file) ? require $file : null;
        if (!is_array($conf)) {
            error_log("Vikundi admin: $file is missing or invalid.");
            http_response_code(503);
            exit('The panel is not configured.');
        }
    }
    return $conf;
}

function admin_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = admin_config();
        try {
            $pdo = new PDO($c['dsn'], $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            error_log('Vikundi admin: database connection failed: ' . $e->getMessage());
            http_response_code(503);
            exit('The panel cannot reach its database.');
        }
    }
    return $pdo;
}

/** A DATETIME read from vikundi_control as a Unix time (see vk_admin_db_ts). */
function db_time(?string $datetime): ?int
{
    static $offset = null;
    if ($offset === null) {
        $offset = (int) admin_db()->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    }
    return vk_admin_db_ts($datetime, $offset);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function not_found(): never
{
    http_response_code(404);
    layout('Not found', '', static function () {
        echo '<div class="vk-card vk-empty"><i class="bi bi-signpost-split"></i><h1 class="h5">Not found</h1><p>That page or group does not exist.</p><a class="btn btn-primary btn-sm" href="/groups">Back to groups</a></div>';
    });
    exit;
}

// ── session ─────────────────────────────────────────────────────────────────
session_name('vkadmin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => empty(admin_config()['insecure_cookie']),
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
session_start();

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Every POST must carry the session's token; anything else stops here. */
function csrf_check(): void
{
    if (!vk_admin_csrf_valid($_SESSION['csrf'] ?? null, $_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
}

/** The logged-in super-admin, re-read on every request, or null. */
function admin_current(): ?array
{
    static $me = false;
    if ($me !== false) {
        return $me;
    }
    $me = null;
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    if (vk_admin_session_expired($_SESSION['last_seen'] ?? null, time())) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'You were signed out after 30 minutes without activity.';
        return null;
    }
    $st = admin_db()->prepare('SELECT id, username, full_name, is_active FROM superadmins WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !(int) $row['is_active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    $_SESSION['last_seen'] = time();
    return $me = $row;
}

function admin_require(): array
{
    return admin_current() ?? redirect('/login');
}

function flash(): ?string
{
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/** "Create group", "Suspend group", ... */
function req_label(string $action): string
{
    return ucfirst(vk_req_audit_action($action));
}

function status_badge(string $status): string
{
    return '<span class="vk-pill ' . vk_admin_status_class($status) . '">' . e(ucfirst($status)) . '</span>';
}

/** The page heading: optional breadcrumb, title, subtitle and buttons on the right. */
function page_head(string $title, string $sub = '', string $actions = '', array $crumbs = []): void
{
    echo '<div class="vk-head"><div>';
    if ($crumbs) {
        $parts = [];
        foreach ($crumbs as $label => $href) {
            $parts[] = $href ? '<a href="' . e($href) . '">' . e($label) . '</a>' : e($label);
        }
        echo '<div class="vk-crumb">' . implode(' / ', $parts) . '</div>';
    }
    echo '<h1>' . e($title) . '</h1>';
    if ($sub !== '') {
        echo '<div class="sub">' . e($sub) . '</div>';
    }
    echo '</div>' . ($actions !== '' ? '<div class="d-flex gap-2 flex-wrap">' . $actions . '</div>' : '') . '</div>';
}

// ── layout ──────────────────────────────────────────────────────────────────
/**
 * Every page. The panel runs no JavaScript and allows no inline styles
 * (vk_admin_security_headers): the look is all in /assets/admin.css, and on a
 * phone the menu is a <details> element.
 */
function layout(string $title, string $active, callable $body): void
{
    $me = $active === 'login' ? null : admin_current();
    $nav = ['groups' => ['Groups', 'bi-grid-1x2'], 'requests' => ['Requests', 'bi-hourglass-split'],
            'history' => ['History', 'bi-clock-history'], 'account' => ['My account', 'bi-person-circle']];
    $pending = $me ? data_pending_count() : 0;
    $logo = '<span class="mark">V</span><span class="name">Vikundi<small>Super-Admin</small></span>';
    $menu = static function () use ($nav, $active, $pending): void { ?>
      <ul class="vk-nav">
        <?php foreach ($nav as $key => [$label, $icon]): ?>
          <li><a class="<?= $active === $key ? 'active' : '' ?>" href="/<?= $key ?>"><i class="bi <?= $icon ?>"></i><?= e($label) ?>
            <?php if ($key === 'requests' && $pending > 0): ?><span class="count"><?= $pending ?></span><?php endif; ?></a></li>
        <?php endforeach; ?>
        <li><a class="cta" href="/create"><i class="bi bi-plus-circle"></i>Create group</a></li>
      </ul>
    <?php };
    $signOut = static function (): void { ?>
      <form method="post" action="/logout" class="d-inline"><?= csrf_field() ?><button>Sign out</button></form>
    <?php };
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Vikundi Super-Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<?php if (!$me): ?>
<div class="vk-auth"><div class="box">
  <div class="vk-logo"><?= $logo ?></div>
  <?php if ($m = flash()): ?><div class="alert alert-info py-2 small"><?= e($m) ?></div><?php endif; ?>
  <?php $body(); ?>
</div></div>
<?php else: ?>
<details class="vk-mobilebar">
  <summary><a class="vk-logo" href="/groups"><?= $logo ?></a><span class="menu"><i class="bi bi-list"></i> Menu<?= $pending > 0 ? ' (' . $pending . ')' : '' ?></span></summary>
  <?php $menu(); ?>
  <div class="foot"><?= e($me['full_name']) ?> &middot; <?php $signOut(); ?></div>
</details>
<div class="vk-shell">
  <aside class="vk-side">
    <a class="vk-logo" href="/groups"><?= $logo ?></a>
    <?php $menu(); ?>
    <div class="foot"><div class="who"><?= e($me['full_name']) ?></div><?php $signOut(); ?></div>
  </aside>
  <main class="vk-main">
    <?php if ($m = flash()): ?><div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= e($m) ?></div><?php endif; ?>
    <?php $body(); ?>
  </main>
</div>
<?php endif; ?>
</body>
</html>
<?php
}
