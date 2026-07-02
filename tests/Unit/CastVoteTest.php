<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Voting module — member ballot (PR B). Source-guards on cast_vote.php pin the
 * one-vote enforcement, eligibility check, secret-ballot separation, and that
 * the choice is never written to the activity log; plus the member page + route.
 * The DB flow itself is verified live.
 */
class CastVoteTest extends TestCase
{
    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    public function testCastVoteIsGuarded(): void
    {
        $s = $this->src('actions/cast_vote.php');
        $this->assertStringContainsString('require_auth.php', $s, 'must require login (B3)');
        $this->assertStringContainsString('require_csrf.php', $s, 'must require CSRF (H6)');
    }

    public function testEligibilityIsChecked(): void
    {
        $s = $this->src('actions/cast_vote.php');
        $this->assertStringContainsString('vote_eligibility', $s);
        // must confirm the vote is open before accepting a ballot
        $this->assertStringContainsString("!== 'open'", $s);
    }

    public function testOneVotePerMemberEnforced(): void
    {
        $s = $this->src('actions/cast_vote.php');
        // participation carries the unique key; a duplicate is caught and reported
        $this->assertStringContainsString('INSERT INTO vote_participation', $s);
        $this->assertStringContainsString('PDOException', $s);
        $this->assertStringContainsString('already voted', $s);
    }

    public function testBallotStoredWithoutMemberAndInTransaction(): void
    {
        $s = $this->src('actions/cast_vote.php');
        // the anonymous ballot insert names only vote_id + option_id (no member)
        $this->assertStringContainsString('INSERT INTO vote_ballots (vote_id, option_id)', $s);
        $this->assertStringContainsString('beginTransaction', $s);
        // the choice must never hit the activity log
        $this->assertStringNotContainsString('logCreate', $s);
    }

    public function testMemberPageAndRoutePresent(): void
    {
        $page = $this->src('app/constant/voting/voting.php');
        $this->assertStringContainsString("requireViewPermission('voting')", $page);
        $this->assertStringContainsString('/actions/cast_vote', $page);
        $this->assertStringContainsString("'voting' => VOTING_DIR", $this->src('roots.php'));
        $this->assertStringContainsString("getUrl('voting')", $this->src('header.php'));
    }
}
