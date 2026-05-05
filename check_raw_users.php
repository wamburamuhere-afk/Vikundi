<?php
require_once 'includes/config.php';
$stmt = $pdo->query("SELECT user_id, username, first_name, last_name, last_login FROM users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
