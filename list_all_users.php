<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT user_id, username, phone, first_name, last_name, role_name FROM users u LEFT JOIN roles r ON u.role_id = r.role_id");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
