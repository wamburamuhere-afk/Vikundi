<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module 3 (write surface): register, edit, approve, reject, reactivate.
 *
 * These endpoints create logins and move members between states, so the
 * properties worth pinning are the ones whose failure is silent: an upload that
 * is not what it claims to be, a column a caller was never meant to set, an
 * audit row filed against nobody.
 */
class MemberWritesApiTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/api_upload.php';
    }

    private static function src(string $rel): string
    {
        $path = dirname(__DIR__, 2) . '/' . $rel;
        self::assertFileExists($path, "{$rel} is missing.");
        return (string) file_get_contents($path);
    }

    /** Source with comments stripped — see MembersApiTest for why this matters. */
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($t[1], "\n"));
                    continue;
                }
                $out .= $t[1];
                continue;
            }
            $out .= $t;
        }
        return $out;
    }

    // — Upload validation ————————————————————————————————————————————————
    //
    // The web stores an upload under whatever extension the client's filename
    // carried, with no whitelist. The only thing stopping an uploaded .php from
    // executing is uploads/.htaccess, and that is a single control one server
    // migration away from being ignored.

    public function testExecutableExtensionsAreRefused(): void
    {
        foreach (['php', 'phtml', 'php5', 'sh', 'html', 'svg'] as $ext) {
            $reason = vk_api_validate_upload(
                ['name' => "payload.{$ext}", 'error' => UPLOAD_ERR_OK, 'size' => 100],
                5242880
            );
            $this->assertNotNull($reason, ".{$ext} must never be stored.");
        }
    }

    #[DataProvider('acceptedTypes')]
    public function testLegitimateSlipTypesAreAccepted(string $name, string $mime): void
    {
        $this->assertNull(
            vk_api_validate_upload(['name' => $name, 'error' => UPLOAD_ERR_OK, 'size' => 1000], 5242880, $mime),
            "{$name} should be accepted."
        );
    }

    public static function acceptedTypes(): array
    {
        return [
            ['slip.jpg', 'image/jpeg'],
            ['slip.jpeg', 'image/jpeg'],
            ['slip.png', 'image/png'],
            ['slip.pdf', 'application/pdf'],
            ['SLIP.PNG', 'image/png'],
        ];
    }

    /**
     * The attacker controls the filename and the declared Content-Type, but not
     * the bytes. A .php renamed to .png must fail on the sniffed type.
     */
    public function testContentsMustMatchTheExtension(): void
    {
        $reason = vk_api_validate_upload(
            ['name' => 'payload.png', 'error' => UPLOAD_ERR_OK, 'size' => 100],
            5242880,
            'text/x-php'
        );
        $this->assertNotNull($reason);
        $this->assertStringContainsString('do not match', $reason);
    }

    public function testOversizedAndEmptyFilesAreRefused(): void
    {
        $this->assertNotNull(vk_api_validate_upload(
            ['name' => 'slip.png', 'error' => UPLOAD_ERR_OK, 'size' => 99999999], 5242880, 'image/png'));
        $this->assertNotNull(vk_api_validate_upload(
            ['name' => 'slip.png', 'error' => UPLOAD_ERR_OK, 'size' => 0], 5242880, 'image/png'));
    }

    public function testUploadErrorsAreReportedRatherThanIgnored(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_CANT_WRITE] as $code) {
            $this->assertNotNull(vk_api_validate_upload(
                ['name' => 'slip.png', 'error' => $code, 'size' => 10], 5242880, 'image/png'));
        }
    }

    /** The stored name must be built from the whitelist, never from client text. */
    public function testTheStoredFilenameIsNotTakenFromTheClient(): void
    {
        $code = self::code('includes/api_upload.php');
        $this->assertStringContainsString('bin2hex(random_bytes(', $code);
        $this->assertStringContainsString('is_uploaded_file(', $code,
            'The temp path must be verified before finfo reads it.');
    }

    // — Registration ————————————————————————————————————————————————————

    public function testRegistrationKeepsTheSlipMandatory(): void
    {
        $code = self::code('api/v1/members_create.php');
        $this->assertStringContainsString("kianzio_slip", $code);
        $this->assertStringContainsString("'slip_required'", $code,
            'A member must not be creatable without the payment evidence the web requires.');
    }

    /**
     * The Member role id differs between installs — 13 fresh, 15 on the live
     * system — and role_id 2 is the Chairperson. A hard-coded fallback here is
     * exactly how update_user_role.php handed out admin access.
     */
    public function testRegistrationResolvesTheMemberRoleIdRatherThanAssumingIt(): void
    {
        $code = self::code('api/v1/members_create.php');
        $this->assertStringContainsString("FROM roles WHERE LOWER(role_name) = 'member'", $code);
        $this->assertDoesNotMatchRegularExpression(
            '/\$memberRoleId\s*=\s*\d+\s*;/',
            $code,
            'The Member role id must never be hard-coded.'
        );
    }

    public function testAFailedRegistrationDoesNotLeaveTheSlipOnDisk(): void
    {
        $this->assertStringContainsString('unlink(', self::code('api/v1/members_create.php'));
    }

    // — Editing ——————————————————————————————————————————————————————————

    /**
     * The request is a map of client-supplied keys. Building the UPDATE from
     * whatever arrives would let a caller set user_id (reassigning a profile to
     * another login), status, or is_deceased.
     */
    public function testOnlyWhitelistedColumnsAreWritable(): void
    {
        $code = self::code('api/v1/members_update.php');
        $this->assertStringContainsString('VK_MEMBER_EDITABLE', $code);

        $this->assertSame(1, preg_match('/const VK_MEMBER_EDITABLE = \[(.*?)\];/s', $code, $m));
        foreach (['user_id', 'customer_id', 'status', 'is_deceased', 'customer_code', 'created_by', 'role_id'] as $forbidden) {
            $this->assertStringNotContainsString(
                "'{$forbidden}'",
                $m[1],
                "'{$forbidden}' must not be client-writable."
            );
        }
    }

    public function testEditingRequiresTheEditPermissionNotMerelyView(): void
    {
        $this->assertMatchesRegularExpression(
            "/vk_api_require_permission\(\\\$auth,\s*'edit',\s*'customers'\)/",
            self::code('api/v1/members_update.php')
        );
    }

    public function testEditingRefusesToStealAnotherMembersPhoneNumber(): void
    {
        $code = self::code('api/v1/members_update.php');
        $this->assertStringContainsString("'phone_taken'", $code,
            'Registration rejects a duplicate phone; an edit must not be a way around it.');
    }

    // — Status changes ————————————————————————————————————————————————————

    /**
     * approve_member.php matches the customers row by EMAIL, which updates
     * nothing when the address differs in case or was never set — leaving
     * users.status and customers.status disagreeing about membership.
     */
    public function testStatusChangesMatchTheCustomerByIdNotEmail(): void
    {
        $code = self::code('api/v1/members_status_change.php');
        $this->assertStringContainsString('UPDATE customers SET status = ? WHERE customer_id = ?', $code);
        $this->assertStringNotContainsString('WHERE email = ?', $code);
    }

    /** A retry after a dropped response must not read as a failure. */
    public function testRepeatingAStatusChangeIsASuccessfulNoOp(): void
    {
        $code = self::code('api/v1/members_status_change.php');
        $this->assertStringContainsString("'changed'   => false", $code);
    }

    /** The shared body is routable; reached without a target it must refuse. */
    public function testTheSharedStatusBodyRefusesWhenReachedDirectly(): void
    {
        $code = self::code('api/v1/members_status_change.php');
        $this->assertMatchesRegularExpression(
            '/if \(!isset\(\$vkTargetStatus, \$vkAuditVerb\)\)/',
            $code
        );
    }

    #[DataProvider('statusEndpoints')]
    public function testEachStatusEndpointSetsExactlyOneTargetStatus(string $rel, string $status): void
    {
        $code = self::code($rel);
        $this->assertMatchesRegularExpression(
            "/\\\$vkTargetStatus\s*=\s*'{$status}'/",
            $code,
            "{$rel} must set the '{$status}' status."
        );
    }

    public static function statusEndpoints(): array
    {
        return [
            ['api/v1/members_approve.php', 'active'],
            ['api/v1/members_reject.php', 'rejected'],
            ['api/v1/members_reactivate.php', 'active'],
        ];
    }

    /** Only statuses the users enum accepts, or the UPDATE truncates silently. */
    public function testTargetStatusesAreValidForTheUsersEnum(): void
    {
        $allowed = ['pending', 'active', 'rejected', 'dormant'];
        foreach (self::statusEndpoints() as [$rel, $status]) {
            $this->assertContains($status, $allowed, "{$rel} targets a status the enum rejects.");
        }
    }

    // — Audit ——————————————————————————————————————————————————————————————

    /**
     * logActivity() resolves a user_id of 0 from $_SESSION. The API has no
     * session, so omitting the id files every mobile action against user 0 and
     * quietly empties the audit trail of attribution.
     */
    #[DataProvider('writingEndpoints')]
    public function testEveryWriteLogsAgainstTheTokensUser(string $rel): void
    {
        $code = self::code($rel);
        $this->assertMatchesRegularExpression(
            '/log(Create|Update|Delete)\([^;]*\$auth\[.user_id.\]\s*\)/s',
            $code,
            "{$rel} must pass \$auth['user_id'] to the audit logger explicitly."
        );
    }

    public static function writingEndpoints(): array
    {
        return [
            ['api/v1/members_create.php'],
            ['api/v1/members_update.php'],
            ['api/v1/members_status_change.php'],
        ];
    }

    /** Every write must be transactional across the users/customers pair. */
    #[DataProvider('writingEndpoints')]
    public function testWritesAreTransactional(string $rel): void
    {
        $code = self::code($rel);
        $this->assertStringContainsString('beginTransaction()', $code);
        $this->assertStringContainsString('rollBack()', $code);
    }
}
