<?php
// Enable Error Reporting for Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Ensure session cookie is accessible across the whole site
// This must be set BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_path', '/');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Start the buffer to capture content
if (ob_get_level() === 0) ob_start();

// Ensure database connection is available in global scope
global $pdo, $pdo_accounts;

// Define root directory
define('ROOT_DIR', __DIR__);

// ============================================================================
// MODULE DIRECTORY DEFINITIONS
// ============================================================================
// Shared & Constant Modules
define('ACCOUNTS_DIR', ROOT_DIR . '/app/constant/accounts');
define('COMMUNICATION_DIR', ROOT_DIR . '/app/constant/communication');
define('DOCUMENT_DIR', ROOT_DIR . '/app/constant/document');
define('INTEGRATIONS_DIR', ROOT_DIR . '/app/constant/integrations');
define('PROFILE_DIR', ROOT_DIR . '/app/constant/profile');
define('RESOURCES_DIR', ROOT_DIR . '/app/constant/resources');
define('REPORTS_DIR', ROOT_DIR . '/app/constant/reports');
define('SETTINGS_DIR', ROOT_DIR . '/app/constant/settings');

// BMS Modules (Core Business)
define('BMS_DIR', ROOT_DIR . '/app/bms');
define('CUSTOMERS_DIR', BMS_DIR . '/customer');
define('SUPPLIERS_DIR', BMS_DIR . '/Suppliers');
define('INVOICE_DIR', BMS_DIR . '/invoice');
define('POS_DIR', BMS_DIR . '/pos');
define('PRODUCT_DIR', BMS_DIR . '/product');
define('PURCHASE_DIR', BMS_DIR . '/purchase');
define('SALES_DIR', BMS_DIR . '/sales');
define('STOCK_DIR', BMS_DIR . '/stock');
define('BANKING_DIR', BMS_DIR . '/banking');
define('GRN_DIR', BMS_DIR . '/grn');
define('LOANS_DIR', BMS_DIR . '/loans');

// Special Directories
define('API_DIR', ROOT_DIR . '/api');
define('AJAX_DIR', ROOT_DIR . '/ajax');
define('COMING_SOON_FILE', ROOT_DIR . '/app/coming_soon.php');


// ============================================================================
// CORE FILE DEFINITIONS
// ============================================================================
define('HEADER_FILE', ROOT_DIR . '/header.php');
define('FOOTER_FILE', ROOT_DIR . '/footer.php');
define('PRINT_FOOTER_FILE', ROOT_DIR . '/includes/print_footer.php');
define('HELPERS_FILE', ROOT_DIR . '/helpers.php');
define('CONFIG_FILE', ROOT_DIR . '/includes/config.php');
define('INDEX_FILE', ROOT_DIR . '/index.php');
define('LOGIN_FILE', ROOT_DIR . '/login.php');
define('LOGOUT_FILE', ROOT_DIR . '/logout.php');

// Automatically include core utilities
require_once CONFIG_FILE;
require_once HELPERS_FILE; // Load Helper Functions
require_once ROOT_DIR . '/includes/activity_logger.php'; // Activity Logger
require_once ROOT_DIR . '/core/permissions.php'; // Load permissions
require_once ROOT_DIR . '/actions/check_auth.php';


// ============================================================================
// URL ROUTING MAP
// ============================================================================
// This array maps clean URLs to actual PHP files
// 
// HOW TO ADD NEW ROUTES:
// 1. Find the appropriate category section below
// 2. Add your route in the format: 'clean/url' => DIRECTORY_CONSTANT . '/file.php'
// 3. For API/AJAX routes, add both clean and .php versions for compatibility
// 4. Keep routes alphabetically sorted within each section for easy maintenance
//
// EXAMPLE:
//    'customers/new_feature' => CUSTOMERS_DIR . '/new_feature.php',
//
// ============================================================================

