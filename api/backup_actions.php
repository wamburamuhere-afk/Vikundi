<?php
/**
 * api/backup_actions.php
 * ----------------------
 * Single JSON dispatcher for all backup operations, mirroring the BMS design:
 *   action=create_backup   → full SQL dump
 *   action=restore_backup  → restore from an existing file (pre-restore snapshot first)
 *   action=upload_restore  → upload a .sql then restore it (pre-restore snapshot first)
 *   action=delete_backup   → delete a backup file
 *
 * Dump/restore engine lives in core/backup.php (handles VIEWS correctly).
 *
 * SECURITY NOTE — CSRF: BMS protects this endpoint with csrf_check(). Vikundi
 * does not yet have a CSRF subsystem (no CSRF_TOKEN / csrf_check), so this port
 * matches Vikundi's existing backup endpoints: a logged-in session plus the
 * canDelete('backup_restore') permission gate. If/when Vikundi gains CSRF, add
 * the check here and the X-CSRF-Token header in backup_restore.php.
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/backup.php';
require_once __DIR__ . '/../includes/activity_logger.php';

header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Gate: must be logged in AND hold delete rights on backup_restore. canDelete()
// admin-bypasses internally; non-admin roles can be delegated via roles UI.
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit;
}
if (!canDelete('backup_restore')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to manage system backups.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Single source of truth for the backup directory — MUST match the path used by
// backup_restore.php (table + auto-backup) and api/download_backup.php so
// create/list/download/restore/delete all operate on the same files.
$backupsDir = ROOT_DIR . '/backups/';
if (!is_dir($backupsDir)) mkdir($backupsDir, 0755, true);

$action = $_POST['action'] ?? '';

/**
 * Restore a SQL file via mysqli multi_query. Collects per-statement errors
 * rather than throwing, so a restore that mostly succeeds still reports clearly.
 *
 * @return string[] list of error messages (empty = clean restore)
 */
function restoreFromFile(string $filepath): array {
    set_time_limit(0);

    // Turn off strict mysqli exceptions so individual statement failures are
    // collected as errors rather than thrown as uncatchable exceptions.
    mysqli_report(MYSQLI_REPORT_OFF);

    $mysqli = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
    if ($mysqli->connect_error) {
        throw new Exception("DB connection failed: " . $mysqli->connect_error);
    }
    $mysqli->set_charset('utf8mb4');

    $sql = file_get_contents($filepath);
    if ($sql === false) throw new Exception("Cannot read backup file.");

    // Guarantee every CREATE TABLE is preceded by DROP TABLE IF EXISTS, so
    // older backups (made before the current dump format) don't fail restore
    // with "table already exists".
    $sql = preg_replace_callback(
        '/\bCREATE TABLE\s+(`[^`]+`|\w+)/i',
        fn($m) => "DROP TABLE IF EXISTS {$m[1]};\nCREATE TABLE {$m[1]}",
        $sql
    );

    $errors = [];
    if (!$mysqli->multi_query($sql)) {
        $errors[] = $mysqli->error;
    }
    // Drain all result sets — required after multi_query.
    do {
        if ($result = $mysqli->store_result()) $result->free();
        if ($mysqli->errno) $errors[] = $mysqli->error;
    } while ($mysqli->more_results() && $mysqli->next_result());

    $mysqli->close();
    return $errors;
}

