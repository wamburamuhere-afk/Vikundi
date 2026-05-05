<?php
// app/constant/accounts/print_petty_cash.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../helpers.php';

if (!isset($_SESSION['user_id'])) exit('Unauthorized');

$id = $_GET['id'] ?? 0;
$lang = ($_SESSION['preferred_language'] ?? 'en');
$isSwahili = ($lang === 'sw');

// Query with ROLE names for both languages
$stmt = $pdo->prepare("
    SELECT v.*, 
           u1.first_name as prep_fn, u1.last_name as prep_ln, r1.role_name as prep_role_en, r1.role_name_sw as prep_role_sw,
           u2.first_name as appr_fn, u2.last_name as appr_ln, r2.role_name as appr_role_en, r2.role_name_sw as appr_role_sw
    FROM petty_cash_vouchers v
    LEFT JOIN users u1 ON v.prepared_by = u1.user_id
    LEFT JOIN roles r1 ON u1.role_id = r1.role_id
    LEFT JOIN users u2 ON v.approved_by = u2.user_id
    LEFT JOIN roles r2 ON u2.role_id = r2.role_id
    WHERE v.id = ?
");
$stmt->execute([$id]);
$v = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$v) exit('Voucher not found');

// Role and Name preparation
$prep_role = $isSwahili ? ($v['prep_role_sw'] ?: $v['prep_role_en']) : $v['prep_role_en'];
$prepared_by_display = trim(($v['prep_fn'] ?? '') . ' ' . ($v['prep_ln'] ?? '')) . ' (' . ($prep_role ?? 'n/a') . ')';

$approved_by_display = '---';
if($v['status'] === 'approved' && $v['appr_fn']) {
    $appr_role = $isSwahili ? ($v['appr_role_sw'] ?: $v['appr_role_en']) : $v['appr_role_en'];
    $approved_by_display = trim($v['appr_fn'] . ' ' . $v['appr_ln']) . ' (' . ($appr_role ?? 'n/a') . ')';
}

// Get Group Info
$stmt_settings = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings");
$stmt_settings->execute();
$gs = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
$group_name = $gs['group_name'] ?? 'KIKUNDI CHETU';

