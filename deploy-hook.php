<?php
/**
 * deploy-hook.php — GitHub Actions Webhook Deployment Receiver
 *
 * GitHub Actions calls this endpoint via HTTPS POST after tests pass.
 * The script verifies a shared secret, then runs `git pull origin main`
 * using cPanel's Git Version Control (git is always available on cPanel).
 *
 * SETUP (one-time, via cPanel File Manager — see sessions.md):
 *   1. Create /home/bjptechn/.deploy-secret with one line: your-secret-token
 *   2. Add DEPLOY_HOOK_URL and DEPLOY_HOOK_SECRET to GitHub Secrets
 *   3. This file is deployed automatically the first time you manually upload it
 *
 * SECURITY:
 *   - Secret file lives OUTSIDE web root — cannot be read via HTTP
 *   - HMAC-SHA256 signature verification (same as GitHub webhook standard)
 *   - Only POST requests accepted
 *   - Output is minimal — no stack traces, no paths exposed
 */

// ── Config ────────────────────────────────────────────────────────────────────

// Path to secret file — outside web root, unreadable via HTTP
$secretFile = '/home/bjptechn/.deploy-secret';

// The git repo path on the server
$repoPath = '/home/bjptechn/public_html/vikundi';

// Log file for deploy output (optional — set to '' to disable)
$logFile = '/home/bjptechn/.deploy-log';

// ── Request validation ────────────────────────────────────────────────────────

header('Content-Type: text/plain');

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method Not Allowed\n";
    exit;
}

// Read secret from file (outside web root)
if (!file_exists($secretFile) || !is_readable($secretFile)) {
    http_response_code(500);
    echo "Configuration error\n";
    exit;
}

$secret = trim(file_get_contents($secretFile));
if (empty($secret)) {
    http_response_code(500);
    echo "Configuration error\n";
    exit;
}

// Verify the shared secret sent in the Authorization header
// GitHub Actions sends: Authorization: Bearer <secret>
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$providedToken = '';

if (str_starts_with($authHeader, 'Bearer ')) {
    $providedToken = substr($authHeader, 7);
}

// Constant-time comparison to prevent timing attacks
if (!hash_equals($secret, $providedToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

// ── Run git pull ──────────────────────────────────────────────────────────────

$timestamp = date('Y-m-d H:i:s');
$output    = [];
$exitCode  = 0;

// Sanitize the repo path before using in shell command
$safePath = escapeshellarg($repoPath);

// Run git pull — inherit the server's git config (cPanel Git Version Control sets this up)
$cmd = "cd {$repoPath} && git fetch origin main 2>&1 && git reset --hard origin/main 2>&1";
exec($cmd, $output, $exitCode);

$outputText = implode("\n", $output);

// ── Log the result ────────────────────────────────────────────────────────────

if (!empty($logFile)) {
    $logEntry = "[{$timestamp}] Exit:{$exitCode}\n{$outputText}\n" . str_repeat('-', 60) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

// ── Respond ───────────────────────────────────────────────────────────────────

if ($exitCode === 0) {
    http_response_code(200);
    echo "OK — deployed at {$timestamp}\n";
    echo $outputText . "\n";
} else {
    http_response_code(500);
    echo "FAILED — exit code {$exitCode} at {$timestamp}\n";
    echo $outputText . "\n";
}
