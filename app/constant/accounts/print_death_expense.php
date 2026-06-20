<?php
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../../../roots.php';

if (!isAuthenticated()) die('Unauthorized');
requireViewPermission('death_expenses');

$id = intval($_GET['id'] ?? 0);
if (!$id) die('Invalid ID');

global $pdo;

$stmt = $pdo->prepare("
    SELECT de.*,
           c.customer_name, c.first_name, c.last_name, c.phone,
           TRIM(CONCAT_WS(' ', uc.first_name, uc.middle_name, uc.last_name)) AS creator_name,
           uc.username AS creator_username, rc.role_name AS creator_role,
           TRIM(CONCAT_WS(' ', ur.first_name, ur.middle_name, ur.last_name)) AS reviewer_name,
           rr.role_name AS reviewer_role,
           TRIM(CONCAT_WS(' ', ua.first_name, ua.middle_name, ua.last_name)) AS approver_name,
           ra.role_name AS approver_role
      FROM death_expenses de
      JOIN customers c   ON de.member_id   = c.customer_id
      LEFT JOIN users uc ON de.created_by  = uc.user_id
      LEFT JOIN roles rc ON uc.role_id     = rc.role_id
      LEFT JOIN users ur ON de.reviewed_by = ur.user_id
      LEFT JOIN roles rr ON ur.role_id     = rr.role_id
      LEFT JOIN users ua ON de.approved_by = ua.user_id
      LEFT JOIN roles ra ON ua.role_id     = ra.role_id
     WHERE de.id = ?
");
$stmt->execute([$id]);
$de = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$de) die('Not found');

$settings   = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$currency   = $settings['currency']   ?? 'TZS';
$group_name = $settings['group_name'] ?? 'VIKUNDI';
$status     = $de['status'] ?? 'pending';

$creator_name  = trim($de['creator_name'])  ?: ($de['creator_username'] ?? '');
$reviewer_name = trim($de['reviewer_name']) ?: '';
$approver_name = trim($de['approver_name']) ?: '';
$member_name   = $de['customer_name'] ?: trim($de['first_name'].' '.$de['last_name']);

$wf_sigs = getWorkflowSignatures($pdo, 'death_expense', $id);
$wf = [
    'created_by_name'    => $creator_name,
    'created_by_role'    => $de['creator_role']   ?? '',
    'reviewed_by_name'   => $reviewer_name,
    'reviewed_by_role'   => $de['reviewer_role']  ?? '',
    'approved_by_name'   => $approver_name,
    'approved_by_role'   => $de['approver_role']  ?? '',
    'created_sig_path'   => $wf_sigs['created']['sig_path']    ?? null,
    'created_signed_at'  => $wf_sigs['created']['signed_at']   ?? null,
    'reviewed_sig_path'  => $wf_sigs['reviewed']['sig_path']   ?? null,
    'reviewed_signed_at' => $wf_sigs['reviewed']['signed_at']  ?? null,
    'approved_sig_path'  => $wf_sigs['approved']['sig_path']   ?? null,
    'approved_signed_at' => $wf_sigs['approved']['signed_at']  ?? null,
    '__include_css'      => true,
];

logActivity('Viewed', 'Death Expenses', 'Printed Death Expense #' . $id, 'DE#' . $id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Death Expense #<?= $id ?> — <?= htmlspecialchars($group_name) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #1a252f; margin: 0; padding: 16px 22px; }
    .status-strip { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 12px;
        background: <?= $status==='approved'?'#d1fae5':($status==='reviewed'?'#dbeafe':'#fef3c7') ?>;
        color:      <?= $status==='approved'?'#065f46':($status==='reviewed'?'#1e40af':'#92400e') ?>; }
    .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
    .detail-card { border: 1px solid #e0e0e0; border-radius: 6px; padding: 9px 12px; }
    .detail-card .dc-label { font-size: 9.5px; text-transform: uppercase; color: #6c757d; font-weight: 700; margin-bottom: 2px; }
    .detail-card .dc-val   { font-size: 12px; font-weight: 600; color: #1a252f; }
    .amount-highlight { background: #fff5f5; border-left: 4px solid #dc3545; padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
    .amount-highlight .amt-label { font-size: 10px; text-transform: uppercase; color: #6c757d; font-weight: 700; }
    .amount-highlight .amt-val   { font-size: 22px; font-weight: 800; color: #dc3545; }
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

<?php PrintHeader::render($pdo, 'DEATH BENEFIT EXPENSE', 'REF #DE-' . $de['id']); ?>

<div class="status-strip">Status: <?= strtoupper($status) ?></div>

<div class="amount-highlight">
    <div>
        <div class="amt-label">Death Benefit Amount</div>
        <div style="font-size:11px;color:#6c757d;margin-top:2px;"><?= htmlspecialchars($member_name) ?> &mdash; <?= date('d M Y', strtotime($de['expense_date'])) ?></div>
    </div>
    <div class="amt-val"><?= $currency ?> <?= number_format($de['amount'], 2) ?></div>
</div>

<div class="detail-grid">
    <div class="detail-card">
        <div class="dc-label">Member</div>
        <div class="dc-val"><?= htmlspecialchars($member_name) ?></div>
    </div>
    <div class="detail-card">
        <div class="dc-label">Date</div>
        <div class="dc-val"><?= date('d M Y', strtotime($de['expense_date'])) ?></div>
    </div>
    <div class="detail-card">
        <div class="dc-label">Deceased Name</div>
        <div class="dc-val"><?= safe_output($de['deceased_name']) ?></div>
    </div>
    <div class="detail-card">
        <div class="dc-label">Relationship</div>
        <div class="dc-val"><?= ucfirst($de['deceased_type']) ?></div>
    </div>
    <?php if (!empty($de['description'])): ?>
    <div class="detail-card" style="grid-column:1/-1;">
        <div class="dc-label">Description</div>
        <div class="dc-val"><?= htmlspecialchars($de['description']) ?></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($status !== 'approved'): ?>
<div class="three-approval-watermark"><?= strtoupper($status) ?></div>
<style>
.three-approval-watermark {
    position: fixed; top:35%; left:0; right:0;
    text-align:center; font-size:120px; font-weight:800;
    color:rgba(220,53,69,0.18); transform:rotate(-30deg);
    pointer-events:none; z-index:9999; letter-spacing:4px;
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
</style>
<?php endif; ?>

<?php require WORKFLOW_SIGNATURE_ROW_FILE; ?>

<?php
$printed_by   = $username ?? '';
$printed_role = $user_role ?? '';
include PRINT_FOOTER_FILE;
?>
</body>
</html>
