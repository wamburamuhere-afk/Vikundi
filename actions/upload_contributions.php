<?php
// actions/upload_contributions.php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if file was uploaded
if (!isset($_FILES['contribution_file']) || $_FILES['contribution_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid file to upload']);
    exit();
}

$upload_type = $_POST['upload_type'] ?? 'existing';
$file_path = $_FILES['contribution_file']['tmp_name'];
$file_ext = strtolower(pathinfo($_FILES['contribution_file']['name'], PATHINFO_EXTENSION));

if ($file_ext !== 'csv') {
    echo json_encode(['success' => false, 'message' => 'Only CSV files are supported at this time. Please save your Excel file as CSV and try again.']);
    exit();
}

$handle = fopen($file_path, 'r');
if (!$handle) {
    echo json_encode(['success' => false, 'message' => 'Unable to open the uploaded file']);
    exit();
}

// Get the header row
$headers = fgetcsv($handle);
if (!$headers) {
    fclose($handle);
    echo json_encode(['success' => false, 'message' => 'The file is empty or invalid']);
    exit();
}

// Clean headers (remove BOM and trim)
foreach ($headers as $key => $value) {
    $headers[$key] = trim(str_replace("\xEF\xBB\xBF", '', $value));
}

$header_map = array_flip($headers);
$import_results = ['success_count' => 0, 'error_count' => 0, 'errors' => []];

$row_num = 1;

while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    if (empty(array_filter($row))) continue; // Skip empty rows

    try {
        $member_id = null;
        $amount = 0;
        $date = date('Y-m-d');
        $type = 'monthly'; // Default
        $description = '';
        
        // M-KOBA Specific Columns
        $mkoba_data = [
            'mkoba_sno' => null,
            'mkoba_trans_id' => null,
            'mkoba_receipt' => null,
            'mkoba_member_name' => null,
            'mkoba_member_id_str' => null,
            'mkoba_source' => null,
            'mkoba_destination' => null,
            'mkoba_trans_type' => null
        ];

        if ($upload_type === 'mkoba') {
            // Expected: S/NO, TRANS_ID, RECEIPT, DATE, MEMBER NAME, MEMBER ID, SOURCE, DESTINATION, AMOUNT, TRANS TYPE
            $sno = $row[$header_map['S/NO']] ?? ($row[$header_map['s/no']] ?? null);
            $trans_id = $row[$header_map['TRANS_ID']] ?? ($row[$header_map['trans_id']] ?? null);
            $receipt = $row[$header_map['RECEIPT']] ?? ($row[$header_map['receipt']] ?? null);
            $date_str = $row[$header_map['DATE']] ?? ($row[$header_map['date']] ?? null);
            $m_name = $row[$header_map['MEMBER NAME']] ?? ($row[$header_map['member name']] ?? null);
            $m_id_str = $row[$header_map['MEMBER ID']] ?? ($row[$header_map['member id']] ?? null);
            $src = $row[$header_map['SOURCE']] ?? ($row[$header_map['source']] ?? null);
            $dest = $row[$header_map['DESTINATION']] ?? ($row[$header_map['destination']] ?? null);
            $amt_str = $row[$header_map['AMOUNT']] ?? ($row[$header_map['amount']] ?? 0);
            $t_type = $row[$header_map['TRANS TYPE']] ?? ($row[$header_map['trans type']] ?? null);

            $date = !empty($date_str) ? date('Y-m-d', strtotime(str_replace('/', '-', $date_str))) : date('Y-m-d');
            $amount = (float) str_replace(',', '', $amt_str);
            $type = 'monthly'; // We can map $t_type later if needed
            $description = "M-Koba: $trans_id | $t_type";

            $mkoba_data = [
                'mkoba_sno' => $sno,
                'mkoba_trans_id' => $trans_id,
                'mkoba_receipt' => $receipt,
                'mkoba_member_name' => $m_name,
                'mkoba_member_id_str' => $m_id_str,
                'mkoba_source' => $src,
                'mkoba_destination' => $dest,
                'mkoba_trans_type' => $t_type
            ];

            // Try to find member
            if (!empty($m_id_str)) {
                $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? OR customer_code = ? LIMIT 1");
                $stmt->execute([$m_id_str, $m_id_str]);
                $member_id = $stmt->fetchColumn();
            }
            
            if (!$member_id && !empty($m_name)) {
                $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? LIMIT 1");
                $stmt->execute(["%$m_name%", "%$m_name%"]);
                $member_id = $stmt->fetchColumn();
            }
        } else {
            // Existing Report Format (General CSV)
            // Try to find columns by name
            $amt_val = 0;
            foreach (['amount', 'kiasi', 'total', 'sum'] as $possible) {
                if (isset($header_map[$possible])) { $amt_val = $row[$header_map[$possible]]; break; }
            }
            $amount = (float) str_replace(',', '', $amt_val);

            foreach (['date', 'tarehe', 'created_at'] as $possible) {
                if (isset($header_map[$possible])) { $date = date('Y-m-d', strtotime(str_replace('/', '-', $row[$header_map[$possible]]))); break; }
            }

            foreach (['id', 'member_id', 'customer_id', 'code'] as $possible) {
                if (isset($header_map[$possible])) {
                    $val = $row[$header_map[$possible]];
                    $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_id = ? OR customer_code = ? LIMIT 1");
                    $stmt->execute([$val, $val]);
                    $member_id = $stmt->fetchColumn();
                    if ($member_id) break;
                }
            }

            if (!$member_id) {
                foreach (['name', 'mwanachama', 'member', 'customer_name'] as $possible) {
                    if (isset($header_map[$possible])) {
                        $val = $row[$header_map[$possible]];
                        $stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE customer_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? LIMIT 1");
                        $stmt->execute(["%$val%", "%$val%"]);
                        $member_id = $stmt->fetchColumn();
                        if ($member_id) break;
                    }
                }
            }
            
            $description = "Bulk Upload: " . date('Y-m-d H:i');
        }

        if (!$member_id) {
            $import_results['error_count']++;
            $import_results['errors'][] = "Row $row_num: Member not found.";
            continue;
        }

        // Insert contribution
        $sql = "INSERT INTO contributions (
                    member_id, amount, contribution_type, contribution_date, description, status, 
                    mkoba_sno, mkoba_trans_id, mkoba_receipt, mkoba_member_name, mkoba_member_id_str, 
                    mkoba_source, mkoba_destination, mkoba_trans_type, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $member_id, $amount, $type, $date, $description,
            $mkoba_data['mkoba_sno'], $mkoba_data['mkoba_trans_id'], $mkoba_data['mkoba_receipt'],
            $mkoba_data['mkoba_member_name'], $mkoba_data['mkoba_member_id_str'],
            $mkoba_data['mkoba_source'], $mkoba_data['mkoba_destination'], $mkoba_data['mkoba_trans_type'],
            $_SESSION['user_id']
        ]);

        $import_results['success_count']++;

    } catch (Exception $e) {
        $import_results['error_count']++;
        $import_results['errors'][] = "Row $row_num: " . $e->getMessage();
    }
}

fclose($handle);

$msg = "Imported " . $import_results['success_count'] . " contributions.";
if ($import_results['error_count'] > 0) {
    $msg .= " Failed: " . $import_results['error_count'] . ". First error: " . ($import_results['errors'][0] ?? 'Unknown error');
}

echo json_encode([
    'success' => true,
    'message' => $msg,
    'details' => $import_results
]);
