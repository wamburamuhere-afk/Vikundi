<?php
/** Groups: summary tiles, then a card per group with its status, backup and links. */

$statuses = ['provisioning', 'active', 'suspended', 'archived'];
$status = vk_admin_filter($_GET['status'] ?? null, $statuses);
$q = vk_admin_search($_GET['q'] ?? null);
$all = data_groups();
$groups = ($status === '' && $q === '') ? $all : data_groups($status, $q);
$counts = vk_admin_group_counts($all);
// Working groups first, archived last; then by name.
$order = ['active' => 0, 'suspended' => 1, 'provisioning' => 2, 'archived' => 3];
usort($groups, static fn($a, $b) => [$order[$a['status']] ?? 9, strtolower($a['name'])] <=> [$order[$b['status']] ?? 9, strtolower($b['name'])]);
$base = (string) admin_config()['base_domain'];
$now = time();

layout('Groups', 'groups', static function () use ($groups, $statuses, $status, $q, $counts, $base, $now) {
    page_head('Groups', 'Every group on the shared server.', '<a class="btn btn-primary" href="/create"><i class="bi bi-plus-lg me-1"></i>Create group</a>');
    $tiles = [
        ['', 'Groups', $counts['total'], 'bi-grid-1x2', ''],
        ['active', 'Active', $counts['active'], 'bi-check-circle', 'green'],
        ['suspended', 'Suspended', $counts['suspended'], 'bi-pause-circle', 'amber'],
        ['archived', 'Archived', $counts['archived'], 'bi-archive', 'grey'],
    ]; ?>
  <div class="vk-stats">
    <?php foreach ($tiles as [$key, $label, $n, $icon, $tone]): ?>
      <a class="vk-stat <?= $tone ?>" href="/groups<?= $key !== '' ? '?status=' . $key : '' ?>"><span class="ic"><i class="bi <?= $icon ?>"></i></span>
        <span><span class="num d-block"><?= (int) $n ?></span><span class="lbl d-block"><?= e($label) ?></span></span></a>
    <?php endforeach; ?>
    <div class="vk-stat"><span class="ic"><i class="bi bi-stars"></i></span>
      <span><span class="num d-block"><?= (int) $counts['demo'] ?></span><span class="lbl d-block">Demo groups</span></span></div>
  </div>

  <form method="get" action="/groups" class="vk-filter">
    <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search by name or address">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    <div class="vk-tabs ms-lg-2">
      <a class="<?= $status === '' ? 'on' : '' ?>" href="/groups<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>">All</a>
      <?php foreach ($statuses as $s): ?>
        <a class="<?= $s === $status ? 'on' : '' ?>" href="/groups?status=<?= $s ?><?= $q !== '' ? '&amp;q=' . urlencode($q) : '' ?>"><?= ucfirst($s) ?></a>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if (!$groups): ?>
    <div class="vk-card vk-empty"><i class="bi bi-search"></i>No groups match.<?php if ($status !== '' || $q !== ''): ?> <a href="/groups">Show all</a><?php endif; ?></div>
  <?php else: ?>
  <div class="vk-groups">
    <?php foreach ($groups as $g):
        $backup = data_last_backup($g['subdomain']);
        $bk = vk_admin_backup_state($backup[1] ?? null, $now);
        $disk = data_disk_use($g['subdomain']);
        $features = vk_features_parse($g['features'] ?? null);
        $url = 'https://' . $g['subdomain'] . '.' . $base; ?>
      <div class="vk-gcard st-<?= e($g['status']) ?>">
        <div class="top">
          <div class="d-flex justify-content-between gap-2 align-items-start">
            <a class="name" href="/groups/<?= e($g['subdomain']) ?>"><?= e($g['name']) ?></a>
            <?= status_badge($g['status']) ?>
          </div>
          <div class="addr"><?= e($g['subdomain'] . '.' . $base) ?></div>
          <div class="d-flex gap-1 flex-wrap mt-2">
            <?php if ((int) $g['is_demo']): ?><span class="vk-chip demo">Demo</span><?php endif; ?>
            <?php foreach (vk_feature_catalog() as $k => $f): if (!$features[$k]): ?><span class="vk-chip off"><?= e($f['label']) ?></span><?php endif; endforeach; ?>
          </div>
        </div>
        <div class="meta">
          <div><span>Last backup</span><b class="vk-bk <?= $bk ?>"><?= $backup ? e(vk_admin_when($backup[1], $now)) : 'None' ?></b></div>
          <div><span>Disk used</span><?= $disk === null ? '—' : e(vk_admin_bytes($disk)) ?></div>
          <div><span>Created</span><?= e(date('j M Y', db_time($g['created_at']))) ?></div>
          <div><span>Database</span><code><?= e($g['db_name']) ?></code></div>
        </div>
        <div class="links">
          <a class="btn btn-sm btn-primary" href="/groups/<?= e($g['subdomain']) ?>">Manage</a>
          <?php if ($g['status'] === 'active'): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e($url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open site</a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= e($url) ?>/guide" target="_blank" rel="noopener"><i class="bi bi-book me-1"></i>Guide</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php });
