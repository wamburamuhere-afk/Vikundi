<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_settings.php';
// vk_api_users_validate_password() wraps reg_password_errors() but does not
// require this file itself — every real caller (api/v1/users.php,
// api/v1/users_detail.php) already requires it, so the test does too.
require_once __DIR__ . '/../../includes/registration_validator.php';

/**
 * Module 17 — Settings & Roles (Users, Roles & Permissions, System Settings,
 * Backups).
 *
 * GATING, deliberately uniform and different from every page_key-gated module
 * so far: every endpoint here calls vk_api_settings_require_admin($auth),
 * which is a direct vk_api_is_admin((int) $auth['role_id']) check — never
 * vk_api_can()/vk_api_require_permission(). Every web page this module mirrors
 * (users.php, add_user.php, edit_user.php, user_roles.php,
 * manage_permissions.php, system_settings.php, backup_restore.php) is
 * Admin/Chairperson-only by design ('users', 'user_roles' and
 * 'system_settings' are in includes/role_grants.php's vk_admin_only_keys()),
 * so this module's $auth only needs role_id — no permissions map is ever
 * consulted, unlike Communication's or Meetings'.
 *
 * vk_api_is_admin() (includes/api_auth.php) matches role_id 1, 2 or 12 —
 * Admin, Chairperson, and the third seeded admin-equivalent role — so tests
 * below exercise all three alongside a refusal for an ordinary Member.
 */
