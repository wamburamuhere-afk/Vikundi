<?php
/** One group: its details, its recent requests and its history. */

$g = data_group((string) $param);
if (!$g) {
    not_found();
}
$allowed = vk_admin_allowed_actions($g['status']);
$pending = group_has_pending($g['subdomain']);
$actionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, $allowed, true)) {
        $actionError = 'That action is not available for a group that is ' . $g['status'] . '.';
    } elseif ($pending) {
        $actionError = 'A request for this group is still waiting or running. Wait for it to finish.';
    } elseif ($action === 'archive' && trim((string) ($_POST['confirm'] ?? '')) !== $g['subdomain']) {
        $actionError = "To archive, type the address '{$g['subdomain']}' exactly.";
    } elseif ($action === 'features') {
        // Every known feature is sent: a ticked box is on, an unticked one is off.
        $want = [];
        foreach (array_keys(vk_feature_catalog()) as $k) {
            $want[$k] = !empty($_POST['feature'][$k]);
        }
        if ($want === vk_features_parse($g['features'] ?? null)) {
            $actionError = 'Nothing changed.';
        } else {
            $id = queue_request((int) $me['id'], 'features', $g['subdomain'], ['features' => $want]);
            $_SESSION['flash'] = "Request #$id saved. The worker on the server picks it up within a minute.";
            redirect("/requests/$id");
        }
    } else {
        $id = queue_request((int) $me['id'], $action, $g['subdomain']);
        $_SESSION['flash'] = "Request #$id saved. The worker on the server picks it up within a minute.";
        redirect("/requests/$id");
    }
}
$backup = data_last_backup($g['subdomain']);
$disk = data_disk_use($g['subdomain']);
$requests = data_requests('', $g['subdomain'], 10);
$history = data_history($g['subdomain'], 10);
$now = time();
$base = (string) admin_config()['base_domain'];
$features = vk_features_parse($g['features'] ?? null);
$hasFeatureColumn = array_key_exists('features', $g);

