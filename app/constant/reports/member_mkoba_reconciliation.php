<?php
/**
 * My M-Koba Reconciliation — one member checking that every payment they made
 * through M-Koba reached their Vikundi account, and for the right amount.
 *
 * The group-wide page (accounts/mkoba_reconciliation.php) ties a whole imported
 * statement out against the books, which is a leader's job. This is the same
 * question asked from one seat: "is my money all here?"
 *
 * Every row is matched through `mkoba_statement_rows.contribution_id`, so what is
 * shown is exactly what the import wrote into this member's savings. Rows the
 * import excluded are never a member's payment — they are group transfers, account
 * openings and balance lines — so nothing of a member's can be hidden by that
 * filter. The page says so rather than leaving the reader to wonder.
 */
ob_start();
date_default_timezone_set('Africa/Nairobi');
require_once HEADER_FILE;

$member_id = intval($_GET['id'] ?? 0);

// Same gate as the two member statements, for the same reason: leadership may open
// any member with ?id, everyone else is forced back to their own record.
$own_stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
$own_stmt->execute([$_SESSION['user_id']]);
$own_cid = (int) ($own_stmt->fetchColumn() ?: 0);

$is_leader = isAdmin() || canCreate('manage_contributions');
if (!$is_leader || !$member_id) {
    $member_id = $own_cid;
}

if (!$member_id) {
    echo '<div class="alert alert-danger m-4">' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama hajapatikana.' : 'Member not found.') . '</div>';
    $content = ob_get_clean(); echo $content; require_once FOOTER_FILE; exit();
}

require_once __DIR__ . '/../../../includes/statement_layout.php';

$member = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$member->execute([$member_id]);
$member = $member->fetch(PDO::FETCH_ASSOC);

