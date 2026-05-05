<?php
// actions/fetch_petty_cash.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// DataTables parameters
$draw = intval($_POST['draw'] ?? 1);
$start = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 10);
$search = $_POST['search']['value'] ?? '';
$from_date = $_POST['from_date'] ?? '';
$to_date = $_POST['to_date'] ?? '';
$status = $_POST['status'] ?? '';
$category = $_POST['category'] ?? '';

// Base query
$query = " FROM petty_cash_vouchers v LEFT JOIN users u ON v.prepared_by = u.user_id WHERE 1=1";

if (!empty($search)) {
    $query .= " AND (v.voucher_no LIKE :search OR v.payee_name LIKE :search OR v.description LIKE :search OR v.category LIKE :search)";
}
if (!empty($from_date)) {
    $query .= " AND v.transaction_date >= :from_date";
}
if (!empty($to_date)) {
    $query .= " AND v.transaction_date <= :to_date";
}
if (!empty($status)) {
    $query .= " AND v.status = :status";
}
if (!empty($category)) {
    $query .= " AND v.category = :category";
}

// Total records
$total_stmt = $pdo->query("SELECT COUNT(*) FROM petty_cash_vouchers");
$total_records = $total_stmt->fetchColumn();

// Filtered records
$filtered_stmt = $pdo->prepare("SELECT COUNT(*) " . $query);
if (!empty($search)) $filtered_stmt->bindValue(':search', "%$search%");
if (!empty($from_date)) $filtered_stmt->bindValue(':from_date', $from_date);
if (!empty($to_date)) $filtered_stmt->bindValue(':to_date', $to_date);
if (!empty($status)) $filtered_stmt->bindValue(':status', $status);
if (!empty($category)) $filtered_stmt->bindValue(':category', $category);
$filtered_stmt->execute();
$filtered_records = $filtered_stmt->fetchColumn();

// Fetch data
$sql = "SELECT v.*, u.username as prepared_by_name " . $query . " ORDER BY v.transaction_date DESC, v.id DESC LIMIT $start, $length";
$data_stmt = $pdo->prepare($sql);
if (!empty($search)) $data_stmt->bindValue(':search', "%$search%");
if (!empty($from_date)) $data_stmt->bindValue(':from_date', $from_date);
if (!empty($to_date)) $data_stmt->bindValue(':to_date', $to_date);
if (!empty($status)) $data_stmt->bindValue(':status', $status);
if (!empty($category)) $data_stmt->bindValue(':category', $category);
$data_stmt->execute();
$vouchers = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
$sno = $start + 1;
foreach ($vouchers as $v) {
    // Status Badge
    $status_badge = '<span class="badge bg-' . get_status_badge($v['status']) . ' rounded-pill px-3">' . ucfirst($v['status']) . '</span>';
    
    // Actions Standard Dropdown
    $actions = '
    <div class="dropdown text-end">
        <button class="btn btn-sm btn-light border dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-gear-fill text-secondary"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="min-width: 160px;">
            <li>
                <a class="dropdown-item py-2 text-primary rounded" href="javascript:void(0)" onclick="viewVoucher(' . $v['id'] . ')">
                    <i class="bi bi-eye-fill me-2"></i> ' . ($isSwahili ? 'Tazama Taarifa' : 'View Details') . '
                </a>
            </li>';
    
    if($v['status'] === 'pending') {
        $actions .= '
            <li>
                <a class="dropdown-item py-2 text-info rounded" href="javascript:void(0)" onclick="editVoucher(' . $v['id'] . ')">
                    <i class="bi bi-pencil-square me-2"></i> ' . ($isSwahili ? 'Hariri Vocha' : 'Edit Voucher') . '
                </a>
            </li>
            <li>
                <a class="dropdown-item py-2 text-success rounded" href="javascript:void(0)" onclick="approveVoucher(' . $v['id'] . ')">
                    <i class="bi bi-check-circle-fill me-2"></i> ' . ($isSwahili ? 'Idhinisha' : 'Approve') . '
                </a>
            </li>';
    }

    $actions .= '
            <li>
                <a class="dropdown-item py-2 text-secondary rounded" href="javascript:void(0)" onclick="printVoucher(' . $v['id'] . ')">
                    <i class="bi bi-printer-fill me-2"></i> ' . ($isSwahili ? 'Chapa (Print)' : 'Print') . '
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item py-2 text-danger rounded" href="javascript:void(0)" onclick="deleteVoucher(' . $v['id'] . ')">
                    <i class="bi bi-trash3-fill me-2"></i> ' . ($isSwahili ? 'Futa Vocha' : 'Delete Voucher') . '
                </a>
            </li>
        </ul>
    </div>';

    $data[] = [
        'sno'         => $sno++,
        'voucher_no'  => '<code>' . htmlspecialchars($v['voucher_no']) . '</code>',
        'date'        => date('d/m/Y', strtotime($v['transaction_date'])),
        'payee'       => '<strong>' . htmlspecialchars($v['payee_name']) . '</strong><br><small class="text-muted">' . htmlspecialchars($v['category']) . '</small>',
        'description' => '<div class="small text-wrap" style="max-width: 250px;">' . htmlspecialchars($v['description']) . '</div>',
        'amount'      => '<div class="text-center fw-bold">' . number_format($v['amount'], 2) . '</div>',
        'status'      => $status_badge,
        'action'      => $actions,
        // Raw fields consumed by the mobile card view (drawCallback)
        'raw_id'          => $v['id'],
        'raw_voucher_no'  => $v['voucher_no'],
        'raw_payee'       => $v['payee_name'],
        'raw_category'    => $v['category'],
        'raw_description' => $v['description'],
        'raw_amount'      => $v['amount'],
        'raw_status'      => $v['status'],
    ];
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => intval($total_records),
    'recordsFiltered' => intval($filtered_records),
    'data' => $data
]);
?>
