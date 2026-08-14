<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Committee's half of the leadership flow: approve or reject an application.
 *
 * Approving is what puts a name on the ballot — it writes a `vote_options` row,
 * which is exactly what the existing voting page renders and cast_vote.php tallies.
 * Nothing about the voting module itself changes, so the risks here are all about
 * the boundary (who may decide) and about the ballot never shifting under people
 * who are already voting on it.
 */
class LeadershipReviewTest extends TestCase
{
    private string $action;
    private string $page;

    protected function setUp(): void
    {
        $root = __DIR__ . '/../../';
        $this->action = file_get_contents($root . 'actions/review_leadership_application.php');
        $this->page   = file_get_contents($root . 'app/constant/voting/manage_leadership_applications.php');
    }

    // -------------------------------------------------------------------------
    // Only the Committee decides
    // -------------------------------------------------------------------------

    public function testReviewingRequiresTheCommitteePermission(): void
    {
        $this->assertStringContainsString(
            "requirePermissionJson('edit', 'manage_leadership_applications');",
            $this->action
        );
        $this->assertStringContainsString(
            "requireViewPermission('manage_leadership_applications');",
            $this->page
        );
    }

    public function testTheEndpointIsAuthenticatedAndCsrfProtected(): void
    {
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_auth.php';", $this->action);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_csrf.php';", $this->action);
    }

    public function testTheDecisionButtonsAreHiddenWithoutEditRights(): void
    {
        // A read-only Committee member can see the applications but not rule on them.
        $this->assertStringContainsString("canEdit('manage_leadership_applications')", $this->page);
    }

    // -------------------------------------------------------------------------
    // The ballot must not move under people who are voting
    // -------------------------------------------------------------------------

    public function testNoDecisionIsAcceptedOnceVotingHasStarted(): void
    {
        // Adding or removing a candidate mid-vote changes what people were choosing
        // between after some of them have already chosen.
        $this->assertStringContainsString("if (\$app['election_status'] !== 'draft') {", $this->action);
        $this->assertStringContainsString('Voting has started; applications can no longer be changed.', $this->action);
    }

    public function testRejectingRemovesTheNameFromTheBallot(): void
    {
        // Otherwise a rejected candidate would still be standing.
        $this->assertMatchesRegularExpression(
            '/reject.*?DELETE FROM vote_options WHERE id = \?/s',
            $this->action
        );
        $this->assertStringContainsString('vote_option_id=NULL', $this->action);
    }

    public function testReopeningAlsoRemovesTheBallotOption(): void
    {
        $this->assertStringContainsString("SET status='pending', review_note=NULL, reviewed_by=NULL, reviewed_at=NULL, vote_option_id=NULL", $this->action);
    }

    public function testApprovingTwiceReusesTheSameBallotOption(): void
    {
        // Re-approving must not add the same person to the ballot a second time.
        $this->assertStringContainsString("if (!empty(\$app['vote_option_id'])) {", $this->action);
        $this->assertStringContainsString('UPDATE vote_options SET label = ?, member_id = ? WHERE id = ?', $this->action);
    }

    public function testTheWholeDecisionIsOneTransaction(): void
    {
        // Writing the ballot option and the application status must not half-happen:
        // an approved application with no ballot option is a candidate nobody can
        // vote for. Both tables are InnoDB, so this is a real transaction.
        $this->assertStringContainsString('$pdo->beginTransaction();', $this->action);
        $this->assertStringContainsString('$pdo->commit();', $this->action);
        $this->assertStringContainsString('if ($pdo->inTransaction()) {', $this->action);
        $this->assertStringContainsString('$pdo->rollBack();', $this->action);
    }

    // -------------------------------------------------------------------------
    // Decisions that have to be explainable
    // -------------------------------------------------------------------------

    public function testARejectionRequiresAReason(): void
    {
        // A rejection without a reason is the kind of decision a group argues about
        // for a year.
        $this->assertStringContainsString("if (\$note === '') {", $this->action);
        $this->assertStringContainsString('Please give a reason for rejecting.', $this->action);
    }

    public function testAWithdrawnApplicationCannotBeRuledOn(): void
    {
        $this->assertStringContainsString("if (\$app['status'] === 'withdrawn') {", $this->action);
    }

    public function testEveryDecisionIsAudited(): void
    {
        $this->assertStringContainsString("logUpdate('Leadership Applications'", $this->action);
        foreach (['(approved)', '(rejected)', '(reopened)'] as $marker) {
            $this->assertStringContainsString($marker, $this->action);
        }
    }

    // -------------------------------------------------------------------------
    // What the Committee is shown
    // -------------------------------------------------------------------------

    public function testTheApplicantsContributionStandingIsShown(): void
    {
        // Not to block anyone — to make the decision knowingly. A group electing a
        // Treasurer who is themselves months behind should at least know it.
        $this->assertStringContainsString('cs_arrears_from_grid(', $this->page);
        $this->assertStringContainsString("\$t('Behind', 'Amechelewa')", $this->page);
        $this->assertStringContainsString("\$t('Contributions up to date', 'Michango iko sawa')", $this->page);
    }

    public function testStandingIsGatheredInOnePassNotPerApplicant(): void
    {
        $this->assertStringContainsString('cs_group_schedules($pdo)', $this->page);
        $this->assertStringNotContainsString('cs_member_schedule(', $this->page);
    }

    public function testAMultiOfficeElectionIsFlaggedRatherThanSilentlyProducingABadBallot(): void
    {
        // A member casts ONE vote per election. If an election carries candidates for
        // several offices they are forced to pick a single person across all of them.
        $this->assertStringContainsString('$multiOffice = count($offices) > 1;', $this->page);
        $this->assertStringContainsString('This election covers more than one position.', $this->page);
        $this->assertStringContainsString('Consider creating one election per position.', $this->page);
    }

    public function testTheBallotLabelCarriesThePositionOnlyWhenItIsNeeded(): void
    {
        // One office: the name alone. Several: the name would be ambiguous without it.
        $this->assertStringContainsString('$manyOffices = ((int) $distinct->fetchColumn()) > 1;', $this->action);
        $this->assertStringContainsString("\$app['member_name'] . ' — ' . \$app['position']", $this->action);
    }

    // -------------------------------------------------------------------------
    // Wiring and escaping
    // -------------------------------------------------------------------------

    public function testMemberSuppliedTextIsEscaped(): void
    {
        // Statement and experience are written by the applicant.
        $this->assertStringContainsString("nl2br(safe_output(\$a['statement']))", $this->page);
        $this->assertStringContainsString("safe_output(\$a['member_name'])", $this->page);
        $this->assertStringContainsString("safe_output(\$a['review_note'])", $this->page);
    }

    public function testItIsRoutedAndInTheMenuBehindItsPermission(): void
    {
        $roots  = file_get_contents(__DIR__ . '/../../roots.php');
        $header = file_get_contents(__DIR__ . '/../../header.php');

        $this->assertStringContainsString("'manage_leadership_applications' => VOTING_DIR", $roots);
        $this->assertStringContainsString("canView('manage_leadership_applications')", $header);
        $this->assertStringContainsString('Pitia Maombi ya Uongozi', $header);
    }

    public function testTheEmptyStateExplainsHowToStart(): void
    {
        // A Committee member arriving before any election exists should be told what
        // to do, not shown a blank page.
        $this->assertStringContainsString('No leadership election has been created yet.', $this->page);
        $this->assertStringContainsString('while it stays in draft', $this->page);
    }
}
