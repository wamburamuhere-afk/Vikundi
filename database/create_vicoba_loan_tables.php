<?php
/**
 * database/create_vicoba_loan_tables.php
 * --------------------------------------
 * The VICOBA loans module's own tables.
 *
 * Deliberately NOT the inherited `loans` / `loan_types` / `loan_repayments` /
 * `guarantors` tables: those are BMS leftovers — MyISAM (no transactions, which
 * money needs), 50 columns nobody fills, and still written to by old routed
 * endpoints. These are InnoDB, hold only what the module uses, and nothing else
 * touches them.
 *
 *   vicoba_loan_types         the group's own loan products and their rules
 *   vicoba_loans              a member's loan, with the rules snapshotted on it
 *   vicoba_loan_guarantors    who was asked to guarantee it, and their answer
 *   vicoba_loan_installments  the repayment schedule
 *   vicoba_loan_payments      each repayment received, and how it was applied
 *
 * No loan types are seeded: a group has no loan products until it defines them.
 *
 * Idempotent and safe to re-run. Registered in database/migrate.php.
 *
 * Run manually:  php database/create_vicoba_loan_tables.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_loan_types` (
      `id` int NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `description` varchar(255) DEFAULT NULL,
      `interest_rate` decimal(6,3) NOT NULL DEFAULT 0.000,      -- % per month
      `interest_type` enum('flat','reducing') NOT NULL DEFAULT 'flat',
      `max_term_months` int NOT NULL DEFAULT 1,
      `max_share_multiple` decimal(6,2) NOT NULL DEFAULT 0.00,  -- 0 = no share-based limit
      `max_amount` decimal(15,2) DEFAULT NULL,                  -- NULL = no fixed cap
      `guarantors_required` tinyint NOT NULL DEFAULT 0,
      `penalty_type` enum('none','percent','fixed') NOT NULL DEFAULT 'none',
      `penalty_value` decimal(15,2) NOT NULL DEFAULT 0.00,      -- per month late
      `grace_days` int NOT NULL DEFAULT 0,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `created_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vlt_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_loans` (
      `loan_id` int NOT NULL AUTO_INCREMENT,
      `reference` varchar(30) NOT NULL,
      `customer_id` int NOT NULL,
      `loan_type_id` int NOT NULL,
      -- the rules as they stood when the loan was applied for (see lr_snapshot())
      `type_name` varchar(100) NOT NULL,
      `interest_rate` decimal(6,3) NOT NULL DEFAULT 0.000,
      `interest_type` enum('flat','reducing') NOT NULL DEFAULT 'flat',
      `guarantors_required` tinyint NOT NULL DEFAULT 0,
      `penalty_type` enum('none','percent','fixed') NOT NULL DEFAULT 'none',
      `penalty_value` decimal(15,2) NOT NULL DEFAULT 0.00,
      `grace_days` int NOT NULL DEFAULT 0,
      `amount` decimal(15,2) NOT NULL,
      `term_months` int NOT NULL,
      `total_interest` decimal(15,2) NOT NULL DEFAULT 0.00,
      `total_repayable` decimal(15,2) NOT NULL DEFAULT 0.00,
      `purpose` varchar(255) DEFAULT NULL,
      `status` enum('pending_guarantors','pending','approved','disbursed','repaid','rejected','cancelled') NOT NULL DEFAULT 'pending',
      `applied_by` int DEFAULT NULL,
      `applied_at` datetime DEFAULT NULL,
      `approved_by` int DEFAULT NULL,
      `approved_at` datetime DEFAULT NULL,
      `rejected_by` int DEFAULT NULL,
      `rejected_at` datetime DEFAULT NULL,
      `rejection_reason` varchar(255) DEFAULT NULL,
      `disbursed_by` int DEFAULT NULL,
      `disbursed_at` datetime DEFAULT NULL,
      `disbursement_account` varchar(50) DEFAULT NULL,
      `disbursement_reference` varchar(100) DEFAULT NULL,
      `repaid_at` datetime DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`loan_id`),
      UNIQUE KEY `uq_vl_reference` (`reference`),
      KEY `idx_vl_customer` (`customer_id`),
      KEY `idx_vl_status` (`status`),
      KEY `idx_vl_type` (`loan_type_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_loan_guarantors` (
      `id` int NOT NULL AUTO_INCREMENT,
      `loan_id` int NOT NULL,
      `guarantor_customer_id` int NOT NULL,
      `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
      `note` varchar(255) DEFAULT NULL,
      `responded_at` datetime DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vlg_loan_guarantor` (`loan_id`, `guarantor_customer_id`),
      KEY `idx_vlg_guarantor` (`guarantor_customer_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_loan_installments` (
      `id` int NOT NULL AUTO_INCREMENT,
      `loan_id` int NOT NULL,
      `seq` int NOT NULL,
      `due_date` date NOT NULL,
      `principal_due` decimal(15,2) NOT NULL DEFAULT 0.00,
      `interest_due` decimal(15,2) NOT NULL DEFAULT 0.00,
      `principal_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
      `interest_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
      `penalty_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
      `status` enum('pending','partial','paid') NOT NULL DEFAULT 'pending',
      `paid_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_vli_loan_seq` (`loan_id`, `seq`),
      KEY `idx_vli_due` (`due_date`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `vicoba_loan_payments` (
      `id` int NOT NULL AUTO_INCREMENT,
      `loan_id` int NOT NULL,
      `payment_date` date NOT NULL,
      `amount` decimal(15,2) NOT NULL,
      `principal_part` decimal(15,2) NOT NULL DEFAULT 0.00,
      `interest_part` decimal(15,2) NOT NULL DEFAULT 0.00,
      `penalty_part` decimal(15,2) NOT NULL DEFAULT 0.00,
      `account` varchar(50) DEFAULT NULL,
      `receipt_number` varchar(100) DEFAULT NULL,
      `note` varchar(255) DEFAULT NULL,
      `recorded_by` int DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_vlp_loan` (`loan_id`),
      KEY `idx_vlp_date` (`payment_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo "  vicoba loan tables ready (types, loans, guarantors, installments, payments).\n";
