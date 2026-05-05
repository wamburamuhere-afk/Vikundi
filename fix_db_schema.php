<?php
define('ROOT_DIR', 'c:/wamp64/www/vikundi');
require_once ROOT_DIR . '/includes/config.php';
try {
    // Add updated_at if missing
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    echo "Column 'updated_at' ensured in users table.\n";
    
    // Add avatar if missing (just in case)
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(255) DEFAULT NULL");
    echo "Column 'avatar' ensured in users table.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
