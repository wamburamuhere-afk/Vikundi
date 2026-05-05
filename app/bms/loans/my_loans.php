<?php
ob_start();
require_once HEADER_FILE;

// Get logged-in member's customer record
$cust_stmt = $pdo->prepare("SELECT c.* FROM customers c JOIN users u ON c.email=u.email WHERE u.user_id=?");
$cust_stmt->execute([$_SESSION['user_id']]);
$customer = $cust_stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo '<div class="alert alert-warning m-4">Wasifu wako wa mwanachama haujapatikana. Wasiliana na msimamizi.</div>';
    $content = ob_get_clean(); require_once FOOTER_FILE; echo $content; exit();
}

$cid = $customer['customer_id'];

// Fetch member's loans
$loans = $pdo->prepare("SELECT * FROM loans WHERE customer_id=? ORDER BY created_at DESC");
$loans->execute([$cid]);
$loans = $loans->fetchAll(PDO::FETCH_ASSOC);

// Summary
$active_loan = null; $total_borrowed = 0; $total_paid_all = 0;
foreach ($loans as $l) {
    $total_borrowed += $l['amount'];
    $total_paid_all += $l['total_paid'];
    if (in_array(strtolower($l['status']), ['disbursed','approved']) && !$active_loan) $active_loan = $l;
}
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center py-3 border-bottom mb-4">
    <div>
        <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-bank2 me-2"></i> Mikopo Yangu</h4>
        <small class="text-muted">Taarifa za mikopo yako yote</small>
    </div>
</div>

<?php if ($active_loan): ?>
<!-- Active Loan Banner -->
<div class="card border-0 shadow-sm mb-4" style="background: linear-gradient(135deg,#0d6efd,#0a58ca); color:white;">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <small class="opacity-75 text-uppercase fw-semibold">Mkopo Unaoendelea</small>
                <h3 class="fw-bold mt-1 mb-0">TZS <?= number_format($active_loan['amount'], 2) ?></h3>
                <p class="mb-0 opacity-75 mt-1">Riba <?= $active_loan['interest_rate'] ?>% kwa mwezi · <?= $active_loan['term_length'] ?> Miezi</p>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <div class="mb-1 opacity-75">Baki Inayobaki</div>
                <h2 class="fw-bold mb-0">TZS <?= number_format($active_loan['balance'], 2) ?></h2>
                <?php $pct = $active_loan['total_repayment'] > 0 ? min(100, ($active_loan['total_paid']/$active_loan['total_repayment'])*100) : 0; ?>
                <div class="progress mt-2" style="height:8px; background:rgba(255,255,255,0.3);">
                    <div class="progress-bar bg-white" style="width:<?= $pct ?>%;"></div>
                </div>
                <small class="opacity-75"><?= number_format($pct,1) ?>% imelipwa</small>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= getUrl('loans/details') ?>?id=<?= $active_loan['loan_id'] ?>" class="btn btn-light btn-sm px-4 fw-semibold">
                <i class="bi bi-eye me-2"></i>Angalia Jedwali la Malipo
            </a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info border-0 shadow-sm mb-4">
    <i class="bi bi-info-circle me-2"></i> Huna mkopo unaoendelea kwa sasa. Wasiliana na msimamizi ukihitaji mkopo.
</div>
<?php endif; ?>

<!-- Loans History -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i> Historia ya Mikopo Yote</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Ref #</th>
                        <th>Kiasi</th>
                        <th>Jumla Linalolipwa</th>
                        <th>Kilicholipwa</th>
                        <th>Baki</th>
                        <th>Tarehe</th>
                        <th class="text-center">Hali</th>
                        <th class="text-center pe-3">Taarifa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loans as $loan):
                        $s = strtolower($loan['status']);
                        $badge = match($s) {
                            'pending'   => ['Inasubiri','warning text-dark'],
                            'approved'  => ['Imeidhinishwa','info'],
                            'disbursed' => ['Inaendelea','primary'],
                            'repaid'    => ['Imelipwa','success'],
                            'defaulted' => ['Imekataliwa','secondary'],
                            default     => [ucfirst($s),'secondary']
                        };
                        $ref = $loan['reference_number'] ?: 'LN-'.str_pad($loan['loan_id'],4,'0',STR_PAD_LEFT);
                    ?>
                    <tr>
                        <td class="ps-4"><code class="text-primary"><?= $ref ?></code></td>
                        <td class="fw-semibold">TZS <?= number_format($loan['amount'],2) ?></td>
                        <td>TZS <?= number_format($loan['total_repayment'],2) ?></td>
                        <td class="text-success fw-semibold">TZS <?= number_format($loan['total_paid'],2) ?></td>
                        <td class="fw-bold <?= $loan['balance']>0 ? 'text-danger':'text-success' ?>">TZS <?= number_format($loan['balance'],2) ?></td>
                        <td><?= date('d/m/Y', strtotime($loan['created_at'])) ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $badge[1] ?> rounded-pill px-3"><?= $badge[0] ?></span>
                        </td>
                        <td class="text-center pe-3">
                            <a href="<?= getUrl('loans/details') ?>?id=<?= $loan['loan_id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($loans)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox" style="font-size:3rem;"></i>
                            <p class="mt-2 mb-0">Hujawahi kuomba mkopo bado.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once FOOTER_FILE;
echo $content;
?>
