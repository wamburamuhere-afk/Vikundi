<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/v1/dashboard must WITHHOLD the group's position from a plain member,
 * not merely leave it out of the UI.
 *
 * The web app hides these figures behind template conditions, which is adequate
 * for a rendered page. It is not adequate for JSON: a member who opens devtools,
 * or points curl at the same endpoint their app calls, reads whatever the server
 * put in the body. So the group keys must never be assembled at all unless the
 * caller is leadership.
 *
 * Verified live per role before this test was written — Chairperson and Treasurer
 * receive the group block, a Member receives only role/me/pending/currency, and
 * the audit trail goes to full admins only (a Secretary does not see it on the
 * web and must not gain it by switching device).
 */
class DashboardApiShapeTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/api/v1/dashboard.php');
    }

    /** Position of the one `if ($isLeadership) {` gate that opens the group block. */
    private static function firstLeadershipGate(): int
    {
        $pos = strpos(self::source(), 'if ($isLeadership) {');
        self::assertNotFalse($pos, 'The endpoint must gate group data behind $isLeadership.');
        return $pos;
    }

    /**
     * Every figure that describes the group rather than the caller. If any of
     * these is assembled before the leadership gate, it reaches a member.
     */
    public static function groupOnlyKeys(): array
    {
        return array_map(static fn ($k) => [$k], [
            "\$payload['members']",
            "\$payload['contributions']",
            "\$payload['expenses']",
            "\$payload['balance']",
            "\$payload['fines']",
            "\$payload['trend']",
            "\$payload['recent_activity']",
        ]);
    }

    #[DataProvider('groupOnlyKeys')]
    public function testGroupFiguresAreOnlyAssembledBehindTheLeadershipGate(string $key): void
    {
        $src  = self::source();
        $gate = self::firstLeadershipGate();

        $at = strpos($src, $key);
        $this->assertNotFalse($at, "{$key} is not built at all — the endpoint lost a figure.");
        $this->assertGreaterThan(
            $gate,
            $at,
            "{$key} is assembled before the \$isLeadership gate, so a plain member would receive it."
        );
    }

    /**
     * The audit trail is stricter still: the web shows it to admin/chairman/
     * mwenyekiti only, so leadership alone is not enough.
     */
    public function testRecentActivityIsGatedOnAdminNotMerelyLeadership(): void
    {
        $src = self::source();

        $adminGate = strpos($src, 'if ($isAdmin) {');
        $this->assertNotFalse($adminGate, 'recent_activity must sit behind an $isAdmin gate.');

        $activityAt = strpos($src, "\$payload['recent_activity']");
        $this->assertNotFalse($activityAt);
        $this->assertGreaterThan(
            $adminGate,
            $activityAt,
            'A Secretary is leadership but does not see the audit trail on the web; '
            . 'it must not become visible by switching to the mobile app.'
        );
    }

    /** The caller's own position is always returned — a Treasurer saves too. */
    public function testTheCallersOwnFiguresAreAlwaysReturned(): void
    {
        $src  = self::source();
        $gate = self::firstLeadershipGate();

        $meAt = strpos($src, "'me' =>");
        $this->assertNotFalse($meAt, "The endpoint must always return the caller's own position.");
        $this->assertLessThan(
            $gate,
            $meAt,
            "The caller's own figures must be built before any role gate, so every role gets them."
        );
    }

    /**
     * Money figures must be delegated to the shared modules, never recomputed
     * here. Two implementations of "what has this group saved" is how a
     * dashboard and a statement come to disagree.
     */
    #[DataProvider('sharedCalculations')]
    public function testMoneyFiguresAreDelegatedToTheSharedModules(string $fn): void
    {
        $this->assertStringContainsString(
            $fn . '(',
            self::source(),
            "{$fn}() must be used rather than reimplementing the calculation."
        );
    }

    public static function sharedCalculations(): array
    {
        return [
            ['cs_group_savings_total'],
            ['cs_member_arrears'],
            ['getGroupFundBalance'],
            ['approvedNotYetPaidExpenses'],
        ];
    }

    /** It is a read-only endpoint; anything else means it grew a side effect. */
    public function testTheEndpointOnlyAcceptsGet(): void
    {
        $this->assertMatchesRegularExpression(
            "/vk_api_require_method\(\s*\[\s*'GET'\s*\]\s*\)/",
            self::source(),
            'The dashboard is read-only and must accept GET only.'
        );
    }

    public function testTheEndpointRequiresAuthentication(): void
    {
        $this->assertStringContainsString('vk_api_require_auth()', self::source());
    }
}
