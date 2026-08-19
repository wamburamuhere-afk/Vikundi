<?php
// Copy this file to config.php and fill in your database credentials.
// NEVER commit config.php — it is excluded by .gitignore.

$host     = 'localhost';
$dbname   = 'vikundi';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'Database connection failed']));
}

// Mobile API (Module 1: Auth) — signs and verifies the JSON Web Tokens issued
// on login. Generate a real value with:
//   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
// A guessable or shared secret lets anyone forge a valid token for any user,
// so includes/api_auth.php refuses to issue or verify tokens if this is left
// as the placeholder below.
define('JWT_SECRET', 'REPLACE_ME_WITH_A_RANDOM_64_CHAR_HEX_STRING');
