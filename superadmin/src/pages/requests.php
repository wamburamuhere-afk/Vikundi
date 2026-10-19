<?php
/** Requests: everything the panel has asked for, newest first. */

$statuses = ['waiting', 'running', 'done', 'failed'];
$status = vk_admin_filter($_GET['status'] ?? null, $statuses);
$rows = data_requests($status);
$now = time();

layout('Requests', 'requests', static function () use ($rows, $statuses, $status, $now) { ?>
  <h1 class="h4 mb-3">Requests</h1>
  <form method="get" action="/requests" class="row g-2 mb-3">
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
      <thead><tr><th>#</th><th>Action</th><th>Group</th><th>Status</th><th>Asked</th><th>Finished</th><th>By</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-secondary p-4">No requests yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="/requests/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
          <td><?= e(req_label($r['action'])) ?></td>
          <td><?= $r['subdomain'] ? '<a href="/groups/' . e($r['subdomain']) . '">' . e($r['subdomain']) . '</a>' : '<span class="text-secondary">all groups</span>' ?></td>
          <td><?= status_badge($r['status']) ?><?php if ($r['warning']): ?> <i class="bi bi-exclamation-triangle text-warning" title="<?= e($r['warning']) ?>"></i><?php endif; ?></td>
          <td class="small"><?= e(vk_admin_when(db_time($r['created_at']), $now)) ?></td>
          <td class="small"><?= e(vk_admin_when(db_time($r['finished_at']), $now)) ?></td>
          <td class="small"><?= e($r['admin_name'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php });