$routes = [
    // ========================================================================
    // CORE PAGES
    // ========================================================================
    'login' => ROOT_DIR . '/login.php',
    'logout' => ROOT_DIR . '/logout.php',
    'dashboard' => ROOT_DIR . '/app/dashboard.php',
    // 'loan-dashboard' => ROOT_DIR . '/app/loan-dashboard.php',
    'register' => ROOT_DIR . '/register.php',
    'forgot_password' => ROOT_DIR . '/forgot_password.php',
    'reset_password' => ROOT_DIR . '/reset_password.php',
    'actions/forgot_password' => ROOT_DIR . '/actions/forgot_password.php',
    'actions/reset_password' => ROOT_DIR . '/actions/reset_password.php',

    // ========================================================================
    // ACCOUNTS MODULE (App Directory)
    // ========================================================================
    'accounts/bank_reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'accounts/bank_reconciliation.php' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'accounts/budget' => ACCOUNTS_DIR . '/budget.php',
    'accounts/budget.php' => ACCOUNTS_DIR . '/budget.php',
    'accounts/budget_details' => ACCOUNTS_DIR . '/budget_details.php',
    'accounts/budget_details.php' => ACCOUNTS_DIR . '/budget_details.php',
    'accounts/chart_of_accounts' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'accounts/chart_of_accounts.php' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'accounts/edit_expense' => ACCOUNTS_DIR . '/edit_expense.php',
    'accounts/edit_expense.php' => ACCOUNTS_DIR . '/edit_expense.php',
    'accounts/edit_journal' => ACCOUNTS_DIR . '/edit_journal.php',
    'accounts/edit_journal.php' => ACCOUNTS_DIR . '/edit_journal.php',
    'accounts/expense_details' => ACCOUNTS_DIR . '/expense_details.php',
    'accounts/expense_details.php' => ACCOUNTS_DIR . '/expense_details.php',
    'accounts/expenses' => ACCOUNTS_DIR . '/expenses.php',
    'accounts/expenses.php' => ACCOUNTS_DIR . '/expenses.php',
    'accounts/add_journal' => ACCOUNTS_DIR . '/add_journal.php',
    'accounts/add_journal.php' => ACCOUNTS_DIR . '/add_journal.php',
    'accounts/journal_details' => ACCOUNTS_DIR . '/journal_details.php',
    'accounts/journal_details.php' => ACCOUNTS_DIR . '/journal_details.php',
    'accounts/journals' => ACCOUNTS_DIR . '/journals.php',
    'accounts/journals.php' => ACCOUNTS_DIR . '/journals.php',
    'accounts/transaction_details' => ACCOUNTS_DIR . '/transaction_details.php',
    'accounts/transaction_details.php' => ACCOUNTS_DIR . '/transaction_details.php',
    'accounts/transactions' => ACCOUNTS_DIR . '/transactions.php',
    'accounts/transactions.php' => ACCOUNTS_DIR . '/transactions.php',
    'accounts/trial_balance' => ACCOUNTS_DIR . '/trial_balance.php',
    'accounts/trial_balance.php' => ACCOUNTS_DIR . '/trial_balance.php',
    'accounts/death_expenses' => ACCOUNTS_DIR . '/death_expenses.php',
    'accounts/death_expenses.php' => ACCOUNTS_DIR . '/death_expenses.php',
    'accounts/other_expenses' => ACCOUNTS_DIR . '/general_expenses.php',
    'accounts/other_expenses.php' => ACCOUNTS_DIR . '/general_expenses.php',

    // Death & General Expenses APIs
    'api/log_action' => API_DIR . '/log_action.php',
    'api/log_action.php' => API_DIR . '/log_action.php',
    'api/search_members_with_phone' => API_DIR . '/search_members_with_phone.php',
    'api/search_members_with_phone.php' => API_DIR . '/search_members_with_phone.php',
    'api/get_member_by_phone' => API_DIR . '/get_member_by_phone.php',
    'api/get_member_by_phone.php' => API_DIR . '/get_member_by_phone.php',
    'api/get_member_dependents' => API_DIR . '/get_member_dependents.php',
    'api/get_member_dependents.php' => API_DIR . '/get_member_dependents.php',
    'api/get_death_expenses' => API_DIR . '/get_death_expenses.php',
    'api/get_death_expenses.php' => API_DIR . '/get_death_expenses.php',
    'api/get_general_expenses' => API_DIR . '/get_general_expenses.php',
    'api/get_general_expenses.php' => API_DIR . '/get_general_expenses.php',
    'api/add_general_expense' => API_DIR . '/add_general_expense.php',
    'api/add_general_expense.php' => API_DIR . '/add_general_expense.php',
    'api/approve_general_expense' => API_DIR . '/approve_general_expense.php',
    'api/approve_general_expense.php' => API_DIR . '/approve_general_expense.php',
    'api/delete_general_expense' => API_DIR . '/delete_general_expense.php',
    'api/delete_general_expense.php' => API_DIR . '/delete_general_expense.php',
    'api/update_general_expense' => API_DIR . '/update_general_expense.php',
    'api/update_general_expense.php' => API_DIR . '/update_general_expense.php',
    'api/get_general_expense_details' => API_DIR . '/get_general_expense_details.php',
    'api/get_general_expense_details.php' => API_DIR . '/get_general_expense_details.php',
    'actions/process_death_expense' => ROOT_DIR . '/actions/process_death_expense.php',
    'actions/process_death_expense.php' => ROOT_DIR . '/actions/process_death_expense.php',
    'actions/approve_death_expense' => ROOT_DIR . '/actions/approve_death_expense.php',
    'actions/approve_death_expense.php' => ROOT_DIR . '/actions/approve_death_expense.php',
    'actions/delete_death_expense' => ROOT_DIR . '/actions/delete_death_expense.php',
    'actions/delete_death_expense.php' => ROOT_DIR . '/actions/delete_death_expense.php',

    // Aliases for Core Modules
    'expenses' => ACCOUNTS_DIR . '/expenses.php',
    'budget' => ACCOUNTS_DIR . '/budget.php',
    'bank_reconciliation' => ACCOUNTS_DIR . '/bank_reconciliation.php',
    'transactions' => ACCOUNTS_DIR . '/transactions.php',
    'chart_of_accounts' => ACCOUNTS_DIR . '/chart_of_accounts.php',
    'death_expenses' => ACCOUNTS_DIR . '/death_expenses.php',
    'other_expenses' => ACCOUNTS_DIR . '/general_expenses.php',

    'accounts/reconciliation_details' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'accounts/reconciliation_details.php' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'reconciliation_details' => ACCOUNTS_DIR . '/reconciliation_details.php',
    'reconciliation_details.php' => ACCOUNTS_DIR . '/reconciliation_details.php',

    // New aliases to avoid 404s
    'bank_accounts' => ACCOUNTS_DIR . '/bank_accounts.php',
    'cash_register' => ACCOUNTS_DIR . '/cash_register.php',
    'petty_cash' => ACCOUNTS_DIR . '/petty_cash.php',
    'actions/save_petty_cash' => ROOT_DIR . '/actions/save_petty_cash.php',
    'actions/fetch_petty_cash' => ROOT_DIR . '/actions/fetch_petty_cash.php',
    'actions/approve_petty_cash' => ROOT_DIR . '/actions/approve_petty_cash.php',
    'actions/delete_petty_cash' => ROOT_DIR . '/actions/delete_petty_cash.php',
    'actions/get_petty_cash' => ROOT_DIR . '/actions/get_petty_cash.php',
    'print_petty_cash' => ACCOUNTS_DIR . '/print_petty_cash.php',
    'payment_vouchers' => ACCOUNTS_DIR . '/payment_vouchers.php',

    // ========================================================================
    // CUSTOMERS MODULE (BMS Directory)
    // ========================================================================
    'customers' => CUSTOMERS_DIR . '/customers.php',
    'customers.php' => CUSTOMERS_DIR . '/customers.php',
    'customers/details' => CUSTOMERS_DIR . '/customer_details.php',
    'customers/edit' => CUSTOMERS_DIR . '/edit_customer.php',
    'member_approvals' => CUSTOMERS_DIR . '/member_approvals.php',
    'customers/group_details' => CUSTOMERS_DIR . '/customer_group_details.php',
    'customers/group_members' => CUSTOMERS_DIR . '/customer_group_members.php',
    'customers/groups' => CUSTOMERS_DIR . '/customer_groups.php',
    'customers/import' => CUSTOMERS_DIR . '/customer_import.php',
    'customers/documents' => CUSTOMERS_DIR . '/customer_documents.php',
    'my_contributions' => CUSTOMERS_DIR . '/manage_contributions.php',
    'submit_contribution' => CUSTOMERS_DIR . '/submit_contribution.php',
    'manage_contributions' => CUSTOMERS_DIR . '/manage_contributions.php',
    'group_settings' => CUSTOMERS_DIR . '/group_settings.php',
    'dormant_members' => CUSTOMERS_DIR . '/dormant_members.php',
    'actions/add_member' => ROOT_DIR . '/actions/add_member.php',
    'actions/save_group_settings' => ROOT_DIR . '/actions/save_group_settings.php',
    'actions/approve_member' => ROOT_DIR . '/actions/approve_member.php',
    'actions/process_registration' => ROOT_DIR . '/actions/process_registration.php',
    'actions/update_contribution' => ROOT_DIR . '/actions/update_contribution.php',
    'actions/upload_contributions' => ROOT_DIR . '/actions/upload_contributions.php',
    'actions/process_contribution' => ROOT_DIR . '/actions/process_contribution.php',
    // 'actions/apply_for_loan' => ROOT_DIR . '/actions/apply_for_loan.php',
    // 'actions/approve_loan_vicoba' => ROOT_DIR . '/actions/approve_loan_vicoba.php',
    // 'actions/record_loan_payment' => ROOT_DIR . '/actions/record_loan_payment.php',

    // Loans Module Pages (Disabled)
    // 'loans'               => LOANS_DIR . '/loans_list.php',
    // 'loans/details'       => LOANS_DIR . '/loan_details.php',
    // 'loans/apply'         => LOANS_DIR . '/loan_application.php',
    // 'my_loans'            => LOANS_DIR . '/my_loans.php',

    // Fines Module
    'manage_fines'        => CUSTOMERS_DIR . '/manage_fines.php',
    'my_fines'            => CUSTOMERS_DIR . '/my_fines.php',
    'actions/issue_fine'         => ROOT_DIR . '/actions/issue_fine.php',
    'actions/update_fine_status' => ROOT_DIR . '/actions/update_fine_status.php',

    // Reports Module
    'vicoba_reports'      => REPORTS_DIR . '/vicoba_reports.php',
    'financial-ledger'    => REPORTS_DIR . '/vicoba_reports.php',
    'member_statement'    => REPORTS_DIR . '/member_statement.php',

    // Routes for Sales, Products, and Purchases are disabled for Vikundi System

    // Communication
    'message_center' => COMMUNICATION_DIR . '/message_center.php',
    'notification_center' => COMMUNICATION_DIR . '/notification_center.php',
    
    // Documents
    'library' => DOCUMENT_DIR . '/library.php',
    
    // HR & Operations remain disabled
    // Routes for Loans and Collections are disabled for Vikundi System


    // ========================================================================
    // DOCUMENT MANAGEMENT MODULE (Constant Directory)
    // ========================================================================
    'customer_documents' => DOCUMENT_DIR . '/customer_documents.php',
    'customer_documents.php' => DOCUMENT_DIR . '/customer_documents.php',
    'document_library' => DOCUMENT_DIR . '/document_library.php',
    'library' => ROOT_DIR . '/app/constant/library/library.php',
    'library' => ROOT_DIR . '/app/constant/library/library.php',
    'messaging' => ROOT_DIR . '/app/constant/messaging/messaging.php',
    'messaging/chat' => ROOT_DIR . '/app/constant/messaging/chat.php',
    'messaging/inbox' => ROOT_DIR . '/app/constant/messaging/inbox.php',
    'message_center' => ROOT_DIR . '/app/constant/messages/message-list.php',
    'message_center' => ROOT_DIR . '/app/constant/messages/message-list.php',
    'notification_center' => ROOT_DIR . '/app/constant/notifications/notification-list.php',
    'notification_center' => ROOT_DIR . '/app/constant/notifications/notification-list.php',
    'documents' => ROOT_DIR . '/app/constant/documents/document-list.php',
    'documents-view' => ROOT_DIR . '/app/constant/documents/document-view.php',
    'document-categories' => ROOT_DIR . '/app/constant/documents/document-categories.php',
    'document-versions' => ROOT_DIR . '/app/constant/documents/document-versions.php',
    'document-tags' => ROOT_DIR . '/app/constant/documents/document-tags.php',
    'document-search' => ROOT_DIR . '/app/constant/documents/document-search.php',
    'document-templates' => ROOT_DIR . '/app/constant/documents/document-templates.php',
    'documents' => ROOT_DIR . '/app/constant/documents/document-list.php',
    'documents-view' => ROOT_DIR . '/app/constant/documents/document-view.php',
    'document-categories' => ROOT_DIR . '/app/constant/documents/document-categories.php',
    'document-versions' => ROOT_DIR . '/app/constant/documents/document-versions.php',
    'document-tags' => ROOT_DIR . '/app/constant/documents/document-tags.php',
    'document-search' => ROOT_DIR . '/app/constant/documents/document-search.php',
    'document-templates' => ROOT_DIR . '/app/constant/documents/document-templates.php',
    'e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    'e_signatures.php' => DOCUMENT_DIR . '/e_signatures.php',
    'leads' => ROOT_DIR . '/app/constant/lead_generation.php', // Check this!
    'message_center' => COMMUNICATION_DIR . '/message_center.php',
    'message_center.php' => COMMUNICATION_DIR . '/message_center.php',
    'notification_center' => COMMUNICATION_DIR . '/notification_center.php',
    'notification_center.php' => COMMUNICATION_DIR . '/notification_center.php',
    'documents/e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    // 'loan_documents' => DOCUMENT_DIR . '/loan_documents.php',
    'select_document_add_esignature' => DOCUMENT_DIR . '/select_document_add_esignature.php',
    'select_document_add_esignature.php' => DOCUMENT_DIR . '/select_document_add_esignature.php',

    // Document aliases for common navigation patterns
    'library' => DOCUMENT_DIR . '/document_library.php',
    'library.php' => DOCUMENT_DIR . '/document_library.php',
    'documents/library' => DOCUMENT_DIR . '/document_library.php',
    'documents/workflow' => DOCUMENT_DIR . '/document_workflow.php',
    'compliance_documents' => DOCUMENT_DIR . '/document_library.php',
    'compliance_documents.php' => DOCUMENT_DIR . '/document_library.php',


    // ========================================================================
    // REPORTS MODULE (App Directory)
    // ========================================================================
    'reports/audit_logs' => REPORTS_DIR . '/audit_logs.php',
    'reports/balance_sheet' => COMING_SOON_FILE,
    'reports/cash_flow' => COMING_SOON_FILE,
    'reports/compliance_checklist' => COMING_SOON_FILE,
    'reports/compliance_dashboard' => COMING_SOON_FILE,
    'reports/customer_activity' => COMING_SOON_FILE,
    'reports/customer_behavior' => COMING_SOON_FILE,
    'reports/customer_statement' => COMING_SOON_FILE,
    'reports/delinquency_report' => COMING_SOON_FILE,
    'reports/financial_statements' => COMING_SOON_FILE,
    'reports/income_statement' => INVOICE_DIR . '/income_statement.php',
    'reports/loan_performance' => COMING_SOON_FILE,
    'reports/loan_trends' => COMING_SOON_FILE,
    'reports/market_analysis' => COMING_SOON_FILE,
    'reports/performance_dashboard' => COMING_SOON_FILE,
    'reports/portfolio_analysis' => COMING_SOON_FILE,
    'reports/profitability_analysis' => COMING_SOON_FILE,
    'reports/regulatory_reports' => COMING_SOON_FILE,
    'reports/risk_analysis' => COMING_SOON_FILE,
    'reports/system_audit' => COMING_SOON_FILE,
    'reports/trial_balance' => ACCOUNTS_DIR . '/trial_balance.php',
    'reports/user_activity' => COMING_SOON_FILE,
    'financial_ledger' => CUSTOMERS_DIR . '/financial_ledger.php',
    'reports/financial_ledger' => CUSTOMERS_DIR . '/financial_ledger.php',

    // Report Aliases
    'balance_sheet' => COMING_SOON_FILE,
    'cash_flow' => COMING_SOON_FILE,
    'ledger_report' => COMING_SOON_FILE,
    'loan_portfolio_report' => COMING_SOON_FILE,
    'loan_performance' => COMING_SOON_FILE,
    'delinquency_report' => COMING_SOON_FILE,
    'repayment_report' => COMING_SOON_FILE,
    'sales_report' => COMING_SOON_FILE,
    'purchase_report' => COMING_SOON_FILE,
    'inventory_report' => COMING_SOON_FILE,
    'profit_loss_report' => COMING_SOON_FILE,
    'expense_report' => REPORTS_DIR . '/expense_report.php',
    'death_analysis' => REPORTS_DIR . '/death_analysis.php',
    'performance_dashboard' => COMING_SOON_FILE,
    'customer_analysis'   => REPORTS_DIR . '/customer_analysis.php',
    'member-analysis'     => REPORTS_DIR . '/customer_analysis.php',
    'product_analysis' => COMING_SOON_FILE,
    'sales_forecast' => COMING_SOON_FILE,
    'trends_analysis' => COMING_SOON_FILE,
    'tax_report' => COMING_SOON_FILE,
    'audit_report' => COMING_SOON_FILE,
    'compliance_report' => COMING_SOON_FILE,
    'employee_report' => COMING_SOON_FILE,
    'asset_report' => COMING_SOON_FILE,

    // ========================================================================
    // COMMUNICATION MODULE (App Directory)
    // ========================================================================
    'communication/campaign_management' => COMMUNICATION_DIR . '/campaign_management.php',
    'communication/email_templates' => COMMUNICATION_DIR . '/email_templates.php',
    'communication/lead_generation' => COMMUNICATION_DIR . '/lead_generation.php',
    'communication/message_center' => COMMUNICATION_DIR . '/message_center.php',
    'communication/notification_center' => COMMUNICATION_DIR . '/notification_center.php',
    'communication/sms_alerts' => COMMUNICATION_DIR . '/sms_alerts.php',
    'communication/sms_templates' => COMMUNICATION_DIR . '/sms_templates.php',

    // Comms Aliases
    'campaigns' => COMMUNICATION_DIR . '/campaign_management.php',
    'campaigns.php' => COMMUNICATION_DIR . '/campaign_management.php',
    'leads' => COMMUNICATION_DIR . '/lead_generation.php',
    'leads.php' => COMMUNICATION_DIR . '/lead_generation.php',
    'email_templates' => COMMUNICATION_DIR . '/email_templates.php',
    'email_templates.php' => COMMUNICATION_DIR . '/email_templates.php',
    'sms_templates' => COMMUNICATION_DIR . '/sms_templates.php',
    'sms_templates.php' => COMMUNICATION_DIR . '/sms_templates.php',
    'message_center' => COMMUNICATION_DIR . '/message_center.php',
    'message_center.php' => COMMUNICATION_DIR . '/message_center.php',
    'notification_center' => COMMUNICATION_DIR . '/notification_center.php',
    'notification_center.php' => COMMUNICATION_DIR . '/notification_center.php',

    // ========================================================================
    // DOCUMENTS MODULE (App Directory)
    // ========================================================================
    'customers/documents' => DOCUMENT_DIR . '/customer_documents.php',
    'documents/e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    'documents/library' => DOCUMENT_DIR . '/document_library.php',
    'document_library.php' => DOCUMENT_DIR . '/document_library.php',
    'documents/e_signatures' => DOCUMENT_DIR . '/e_signatures.php',
    'e_signatures.php' => DOCUMENT_DIR . '/e_signatures.php',
    'documents/workflow' => DOCUMENT_DIR . '/document_workflow.php',
    'compliance_documents' => COMING_SOON_FILE,
    'compliance_documents.php' => COMING_SOON_FILE,

    // ========================================================================
    // INTEGRATIONS MODULE (App Directory)
    // ========================================================================
    'integrations/api_dashboard' => INTEGRATIONS_DIR . '/api_dashboard.php',
    'integrations/api_documentation' => INTEGRATIONS_DIR . '/api_documentation.php',
    'integrations/banking_integration' => INTEGRATIONS_DIR . '/banking_integration.php',
    'integrations/credit_bureau' => INTEGRATIONS_DIR . '/credit_bureau.php',
    'integrations/crm_integration' => INTEGRATIONS_DIR . '/crm_integration.php',
    'integrations/payment_gateways' => INTEGRATIONS_DIR . '/payment_gateways.php',
    'integrations/webhooks' => INTEGRATIONS_DIR . '/webhooks.php',

    // ========================================================================
    // SETTINGS & USER MANAGEMENT (App Directory)
    // ========================================================================
    'profile' => PROFILE_DIR . '/profile.php',
    'profile.php' => PROFILE_DIR . '/profile.php',
    'settings/notifications' => SETTINGS_DIR . '/notification_settings.php',
    'settings/notifications' => SETTINGS_DIR . '/notification_settings.php',
    'notification_settings' => SETTINGS_DIR . '/notification_settings.php',
    'notification_settings.php' => SETTINGS_DIR . '/notification_settings.php',
    'settings/system' => SETTINGS_DIR . '/system_settings.php',
    'system_settings' => SETTINGS_DIR . '/system_settings.php',
    'system_settings.php' => SETTINGS_DIR . '/system_settings.php',
    'users' => SETTINGS_DIR . '/users.php',
    'users.php' => SETTINGS_DIR . '/users.php',
    'user_roles' => SETTINGS_DIR . '/user_roles.php',
    'user_roles.php' => SETTINGS_DIR . '/user_roles.php',
    'permissions' => SETTINGS_DIR . '/manage_permissions.php',
    'permissions.php' => SETTINGS_DIR . '/manage_permissions.php',
    'ajax/get_role' => SETTINGS_DIR . '/ajax/get_role.php',
    'ajax/get_role.php' => SETTINGS_DIR . '/ajax/get_role.php',
    'audit-logs' => ROOT_DIR . '/app/audit_logs.php',
    'audit_logs' => ROOT_DIR . '/app/audit_logs.php',
    'audit_logs.php' => ROOT_DIR . '/app/audit_logs.php',
    'activity-logs' => ROOT_DIR . '/app/audit_logs.php',
    'activity_logs' => ROOT_DIR . '/app/audit_logs.php',
    'company_profile' => COMING_SOON_FILE,
    'company_profile.php' => COMING_SOON_FILE,
    'backup_restore' => COMING_SOON_FILE,
    'backup_restore.php' => COMING_SOON_FILE,
    'tax_settings' => COMING_SOON_FILE,
    'tax_settings.php' => COMING_SOON_FILE,
    'payment_settings' => COMING_SOON_FILE,
    'payment_settings.php' => COMING_SOON_FILE,
    'my_settings' => PROFILE_DIR . '/my_settings.php',
    'my_settings.php' => PROFILE_DIR . '/my_settings.php',
    'help.php' => COMING_SOON_FILE,

    // ========================================================================
    // AJAX ENDPOINTS
    // ========================================================================
    // Accounts Related - Moved to API/Accounts section below
    // Old AJAX routes removed as requested


    // Customers Related
    'ajax/save_customer_document' => AJAX_DIR . '/save_customer_document.php',
    'ajax/save_feedback' => AJAX_DIR . '/save_feedback.php',
    'ajax/search_customers' => AJAX_DIR . '/search_customers.php',
    'ajax/get_member_beneficiaries' => AJAX_DIR . '/get_member_beneficiaries.php',
    'ajax/update_customer_document' => AJAX_DIR . '/update_customer_document.php',
    'ajax/update_feedback_status' => AJAX_DIR . '/update_feedback_status.php',

    // Loans Related
    'ajax/add_collection_strategy' => AJAX_DIR . '/add_collection_strategy.php',
    'ajax/add_guarantor' => AJAX_DIR . '/add_guarantor.php',
    'ajax/add_strategy_template' => AJAX_DIR . '/add_strategy_template.php',
    'ajax/apply_loan' => AJAX_DIR . '/apply_loan.php',
    'ajax/assign_loans_to_strategy' => AJAX_DIR . '/assign_loans_to_strategy.php',
    'ajax/calculate_penalties' => AJAX_DIR . '/calculate_penalties.php',
    'ajax/delete_collateral_document' => AJAX_DIR . '/delete_collateral_document.php',
    'ajax/delete_payment' => AJAX_DIR . '/delete_payment.php',
    'ajax/edit_loan' => AJAX_DIR . '/edit_loan.php',
    'ajax/export_strategies' => AJAX_DIR . '/export_strategies.php',
    'ajax/get_available_loans' => AJAX_DIR . '/get_available_loans.php',
    'ajax/get_collateral_attachments' => AJAX_DIR . '/get_collateral_attachments.php',
    'ajax/get_collateral_details' => AJAX_DIR . '/get_collateral_details.php',
    'ajax/get_collateral_documents' => AJAX_DIR . '/get_collateral_documents.php',
    'ajax/get_collaterals' => AJAX_DIR . '/get_collaterals.php',
    'ajax/get_loan_details_bulk' => AJAX_DIR . '/get_loan_details_bulk.php',
    'ajax/get_loans_for_collateral' => AJAX_DIR . '/get_loans_for_collateral.php',
    'ajax/get_payment_details' => AJAX_DIR . '/get_payment_details.php',
    'ajax/get_receipt' => AJAX_DIR . '/get_receipt.php',
    'ajax/get_schedule_details' => AJAX_DIR . '/get_schedule_details.php',
    'ajax/get_schedule_details_bulk' => AJAX_DIR . '/get_schedule_details_bulk.php',
    'ajax/get_strategy_details' => AJAX_DIR . '/get_strategy_details.php',
    'ajax/loan_collaterals_details' => ROOT_DIR . '/app/planned/loan_collaterals_details.php',
    'ajax/record_overdue_payment' => AJAX_DIR . '/record_overdue_payment.php',
    'ajax/record_payment' => AJAX_DIR . '/record_payment.php',
    'ajax/save_collateral' => AJAX_DIR . '/save_collateral.php',
    'ajax/search_guarantors' => AJAX_DIR . '/search_guarantors.php',
    'ajax/search_loan_officers' => AJAX_DIR . '/search_loan_officers.php',
    'ajax/search_loans' => AJAX_DIR . '/search_loans.php',
    'ajax/undo_loan_status.php' => API_DIR . '/undo_loan_status.php',
    'ajax/update_collateral_status' => AJAX_DIR . '/update_collateral_status.php',
    'ajax/update_loan_document' => AJAX_DIR . '/update_loan_document.php',
    'ajax/update_penalty' => AJAX_DIR . '/update_penalty.php',
    'ajax/update_risk_level' => AJAX_DIR . '/update_risk_level.php',
    'ajax/update_strategy_status' => AJAX_DIR . '/update_strategy_status.php',
    'ajax/upload_additional' => AJAX_DIR . '/upload_additional.php',
    'ajax/upload_collateral_doc' => AJAX_DIR . '/upload_collateral_doc.php',
    'ajax/upload_disbursement_doc' => AJAX_DIR . '/upload_disbursement_doc.php',
    'ajax/upload_kyc' => AJAX_DIR . '/upload_kyc.php',
    'ajax/upload_loan_doc' => AJAX_DIR . '/upload_loan_doc.php',
    'ajax/upload_profile_doc' => AJAX_DIR . '/upload_profile_doc.php',

    // Communication Related
    'ajax/delete_email_template' => API_DIR . '/delete_email_template.php',
    'ajax/delete_feedback' => API_DIR . '/delete_feedback.php',
    'ajax/delete_notification' => API_DIR . '/delete_notification.php',
    'ajax/delete_sms_template' => API_DIR . '/delete_sms_template.php',
    'ajax/mark_notification_read' => API_DIR . '/mark_notification_read.php',
    'ajax/notification_bulk_actions' => API_DIR . '/notification_bulk_actions.php',
    'ajax/save_campaign' => API_DIR . '/save_campaign.php',
    'ajax/save_email_template' => API_DIR . '/save_email_template.php',
    'ajax/save_feedback' => API_DIR . '/save_feedback.php',
    'ajax/save_lead' => API_DIR . '/save_lead.php',
    'ajax/save_notification_preferences' => API_DIR . '/save_notification_preferences.php',
    'ajax/save_sms_template' => API_DIR . '/save_sms_template.php',
    'ajax/send_template_email' => AJAX_DIR . '/send_template_email.php',
    'ajax/setup_email_templates' => API_DIR . '/setup_email_templates.php',
    'ajax/setup_sms_templates' => API_DIR . '/setup_sms_templates.php',
    'ajax/update_feedback_status' => API_DIR . '/update_feedback_status.php',
    'ajax/update_loan_status' => AJAX_DIR . '/update_loan_status.php',

    // Documents Related
    'api/get_documents'    => API_DIR . '/get_documents.php',
    'api/get_documents.php' => API_DIR . '/get_documents.php',
    'api/delete_document'  => API_DIR . '/delete_document.php',
    'api/delete_document.php' => API_DIR . '/delete_document.php',
    'ajax/apply_signature' => AJAX_DIR . '/apply_signature.php',
    'ajax/assign_workflow_document' => AJAX_DIR . '/assign_workflow_document.php',
    'ajax/delete_workflow' => AJAX_DIR . '/delete_workflow.php',
    'ajax/get_active_workflows_list' => AJAX_DIR . '/get_active_workflows_list.php',
    'ajax/get_all_documents' => AJAX_DIR . '/get_all_documents.php',
    'ajax/get_my_tasks' => AJAX_DIR . '/get_my_tasks.php',
    'ajax/get_task_details' => AJAX_DIR . '/get_task_details.php',
    'ajax/get_user_signatures_list' => AJAX_DIR . '/get_user_signatures_list.php',
    'ajax/save_drawn_signature' => AJAX_DIR . '/save_drawn_signature.php',
    'ajax/save_journal' => AJAX_DIR . '/save_journal.php',
    'ajax/save_workflow' => AJAX_DIR . '/save_workflow.php',
    'ajax/update_task_status' => AJAX_DIR . '/update_task_status.php',
    'ajax/update_workflow' => AJAX_DIR . '/update_workflow.php',
    'ajax/upload_signature' => AJAX_DIR . '/upload_signature.php',
    'ajax/upload_signature.php' => AJAX_DIR . '/upload_signature.php',
    'upload_signature.php' => AJAX_DIR . '/upload_signature.php',

    // Users & Settings Related
    'ajax/assign_role' => AJAX_DIR . '/assign_role.php',
    'ajax/delete_user' => AJAX_DIR . '/delete_user.php',
    'ajax/get_all_users' => AJAX_DIR . '/get_all_users.php',
    'ajax/get_role' => AJAX_DIR . '/get_role.php',
    'ajax/get_users' => AJAX_DIR . '/get_users.php',
    'ajax/toggle_user' => AJAX_DIR . '/toggle_user.php',

    // ========================================================================
    // API ENDPOINTS - ACCOUNTS
    // ========================================================================
    'api/add_budget' => API_DIR . '/account/add_budget.php',
    'api/add_budget.php' => API_DIR . '/account/add_budget.php',
    'api/add_compound_journal' => API_DIR . '/account/add_compound_journal.php',
    'api/add_compound_journal.php' => API_DIR . '/account/add_compound_journal.php',
    'api/add_expense' => API_DIR . '/account/add_expense.php',
    'api/add_expense.php' => API_DIR . '/account/add_expense.php',
    'api/add_transaction' => API_DIR . '/account/add_transaction.php',
    'api/add_transaction.php' => API_DIR . '/account/add_transaction.php',
    'api/delete_account' => API_DIR . '/account/delete_account.php',
    'api/delete_account.php' => API_DIR . '/account/delete_account.php',
    'api/delete_account_category' => API_DIR . '/account/delete_account_category.php',
    'api/delete_account_category.php' => API_DIR . '/account/delete_account_category.php',
    'api/delete_budget' => API_DIR . '/account/delete_budget.php',
    'api/delete_budget.php' => API_DIR . '/account/delete_budget.php',
    'api/delete_expense' => API_DIR . '/account/delete_expense.php',
    'api/delete_expense.php' => API_DIR . '/account/delete_expense.php',
    'api/export_expenses' => API_DIR . '/account/export_expenses.php',
    'api/export_expenses.php' => API_DIR . '/account/export_expenses.php',
    'api/export_journals' => API_DIR . '/account/export_journals.php',
    'api/export_journals.php' => API_DIR . '/account/export_journals.php',
    'api/get_account' => API_DIR . '/account/get_account.php',
    'api/get_account.php' => API_DIR . '/account/get_account.php',
    'api/get_account_categories' => API_DIR . '/account/get_account_categories.php',
    'api/get_account_categories.php' => API_DIR . '/account/get_account_categories.php',
    'api/get_account_category' => API_DIR . '/account/get_account_category.php',
    'api/get_account_category.php' => API_DIR . '/account/get_account_category.php',
    'api/get_account_types' => API_DIR . '/account/get_account_types.php',
    'api/get_account_types.php' => API_DIR . '/account/get_account_types.php',
    'api/get_accounts' => API_DIR . '/account/get_accounts.php',
    'api/get_accounts.php' => API_DIR . '/account/get_accounts.php',
    'api/get_bank_accounts' => API_DIR . '/account/get_bank_accounts.php',
    'api/get_bank_accounts.php' => API_DIR . '/account/get_bank_accounts.php',
    'api/get_budget' => API_DIR . '/account/get_budget.php',
    'api/get_budget.php' => API_DIR . '/account/get_budget.php',
    'api/get_categories_by_type' => API_DIR . '/account/get_categories_by_type.php',
    'api/get_categories_by_type.php' => API_DIR . '/account/get_categories_by_type.php',
    'api/get_category_details' => API_DIR . '/account/get_category_details.php',
    'api/get_category_details.php' => API_DIR . '/account/get_category_details.php',
    'api/get_chart_of_accounts' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/get_chart_of_accounts.php' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/get_expense' => API_DIR . '/account/get_expense.php',
    'api/get_expense.php' => API_DIR . '/account/get_expense.php',
    'api/get_expenses' => API_DIR . '/account/get_expenses.php',
    'api/get_expenses.php' => API_DIR . '/account/get_expenses.php',
    'api/save_journal' => API_DIR . '/account/save_journal.php',
    'api/save_journal.php' => API_DIR . '/account/save_journal.php',
    'api/search_accounts' => API_DIR . '/account/search_accounts.php',
    'api/search_accounts.php' => API_DIR . '/account/search_accounts.php',
    'api/update_budget' => API_DIR . '/account/update_budget.php',
    'api/update_budget.php' => API_DIR . '/account/update_budget.php',
    'api/update_budget_status' => API_DIR . '/account/update_budget_status.php',
    'api/update_budget_status.php' => API_DIR . '/account/update_budget_status.php',
    'api/update_expense' => API_DIR . '/account/update_expense.php',
    'api/update_expense.php' => API_DIR . '/account/update_expense.php',
    'api/update_expense_status' => API_DIR . '/account/update_expense_status.php',
    'api/update_expense_status.php' => API_DIR . '/account/update_expense_status.php',
    'api/update_journal' => API_DIR . '/account/update_journal.php',
    'api/update_journal.php' => API_DIR . '/account/update_journal.php',
    'api/update_journal_status' => API_DIR . '/account/update_journal_status.php',
    'api/update_journal_status.php' => API_DIR . '/account/update_journal_status.php',
    'api/update_transaction' => API_DIR . '/account/update_transaction.php',
    'api/update_transaction.php' => API_DIR . '/account/update_transaction.php',
    'api/update_transaction_status' => API_DIR . '/account/update_transaction_status.php',
    'api/update_transaction_status.php' => API_DIR . '/account/update_transaction_status.php',
    'api/void_journal' => API_DIR . '/account/void_journal.php',
    'api/void_journal.php' => API_DIR . '/account/void_journal.php',

    // ========================================================================
    // API ENDPOINTS - ACCOUNTS
    // ========================================================================
    'api/accounts/add_budget' => API_DIR . '/account/add_budget.php',
    'api/accounts/add_budget.php' => API_DIR . '/account/add_budget.php',
    'api/accounts/add_compound_journal' => API_DIR . '/account/add_compound_journal.php',
    'api/accounts/add_compound_journal.php' => API_DIR . '/account/add_compound_journal.php',
    'api/accounts/add_expense' => API_DIR . '/account/add_expense.php',
    'api/accounts/add_expense.php' => API_DIR . '/account/add_expense.php',
    'api/accounts/add_transaction' => API_DIR . '/account/add_transaction.php',
    'api/accounts/add_transaction.php' => API_DIR . '/account/add_transaction.php',
    'api/accounts/delete_account' => API_DIR . '/account/delete_account.php',
    'api/accounts/delete_account.php' => API_DIR . '/account/delete_account.php',
    'api/accounts/delete_account_category' => API_DIR . '/account/delete_account_category.php',
    'api/accounts/delete_account_category.php' => API_DIR . '/account/delete_account_category.php',
    'api/accounts/delete_budget' => API_DIR . '/account/delete_budget.php',
    'api/accounts/delete_budget.php' => API_DIR . '/account/delete_budget.php',
    'api/accounts/delete_expense' => API_DIR . '/account/delete_expense.php',
    'api/accounts/delete_expense.php' => API_DIR . '/account/delete_expense.php',
    'api/accounts/export_expenses' => API_DIR . '/account/export_expenses.php',
    'api/accounts/export_expenses.php' => API_DIR . '/account/export_expenses.php',
    'api/accounts/export_journals' => API_DIR . '/account/export_journals.php',
    'api/accounts/export_journals.php' => API_DIR . '/account/export_journals.php',
    'api/accounts/get_account' => API_DIR . '/account/get_account.php',
    'api/accounts/get_account.php' => API_DIR . '/account/get_account.php',
    'api/accounts/get_account_categories' => API_DIR . '/account/get_account_categories.php',
    'api/accounts/get_account_categories.php' => API_DIR . '/account/get_account_categories.php',
    'api/accounts/get_account_category' => API_DIR . '/account/get_account_category.php',
    'api/accounts/get_account_category.php' => API_DIR . '/account/get_account_category.php',
    'api/accounts/get_account_types' => API_DIR . '/account/get_account_types.php',
    'api/accounts/get_account_types.php' => API_DIR . '/account/get_account_types.php',
    'api/accounts/get_accounts' => API_DIR . '/account/get_accounts.php',
    'api/accounts/get_accounts.php' => API_DIR . '/account/get_accounts.php',
    'api/accounts/get_bank_accounts' => API_DIR . '/account/get_bank_accounts.php',
    'api/accounts/get_bank_accounts.php' => API_DIR . '/account/get_bank_accounts.php',
    'api/accounts/get_budget' => API_DIR . '/account/get_budget.php',
    'api/accounts/get_budget.php' => API_DIR . '/account/get_budget.php',
    'api/accounts/get_categories_by_type' => API_DIR . '/account/get_categories_by_type.php',
    'api/accounts/get_categories_by_type.php' => API_DIR . '/account/get_categories_by_type.php',
    'api/accounts/get_category_details' => API_DIR . '/account/get_category_details.php',
    'api/accounts/get_category_details.php' => API_DIR . '/account/get_category_details.php',
    'api/accounts/get_chart_of_accounts' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/accounts/get_chart_of_accounts.php' => API_DIR . '/account/get_chart_of_accounts.php',
    'api/accounts/get_expense' => API_DIR . '/account/get_expense.php',
    'api/accounts/get_expense.php' => API_DIR . '/account/get_expense.php',
    'api/accounts/get_expenses' => API_DIR . '/account/get_expenses.php',
    'api/accounts/get_expenses.php' => API_DIR . '/account/get_expenses.php',
    'api/accounts/save_journal' => API_DIR . '/account/save_journal.php',
    'api/accounts/save_journal.php' => API_DIR . '/account/save_journal.php',
    'api/accounts/save_account' => API_DIR . '/account/save_account.php',
    'api/accounts/save_account.php' => API_DIR . '/account/save_account.php',
    'api/accounts/save_category' => API_DIR . '/account/save_category.php',
    'api/accounts/save_category.php' => API_DIR . '/account/save_category.php',
    'api/accounts/search_accounts' => API_DIR . '/account/search_accounts.php',
    'api/accounts/search_accounts.php' => API_DIR . '/account/search_accounts.php',
    'api/accounts/update_budget' => API_DIR . '/account/update_budget.php',
    'api/accounts/update_budget.php' => API_DIR . '/account/update_budget.php',
    'api/accounts/update_budget_status' => API_DIR . '/account/update_budget_status.php',
    'api/accounts/update_budget_status.php' => API_DIR . '/account/update_budget_status.php',
    'api/accounts/update_expense' => API_DIR . '/account/update_expense.php',
    'api/accounts/update_expense.php' => API_DIR . '/account/update_expense.php',
    'api/accounts/update_expense_status' => API_DIR . '/account/update_expense_status.php',
    'api/accounts/update_expense_status.php' => API_DIR . '/account/update_expense_status.php',
    'api/accounts/update_journal' => API_DIR . '/account/update_journal.php',
    'api/accounts/update_journal.php' => API_DIR . '/account/update_journal.php',
    'api/accounts/update_journal_status' => API_DIR . '/account/update_journal_status.php',
    'api/accounts/update_journal_status.php' => API_DIR . '/account/update_journal_status.php',
    'api/accounts/update_transaction' => API_DIR . '/account/update_transaction.php',
    'api/accounts/update_transaction.php' => API_DIR . '/account/update_transaction.php',
    'api/accounts/update_transaction_status' => API_DIR . '/account/update_transaction_status.php',
    'api/accounts/update_transaction_status.php' => API_DIR . '/account/update_transaction_status.php',
    'api/accounts/void_journal' => API_DIR . '/account/void_journal.php',
    'api/accounts/void_journal.php' => API_DIR . '/account/void_journal.php',

    // Bank Reconciliation (Direct Access)
    'api/get_bank_balance' => API_DIR . '/account/get_bank_balance.php',
    'api/get_bank_balance.php' => API_DIR . '/account/get_bank_balance.php',
    'api/create_reconciliation' => API_DIR . '/account/create_reconciliation.php',
    'api/create_reconciliation.php' => API_DIR . '/account/create_reconciliation.php',
    'api/get_bank_reconciliations' => API_DIR . '/account/get_bank_reconciliations.php',
    'api/get_bank_reconciliations.php' => API_DIR . '/account/get_bank_reconciliations.php',
    'api/delete_reconciliation' => API_DIR . '/account/delete_reconciliation.php',
    'api/delete_reconciliation.php' => API_DIR . '/account/delete_reconciliation.php',
    'api/update_reconciliation_status' => API_DIR . '/account/update_reconciliation_status.php',
    'api/update_reconciliation_status.php' => API_DIR . '/account/update_reconciliation_status.php',
    
    // Invoice APIs
    'api/account/get_invoices' => API_DIR . '/account/get_invoices.php',
    'api/account/get_invoices.php' => API_DIR . '/account/get_invoices.php',
    'api/account/save_invoice' => API_DIR . '/account/save_invoice.php',
    'api/account/save_invoice.php' => API_DIR . '/account/save_invoice.php',
    'api/account/delete_invoice' => API_DIR . '/account/delete_invoice.php',
    'api/account/delete_invoice.php' => API_DIR . '/account/delete_invoice.php',
    'api/account/update_invoice_status' => API_DIR . '/account/update_invoice_status.php',
    'api/account/update_invoice_status.php' => API_DIR . '/account/update_invoice_status.php',
    'api/account/export_invoices' => API_DIR . '/account/export_invoices.php',
    'api/account/export_invoices.php' => API_DIR . '/account/export_invoices.php',
    'api/account/get_income_statement' => API_DIR . '/account/get_income_statement.php',
    'api/account/get_income_statement.php' => API_DIR . '/account/get_income_statement.php',
    'api/account/export_income_statement' => API_DIR . '/account/export_income_statement.php',
    'api/account/export_income_statement.php' => API_DIR . '/account/export_income_statement.php',
    'api/account/get_products' => API_DIR . '/account/get_products.php',
    'api/account/get_products.php' => API_DIR . '/account/get_products.php',

    // Purchase Order APIs
    'api/account/get_purchase_orders' => API_DIR . '/account/get_purchase_orders.php',
    'api/account/get_purchase_orders.php' => API_DIR . '/account/get_purchase_orders.php',
    'api/account/update_purchase_order_status' => API_DIR . '/account/update_purchase_order_status.php',
    'api/account/update_purchase_order_status.php' => API_DIR . '/account/update_purchase_order_status.php',
    'api/account/export_purchase_orders' => API_DIR . '/account/export_purchase_orders.php',
    'api/account/export_purchase_orders.php' => API_DIR . '/account/export_purchase_orders.php',
    'api/account/save_purchase_order' => API_DIR . '/account/save_purchase_order.php',
    'api/account/save_purchase_order.php' => API_DIR . '/account/save_purchase_order.php',
    'api/account/get_purchase_order' => API_DIR . '/account/get_purchase_order.php',
    'api/account/get_purchase_order.php' => API_DIR . '/account/get_purchase_order.php',
    'api/account/delete_purchase_order' => API_DIR . '/account/delete_purchase_order.php',
    'api/account/delete_purchase_order.php' => API_DIR . '/account/delete_purchase_order.php',

    // Sales Order APIs
    'api/account/get_sales_orders' => API_DIR . '/account/get_sales_orders.php',
    'api/account/get_sales_orders.php' => API_DIR . '/account/get_sales_orders.php',
    'api/account/save_sales_order' => API_DIR . '/account/save_sales_order.php',
    'api/account/save_sales_order.php' => API_DIR . '/account/save_sales_order.php',
    'api/account/update_sales_order_status' => API_DIR . '/account/update_sales_order_status.php',
    'api/account/update_sales_order_status.php' => API_DIR . '/account/update_sales_order_status.php',
    'api/account/delete_sales_order' => API_DIR . '/account/delete_sales_order.php',
    'api/account/delete_sales_order.php' => API_DIR . '/account/delete_sales_order.php',
    'api/account/get_customer' => API_DIR . '/account/get_customer.php',
    'api/account/get_customer.php' => API_DIR . '/account/get_customer.php',
    'api/account/get_tax_rates' => API_DIR . '/account/get_tax_rates.php',
    'api/account/get_tax_rates.php' => API_DIR . '/account/get_tax_rates.php',
    'api/account/get_sales_order_items' => API_DIR . '/account/get_sales_order_items.php',
    'api/account/get_sales_order_items.php' => API_DIR . '/account/get_sales_order_items.php',

    // Purchase Return APIs
    'api/account/get_purchase_returns' => API_DIR . '/account/get_purchase_returns.php',
    'api/account/get_purchase_returns.php' => API_DIR . '/account/get_purchase_returns.php',
    'api/account/save_purchase_return' => API_DIR . '/account/save_purchase_return.php',
    'api/account/save_purchase_return.php' => API_DIR . '/account/save_purchase_return.php',
    'api/account/update_purchase_return_status' => API_DIR . '/account/update_purchase_return_status.php',
    'api/account/update_purchase_return_status.php' => API_DIR . '/account/update_purchase_return_status.php',
    'api/account/delete_purchase_return' => API_DIR . '/account/delete_purchase_return.php',
    'api/account/delete_purchase_return.php' => API_DIR . '/account/delete_purchase_return.php',


    // ========================================================================
    // API ENDPOINTS - CUSTOMERS

    // ========================================================================
    'api/add_group_members' => API_DIR . '/add_group_members.php',
    'api/create_customer_group' => API_DIR . '/create_customer_group.php',
    'api/delete_customer' => API_DIR . '/delete_customer.php',
    'api/delete_customer_group' => API_DIR . '/delete_customer_group.php',
    'api/export_group_members' => API_DIR . '/export_group_members.php',
    'api/get_customer_documents' => API_DIR . '/get_customer_documents.php',
    'api/get_customer_group' => API_DIR . '/get_customer_group.php',
    'api/get_customers' => API_DIR . '/get_customers.php',
    'api/get_feedback' => API_DIR . '/get_feedback.php',
    'api/get_group_members' => API_DIR . '/get_group_members.php',
    'api/import_customers' => API_DIR . '/import_customers.php',
    'api/process_edit_customer' => API_DIR . '/process_edit_customer.php',
    'api/process_register' => API_DIR . '/process_register.php',
    'api/refresh_dynamic_group' => API_DIR . '/refresh_dynamic_group.php',
    'api/remove_group_member' => API_DIR . '/remove_group_member.php',
    'api/update_customer_group' => API_DIR . '/update_customer_group.php',
    'api/get_customer' => API_DIR . '/account/get_customer.php',
    'api/get_customer.php' => API_DIR . '/account/get_customer.php',
    'sales_invoices' => BMS_DIR . '/invoice/invoices.php',
    'customer_payments' => BMS_DIR . '/customer/customer_payments.php',

    // ========================================================================
    // API ENDPOINTS - LOANS
    // ========================================================================
    'api/activate_loan' => API_DIR . '/activate_loan.php',
    'api/add_guarantor' => AJAX_DIR . '/add_guarantor.php',
    'api/add_guarantor.php' => AJAX_DIR . '/add_guarantor.php',
    'api/approve_loan' => API_DIR . '/approve_loan.php',
    'api/apply_loan' => AJAX_DIR . '/apply_loan.php',
    'api/apply_loan.php' => AJAX_DIR . '/apply_loan.php',
    'api/calculate_penalties' => AJAX_DIR . '/calculate_penalties.php',
    'api/collateral_verification' => API_DIR . '/collateral_verification.php',
    'api/credit_check' => API_DIR . '/credit_check.php',
    'api/delete_collateral_document' => AJAX_DIR . '/delete_collateral_document.php',
    'api/delete_payment' => AJAX_DIR . '/delete_payment.php',
    'api/delete_product' => API_DIR . '/delete_product.php',
    'api/disburse_loan' => API_DIR . '/disburse_loan.php',
    'api/disburse_loan.php' => API_DIR . '/disburse_loan.php',
    'api/escalate_cases' => API_DIR . '/escalate_cases.php',
    'api/export_payments' => API_DIR . '/export_payments.php',
    'api/fetch_districts' => API_DIR . '/fetch_districts.php',
    'api/fetch_regions' => API_DIR . '/fetch_regions.php',
    'api/fix_schema' => API_DIR . '/fix_schema.php',
    'api/fix_schema_v2' => API_DIR . '/fix_schema_v2.php',
    'api/generate_loan_contract' => API_DIR . '/generate_loan_contract.php',
    'api/generate_loan_pdf' => API_DIR . '/generate_loan_pdf.php',
    'api/generate_loan_portfolio_report' => API_DIR . '/generate_loan_portfolio_report.php',
    'api/generate_repayment_schedule' => API_DIR . '/generate_repayment_schedule.php',
    'api/generate_schedules' => API_DIR . '/generate_schedules.php',
    'api/get_collateral_attachments' => API_DIR . '/get_collateral_attachments.php',
    'api/get_collateral_details' => API_DIR . '/get_collateral_details.php',
    'api/get_collateral_documents' => API_DIR . '/get_collateral_documents.php',
    'api/get_collaterals' => API_DIR . '/get_collaterals.php',
    'api/get_contact_history' => API_DIR . '/get_contact_history.php',
    'api/get_loan_details' => API_DIR . '/get_loan_details.php',
    'api/get_loan_details.php' => API_DIR . '/get_loan_details.php',
    'api/get_loan_documents' => API_DIR . '/get_loan_documents.php',
    'api/get_loan_officers' => API_DIR . '/get_loan_officers.php',
    'api/get_loan_products' => API_DIR . '/get_loan_products.php',
    'api/get_loans' => API_DIR . '/get_loans.php',
    'api/get_loans.php' => API_DIR . '/get_loans.php',
    'api/get_loans_for_collateral' => API_DIR . '/get_loans_for_collateral.php',
    'api/get_transactions' => API_DIR . '/get_transactions.php',
    'api/save_transaction' => API_DIR . '/save_transaction.php',
    'api/search_customers' => API_DIR . '/search_customers.php',
    'api/search_guarantors' => API_DIR . '/search_guarantors.php',
    'api/search_loan_officers' => API_DIR . '/search_loan_officers.php',
    'api/get_loans_without_schedules' => API_DIR . '/get_loans_without_schedules.php',
    'api/get_overdue_loans' => API_DIR . '/get_overdue_loans.php',
    'api/get_payment_details' => API_DIR . '/get_payment_details.php',
    'api/get_processes' => API_DIR . '/get_processes.php',
    'api/get_product_details' => API_DIR . '/get_product_details.php',
    'api/get_products' => API_DIR . '/get_products.php',
    'api/get_receipt' => AJAX_DIR . '/get_receipt.php',
    'api/get_schedules' => API_DIR . '/get_schedules.php',
    'api/loan_collaterals_details' => COMING_SOON_FILE,
    'api/loan_settlement' => API_DIR . '/loan_settlement.php',
    'api/loan_topup' => API_DIR . '/loan_topup.php',
    'api/log_contact' => API_DIR . '/log_contact.php',
    'api/mark_defaulted' => API_DIR . '/mark_defaulted.php',
    'api/mark_repaid' => API_DIR . '/mark_repaid.php',
    'api/process_bulk_payment' => API_DIR . '/process_bulk_payment.php',
    'api/record_payment' => AJAX_DIR . '/record_payment.php',
    'api/reject_loan' => API_DIR . '/reject_loan.php',
    'api/reschedule_payment' => API_DIR . '/reschedule_payment.php',
    'api/reverse_payment' => API_DIR . '/reverse_payment.php',
    'api/risk_assessment' => API_DIR . '/risk_assessment.php',
    'api/save_collateral' => API_DIR . '/save_collateral.php',
    'api/save_product' => API_DIR . '/save_product.php',
    'api/undo_loan_status' => API_DIR . '/undo_loan_status.php',
    'api/update_collateral_status' => API_DIR . '/update_collateral_status.php',
    'api/update_guarantor' => API_DIR . '/update_guarantor.php',
    'api/update_loan_document' => AJAX_DIR . '/update_loan_document.php',
    'api/update_penalty' => AJAX_DIR . '/update_penalty.php',
    'api/upload_collateral_doc' => AJAX_DIR . '/upload_collateral_doc.php',
    'api/upload_disbursement_doc' => AJAX_DIR . '/upload_disbursement_doc.php',
    'api/upload_loan_doc' => AJAX_DIR . '/upload_loan_doc.php',

    // ========================================================================
    // API ENDPOINTS - REPORTS
    // ========================================================================
    'api/generate_financial_report' => API_DIR . '/generate_financial_report.php',
    'api/get_access_log' => API_DIR . '/get_access_log.php',

    // ========================================================================
    // API ENDPOINTS - COMMUNICATION
    // ========================================================================
    'api/delete_email_template' => API_DIR . '/delete_email_template.php',
    'api/delete_email_template.php' => API_DIR . '/delete_email_template.php',
    'api/delete_feedback' => API_DIR . '/delete_feedback.php',
    'api/delete_feedback.php' => API_DIR . '/delete_feedback.php',
    'api/delete_notification' => API_DIR . '/delete_notification.php',
    'api/delete_notification.php' => API_DIR . '/delete_notification.php',
    'api/delete_sms_template' => API_DIR . '/delete_sms_template.php',
    'api/delete_sms_template.php' => API_DIR . '/delete_sms_template.php',
    'api/get_campaigns' => API_DIR . '/get_campaigns.php',
    'api/get_campaigns.php' => API_DIR . '/get_campaigns.php',
    'api/get_email_templates' => API_DIR . '/get_email_templates.php',
    'api/get_email_templates.php' => API_DIR . '/get_email_templates.php',
    'api/get_leads' => API_DIR . '/get_leads.php',
    'api/get_leads.php' => API_DIR . '/get_leads.php',
    'api/get_notifications' => API_DIR . '/get_notifications.php',
    'api/get_notifications.php' => API_DIR . '/get_notifications.php',
    'api/get_sms_templates' => API_DIR . '/get_sms_templates.php',
    'api/get_sms_templates.php' => API_DIR . '/get_sms_templates.php',
    'api/mark_notification_read' => API_DIR . '/mark_notification_read.php',
    'api/mark_notification_read.php' => API_DIR . '/mark_notification_read.php',
    'api/notification_bulk_actions' => API_DIR . '/notification_bulk_actions.php',
    'api/notification_bulk_actions.php' => API_DIR . '/notification_bulk_actions.php',
    'api/save_campaign' => API_DIR . '/save_campaign.php',
    'api/save_campaign.php' => API_DIR . '/save_campaign.php',
    'api/save_email_template' => API_DIR . '/save_email_template.php',
    'api/save_email_template.php' => API_DIR . '/save_email_template.php',
    'api/save_feedback' => API_DIR . '/save_feedback.php',
    'api/save_feedback.php' => API_DIR . '/save_feedback.php',
    'api/save_lead' => API_DIR . '/save_lead.php',
    'api/save_lead.php' => API_DIR . '/save_lead.php',
    'api/save_notification_preferences' => API_DIR . '/save_notification_preferences.php',
    'api/save_notification_preferences.php' => API_DIR . '/save_notification_preferences.php',
    'api/save_sms_template' => API_DIR . '/save_sms_template.php',
    'api/save_sms_template.php' => API_DIR . '/save_sms_template.php',
    'api/send_reminders' => API_DIR . '/send_reminders.php',
    'api/setup_email_templates' => API_DIR . '/setup_email_templates.php',
    'api/setup_sms_templates' => API_DIR . '/setup_sms_templates.php',
    'api/test_email_config' => API_DIR . '/test_email_config.php',
    'api/test_sms_config' => API_DIR . '/test_sms_config.php',
    'api/update_feedback_status' => API_DIR . '/update_feedback_status.php',
    'api/update_feedback_status.php' => API_DIR . '/update_feedback_status.php',
    'api/verify_esignature' => API_DIR . '/verify_esignature.php',

    // ========================================================================
    // API ENDPOINTS - DOCUMENTS
    // ========================================================================
    'api/delete_collateral_document' => API_DIR . '/document/delete_collateral_document.php',
    'api/delete_collateral_document.php' => API_DIR . '/document/delete_collateral_document.php',
    'api/delete_document' => API_DIR . '/document/delete_document.php',
    'api/delete_document.php' => API_DIR . '/document/delete_document.php',
    'api/delete_document_template' => API_DIR . '/document/delete_document_template.php',
    'api/delete_document_template.php' => API_DIR . '/document/delete_document_template.php',
    'api/delete_signature' => API_DIR . '/document/delete_signature.php',
    'api/delete_signature.php' => API_DIR . '/document/delete_signature.php',
    'api/get_all_documents' => API_DIR . '/document/get_all_documents.php',
    'api/get_all_documents.php' => API_DIR . '/document/get_all_documents.php',
    'api/get_collateral_attachments' => API_DIR . '/document/get_collateral_attachments.php',
    'api/get_collateral_attachments.php' => API_DIR . '/document/get_collateral_attachments.php',
    'api/get_collateral_documents' => API_DIR . '/document/get_collateral_documents.php',
    'api/get_collateral_documents.php' => API_DIR . '/document/get_collateral_documents.php',
    'api/get_compliance_documents' => API_DIR . '/document/get_compliance_documents.php',
    'api/get_compliance_documents.php' => API_DIR . '/document/get_compliance_documents.php',
    'api/get_document_template' => API_DIR . '/document/get_document_template.php',
    'api/get_document_template.php' => API_DIR . '/document/get_document_template.php',
    'api/get_documents' => API_DIR . '/document/get_documents.php',
    'api/get_documents.php' => API_DIR . '/document/get_documents.php',
    'api/get_loan_documents' => API_DIR . '/document/get_loan_documents.php',
    'api/get_loan_documents.php' => API_DIR . '/document/get_loan_documents.php',
    'api/quick_upload_document' => API_DIR . '/document/quick_upload_document.php',
    'api/quick_upload_document.php' => API_DIR . '/document/quick_upload_document.php',
    'api/send_template_email' => API_DIR . '/document/send_template_email.php',
    'api/send_template_email.php' => API_DIR . '/document/send_template_email.php',
    'api/update_customer_document' => API_DIR . '/document/update_customer_document.php',
    'api/update_customer_document.php' => API_DIR . '/document/update_customer_document.php',
    'api/update_loan_document' => API_DIR . '/document/update_loan_document.php',
    'api/update_loan_document.php' => API_DIR . '/document/update_loan_document.php',
    'api/upload_collateral_doc' => API_DIR . '/document/upload_collateral_doc.php',
    'api/upload_collateral_doc.php' => API_DIR . '/document/upload_collateral_doc.php',
    'api/upload_signature' => API_DIR . '/document/upload_signature.php',
    'api/upload_signature.php' => API_DIR . '/document/upload_signature.php',
    'api/upload_signed_document' => API_DIR . '/document/upload_signed_document.php',
    'api/upload_signed_document.php' => API_DIR . '/document/upload_signed_document.php',
    // Legacy signature/workflow endpoints (keeping for backwards compatibility)
    'api/get_pending_signatures' => API_DIR . '/get_pending_signatures.php',
    'api/get_pending_signatures.php' => API_DIR . '/get_pending_signatures.php',
    'api/get_signature_history' => API_DIR . '/get_signature_history.php',
    'api/get_signature_history.php' => API_DIR . '/get_signature_history.php',
    'api/get_templates' => API_DIR . '/get_templates.php',
    'api/get_templates.php' => API_DIR . '/get_templates.php',
    'api/get_user_signatures' => API_DIR . '/get_user_signatures.php',
    'api/get_user_signatures.php' => API_DIR . '/get_user_signatures.php',
    'api/get_workflows' => API_DIR . '/get_workflows.php',
    'api/get_workflows.php' => API_DIR . '/get_workflows.php',
    'api/save_document_template' => AJAX_DIR . '/save_document_template.php',
    'api/save_document_template.php' => AJAX_DIR . '/save_document_template.php',

    // ========================================================================
    // API ENDPOINTS - USERS & SETTINGS
    // ========================================================================
    'api/assign_role' => API_DIR . '/assign_role.php',
    'api/delete_user' => API_DIR . '/delete_user.php',
    'api/get_role' => API_DIR . '/get_role.php',
    'api/get_users' => API_DIR . '/get_users.php',
    'api/toggle_user' => API_DIR . '/toggle_user.php',

    // ========================================================================
    // API ENDPOINTS - PRODUCTS
    // ========================================================================
    'api/get_products' => API_DIR . '/get_products.php',
    'api/get_products.php' => API_DIR . '/get_products.php',
    'api/get_categories' => API_DIR . '/get_categories.php',
    'api/get_categories.php' => API_DIR . '/get_categories.php',
    'api/create_category' => API_DIR . '/create_category.php',
    'api/create_category.php' => API_DIR . '/create_category.php',
    'api/open_cash_drawer' => API_DIR . '/open_cash_drawer.php',
    'api/open_cash_drawer.php' => API_DIR . '/open_cash_drawer.php',
    'api/get_stock_counts' => API_DIR . '/get_stock_counts.php',
    'api/get_stock_counts.php' => API_DIR . '/get_stock_counts.php',
    'api/export_products' => API_DIR . '/export_products.php',
    'api/export_products.php' => API_DIR . '/export_products.php',
    'api/save_brand' => API_DIR . '/save_brand.php',
    'api/save_brand.php' => API_DIR . '/save_brand.php',
    'api/delete_brand' => API_DIR . '/delete_brand.php',
    'api/delete_brand.php' => API_DIR . '/delete_brand.php',
    'api/update_category' => API_DIR . '/update_category.php',
    'api/update_category.php' => API_DIR . '/update_category.php',
    'api/delete_category' => API_DIR . '/delete_category.php',
    'api/delete_category.php' => API_DIR . '/delete_category.php',
    'api/create_product' => API_DIR . '/create_product.php',
    'api/create_product.php' => API_DIR . '/create_product.php',
    'api/import_products' => API_DIR . '/import_products.php',
    'api/import_products.php' => API_DIR . '/import_products.php',

    // Purchase Returns
    'api/get_purchase_returns' => API_DIR . '/get_purchase_returns.php',
    'api/get_purchase_returns.php' => API_DIR . '/get_purchase_returns.php',
    'api/get_purchase_return_stats' => API_DIR . '/get_purchase_return_stats.php',
    'api/get_purchase_return_stats.php' => API_DIR . '/get_purchase_return_stats.php',
    'api/create_purchase_return' => API_DIR . '/create_purchase_return.php',
    'api/create_purchase_return.php' => API_DIR . '/create_purchase_return.php',
    'api/update_purchase_return' => API_DIR . '/update_purchase_return.php',
    'api/update_purchase_return.php' => API_DIR . '/update_purchase_return.php',
    'api/update_purchase_return_status' => API_DIR . '/update_purchase_return_status.php',
    'api/update_purchase_return_status.php' => API_DIR . '/update_purchase_return_status.php',
    'api/get_purchase_return' => API_DIR . '/get_purchase_return.php',
    'api/get_purchase_return.php' => API_DIR . '/get_purchase_return.php',
    'api/delete_purchase_return' => API_DIR . '/delete_purchase_return.php',
    'api/delete_purchase_return.php' => API_DIR . '/delete_purchase_return.php',

    // Missing CRM & Notification APIs
    'api/get_leads' => API_DIR . '/get_leads.php',
    'api/get_leads.php' => API_DIR . '/get_leads.php',
    'api/get_campaigns' => API_DIR . '/get_campaigns.php',
    'api/get_campaigns.php' => API_DIR . '/get_campaigns.php',
    'api/get_notifications' => API_DIR . '/get_notifications.php',
    'api/get_notifications.php' => API_DIR . '/get_notifications.php',
    
    // Customers
    'api/add_customer' => API_DIR . '/add_customer.php',
    'api/add_customer.php' => API_DIR . '/add_customer.php',

    // Dashboard
    'api/get_performance_data' => API_DIR . '/get_performance_data.php',
    'api/get_performance_data.php' => API_DIR . '/get_performance_data.php',

    // Backup & Restore
    'api/create_backup' => API_DIR . '/create_backup.php',
    'api/create_backup.php' => API_DIR . '/create_backup.php',
    'api/get_backup_list' => API_DIR . '/get_backup_list.php',
    'api/get_backup_list.php' => API_DIR . '/get_backup_list.php',
    'api/delete_backup' => API_DIR . '/delete_backup.php',
    'api/delete_backup.php' => API_DIR . '/delete_backup.php',
    'api/download_backup' => API_DIR . '/download_backup.php',
    'api/download_backup.php' => API_DIR . '/download_backup.php',
];

