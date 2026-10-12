<?php
// app/bms/loans/my_loans.php — a member's own loans, and the loans they have
// been asked to guarantee.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();
require_once ROOT_DIR . '/includes/member_savings.php';
require_once ROOT_DIR . '/includes/loans.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$customer_id = vk_member_customer_id($pdo, (int) ($_SESSION['user_id'] ?? 0)) ?? 0;
$labels      = vl_status_labels($is_sw);
$loans       = $customer_id > 0 ? vl_loans($pdo, $customer_id) : [];
$requests    = $customer_id > 0 ? vl_guarantee_requests($pdo, $customer_id) : [];

$apply_types   = vl_active_types($pdo);
$apply_members = [];
$apply_self_id = $customer_id;
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0f4c81 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-bank me-2"></i><?= $t('My Loans', 'Mikopo Yangu') ?></h3>
                <p class="text-muted mb-0 small"><?= $t('Your loans and the loans you have been asked to guarantee', 'Mikopo yako na mikopo uliyoombwa kudhamini') ?></p>
            </div>
            <?php if ($customer_id > 0): ?>
            <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#loanApplyModal">
                <i class="bi bi-plus-lg me-1"></i> <?= $t('Apply for a loan', 'Omba mkopo') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($customer_id <= 0): ?>
    <div class="alert alert-info"><?= $t('Your member profile is not set up yet. Please contact your group leadership.', 'Wasifu wako wa uanachama haujakamilika. Tafadhali wasiliana na uongozi wa kikundi.') ?></div>
    <?php else: ?>

    <?php if ($requests): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-bold"><i class="bi bi-shield-check me-2 text-warning"></i><?= $t('Guarantee requests', 'Maombi ya udhamini') ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th><?= $t('Borrower', 'Mkopaji') ?></th><th><?= $t('Loan', 'Mkopo') ?></th>
                        <th class="text-end"><?= $t('Amount', 'Kiasi') ?></th><th><?= $t('Your answer', 'Jibu lako') ?></th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($requests as $r): $open = $r['guarantee_status'] === 'pending' && $r['loan_status'] === 'pending_guarantors'; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($r['member_name']) ?></td>
                            <td><?= htmlspecialchars($r['type_name']) ?> <span class="small text-muted">· <?= htmlspecialchars($r['reference']) ?> · <?= (int) $r['term_months'] ?> <?= $t('months', 'miezi') ?></span></td>
                            <td class="text-end"><?= number_format((float) $r['amount']) ?></td>
                            <td>
                                <?php if ($open): ?><span class="badge bg-warning text-dark"><?= $t('Waiting for you', 'Linakusubiri') ?></span>
                                <?php elseif ($r['guarantee_status'] === 'accepted'): ?><span class="badge bg-success"><?= $t('Accepted', 'Umekubali') ?></span>
                                <?php elseif ($r['guarantee_status'] === 'declined'): ?><span class="badge bg-danger"><?= $t('Declined', 'Umekataa') ?></span>
                                <?php else: ?><span class="badge bg-secondary"><?= $t('Closed', 'Limefungwa') ?></span><?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($open): ?>
                                <button type="button" class="btn btn-success btn-sm guaranteeBtn" data-loan="<?= (int) $r['loan_id'] ?>" data-decision="accept"><i class="bi bi-check-lg"></i> <?= $t('Accept', 'Kubali') ?></button>
                                <button type="button" class="btn btn-outline-danger btn-sm guaranteeBtn" data-loan="<?= (int) $r['loan_id'] ?>" data-decision="decline"><i class="bi bi-x-lg"></i> <?= $t('Decline', 'Kataa') ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i><?= $t('My loans', 'Mikopo yangu') ?></div>
        <div class="card-body">
            <?php if (!$loans): ?>
                <p class="text-muted mb-0"><?= $t('You have no loans yet.', 'Huna mkopo wowote bado.') ?></p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr>
                        <th><?= $t('Reference', 'Kumbukumbu') ?></th><th><?= $t('Type', 'Aina') ?></th>
                        <th class="text-end"><?= $t('Amount', 'Kiasi') ?></th><th class="text-end"><?= $t('To repay', 'Jumla ya marejesho') ?></th>
                        <th class="text-end"><?= $t('Balance', 'Deni') ?></th><th><?= $t('Status', 'Hali') ?></th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($loans as $l): [$label, $color] = $labels[$l['status']]; ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($l['reference']) ?></td>
                            <td><?= htmlspecialchars($l['type_name']) ?></td>
                            <td class="text-end"><?= number_format((float) $l['amount']) ?></td>
                            <td class="text-end"><?= number_format((float) $l['total_repayable']) ?></td>
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
    <?php endif; ?>
</div>

<?php if ($customer_id > 0) { require __DIR__ . '/_apply_modal.php'; } ?>
<script>
$(document).on('click', '.guaranteeBtn', function () {
    const isSw = <?= json_encode($is_sw) ?>;
    const decision = $(this).data('decision'), loan = $(this).data('loan');
    Swal.fire({
        icon: 'question',
        title: decision === 'accept' ? (isSw ? 'Ukubali kumdhamini?' : 'Accept to guarantee this loan?') : (isSw ? 'Ukatae kumdhamini?' : 'Decline to guarantee this loan?'),
        text: decision === 'accept'
            ? (isSw ? 'Kwa kukubali, unathibitisha kumdhamini mkopaji huyu.' : 'By accepting, you confirm you stand as guarantor for this borrower.')
            : (isSw ? 'Ukikataa, ombi la mkopo litaghairiwa.' : 'If you decline, the loan application is cancelled.'),
        showCancelButton: true,
        confirmButtonText: isSw ? 'Ndiyo' : 'Yes', cancelButtonText: isSw ? 'Hapana' : 'No'
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.ajax({ url: '<?= getUrl("actions/loan_action") ?>', type: 'POST', dataType: 'json', data: { action: 'respond', loan_id: loan, decision: decision } })
            .done(function (res) { Swal.fire({ icon: res.success ? 'success' : 'error', title: res.message, timer: 1600, showConfirmButton: false }).then(() => location.reload()); })
            .fail(function (xhr) { Swal.fire(isSw ? 'Hitilafu' : 'Error', (xhr.responseJSON && xhr.responseJSON.message) || '', 'error'); });
    });
});
</script>
<?php includeFooter(); ob_end_flush(); ?>
