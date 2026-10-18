<?php
/** One request: what was asked, how it went, and the command's output. */

$r = data_request((int) $param);
if (!$r) {
    not_found();
}
$now = time();
$params = $r['params'] ? (json_decode((string) $r['params'], true) ?: []) : [];
$pending = in_array($r['status'], ['waiting', 'running'], true);
if ($pending) {
    header('Refresh: 5'); // the panel runs no JavaScript; the browser reloads the page
}

layout('Request #' . (int) $r['id'], 'requests', static function () use ($r, $params, $pending, $now) { ?>
  <nav class="small mb-2"><a href="/requests">Requests</a> / #<?= (int) $r['id'] ?></nav>
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">#<?= (int) $r['id'] ?>: <?= e(req_label($r['action'])) ?><?= $r['subdomain'] ? ' ' . e($r['subdomain']) : '' ?></h1>
    <?= status_badge($r['status']) ?>
  </div>
  <?php if ($pending): ?><div class="alert alert-warning py-2">The worker on the server picks requests up within a minute. This page refreshes by itself.</div><?php endif; ?>
  <?php if ($r['warning']): ?><div class="alert alert-warning py-2"><?= e($r['warning']) ?></div><?php endif; ?>
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="vk-card h-100">
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
        <h2 class="h6 mb-3">Form details</h2>
        <dl class="row vk-dl mb-0">
          <?php foreach (['name' => 'Group name', 'chair_first' => 'Chairperson first name', 'chair_last' => 'Chairperson last name', 'chair_phone' => 'Chairperson phone', 'chair_email' => 'Chairperson email'] as $k => $label): ?>
            <?php if (($params[$k] ?? '') !== ''): ?><dt class="col-sm-5"><?= e($label) ?></dt><dd class="col-sm-7"><?= e((string) $params[$k]) ?></dd><?php endif; ?>
          <?php endforeach; ?>
          <dt class="col-sm-5">Demo group</dt><dd class="col-sm-7"><?= !empty($params['demo']) ? 'Yes' : 'No' ?></dd>
        </dl>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($r['output'] !== null && $r['output'] !== ''): ?>
    <div class="col-12">
      <h2 class="h6">Command output</h2>
      <pre class="vk-output"><?= e($r['output']) ?></pre>
    </div>
    <?php endif; ?>
  </div>
<?php });