/**
 * Get the clean URL for a page
 * @param string $page The page identifier (e.g., 'accounts/journals')
 * @return string The clean URL
 */
if (!function_exists('getUrl')) {
    /**
     * Get the clean URL for a page
     * @param string $page The page identifier (e.g., 'accounts/journals')
     * @return string The clean URL
     */
    function getUrl($page) {
        global $routes;
        
        // Calculate the base subfolder where the project is located
        $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $proj_root = str_replace('\\', '/', ROOT_DIR);
        $base_path = trim(str_replace($doc_root, '', $proj_root), '/');
        $base_url = !empty($base_path) ? '/' . $base_path : '/';

        // Strip leading slash from page identifier for uniform lookup
        $cleanPage = ltrim($page, '/');
        
        $finalRoute = $cleanPage;

        // 1. If it's already a direct key in routes, no transformation needed
        if (isset($routes[$cleanPage])) {
            $finalRoute = $cleanPage;
        }
        // 2. If it has .php, try to find the clean version
        else if (str_ends_with($cleanPage, '.php')) {
            $noPhp = substr($cleanPage, 0, -4);
            if (isset($routes[$noPhp])) {
                $finalRoute = $noPhp;
            } else {
                $finalRoute = preg_replace('/\.php$/', '', $cleanPage);
            }
        }

        // Combine with base path for final URL
        return rtrim($base_url, '/') . '/' . ltrim($finalRoute, '/');
    }
}