layout($g['name'], 'groups', static function () use ($g, $backup, $disk, $requests, $history, $now, $base, $allowed, $pending, $actionError, $features, $hasFeatureColumn) { ?>
  <nav class="small mb-2"><a href="/groups">Groups</a> / <?= e($g['name']) ?></nav>
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><?= e($g['name']) ?></h1> <?= status_badge($g['status']) ?>
  </div>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="vk-card h-100">
        <h2 class="h6 mb-3">Details</h2>
        <dl class="row vk-dl mb-0">
          <dt class="col-sm-4">Address</dt>
          <dd class="col-sm-8"><a href="https://<?= e($g['subdomain'] . '.' . $base) ?>" rel="noopener" target="_blank"><?= e($g['subdomain'] . '.' . $base) ?></a></dd>
          <?php if ($g['hosts']): ?>
          <dt class="col-sm-4">Also reached at</dt><dd class="col-sm-8"><?= e(implode(', ', $g['hosts'])) ?></dd>
          <?php endif; ?>
          <dt class="col-sm-4">Database</dt><dd class="col-sm-8"><code><?= e($g['db_name']) ?></code></dd>
          <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['created_at']))) ?></dd>
          <?php if ($g['suspended_at']): ?><dt class="col-sm-4">Suspended</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['suspended_at']))) ?></dd><?php endif; ?>
          <?php if ($g['archived_at']): ?><dt class="col-sm-4">Archived</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['archived_at']))) ?></dd><?php endif; ?>
          <dt class="col-sm-4">Last backup</dt>
          <dd class="col-sm-8"><?= $backup ? e(vk_admin_when($backup[1], $now)) . ' <span class="small text-secondary">(' . e($backup[0]) . ')</span>' : '<span class="text-danger">No backup found</span>' ?></dd>
          <dt class="col-sm-4">Disk used</dt><dd class="col-sm-8"><?= $disk === null ? 'unknown' : e(vk_admin_bytes($disk)) ?></dd>
          <dt class="col-sm-4">Demo group</dt><dd class="col-sm-8"><?= (int) $g['is_demo'] ? 'Yes' : 'No' ?></dd>
        </dl>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="vk-card h-100">
        <h2 class="h6 mb-3">Actions</h2>
        <?php if ($actionError): ?><div class="alert alert-danger py-2 small"><?= e($actionError) ?></div><?php endif; ?>
        <?php if ($pending): ?>
          <p class="small text-secondary mb-0">A request for this group is waiting or running. Actions come back when it finishes.</p>
        <?php elseif (!$allowed): ?>
          <p class="small text-secondary mb-0">No actions for a group that is <?= e($g['status']) ?>.<?php if ($g['status'] === 'archived'): ?> An archived group can only be brought back from the server.<?php endif; ?></p>
        <?php else: ?>
          <?php if (in_array('suspend', $allowed, true)): ?>
          <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="suspend">
            <button class="btn btn-warning w-100">Suspend group</button>
            <div class="form-text">Members see the offline page. Nothing is deleted.</div></form>
          <?php endif; ?>
          <?php if (in_array('resume', $allowed, true)): ?>
          <form method="post" class="mb-2"><?= csrf_field() ?><input type="hidden" name="action" value="resume">
            <button class="btn btn-primary w-100">Resume group</button>
            <div class="form-text">Back online within a minute.</div></form>
          <?php endif; ?>
          <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="cert">
            <button class="btn btn-outline-secondary w-100">Retry https certificate</button></form>
          <details>
            <summary class="text-danger small">Archive group&hellip;</summary>
            <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
              <p class="small mb-2">Takes a final backup, then takes the group offline for good. Nothing is deleted. Type <code><?= e($g['subdomain']) ?></code> to confirm.</p>
              <input class="form-control form-control-sm mb-2" name="confirm" autocomplete="off" required>
              <button class="btn btn-outline-danger btn-sm w-100">Archive <?= e($g['name']) ?></button></form>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="vk-card">
        <h2 class="h6 mb-1">Features</h2>
        <p class="small text-secondary mb-3">What this group has. A feature that is off disappears for everyone in the group, leaders included. Nothing is deleted: switching it back on brings it all back.</p>
        <?php if (!$hasFeatureColumn): ?>
          <p class="small text-danger mb-0">The registry has no features column yet. Run <code>database/control/add_tenant_features.sql</code> as MySQL root.</p>
        <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="features">
          <?php foreach (vk_feature_catalog() as $k => $f): ?>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" role="switch" id="feature-<?= e($k) ?>" name="feature[<?= e($k) ?>]" value="1"<?= $features[$k] ? ' checked' : '' ?><?= ($pending || !in_array('features', $allowed, true)) ? ' disabled' : '' ?>>
            <label class="form-check-label" for="feature-<?= e($k) ?>"><strong><?= e($f['label']) ?></strong> <span class="small text-secondary">&mdash; <?= e($f['help']) ?></span></label>
          </div>
          <?php endforeach; ?>
          <?php if (!$pending && in_array('features', $allowed, true)): ?>
          <button class="btn btn-primary btn-sm mt-1">Save features</button>
          <div class="form-text">Takes effect within a minute, when the worker runs.</div>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="vk-card">
        <h2 class="h6 mb-3">Recent requests for this group</h2>
        <?php if (!$requests): ?><p class="text-secondary small mb-0">None yet.</p><?php else: ?>
        <table class="table table-sm mb-0">
          <thead><tr><th>#</th><th>Action</th><th>Status</th><th>Asked</th><th>By</th></tr></thead>
          <tbody><?php foreach ($requests as $r): ?>
            <tr><td><a href="/requests/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td><td><?= e(req_label($r['action'])) ?></td>
                <td><?= status_badge($r['status']) ?></td><td class="small"><?= e(vk_admin_when(db_time($r['created_at']), $now)) ?></td>
                <td class="small"><?= e($r['admin_name'] ?? '') ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12">
      <div class="vk-card">
        <div class="d-flex justify-content-between"><h2 class="h6 mb-3">History</h2><a class="small" href="/history?group=<?= e($g['subdomain']) ?>">All history for this group</a></div>
        <?php if (!$history): ?><p class="text-secondary small mb-0">Nothing recorded yet.</p><?php else: ?>
        <table class="table table-sm mb-0">
          <tbody><?php foreach ($history as $h): ?>
            <tr><td class="small text-nowrap"><?= e(vk_admin_when(db_time($h['created_at']), $now)) ?></td><td class="small"><?= e($h['actor_name']) ?></td>
                <td class="small"><?= e($h['action']) ?></td><td><?= status_badge($h['result']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php });
