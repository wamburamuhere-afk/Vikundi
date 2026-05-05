<?php
$pdo = new PDO('mysql:host=localhost', 'root', '');
$stmt = $pdo->query('SHOW DATABASES');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
?>
