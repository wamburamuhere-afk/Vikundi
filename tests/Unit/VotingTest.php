<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Voting module — management side (PR A). Pure tests cover validation,
 * normalisation, tally and turnout; source-guards pin the wiring (migration
 * registered before the role seed, secret-ballot table has no member link,
 * member-hidden key, handlers carry the guard stack, results endpoint hides the
 * tally while open).
 */
class VotingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/vote_helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure helpers -------------------------------------------------------

    public function testTitleRequired(): void
    {
        $errs = vk_vote_input_errors(['title' => '', 'vote_type' => 'motion'], []);
        $this->assertNotEmpty($errs);
    }

    public function testCandidateNeedsTwoOptions(): void
    {
        $one = vk_vote_input_errors(['title' => 'Election', 'vote_type' => 'candidate'], ['Amina']);
        $this->assertNotEmpty($one);
        $two = vk_vote_input_errors(['title' => 'Election', 'vote_type' => 'candidate'], ['Amina', 'Juma']);
        $this->assertSame([], $two);
    }

    public function testMotionNeedsNoCandidateOptions(): void
    {
        $this->assertSame([], vk_vote_input_errors(['title' => 'Raise dues?', 'vote_type' => 'motion'], []));
        $this->assertSame(['Yes', 'No', 'Abstain'], vk_default_motion_options());
    }

    public function testNormalisation(): void
    {
        $this->assertSame('motion', vk_normalize_vote_type('MOTION'));
        $this->assertSame('candidate', vk_normalize_vote_type('junk'));
        $this->assertSame('open', vk_normalize_vote_status('Open'));
        $this->assertSame('draft', vk_normalize_vote_status(''));
    }

    public function testTallySortsDescending(): void
    {
        $opts = [['id' => 1, 'label' => 'A'], ['id' => 2, 'label' => 'B'], ['id' => 3, 'label' => 'C']];
        $tally = vk_vote_tally($opts, [1 => 5, 2 => 9, 3 => 2]);
        $this->assertSame('B', $tally[0]['label']);
        $this->assertSame(9, $tally[0]['votes']);
        $this->assertSame('C', $tally[2]['label']);
    }

    public function testTurnoutPercent(): void
    {
        $this->assertSame(50, vk_turnout_percent(15, 30));
        $this->assertSame(0, vk_turnout_percent(0, 0));
        $this->assertSame(100, vk_turnout_percent(30, 30));
    }

    // --- wiring (source guards) --------------------------------------------

    public function testMigrationRegisteredBeforeRoleSeed(): void
    {
        $mig = $this->src('database/migrate.php');
        $this->assertStringContainsString('create_voting_tables.php', $mig);
        $this->assertLessThan(
            strpos($mig, 'seed_vicoba_roles.php'),
            strpos($mig, 'create_voting_tables.php'),
            'voting migration must run before the role seed'
        );
    }

    public function testBallotTableHasNoMemberLink(): void
    {
        // The heart of the secret ballot: vote_ballots must NOT carry member_id.
        $mig = $this->src('database/create_voting_tables.php');
        $this->assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS `vote_ballots`.*?\)\s*ENGINE/s', $mig);
        $ballot = preg_replace('/^.*CREATE TABLE IF NOT EXISTS `vote_ballots`(.*?)ENGINE.*$/s', '$1', $mig);
        $this->assertStringNotContainsString('member_id', $ballot, 'vote_ballots must not link to a member');
        $this->assertStringContainsString('option_id', $ballot);
    }

    public function testMembersCannotManageVoting(): void
    {
        $this->assertStringContainsString("'manage_voting'", $this->src('includes/role_grants.php'));
    }

    public function testHandlersCarryGuardStack(): void
    {
        foreach (['actions/save_vote.php', 'actions/set_vote_status.php', 'actions/delete_vote.php'] as $f) {
            $s = $this->src($f);
            $this->assertStringContainsString('require_auth.php', $s, "$f needs auth guard");
            $this->assertStringContainsString('require_csrf.php', $s, "$f needs CSRF guard");
            $this->assertStringContainsString("'manage_voting'", $s, "$f needs manage_voting authorization");
        }
    }

    public function testOpenTakesEligibilitySnapshot(): void
    {
        $s = $this->src('actions/set_vote_status.php');
        $this->assertStringContainsString('vote_eligibility', $s);
        $this->assertStringContainsString("status='open'", $s);
    }

    public function testResultsHideTallyWhileOpen(): void
    {
        $s = $this->src('api/get_vote_results.php');
        // tally only when closed; never while open
        $this->assertStringContainsString("\$vote['status'] === 'closed'", $s);
        $this->assertStringContainsString('can_see_tally', $s);
    }
}
