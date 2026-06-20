<?php
/**
 * api/email/test_connection.php — send a real test email using the SAVED
 * email settings, so an admin can confirm delivery actually works.
 * Mirrors api/ai/test_connection.php.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/core/permissions.php';
require_once ROOT_DIR . '/includes/activity_logger.php';
require_once ROOT_DIR . '/includes/email_helper.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception($is_sw ? 'Hujaingia kwenye mfumo.' : 'Not authenticated.');
    }
    if (!isAdmin() && !canEdit('system_settings')) {
        http_response_code(403);
        throw new Exception($is_sw ? 'Ni wasimamizi pekee.' : 'Administrators only.');
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception($is_sw ? 'Njia si sahihi.' : 'Method not allowed.');
    }

    // Default the test recipient to the logged-in admin's own email.
    $to = trim($_POST['test_email'] ?? '');
    if ($to === '') {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE user_id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $to = (string)$stmt->fetchColumn();
    }
    if (!email_is_valid($to)) {
        throw new Exception($is_sw ? 'Weka barua pepe sahihi ya kupokea jaribio.' : 'Enter a valid email address to receive the test.');
    }

    $subject = $is_sw ? 'Jaribio la Barua Pepe — Vikundi' : 'Email Test — Vikundi';
    $body    = $is_sw
        ? '<p>Hongera! Mipangilio yako ya barua pepe inafanya kazi. Ujumbe huu umetumwa kutoka mfumo wa Vikundi.</p>'
        : '<p>Success! Your email settings are working. This message was sent from the Vikundi system.</p>';

    $res = email_send($to, $subject, $body, ['created_by' => (int)$_SESSION['user_id']]);

    logActivity('Tested', 'Email Settings', 'Sent a test email to ' . $to, 'EMAILCFG', (int)$_SESSION['user_id']);

    if ($res['success']) {
        echo json_encode(['success' => true, 'message' => ($is_sw ? 'Barua pepe ya jaribio imetumwa kwa ' : 'Test email sent to ') . $to . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => ($is_sw ? 'Imeshindwa: ' : 'Failed: ') . $res['message']]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
