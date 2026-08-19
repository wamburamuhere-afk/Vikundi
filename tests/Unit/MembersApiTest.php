<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module 3 (read surface): the members roster, member detail, dormant list and
 * group settings.
 *
 * The property that matters here is not "does it return rows" but "who is told
 * what". A savings group's roster is legitimately visible to every member — they
 * are in a group together — while each other's phone number, NIDA, address, next
 * of kin and opening balance are not. The web enforces that with
 * vk_mask_member_row(); these endpoints must use the same function rather than
 * a second list of sensitive fields, or adding a column to one will silently
 * expose it in the other.
 *
 * Masking must also happen server-side. A template can omit a field it was
 * given; JSON cannot. Anything placed in the body is readable by whoever holds
 * the token, whatever the app chooses to render.
 */
class MembersApiTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function src(string $rel): string
    {
        $path = self::root() . '/' . $rel;
        self::assertFileExists($path, "{$rel} is missing.");
        return (string) file_get_contents($path);
    }

    /**
     * Source with comments stripped, string literals intact.
     *
     * Every one of these files explains in its docblock what it does — naming
     * vk_mask_member_row(), the permissions it checks, the keys it excludes.
     * Asserting against the raw file therefore passes on the PROSE: deleting the
     * masking call from the body left the docblock mention behind and the test
     * stayed green. Assertions about code must see only code.
     */
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }
        return $out;
    }

    /** Endpoints that return member rows and therefore must mask them. */
    public static function memberListingEndpoints(): array
    {
        return [
            ['api/v1/members.php'],
            ['api/v1/members_detail.php'],
            ['api/v1/members_dormant.php'],
        ];
    }

    // — Masking ————————————————————————————————————————————————————————————

    #[DataProvider('memberListingEndpoints')]
    public function testEveryMemberEndpointMasksWithTheSharedFunction(string $rel): void
    {
        $this->assertStringContainsString(
            'vk_mask_member_row(',
            self::code($rel),
            "{$rel} must mask with the shared helper, not its own field list — otherwise a new "
            . 'sensitive column gets masked on the web and leaked here.'
        );
    }

    #[DataProvider('memberListingEndpoints')]
    public function testEveryMemberEndpointRequiresTheCustomersViewPermission(string $rel): void
    {
        $src = self::code($rel);
        $this->assertStringContainsString('vk_api_require_auth()', $src);
        $this->assertMatchesRegularExpression(
            "/vk_api_require_permission\(\\\$auth,\s*'view',\s*'customers'\)/",
            $src,
            "{$rel} must be gated on the same permission as the web roster."
        );
    }

    /**
     * The masking decision must mirror core/permissions.php::canSeeMemberSensitiveData():
     * admins, plus anyone who may edit members. A Treasurer can edit members and
     * therefore sees contact details; an ordinary Member cannot and does not.
     */
    #[DataProvider('memberListingEndpoints')]
    public function testSensitivityIsDecidedByAdminOrEditPermission(string $rel): void
    {
        $src = self::code($rel);
        $this->assertStringContainsString('vk_role_is_admin(', $src);
        $this->assertMatchesRegularExpression(
            "/vk_api_can\(\\\$auth,\s*'edit',\s*'customers'\)/",
            $src,
            "{$rel} must use the 'edit customers' permission to decide sensitivity."
        );
    }

    /** A caller always sees their own record in full, on every listing. */
    #[DataProvider('memberListingEndpoints')]
    public function testACallerIsNeverMaskedFromTheirOwnRecord(string $rel): void
    {
        $this->assertMatchesRegularExpression(
            '/\$isSelf/',
            self::code($rel),
            "{$rel} must exempt the caller's own record from masking."
        );
    }

    // — The self-only rule on detail ————————————————————————————————————————

    /**
     * The web detail page confines an ordinary member to their own record. The
     * refusal must come BEFORE the record is fetched, or a caller learns whether
     * an id exists by the difference between 403 and 404.
     */
    public function testMemberDetailRefusesOtherRecordsBeforeReadingThem(): void
    {
        $src = self::code('api/v1/members_detail.php');

        $forbidden = strpos($src, "vk_api_error(403, 'forbidden'");
        $this->assertNotFalse($forbidden, 'Detail must refuse a member reading another record.');

        $query = strpos($src, 'SELECT c.*');
        $this->assertNotFalse($query);
        $this->assertLessThan(
            $query,
            $forbidden,
            'The 403 must be decided before the row is read, so a probing caller cannot '
            . 'distinguish "exists but forbidden" from "does not exist".'
        );
    }

    // — Pagination —————————————————————————————————————————————————————————

    /**
     * A mobile client on a Tanzanian mobile connection must not be able to ask
     * for the entire members table in one response, deliberately or by a typo in
     * per_page.
     */
    #[DataProvider('paginatedEndpoints')]
    public function testPageSizeIsClamped(string $rel): void
    {
        $this->assertMatchesRegularExpression(
            '/min\(\s*100\s*,/',
            self::code($rel),
            "{$rel} must clamp per_page to a maximum."
        );
        $this->assertMatchesRegularExpression(
            '/max\(\s*1\s*,/',
            self::code($rel),
            "{$rel} must floor per_page at 1, or an offset calculation goes negative."
        );
    }

    public static function paginatedEndpoints(): array
    {
        return [['api/v1/members.php'], ['api/v1/members_dormant.php']];
    }

    // — Group settings —————————————————————————————————————————————————————

    /**
     * group_settings is a free-form key/value table that already holds
     * operational state (auto_termination_last_run, a cached group_balance).
     * Returning it wholesale would publish whatever anyone stores there next.
     */
    public function testGroupSettingsWhitelistsKeysInsteadOfDumpingTheTable(): void
    {
        $src = self::src('api/v1/group-settings.php');

        $this->assertStringContainsString('VK_GROUP_SETTING_KEYS', $src);

        // Assert on the whitelist itself, not the whole file: the docblock names
        // the operational keys precisely to explain why they are excluded, and
        // matching the file would test the wording of a comment.
        $this->assertSame(
            1,
            preg_match('/const VK_GROUP_SETTING_KEYS = \[(.*?)\];/s', $src, $m),
            'The whitelist constant must be declared as an array literal.'
        );
        foreach (['auto_termination_last_run', 'group_balance'] as $operational) {
            $this->assertStringNotContainsString(
                $operational,
                $m[1],
                "'{$operational}' is operational state, not client configuration."
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/vk_api_ok\(\s*\[\s*.{0,40}\$raw\b/s',
            $src,
            'The raw settings table must never be returned directly.'
        );
    }

    /**
     * The web page gated on ['Admin','Secretary','Katibu'] — omitting
     * 'Chairperson', the exact name seed_vicoba_roles.php creates — so the head
     * of the group could not open the group's own settings. Same defect the
     * dashboard had.
     */
    public function testGroupSettingsPageUsesTheSharedAdminDefinition(): void
    {
        $src = self::code('app/bms/customer/group_settings.php');

        $this->assertStringContainsString('vk_role_is_admin(', $src);
        $this->assertDoesNotMatchRegularExpression(
            "/in_array\(\s*\\\$user_role\s*,\s*\[\s*'Admin'\s*,\s*'Secretary'\s*,\s*'Katibu'\s*\]\s*\)/",
            $src,
            "The hard-coded list omitted 'Chairperson' and locked the group's chairperson "
            . 'out of their own settings.'
        );
    }

    // — Routing ————————————————————————————————————————————————————————————

    public function testTheRouterMapsNumericIdsAndStaticSubResources(): void
    {
        $src = self::src('roots.php');

        $this->assertStringContainsString('^api/v1/([a-z0-9-]+)/(\d+)', $src, 'Numeric id route missing.');
        $this->assertStringContainsString('^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$', $src, 'Static sub-resource route missing.');
        $this->assertStringContainsString('is_file($target)', $src, 'The resolved handler must be verified to exist.');
    }

    /**
     * A directory under api/v1 whose name matches a collection endpoint file
     * breaks that endpoint: mod_dir's DirectorySlash sees the directory and
     * 301-redirects /api/v1/members to /api/v1/members/, so the list stops
     * answering. Found the hard way — the list endpoint returned 301 until the
     * handlers were flattened to members_detail.php / members_dormant.php.
     */
    public function testNoDirectoryUnderApiV1ShadowsACollectionEndpoint(): void
    {
        $base = self::root() . '/api/v1';
        $this->assertDirectoryExists($base);

        $clashes = [];
        foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            if (is_file($base . '/' . $entry->getFilename() . '.php')) {
                $clashes[] = $entry->getFilename();
            }
        }

        $this->assertSame(
            [],
            $clashes,
            "These api/v1 directories share a name with a sibling .php endpoint. Apache will\n"
            . "301 the collection URL to add a trailing slash and the endpoint stops working.\n"
            . "Flatten the handlers to <resource>_<action>.php instead.\n"
        );
    }
}