$isSw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Every imported M-Koba row that became one of this member's contributions, with
// BOTH amounts so the two can be compared rather than assumed equal.
$q = $pdo->prepare("
    SELECT m.trans_date, m.trans_id, m.receipt, m.trans_type, m.batch,
           m.amount        AS mkoba_amount,
           c.contribution_id,
           c.amount        AS book_amount,
           c.contribution_date,
           c.status        AS book_status
      FROM mkoba_statement_rows m
      JOIN contributions c ON c.contribution_id = m.contribution_id
     WHERE c.member_id = ?
     ORDER BY m.trans_date ASC, m.id ASC");
$q->execute([$member_id]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

$mkoba_total = 0.0;
$book_total  = 0.0;
$mismatches  = 0;
$pending     = 0;
foreach ($rows as $r) {
    $mkoba_total += (float) $r['mkoba_amount'];
    $book_total  += (float) $r['book_amount'];
    if ((float) $r['mkoba_amount'] !== (float) $r['book_amount']) {
        $mismatches++;
    }
    if (!in_array($r['book_status'], ['approved', 'confirmed', ''], true)) {
        $pending++;
    }
}
$difference = $mkoba_total - $book_total;

$group       = stmt_group($pdo);
$member_name = trim(implode(' ', array_filter([
    $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
])));
$money = fn(float $n): string => 'TSh ' . number_format($n, 0);
// stmt_head() joins these two with a space, so the title carries the connecting
// word — "M-KOBA RECONCILIATION ABDALLAH ALLY" ran together without it.
$title = $isSw ? 'Ulinganishaji wa M-Koba kwa' : 'M-Koba Reconciliation for';
?>
<?php stmt_css(); ?>
<style>
.vk-rec-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0}
.vk-rec-tile{border:1px solid #cfd6dd;border-radius:9px;padding:14px 12px;text-align:center;background:#f6f8fa}
.vk-rec-tile b{display:block;font-size:20px;color:#0f4c81;font-weight:800}
.vk-rec-tile span{display:block;margin-top:4px;font-size:10.5px;color:#5b6670;text-transform:uppercase;letter-spacing:.04em}
.vk-rec-tile.ok{background:#eaf6ee;border-color:#bfe3cc}.vk-rec-tile.ok b{color:#1a7f4b}
.vk-rec-tile.bad{background:#fdecea;border-color:#f5c6c0}.vk-rec-tile.bad b{color:#b02a37}
@media(max-width:700px){.vk-rec-tiles{grid-template-columns:repeat(2,1fr)}}
@media print{.vk-rec-tiles{grid-template-columns:repeat(4,1fr)}
  .vk-rec-tile,.vk-rec-tile.ok,.vk-rec-tile.bad{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}
</style>

<div class="no-print mb-3">
    <div class="row align-items-center g-3">
        <div class="col-md">
            <h3 class="fw-bold text-primary mb-0"><i class="bi bi-clipboard-check me-2"></i>
                <?= $isSw ? 'Ulinganishaji wa M-Koba Wangu' : 'My M-Koba Reconciliation' ?></h3>
            <p class="text-muted small mb-0"><?= htmlspecialchars($member_name) ?> | #<?= (int) $member['customer_id'] ?></p>
        </div>
        <div class="col-md-auto d-flex gap-2">
            <a href="<?= getUrl('member_statement') ?><?= $is_leader && !empty($_GET['id']) ? '?id=' . (int) $member_id : '' ?>"
               class="btn btn-outline-primary rounded-pill px-4 shadow-sm fw-bold">
                <i class="bi bi-cash-stack me-2"></i> <?= $isSw ? 'Michango' : 'Contributions' ?>
            </a>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm fw-bold" onclick="window.print()">
                <i class="bi bi-printer me-2"></i> <?= $isSw ? 'Chapisha' : 'Print' ?>
            </button>
        </div>
    </div>
</div>

<div class="vk-stmt-sheet shadow-sm">

    <?php // stmt_head() escapes what it is given; pre-escaping here would double it. ?>
    <?php stmt_head($group, $title, $member_name); ?>

    <div class="vk-rec-tiles">
        <div class="vk-rec-tile"><b><?= count($rows) ?></b><span><?= $isSw ? 'Miamala' : 'Transactions' ?></span></div>
        <div class="vk-rec-tile"><b><?= $money($mkoba_total) ?></b><span><?= $isSw ? 'Kutoka M-Koba' : 'From M-Koba' ?></span></div>
        <div class="vk-rec-tile"><b><?= $money($book_total) ?></b><span><?= $isSw ? 'Kwenye Vikundi' : 'Recorded in Vikundi' ?></span></div>
        <div class="vk-rec-tile <?= abs($difference) < 0.01 ? 'ok' : 'bad' ?>">
            <b><?= $money($difference) ?></b><span><?= $isSw ? 'Tofauti' : 'Difference' ?></span>
        </div>
    </div>

    <?php if (abs($difference) < 0.01 && $mismatches === 0): ?>
        <p style="font-size:9.5pt;color:#1a7f4b;font-weight:700;margin:0 0 10px;">
            <?= $isSw
                ? 'Kila muamala wa M-Koba umeingia kwenye akaunti yako kwa kiasi kilekile.'
                : 'Every M-Koba transaction reached your account for the same amount.' ?>
        </p>
    <?php elseif ($mismatches > 0): ?>
        <p style="font-size:9.5pt;color:#b02a37;font-weight:700;margin:0 0 10px;">
            <?= $isSw
                ? $mismatches . ' muamala hauendani na kiasi kilichoandikwa. Wasiliana na Mweka Hazina.'
                : $mismatches . ' transaction(s) do not match the amount recorded. Please raise this with the Treasurer.' ?>
        </p>
    <?php endif; ?>

    <?php if ($pending > 0): ?>
        <p style="font-size:9pt;color:#8a6d3b;margin:0 0 10px;">
            <?= $isSw
                ? $pending . ' muamala bado unasubiri idhini, kwa hiyo hauhesabiki kwenye michango yako bado.'
                : $pending . ' transaction(s) are still awaiting approval, so they do not yet count towards your contributions.' ?>
        </p>
    <?php endif; ?>

    <table class="vk-stmt-grid">
        <thead>
            <tr>
                <th class="vk-stmt-bar"><?= $isSw ? 'TAREHE' : 'DATE' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'KUMBUKUMBU' : 'TRANSACTION ID' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'RISITI' : 'RECEIPT' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'KIASI (M-KOBA)' : 'AMOUNT (M-KOBA)' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'KIASI (VIKUNDI)' : 'AMOUNT (VIKUNDI)' ?></th>
                <th class="vk-stmt-bar"><?= $isSw ? 'HALI' : 'STATUS' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="vk-stmt-empty">
                    <?= $isSw
                        ? 'Hakuna muamala wa M-Koba uliohusishwa na akaunti yako.'
                        : 'No M-Koba transactions are linked to your account.' ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $matched = ((float) $r['mkoba_amount']) === ((float) $r['book_amount']);
                $ok      = in_array($r['book_status'], ['approved', 'confirmed', ''], true);
            ?>
            <tr>
                <td><?= date('d M Y', strtotime($r['trans_date'])) ?></td>
                <td class="small"><?= htmlspecialchars((string) $r['trans_id']) ?></td>
                <td class="small"><?= htmlspecialchars((string) $r['receipt']) ?></td>
                <td class="vk-stmt-num"><?= number_format((float) $r['mkoba_amount'], 0) ?></td>
                <td class="vk-stmt-num"><?= number_format((float) $r['book_amount'], 0) ?></td>
                <td class="<?= !$matched ? 'vk-c-unpaid' : ($ok ? 'vk-c-paid' : 'vk-c-partial') ?>">
                    <?php if (!$matched): ?>
                        <?= $isSw ? 'Kiasi hakilingani' : 'Amount differs' ?>
                    <?php elseif ($ok): ?>
                        <?= $isSw ? 'Imeingia' : 'Recorded' ?>
                    <?php else: ?>
                        <?= $isSw ? 'Inasubiri idhini' : 'Awaiting approval' ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="text-muted" style="font-size:8.5pt;margin:12px 0 0;">
        <?= $isSw
            ? 'Safu za taarifa ya M-Koba ambazo si malipo ya mwanachama — uhamisho wa kikundi, ufunguzi wa akaunti na safu za salio — haziko hapa kwa sababu si za mtu binafsi. Zinaonekana kwenye ukurasa wa ulinganishaji wa kikundi.'
            : 'M-Koba statement lines that are not a member payment — group transfers, account openings and balance lines — are not listed here because they do not belong to any one member. They appear on the group reconciliation page.' ?>
    </p>

</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>

<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