final class SettingsApiTest extends TestCase
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

    private static function auth(int $roleId): array
    {
        return ['user_id' => 1, 'role_id' => $roleId];
    }

    /**
     * A PDO that must never be queried — every branch under test fails
     * validation before touching the database. Same technique as
     * MeetingsApiTest/CommunicationApiTest's own fakePdoNeverCalled().
     */
    private static function fakePdoNeverCalled(): PDO
    {
        return new class extends PDO {
            public function __construct()
            {
                // deliberately not calling parent::__construct() — no connection
            }
        };
    }

    /**
     * Stands in for PDO wherever a validator only ever needs one scalar back
     * from fetchColumn() — vk_api_users_validate_role()'s existence check.
     */
    private static function fakePdoFetchColumn(mixed $value): PDO
    {
        return new class ($value) extends PDO {
            public function __construct(private mixed $value)
            {
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = [])
            {
                return new class ($this->value) {
                    public function __construct(private mixed $value)
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        return true;
                    }

                    public function fetchColumn(int $column = 0): mixed
                    {
                        return $this->value;
                    }
                };
            }
        };
    }

    /**
     * Stands in for PDO wherever a validator needs a canned row/id set back
     * from fetchAll() — vk_api_users_validate_unique()'s collision lookup and
     * vk_api_role_permissions_validate()'s permission-id existence lookup both
     * shape their check purely on whatever fetchAll() returns, so one generic
     * stub covers both. Records the bound params when a reference is passed.
     */
    private static function fakePdoFetchAll(array $rows, ?array &$bound = null): PDO
    {
        return new class ($rows, $bound) extends PDO {
            public function __construct(private array $rows, private ?array &$bound)
            {
            }

            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = [])
            {
                return new class ($this->rows, $this->bound) {
                    public function __construct(private array $rows, private ?array &$bound)
                    {
                    }

                    public function execute(?array $params = null): bool
                    {
                        $this->bound = $params;
                        return true;
                    }

                    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
                    {
                        return $this->rows;
                    }
                };
            }
        };
    }

    // ── vk_api_settings_require_admin(): the single gate for this whole module ─

    public function testRequireAdminAllowsRoleIdOne(): void
    {
        vk_api_settings_require_admin(self::auth(1));
        $this->assertTrue(true, 'no exception means the admin gate passed');
    }

    public function testRequireAdminAllowsRoleIdTwoChairperson(): void
    {
        vk_api_settings_require_admin(self::auth(2));
        $this->assertTrue(true, 'no exception means the admin gate passed');
    }

    public function testRequireAdminAllowsRoleIdTwelve(): void
    {
        vk_api_settings_require_admin(self::auth(12));
        $this->assertTrue(true, 'no exception means the admin gate passed');
    }

    public function testRequireAdminRefusesAnOrdinaryMember(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/forbidden/');
        $this->expectExceptionMessageMatches('/Admin\/Chairperson only/');
        vk_api_settings_require_admin(self::auth(13));
    }

    public function testRequireAdminRefusesRoleIdZero(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/forbidden/');
        vk_api_settings_require_admin(self::auth(0));
    }

    // ── vk_api_user_statuses(): the real enum ────────────────────────────────

    public function testUserStatusesAreThePendingActiveRejectedDormantEnum(): void
    {
        $this->assertSame(['pending', 'active', 'rejected', 'dormant'], vk_api_user_statuses());
    }

    public function testDeletedIsNotARealStatus(): void
    {
        // 'deleted' was a magic trigger value in the old
        // actions/update_user_status.php that ran an actual DELETE FROM users
        // before the real UPDATE ever ran — never a real column value. The API
        // must never accept it.
        $this->assertNotContains('deleted', vk_api_user_statuses());
    }

    // ── vk_api_user_row(): the shaped users row ──────────────────────────────

    private static function userRaw(array $over = []): array
    {
        return $over + [
            'user_id'    => 7,
            'username'   => 'jmwakyusa',
            'email'      => 'juma@example.com',
            'first_name' => 'Juma',
            'last_name'  => 'Mwakyusa',
            'role_id'    => 4,
            'role_name'  => 'Treasurer',
            'status'     => 'active',
            'created_at' => '2026-09-01 09:00:00',
            'last_login' => '2026-09-08 07:30:00',
        ];
    }

    public function testUserRowNeverIncludesThePasswordOrAnyHashDerivedField(): void
    {
        $row = vk_api_user_row(self::userRaw(['password' => '$2y$10$abundantlyhashedvalue']));
        $this->assertArrayNotHasKey('password', $row);
        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsStringIgnoringCase(
                'password',
                $key,
                "the shaped row must never carry a password-shaped key ($key)"
            );
        }
    }

    public function testUserRowShapesTheCoreFields(): void
    {
        $row = vk_api_user_row(self::userRaw());
        $this->assertSame(7, $row['user_id']);
        $this->assertSame('jmwakyusa', $row['username']);
        $this->assertSame('juma@example.com', $row['email']);
        $this->assertSame('Juma', $row['first_name']);
        $this->assertSame('Mwakyusa', $row['last_name']);
        $this->assertSame(4, $row['role_id']);
        $this->assertSame('Treasurer', $row['role_name']);
        $this->assertSame('active', $row['status']);
    }

    public function testUserRowRoleIdIsNullWhenAbsent(): void
    {
        $this->assertNull(vk_api_user_row(self::userRaw(['role_id' => null]))['role_id']);
    }

    public function testUserRowRoleNameDefaultsToNullWhenAbsent(): void
    {
        $raw = self::userRaw();
        unset($raw['role_name']);
        $this->assertNull(vk_api_user_row($raw)['role_name']);
    }

    public function testUserRowStatusDefaultsToPendingWhenAbsent(): void
    {
        $raw = self::userRaw();
        unset($raw['status']);
        $this->assertSame('pending', vk_api_user_row($raw)['status']);
    }

    public function testUserRowEmailFirstNameLastNameDefaultToEmptyStringWhenAbsent(): void
    {
        $raw = self::userRaw();
        unset($raw['email'], $raw['first_name'], $raw['last_name']);
        $row = vk_api_user_row($raw);
        $this->assertSame('', $row['email']);
        $this->assertSame('', $row['first_name']);
        $this->assertSame('', $row['last_name']);
    }

    public function testUserRowCreatedAtAndLastLoginAreNullWhenEmpty(): void
    {
        $row = vk_api_user_row(self::userRaw(['created_at' => null, 'last_login' => null]));
        $this->assertNull($row['created_at']);
        $this->assertNull($row['last_login']);
    }

    public function testUserRowCreatedAtAndLastLoginAreFormattedAsDateAtomWhenPresent(): void
    {
        $row = vk_api_user_row(self::userRaw());
        $this->assertSame(date(DATE_ATOM, strtotime('2026-09-01 09:00:00')), $row['created_at']);
        $this->assertSame(date(DATE_ATOM, strtotime('2026-09-08 07:30:00')), $row['last_login']);
    }

    // ── vk_api_users_filters(): pure validation, PDO is never queried ───────

    public function testUsersFiltersNoFiltersMeansNoConditions(): void
    {
        $this->assertSame([[], []], vk_api_users_filters(self::fakePdoNeverCalled(), []));
    }

    public function testUsersFiltersRoleIdIsOnlyAddedWhenPositive(): void
    {
        [$where, $params] = vk_api_users_filters(self::fakePdoNeverCalled(), ['role_id' => '4']);
        $this->assertSame(['u.role_id = ?'], $where);
        $this->assertSame([4], $params);
    }

    public function testUsersFiltersRoleIdZeroOrNegativeIsOmitted(): void
    {
        $this->assertSame([[], []], vk_api_users_filters(self::fakePdoNeverCalled(), ['role_id' => '0']));
        $this->assertSame([[], []], vk_api_users_filters(self::fakePdoNeverCalled(), ['role_id' => '-5']));
    }

    public function testUsersFiltersRejectsAnUnknownStatus(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_status/');
        vk_api_users_filters(self::fakePdoNeverCalled(), ['status' => 'deleted']);
    }

    public function testUsersFiltersAcceptsAKnownStatus(): void
    {
        [$where, $params] = vk_api_users_filters(self::fakePdoNeverCalled(), ['status' => 'dormant']);
        $this->assertSame(['u.status = ?'], $where);
        $this->assertSame(['dormant'], $params);
    }

    public function testUsersFiltersSearchProducesFourBoundLikes(): void
    {
        [$where, $params] = vk_api_users_filters(self::fakePdoNeverCalled(), ['search' => 'Juma']);
        $this->assertCount(1, $where);
        $this->assertSame(['%Juma%', '%Juma%', '%Juma%', '%Juma%'], $params);
        $this->assertStringNotContainsString('Juma', $where[0], 'the search term must be bound, never interpolated');
    }

    public function testUsersFiltersNeverTouchesTheDatabase(): void
    {
        // $pdo is accepted only for signature symmetry with the rest of this
        // module's *_filters()/validate_*() helpers — every branch here is
        // pure validation. A fatal from the never-called PDO would fail this.
        vk_api_users_filters(self::fakePdoNeverCalled(), ['role_id' => 4, 'status' => 'active', 'search' => 'x']);
        $this->assertTrue(true);
    }

    // ── vk_api_users_validate_role(): pure branch + the DB-backed branches ──

    public function testValidateRoleRejectsAZeroIdBeforeTouchingThePdo(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/role_required/');
        vk_api_users_validate_role(self::fakePdoNeverCalled(), 0);
    }

    public function testValidateRoleRejectsANonNumericValueThatCoercesToZero(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/role_required/');
        vk_api_users_validate_role(self::fakePdoNeverCalled(), null);
    }

    public function testValidateRoleRejectsANegativeId(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/role_required/');
        vk_api_users_validate_role(self::fakePdoNeverCalled(), -3);
    }

    public function testValidateRoleReturnsTheIdWhenTheRoleExists(): void
    {
        $this->assertSame(4, vk_api_users_validate_role(self::fakePdoFetchColumn(1), '4'));
    }

    public function testValidateRoleRejectsAnIdThatDoesNotExist(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/role_not_found/');
        vk_api_users_validate_role(self::fakePdoFetchColumn(false), 999);
    }

    // ── vk_api_users_validate_unique(): DB-backed, faked via the suite's ────
    // ── established "prepare()->execute()->fetchAll() returns a canned      ─
    // ── result set" pattern (see WorkflowActorTest/ContributionAccessTest). ─

    public function testValidateUniqueDoesNotThrowWhenNoRowsCollide(): void
    {
        vk_api_users_validate_unique(self::fakePdoFetchAll([]), 'newuser', 'new@example.com');
        $this->assertTrue(true, 'no exception means username/email are both free');
    }

    public function testValidateUniqueRejectsAnExistingUsernameCaseInsensitively(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/username_taken/');
        vk_api_users_validate_unique(
            self::fakePdoFetchAll([['username' => 'JMwakyusa', 'email' => 'other@example.com']]),
            'jmwakyusa',
            'new@example.com'
        );
    }

    public function testValidateUniqueRejectsAnExistingEmailCaseInsensitively(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/email_taken/');
        vk_api_users_validate_unique(
            self::fakePdoFetchAll([['username' => 'someoneelse', 'email' => 'Juma@Example.com']]),
            'newuser',
            'juma@example.com'
        );
    }

    public function testValidateUniquePassesTheExcludeIdThroughAsTheThirdBoundParam(): void
    {
        $bound = null;
        vk_api_users_validate_unique(self::fakePdoFetchAll([], $bound), 'jmwakyusa', 'juma@example.com', 7);
        $this->assertSame(['jmwakyusa', 'juma@example.com', 7], $bound);
    }

    public function testValidateUniqueExcludeIdDefaultsToZeroForANewUser(): void
    {
        $bound = null;
        vk_api_users_validate_unique(self::fakePdoFetchAll([], $bound), 'jmwakyusa', 'juma@example.com');
        $this->assertSame(['jmwakyusa', 'juma@example.com', 0], $bound);
    }

    // ── vk_api_users_validate_password(): wraps reg_password_errors() ───────

    public function testValidatePasswordRejectsAWeakPassword(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/weak_password/');
        vk_api_users_validate_password('abc');
    }

    public function testValidatePasswordAcceptsAStrongPassword(): void
    {
        vk_api_users_validate_password('Abcdefg1');
        $this->assertTrue(true, 'no exception means reg_password_errors() found nothing wrong');
    }

    public function testValidatePasswordUsesTheSuppliedLanguageForItsErrors(): void
    {
        try {
            vk_api_users_validate_password('abc', 'sw');
            $this->fail('expected a weak_password refusal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'Nywila',
                $e->getMessage(),
                'the $lang argument must actually reach reg_password_errors()'
            );
        }
    }

    // ── VK_API_PROTECTED_ROLE_ID ──────────────────────────────────────────

    public function testProtectedRoleIdIsOne(): void
    {
        $this->assertSame(1, VK_API_PROTECTED_ROLE_ID);
    }

    // ── vk_api_role_row(): the shaped roles row ──────────────────────────

    private static function roleRaw(array $over = []): array
    {
        return $over + ['role_id' => 3, 'role_name' => 'Secretary', 'description' => 'Handles minutes and records.'];
    }

    public function testRoleRowShapesTheCoreFields(): void
    {
        $row = vk_api_role_row(self::roleRaw());
        $this->assertSame(3, $row['role_id']);
        $this->assertSame('Secretary', $row['role_name']);
        $this->assertSame('Handles minutes and records.', $row['description']);
    }

    public function testRoleRowDescriptionDefaultsToNullWhenAbsent(): void
    {
        $raw = self::roleRaw();
        unset($raw['description']);
        $this->assertNull(vk_api_role_row($raw)['description']);
    }

    public function testRoleRowUserCountKeyIsAlwaysPresentButNullWhenNotSupplied(): void
    {
        $row = vk_api_role_row(self::roleRaw());
        $this->assertArrayHasKey('user_count', $row);
        $this->assertNull($row['user_count']);
    }

    public function testRoleRowUserCountIsCastToIntWhenSupplied(): void
    {
        $row = vk_api_role_row(self::roleRaw(['user_count' => '5']));
        $this->assertSame(5, $row['user_count']);
    }

    // ── vk_api_role_permission_row(): one permission row + its grant ────────

    private static function permRaw(array $over = []): array
    {
        return $over + [
            'permission_id' => 12,
            'page_key'      => 'meetings',
            'page_name'     => 'Meetings',
            'module_name'   => 'Governance',
            'description'   => 'Meeting management.',
        ];
    }

    public function testRolePermissionRowShapesTheCoreFieldsFromThePermissionAndItsGrant(): void
    {
        $row = vk_api_role_permission_row(
            self::permRaw(),
            ['can_view' => 1, 'can_create' => 0, 'can_edit' => 1, 'can_delete' => 0]
        );
        $this->assertSame(12, $row['permission_id']);
        $this->assertSame('meetings', $row['page_key']);
        $this->assertSame('Meetings', $row['page_name']);
        $this->assertSame('Governance', $row['module_name']);
        $this->assertTrue($row['can_view']);
        $this->assertFalse($row['can_create']);
        $this->assertTrue($row['can_edit']);
        $this->assertFalse($row['can_delete']);
    }

    public function testRolePermissionRowAnEmptyGrantMeansAllFourFlagsFalse(): void
    {
        $row = vk_api_role_permission_row(self::permRaw(), []);
        $this->assertFalse($row['can_view']);
        $this->assertFalse($row['can_create']);
        $this->assertFalse($row['can_edit']);
        $this->assertFalse($row['can_delete']);
    }

    public function testRolePermissionRowPageNameFallsBackToThePageKeyWhenAbsent(): void
    {
        $raw = self::permRaw();
        unset($raw['page_name']);
        $this->assertSame('meetings', vk_api_role_permission_row($raw, [])['page_name']);
    }

    public function testRolePermissionRowModuleNameAndDescriptionDefaultToNull(): void
    {
        $raw = self::permRaw();
        unset($raw['module_name'], $raw['description']);
        $row = vk_api_role_permission_row($raw, []);
        $this->assertNull($row['module_name']);
        $this->assertNull($row['description']);
    }

    // ── vk_api_role_permissions_validate() ───────────────────────────────
    // Pure branches use fakePdoNeverCalled(). The id-existence lookup branches
    // use fakePdoFetchAll() to hand back a canned "these ids are valid" set —
    // the same PDO-faking convention WorkflowActorTest/ContributionAccessTest
    // already use elsewhere in this suite.

    public function testRolePermissionsValidateRejectsANonArrayPayload(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/permissions_required/');
        vk_api_role_permissions_validate(self::fakePdoNeverCalled(), 'not-an-object');
    }

    public function testRolePermissionsValidateRejectsANullPayload(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/permissions_required/');
        vk_api_role_permissions_validate(self::fakePdoNeverCalled(), null);
    }

    public function testRolePermissionsValidateAnEmptyPayloadReturnsAnEmptyListWithoutTouchingThePdo(): void
    {
        $this->assertSame([], vk_api_role_permissions_validate(self::fakePdoNeverCalled(), []));
    }

    public function testRolePermissionsValidateRejectsNonArrayFlagsForAKnownId(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/invalid_permission_flags/');
        vk_api_role_permissions_validate(self::fakePdoFetchAll([5]), [5 => 'not-an-object']);
    }

    public function testRolePermissionsValidateRejectsAnUnknownPermissionId(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/permission_not_found/');
        vk_api_role_permissions_validate(self::fakePdoFetchAll([]), [5 => ['view' => 1]]);
    }

    public function testRolePermissionsValidateCreateWithoutViewIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/view_required/');
        vk_api_role_permissions_validate(
            self::fakePdoFetchAll([5]),
            [5 => ['view' => false, 'create' => true, 'edit' => false, 'delete' => false]]
        );
    }

    public function testRolePermissionsValidateEditWithoutViewIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/view_required/');
        vk_api_role_permissions_validate(self::fakePdoFetchAll([9]), [9 => ['view' => false, 'edit' => true]]);
    }

    public function testRolePermissionsValidateDeleteWithoutViewIsRefused(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/view_required/');
        vk_api_role_permissions_validate(self::fakePdoFetchAll([9]), [9 => ['view' => false, 'delete' => true]]);
    }

    public function testRolePermissionsValidateDropsARowWhereAllFourFlagsAreFalse(): void
    {
        $out = vk_api_role_permissions_validate(
            self::fakePdoFetchAll([5]),
            [5 => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false]]
        );
        $this->assertSame([], $out);
    }

    public function testRolePermissionsValidateKeepsAViewOnlyRow(): void
    {
        $out = vk_api_role_permissions_validate(self::fakePdoFetchAll([5]), [5 => ['view' => true]]);
        $this->assertSame(
            [['permission_id' => 5, 'view' => true, 'create' => false, 'edit' => false, 'delete' => false]],
            $out
        );
    }

    public function testRolePermissionsValidateKeepsAFullyGrantedRow(): void
    {
        $out = vk_api_role_permissions_validate(
            self::fakePdoFetchAll([5]),
            [5 => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true]]
        );
        $this->assertSame(
            [['permission_id' => 5, 'view' => true, 'create' => true, 'edit' => true, 'delete' => true]],
            $out
        );
    }

    public function testRolePermissionsValidateProcessesMultipleEntriesIndependently(): void
    {
        $out = vk_api_role_permissions_validate(self::fakePdoFetchAll([5, 9]), [
            5 => ['view' => true, 'create' => true],
            9 => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
        ]);
        $this->assertCount(1, $out, 'the all-false row must be dropped, leaving only the granted one');
        $this->assertSame(5, $out[0]['permission_id']);
    }

    // ── vk_api_settings_get() / vk_api_settings_save() ───────────────────
    //
    // Both are thin DB passthroughs ($pdo->query()/$pdo->prepare()->execute()
    // with no branching logic to exercise) — skipped per this module's own
    // note, rather than building a full result-set-cycling PDO fake for
    // near-zero return. Covered indirectly: every test below that exercises
    // vk_api_settings_shape() proves the flat-map -> section-grouped contract
    // vk_api_settings_get()'s return value must satisfy.

    // ── VK_API_SETTINGS_SECTIONS: the shape both GET and PUT are built from ─

    public function testSettingsSectionsHasExactlyFourSections(): void
    {
        $this->assertSame(['general', 'email', 'sms', 'security'], array_keys(VK_API_SETTINGS_SECTIONS));
    }

    public function testGeneralSectionHasItsNineFields(): void
    {
        $this->assertSame(
            ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website', 'currency', 'timezone', 'date_format', 'items_per_page'],
            VK_API_SETTINGS_SECTIONS['general']
        );
    }

    public function testEmailSectionHasItsEightFields(): void
    {
        $this->assertSame(
            ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'from_email', 'from_name', 'enable_email_notifications'],
            VK_API_SETTINGS_SECTIONS['email']
        );
    }

    public function testSmsSectionHasItsFiveFields(): void
    {
        $this->assertSame(
            ['sms_gateway_type', 'sms_api_key', 'sms_api_secret', 'sms_sender_id', 'enable_sms_notifications'],
            VK_API_SETTINGS_SECTIONS['sms']
        );
    }

    public function testSecuritySectionHasItsSixFields(): void
    {
        $this->assertSame(
            ['session_timeout', 'max_login_attempts', 'password_expiry_days', 'require_strong_password', 'enable_2fa', 'enable_audit_log'],
            VK_API_SETTINGS_SECTIONS['security']
        );
    }

    // ── vk_api_settings_shape(): flat map -> grouped sections ────────────

    public function testSettingsShapeIncludesEveryKeyFromEverySectionEvenWhenMissingFromTheFlatMap(): void
    {
        $shaped = vk_api_settings_shape([]);
        foreach (VK_API_SETTINGS_SECTIONS as $section => $keys) {
            $this->assertArrayHasKey($section, $shaped);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $shaped[$section], "$section.$key must appear even when absent");
                $this->assertNull($shaped[$section][$key]);
            }
        }
    }

    public function testSettingsShapeCarriesThroughSuppliedValues(): void
    {
        $shaped = vk_api_settings_shape(['company_name' => 'Umoja VICOBA', 'smtp_port' => '587']);
        $this->assertSame('Umoja VICOBA', $shaped['general']['company_name']);
        $this->assertSame('587', $shaped['email']['smtp_port']);
    }

    public function testSettingsShapeGroupDefaultsToAnEmptyArrayWhenGroupSettingsIsAbsent(): void
    {
        $this->assertSame([], vk_api_settings_shape([])['group']);
    }

    public function testSettingsShapeGroupDegradesToAnEmptyArrayOnMalformedJson(): void
    {
        $shaped = vk_api_settings_shape(['group_settings' => 'not json at all']);
        $this->assertSame([], $shaped['group'], 'a malformed group_settings value must degrade silently, not error');
    }

    public function testSettingsShapeGroupDecodesValidJson(): void
    {
        $shaped = vk_api_settings_shape(['group_settings' => '{"org_type":"savings_group","logo":"x.png"}']);
        $this->assertSame(['org_type' => 'savings_group', 'logo' => 'x.png'], $shaped['group']);
    }

    // ── structural: users.php ─────────────────────────────────────────────

    public function testUsersEndpointAcceptsGetAndPostOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET', 'POST'])", self::code('api/v1/users.php'));
    }

    public function testUsersEndpointIsNotGatedThroughAPageKeyPermission(): void
    {
        $code = self::code('api/v1/users.php');
        $this->assertStringNotContainsString('vk_api_require_permission(', $code);
        $this->assertStringNotContainsString('vk_api_can(', $code);
    }

    public function testUsersEndpointNeverCreatesAMatchingCustomerRow(): void
    {
        $this->assertStringNotContainsString('customers', self::code('api/v1/users.php'));
    }

    public function testUsersEndpointCreateValidatesUsernameLength(): void
    {
        $this->assertStringContainsString('strlen($username) < 4', self::code('api/v1/users.php'));
    }

    public function testUsersEndpointCreateValidatesEmailFormat(): void
    {
        $this->assertStringContainsString('filter_var($email, FILTER_VALIDATE_EMAIL)', self::code('api/v1/users.php'));
    }

    public function testUsersEndpointCreateStatusDefaultsToActive(): void
    {
        $this->assertStringContainsString("(string) (\$body['status'] ?? 'active')", self::code('api/v1/users.php'));
    }

    public function testUsersEndpointCreateValidatesStatusAgainstTheRealEnum(): void
    {
        $this->assertStringContainsString('vk_api_user_statuses()', self::code('api/v1/users.php'));
    }

    public function testUsersEndpointCreateRunsRoleUniquenessAndPasswordValidationsInOrder(): void
    {
        $code   = self::code('api/v1/users.php');
        $role   = strpos($code, 'vk_api_users_validate_role(');
        $unique = strpos($code, 'vk_api_users_validate_unique(');
        $pass   = strpos($code, 'vk_api_users_validate_password(');
        $this->assertNotFalse($role);
        $this->assertNotFalse($unique);
        $this->assertNotFalse($pass);
        $this->assertLessThan($unique, $role);
        $this->assertLessThan($pass, $unique);
    }

    public function testUsersEndpointHashesThePasswordBeforeStoringIt(): void
    {
        $this->assertStringContainsString('password_hash($password, PASSWORD_DEFAULT)', self::code('api/v1/users.php'));
    }

    public function testUsersEndpointBodyIsNotParsedBeforeTheAdminGate(): void
    {
        $code  = self::code('api/v1/users.php');
        $admin = strpos($code, 'vk_api_settings_require_admin($auth);');
        $body  = strpos($code, 'vk_api_body()');
        $this->assertNotFalse($admin);
        $this->assertNotFalse($body);
        $this->assertLessThan($body, $admin);
    }

    // ── structural: users_detail.php ─────────────────────────────────────

    public function testUsersDetailAcceptsGetAndPutOnlyNoDelete(): void
    {
        $code = self::code('api/v1/users_detail.php');
        $this->assertStringContainsString("vk_api_require_method(['GET', 'PUT'])", $code);
        $this->assertStringNotContainsString('DELETE', $code);
    }

    public function testUsersDetailPasswordIsOptionalOnEdit(): void
    {
        $this->assertStringContainsString("if (\$password !== '')", self::code('api/v1/users_detail.php'));
    }

    public function testUsersDetailUniquenessCheckExcludesTheEditedRowItself(): void
    {
        $this->assertStringContainsString(
            'vk_api_users_validate_unique($pdo, $username, $email, $id)',
            self::code('api/v1/users_detail.php')
        );
    }

    public function testUsersDetailValidatesStatusAgainstTheRealEnumNotAHardcodedList(): void
    {
        $code = self::code('api/v1/users_detail.php');
        $this->assertStringContainsString('vk_api_user_statuses()', $code);
        $this->assertStringNotContainsString(
            "'deleted'",
            $code,
            'status=deleted must be refused by the shared enum check, never special-cased here'
        );
    }

    public function testUsersDetailHasNoDeleteQueryAnywhere(): void
    {
        $this->assertStringNotContainsString('DELETE FROM users', self::code('api/v1/users_detail.php'));
    }

    public function testUsersDetailLoadHappensAfterTheAdminGate(): void
    {
        $code  = self::code('api/v1/users_detail.php');
        $admin = strpos($code, 'vk_api_settings_require_admin($auth);');
        $load  = strpos($code, '$load = function');
        $this->assertNotFalse($admin);
        $this->assertNotFalse($load);
        $this->assertLessThan($load, $admin);
    }

    // ── structural: roles.php ────────────────────────────────────────────

    public function testRolesEndpointIsGetOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET'])", self::code('api/v1/roles.php'));
    }

    public function testRolesEndpointIsReadOnlyNoWrites(): void
    {
        $code = self::code('api/v1/roles.php');
        $this->assertStringNotContainsString('INSERT INTO roles', $code);
        $this->assertStringNotContainsString('UPDATE roles', $code);
        $this->assertStringNotContainsString('DELETE FROM roles', $code);
    }

    // ── structural: roles_permissions.php ────────────────────────────────

    public function testRolesPermissionsAcceptsGetAndPutOnly(): void
    {
        $this->assertStringContainsString(
            "vk_api_require_method(['GET', 'PUT'])",
            self::code('api/v1/roles_permissions.php')
        );
    }

    public function testRolesPermissionsRefusesWritingToTheProtectedRoleBeforeParsingTheBody(): void
    {
        $code           = self::code('api/v1/roles_permissions.php');
        $protectedCheck = strpos($code, 'VK_API_PROTECTED_ROLE_ID');
        $bodyParse      = strpos($code, 'vk_api_body()');
        $validate       = strpos($code, 'vk_api_role_permissions_validate(');
        $this->assertNotFalse($protectedCheck);
        $this->assertNotFalse($bodyParse);
        $this->assertNotFalse($validate);
        $this->assertLessThan($bodyParse, $protectedCheck, 'the protected-role check must run before the body is even parsed');
        $this->assertLessThan($validate, $bodyParse);
    }

    public function testRolesPermissionsProtectedRoleCheckSitsInsideThePutBranchNotBeforeIt(): void
    {
        // GET must still work for role_id 1 (read-only viewing isn't blocked,
        // only writing) — the protected-role refusal must sit after the PUT
        // branch opens, not ahead of the method split.
        $code           = self::code('api/v1/roles_permissions.php');
        $putBranch      = strpos($code, "if (\$_SERVER['REQUEST_METHOD'] === 'PUT')");
        $protectedCheck = strpos($code, 'VK_API_PROTECTED_ROLE_ID', $putBranch === false ? 0 : $putBranch);
        $this->assertNotFalse($putBranch);
        $this->assertNotFalse($protectedCheck);
        $this->assertGreaterThan($putBranch, $protectedCheck);
    }

    public function testRolesPermissionsProtectedRoleErrorMatchesTheWebsOwnRule(): void
    {
        $this->assertStringContainsString(
            "vk_api_error(403, 'protected_role', 'The Admin role is protected and its permissions cannot be modified.')",
            self::code('api/v1/roles_permissions.php')
        );
    }

    // ── structural: settings_system.php ──────────────────────────────────

    public function testSettingsSystemAcceptsGetAndPutOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET', 'PUT'])", self::code('api/v1/settings_system.php'));
    }

    public function testSettingsSystemOnlyValidatesSectionsPresentInTheBody(): void
    {
        $code = self::code('api/v1/settings_system.php');
        $this->assertStringContainsString('foreach (VK_API_SETTINGS_SECTIONS as $section => $keys)', $code);
        $this->assertStringContainsString("if (!array_key_exists(\$section, \$body)) {", $code);
    }

    public function testSettingsSystemRefusesANonObjectSection(): void
    {
        $this->assertStringContainsString(
            "vk_api_error(422, 'invalid_section', \"\$section must be an object.\")",
            self::code('api/v1/settings_system.php')
        );
    }

    public function testSettingsSystemRefusesAnEmptyPutWithNothingToSave(): void
    {
        $this->assertStringContainsString(
            "vk_api_error(422, 'nothing_to_save', 'Provide at least one of: general, email, sms, security, group.')",
            self::code('api/v1/settings_system.php')
        );
    }

    public function testSettingsSystemGroupSectionIsHandledSeparatelyFromTheFourFixedSections(): void
    {
        $code = self::code('api/v1/settings_system.php');
        $this->assertStringContainsString("if (array_key_exists('group', \$body)) {", $code);
        $this->assertStringContainsString("vk_api_settings_save(\$pdo, 'group_settings', json_encode(\$body['group']))", $code);
    }

    public function testSettingsSystemResponseIsShapedThroughTheSharedShapeFunction(): void
    {
        $this->assertStringContainsString(
            'vk_api_settings_shape(vk_api_settings_get($pdo))',
            self::code('api/v1/settings_system.php')
        );
    }

    // ── structural: settings_backup.php ──────────────────────────────────

    public function testSettingsBackupAcceptsGetAndPostOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET', 'POST'])", self::code('api/v1/settings_backup.php'));
    }

    public function testSettingsBackupUsesThePurePhpDumpEngineNotExecMysqldump(): void
    {
        $code = self::code('api/v1/settings_backup.php');
        $this->assertStringContainsString('vikundi_write_dump($pdo, $filepath)', $code);
        $this->assertStringNotContainsString('exec(', $code);
        $this->assertStringNotContainsString('mysqldump', $code);
    }

    public function testSettingsBackupHasNoRestoreAction(): void
    {
        $this->assertStringNotContainsString('restore', self::code('api/v1/settings_backup.php'));
    }

    public function testSettingsBackupListOnlyReturnsSqlFiles(): void
    {
        $this->assertStringContainsString(
            "pathinfo(\$file, PATHINFO_EXTENSION) !== 'sql'",
            self::code('api/v1/settings_backup.php')
        );
    }

    public function testSettingsBackupFilenameFollowsTheTimestampedSqlPattern(): void
    {
        $this->assertStringContainsString(
            "'backup_v_' . date('Y-m-d_H-i-s') . '.sql'",
            self::code('api/v1/settings_backup.php')
        );
    }

    // ── structural: backup-download.php ──────────────────────────────────

    public function testBackupDownloadIsGetOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET'])", self::code('api/v1/backup-download.php'));
    }

    public function testBackupDownloadResolvesAndPrefixChecksThePathBeforeServingIt(): void
    {
        $code = self::code('api/v1/backup-download.php');
        $this->assertStringContainsString('realpath(', $code);
        $this->assertStringContainsString('strpos($filepath, $backupsDir) !== 0', $code);
    }

    public function testBackupDownloadRequiresTheSqlExtension(): void
    {
        $this->assertStringContainsString(
            "pathinfo(\$filepath, PATHINFO_EXTENSION) !== 'sql'",
            self::code('api/v1/backup-download.php')
        );
    }

    // ── every endpoint: the shared admin gate, in the right place ────────

    private const ALL_ENDPOINTS = [
        'api/v1/users.php',
        'api/v1/users_detail.php',
        'api/v1/roles.php',
        'api/v1/roles_permissions.php',
        'api/v1/settings_system.php',
        'api/v1/settings_backup.php',
        'api/v1/backup-download.php',
    ];

    public function testEveryNewEndpointReachesTheApiAuthMarker(): void
    {
        foreach (self::ALL_ENDPOINTS as $file) {
            $this->assertStringContainsString('vk_api_require_auth(', self::code($file), $file);
        }
    }

    public function testEveryNewEndpointGatesOnTheSettingsAdminCheckNotAPageKeyPermission(): void
    {
        foreach (self::ALL_ENDPOINTS as $file) {
            $code = self::code($file);
            $this->assertStringContainsString('vk_api_settings_require_admin($auth)', $code, $file);
            $this->assertStringNotContainsString('vk_api_require_permission(', $code, $file);
        }
    }

    public function testEveryNewEndpointChecksAdminImmediatelyAfterAuth(): void
    {
        foreach (self::ALL_ENDPOINTS as $file) {
            $code  = self::code($file);
            $auth  = strpos($code, '$auth = vk_api_require_auth();');
            $admin = strpos($code, 'vk_api_settings_require_admin($auth);');
            $this->assertNotFalse($auth, $file);
            $this->assertNotFalse($admin, $file);
            $this->assertLessThan($admin, $auth, $file);
        }
    }

    // ── auditing ────────────────────────────────────────────────────────

    public function testEveryWriteStampsTheSessionUserIdBeforeLogging(): void
    {
        foreach ([
            'api/v1/users.php', 'api/v1/users_detail.php', 'api/v1/roles_permissions.php',
            'api/v1/settings_system.php', 'api/v1/settings_backup.php',
        ] as $file) {
            $this->assertStringContainsString("\$_SESSION['user_id'] = \$callerId", self::code($file), $file);
        }
    }

    public function testEveryWriteIsAuditedAgainstTheRealUser(): void
    {
        foreach ([
            'api/v1/users.php'             => 'logCreate',
            'api/v1/users_detail.php'      => 'logUpdate',
            'api/v1/roles_permissions.php' => 'logUpdate',
            'api/v1/settings_system.php'   => 'logUpdate',
            'api/v1/settings_backup.php'   => 'logActivity',
        ] as $file => $fn) {
            $code = self::code($file);
            $this->assertStringContainsString("\$callerId = (int) \$auth['user_id']", $code, $file);
            $this->assertMatchesRegularExpression(
                "/{$fn}\([^;]*\\\$callerId\)/s",
                $code,
                "$file: $fn must be audited against \$callerId"
            );
        }
    }

    // ── routing ─────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/users'               => 'users.php',
            'api/v1/users/7'             => 'users_detail.php',
            'api/v1/roles'               => 'roles.php',
            'api/v1/roles/3/permissions' => 'roles_permissions.php',
            'api/v1/settings/system'     => 'settings_system.php',
            'api/v1/settings/backup'     => 'settings_backup.php',
            'api/v1/backup-download'     => 'backup-download.php',
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
}
