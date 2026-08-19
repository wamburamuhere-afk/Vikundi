<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The demo seeders support --fresh, which TRUNCATEs the member, contribution,
 * loan and fine tables. Demo and production live in two directories on the same
 * server running identical code, so the only thing standing between a mistaken
 * `cd` and a destroyed VICOBA ledger is database/demo_seed_guard.php.
 *
 * These tests pin the refusal rules. The two hard checks — a known production
 * database name, and a real client's group name in the data — must not be
 * defeatable by any flag; that is the whole point of them, and it is exactly
 * the property that would be quietly lost in a later "make the seeder easier
 * to run" edit.
 */
class DemoSeedGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/database/demo_seed_guard.php';
    }

    // — Hard refusal: production database name —————————————————————————————

    public function testRefusesTheKnownProductionDatabase(): void
    {
        $reason = vk_demo_seed_block_reason('bjptechn_vikundi', '', false);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('PRODUCTION', $reason);
    }

    public function testTheProductionDatabaseRefusalCannotBeOverriddenByTheFlag(): void
    {
        $this->assertNotNull(
            vk_demo_seed_block_reason('bjptechn_vikundi', '', true),
            '--allow-nondemo-db must not unlock the production database.'
        );
    }

    public function testTheProductionDatabaseIsMatchedCaseInsensitively(): void
    {
        $this->assertNotNull(vk_demo_seed_block_reason('BJPTechn_Vikundi', '', true));
    }

    /**
     * The production check is an exact match, so a demo database that merely
     * starts with the same prefix must still be seedable. If this ever became a
     * str_contains() the demo site could not be seeded at all.
     */
    public function testADemoDatabaseSharingTheProductionPrefixIsAllowed(): void
    {
        $this->assertNull(vk_demo_seed_block_reason('bjptechn_vikundi_demo', 'Umoja VICOBA Group', false));
    }

    // — Hard refusal: the data says it belongs to a real group ————————————————

    /**
     * The signal that travels with the data rather than the connection. A
     * restored production dump under a fresh database name is still production,
     * and the name check alone would wave it straight through.
     */
    public function testRefusesADatabaseWhoseGroupNameIsARealClient(): void
    {
        $reason = vk_demo_seed_block_reason('vikundi_demo', 'UKUU Msakuzi', false);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('real group', $reason);
    }

    public function testTheRealGroupRefusalCannotBeOverriddenByTheFlag(): void
    {
        $this->assertNotNull(vk_demo_seed_block_reason('vikundi_demo', 'UKUU Msakuzi', true));
    }

    public function testTheGroupNameIsMatchedCaseInsensitivelyAndAsASubstring(): void
    {
        $this->assertNotNull(vk_demo_seed_block_reason('demo_db', 'ukuu msakuzi vicoba group', false));
        $this->assertNotNull(vk_demo_seed_block_reason('demo_db', 'Chama cha MSAKUZI', false));
    }

    public function testAnUnrelatedGroupNameDoesNotTripTheGuard(): void
    {
        $this->assertNull(vk_demo_seed_block_reason('vikundi_demo', 'Umoja VICOBA Group', false));
    }

    // — Soft refusal: the name carries no demo marker ——————————————————————

    public function testRefusesANameWithNoDemoMarkerUnlessTheFlagIsGiven(): void
    {
        $reason = vk_demo_seed_block_reason('vikundi', '', false);
        $this->assertNotNull($reason);
        $this->assertStringContainsString('--allow-nondemo-db', $reason);
    }

    public function testTheFlagUnlocksANameWithNoDemoMarker(): void
    {
        $this->assertNull(vk_demo_seed_block_reason('vikundi', '', true));
    }

    #[DataProvider('safeNames')]
    public function testNamesThatReadAsNonProductionAreAllowedWithoutTheFlag(string $db): void
    {
        $this->assertNull(vk_demo_seed_block_reason($db, '', false), "{$db} should be seedable");
    }

    public static function safeNames(): array
    {
        return [
            ['vikundi_demo'],
            ['demo_vikundi'],
            ['vikundi_test'],
            ['vikundi_staging'],
            ['vikundi_sandbox'],
            ['vikundi_scratch'],
            ['VIKUNDI_DEMO'],
        ];
    }

    // — Fail closed —————————————————————————————————————————————————————————

    public function testRefusesWhenNoDatabaseIsSelected(): void
    {
        $reason = vk_demo_seed_block_reason('', '', true);
        $this->assertNotNull($reason, 'An unverifiable target must never be seeded.');
        $this->assertStringContainsString('No database', $reason);
    }

    // — The guard is actually wired in ——————————————————————————————————————

    /**
     * A perfect guard that nothing calls protects nothing. Both seeders must
     * require it AND invoke it; requiring the file alone does nothing.
     *
     */
    #[DataProvider('seeders')]
    public function testEverySeederInvokesTheGuard(string $relative): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $this->assertStringContainsString("demo_seed_guard.php", $src, "{$relative} must require the guard");
        $this->assertStringContainsString('vk_demo_seed_guard(', $src, "{$relative} must call the guard");
    }

    public static function seeders(): array
    {
        return [
            ['database/seed_demo_data.php'],
            ['database/seed_demo_walkthrough.php'],
        ];
    }

    /**
     * The guard must run before anything destructive. If a TRUNCATE were
     * reachable first, the refusal would arrive after the damage.
     *
     */
    #[DataProvider('seeders')]
    public function testTheGuardRunsBeforeAnyTruncate(string $relative): void
    {
        // Comments are stripped first. Both seeders describe --fresh as
        // "truncate the demo tables" in their usage docblock, and matching that
        // prose would make this test pass or fail on the wording of a comment
        // rather than on the order of the code.
        $src = self::withoutComments((string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative));

        $guardAt = strpos($src, 'vk_demo_seed_guard(');
        $this->assertNotFalse($guardAt);

        $truncateAt = stripos($src, 'TRUNCATE');
        if ($truncateAt === false) {
            $this->assertTrue(true, 'No TRUNCATE in this seeder.');
            return;
        }
        $this->assertLessThan(
            $truncateAt,
            $guardAt,
            "{$relative} reaches a TRUNCATE before the guard has had a chance to refuse."
        );
    }

    /** Source with every comment removed, string literals left intact. */
    private static function withoutComments(string $src): string
    {
        $out = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the newlines so byte offsets stay roughly comparable.
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
}
