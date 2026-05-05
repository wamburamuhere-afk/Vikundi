<?php
require_once 'includes/config.php';
$stmt = $pdo->query("DESCRIBE customers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
