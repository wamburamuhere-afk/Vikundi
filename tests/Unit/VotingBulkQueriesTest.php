<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The voting page ran a per-vote N+1: each published vote fired ~4 queries (options,
 * ballot tally, participation, eligibility) and each open vote ~2 — up to ~60 queries
 * with 15 published votes. It now pre-aggregates all of that in a handful of bulk
 * "WHERE vote_id IN (...) GROUP BY vote_id" queries and the render loops read arrays.
 */
class VotingBulkQueriesTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(__DIR__ . '/../../app/constant/voting/voting.php');
    }

    public function testTalliesTurnoutAndOptionsArePreAggregated(): void
    {
        $this->assertStringContainsString('FROM vote_options WHERE vote_id IN', $this->src);
        $this->assertStringContainsString('FROM vote_ballots WHERE vote_id IN', $this->src);
        $this->assertStringContainsString('GROUP BY vote_id, option_id', $this->src);
        $this->assertStringContainsString('FROM vote_participation WHERE vote_id IN', $this->src);
        $this->assertStringContainsString('FROM vote_eligibility WHERE vote_id IN', $this->src);
    }

    public function testTheRenderLoopsReadArraysNotPerVoteQueries(): void
    {
        // The loops now read pre-fetched arrays.
        $this->assertStringContainsString('$ballots[$vid] ?? []', $this->src);
        $this->assertStringContainsString('$partByVote[$vid] ?? 0', $this->src);
        $this->assertStringContainsString('$optsByVote[(int) $v[\'id\']] ?? []', $this->src);

        // The old per-vote queries must be gone.
        $this->assertStringNotContainsString("\$optStmt->execute([\$v['id']])", $this->src);
        $this->assertStringNotContainsString('$cnt = $pdo->prepare("SELECT option_id, COUNT(*)', $this->src);
        $this->assertStringNotContainsString('FROM vote_participation WHERE vote_id = " . (int) $v[\'id\']', $this->src);
        $this->assertStringNotContainsString('FROM vote_eligibility WHERE vote_id = " . (int) $v[\'id\']', $this->src);
    }
}