// Fetch current logged-in user details for the dynamic footer
$stmt_cu = $pdo->prepare("
    SELECT u.username, u.first_name, u.last_name, r.role_name, r.role_name_sw 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    WHERE u.user_id = ?
");
$stmt_cu->execute([$_SESSION['user_id']]);
$cu = $stmt_cu->fetch(PDO::FETCH_ASSOC);

$current_user_display = trim(($cu['first_name'] ?? '') . ' ' . ($cu['last_name'] ?? '')) ?: ($cu['username'] ?? 'User');
$current_user_role = $isSwahili ? ($cu['role_name_sw'] ?: $cu['role_name']) : $cu['role_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Voucher - <?= $v['voucher_no'] ?></title>
    <style>
        @page { margin: 15mm; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; padding: 0; color: #000; -webkit-print-color-adjust: exact; }
        .voucher-box { border: 1px solid #000; padding: 40px; margin: 0 auto; width: 100%; box-sizing: border-box; position: relative; display: flex; flex-direction: column; }
        .header { text-align: center; border-bottom: 3px double #000; padding-bottom: 20px; margin-bottom: 40px; }
        .header h1 { margin: 0; font-size: 32px; text-transform: uppercase; color: #000; }
        .header h2 { margin: 10px 0; font-size: 22px; letter-spacing: 2px; text-decoration: underline; }
        .row { display: flex; justify-content: space-between; margin-bottom: 25px; align-items: baseline; }
        .label { font-weight: bold; text-transform: uppercase; font-size: 15px; color: #333; min-width: 140px; }
        .value { border-bottom: 1px solid #000; padding: 2px 10px; min-height: 25px; flex-grow: 1; font-size: 18px; font-weight: 600; }
        .amount-box { border: 3px solid #000; padding: 15px 25px; font-weight: bold; font-size: 28px; text-align: right; width: 400px; background: #f9f9f9; }
        .voucher-box { border: 1px solid #000; padding: 25px; margin: 0 auto; width: 100%; box-sizing: border-box; position: relative; display: flex; flex-direction: column; }
        .description-box { border: 1px solid #000; padding: 12px; min-height: 80px; margin-bottom: 15px; font-size: 15px; line-height: 1.4; background: #fff; word-wrap: break-word; overflow-wrap: break-word; }
        .footer-signs { display: flex; justify-content: space-between; margin-top: 20px; padding-top: 20px; }
        .sign-col { text-align: center; width: 30%; }
        .sign-line { border-top: 2px solid #000; margin-bottom: 10px; width: 100%; }
        .sign-label { font-weight: bold; text-transform: uppercase; font-size: 13px; margin-bottom: 5px; }
        .sign-name { font-size: 14px; color: #444; }
        
        @media print { 
            .no-print { display: none !important; } 
            .voucher-box { border: 1px solid #000; padding: 15px; }
            body { padding: 0; margin-bottom: 80px; }
            .print-footer { position: fixed; bottom: 0; left: 0; width: 100%; background: #fff; z-index: 1000; }
            .d-print-block { display: block !important; }
        }

        @media print and (orientation: landscape) {
            .voucher-box { padding: 10px; margin-top: 0; }
            .print-header { margin-bottom: 10px; padding-bottom: 10px; }
            .logo-img { max-height: 50px; }
            .group-name { font-size: 18px; }
            .report-title { font-size: 16px; }
            .row { margin-bottom: 8px; }
            .label { font-size: 13px; min-width: 110px; }
            .value { font-size: 15px; min-height: 20px; }
            .amount-box { padding: 8px 15px; font-size: 22px; width: 300px; }
            .description-box { min-height: 50px; font-size: 14px; margin-bottom: 10px; }
            .footer-signs { margin-top: 10px; padding-top: 10px; }
            .sign-label { font-size: 11px; }
            .sign-name { font-size: 12px; }
        }

        .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 15px; }
        .logo-img { max-height: 70px; margin-bottom: 5px; }
        .group-name { color: #0d6efd; text-transform: uppercase; font-weight: 800; font-size: 22px; margin-bottom: 2px; }
        .report-title { color: #000; text-transform: uppercase; font-weight: 900; font-size: 18px; display: inline-block; }
        
        .print-footer { border-top: 1px solid #dee2e6; padding: 10px 0; font-size: 11px; color: #6c757d; }
        .powered-by { color: #0d6efd !important; font-weight: bold; }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 30px; text-align: center; background: #f8f9fa; padding: 15px; border-bottom: 1px solid #ddd;">
        <button onclick="window.location.href='<?= getUrl('petty_cash') ?>'" style="padding: 10px 20px; cursor: pointer; background: #6c757d; color: #fff; border: none; border-radius: 5px; font-weight: bold; margin-right: 10px;">
            <i class="bi bi-arrow-left"></i> <?= $isSwahili ? 'RUDI NYUMA' : 'GO BACK' ?>
        </button>
        <button onclick="doPrint()" style="padding: 10px 20px; cursor: pointer; background: #0d6efd; color: #fff; border: none; border-radius: 5px; font-weight: bold;">
            <?= $isSwahili ? 'CHAPA (PRINT)' : 'PRINT NOW' ?>
        </button>
    </div>

    <div class="voucher-box">
        <!-- New Professional Print Header -->
        <div class="print-header">
            <?php 
                $logo_filename = $gs['group_logo'] ?? 'logo1.png';
                $logo_url = getUrl('assets/images/' . $logo_filename);
            ?>
            <img src="<?= $logo_url ?>" class="logo-img" alt="Logo">
            <div class="group-name"><?= htmlspecialchars($group_name) ?></div>
            <div class="report-title">PETTY CASH VOUCHER</div>
        </div>

        <div class="row">
            <div class="col" style="display:flex; width: 50%;">
                <span class="label">Voucher No:</span>
                <span class="value"><?= $v['voucher_no'] ?></span>
            </div>
            <div class="col" style="display:flex; width: 45%; justify-content: flex-end;">
                <span class="label" style="min-width: 60px;">Date:</span>
                <span class="value" style="flex-grow:0; width: 150px; text-align:center;"><?= date('d F Y', strtotime($v['transaction_date'])) ?></span>
            </div>
        </div>

        <div class="row">
            <div class="col" style="display:flex; width: 100%;">
                <span class="label">Payee Name:</span>
                <span class="value" id="payeeField"><?= htmlspecialchars($v['payee_name']) ?></span>
            </div>
        </div>

        <div class="row">
            <div class="col" style="display:flex; width: 100%;">
                <span class="label">Category:</span>
                <span class="value"><?= htmlspecialchars($v['category']) ?></span>
            </div>
        </div>

        <div class="label" style="margin-bottom: 5px;"><?= $isSwahili ? 'MAALIZO YA MATUMIZI:' : 'DESCRIPTION OF EXPENDITURE:' ?></div>
        <div class="description-box">
            <?= nl2br(htmlspecialchars($v['description'])) ?>
        </div>

        <div class="row" style="justify-content: flex-end;">
            <div class="amount-box">
                <span style="font-size: 14px; font-weight: normal;"><?= $isSwahili ? 'KIASI:' : 'AMOUNT:' ?></span>
                TSh <?= number_format($v['amount'], 2) ?>
            </div>
        </div>

        <div class="footer-signs">
            <div class="sign-col">
                <div class="sign-line"></div>
                <div class="sign-label"><?= $isSwahili ? 'Sahihi ya Mpokeaji' : 'Receiver Sign' ?></div>
                <div class="sign-name"><?= htmlspecialchars($v['payee_name']) ?></div>
            </div>
            <div class="sign-col">
                <div class="sign-line"></div>
                <div class="sign-label"><?= $isSwahili ? 'Iliyoandaliwa Na' : 'Prepared By' ?></div>
                <div class="sign-name"><?= htmlspecialchars($prepared_by_display) ?></div>
            </div>
            <div class="sign-col">
                <div class="sign-line"></div>
                <div class="sign-label"><?= $isSwahili ? 'Iliyoidhinishwa Na' : 'Approved By' ?></div>
                <div class="sign-name" style="font-weight: bold;"><?= htmlspecialchars($approved_by_display) ?></div>
            </div>
        </div>
    </div>

    <!-- Print Footer (Visible only when printing) -->
    <div class="d-none d-print-block w-100 mt-5 print-footer">
        <div class="border-top pt-3" style="border-top: 1px solid #dee2e6 !important; text-align: center;">
            <p class="mb-1">
                <?= $isSwahili ? 'Nyaraka hii imechapishwa na' : 'This document was Printed by' ?> 
                <strong><?= htmlspecialchars($current_user_display) ?></strong> - 
                <span class="text-uppercase"><?= htmlspecialchars($current_user_role ?? 'Member') ?></span> 
                <?= $isSwahili ? 'mnamo' : 'on' ?> 
                <?= date('d M, Y') ?> <?= $isSwahili ? 'saa' : 'at' ?> <span id="print_time_js"><?= date('H:i:s') ?></span>
            </p>
            <p class="mb-0 powered-by">Powered By BJP Technologies @ 2026, All Rights Reserved</p>
        </div>
    </div>
    <script>
        window.onbeforeprint = function() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            const timeStr = `${h}:${m}:${s}`;
            const timeSpan = document.getElementById('print_time_js');
            if (timeSpan) timeSpan.innerText = timeStr;
        };

        function doPrint() {
            window.print();
        }
    </script>
</body>
</html>
