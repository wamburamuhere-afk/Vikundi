<?php
/**
 * Create a group. The form is checked here with the same rules the worker uses
 * (and checks again); on success a request is saved and you land on its page,
 * which shows the progress and then the first Chairperson's password, once.
 */

$base = (string) admin_config()['base_domain'];
$sub = '';
$p = vk_req_clean_params([]);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sub = strtolower(trim((string) ($_POST['subdomain'] ?? '')));
    $p = vk_req_clean_params($_POST);
    $taken = admin_db()->query('SELECT subdomain FROM tenants')->fetchAll(PDO::FETCH_COLUMN);
    $hosts = admin_db()->query('SELECT host FROM tenant_hosts')->fetchAll(PDO::FETCH_COLUMN);
    $errors = vk_req_validate('create', $sub, $p, $taken, $hosts, $base);
    if (!$errors && group_has_pending($sub)) {
        $errors[] = "A request for '$sub' is already waiting or running.";
    }
    if (!$errors) {
        $id = queue_request((int) $me['id'], 'create', $sub, $p);
        $_SESSION['flash'] = "Request #$id saved. The worker on the server picks it up within a minute.";
        redirect("/requests/$id");
    }
}

layout('Create group', 'groups', static function () use ($base, $sub, $p, $errors) {
    page_head('Create group', 'The worker builds the group within a minute: its database, address, https and first Chairperson.', '', ['Groups' => '/groups', 'Create group' => '']); ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger vk-narrow"><strong>Please fix:</strong><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" action="/create" class="vk-card vk-narrow" autocomplete="off">
    <?= csrf_field() ?>
    <h2><i class="bi bi-people"></i>The group</h2>
    <div class="mb-3">
      <label class="form-label" for="name">Group name</label>
      <input class="form-control" id="name" name="name" maxlength="150" value="<?= e($p['name']) ?>" required>
    </div>
    <div class="mb-4">
      <label class="form-label" for="subdomain">Address</label>
      <div class="input-group">
        <input class="form-control" id="subdomain" name="subdomain" maxlength="29" value="<?= e($sub) ?>" pattern="[a-z0-9][a-z0-9-]{1,27}[a-z0-9]" required>
        <span class="input-group-text">.<?= e($base) ?></span>
      </div>
      <div class="form-text">3 to 29 lowercase letters, digits or single hyphens. Checked when you press Create.</div>
    </div>
    <h2><i class="bi bi-person-badge"></i>First Chairperson</h2>
    <div class="row g-2 mb-3">
      <div class="col-sm-6">
        <label class="form-label" for="chair_first">First name</label>
        <input class="form-control" id="chair_first" name="chair_first" maxlength="60" value="<?= e($p['chair_first']) ?>" required>
      </div>
      <div class="col-sm-6">
        <label class="form-label" for="chair_last">Last name</label>
        <input class="form-control" id="chair_last" name="chair_last" maxlength="60" value="<?= e($p['chair_last']) ?>" required>
      </div>
    </div>
    <div class="row g-2 mb-4">
      <div class="col-sm-6">
        <label class="form-label" for="chair_phone">Phone</label>
        <input class="form-control" id="chair_phone" name="chair_phone" inputmode="tel" value="<?= e($p['chair_phone']) ?>" placeholder="255712345678" required>
      </div>
      <div class="col-sm-6">
        <label class="form-label" for="chair_email">Email <span class="text-secondary">(optional)</span></label>
        <input class="form-control" id="chair_email" name="chair_email" type="email" value="<?= e($p['chair_email']) ?>">
      </div>
    </div>
    <label class="vk-switch" for="demo">
      <input class="form-check-input" type="checkbox" id="demo" name="demo" value="1"<?= $p['demo'] ? ' checked' : '' ?>>
      <span><strong>Demo group (for prospects)</strong>
      <small>Adds sample members, contributions, Social Fund payments and loans around the Chairperson. When they sign up, use <em>Clear demo data</em> on the group page.</small></span>
    </label>
    <button class="btn btn-primary w-100 mt-2"><i class="bi bi-plus-lg me-1"></i>Create group</button>
  </form>
<?php });
