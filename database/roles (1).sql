-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 18, 2026 at 10:16 AM
-- Server version: 8.2.0
-- PHP Version: 8.2.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bms`
--

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'Full system access', '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(2, 'Managing Director', 'Full system access', '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(3, 'Loan Officer', 'Can process loan applications', '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(4, 'Staff', 'Basic system access', '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(5, 'Director', 'Full system access', '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(6, 'CFO', NULL, '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(7, 'Accountant', NULL, '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(8, 'Credit Manager', NULL, '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(9, 'Loan Manager', NULL, '2025-07-07 17:52:36', '2025-10-23 21:05:06'),
(11, 'Secretary (PS)', NULL, '2025-07-07 17:52:36', '2025-10-23 21:05:06');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
