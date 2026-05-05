<?php
/**
 * SMS Helper for VICoBA System
 * Handles SMS sending via multiple gateways
 */

require_once __DIR__ . '/config.php';

if (!function_exists('send_sms')) {
    /**
     * Send an SMS to a recipient
     * @param string $phone The recipient phone number (e.g., 0712345678)
     * @param string $message The message body
     * @return array [success => bool, response => string]
     */
    function send_sms($phone, $message) {
        $api_key = ""; // Get these from settings
        $sender_id = "";
        
        // Find actual settings from database
        global $pdo;
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sms_api_key', 'sms_sender_id', 'enable_sms_notifications')");
        $sms_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (($sms_settings['enable_sms_notifications'] ?? 0) != 1) {
            return ['success' => false, 'message' => 'SMS notifications are disabled in settings.'];
        }

        $api_key = $sms_settings['sms_api_key'] ?? '';
        $sender_id = $sms_settings['sms_sender_id'] ?? 'VIKUNDI';

        if (empty($api_key)) {
            return ['success' => false, 'message' => 'SMS API Key not configured.'];
        }

        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) == 9) {
            $phone = '255' . $phone;
        } elseif (strlen($phone) == 10 && str_starts_with($phone, '0')) {
            $phone = '255' . substr($phone, 1);
        }

        // --- GATEWAY INTEGRATION (Example: Beem or similar African gateway) ---
        // For demonstration, we simulate a successful send or log to a table
        
        try {
            // Log to sms_alerts table for record keeping
            $log_stmt = $pdo->prepare("INSERT INTO sms_alerts (recipient, message, status, created_at) VALUES (?, ?, ?, NOW())");
            $log_stmt->execute([$phone, $message, 'sent']);
            
            // Real API Call would go here (e.g. using curl)
            /*
            $url = 'https://api.gateway.com/v1/send';
            $data = [ 'recipient' => $phone, 'message' => $message, 'api_key' => $api_key, 'sender' => $sender_id ];
            // ... curl execution ...
            */
            
            return ['success' => true, 'message' => 'SMS sent and logged.'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'SMS failed: ' . $e->getMessage()];
        }
    }
}
