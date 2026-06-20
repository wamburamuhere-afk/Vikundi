<?php
/**
 * Member search for the Death Expenses FILTER (comms/accounts > Death Expenses).
 *
 * Unlike api/search_members_with_phone.php (which powers the "Record New Death"
 * modal and deliberately excludes members who already have a death record),
 * this endpoint searches exactly the members who DO appear in the
 * death_expenses log — so every option the user picks returns matching rows.
 * Returns Select2 format: { results: [{ id: member_id, text }] }.
 */
require_once __DIR__ . '/../includes/config.php';
global $pdo;

header('Content-Type: application/json');

$q = $_GET['q'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT c.customer_id,
               TRIM(CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,''))) AS name,
               c.phone,
               COUNT(d.id) AS records
        FROM death_expenses d
        JOIN customers c ON d.member_id = c.customer_id
        WHERE (c.first_name LIKE :q OR c.last_name LIKE :q OR c.phone LIKE :q
               OR CONCAT(COALESCE(c.first_name,''),' ',COALESCE(c.last_name,'')) LIKE :q)
        GROUP BY c.customer_id, name, c.phone
        ORDER BY c.first_name, c.last_name
        LIMIT 25
    ");
    $stmt->execute(['q' => "%$q%"]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(function ($r) {
        $label = $r['name'] !== '' ? $r['name'] : ('Member #' . $r['customer_id']);
        if (!empty($r['phone'])) $label .= ' (' . $r['phone'] . ')';
        $label .= ' — ' . (int)$r['records'] . ' rec';
        return ['id' => (int)$r['customer_id'], 'text' => $label];
    }, $rows);

    echo json_encode(['results' => $results]);
} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => $e->getMessage()]);
}
?>
