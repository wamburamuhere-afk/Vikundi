<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for leave permissions
$can_view_leaves = in_array($user_role, ['Admin', 'Manager', 'HR', 'Supervisor']);
$can_edit_leaves = in_array($user_role, ['Admin', 'HR']);
$can_approve_leaves = in_array($user_role, ['Admin', 'Manager']);
$can_delete_leaves = in_array($user_role, ['Admin']);

if (!$can_view_leaves) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get current date for filters
$current_date = date('Y-m-d');
$selected_start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$selected_end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';
$selected_type = isset($_GET['type']) ? $_GET['type'] : '';
$selected_department = isset($_GET['department']) ? (int)$_GET['department'] : null;
$selected_employee = isset($_GET['employee']) ? (int)$_GET['employee'] : null;

// Get departments for filtering
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get employees for filtering
$employees_query = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE status = 'active' ORDER BY first_name, last_name";
$employees = $pdo->query($employees_query)->fetchAll(PDO::FETCH_ASSOC);

// Get leave types
$leave_types = $pdo->query("SELECT * FROM leave_types WHERE status = 'active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

// Helper functions removed, now in helpers.php

// Check if user is viewing their own leaves
$is_viewing_own = ($selected_employee == $_SESSION['user_id']) || ($user_role == 'Employee');
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-calendar"></i> Leave Management</h2>
                    <p class="text-muted mb-0">Manage employee leaves and approvals</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_leaves || $is_viewing_own): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                        <i class="bi bi-plus-circle"></i> Apply for Leave
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportLeaves()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <?php if ($can_edit_leaves): ?>
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkLeaveModal">
                        <i class="bi bi-upload"></i> Bulk Import
                    </button>
                    <?php endif; ?>
                    <a href="leave_reports.php" class="btn btn-warning">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <?php
    // Calculate leave statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_leaves,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(total_days) as total_days
        FROM leaves 
        WHERE start_date BETWEEN ? AND ?
    ";
    
    $stats_params = [$selected_start_date, $selected_end_date];
    
    if ($selected_status) {
        $stats_query .= " AND status = ?";
        $stats_params[] = $selected_status;
    }
    
    if ($selected_type) {
        $stats_query .= " AND leave_type = ?";
        $stats_params[] = $selected_type;
    }
    
    if ($selected_department) {
        $stats_query .= " AND employee_id IN (SELECT employee_id FROM employees WHERE department_id = ?)";
        $stats_params[] = $selected_department;
    }
    
    if ($selected_employee) {
        $stats_query .= " AND employee_id = ?";
        $stats_params[] = $selected_employee;
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
                            <h4 class="mb-0"><?= $stats['total_leaves'] ?? 0 ?></h4>
                            <p class="mb-0">Total Leaves</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar" style="font-size: 1.5rem;"></i>
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
                            <h4 class="mb-0"><?= $stats['approved'] ?? 0 ?></h4>
                            <p class="mb-0">Approved</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 1.5rem;"></i>
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
                            <h4 class="mb-0"><?= $stats['rejected'] ?? 0 ?></h4>
                            <p class="mb-0">Rejected</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-x-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $stats['total_days'] ?? 0 ?></h4>
                            <p class="mb-0">Total Days</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-check" style="font-size: 1.5rem;"></i>
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
                            <h4 class="mb-0">
                                <?php
                                $avg_days = ($stats['total_leaves'] > 0) ? round($stats['total_days'] / $stats['total_leaves'], 1) : 0;
                                echo $avg_days;
                                ?>
                            </h4>
                            <p class="mb-0">Avg Days/Leave</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Leave Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="leaveFilterForm" method="GET">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $selected_start_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $selected_end_date ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Leave Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="pending" <?= ($selected_status == 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="approved" <?= ($selected_status == 'approved') ? 'selected' : '' ?>>Approved</option>
                                <option value="rejected" <?= ($selected_status == 'rejected') ? 'selected' : '' ?>>Rejected</option>
                                <option value="cancelled" <?= ($selected_status == 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                                <option value="taken" <?= ($selected_status == 'taken') ? 'selected' : '' ?>>Taken</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Leave Type</label>
                            <select class="form-select" id="type" name="type">
                                <option value="">All Types</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>" <?= ($selected_type == $type['type_name']) ? 'selected' : '' ?>>
                                    <?= safe_output($type['type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Department</label>
                            <select class="form-select" id="department" name="department">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>" <?= ($selected_department == $dept['department_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($dept['department_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select" id="employee" name="employee">
                                <option value="">All Employees</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>" <?= ($selected_employee == $emp['employee_id']) ? 'selected' : '' ?>>
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?> (<?= safe_output($emp['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" id="searchLeaves" name="search" placeholder="Search by reason, notes, or reference...">
                        </div>
                        <div class="col-12 d-flex justify-content-end">
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

    <!-- Leave Balance Summary -->
    <div class="card mb-4">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-pie-chart"></i> Leave Balance Summary</h5>
            <span class="badge bg-light text-dark">
                <?= date('F Y') ?>
            </span>
        </div>
        <div class="card-body">
            <div class="row">
                <?php
                // Get leave balances for current year
                $balance_query = "
                    SELECT 
                        lt.type_name,
                        lt.max_days_per_year,
                        COALESCE(SUM(CASE WHEN l.status = 'approved' THEN l.total_days ELSE 0 END), 0) as used_days
                    FROM leave_types lt
                    LEFT JOIN leaves l ON lt.type_name = l.leave_type 
                        AND YEAR(l.start_date) = YEAR(CURDATE())
                        AND l.status = 'approved'
                        AND l.employee_id = ?
                    WHERE lt.status = 'active'
                    GROUP BY lt.type_id, lt.type_name, lt.max_days_per_year
                    ORDER BY lt.type_name
                ";
                
                $balance_stmt = $pdo->prepare($balance_query);
                $balance_stmt->execute([$selected_employee ?: ($_SESSION['user_id'] ?? 0)]);
                $leave_balances = $balance_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($leave_balances as $balance):
                    $used = $balance['used_days'];
                    $max = $balance['max_days_per_year'];
                    $remaining = $max - $used;
                    $percentage = $max > 0 ? round(($used / $max) * 100) : 0;
                ?>
                <div class="col-md-3 mb-3">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title"><?= safe_output($balance['type_name']) ?> Leave</h6>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <h4 class="mb-0 text-primary"><?= $remaining ?></h4>
                                    <small class="text-muted">Days Remaining</small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted"><?= $used ?>/<?= $max ?> days</small>
                                </div>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-<?= get_type_badge($balance['type_name']) ?>" 
                                     style="width: <?= $percentage ?>%"
                                     role="progressbar"
                                     aria-valuenow="<?= $percentage ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100"></div>
                            </div>
                            <small class="text-muted mt-2 d-block"><?= $percentage ?>% used</small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Leaves List -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Leaves List</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $stats['total_leaves'] ?? 0 ?> leaves
                </span>
                <?php if ($can_edit_leaves): ?>
                <button type="button" class="btn btn-light btn-sm" onclick="selectAllLeaves()">
                    <i class="bi bi-check-all"></i> Select All
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php
            // Fetch leaves with employee details
            $leaves_query = "
                SELECT 
                    l.*,
                    e.first_name,
                    e.last_name,
                    e.employee_number,
                    e.department_id,
                    d.department_name,
                    u1.username as applied_by_name,
                    u2.username as approved_by_name,
                    lt.color as type_color
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.employee_id
                LEFT JOIN departments d ON e.department_id = d.department_id
                LEFT JOIN users u1 ON l.applied_by = u1.user_id
                LEFT JOIN users u2 ON l.approved_by = u2.user_id
                LEFT JOIN leave_types lt ON l.leave_type = lt.type_name
                WHERE l.start_date BETWEEN ? AND ?
            ";
            
            $leaves_params = [$selected_start_date, $selected_end_date];
            
            if ($selected_status) {
                $leaves_query .= " AND l.status = ?";
                $leaves_params[] = $selected_status;
            }
            
            if ($selected_type) {
                $leaves_query .= " AND l.leave_type = ?";
                $leaves_params[] = $selected_type;
            }
            
            if ($selected_department) {
                $leaves_query .= " AND e.department_id = ?";
                $leaves_params[] = $selected_department;
            }
            
            if ($selected_employee) {
                $leaves_query .= " AND l.employee_id = ?";
                $leaves_params[] = $selected_employee;
            }
            
            if (isset($_GET['search']) && !empty($_GET['search'])) {
                $leaves_query .= " AND (l.reason LIKE ? OR l.notes LIKE ? OR l.reference_number LIKE ?)";
                $search_term = '%' . $_GET['search'] . '%';
                $leaves_params[] = $search_term;
                $leaves_params[] = $search_term;
                $leaves_params[] = $search_term;
            }
            
            $leaves_query .= " ORDER BY l.start_date DESC, l.created_at DESC";
            
            $leaves_stmt = $pdo->prepare($leaves_query);
            $leaves_stmt->execute($leaves_params);
            $leaves = $leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            
            <?php if (count($leaves) > 0): ?>
                <div class="table-responsive">
                    <table id="leavesTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <?php if ($can_edit_leaves): ?>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <?php endif; ?>
                                <th>Reference #</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Leave Details</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Applied By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <?php if ($can_edit_leaves): ?>
                                <td>
                                    <input type="checkbox" class="leave-checkbox" value="<?= $leave['leave_id'] ?>">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <code><?= safe_output($leave['reference_number']) ?></code><br>
                                    <small class="text-muted"><?= format_date($leave['created_at'], 'd M Y') ?></small>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($leave['first_name'] . ' ' . $leave['last_name']) ?></strong><br>
                                        <small class="text-muted"><?= safe_output($leave['employee_number']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?= safe_output($leave['department_name']) ?>
                                </td>
                                <td>
                                    <div>
                                        <span class="badge bg-<?= get_type_badge($leave['leave_type']) ?>">
                                            <?= ucfirst($leave['leave_type']) ?>
                                        </span>
                                        <br>
                                        <small><?= safe_output($leave['reason']) ?></small>
                                        <?php if (!empty($leave['notes'])): ?>
                                        <br>
                                        <small class="text-muted"><i><?= substr(safe_output($leave['notes']), 0, 50) ?>...</i></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= format_date($leave['start_date']) ?></strong><br>
                                        <small>to</small><br>
                                        <strong><?= format_date($leave['end_date']) ?></strong><br>
                                        <span class="badge bg-info"><?= $leave['total_days'] ?> days</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($leave['status']) ?>">
                                        <?= ucfirst($leave['status']) ?>
                                    </span>
                                    <?php if ($leave['status'] == 'approved' && !empty($leave['approved_by_name'])): ?>
                                    <br>
                                    <small class="text-muted">
                                        By <?= safe_output($leave['approved_by_name']) ?><br>
                                        <?= format_date($leave['approved_date'], 'd M Y') ?>
                                    </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>
                                        <?= safe_output($leave['applied_by_name']) ?><br>
                                        <small class="text-muted"><?= format_date($leave['applied_date'], 'd M Y') ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="leave_details.php?id=<?= $leave['leave_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="leave_application.php?id=<?= $leave['leave_id'] ?>" target="_blank">
                                                    <i class="bi bi-printer"></i> Print Application
                                                </a>
                                            </li>
                                            
                                            <?php if ($can_edit_leaves || $leave['applied_by'] == $_SESSION['user_id']): ?>
                                            <?php if ($leave['status'] == 'pending'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Leave
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_approve_leaves && $leave['status'] == 'pending'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="approveLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-check-circle"></i> Approve Leave
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="rejectLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-x-circle"></i> Reject Leave
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($leave['status'] == 'approved' && $leave['start_date'] > date('Y-m-d')): ?>
                                            <?php if ($can_edit_leaves || $leave['applied_by'] == $_SESSION['user_id']): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="cancelLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-ban"></i> Cancel Leave
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_edit_leaves): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-info" href="#" onclick="duplicateLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-copy"></i> Duplicate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_leaves && ($leave['status'] == 'pending' || $leave['status'] == 'cancelled')): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="deleteLeave(<?= $leave['leave_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions -->
                <?php if ($can_edit_leaves): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-4 mb-2">
                            <small><span id="selectedCount">0</span> leaves selected</small>
                        </div>
                        <div class="col-md-8 text-end">
                            <div class="btn-group">
                                <?php if ($can_approve_leaves): ?>
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkUpdateStatus('approved')">
                                    <i class="bi bi-check-circle"></i> Approve
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkUpdateStatus('rejected')">
                                    <i class="bi bi-x-circle"></i> Reject
                                </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="bulkUpdateStatus('cancelled')">
                                    <i class="bi bi-ban"></i> Cancel
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="bulkExportApplications()">
                                    <i class="bi bi-download"></i> Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Leaves Found</h4>
                    <p class="text-muted">No leave records found for the selected filters.</p>
                    <?php if ($can_edit_leaves || $is_viewing_own): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                        <i class="bi bi-plus-circle"></i> Apply for Leave
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Apply for Leave Modal -->
<?php if ($can_edit_leaves || $is_viewing_own): ?>
<div class="modal fade" id="applyLeaveModal" tabindex="-1" aria-labelledby="applyLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="applyLeaveModalLabel">
                    <i class="bi bi-plus-circle"></i> Apply for Leave
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="applyLeaveForm">
                <div class="modal-body">
                    <div id="apply-leave-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="employee_id" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="employee_id" name="employee_id" required <?= ($is_viewing_own && !$can_edit_leaves) ? 'disabled' : '' ?>>
                                <?php if ($is_viewing_own && !$can_edit_leaves): ?>
                                <?php 
                                // Get current user's employee record
                                $user_emp = $pdo->prepare("
                                    SELECT e.* FROM employees e 
                                    JOIN users u ON e.employee_id = u.employee_id 
                                    WHERE u.user_id = ?
                                ");
                                $user_emp->execute([$_SESSION['user_id']]);
                                $current_employee = $user_emp->fetch();
                                ?>
                                <option value="<?= $current_employee['employee_id'] ?>" selected>
                                    <?= safe_output($current_employee['first_name'] . ' ' . $current_employee['last_name']) ?> 
                                    (<?= safe_output($current_employee['employee_number']) ?>)
                                </option>
                                <input type="hidden" name="employee_id" value="<?= $current_employee['employee_id'] ?>">
                                <?php else: ?>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?> (<?= safe_output($emp['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="leave_type" class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="leave_type" name="leave_type" required onchange="updateLeaveBalance()">
                                <option value="">Select Type</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>" 
                                        data-max-days="<?= $type['max_days_per_year'] ?>"
                                        data-requires-doc="<?= $type['requires_document'] ?>">
                                    <?= safe_output($type['type_name']) ?> 
                                    (Max: <?= $type['max_days_per_year'] ?> days/year)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="start_date" name="start_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="end_date" name="end_date" required onchange="calculateDays()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="total_days" class="form-label">Total Days</label>
                            <input type="number" class="form-control" id="total_days" name="total_days" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="half_day" class="form-label">Half Day</label>
                            <select class="form-select" id="half_day" name="half_day">
                                <option value="">No</option>
                                <option value="first_half">First Half</option>
                                <option value="second_half">Second Half</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="is_paid" class="form-label">Leave Type</label>
                            <select class="form-select" id="is_paid" name="is_paid">
                                <option value="1">Paid Leave</option>
                                <option value="0">Unpaid Leave</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="reason" class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required placeholder="Please provide a reason for your leave"></textarea>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label for="notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Any additional information or notes"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="contact_during_leave" class="form-label">Contact During Leave</label>
                            <input type="text" class="form-control" id="contact_during_leave" name="contact_during_leave" placeholder="Phone number or email">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="handover_to" class="form-label">Handover To</label>
                            <select class="form-select" id="handover_to" name="handover_to">
                                <option value="">Select Colleague</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['employee_id'] ?>">
                                    <?= safe_output($emp['first_name'] . ' ' . $emp['last_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <div id="documentSection" style="display: none;">
                                <label for="document" class="form-label">Supporting Document</label>
                                <input type="file" class="form-control" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Upload supporting document (e.g., medical certificate)</small>
                            </div>
                        </div>
                        
                        <!-- Leave Balance Information -->
                        <div class="col-12 mb-3">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Leave Balance Information</h6>
                                </div>
                                <div class="card-body">
                                    <div id="balanceInfo">
                                        <p class="text-muted mb-0">Select an employee and leave type to view balance information.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Submit Leave Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Leave Modal -->
<?php if ($can_edit_leaves): ?>
<div class="modal fade" id="bulkLeaveModal" tabindex="-1" aria-labelledby="bulkLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="bulkLeaveModalLabel">
                    <i class="bi bi-upload"></i> Bulk Leave Import
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkLeaveForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="bulk-leave-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the leave data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="bulk_file" name="bulk_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bulk_action" class="form-label">Import Action</label>
                        <select class="form-select" id="bulk_action" name="bulk_action">
                            <option value="add_new">Add New Leaves Only</option>
                            <option value="update_existing">Update Existing Leaves</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="bulk_skip_errors" name="skip_errors">
                        <label class="form-check-label" for="bulk_skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadLeaveTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Upload & Process
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Leave Modal -->
<div class="modal fade" id="editLeaveModal" tabindex="-1" aria-labelledby="editLeaveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editLeaveModalLabel">
                    <i class="bi bi-pencil"></i> Edit Leave Application
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editLeaveForm">
                <div class="modal-body">
                    <div id="edit-leave-message" class="mb-3"></div>
                    <input type="hidden" id="edit_leave_id" name="leave_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_leave_type" class="form-label">Leave Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_leave_type" name="leave_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($leave_types as $type): ?>
                                <option value="<?= $type['type_name'] ?>">
                                    <?= safe_output($type['type_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_total_days" class="form-label">Total Days</label>
                            <input type="number" class="form-control" id="edit_total_days" name="total_days" readonly>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_reason" class="form-label">Reason for Leave <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="edit_reason" name="reason" rows="3" required></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="edit_notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Update Leave Application
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

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
    let leavesTable = $('#leavesTable').DataTable({
        language: {
            search: "Search leaves:",
            lengthMenu: "Show _MENU_ leaves per page",
            info: "Showing _START_ to _END_ of _TOTAL_ leaves",
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
                title: 'Leaves_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                titleAttr: 'Export to PDF',
                title: 'Leaves_List_' + new Date().toISOString().slice(0,10)
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

    // Apply leave form submission
    $('#applyLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...');

        $.ajax({
            url: 'api/apply_leave.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#apply-leave-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#apply-leave-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
                }
            },
            error: function(xhr, status, error) {
                $('#apply-leave-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
                console.error('Error:', error);
            }
        });
    });

    // Bulk leave form submission
    $('#bulkLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: 'api/import_leaves.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#bulk-leave-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    if (response.results) {
                        let resultsHtml = '<div class="mt-2"><small>';
                        resultsHtml += 'Total rows: ' + response.results.total_rows + '<br>';
                        resultsHtml += 'Successful: ' + response.results.successful + '<br>';
                        resultsHtml += 'Failed: ' + response.results.failed + '<br>';
                        resultsHtml += 'Skipped: ' + response.results.skipped + '<br>';
                        if (response.results.errors && response.results.errors.length > 0) {
                            resultsHtml += '<strong>Errors:</strong><ul>';
                            response.results.errors.forEach(function(error) {
                                resultsHtml += '<li>' + error + '</li>';
                            });
                            resultsHtml += '</ul>';
                        }
                        resultsHtml += '</small></div>';
                        $('#bulk-leave-message').append(resultsHtml);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $('#bulk-leave-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
                }
            },
            error: function(xhr, status, error) {
                $('#bulk-leave-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
                console.error('Error:', error);
            }
        });
    });

    // Edit leave form submission
    $('#editLeaveForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_leave.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit-leave-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#edit-leave-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
                }
            },
            error: function(xhr, status, error) {
                $('#edit-leave-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
                console.error('Error:', error);
            }
        });
    });

    // Show/hide document section based on leave type
    $('#leave_type').on('change', function() {
        const selectedOption = $(this).find(':selected');
        const requiresDoc = selectedOption.data('requires-doc') == 1;
        
        if (requiresDoc) {
            $('#documentSection').show();
        } else {
            $('#documentSection').hide();
        }
        
        updateLeaveBalance();
    });

    // Checkbox selection handlers
    $('.leave-checkbox').on('change', function() {
        updateSelectedCount();
    });

    // Reset forms when modals are closed
    $('#applyLeaveModal').on('hidden.bs.modal', function() {
        $('#applyLeaveForm')[0].reset();
        $('#apply-leave-message').html('');
        $('#documentSection').hide();
        $('#applyLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Submit Leave Application');
    });
    
    $('#bulkLeaveModal').on('hidden.bs.modal', function() {
        $('#bulkLeaveForm')[0].reset();
        $('#bulk-leave-message').html('');
        $('#bulkLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
    });
    
    $('#editLeaveModal').on('hidden.bs.modal', function() {
        $('#editLeaveForm')[0].reset();
        $('#edit-leave-message').html('');
        $('#editLeaveForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Leave Application');
    });
});

function clearFilters() {
    window.location.href = 'leaves.php';
}

function exportLeaves() {
    // Trigger DataTable export
    $('#leavesTable').DataTable().button('.buttons-excel').trigger();
}

function getSelectedLeaveIds() {
    const selectedIds = [];
    $('.leave-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });
    return selectedIds;
}

function updateSelectedCount() {
    const selectedCount = $('.leave-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
}

function toggleSelectAll(checkbox) {
    $('.leave-checkbox').prop('checked', checkbox.checked);
    updateSelectedCount();
}

function selectAllLeaves() {
    $('#selectAll').prop('checked', true);
    $('.leave-checkbox').prop('checked', true);
    updateSelectedCount();
}

function bulkUpdateStatus(status) {
    const selectedIds = getSelectedLeaveIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one leave.');
        return;
    }
    
    if (!confirm(`Are you sure you want to ${status} ${selectedIds.length} leave application(s)?`)) {
        return;
    }

    $.ajax({
        url: 'api/bulk_update_leave_status.php',
        type: 'POST',
        data: { 
            leave_ids: selectedIds,
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

function bulkExportApplications() {
    const selectedIds = getSelectedLeaveIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one leave.');
        return;
    }
    
    // Create a form and submit to generate PDF
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/export_leave_applications.php';
    form.target = '_blank';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'leave_ids';
    input.value = JSON.stringify(selectedIds);
    form.appendChild(input);
    
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

function calculateDays() {
    const startDate = $('#start_date').val();
    const endDate = $('#end_date').val();
    const halfDay = $('#half_day').val();
    
    if (startDate && endDate) {
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        // Calculate difference in days
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // Include both start and end dates
        
        let totalDays = diffDays;
        
        // Adjust for half day
        if (halfDay) {
            totalDays = diffDays - 0.5;
        }
        
        $('#total_days').val(totalDays);
        $('#edit_total_days').val(totalDays);
        
        updateLeaveBalance();
    }
}

function updateLeaveBalance() {
    const employeeId = $('#employee_id').val();
    const leaveType = $('#leave_type').val();
    const totalDays = parseFloat($('#total_days').val()) || 0;
    
    if (employeeId && leaveType) {
        $.ajax({
            url: 'api/get_leave_balance.php',
            type: 'GET',
            data: { 
                employee_id: employeeId,
                leave_type: leaveType
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const balance = response.balance;
                    const maxDays = response.max_days_per_year || 0;
                    const usedDays = balance.used_days || 0;
                    const remaining = maxDays - usedDays;
                    
                    let balanceHtml = `
                        <div class="row">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4 class="text-primary">${remaining}</h4>
                                    <small class="text-muted">Days Remaining</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4>${usedDays}</h4>
                                    <small class="text-muted">Days Used</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <h4>${maxDays}</h4>
                                    <small class="text-muted">Annual Limit</small>
                                </div>
                            </div>
                        </div>
                        <div class="progress mt-2" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: ${(usedDays/maxDays)*100}%"></div>
                        </div>
                    `;
                    
                    if (totalDays > 0) {
                        const afterLeave = remaining - totalDays;
                        if (afterLeave < 0) {
                            balanceHtml += `
                                <div class="alert alert-danger mt-2">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    After this leave, you will exceed your annual limit by ${-afterLeave} days.
                                </div>
                            `;
                        } else {
                            balanceHtml += `
                                <div class="alert alert-info mt-2">
                                    <i class="bi bi-info-circle"></i> 
                                    After this leave, you will have ${afterLeave} days remaining.
                                </div>
                            `;
                        }
                    }
                    
                    $('#balanceInfo').html(balanceHtml);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error loading balance:', error);
            }
        });
    }
}

function editLeave(leaveId) {
    // Load leave data for editing
    $.ajax({
        url: 'api/get_leave.php',
        type: 'GET',
        data: { id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_leave_id').val(response.data.leave_id);
                $('#edit_leave_type').val(response.data.leave_type);
                $('#edit_start_date').val(response.data.start_date);
                $('#edit_end_date').val(response.data.end_date);
                $('#edit_total_days').val(response.data.total_days);
                $('#edit_reason').val(response.data.reason);
                $('#edit_notes').val(response.data.notes || '');
                
                // Show edit modal
                $('#editLeaveModal').modal('show');
            } else {
                alert('Error loading leave data: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading leave data. Please try again.');
            console.error('Error:', error);
        }
    });
}

function approveLeave(leaveId) {
    if (!confirm('Are you sure you want to approve this leave application?')) {
        return;
    }

    $.ajax({
        url: 'api/approve_leave.php',
        type: 'POST',
        data: { 
            leave_id: leaveId,
            action: 'approve'
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error approving leave: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error approving leave. Please try again.');
            console.error('Error:', error);
        }
    });
}

function rejectLeave(leaveId) {
    const reason = prompt('Please provide a reason for rejection:');
    if (reason === null) return; // User cancelled
    
    if (!reason.trim()) {
        alert('Reason is required for rejection.');
        return;
    }

    $.ajax({
        url: 'api/reject_leave.php',
        type: 'POST',
        data: { 
            leave_id: leaveId,
            reason: reason
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error rejecting leave: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error rejecting leave. Please try again.');
            console.error('Error:', error);
        }
    });
}

function cancelLeave(leaveId) {
    if (!confirm('Are you sure you want to cancel this leave application?')) {
        return;
    }

    $.ajax({
        url: 'api/cancel_leave.php',
        type: 'POST',
        data: { leave_id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error cancelling leave: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error cancelling leave. Please try again.');
            console.error('Error:', error);
        }
    });
}

function duplicateLeave(leaveId) {
    if (!confirm('Are you sure you want to duplicate this leave application?')) {
        return;
    }

    $.ajax({
        url: 'api/duplicate_leave.php',
        type: 'POST',
        data: { leave_id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error duplicating leave: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error duplicating leave. Please try again.');
            console.error('Error:', error);
        }
    });
}

function deleteLeave(leaveId) {
    if (!confirm('Are you sure you want to delete this leave application? This action cannot be undone.')) {
        return;
    }

    $.ajax({
        url: 'api/delete_leave.php',
        type: 'POST',
        data: { leave_id: leaveId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error deleting leave: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error deleting leave. Please try again.');
            console.error('Error:', error);
        }
    });
}

function downloadLeaveTemplate() {
    // Create a CSV template file
    const headers = [
        'employee_id', 'leave_type', 'start_date', 'end_date', 'reason', 
        'notes', 'contact_during_leave', 'handover_to'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n1,annual,2023-10-01,2023-10-05,Family vacation,Will be available on phone,+255123456789,2";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "leaves_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
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
.card.bg-warning,
.card.bg-success,
.card.bg-danger,
.card.bg-info,
.card.bg-secondary {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }
.card.bg-info { background: linear-gradient(45deg, #0dcaf0, #0aa2c0); }
.card.bg-secondary { background: linear-gradient(45deg, #6c757d, #5a6268); }

/* Checkbox styling */
.leave-checkbox {
    cursor: pointer;
}

/* Progress bar customization */
.progress-bar {
    font-size: 0.75rem;
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
    .col-xl-2, .col-md-3 {
        margin-bottom: 0.5rem;
    }
    
    .table-responsive {
        overflow-x: auto;
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