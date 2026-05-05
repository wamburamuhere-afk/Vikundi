<?php
require_once 'includes/config.php';

echo "STARTING TWO-WAY SYNC...\n";

// 1. SYNC CUSTOMERS -> USERS (Ensure every member can login)
$customers = $pdo->query("SELECT * FROM customers")->fetchAll(PDO::FETCH_ASSOC);
$member_role_id = 15; // From previous check, 'Member (Mwanachama)' is 15

foreach ($customers as $c) {
    $phone = trim($c['phone']);
    $check = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
    $check->execute([$phone]);
    if (!$check->fetch()) {
        // Create User
        $first_initial = strtolower(substr(trim($c['first_name']), 0, 1));
        $last_name_slug = strtolower(preg_replace('/\s+/', '', trim($c['last_name'])));
        $username = $first_initial . $last_name_slug;
        $base_username = $username;
        $stmt_un = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt_un->execute([$username]);
        $i = 1;
        while ($stmt_un->fetchColumn() > 0) {
            $username = $base_username . $i++;
            $stmt_un->execute([$username]);
        }

        $hashed_password = password_hash('123456', PASSWORD_DEFAULT); // Default password
        $ins = $pdo->prepare("
            INSERT INTO users (first_name, middle_name, last_name, phone, email, username, password, role_id, user_role, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $c['first_name'], $c['middle_name'], $c['last_name'], $phone, $c['email'], 
            $username, $hashed_password, $member_role_id, 'Member', 'active'
        ]);
        echo "CREATED USER: $username for MEMBER: {$c['first_name']} {$c['last_name']}\n";
    }
}

// 2. SYNC USERS -> CUSTOMERS (Ensure every user with 'Member' role has a record)
// We check users with role_id=15 or role_name LIKE '%Member%'
$users = $pdo->query("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name LIKE '%Member%' OR r.role_name LIKE '%Mwanachama%'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    $phone = trim($u['phone']);
    $check = $pdo->prepare("SELECT customer_id FROM customers WHERE phone = ?");
    $check->execute([$phone]);
    if (!$check->fetch()) {
        // Create Customer
        $full_name = trim("{$u['first_name']} {$u['middle_name']} {$u['last_name']}");
        $ins = $pdo->prepare("
            INSERT INTO customers (first_name, middle_name, last_name, customer_name, phone, email, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $u['first_name'], $u['middle_name'], $u['last_name'], $full_name, $phone, $u['email'], 'active'
        ]);
        echo "CREATED MEMBER RECORD for USER: {$u['username']} ({$full_name})\n";
    }
}

echo "SYNC COMPLETE.\n";
