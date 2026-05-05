<?php
require_once 'includes/config.php';
$table = $argv[1] ?? 'documents';
$stmt = $pdo->query("DESCRIBE $table");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . PHP_EOL;
}
