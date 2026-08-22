<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * GET /api/v1/auth/me must describe what the server will ACTUALLY do.
 *
 * WHAT THIS CAUGHT. vk_api_can() short-circuits for an admin and never reads the
 * permission map:
 *
 *     if (vk_api_is_admin($auth['role_id'])) return true;
 *
 * So an admin is granted every page regardless of role_permissions — and on the
 * live system those rows are nearly empty, because the web app never needed
 * them: isAdmin() has always bypassed the check. The demo Admin held 10 page
 * keys and `customers` was not one of them.
 *
 * /auth/me returned that raw map while documenting itself as "what the server
 * will actually enforce". A client that did exactly what the documentation said
 * hid Members List from the Admin, on an account the server would have served.
 * Reported from the Flutter side; the API was at fault, not the client.
 *
 * The fix belongs on the server. One inconsistency that every consumer has to
 * special-case is a server bug wearing a client's clothes — the second consumer
 * would have hit it too.
 */
class AuthMeShapeTest extends TestCase
{
    private static function code(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path);
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $out .= $t[1];
                continue;
            }
            $out .= $t;
        }
        return $out;
    }

    // — Effective permissions ————————————————————————————————————————————

    public function testAnAdminIsGivenTheWholeCatalogue(): void
    {
        $code = self::code('api/v1/auth/me.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$isAdmin\s*\)/',
            $code,
            'me.php must widen the permission map for an admin.'
        );
        $this->assertStringContainsString(
            'SELECT page_key FROM permissions',
            $code,
            'The catalogue must be read from the database, so a page added later appears '
            . 'without editing this file.'
        );
    }

    /**
     * A union, never a replacement. If the widening ever became an assignment, a
     * key present in the role's own rows but absent from the catalogue would be
     * dropped — turning a fix for missing access into a cause of missing access.
     */
    public function testWideningCanOnlyAddAccessNeverRemoveIt(): void
    {
        $code = self::code('api/v1/auth/me.php');

        $this->assertMatchesRegularExpression(
            '/\$permissions\s*=\s*\$granted\s*\+\s*\$permissions\s*;/',
            $code,
            'Must union the catalogue with the role\'s own rows (\$granted + \$permissions), '
            . 'so nothing the role already had can be lost.'
        );
    }

    /** A non-admin must be unaffected: their map is the authority for them. */
    public function testANonAdminsMapIsNotWidened(): void
    {
        $code = self::code('api/v1/auth/me.php');

        $widenAt = strpos($code, '$granted + $permissions');
        $guardAt = strpos($code, 'if ($isAdmin)');

        $this->assertNotFalse($guardAt);
        $this->assertNotFalse($widenAt);
        $this->assertGreaterThan(
            $guardAt,
            $widenAt,
            'The widening must sit inside the admin guard, or every role gets everything.'
        );
    }

    // — Fields that disagreed between endpoints ——————————————————————————

    /**
     * /dashboard returned null for an account with no member record while this
     * returned 0, so the same field meant two things depending which endpoint you
     * asked. A client null-checking one and integer-checking the other breaks on
     * whichever screen is written second.
     */
    public function testMemberIdIsNullWhenThereIsNoMemberRecord(): void
    {
        $code = self::code('api/v1/auth/me.php');

        $this->assertMatchesRegularExpression(
            '/\$memberId\s*>\s*0\s*\?\s*\$memberId\s*:\s*null/',
            $code,
            'member_id must be null rather than 0 when the account has no member record, '
            . 'matching /dashboard.'
        );
    }

    /**
     * is_leadership existed in /dashboard from the start and was missing here, so
     * a client could not tell a Secretary from a Member without inspecting the
     * permission map itself.
     */
    public function testIsLeadershipIsReported(): void
    {
        $code = self::code('api/v1/auth/me.php');

        $this->assertStringContainsString("'is_leadership'", $code);
        $this->assertStringContainsString('vk_role_is_leadership(', $code);
    }

    /** Both endpoints must derive leadership from the same shared definition. */
    public function testBothEndpointsUseTheSameLeadershipDefinition(): void
    {
        foreach (['api/v1/auth/me.php', 'api/v1/dashboard.php'] as $rel) {
            $this->assertStringContainsString(
                'vk_role_is_leadership(',
                self::code($rel),
                "{$rel} must use the shared leadership definition."
            );
        }
    }

    /**
     * The two endpoints describe the same account. Any field naming the same
     * thing must be produced the same way, or they drift apart again.
     */
    public function testTheTwoEndpointsAgreeOnHowMemberIdIsProduced(): void
    {
        $me   = self::code('api/v1/auth/me.php');
        $dash = self::code('api/v1/dashboard.php');

        foreach (['me.php' => $me, 'dashboard.php' => $dash] as $name => $code) {
            $this->assertMatchesRegularExpression(
                '/(\$memberId\s*>\s*0\s*\?\s*\$memberId\s*:\s*null|\$memberId\s*\?:\s*null)/',
                $code,
                "{$name} must return null, not 0, for an account with no member record."
            );
        }
    }
}
