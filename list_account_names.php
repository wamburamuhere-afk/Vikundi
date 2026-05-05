<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->query("SELECT account_name FROM accounts");
$names = $stmt->fetchAll(PDO::FETCH_COLUMN);
print_r($names);
?>
