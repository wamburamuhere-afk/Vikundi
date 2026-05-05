<?php
require_once 'includes/config.php';

echo "ROLES:\n";
$stmt = $pdo->query("SELECT * FROM roles");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nSYNCING MEMBERS TO USERS...\n";

// 1. Get all customers
$members = $pdo->query("SELECT * FROM customers")->fetchAll(PDO::FETCH_ASSOC);
$member_role_id = 4; // Default to 'Customer/Member' if not found

$added = 0;
$skipped = 0;

foreach ($members as $m) {
    // Check if phone already has a user account
    $check = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
    $check->execute([$m['phone']]);
    if ($check->fetch()) {
        $skipped++;
        continue;
    }

    // Generate Username
    $first_initial = strtolower(substr(trim($m['first_name']), 0, 1));
    $last_name_slug = strtolower(preg_replace('/\s+/', '', trim($m['last_name'])));
    $username = $first_initial . $last_name_slug;
    $base_username = $username;

    // Ensure uniqueness
    $stmt_un = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $stmt_un->execute([$username]);
    $i = 1;
    while ($stmt_un->fetchColumn() > 0) {
        $username = $base_username . $i++;
        $stmt_un->execute([$username]);
    }

    // Insert user
    $hashed_password = password_hash('Vikundi2024', PASSWORD_DEFAULT);
    $ins = $pdo->prepare("
        INSERT INTO users (first_name, middle_name, last_name, email, phone, username, password, role_id, user_role, status, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $ins->execute([
        $m['first_name'], 
        $m['middle_name'], 
        $m['last_name'], 
        $m['email'], 
        $m['phone'], 
        $username, 
        $hashed_password, 
        $member_role_id, 
        'Member', 
        'active'
    ]);
    
    $added++;
    echo "Added User: $username for Member: {$m['customer_name']}\n";
}

echo "\nFINISHED: Added $added users, Skipped $skipped existing users.\n";
