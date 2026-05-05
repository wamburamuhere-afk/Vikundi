<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user role for attendance permissions
$can_view_attendance = in_array($user_role, ['Admin', 'Manager', 'HR', 'Supervisor']);
$can_edit_attendance = in_array($user_role, ['Admin', 'HR', 'Supervisor']);
$can_approve_attendance = in_array($user_role, ['Admin', 'Manager']);

if (!$can_view_attendance) {
    header("Location: dashboard.php?error=Access Denied");
    exit();
}

// Get current date for default filter
$current_date = date('Y-m-d');
$current_month = date('Y-m');
$selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;
$selected_department = isset($_GET['department']) ? (int)$_GET['department'] : null;
$selected_status = isset($_GET['status']) ? $_GET['status'] : '';

// Get departments for filtering
$departments = $pdo->query("SELECT * FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// Get all active employees
$employees_query = "
    SELECT e.*, d.department_name 
    FROM employees e 
    LEFT JOIN departments d ON e.department_id = d.department_id 
    WHERE e.employment_status IN ('active', 'probation', 'contract') 
    AND e.status = 'active'
";

if ($selected_department) {
    $employees_query .= " AND e.department_id = ?";
    $employees_params = [$selected_department];
} else {
    $employees_params = [];
}

$employees_query .= " ORDER BY d.department_name, e.first_name, e.last_name";
$employees_stmt = $pdo->prepare($employees_query);
$employees_stmt->execute($employees_params);
$employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper functions
function format_time($time) {
    return !empty($time) ? date('h:i A', strtotime($time)) : '--:--';
}


function calculate_hours($check_in, $check_out) {
    if (empty($check_in) || empty($check_out)) return '0.00';
    
    $check_in_time = strtotime($check_in);
    $check_out_time = strtotime($check_out);
    
    $hours = ($check_out_time - $check_in_time) / 3600;
    return number_format($hours, 2);
}

// safe_output removed, now in helpers.php

function get_day_name($date) {
    return date('l', strtotime($date));
}

function is_weekend($date) {
    $day = date('N', strtotime($date));
    return ($day >= 6); // 6 = Saturday, 7 = Sunday
}

// Get attendance data for selected date
$attendance_data = [];
$attendance_summary = [
    'present' => 0,
    'absent' => 0,
    'late' => 0,
    'half_day' => 0,
    'leave' => 0,
    'holiday' => 0,
    'weekend' => 0,
    'total_employees' => 0
];

if ($employees) {
    // Get attendance records for selected date
    $attendance_query = "
        SELECT a.*, e.first_name, e.last_name, e.employee_number, e.department_id, d.department_name 
        FROM attendance a 
        LEFT JOIN employees e ON a.employee_id = e.employee_id 
        LEFT JOIN departments d ON e.department_id = d.department_id 
        WHERE a.attendance_date = ?
    ";
    
    if ($selected_department) {
        $attendance_query .= " AND e.department_id = ?";
        $attendance_params = [$selected_date, $selected_department];
    } else {
        $attendance_params = [$selected_date];
    }
    
    $attendance_stmt = $pdo->prepare($attendance_query);
    $attendance_stmt->execute($attendance_params);
    $existing_attendance = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Map attendance by employee_id for easy lookup
    $attendance_map = [];
    foreach ($existing_attendance as $record) {
        $attendance_map[$record['employee_id']] = $record;
    }
    
    // Prepare attendance data for display
    foreach ($employees as $employee) {
        $employee_id = $employee['employee_id'];
        
        if (isset($attendance_map[$employee_id])) {
            // Use existing attendance record
            $attendance_record = $attendance_map[$employee_id];
            $status = $attendance_record['status'];
            $check_in = $attendance_record['check_in_time'];
            $check_out = $attendance_record['check_out_time'];
            $hours = calculate_hours($check_in, $check_out);
            $notes = $attendance_record['notes'];
        } else {
            // Create default record based on day type
            if (is_weekend($selected_date)) {
                $status = 'weekend';
                $check_in = null;
                $check_out = null;
                $hours = '0.00';
                $notes = 'Weekend';
            } else {
                // Check if employee is on leave for this date
                $leave_check = $pdo->prepare("
                    SELECT * FROM leaves 
                    WHERE employee_id = ? 
                    AND start_date <= ? 
                    AND end_date >= ? 
                    AND status = 'approved'
                ");
                $leave_check->execute([$employee_id, $selected_date, $selected_date]);
                $on_leave = $leave_check->fetch();
                
                if ($on_leave) {
                    $status = 'leave';
                    $check_in = null;
                    $check_out = null;
                    $hours = '0.00';
                    $notes = $on_leave['leave_type'] . ' leave';
                } else {
                    $status = 'absent'; // Default status for work day
                    $check_in = null;
                    $check_out = null;
                    $hours = '0.00';
                    $notes = '';
                }
            }
        }
        
        // Update summary
        $attendance_summary[$status]++;
        $attendance_summary['total_employees']++;
        
        $attendance_data[] = [
            'employee_id' => $employee_id,
            'employee_number' => $employee['employee_number'],
            'first_name' => $employee['first_name'],
            'last_name' => $employee['last_name'],
            'department_name' => $employee['department_name'],
            'attendance_date' => $selected_date,
            'check_in_time' => $check_in,
            'check_out_time' => $check_out,
            'total_hours' => $hours,
            'status' => $status,
            'notes' => $notes,
            'existing_record' => isset($attendance_map[$employee_id])
        ];
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-clock"></i> Attendance Management</h2>
                    <p class="text-muted mb-0">Track and manage employee attendance</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_attendance): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#markAttendanceModal">
                        <i class="bi bi-check-circle"></i> Mark Attendance
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success" onclick="exportAttendance()">
                        <i class="bi bi-download"></i> Export
                    </button>
                    <?php if ($can_edit_attendance): ?>
                    <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#bulkAttendanceModal">
                        <i class="bi bi-upload"></i> Bulk Update
                    </button>
                    <?php endif; ?>
                    <a href="attendance_reports.php" class="btn btn-warning">
                        <i class="bi bi-graph-up"></i> Reports
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Date Navigation & Filter Card -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-calendar"></i> Attendance Date & Filters</h6>
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <div class="collapse show" id="filterCollapse">
            <div class="card-body">
                <form id="attendanceFilterForm" method="GET">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Select Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="date" name="date" value="<?= $selected_date ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Month View</label>
                            <input type="month" class="form-control" id="month" name="month" value="<?= $selected_month ?>">
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
                            <label class="form-label">Attendance Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Status</option>
                                <option value="present" <?= ($selected_status == 'present') ? 'selected' : '' ?>>Present</option>
                                <option value="absent" <?= ($selected_status == 'absent') ? 'selected' : '' ?>>Absent</option>
                                <option value="late" <?= ($selected_status == 'late') ? 'selected' : '' ?>>Late</option>
                                <option value="half_day" <?= ($selected_status == 'half_day') ? 'selected' : '' ?>>Half Day</option>
                                <option value="leave" <?= ($selected_status == 'leave') ? 'selected' : '' ?>>Leave</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Search Employee</label>
                            <input type="text" class="form-control" id="searchEmployee" name="search" placeholder="Search by employee name, ID...">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="btn-group w-100">
                                <button type="button" class="btn btn-outline-secondary" onclick="changeDate(-1)">
                                    <i class="bi bi-chevron-left"></i> Previous
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="goToToday()">
                                    <i class="bi bi-calendar-check"></i> Today
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="changeDate(1)">
                                    Next <i class="bi bi-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 text-end">
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

    <!-- Attendance Summary -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Attendance Summary - <?= date('l, F j, Y', strtotime($selected_date)) ?></h5>
                    <span class="badge bg-light text-dark">
                        <?= $attendance_summary['total_employees'] ?> employees
                    </span>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                            <div class="card custom-stat-card text-white"><div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h4 class="mb-0"><?= $attendance_summary['present'] ?></h4>
                                            <p class="mb-0">Present</p>
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
                                            <h4 class="mb-0"><?= $attendance_summary['absent'] ?></h4>
                                            <p class="mb-0">Absent</p>
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
                                            <h4 class="mb-0"><?= $attendance_summary['late'] ?></h4>
                                            <p class="mb-0">Late</p>
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
                                            <h4 class="mb-0"><?= $attendance_summary['half_day'] ?></h4>
                                            <p class="mb-0">Half Day</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-hourglass-split" style="font-size: 1.5rem;"></i>
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
                                            <h4 class="mb-0"><?= $attendance_summary['leave'] ?></h4>
                                            <p class="mb-0">On Leave</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-calendar" style="font-size: 1.5rem;"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-sm-6 mb-3">
                            <div class="card bg-dark text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h4 class="mb-0"><?= $attendance_summary['weekend'] ?></h4>
                                            <p class="mb-0">Weekend</p>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-umbrella" style="font-size: 1.5rem;"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Attendance Progress Bar -->
                    <div class="mt-3">
                        <h6>Attendance Distribution</h6>
                        <div class="progress mb-2" style="height: 25px;">
                            <?php
                            $total = $attendance_summary['total_employees'];
                            if ($total > 0):
                                $present_percent = ($attendance_summary['present'] / $total) * 100;
                                $absent_percent = ($attendance_summary['absent'] / $total) * 100;
                                $late_percent = ($attendance_summary['late'] / $total) * 100;
                                $half_day_percent = ($attendance_summary['half_day'] / $total) * 100;
                                $leave_percent = ($attendance_summary['leave'] / $total) * 100;
                                $weekend_percent = ($attendance_summary['weekend'] / $total) * 100;
                            ?>
                            <div class="progress-bar bg-success" style="width: <?= $present_percent ?>%">
                                Present (<?= round($present_percent) ?>%)
                            </div>
                            <div class="progress-bar bg-danger" style="width: <?= $absent_percent ?>%">
                                Absent (<?= round($absent_percent) ?>%)
                            </div>
                            <div class="progress-bar bg-warning" style="width: <?= $late_percent ?>%">
                                Late (<?= round($late_percent) ?>%)
                            </div>
                            <div class="progress-bar bg-info" style="width: <?= $half_day_percent ?>%">
                                Half Day (<?= round($half_day_percent) ?>%)
                            </div>
                            <div class="progress-bar bg-secondary" style="width: <?= $leave_percent ?>%">
                                Leave (<?= round($leave_percent) ?>%)
                            </div>
                            <div class="progress-bar bg-dark" style="width: <?= $weekend_percent ?>%">
                                Weekend (<?= round($weekend_percent) ?>%)
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance List -->
    <div class="card">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Daily Attendance - <?= date('D, M j, Y', strtotime($selected_date)) ?></h5>
            <div class="d-flex">
                <?php if ($can_edit_attendance): ?>
                <button type="button" class="btn btn-light btn-sm me-2" onclick="selectAllAttendance()">
                    <i class="bi bi-check-all"></i> Select All
                </button>
                <?php endif; ?>
                <span class="badge bg-light text-dark">
                    Showing <?= count($attendance_data) ?> employees
                </span>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($attendance_data) > 0): ?>
                <div class="table-responsive">
                    <table id="attendanceTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <?php if ($can_edit_attendance): ?>
                                <th width="30">
                                    <input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)">
                                </th>
                                <?php endif; ?>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Check In</th>
                                <th>Check Out</th>
                                <th>Total Hours</th>
                                <th>Status</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_data as $record): 
                                // Apply status filter if selected
                                if ($selected_status && $record['status'] != $selected_status) {
                                    continue;
                                }
                            ?>
                            <tr>
                                <?php if ($can_edit_attendance): ?>
                                <td>
                                    <input type="checkbox" class="attendance-checkbox" value="<?= $record['employee_id'] ?>">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div>
                                        <strong><?= safe_output($record['first_name'] . ' ' . $record['last_name']) ?></strong><br>
                                        <small class="text-muted"><?= safe_output($record['employee_number']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <?= safe_output($record['department_name']) ?>
                                </td>
                                <td>
                                    <div class="time-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="time" class="form-control form-control-sm check-in-time" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= $record['check_in_time'] ? date('H:i', strtotime($record['check_in_time'])) : '' ?>"
                                               onchange="updateAttendanceTime(<?= $record['employee_id'] ?>, 'check_in', this.value)">
                                        <?php else: ?>
                                        <span class="time-display"><?= format_time($record['check_in_time']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="time-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="time" class="form-control form-control-sm check-out-time" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= $record['check_out_time'] ? date('H:i', strtotime($record['check_out_time'])) : '' ?>"
                                               onchange="updateAttendanceTime(<?= $record['employee_id'] ?>, 'check_out', this.value)">
                                        <?php else: ?>
                                        <span class="time-display"><?= format_time($record['check_out_time']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-center">
                                        <strong><?= $record['total_hours'] ?></strong> hrs
                                    </div>
                                </td>
                                <td>
                                    <div class="status-select">
                                        <?php if ($can_edit_attendance): ?>
                                        <select class="form-select form-select-sm attendance-status" 
                                                data-employee-id="<?= $record['employee_id'] ?>"
                                                onchange="updateAttendanceStatus(<?= $record['employee_id'] ?>, this.value)">
                                            <option value="present" <?= $record['status'] == 'present' ? 'selected' : '' ?>>Present</option>
                                            <option value="absent" <?= $record['status'] == 'absent' ? 'selected' : '' ?>>Absent</option>
                                            <option value="late" <?= $record['status'] == 'late' ? 'selected' : '' ?>>Late</option>
                                            <option value="half_day" <?= $record['status'] == 'half_day' ? 'selected' : '' ?>>Half Day</option>
                                            <option value="leave" <?= $record['status'] == 'leave' ? 'selected' : '' ?>>Leave</option>
                                            <option value="holiday" <?= $record['status'] == 'holiday' ? 'selected' : '' ?>>Holiday</option>
                                            <option value="weekend" <?= $record['status'] == 'weekend' ? 'selected' : '' ?>>Weekend</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="badge bg-<?= get_attendance_badge($record['status']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $record['status'])) ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="notes-input">
                                        <?php if ($can_edit_attendance): ?>
                                        <input type="text" class="form-control form-control-sm attendance-notes" 
                                               data-employee-id="<?= $record['employee_id'] ?>"
                                               value="<?= safe_output($record['notes']) ?>"
                                               placeholder="Add notes"
                                               onchange="updateAttendanceNotes(<?= $record['employee_id'] ?>, this.value)">
                                        <?php else: ?>
                                        <small><?= safe_output($record['notes']) ?></small>
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
                                                <a class="dropdown-item" href="employee_attendance.php?id=<?= $record['employee_id'] ?>">
                                                    <i class="bi bi-clock-history"></i> View History
                                                </a>
                                            </li>
                                            <?php if ($can_edit_attendance): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkPresent(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-check-circle"></i> Mark Present
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkAbsent(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-x-circle"></i> Mark Absent
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="quickMarkLate(<?= $record['employee_id'] ?>)">
                                                    <i class="bi bi-clock"></i> Mark Late
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="viewAttendanceDetails(<?= $record['employee_id'] ?>, '<?= $selected_date ?>')">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_attendance && $record['existing_record']): ?>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="deleteAttendanceRecord(<?= $record['employee_id'] ?>, '<?= $selected_date ?>')">
                                                    <i class="bi bi-trash"></i> Delete Record
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
                <?php if ($can_edit_attendance): ?>
                <div class="mt-3 p-3 bg-light rounded">
                    <div class="row align-items-center">
                        <div class="col-md-4 mb-2">
                            <small><span id="selectedCount">0</span> employees selected</small>
                        </div>
                        <div class="col-md-8 text-end">
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkMarkStatus('present')">
                                    <i class="bi bi-check-circle"></i> Mark Present
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkMarkStatus('absent')">
                                    <i class="bi bi-x-circle"></i> Mark Absent
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="bulkMarkStatus('late')">
                                    <i class="bi bi-clock"></i> Mark Late
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="bulkMarkStatus('half_day')">
                                    <i class="bi bi-hourglass-split"></i> Mark Half Day
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="bulkMarkStatus('leave')">
                                    <i class="bi bi-calendar"></i> Mark Leave
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Daily Summary -->
                <div class="mt-4">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="mb-0"><i class="bi bi-pie-chart"></i> Daily Attendance Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Attendance Statistics</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Total Employees
                                            <span class="badge bg-primary"><?= $attendance_summary['total_employees'] ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Present Rate
                                            <span class="badge bg-success"><?= $attendance_summary['total_employees'] > 0 ? round(($attendance_summary['present'] / $attendance_summary['total_employees']) * 100, 1) : 0 ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Absence Rate
                                            <span class="badge bg-danger"><?= $attendance_summary['total_employees'] > 0 ? round(($attendance_summary['absent'] / $attendance_summary['total_employees']) * 100, 1) : 0 ?>%</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Late Arrivals
                                            <span class="badge bg-warning"><?= $attendance_summary['late'] ?></span>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Working Hours Summary</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Standard Hours
                                            <span class="badge bg-info">8.00 hrs</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Average Hours Today
                                            <span class="badge bg-info"><?= calculate_average_hours($attendance_data) ?> hrs</span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Early Departures
                                            <span class="badge bg-warning"><?= count_early_departures($attendance_data) ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            Overtime Today
                                            <span class="badge bg-success"><?= calculate_overtime($attendance_data) ?> hrs</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Employees Found</h4>
                    <p class="text-muted">No active employees found for the selected filters.</p>
                    <button type="button" class="btn btn-primary mt-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear Filters
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mark Attendance Modal -->
<?php if ($can_edit_attendance): ?>
<div class="modal fade" id="markAttendanceModal" tabindex="-1" aria-labelledby="markAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="markAttendanceModalLabel">
                    <i class="bi bi-check-circle"></i> Mark Attendance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="markAttendanceForm">
                <div class="modal-body">
                    <div id="mark-attendance-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="mark_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="mark_date" name="attendance_date" value="<?= $selected_date ?>" max="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_employee" class="form-label">Employee <span class="text-danger">*</span></label>
                            <select class="form-select" id="mark_employee" name="employee_id" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $employee): ?>
                                <option value="<?= $employee['employee_id'] ?>">
                                    <?= safe_output($employee['first_name'] . ' ' . $employee['last_name']) ?> (<?= safe_output($employee['employee_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_check_in" class="form-label">Check In Time</label>
                            <input type="time" class="form-control" id="mark_check_in" name="check_in_time">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_check_out" class="form-label">Check Out Time</label>
                            <input type="time" class="form-control" id="mark_check_out" name="check_out_time">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="mark_status" name="status" required>
                                <option value="present" selected>Present</option>
                                <option value="absent">Absent</option>
                                <option value="late">Late</option>
                                <option value="half_day">Half Day</option>
                                <option value="leave">Leave</option>
                                <option value="holiday">Holiday</option>
                                <option value="weekend">Weekend</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="mark_total_hours" class="form-label">Total Hours</label>
                            <input type="number" class="form-control" id="mark_total_hours" name="total_hours" step="0.01" placeholder="0.00">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="mark_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="mark_notes" name="notes" rows="3" placeholder="Any notes about attendance"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Attendance Modal -->
<?php if ($can_edit_attendance): ?>
<div class="modal fade" id="bulkAttendanceModal" tabindex="-1" aria-labelledby="bulkAttendanceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="bulkAttendanceModalLabel">
                    <i class="bi bi-upload"></i> Bulk Attendance Update
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="bulkAttendanceForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div id="bulk-attendance-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the attendance data</li>
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
                            <option value="add_new">Add New Records Only</option>
                            <option value="update_existing">Update Existing Records</option>
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
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadAttendanceTemplate()">
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
    let attendanceTable = $('#attendanceTable').DataTable({
        language: {
            search: "Search attendance:",
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
                title: 'Attendance_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'pdfHtml5',
                text: '<i class="bi bi-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-outline-danger',
                titleAttr: 'Export to PDF',
                title: 'Attendance_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'print',
                text: '<i class="bi bi-printer"></i> Print',
                className: 'btn btn-sm btn-outline-info',
                titleAttr: 'Print table'
            }
        ],
        pageLength: 25,
        order: [[1, 'asc']],
        footerCallback: function(row, data, start, end, display) {
            // Update selected count
            updateSelectedCount();
        }
    });

    // Mark attendance form submission
    $('#markAttendanceForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/mark_attendance.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#mark-attendance-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#mark-attendance-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
                }
            },
            error: function(xhr, status, error) {
                $('#mark-attendance-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
                console.error('Error:', error);
            }
        });
    });

    // Bulk attendance form submission
    $('#bulkAttendanceForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...');

        $.ajax({
            url: 'api/import_attendance.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#bulk-attendance-message').html('<div class="alert alert-success">' + response.message + '</div>');
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
                        $('#bulk-attendance-message').append(resultsHtml);
                    }
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    $('#bulk-attendance-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
                }
            },
            error: function(xhr, status, error) {
                $('#bulk-attendance-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
                console.error('Error:', error);
            }
        });
    });

    // Checkbox selection handlers
    $('.attendance-checkbox').on('change', function() {
        updateSelectedCount();
    });

    // Reset forms when modals are closed
    $('#markAttendanceModal').on('hidden.bs.modal', function() {
        $('#markAttendanceForm')[0].reset();
        $('#mark-attendance-message').html('');
        $('#markAttendanceForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Attendance');
    });
    
    $('#bulkAttendanceModal').on('hidden.bs.modal', function() {
        $('#bulkAttendanceForm')[0].reset();
        $('#bulk-attendance-message').html('');
        $('#bulkAttendanceForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-upload"></i> Upload & Process');
    });
});

