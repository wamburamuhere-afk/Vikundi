<?php
require_once __DIR__ . '/includes/config.php';
$stmt = $pdo->query("SELECT * FROM banks");
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($banks);
?>
