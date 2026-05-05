<?php
// actions/forgot_password.php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/config.php';

$username = trim($_POST['username'] ?? '');
$nida = trim($_POST['nida'] ?? '');

if (empty($username) || empty($nida)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in both fields.']);
    exit();
}

// Search for user with matching username and check their NIDA in customers table
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, c.nida_number 
    FROM users u 
    JOIN customers c ON u.email = c.email 
    WHERE u.username = ? AND c.nida_number = ?
");
$stmt->execute([$username, $nida]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    // Identity Verified!
    // Generate a temporary reset token (simple version for this exercise)
    $token = bin2hex(random_bytes(16));
    
    // Store token in session for simplicity (or database if more security needed)
    $_SESSION['reset_token'] = $token;
    $_SESSION['reset_user_id'] = $user['user_id'];
    $_SESSION['reset_time'] = time();

    $lang = $_SESSION['preferred_language'] ?? 'en';
    $message = ($lang === 'sw') 
        ? "Utambulisho umethibitishwa kwa mtumiaji: " . $user['username'] . ". Sasa unaweza kubadili neno la siri."
        : "Identity verified for user: " . $user['username'] . ". You can now reset your password.";

    echo json_encode([
        'success' => true, 
        'message' => $message,
        'token' => $token
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Verification failed. Information provided does not match our records.']);
}
