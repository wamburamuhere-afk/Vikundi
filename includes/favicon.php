<?php
/**
 * includes/favicon.php — the browser-tab and home-screen icon tags, one copy for every
 * page that writes its own <head>. The icon is the square mark from the Vikundi logo
 * (assets/images/logo1.png): favicon.ico at the web root (browsers also ask for it on
 * their own), PNGs in assets/images/icons/.
 */

if (!function_exists('vk_favicon_tags')) {
    function vk_favicon_tags(): string
    {
        // getUrl() keeps the links right when the app runs in a sub-folder.
        $u = static fn(string $p): string => function_exists('getUrl') ? getUrl($p) : '/' . $p;
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<link rel="icon" href="' . $h($u('favicon.ico')) . '" sizes="any">' . "\n"
             . '<link rel="icon" type="image/png" sizes="32x32" href="' . $h($u('assets/images/icons/icon-32.png')) . '">' . "\n"
             . '<link rel="icon" type="image/png" sizes="192x192" href="' . $h($u('assets/images/icons/icon-192.png')) . '">' . "\n"
             . '<link rel="apple-touch-icon" href="' . $h($u('assets/images/icons/apple-touch-icon.png')) . '">' . "\n";
    }
}
