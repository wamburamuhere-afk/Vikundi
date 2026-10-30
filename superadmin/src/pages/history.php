<?php
/** History: every change to a group and every sign-in, from the panel or the server. */

$group = vk_admin_search($_GET['group'] ?? null);
$group = preg_match('/^[a-z0-9-]{3,30}$/', $group) ? $group : '';
$rows = data_history($group !== '' ? $group : null);
$now = time();

layout('History', 'history', static function () use ($rows, $group, $now) {
    page_head('History' . ($group !== '' ? ': ' . $group : ''), 'Every change to a group and every sign-in, from the panel or the server.',
              $group !== '' ? '<a class="btn btn-outline-secondary btn-sm" href="/history">Show all groups</a>' : ''); ?>
  <div class="vk-card flush">
    <?php if (!$rows): ?><div class="vk-empty"><i class="bi bi-clock-history"></i>Nothing recorded yet.</div><?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead><tr><th>When</th><th>Who</th><th>What</th><th>Group</th><th>Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $h): ?>
        <tr>
          <td class="small text-nowrap text-secondary"><?= e(vk_admin_when(db_time($h['created_at']), $now)) ?></td>
          <td class="small"><?= e($h['actor_name']) ?></td>
          <td class="small"><?= e($h['action']) ?><?php if ($h['request_id']): ?> <a href="/requests/<?= (int) $h['request_id'] ?>">#<?= (int) $h['request_id'] ?></a><?php endif; ?></td>
          <td class="small"><?= $h['subdomain'] ? '<a href="/groups/' . e($h['subdomain']) . '">' . e($h['subdomain']) . '</a>' : '' ?></td>
          <td><?= status_badge($h['result']) ?></td>
          <td class="small text-secondary"><?= e($h['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php });
