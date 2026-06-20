<?php
/**
 * SMS Helper for the Vikundi VICOBA System
 * --------------------------------------------------------------
 * Provider-agnostic, REAL SMS sending over the gateways' HTTPS APIs
 * (Beem Africa, Africa's Talking, Twilio, or a custom endpoint), built
 * to mirror includes/email_helper.php. Configuration lives in the
 * `system_settings` table (group 'sms'); secrets are encrypted with the
 * existing core/ai_crypto.php. Every attempt is recorded in `sms_logs`.
 *
 * Pure helpers (no DB / no network) are testable in isolation.
 *
 * Callers that use the DB-backed functions must have $pdo available (they
 * load includes/config.php via roots.php/header.php first), so this file does
 * not open its own connection.
 */

// ---------------------------------------------------------------------------
// Pure helpers (no DB / no network)
// ---------------------------------------------------------------------------

if (!function_exists('sms_normalize_phone')) {
    /**
     * Normalise a phone number to international MSISDN (no '+'), defaulting to
     * Tanzania (255) for local formats.
     *
     * @param string $phone
     * @param string $cc Default country code digits (Tanzania = 255)
     * @return string Digits only, e.g. 255712345678 (empty if unusable)
     */
    function sms_normalize_phone($phone, string $cc = '255'): string
    {
        $p = preg_replace('/[^0-9]/', '', (string)$phone);
        if ($p === '') return '';
        if (str_starts_with($p, '0')) {
            $p = $cc . substr($p, 1);            // 0712… -> 255712…
        } elseif (strlen($p) === 9) {
            $p = $cc . $p;                        // 712345678 -> 255712345678
        }
        return $p;
    }
}

if (!function_exists('sms_segments')) {
    /**
     * Number of 160-char SMS segments a message needs (153 per part when
     * concatenated). Useful for cost/length hints.
     *
     * @param string $message
     * @return int
     */
    function sms_segments($message): int
    {
        $len = mb_strlen((string)$message);
        if ($len === 0) return 0;
        return $len <= 160 ? 1 : (int)ceil($len / 153);
    }
}

if (!function_exists('sms_gateways')) {
    /**
     * Supported SMS gateways with bilingual help and which credential fields
     * each one needs. Lets low-tech admins pick a provider instead of guessing
     * endpoints.
     *
     * @param bool $is_sw
     * @return array<string,array{label:string,fields:string[],help:string}>
     */
    function sms_gateways(bool $is_sw = false): array
    {
        return [
            'beem' => [
                'label'  => 'Beem Africa',
                'fields' => ['api_key', 'api_secret', 'sender_id'],
                'help'   => $is_sw
                    ? 'Pendekezwa Afrika Mashariki. Tumia API Key na Secret kutoka kwenye dashibodi ya Beem; Sender ID iliyoidhinishwa.'
                    : 'Recommended for East Africa. Use the API Key and Secret from your Beem dashboard, plus an approved Sender ID.',
            ],
            'africastalking' => [
                'label'  => "Africa's Talking",
                'fields' => ['username', 'api_key', 'sender_id'],
                'help'   => $is_sw
                    ? 'Weka Username na API Key. Sender ID (au shortcode) ni ya hiari.'
                    : 'Enter your Username and API Key. Sender ID (or shortcode) is optional.',
            ],
            'twilio' => [
                'label'  => 'Twilio',
                'fields' => ['api_key', 'api_secret', 'sender_id'],
                'help'   => $is_sw
                    ? 'API Key = Account SID, Secret = Auth Token, Sender ID = namba yako ya Twilio.'
                    : 'API Key = Account SID, Secret = Auth Token, Sender ID = your Twilio number.',
            ],
            'custom' => [
                'label'  => $is_sw ? 'Nyingine (HTTP API)' : 'Other (HTTP API)',
                'fields' => ['base_url', 'api_key', 'sender_id'],
                'help'   => $is_sw
                    ? 'POST kwa base_url na sehemu: to, message, sender, api_key.'
                    : 'POSTs to your base_url with fields: to, message, sender, api_key.',
            ],
        ];
    }
}

// ---------------------------------------------------------------------------
// DB-backed helpers
// ---------------------------------------------------------------------------

if (!function_exists('sms_ensure_logs_table')) {
    /**
     * Create the sms_logs table if missing (idempotent). Separate from the
     * loan-centric sms_alerts table.
     *
     * @param PDO $pdo
     * @return void
     */
    function sms_ensure_logs_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sms_logs (
                sms_id          INT AUTO_INCREMENT PRIMARY KEY,
                recipient_phone VARCHAR(30) NOT NULL,
                recipient_name  VARCHAR(150) DEFAULT NULL,
                message         VARCHAR(800) NOT NULL,
                status          ENUM('sent','failed','queued') NOT NULL DEFAULT 'queued',
                provider        VARCHAR(40) DEFAULT NULL,
                error_message   TEXT DEFAULT NULL,
                segments        TINYINT DEFAULT 1,
                sent_at         DATETIME DEFAULT NULL,
                created_by      INT DEFAULT NULL,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_logs_status (status),
                INDEX idx_sms_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

if (!function_exists('sms_save_setting')) {
    /**
     * Upsert one key into system_settings under the 'sms' group.
     *
     * @param PDO $pdo
     * @param string $key
     * @param string $value
     * @return void
     */
    function sms_save_setting(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group)
                       VALUES (?, ?, 'sms')
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $value]);
    }
}

