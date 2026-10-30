<?php
/** My account: who is signed in, and changing your own password. */

$pwError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwError = change_own_password($me, (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''), (string) ($_POST['again'] ?? ''));
    if ($pwError === null) {
        admin_audit($me, 'change own password', null, 'done');
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Password changed.';
        redirect('/account');
    }
}

$st = admin_db()->prepare('SELECT username, full_name, last_login_at, last_login_ip, password_changed_at, created_at FROM superadmins WHERE id = ?');
$st->execute([$me['id']]);
$a = $st->fetch();
$now = time();

layout('My account', 'account', static function () use ($a, $now, $pwError) {
    page_head('My account', 'Who is signed in, and your password.'); ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="vk-card h-100">
        <h2><i class="bi bi-person-circle"></i>Account</h2>
        <dl class="row vk-dl mb-0">
          <dt class="col-sm-5">Name</dt><dd class="col-sm-7"><?= e($a['full_name']) ?></dd>
          <dt class="col-sm-5">Username</dt><dd class="col-sm-7"><?= e($a['username']) ?></dd>
          <dt class="col-sm-5">This sign-in</dt><dd class="col-sm-7"><?= e(vk_admin_when(db_time($a['last_login_at']), $now)) ?> <span class="small text-secondary"><?= e($a['last_login_ip']) ?></span></dd>
          <dt class="col-sm-5">Password changed</dt><dd class="col-sm-7"><?= e(vk_admin_when(db_time($a['password_changed_at']), $now)) ?></dd>
        </dl>
      </div>
    </div>
    <div class="col-lg-7">
      <form method="post" action="/account" class="vk-card h-100" autocomplete="off">
        <h2><i class="bi bi-shield-lock"></i>Change my password</h2>
        <?php if ($pwError): ?><div class="alert alert-danger py-2"><?= e($pwError) ?></div><?php endif; ?>
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="current">Current password</label>
          <input class="form-control" id="current" name="current" type="password" autocomplete="current-password" required></div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6"><label class="form-label" for="new">New password</label>
            <input class="form-control" id="new" name="new" type="password" autocomplete="new-password" minlength="12" required>
            <div class="form-text">At least 12 characters, letters and a digit.</div></div>
          <div class="col-sm-6"><label class="form-label" for="again">New password again</label>
            <input class="form-control" id="again" name="again" type="password" autocomplete="new-password" required></div>
        </div>
        <button class="btn btn-primary">Change password</button>
      </form>
    </div>
  </div>
<?php });
