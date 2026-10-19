<?php
/** Groups list: every group, its status, last backup and disk use. */

$statuses = ['provisioning', 'active', 'suspended', 'archived'];
$status = vk_admin_filter($_GET['status'] ?? null, $statuses);
$q = vk_admin_search($_GET['q'] ?? null);
$groups = data_groups($status, $q);
$now = time();

layout('Groups', 'groups', static function () use ($groups, $statuses, $status, $q, $now) { ?>
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0">Groups (<?= count($groups) ?>)</h1>
    <a class="btn btn-primary" href="/create"><i class="bi bi-plus-lg me-1"></i>Create group</a>
  </div>
  <form method="get" action="/groups" class="row g-2 mb-3">
    <div class="col-sm-6 col-lg-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name or address"></div>
    <div class="col-sm-4 col-lg-3">
      <select class="form-select" name="status">
        <option value="">Status: all</option>
        <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $s === $status ? ' selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
  </form>
  <div class="vk-card p-0">
    <table class="table table-hover mb-0">
      <thead><tr><th>Group</th><th>Status</th><th>Created</th><th>Last backup</th><th class="text-end">Disk</th></tr></thead>
      <tbody>
      <?php if (!$groups): ?>
        <tr><td colspan="5" class="text-secondary p-4">No groups match.</td></tr>
      <?php endif; ?>
      <?php foreach ($groups as $g):
          $backup = data_last_backup($g['subdomain']);
          $disk = data_disk_use($g['subdomain']); ?>
        <tr>
          <td><a class="fw-semibold text-decoration-none" href="/groups/<?= e($g['subdomain']) ?>"><?= e($g['name']) ?></a>
              <?php if ((int) $g['is_demo']): ?><span class="badge text-bg-light border ms-1">demo</span><?php endif; ?>
              <div class="small text-secondary"><?= e($g['subdomain']) ?>.<?= e(admin_config()['base_domain']) ?></div></td>
          <td><?= status_badge($g['status']) ?></td>
          <td class="small"><?= e(date('j M Y', db_time($g['created_at']))) ?></td>
          <td class="small"><?= $backup ? e(vk_admin_when($backup[1], $now)) : '<span class="text-danger">none</span>' ?></td>
          <td class="small text-end"><?= $disk === null ? '' : e(vk_admin_bytes($disk)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php });
