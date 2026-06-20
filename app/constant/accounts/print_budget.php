<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) die('Unauthorized');

$id = intval($_GET['id'] ?? 0);
if (!$id) die('Invalid ID');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

global $pdo;

$stmt = $pdo->prepare("
    SELECT b.*, ec.category_name,
           COALESCE(ec.category_name, b.budget_name) as display_name,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username, rc.role_name AS creator_role,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM budgets b
      LEFT JOIN expense_categories ec ON b.category_id = ec.category_id
      LEFT JOIN users uc ON b.created_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id     = rc.role_id
      LEFT JOIN users ur ON b.reviewed_by  = ur.user_id
      LEFT JOIN roles rr ON ur.role_id     = rr.role_id
      LEFT JOIN users ua ON b.approved_by  = ua.user_id
      LEFT JOIN roles ra ON ua.role_id     = ra.role_id
     WHERE b.budget_id = ?
");
$stmt->execute([$id]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$budget) die('Budget not found');

$items_stmt = $pdo->prepare("SELECT * FROM budget_items WHERE budget_id = ? ORDER BY item_id ASC");
$items_stmt->execute([$id]);
$budget_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$gs = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$group_name = $gs['group_name'] ?? 'VIKUNDI';
$status     = $budget['status'] ?? 'pending';

$creator_name  = trim($budget['creator_name'])  ?: ($budget['creator_username'] ?? '');
$reviewer_name = trim($budget['reviewer_name']) ?: '';
$approver_name = trim($budget['approver_name']) ?: '';

$wf_sigs = getWorkflowSignatures($pdo, 'budget', $id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $budget['creator_role']   ?? '',
    'reviewed_by_name'   => $reviewer_name,
    'reviewed_by_role'   => $budget['reviewer_role']  ?? '',
    'approved_by_name'   => $approver_name,
    'approved_by_role'   => $budget['approver_role']  ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']    ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']   ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']   ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at']  ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']   ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at']  ?? ($budget['approved_at'] ?? null),
    '__include_css'      => true,
];

$period = date('F Y', mktime(0, 0, 0, $budget['budget_month'], 1, $budget['budget_year']));

logActivity('Viewed', 'Budget', 'Printed Budget #' . $id, 'BUDGET#' . $id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Budget — <?= htmlspecialchars($budget['display_name'] ?? 'Budget') ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a252f; margin: 0; padding: 16px 22px; }
    .status-strip { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 10px;
        background: <?= $status==='approved'?'#d1fae5':($status==='reviewed'?'#dbeafe':'#fef3c7') ?>;
        color:      <?= $status==='approved'?'#065f46':($status==='reviewed'?'#1e40af':'#92400e') ?>; }
    .meta-row { display: flex; gap: 20px; margin-bottom: 12px; font-size: 11px; }
    .meta-item .lbl { color: #6c757d; font-weight: 700; text-transform: uppercase; font-size: 9px; margin-bottom: 1px; }
    .meta-item .val { font-weight: 600; }
    table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.items th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; padding: 6px 8px; text-align: left; border-bottom: 2px solid #e2e8f0; }
    table.items td { padding: 6px 8px; border-bottom: 1px solid #e9ecef; font-size: 11px; }
    table.items .text-end { text-align: right; }
    table.items .text-center { text-align: center; }
    table.items tfoot td { font-weight: 800; background: #f8f9fa; font-size: 12px; border-top: 2px solid #0d6efd; }
    .notes-box { background: #f8f9fa; border-left: 4px solid #0d6efd; padding: 8px 12px; border-radius: 4px; font-size: 11px; margin-bottom: 14px; }
    .no-print { display: block; }
    @media print { .no-print { display: none !important; } }
</style>
<?php include PRINT_FOOTER_CSS_FILE; PrintHeader::css(); ?>
</head>
<body onload="window.print()">

<div class="no-print" style="margin-bottom:16px;display:flex;gap:8px;">
    <button onclick="window.print()" style="padding:6px 16px;cursor:pointer;font-weight:600;background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;">Print</button>
    <button onclick="window.close()" style="padding:6px 16px;cursor:pointer;background:#fff;border:1px solid #dee2e6;border-radius:4px;">Close</button>
</div>

<?php if ($status !== 'approved'): ?>
<div style="position:fixed;top:35%;left:0;right:0;text-align:center;font-size:110px;font-weight:800;
    color:rgba(13,110,253,0.12);transform:rotate(-30deg);pointer-events:none;z-index:9999;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;"><?= strtoupper($status) ?></div>
<?php endif; ?>

<?php PrintHeader::render($pdo, $is_sw ? 'RIPOTI YA BAJETI' : 'BUDGET REPORT', 'BUDGET #' . $id); ?>

<div class="status-strip">Status: <?= strtoupper($status) ?></div>

<div class="meta-row">
    <div class="meta-item">
        <div class="lbl"><?= $is_sw?'Jina la Bajeti':'Budget Name' ?></div>
        <div class="val"><?= htmlspecialchars($budget['display_name'] ?? '') ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl"><?= $is_sw?'Kipindi':'Period' ?></div>
        <div class="val"><?= $period ?></div>
    </div>
    <div class="meta-item">
        <div class="lbl"><?= $is_sw?'Kiasi Kilichotengwa':'Allocated Amount' ?></div>
        <div class="val">TZS <?= number_format($budget['allocated_amount'] ?? 0, 2) ?></div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width:35px;">#</th>
            <th><?= $is_sw?'Maelezo':'Description' ?></th>
            <th class="text-center" style="width:70px;"><?= $is_sw?'Vipimo':'Units' ?></th>
            <th class="text-center" style="width:55px;"><?= $is_sw?'Idadi':'Qty' ?></th>
            <th class="text-end" style="width:100px;"><?= $is_sw?'Bei':'Price' ?></th>
            <th class="text-end" style="width:110px;"><?= $is_sw?'Jumla':'Total' ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($budget_items as $i => $item): ?>
        <tr>
            <td class="text-center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($item['description']) ?></td>
            <td class="text-center"><?= htmlspecialchars($item['units'] ?: '—') ?></td>
            <td class="text-center"><?= number_format($item['qty'], 0) ?></td>
            <td class="text-end"><?= number_format($item['price_per_item'], 2) ?></td>
            <td class="text-end"><?= number_format($item['total_amount'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="text-end pe-2"><?= $is_sw?'JUMLA KUU':'GRAND TOTAL' ?></td>
            <td class="text-end" style="color:#0d6efd;">TZS <?= number_format($budget['allocated_amount'] ?? 0, 2) ?></td>
        </tr>
    </tfoot>
</table>

<?php if (!empty($budget['notes'])): ?>
<div class="notes-box">
    <strong><?= $is_sw?'MAONI:':'NOTES:' ?></strong> <?= nl2br(htmlspecialchars($budget['notes'])) ?>
</div>
<?php endif; ?>

<?php require WORKFLOW_SIGNATURE_ROW_FILE; ?>

<?php
$printed_by   = $username  ?? '';
$printed_role = $user_role ?? '';
include PRINT_FOOTER_FILE;
?>
</body>
</html>
