<?php
require_once 'includes/config.php';

echo "REFINING VICOBA PERMISSIONS (CRUD SCALE)...\n";

// 1. CLEAR OLD PERMISSIONS
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE role_permissions");
$pdo->exec("TRUNCATE TABLE permissions");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 2. DEFINE NEW GRANULAR PERMISSIONS
$new_permissions = [
    // Dashboard Components
    ['dash_overview', 'Dashboard Overview', 'View summary cards and statistics', 'Dashboard'],
    ['dash_activity', 'Activity Logs', 'View recent system activities on dashboard', 'Dashboard'],
    ['dash_actions', 'Quick Actions', 'Access to shortcut buttons for common tasks', 'Dashboard'],
    
    // Member Management
    ['members', 'Member Management', 'Add, View, Edit, or Delete members', 'Management'],
    ['registers', 'Meeting Registers', 'Manage attendance and meeting records', 'Management'],
    ['group_info', 'Group Profile', 'Setup group name, vision, and rules', 'Management'],

    // Finance (The Heart)
    ['contributions', 'Contributions & Savings', 'Manage weekly/monthly savings deposits', 'Finance'],
    ['shares', 'Shares Management', 'Manage share purchases and value', 'Finance'],
    ['fines', 'Fines & Penalties', 'Assign and collect various fines', 'Finance'],
    ['expenses', 'Operating Expenses', 'Record and track group expenditures', 'Finance'],
    
    // Loans
    ['loans', 'Loan Management', 'Process applications, approvals, and tracking', 'Loans'],
    ['repayments', 'Loan Repayment', 'Record payments and update balances', 'Loans'],

    // Reports
    ['reports_finance', 'Financial Reports', 'Income, Balance Sheets, and Ledgers', 'Reports'],
    ['reports_members', 'Member Statements', 'Individual performance and activity reports', 'Reports'],

    // Admin
    ['admin_users', 'System Users', 'User accounts and login management', 'Administration'],
    ['admin_roles', 'Role Permissions', 'Defining who does what in the system', 'Administration'],
    ['admin_settings', 'System Settings', 'Core group policy and system configuration', 'Administration']
];

$ins_perm = $pdo->prepare("INSERT INTO permissions (page_key, page_name, description, module_name, created_at) VALUES (?, ?, ?, ?, NOW())");
foreach ($new_permissions as $p) {
    if (!$ins_perm->execute($p)) {
         print_r($ins_perm->errorInfo());
    }
}

// 3. AUTO-ASSIGN DEFAULT CRUD LEVELS TO ROLES
$roles = $pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
$perm_map = [];
$all_perms = $pdo->query("SELECT permission_id, page_key FROM permissions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($all_perms as $p) { $perm_map[$p['page_key']] = $p['permission_id']; }

foreach ($roles as $r) {
    $r_name = strtolower($r['role_name']);
    $rid = $r['role_id'];
    
    // Helper function for quick assignment
    $assign = function($rid, $pkey, $v, $c, $e, $d) use ($pdo, $perm_map) {
        if (!isset($perm_map[$pkey])) return;
        $pid = $perm_map[$pkey];
        $st = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$rid, $pid, $v, $c, $e, $d]);
    };

    // Admin & Chairman - Full Everything (1,1,1,1)
    if (str_contains($r_name, 'admin') || str_contains($r_name, 'mwenyekiti') || str_contains($r_name, 'chairman')) {
        foreach ($perm_map as $pkey => $pid) {
            $assign($rid, $pkey, 1, 1, 1, 1);
        }
    }
    // Katibu (Secretary) - Full Management, limited finance edits
    elseif (str_contains($r_name, 'katibu') || str_contains($r_name, 'secretary')) {
        $assign($rid, 'dash_overview', 1, 0, 0, 0);
        $assign($rid, 'dash_actions', 1, 0, 0, 0);
        $assign($rid, 'members', 1, 1, 1, 0);
        $assign($rid, 'registers', 1, 1, 1, 1);
        $assign($rid, 'contributions', 1, 1, 1, 0);
        $assign($rid, 'loans', 1, 1, 1, 0);
        $assign($rid, 'reports_finance', 1, 0, 0, 0);
        $assign($rid, 'reports_members', 1, 0, 0, 0);
    }
    // Mhazini (Treasurer) - Full Finance, No members
    elseif (str_contains($r_name, 'mhazini') || str_contains($r_name, 'treasurer')) {
        $assign($rid, 'dash_overview', 1, 0, 0, 0);
        $assign($rid, 'contributions', 1, 1, 1, 1);
        $assign($rid, 'shares', 1, 1, 1, 0);
        $assign($rid, 'fines', 1, 1, 1, 1);
        $assign($rid, 'expenses', 1, 1, 1, 1);
        $assign($rid, 'repayments', 1, 1, 1, 0);
        $assign($rid, 'reports_finance', 1, 1, 0, 0);
    }
    // Member - View only Dashboard and Statements
    elseif (str_contains($r_name, 'member') || str_contains($r_name, 'mwanachama')) {
        $assign($rid, 'dash_overview', 1, 0, 0, 0);
        $assign($rid, 'reports_members', 1, 0, 0, 0);
    }
}

echo "REFINED PERMISSIONS SETUP SUCCESSFUL.\n";
