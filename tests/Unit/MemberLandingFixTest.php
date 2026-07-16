<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fixes found by testing the Member Home live as a member:
 *  - login sent everyone to the dashboard (getLandingPage was never called);
 *  - the member statement said "Member not found" for a member and let a member
 *    read another member's statement via ?id.
 */
class MemberLandingFixTest extends TestCase
{
    private function src(string $rel): string
    {
        return file_get_contents(__DIR__ . '/../../' . $rel);
    }

    public function testLoginRedirectsByLandingPageNotHardcodedDashboard(): void
    {
        $action = $this->src('actions/login.php');
        $this->assertStringContainsString("\$response['redirect']", $action);
        $this->assertStringContainsString('getLandingPage()', $action);

        $page = $this->src('login.php');
        // the page follows the server's redirect, not a hardcoded dashboard
        $this->assertStringContainsString('response.redirect || ', $page);
        $this->assertStringNotContainsString("window.location.href = 'dashboard';", $page);
        // the already-logged-in redirect also respects the landing page
        $this->assertStringContainsString('getLandingPage()', $page);
    }

    public function testMemberStatementDefaultsToOwnAndBlocksPeeking(): void
    {
        $p = $this->src('app/constant/reports/member_statement.php');
        // leadership discriminator, not the members-also-have-it report permission
        $this->assertStringContainsString('$is_leader = isAdmin() || canCreate(', $p);
        // members always see their own; a leader with no id defaults to their own
        $this->assertStringContainsString('if (!$is_leader || !$member_id)', $p);
        // the broken branch that keyed only on report-view access is gone
        $this->assertStringNotContainsString("if (!canView('vicoba_reports')) {", $p);
    }
}
