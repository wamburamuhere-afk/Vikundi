<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for payroll permissions
$can_view_payroll = in_array($user_role, ['Admin', 'Manager', 'Accountant', 'HR']);
$can_process_payroll = in_array($user_role, ['Admin', 'Accountant']);
$can_approve_payroll = in_array($user_role, ['Admin', 'Manager']);

if (!$can_view_payroll) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get current month for default period
$current_month = date('Y-m');
$selected_period = isset($_GET['period']) ? $_GET['period'] : $current_month;
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';

// Get payroll periods
$periods_query = "
    SELECT DISTINCT payroll_period 
    FROM payroll 
    ORDER BY payroll_period DESC 
    LIMIT 12
";
$periods = $pdo->query($periods_query)->fetchAll(PDO::FETCH_COLUMN);

// Get departments for filtering
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php

function calculate_payroll_summary($payroll_data) {
    $summary = [
        'total_basic_salary' => 0,
        'total_allowances' => 0,
        'total_deductions' => 0,
        'total_tax' => 0,
        'total_net_salary' => 0,
        'employee_count' => 0
    ];
    
    foreach ($payroll_data as $record) {
        $summary['total_basic_salary'] += $record['basic_salary'];
        $summary['total_allowances'] += $record['allowances'];
        $summary['total_deductions'] += $record['deductions'];
        $summary['total_tax'] += $record['tax_amount'];
        $summary['total_net_salary'] += $record['net_salary'];
        $summary['employee_count']++;
    }
    
    return $summary;
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-cash-stack"></i> Payroll Management</h2>
                    <p class="text-muted mb-0">Process, manage, and track employee payroll</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_process_payroll): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#processPayrollModal">
                        <i class="bi bi-gear"></i> Process Payroll
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportPayroll()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <?php if ($can_process_payroll): ?>
                    <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#bulkPayrollModal">
                        <i class="bi bi-arrow-repeat"></i> Bulk Update
                    </button>
                    <?php endif; ?>
                    <a href="payroll_reports.php" class="btn btn-info">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Payroll Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="payrollFilterForm" method="GET">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Payroll Period</label>
                            <select class="form-select" id="period" name="period">
                                <option value="">All Periods</option>
                                <?php foreach ($periods as $period): ?>
                                <option value="<?= $period ?>" <?= ($period == $selected_period) ? 'selected' : '' ?>>
                                    <?= date('F Y', strtotime($period . '-01')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?= ($selected_status == 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="processing" <?= ($selected_status == 'processing') ? 'selected' : '' ?>>Processing</option>
                                <option value="approved" <?= ($selected_status == 'approved') ? 'selected' : '' ?>>Approved</option>
                                <option value="paid" <?= ($selected_status == 'paid') ? 'selected' : '' ?>>Paid</option>
                                <option value="rejected" <?= ($selected_status == 'rejected') ? 'selected' : '' ?>>Rejected</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Payment Method</label>
                            <select class="form-select" id="payment_method" name="payment_method">
                                <option value="">All Methods</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" id="searchPayroll" name="search" placeholder="Search by employee name, ID, payroll number...">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                                <i class="bi bi-arrow-clockwise"></i> Clear
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <?php
    // Calculate payroll statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
            SUM(CASE WHEN payment_status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN payment_status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN payment_status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(basic_salary) as total_basic,
            SUM(allowances) as total_allowances,
            SUM(deductions) as total_deductions,
            SUM(tax_amount) as total_tax,
            SUM(net_salary) as total_net
        FROM payroll 
        WHERE 1=1
    ";
    
    $stats_params = [];
    
    if ($selected_period) {
        $stats_query .= " AND payroll_period = ?";
        $stats_params[] = $selected_period;
    }
    
    if ($selected_status) {
        $stats_query .= " AND payment_status = ?";
        $stats_params[] = $selected_status;
    }
    
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    ?>
    
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['total_records'] ?? 0 ?></h4>
                            <p class="mb-0">Records</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-list-ul" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['paid'] ?? 0 ?></h4>
                            <p class="mb-0">Paid</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['pending'] ?? 0 ?></h4>
                            <p class="mb-0">Pending</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($stats['total_net'] ?? 0) ?></h4>
                            <p class="mb-0">Net Pay</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($stats['total_deductions'] ?? 0) ?></h4>
                            <p class="mb-0">Deductions</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-arrow-down-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card bg-secondary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= format_currency($stats['total_tax'] ?? 0) ?></h4>
                            <p class="mb-0">Tax</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-percent" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll Summary -->
    <div class="card mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Payroll Summary</h5>
            <span class="badge bg-light text-dark">
                <?= $selected_period ? date('F Y', strtotime($selected_period . '-01')) : 'All Periods' ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-primary text-white rounded p-3 me-3">
                            <i class="bi bi-currency-dollar" style="font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <h5 class="mb-0"><?= format_currency($stats['total_basic'] ?? 0) ?></h5>
                            <p class="text-muted mb-0">Total Basic Salary</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-success text-white rounded p-3 me-3">
                            <i class="bi bi-plus-circle" style="font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <h5 class="mb-0"><?= format_currency($stats['total_allowances'] ?? 0) ?></h5>
                            <p class="text-muted mb-0">Total Allowances</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="d-flex align-items-center mb-3">
                        <div class="bg-info text-white rounded p-3 me-3">
                            <i class="bi bi-arrow-down-up" style="font-size: 1.5rem;"></i>
                        </div>
                        <div>
                            <h5 class="mb-0"><?= format_currency($stats['total_net'] ?? 0) ?></h5>
                            <p class="text-muted mb-0">Total Net Pay</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Progress bars for expense breakdown -->
            <div class="mt-4">
                <h6>Expense Breakdown</h6>
                <div class="progress mb-2" style="height: 25px;">
                    <div class="progress-bar bg-primary" style="width: 70%">
                        Basic Salary (70%)
                    </div>
                    <div class="progress-bar bg-success" style="width: 15%">
                        Allowances (15%)
                    </div>
                    <div class="progress-bar bg-danger" style="width: 10%">
                        Deductions (10%)
                    </div>
                    <div class="progress-bar bg-secondary" style="width: 5%">
                        Tax (5%)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Payroll Records</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $stats['total_records'] ?? 0 ?> records
                </span>
                <?php if ($can_process_payroll): ?>
                <button type="button" class="btn btn-light btn-sm" onclick="selectAllPayrolls()">
                    <i class="bi bi-check-all"></i> Select All
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php
            // Fetch payroll records
            $payroll_query = "
                SELECT 
                    p.*,
                    e.first_name,
                    e.last_name,
                    e.employee_number,
                    e.department_id,
                    e.bank_account,
                    e.payment_method as emp_payment_method,
                    d.department_name,
                    u1.username as created_by_name,
                    u2.username as approved_by_name
                FROM payroll p
                LEFT JOIN employees e ON p.employee_id = e.employee_id
                LEFT JOIN departments d ON e.department_id = d.department_id
                LEFT JOIN users u1 ON p.created_by = u1.user_id
                LEFT JOIN users u2 ON p.approved_by = u2.user_id
                WHERE 1=1
            ";
            
            $payroll_params = [];
            
            if ($selected_period) {
                $payroll_query .= " AND p.payroll_period = ?";
                $payroll_params[] = $selected_period;
            }
            
            if ($selected_status) {
                $payroll_query .= " AND p.payment_status = ?";
                $payroll_params[] = $selected_status;
            }
            
            if (isset($_GET['department']) && !empty($_GET['department'])) {
                $payroll_query .= " AND e.department_id = ?";
                $payroll_params[] = $_GET['department'];
            }
            
            if (isset($_GET['payment_method']) && !empty($_GET['payment_method'])) {
                $payroll_query .= " AND p.payment_method = ?";
                $payroll_params[] = $_GET['payment_method'];
            }
            
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $payroll_query .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_number LIKE ? OR p.payroll_number LIKE ?)";
                $search_term = '%' . $_GET['search'] . '%';
                $payroll_params[] = $search_term;
                $payroll_params[] = $search_term;
                $payroll_params[] = $search_term;
                $payroll_params[] = $search_term;
            }
            
            $payroll_query .= " ORDER BY p.payroll_date DESC, e.first_name ASC";
            
            $payroll_stmt = $pdo->prepare($payroll_query);
            $payroll_stmt->execute($payroll_params);
            $payrolls = $payroll_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate summary
            $payroll_summary = calculate_payroll_summary($payrolls);
            ?>
            
            <?php if (count($payrolls) > 0): ?>
                <div class="table-responsive">
                    <table id="payrollTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <?php if ($can_process_payroll): ?>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <?php endif; ?>
                                <th>Payroll #</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Basic Salary</th>
                                <th>Allowances</th>
                                <th>Deductions</th>
                                <th>Tax</th>
                                <th>Net Salary</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payrolls as $payroll): ?>
                            <tr>
                                <?php if ($can_process_payroll): ?>
                                <td>
                                    <input type="checkbox" class="payroll-checkbox" value="<?= $payroll['payroll_id'] ?>">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <code><?= safe_output($payroll['payroll_number'] ?? 'N/A') ?></code><br>
                                    <small class="text-muted"><?= date('d M Y', strtotime($payroll['payroll_date'])) ?></small>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($payroll['first_name'] . ' ' . $payroll['last_name']) ?></strong><br>
                                        <small class="text-muted"><?= safe_output($payroll['employee_number']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?= safe_output($payroll['department_name']) ?>
                                </td>
                                <td>
                                    <?= date('M Y', strtotime($payroll['payroll_period'] . '-01')) ?>
                                </td>
                                <td class="text-end">
                                    <strong><?= format_currency($payroll['basic_salary']) ?></strong>
                                </td>
                                <td class="text-end">
                                    <span class="text-success"><?= format_currency($payroll['allowances']) ?></span>
                                </td>
                                <td class="text-end">
                                    <span class="text-danger"><?= format_currency($payroll['deductions']) ?></span>
                                </td>
                                <td class="text-end">
                                    <span class="text-secondary"><?= format_currency($payroll['tax_amount']) ?></span>
                                </td>
                                <td class="text-end">
                                    <strong class="text-primary"><?= format_currency($payroll['net_salary']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($payroll['payment_status']) ?>">
                                        <?= ucfirst($payroll['payment_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <small><?= ucfirst($payroll['payment_method']) ?></small><br>
                                        <?php if ($payroll['payment_status'] == 'paid' && !empty($payroll['payment_date'])): ?>
                                        <small class="text-muted"><?= date('d M Y', strtotime($payroll['payment_date'])) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="payroll_details.php?id=<?= $payroll['payroll_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="payslip.php?id=<?= $payroll['payroll_id'] ?>" target="_blank">
                                                    <i class="bi bi-printer"></i> Print Payslip
                                                </a>
                                            </li>
                                            <?php if ($can_process_payroll): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editPayroll(<?= $payroll['payroll_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_process_payroll): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($payroll['payment_status'] == 'pending'): ?>
                                            <li>
                                                <a class="dropdown-item text-info" href="#" onclick="updatePayrollStatus(<?= $payroll['payroll_id'] ?>, 'processing')">
                                                    <i class="bi bi-gear"></i> Mark as Processing
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($payroll['payment_status'] == 'processing'): ?>
                                            <li>
                                                <a class="dropdown-item text-primary" href="#" onclick="updatePayrollStatus(<?= $payroll['payroll_id'] ?>, 'approved')">
                                                    <i class="bi bi-check-circle"></i> Approve
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($payroll['payment_status'] == 'approved'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="markAsPaid(<?= $payroll['payroll_id'] ?>)">
                                                    <i class="bi bi-cash"></i> Mark as Paid
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if (in_array($payroll['payment_status'], ['pending', 'processing', 'approved'])): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="updatePayrollStatus(<?= $payroll['payroll_id'] ?>, 'rejected')">
                                                    <i class="bi bi-x-circle"></i> Reject
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_approve_payroll && $payroll['payment_status'] == 'processing'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="approvePayroll(<?= $payroll['payroll_id'] ?>)">
                                                    <i class="bi bi-check-square"></i> Approve Payment
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_process_payroll): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-info" href="#" onclick="duplicatePayroll(<?= $payroll['payroll_id'] ?>)">
                                                    <i class="bi bi-copy"></i> Duplicate
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-warning" href="payroll_ledger.php?employee=<?= $payroll['employee_id'] ?>">
                                                    <i class="bi bi-journal"></i> View Ledger
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-dark">
                                <?php if ($can_process_payroll): ?>
                                <th></th>
                                <?php endif; ?>
                                <th colspan="3" class="text-end">Totals:</th>
                                <th><?= $payroll_summary['employee_count'] ?> employees</th>
                                <th class="text-end"><?= format_currency($payroll_summary['total_basic_salary']) ?></th>
                                <th class="text-end"><?= format_currency($payroll_summary['total_allowances']) ?></th>
                                <th class="text-end"><?= format_currency($payroll_summary['total_deductions']) ?></th>
                                <th class="text-end"><?= format_currency($payroll_summary['total_tax']) ?></th>
                                <th class="text-end"><?= format_currency($payroll_summary['total_net_salary']) ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <?php if ($can_process_payroll): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-4 mb-2">
                            <small><span id="selectedCount">0</span> payroll records selected</small>
                        </div>
                        <div class="col-md-8 text-end">
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="bulkUpdateStatus('processing')">
                                    <i class="bi bi-gear"></i> Mark as Processing
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkUpdateStatus('approved')">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkUpdateStatus('rejected')">
                                    <i class="bi bi-x-circle"></i> Reject
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="bulkExportPayslips()">
                                    <i class="bi bi-download"></i> Export Payslips
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="bulkSendPayslips()">
                                    <i class="bi bi-envelope"></i> Email Payslips
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-cash-stack" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Payroll Records Found</h4>
                    <p class="text-muted">Process payroll for the current period to get started.</p>
                    <?php if ($can_process_payroll): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#processPayrollModal">
                        <i class="bi bi-gear"></i> Process Payroll
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Process Payroll Modal -->
<?php if ($can_process_payroll): ?>
<div class="modal fade" id="processPayrollModal" tabindex="-1" aria-labelledby="processPayrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="processPayrollModalLabel">
                    <i class="bi bi-gear"></i> Process Payroll
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="processPayrollForm">
                <div class="modal-body">
                    <div id="process-payroll-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payroll_period" class="form-label">Payroll Period <span class="text-danger">*</span></label>
                            <input type="month" class="form-control" id="payroll_period" name="payroll_period" value="<?= date('Y-m') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="payroll_date" class="form-label">Payroll Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="payroll_date" name="payroll_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="process_department" class="form-label">Department (Optional)</label>
                            <select class="form-select" id="process_department" name="department_id">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="process_employment_status" class="form-label">Employee Status</label>
                            <select class="form-select" id="process_employment_status" name="employment_status">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="probation">Probation</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="include_allowances" name="include_allowances" checked>
                                <label class="form-check-label" for="include_allowances">
                                    Include allowances and benefits
                                </label>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="include_deductions" name="include_deductions" checked>
                                <label class="form-check-label" for="include_deductions">
                                    Include deductions (tax, loans, etc.)
                                </label>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="include_attendance" name="include_attendance" checked>
                                <label class="form-check-label" for="include_attendance">
                                    Consider attendance records
                                </label>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="auto_approve" name="auto_approve">
                                <label class="form-check-label" for="auto_approve">
                                    Auto-approve processed payroll
                                </label>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="process_notes" class="form-label">Processing Notes</label>
                            <textarea class="form-control" id="process_notes" name="notes" rows="2" placeholder="Any notes about this payroll processing"></textarea>
                        </div>
                    </div>
                    
                    <!-- Preview Section -->
                    <div id="payrollPreview" class="mt-3" style="display: none;">
                        <h6><i class="bi bi-eye"></i> Payroll Preview</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Basic</th>
                                        <th>Allowances</th>
                                        <th>Deductions</th>
                                        <th>Net</th>
                                    </tr>
                                </thead>
                                <tbody id="previewBody"></tbody>
                                <tfoot>
                                    <tr>
                                        <th>Total:</th>
                                        <th id="previewTotalBasic">0.00</th>
                                        <th id="previewTotalAllowances">0.00</th>
                                        <th id="previewTotalDeductions">0.00</th>
                                        <th id="previewTotalNet">0.00</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="previewPayroll()">
                        <i class="bi bi-eye"></i> Preview
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-gear"></i> Process Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Update Modal -->
<?php if ($can_process_payroll): ?>
<div class="modal fade" id="bulkPayrollModal" tabindex="-1" aria-labelledby="bulkPayrollModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="bulkPayrollModalLabel">
                    <i class="bi bi-arrow-repeat"></i> Bulk Payroll Update
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkPayrollForm">
                <div class="modal-body">
                    <div id="bulk-payroll-message" class="mb-3"></div>
                    
                    <div class="mb-3">
                        <label for="bulk_action" class="form-label">Action <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_action" name="action" required>
                            <option value="">Select Action</option>
                            <option value="update_status">Update Status</option>
                            <option value="update_payment_method">Update Payment Method</option>
                            <option value="add_allowance">Add Allowance</option>
                            <option value="add_deduction">Add Deduction</option>
                        </select>
                    </div>
                    
                    <div id="bulkActionFields">
                        <!-- Dynamic fields will be loaded here -->
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="bulk_notes" name="notes" rows="2" placeholder="Reason for bulk update"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Apply Bulk Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Payroll Modal -->
<?php if ($can_process_payroll): ?>
<div class="modal fade" id="editPayrollModal" tabindex="-1" aria-labelledby="editPayrollModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="editPayrollModalLabel">
                    <i class="bi bi-pencil"></i> Edit Payroll
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editPayrollForm">
                <div class="modal-body">
                    <div id="edit-payroll-message" class="mb-3"></div>
                    <input type="hidden" id="edit_payroll_id" name="payroll_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_basic_salary" class="form-label">Basic Salary <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_basic_salary" name="basic_salary" step="0.01" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_allowances" class="form-label">Allowances</label>
                            <input type="number" class="form-control" id="edit_allowances" name="allowances" step="0.01">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_deductions" class="form-label">Deductions</label>
                            <input type="number" class="form-control" id="edit_deductions" name="deductions" step="0.01">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_tax_amount" class="form-label">Tax Amount</label>
                            <input type="number" class="form-control" id="edit_tax_amount" name="tax_amount" step="0.01">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_payment_method" class="form-label">Payment Method</label>
                            <select class="form-select" id="edit_payment_method" name="payment_method">
                                <option value="bank">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="mobile">Mobile Money</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_payment_status" class="form-label">Payment Status</label>
                            <select class="form-select" id="edit_payment_status" name="payment_status">
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="approved">Approved</option>
                                <option value="paid">Paid</option>
                                <option value="rejected">Rejected</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="3" placeholder="Additional notes about this payroll"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-check-circle"></i> Update Payroll
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include DataTables and other scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    let payrollTable = $('#payrollTable').DataTable({
        language: {
            search: "Search payroll:",
            lengthMenu: "Show _MENU_ records per page",
            info: "Showing _START_ to _END_ of _TOTAL_ records",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'copyHtml5',
                text: '<i class="bi bi-clipboard"></i> Copy',
                className: 'btn btn-sm btn-outline-secondary',
                titleAttr: 'Copy to clipboard'
            },
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-excel"></i> Excel',
                className: 'btn btn-sm btn-outline-success',
                titleAttr: 'Export to Excel',
                title: 'Payroll_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                titleAttr: 'Export to PDF',
                title: 'Payroll_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-info',
                titleAttr: 'Print table'
            }
        ],
        pageLength: 25,
        order: [[1, 'desc']],
        footerCallback: function(row, data, start, end, display) {
            // Update selected count
            updateSelectedCount();
        }
    });

    // Process payroll form submission
    $('#processPayrollForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: 'api/process_payroll.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#process-payroll-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    if (response.summary) {
                        let summaryHtml = '<div class="alert alert-info mt-3"><h6>Processing Summary:</h6>';
                        summaryHtml += 'Total employees processed: ' + response.summary.total_processed + '<br>';
                        summaryHtml += 'Successful: ' + response.summary.successful + '<br>';
                        summaryHtml += 'Failed: ' + response.summary.failed + '<br>';
                        summaryHtml += 'Total amount: ' + response.summary.total_amount + '<br>';
                        summaryHtml += '</div>';
                        $('#process-payroll-message').append(summaryHtml);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $('#process-payroll-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-gear"></i> Process Payroll');
                }
            },
            error: function(xhr, status, error) {
                $('#process-payroll-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-gear"></i> Process Payroll');
                console.error('Error:', error);
            }
        });
    });

    // Bulk payroll form submission
    $('#bulkPayrollForm').on('submit', function(e) {
        e.preventDefault();
        
        const selectedIds = getSelectedPayrollIds();
        if (selectedIds.length === 0) {
            alert('Please select at least one payroll record.');
            return;
        }
        
        const formData = $(this).serialize() + '&payroll_ids=' + JSON.stringify(selectedIds);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/bulk_update_payroll.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#bulk-payroll-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#bulk-payroll-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Apply Bulk Update');
                }
            },
            error: function(xhr, status, error) {
                $('#bulk-payroll-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Apply Bulk Update');
                console.error('Error:', error);
            }
        });
    });

    // Edit payroll form submission
    $('#editPayrollForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_payroll.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit-payroll-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#edit-payroll-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Payroll');
                }
            },
            error: function(xhr, status, error) {
                $('#edit-payroll-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Payroll');
                console.error('Error:', error);
            }
        });
    });

    // Handle bulk action change
    $('#bulk_action').on('change', function() {
        const action = $(this).val();
        let fieldsHtml = '';
        
        switch (action) {
            case 'update_status':
                fieldsHtml = `
                    <div class="mb-3">
                        <label for="bulk_status" class="form-label">New Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_status" name="status" required>
                            <option value="">Select Status</option>
                            <option value="processing">Processing</option>
                            <option value="approved">Approved</option>
                            <option value="paid">Paid</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                `;
                break;
                
            case 'update_payment_method':
                fieldsHtml = `
                    <div class="mb-3">
                        <label for="bulk_payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" id="bulk_payment_method" name="payment_method" required>
                            <option value="">Select Method</option>
                            <option value="bank">Bank Transfer</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                            <option value="mobile">Mobile Money</option>
                        </select>
                    </div>
                `;
                break;
                
            case 'add_allowance':
                fieldsHtml = `
                    <div class="mb-3">
                        <label for="bulk_allowance_amount" class="form-label">Allowance Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="bulk_allowance_amount" name="amount" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="mb-3">
                        <label for="bulk_allowance_description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="bulk_allowance_description" name="description" placeholder="Allowance description">
                    </div>
                `;
                break;
                
            case 'add_deduction':
                fieldsHtml = `
                    <div class="mb-3">
                        <label for="bulk_deduction_amount" class="form-label">Deduction Amount <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="bulk_deduction_amount" name="amount" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="mb-3">
                        <label for="bulk_deduction_description" class="form-label">Description</label>
                        <input type="text" class="form-control" id="bulk_deduction_description" name="description" placeholder="Deduction description">
                    </div>
                `;
                break;
        }
        
        $('#bulkActionFields').html(fieldsHtml);
    });

    // Checkbox selection handlers
    $('.payroll-checkbox').on('change', function() {
        updateSelectedCount();
    });

    // Reset forms when modals are closed
    $('#processPayrollModal').on('hidden.bs.modal', function() {
        $('#processPayrollForm')[0].reset();
        $('#process-payroll-message').html('');
        $('#payrollPreview').hide();
        $('#processPayrollForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-gear"></i> Process Payroll');
    });
    
    $('#bulkPayrollModal').on('hidden.bs.modal', function() {
        $('#bulkPayrollForm')[0].reset();
        $('#bulk-payroll-message').html('');
        $('#bulkActionFields').html('');
        $('#bulkPayrollForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Apply Bulk Update');
    });
    
    $('#editPayrollModal').on('hidden.bs.modal', function() {
        $('#editPayrollForm')[0].reset();
        $('#edit-payroll-message').html('');
        $('#editPayrollForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Payroll');
    });
});

