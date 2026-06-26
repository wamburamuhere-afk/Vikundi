<?php
// actions/import_contributions.php
// Bulk transaction import — our own template OR an M-Koba statement. Each row is
// normalised by includes/transaction_import.php, matched to a member by phone,
// de-duplicated, and inserted into `contributions` as a PENDING transaction.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/require_csrf.php'; // audit H6: valid CSRF token required
require_once __DIR__ . '/../includes/transaction_import.php';
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access. Please login first.");
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload_file'])) {
    $upload_type = $_POST['upload_type'] ?? 'existing_report';
    $isMkoba = ($upload_type === 'mkoba_statement');
    $file = $_FILES['upload_file']['tmp_name'];

    if (!is_uploaded_file($file)) {
        $_SESSION['import_response'] = ['success' => false, 'message' => 'No file uploaded.'];
        header('Location: ' . getUrl('transactions'));
        exit;
    }

    $handle = fopen($file, 'r');
    if (!$handle) {
        $_SESSION['import_response'] = ['success' => false, 'message' => 'Unable to read file.'];
        header('Location: ' . getUrl('transactions'));
        exit;
    }

    // Header row → lowercased, trimmed names (handles a UTF-8 BOM on col 1).
    $headers = fgetcsv($handle);
    if ($headers === false) {
        $_SESSION['import_response'] = ['success' => false, 'message' => 'The file is empty.'];
        header('Location: ' . getUrl('transactions'));
        exit;
    }
    $headers = array_map(function ($h) {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $h)));
    }, $headers);

    $imported = 0; $skipped = 0; $duplicates = 0; $unmatched = [];

    $findMember = $pdo->prepare("
        SELECT c.customer_id FROM customers c
        JOIN users u ON c.user_id = u.user_id
        WHERE u.status = 'active' AND c.is_deceased = 0 AND c.phone LIKE ?
        LIMIT 1
    ");
    $dupByReceipt = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE mkoba_receipt = ? AND member_id = ?");
    $dupByRow     = $pdo->prepare("SELECT COUNT(*) FROM contributions WHERE member_id = ? AND amount = ? AND contribution_date = ?");
    $insert = $pdo->prepare("
        INSERT INTO contributions (
            member_id, amount, contribution_type, contribution_date, description, status,
            receipt_number, account,
            mkoba_receipt, mkoba_trans_type, mkoba_source, mkoba_destination,
            mkoba_member_id_str, mkoba_member_name, mkoba_trans_id, mkoba_sno,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    try {
        $pdo->beginTransaction();

        while (($data = fgetcsv($handle)) !== false) {
            // Skip wholly empty lines.
            if (count(array_filter($data, fn($v) => trim((string) $v) !== '')) === 0) continue;

            $assoc = [];
            foreach ($headers as $i => $key) {
                if ($key !== '') $assoc[$key] = $data[$i] ?? '';
            }

            $p = $isMkoba ? mkoba_parse_row($assoc) : txn_template_parse_row($assoc);
            if ($p === null) { $skipped++; continue; } // non-contribution / missing phone or amount

            $findMember->execute(['%' . $p['phone']]);
            $member_id = $findMember->fetchColumn();
            if (!$member_id) {
                $unmatched[] = ($p['name'] !== '' ? $p['name'] : 'Unknown') . ' (' . $p['phone'] . ')';
                continue;
            }

            // De-dupe: by receipt when present, else by member+amount+date.
            if ($p['receipt'] !== '') {
                $dupByReceipt->execute([$p['receipt'], $member_id]);
                $isDup = (int) $dupByReceipt->fetchColumn() > 0;
            } else {
                $dupByRow->execute([$member_id, $p['amount'], $p['date'] ?? date('Y-m-d')]);
                $isDup = (int) $dupByRow->fetchColumn() > 0;
            }
            if ($isDup) { $duplicates++; continue; }

            $insert->execute([
                $member_id, $p['amount'], $p['type'], $p['date'] ?? date('Y-m-d'), $p['description'],
                $p['receipt'] !== '' ? $p['receipt'] : null,
                $p['account'],
                $p['receipt'] !== '' ? $p['receipt'] : null,
                $p['trans_type'] !== '' ? $p['trans_type'] : null,
                $p['source'] !== '' ? $p['source'] : null,
                $p['destination'] !== '' ? $p['destination'] : null,
                $p['phone'],
                $p['name'] !== '' ? $p['name'] : null,
                $p['trans_id'] !== '' ? $p['trans_id'] : null,
                $p['sno'] !== '' ? $p['sno'] : null,
                $_SESSION['user_id'],
            ]);
            $imported++;
        }

        $pdo->commit();

        $parts = ["Imported $imported transaction(s) (pending approval)."];
        if ($duplicates) $parts[] = "$duplicates already on record — skipped.";
        if ($skipped)    $parts[] = "$skipped non-contribution row(s) ignored.";
        if ($unmatched)  $parts[] = count($unmatched) . " unmatched (no member): " . implode(', ', array_slice($unmatched, 0, 8)) . (count($unmatched) > 8 ? '…' : '');

        $response['success'] = true;
        $response['message'] = implode(' ', $parts);

        if ($imported > 0) {
            require_once __DIR__ . '/../includes/activity_logger.php';
            logCreate('Transactions', (string) $imported, $isMkoba ? 'M-Koba import' : 'Bulk import');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $response['message'] = 'Import error: ' . $e->getMessage();
    }

    fclose($handle);
} else {
    $response['message'] = 'Invalid request.';
}

$_SESSION['import_response'] = $response;
header('Location: ' . getUrl('transactions'));
exit;
