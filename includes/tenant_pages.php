<?php
/**
 * includes/tenant_pages.php — the two pages a request gets when it does not reach
 * a group: not found (404) and temporarily offline (503).
 *
 * Deliberately standalone: no header.php, no database, no session. These run
 * before any group is known, so they must not touch anything that belongs to one.
 * An API or AJAX caller gets the same answer as JSON.
 */

if (!function_exists('vk_tenant_page_text')) {
    /** [status, English title, English body, Swahili title, Swahili body]. */
    function vk_tenant_page_text(string $decision): array
    {
        return $decision === 'suspended'
            ? [503, 'Temporarily offline', 'This group’s Vikundi site is offline for now. Please contact BJP Technologies.',
                    'Haipatikani kwa sasa', 'Tovuti ya Vikundi ya kikundi hiki haipatikani kwa sasa. Tafadhali wasiliana na BJP Technologies.']
            : [404, 'Group not found', 'There is no Vikundi group at this address. Please check the address you were given.',
                    'Kikundi hakijapatikana', 'Hakuna kikundi cha Vikundi kwenye anwani hii. Tafadhali hakikisha anwani uliyopewa.'];
    }
}

if (!function_exists('vk_tenant_wants_json')) {
    function vk_tenant_wants_json(array $server): bool
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        return (bool) preg_match('#/(api|ajax|actions)/#', $uri)
            || str_contains((string) ($server['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('vk_tenant_render_page')) {
    function vk_tenant_render_page(string $decision, ?array $row = null): void
    {
        [$status, , $bodyEn] = vk_tenant_page_text($decision);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store');
            if ($status === 503) {
                header('Retry-After: 86400');
            }
        }

        if (vk_tenant_wants_json($_SERVER)) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['status' => 'error', 'success' => false, 'message' => $bodyEn]);
            return;
        }

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo vk_tenant_page_html($decision, $row);
    }
}

if (!function_exists('vk_tenant_page_html')) {
    /** The standalone HTML page, English then Swahili. */
    function vk_tenant_page_html(string $decision, ?array $row = null): string
    {
        [, $titleEn, $bodyEn, $titleSw, $bodySw] = vk_tenant_page_text($decision);
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        // The group's name is shown on the offline page only: a not-found page never
        // confirms or denies which names exist.
        $name = ($decision === 'suspended' && !empty($row['name'])) ? '<p class="g">' . $e((string) $row['name']) . '</p>' : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . $e($titleEn) . ' · Vikundi</title>'
            . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f5f7fa;color:#1b2733;font:16px/1.5 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:24px}'
            . '.c{max-width:520px;background:#fff;border:1px solid #e4e9ef;border-radius:12px;padding:32px 36px}'
            . 'h1{margin:0 0 8px;font-size:22px;color:#0f4c81}p{margin:0 0 12px}.g{font-weight:700}'
            . '.sw{border-top:1px solid #e4e9ef;margin-top:20px;padding-top:16px;color:#5b6b7a}'
            . '.f{margin-top:20px;font-size:13px;color:#5b6b7a}</style></head><body><div class="c">'
            . $name
            . '<h1>' . $e($titleEn) . '</h1><p>' . $e($bodyEn) . '</p>'
            . '<div class="sw"><h1>' . $e($titleSw) . '</h1><p>' . $e($bodySw) . '</p></div>'
            . '<p class="f">BJP Technologies (T) Ltd · +255 764 764 011 · info@bjptechnologies.co.tz</p>'
            . '</div></body></html>';
    }
}
