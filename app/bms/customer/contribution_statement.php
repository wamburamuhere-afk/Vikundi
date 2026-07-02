<?php
// app/bms/customer/contribution_statement.php — printable date-range statement
// of contributions (group-wide, optional member/status filter).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/contribution_statement.php';
includeHeader();

requireViewPermission('manage_contributions');

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$f = vk_statement_filters($_GET);
$params = [];
$where = vk_statement_where($f, $params);

$stmt = $pdo->prepare("
    SELECT con.contribution_date, con.receipt_number, con.account, con.contribution_type, con.amount, con.status,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name, c.phone
      FROM contributions con
      LEFT JOIN customers c ON con.member_id = c.customer_id
     WHERE $where
     ORDER BY con.contribution_date DESC, con.contribution_id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = array_sum(array_map(fn($r) => (float) $r['amount'], $rows));

$member_name = '';
if ($f['member_id'] > 0) {
    $mn = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM customers WHERE customer_id = ?");
    $mn->execute([$f['member_id']]);
    $member_name = (string) $mn->fetchColumn();
}
$range = trim(($f['from'] ?: '…') . ' → ' . ($f['to'] ?: '…'));
$sb = ['pending' => 'warning', 'reviewed' => 'info', 'approved' => 'success', 'cancelled' => 'secondary'];
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'TAARIFA YA MIAMALA' : 'CONTRIBUTIONS STATEMENT'); ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('manage_contributions') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="mb-3">
                <h5 class="fw-bold text-primary mb-1"><?= $t('Contributions Statement', 'Taarifa ya Michango') ?></h5>
                <div class="small text-muted">
                    <b><?= $t('Period', 'Kipindi') ?>:</b> <?= htmlspecialchars($range) ?>
                    &nbsp;·&nbsp; <b><?= $t('Scope', 'Wigo') ?>:</b> <?= $member_name !== '' ? htmlspecialchars($member_name) : $t('All members (group-wide)', 'Wanachama wote') ?>
                    <?php if ($f['status'] !== ''): ?>&nbsp;·&nbsp; <b><?= $t('Status', 'Hali') ?>:</b> <?= ucfirst($f['status']) ?><?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th><th><?= $t('Date', 'Tarehe') ?></th><th><?= $t('Member', 'Mwanachama') ?></th>
                            <th><?= $t('Receipt', 'Risiti') ?></th><th><?= $t('Account', 'Akaunti') ?></th>
                            <th><?= $t('Type', 'Aina') ?></th><th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
                            <th class="text-center"><?= $t('Status', 'Hali') ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (!$rows): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4"><?= $t('No transactions in this period.', 'Hakuna miamala kwa kipindi hiki.') ?></td></tr>
                        <?php else: $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= safe_output($r['contribution_date'], '—') ?></td>
                                <td><?= htmlspecialchars($r['member_name'] ?: '—') ?><?php if (!empty($r['phone'])): ?><div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($r['phone']) ?></div><?php endif; ?></td>
                                <td><?= safe_output($r['receipt_number'], '—') ?></td>
                                <td><?= safe_output($r['account'], '—') ?></td>
                                <td><?= safe_output(ucfirst($r['contribution_type']), '—') ?></td>
                                <td class="text-end fw-semibold"><?= number_format((float) $r['amount'], 0) ?></td>
                                <td class="text-center"><span class="badge bg-<?= $sb[$r['status']] ?? 'secondary' ?>"><?= safe_output($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="6" class="text-end"><?= $t('Total', 'Jumla') ?></td>
                            <td class="text-end text-primary"><?= number_format($total, 0) ?> TZS</td>
                            <td class="text-center"><?= count($rows) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
