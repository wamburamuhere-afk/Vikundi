<?php
/**
 * AJAX handler for batch member import — header-named template.
 * Path: ajax/process_member_import.php
 *
 * Reads our simple members template: columns identified by HEADER NAME, so order
 * doesn't matter and a missing/extra column won't corrupt the row. Each member
 * gets an auto username + password username@123 (admin-created). Rich family /
 * guarantor / photo data is added later via the edit screens.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/member_import.php';

$response = ['status' => 'error', 'message' => ''];
$lang = $_SESSION['preferred_language'] ?? 'en';
$sw = ($lang === 'sw');

if (!isset($_SESSION['user_id']) || !canCreate('customers')) {
    $response['message'] = $sw ? 'Huna ruhusa ya kufanya hivi.' : 'You do not have permission to perform this action.';
    echo json_encode($response);
    exit;
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $response['message'] = $sw
        ? 'Kipindi chako kimeisha au ombi si salama. Onyesha upya ukurasa kisha ujaribu tena.'
        : 'Your session has expired or the request was not secure. Please refresh the page and try again.';
    echo json_encode($response);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['import_file'])) {
    $response['message'] = $sw ? 'Ombi si sahihi.' : 'Invalid request.';
    echo json_encode($response);
    exit;
}

$file = $_FILES['import_file'];
if ($file['error'] !== 0) {
    $response['message'] = $sw ? 'Hitilafu wakati wa kupandisha faili.' : 'Error uploading file.';
    echo json_encode($response);
    exit;
}
$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    $response['message'] = $sw ? 'Imeshindwa kufungua faili.' : 'Failed to open file.';
    echo json_encode($response);
    exit;
}

// Auto-detect delimiter from the first line.
$firstLine = fgets($handle);
$delimiter = ($firstLine !== false && substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
rewind($handle);
// Skip an Excel 'sep=' hint line if present.
$peek = fgets($handle);
if (strpos((string) $peek, 'sep=') === false) {
    rewind($handle);
}

// Header row → lowercased, trimmed, BOM-stripped names.
$headers = fgetcsv($handle, 3000, $delimiter);
if ($headers === false) {
    fclose($handle);
    $response['message'] = $sw ? 'Faili halina vichwa vya safu.' : 'The file has no header row.';
    echo json_encode($response);
    exit;
}
$headers = array_map(function ($h) {
    return strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h)));
}, $headers);

require_once __DIR__ . '/../includes/activity_logger.php';

$imported = 0;
$errors = [];
$rowCount = 1; // header was row 1
$seen = [];    // intra-file phone de-dupe

try {
    $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) LIKE '%member%' OR LOWER(role_name) LIKE '%mwanachama%' LIMIT 1");
    $roleStmt->execute();
    $member_role_id = $roleStmt->fetchColumn() ?: null;

    $uCheck  = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $uInsert = $pdo->prepare("INSERT INTO users (username, email, password, first_name, middle_name, last_name, phone, user_role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Member', ?, 'pending', NOW())");
    $cInsert = $pdo->prepare("INSERT INTO customers (
        first_name, middle_name, last_name, customer_name, email, phone, gender, nida_number, religion, birth_region,
        marital_status, country, state, district, ward, street, house_number,
        status, initial_savings, user_id, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, NOW())");
    $contribInsert = $pdo->prepare("INSERT INTO contributions (member_id, amount, contribution_date, description, status, created_at) VALUES (?, ?, CURRENT_DATE, 'Initial savings', 'pending', NOW())");

    $pdo->beginTransaction();

    while (($data = fgetcsv($handle, 3000, $delimiter)) !== false) {
        $rowCount++;
        if (count(array_filter($data, fn($v) => trim((string) $v) !== '')) === 0) continue; // blank line

        $assoc = [];
        foreach ($headers as $i => $key) {
            if ($key !== '') $assoc[$key] = $data[$i] ?? '';
        }

        $row = member_import_parse_row($assoc);
        if (is_string($row)) { $errors[] = "Row #$rowCount: $row."; continue; }

        if (isset($seen[$row['phone']])) {
            $errors[] = "Row #$rowCount: duplicate phone {$row['phone']} in file.";
            continue;
        }
        $seen[$row['phone']] = true;

        // Username + auto password (username@123).
        $username = strtolower(substr($row['first_name'], 0, 1) . preg_replace('/\s+/', '', $row['last_name']));
        $uCheck->execute([$username]);
        if ((int) $uCheck->fetchColumn() > 0) $username .= mt_rand(10, 99);
        $hashed = password_hash($username . '@123', PASSWORD_BCRYPT);

        $uInsert->execute([
            $username, $row['email'] ?: null, $hashed,
            $row['first_name'], $row['middle_name'], $row['last_name'], $row['phone'], $member_role_id,
        ]);
        $user_id = $pdo->lastInsertId();

        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $cInsert->execute([
            $row['first_name'], $row['middle_name'], $row['last_name'], $full_name, $row['email'] ?: null, $row['phone'],
            $row['gender'] ?: null, $row['nida'] ?: null, $row['religion'] ?: null, $row['birth_region'] ?: null,
            $row['marital_status'] ?: null, $row['country'], $row['region'] ?: null, $row['district'] ?: null,
            $row['ward'] ?: null, $row['street'] ?: null, $row['house_number'] ?: null,
            $row['initial_savings'], $user_id,
        ]);
        $cust_id = $pdo->lastInsertId();

        if ($row['initial_savings'] > 0) {
            $contribInsert->execute([$cust_id, $row['initial_savings']]);
        }

        logCreate('Members (Batch Import)', $full_name, "USER#$user_id", $_SESSION['user_id']);
        $imported++;
    }

    $pdo->commit();
    $msg = $sw ? "Wameingizwa wanachama $imported." : "Imported $imported member(s).";
    if ($errors) $msg .= ' ' . (count($errors) . ($sw ? ' safu zimerukwa.' : ' row(s) skipped.'));
    $response = ['status' => 'success', 'message' => $msg, 'errors' => array_slice($errors, 0, 12)];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Batch member import failed: ' . $e->getMessage());
    $response['message'] = $sw
        ? 'Hitilafu ya mfumo wakati wa kuhifadhi. Hakuna mwanachama aliyeingizwa.'
        : 'A system error occurred while saving. No members were imported.';
}

fclose($handle);
echo json_encode($response);
