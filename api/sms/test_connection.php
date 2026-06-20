<?php
/**
 * api/sms/test_connection.php — send a real test SMS using the SAVED gateway
 * settings, so an admin can confirm delivery works. Mirrors the email tester.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once ROOT_DIR . '/includes/config.php';
require_once ROOT_DIR . '/core/permissions.php';
require_once ROOT_DIR . '/includes/activity_logger.php';
require_once ROOT_DIR . '/includes/sms_helper.php';

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

    $to = trim($_POST['test_phone'] ?? '');
    if ($to === '') {
        $stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $to = (string)$stmt->fetchColumn();
    }
    if (sms_normalize_phone($to) === '') {
        throw new Exception($is_sw ? 'Weka namba sahihi ya simu kupokea jaribio.' : 'Enter a valid phone number to receive the test.');
    }

    $msg = $is_sw
        ? 'Jaribio la Vikundi: mipangilio yako ya SMS inafanya kazi.'
        : 'Vikundi test: your SMS settings are working.';

    $res = sms_send($to, $msg, ['created_by' => (int)$_SESSION['user_id']]);

    logActivity('Tested', 'SMS Settings', 'Sent a test SMS to ' . $to, 'SMSCFG', (int)$_SESSION['user_id']);

    if ($res['success']) {
        echo json_encode(['success' => true, 'message' => ($is_sw ? 'SMS ya jaribio imetumwa kwa ' : 'Test SMS sent to ') . $to . '.']);
    } else {
        echo json_encode(['success' => false, 'message' => ($is_sw ? 'Imeshindwa: ' : 'Failed: ') . $res['message']]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
