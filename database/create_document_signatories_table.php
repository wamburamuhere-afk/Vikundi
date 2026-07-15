<?php
/**
 * database/create_document_signatories_table.php
 * ----------------------------------------------
 * Multi-party signing for authored documents. A document can require several
 * signatories (e.g. Chairperson + Secretary, or "creator signs then assigns a
 * member to sign"). Each signatory signs their OWN slot with their e-signature.
 *
 * Creates `document_signatories`:
 *   one row per (document, user) — who must sign, their order/label, and — once
 *   they sign — their captured signature image + timestamp.
 *
 * Idempotent and safe to re-run.
 *
 * Run manually:  php database/create_document_signatories_table.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `document_signatories` (
      `id` int NOT NULL AUTO_INCREMENT,
      `document_id` int NOT NULL,
      `user_id` int NOT NULL,
      `role_label` varchar(100) DEFAULT NULL,
      `sign_order` int NOT NULL DEFAULT 1,
      `status` enum('pending','signed','declined') NOT NULL DEFAULT 'pending',
      `sig_path` varchar(255) DEFAULT NULL,
      `signed_at` timestamp NULL DEFAULT NULL,
      `assigned_by` int DEFAULT NULL,
      `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `note` varchar(255) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uq_doc_user` (`document_id`, `user_id`),
      KEY `idx_doc` (`document_id`),
      KEY `idx_user_status` (`user_id`, `status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");

echo "document_signatories table ready.\n";
