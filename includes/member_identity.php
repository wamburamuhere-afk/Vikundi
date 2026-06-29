<?php
// includes/member_identity.php
//
// Shared member-identity helpers used by both registration paths
// (actions/add_member.php = admin-created, actions/process_registration.php =
// self-registration) and the email backfill CLI. Keeping the rules in one place
// kills the username logic that was duplicated across the two action files and
// makes the pure parts testable without a database.

if (!function_exists('vk_build_username')) {
    /**
     * Deterministic base username: first initial + last name, lowercased, with
     * spaces and punctuation stripped. e.g. ("John", "Doe") -> "jdoe".
     * Uniqueness is the caller's job — see vk_unique_username().
     */
    function vk_build_username(string $first_name, string $last_name): string {
        $first_initial = strtolower(substr(trim($first_name), 0, 1));
        $last_slug     = strtolower(preg_replace('/[^a-z0-9]/i', '', $last_name));
        return $first_initial . $last_slug;
    }
}

if (!function_exists('vk_normalize_email_domain')) {
    /**
     * Reduce a raw host (or full URL) to a bare mail domain: strip scheme and
     * path, a leading "www.", and any ":port".
     * e.g. "https://www.vikundi.co.tz:8080/x" -> "vikundi.co.tz".
     * Returns '' when nothing usable remains.
     */
    function vk_normalize_email_domain(?string $host): string {
        $host = trim((string) $host);
        if ($host === '') {
            return '';
        }
        if (strpos($host, '://') !== false) {            // a full URL slipped in
            $host = parse_url($host, PHP_URL_HOST) ?: $host;
        } else {
            $host = explode('/', $host)[0];              // drop any path
        }
        $host = preg_replace('/:\d+$/', '', $host);       // strip :port
        $host = preg_replace('/^www\./i', '', $host);     // strip leading www.
        return strtolower(trim($host));
    }
}

if (!function_exists('vk_detect_request_host')) {
    /** The current web request host (normalised), or '' on CLI / when absent. */
    function vk_detect_request_host(): string {
        return vk_normalize_email_domain(
            $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')
        );
    }
}

if (!function_exists('vk_build_member_email')) {
    /** Build "username@domain", normalising both parts. */
    function vk_build_member_email(string $username, string $domain): string {
        $domain = vk_normalize_email_domain($domain);
        if ($domain === '') {
            $domain = 'localhost';
        }
        return strtolower(trim($username)) . '@' . $domain;
    }
}

if (!function_exists('vk_member_email_domain')) {
    /**
     * Resolve the domain to use for auto-generated member emails, in order:
     *   1. the `member_email_domain` setting, if an admin set a non-empty
     *      override (e.g. host on app.vikundi.co.tz but mail @vikundi.co.tz);
     *   2. the live web request host — also persisted to
     *      `member_email_domain_detected` so the CLI backfill / cron (which
     *      have no HTTP host) can reuse the same value;
     *   3. the last detected host persisted previously;
     *   4. 'localhost' as a final safety net.
     *
     * No hardcoded production default — emails follow wherever the site is
     * hosted, so they line up with the mailboxes created in cPanel.
     */
    function vk_member_email_domain(PDO $pdo): string {
        $get = function (string $key) use ($pdo): string {
            $st = $pdo->prepare("SELECT setting_value FROM group_settings WHERE setting_key = ?");
            $st->execute([$key]);
            return vk_normalize_email_domain((string) ($st->fetchColumn() ?: ''));
        };

        $override = $get('member_email_domain');
        if ($override !== '') {
            return $override;
        }

        $live = vk_detect_request_host();
        if ($live !== '') {
            $up = $pdo->prepare(
                "INSERT INTO group_settings (setting_key, setting_value)
                 VALUES ('member_email_domain_detected', :v)
                 ON DUPLICATE KEY UPDATE setting_value = :v2"
            );
            $up->execute([':v' => $live, ':v2' => $live]);
            return $live;
        }

        $detected = $get('member_email_domain_detected');
        if ($detected !== '') {
            return $detected;
        }

        return 'localhost';
    }
}

if (!function_exists('vk_unique_username')) {
    /**
     * Return a username free in the users table, starting from $base and
     * appending 1, 2, 3, … until one is available. (Replaces the two slightly
     * different inline loops the action files used to carry.)
     */
    function vk_unique_username(PDO $pdo, string $base): string {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $username = $base;
        $i = 1;
        $stmt->execute([$username]);
        while ((int) $stmt->fetchColumn() > 0) {
            $username = $base . $i;
            $stmt->execute([$username]);
            $i++;
        }
        return $username;
    }
}
