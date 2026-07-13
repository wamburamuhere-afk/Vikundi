<?php
/**
 * includes/document_sanitizer.php
 * -------------------------------
 * Server-side sanitiser for rich-text document bodies authored in Summernote.
 * The editor emits raw HTML which is stored and later rendered to other users,
 * so it MUST be sanitised to prevent stored XSS. Uses HTMLPurifier with a
 * conservative allow-list covering the formatting the toolbar can produce.
 *
 * The purifier only runs on save (not on render), so it adds no runtime weight
 * to normal page loads.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('vk_sanitize_document_html')) {
    /**
     * Return a safe subset of the given HTML — formatting tags, lists, tables,
     * links and basic inline colour/alignment styles are kept; scripts, event
     * handlers and dangerous URLs are stripped.
     */
    function vk_sanitize_document_html(string $html): string
    {
        static $purifier = null;
        if ($purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

            // Tags/attributes the Summernote toolbar can produce.
            $config->set('HTML.Allowed',
                'p,br,span[style],div[style],'
                . 'b,strong,i,em,u,s,strike,sub,sup,'
                . 'h1,h2,h3,h4,h5,h6,blockquote,pre,code,hr,'
                . 'ul,ol,li,'
                . 'a[href|title|target],'
                . 'table,thead,tbody,tfoot,tr,'
                . 'th[colspan|rowspan|style],td[colspan|rowspan|style],'
                . 'img[src|alt|width|height|style]'
            );

            // Only safe inline styles (colour, alignment, basic text formatting).
            $config->set('CSS.AllowedProperties',
                'color,background-color,text-align,text-decoration,font-weight,'
                . 'font-style,font-size,width,height,margin,padding,vertical-align'
            );

            // Links/images restricted to safe schemes (no data: or javascript:).
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('AutoFormat.RemoveEmpty', true);

            // Definition cache in the system temp dir — no writable path needed in the repo.
            $config->set('Cache.SerializerPath', sys_get_temp_dir());

            $purifier = new HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }
}
