<?php
/** One group: its details, its recent requests and its history. */

$g = data_group((string) $param);
if (!$g) {
    not_found();
}
$backup = data_last_backup($g['subdomain']);
$disk = data_disk_use($g['subdomain']);
$requests = data_requests('', $g['subdomain'], 10);
$history = data_history($g['subdomain'], 10);
$now = time();
$base = (string) admin_config()['base_domain'];

layout($g['name'], 'groups', static function () use ($g, $backup, $disk, $requests, $history, $now, $base) { ?>
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
        <p class="text-secondary small mb-0">Suspend, resume, archive and certificate retry arrive with the next update of the panel.</p>
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
