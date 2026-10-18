<?php
/**
 * Login. Five wrong passwords lock the login for 15 minutes. The same message
 * is shown for an unknown username, a wrong password and a disabled login, so
 * the page never says which usernames exist.
 */

if (admin_current()) {
    redirect('/groups');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $now = time();

    $st = admin_db()->prepare('SELECT * FROM superadmins WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch() ?: null;

    // Spend the same time whether or not the username exists.
    $hash = $row['password_hash'] ?? '$2y$12$U4lG52b0wBoY5KVOC.Dn1OXt0UggfOSWaaFrYxx0O8kxy6FdSPpmW';
    $ok = password_verify($password, $hash);

    if ($row && vk_admin_is_locked($row['locked_until'], $now)) {
        $error = 'Too many attempts. Try again in 15 minutes.';
    } elseif (!$row || !$ok || !(int) $row['is_active']) {
        if ($row) {
            [$failed, $lockUntil] = vk_admin_after_failed_login((int) $row['failed_attempts'], $now);
            admin_db()->prepare('UPDATE superadmins SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$failed, $lockUntil ? date('Y-m-d H:i:s', $lockUntil) : null, $row['id']]);
            if ($lockUntil) {
                admin_db()->prepare("INSERT INTO admin_audit_log (actor_type, actor_id, actor_name, action, result, detail, ip)
                                     VALUES ('superadmin', ?, ?, 'sign in', 'locked', '5 wrong passwords: locked for 15 minutes', ?)")
                    ->execute([$row['id'], $row['full_name'], $_SERVER['REMOTE_ADDR'] ?? null]);
            }
        }
        $error = 'Wrong username or password.';
        usleep(random_int(200000, 400000));
    } else {
        admin_db()->prepare('UPDATE superadmins SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = ? WHERE id = ?')
            ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $row['id']]);
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            admin_db()->prepare('UPDATE superadmins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
        }
        admin_db()->prepare("INSERT INTO admin_audit_log (actor_type, actor_id, actor_name, action, result, ip)
                             VALUES ('superadmin', ?, ?, 'sign in', 'done', ?)")
            ->execute([$row['id'], $row['full_name'], $_SERVER['REMOTE_ADDR'] ?? null]);

        session_regenerate_id(true);
        $_SESSION = ['admin_id' => (int) $row['id'], 'last_seen' => time()];
        redirect('/groups');
    }
}

layout('Sign in', 'login', static function () use ($error, $username) { ?>
  <div class="vk-login mx-auto">
    <h1 class="h4 mb-1">Vikundi Super-Admin</h1>
    <p class="text-secondary small mb-4">BJP Technologies staff only.</p>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/login" class="vk-card" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" value="<?= e($username) ?>" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required>
      </div>
      <button class="btn btn-primary w-100">Sign in</button>
    </form>
    <p class="text-secondary small mt-3">Lost your password? It is reset on the server.</p>
  </div>
<?php });
