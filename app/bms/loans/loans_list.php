<?php
// app/bms/loans/loans_list.php
$page_title = ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Orodha ya Mikopo - VICoBA' : 'Loans List - VICoBA';
require_once 'header.php';

if (!in_array($user_role, ['Admin', 'Secretary', 'Katibu', 'Treasurer'])) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

// Fetch group settings
$gs = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$interest_rate  = floatval($gs['loan_interest_rate'] ?? 10);
$max_term       = intval($gs['loan_max_term_months'] ?? 3);
$multiplier     = floatval($gs['loan_multiplier'] ?? 3);

// Fetch all loans with member info
$loans = $pdo->query("
    SELECT l.*,
        CONCAT(c.first_name, ' ', c.last_name) AS member_name,
        c.phone AS member_phone,
        c.customer_id
    FROM loans l
    LEFT JOIN customers c ON l.customer_id = c.customer_id
    ORDER BY l.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = ['total' => count($loans), 'pending' => 0, 'active' => 0, 'repaid' => 0, 'defaulted' => 0, 'total_disbursed' => 0];
foreach ($loans as $l) {
    $status = strtolower($l['status']);
    if ($status === 'pending') $stats['pending']++;
    if (in_array($status, ['approved','disbursed'])) { $stats['active']++; $stats['total_disbursed'] += $l['amount']; }
    if ($status === 'repaid') $stats['repaid']++;
    if ($status === 'defaulted') $stats['defaulted']++;
}

// Fetch all active members for the apply form (similar to fines list)
$members = $pdo->query("
    SELECT c.customer_id, u.first_name, u.last_name, u.status as user_status, c.phone
    FROM users u
    JOIN customers c ON u.email = c.email
    WHERE u.status != 'deleted' AND u.user_role != 'Admin'
    ORDER BY u.first_name ASC, u.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Include Select2 for searching members -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center py-3 border-bottom mb-4">
        <div>
            <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-bank2 me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Mikopo' : 'Loan Management' ?></h4>
            <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ombi, idhini na malipo ya mikopo ya wanachama' : 'Member loan applications, approvals and payments' ?></small>
        </div>
        <button class="btn btn-primary shadow-sm px-4" data-bs-toggle="modal" data-bs-target="#applyLoanModal">
            <i class="bi bi-plus-circle-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Omba Mkopo Mpya' : 'Apply New Loan' ?>
        </button>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-warning bg-opacity-10 py-2 rounded-4">
                <div class="card-body">
                    <div class="small fw-bold text-warning mb-1 text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Yanasubiri' : 'Pending Review' ?></div>
                    <div class="fs-2 fw-bold text-warning"><?= $stats['pending'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-primary bg-opacity-10 py-2 rounded-4">
                <div class="card-body">
                    <div class="small fw-bold text-primary mb-1 text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Yanayoendelea' : 'Active Loans' ?></div>
                    <div class="fs-2 fw-bold text-primary"><?= $stats['active'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-success bg-opacity-10 py-2 rounded-4">
                <div class="card-body">
                    <div class="small fw-bold text-success mb-1 text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Yaliyolipwa' : 'Fully Repaid' ?></div>
                    <div class="fs-2 fw-bold text-success"><?= $stats['repaid'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100 bg-info bg-opacity-10 py-2 rounded-4">
                <div class="card-body">
                    <div class="small fw-bold text-info mb-1 text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla Iliyotolewa' : 'Total Disbursed' ?></div>
                    <div class="fs-4 fw-bold text-info">TZS <?= number_format($stats['total_disbursed']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loans Table -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Orodha ya Mikopo' : 'Loans List' ?></h6>
            <div class="mt-3 d-md-none d-print-none">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" id="loanCardSearch" class="form-control border-start-0"
                           placeholder="<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tafuta mkopo...' : 'Search loans...' ?>">
                </div>
            </div>
        </div>
        <div class="card-body p-0 d-none d-md-block d-print-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="loansTable">
                    <thead class="bg-light text-uppercase small text-muted">
                        <tr>
                            <th class="ps-4">S/NO</th>
                            <th>Ref #</th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member' ?></th>
                            <th class="text-end"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mkopo (TZS)' : 'Loan (TZS)' ?></th>
                            <th class="text-center"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Riba %' : 'Interest %' ?></th>
                            <th class="text-end"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jumla' : 'Total' ?></th>
                            <th class="text-end text-info"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Zilizolipwa' : 'Paid' ?></th>
                            <th class="text-end"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Baki' : 'Balance' ?></th>
                            <th class="text-center"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali' : 'Status' ?></th>
                            <th class="text-center pe-4"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua' : 'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($loans as $loan): ?>
                        <?php
                            $status = strtolower($loan['status'] ?? 'pending');
                            $badge = match($status) {
                                'pending'   => 'bg-warning text-dark',
                                'approved'  => 'bg-info text-white',
                                'disbursed' => 'bg-primary text-white',
                                'repaid'    => 'bg-success text-white',
                                'defaulted' => 'bg-danger text-white',
                                default     => 'bg-secondary text-white'
                            };
                            $status_label = match($status) {
                                'pending'   => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inasubiri' : 'Pending'),
                                'approved'  => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imeidhinishwa' : 'Approved'),
                                'disbursed' => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imetolewa' : 'Disbursed'),
                                'repaid'    => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imelipwa' : 'Repaid'),
                                'defaulted' => (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Deni' : 'Defaulted'),
                                default     => ucfirst($status)
                            };
                        ?>
                        <tr id="loan-row-<?= $loan['loan_id'] ?>">
                            <td class="ps-4 fw-bold text-muted"><?= $sno++ ?></td>
                            <td class="fw-bold text-primary small"><?= htmlspecialchars($loan['reference_number'] ?? 'LN-'.$loan['loan_id']) ?></td>
                            <td>
                                <div class="fw-bold"><?= htmlspecialchars($loan['member_name'] ?? (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama' : 'Member')) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($loan['member_phone'] ?? '') ?></small>
                            </td>
                            <td class="text-end fw-bold"><?= number_format($loan['amount'], 2) ?></td>
                            <td class="text-center"><?= $loan['interest_rate'] ?>%</td>
                            <td class="text-end fw-bold"><?= number_format($loan['total_repayment'], 2) ?></td>
                            <td class="text-end text-info fw-bold"><?= number_format($loan['total_paid'] ?? 0, 2) ?></td>
                            <td class="text-end fw-bold <?= ($loan['balance'] ?? 0) > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($loan['balance'] ?? 0, 2) ?>
                            </td>
                            <td class="text-center">
                                <span class="badge rounded-pill <?= $badge ?> px-3"><?= $status_label ?></span>
                            </td>
                            <td class="text-center pe-4">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm border shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <li><a class="dropdown-item py-2" href="<?= getUrl('loans/details') ?>?id=<?= $loan['loan_id'] ?>"><i class="bi bi-eye-fill text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa Kamili' : 'View Details' ?></a></li>
                                        <?php if ($status === 'pending'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="rejectLoan(<?= $loan['loan_id'] ?>)"><i class="bi bi-person-x-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kataa' : 'Reject' ?></a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="deleteLoan(<?= $loan['loan_id'] ?>)"><i class="bi bi-trash-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Futa Mkopo' : 'Delete Loan' ?></a></li>
                                        <?php endif; ?>
                                        <?php if ($status === 'approved'): ?>
                                        <li><a class="dropdown-item py-2 text-primary fw-bold" href="javascript:void(0)" onclick="disburseLoan(<?= $loan['loan_id'] ?>)"><i class="bi bi-cash-stack me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Toa Fedha (Disburse)' : 'Disburse Funds' ?></a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php endif; ?>
                                        <?php if (in_array($status, ['approved','disbursed'])): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item py-2 text-info" href="javascript:void(0)" onclick="openPaymentModal(<?= $loan['loan_id'] ?>, '<?= htmlspecialchars($loan['member_name'] ?? 'Member') ?>', <?= $loan['balance'] ?? 0 ?>)"><i class="bi bi-cash-coin me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ingiza Malipo' : 'Enter Payment' ?></a></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══ CARD VIEW — Mobile Only ═══ -->
        <div class="p-3 d-md-none d-print-none" id="loanCardsWrapper">
            <?php $c_sno = 1; foreach ($loans as $loan):
                $_lang_l     = $_SESSION['preferred_language'] ?? 'en';
                $_sw_l       = ($_lang_l === 'sw');
                $l_status    = strtolower($loan['status'] ?? 'pending');
                $l_badge     = match($l_status) {
                    'pending'   => 'bg-warning text-dark',
                    'approved'  => 'bg-info text-white',
                    'disbursed' => 'bg-primary text-white',
                    'repaid'    => 'bg-success text-white',
                    'defaulted' => 'bg-danger text-white',
                    default     => 'bg-secondary text-white'
                };
                $l_label = match($l_status) {
                    'pending'   => ($_sw_l ? 'Inasubiri'      : 'Pending'),
                    'approved'  => ($_sw_l ? 'Imeidhinishwa'  : 'Approved'),
                    'disbursed' => ($_sw_l ? 'Imetolewa'      : 'Disbursed'),
                    'repaid'    => ($_sw_l ? 'Imelipwa'       : 'Repaid'),
                    'defaulted' => ($_sw_l ? 'Deni'           : 'Defaulted'),
                    default     => ucfirst($l_status)
                };
                $l_ref     = htmlspecialchars($loan['reference_number'] ?? 'LN-'.$loan['loan_id']);
                $l_name    = $loan['member_name'] ?? 'Member';
                $l_avatar  = strtoupper(substr($l_name, 0, 1));
                $l_balance = $loan['balance'] ?? 0;
                $l_search  = strtolower(implode(' ', [
                    $l_ref, $l_name, $loan['member_phone'] ?? '', $l_status, $l_label
                ]));
            ?>
            <div class="vk-member-card"
                 data-name="<?= htmlspecialchars($l_search) ?>"
                 data-status-text="<?= htmlspecialchars(strtolower($l_label)) ?>">

                <!-- Header: avatar · name · ref · badge -->
                <div class="vk-card-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="vk-card-avatar" style="background: linear-gradient(135deg, #198754, #146c43);">
                            <?= $l_avatar ?>
                        </div>
                        <div class="flex-grow-1" style="min-width:0;">
                            <div class="fw-bold text-dark lh-sm mb-1"><?= safe_output($l_name) ?></div>
                            <small class="text-muted"><?= $l_ref ?> &middot; <?= safe_output($loan['member_phone'] ?? '—') ?></small>
                        </div>
                        <span class="badge rounded-pill <?= $l_badge ?> px-3"><?= $l_label ?></span>
                    </div>
                </div>

                <!-- Body: label on left, value on right -->
                <div class="vk-card-body">
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_l ? 'Mkopo (TZS)' : 'Loan (TZS)' ?></span>
                        <span class="vk-card-value fw-bold"><?= number_format($loan['amount'], 2) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_l ? 'Riba' : 'Interest' ?></span>
                        <span class="vk-card-value"><?= $loan['interest_rate'] ?>%</span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_l ? 'Jumla Kulipa' : 'Total Repay' ?></span>
                        <span class="vk-card-value fw-bold"><?= number_format($loan['total_repayment'], 2) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_l ? 'Zilizolipwa' : 'Paid' ?></span>
                        <span class="vk-card-value fw-bold text-info"><?= number_format($loan['total_paid'] ?? 0, 2) ?></span>
                    </div>
                    <div class="vk-card-row">
                        <span class="vk-card-label"><?= $_sw_l ? 'Baki' : 'Balance' ?></span>
                        <span class="vk-card-value fw-bold <?= $l_balance > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= number_format($l_balance, 2) ?>
                        </span>
                    </div>
                </div>

                <!-- Actions: icon-only, status-dependent, consistent colors -->
                <div class="vk-card-actions">
                    <a href="<?= getUrl('loans/details') ?>?id=<?= $loan['loan_id'] ?>"
                       class="btn btn-sm btn-outline-primary vk-btn-action"
                       title="<?= $_sw_l ? 'Taarifa Kamili' : 'View Details' ?>">
                        <i class="bi bi-eye-fill"></i>
                    </a>
                    <?php if ($l_status === 'pending'): ?>
                    <button class="btn btn-sm btn-outline-warning vk-btn-action"
                            onclick="rejectLoan(<?= $loan['loan_id'] ?>)"
                            title="<?= $_sw_l ? 'Kataa' : 'Reject' ?>">
                        <i class="bi bi-person-x-fill"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger vk-btn-action"
                            onclick="deleteLoan(<?= $loan['loan_id'] ?>)"
                            title="<?= $_sw_l ? 'Futa Mkopo' : 'Delete Loan' ?>">
                        <i class="bi bi-trash-fill"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($l_status === 'approved'): ?>
                    <button class="btn btn-sm btn-outline-success vk-btn-action"
                            onclick="disburseLoan(<?= $loan['loan_id'] ?>)"
                            title="<?= $_sw_l ? 'Toa Fedha' : 'Disburse Funds' ?>">
                        <i class="bi bi-cash-stack"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (in_array($l_status, ['approved', 'disbursed'])): ?>
                    <button class="btn btn-sm btn-outline-info vk-btn-action"
                            onclick="openPaymentModal(<?= $loan['loan_id'] ?>, '<?= htmlspecialchars($l_name, ENT_QUOTES) ?>', <?= $l_balance ?>)"
                            title="<?= $_sw_l ? 'Ingiza Malipo' : 'Enter Payment' ?>">
                        <i class="bi bi-cash-coin"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div id="loanCardsEmptyState" class="d-none text-center py-5">
                <i class="bi bi-search fs-1 text-muted d-block mb-3"></i>
                <p class="text-muted mb-0">
                    <?= (($_SESSION['preferred_language'] ?? 'en') === 'sw') ? 'Hakuna mkopo unaolingana.' : 'No matching loans found.' ?>
                </p>
            </div>
        </div>
        <!-- ═══ END CARD VIEW ═══ -->

    </div>
</div>

<!-- Modal: Apply Loan -->
<div class="modal fade" id="applyLoanModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-bank2 me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ombi Jipya la Mkopo' : 'New Loan Application' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="applyLoanForm">
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold small text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanachama *' : 'Member *' ?></label>
                        <select name="customer_id" id="loanMemberSelect" class="form-select select2-member" required>
                            <option value=""><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '-- Tafuta Mwanachama hapa... --' : '-- Search Member here... --' ?></option>
                            <?php foreach ($members as $m): 
                                $name = htmlspecialchars($m['first_name'] . ' ' . $m['last_name']);
                            ?>
                            <option value="<?= $m['customer_id'] ?>"><?= $name ?> (<?= $m['phone'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi cha Mkopo (TZS) *' : 'Loan Amount (TZS) *' ?></label>
                        <input type="number" name="amount" id="loanAmount" class="form-control fw-bold" min="1000" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold small text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda (Miezi) *' : 'Term (Months) *' ?></label>
                        <input type="number" name="term_months" id="loanTerm" class="form-control" min="1" max="<?= $max_term ?>" value="<?= $max_term ?>" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                <button type="submit" class="btn btn-primary px-5 shadow" id="submitLoanBtn"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasilisha Ombi' : 'Submit Application' ?></button>
            </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Payment -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ingiza Malipo' : 'Enter Repayment' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm">
                <input type="hidden" name="loan_id" id="payLoanId">
                <div class="modal-body p-4">
                    <p class="mb-3"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mkopo wa:' : 'Loan for:' ?> <strong id="payMemberName"></strong><br><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Baki:' : 'Balance:' ?> <strong class="text-danger" id="payBalance"></strong></p>
                    <div class="col-12">
                        <label class="form-label fw-bold small text-uppercase"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiasi Kidogo Kinacholipwa (TZS) *' : 'Amount to be Paid (TZS) *' ?></label>
                        <input type="number" name="amount_paid" class="form-control fw-bold" required>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-success px-5 shadow" id="submitPaymentBtn"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Malipo' : 'Save Payment' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('.select2-member').select2({ theme: 'bootstrap-5', dropdownParent: $('#applyLoanModal'), width: '100%'});
    $('#loansTable').DataTable({ 
        dom: 'Bfrtip',
        buttons: [
            { 
                extend: 'excel', 
                text: '<i class="bi bi-file-earmark-excel me-1"></i> Excel', 
                className: 'btn btn-sm btn-success rounded-pill px-3 mb-2',
                exportOptions: { columns: ':not(:last-child)' } 
            },
            { 
                extend: 'pdf', 
                text: '<i class="bi bi-file-earmark-pdf me-1"></i> PDF', 
                className: 'btn btn-sm btn-danger rounded-pill px-3 mb-2',
                exportOptions: { columns: ':not(:last-child)' }
            },
            { 
                extend: 'print', 
                text: '<i class="bi bi-printer me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chapa' : 'Print' ?>', 
                className: 'btn btn-sm btn-light border rounded-pill px-3 mb-2',
                exportOptions: { columns: ':not(:last-child)' }
            }
        ],
        language: { 
            search: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tafuta:' : 'Search:' ?>', 
            lengthMenu: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Onyesha _MENU_' : 'Show _MENU_' ?>', 
            info: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '_START_-_END_ ya _TOTAL_' : '_START_-_END_ of _TOTAL_' ?>', 
            paginate: {
                previous: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyuma' : 'Previous' ?>', 
                next: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mbele' : 'Next' ?>'
            },
            zeroRecords: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna data inayolingana' : 'No matching records found' ?>'
        }, 
        order: [[0, 'asc']] 
    });

    $('#applyLoanForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#submitLoanBtn'); 
        $btn.prop('disabled', true).text('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inatuma...' : 'Submitting...' ?>');
        $.ajax({
            url: '<?= getUrl("actions/apply_for_loan") ?>', type: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(res) {
                if(res.success) Swal.fire({ icon:'success', title:'<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imekamilika!' : 'Completed!' ?>', timer:1500, showConfirmButton:false }).then(() => location.reload());
                else { $btn.prop('disabled', false).text('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasilisha Ombi' : 'Submit Application' ?>'); Swal.fire('Error', res.message, 'error'); }
            }
        });
    });

    $('#paymentForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#submitPaymentBtn'); 
        $btn.prop('disabled', true).text('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inatuma...' : 'Recording...' ?>');
        $.ajax({
            url: '<?= getUrl("actions/record_loan_payment") ?>', type: 'POST', data: $(this).serialize(), dataType: 'json',
            success: function(res) {
                if(res.success) Swal.fire({ icon:'success', title:'<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imekamilika!' : 'Completed!' ?>', timer:1500, showConfirmButton:false }).then(() => location.reload());
                else { $btn.prop('disabled', false).text('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi Malipo' : 'Save Payment' ?>'); Swal.fire('Error', res.message, 'error'); }
            }
        });
    });
});

function approveLoan(id) { 
    Swal.fire({ 
        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idhinisha Mkopo?' : 'Approve Loan?' ?>', 
        icon: 'question', 
        showCancelButton: true,
        confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ndiyo, Idhinisha' : 'Yes, Approve' ?>',
        cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?>'
    }).then(r => {
        if(r.isConfirmed) $.post('<?= getUrl("actions/approve_loan_vicoba") ?>', {loan_id:id, action:'approve'}, r => location.reload(), 'json');
    });
}
function rejectLoan(id) { 
    Swal.fire({ 
        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kataa Mkopo?' : 'Reject Loan?' ?>', 
        icon: 'warning', 
        showCancelButton: true,
        confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ndiyo, Kataa' : 'Yes, Reject' ?>',
        cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?>'
    }).then(r => {
        if(r.isConfirmed) $.post('<?= getUrl("actions/approve_loan_vicoba") ?>', {loan_id:id, action:'reject'}, r => location.reload(), 'json');
    });
}
function deleteLoan(id) {
    Swal.fire({
        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Futa Mkopo?' : 'Delete Loan?' ?>',
        text: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Je, unataka kufuta kabisa ombi hili la mkopo? Kitendo hiki hakitarudishwa.' : 'Are you sure you want to permanently delete this loan application? This action cannot be undone.' ?>',
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ndiyo, Futa' : 'Yes, Delete' ?>',
        cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?>'
    }).then(r => {
        if (r.isConfirmed) {
            $.post('<?= getUrl("actions/delete_loan") ?>', { loan_id: id }, function(res) {
                if (res.success) Swal.fire('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Imefutwa!' : 'Deleted!' ?>', res.message, 'success').then(() => location.reload());
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}
function disburseLoan(id) {
    Swal.fire({
        title: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Toa Fedha?' : 'Disburse Funds?' ?>',
        text: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Thibitisha kuwa mwanachama amekabidhiwa fedha hizi ili kuanza kumlipisha marejesho.' : 'Confirm that the member has received the funds to start the repayment process.' ?>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ndiyo, Toa Fedha' : 'Yes, Disburse Funds' ?>',
        cancelButtonText: '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Bado' : 'Not Yet' ?>'
    }).then(r => {
        if (r.isConfirmed) {
            $.post('<?= getUrl("actions/disburse_loan") ?>', { loan_id: id }, function(res) {
                if (res.success) Swal.fire('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tayari!' : 'Success!' ?>', res.message, 'success').then(() => location.reload());
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}
function openPaymentModal(id, name, bal) {
    $('#payLoanId').val(id); $('#payMemberName').text(name); $('#payBalance').text('TZS ' + parseFloat(bal).toLocaleString());
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

// Mobile card search
$('#loanCardSearch').on('keyup', function() { filterLoanCards($(this).val()); });

function filterLoanCards(searchVal) {
    var search = (searchVal || '').toLowerCase().trim();
    var visible = 0;
    $('#loanCardsWrapper .vk-member-card').each(function() {
        var name = ($(this).data('name') || '').toLowerCase();
        var show = !search || name.indexOf(search) !== -1;
        $(this).toggle(show);
        if (show) visible++;
    });
    $('#loanCardsEmptyState').toggleClass('d-none', visible > 0);
}
</script>

<?php require_once 'footer.php'; ?>
