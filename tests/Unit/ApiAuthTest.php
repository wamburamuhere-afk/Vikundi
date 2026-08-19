<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Module 1 of the mobile API: token auth.
 *
 * The token helpers in includes/api_auth.php are pure enough to exercise
 * directly (issuing and verifying a JWT needs no database), so those get real
 * behavioural tests rather than source greps. The endpoint files are checked
 * structurally — they cannot be executed without a live request — but only for
 * the properties that would be genuine security holes if they regressed.
 *
 * The end-to-end flow (login -> me -> refresh rotation -> logout revocation ->
 * blocked account) was verified against a running server with real users before
 * this was written; see the PR body.
 */
class ApiAuthTest extends TestCase
{
    private string $root;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        // A test-only secret. The real one lives in includes/config.php, which is
        // gitignored and absent in CI.
        if (!defined('JWT_SECRET')) {
            define('JWT_SECRET', str_repeat('a1b2c3d4', 8));
        }
        require_once $root . '/includes/api_auth.php';
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function read(string $rel): string
    {
        $full = $this->root . '/' . $rel;
        $this->assertFileExists($full);
        return file_get_contents($full);
    }

    // -------------------------------------------------------------------------
    // Access tokens
    // -------------------------------------------------------------------------

    public function testAValidAccessTokenRoundTrips(): void
    {
        $token  = vk_api_issue_access_token(42, 7);
        $claims = vk_api_verify_access_token($token);

        $this->assertNotNull($claims);
        $this->assertSame(42, $claims['sub']);
        $this->assertSame(7, $claims['rid']);
    }

    public function testAnExpiredAccessTokenIsRefused(): void
    {
        // Issued far enough in the past that it is expired now.
        $token = vk_api_issue_access_token(42, 7, time() - (VK_API_ACCESS_TTL + 60));
        $this->assertNull(vk_api_verify_access_token($token));
    }

    public function testATamperedAccessTokenIsRefused(): void
    {
        $token = vk_api_issue_access_token(42, 7);

        // Flip a character in the payload segment. The signature no longer matches.
        [$h, $p, $s] = explode('.', $token);
        $p[0] = $p[0] === 'a' ? 'b' : 'a';

        $this->assertNull(vk_api_verify_access_token("$h.$p.$s"));
    }

    public function testATokenSignedWithAnotherSecretIsRefused(): void
    {
        // Forged elsewhere: correct shape, correct claims, wrong key. The key is
        // deliberately long enough to be accepted by the library, so this tests
        // signature rejection rather than the length guard below.
        $forged = \Firebase\JWT\JWT::encode(
            ['sub' => 42, 'rid' => 1, 'iat' => time(), 'exp' => time() + 3600, 'typ' => 'access'],
            str_repeat('9f8e7d6c', 8),
            'HS256'
        );

        $this->assertNull(vk_api_verify_access_token($forged));
    }

    public function testTheLibraryRefusesAWeakSigningKey(): void
    {
        // firebase/php-jwt v7 rejects keys under 256 bits outright, so a
        // deployment cannot quietly weaken the signature by choosing a short
        // secret. The generator documented in config.example.php produces 32
        // random bytes (64 hex chars), comfortably above the floor.
        $this->expectException(\DomainException::class);
        \Firebase\JWT\JWT::encode(['sub' => 1], 'too-short', 'HS256');
    }

    public function testGarbageIsRefusedRatherThanCrashing(): void
    {
        foreach (['', 'not-a-token', 'a.b.c', '....'] as $junk) {
            $this->assertNull(vk_api_verify_access_token($junk), "refused: '$junk'");
        }
    }

    public function testTheAlgorithmIsPinnedSoNoneCannotBeAccepted(): void
    {
        // The classic JWT attack: re-sign with alg=none and hope the verifier
        // honours the header. firebase/php-jwt is told exactly one algorithm, so
        // the header cannot choose it.
        $src = $this->read('includes/api_auth.php');
        $this->assertStringContainsString("new Key(\$secret, VK_API_JWT_ALG)", $src);
        $this->assertStringContainsString("define('VK_API_JWT_ALG', 'HS256')", $src);
    }

    public function testARefreshTokenIsNotAcceptedAsAnAccessToken(): void
    {
        // The two token types are not interchangeable: a long-lived refresh token
        // presented as a bearer credential must be refused.
        $notAccess = \Firebase\JWT\JWT::encode(
            ['sub' => 42, 'rid' => 1, 'iat' => time(), 'exp' => time() + 3600, 'typ' => 'refresh'],
            JWT_SECRET,
            'HS256'
        );

        $this->assertNull(vk_api_verify_access_token($notAccess));
    }

    // -------------------------------------------------------------------------
    // The secret
    // -------------------------------------------------------------------------