function changeDate(days) {
    const currentDate = new Date('<?= $selected_date ?>');
    currentDate.setDate(currentDate.getDate() + days);
    
    const newDate = currentDate.toISOString().split('T')[0];
    window.location.href = `attendance.php?date=${newDate}&department=<?= $selected_department ?>&status=<?= $selected_status ?>`;
}

function goToToday() {
    window.location.href = `attendance.php?date=<?= date('Y-m-d') ?>&department=<?= $selected_department ?>&status=<?= $selected_status ?>`;
}

function clearFilters() {
    window.location.href = 'attendance.php';
}

function exportAttendance() {
    // Trigger DataTable export
    $('#attendanceTable').DataTable().button('.buttons-excel').trigger();
}

function getSelectedEmployeeIds() {
    const selectedIds = [];
    $('.attendance-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });
    return selectedIds;
}

function updateSelectedCount() {
    const selectedCount = $('.attendance-checkbox:checked').length;
    $('#selectedCount').text(selectedCount);
}

function toggleSelectAll(checkbox) {
    $('.attendance-checkbox').prop('checked', checkbox.checked);
    updateSelectedCount();
}

function selectAllAttendance() {
    $('#selectAll').prop('checked', true);
    $('.attendance-checkbox').prop('checked', true);
    updateSelectedCount();
}

function bulkMarkStatus(status) {
    const selectedIds = getSelectedEmployeeIds();
    if (selectedIds.length === 0) {
        alert('Please select at least one employee.');
        return;
    }
    
    const statusName = status.replace('_', ' ');
    if (!confirm(`Are you sure you want to mark ${selectedIds.length} employee(s) as ${statusName}?`)) {
        return;
    }

    $.ajax({
        url: 'api/bulk_mark_attendance.php',
        type: 'POST',
        data: { 
            employee_ids: selectedIds,
            attendance_date: '<?= $selected_date ?>',
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error marking attendance: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error marking attendance. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateAttendanceTime(employeeId, field, value) {
    $.ajax({
        url: 'api/update_attendance_time.php',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            field: field,
            value: value
        },
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                alert('Error updating time: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating time. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateAttendanceStatus(employeeId, status) {
    $.ajax({
        url: 'api/update_attendance_status.php',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                alert('Error updating status: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating status. Please try again.');
            console.error('Error:', error);
        }
    });
}

function updateAttendanceNotes(employeeId, notes) {
    $.ajax({
        url: 'api/update_attendance_notes.php',
        type: 'POST',
        data: { 
            employee_id: employeeId,
            attendance_date: '<?= $selected_date ?>',
            notes: notes
        },
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                alert('Error updating notes: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            alert('Error updating notes. Please try again.');
            console.error('Error:', error);
        }
    });
}

function quickMarkPresent(employeeId) {
    if (confirm('Mark this employee as present for today?')) {
        $.ajax({
            url: 'api/quick_mark_attendance.php',
            type: 'POST',
            data: { 
                employee_id: employeeId,
                attendance_date: '<?= $selected_date ?>',
                status: 'present',
                check_in_time: '09:00',
                check_out_time: '17:00'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error marking attendance: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error marking attendance. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function quickMarkAbsent(employeeId) {
    if (confirm('Mark this employee as absent for today?')) {
        $.ajax({
            url: 'api/quick_mark_attendance.php',
            type: 'POST',
            data: { 
                employee_id: employeeId,
                attendance_date: '<?= $selected_date ?>',
                status: 'absent'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error marking attendance: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error marking attendance. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function quickMarkLate(employeeId) {
    if (confirm('Mark this employee as late for today?')) {
        $.ajax({
            url: 'api/quick_mark_attendance.php',
            type: 'POST',
            data: { 
                employee_id: employeeId,
                attendance_date: '<?= $selected_date ?>',
                status: 'late',
                check_in_time: '10:00',
                check_out_time: '18:00'
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error marking attendance: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error marking attendance. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function viewAttendanceDetails(employeeId, date) {
    window.open(`attendance_details.php?employee_id=${employeeId}&date=${date}`, '_blank');
}

function deleteAttendanceRecord(employeeId, date) {
    if (confirm('Are you sure you want to delete this attendance record?')) {
        $.ajax({
            url: 'api/delete_attendance.php',
            type: 'POST',
            data: { 
                employee_id: employeeId,
                attendance_date: date
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('Error deleting record: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                alert('Error deleting record. Please try again.');
                console.error('Error:', error);
            }
        });
    }
}

function downloadAttendanceTemplate() {
    // Create a CSV template file
    const headers = [
        'employee_id', 'attendance_date', 'check_in_time', 'check_out_time', 
        'status', 'notes'
    ];
    
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\n1,2023-10-01,09:00,17:00,present,On time\n2,2023-10-01,,,absent,Sick leave";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "attendance_import_template.csv");
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
.card.bg-danger,
.card.bg-warning,
.card.bg-info,
.card.bg-secondary,
.card.bg-dark {
    border: none;
}

.card.bg-primary { background: linear-gradient(45deg, #0d6efd, #0b5ed7); }
.card.bg-success { background: linear-gradient(45deg, #198754, #157347); }
.card.bg-danger { background: linear-gradient(45deg, #dc3545, #bb2d3b); }
.card.bg-warning { background: linear-gradient(45deg, #ffc107, #e0a800); }
.card.bg-info { background: linear-gradient(45deg, #0dcaf0, #0aa2c0); }
.card.bg-secondary { background: linear-gradient(45deg, #6c757d, #5a6268); }
.card.bg-dark { background: linear-gradient(45deg, #212529, #343a40); }

/* Progress bar customization */
.progress-bar {
    font-size: 0.75rem;
    font-weight: 600;
}

/* Checkbox styling */
.attendance-checkbox {
    cursor: pointer;
}

/* Time input styling */
.time-input input {
    min-width: 80px;
}

.time-display {
    font-family: monospace;
    font-weight: 600;
}

/* Status select styling */
.status-select select {
    min-width: 100px;
}

/* Notes input styling */
.notes-input input {
    min-width: 150px;
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
    
    .time-input input,
    .status-select select,
    .notes-input input {
        width: 100%;
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
// Helper functions for summary calculations
function calculate_average_hours($attendance_data) {
    $total_hours = 0;
    $count = 0;
    
    foreach ($attendance_data as $record) {
        if ($record['status'] == 'present' || $record['status'] == 'late' || $record['status'] == 'half_day') {
            $total_hours += (float)$record['total_hours'];
            $count++;
        }
    }
    
    return $count > 0 ? number_format($total_hours / $count, 2) : '0.00';
}

function count_early_departures($attendance_data) {
    $count = 0;
    
    foreach ($attendance_data as $record) {
        if (!empty($record['check_out_time'])) {
            $check_out = strtotime($record['check_out_time']);
            $standard_check_out = strtotime('17:00:00');
            
            if ($check_out < $standard_check_out && $record['status'] != 'half_day') {
                $count++;
            }
        }
    }
    
    return $count;
}

function calculate_overtime($attendance_data) {
    $total_overtime = 0;
    $standard_hours = 8;
    
    foreach ($attendance_data as $record) {
        if ($record['status'] == 'present' || $record['status'] == 'late') {
            $hours = (float)$record['total_hours'];
            if ($hours > $standard_hours) {
                $total_overtime += ($hours - $standard_hours);
            }
        }
    }
    
    return number_format($total_overtime, 2);
}

// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>