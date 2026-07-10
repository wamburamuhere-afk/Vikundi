<?php
// app/bms/customer/fines_print.php — printable fines register (leadership),
// honouring the Member + Status filters from the manage_fines page. The list is
// a server-side DataTable, so this dedicated page renders the full filtered set.
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('manage_fines');
require_once __DIR__ . '/../../../includes/fine_helpers.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$status    = in_array($_GET['status'] ?? '', vk_fine_statuses(), true) ? $_GET['status'] : '';
$member_id = ctype_digit((string) ($_GET['member_id'] ?? '')) ? (int) $_GET['member_id'] : 0;

$where = 'WHERE 1=1';
$params = [];
if ($status !== '')  { $where .= ' AND f.status = :st';       $params['st']  = $status; }
if ($member_id > 0)  { $where .= ' AND f.customer_id = :mid'; $params['mid'] = $member_id; }

$stmt = $pdo->prepare("
    SELECT f.fine_id, f.amount, f.status, f.created_at, f.reason,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
           m.title AS meeting_title
      FROM fines f
      LEFT JOIN customers c ON f.customer_id = c.customer_id
      LEFT JOIN meetings  m ON f.meeting_id  = m.id
      $where
     ORDER BY f.created_at DESC, f.fine_id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals within the printed (filtered) set.
$tot = ['pending' => 0.0, 'paid' => 0.0, 'waived' => 0.0];
$grand = 0.0;
foreach ($rows as $r) {
    if (isset($tot[$r['status']])) $tot[$r['status']] += (float) $r['amount'];
    $grand += (float) $r['amount'];
}

$member_name = '';
if ($member_id > 0) {
    $mn = $pdo->prepare("SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) FROM customers WHERE customer_id = ?");
    $mn->execute([$member_id]);
    $member_name = (string) $mn->fetchColumn();
}
$sb = ['pending' => 'warning', 'paid' => 'success', 'waived' => 'secondary'];

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'REJISTA YA FAINI' : 'FINES REGISTER'); ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('manage_fines') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="mb-3">
            <h5 class="fw-bold text-danger mb-1"><?= $t('Fines Register', 'Rejista ya Faini') ?></h5>
            <div class="small text-muted">
                <b><?= $t('Scope', 'Wigo') ?>:</b> <?= $member_name !== '' ? htmlspecialchars($member_name) : $t('All members', 'Wanachama wote') ?>
                <?php if ($status !== ''): ?>&nbsp;·&nbsp; <b><?= $t('Status', 'Hali') ?>:</b> <?= ucfirst($status) ?><?php endif; ?>
                &nbsp;·&nbsp; <b><?= $t('Records', 'Idadi') ?>:</b> <?= count($rows) ?>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px">#</th>
                        <th><?= $t('Member', 'Mwanachama') ?></th>
                        <th><?= $t('Reason', 'Sababu') ?></th>
                        <th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
                        <th class="text-nowrap"><?= $t('Date', 'Tarehe') ?></th>
                        <th class="text-center"><?= $t('Status', 'Hali') ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4"><?= $t('No fines found.', 'Hakuna faini.') ?></td></tr>
                    <?php else: $i = 1; foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($r['member_name'] ?: '—') ?></td>
                            <td><?= safe_output($r['reason'] ?: ($r['meeting_title'] ? ($t('Meeting absence', 'Kukosa mkutano') . ': ' . $r['meeting_title']) : '—'), '—') ?></td>
                            <td class="text-end fw-semibold"><?= number_format((float) $r['amount'], 0) ?></td>
                            <td class="text-nowrap"><?= $r['created_at'] ? date('d M Y', strtotime($r['created_at'])) : '—' ?></td>
                            <td class="text-center"><span class="badge bg-<?= $sb[$r['status']] ?? 'secondary' ?>"><?= safe_output($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="3" class="text-end"><?= $t('Total', 'Jumla') ?></td>
                        <td class="text-end text-danger"><?= number_format($grand, 0) ?> TZS</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="small mt-2 text-muted">
            <b><?= $t('Pending', 'Zinasubiri') ?>:</b> <?= number_format($tot['pending'], 0) ?> &nbsp;·&nbsp;
            <b><?= $t('Paid', 'Zilizolipwa') ?>:</b> <?= number_format($tot['paid'], 0) ?> &nbsp;·&nbsp;
            <b><?= $t('Waived', 'Zilizosamehewa') ?>:</b> <?= number_format($tot['waived'], 0) ?> TZS
        </div>
    </div></div>
</div>

<style>
    /* Print the grand total once at the end (a <tfoot> otherwise repeats on every
       page and overlaps the fixed footer); keep each row intact across breaks. */
    @media print {
        .table tfoot { display: table-row-group; }
        .table tfoot td { border-top: 2px solid #333 !important; }
        .table tbody tr { page-break-inside: avoid; }
    }
</style>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
