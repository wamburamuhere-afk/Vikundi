<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSRF helpers (includes/csrf.php) that back the central guard
 * includes/require_csrf.php (audit H6): token verification, safe-method gating,
 * and where the submitted token is read from (header vs POST field).
 */
class CsrfTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/csrf.php';
    }

    protected function setUp(): void
    {
        // Deterministic stored token for verify tests.
        $_SESSION['csrf_token'] = 'stored-token-abc';
    }

    // --- csrf_verify() ---

    public function testVerifyAcceptsMatchingToken(): void
    {
        $this->assertTrue(csrf_verify('stored-token-abc'));
    }

    public function testVerifyRejectsWrongToken(): void
    {
        $this->assertFalse(csrf_verify('not-the-token'));
    }

    public function testVerifyRejectsEmptyOrNull(): void
    {
        $this->assertFalse(csrf_verify(''));
        $this->assertFalse(csrf_verify(null));
    }

    public function testVerifyRejectsWhenNoStoredToken(): void
    {
        $_SESSION['csrf_token'] = '';
        $this->assertFalse(csrf_verify('anything'));
    }

    // --- csrf_is_safe_method() ---

    public function testSafeMethodsBypass(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS', 'TRACE', 'get', ' head '] as $m) {
            $this->assertTrue(csrf_is_safe_method($m), "$m should be safe");
        }
    }

    public function testUnsafeMethodsAreNotSafe(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'post'] as $m) {
            $this->assertFalse(csrf_is_safe_method($m), "$m should be unsafe");
        }
    }

    // --- csrf_extract_token() ---

    public function testExtractPrefersHeader(): void
    {
        $token = csrf_extract_token(
            ['HTTP_X_CSRF_TOKEN' => 'from-header'],
            ['csrf_token' => 'from-body']
        );
        $this->assertSame('from-header', $token);
    }

    public function testExtractFallsBackToPostField(): void
    {
        $token = csrf_extract_token([], ['csrf_token' => 'from-body']);
        $this->assertSame('from-body', $token);
    }

    public function testExtractReturnsNullWhenAbsent(): void
    {
        $this->assertNull(csrf_extract_token([], []));
        $this->assertNull(csrf_extract_token(['HTTP_X_CSRF_TOKEN' => ''], ['csrf_token' => '']));
    }

    /**
     * End-to-end of the guard's decision: a forged unsafe request with no token
     * fails, while the same request carrying the session token passes.
     */
    public function testGuardLogicRejectsForgedAndAcceptsTokened(): void
    {
        // Forged: POST, no header, no field.
        $forged = csrf_extract_token([], []);
        $this->assertFalse(!csrf_is_safe_method('POST') && csrf_verify($forged));

        // Legit: POST carrying the session token in the header.
        $legit = csrf_extract_token(['HTTP_X_CSRF_TOKEN' => 'stored-token-abc'], []);
        $this->assertTrue(!csrf_is_safe_method('POST') && csrf_verify($legit));
    }
}
