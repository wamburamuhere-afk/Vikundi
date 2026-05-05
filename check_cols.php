<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE customers");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('termination_date', $cols)) {
    $pdo->exec("ALTER TABLE customers ADD COLUMN termination_date DATETIME DEFAULT NULL");
    echo "Added termination_date\n";
}
if (!in_array('termination_reason', $cols)) {
    $pdo->exec("ALTER TABLE customers ADD COLUMN termination_reason VARCHAR(255) DEFAULT NULL");
    echo "Added termination_reason\n";
}
echo "Check complete.";
?>
