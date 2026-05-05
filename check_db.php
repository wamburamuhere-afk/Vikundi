<?php
require_once 'c:/wamp64/www/vikundi/includes/config.php';
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Tables in " . DB_NAME . ":\n";
foreach($tables as $table) {
    echo "- $table\n";
    $colsStmt = $pdo->query("DESCRIBE $table");
    $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
    // foreach($cols as $col) { echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n"; }
}
?>