if (!function_exists('sms_get_config')) {
    /**
     * Full SMS gateway configuration (group 'sms'). API key/secret are stored
     * encrypted (core/ai_crypto.php) and decrypted here for sending only.
     *
     * @param PDO $pdo
     * @return array
     */
    function sms_get_config(PDO $pdo): array
    {
        $s = [];
        try {
            $stmt = $pdo->query("
                SELECT setting_key, setting_value FROM system_settings
                WHERE setting_key IN (
                    'sms_enabled','sms_provider','sms_api_key_enc','sms_api_secret_enc',
                    'sms_username','sms_sender_id','sms_base_url',
                    'enable_sms_notifications','sms_api_key','sms_sender_id_legacy'
                )
            ");
            $s = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            error_log('sms_get_config: ' . $e->getMessage());
        }

        $cryptoFile = __DIR__ . '/../core/ai_crypto.php';
        if (is_file($cryptoFile)) require_once $cryptoFile;
        $dec = function ($v) {
            if (empty($v)) return '';
            return function_exists('aiDecryptSecret') ? (string) aiDecryptSecret($v) : '';
        };

        $enabled = isset($s['sms_enabled'])
            ? ($s['sms_enabled'] != '0')
            : (($s['enable_sms_notifications'] ?? '1') != '0');

        $apiKey = $dec($s['sms_api_key_enc'] ?? '');
        $secret = $dec($s['sms_api_secret_enc'] ?? '');
        $provider = $s['sms_provider'] ?? '';

        // A gateway is usable when the provider + its required credentials exist.
        $hasGateway = false;
        if ($provider !== '' && $apiKey !== '') {
            if ($provider === 'africastalking') {
                $hasGateway = !empty($s['sms_username']);
            } elseif ($provider === 'custom') {
                $hasGateway = !empty($s['sms_base_url']);
            } else { // beem, twilio
                $hasGateway = $secret !== '';
            }
        }

        return [
            'enabled'    => $enabled,
            'provider'   => $provider,
            'api_key'    => $apiKey,
            'api_secret' => $secret,
            'username'   => trim((string)($s['sms_username'] ?? '')),
            'sender_id'  => trim((string)($s['sms_sender_id'] ?? 'VIKUNDI')) ?: 'VIKUNDI',
            'base_url'   => trim((string)($s['sms_base_url'] ?? '')),
            'has_gateway' => $hasGateway,
        ];
    }
}

if (!function_exists('sms_send_via_gateway')) {
    /**
     * Deliver one SMS through the configured gateway via cURL. Returns
     * [ok, error]. Never throws.
     *
     * @param array  $cfg   sms_get_config() result
     * @param string $phone Normalised MSISDN (digits, no '+')
     * @param string $message
     * @return array{ok:bool,error:?string}
     */
    function sms_send_via_gateway(array $cfg, string $phone, string $message): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL is not available on this server.'];
        }

        $provider = $cfg['provider'];
        $sender   = $cfg['sender_id'] ?: 'VIKUNDI';

        try {
            switch ($provider) {
                case 'beem':
                    $url  = 'https://apisms.beem.africa/v1/send';
                    $body = json_encode([
                        'source_addr' => $sender,
                        'encoding'    => 0,
                        'message'     => $message,
                        'recipients'  => [['recipient_id' => 1, 'dest_addr' => $phone]],
                    ]);
                    return _sms_http($url, $body, [
                        'Content-Type: application/json',
                        'Authorization: Basic ' . base64_encode($cfg['api_key'] . ':' . $cfg['api_secret']),
                    ], 'json');

                case 'africastalking':
                    $url  = 'https://api.africastalking.com/version1/messaging';
                    $form = http_build_query(array_filter([
                        'username' => $cfg['username'],
                        'to'       => '+' . $phone,
                        'message'  => $message,
                        'from'     => $cfg['sender_id'] !== 'VIKUNDI' ? $cfg['sender_id'] : null,
                    ]));
                    return _sms_http($url, $form, [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Accept: application/json',
                        'apiKey: ' . $cfg['api_key'],
                    ], 'form');

                case 'twilio':
                    // api_key = Account SID, api_secret = Auth Token, sender_id = From number
                    $sid  = $cfg['api_key'];
                    $url  = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
                    $form = http_build_query([
                        'To'   => '+' . $phone,
                        'From' => $cfg['sender_id'],
                        'Body' => $message,
                    ]);
                    return _sms_http($url, $form, [
                        'Content-Type: application/x-www-form-urlencoded',
                        'Authorization: Basic ' . base64_encode($sid . ':' . $cfg['api_secret']),
                    ], 'twilio');

                case 'custom':
                    $form = http_build_query([
                        'to' => $phone, 'message' => $message,
                        'sender' => $sender, 'api_key' => $cfg['api_key'],
                    ]);
                    return _sms_http($cfg['base_url'], $form, [
                        'Content-Type: application/x-www-form-urlencoded',
                    ], 'form');

                default:
                    return ['ok' => false, 'error' => 'No SMS gateway is configured.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

if (!function_exists('_sms_http')) {
    /**
     * Internal: POST to a gateway and interpret the HTTP status as success.
     *
     * @param string   $url
     * @param string   $body
     * @param string[] $headers
     * @param string   $kind  json|form|twilio (affects success codes)
     * @return array{ok:bool,error:?string}
     */
    function _sms_http(string $url, string $body, array $headers, string $kind): array
    {
        if ($url === '') return ['ok' => false, 'error' => 'Gateway URL is not set.'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'error' => 'Network error: ' . ($cerr ?: 'no response')];
        }
        // 2xx = accepted by the gateway. Beem/AT use 200/201; Twilio uses 201.
        if ($code >= 200 && $code < 300) {
            // Africa's Talking returns 201 even for some rejections — surface the body if it signals failure.
            if (stripos((string)$resp, '"status":"Failed"') !== false || stripos((string)$resp, 'InvalidSenderId') !== false) {
                return ['ok' => false, 'error' => trim((string)$resp)];
            }
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'Gateway HTTP ' . $code . ': ' . substr((string)$resp, 0, 300)];
    }
}

if (!function_exists('sms_send')) {
    /**
     * Send one SMS and record the attempt in sms_logs. Never throws.
     *
     * @param string $phone   Recipient phone (any local/international format)
     * @param string $message Message body
     * @param array  $opts    { recipient_name, created_by, log (default true) }
     * @return array{success:bool,message:string,sms_id:?int,status:string}
     */
    function sms_send($phone, $message, array $opts = []): array
    {
        global $pdo;

        $message = trim((string)$message);
        $name    = $opts['recipient_name'] ?? null;
        $created_by = $opts['created_by'] ?? ($_SESSION['user_id'] ?? null);
        $do_log  = $opts['log'] ?? true;

        $msisdn = sms_normalize_phone($phone);
        if ($msisdn === '' || strlen($msisdn) < 10) {
            return ['success' => false, 'message' => 'Invalid phone number.', 'sms_id' => null, 'status' => 'failed'];
        }
        if ($message === '') {
            return ['success' => false, 'message' => 'Message is required.', 'sms_id' => null, 'status' => 'failed'];
        }

        $cfg = ($pdo instanceof PDO)
            ? sms_get_config($pdo)
            : ['enabled' => false, 'has_gateway' => false, 'provider' => ''];

        if (!$cfg['enabled']) {
            return ['success' => false, 'message' => 'SMS sending is disabled in settings.', 'sms_id' => null, 'status' => 'failed'];
        }

        $status = 'failed';
        $error  = null;
        if (!empty($cfg['has_gateway'])) {
            $res = sms_send_via_gateway($cfg, $msisdn, $message);
            $status = $res['ok'] ? 'sent' : 'failed';
            $error  = $res['error'];
        } else {
            $error = 'No SMS gateway is set up yet. Ask an administrator to configure SMS Settings.';
        }

        $sms_id = null;
        if ($do_log && $pdo instanceof PDO) {
            try {
                sms_ensure_logs_table($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO sms_logs
                        (recipient_phone, recipient_name, message, status, provider, error_message, segments, sent_at, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $msisdn, $name, $message, $status, $cfg['provider'] ?? null, $error,
                    sms_segments($message), $status === 'sent' ? date('Y-m-d H:i:s') : null, $created_by,
                ]);
                $sms_id = (int)$pdo->lastInsertId();
            } catch (Throwable $e) {
                error_log('sms_send log: ' . $e->getMessage());
            }
        }

        return [
            'success' => $status === 'sent',
            'message' => $status === 'sent' ? 'SMS sent.' : ($error ?? 'SMS could not be sent.'),
            'sms_id'  => $sms_id,
            'status'  => $status,
        ];
    }
}

if (!function_exists('send_sms')) {
    /**
     * Backward-compatible wrapper (older callers use send_sms($phone,$message)).
     *
     * @param string $phone
     * @param string $message
     * @return array{success:bool,message:string}
     */
    function send_sms($phone, $message): array
    {
        $r = sms_send($phone, $message);
        return ['success' => $r['success'], 'message' => $r['message']];
    }
}