/**
 * Route handler - processes clean URLs
 * This should be called from .htaccess or index.php
 */
if (!function_exists('handleRoute')) {
    /**
     * Route handler - processes clean URLs
     * This should be called from .htaccess or index.php
     */
    function handleRoute() {
        global $routes, $pdo, $pdo_accounts;
        
        // Get the request URI and clean it up
        $request_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri_no_query = strtok($request_uri, '?');
        
        // Calculate the base subfolder where the project is located
        // Example: if project is in /wamp64/www/vikundi, base_root is /vikundi
        $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        $proj_root = str_replace('\\', '/', ROOT_DIR);
        $base_path = trim(str_replace($doc_root, '', $proj_root), '/');

        // Strip the base subfolder from the URI
        $clean_uri = trim($uri_no_query, '/');
        if (!empty($base_path) && str_starts_with($clean_uri, $base_path)) {
            $clean_uri = trim(substr($clean_uri, strlen($base_path)), '/');
        }

        // Attempt to handle root URL if empty
        if (empty($clean_uri)) {
            $clean_uri = 'dashboard'; // or whatever default
        }

        // 1. Map lookup
        if (isset($routes[$clean_uri])) {
            $file = $routes[$clean_uri];
            if (file_exists($file)) {
                require_once $file;
                return true;
            } else {
                // File is mapped but missing - Show Coming Soon
                if (file_exists(COMING_SOON_FILE)) {
                    require_once COMING_SOON_FILE;
                    return true;
                }
            }
        }

        // 2. Fallback: Literal files in root or with .php
        $possible_files = [
            ROOT_DIR . '/' . ltrim($clean_uri, '/'),
            ROOT_DIR . '/' . ltrim($clean_uri, '/') . '.php'
        ];

        foreach ($possible_files as $f) {
            if (file_exists($f) && is_file($f)) {
                $ext = pathinfo($f, PATHINFO_EXTENSION);
                
                // Allow non-PHP files (assets) OR PHP files that are in internal folders
                $isInternal = str_contains($uri_no_query, 'actions/') || 
                              str_contains($uri_no_query, 'ajax/') || 
                              str_contains($uri_no_query, 'api/');

                if ($ext !== 'php' || $isInternal) {
                    require_once $f;
                    return true;
                }
            }
        }

        return false;
    }
}

