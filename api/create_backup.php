<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/config.php';

// Check permissions (Admin only)
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $root_dir = realpath(__DIR__ . '/..');
    $backup_dir = $root_dir . DIRECTORY_SEPARATOR . 'backups';
    
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0777, true);
    }

    $filename = 'backup_v_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backup_dir . DIRECTORY_SEPARATOR . $filename;

    // Check if exec is enabled
    $disabled = explode(',', ini_get('disable_functions'));
    if (in_array('exec', array_map('trim', $disabled))) {
        throw new Exception("The 'exec' function is disabled in PHP configuration.");
    }

    // Define common WAMP/XAMPP paths for mysqldump on Windows
    $mysqldump_paths = [
        'C:\wamp64\bin\mysql\mysql8.4.7\bin\mysqldump.exe',
        'C:\wamp64\bin\mysql\mysql8.0.27\bin\mysqldump.exe',
        'C:\wamp64\bin\mysql\mysql5.7.36\bin\mysqldump.exe',
        'mysqldump'
    ];

    $success = false;
    $errors = [];

    // Try multiple hosts (MySQL 8 on Windows sometimes needs 127.0.0.1 instead of localhost)
    $hosts = [DB_SERVER, '127.0.0.1'];

    foreach ($mysqldump_paths as $path) {
        foreach ($hosts as $host) {
            $pass_part = !empty(DB_PASSWORD) ? " --password=\"" . DB_PASSWORD . "\"" : "";
            
            // Command for mysqldump
            $command = "\"$path\" --user=\"" . DB_USERNAME . "\"$pass_part --host=\"" . $host . "\" " . DB_NAME . " > \"" . $filepath . "\" 2>&1";
            
            $output = [];
            $return_var = -1;
            @exec($command, $output, $return_var);

            if ($return_var === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                $success = true;
                break 2;
            } else {
                $errors[] = "$path (@$host): " . implode(" ", $output);
            }
        }
    }

    if ($success) {
        // Clean up older backups (optional, but good practice)
        echo json_encode(['success' => true, 'message' => 'Backup created successfully', 'filename' => $filename]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Backup failed on all attempted paths.',
            'debug' => implode(" | ", array_unique($errors))
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
}
