<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->query("SELECT * FROM accounts WHERE account_name LIKE '%cash%' OR account_name LIKE '%bank%'");
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($accounts);
?>
