<?php
/**
 * VICoBA Activity Logger
 * Kurekodi kila tendo: Login, View, Create, Edit, Delete
 *
 * Matumizi:
 *   logCreate('Members', 'John Doe', 'MEMBER#5');
 *   logUpdate('Contributions', 'TZS 5,000 - John Doe', 'CONTRIB#12');
 *   logDelete('Fines', 'Fine #3 - Late payment', 'FINE#3');
 */

if (!function_exists('logActivity')) {
    function logActivity(
        string $action,           // e.g. "Created", "Updated", "Deleted", "Viewed", "Login"
        string $module,           // e.g. "Members", "Contributions", "Loans", "Auth"
        string $description,      // Full human-readable sentence
        string $reference = '',   // e.g. "MEMBER#5", "CONTRIB#23", "LOAN#8"
        int $user_id = 0
    ): void {
        global $pdo;
        if (!$pdo) return;

        try {
            if ($user_id === 0 && isset($_SESSION['user_id'])) {
                $user_id = (int) $_SESSION['user_id'];
            }
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

            $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, module, description, reference, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([$user_id, $action, $module, $description, $reference, $ip, $ua]);
        } catch (Exception $e) {
            error_log("ActivityLogger: " . $e->getMessage());
        }
    }
}

// ── Login / Logout ──────────────────────────────────────────────────────────────
if (!function_exists('logLogin')) {
    function logLogin(int $user_id, string $fullname): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "$fullname ameingia kwenye mfumo"
            : "$fullname logged into the system";
        logActivity('Login', 'Auth', $desc, "USER#$user_id", $user_id);
    }
}

if (!function_exists('logLogout')) {
    function logLogout(int $user_id, string $fullname): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "$fullname ametoka mfumoni"
            : "$fullname logged out of the system";
        logActivity('Logout', 'Auth', $desc, "USER#$user_id", $user_id);
    }
}

// ── CRUD Actions ───────────────────────────────────────────────────────────────
if (!function_exists('logCreate')) {
    /**
     * @param string $module   e.g. "Members", "Contributions", "Loans"
     * @param string $itemName e.g. "John Doe", "TZS 5,000 for Pendo Mlyangali"
     * @param string $ref      e.g. "MEMBER#5", "CONTRIB#12"
     */
    function logCreate(string $module, string $itemName, string $ref = '', int $user_id = 0): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "Aliunda rekodi mpya kwenye $module: $itemName"
            : "Created new $module record: $itemName";
        logActivity('Created', $module, $desc, $ref, $user_id);
    }
}

if (!function_exists('logUpdate')) {
    function logUpdate(string $module, string $itemName, string $ref = '', int $user_id = 0): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "Alibadilisha rekodi kwenye $module: $itemName"
            : "Updated $module record: $itemName";
        logActivity('Updated', $module, $desc, $ref, $user_id);
    }
}

if (!function_exists('logDelete')) {
    function logDelete(string $module, string $itemName, string $ref = '', int $user_id = 0): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "Alifuta rekodi kutoka $module: $itemName"
            : "Deleted $module record: $itemName";
        logActivity('Deleted', $module, $desc, $ref, $user_id);
    }
}

// ── Page View ───────────────────────────────────────────────────────────────────
if (!function_exists('logView')) {
    function logView(string $pageName, string $ref = '', int $user_id = 0): void {
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $desc = $lang === 'sw'
            ? "Alitazama ukurasa wa: $pageName"
            : "Viewed the $pageName page";
        logActivity('Viewed', 'Navigation', $desc, $ref ?: $pageName, $user_id);
    }
}
