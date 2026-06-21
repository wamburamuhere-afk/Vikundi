<?php
/**
 * AJAX Handler for Batch Member Import (SMART AUTO-DETECT VERSION)
 * Path: ajax/process_member_import.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/registration_validator.php';

/**
 * Helper to clean numeric values that might be in scientific notation (Excel)
 */
function cleanNumericImport($val) {
    if (empty($val)) return '';
    $val = trim((string)$val);
    // Handle scientific notation (e.g. 1.23E+10)
    if (stripos($val, 'E+') !== false || stripos($val, 'E-') !== false) {
        $val = number_format((float)$val, 0, '.', '');
    }
    return preg_replace('/[^0-9]/', '', $val); // Keep only digits
}

/**
 * Helper to parse various date formats from CSV into Y-m-d
 */
function parseImportDate($val) {
    if (empty($val)) return null;
    $val = trim((string)$val);
    
    // Check if it's already Y-m-d
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    
    // Try common CSV formats
    $formats = ['d/m/Y', 'm/d/Y', 'd.m.Y', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $f) {
        $d = DateTime::createFromFormat($f, $val);
        if ($d && $d->format($f) === $val) {
            return $d->format('Y-m-d');
        }
    }
    
    // Fallback to strtotime but replace / with - for ambiguity
    $normalized = str_replace(['/', '.'], '-', $val);
    $ts = strtotime($normalized);
    return $ts ? date('Y-m-d', $ts) : null;
}


$response = ['status' => 'error', 'message' => ''];
$lang = $_SESSION['preferred_language'] ?? 'en';

if (!isset($_SESSION['user_id']) || !canCreate('customers')) {
    $response['message'] = ($lang === 'sw') ? 'Huna ruhusa ya kufanya hivi.' : 'You do not have permission to perform this action.';
    echo json_encode($response);
    exit;
}

