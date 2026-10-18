<?php
/** My account: who is signed in. Changing the password arrives with the panel's next update. */

$st = admin_db()->prepare('SELECT username, full_name, last_login_at, last_login_ip, password_changed_at, created_at FROM superadmins WHERE id = ?');
$st->execute([$me['id']]);
$a = $st->fetch();
$now = time();

layout('My account', 'account', static function () use ($a, $now) { ?>
  <h1 class="h4 mb-3">My account</h1>
  <div class="vk-card vk-narrow">
    <dl class="row vk-dl mb-0">
      <dt class="col-sm-5">Name</dt><dd class="col-sm-7"><?= e($a['full_name']) ?></dd>
      <dt class="col-sm-5">Username</dt><dd class="col-sm-7"><?= e($a['username']) ?></dd>
      <dt class="col-sm-5">This sign-in</dt><dd class="col-sm-7"><?= e(vk_admin_when(db_time($a['last_login_at']), $now)) ?> <span class="small text-secondary"><?= e($a['last_login_ip']) ?></span></dd>
      <dt class="col-sm-5">Password changed</dt><dd class="col-sm-7"><?= e(vk_admin_when(db_time($a['password_changed_at']), $now)) ?></dd>
    </dl>
  </div>
<?php });
