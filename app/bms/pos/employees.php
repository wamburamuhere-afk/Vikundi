<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for employee permissions
$can_view_employees = in_array($user_role, ['Admin', 'Manager', 'HR']);
$can_edit_employees = in_array($user_role, ['Admin', 'HR']);
$can_delete_employees = in_array($user_role, ['Admin']);

if (!$can_view_employees) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get departments
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get designations
$designations = $pdo->query("SELECT * FROM designations WHERE status = 'active' ORDER BY designation_name")->fetchAll(PDO::FETCH_ASSOC);

// Get employment types
$employment_types = $pdo->query("SELECT * FROM employment_types WHERE status = 'active' ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees with additional data
$query = "
    SELECT 
        e.*,
        d.department_name,
        des.designation_name,
        et.type_name as employment_type,
        u1.username as created_by_name,
        u2.username as updated_by_name,
        COUNT(DISTINCT a.attendance_id) as total_attendance,
        COUNT(DISTINCT l.leave_id) as total_leaves,
        COUNT(DISTINCT p.payroll_id) as total_payrolls
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    LEFT JOIN employment_types et ON e.employment_type_id = et.type_id
    LEFT JOIN attendance a ON e.employee_id = a.employee_id
    LEFT JOIN leaves l ON e.employee_id = l.employee_id
    LEFT JOIN payroll p ON e.employee_id = p.employee_id
    LEFT JOIN users u1 ON e.created_by = u1.user_id
    LEFT JOIN users u2 ON e.updated_by = u2.user_id
    WHERE e.status != 'terminated'
    GROUP BY e.employee_id
    ORDER BY e.first_name, e.last_name ASC
";

$stmt = $pdo->query($query);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_employees = count($employees);
$active_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'active';
});
$on_leave_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'on_leave';
});
$probation_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'probation';
});
$contract_employees = array_filter($employees, function($employee) {
    return $employee['employment_status'] == 'contract';
});

