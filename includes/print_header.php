<?php
/**
 * PrintHeader — centralised print-page header renderer.
 *
 * Usage in every standalone print page:
 *
 *   In <head>:         <?php PrintHeader::css(); ?>
 *   In <body>:         <?php PrintHeader::render($pdo, 'DOCUMENT TITLE', 'REF #123'); ?>
 *
 * Reads group_logo and group_name from group_settings once per request (cached).
 * Embeds the logo as base64 so it prints correctly without network access.
 */
class PrintHeader
{
    private static ?array $cache   = null;
    private static bool   $cssOut  = false;

    // ── Private helpers ──────────────────────────────────────────────────────

    private static function settings(PDO $pdo): array
    {
        if (self::$cache === null) {
            self::$cache = $pdo
                ->query("SELECT setting_key, setting_value FROM group_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        return self::$cache;
    }

    private static function logoSrc(string $logoFile): string
    {
        $path = ROOT_DIR . '/assets/images/' . $logoFile;
        if (file_exists($path) && is_readable($path)) {
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg'
                  : ($ext === 'gif'                 ? 'image/gif'
                  :                                   'image/png');
            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
        }
        return htmlspecialchars(getUrl('assets/images/' . $logoFile));
    }

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Output the CSS block. Call once inside <head> — before </head>.
     */
    public static function css(): void
    {
        echo <<<CSS
<style>
/* ── Vikundi Print Header ────────────────────────────────────── */
.vk-print-header {
    text-align: center;
    margin-bottom: 20px;
    padding-bottom: 14px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.vk-ph-logo {
    display: block;
    margin: 0 auto 10px;
    max-height: 80px;
    max-width: 200px;
    width: auto;
    object-fit: contain;
}
.vk-ph-org {
    font-size: 26px;
    font-weight: 800;
    color: #0d6efd;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 3px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.vk-ph-sys {
    font-size: 9.5px;
    color: #6c757d;
    letter-spacing: 0.3px;
    margin-bottom: 9px;
}
.vk-ph-title {
    font-size: 13px;
    font-weight: 800;
    color: #1a252f;
    text-transform: uppercase;
    letter-spacing: 2px;
    margin-bottom: 4px;
}
.vk-ph-ref {
    font-size: 10px;
    color: #495057;
    font-weight: 600;
    margin-bottom: 8px;
}
.vk-ph-rule {
    border: none;
    border-top: 2px solid #0d6efd;
    width: 100%;
    margin: 0 auto;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
/* ─────────────────────────────────────────────────────────────── */
</style>
CSS;
    }

    /**
     * CSS string for injection into a DataTables popup window <head>.
     * Use in JS: $(win.document.head).append(`<?php echo PrintHeader::popupCss(); ?>`);
     */
    public static function popupCss(): string
    {
        return '<style>'
            . '.vk-print-header{text-align:center;margin-bottom:20px;padding-bottom:14px;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.vk-ph-logo{display:block;margin:0 auto 10px;max-height:80px;max-width:200px;width:auto;object-fit:contain}'
            . '.vk-ph-org{font-size:26px;font-weight:800;color:#0d6efd;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.vk-ph-sys{font-size:9.5px;color:#6c757d;letter-spacing:.3px;margin-bottom:9px}'
            . '.vk-ph-title{font-size:13px;font-weight:800;color:#1a252f;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px}'
            . '.vk-ph-ref{font-size:10px;color:#495057;font-weight:600;margin-bottom:8px}'
            . '.vk-ph-rule{border:none;border-top:2px solid #0d6efd;width:100%;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '</style>';
    }

    /**
     * Render the print header block.
     *
     * @param PDO    $pdo   Active DB connection
     * @param string $title Document type heading  e.g. "PETTY CASH VOUCHER"
     * @param string $ref   Optional reference line e.g. "REF #PCV-0012"
     */
    public static function render(PDO $pdo, string $title, string $ref = ''): void
    {
        if (!self::$cssOut) {
            self::css();
            self::$cssOut = true;
        }
        $s          = self::settings($pdo);
        $group_name = htmlspecialchars($s['group_name'] ?? 'VIKUNDI');
        $logo_src   = self::logoSrc($s['group_logo'] ?? 'logo1.png');
        $title_html = htmlspecialchars($title);
        $ref_html   = $ref !== ''
                    ? '<div class="vk-ph-ref">' . htmlspecialchars($ref) . '</div>'
                    : '';

        echo <<<HTML

<div class="vk-print-header">
    <img src="{$logo_src}" alt="Logo" class="vk-ph-logo">
    <div class="vk-ph-org">{$group_name}</div>
    <div class="vk-ph-sys">VICOBA Group Management System</div>
    <div class="vk-ph-title">{$title_html}</div>
    {$ref_html}
    <div class="vk-ph-rule"></div>
</div>

HTML;
    }
}
