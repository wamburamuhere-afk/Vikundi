<?php
require_once __DIR__ . '/includes/config.php';
global $pdo;

try {
    // 1. Add columns to death_expenses if they don't exist
    $stmt = $pdo->query("DESCRIBE death_expenses");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('status', $cols)) {
        $pdo->exec("ALTER TABLE death_expenses ADD COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' AFTER description");
    }
    if (!in_array('deceased_relationship', $cols)) {
        $pdo->exec("ALTER TABLE death_expenses ADD COLUMN deceased_relationship VARCHAR(50) DEFAULT NULL AFTER deceased_name");
    }
    if (!in_array('approved_by', $cols)) {
        $pdo->exec("ALTER TABLE death_expenses ADD COLUMN approved_by INT DEFAULT NULL");
    }
    if (!in_array('approved_at', $cols)) {
        $pdo->exec("ALTER TABLE death_expenses ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL");
    }
    if (!in_array('phone_number', $cols)) {
        $pdo->exec("ALTER TABLE death_expenses ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER member_id");
    }

    // 2. Create simplified general_expenses table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS general_expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        description TEXT NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        approved_at TIMESTAMP NULL DEFAULT NULL,
        approved_by INT
    )");

    // 3. Ensure we have a Group Balance setting
    $stmt = $pdo->prepare("INSERT IGNORE INTO group_settings (setting_key, setting_value) VALUES ('group_balance', '0')");
    $stmt->execute();

    echo "Database migration successful.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
