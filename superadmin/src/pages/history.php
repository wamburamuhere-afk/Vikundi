<?php
/** History: every change to a group and every sign-in, from the panel or the server. */

$group = vk_admin_search($_GET['group'] ?? null);
$group = preg_match('/^[a-z0-9-]{3,30}$/', $group) ? $group : '';
$rows = data_history($group !== '' ? $group : null);
$now = time();

layout('History', 'history', static function () use ($rows, $group, $now) { ?>
  <h1 class="h4 mb-3">History<?= $group !== '' ? ': ' . e($group) : '' ?></h1>
  <?php if ($group !== ''): ?><p class="small"><a href="/history">Show all groups</a></p><?php endif; ?>
  <div class="vk-card p-0">
    <table class="table mb-0">
      <thead><tr><th>When</th><th>Who</th><th>What</th><th>Group</th><th>Result</th><th>Details</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-secondary p-4">Nothing recorded yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $h): ?>
        <tr>
          <td class="small text-nowrap"><?= e(vk_admin_when(db_time($h['created_at']), $now)) ?></td>
          <td class="small"><?= e($h['actor_name']) ?></td>
          <td class="small"><?= e($h['action']) ?><?php if ($h['request_id']): ?> <a href="/requests/<?= (int) $h['request_id'] ?>">#<?= (int) $h['request_id'] ?></a><?php endif; ?></td>
          <td class="small"><?= $h['subdomain'] ? '<a href="/groups/' . e($h['subdomain']) . '">' . e($h['subdomain']) . '</a>' : '' ?></td>
          <td><?= status_badge($h['result']) ?></td>
          <td class="small text-secondary"><?= e($h['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php });
