<?php
// app/bms/loans/loan_details.php — one loan: its terms, guarantors and schedule,
// with the actions the viewer is allowed to take on it.
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();
require_once ROOT_DIR . '/includes/member_savings.php';
require_once ROOT_DIR . '/includes/loans.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$uid         = (int) ($_SESSION['user_id'] ?? 0);
$customer_id = vk_member_customer_id($pdo, $uid);
$loan_id     = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$loan        = $loan_id > 0 ? vl_loan($pdo, $loan_id) : null;

// One answer for "no such loan" and "not yours to see", so a member cannot learn
// which loan numbers exist by trying them.
if (!$loan || !vl_may_view($pdo, $loan, $uid, $customer_id)) {
    echo '<div class="container py-5"><div class="alert alert-danger"><i class="bi bi-shield-lock me-2"></i>'
       . $t('This loan was not found.', 'Mkopo huu haujapatikana.') . '</div>'
       . '<a class="btn btn-secondary" href="' . getUrl('my_loans') . '">' . $t('Back', 'Rudi') . '</a></div>';
    includeFooter();
    ob_end_flush();
    exit;
}

$manage       = vl_can_manage();
$is_own       = (int) $loan['member_user_id'] === $uid;
$labels       = vl_status_labels($is_sw);
[$status_label, $status_color] = $labels[$loan['status']];
$guarantors   = vl_guarantors($pdo, $loan_id);
$installments = vl_installments($pdo, $loan_id);

// Before the loan is paid out there is no fixed schedule yet — show what it
// would be if paid out today, clearly marked as an estimate.
$is_estimate = !$installments && in_array($loan['status'], ['pending_guarantors', 'pending', 'approved'], true);
$lines = [];
if ($installments) {
    foreach ($installments as $i) {
        $lines[] = ['seq' => $i['seq'], 'due_date' => $i['due_date'], 'principal' => (float) $i['principal_due'], 'interest' => (float) $i['interest_due'],
                    'paid' => (float) $i['principal_paid'] + (float) $i['interest_paid'], 'status' => $i['status']];
    }
} elseif ($is_estimate) {
    foreach (lr_schedule((float) $loan['amount'], (float) $loan['interest_rate'], $loan['interest_type'], (int) $loan['term_months'], date('Y-m-d')) as $l) {
        $lines[] = ['seq' => $l['seq'], 'due_date' => $l['due_date'], 'principal' => $l['principal'], 'interest' => $l['interest'], 'paid' => 0.0, 'status' => 'pending'];
    }
}

$my_guarantee = null;
foreach ($guarantors as $g) {
    if ($customer_id !== null && (int) $g['guarantor_customer_id'] === $customer_id) { $my_guarantee = $g; }
}

// What this viewer may do right now. The server re-checks every one of these.
$can_respond  = $my_guarantee && $my_guarantee['status'] === 'pending' && $loan['status'] === 'pending_guarantors';
$can_cancel   = $is_own && in_array($loan['status'], ['pending_guarantors', 'pending'], true);
// A leader may record a guarantor's answer for them (members who do not sign in).
$can_record_for = !$is_own && $loan['status'] === 'pending_guarantors' && canApprove('approve_loan');
$can_decide   = !$is_own && $loan['status'] === 'pending' && canApprove('approve_loan');
$can_disburse = !$is_own && $loan['status'] === 'approved' && canCreate('disburse_loan');
$can_repay    = !$is_own && $loan['status'] === 'disbursed' && canCreate('disburse_loan');

// Where the loan stands today: penalties are worked out from the schedule on the
// day, never stored, so this is always current.
$today    = date('Y-m-d');
$rules    = vl_rules($loan);
$payoff   = $installments ? lr_payoff($installments, $today, $rules) : null;
$payments = vl_payments($pdo, $loan_id);
$due_now  = 0.0;
foreach ($installments as $k => $i) {
    $pen = lr_installment_penalty_due($i, $today, $rules);
    $lines[$k]['penalty'] = $pen + (float) $i['penalty_paid'];
    $lines[$k]['overdue'] = lr_installment_unpaid($i) > 0 && $i['due_date'] < $today;
    if (lr_installment_unpaid($i) > 0 && $i['due_date'] <= $today) {
        $due_now += lr_installment_unpaid($i) + $pen;
    }
}

