<?php
// app/constant/accounts/print_petty_cash.php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) exit('Unauthorized');

$id = intval($_GET['id'] ?? 0);
if (!$id) exit('Invalid ID');

$isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

global $pdo;

$stmt = $pdo->prepare("
    SELECT v.*,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username, rc.role_name AS creator_role,
           r1.role_name_sw AS creator_role_sw,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM petty_cash_vouchers v
      LEFT JOIN users uc ON v.prepared_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id     = rc.role_id
      LEFT JOIN roles r1 ON uc.role_id     = r1.role_id
      LEFT JOIN users ur ON v.reviewed_by  = ur.user_id
      LEFT JOIN roles rr ON ur.role_id     = rr.role_id
      LEFT JOIN users ua ON v.approved_by  = ua.user_id
      LEFT JOIN roles ra ON ua.role_id     = ra.role_id
     WHERE v.id = ?
");
$stmt->execute([$id]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$v) exit('Voucher not found');

$gs = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$group_name = $gs['group_name'] ?? 'KIKUNDI CHETU';
$status     = $v['status'] ?? 'pending';

$creator_name  = trim($v['creator_name'])  ?: ($v['creator_username'] ?? '');
$reviewer_name = trim($v['reviewer_name']) ?: '';
$approver_name = trim($v['approver_name']) ?: '';

$wf_sigs = getWorkflowSignatures($pdo, 'petty_cash', $id);

// For petty cash the "created" action may not have a workflow_signatures row — fall back to prepared_by info
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $isSwahili ? ($v['creator_role_sw'] ?? $v['creator_role'] ?? '') : ($v['creator_role'] ?? ''),
    'reviewed_by_name'   => $reviewer_name,
    'reviewed_by_role'   => $v['reviewer_role'] ?? '',
    'approved_by_name'   => $approver_name,
    'approved_by_role'   => $v['approver_role'] ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']    ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']   ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']   ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at']  ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']   ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at']  ?? ($v['approval_date'] ?? null),
    '__include_css'      => true,
];

logActivity('Viewed', 'Petty Cash', 'Printed Petty Cash Voucher #' . $v['voucher_no'], 'PCV#' . $v['voucher_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Voucher - <?= htmlspecialchars($v['voucher_no']) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; padding: 20px 20px 0 20px; color: #000; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .voucher-box { border: 1px solid #000; padding: 25px; margin: 0 auto; width: 100%; box-sizing: border-box; position: relative; display: flex; flex-direction: column; }
        .row { display: flex; justify-content: space-between; margin-bottom: 22px; align-items: baseline; }
        .label { font-weight: bold; text-transform: uppercase; font-size: 13px; color: #333; min-width: 130px; }
        .value { border-bottom: 1px solid #000; padding: 2px 10px; min-height: 22px; flex-grow: 1; font-size: 16px; font-weight: 600; }
        .amount-box { border: 3px solid #000; padding: 12px 22px; font-weight: bold; font-size: 24px; text-align: right; width: 350px; background: #f9f9f9; }
        .description-box { border: 1px solid #000; padding: 12px; min-height: 70px; margin-bottom: 14px; font-size: 14px; line-height: 1.4; background: #fff; word-wrap: break-word; }
        .status-strip { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 10px;
            background: <?= $status==='approved'?'#d1fae5':($status==='reviewed'?'#dbeafe':'#fef3c7') ?>;
            color:      <?= $status==='approved'?'#065f46':($status==='reviewed'?'#1e40af':'#92400e') ?>; }
        .no-print { display: block; }
        @media print { .no-print { display: none !important; } }
    </style>
    <?php include PRINT_FOOTER_CSS_FILE; PrintHeader::css(); ?>
</head>
<body onload="window.print()">

<div class="no-print" style="margin-bottom:20px;text-align:center;background:#f8f9fa;padding:14px;border-bottom:1px solid #ddd;display:flex;gap:10px;justify-content:center;">
    <button onclick="window.location.href='<?= getUrl('petty_cash') ?>'" style="padding:8px 18px;cursor:pointer;background:#6c757d;color:#fff;border:none;border-radius:5px;font-weight:bold;">
        <i class="bi bi-arrow-left"></i> <?= $isSwahili ? 'RUDI NYUMA' : 'GO BACK' ?>
    </button>
    <button onclick="window.print()" style="padding:8px 18px;cursor:pointer;background:#0d6efd;color:#fff;border:none;border-radius:5px;font-weight:bold;">
        <?= $isSwahili ? 'CHAPA (PRINT)' : 'PRINT NOW' ?>
    </button>
</div>

<?php if ($status !== 'approved'): ?>
<div style="position:fixed;top:35%;left:0;right:0;text-align:center;font-size:110px;font-weight:800;
    color:rgba(255,193,7,0.2);transform:rotate(-30deg);pointer-events:none;z-index:9999;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;"><?= strtoupper($status) ?></div>
<?php endif; ?>

<div class="voucher-box">
    <?php PrintHeader::render($pdo, $isSwahili ? 'VOCHA YA PETTY CASH' : 'PETTY CASH VOUCHER', 'REF #' . $v['voucher_no']); ?>

    <div class="status-strip">Status: <?= strtoupper($status) ?></div>

    <div class="row">
        <div class="col" style="display:flex;width:50%;">
            <span class="label"><?= $isSwahili?'Nambari ya Vocha':'Voucher No' ?>:</span>
            <span class="value"><?= htmlspecialchars($v['voucher_no']) ?></span>
        </div>
        <div class="col" style="display:flex;width:45%;justify-content:flex-end;">
            <span class="label" style="min-width:55px;"><?= $isSwahili?'Tarehe':'Date' ?>:</span>
            <span class="value" style="flex-grow:0;width:140px;text-align:center;"><?= date('d F Y', strtotime($v['transaction_date'])) ?></span>
        </div>
    </div>

    <div class="row">
        <div class="col" style="display:flex;width:100%;">
            <span class="label"><?= $isSwahili?'Jina la Mlipwa':'Payee Name' ?>:</span>
            <span class="value"><?= htmlspecialchars($v['payee_name']) ?></span>
        </div>
    </div>

    <div class="row">
        <div class="col" style="display:flex;width:100%;">
            <span class="label"><?= $isSwahili?'Kategoria':'Category' ?>:</span>
            <span class="value"><?= htmlspecialchars($v['category']) ?></span>
        </div>
    </div>

    <div class="label" style="margin-bottom:5px;"><?= $isSwahili?'MAALIZO YA MATUMIZI:':'DESCRIPTION OF EXPENDITURE:' ?></div>
    <div class="description-box"><?= nl2br(htmlspecialchars($v['description'])) ?></div>

    <div class="row" style="justify-content:flex-end;">
        <div class="amount-box">
            <span style="font-size:13px;font-weight:normal;"><?= $isSwahili?'KIASI:':'AMOUNT:' ?></span>
            TSh <?= number_format($v['amount'], 2) ?>
        </div>
    </div>

    <?php require WORKFLOW_SIGNATURE_ROW_FILE; ?>
</div>

<?php
$printed_by   = $username   ?? '';
$printed_role = $user_role  ?? '';
include PRINT_FOOTER_FILE;
?>
<script>
window.onbeforeprint = function() {
    const now = new Date();
    const ts = String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0')+':'+String(now.getSeconds()).padStart(2,'0');
    const el = document.getElementById('print_time_js');
    if (el) el.innerText = ts;
};
</script>
</body>
</html>