// Helper functions removed, now in helpers.php
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-people"></i> Employee Management</h2>
                    <p class="text-muted mb-0">Manage your employees, their records, and payroll information</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_employees): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="bi bi-plus-circle"></i> Add Employee
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportEmployees()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <?php if ($can_edit_employees): ?>
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#importEmployeesModal">
                        <i class="bi bi-upload"></i> Import
                    </button>
                    <?php endif; ?>
                    <a href="employee_reports.php" class="btn btn-warning">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_employees ?></h4>
                            <p class="mb-0">Total Employees</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($active_employees) ?></h4>
                            <p class="mb-0">Active</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($on_leave_employees) ?></h4>
                            <p class="mb-0">On Leave</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($probation_employees) ?></h4>
                            <p class="mb-0">Probation</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Employment Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="probation">Probation</option>
                            <option value="contract">Contract</option>
                            <option value="on_leave">On Leave</option>
                            <option value="terminated">Terminated</option>
                            <option value="resigned">Resigned</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" id="departmentFilter">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Designation</label>
                        <select class="form-select" id="designationFilter">
                            <option value="">All Designations</option>
                            <?php foreach ($designations as $designation): ?>
                                <option value="<?= $designation['designation_id'] ?>"><?= safe_output($designation['designation_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Employment Type</label>
                        <select class="form-select" id="employmentTypeFilter">
                            <option value="">All Types</option>
                            <?php foreach ($employment_types as $type): ?>
                                <option value="<?= $type['type_id'] ?>"><?= safe_output($type['type_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Search</label>
                        <input type="text" class="form-control" id="searchEmployees" placeholder="Search by name, employee ID, email, phone...">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="bi bi-arrow-clockwise"></i> Clear
                        </button>
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Employees Table -->
    <div class="card">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Employees List</h5>
            <div class="d-flex">
                <span class="badge bg-light text-dark me-2">
                    <?= $total_employees ?> employees
                </span>
                <div class="btn-group" role="group">
                    <button type="button" class="btn btn-light btn-sm" onclick="toggleView('table')" title="Table View">
                        <i class="bi bi-table"></i>
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="toggleView('card')" title="Card View">
                        <i class="bi bi-grid"></i>
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($employees) > 0): ?>
                <!-- Table View -->
                <div id="tableView" class="table-responsive">
                    <table id="employeesTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Contact Info</th>
                                <th>Department & Designation</th>
                                <th>Employment Details</th>
                                <th>Salary</th>
                                <th>Attendance/Leaves</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $employee): ?>
                            <tr>
                                <td>
                                    <code><?= safe_output($employee['employee_id']) ?></code><br>
                                    <small class="text-muted"><?= safe_output($employee['employee_number']) ?></small>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></strong><br>
                                        <small class="text-muted">
                                            <i class="bi bi-gender-<?= $employee['gender'] == 'male' ? 'male' : 'female' ?>"></i>
                                            <?= ucfirst($employee['gender']) ?>, 
                                            Age: <?= calculate_age($employee['date_of_birth']) ?>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php if (!empty($employee['email'])): ?>
                                        <small><i class="bi bi-envelope"></i> <?= safe_output($employee['email']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['phone'])): ?>
                                        <small><i class="bi bi-telephone"></i> <?= safe_output($employee['phone']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['emergency_contact'])): ?>
                                        <small><i class="bi bi-telephone-plus"></i> <?= safe_output($employee['emergency_contact']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php if (!empty($employee['department_name'])): ?>
                                        <strong><?= safe_output($employee['department_name']) ?></strong><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['designation_name'])): ?>
                                        <small class="text-muted"><?= safe_output($employee['designation_name']) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['reporting_to'])): ?>
                                        <small>Reports to: <?= safe_output($employee['reporting_to']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <small><?= safe_output($employee['employment_type']) ?></small><br>
                                        <?php if (!empty($employee['hire_date'])): ?>
                                        <small>Hired: <?= date('d M Y', strtotime($employee['hire_date'])) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['probation_end_date'])): ?>
                                        <small>Probation ends: <?= date('d M Y', strtotime($employee['probation_end_date'])) ?></small><br>
                                        <?php endif; ?>
                                        <?php if (!empty($employee['contract_end_date'])): ?>
                                        <small>Contract ends: <?= date('d M Y', strtotime($employee['contract_end_date'])) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <strong><?= format_currency($employee['basic_salary'] ?? 0) ?></strong><br>
                                        <small class="text-success">
                                            <i class="bi bi-arrow-up-circle"></i> <?= format_currency($employee['allowances'] ?? 0) ?><br>
                                            <small class="text-danger">
                                                <i class="bi bi-arrow-down-circle"></i> <?= format_currency($employee['deductions'] ?? 0) ?>
                                            </small>
                                        </small>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <div class="d-flex justify-content-around">
                                            <div>
                                                <span class="badge bg-primary"><?= $employee['total_attendance'] ?? 0 ?></span>
                                                <br>
                                                <small>Attendance</small>
                                            </div>
                                            <div>
                                                <span class="badge bg-info"><?= $employee['total_leaves'] ?? 0 ?></span>
                                                <br>
                                                <small>Leaves</small>
                                            </div>
                                            <div>
                                                <span class="badge bg-success"><?= $employee['total_payrolls'] ?? 0 ?></span>
                                                <br>
                                                <small>Payrolls</small>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($employee['employment_status']) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $employee['employment_status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="employee_details.php?id=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="employee_profile.php?id=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-person"></i> View Profile
                                                </a>
                                            </li>
                                            <?php if ($can_edit_employees): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editEmployee(<?= $employee['employee_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Employee
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="attendance.php?employee=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-clock"></i> Attendance
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="leaves.php?employee=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-calendar"></i> Leaves
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="payroll.php?employee=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-cash"></i> Payroll
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="documents.php?employee=<?= $employee['employee_id'] ?>">
                                                    <i class="bi bi-files"></i> Documents
                                                </a>
                                            </li>
                                            
                                            <?php if ($can_edit_employees): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($employee['employment_status'] == 'active'): ?>
                                            <li>
                                                <a class="dropdown-item text-warning" href="#" onclick="updateStatus(<?= $employee['employee_id'] ?>, 'probation')">
                                                    <i class="bi bi-clock"></i> Move to Probation
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item text-info" href="#" onclick="updateStatus(<?= $employee['employee_id'] ?>, 'on_leave')">
                                                    <i class="bi bi-calendar"></i> Mark as On Leave
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($employee['employment_status'] == 'probation'): ?>
                                            <li>
                                                <a class="dropdown-item text-success" href="#" onclick="updateStatus(<?= $employee['employee_id'] ?>, 'active')">
                                                    <i class="bi bi-check-circle"></i> Confirm Employment
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if (in_array($employee['employment_status'], ['active', 'probation', 'contract'])): ?>
                                            <li>
                                                <a class="dropdown-item text-secondary" href="#" onclick="updateStatus(<?= $employee['employee_id'] ?>, 'resigned')">
                                                    <i class="bi bi-door-open"></i> Mark as Resigned
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($employee['employment_status'] != 'terminated'): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="updateStatus(<?= $employee['employee_id'] ?>, 'terminated')">
                                                    <i class="bi bi-person-x"></i> Terminate
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <?php if ($can_delete_employees): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $employee['employee_id'] ?>)">
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
                
                <!-- Card View (Hidden by default) -->
                <div id="cardView" class="row d-none">
                    <?php foreach ($employees as $employee): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?></h6>
                                    <small class="text-muted"><?= safe_output($employee['employee_number']) ?></small>
                                </div>
                                <span class="badge bg-<?= get_status_badge($employee['employment_status']) ?>">
                                    <?= ucfirst(substr($employee['employment_status'], 0, 1)) ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-3">
                                    <div class="avatar-placeholder bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px; font-size: 2rem;">
                                        <?= strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1)) ?>
                                    </div>
                                </div>
                                
                                <div class="mb-2 text-center">
                                    <strong><?= safe_output($employee['designation_name']) ?></strong><br>
                                    <small class="text-muted"><?= safe_output($employee['department_name']) ?></small>
                                </div>
                                
                                <div class="mb-2">
                                    <?php if (!empty($employee['email'])): ?>
                                    <small><i class="bi bi-envelope"></i> <?= safe_output($employee['email']) ?></small><br>
                                    <?php endif; ?>
                                    <?php if (!empty($employee['phone'])): ?>
                                    <small><i class="bi bi-telephone"></i> <?= safe_output($employee['phone']) ?></small>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-2">
                                    <small><i class="bi bi-calendar"></i> 
                                        Hired: <?= date('d M Y', strtotime($employee['hire_date'])) ?>
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <small class="text-success">
                                        <i class="bi bi-cash"></i> Salary: <?= format_currency($employee['basic_salary'] ?? 0) ?>
                                    </small>
                                </div>
                                
                                <div class="d-flex justify-content-between mt-3">
                                    <div class="text-center">
                                        <div class="badge bg-primary"><?= $employee['total_attendance'] ?? 0 ?></div>
                                        <br>
                                        <small>Attendance</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-info"><?= $employee['total_leaves'] ?? 0 ?></div>
                                        <br>
                                        <small>Leaves</small>
                                    </div>
                                    <div class="text-center">
                                        <div class="badge bg-success"><?= $employee['total_payrolls'] ?? 0 ?></div>
                                        <br>
                                        <small>Payrolls</small>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="employee_details.php?id=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($can_edit_employees): ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="editEmployee(<?= $employee['employee_id'] ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <?php endif; ?>
                                    <a href="attendance.php?employee=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-success" title="Attendance">
                                        <i class="bi bi-clock"></i>
                                    </a>
                                    <a href="payroll.php?employee=<?= $employee['employee_id'] ?>" class="btn btn-sm btn-outline-info" title="Payroll">
                                        <i class="bi bi-cash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Employees Found</h4>
                    <p class="text-muted">Get started by adding your first employee.</p>
                    <?php if ($can_edit_employees): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Employee
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Employee Modal -->
<?php if ($can_edit_employees): ?>
<div class="modal fade" id="addEmployeeModal" tabindex="-1" aria-labelledby="addEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addEmployeeModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Employee
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addEmployeeForm">
                <div class="modal-body">
                    <div id="add-employee-message" class="mb-3"></div>
                    
                    <ul class="nav nav-tabs mb-3" id="employeeTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab">Personal Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="employment-tab" data-bs-toggle="tab" data-bs-target="#employment" type="button" role="tab">Employment</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="salary-tab" data-bs-toggle="tab" data-bs-target="#salary" type="button" role="tab">Salary & Benefits</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#contact" type="button" role="tab">Contact Details</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="bank-tab" data-bs-toggle="tab" data-bs-target="#bank" type="button" role="tab">Bank & Documents</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="employeeTabContent">
                        <!-- Personal Information Tab -->
                        <div class="tab-pane fade show active" id="personal" role="tabpanel">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required placeholder="Enter first name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name" placeholder="Enter middle name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required placeholder="Enter last name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="gender" class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="">Select Gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="marital_status" class="form-label">Marital Status</label>
                                    <select class="form-select" id="marital_status" name="marital_status">
                                        <option value="">Select Status</option>
                                        <option value="single">Single</option>
                                        <option value="married">Married</option>
                                        <option value="divorced">Divorced</option>
                                        <option value="widowed">Widowed</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="national_id" class="form-label">National ID Number</label>
                                    <input type="text" class="form-control" id="national_id" name="national_id" placeholder="National ID">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="passport_number" class="form-label">Passport Number</label>
                                    <input type="text" class="form-control" id="passport_number" name="passport_number" placeholder="Passport number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="notes" class="form-label">Personal Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2" placeholder="Any personal notes about employee"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Employment Details Tab -->
                        <div class="tab-pane fade" id="employment" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="employee_number" class="form-label">Employee Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="employee_number" name="employee_number" required placeholder="EMP-001">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="hire_date" class="form-label">Hire Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="hire_date" name="hire_date" required value="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                    <select class="form-select" id="department_id" name="department_id" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="designation_id" class="form-label">Designation <span class="text-danger">*</span></label>
                                    <select class="form-select" id="designation_id" name="designation_id" required>
                                        <option value="">Select Designation</option>
                                        <?php foreach ($designations as $designation): ?>
                                        <option value="<?= $designation['designation_id'] ?>"><?= safe_output($designation['designation_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="employment_type_id" class="form-label">Employment Type <span class="text-danger">*</span></label>
                                    <select class="form-select" id="employment_type_id" name="employment_type_id" required>
                                        <option value="">Select Type</option>
                                        <?php foreach ($employment_types as $type): ?>
                                        <option value="<?= $type['type_id'] ?>"><?= safe_output($type['type_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="employment_status" class="form-label">Employment Status <span class="text-danger">*</span></label>
                                    <select class="form-select" id="employment_status" name="employment_status" required>
                                        <option value="probation" selected>Probation</option>
                                        <option value="active">Active</option>
                                        <option value="contract">Contract</option>
                                        <option value="on_leave">On Leave</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="probation_end_date" class="form-label">Probation End Date</label>
                                    <input type="date" class="form-control" id="probation_end_date" name="probation_end_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="contract_end_date" class="form-label">Contract End Date</label>
                                    <input type="date" class="form-control" id="contract_end_date" name="contract_end_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="reporting_to" class="form-label">Reporting To</label>
                                    <input type="text" class="form-control" id="reporting_to" name="reporting_to" placeholder="Manager name or ID">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="work_location" class="form-label">Work Location</label>
                                    <input type="text" class="form-control" id="work_location" name="work_location" placeholder="Office location">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Salary & Benefits Tab -->
                        <div class="tab-pane fade" id="salary" role="tabpanel">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="basic_salary" class="form-label">Basic Salary <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control" id="basic_salary" name="basic_salary" step="0.01" required placeholder="0.00">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="hourly_rate" class="form-label">Hourly Rate</label>
                                    <input type="number" class="form-control" id="hourly_rate" name="hourly_rate" step="0.01" placeholder="Hourly rate if applicable">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <select class="form-select" id="currency" name="currency">
                                        <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_frequency" class="form-label">Payment Frequency</label>
                                    <select class="form-select" id="payment_frequency" name="payment_frequency">
                                        <option value="monthly" selected>Monthly</option>
                                        <option value="biweekly">Bi-Weekly</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="daily">Daily</option>
                                        <option value="hourly">Hourly</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="payment_method" class="form-label">Payment Method</label>
                                    <select class="form-select" id="payment_method" name="payment_method">
                                        <option value="bank">Bank Transfer</option>
                                        <option value="cash">Cash</option>
                                        <option value="check">Check</option>
                                        <option value="mobile">Mobile Money</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="social_security_number" class="form-label">Social Security Number</label>
                                    <input type="text" class="form-control" id="social_security_number" name="social_security_number" placeholder="SSN/NIDA">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Benefits</label>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="health_insurance" name="benefits[]" value="health_insurance">
                                                <label class="form-check-label" for="health_insurance">Health Insurance</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="life_insurance" name="benefits[]" value="life_insurance">
                                                <label class="form-check-label" for="life_insurance">Life Insurance</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="pension" name="benefits[]" value="pension">
                                                <label class="form-check-label" for="pension">Pension</label>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="transport_allowance" name="benefits[]" value="transport_allowance">
                                                <label class="form-check-label" for="transport_allowance">Transport Allowance</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Tab -->
                        <div class="tab-pane fade" id="contact" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" required placeholder="employee@company.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="phone" name="phone" required placeholder="+255 123 456 789">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="alternate_phone" class="form-label">Alternate Phone</label>
                                    <input type="text" class="form-control" id="alternate_phone" name="alternate_phone" placeholder="Alternate phone number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="emergency_contact" class="form-label">Emergency Contact <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" required placeholder="Emergency contact name & number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="address" class="form-label">Address <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="address" name="address" rows="2" required placeholder="Residential address"></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="city" class="form-label">City</label>
                                    <input type="text" class="form-control" id="city" name="city" placeholder="City">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" placeholder="Country" value="Tanzania">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Bank & Documents Tab -->
                        <div class="tab-pane fade" id="bank" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account Number</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="bank_branch" class="form-label">Bank Branch</label>
                                    <input type="text" class="form-control" id="bank_branch" name="bank_branch" placeholder="Bank branch">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="mobile_money" class="form-label">Mobile Money Number</label>
                                    <input type="text" class="form-control" id="mobile_money" name="mobile_money" placeholder="Mobile money number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label">Required Documents</label>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="cv_attached" name="documents[]" value="cv">
                                                <label class="form-check-label" for="cv_attached">CV/Resume</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="id_copy" name="documents[]" value="id">
                                                <label class="form-check-label" for="id_copy">ID Copy</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="certificates" name="documents[]" value="certificates">
                                                <label class="form-check-label" for="certificates">Certificates</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="additional_notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="additional_notes" name="additional_notes" rows="3" placeholder="Any additional notes or information"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Employee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Employees Modal -->
<div class="modal fade" id="importEmployeesModal" tabindex="-1" aria-labelledby="importEmployeesModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importEmployeesModalLabel">
                    <i class="bi bi-upload"></i> Import Employees
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importEmployeesForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the employee data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_action" class="form-label">Import Action</label>
                        <select class="form-select" id="import_action" name="import_action">
                            <option value="add_new">Add New Employees Only</option>
                            <option value="update_existing">Update Existing Employees</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_errors" name="skip_errors">
                        <label class="form-check-label" for="skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Employees
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Edit Modal -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1" aria-labelledby="editEmployeeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="editEmployeeModalLabel">
                    <i class="bi bi-pencil"></i> Quick Edit Employee
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editEmployeeForm">
                <div class="modal-body">
                    <div id="edit-employee-message" class="mb-3"></div>
                    <input type="hidden" id="edit_employee_id" name="employee_id">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_last_name" name="last_name" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="edit_email" name="email" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_phone" name="phone" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_employment_status" class="form-label">Status</label>
                            <select class="form-select" id="edit_employment_status" name="employment_status">
                                <option value="active">Active</option>
                                <option value="probation">Probation</option>
                                <option value="contract">Contract</option>
                                <option value="on_leave">On Leave</option>
                                <option value="resigned">Resigned</option>
                                <option value="terminated">Terminated</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_department_id" class="form-label">Department</label>
                        <select class="form-select" id="edit_department_id" name="department_id">
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>"><?= safe_output($dept['department_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_basic_salary" class="form-label">Basic Salary</label>
                        <input type="number" class="form-control" id="edit_basic_salary" name="basic_salary" step="0.01">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-check-circle"></i> Update Employee
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
    let employeesTable = $('#employeesTable').DataTable({
        language: {
            search: "Search employees:",
            lengthMenu: "Show _MENU_ employees per page",
            info: "Showing _START_ to _END_ of _TOTAL_ employees",
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
                title: 'Employees_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                titleAttr: 'Export to PDF',
                title: 'Employees_List_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-info',
                titleAttr: 'Print table'
            }
        ],
        pageLength: 25,
        order: [[1, 'asc']]
    });

    // Add employee form submission
    $('#addEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/add_employee.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-employee-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-employee-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
                }
            },
            error: function(xhr, status, error) {
                $('#add-employee-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
                console.error('Error:', error);
            }
        });
    });

    // Import form submission
    $('#importEmployeesForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Importing...');

        $.ajax({
            url: 'api/import_employees.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#import-message').html('<div class="alert alert-success">' + response.message + '</div>');
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
                        $('#import-message').append(resultsHtml);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $('#import-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
                }
            },
            error: function(xhr, status, error) {
                $('#import-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
                console.error('Error:', error);
            }
        });
    });

    // Edit employee form submission
    $('#editEmployeeForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        $.ajax({
            url: 'api/update_employee.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#edit-employee-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#edit-employee-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
                }
            },
            error: function(xhr, status, error) {
                $('#edit-employee-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
                console.error('Error:', error);
            }
        });
    });

    // Reset forms when modals are closed
    $('#addEmployeeModal').on('hidden.bs.modal', function() {
        $('#addEmployeeForm')[0].reset();
        $('#add-employee-message').html('');
        $('#addEmployeeForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Employee');
        $('#employeeTabs .nav-link:first').tab('show');
    });
    
    $('#importEmployeesModal').on('hidden.bs.modal', function() {
        $('#importEmployeesForm')[0].reset();
        $('#import-message').html('');
        $('#importEmployeesForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Import Employees');
    });
    
    $('#editEmployeeModal').on('hidden.bs.modal', function() {
        $('#editEmployeeForm')[0].reset();
        $('#edit-employee-message').html('');
        $('#editEmployeeForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Employee');
    });
});

