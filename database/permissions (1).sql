-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 18, 2026 at 10:18 AM
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
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `permission_id` int NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `page_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `module_id` int DEFAULT NULL,
  `module_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `page_key` (`page_key`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `permission_name`, `page_key`, `page_name`, `description`, `module_id`, `module_name`, `created_at`) VALUES
(1, '', 'customers', 'Customers List', 'View and manage customers', NULL, 'Customers', '2026-01-06 13:32:43'),
(2, '', 'customer_registration', 'Customer Registration', 'Register new customers', NULL, 'Customers', '2026-01-06 13:32:43'),
(3, '', 'customer_groups', 'Customer Groups', 'Manage customer groups', NULL, 'Customers', '2026-01-06 13:32:43'),
(4, '', 'customer_import', 'Customer Import', 'Import customers from file', NULL, 'Customers', '2026-01-06 13:32:43'),
(5, '', 'customer_details', 'Customer Details', 'View customer details', NULL, 'Customers', '2026-01-06 13:32:43'),
(6, '', 'edit_customer', 'Edit Customer', 'Edit customer information', NULL, 'Customers', '2026-01-06 13:32:43'),
(7, '', 'loans', 'Loans List', 'View and manage loans', NULL, 'Loans', '2026-01-06 13:32:43'),
(8, '', 'loan_application', 'Loan Application', 'Create loan applications', NULL, 'Loans', '2026-01-06 13:32:43'),
(9, '', 'loan_collaterals', 'Loan Collaterals', 'Manage loan collaterals', NULL, 'Loans', '2026-01-06 13:32:43'),
(10, '', 'loan_products', 'Loan Products', 'Manage loan products', NULL, 'Loans', '2026-01-06 13:32:43'),
(11, '', 'loan_schedules', 'Loan Schedules', 'View loan schedules', NULL, 'Loans', '2026-01-06 13:32:43'),
(12, '', 'loan_processes', 'Loan Processes', 'Manage loan processes', NULL, 'Loans', '2026-01-06 13:32:43'),
(13, '', 'loan_terms', 'Loan Terms', 'Manage loan terms', NULL, 'Loans', '2026-01-06 13:32:43'),
(14, '', 'loan_details', 'Loan Details', 'View loan details', NULL, 'Loans', '2026-01-06 13:32:43'),
(15, '', 'edit_loan', 'Edit Loan', 'Edit loan information', NULL, 'Loans', '2026-01-06 13:32:43'),
(16, '', 'collections_dashboard', 'Collections Dashboard', 'View collections overview', NULL, 'Collections', '2026-01-06 13:32:43'),
(17, '', 'payment_processing', 'Payment Processing', 'Process loan payments', NULL, 'Collections', '2026-01-06 13:32:43'),
(18, '', 'overdue_loans', 'Overdue Loans', 'Manage overdue loans', NULL, 'Collections', '2026-01-06 13:32:43'),
(19, '', 'collection_strategies', 'Collection Strategies', 'Manage collection strategies', NULL, 'Collections', '2026-01-06 13:32:43'),
(20, '', 'payments', 'Payments', 'View and manage payments', NULL, 'Collections', '2026-01-06 13:32:43'),
(21, '', 'financial_statements', 'Financial Statements', 'View financial statements', NULL, 'Reports', '2026-01-06 13:32:43'),
(22, '', 'loan_portfolio_report', 'Loan Portfolio Report', 'View loan portfolio', NULL, 'Reports', '2026-01-06 13:32:43'),
(23, '', 'repayment_report', 'Repayment Report', 'View repayment reports', NULL, 'Reports', '2026-01-06 13:32:43'),
(24, '', 'income_statement', 'Income Statement', 'View income statement', NULL, 'Reports', '2026-01-06 13:32:43'),
(25, '', 'balance_sheet', 'Balance Sheet', 'View balance sheet', NULL, 'Reports', '2026-01-06 13:32:43'),
(26, '', 'trial_balance', 'Trial Balance', 'View trial balance', NULL, 'Reports', '2026-01-06 13:32:43'),
(27, '', 'expenses', 'Expenses', 'Manage expenses', NULL, 'Accounts', '2026-01-06 13:32:43'),
(28, '', 'journals', 'Journals', 'Manage journal entries', NULL, 'Accounts', '2026-01-06 13:32:43'),
(29, '', 'chart_of_accounts', 'Chart of Accounts', 'Manage chart of accounts', NULL, 'Accounts', '2026-01-06 13:32:43'),
(30, '', 'document_library', 'Document Library', 'Manage documents', NULL, 'Documents', '2026-01-06 13:32:43'),
(31, '', 'document_templates', 'Document Templates', 'Manage document templates', NULL, 'Documents', '2026-01-06 13:32:43'),
(32, '', 'e_signatures', 'E-Signatures', 'Manage electronic signatures', NULL, 'Documents', '2026-01-06 13:32:43'),
(33, '', 'document_workflow', 'Document Workflow', 'Manage document workflow', NULL, 'Documents', '2026-01-06 13:32:43'),
(34, '', 'customer_documents', 'Customer Documents', 'Manage customer documents', NULL, 'Documents', '2026-01-06 13:32:43'),
(35, '', 'loan_documents', 'Loan Documents', 'Manage loan documents', NULL, 'Documents', '2026-01-06 13:32:43'),
(36, '', 'compliance_documents', 'Compliance Documents', 'Manage compliance documents', NULL, 'Documents', '2026-01-06 13:32:43'),
(37, '', 'message_center', 'Message Center', 'Send and receive messages', NULL, 'Communication', '2026-01-06 13:32:43'),
(38, '', 'sms_alerts', 'SMS Alerts', 'Manage SMS alerts', NULL, 'Communication', '2026-01-06 13:32:43'),
(39, '', 'payment_reminders', 'Payment Reminders', 'Manage payment reminders', NULL, 'Communication', '2026-01-06 13:32:43'),
(40, '', 'notification_center', 'Notification Center', 'View notifications', NULL, 'Communication', '2026-01-06 13:32:43'),
(41, '', 'collection_letters', 'Collection Letters', 'Manage collection letters', NULL, 'Communication', '2026-01-06 13:32:43'),
(42, '', 'guarantors', 'Guarantors List', 'View and manage guarantors', NULL, 'Guarantors', '2026-01-06 13:32:43'),
(43, '', 'guarantor_registration', 'Guarantor Registration', 'Register new guarantors', NULL, 'Guarantors', '2026-01-06 13:32:43'),
(44, '', 'guarantor_details', 'Guarantor Details', 'View guarantor details', NULL, 'Guarantors', '2026-01-06 13:32:43'),
(45, '', 'edit_guarantor', 'Edit Guarantor', 'Edit guarantor information', NULL, 'Guarantors', '2026-01-06 13:32:43'),
(46, '', 'users', 'Users Management', 'Manage system users', NULL, 'Settings', '2026-01-06 13:32:43'),
(47, '', 'user_roles', 'User Roles', 'Manage user roles and permissions', NULL, 'Settings', '2026-01-06 13:32:43'),
(48, '', 'system_settings', 'System Settings', 'Configure system settings', NULL, 'Settings', '2026-01-06 13:32:43'),
(49, '', 'notification_settings', 'Notification Settings', 'Configure notifications', NULL, 'Settings', '2026-01-06 13:32:43'),
(50, '', 'policy_management', 'Policy Management', 'Manage policies', NULL, 'Settings', '2026-01-06 13:32:43'),
(51, '', 'add_user', 'Add User', 'Add new system user', NULL, 'Settings', '2026-01-06 13:32:43'),
(52, '', 'edit_user', 'Edit User', 'Edit user information', NULL, 'Settings', '2026-01-06 13:32:43'),
(53, '', 'dashboard', 'Dashboard', 'View system dashboard', NULL, 'Dashboard', '2026-01-06 13:32:43'),
(54, '', 'approve_loan', 'Approve Loan', 'Permission to approve loan applications', NULL, 'Loans', '2026-01-08 06:02:42'),
(55, '', 'reject_loan', 'Reject Loan', 'Permission to reject loan applications', NULL, 'Loans', '2026-01-08 06:02:42'),
(56, '', 'disburse_loan', 'Disburse Loan', 'Permission to disburse loan funds', NULL, 'Loans', '2026-01-08 06:02:42'),
(57, '', 'view_pending_loans', 'View Pending Loans', 'Permission to view pending loans section', NULL, 'Loans', '2026-01-08 06:02:42'),
(58, '', 'view_approved_loans', 'View Approved Loans', 'Permission to view approved loans section', NULL, 'Loans', '2026-01-08 06:02:42'),
(59, '', 'view_disbursed_loans', 'View Disbursed Loans', 'Permission to view disbursed loans section', NULL, 'Loans', '2026-01-08 06:02:42'),
(60, '', 'view_repaid_loans', 'View Repaid Loans', 'Permission to view repaid loans section', NULL, 'Loans', '2026-01-08 06:02:42'),
(61, '', 'view_defaulted_loans', 'View Defaulted Loans', 'Permission to view defaulted loans section', NULL, 'Loans', '2026-01-08 06:02:42'),
(70, '', 'view_loan_details', 'View Loan Details', 'Permission to view detailed loan information', NULL, 'Loans', '2026-01-08 06:05:31'),
(72, '', 'transactions', 'Transactions Management', 'View and manage transactions', NULL, 'Accounts', '2026-01-08 06:24:16'),
(73, '', 'view_active_collaterals', 'View Active Collaterals', 'Permission to view active collaterals section', NULL, 'Loans', '2026-01-08 07:51:47'),
(74, '', 'view_released_collaterals', 'View Released Collaterals', 'Permission to view released collaterals section', NULL, 'Loans', '2026-01-08 07:51:47'),
(75, '', 'view_forfeited_collaterals', 'View Forfeited Collaterals', 'Permission to view forfeited collaterals section', NULL, 'Loans', '2026-01-08 07:51:47'),
(76, '', 'release_collateral', 'Release Collateral', 'Permission to release loan collaterals', NULL, 'Loans', '2026-01-08 07:51:47'),
(77, '', 'forfeit_collateral', 'Forfeit Collateral', 'Permission to forfeit loan collaterals', NULL, 'Loans', '2026-01-08 07:51:47'),
(78, '', 'view_rejected_loans', 'View Rejected Loans', 'Permission to view rejected loans section', NULL, 'Loans', '2026-01-08 13:27:50'),
(80, '', 'email_templates', 'Email Templates', 'Manage system email templates', NULL, 'Settings', '2026-01-10 14:01:06'),
(81, '', 'sms_templates', 'SMS Templates', 'Manage system SMS templates', NULL, 'Settings', '2026-01-10 14:02:14'),
(82, '', 'customer_feedback', 'Customer Feedback', 'Can view and manage customer feedback', NULL, 'Marketing', '2026-01-12 08:05:50'),
(83, '', 'campaign_management', 'Campaign Management', 'Can view and manage marketing campaigns', NULL, 'Marketing', '2026-01-12 08:05:50'),
(84, '', 'lead_generation', 'Lead Generation', 'Can view and manage generated leads', NULL, 'Marketing', '2026-01-12 08:05:50');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
