<?php
/** Requests: everything the panel has asked for, newest first, with a tab per status. */

$statuses = ['waiting', 'running', 'done', 'failed'];
$status = vk_admin_filter($_GET['status'] ?? null, $statuses);
$rows = data_requests($status);
$counts = data_request_counts();
$now = time();
$pendingNow = $counts['waiting'] + $counts['running'];
if ($pendingNow > 0) {
    header('Refresh: 10'); // no JavaScript in the panel: the browser reloads while work is waiting
}

layout('Requests', 'requests', static function () use ($rows, $statuses, $status, $counts, $now, $pendingNow) {
    page_head('Requests', 'What the panel asked the server to do. The worker picks each one up within a minute.'); ?>
  <?php if ($pendingNow > 0): ?><div class="alert alert-warning"><i class="bi bi-hourglass-split me-1"></i><?= $pendingNow ?> request<?= $pendingNow === 1 ? ' is' : 's are' ?> waiting or running. This page refreshes by itself.</div><?php endif; ?>
  <div class="vk-tabs mb-3">
    <a class="<?= $status === '' ? 'on' : '' ?>" href="/requests">All (<?= $counts[''] ?>)</a>
    <?php foreach ($statuses as $s): ?><a class="<?= $s === $status ? 'on' : '' ?>" href="/requests?status=<?= $s ?>"><?= ucfirst($s) ?> (<?= $counts[$s] ?>)</a><?php endforeach; ?>
  </div>
  <div class="vk-card flush">
    <?php if (!$rows): ?><div class="vk-empty"><i class="bi bi-inbox"></i>No requests<?= $status !== '' ? ' ' . e($status) : ' yet' ?>.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead><tr><th>#</th><th>Action</th><th>Group</th><th>Status</th><th>Asked</th><th>Finished</th><th>By</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a class="fw-semibold" href="/requests/<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
          <td><?= e(req_label($r['action'])) ?></td>
          <td><?= $r['subdomain'] ? '<a href="/groups/' . e($r['subdomain']) . '">' . e($r['subdomain']) . '</a>' : '<span class="text-secondary">all groups</span>' ?></td>
          <td><?= status_badge($r['status']) ?><?php if ($r['warning']): ?> <i class="bi bi-exclamation-triangle-fill text-warning" title="<?= e($r['warning']) ?>"></i><?php endif; ?></td>
          <td class="small text-secondary text-nowrap"><?= e(vk_admin_when(db_time($r['created_at']), $now)) ?></td>
          <td class="small text-secondary text-nowrap"><?= e(vk_admin_when(db_time($r['finished_at']), $now)) ?></td>
          <td class="small"><?= e($r['admin_name'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php });
