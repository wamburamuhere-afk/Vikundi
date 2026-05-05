<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->query("SELECT * FROM accounts LIMIT 10");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts);
?>
