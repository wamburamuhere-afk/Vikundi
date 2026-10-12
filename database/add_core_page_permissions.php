<?php
/**
 * database/add_core_page_permissions.php
 * --------------------------------------
 * Register the permission page-keys the everyday pages gate on, for databases
 * that do not have them.
 *
 * WHY THEY CAN BE MISSING. These eleven rows have only ever arrived through the
 * original SQL dump — no migration creates them. Every existing install was
 * restored from that dump, so it has them. A database built by
 * database/migrate.php alone does not: it gets the keys later migrations add
 * (fines, meetings, voting…) and nothing else. On such a database only an
 * admin-role user (the Chairperson) can open the Members list, Expenses, the
 * document library or the message centre; the Secretary and the Treasurer are
 * redirected away. Found by installing into an empty database before the first
 * new-group deployment.
 *
 * The list is exactly the keys an existing install already has, with the same
 * names — so a new group starts out behaving as the current one does, and no
 * access policy is invented here. Who gets what is left to
 * database/seed_vicoba_roles.php and includes/role_grants.php, as for every
 * other key: this file must run BEFORE the role seeder.
 *
 * An existing row is never touched. Idempotent and safe to re-run.
 *
 * Run manually:  php database/add_core_page_permissions.php
 */

require_once __DIR__ . '/../includes/config.php';

// page_key => [page_name, module_name, description]
$keys = [
    'customers'           => ['Customers List', 'Customers', 'View and manage the members list'],
    'expenses'            => ['Expenses', 'Accounts', 'Record, review and pay group expenses'],
    'document_library'    => ['Document Library', 'Documents', 'Browse the group document library'],
    'document_templates'  => ['Document Templates', 'Documents', 'Manage document templates'],
    'document_workflow'   => ['Document Workflow', 'Documents', 'Review and approve documents'],
    'e_signatures'        => ['E-Signatures', 'Documents', 'Sign documents electronically'],
    'message_center'      => ['Message Center', 'Communication', 'Send and read group messages'],
    'notification_center' => ['Notification Center', 'Communication', 'Read notifications'],
    'system_settings'     => ['System Settings', 'Settings', 'Configure the system'],
    'users'               => ['Users Management', 'Settings', 'Manage user accounts'],
    'user_roles'          => ['User Roles', 'Settings', 'Manage roles and permissions'],
];

$check = $pdo->prepare('SELECT permission_id FROM permissions WHERE page_key = ?');
$add   = $pdo->prepare('INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?, ?, ?, ?, ?)');

$added = [];
foreach ($keys as $key => [$name, $module, $description]) {
    $check->execute([$key]);
    if (!$check->fetchColumn()) {
        $add->execute(['', $key, $name, $description, $module]);
        $added[] = $key;
    }
}

echo $added
    ? '  Added ' . count($added) . ' core page permission(s): ' . implode(', ', $added) . ".\n"
    : "  Core page permissions already present.\n";
