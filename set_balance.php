<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->prepare("UPDATE group_settings SET setting_value = '1000000' WHERE setting_key = 'group_balance'");
$stmt->execute();
echo "Balance set to 1,000,000.";
?>