$rate = rtrim(rtrim(number_format((float) $loan['interest_rate'], 3), '0'), '.');
$penalty = match ($loan['penalty_type']) {
    'percent' => rtrim(rtrim(number_format((float) $loan['penalty_value'], 2), '0'), '.') . '% ' . $t('of the overdue amount, per month', 'ya kiasi kilichochelewa, kwa mwezi'),
    'fixed'   => 'TZS ' . number_format((float) $loan['penalty_value']) . ' ' . $t('per month late', 'kwa mwezi wa kuchelewa'),
    default   => $t('None', 'Hakuna'),
};
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;overflow-x:hidden;">
    <div class="card border-0 shadow-sm mb-4" style="border-left:5px solid #0f4c81 !important;">
        <div class="card-body p-3 p-md-4 bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h3 class="fw-bold mb-1 text-primary"><i class="bi bi-bank me-2"></i><?= htmlspecialchars($loan['reference']) ?>
                    <span class="badge bg-<?= $status_color ?> fs-6 align-middle ms-2"><?= htmlspecialchars($status_label) ?></span></h3>
                <p class="text-muted mb-0"><?= htmlspecialchars($loan['member_name']) ?> · <?= htmlspecialchars($loan['type_name']) ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($can_respond): ?>
                <button type="button" class="btn btn-success rounded-pill px-3 loanActBtn" data-action="respond" data-decision="accept"><i class="bi bi-check-lg me-1"></i><?= $t('Accept to guarantee', 'Kubali kudhamini') ?></button>
                <button type="button" class="btn btn-outline-danger rounded-pill px-3 loanActBtn" data-action="respond" data-decision="decline"><i class="bi bi-x-lg me-1"></i><?= $t('Decline', 'Kataa') ?></button>
                <?php endif; ?>
                <?php if ($can_decide): ?>
                <button type="button" class="btn btn-success rounded-pill px-3 loanActBtn" data-action="approve"><i class="bi bi-check2-circle me-1"></i><?= $t('Approve', 'Idhinisha') ?></button>
                <button type="button" class="btn btn-outline-danger rounded-pill px-3 loanActBtn" data-action="reject"><i class="bi bi-x-circle me-1"></i><?= $t('Reject', 'Kataa') ?></button>
                <?php endif; ?>
                <?php if ($can_disburse): ?>
                <button type="button" class="btn btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#disburseModal"><i class="bi bi-cash-stack me-1"></i><?= $t('Pay out', 'Toa mkopo') ?></button>
                <?php endif; ?>
                <?php if ($can_repay): ?>
                <button type="button" class="btn btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#repayModal"><i class="bi bi-cash-coin me-1"></i><?= $t('Record repayment', 'Rekodi rejesho') ?></button>
                <?php endif; ?>
                <?php if ($can_cancel): ?>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3 loanActBtn" data-action="cancel"><?= $t('Withdraw application', 'Ghairi ombi') ?></button>
                <?php endif; ?>
                <a class="btn btn-light rounded-pill px-3" href="<?= getUrl($manage ? 'loans' : 'my_loans') ?>"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
            </div>
        </div>
    </div>

    <?php if ($is_own && ($manage) && in_array($loan['status'], ['pending', 'approved'], true)): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-2"></i><?= $t('This is your own loan, so another leader must approve it and pay it out.', 'Huu ni mkopo wako mwenyewe, kwa hiyo kiongozi mwingine ndiye ataidhinisha na kuutoa.') ?></div>
    <?php endif; ?>
    <?php if (in_array($loan['status'], ['rejected', 'cancelled'], true) && $loan['rejection_reason']): ?>
    <div class="alert alert-secondary"><strong><?= $t('Reason', 'Sababu') ?>:</strong> <?= htmlspecialchars($loan['rejection_reason']) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <?php foreach ([
            [$t('Amount borrowed', 'Kiasi kilichokopwa'), 'TZS ' . number_format((float) $loan['amount']), 'primary'],
            [$t('Interest', 'Riba'), 'TZS ' . number_format((float) $loan['total_interest']), 'danger'],
            [$t('Total to repay', 'Jumla ya marejesho'), 'TZS ' . number_format((float) $loan['total_repayable']), 'success'],
            [$t('Period', 'Muda'), (int) $loan['term_months'] . ' ' . $t('months', 'miezi'), 'secondary'],
        ] as [$label, $value, $color]): ?>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <div class="text-muted small text-uppercase fw-bold"><?= $label ?></div>
                <div class="fs-5 fw-bold text-<?= $color ?>"><?= $value ?></div>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($loan['status'] === 'disbursed' && $payoff): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row text-center g-3">
                <div class="col-6 col-lg-3"><div class="small text-muted text-uppercase fw-bold"><?= $t('Due now', 'Kinachodaiwa sasa') ?></div><div class="fs-5 fw-bold <?= $due_now > 0 ? 'text-danger' : 'text-muted' ?>">TZS <?= number_format($due_now, 2) ?></div></div>
                <div class="col-6 col-lg-3"><div class="small text-muted text-uppercase fw-bold"><?= $t('Principal left', 'Mkopo uliobaki') ?></div><div class="fs-5 fw-bold">TZS <?= number_format($payoff['principal'], 2) ?></div></div>
                <div class="col-6 col-lg-3"><div class="small text-muted text-uppercase fw-bold"><?= $t('Late penalties', 'Adhabu za kuchelewa') ?></div><div class="fs-5 fw-bold <?= $payoff['penalty'] > 0 ? 'text-danger' : 'text-muted' ?>">TZS <?= number_format($payoff['penalty'], 2) ?></div></div>
                <div class="col-6 col-lg-3"><div class="small text-muted text-uppercase fw-bold"><?= $t('To clear the loan today', 'Kumaliza mkopo leo') ?></div><div class="fs-5 fw-bold text-primary">TZS <?= number_format($payoff['total'], 2) ?></div>
                    <?php if ($payoff['waived_interest'] > 0): ?><div class="small text-success"><?= $t('saves', 'unaokoa') ?> TZS <?= number_format($payoff['waived_interest'], 2) ?> <?= $t('interest', 'riba') ?></div><?php endif; ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-bold"><?= $t('Terms', 'Masharti') ?></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted"><?= $t('Interest', 'Riba') ?></dt>
                        <dd class="col-7"><?= $rate ?>% <?= $t('a month', 'kwa mwezi') ?>, <?= $loan['interest_type'] === 'reducing' ? $t('on what is still owed', 'kwa deni lililobaki') : $t('on the amount borrowed', 'kwa kiasi kilichokopwa') ?></dd>
                        <dt class="col-5 text-muted"><?= $t('Late penalty', 'Adhabu ya kuchelewa') ?></dt>
                        <dd class="col-7"><?= htmlspecialchars($penalty) ?><?= $loan['penalty_type'] !== 'none' && (int) $loan['grace_days'] > 0 ? ' (' . (int) $loan['grace_days'] . ' ' . $t('grace days', 'siku za msamaha') . ')' : '' ?></dd>
                        <dt class="col-5 text-muted"><?= $t('Purpose', 'Madhumuni') ?></dt>
                        <dd class="col-7"><?= $loan['purpose'] ? htmlspecialchars($loan['purpose']) : '—' ?></dd>
                        <dt class="col-5 text-muted"><?= $t('Applied', 'Limeombwa') ?></dt>
                        <dd class="col-7"><?= $loan['applied_at'] ? date('d M Y', strtotime($loan['applied_at'])) : '—' ?></dd>
                        <?php if ($loan['approved_at']): ?>
                        <dt class="col-5 text-muted"><?= $t('Approved', 'Umeidhinishwa') ?></dt>
                        <dd class="col-7"><?= date('d M Y', strtotime($loan['approved_at'])) ?></dd>
                        <?php endif; ?>
                        <?php if ($loan['disbursed_at']): ?>
                        <dt class="col-5 text-muted"><?= $t('Paid out', 'Umetolewa') ?></dt>
                        <dd class="col-7 mb-0"><?= date('d M Y', strtotime($loan['disbursed_at'])) ?> · <?= htmlspecialchars((string) $loan['disbursement_account']) ?><?= $loan['disbursement_reference'] ? ' · ' . htmlspecialchars($loan['disbursement_reference']) : '' ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold"><?= $t('Guarantors', 'Wadhamini') ?> <span class="text-muted fw-normal">(<?= (int) $loan['guarantors_required'] ?> <?= $t('required', 'wanahitajika') ?>)</span></div>
                <div class="card-body">
                    <?php if (!$guarantors): ?>
                        <p class="text-muted mb-0 small"><?= $t('This loan type needs no guarantor.', 'Aina hii ya mkopo haihitaji mdhamini.') ?></p>
                    <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($guarantors as $g):
                            [$g_label, $g_color] = match ($g['status']) {
                                'accepted' => [$t('Accepted', 'Amekubali'), 'success'],
                                'declined' => [$t('Declined', 'Amekataa'), 'danger'],
                                default    => [$t('Waiting', 'Anasubiriwa'), 'warning'],
                            }; ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-center">
                                <span><?= htmlspecialchars($g['name']) ?></span>
                                <span class="badge bg-<?= $g_color ?>"><?= $g_label ?></span>
                            </div>
                            <?php if (!empty($g['note'])): ?><div class="small text-muted mt-1"><?= htmlspecialchars($g['note']) ?></div><?php endif; ?>
                            <?php if ($can_record_for && $g['status'] === 'pending' && (int) $g['guarantor_customer_id'] !== (int) $customer_id): ?>
                            <div class="mt-2 d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill guarantorForBtn" data-decision="accept" data-guarantor="<?= (int) $g['guarantor_customer_id'] ?>" data-name="<?= htmlspecialchars($g['name']) ?>"><i class="bi bi-check-lg me-1"></i><?= $t('Record acceptance', 'Rekodi kukubali') ?></button>
                                <button type="button" class="btn btn-sm btn-outline-danger rounded-pill guarantorForBtn" data-decision="decline" data-guarantor="<?= (int) $g['guarantor_customer_id'] ?>" data-name="<?= htmlspecialchars($g['name']) ?>"><?= $t('Record decline', 'Rekodi kukataa') ?></button>
                            </div>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold d-flex justify-content-between">
                    <span><?= $t('Repayment schedule', 'Ratiba ya marejesho') ?></span>
                    <?php if ($is_estimate): ?><span class="badge bg-light text-dark border"><?= $t('Estimate — dates are fixed when the loan is paid out', 'Makadirio — tarehe hupangwa mkopo unapotolewa') ?></span><?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$lines): ?>
                        <p class="text-muted mb-0 small"><?= $t('No schedule.', 'Hakuna ratiba.') ?></p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th>#</th><th><?= $t('Due', 'Tarehe') ?></th>
                                <th class="text-end"><?= $t('Principal', 'Mkopo') ?></th><th class="text-end"><?= $t('Interest', 'Riba') ?></th>
                                <th class="text-end"><?= $t('Instalment', 'Rejesho') ?></th><th class="text-end"><?= $t('Penalty', 'Adhabu') ?></th><th class="text-end"><?= $t('Paid', 'Imelipwa') ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($lines as $l): ?>
                                <tr class="<?= $l['status'] === 'paid' ? 'table-success' : (!empty($l['overdue']) ? 'table-danger' : '') ?>">
                                    <td><?= (int) $l['seq'] ?></td>
                                    <td><?= date('d M Y', strtotime($l['due_date'])) ?></td>
                                    <td class="text-end"><?= number_format($l['principal'], 2) ?></td>
                                    <td class="text-end"><?= number_format($l['interest'], 2) ?></td>
                                    <td class="text-end fw-bold"><?= number_format($l['principal'] + $l['interest'], 2) ?></td>
                                    <td class="text-end <?= ($l['penalty'] ?? 0) > 0 ? 'text-danger' : 'text-muted' ?>"><?= number_format($l['penalty'] ?? 0, 2) ?></td>
                                    <td class="text-end"><?= number_format($l['paid'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($payments): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white fw-bold"><?= $t('Repayments received', 'Marejesho yaliyopokelewa') ?></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr>
                                <th><?= $t('Date', 'Tarehe') ?></th><th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
                                <th class="text-end"><?= $t('Principal', 'Mkopo') ?></th><th class="text-end"><?= $t('Interest', 'Riba') ?></th>
                                <th class="text-end"><?= $t('Penalty', 'Adhabu') ?></th><th><?= $t('Account', 'Akaunti') ?></th><th><?= $t('Receipt', 'Risiti') ?></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
                                    <td class="text-end fw-bold"><?= number_format((float) $p['amount'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $p['principal_part'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $p['interest_part'], 2) ?></td>
                                    <td class="text-end"><?= number_format((float) $p['penalty_part'], 2) ?></td>
                                    <td><?= htmlspecialchars((string) $p['account']) ?></td>
                                    <td><?= htmlspecialchars((string) $p['receipt_number']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($can_repay): ?>
<div class="modal fade" id="repayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="repayForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><?= $t('Record repayment', 'Rekodi rejesho') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-start">
                <p class="small text-muted mb-3"><?= $t('The payment goes to the oldest instalment first: its penalty, then its interest, then its principal.', 'Malipo huanza na rejesho la zamani zaidi: adhabu yake, kisha riba, kisha mkopo.') ?></p>
                <input type="hidden" name="action" value="repay">
                <input type="hidden" name="loan_id" value="<?= (int) $loan_id ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= $t('Amount received (TZS)', 'Kiasi kilichopokelewa (TZS)') ?></label>
                    <input type="number" name="amount" class="form-control" min="1" step="any" required value="<?= $due_now > 0 ? htmlspecialchars((string) round($due_now, 2)) : '' ?>">
                    <div class="form-text"><?= $t('Due now', 'Kinachodaiwa sasa') ?>: TZS <?= number_format($due_now, 2) ?> · <?= $t('to clear the loan', 'kumaliza mkopo') ?>: TZS <?= number_format((float) ($payoff['total'] ?? 0), 2) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= $t('Date received', 'Tarehe ya kupokea') ?></label>
                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= $t('Received into', 'Imepokelewa kwenye') ?></label>
                    <select name="account" class="form-select" required>
                        <?php foreach (['M-Koba', 'Bank', 'Cash', 'Mobile Money'] as $acc): ?><option><?= $acc ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold"><?= $t('Receipt / M-Koba reference (optional)', 'Risiti / kumbukumbu ya M-Koba (si lazima)') ?></label>
                    <input type="text" name="receipt_number" class="form-control" maxlength="100">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $t('Cancel', 'Ghairi') ?></button>
                <button type="submit" class="btn btn-primary px-4"><?= $t('Save repayment', 'Hifadhi rejesho') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($can_disburse): ?>
<div class="modal fade" id="disburseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="disburseForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><?= $t('Pay out loan', 'Toa mkopo') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-start">
                <p class="mb-3"><?= $t('Paying out', 'Unatoa') ?> <strong>TZS <?= number_format((float) $loan['amount']) ?></strong> <?= $t('to', 'kwa') ?> <strong><?= htmlspecialchars($loan['member_name']) ?></strong>. <?= $t('The repayment dates are counted from this day.', 'Tarehe za marejesho huhesabiwa kuanzia siku hii.') ?></p>
                <input type="hidden" name="action" value="disburse">
                <input type="hidden" name="loan_id" value="<?= (int) $loan_id ?>">
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= $t('Date paid out', 'Tarehe ya kutoa') ?></label>
                    <input type="date" name="disbursement_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold"><?= $t('Paid from', 'Imetolewa kutoka') ?></label>
                    <select name="account" class="form-select" required>
                        <?php foreach (['M-Koba', 'Bank', 'Cash', 'Mobile Money'] as $acc): ?><option><?= $acc ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-bold"><?= $t('Reference / receipt (optional)', 'Kumbukumbu / risiti (si lazima)') ?></label>
                    <input type="text" name="reference" class="form-control" maxlength="100">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $t('Cancel', 'Ghairi') ?></button>
                <button type="submit" class="btn btn-primary px-4"><?= $t('Confirm pay-out', 'Thibitisha') ?></button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const isSw = <?= json_encode($is_sw) ?>;
    const tr = (en, sw) => isSw ? sw : en;
    const url = '<?= getUrl("actions/loan_action") ?>';
    const loanId = <?= (int) $loan_id ?>;

    function send(data) {
        $.ajax({ url: url, type: 'POST', dataType: 'json', data: data })
            .done(function (res) {
                Swal.fire({ icon: res.success ? 'success' : 'error', title: res.message, timer: res.success ? 1500 : undefined, showConfirmButton: !res.success })
                    .then(function () { if (res.success) location.reload(); });
            })
            .fail(function (xhr) { Swal.fire(tr('Error', 'Hitilafu'), (xhr.responseJSON && xhr.responseJSON.message) || tr('Server error.', 'Hitilafu ya seva.'), 'error'); });
    }

    const prompts = {
        approve: [tr('Approve this loan?', 'Idhinisha mkopo huu?'), ''],
        cancel:  [tr('Withdraw this application?', 'Ghairi ombi hili?'), ''],
        accept:  [tr('Accept to guarantee this loan?', 'Ukubali kudhamini mkopo huu?'), tr('By accepting, you confirm you stand as guarantor for this borrower.', 'Kwa kukubali, unathibitisha kumdhamini mkopaji huyu.')],
        decline: [tr('Decline to guarantee this loan?', 'Ukatae kudhamini mkopo huu?'), tr('If you decline, the loan application is cancelled.', 'Ukikataa, ombi la mkopo litaghairiwa.')]
    };

    $(document).on('click', '.loanActBtn', function () {
        const action = $(this).data('action'), decision = $(this).data('decision');
        if (action === 'reject') {
            Swal.fire({
                title: tr('Reject this loan?', 'Kataa mkopo huu?'), input: 'text', inputLabel: tr('Reason (the member will see it)', 'Sababu (mwanachama ataiona)'),
                showCancelButton: true, confirmButtonText: tr('Reject', 'Kataa'), cancelButtonText: tr('Cancel', 'Ghairi'),
                inputValidator: v => (v && v.trim()) ? undefined : tr('Give a reason.', 'Andika sababu.')
            }).then(function (r) { if (r.isConfirmed) send({ action: 'reject', loan_id: loanId, reason: r.value }); });
            return;
        }
        const p = prompts[decision || action];
        Swal.fire({ icon: 'question', title: p[0], text: p[1], showCancelButton: true, confirmButtonText: tr('Yes', 'Ndiyo'), cancelButtonText: tr('No', 'Hapana') })
            .then(function (r) { if (r.isConfirmed) send({ action: action, loan_id: loanId, decision: decision }); });
    });

    // A leader records a guarantor's answer for them, saying how they agreed.
    $(document).on('click', '.guarantorForBtn', function () {
        const decision = $(this).data('decision'), gid = $(this).data('guarantor'), name = $(this).data('name');
        Swal.fire({
            titleText: decision === 'accept' ? tr('Record that ' + name + ' accepts to guarantee?', 'Rekodi kwamba ' + name + ' amekubali kudhamini?')
                                         : tr('Record that ' + name + ' declines?', 'Rekodi kwamba ' + name + ' amekataa?'),
            text: decision === 'accept' ? '' : tr('The loan application will be cancelled.', 'Ombi la mkopo litaghairiwa.'),
            input: 'text', inputLabel: tr('How did the guarantor agree? (kept on the loan, with your name)', 'Mdhamini alikubali vipi? (huhifadhiwa kwenye mkopo, pamoja na jina lako)'),
            inputPlaceholder: tr('e.g. signed the paper form on 20 Oct', 'mfano: amesaini fomu tarehe 20 Okt'),
            showCancelButton: true, confirmButtonText: tr('Record', 'Rekodi'), cancelButtonText: tr('Cancel', 'Ghairi'),
            inputValidator: v => (v && v.trim().length >= 3) ? undefined : tr('Write how the guarantor agreed.', 'Andika jinsi mdhamini alivyokubali.')
        }).then(function (r) { if (r.isConfirmed) send({ action: 'respond_for', loan_id: loanId, guarantor_customer_id: gid, decision: decision, note: r.value }); });
    });

    $('#disburseForm, #repayForm').on('submit', function (e) { e.preventDefault(); send($(this).serialize()); });
})();
</script>
<?php includeFooter(); ob_end_flush(); ?>
