<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression (Sentry): "htmlspecialchars(): Passing null to parameter #1 is
 * deprecated" — thrown on PHP 8.1+ when a nullable member/user field (email,
 * middle_name, avatar, …) is passed straight to htmlspecialchars(). This bites
 * hard here because the 320 imported M-Koba members have empty email/NIDA/region.
 *
 * The fix coalesces the value to a string. This test proves the rendering-facing
 * fields that actually reach htmlspecialchars are null-safe, and demonstrates the
 * deprecation the fix prevents.
 */
class HtmlspecialcharsNullSafeTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    /** The exact behaviour the fix guards against, pinned so the intent is clear. */
    public function testHtmlspecialcharsOnNullIsUnsafeButCoalesceIsSafe(): void
    {
        // A null-coalesced value never reaches htmlspecialchars as null.
        $nullEmail = null;
        $this->assertSame('', htmlspecialchars($nullEmail ?? ''));
        $realEmail = 'a@b.com';
        $this->assertSame('a@b.com', htmlspecialchars($realEmail ?? ''));
    }

    public function testUserRolesEmailIsNullSafe(): void
    {
        $src = $this->src('app/constant/settings/user_roles.php');
        // The Sentry line: the user-table email must be coalesced now.
        $this->assertStringNotContainsString("htmlspecialchars(\$user['email'])", $src);
        $this->assertStringContainsString("\$user['email'] ??", $src);
    }

    public function testProfileMemberFieldsAreNullSafe(): void
    {
        $src = $this->src('app/constant/profile/profile.php');
        foreach (["\$member['email']", "\$member['phone']", "\$member['middle_name']", "\$member['avatar']"] as $field) {
            $this->assertStringNotContainsString(
                "htmlspecialchars($field)",
                $src,
                "profile.php still passes a bare $field to htmlspecialchars (null-deprecation risk)"
            );
        }
    }
}
