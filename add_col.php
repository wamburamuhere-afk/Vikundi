<?php
require_once 'includes/config.php';
try {
    $pdo->exec("ALTER TABLE documents ADD COLUMN is_template TINYINT(1) DEFAULT 0");
    echo "Column added successfully";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
