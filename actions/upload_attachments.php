<?php
// ajax/upload_attachments.php
session_start();
require_once '../includes/db.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Define upload directory
    $uploadDir = '../uploads/';

    // Get customer ID
    $customer_id = $_POST['customer_id'];

    // Process each file
    $files = [
        'id_document' => 'ID Document',
        'passport_photo' => 'Passport Photo',
        'proof_of_address' => 'Proof of Address',
        'income_proof' => 'Income Proof'
    ];

    foreach ($files as $fileInput => $fileType) {
        if (isset($_FILES[$fileInput])) {
            $fileName = uniqid() . '_' . basename($_FILES[$fileInput]['name']); // Unique filename
            $filePath = $uploadDir . $fileName;

            // Move uploaded file to the upload directory
            if (move_uploaded_file($_FILES[$fileInput]['tmp_name'], $filePath)) {
                // Save file path to the database
                $stmt = $pdo->prepare("INSERT INTO customer_attachments (customer_id, file_type, file_path) VALUES (?, ?, ?)");
                if (!$stmt->execute([$customer_id, $fileType, $filePath])) {
                    $response['message'] = 'Failed to save file information in the database.';
                    echo json_encode($response);
                    exit;
                }
            } else {
                $response['message'] = 'Failed to upload files.';
                echo json_encode($response);
                exit;
            }
        }
    }

    $response['success'] = true;
    $response['message'] = 'Files uploaded and saved successfully!';
} else {
    $response['message'] = 'No files uploaded or an error occurred.';
}

echo json_encode($response);
?>