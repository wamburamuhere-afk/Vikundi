<?php
require_once 'includes/config.php';
$u = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$c = $pdo->query("SELECT COUNT(*) FROM customers WHERE status != 'terminated'")->fetchColumn();
echo "Users in system: $u\n";
echo "Active Members in customers: $c\n";
?>