/**
 * Get relative path from module to root
 * @param string $module The module name (e.g., 'accounts', 'customers')
 * @return string The relative path to root
 */
if (!function_exists('getRelativeRoot')) {
    /**
     * Get relative path from module to root
     * @param string $module The module name (e.g., 'accounts', 'customers')
     * @return string The relative path to root
     */
    function getRelativeRoot($module = '') {
        switch ($module) {
            case 'accounts':
            case 'customers':
            case 'communication':
            case 'document':
            case 'integrations':
            case 'profile':
            case 'resources':
            case 'users':
            case 'loan':
                return '../../../';
            default:
                return '';
        }
    }
}

/**
 * Build a clean URL with domain
 * @param string $page The page identifier
 * @return string Full URL with domain
 */
if (!function_exists('buildUrl')) {
    /**
     * Build a clean URL with domain
     * @param string $page The page identifier
     * @return string Full URL with domain
     */
    function buildUrl($page) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $domain = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $domain . getUrl($page);
    }
}



/**
 * Redirect to a clean URL
 * @param string $page The page identifier
 */
if (!function_exists('redirectTo')) {
    /**
     * Redirect to a clean URL
     * @param string $page The page identifier
     */
    function redirectTo($page) {
        if (empty($page)) return;
        
        $target = getUrl($page);
        $current = strtok($_SERVER['REQUEST_URI'], '?');
        
        // Avoid infinite redirect loop
        if ($current === $target) {
            return;
        }
        
        header('Location: ' . $target);
        exit();
    }
}

/**
 * Includes the main application header
 */
if (!function_exists('includeHeader')) {
    /**
     * Includes the main application header
     */
    function includeHeader() {
        global $pdo, $pdo_accounts;
        if (file_exists(HEADER_FILE)) {
            require_once HEADER_FILE;
        }
    }
}

/**
 * Includes the main application footer
 */
if (!function_exists('includeFooter')) {
    /**
     * Includes the main application footer
     */
    function includeFooter() {
        global $pdo, $pdo_accounts;
        if (file_exists(FOOTER_FILE)) {
            require_once FOOTER_FILE;
        }
    }
}

?>
