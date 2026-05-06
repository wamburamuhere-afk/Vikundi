<?php
// app/bms/loans/loan_details.php
$page_title = 'Taarifa za Mkopo - VICoBA';
require_once 'header.php';

$loan_id = intval($_GET['id'] ?? 0);
if (!$loan_id) { header("Location: " . getUrl('loans')); exit(); }

$stmt = $pdo->prepare("
    SELECT l.*, CONCAT(c.first_name, ' ', c.last_name) AS member_name,
        c.phone AS member_phone, c.email AS member_email, c.customer_id
    FROM loans l 
    LEFT JOIN customers c ON l.customer_id = c.customer_id
    WHERE l.loan_id = ?
");
$stmt->execute([$loan_id]);
$loan = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$loan) { header("Location: " . getUrl('loans')); exit(); }

// Fetch repayment schedule
$schedule = $pdo->prepare("SELECT * FROM loan_repayments WHERE loan_id=? ORDER BY due_date ASC");
$schedule->execute([$loan_id]);
$schedule = $schedule->fetchAll(PDO::FETCH_ASSOC);

$status = strtolower($loan['status'] ?? 'pending');
$status_label = match($status) {
    'pending'   => ['Inasubiri Idhini', 'warning'],
    'approved'  => ['Imeidhinishwa', 'info'],
    'disbursed' => ['Imetolewa - Inaendelea', 'primary'],
    'repaid'    => ['Imelipwa Kikamilifu', 'success'],
    'defaulted' => ['Haijalipiwa', 'danger'],
    default     => [ucfirst($status), 'secondary']
};
$ref = $loan['reference_number'] ?: 'LN-' . str_pad($loan_id, 4, '0', STR_PAD_LEFT);
?>

