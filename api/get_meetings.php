<?php
// api/get_meetings.php — server-side list for the Meetings DataTable + stats.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_auth.php'; // audit B3: must be logged in
global $pdo;

header('Content-Type: application/json');

$draw   = (int) ($_GET['draw'] ?? 1);
$start  = max(0, (int) ($_GET['start'] ?? 0));
$length = (int) ($_GET['length'] ?? 10);
if ($length <= 0) { $length = 10; }

$status = $_GET['status'] ?? '';
$type   = $_GET['type'] ?? '';
$from   = $_GET['date_from'] ?? '';
$to     = $_GET['date_to'] ?? '';

try {
    $where = "WHERE 1=1";
    $params = [];
    if (in_array($status, ['scheduled', 'held', 'cancelled'], true)) { $where .= " AND m.status = :st"; $params['st'] = $status; }
    if (in_array($type, ['regular', 'special', 'agm'], true))        { $where .= " AND m.meeting_type = :ty"; $params['ty'] = $type; }
    if ($from) { $where .= " AND m.meeting_date >= :df"; $params['df'] = $from; }
    if ($to)   { $where .= " AND m.meeting_date <= :dt"; $params['dt'] = $to; }

    $recordsTotal = (int) $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn();

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM meetings m $where");
    $cnt->execute($params);
    $recordsFiltered = (int) $cnt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT m.*,
               (SELECT COUNT(*) FROM meeting_attendance a WHERE a.meeting_id = m.id AND a.status = 'present') AS present_count
          FROM meetings m
          $where
         ORDER BY m.meeting_date DESC, m.id DESC
         LIMIT $start, $length
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total     = $recordsTotal;
    $held      = (int) $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'held'")->fetchColumn();
    $scheduled = (int) $pdo->query("SELECT COUNT(*) FROM meetings WHERE status = 'scheduled'")->fetchColumn();
    $month     = (int) $pdo->query("SELECT COUNT(*) FROM meetings WHERE MONTH(meeting_date) = MONTH(CURRENT_DATE) AND YEAR(meeting_date) = YEAR(CURRENT_DATE)")->fetchColumn();

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
        'statTotal' => $total,
        'statHeld' => $held,
        'statScheduled' => $scheduled,
        'statMonth' => $month,
    ]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
