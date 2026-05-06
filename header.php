<?php
// session_start(); // Handled by roots.php
require_once __DIR__ . '/includes/config.php';
include_once __DIR__ . '/actions/auto_terminate_members.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT username, first_name, middle_name, last_name FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$username = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
if (empty($username)) $username = $user['username'];

// Get user role for menu permissions
$role_stmt = $pdo->prepare("SELECT r.role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
$role_stmt->execute([$_SESSION['user_id']]);
$role_data = $role_stmt->fetch();
$user_role = $role_data['role_name'] ?? 'user';
$user_role_lower = strtolower($user_role); // Normalized role check

// Get company type and branding from settings
$settings_all_stmt = $pdo->prepare("SELECT setting_key, setting_value FROM group_settings");
$settings_all_stmt->execute();
$gs = $settings_all_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$company_type = $gs['company_type'] ?? 'microfinance';
$group_name = $gs['group_name'] ?? 'KIKUNDI';
$group_logo = $gs['group_logo'] ?? 'logo1.png';

// AUDIT LOGGING: Track Navigation with REAL Page Names from URL
try {
    // Skip AJAX requests - don't log those
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_GET['ajax']) || isset($_GET['datatable']);
    
    if (!$is_ajax && isset($_SESSION['user_id'])) {
        require_once __DIR__ . '/includes/activity_logger.php';
        
        // Use URL path to determine real page name
        $uri = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        $uri_clean = strtok($uri, '?'); // Remove query string

        // Map URL paths to friendly page names
        $page_name_map = [
            ''                          => ['en' => 'Dashboard',            'sw' => 'Dashibodi'],
            'dashboard'                 => ['en' => 'Dashboard',            'sw' => 'Dashibodi'],
            'customers'                 => ['en' => 'Members List',         'sw' => 'Orodha ya Wanachama'],
            'member_approvals'          => ['en' => 'Member Approvals',     'sw' => 'Idhini za Wanachama'],
            'manage_contributions'      => ['en' => 'Contributions',        'sw' => 'Michango'],
            'manage_shares'             => ['en' => 'Shares',               'sw' => 'Hisa'],
            'manage_fines'              => ['en' => 'Fines',                'sw' => 'Faini'],
            'expenses'                  => ['en' => 'Expenses',             'sw' => 'Matumizi'],
            'death_expenses'            => ['en' => 'Funeral Support',      'sw' => 'Misaada ya Msiba'],
            'manage_loans'              => ['en' => 'Loans',                'sw' => 'Mikopo'],
            'loan_repayments'           => ['en' => 'Loan Repayments',      'sw' => 'Marejesho ya Mikopo'],
            'users'                     => ['en' => 'System Users',         'sw' => 'Watumiaji wa Mfumo'],
            'user_roles'                => ['en' => 'Role Permissions',     'sw' => 'Nafasi na Mamlaka'],
            'profile'                   => ['en' => 'My Profile',           'sw' => 'Wasifu Wangu'],
            'system_settings'           => ['en' => 'System Settings',      'sw' => 'Mipangilio ya Mfumo'],
            'audit-logs'                => ['en' => 'Activity Logs',        'sw' => 'Kumbukumbu za Shughuli'],
            'activity-logs'             => ['en' => 'Activity Logs',        'sw' => 'Kumbukumbu za Shughuli'],
            'audit_logs'                => ['en' => 'Activity Logs',        'sw' => 'Kumbukumbu za Shughuli'],
            'financial_ledger'          => ['en' => 'Financial Ledger',     'sw' => 'Rejesta ya Fedha'],
            'reports/financial_ledger'  => ['en' => 'Financial Ledger',     'sw' => 'Rejesta ya Fedha'],
            'petty_cash'                => ['en' => 'Petty Cash',           'sw' => 'Vocha za Petty Cash'],
            'budget'                    => ['en' => 'Budget',               'sw' => 'Bajeti'],
        ];

        $lang = $_SESSION['preferred_language'] ?? 'en';
        
        if (isset($page_name_map[$uri_clean])) {
            $page_label = $page_name_map[$uri_clean][$lang];
            $ref_label  = $page_name_map[$uri_clean]['en']; // Always use English for reference ID
        } elseif (isset($page_title)) {
            $page_label = $page_title;
            $ref_label  = $page_title;
        } else {
            // Fallback: make something readable from URL
            $page_label = ucwords(str_replace(['-', '_'], ' ', basename($uri_clean, '.php')));
            $ref_label  = $page_label;
        }

        // Use the new centralized logger function
        logView($page_label, $ref_label);
    }
} catch (Exception $e) { }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title : 'VIKUNDI MANAGEMENT SYSTEM'; ?></title>
    
    <!-- jQuery first -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>

    <!-- Font Awesome 5 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- DataTables JS & Extensions -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <link rel="stylesheet" href="/style.css">

    <style>
        /* Main CSS Overrides */
        
        /* Adjust main content to account for sticky header */
        .container.mt-4 {
            padding-top: 20px;
        }
        
        /* Smooth scrolling for anchor links */
        html {
            scroll-padding-top: 80px;
        }

        /* Compact dropdowns */
        .dropdown-menu {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            max-height: 80vh;
            overflow-y: auto;
            font-size: 0.9rem;
        }
        
        .dropdown-item {
            padding: 0.4rem 1rem;
        }
        
        .dropdown-header {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.3rem 1rem;
        }
        
        /* Mega menu for reports */
        .mega-dropdown {
            position: static !important;
        }
        
        .mega-dropdown-menu {
            width: 100%;
            max-width: 1200px;
            left: 50% !important;
            transform: translateX(-50%) !important;
            padding: 1.5rem;
        }
        
        .mega-column {
            padding: 0 1rem;
        }
        
        .mega-column h6 {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.8rem;
            color: #495057;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 0.5rem;
        }
        
        /* Compact navigation for many items */
        .navbar-nav {
            flex-wrap: wrap;
        }
        
        .nav-item {
            margin-right: 0.2rem;
        }
        
        .nav-link {
            padding: 0.5rem 0.8rem;
            font-size: 0.9rem;
        }
        
        /* Company type badge */
        .company-badge {
            font-size: 0.7rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.3rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1400px) {
            .nav-link {
                padding: 0.5rem 0.6rem;
                font-size: 0.85rem;
            }
            
            .nav-link i {
                margin-right: 0.2rem;
            }
            
            .dropdown-menu {
                font-size: 0.85rem;
            }
        }
        
        /* Header Wrapper */
        .header-wrapper {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 2000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        /* Top Header (Branding) */
        .top-header {
            background: #0b5ed7; /* Subtle Dark Blue transition */
            padding: 4px 0; /* Reduced from 8px */
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        /* Bottom Header (Nav) */
        .bottom-header {
            background: #0d6efd; /* Same Primary Blue */
            padding: 0;
        }

        .bottom-header .nav-link {
            padding-top: 2px !important;
            padding-bottom: 2px !important;
            font-size: 0.88rem; /* Slightly smaller for slim look */
        }

        body {
            padding-top: 82px !important; /* Total header height - ultra slim */
        }
        
        .navbar {
            padding: 0 !important;
            box-shadow: none !important;
        }

        /* Ensure content container has enough space */
        .container-fluid.px-4.mt-3 {
            margin-top: 25px !important;
        }
        
        @media (max-width: 992px) {
            .navbar-nav {
                max-height: 400px;
                overflow-y: auto;
            }
            .mega-dropdown-menu {
                position: static !important;
                transform: none !important;
                width: 100% !important;
                max-height: none;
            }
        }

        /* Smart Mobile Scrolling Marquee */
        @media (max-width: 576px) {
            .top-header { padding: 4px 0; }
            .main-logo { height: 26px !important; margin-right: 4px !important; }
            
            .date-location-box {
                font-size: 0.52rem !important;
                background: rgba(255,255,255,0.1);
                padding: 1px 3px;
                border-radius: 4px;
                white-space: nowrap;
                flex-shrink: 0;
                display: flex !important;
                gap: 2px !important;
                justify-content: flex-end;
            }
            .date-location-box .date-text, .date-location-box .location-text {
                font-size: 0.52rem !important;
                overflow: visible !important;
                text-overflow: clip !important;
            }

            .marquee-container {
                flex-grow: 1;
                overflow: hidden;
                white-space: nowrap;
                position: relative;
                margin: 0 4px;
                display: block !important;
            }

            .marquee-text {
                display: inline-block;
                padding-left: 100%;
                animation: marquee 15s linear infinite;
                font-size: 0.95rem !important;
                font-weight: 700;
                color: white;
            }

            @keyframes marquee {
                0% { transform: translateX(0); }
                100% { transform: translateX(-100%); }
            }
            
            body { padding-top: 72px !important; }
        }
        
        /* Print styles */
        @media print {
            .header-wrapper, .navbar {
                display: none !important;
            }
            body {
                padding-top: 0 !important;
            }
        }

        .main-logo {
            height: 32px;
            width: auto;
            object-fit: contain;
            border-radius: 4px;
            background: white;
            padding: 2px;
        }
    </style>
    
    <!-- Dynamic Theme Support -->
    <?php if (($_SESSION['theme'] ?? 'light') === 'dark'): ?>
    <style>
        body { background-color: #1a1d21 !important; color: #e1e7ec !important; }
        .card, .modal-content, .dropdown-menu { background-color: #24282d !important; color: #e1e7ec !important; border-color: #3a3f45 !important; }
        .text-dark, h1, h2, h3, h4, h5, h6, .dropdown-item { color: #ffffff !important; }
        .dropdown-item:hover { background-color: #3a3f45 !important; }
        .form-control, .form-select, .bg-light { background-color: #2d3238 !important; border-color: #444b52 !important; color: #ffffff !important; }
        .form-control:focus, .form-select:focus { background-color: #333940 !important; color: #ffffff !important; }
        .border-bottom, .border-top, .border, hr { border-color: #3a3f45 !important; }
        .text-muted, .form-text, .text-white-50 { color: #a1aab2 !important; }
        .nav-link { color: #d1d8de !important; }
        .nav-link:hover { color: #ffffff !important; }
        .nav-pills .nav-link.active { background-color: #0d6efd !important; }
        .alert-light { background-color: #2d3238 !important; color: #e1e7ec !important; border: 1px solid #3a3f45 !important; }
    </style>
    <?php endif; ?>
</head>
<body>
    <div class="header-wrapper">
        <!-- TOP BRANDING BAR -->
        <div class="top-header">
            <div class="container-fluid px-4 d-flex align-items-center">
                <a class="navbar-brand d-flex align-items-center text-white text-decoration-none" href="<?= getUrl('dashboard') ?>">
                    <img src="/assets/images/<?= htmlspecialchars($group_logo) ?>" alt="Logo" class="main-logo me-3" style="height: 32px; width: auto; background: white; padding: 1px;">
                    <h5 class="fw-bold mb-0 text-white d-none d-md-block" style="letter-spacing: -0.5px; font-size: 1.15rem; line-height: 1.2;"><?= htmlspecialchars($group_name) ?></h5>
                </a>

                <!-- Mobile Scrolling Name -->
                <div class="marquee-container d-md-none">
                    <div class="marquee-text"><?= htmlspecialchars($group_name) ?></div>
                </div>
                
                <!-- System Date & Location (One Row) -->
                <div class="ms-auto d-flex align-items-center gap-2 gap-md-3 text-white date-location-box">
                    <div class="small fw-bold date-text" style="font-size: 0.85rem;">
                        <i class="bi bi-calendar3 me-1 opacity-75"></i> 
                        <span class="d-none d-md-inline"><?= date('l, d M Y') ?></span>
                        <span class="d-inline d-md-none"><?= date('D, d M Y') ?></span>
                    </div>
                    <span class="opacity-25 d-none d-md-inline">|</span>
                    <div class="text-white-50 small location-text" style="font-size: 0.85rem;"><i class="bi bi-geo-alt-fill text-warning me-1"></i> <?= $gs['company_physical_address'] ?? 'Tanzania' ?></div>
                </div>
            </div>
        </div>

        <!-- BOTTOM NAVIGATION BAR -->
        <nav class="navbar navbar-expand-lg navbar-dark bottom-header">
            <div class="container-fluid px-4">
                <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                    <span class="navbar-toggler-icon"></span>
                </button>
                
                <div class="collapse navbar-collapse" id="navbarNav">
                    <ul class="navbar-nav me-auto">
                        <!-- Core Modules -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="coreDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-house"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi' : 'Management' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="coreDropdown">
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Kikundi' : 'Group Management' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('dashboard') ?>"><i class="bi bi-speedometer2 text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dashibodi' : 'Dashboard' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('customers') ?>"><i class="bi bi-people text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama' : 'Members' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('dormant_members') ?>"><i class="bi bi-person-x text-warning me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wanachama Wasiofanya Kazi' : 'Dormant Members' ?></a></li>
                            </ul>
                        </li>

                        <!-- Financial Management -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="financeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-wallet2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Fedha' : 'Finance' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="financeDropdown">
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Fedha' : 'Financial Management' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('manage_contributions') ?>"><i class="bi bi-cash-stack text-success me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Michango' : 'Contributions' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('expenses') ?>"><i class="bi bi-cart-dash-fill text-danger me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Matumizi' : 'Expenses' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('petty_cash') ?>"><i class="bi bi-receipt text-info me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Miamala ya Vocha' : 'Petty Cash Voucher' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('budget') ?>"><i class="bi bi-calculator text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Bajeti' : 'Budget' ?></a></li>
                            </ul>
                        </li>
                        
                        <!-- Communication -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="communicationDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-chat"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mawasiliano' : 'Communication' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="communicationDropdown">
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mawasiliano' : 'Communication' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('message_center') ?>"><i class="bi bi-chat-left"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ujumbe' : 'Messages' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('notification_center') ?>"><i class="bi bi-bell"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa (Notifications)' : 'Notifications' ?></a></li>
                            </ul>
                        </li>
                        
                        <!-- Documents -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="documentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-files"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyaraka' : 'Documents' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="documentsDropdown">
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Nyaraka' : 'Document Management' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('library') ?>"><i class="bi bi-folder"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Maktaba' : 'Library' ?></a></li>
                            </ul>
                        </li>

                        <!-- Reports Menu -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-graph-up"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ripoti' : 'Reports' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="reportsDropdown">
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Fedha' : 'Financial Management' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('expense_report') ?>"><i class="bi bi-cash-coin me-2 text-danger"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchanganuo wa Matumizi' : 'Expense Summary' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('death_analysis') ?>"><i class="bi bi-heart-pulse me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uchambuzi wa Misiba' : 'Funeral Aid Analysis' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('reports/financial_ledger') ?>"><i class="bi bi-journal-text me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ledger ya Fedha' : 'Financial Ledger' ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uchambuzi' : 'Analysis' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('customer_analysis') ?>"><i class="bi bi-people me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uchambuzi wa Wanachama' : 'Member Analysis' ?></a></li>
                            </ul>
                        </li>
                        
                        <!-- Settings & Admin -->
                        <?php if (in_array($user_role_lower, ['admin', 'mwenyekiti', 'chairman'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle px-3" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-sliders"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Utawala (Admin)' : 'Administration' ?>
                            </a>
                            <ul class="dropdown-menu shadow border-0 mt-0" aria-labelledby="adminDropdown">
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio ya Kikundi' : 'Group Settings' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('group_settings') ?>"><i class="bi bi-gear-fill text-primary me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio Mikuu' : 'Main Settings' ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Watumiaji' : 'User Management' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('users') ?>"><i class="bi bi-people"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Watumiaji' : 'Users' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('user_roles') ?>"><i class="bi bi-shield-check"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nafasi/Majukumu' : 'Roles & Permissions' ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio ya Mfumo' : 'System Settings' ?></h6></li>
                                <li><a class="dropdown-item" href="<?= getUrl('system_settings') ?>"><i class="bi bi-gear"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio ya Programu' : 'App Settings' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('backup_restore') ?>"><i class="bi bi-database"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nakala Mkoba (Backup)' : 'Backup & Restore' ?></a></li>
                            </ul>
                        </li>
                        <?php endif; ?>
                    </ul>
                    
                    <!-- User Account -->
                    <ul class="navbar-nav">
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle py-3 px-3 d-flex align-items-center fw-bold" href="#" id="userDrop" data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle fs-5 me-2"></i>
                                <div class="d-none d-xl-block">
                                    <span class="d-block" style="font-size: 0.85rem; line-height: 1;"><?= htmlspecialchars($username) ?></span>
                                    <span class="text-white-50" style="font-size: 10px; text-transform: uppercase;"><?= htmlspecialchars($user_role ?? 'USER') ?></span>
                                </div>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-0">
                                <li><a class="dropdown-item" href="<?= getUrl('profile') ?>"><i class="bi bi-person me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wasifu Wangu' : 'My Profile' ?></a></li>
                                <li><a class="dropdown-item" href="<?= getUrl('my_settings') ?>"><i class="bi bi-gear me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio Yangu' : 'My Settings' ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?= getUrl('help') ?>"><i class="bi bi-question-circle"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Msaada' : 'Help' ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger fw-bold" href="<?= getUrl('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Toka Nje' : 'Logout' ?></a></li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>
    </div>

    <!-- Main Content Area -->
    <div class="container-fluid px-4 mt-3">

<script>
// Initialize Bootstrap dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Enable all dropdowns
    var dropdownElements = document.querySelectorAll('.dropdown-toggle');
    dropdownElements.forEach(function(dropdown) {
        new bootstrap.Dropdown(dropdown);
    });
    
    // Mega dropdown positioning
    var megaDropdowns = document.querySelectorAll('.mega-dropdown');
    megaDropdowns.forEach(function(mega) {
        mega.addEventListener('show.bs.dropdown', function(e) {
            var menu = this.querySelector('.dropdown-menu');
            menu.style.left = '50%';
            menu.style.transform = 'translateX(-50%)';
        });
    });
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown-toggle')) {
            var openDropdowns = document.querySelectorAll('.dropdown-menu.show');
            openDropdowns.forEach(function(dropdown) {
                bootstrap.Dropdown.getInstance(dropdown.previousElementSibling).hide();
            });
        }
    });
});
</script>