    public function testAPlaceholderSecretIsTreatedAsNoSecret(): void
    {
        // config.example.php ships a REPLACE_ME value. A deployment that never
        // changed it must fail closed rather than sign tokens everyone can forge.
        $src = $this->read('includes/api_auth.php');
        $this->assertStringContainsString("str_starts_with(\$secret, 'REPLACE_ME')", $src);
        $this->assertStringContainsString('return null;', $src);
    }

    public function testEndpointsRefuseToRunWithoutASecret(): void
    {
        foreach (['api/v1/auth/login.php', 'api/v1/auth/refresh.php'] as $rel) {
            $this->assertStringContainsString(
                'server_misconfigured',
                $this->read($rel),
                "$rel must refuse to issue tokens when JWT_SECRET is unset"
            );
        }
    }

    public function testTheExampleConfigDocumentsTheSecretWithoutShippingARealOne(): void
    {
        $src = $this->read('includes/config.example.php');
        $this->assertStringContainsString("define('JWT_SECRET'", $src);
        $this->assertStringContainsString('REPLACE_ME', $src);
    }

    // -------------------------------------------------------------------------
    // Bearer header parsing
    // -------------------------------------------------------------------------

    public function testBearerTokenIsExtractedFromEitherHeaderVariant(): void
    {
        $this->assertSame('abc123', vk_api_bearer_token(['HTTP_AUTHORIZATION' => 'Bearer abc123']));
        $this->assertSame('abc123', vk_api_bearer_token(['HTTP_AUTHORIZATION' => 'bearer abc123']));
        // Apache moves the header here under some CGI configurations.
        $this->assertSame('abc123', vk_api_bearer_token(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc123']));
    }

    public function testMalformedAuthorizationHeadersYieldNoToken(): void
    {
        foreach ([[], ['HTTP_AUTHORIZATION' => ''], ['HTTP_AUTHORIZATION' => 'Basic abc'],
                  ['HTTP_AUTHORIZATION' => 'Bearer'], ['HTTP_AUTHORIZATION' => 'Bearer a b']] as $server) {
            $this->assertSame('', vk_api_bearer_token($server));
        }
    }

    // -------------------------------------------------------------------------
    // Storage: refresh tokens are hashed, never stored raw
    // -------------------------------------------------------------------------

    public function testRefreshTokensAreStoredOnlyAsAHash(): void
    {
        $src = $this->read('includes/api_auth.php');

        // Every read and write path goes through the hash.
        $this->assertStringContainsString("hash('sha256', \$raw)", $src);
        // The raw value must never be written to a column.
        $this->assertStringNotContainsString('VALUES (?, $raw', $src);
    }

    public function testTheTokenTableStoresAHashColumnNotATokenColumn(): void
    {
        $src = $this->read('database/create_api_tokens_table.php');
        $this->assertStringContainsString('`token_hash` char(64)', $src);
        $this->assertStringContainsString('ENGINE=InnoDB', $src);
        // Revocation is the whole reason this table exists.
        $this->assertStringContainsString('`revoked_at`', $src);
    }

    public function testTheMigrationIsRegisteredAndIdempotent(): void
    {
        $src = $this->read('database/create_api_tokens_table.php');
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `api_refresh_tokens`', $src);

        $runner = $this->read('database/migrate.php');
        $this->assertStringContainsString("'create_api_tokens_table.php'", $runner);
    }

    // -------------------------------------------------------------------------
    // Endpoint behaviour that would be a hole if it regressed
    // -------------------------------------------------------------------------

    public function testLoginMirrorsTheWebAppsBlockedStatuses(): void
    {
        // Two sign-in paths that disagree about who may sign in is a security
        // hole. Both must refuse the same set.
        $api = $this->read('api/v1/auth/login.php');
        $web = $this->read('actions/login.php');

        foreach (['pending', 'rejected', 'inactive', 'suspended'] as $status) {
            $this->assertStringContainsString("'$status'", $api, "API login must handle '$status'");
            $this->assertStringContainsString("'$status'", $web, "web login handles '$status'");
        }
        // Soft-deleted accounts are excluded by the lookup itself, as in the web app.
        $this->assertStringContainsString("status != 'deleted'", $api);
    }

    public function testLoginDoesNotRevealWhetherAnAccountExists(): void
    {
        $src = $this->read('api/v1/auth/login.php');

        // One code for both "no such user" and "wrong password" — otherwise the
        // endpoint enumerates valid usernames.
        $this->assertSame(
            2,
            substr_count($src, "'invalid_credentials'"),
            'both the unknown-user and wrong-password branches must return invalid_credentials'
        );
    }

    public function testFailedLoginsAreAudited(): void
    {
        $src = $this->read('api/v1/auth/login.php');
        $this->assertStringContainsString("logFailedLogin(\$loginInput, 'account not found'", $src);
        $this->assertStringContainsString("logFailedLogin(\$loginInput, 'wrong password'", $src);
        $this->assertStringContainsString('logLogin(', $src);
    }

    public function testRefreshRotatesTheTokenItWasGiven(): void
    {
        // Without rotation a stolen refresh token works for its full 30 days and
        // the theft is invisible. With it, the legitimate client's next refresh
        // fails and the problem surfaces.
        $src = $this->read('api/v1/auth/refresh.php');
        $this->assertStringContainsString('vk_api_revoke_refresh_token($pdo, $raw)', $src);
        $this->assertStringContainsString('vk_api_issue_refresh_token(', $src);
    }

    public function testRefreshRefusesAndFullyRevokesABlockedAccount(): void
    {
        $src = $this->read('api/v1/auth/refresh.php');
        $this->assertStringContainsString('vk_api_blocked_statuses()', $src);
        $this->assertStringContainsString('vk_api_revoke_all_for_user($pdo, $userId)', $src);
    }

    public function testLogoutCannotRevokeAnotherUsersToken(): void
    {
        // Without the ownership check, any authenticated user could sign out any
        // other user whose refresh token they obtained.
        $src = $this->read('api/v1/auth/logout.php');
        $this->assertStringContainsString("\$row['user_id'] !== \$auth['user_id']", $src);
    }

    public function testEveryAuthEndpointPinsItsHttpMethod(): void
    {
        $expected = [
            'api/v1/auth/login.php'   => "vk_api_require_method(['POST'])",
            'api/v1/auth/refresh.php' => "vk_api_require_method(['POST'])",
            'api/v1/auth/logout.php'  => "vk_api_require_method(['POST'])",
            'api/v1/auth/me.php'      => "vk_api_require_method(['GET'])",
        ];
        foreach ($expected as $rel => $call) {
            $this->assertStringContainsString($call, $this->read($rel), "$rel must pin its method");
        }
    }

    public function testProtectedEndpointsRequireAToken(): void
    {
        foreach (['api/v1/auth/logout.php', 'api/v1/auth/me.php'] as $rel) {
            $this->assertStringContainsString('vk_api_require_auth()', $this->read($rel), "$rel must require auth");
        }
        // login and refresh are the two that must NOT, since they are how a
        // client without a token gets one.
        foreach (['api/v1/auth/login.php', 'api/v1/auth/refresh.php'] as $rel) {
            $this->assertStringNotContainsString('vk_api_require_auth()', $this->read($rel));
        }
    }

    // -------------------------------------------------------------------------
    // Bootstrap guarantees
    // -------------------------------------------------------------------------

    public function testPermissionsAreReadFreshNotCarriedInTheToken(): void
    {
        // Baking permissions into the token means a revoked right survives until
        // the token expires — the same defect SEC-010 describes in the web
        // app's session cache.
        $auth = $this->read('includes/api_auth.php');
        $this->assertStringContainsString('function vk_api_load_permissions(PDO $pdo, int $roleId)', $auth);

        // The issued claim set carries identity only.
        $this->assertStringNotContainsString("'perms'", $auth);
        $this->assertStringNotContainsString("'permissions' =>", $auth);
    }

    public function testTheAuthGateRechecksAccountStatusOnEveryRequest(): void
    {
        // A JWT keeps asserting the account was fine when it was signed. Reading
        // the row means an account disabled five minutes ago stops working now.
        $src = $this->read('includes/api_bootstrap.php');
        $this->assertStringContainsString('vk_api_blocked_statuses()', $src);
        $this->assertStringContainsString("'account_blocked'", $src);
    }

    public function testAdminBypassUsesRoleIdsNotRoleNames(): void
    {
        // SEC-015: the web app treats certain role *names* as admin, so renaming a
        // role silently grants or revokes admin. The API must not inherit that.
        $src = $this->read('includes/api_bootstrap.php');
        $this->assertStringContainsString('in_array($roleId, [1, 2, 12], true)', $src);
        $this->assertStringNotContainsString("'mwenyekiti'", $src);
        $this->assertStringNotContainsString("'treasurer'", $src);
    }

    public function testResponsesAreNeverCached(): void
    {
        // Every response is per-user and token-authenticated.
        $this->assertStringContainsString("header('Cache-Control: no-store')", $this->read('includes/api_bootstrap.php'));
    }

    public function testCorsDoesNotEnableCookieCredentials(): void
    {
        // The API authenticates by Authorization header. Allowing credentialed
        // cross-origin requests alongside a wildcard origin would be the classic
        // misconfiguration; there is nothing here for CSRF to ride on.
        $src = $this->read('includes/api_bootstrap.php');
        $this->assertStringContainsString("header('Access-Control-Allow-Origin: *')", $src);
        // Match the header() CALL, not any mention — the file discusses this
        // header in a comment, and an earlier version of this test passed on
        // the comment alone.
        $this->assertDoesNotMatchRegularExpression(
            '/header\(\s*[\'"]Access-Control-Allow-Credentials/',
            $src,
            'the API must not send Access-Control-Allow-Credentials'
        );
    }
}