function clearFilters() {
    window.location.href = 'payroll.php';
}

function exportPayroll() {
    // Trigger DataTable export
    $('#payrollTable').DataTable().button('.buttons-excel').trigger();
}

function getSelectedPayrollIds() {
    const selectedIds = [];
    $('.payroll-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });
    return selectedIds;
}

function updateSelectedCount() {
    const selectedCount = $('.payroll-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
}

function toggleSelectAll(checkbox) {
    $('.payroll-checkbox').prop('checked', checkbox.checked);
    updateSelectedCount();
}

function selectAllPayrolls() {
    $('#selectAll').prop('checked', true);
    $('.payroll-checkbox').prop('checked', true);
    updateSelectedCount();
}

function bulkUpdateStatus(status) {
    const selectedIds = getSelectedPayrollIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one payroll record.');
        return;
    }
    
    if (!confirm(`Are you sure you want to mark ${selectedIds.length} payroll record(s) as ${status}?`)) {
        return;
    }

    $.ajax({
        url: 'api/bulk_update_payroll_status.php',
        type: 'POST',
        data: { 
            payroll_ids: selectedIds,
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error updating status: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating status. Please try again.');
            console.error('Error:', error);
        }
    });
}

function bulkExportPayslips() {
    const selectedIds = getSelectedPayrollIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one payroll record.');
        return;
    }
    
    // Create a form and submit to generate PDF
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/export_payslips.php';
    form.target = '_blank';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'payroll_ids';
    input.value = JSON.stringify(selectedIds);
    form.appendChild(input);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function bulkSendPayslips() {
    const selectedIds = getSelectedPayrollIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one payroll record.');
        return;
    }
    
    if (!confirm(`Are you sure you want to email payslips to ${selectedIds.length} employee(s)?`)) {
        return;
    }

    $.ajax({
        url: 'api/send_payslips.php',
        type: 'POST',
        data: { 
            payroll_ids: selectedIds
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
            } else {
                alert('Error sending payslips: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error sending payslips. Please try again.');
            console.error('Error:', error);
        }
    });
}