<div class="container-fluid py-4">
    <!-- Page Header (Actions in one row) -->
    <div class="d-flex justify-content-between align-items-center py-3 border-bottom mb-4 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold text-primary">
                <i class="bi bi-file-earmark-text me-2"></i> Taarifa za Mkopo: <code><?= htmlspecialchars($ref) ?></code>
            </h4>
            <small class="text-muted">Kagua na kutoa ripoti ya mkopo wa mwanachama</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('loans') ?>" class="btn btn-primary px-4 rounded-pill shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> Rudi Kwenye Mikopo
            </a>
            <button onclick="window.print()" class="btn btn-outline-dark px-4 rounded-pill border-2">
                <i class="bi bi-printer me-2"></i> Print Statement
            </button>
        </div>
    </div>

    <!-- Printable Header Content (Hidden on Screen) -->
    <div class="d-none d-print-block text-center mb-4">
        <h3 class="fw-bold">MFUMO WA VICoBA</h3>
        <h5>TAARIFA KAWAIDA YA MKOPO</h5>
        <div class="small">Ref: <?= htmlspecialchars($ref) ?> | Mwanachama: <?= htmlspecialchars($loan['member_name'] ?? 'N/A') ?></div>
        <hr>
    </div>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-md-5">
            <!-- Loan Summary Card (Now contains Status) -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calculator me-2"></i> Muhtasari & Hali</h6>
                    <span class="badge bg-<?= $status_label[1] ?> px-3 py-1 rounded-pill small border border-white border-opacity-25 shadow-sm"><?= $status_label[0] ?></span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-4">
                        <div class="col-12 border-bottom pb-3">
                            <small class="text-muted d-block small fw-bold uppercase">Mwanachama</small>
                            <span class="fs-5 fw-bold text-dark"><?= htmlspecialchars($loan['member_name'] ?? 'Mwanachama') ?></span>
                            <br><small class="text-muted"><i class="bi bi-telephone me-1"></i> <?= htmlspecialchars($loan['member_phone'] ?? 'N/A') ?></small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block small fw-bold uppercase">Kiasi cha Mkopo</small>
                            <span class="fw-bold text-primary fs-5">TZS <?= number_format($loan['amount'], 2) ?></span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block small fw-bold uppercase">Riba (%)</small>
                            <span class="fw-bold fs-5"><?= $loan['interest_rate'] ?>%</span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block small fw-bold uppercase">Muda wa Mkopo</small>
                            <span class="fw-bold fs-5"><?= $loan['term_length'] ?> Miezi</span>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block small fw-bold uppercase">Jumla na Riba</small>
                            <span class="fw-bold text-success fs-5">TZS <?= number_format($loan['total_repayment'], 2) ?></span>
                        </div>
                    </div>

                    <!-- Payout Details -->
                    <div class="mt-4 p-3 bg-light rounded-3 border">
                        <div class="row g-2">
                            <div class="col-6">
                                <small class="text-muted d-block small fw-bold">KILICHOLIPWA</small>
                                <span class="fw-bold text-info">TZS <?= number_format($loan['total_paid'] ?? 0, 2) ?></span>
                            </div>
                            <div class="col-6 border-start ps-3">
                                <small class="text-muted d-block small fw-bold">BAKI (DENI)</small>
                                <span class="fw-bold text-danger">TZS <?= number_format($loan['balance'] ?? 0, 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <?php 
                    $total_rep = (float)($loan['total_repayment'] ?? 1);
                    $paid = (float)($loan['total_paid'] ?? 0);
                    $pct = min(100, ($paid / $total_rep) * 100); 
                    ?>
                    <div class="mt-4">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted small fw-bold uppercase">Maendeleo ya Marejesho</small>
                            <small class="fw-bold text-primary"><?= number_format($pct, 1) ?>%</small>
                        </div>
                        <div class="progress rounded-pill bg-white border shadow-sm" style="height:14px;">
                            <div class="progress-bar bg-primary" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Dates & Purpose Card -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-body p-4">
                    <div class="row g-3">
                         <div class="col-6">
                            <small class="text-muted d-block small fw-bold">TAREHE YA OMBI</small>
                            <span><?= $loan['application_date'] ? date('d/m/Y', strtotime($loan['application_date'])) : 'N/A' ?></span>
                        </div>
                         <div class="col-6">
                            <small class="text-muted d-block small fw-bold">TAREHE YA MWISHO</small>
                            <span><?= $loan['loan_end_date'] ? date('d/m/Y', strtotime($loan['loan_end_date'])) : 'N/A' ?></span>
                        </div>
                         <div class="col-12 mt-2">
                            <small class="text-muted d-block small fw-bold">LENGO LA MKOPO</small>
                            <p class="mb-0 text-muted"><?= htmlspecialchars($loan['purpose'] ?? 'Matumizi ya kawaida ya kikundi') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Schedule -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-2 text-primary"></i> Jedwali la Marejesho (Instalments)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive d-none d-md-block d-print-block">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4">No</th>
                                    <th>Tarehe</th>
                                    <th class="text-end">Deni (TZS)</th>
                                    <th class="text-end">Ulipaji</th>
                                    <th class="text-center pe-3">Hali</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($schedule)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">Hakuna jedwali lililotengenezwa bado.</td></tr>
                                <?php else: ?>
                                <?php foreach ($schedule as $i => $inst):
                                    $istatus = strtolower($inst['status'] ?? 'pending');
                                    $ibadge = match($istatus) {
                                        'paid'    => ['Imelipwa', 'success'],
                                        'partial' => ['Sehemu', 'warning text-dark'],
                                        'late'    => ['Imechelewa', 'danger'],
                                        default   => ['Inasubiri', 'light text-dark border']
                                    };
                                    $overdue = ($istatus === 'pending' || $istatus === 'partial') && $inst['due_date'] < date('Y-m-d');
                                ?>
                                <tr class="<?= $overdue ? 'bg-danger bg-opacity-10' : '' ?>">
                                    <td class="ps-4 text-muted small"><?= $i + 1 ?></td>
                                    <td class="small fw-bold">
                                        <?= date('d/m/Y', strtotime($inst['due_date'])) ?>
                                        <?php if ($overdue): ?><br><small class="text-danger fw-bold text-uppercase" style="font-size:10px;">!!! IMECHELEWA</small><?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">TZS <?= number_format($inst['amount'], 2) ?></td>
                                    <td class="text-end text-<?= ($inst['amount_paid'] ?? 0) > 0 ? 'success' : 'muted' ?>">
                                        TZS <?= number_format($inst['amount_paid'] ?? 0, 2) ?>
                                    </td>
                                    <td class="text-center pe-3">
                                        <span class="badge bg-<?= $ibadge[1] ?> rounded-pill px-3 py-1 small"><?= $ibadge[0] ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- ═══ CARD VIEW — Mobile Only ═══ -->
                    <div class="p-3 d-md-none d-print-none vk-cards-wrapper" id="scheduleCardsWrapper">
                        <?php if (empty($schedule)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>
                            <p>Hakuna jedwali lililotengenezwa bado.</p>
                        </div>
                        <?php else: foreach ($schedule as $i => $inst):
                            $sc_status  = strtolower($inst['status'] ?? 'pending');
                            $sc_overdue = ($sc_status === 'pending' || $sc_status === 'partial') && $inst['due_date'] < date('Y-m-d');
                            $sc_badge   = match($sc_status) {
                                'paid'    => ['Imelipwa',   'bg-success text-white'],
                                'partial' => ['Sehemu',     'bg-warning text-dark'],
                                'late'    => ['Imechelewa', 'bg-danger text-white'],
                                default   => ['Inasubiri',  'bg-light text-dark border']
                            };
                            $sc_av_color = $sc_status === 'paid'
                                ? 'linear-gradient(135deg,#198754,#146c43)'
                                : ($sc_overdue ? 'linear-gradient(135deg,#dc3545,#b02a37)' : 'linear-gradient(135deg,#0d6efd,#0a58ca)');
                        ?>
                        <div class="vk-member-card <?= $sc_overdue ? 'border-danger' : '' ?>">
                            <div class="vk-card-header d-flex justify-content-between align-items-center gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="vk-card-avatar" style="background:<?= $sc_av_color ?>;"><?= $i + 1 ?></div>
                                    <div>
                                        <div class="fw-bold text-dark" style="font-size:13px;"><?= date('d/m/Y', strtotime($inst['due_date'])) ?></div>
                                        <?php if ($sc_overdue): ?>
                                        <small class="text-danger fw-bold" style="font-size:10px;">!!! IMECHELEWA</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge <?= $sc_badge[1] ?> rounded-pill px-2" style="font-size:10px;"><?= $sc_badge[0] ?></span>
                            </div>
                            <div class="vk-card-body">
                                <div class="vk-card-row">
                                    <span class="vk-card-label">Deni (TZS)</span>
                                    <span class="vk-card-value fw-bold">TZS <?= number_format($inst['amount'], 2) ?></span>
                                </div>
                                <div class="vk-card-row">
                                    <span class="vk-card-label">Ulipaji</span>
                                    <span class="vk-card-value fw-bold text-<?= ($inst['amount_paid'] ?? 0) > 0 ? 'success' : 'muted' ?>">
                                        TZS <?= number_format($inst['amount_paid'] ?? 0, 2) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Smooth hover for the primary back button */
.btn-primary:hover {
    background-color: #0b5ed7 !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3) !important;
}
.btn-primary { transition: all 0.3s ease; }
</style>

<?php require_once 'footer.php'; ?>
