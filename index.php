<?php
/**
 * Main Entry Point
 * Handles routing and authentication
 */

// Include routing and core functionality
require_once __DIR__ . '/roots.php';

// Check if this is a direct access to index.php (root URL)
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$clean_uri = trim(strtok($request_uri, '?'), '/');

// A group's uploaded logo is linked as /assets/images/<file> everywhere, as it
// always has been. On a single site the file is really there and Apache serves
// it before PHP runs. On the shared server it lives in the group's own storage,
// so the request falls through to here and is served from that group's folder.
if (preg_match('#(?:^|/)assets/images/(group_logo_[A-Za-z0-9_-]{1,80}\.(png|jpe?g|gif|webp))$#', $clean_uri, $m)) {
    $logo = vk_storage_path('assets/images/' . $m[1]);
    if (is_file($logo)) {
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $types[strtolower($m[2])]);
        header('Content-Length: ' . filesize($logo));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        readfile($logo);
        exit;
    }
}

// If accessing root URL directly
if (empty($clean_uri) || $clean_uri === 'index.php') {
    // Check if user is logged in
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        // User is logged in, redirect to dashboard
        redirectTo('dashboard');
    } else {
        // User is not logged in, redirect to login
        redirectTo('login');
    }
} else {
    // Handle the route through the routing system
    $handled = handleRoute();
    
    // If route not found, show branded 404 alert
    if (!$handled) {
        http_response_code(404);
        require_once ROOT_DIR . '/header.php';
        
        $lang = $_SESSION['preferred_language'] ?? 'en';
        $title = ($lang === 'sw') ? 'Ukurasa Haupatikani' : 'Page Not Found';
        $msg = ($lang === 'sw') ? 'Samahani, ukurasa unaotafuta haupo kwenye mfumo huu kwa sasa.' : 'Sorry, the page you are looking for does not exist on this system.';
        $btn = ($lang === 'sw') ? 'Rudi Dashboard' : 'Back to Dashboard';
        $url = getUrl('dashboard');

        echo "
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'warning',
                title: '$title',
                text: '$msg',
                confirmButtonColor: '#0d6efd',
                confirmButtonText: '$btn',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '$url';
                }
            });
        });
        </script>
        <div class='container py-5 text-center' style='min-height: 70vh; display: flex; align-items: center; justify-content: center;'>
            <div>
                <div class='display-1 text-warning opacity-25 mb-4'><i class='bi bi-exclamation-octagon'></i></div>
                <h3 class='text-muted'>$title</h3>
                <p class='text-muted'>$msg</p>
                <a href='$url' class='btn btn-primary mt-3'>$btn</a>
            </div>
        </div>";
        require_once ROOT_DIR . '/footer.php';
        exit();
    }
}
?>