function editPayroll(payrollId) {
    // Load payroll data for editing
    $.ajax({
        url: 'api/get_payroll.php',
        type: 'GET',
        data: { id: payrollId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_payroll_id').val(response.data.payroll_id);
                $('#edit_basic_salary').val(response.data.basic_salary);
                $('#edit_allowances').val(response.data.allowances);
                $('#edit_deductions').val(response.data.deductions);
                $('#edit_tax_amount').val(response.data.tax_amount);
                $('#edit_payment_method').val(response.data.payment_method);
                $('#edit_payment_status').val(response.data.payment_status);
                $('#edit_notes').val(response.data.notes || '');
                
                // Show edit modal
                $('#editPayrollModal').modal('show');
            } else {
                alert('Error loading payroll data: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading payroll data. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updatePayrollStatus(payrollId, status) {
    const actionMap = {
        'processing': 'mark as processing',
        'approved': 'approve',
        'paid': 'mark as paid',
        'rejected': 'reject',
        'cancelled': 'cancel'
    };
    
    const action = actionMap[status] || 'update';
    
    if (!confirm('Are you sure you want to ' + action + ' this payroll?')) {
        return;
    }

    $.ajax({
        url: 'api/update_payroll_status.php',
        type: 'POST',
        data: { 
            payroll_id: payrollId,
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error updating status: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating status. Please try again.');
            console.error('Error:', error);
        }
    });
}

function markAsPaid(payrollId) {
    if (!confirm('Are you sure you want to mark this payroll as paid? This action cannot be undone.')) {
        return;
    }

    $.ajax({
        url: 'api/mark_payroll_paid.php',
        type: 'POST',
        data: { payroll_id: payrollId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error marking as paid: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error marking as paid. Please try again.');
            console.error('Error:', error);
        }
    });
}

function approvePayroll(payrollId) {
    if (!confirm('Are you sure you want to approve this payroll for payment?')) {
        return;
    }

    $.ajax({
        url: 'api/approve_payroll.php',
        type: 'POST',
        data: { payroll_id: payrollId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error approving payroll: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error approving payroll. Please try again.');
            console.error('Error:', error);
        }
    });
}

function duplicatePayroll(payrollId) {
    if (!confirm('Are you sure you want to duplicate this payroll record?')) {
        return;
    }

    $.ajax({
        url: 'api/duplicate_payroll.php',
        type: 'POST',
        data: { payroll_id: payrollId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error duplicating payroll: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error duplicating payroll. Please try again.');
            console.error('Error:', error);
        }
    });
}

function previewPayroll() {
    const period = $('#payroll_period').val();
    const department = $('#process_department').val();
    
    if (!period) {
        alert('Please select a payroll period first.');
        return;
    }

    $.ajax({
        url: 'api/preview_payroll.php',
        type: 'GET',
        data: { 
            period: period,
            department_id: department
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const previewBody = $('#previewBody');
                const preview = $('#payrollPreview');
                previewBody.empty();
                
                let totalBasic = 0;
                let totalAllowances = 0;
                let totalDeductions = 0;
                let totalNet = 0;
                
                response.employees.forEach(function(employee) {
                    const net = employee.basic_salary + employee.allowances - employee.deductions;
                    
                    previewBody.append(`
                        <tr>
                            <td>${employee.first_name} ${employee.last_name}</td>
                            <td>${formatCurrency(employee.basic_salary)}</td>
                            <td>${formatCurrency(employee.allowances)}</td>
                            <td>${formatCurrency(employee.deductions)}</td>
                            <td>${formatCurrency(net)}</td>
                        </tr>
                    `);
                    
                    totalBasic += employee.basic_salary;
                    totalAllowances += employee.allowances;
                    totalDeductions += employee.deductions;
                    totalNet += net;
                });
                
                $('#previewTotalBasic').text(formatCurrency(totalBasic));
                $('#previewTotalAllowances').text(formatCurrency(totalAllowances));
                $('#previewTotalDeductions').text(formatCurrency(totalDeductions));
                $('#previewTotalNet').text(formatCurrency(totalNet));
                
                preview.show();
            } else {
                alert('Error generating preview: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error generating preview. Please try again.');
            console.error('Error:', error);
        }
    });
}

function formatCurrency(amount) {
    return 'TSh ' + parseFloat(amount).toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.dropdown-menu {
    font-size: 0.875rem;
    min-width: 200px;
}

.dropdown-item {
    padding: 0.25rem 1rem;
}

.dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-warning,
.card.bg-info,
.card.bg-danger,
.card.bg-secondary {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-info { background: linear-gradient(45deg, #0dcaf0, #0aa2c0); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }
.card.bg-secondary { background: linear-gradient(45deg, #6c757d, #5a6268); }

/* Progress bar customization */
.progress-bar {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Checkbox styling */
.payroll-checkbox {
    cursor: pointer;
}

/* Print styles */
@media print {
    .navbar, .card-header, .btn, .dropdown, .dataTables_length, 
    .dataTables_filter, .dataTables_info, .dataTables_paginate, 
    .dt-buttons, .modal {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    table {
        width: 100% !important;
        font-size: 12px;
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .btn-group {
        flex-wrap: wrap;
        gap: 0.25rem;
    }
}

@media (max-width: 576px) {
    .col-xl-2 {
        margin-bottom: 0.5rem;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    .progress {
        font-size: 0.7rem;
    }
}

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>