<?php
/**
 * includes/route_guard.php — which file the router's fallback may load.
 * Pure: the file system only, no session, no output. Tested on its own.
 */

if (!function_exists('vk_route_endpoint_file')) {
    /**
     * The PHP endpoint file a fallback URL names, or null. Only a real .php file
     * inside actions/, ajax/ or api/ (at any depth, as before), resolved on disk
     * and still inside the app: never a non-PHP file, never a path that climbs out.
     */
    function vk_route_endpoint_file(string $cleanUri, string $rootDir): ?string
    {
        if ($cleanUri === '' || str_contains($cleanUri, '..') || str_contains($cleanUri, "\0")
            || !preg_match('#(^|/)(actions|ajax|api)/#', $cleanUri)) {
            return null;
        }
        $root = realpath($rootDir);
        if ($root === false) {
            return null;
        }
        foreach ([$cleanUri, $cleanUri . '.php'] as $rel) {
            $real = realpath($root . '/' . ltrim($rel, '/'));
            if ($real !== false && is_file($real) && strtolower(pathinfo($real, PATHINFO_EXTENSION)) === 'php'
                && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        return null;
    }
}