// CSRF protection (same as the rest of the registration flow)
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $response['message'] = ($lang === 'sw')
        ? 'Kipindi chako kimeisha au ombi si salama. Tafadhali onyesha upya ukurasa kisha ujaribu tena.'
        : 'Your session has expired or the request was not secure. Please refresh the page and try again.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    if ($file['error'] !== 0) {
        $response['message'] = ($lang === 'sw') ? 'Hitilafu wakati wa kupandisha faili.' : 'Error uploading file.';
        echo json_encode($response);
        exit;
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $response['message'] = ($lang === 'sw') ? 'Imeshindwa kufungua faili.' : 'Failed to open file.';
        echo json_encode($response);
        exit;
    }

    // --- AUTO-DETECT DELIMITER ---
    $firstLine = fgets($handle);
    $delimiter = ","; // default
    if ($firstLine !== false) {
        $commaCount = substr_count($firstLine, ",");
        $semiCount = substr_count($firstLine, ";");
        if ($semiCount > $commaCount) {
            $delimiter = ";";
        }
    }
    rewind($handle);

    // Skip potential Excel 'sep=...' line
    $line1 = fgets($handle);
    if (strpos($line1, 'sep=') !== false) {
        // Line 1 is just the separator hint, header is line 2
    } else {
        rewind($handle);
    }
    // Skip the header row
    fgetcsv($handle, 3000, $delimiter);

    $rowCount = 0;
    $errors = [];
    $allRows = [];
    $seenPhones = [];   // intra-file duplicate guards
    $seenEmails = [];

    while (($data = fgetcsv($handle, 3000, $delimiter)) !== FALSE) {
        $rowCount++;
        
        // Skip empty rows
        if (empty(array_filter($data))) continue;

        // Ensure we have enough columns (Expected 41-42)
        if (count($data) < 41) {
            $errors[] = ($lang === 'sw') ? "Row #$rowCount: Column hazitoshelezi (Zinapaswa kuwa 41, zimeonekana ".count($data).")." : "Row #$rowCount: Insufficient columns (Expected 41, found ".count($data).").";
            continue;
        }

        $row = [
            'first_name' => trim($data[1] ?? ''),
            'middle_name' => trim($data[2] ?? ''),
            'last_name' => trim($data[3] ?? ''),
            'email' => trim($data[4] ?? ''),
            'phone' => cleanNumericImport($data[5] ?? ''),
            'gender' => trim($data[6] ?? ''),
            'dob' => parseImportDate($data[7] ?? ''),
            'nida' => cleanNumericImport($data[8] ?? ''),
            'religion' => trim($data[9] ?? ''),
            'birth_region' => trim($data[10] ?? ''),
            'marital_status' => trim($data[11] ?? ''),
            'initial_savings' => trim($data[12] ?? 0),
            'country' => trim($data[13] ?? 'Tanzania'),
            'region' => trim($data[14] ?? ''),
            'district' => trim($data[15] ?? ''),
            'ward' => trim($data[16] ?? ''),
            'street' => trim($data[17] ?? ''),
            'house_no' => trim($data[18] ?? ''),
            'father_name' => trim($data[19] ?? ''),
            'father_loc' => trim($data[20] ?? ''),
            'father_street' => trim($data[21] ?? ''),
            'father_phone' => cleanNumericImport($data[22] ?? ''),
            'mother_name' => trim($data[23] ?? ''),
            'mother_loc' => trim($data[24] ?? ''),
            'mother_street' => trim($data[25] ?? ''),
            'mother_phone' => cleanNumericImport($data[26] ?? ''),
            'spouse_first' => trim($data[27] ?? ''),
            'spouse_middle' => trim($data[28] ?? ''),
            'spouse_last' => trim($data[29] ?? ''),
            'spouse_email' => trim($data[30] ?? ''),
            'spouse_phone' => cleanNumericImport($data[31] ?? ''),
            'spouse_gender' => trim($data[32] ?? ''),
            'spouse_dob' => parseImportDate($data[33] ?? ''),
            'spouse_nida' => cleanNumericImport($data[34] ?? ''),
            'spouse_religion' => trim($data[35] ?? ''),
            'spouse_birth_reg' => trim($data[36] ?? ''),
            'children_raw' => trim($data[37] ?? ''),
            'guarantor_name' => trim($data[38] ?? ''),
            'guarantor_phone' => cleanNumericImport($data[39] ?? ''),
            'guarantor_rel' => trim($data[40] ?? ''),
            'guarantor_loc' => trim($data[41] ?? '')
        ];


        if (empty($row['first_name']) || empty($row['middle_name']) || empty($row['last_name']) || empty($row['phone'])) {
            $errors[] = ($lang === 'sw') ? "Row #$rowCount: Majina Matatu na Simu ni lazima." : "Row #$rowCount: Three names and Phone are mandatory.";
            continue;
        }

        // ---- Same FORMAT rules as the interactive forms (shared helpers) ----
        $rowErr = [];
        if (!reg_valid_name($row['first_name'])) $rowErr[] = ($lang === 'sw') ? 'jina la kwanza si sahihi' : 'first name is invalid';
        if (!reg_valid_name($row['last_name']))  $rowErr[] = ($lang === 'sw') ? 'jina la mwisho si sahihi' : 'last name is invalid';
        if (!reg_valid_phone($row['phone']))     $rowErr[] = ($lang === 'sw') ? 'namba ya simu si sahihi' : 'phone is invalid';
        if ($row['email'] !== '' && !reg_valid_email($row['email']))               $rowErr[] = ($lang === 'sw') ? 'barua pepe si sahihi' : 'email is invalid';
        if ($row['nida'] !== '' && !reg_valid_nida($row['nida']))                  $rowErr[] = ($lang === 'sw') ? 'NIDA lazima iwe tarakimu 20' : 'NIDA must be 20 digits';
        if ($row['spouse_email'] !== '' && !reg_valid_email($row['spouse_email'])) $rowErr[] = ($lang === 'sw') ? 'barua pepe ya mwenzi si sahihi' : 'spouse email is invalid';
        if ($row['spouse_nida'] !== '' && !reg_valid_nida($row['spouse_nida']))    $rowErr[] = ($lang === 'sw') ? 'NIDA ya mwenzi lazima iwe tarakimu 20' : 'spouse NIDA must be 20 digits';
        foreach (['father_phone', 'mother_phone', 'spouse_phone', 'guarantor_phone'] as $pk) {
            if ($row[$pk] !== '' && !reg_valid_phone($row[$pk])) {
                $rowErr[] = ($lang === 'sw') ? "namba ya $pk si sahihi" : "$pk is invalid";
            }
        }
        if ($row['children_raw'] !== '') {
            foreach (explode(',', $row['children_raw']) as $kid) {
                $parts = explode('-', trim($kid));
                $age = trim($parts[1] ?? '');
                if ($age !== '' && (!ctype_digit($age) || (int)$age > 120)) {
                    $rowErr[] = ($lang === 'sw') ? 'umri wa mtoto si sahihi (0-120)' : 'a child age is invalid (0-120)';
                    break;
                }
            }
        }
        if (!empty($rowErr)) {
            $errors[] = "Row #$rowCount: " . implode('; ', $rowErr) . '.';
            continue;
        }

        // ---- Canonicalise email & phone (consistent duplicate detection & storage) ----
        $row['email'] = ($row['email'] !== '') ? strtolower($row['email']) : '';
        $row['phone'] = reg_normalize_phone($row['phone']);

        // ---- Duplicate within THIS file ----
        if (isset($seenPhones[$row['phone']])) {
            $errors[] = ($lang === 'sw') ? "Row #$rowCount: Simu (".$row['phone'].") imejirudia ndani ya faili." : "Row #$rowCount: Phone (".$row['phone'].") is duplicated within the file.";
            continue;
        }
        if ($row['email'] !== '' && isset($seenEmails[$row['email']])) {
            $errors[] = ($lang === 'sw') ? "Row #$rowCount: Barua pepe (".$row['email'].") imejirudia ndani ya faili." : "Row #$rowCount: Email (".$row['email'].") is duplicated within the file.";
            continue;
        }

        // ---- Duplicate Phone Check (DB, normalized) ----
        $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE phone = ?");
        $checkStmt->execute([$row['phone']]);
        if ($checkStmt->fetch()) {
            $errors[] = ($lang === 'sw') ? "Row #$rowCount: Simu ya (".$row['phone'].") tayari ipo." : "Row #$rowCount: Phone (".$row['phone'].") already exists.";
            continue;
        }

        // ---- Duplicate Email Check (DB) ----
        if ($row['email'] !== '') {
            $emailStmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $emailStmt->execute([$row['email']]);
            if ($emailStmt->fetch()) {
                $errors[] = ($lang === 'sw') ? "Row #$rowCount: Barua pepe (".$row['email'].") tayari ipo." : "Row #$rowCount: Email (".$row['email'].") already exists.";
                continue;
            }
        }

        $seenPhones[$row['phone']] = true;
        if ($row['email'] !== '') $seenEmails[$row['email']] = true;
        $allRows[] = $row;
    }
    fclose($handle);

    if (!empty($errors)) {
        $shown = array_slice($errors, 0, 50);
        $more  = count($errors) - count($shown);
        $extra = $more > 0 ? ("\n" . (($lang === 'sw') ? "...na makosa mengine $more." : "...and $more more.")) : '';
        $header = ($lang === 'sw') ? "MAKOSA! Faili lina makosa (".count($errors)."). Hakuna aliyeingizwa:\n" : "ERRORS! File has errors (".count($errors)."). Nobody was imported:\n";
        $response['message'] = $header . implode("\n", $shown) . $extra;
        echo json_encode($response);
        exit;
    }

    try {
        $role_stmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) LIKE '%member%' OR LOWER(role_name) LIKE '%mwanachama%' LIMIT 1");
        $role_stmt->execute();
        $member_role_id = $role_stmt->fetchColumn() ?: null;

        $pdo->beginTransaction();
        $count = 0;

        foreach ($allRows as $row) {
            // Generate Username/Password
            $username = strtolower(substr($row['first_name'], 0, 1) . preg_replace('/\s+/', '', $row['last_name']));
            $u_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $u_check->execute([$username]);
            if ($u_check->fetchColumn() > 0) $username .= mt_rand(10, 99);
            
            $password = $username . "@123";
            $hashed = password_hash($password, PASSWORD_BCRYPT);

            // Sanitize Entrance Fee (remove commas/spaces)
            $fee = (float)preg_replace('/[^0-9.]/', '', $row['initial_savings']);

            // Parse Children
            $children = [];
            if (!empty($row['children_raw'])) {
                $kids = explode(',', $row['children_raw']);
                foreach ($kids as $k) {
                    $parts = explode('-', trim($k));
                    if (count($parts) >= 1) {
                        $children[] = ['name' => $parts[0], 'age' => $parts[1] ?? '', 'gender' => $parts[2] ?? ''];
                    }
                }
            }

            // Insert User
            $u_stmt = $pdo->prepare("INSERT INTO users (username, email, password, first_name, middle_name, last_name, phone, user_role, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'Member', ?, 'pending', NOW())");
            $u_stmt->execute([$username, $row['email'], $hashed, $row['first_name'], $row['middle_name'], $row['last_name'], $row['phone'], $member_role_id]);
            $user_id = $pdo->lastInsertId();

            // Insert Customer
            $full_name = trim($row['first_name']." ".$row['middle_name']." ".$row['last_name']);
            $c_stmt = $pdo->prepare("INSERT INTO customers (
                first_name, middle_name, last_name, customer_name, email, phone, gender, dob, nida_number, religion, birth_region,
                country, state, district, ward, street, house_number, marital_status,
                father_name, father_location, father_sub_location, father_phone,
                mother_name, mother_location, mother_sub_location, mother_phone,
                spouse_first_name, spouse_middle_name, spouse_last_name, spouse_email, spouse_phone, spouse_gender, spouse_dob, spouse_nida, spouse_religion, spouse_birth_region,
                children_data, guarantor_name, guarantor_phone, guarantor_rel, guarantor_location,
                status, initial_savings, user_id, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending', ?, ?, NOW())");
            
            $c_stmt->execute([
                $row['first_name'], $row['middle_name'], $row['last_name'], $full_name, $row['email'], $row['phone'], $row['gender'], $row['dob'], $row['nida'], $row['religion'], $row['birth_region'],
                $row['country'], $row['region'], $row['district'], $row['ward'], $row['street'], $row['house_no'], $row['marital_status'],
                $row['father_name'], $row['father_loc'], $row['father_street'], $row['father_phone'],
                $row['mother_name'], $row['mother_loc'], $row['mother_street'], $row['mother_phone'],
                $row['spouse_first'], $row['spouse_middle'], $row['spouse_last'], $row['spouse_email'], $row['spouse_phone'], $row['spouse_gender'], $row['spouse_dob'], $row['spouse_nida'], $row['spouse_religion'], $row['spouse_birth_reg'],
                json_encode($children), $row['guarantor_name'], $row['guarantor_phone'], $row['guarantor_rel'], $row['guarantor_loc'],
                $fee, $user_id
            ]);
            $cust_id = $pdo->lastInsertId();

            if ($fee > 0) {
                $pdo->prepare("INSERT INTO contributions (member_id, amount, contribution_date, description, status, created_at) VALUES (?, ?, CURRENT_DATE, 'Initial savings', 'pending', NOW())")->execute([$cust_id, $fee]);
            }

            // Audit trail — record each bulk-created member
            logCreate('Members (Batch Import)', $full_name, "USER#$user_id", $_SESSION['user_id']);
            $count++;
        }
        $pdo->commit();
        $response = ['status' => 'success', 'message' => ($lang === 'sw' ? "Usajili wa wanachama $count umekamilika." : "Import of $count members completed.")];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Batch member import failed: ' . $e->getMessage());
        $response['message'] = ($lang === 'sw')
            ? 'Hitilafu ya mfumo wakati wa kuhifadhi. Hakuna mwanachama aliyeingizwa.'
            : 'A system error occurred while saving. No members were imported.';
    }
}
echo json_encode($response);
