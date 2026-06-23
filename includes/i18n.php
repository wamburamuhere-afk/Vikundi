<?php
/**
 * includes/i18n.php
 * -----------------
 * Lightweight, file-based internationalization (no database).
 *
 * Translations live in lang/<code>.json as flat "key" => "string" maps.
 * Supported languages: en (default) and sw.
 *
 * Usage:
 *   t('login.username')                       → translated string for current language
 *   t('dashboard.hours_ago', ['n' => 3])      → "{n}" placeholders interpolated from $vars
 *   et('login.username')                      → htmlspecialchars(t(...)) for HTML output
 *   current_lang()                            → 'en' | 'sw'
 *
 * The active language comes from $_SESSION['preferred_language'] and can be
 * switched on ANY page with ?lang=en or ?lang=sw (session only — never written
 * to the database here; the profile page is what persists a user's choice).
 */

if (!defined('I18N_DEFAULT_LANG')) define('I18N_DEFAULT_LANG', 'en');
if (!defined('I18N_SUPPORTED'))    define('I18N_SUPPORTED', ['en', 'sw']);

// Global, session-scoped language switch. Works on login (pre-auth) and every
// authed page because this file is included from roots.php.
if (isset($_GET['lang'])
    && in_array($_GET['lang'], I18N_SUPPORTED, true)
    && session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['preferred_language'] = $_GET['lang'];
}

/**
 * Current active language code — always one of I18N_SUPPORTED.
 */
function current_lang(): string
{
    $lang = $_SESSION['preferred_language'] ?? I18N_DEFAULT_LANG;
    return in_array($lang, I18N_SUPPORTED, true) ? $lang : I18N_DEFAULT_LANG;
}

/**
 * Load and cache a language file once per request.
 *
 * @return array<string, string>
 */
function i18n_load(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $base = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
    $file = $base . '/lang/' . $lang . '.json';
    $data = [];
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    return $cache[$lang] = $data;
}

/**
 * Translate a key for the current language.
 *
 * Fallback order: current language → English → the key itself, so the UI never
 * renders blank even if a translation is missing.
 *
 * @param array<string, scalar> $vars Values for {placeholder} interpolation.
 */
function t(string $key, array $vars = []): string
{
    $lang = current_lang();
    $str  = i18n_load($lang)[$key]
        ?? i18n_load(I18N_DEFAULT_LANG)[$key]
        ?? $key;

    foreach ($vars as $name => $value) {
        $str = str_replace('{' . $name . '}', (string) $value, $str);
    }
    return $str;
}

/**
 * Same as t() but HTML-escaped — use this when echoing into markup.
 *
 * @param array<string, scalar> $vars Values for {placeholder} interpolation.
 */
function et(string $key, array $vars = []): string
{
    return htmlspecialchars(t($key, $vars), ENT_QUOTES, 'UTF-8');
}
