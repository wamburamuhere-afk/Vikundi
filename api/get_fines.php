<?php
// api/get_fines.php — server-side list for the leadership Fines page.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../includes/fine_helpers.php';
global $pdo;

header('Content-Type: application/json');

// audit H3 — only those allowed to view all members' fines.
if (!isAdmin() && !canView('manage_fines')) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

$draw   = (int) ($_GET['draw'] ?? 1);
$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 10);
if ($length <= 0) { $length = 10; }

$status    = $_GET['status'] ?? '';
$member_id = ctype_digit((string) ($_GET['member_id'] ?? '')) ? (int) $_GET['member_id'] : 0;

try {
    $where = "WHERE 1=1";
    $params = [];
    if (in_array($status, vk_fine_statuses(), true)) { $where .= " AND f.status = :st"; $params['st'] = $status; }
    if ($member_id > 0) { $where .= " AND f.customer_id = :mid"; $params['mid'] = $member_id; }

    $recordsTotal = (int) $pdo->query("SELECT COUNT(*) FROM fines")->fetchColumn();

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM fines f $where");
    $cnt->execute($params);
    $recordsFiltered = (int) $cnt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT f.*,
               TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS member_name,
               c.phone AS member_phone,
               m.title AS meeting_title
          FROM fines f
          LEFT JOIN customers c ON f.customer_id = c.customer_id
          LEFT JOIN meetings  m ON f.meeting_id  = m.id
          $where
         ORDER BY f.created_at DESC, f.fine_id DESC
         LIMIT $start, $length
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Totals across the whole table (money owed / collected / forgiven).
    $sum = fn($st) => (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM fines WHERE status = '$st'")->fetchColumn();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
        'totalPending' => $sum('pending'),
        'totalPaid' => $sum('paid'),
        'totalWaived' => $sum('waived'),
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
