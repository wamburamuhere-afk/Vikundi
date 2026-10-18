<?php
/**
 * superadmin/public/index.php — the super-admin panel's only entry point.
 * Apache's admin site sends every request that is not a file here
 * (FallbackResource /index.php).
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/data.php';

$route = vk_admin_route((string) ($_SERVER['REQUEST_URI'] ?? '/'));
if ($route === null) {
    if (!admin_current()) {
        redirect('/login');
    }
    not_found();
}
[$page, $param] = $route;

// Every page but the login page needs a signed-in super-admin.
if ($page !== 'login') {
    $me = admin_require();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

require __DIR__ . '/../src/pages/' . $page . '.php';
