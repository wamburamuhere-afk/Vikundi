<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two failures that cost real time on the demo site, pinned so they cannot
 * come back.
 *
 * 1. seed_demo_data.php --fresh deleted previously-seeded users filtered on
 *    user_role = 'Member'. The walkthrough seeder promotes three of those
 *    members into Chairperson, Secretary and Treasurer, so on a second run the
 *    three leaders survived the delete and the new ones were created with a
 *    numeric suffix — rmollel AND rmollel1. The credentials the seeder printed
 *    then belonged to a different account than the one holding the office.
 *
 * 2. seed_vicoba_roles.php built every role's grants by looping over the
 *    `permissions` catalogue, and printed "Seeded default permissions for: ..."
 *    whether or not that catalogue had any rows. On an install whose catalogue
 *    was empty this reported success while granting nothing, and the site came
 *    up looking healthy with a half-empty menu.
 */
class DemoSeedFreshnessTest extends TestCase
{
    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    /**
     * The demo email domain is the marker for a seeded account. Filtering the
     * cleanup on user_role as well is what left the promoted leaders behind.
     */
    public function testFreshDeletesSeededUsersByEmailDomainAndNotByRole(): void
    {
        $src = self::src('database/seed_demo_data.php');

        $this->assertMatchesRegularExpression(
            '/DELETE FROM users WHERE email LIKE \'%@example\.co\.tz\'/',
            $src,
            '--fresh must clear seeded accounts by the demo email domain.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/DELETE FROM users WHERE user_role\s*=/",
            $src,
            'Filtering the cleanup on user_role leaves promoted leaders behind, '
            . 'so the next run creates suffixed duplicates (rmollel and rmollel1).'
        );
    }

    /** The delete must never be able to reach an account that is not seeded. */
    public function testTheCleanupIsScopedToTheDemoEmailDomain(): void
    {
        $src = self::src('database/seed_demo_data.php');

        preg_match_all('/DELETE FROM users[^"]*/', $src, $m);
        $this->assertNotEmpty($m[0], 'Expected a user cleanup statement.');
        foreach ($m[0] as $stmt) {
            $this->assertStringContainsString(
                '@example.co.tz',
                $stmt,
                "Unscoped user delete in seed_demo_data.php: {$stmt}"
            );
        }
    }

    /**
     * An empty catalogue must be a hard failure. Reporting success is what made
     * the demo's missing grants invisible for hours.
     */
    public function testTheRoleSeederRefusesAnEmptyPermissionsCatalogue(): void
    {
        $src = self::src('database/seed_vicoba_roles.php');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$perms\s*\)/',
            $src,
            'seed_vicoba_roles.php must verify the permissions catalogue is non-empty.'
        );
        $this->assertStringContainsString(
            'exit(1)',
            $src,
            'An empty catalogue must abort, not print a success line and continue.'
        );

        // The abort must come before the loop it protects.
        $guardAt = strpos($src, 'if (!$perms)');
        $loopAt  = strpos($src, 'foreach ($perms as $pid => $key)');
        $this->assertNotFalse($guardAt);
        $this->assertNotFalse($loopAt);
        $this->assertLessThan($loopAt, $guardAt, 'The abort must precede the grant loop it protects.');
    }
}
