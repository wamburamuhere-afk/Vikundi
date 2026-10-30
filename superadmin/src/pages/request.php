<?php
/** One request: what was asked, how it went, and the command's output. */

$r = data_request((int) $param);
if (!$r) {
    not_found();
}
$now = time();
$params = $r['params'] ? (json_decode((string) $r['params'], true) ?: []) : [];
$pending = in_array($r['status'], ['waiting', 'running'], true);
$revealed = null;
$revealMissed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['reveal'] ?? '') === '1') {
    $revealed = take_one_time_secret((int) $r['id']);
    $revealMissed = $revealed === null;
    if ($revealed !== null) {
        admin_audit($me, 'view first Chairperson password', $r['subdomain'], 'done', null, (int) $r['id']);
    }
    $r = data_request((int) $r['id']);
}
$chairUser = vk_admin_username_from_output($r['output']);
if ($pending) {
    header('Refresh: 5'); // the panel runs no JavaScript; the browser reloads the page
}

layout('Request #' . (int) $r['id'], 'requests', static function () use ($r, $params, $pending, $now, $revealed, $revealMissed, $chairUser) {
    page_head('#' . (int) $r['id'] . ' ' . req_label($r['action']) . ($r['subdomain'] ? ' · ' . $r['subdomain'] : ''),
              'Asked ' . vk_admin_when(db_time($r['created_at']), $now) . ($r['admin_name'] ? ' by ' . $r['admin_name'] : ''),
              status_badge($r['status']), ['Requests' => '/requests', '#' . (int) $r['id'] => '']); ?>
  <div class="vk-card mb-3">
    <ol class="vk-steps">
      <?php foreach (vk_admin_request_steps($r['status']) as [$label, $state]): ?><li class="<?= $state ?>"><?= e($label) ?></li><?php endforeach; ?>
    </ol>
    <?php if ($pending): ?><p class="small text-secondary text-center mb-0"><i class="bi bi-arrow-repeat me-1"></i>The worker on the server picks requests up within a minute. This page refreshes by itself.</p><?php endif; ?>
    <?php if ($r['status'] === 'failed'): ?><p class="small text-danger text-center mb-0">It failed. The command output below says why; nothing was half-done. Fix the cause and ask again.</p><?php endif; ?>
  </div>
  <?php if ($r['warning']): ?><div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= e($r['warning']) ?></div><?php endif; ?>
  <?php if ($r['action'] === 'create' && $r['status'] === 'done' && $chairUser): ?>
    <div class="vk-card vk-secret mb-3">
      <h2><i class="bi bi-key"></i>First Chairperson login</h2>
      <dl class="row vk-dl mb-1">
        <dt class="col-sm-3">Address</dt><dd class="col-sm-9"><a href="https://<?= e($r['subdomain'] . '.' . admin_config()['base_domain']) ?>" target="_blank" rel="noopener"><?= e($r['subdomain'] . '.' . admin_config()['base_domain']) ?></a></dd>
        <dt class="col-sm-3">Username</dt><dd class="col-sm-9"><strong><?= e($chairUser) ?></strong></dd>
        <?php if ($revealed !== null): ?><dt class="col-sm-3">Password</dt><dd class="col-sm-9"><span class="pw"><?= e($revealed) ?></span></dd><?php endif; ?>
      </dl>
      <?php if ($revealed !== null): ?>
        <p class="small text-danger mb-0"><i class="bi bi-exclamation-circle me-1"></i>Write it down or send it to the Chairperson now. It will not be shown again. Ask them to change it under My Settings.</p>
      <?php elseif ((int) $r['has_secret']): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="reveal" value="1">
          <button class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Show the password once</button>
          <span class="small text-secondary ms-2">It is erased as soon as it is shown.</span></form>
      <?php else: ?>
        <p class="small text-secondary mb-0"><?= $revealMissed ? 'Someone has already viewed this password. ' : '' ?>The password was shown<?= $r['secret_viewed_at'] ? ' on ' . e(vk_admin_when(db_time($r['secret_viewed_at']), $now)) : '' ?> and has been erased.
          If it was lost, reset it from the server or ask the Chairperson to use the app's password reset.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="vk-card h-100">
        <h2><i class="bi bi-info-circle"></i>Request</h2>
        <dl class="row vk-dl mb-0">
          <dt class="col-sm-4">Asked by</dt><dd class="col-sm-8"><?= e($r['admin_name'] ?? '') ?></dd>
          <dt class="col-sm-4">Asked</dt><dd class="col-sm-8"><?= e(vk_admin_when(db_time($r['created_at']), $now)) ?></dd>
          <dt class="col-sm-4">Started</dt><dd class="col-sm-8"><?= e(vk_admin_when(db_time($r['started_at']), $now)) ?: '<span class="text-secondary">not yet</span>' ?></dd>
          <dt class="col-sm-4">Finished</dt><dd class="col-sm-8"><?= e(vk_admin_when(db_time($r['finished_at']), $now)) ?: '<span class="text-secondary">not yet</span>' ?></dd>
          <?php if ($r['exit_code'] !== null): ?><dt class="col-sm-4">Exit code</dt><dd class="col-sm-8"><?= (int) $r['exit_code'] ?></dd><?php endif; ?>
          <?php if ($r['subdomain']): ?><dt class="col-sm-4">Group</dt><dd class="col-sm-8"><a href="/groups/<?= e($r['subdomain']) ?>"><?= e($r['subdomain']) ?></a></dd><?php endif; ?>
        </dl>
      </div>
    </div>
    <?php if ($params): ?>
    <div class="col-lg-6">
      <div class="vk-card h-100">
        <h2><i class="bi bi-ui-checks"></i>Form details</h2>
        <dl class="row vk-dl mb-0">
          <?php if ($r['action'] === 'features'): ?>
          <?php foreach (vk_feature_catalog() as $k => $f): ?>
          <dt class="col-sm-5"><?= e($f['label']) ?></dt><dd class="col-sm-7"><?= !empty($params['features'][$k]) ? 'On' : 'Off' ?></dd>
          <?php endforeach; ?>
          <?php else: ?>
          <?php foreach (['name' => 'Group name', 'chair_first' => 'Chairperson first name', 'chair_last' => 'Chairperson last name', 'chair_phone' => 'Chairperson phone', 'chair_email' => 'Chairperson email'] as $k => $label): ?>
            <?php if (($params[$k] ?? '') !== ''): ?><dt class="col-sm-5"><?= e($label) ?></dt><dd class="col-sm-7"><?= e((string) $params[$k]) ?></dd><?php endif; ?>
          <?php endforeach; ?>
          <dt class="col-sm-5">Demo group</dt><dd class="col-sm-7"><?= !empty($params['demo']) ? 'Yes' : 'No' ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($r['output'] !== null && $r['output'] !== ''): ?>
    <div class="col-12">
      <details class="vk-card"<?= $r['status'] === 'failed' ? ' open' : '' ?>>
        <summary class="fw-semibold"><i class="bi bi-terminal me-1"></i>Command output</summary>
        <pre class="vk-output mt-3"><?= e($r['output']) ?></pre>
      </details>
    </div>
    <?php endif; ?>
  </div>
<?php });
