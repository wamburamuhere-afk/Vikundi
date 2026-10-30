<?php
/** One group: its details, its recent requests and its history. */

$g = data_group((string) $param);
if (!$g) {
    not_found();
}
$allowed = vk_admin_allowed_actions($g['status'], (bool) (int) $g['is_demo']);
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
    } elseif ($action === 'clear_demo' && trim((string) ($_POST['confirm'] ?? '')) !== $g['subdomain']) {
        $actionError = "To clear the demo data, type the address '{$g['subdomain']}' exactly.";
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

layout($g['name'], 'groups', static function () use ($g, $backup, $disk, $requests, $history, $now, $base, $allowed, $pending, $actionError, $features, $hasFeatureColumn) {
    $url = 'https://' . $g['subdomain'] . '.' . $base;
    $guide = $url . '/guide';
    $bk = vk_admin_backup_state($backup[1] ?? null, $now);
    $canAct = !$pending && $allowed; ?>
  <div class="vk-crumb"><a href="/groups">Groups</a> / <?= e($g['name']) ?></div>
  <div class="vk-hero">
    <div>
      <h1><?= e($g['name']) ?></h1>
      <a class="addr" href="<?= e($url) ?>" target="_blank" rel="noopener"><i class="bi bi-globe2 me-1"></i><?= e($g['subdomain'] . '.' . $base) ?></a>
      <div class="tags">
        <?= status_badge($g['status']) ?>
        <?php if ((int) $g['is_demo']): ?><span class="vk-chip">Demo group</span><?php endif; ?>
        <?php foreach (vk_feature_catalog() as $k => $f): if (!$features[$k]): ?><span class="vk-chip"><?= e($f['label']) ?> off</span><?php endif; endforeach; ?>
      </div>
    </div>
    <?php if ($g['status'] === 'active'): ?>
    <div class="btns">
      <a class="btn btn-light" href="<?= e($url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open site</a>
      <a class="btn btn-outline-light" href="<?= e($guide) ?>?lang=en" target="_blank" rel="noopener"><i class="bi bi-book me-1"></i>Guide (EN)</a>
      <a class="btn btn-outline-light" href="<?= e($guide) ?>?lang=sw" target="_blank" rel="noopener">Mwongozo (SW)</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($actionError): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($actionError) ?></div><?php endif; ?>
  <?php if ($pending): ?><div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i>A request for this group is waiting or running. Actions come back when it finishes. <a href="/requests?status=waiting">See requests</a></div><?php endif; ?>

  <div class="vk-stats">
    <div class="vk-stat <?= $bk === 'ok' ? 'green' : ($bk === 'late' ? 'amber' : 'red') ?>"><span class="ic"><i class="bi bi-shield-check"></i></span>
      <span><span class="num d-block fs-6"><?= $backup ? e(vk_admin_when($backup[1], $now)) : 'None found' ?></span><span class="lbl d-block">Last backup</span></span></div>
    <div class="vk-stat"><span class="ic"><i class="bi bi-hdd"></i></span>
      <span><span class="num d-block fs-6"><?= $disk === null ? 'Unknown' : e(vk_admin_bytes($disk)) ?></span><span class="lbl d-block">Disk used</span></span></div>
    <div class="vk-stat grey"><span class="ic"><i class="bi bi-calendar3"></i></span>
      <span><span class="num d-block fs-6"><?= e(date('j M Y', db_time($g['created_at']))) ?></span><span class="lbl d-block">Created</span></span></div>
  </div>

  <div class="row g-3">
    <div class="col-xl-7">
      <div class="vk-card h-100">
        <h2><i class="bi bi-info-circle"></i>Details</h2>
        <dl class="row vk-dl mb-0">
          <dt class="col-sm-4">Address</dt>
          <dd class="col-sm-8"><a href="<?= e($url) ?>" rel="noopener" target="_blank"><?= e($g['subdomain'] . '.' . $base) ?></a></dd>
          <dt class="col-sm-4">Group guide</dt>
          <dd class="col-sm-8"><?php $guide = 'https://' . $g['subdomain'] . '.' . $base . '/guide'; ?>
            <a href="<?= e($guide) ?>?lang=en" rel="noopener" target="_blank">English</a> &middot;
            <a href="<?= e($guide) ?>?lang=sw" rel="noopener" target="_blank">Kiswahili</a>
            <div class="form-text">Send this link (or its Download PDF) to the group. The password goes separately.</div></dd>
          <?php $others = array_values(array_diff($g['hosts'], [$g['subdomain'] . '.' . $base])); if ($others): ?>
          <dt class="col-sm-4">Also reached at</dt><dd class="col-sm-8"><?= e(implode(', ', $others)) ?></dd>
          <?php endif; ?>
          <dt class="col-sm-4">Database</dt><dd class="col-sm-8"><code><?= e($g['db_name']) ?></code></dd>
          <dt class="col-sm-4">Created</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['created_at']))) ?></dd>
          <?php if ($g['suspended_at']): ?><dt class="col-sm-4">Suspended</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['suspended_at']))) ?></dd><?php endif; ?>
          <?php if ($g['archived_at']): ?><dt class="col-sm-4">Archived</dt><dd class="col-sm-8"><?= e(date('j M Y H:i', db_time($g['archived_at']))) ?></dd><?php endif; ?>
          <dt class="col-sm-4">Last backup</dt>
          <dd class="col-sm-8"><span class="vk-bk <?= $bk ?>"><?= $backup ? e(vk_admin_when($backup[1], $now)) : 'No backup found' ?></span><?php if ($backup): ?> <span class="small text-secondary">(<?= e($backup[0]) ?>)</span><?php endif; ?></dd>
          <dt class="col-sm-4">Demo group</dt><dd class="col-sm-8"><?= (int) $g['is_demo'] ? 'Yes: sample data' : 'No' ?></dd>
        </dl>
      </div>
    </div>

    <div class="col-xl-5">
      <div class="vk-card h-100">
        <h2><i class="bi bi-lightning-charge"></i>Actions</h2>
        <?php if (!$allowed): ?>
          <p class="small text-secondary mb-0">No actions for a group that is <?= e($g['status']) ?>.<?php if ($g['status'] === 'archived'): ?> An archived group can only be brought back from the server.<?php endif; ?></p>
        <?php else: ?>
          <?php if (in_array('suspend', $allowed, true)): ?>
          <div class="vk-action"><div class="txt"><strong>Suspend</strong><small>Members see the offline page. Nothing is deleted.</small></div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><button class="btn btn-warning btn-sm"<?= $canAct ? '' : ' disabled' ?>>Suspend</button></form></div>
          <?php endif; ?>
          <?php if (in_array('resume', $allowed, true)): ?>
          <div class="vk-action"><div class="txt"><strong>Resume</strong><small>Back online within a minute.</small></div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume"><button class="btn btn-primary btn-sm"<?= $canAct ? '' : ' disabled' ?>>Resume</button></form></div>
          <?php endif; ?>
          <div class="vk-action"><div class="txt"><strong>Https certificate</strong><small>Run again if the padlock is missing on this address.</small></div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cert"><button class="btn btn-outline-secondary btn-sm"<?= $canAct ? '' : ' disabled' ?>>Retry</button></form></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12">
      <div class="vk-card">
        <h2><i class="bi bi-toggles"></i>Features</h2>
        <p class="help">What this group has. A feature that is off disappears for everyone in the group, leaders included. Nothing is deleted: switching it back on brings it all back.</p>
        <?php if (!$hasFeatureColumn): ?>
          <p class="small text-danger mb-0">The registry has no features column yet. Run <code>database/control/add_tenant_features.sql</code> as MySQL root.</p>
        <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="features">
          <div class="row g-2">
          <?php foreach (vk_feature_catalog() as $k => $f): ?>
          <div class="col-md-6"><label class="vk-switch form-switch" for="feature-<?= e($k) ?>">
            <input class="form-check-input" type="checkbox" role="switch" id="feature-<?= e($k) ?>" name="feature[<?= e($k) ?>]" value="1"<?= $features[$k] ? ' checked' : '' ?><?= ($pending || !in_array('features', $allowed, true)) ? ' disabled' : '' ?>>
            <span><strong><?= e($f['label']) ?></strong><small><?= e($f['help']) ?></small></span>
          </label></div>
          <?php endforeach; ?>
          </div>
          <?php if (!$pending && in_array('features', $allowed, true)): ?>
          <button class="btn btn-primary btn-sm mt-1">Save features</button>
          <span class="form-text ms-2">Takes effect within a minute, when the worker runs.</span>
          <?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canAct && (in_array('archive', $allowed, true) || in_array('clear_demo', $allowed, true))): ?>
    <div class="col-12">
      <div class="vk-danger">
        <div class="hd"><i class="bi bi-exclamation-octagon"></i>Danger zone</div>
        <?php if (in_array('clear_demo', $allowed, true)): ?>
        <details>
          <summary>Clear demo data<small>when this prospect signs up</small></summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear_demo">
            <p class="small mb-2">Removes every sample member, contribution, loan, fine and expense. The Chairperson's login, the group's name and the settings they changed stay. The group then becomes a real group, and this cannot be undone. Type <code><?= e($g['subdomain']) ?></code> to confirm.</p>
            <input class="form-control form-control-sm mb-2" name="confirm" autocomplete="off" required>
            <button class="btn btn-outline-danger btn-sm">Clear demo data</button></form>
        </details>
        <?php endif; ?>
        <?php if (in_array('archive', $allowed, true)): ?>
        <details>
          <summary>Archive group<small>final backup, then offline for good</small></summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="archive">
            <p class="small mb-2">Takes a final backup, then takes the group offline for good. Nothing is deleted. Type <code><?= e($g['subdomain']) ?></code> to confirm.</p>
            <input class="form-control form-control-sm mb-2" name="confirm" autocomplete="off" required>
            <button class="btn btn-danger btn-sm">Archive <?= e($g['name']) ?></button></form>
        </details>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="col-lg-6">
      <div class="vk-card flush h-100">
        <div class="p-3 pb-0"><h2><i class="bi bi-hourglass-split"></i>Recent requests</h2></div>
        <?php if (!$requests): ?><p class="text-secondary small px-3 pb-3 mb-0">None yet.</p><?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0">
          <thead><tr><th>#</th><th>Action</th><th>Status</th><th>Asked</th></tr></thead>
          <tbody><?php foreach ($requests as $r): ?>
            <tr><td><a href="/requests/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td><td><?= e(req_label($r['action'])) ?></td>
                <td><?= status_badge($r['status']) ?></td><td class="small text-secondary"><?= e(vk_admin_when(db_time($r['created_at']), $now)) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="vk-card h-100">
        <div class="d-flex justify-content-between"><h2><i class="bi bi-clock-history"></i>History</h2><a class="small" href="/history?group=<?= e($g['subdomain']) ?>">All</a></div>
        <?php if (!$history): ?><p class="text-secondary small mb-0">Nothing recorded yet.</p><?php else: ?>
        <ul class="vk-timeline"><?php foreach ($history as $h): ?>
          <li><span class="when"><?= e(vk_admin_when(db_time($h['created_at']), $now)) ?></span>
              <span class="what"><?= e($h['action']) ?> <span class="text-secondary small">by <?= e($h['actor_name']) ?></span></span>
              <?= status_badge($h['result']) ?></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php });
