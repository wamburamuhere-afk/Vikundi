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
        echo '<h1 class="h4">Not found</h1><p>That page or group does not exist.</p><p><a href="/groups">Back to groups</a></p>';
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
    return '<span class="badge rounded-pill text-bg-' . vk_admin_status_class($status) . '">' . e(ucfirst($status)) . '</span>';
}

// ── layout ──────────────────────────────────────────────────────────────────
function layout(string $title, string $active, callable $body): void
{
    $me = $active === 'login' ? null : admin_current();
    $nav = ['groups' => ['Groups', 'bi-collection'], 'requests' => ['Requests', 'bi-hourglass-split'], 'history' => ['History', 'bi-clock-history'], 'account' => ['My account', 'bi-person']];
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
<header class="vk-topbar border-bottom">
  <div class="container-fluid d-flex align-items-center justify-content-between py-2">
    <a class="vk-brand" href="/groups">Vikundi Super-Admin</a>
    <?php if ($me): ?>
      <div class="small text-secondary">Signed in: <?= e($me['full_name']) ?> &nbsp;|&nbsp;
        <form method="post" action="/logout" class="d-inline"><?= csrf_field() ?><button class="btn btn-link btn-sm p-0 align-baseline">Sign out</button></form>
      </div>
    <?php endif; ?>
  </div>
</header>
<div class="container-fluid">
  <div class="row">
    <?php if ($me): ?>
    <nav class="col-md-3 col-lg-2 py-3 vk-sidebar">
      <ul class="nav nav-pills flex-column gap-1">
        <?php foreach ($nav as $key => [$label, $icon]): ?>
          <li class="nav-item"><a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="/<?= $key ?>"><i class="bi <?= $icon ?> me-2"></i><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <main class="col-md-9 col-lg-10 py-3 px-md-4">
    <?php else: ?>
    <main class="col-12 py-5">
    <?php endif; ?>
      <?php if ($m = flash()): ?><div class="alert alert-info"><?= e($m) ?></div><?php endif; ?>
      <?php $body(); ?>
    </main>
  </div>
</div>
</body>
</html>
<?php
}
