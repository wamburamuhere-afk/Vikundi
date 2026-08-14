<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Leadership applications — the stage that was missing between "the group needs
 * new officers" and "members vote":
 *
 *     member applies  ->  Committee reviews  ->  approved names become the ballot
 *
 * The line the group drew is the one these tests defend hardest: members apply and
 * vote, they do NOT approve. Everything else here is about not letting an
 * application change after it has been ruled on or after voting has opened.
 */
class LeadershipApplicationTest extends TestCase
{
    private string $migration;
    private string $action;
    private string $page;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/leadership_helpers.php';
    }

    protected function setUp(): void
    {
        $root = __DIR__ . '/../../';
        $this->migration = file_get_contents($root . 'database/create_leadership_applications_table.php');
        $this->action    = file_get_contents($root . 'actions/save_leadership_application.php');
        $this->page      = file_get_contents($root . 'app/constant/voting/leadership_application.php');
    }

    // -------------------------------------------------------------------------
    // Who may do what
    // -------------------------------------------------------------------------

    public function testOrdinaryMembersCanApplyButNotReview(): void
    {
        // Two separate page-keys, granted to different sets of roles. Members are in
        // the first and must never be in the second.
        $this->assertStringContainsString("\$grantTo('leadership_applications', \$everyone", $this->migration);
        $this->assertStringContainsString("\$grantTo('manage_leadership_applications', \$leadership", $this->migration);
        $this->assertStringContainsString("\$everyone   = array_merge(\$leadership, ['member', 'mwanachama']);", $this->migration);
    }

    public function testTheReviewGrantNeverIncludesMember(): void
    {
        // Pull the leadership role list out of the migration and prove 'member' is
        // absent — the whole approval boundary rests on this one array.
        preg_match("/\\\$leadership = \[(.*?)\];/s", $this->migration, $m);
        $this->assertNotEmpty($m, 'the leadership role list must exist');
        $this->assertStringNotContainsString("'member'", $m[1]);
        $this->assertStringNotContainsString("'mwanachama'", $m[1]);
        $this->assertStringContainsString("'chairperson'", $m[1]);
        $this->assertStringContainsString("'treasurer'", $m[1]);
    }

    public function testTheGrantIsReAssertedBecauseTheSeederResetsMemberEveryDeploy(): void
    {
        // seed_vicoba_roles.php runs earlier in the migration list and resets the
        // Member role to view-only on EVERY run — deliberately; Member is meant to be
        // view-only almost everywhere. Applying for leadership is an exception to
        // that, so the grant must be re-asserted after each reseed.
        //
        // An insert-if-missing grant worked on the first deploy (the permission did
        // not exist yet, so the seeder could not touch it) and silently failed on the
        // second: the seeder re-seeded Member view-only across every page including
        // this one, and the grant then saw a row and did nothing. A member could open
        // the application page and not submit it.
        $this->assertStringContainsString('UPDATE role_permissions', $this->migration);
        $this->assertStringContainsString('can_create = GREATEST(can_create, ?)', $this->migration);
    }

    public function testRightsAreRaisedNeverLowered(): void
    {
        // GREATEST, not assignment: an administrator who widened a leadership role's
        // access through the Roles screen must keep it across deploys.
        foreach (['can_view', 'can_create', 'can_edit', 'can_delete'] as $col) {
            $this->assertMatchesRegularExpression(
                '/' . $col . '\s*=\s*GREATEST\(\s*' . $col . '\s*,/',
                $this->migration,
                "$col must be raised, not assigned"
            );
        }
    }

    public function testApplicantsCannotEditOrDeleteTheirSubmission(): void
    {
        // view + create only. An application is withdrawn, not deleted, so the record
        // survives for the audit trail.
        $this->assertStringContainsString("\$grantTo('leadership_applications', \$everyone, [1, 1, 0, 0]);", $this->migration);
    }

    public function testThePageAndActionAreBothGated(): void
    {
        $this->assertStringContainsString("requireViewPermission('leadership_applications');", $this->page);
        $this->assertStringContainsString("if (!canCreate('leadership_applications')) {", $this->action);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_csrf.php';", $this->action);
        $this->assertStringContainsString("require_once __DIR__ . '/../includes/require_auth.php';", $this->action);
    }

    // -------------------------------------------------------------------------
    // One application per member, enforced where it cannot be raced
    // -------------------------------------------------------------------------

    public function testTheOnePerMemberRuleIsADatabaseConstraint(): void
    {
        // A PHP check alone loses to two browser tabs submitting at once.
        $this->assertStringContainsString(
            'UNIQUE KEY `one_application_per_member_per_election` (`vote_id`,`member_id`)',
            $this->migration
        );
    }

    public function testADuplicateIsReportedAsSuchNotAsAServerError(): void
    {
        $this->assertStringContainsString("if (\$e->getCode() === '23000') {", $this->action);
        $this->assertStringContainsString('You already have an application for this election.', $this->action);
    }

    // -------------------------------------------------------------------------
    // What cannot change, and when
    // -------------------------------------------------------------------------

    public function testApplicationsAreRefusedOnceVotingHasOpened(): void
    {
        // Accepting a late application would change the list of names underneath
        // people who have already voted.
        $this->assertStringContainsString("if (\$election['status'] !== 'draft') {", $this->action);
        $this->assertStringContainsString('Applications are closed for this election.', $this->action);
    }

    public function testARuledApplicationCannotBeReopenedByTheApplicant(): void
    {
        // Otherwise a rejected candidate has unlimited retries against a decision the
        // Committee already made.
        $this->assertStringContainsString(
            "if (\$existing && in_array(\$existing['status'], ['approved', 'rejected'], true)) {",
            $this->action
        );
    }

    public function testOnlyAPendingApplicationCanBeWithdrawn(): void
    {
        $this->assertStringContainsString("if (!\$existing || \$existing['status'] !== 'pending') {", $this->action);
        $this->assertStringContainsString("SET status='withdrawn'", $this->action);
    }

    public function testEditabilityRequiresBothAPendingApplicationAndADraftElection(): void
    {
        $this->assertTrue(vk_application_is_editable(['status' => 'pending'], 'draft'));
        $this->assertFalse(vk_application_is_editable(['status' => 'pending'], 'open'));
        $this->assertFalse(vk_application_is_editable(['status' => 'approved'], 'draft'));
        $this->assertFalse(vk_application_is_editable(['status' => 'rejected'], 'draft'));
        $this->assertFalse(vk_application_is_editable(null, 'draft'));
    }

    // -------------------------------------------------------------------------
    // What is accepted
    // -------------------------------------------------------------------------

    public function testThePositionMustBeOneTheGroupConfigured(): void
    {
        // Not whatever the form happened to post.
        $this->assertStringContainsString('if (!in_array($position, $positions, true)) {', $this->action);
        $this->assertStringContainsString('Choose a valid position.', $this->action);
    }

    public function testNobodyCanProposeThemselves(): void
    {
        $this->assertStringContainsString('if ($proposer === $member_id) {', $this->action);
        $this->assertStringContainsString('You cannot propose yourself.', $this->action);
    }

    public function testTheDeclarationAndStatementAreRequired(): void
    {
        $this->assertStringContainsString('if (!$declared) {', $this->action);
        $this->assertStringContainsString("if (\$statement === '') {", $this->action);
    }

    public function testAMotionVoteNeverTakesApplications(): void
    {
        // There is nobody to stand for a motion.
        $this->assertStringContainsString("\$election['vote_type'] !== 'candidate'", $this->action);
        $this->assertStringContainsString("WHERE vote_type = 'candidate' AND status = 'draft'",
            file_get_contents(__DIR__ . '/../../includes/leadership_helpers.php'));
    }

    // -------------------------------------------------------------------------
    // Positions come from the group, not from code
    // -------------------------------------------------------------------------

    public function testPositionsAreEditableRatherThanHardCoded(): void
    {
        $this->assertStringContainsString("'leadership_positions'", $this->migration);
        $this->assertStringContainsString('One per line.', $this->migration);
    }

    public function testAnUnconfiguredListBlocksApplicationsRatherThanInventingOne(): void
    {
        // A member choosing from a list the group never agreed to is worse than a
        // member being told to come back later.
        $helpers = file_get_contents(__DIR__ . '/../../includes/leadership_helpers.php');
        $this->assertStringContainsString('return [];', $helpers);
        $this->assertStringContainsString('Leadership positions have not been configured.', $this->action);
        $this->assertStringContainsString('Leadership positions have not been set up yet.', $this->page);
    }

    public function testTheSettingsColumnWasWidenedForTheList(): void
    {
        // varchar(255) held roughly six bilingual positions. A seventh would have been
        // truncated, and under a non-strict sql_mode silently — a position simply
        // vanishing from the form with no error anywhere.
        $this->assertStringContainsString("MODIFY `setting_value` TEXT NULL", $this->migration);
        $this->assertStringContainsString('information_schema.COLUMNS', $this->migration);
    }

    // -------------------------------------------------------------------------
    // Migration hygiene
    // -------------------------------------------------------------------------

    public function testTheMigrationIsIdempotentAndRegistered(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `leadership_applications`', $this->migration);
        $this->assertStringContainsString('SELECT permission_id FROM permissions WHERE page_key = ?', $this->migration);

        $runner = file_get_contents(__DIR__ . '/../../database/migrate.php');
        $this->assertStringContainsString("'create_leadership_applications_table.php'", $runner);
    }

    public function testSubmissionsAreAudited(): void
    {
        $this->assertStringContainsString("logCreate('Leadership Applications'", $this->action);
        $this->assertStringContainsString("logUpdate('Leadership Applications'", $this->action);
    }

    public function testMemberSuppliedTextIsEscapedOnThePage(): void
    {
        $this->assertStringContainsString("safe_output(\$app['statement'])", $this->page);
        $this->assertStringContainsString("safe_output(\$e['title'])", $this->page);
        $this->assertStringContainsString("safe_output(\$p)", $this->page);
    }

    public function testItIsRoutedAndInTheMenuBehindItsPermission(): void
    {
        $roots  = file_get_contents(__DIR__ . '/../../roots.php');
        $header = file_get_contents(__DIR__ . '/../../header.php');

        $this->assertStringContainsString("'leadership_application' => VOTING_DIR", $roots);
        $this->assertStringContainsString("canView('leadership_applications')", $header);
        $this->assertStringContainsString('Maombi ya Uongozi', $header);
    }
}
