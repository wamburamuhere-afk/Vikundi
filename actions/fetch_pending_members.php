<?php
// actions/fetch_pending_members.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

// Auth check
$allowed_roles = ['Admin', 'Secretary', 'Katibu', 'Administrator'];
$stmt_check = $pdo->prepare("SELECT r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$stmt_check->execute([$_SESSION['user_id'] ?? 0]);
$user_role_db = $stmt_check->fetchColumn();

if (!in_array($user_role_db, $allowed_roles) && !in_array($_SESSION['user_role'] ?? '', $allowed_roles)) {
    echo json_encode(['error' => 'Access Denied']);
    exit;
}

// DataTables parameters
$draw = intval($_POST['draw'] ?? 1);
$start = intval($_POST['start'] ?? 0);
$length = intval($_POST['length'] ?? 10);
$search = $_POST['search']['value'] ?? '';

// Base query
$query = "
    FROM users u
    JOIN customers c ON u.email = c.email
    WHERE u.status = 'pending'
";

if (!empty($search)) {
    $query .= " AND (c.first_name LIKE :search OR c.middle_name LIKE :search OR c.last_name LIKE :search OR u.username LIKE :search OR u.email LIKE :search OR c.phone LIKE :search)";
}

// Total records
$total_stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$total_records = $total_stmt->fetchColumn();

// Filtered records
$filtered_stmt = $pdo->prepare("SELECT COUNT(*) " . $query);
if (!empty($search)) {
    $filtered_stmt->bindValue(':search', "%$search%");
}
$filtered_stmt->execute();
$filtered_records = $filtered_stmt->fetchColumn();

// Fetch data
$sql = "SELECT u.user_id, u.username, u.email, u.created_at, c.first_name, c.middle_name, c.last_name, c.phone, c.customer_id, c.initial_savings " . $query . " ORDER BY u.created_at DESC LIMIT $start, $length";
$data_stmt = $pdo->prepare($sql);
if (!empty($search)) {
    $data_stmt->bindValue(':search', "%$search%");
}
$data_stmt->execute();
$members = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
$sno = $start + 1;
foreach ($members as $m) {
    // Entrance fee check
    $e_stmt = $pdo->prepare("SELECT amount, evidence_path FROM contributions WHERE member_id = ? AND contribution_type = 'entrance' LIMIT 1");
    $e_stmt->execute([$m['customer_id']]);
    $con = $e_stmt->fetch(PDO::FETCH_ASSOC);
    
    $m['entrance_amount'] = $con['amount'] ?? ($m['initial_savings'] ?? 0);
    $m['evidence_path'] = $con['evidence_path'] ?? null;
    
    $data[] = [
        'sno' => $sno++,
        'date' => date('d/m/Y', strtotime($m['created_at'])),
        'name' => '<strong>' . htmlspecialchars(trim($m['first_name'] . ' ' . $m['middle_name'] . ' ' . $m['last_name'])) . '</strong><br><small class="text-muted">@' . htmlspecialchars($m['username']) . '</small>',
        'contact' => '<div><i class="bi bi-envelope me-1"></i> ' . htmlspecialchars($m['email']) . '</div><div><i class="bi bi-phone me-1"></i> ' . htmlspecialchars($m['phone']) . '</div>',
        'amount' => '<div class="text-end fw-bold text-success">TSh ' . number_format($m['entrance_amount'], 2) . '</div>',
        'slip' => $m['evidence_path'] 
            ? '<a href="' . htmlspecialchars($m['evidence_path']) . '" target="_blank" class="btn btn-sm btn-outline-info rounded-pill px-3"><i class="bi bi-file-earmark-image me-1"></i> ' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tazama Slip' : 'View Slip') . '</a>'
            : '<span class="badge bg-secondary opacity-75 rounded-pill px-3 text-white">' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna Slip' : 'No Slip') . '</span>',
        'action' => '
            <div class="dropdown text-end">
                <button class="btn btn-sm btn-light border dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill text-secondary"></i></button>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                    <li><a class="dropdown-item py-2 text-primary rounded" href="' . getUrl('profile') . '?id=' . $m['user_id'] . '&ref=approvals"><i class="bi bi-eye-fill me-2"></i> ' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tazama Taarifa' : 'View Details') . '</a></li>
                    <li><a class="dropdown-item py-2 text-info rounded" href="' . getUrl('profile') . '?id=' . $m['user_id'] . '&edit=1&ref=approvals"><i class="bi bi-pencil-square me-2"></i> ' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hariri Taarifa' : 'Edit Detail') . '</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-success rounded" href="javascript:void(0)" onclick="approveMember(' . $m['user_id'] . ')"><i class="bi bi-check-circle-fill me-2"></i> ' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mkubali (Approve)' : 'Approve Member') . '</a></li>
                    <li><a class="dropdown-item py-2 text-danger rounded" href="javascript:void(0)" onclick="rejectMember(' . $m['user_id'] . ')"><i class="bi bi-x-circle-fill me-2"></i> ' . (($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mkatae (Reject)' : 'Reject Member') . '</a></li>
                </ul>
            </div>',
        'DT_RowId' => 'row-' . $m['user_id']
    ];
}

echo json_encode([
    'draw' => $draw,
    'recordsTotal' => intval($total_records),
    'recordsFiltered' => intval($filtered_records),
    'data' => $data
]);