switch ($action) {

    // ── CREATE BACKUP ──────────────────────────────────────────────────────
    case 'create_backup':
        try {
            $filename = 'backup_v_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backupsDir . $filename;
            vikundi_write_dump($pdo, $filepath);

            $kb        = round(filesize($filepath) / 1024, 2);
            $sizeLabel = $kb >= 1024 ? round($kb / 1024, 2) . ' MB' : $kb . ' KB';

            logActivity('Created', 'Backup', "Created database backup: $filename ($sizeLabel)", $filename);

            echo json_encode([
                'success'  => true,
                'message'  => 'Backup created successfully.',
                'filename' => $filename,
                'size'     => $sizeLabel,
            ]);
        } catch (Exception $e) {
            if (isset($filepath) && file_exists($filepath)) @unlink($filepath);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    // ── RESTORE FROM EXISTING BACKUP ───────────────────────────────────────
    case 'restore_backup':
        $filename = basename($_POST['filename'] ?? '');
        $filepath = $backupsDir . $filename;

        if (!$filename || !file_exists($filepath)) {
            echo json_encode(['success' => false, 'message' => 'Backup file not found.']);
            break;
        }

        // Safety net: snapshot CURRENT state before overwriting it. Failure to
        // snapshot aborts the restore.
        try {
            vikundi_write_dump($pdo, $backupsDir . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql');
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Aborted — could not create a pre-restore safety backup: ' . $e->getMessage()]);
            break;
        }

        try {
            $errors = restoreFromFile($filepath);
            if (empty($errors)) {
                logActivity('Restored', 'Backup', "Restored database from backup: $filename", $filename);
                echo json_encode(['success' => true, 'message' => "Database restored successfully from $filename."]);
            } else {
                $count = count($errors);
                error_log("Restore errors from $filename: " . implode(' | ', array_slice($errors, 0, 10)));
                echo json_encode(['success' => false, 'message' => "Restore completed with $count error(s). Check the server error log for details."]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        break;

    // ── DELETE BACKUP ──────────────────────────────────────────────────────
    case 'delete_backup':
        $filename = basename($_POST['filename'] ?? '');
        $filepath = $backupsDir . $filename;

        if (!$filename || !file_exists($filepath)) {
            echo json_encode(['success' => false, 'message' => 'File not found.']);
            break;
        }
        if (@unlink($filepath)) {
            logActivity('Deleted', 'Backup', "Deleted database backup: $filename", $filename);
            echo json_encode(['success' => true, 'message' => "$filename deleted."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete file.']);
        }
        break;

    // ── UPLOAD & RESTORE ───────────────────────────────────────────────────
    case 'upload_restore':
        // When post_max_size is exceeded, PHP empties $_FILES and $_POST entirely.
        if (empty($_FILES)) {
            $maxPost = ini_get('post_max_size');
            echo json_encode(['success' => false,
                'message' => "Upload failed: the file is too large for the server. Current post_max_size is $maxPost. Increase it in php.ini (post_max_size and upload_max_filesize), then restart Apache."]);
            break;
        }
        if (!isset($_FILES['backup_file'])) {
            echo json_encode(['success' => false, 'message' => 'No file received by the server.']);
            break;
        }
        if ($_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $phpUploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ') in php.ini — increase it and restart Apache.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the MAX_FILE_SIZE directive in the HTML form.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            ];
            $code = $_FILES['backup_file']['error'];
            $msg  = $phpUploadErrors[$code] ?? "PHP upload error code: $code";
            echo json_encode(['success' => false, 'message' => $msg]);
            break;
        }

        $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Only .sql files are allowed.']);
            break;
        }

        // Content sniff — first non-empty line must look like SQL.
        $tmpHandle = fopen($_FILES['backup_file']['tmp_name'], 'r');
        $firstLine = '';
        while (!feof($tmpHandle) && trim($firstLine) === '') $firstLine = fgets($tmpHandle);
        fclose($tmpHandle);
        $firstLine  = trim($firstLine);
        $validStart = str_starts_with($firstLine, '--') || str_starts_with($firstLine, '/*')
                   || str_starts_with($firstLine, 'SET ') || str_starts_with($firstLine, 'CREATE ')
                   || str_starts_with($firstLine, 'INSERT ');
        if (!$validStart) {
            echo json_encode(['success' => false, 'message' => 'File does not appear to be a valid SQL dump.']);
            break;
        }

        $safeOrigName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', basename($_FILES['backup_file']['name']));
        $filename     = 'uploaded_' . date('Ymd_His') . '_' . $safeOrigName;
        $destination  = $backupsDir . $filename;

        if (!move_uploaded_file($_FILES['backup_file']['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
            break;
        }

        // Safety net: snapshot current state before the uploaded restore.
        try {
            vikundi_write_dump($pdo, $backupsDir . 'pre_restore_' . date('Y-m-d_H-i-s') . '.sql');
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Aborted — could not create a pre-restore safety backup: ' . $e->getMessage()]);
            break;
        }

        try {
            $errors = restoreFromFile($destination);
            if (empty($errors)) {
                logActivity('Restored', 'Backup', "Uploaded & restored database from: $filename", $filename);
                echo json_encode(['success' => true, 'message' => 'File uploaded and database restored successfully.']);
            } else {
                $count = count($errors);
                error_log("Upload restore errors: " . implode(' | ', array_slice($errors, 0, 10)));
                echo json_encode(['success' => false, 'message' => "Restore completed with $count error(s). Check the server error log."]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
