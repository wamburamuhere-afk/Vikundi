<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_voting.php';

/**
 * Module 14 — Voting & Leadership Applications.
 *
 * Two features sharing one `votes` table: Elections (gated `manage_voting`
 * for leadership, `voting` for a member's own ballot) and Leadership
 * Applications (gated `leadership_applications` to apply, `manage_leadership_applications`
 * to review). Every permission check in the underlying web files was found
 * internally consistent (unlike the Documents module's `library` vs
 * `document_library` incident) — what was found and fixed instead is that
 * `voting` itself was never granted to Secretary/Treasurer by any migration,
 * only `manage_voting` was; see database/grant_voting_permission.php.
 *
 * DB-touching loaders/mutators (vk_api_election_load, vk_api_cast_vote,
 * vk_api_application_load) are exercised live, not here — same precedent as
 * every prior module's *_load()/DB-mutating functions.
 */
final class VotingApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    private static function auth(bool $leader, bool $admin = false, int $userId = 1): array
    {
        return [
            'user_id' => $userId,
            'role_id' => $admin ? 1 : ($leader ? 4 : 13),
            'permissions' => $leader || $admin
                ? [
                    'manage_voting'                   => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 1],
                    'voting'                           => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0],
                    'leadership_applications'          => ['view' => 1, 'create' => 1, 'edit' => 0, 'delete' => 0],
                    'manage_leadership_applications'   => ['view' => 1, 'create' => 1, 'edit' => 1, 'delete' => 0],
                ]
                : [
                    'voting'                  => ['view' => 1, 'create' => 0, 'edit' => 0, 'delete' => 0],
                    'leadership_applications' => ['view' => 1, 'create' => 1, 'edit' => 0, 'delete' => 0],
                ],
        ];
    }

    private static function electionRaw(array $over = []): array
    {
        return $over + [
            'id'              => 3,
            'title'           => 'Chairperson Election 2026',
            'description'     => 'Annual leadership election',
            'vote_type'       => 'candidate',
            'status'          => 'draft',
            'opens_at'        => null,
            'closes_at'       => null,
            'publish_results' => 0,
            'created_at'      => '2026-08-01 09:00:00',
        ];
    }

    private static function applicationRaw(array $over = []): array
    {
        return $over + [
            'id'                 => 9,
            'vote_id'            => 3,
            'election_title'     => 'Chairperson Election 2026',
            'election_status'    => 'draft',
            'member_id'          => 5,
            'position'           => 'Chairperson / Mwenyekiti',
            'statement'          => 'I would like to serve because...',
            'experience'         => null,
            'proposer_member_id' => null,
            'proposer_name'      => null,
            'declaration'        => 1,
            'status'             => 'pending',
            'review_note'        => null,
            'reviewed_by'        => null,
            'reviewer_name'      => null,
            'reviewed_at'        => null,
            'created_at'         => '2026-08-05 10:00:00',
            'updated_at'         => '2026-08-05 10:00:00',
        ];
    }

    // ── elections: the row ──────────────────────────────────────────────────

    public function testElectionRowNeverIncludesTheTally(): void
    {
        $this->assertArrayNotHasKey('tally', vk_api_election_row(self::electionRaw()));
    }

    public function testElectionRowPublishResultsIsBoolean(): void
    {
        $this->assertFalse(vk_api_election_row(self::electionRaw())['publish_results']);
        $this->assertTrue(vk_api_election_row(self::electionRaw(['publish_results' => 1]))['publish_results']);
    }

    public function testElectionRowCountsAreOnlyIncludedWhenSupplied(): void
    {
        $row = vk_api_election_row(self::electionRaw());
        $this->assertArrayNotHasKey('option_count', $row);
        $row2 = vk_api_election_row(self::electionRaw(['option_count' => '4', 'eligible_count' => '300', 'voted_count' => '12']));
        $this->assertSame(4, $row2['option_count']);
        $this->assertSame(300, $row2['eligible_count']);
        $this->assertSame(12, $row2['voted_count']);
    }

    public function testElectionRowBlankDescriptionIsNullNotEmptyString(): void
    {
        $this->assertNull(vk_api_election_row(self::electionRaw(['description' => '  ']))['description']);
    }

    // ── elections: lifecycle actions depend on status, not just permission ──

    public function testADraftElectionOffersEditAndOpenNotClose(): void
    {
        $actions = vk_api_election_actions(self::auth(true), 'draft');
        $this->assertTrue($actions['edit']);
        $this->assertTrue($actions['open']);
        $this->assertFalse($actions['close']);
        $this->assertTrue($actions['delete']);
    }

    public function testAnOpenElectionOffersCloseNotEditOrOpenOrDelete(): void
    {
        $actions = vk_api_election_actions(self::auth(true), 'open');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['open']);
        $this->assertTrue($actions['close']);
        $this->assertFalse($actions['delete'], 'an open election must be closed before it can be deleted');
    }

    public function testAClosedElectionOffersOnlyDelete(): void
    {
        $actions = vk_api_election_actions(self::auth(true), 'closed');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['open']);
        $this->assertFalse($actions['close']);
        $this->assertTrue($actions['delete']);
    }

    public function testAMemberIsOfferedNoLeadershipActions(): void
    {
        $actions = vk_api_election_actions(self::auth(false), 'draft');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['open']);
        $this->assertFalse($actions['delete']);
    }

    // ── elections: option building — mirrors actions/save_vote.php exactly ──

    public function testAMotionElectionAlwaysGetsTheFixedThreeOptions(): void
    {
        $options = vk_api_election_build_options('motion', ['ignored'], []);
        $this->assertSame([['Yes', null], ['No', null], ['Abstain', null]], $options);
    }

    public function testACandidateElectionSkipsBlankLabels(): void
    {
        $options = vk_api_election_build_options('candidate', ['Amina', '', '  ', 'Baraka'], []);
        $this->assertSame([['Amina', null], ['Baraka', null]], $options);
    }

    public function testACandidateElectionMayHaveZeroOptions(): void
    {
        // Deliberate: a leadership election starts empty and is filled by
        // approved member applications — see vk_vote_input_errors()'s own
        // doc comment.
        $this->assertSame([], vk_api_election_build_options('candidate', [], []));
    }

    public function testOptionLabelsAssociateWithMemberIdsByIndex(): void
    {
        $options = vk_api_election_build_options('candidate', ['Amina', 'Baraka'], [0 => '5', 1 => 'not-a-number']);
        $this->assertSame([['Amina', 5], ['Baraka', null]], $options);
    }

    // ── voting: member-facing open election row ──────────────────────────────

    public function testVotingOpenRowNeverIncludesTheTally(): void
    {
        $row = vk_api_voting_open_row(self::electionRaw(['status' => 'open']), [], false);
        $this->assertArrayNotHasKey('tally', $row);
        $this->assertArrayNotHasKey('status', $row, 'the caller already knows it is open — every row in this list is');
    }

    public function testVotingOpenRowCarriesTheCallersOwnHasVotedFlag(): void
    {
        $this->assertTrue(vk_api_voting_open_row(self::electionRaw(), [], true)['has_voted']);
        $this->assertFalse(vk_api_voting_open_row(self::electionRaw(), [], false)['has_voted']);
    }

    // ── leadership applications: the row ─────────────────────────────────────

    public function testApplicationRowProposerIsNullWhenNoneWasNamed(): void
    {
        $this->assertNull(vk_api_application_row(self::applicationRaw())['proposer']);
    }

    public function testApplicationRowProposerIsPopulatedWhenNamed(): void
    {
        $row = vk_api_application_row(self::applicationRaw(['proposer_member_id' => 11, 'proposer_name' => 'Juma Said']));
        $this->assertSame(['id' => 11, 'name' => 'Juma Said'], $row['proposer']);
    }

    public function testApplicationRowMemberFieldIsOmittedWhenNotJoined(): void
    {
        // The /mine context never selects member_name (the caller already
        // knows who they are) — the row must not fabricate one.
        $this->assertNull(vk_api_application_row(self::applicationRaw())['member']);
    }

    public function testApplicationRowMemberFieldIsPopulatedWhenJoined(): void
    {
        $row = vk_api_application_row(self::applicationRaw(['member_name' => 'Amina Hando']));
        $this->assertSame(['id' => 5, 'name' => 'Amina Hando'], $row['member']);
    }

    // ── leadership applications: actions ──────────────────────────────────────

    public function testOwnerMayEditAndWithdrawOnlyWhilePendingAndElectionDraft(): void
    {
        $auth = self::auth(false);
        $actions = vk_api_application_actions($auth, true, self::applicationRaw(), 'draft');
        $this->assertTrue($actions['edit']);
        $this->assertTrue($actions['withdraw']);
    }

    public function testOwnerCannotEditOnceTheElectionHasOpened(): void
    {
        $auth = self::auth(false);
        $actions = vk_api_application_actions($auth, true, self::applicationRaw(), 'open');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['withdraw']);
    }

    public function testOwnerCannotEditAnApprovedApplication(): void
    {
        $auth = self::auth(false);
        $actions = vk_api_application_actions($auth, true, self::applicationRaw(['status' => 'approved']), 'draft');
        $this->assertFalse($actions['edit']);
    }

    public function testANonOwnerNeverGetsEditOrWithdrawRegardlessOfPermission(): void
    {
        $actions = vk_api_application_actions(self::auth(true), false, self::applicationRaw(), 'draft');
        $this->assertFalse($actions['edit']);
        $this->assertFalse($actions['withdraw']);
    }

    public function testCommitteeMayReviewOnlyWhileElectionIsDraft(): void
    {
        $auth = self::auth(true);
        $draft = vk_api_application_actions($auth, false, self::applicationRaw(), 'draft');
        $open  = vk_api_application_actions($auth, false, self::applicationRaw(), 'open');
        $this->assertTrue($draft['approve']);
        $this->assertTrue($draft['reject']);
        $this->assertFalse($open['approve']);
        $this->assertFalse($open['reject']);
        $this->assertFalse($open['reset']);
    }

    public function testCommitteeMayNeverReviewAWithdrawnApplication(): void
    {
        $auth = self::auth(true);
        $actions = vk_api_application_actions($auth, false, self::applicationRaw(['status' => 'withdrawn']), 'draft');
        $this->assertFalse($actions['approve']);
        $this->assertFalse($actions['reject']);
        $this->assertFalse($actions['reset']);
    }

    public function testAMemberWithNoReviewPermissionNeverGetsReviewActions(): void
    {
        $actions = vk_api_application_actions(self::auth(false), false, self::applicationRaw(), 'draft');
        $this->assertFalse($actions['approve']);
        $this->assertFalse($actions['reject']);
        $this->assertFalse($actions['reset']);
    }

    // ── leadership applications: input validation ─────────────────────────────

    public function testNoConfiguredPositionsRefusesEveryApplication(): void
    {
        $errors = vk_api_application_input_errors(['position' => 'Chairperson', 'statement' => 'x', 'declaration' => 1], []);
        $this->assertNotEmpty($errors);
    }

    public function testAPositionNotInTheConfiguredListIsRejected(): void
    {
        $errors = vk_api_application_input_errors(
            ['position' => 'Emperor', 'statement' => 'x', 'declaration' => 1],
            ['Chairperson / Mwenyekiti', 'Secretary / Katibu']
        );
        $this->assertNotEmpty($errors);
    }

    public function testABlankStatementIsRejected(): void
    {
        $errors = vk_api_application_input_errors(
            ['position' => 'Chairperson / Mwenyekiti', 'statement' => '  ', 'declaration' => 1],
            ['Chairperson / Mwenyekiti']
        );
        $this->assertNotEmpty($errors);
    }

    public function testAMissingDeclarationIsRejected(): void
    {
        $errors = vk_api_application_input_errors(
            ['position' => 'Chairperson / Mwenyekiti', 'statement' => 'x'],
            ['Chairperson / Mwenyekiti']
        );
        $this->assertNotEmpty($errors);
    }

    public function testAValidApplicationPassesCleanly(): void
    {
        $errors = vk_api_application_input_errors(
            ['position' => 'Chairperson / Mwenyekiti', 'statement' => 'Why I am standing.', 'declaration' => 1],
            ['Chairperson / Mwenyekiti']
        );
        $this->assertSame([], $errors);
    }

    // ── the voting-permission grant fix ──────────────────────────────────────

    public function testVotingPermissionGrantMigrationExistsAndIsRegistered(): void
    {
        $this->assertFileExists(__DIR__ . '/../../database/grant_voting_permission.php');
        $this->assertStringContainsString('grant_voting_permission.php', self::code('database/migrate.php'));
    }

    public function testTheGrantMigrationGivesViewOnlyNotFullCrud(): void
    {
        // Matches Member's own scope for this key — voting is "cast your own
        // ballot," never a create/edit/delete workflow.
        $code = self::code('database/grant_voting_permission.php');
        $this->assertStringContainsString('can_view, can_create, can_edit, can_delete) VALUES (?, ?, 1, 0, 0, 0)', $code);
    }

    // ── structural: gates, secrecy, and cascades ──────────────────────────────

    public function testElectionsListGateComesBeforeAnyQuery(): void
    {
        $code  = self::code('api/v1/elections.php');
        $gate  = strpos($code, "vk_api_require_permission(\$auth, 'view', 'manage_voting')");
        $query = strpos($code, 'FROM votes v');
        $this->assertNotFalse($gate);
        $this->assertNotFalse($query);
        $this->assertLessThan($query, $gate);
    }

    public function testVotingOpenIsGatedOnVotingNotManageVoting(): void
    {
        $code = self::code('api/v1/voting_open.php');
        $this->assertStringContainsString("vk_api_require_permission(\$auth, 'view', 'voting')", $code);
        $this->assertStringNotContainsString('manage_voting', $code);
    }

    public function testCastingAVoteNeverWritesAnActivityLogEntry(): void
    {
        // The whole point of the secret-ballot design is undermined by an
        // audit row timestamping "member X voted in election Y" — matches
        // actions/cast_vote.php's own deliberate choice exactly.
        $code = self::code('api/v1/votes.php');
        $this->assertStringNotContainsString('logCreate', $code);
        $this->assertStringNotContainsString('logUpdate', $code);
    }

    public function testResultsEndpointHasNoLeadershipGateAtTheTop(): void
    {
        // Mirrors api/get_vote_results.php: any authenticated user may call
        // it; secrecy is enforced inside vk_api_election_results(), not by a
        // 403 at the door.
        $code = self::code('api/v1/elections_results.php');
        $this->assertStringNotContainsString('vk_api_require_permission', $code);
        $this->assertStringContainsString('vk_api_require_auth', $code);
    }

    public function testElectionDeleteCascadesToLeadershipApplications(): void
    {
        // Added to actions/delete_vote.php specifically because leaving it
        // out orphaned applications pointing at a deleted election id.
        $code = self::code('api/v1/elections_detail.php');
        $this->assertStringContainsString('leadership_applications', $code);
        $this->assertStringContainsString("election_open", $code);
    }

    public function testOpenRefusesUnderTwoOptionsBeforeTouchingEligibility(): void
    {
        $code = self::code('api/v1/elections_open.php');
        $tooFew = strpos($code, 'too_few_options');
        $eligibility = strpos($code, 'INSERT IGNORE INTO vote_eligibility');
        $this->assertNotFalse($tooFew);
        $this->assertNotFalse($eligibility);
        $this->assertLessThan($eligibility, $tooFew);
    }

    public function testRejectRequiresANonEmptyReason(): void
    {
        $code = self::code('api/v1/leadership-applications_reject.php');
        $this->assertStringContainsString('reason_required', $code);
    }

    public function testApproveRejectAndResetAllRefuseOnceVotingHasStarted(): void
    {
        foreach (['approve', 'reject', 'reset'] as $action) {
            $code = self::code("api/v1/leadership-applications_{$action}.php");
            $this->assertStringContainsString("election_status'] !== 'draft'", $code, "{$action} must check the election is still draft");
            $this->assertStringContainsString("status'] === 'withdrawn'", $code, "{$action} must refuse a withdrawn application");
        }
    }

    public function testResetDeletesTheBallotOptionItRemoves(): void
    {
        $code = self::code('api/v1/leadership-applications_reset.php');
        $this->assertStringContainsString('DELETE FROM vote_options WHERE id = ?', $code);
    }

    public function testEditingOwnApplicationChecksOwnershipBeforeEditability(): void
    {
        $code = self::code('api/v1/leadership-applications_detail.php');
        $ownership = strpos($code, 'not_found');
        $editable  = strpos($code, 'vk_application_is_editable');
        $this->assertNotFalse($ownership);
        $this->assertNotFalse($editable);
        $this->assertLessThan($editable, $ownership);
    }

    // ── routing ──────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/elections'                                => 'elections.php',
            'api/v1/elections/3'                              => 'elections_detail.php',
            'api/v1/elections/3/open'                         => 'elections_open.php',
            'api/v1/elections/3/close'                        => 'elections_close.php',
            'api/v1/elections/3/results'                      => 'elections_results.php',
            'api/v1/voting/open'                              => 'voting_open.php',
            'api/v1/votes'                                    => 'votes.php',
            'api/v1/leadership-positions'                     => 'leadership-positions.php',
            'api/v1/leadership-applications'                  => 'leadership-applications.php',
            'api/v1/leadership-applications/9'                => 'leadership-applications_detail.php',
            'api/v1/leadership-applications/mine'             => 'leadership-applications_mine.php',
            'api/v1/leadership-applications/9/withdraw'       => 'leadership-applications_withdraw.php',
            'api/v1/leadership-applications/9/approve'        => 'leadership-applications_approve.php',
            'api/v1/leadership-applications/9/reject'         => 'leadership-applications_reject.php',
            'api/v1/leadership-applications/9/reset'          => 'leadership-applications_reset.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } elseif (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }

    // ── auditing ────────────────────────────────────────────────────────────────

    public function testEveryReviewDecisionIsAuditedAgainstTheRealUser(): void
    {
        foreach (['approve', 'reject', 'reset'] as $action) {
            $this->assertMatchesRegularExpression(
                "/logUpdate\([^;]*\\\$auth\['user_id'\]\)/s",
                self::code("api/v1/leadership-applications_{$action}.php")
            );
        }
    }

    public function testElectionCreationIsAuditedAgainstTheRealUser(): void
    {
        $this->assertMatchesRegularExpression(
            "/logCreate\([^;]*\\\$auth\['user_id'\]\)/s",
            self::code('api/v1/elections_create.php')
        );
    }
}
