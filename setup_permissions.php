<?php
require_once 'includes/config.php';

echo "RESETTING SYSTEM ROLES AND PERMISSIONS FOR VICOBA...\n";

// 1. CLEAR OLD PERMISSIONS
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE role_permissions");
$pdo->exec("TRUNCATE TABLE permissions");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 2. DEFINE NEW PERMISSIONS (VICoBA Modules)
$new_permissions = [
    // Dashboard
    ['dashboard', 'Dashboard View', 'Access to the main group dashboard', 'Management'],
    
    // Group Management
    ['manage_members', 'Manage Members', 'Add, Edit, View Group Members', 'Management'],
    ['member_register', 'Member Register', 'View detailed member participation records', 'Management'],
    ['group_profile', 'Group Profile', 'Manage the group identity and setup', 'Management'],

    // Financial Operations (The Core of VICoBA)
    ['financial_contributions', 'Manage Contributions', 'Record weekly/monthly contributions', 'Finance'],
    ['financial_shares', 'Manage Shares', 'Record and track share purchases by members', 'Finance'],
    ['financial_fines', 'Manage Fines', 'Assign and collect fines/penalties', 'Finance'],
    ['financial_expenses', 'Manage Expenses', 'Record group operating expenses', 'Finance'],
    ['financial_bank', 'Bank & Cash Accounts', 'Manage group bank deposits and cash balance', 'Finance'],
    ['financial_entrance', 'Entrance Fees', 'Manage new member entrance fees', 'Finance'],
    
    // Loan Management
    ['loan_requests', 'Loan Requests', 'Process and approve member loans', 'Loans'],
    ['loan_repayments', 'Loan Repayments', 'Record loan payments and interest', 'Loans'],
    ['loan_portfolio', 'Loan Portfolio', 'View all active loans and collectors', 'Loans'],

    // Reports & Analytics
    ['report_contribution_ledger', 'Contribution Ledger', 'Detailed periodic contribution reports', 'Reports'],
    ['report_financial_statements', 'Financial Statements', 'Profit/Loss and Balance Sheet', 'Reports'],
    ['report_loan_performance', 'Loan Performance', 'Track repayment rates and arrears', 'Reports'],
    ['report_member_statements', 'Member Statements', 'Individual member financial activity', 'Reports'],

    // Administration
    ['admin_users', 'Manage Users', 'Create and manage system logins', 'Administration'],
    ['user_roles', 'Manage Roles', 'Setup roles and their permissions', 'Administration'],
    ['admin_settings', 'System Settings', 'Configure group policy and system values', 'Administration'],
    ['admin_audit', 'Activity Logs', 'View system changes and user audits', 'Administration']
];

$ins_perm = $pdo->prepare("INSERT INTO permissions (page_key, page_name, description, module_name, created_at) VALUES (?, ?, ?, ?, NOW())");
foreach ($new_permissions as $p) {
    $ins_perm->execute($p);
}

// 3. GET ROLES
$roles = $pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
$all_perms = $pdo->query("SELECT permission_id, page_key FROM permissions")->fetchAll(PDO::FETCH_ASSOC);

// Map page_key to ID for easy assignment
$perm_map = [];
foreach ($all_perms as $p) {
    $perm_map[$p['page_key']] = $p['permission_id'];
}

// 4. ASSIGN PERMISSIONS TO ROLES
foreach ($roles as $r) {
    $r_name = strtolower($r['role_name']);
    $role_id = $r['role_id'];
    
    $allowed_keys = [];

    // Admin & Mwenyekiti (Chairman) - Full Authority
    if (str_contains($r_name, 'admin') || str_contains($r_name, 'mwenyekiti') || str_contains($r_name, 'chairman')) {
        $allowed_keys = array_keys($perm_map);
    }
    // Katibu (Secretary) - Full data management except extreme admin
    elseif (str_contains($r_name, 'katibu') || str_contains($r_name, 'secretary')) {
        $allowed_keys = [
            'dashboard', 'manage_members', 'member_register', 'group_profile',
            'financial_contributions', 'financial_shares', 'financial_fines', 'financial_expenses', 'financial_entrance',
            'loan_requests', 'loan_repayments', 'loan_portfolio',
            'report_contribution_ledger', 'report_financial_statements', 'report_loan_performance', 'report_member_statements'
        ];
    }
    // Mhazini (Treasurer) - Pure Financial Access
    elseif (str_contains($r_name, 'mhazini') || str_contains($r_name, 'treasurer')) {
        $allowed_keys = [
            'dashboard', 'financial_contributions', 'financial_shares', 'financial_fines', 'financial_expenses', 'financial_bank',
            'loan_repayments', 'loan_portfolio',
            'report_contribution_ledger', 'report_financial_statements'
        ];
    }
    // Member (Mwanachama) - View access only
    elseif (str_contains($r_name, 'member') || str_contains($r_name, 'mwanachama') || str_contains($r_name, 'mjumbe')) {
        $allowed_keys = ['dashboard', 'report_member_statements'];
    }

    // Insert permissions
    $ins_role_perm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 1, 1, 1)");
    foreach ($allowed_keys as $key) {
        if (isset($perm_map[$key])) {
            $ins_role_perm->execute([$role_id, $perm_map[$key]]);
        }
    }
}

echo "VICOBA SYSTEM ROLES & PERMISSIONS SETUP COMPLETE.\n";
