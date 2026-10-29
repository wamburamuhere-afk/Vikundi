<?php
/**
 * includes/features.php — which optional features a group has.
 *
 * Set per group by the super-admin (the panel's Features form → request queue →
 * bin/provision_tenant.php --features), stored in vikundi_control.tenants.features
 * as JSON, e.g. {"shares":false,"dividends":false}. Group leaders cannot change it:
 * it is what the group signed up for, not a group setting.
 *
 * A feature that is not listed is ON. So a group with no features saved, a
 * registry without the column yet, and single-site mode (the test site, local
 * development) all behave exactly as before.
 *
 * A feature that is off is gone for everyone in the group, leaders included:
 * its menu links, its pages (vk_require_feature) and its settings fields.
 * Nothing is deleted; switching it back on brings it all back.
 */

if (!function_exists('vk_feature_catalog')) {
    /** The features that can be switched off, with their panel labels. */
    function vk_feature_catalog(): array
    {
        return [
            'shares'    => ['label' => 'Shares',    'help' => 'Share price, the Shares contribution type, the Shares Register and share balances.'],
            'dividends' => ['label' => 'Dividends', 'help' => 'The Dividends page and each member\'s dividend on My Home.'],
        ];
    }
}

if (!function_exists('vk_feature_setting_keys')) {
    /** The group_settings keys that belong to each feature: hidden and not saved while it is off. */
    function vk_feature_setting_keys(): array
    {
        return [
            'shares'    => ['share_price', 'shares_from_monthly'],
            'dividends' => ['dividend_distribute_pct'],
        ];
    }
}

if (!function_exists('vk_feature_hidden_settings')) {
    /** The group_settings keys of the features this group does not have. */
    function vk_feature_hidden_settings(): array
    {
        $hidden = [];
        foreach (vk_feature_setting_keys() as $feature => $keys) {
            if (!vk_feature($feature)) {
                $hidden = array_merge($hidden, $keys);
            }
        }
        return $hidden;
    }
}

if (!function_exists('vk_features_parse')) {
    /**
     * A stored features value → [key => bool] for every known feature.
     * Unknown keys are dropped; anything that is not exactly false counts as on.
     */
    function vk_features_parse(?string $json): array
    {
        $stored = ($json !== null && $json !== '') ? json_decode($json, true) : null;
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach (array_keys(vk_feature_catalog()) as $key) {
            $out[$key] = ($stored[$key] ?? true) !== false;
        }
        return $out;
    }
}

if (!function_exists('vk_features_encode')) {
    /** [key => bool] → the JSON the registry stores (known keys only, every one listed). */
    function vk_features_encode(array $features): string
    {
        $out = [];
        foreach (array_keys(vk_feature_catalog()) as $key) {
            $out[$key] = ($features[$key] ?? true) !== false;
        }
        return json_encode($out);
    }
}

if (!function_exists('vk_features_from_spec')) {
    /**
     * The provisioner's --set value, "shares:off,dividends:on", → [key => bool],
     * or a string explaining what is wrong. Every known feature must be given once.
     */
    function vk_features_from_spec(string $spec): array|string
    {
        $known = array_keys(vk_feature_catalog());
        $out = [];
        foreach (explode(',', $spec) as $part) {
            if (!preg_match('/^([a-z_]+):(on|off)$/', trim($part), $m)) {
                return "Bad value '" . trim($part) . "': use name:on or name:off.";
            }
            if (!in_array($m[1], $known, true)) {
                return "Unknown feature '{$m[1]}'.";
            }
            if (array_key_exists($m[1], $out)) {
                return "Feature '{$m[1]}' is given twice.";
            }
            $out[$m[1]] = $m[2] === 'on';
        }
        if ($missing = array_diff($known, array_keys($out))) {
            return 'Missing: ' . implode(', ', $missing) . '.';
        }
        return $out;
    }
}

if (!function_exists('vk_features_to_spec')) {
    /** [key => bool] → "shares:off,dividends:on" (the inverse of vk_features_from_spec). */
    function vk_features_to_spec(array $features): string
    {
        $parts = [];
        foreach (array_keys(vk_feature_catalog()) as $key) {
            $parts[] = $key . ':' . (($features[$key] ?? true) !== false ? 'on' : 'off');
        }
        return implode(',', $parts);
    }
}

if (!function_exists('vk_features_current')) {
    /** This request's group's features; all on in single-site mode. */
    function vk_features_current(): array
    {
        $row = function_exists('vk_tenant_current') ? vk_tenant_current() : null;
        return vk_features_parse(is_array($row) ? ($row['features'] ?? null) : null);
    }
}

if (!function_exists('vk_feature')) {
    /** Does this group have the feature? Unknown names are treated as on. */
    function vk_feature(string $key): bool
    {
        return vk_features_current()[$key] ?? true;
    }
}

if (!function_exists('vk_require_feature')) {
    /**
     * Stop a page or endpoint of a feature the group does not have. Answers 404,
     * as if it did not exist: JSON for AJAX/action callers, a short page otherwise.
     */
    function vk_require_feature(string $key, bool $json = false): void
    {
        if (vk_feature($key)) {
            return;
        }
        http_response_code(404);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'This feature is not available for this group.']);
            exit;
        }
        $sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
        $msg = $sw ? 'Huduma hii haipatikani kwa kikundi hiki.' : 'This feature is not available for this group.';
        $back = $sw ? 'Rudi mwanzo' : 'Back to the dashboard';
        $home = function_exists('getUrl') ? getUrl('dashboard') : '/';
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . htmlspecialchars($msg) . '</title></head><body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem">'
           . '<h1 style="font-size:1.25rem">' . htmlspecialchars($msg) . '</h1>'
           . '<p><a href="' . htmlspecialchars($home) . '">' . htmlspecialchars($back) . '</a></p></body></html>';
        exit;
    }
}
