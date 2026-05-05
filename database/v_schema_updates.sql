ALTER TABLE customers MODIFY COLUMN status ENUM('active', 'inactive', 'suspended', 'blacklisted', 'pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending';
ALTER TABLE users ADD COLUMN status ENUM('pending', 'active', 'rejected') DEFAULT 'active' AFTER user_role;
-- Set existing users to active
UPDATE users SET status = 'active';

CREATE TABLE IF NOT EXISTS `contributions` (
  `contribution_id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `contribution_date` date NOT NULL,
  `description` text,
  `status` enum('pending','confirmed','cancelled') DEFAULT 'confirmed',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contribution_id`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