function applyFilters() {
    const table = $('#employeesTable').DataTable();
    
    // Status filter
    const status = $('#statusFilter').val();
    if (status) {
        table.column(7).search('^' + status + '$', true, false).draw();
    } else {
        table.column(7).search('').draw();
    }
    
    // Search filter
    const search = $('#searchEmployees').val();
    table.search(search).draw();
    
    // Department filter
    const department = $('#departmentFilter').val();
    if (department) {
        table.column(3).search(department).draw();
    } else {
        table.column(3).search('').draw();
    }
    
    // Designation filter
    const designation = $('#designationFilter').val();
    if (designation) {
        // Search in designation column
        table.column(3).search(designation).draw();
    }
    
    // Employment type filter
    const empType = $('#employmentTypeFilter').val();
    if (empType) {
        table.column(4).search(empType).draw();
    }
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#departmentFilter').val('');
    $('#designationFilter').val('');
    $('#employmentTypeFilter').val('');
    $('#searchEmployees').val('');
    
    const table = $('#employeesTable').DataTable();
    table.search('').columns().search('').draw();
}

function editEmployee(employeeId) {
    // Load employee data for quick edit
    $.ajax({
        url: 'api/get_employee.php',
        type: 'GET',
        data: { id: employeeId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form
                $('#edit_employee_id').val(response.data.employee_id);
                $('#edit_first_name').val(response.data.first_name);
                $('#edit_last_name').val(response.data.last_name);
                $('#edit_email').val(response.data.email || '');
                $('#edit_phone').val(response.data.phone || '');
                $('#edit_employment_status').val(response.data.employment_status);
                $('#edit_department_id').val(response.data.department_id);
                $('#edit_basic_salary').val(response.data.basic_salary);
                
                // Show edit modal
                $('#editEmployeeModal').modal('show');
            } else {
                alert('Error loading employee data: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error loading employee data. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateStatus(employeeId, status) {
    const actionMap = {
        'active': 'activate',
        'probation': 'move to probation',
        'contract': 'change to contract',
        'on_leave': 'mark as on leave',
        'resigned': 'mark as resigned',
        'terminated': 'terminate'
    };
    
    const action = actionMap[status] || 'update';
    
    if (!confirm('Are you sure you want to ' + action + ' this employee?')) {
        return;
    }

    $.ajax({
        url: 'api/update_employee_status.php',
        type: 'POST',
        data: { 
            employee_id: employeeId,
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

function confirmDelete(employeeId) {
    if (confirm('Are you sure you want to delete this employee? This action cannot be undone.')) {
        $.ajax({
            url: 'api/delete_employee.php',
            method: 'POST',
            data: { employee_id: employeeId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error deleting employee: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting employee. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function toggleView(viewType) {
    const tableView = $('#tableView');
    const cardView = $('#cardView');
    const tableBtn = $('button[onclick*="table"]');
    const cardBtn = $('button[onclick*="card"]');
    
    if (viewType === 'table') {
        tableView.removeClass('d-none');
        cardView.addClass('d-none');
        tableBtn.removeClass('btn-outline-light').addClass('btn-light');
        cardBtn.removeClass('btn-light').addClass('btn-outline-light');
    } else {
        tableView.addClass('d-none');
        cardView.removeClass('d-none');
        tableBtn.removeClass('btn-light').addClass('btn-outline-light');
        cardBtn.removeClass('btn-outline-light').addClass('btn-light');
    }
    
    // Store preference in localStorage
    localStorage.setItem('employeesView', viewType);
}

// Load view preference on page load
$(document).ready(function() {
    const savedView = localStorage.getItem('employeesView') || 'table';
    toggleView(savedView);
});

function exportEmployees() {
    // Trigger DataTable export
    $('#employeesTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    // Create a CSV template file
    const headers = [
        'employee_number', 'first_name', 'middle_name', 'last_name', 'gender',
        'date_of_birth', 'email', 'phone', 'emergency_contact', 'address',
        'city', 'country', 'national_id', 'hire_date', 'department_id',
        'designation_id', 'employment_type_id', 'employment_status',
        'basic_salary', 'currency', 'payment_frequency', 'payment_method'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\nEMP-001,John,,Doe,male,1990-01-15,john.doe@company.com,+255123456789,Emergency: +255987654321,123 Main St,Dar es Salaam,Tanzania,123456789,2023-01-01,1,1,1,active,500000,TZS,monthly,bank";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "employees_import_template.csv");
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
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Card view styling */
#cardView .card {
    transition: transform 0.2s;
}

#cardView .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* Avatar placeholder */
.avatar-placeholder {
    background: linear-gradient(45deg, #0d6efd, #0b5ed7);
    font-weight: bold;
}

/* Tab styling */
.nav-tabs .nav-link {
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}

.nav-tabs .nav-link.active {
    font-weight: 600;
}

/* Print styles */
@media print {
    .navbar, .card-header, .btn, .dropdown, .dataTables_length, 
    .dataTables_filter, .dataTables_info, .dataTables_paginate, 
    .dt-buttons {
        display: none !important;
    }
    
    .card {
        border: none;
        box-shadow: none;
    }
    
    .card-body {
        padding: 0;
    }
    
    table {
        width: 100% !important;
    }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    #cardView .col-xl-3 {
        flex: 0 0 100%;
        max-width: 100%;
    }
    
    .modal-dialog {
        margin: 0.5rem;
    }
    
    .nav-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    
    .nav-tabs .nav-item {
        white-space: nowrap;
    }
}

@media (max-width: 576px) {
    .btn-group {
        width: 100%;
        margin-top: 0.5rem;
    }
    
    .btn-group .btn {
        flex: 1;
    }
    
    .table-responsive {
        font-size: 0.85rem;
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