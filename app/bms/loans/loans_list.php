<?php
// app/bms/loans/loans_list.php — leadership: every member's loans.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();
require_once ROOT_DIR . '/includes/loans.php';

// Leadership only. A member reaches their own loans through My Loans.
if (!vl_can_manage()) {
    requireViewPermission('approve_loan');
}

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$labels = vl_status_labels($is_sw);
$status = isset($labels[$_GET['status'] ?? '']) ? (string) $_GET['status'] : '';
$loans  = vl_loans($pdo, null, $status);

// Headline figures, across all loans regardless of the filter.
$all = $status === '' ? $loans : vl_loans($pdo);
$kpi = ['waiting' => 0, 'to_pay_out' => 0, 'out' => 0.0, 'owed' => 0.0];
foreach ($all as $l) {
    if ($l['status'] === 'pending')   $kpi['waiting']++;
    if ($l['status'] === 'approved')  $kpi['to_pay_out']++;
    if ($l['status'] === 'disbursed') { $kpi['out'] += (float) $l['amount']; $kpi['owed'] += (float) $l['balance']; }
}

$apply_types   = vl_active_types($pdo);
$apply_members = $pdo->query("SELECT customer_id, " . vl_member_name_sql('customers') . " AS name FROM customers WHERE status = 'active' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
$apply_self_id = 0;
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0f4c81 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-bank me-2"></i><?= $t('Loans', 'Mikopo') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Applications, approvals and loans paid out', 'Maombi, idhini na mikopo iliyotolewa') ?></p>
            </div>
            <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#loanApplyModal">
                <i class="bi bi-plus-lg me-1"></i> <?= $t('New application', 'Ombi jipya') ?>
            </button>
        </div>
    </div>

    <div class="row g-2 mb-4">
        <?php foreach ([
            ['info', $t('Awaiting approval', 'Yanasubiri idhini'), number_format($kpi['waiting'])],
            ['primary', $t('Approved, not paid out', 'Imeidhinishwa, haijatolewa'), number_format($kpi['to_pay_out'])],
            ['success', $t('Out on loan (TZS)', 'Iliyokopeshwa (TZS)'), number_format($kpi['out'])],
            ['danger', $t('Still owed (TZS)', 'Deni lililobaki (TZS)'), number_format($kpi['owed'])],
        ] as [$color, $label, $val]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3 text-center">
                <div class="fs-5 fw-bold text-<?= $color ?>"><?= $val ?></div>
                <div class="small text-muted"><?= $label ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="<?= getUrl('loans') ?>" class="btn btn-sm rounded-pill <?= $status === '' ? 'btn-primary' : 'btn-outline-primary' ?>"><?= $t('All', 'Yote') ?></a>
                <?php foreach ($labels as $key => [$label, $color]): ?>
                <a href="<?= getUrl('loans') ?>?status=<?= $key ?>" class="btn btn-sm rounded-pill <?= $status === $key ? 'btn-primary' : 'btn-outline-primary' ?>"><?= htmlspecialchars($label) ?></a>
                <?php endforeach; ?>
            </div>
            <?php if (!$loans): ?>
                <p class="text-muted mb-0"><?= $t('No loans here yet.', 'Hakuna mikopo hapa bado.') ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?= $t('Reference', 'Kumbukumbu') ?></th>
                            <th><?= $t('Member', 'Mwanachama') ?></th>
                            <th><?= $t('Type', 'Aina') ?></th>
                            <th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
                            <th><?= $t('Period', 'Muda') ?></th>
                            <th class="text-end"><?= $t('Balance', 'Deni') ?></th>
                            <th><?= $t('Status', 'Hali') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($loans as $l): [$label, $color] = $labels[$l['status']]; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($l['reference']) ?></td>
                            <td><?= htmlspecialchars($l['member_name']) ?></td>
                            <td><?= htmlspecialchars($l['type_name']) ?></td>
                            <td class="text-end"><?= number_format((float) $l['amount']) ?></td>
                            <td><?= (int) $l['term_months'] ?> <?= $t('mo', 'miezi') ?></td>
                            <td class="text-end"><?= $l['status'] === 'disbursed' ? number_format((float) $l['balance']) : '—' ?></td>
                            <td><span class="badge bg-<?= $color ?>"><?= htmlspecialchars($label) ?></span></td>
                            <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= getUrl('loans/details') ?>?id=<?= (int) $l['loan_id'] ?>"><?= $t('Open', 'Fungua') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/_apply_modal.php'; ?>
<?php includeFooter(); ob_end_flush(); ?>
