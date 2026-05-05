<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->query("DESCRIBE customers");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}
?>
