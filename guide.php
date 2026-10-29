<?php
/**
 * guide.php — the group's welcome guide, at /guide (English or Kiswahili: ?lang=en|sw).
 *
 * Open to anyone with the address, like the sign-in page, so it can be sent to a
 * new group before they first sign in. It shows only the group's name, logo and
 * address, and the instructions. The group's own figures (contribution, Social
 * Fund, deadlines, loan types) appear only for someone signed in.
 *
 * "Download PDF" is the browser's Print → Save as PDF; the print styles keep the colours.
 * Content: includes/group_guide.php.
 */

require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/includes/group_guide.php';
require_once __DIR__ . '/includes/late_rules.php'; // late_settings(), cr_monthly_rule()

$sw = current_lang() === 'sw';
$gs = $pdo->query('SELECT setting_key, setting_value FROM group_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$groupName = (string) ($gs['group_name'] ?? 'Vikundi');
$logo = basename(trim((string) ($gs['group_logo'] ?? ''))) ?: 'logo1.png';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = preg_replace('/[^A-Za-z0-9.:-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
$tenant = vk_tenant_current();
$signedIn = !empty($_SESSION['user_id']);

$figures = null;
if ($signedIn) {
    $figures = [
        'rule'         => cr_monthly_rule($gs),
        'social'       => (float) ($gs['social_fund_amount'] ?? 0),
        'entrance'     => (float) ($gs['entrance_fee'] ?? 0),
        'share_price'  => (float) ($gs['share_price'] ?? 0),
        'deadline_day' => (int) ($gs['deadline_day'] ?? 0),
        'late'         => late_settings($gs),
        'loan_types'   => [],
    ];
    try {
        $figures['loan_types'] = $pdo->query('SELECT * FROM vicoba_loan_types WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // A database without loan types yet: the guide still shows.
    }
}

$sections = vk_guide_sections([
    'name'      => $groupName,
    'address'   => $scheme . '://' . $host,
    'demo'      => is_array($tenant) && (int) ($tenant['is_demo'] ?? 0) === 1,
    'shares'    => vk_feature('shares'),
    'dividends' => vk_feature('dividends'),
    'figures'   => $figures,
], $sw);
$parts = vk_guide_parts($sw);
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$kinds = ['tip' => $sw ? 'Kidokezo' : 'Tip', 'note' => $sw ? 'Kumbuka' : 'Note', 'important' => $sw ? 'Muhimu' : 'Important'];
$title = $sw ? 'Mwongozo wa Kikundi' : 'Group Guide';
?>
<!DOCTYPE html>
<html lang="<?= $sw ? 'sw' : 'en' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?= $h($groupName . ' — ' . $title) ?></title>
<style>
  :root { --brand:#0f4c81; --brand2:#1769aa; --tint:#eaf2fb; --ink:#1f2d3d; --muted:#5b6b7c; --line:#d9e3ee;
          --tip:#e8f6ee; --tipb:#2e8b57; --note:#eaf2fb; --noteb:#1769aa; --imp:#fff4e5; --impb:#d9822b; }
  * { box-sizing:border-box; }
  body { margin:0; background:#f4f7fb; color:var(--ink); font:15px/1.6 "Segoe UI", Roboto, Arial, sans-serif;
         -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .bar { position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid var(--line); }
  .bar .in { max-width:920px; margin:0 auto; padding:10px 16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .bar .sp { flex:1; }
  .bar a, .bar button { font:inherit; font-size:14px; text-decoration:none; border-radius:8px; padding:6px 12px; border:1px solid var(--line); background:#fff; color:var(--brand); cursor:pointer; }
  .bar .on { background:var(--brand); color:#fff; border-color:var(--brand); }
  .bar .pdf { background:#d9822b; border-color:#d9822b; color:#fff; font-weight:600; }
  main { max-width:920px; margin:0 auto; padding:20px 16px 48px; }
  .cover { background:linear-gradient(135deg,var(--brand),var(--brand2)); color:#fff; border-radius:16px; padding:28px; display:flex; gap:20px; align-items:center; }
  .cover img { width:84px; height:84px; object-fit:contain; background:#fff; border-radius:50%; padding:8px; flex:none; }
  .cover h1 { margin:0; font-size:26px; line-height:1.25; }
  .cover .sub { opacity:.9; margin-top:4px; }
  .cover .addr { display:inline-block; margin-top:10px; background:rgba(255,255,255,.16); padding:4px 10px; border-radius:6px; font-weight:600; word-break:break-all; }
  .toc { background:#fff; border:1px solid var(--line); border-radius:12px; padding:16px 20px; margin:20px 0; columns:2; column-gap:28px; }
  .toc h2 { column-span:all; margin:0 0 8px; font-size:16px; color:var(--brand); }
  .toc .grp { break-inside:avoid; margin-bottom:8px; }
  .toc .grp div { font-size:14px; padding:2px 0; }
  .toc .p { font-weight:700; color:var(--brand); }
  .toc a { color:var(--ink); text-decoration:none; }
  .part { background:var(--brand); color:#fff; border-radius:10px; padding:10px 16px; margin:28px 0 12px; font-weight:700; letter-spacing:.3px; }
  section { background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px 20px; margin:12px 0; break-inside:avoid; }
  section h2 { margin:0 0 6px; font-size:19px; color:var(--brand); border-bottom:2px solid var(--tint); padding-bottom:6px; }
  section h2 .n { display:inline-block; min-width:30px; height:30px; line-height:30px; text-align:center; border-radius:8px; background:var(--tint); color:var(--brand); margin-right:8px; font-size:15px; }
  .who { display:inline-block; font-size:13px; background:#f1f5f9; color:var(--muted); border-radius:20px; padding:2px 10px; margin:4px 0 6px; }
  ol { padding-left:22px; margin:8px 0; } ol li { margin:4px 0; }
  table { width:100%; border-collapse:collapse; margin:10px 0; font-size:14px; }
  th { background:var(--tint); color:var(--brand); text-align:left; padding:8px 10px; }
  td { border-top:1px solid var(--line); padding:8px 10px; vertical-align:top; }
  td:first-child { font-weight:600; width:34%; }
  .call { border-left:4px solid; border-radius:8px; padding:8px 12px; margin:8px 0; font-size:14px; }
  .call b { margin-right:4px; }
  .tip { background:var(--tip); border-color:var(--tipb); } .note { background:var(--note); border-color:var(--noteb); } .important { background:var(--imp); border-color:var(--impb); }
  footer { text-align:center; color:var(--muted); font-size:13px; margin-top:28px; }
  @media (max-width:640px) { .cover { flex-direction:column; text-align:center; } .toc { columns:1; } td:first-child { width:auto; } }
  @media print {
    @page { size:A4; margin:14mm; }
    body { background:#fff; font-size:12.5px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .bar { display:none; } main { padding:0; max-width:none; }
    section, .toc { border-color:#c9d6e3; }
    .part { break-after:avoid; }
    .call, table, .toc .grp div, .who { font-size:11.5px; }
  }
</style>
</head>
<body>
<div class="bar"><div class="in">
  <a href="?lang=en" class="<?= $sw ? '' : 'on' ?>">English</a>
  <a href="?lang=sw" class="<?= $sw ? 'on' : '' ?>">Kiswahili</a>
  <span class="sp"></span>
  <button type="button" class="pdf" onclick="window.print()"><?= $sw ? 'Pakua PDF' : 'Download PDF' ?></button>
  <a href="<?= $h($signedIn ? getUrl('dashboard') : getUrl('login')) ?>"><?= $signedIn ? ($sw ? 'Dashibodi' : 'Dashboard') : ($sw ? 'Ingia' : 'Sign in') ?></a>
</div></div>
<main>
  <div class="cover">
    <img src="assets/images/<?= $h($logo) ?>" alt="" onerror="this.style.display='none'">
    <div>
      <h1><?= $h($groupName) ?></h1>
      <div class="sub"><?= $h($title) ?> · <?= $sw ? 'Jinsi ya kuendesha kikundi chenu kwenye Vikundi, hatua kwa hatua.' : 'How to run your group on Vikundi, step by step.' ?></div>
      <span class="addr"><?= $h($scheme . '://' . $host) ?></span>
    </div>
  </div>

  <nav class="toc">
    <h2><?= $sw ? 'Yaliyomo' : 'Contents' ?></h2>
    <?php $n = 0; foreach ($parts as $partKey => $partTitle): $inPart = array_filter($sections, static fn($x) => $x['part'] === $partKey); if (!$inPart) { continue; } ?>
      <div class="grp"><div class="p"><?= $h($partTitle) ?></div>
      <?php foreach ($inPart as $sec): $n++; ?><div><a href="#<?= $h($sec['id']) ?>"><?= $n ?>. <?= $h($sec['title']) ?></a></div><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <?php $n = 0; $lastPart = null; foreach ($sections as $sec): $n++; ?>
    <?php if ($sec['part'] !== $lastPart): $lastPart = $sec['part']; ?><div class="part"><?= $h($parts[$sec['part']]) ?></div><?php endif; ?>
    <section id="<?= $h($sec['id']) ?>">
      <h2><span class="n"><?= $n ?></span><?= $h($sec['title']) ?></h2>
      <?php if (!empty($sec['who'])): ?><div class="who"><?= $sw ? 'Anayefanya hili' : 'Who does this' ?>: <?= $h($sec['who']) ?></div><?php endif; ?>
      <?php if (!empty($sec['intro'])): ?><p><?= $h($sec['intro']) ?></p><?php endif; ?>
      <?php if ($sec['steps']): ?><ol><?php foreach ($sec['steps'] as $step): ?><li><?= $h($step) ?></li><?php endforeach; ?></ol><?php endif; ?>
      <?php if (!empty($sec['table']['rows'])): ?>
        <table>
          <tr><th><?= $h($sec['table']['head'][0]) ?></th><th><?= $h($sec['table']['head'][1]) ?></th></tr>
          <?php foreach ($sec['table']['rows'] as [$a, $b]): ?><tr><td><?= $h($a) ?></td><td><?= $h($b) ?></td></tr><?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php foreach ($sec['notes'] as [$kind, $text]): ?>
        <div class="call <?= $h($kind) ?>"><b><?= $h($kinds[$kind] ?? '') ?>.</b><?= $h($text) ?></div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <footer>BJP Technologies (T) Ltd · <?= $h(VK_GUIDE_SUPPORT_PHONE) ?> · <?= $h(VK_GUIDE_SUPPORT_EMAIL) ?></footer>
</main>
</body>
</html>
