<?php
/**
 * includes/document_sanitizer.php
 * -------------------------------
 * Server-side sanitiser for rich-text document bodies authored in Summernote.
 * The editor emits raw HTML which is stored and later rendered to other users,
 * so it MUST be sanitised to prevent stored XSS.
 *
 * Uses HTMLPurifier when the composer dependency is installed; otherwise falls
 * back to a dependency-free DOMDocument allow-list so the feature works even on
 * hosts where `composer install` has not pulled the package. Both paths keep the
 * same conservative set of formatting tags and strip scripts, event handlers and
 * dangerous URLs.
 */

$__vk_autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__vk_autoload)) {
    require_once $__vk_autoload;
}

if (!function_exists('vk_sanitize_document_html')) {
    /**
     * Return a safe subset of the given HTML.
     */
    function vk_sanitize_document_html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        // Prefer HTMLPurifier when the dependency is present.
        if (class_exists('HTMLPurifier_Config')) {
            return vk_htmlpurifier_sanitize($html);
        }
        // Dependency-free fallback.
        return vk_dom_sanitize_html($html);
    }
}

if (!function_exists('vk_document_allowed_tags')) {
    /** tag => list of attributes kept on that tag. */
    function vk_document_allowed_tags(): array
    {
        // `style` is allowed on block elements too, because Summernote stores
        // text alignment (and font size/colour) as inline styles on p / headings
        // / list items. The value is still filtered to the safe CSS property
        // allow-list, so keeping it here does not widen the attack surface.
        return [
            'p' => ['style'], 'br' => [], 'span' => ['style'], 'div' => ['style'],
            'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [], 'sub' => [], 'sup' => [],
            'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'], 'h5' => ['style'], 'h6' => ['style'],
            'blockquote' => ['style'], 'pre' => ['style'], 'code' => [], 'hr' => [],
            'ul' => ['style'], 'ol' => ['style'], 'li' => ['style'],
            'a' => ['href', 'title', 'target'],
            'table' => ['style'], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => ['style'],
            'th' => ['colspan', 'rowspan', 'style'], 'td' => ['colspan', 'rowspan', 'style'],
            'img' => ['src', 'alt', 'width', 'height', 'style'],
        ];
    }
}

if (!function_exists('vk_document_allowed_styles')) {
    function vk_document_allowed_styles(): array
    {
        return [
            'color', 'background-color', 'text-align', 'text-decoration', 'font-weight',
            'font-style', 'font-size', 'width', 'height', 'margin', 'padding', 'vertical-align',
        ];
    }
}

if (!function_exists('vk_htmlpurifier_sanitize')) {
    function vk_htmlpurifier_sanitize(string $html): string
    {
        static $purifier = null;
        if ($purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('HTML.Allowed',
                'p[style],br,span[style],div[style],'
                . 'b,strong,i,em,u,s,strike,sub,sup,'
                . 'h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],blockquote[style],pre[style],code,hr,'
                . 'ul[style],ol[style],li[style],'
                . 'a[href|title|target],'
                . 'table[style],thead,tbody,tfoot,tr[style],'
                . 'th[colspan|rowspan|style],td[colspan|rowspan|style],'
                . 'img[src|alt|width|height|style]'
            );
            $config->set('CSS.AllowedProperties', implode(',', vk_document_allowed_styles()));
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('AutoFormat.RemoveEmpty', true);
            $config->set('Cache.SerializerPath', sys_get_temp_dir());
            $purifier = new HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }
}

if (!function_exists('vk_dom_sanitize_html')) {
    /**
     * Dependency-free allow-list sanitiser using DOMDocument. Removes disallowed
     * tags (dangerous ones dropped, others unwrapped to keep their text), strips
     * event handlers, unsafe URLs and non-allowed inline styles.
     */
    function vk_dom_sanitize_html(string $html): string
    {
        $allowed    = vk_document_allowed_tags();
        $styleProps = vk_document_allowed_styles();
        $structural = ['html', 'head', 'body', 'meta', 'title'];
        $dangerous  = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'form', 'input', 'button', 'textarea', 'base', 'svg'];

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html . '</body>',
            LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }
        $xpath = new DOMXPath($dom);

        // Pass 1: remove dangerous tags entirely, unwrap other disallowed tags.
        // Restart after each change since the node list mutates.
        $again = true;
        while ($again) {
            $again = false;
            foreach ($xpath->query('//*') as $el) {
                $tag = strtolower($el->localName);
                if (in_array($tag, $structural, true) || isset($allowed[$tag])) {
                    continue;
                }
                if (in_array($tag, $dangerous, true)) {
                    $el->parentNode->removeChild($el);
                } else {
                    while ($el->firstChild) {
                        $el->parentNode->insertBefore($el->firstChild, $el);
                    }
                    $el->parentNode->removeChild($el);
                }
                $again = true;
                break;
            }
        }

        // Pass 2: scrub attributes on every remaining element.
        foreach ($xpath->query('//*') as $el) {
            $tag = strtolower($el->localName);
            if (!isset($allowed[$tag])) {
                continue; // structural wrappers
            }
            $keep = $allowed[$tag];
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                if (strpos($name, 'on') === 0 || !in_array($name, $keep, true)) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }
                if ($name === 'href' || $name === 'src') {
                    if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', trim((string) $attr->nodeValue))) {
                        $el->removeAttribute($attr->nodeName);
                    }
                } elseif ($name === 'target') {
                    if ($attr->nodeValue !== '_blank') {
                        $el->removeAttribute($attr->nodeName);
                    }
                } elseif ($name === 'style') {
                    $el->setAttribute('style', vk_filter_inline_style((string) $attr->nodeValue, $styleProps));
                }
            }
        }

        // Serialise the body's inner HTML.
        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }
        return trim($out);
    }
}

if (!function_exists('vk_filter_inline_style')) {
    /** Keep only allow-listed CSS properties with safe values. */
    function vk_filter_inline_style(string $style, array $allowedProps): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (strpos($decl, ':') === false) {
                continue;
            }
            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val  = trim($val);
            if ($val === '' || !in_array($prop, $allowedProps, true)) {
                continue;
            }
            if (preg_match('/url\s*\(|expression\s*\(|javascript:/i', $val)) {
                continue;
            }
            $out[] = $prop . ': ' . $val;
        }
        return implode('; ', $out);
    }
}
