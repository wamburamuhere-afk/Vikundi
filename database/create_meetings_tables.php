<?php
/**
 * database/create_meetings_tables.php
 * -----------------------------------
 * Meetings module: a meeting record (heading, date, agenda, minutes, status)
 * with attendance and supporting documents (documents.related_type='meeting').
 *
 * Creates:
 *   meetings             — the meeting record
 *   meeting_attendance   — present/absent per member (UNIQUE meeting+member)
 * and registers the `meetings` permission page-key so the role seeder grants it
 * (Member = view; leadership via admin-bypass).
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php BEFORE
 * seed_vicoba_roles.php, so the permission exists when roles are (re)seeded.
 *
 * Run manually:  php database/create_meetings_tables.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `meetings` (
      `id` int NOT NULL AUTO_INCREMENT,
      `title` varchar(255) NOT NULL,
      `meeting_date` date NOT NULL,
      `meeting_time` time DEFAULT NULL,
      `location` varchar(255) DEFAULT NULL,
      `meeting_type` enum('regular','special','agm') NOT NULL DEFAULT 'regular',
      `agenda` text,
      `minutes` text,
      `status` enum('scheduled','held','cancelled') NOT NULL DEFAULT 'scheduled',
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `meeting_attendance` (
      `id` int NOT NULL AUTO_INCREMENT,
      `meeting_id` int NOT NULL,
      `member_id` int NOT NULL,
      `status` enum('present','absent') NOT NULL DEFAULT 'absent',
      `marked_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `meeting_member` (`meeting_id`,`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

// Register the permission page-key (idempotent — page_key is UNIQUE).
$check = $pdo->prepare("SELECT permission_id FROM permissions WHERE page_key = ?");
$check->execute(['meetings']);
if (!$check->fetchColumn()) {
    $pdo->prepare("INSERT INTO permissions (permission_name, page_key, page_name, description, module_name) VALUES (?,?,?,?,?)")
        ->execute(['', 'meetings', 'Meetings', 'Group meetings, attendance and documents', 'Management']);
    echo "Meetings tables ready. Added 'meetings' permission.\n";
} else {
    echo "Meetings tables ready. 'meetings' permission already present.\n";
}
