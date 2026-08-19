<?php
/**
 * database/create_api_tokens_table.php
 * -------------------------------------
 * Storage for the mobile API's refresh tokens (Module 1: Auth).
 *
 * The access token is a short-lived, stateless JWT — nothing to store for it.
 * The refresh token is the opposite on purpose: it has to be revocable (a
 * logout, or an admin disabling an account, must be able to kill a token that
 * has not expired yet), and a stateless JWT cannot be revoked before its own
 * expiry. So the refresh token is an opaque random string; only its SHA-256
 * hash is stored, the same way a password is never stored in the clear —
 * anyone reading this table cannot use a row to authenticate as the user.
 *
 * No FK to `users.user_id`: `users` is MyISAM (see project memory), and a
 * MyISAM table cannot be the parent of an InnoDB foreign key. The column is
 * indexed instead.
 *
 * Idempotent. Registered in database/migrate.php.
 *
 * Run manually:  php database/create_api_tokens_table.php
 */

require_once __DIR__ . '/../includes/config.php';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `api_refresh_tokens` (
      `id` int NOT NULL AUTO_INCREMENT,
      `user_id` int NOT NULL,
      `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the raw refresh token; the raw value is never stored',
      `issued_at` datetime NOT NULL,
      `expires_at` datetime NOT NULL,
      `revoked_at` datetime DEFAULT NULL,
      `user_agent` varchar(255) DEFAULT NULL COMMENT 'informational only, never trusted for security decisions',
      PRIMARY KEY (`id`),
      UNIQUE KEY `token_hash` (`token_hash`),
      KEY `user_lookup` (`user_id`, `revoked_at`),
      KEY `expiry_sweep` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
");
echo "  api_refresh_tokens table ready.\n";

// Sweep long-expired rows so the table doesn't grow forever. A token is kept
// for 30 days past its own expiry before deletion — long enough that a
// support conversation about "was I logged in on such a date" can still be
// answered from the row, short enough that this never becomes the group's
// second-largest table.
$deleted = $pdo->exec("
    DELETE FROM api_refresh_tokens
     WHERE expires_at < (NOW() - INTERVAL 30 DAY)
");
if ($deleted) {
    echo "  Swept $deleted long-expired refresh token row(s).\n";
}

echo "API refresh tokens ready.\n";
