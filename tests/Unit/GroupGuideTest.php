<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The welcome guide every group has at /guide (guide.php, includes/group_guide.php):
 * filled in from the group, in English and Kiswahili, following the features the
 * super-admin gave the group, and showing the group's own figures only to someone
 * signed in.
 */
final class GroupGuideTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        require_once self::$root . '/includes/group_guide.php';
    }

    private function ctx(array $over = []): array
    {
        return array_merge(['name' => 'Pamoja Association Group (PaA)', 'address' => 'https://pamoja.vikundi.bjptechnologies.co.tz',
                            'demo' => false, 'shares' => true, 'dividends' => true, 'figures' => null], $over);
    }

    private function ids(array $sections): array
    {
        return array_column($sections, 'id');
    }

    /** Every piece of text in the guide, flattened. */
    private function text(array $sections): string
    {
        $out = [];
        array_walk_recursive($sections, static function ($v) use (&$out) { $out[] = (string) $v; });
        return implode("\n", $out);
    }

    private function section(array $sections, string $id): array
    {
        foreach ($sections as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        $this->fail("no section $id");
    }

    public function testBothLanguagesHaveTheSameSectionsInTheSameParts(): void
    {
        $en = vk_guide_sections($this->ctx(), false);
        $sw = vk_guide_sections($this->ctx(), true);
        $this->assertSame($this->ids($en), $this->ids($sw));
        $this->assertSame(array_column($en, 'part'), array_column($sw, 'part'));
        foreach ($en as $i => $s) {
            $this->assertNotSame($s['title'], $sw[$i]['title'], "{$s['id']} is not translated");
            $this->assertArrayHasKey($s['part'], vk_guide_parts(false));
        }
        $this->assertSame(array_keys(vk_guide_parts(false)), array_values(array_unique(array_column($en, 'part'))), 'parts come in order');
    }

    public function testTheGuideNamesTheGroupAndItsAddress(): void
    {
        $text = $this->text(vk_guide_sections($this->ctx(), false));
        $this->assertStringContainsString('Pamoja Association Group (PaA) has its own Vikundi site at https://pamoja.vikundi.bjptechnologies.co.tz', $text);
        $this->assertStringContainsString('Open https://pamoja.vikundi.bjptechnologies.co.tz in your browser.', $text);
        $this->assertStringContainsString(VK_GUIDE_SUPPORT_EMAIL, $text);
    }

    public function testAGroupWithoutSharesOrDividendsIsNotToldAboutThem(): void
    {
        foreach ([false, true] as $sw) {
            $off = vk_guide_sections($this->ctx(['shares' => false, 'dividends' => false]), $sw);
            $this->assertNotContains('dividends', $this->ids($off));
            $text = $this->text($off);
            $this->assertStringNotContainsString('Shares Register', $text);
            $this->assertStringNotContainsString('Shares & Funds', $text);
            $this->assertStringNotContainsString($sw ? 'gawio' : 'dividends', strtolower($text));
            $types = array_column($this->section($off, 'payments')['table']['rows'], 0);
            $this->assertNotContains('Shares', $types);
        }
        $on = vk_guide_sections($this->ctx(), false);
        $this->assertContains('dividends', $this->ids($on));
        $this->assertContains('Shares', array_column($this->section($on, 'payments')['table']['rows'], 0));
    }

    public function testOnlyADemoGroupIsToldItsDataAreSamples(): void
    {
        $notes = fn(bool $demo) => $this->text($this->section(vk_guide_sections($this->ctx(['demo' => $demo]), false), 'welcome')['notes']);
        $this->assertStringContainsString('This is a demo', $notes(true));
        $this->assertStringNotContainsString('This is a demo', $notes(false));
    }

    public function testAVisitorSeesNoFiguresOnlyAnInvitationToSignIn(): void
    {
        $rules = $this->section(vk_guide_sections($this->ctx(), false), 'your-rules');
        $this->assertArrayNotHasKey('table', $rules);
        $this->assertStringContainsString('Sign in', $this->text($rules['notes']));
    }

    public function testASignedInLeaderSeesTheGroupsOwnRules(): void
    {
        $figures = [
            'rule' => [['from' => null, 'amount' => 5000], ['from' => '2026-06', 'amount' => 10000]],
            'social' => 5000, 'entrance' => 0, 'share_price' => 2000, 'deadline_day' => 10,
            'late' => ['enabled' => true, 'fine' => 5000.0, 'fine_day' => 9, 'dormant_day' => 15],
            'loan_types' => [['name' => 'Emergency Loan', 'interest_rate' => '3.00', 'interest_type' => 'flat', 'max_term_months' => 3,
                              'max_amount' => '300000', 'max_share_multiple' => '2', 'guarantors_required' => 1]],
        ];
        $rows = vk_guide_rules_rows($figures, false, true);
        $byRule = array_column($rows, 1, 0);
        $this->assertSame('From the start: TSh 5,000; From 2026-06: TSh 10,000', $byRule['Monthly contribution']);
        $this->assertSame('TSh 5,000 a month', $byRule['Social Fund']);
        $this->assertArrayNotHasKey('Entrance fee', $byRule, 'no fee, no row');
        $this->assertSame('TSh 2,000', $byRule['Price of one share']);
        $this->assertSame('Day 10 of the month', $byRule['Contribution deadline']);
        $this->assertStringContainsString('fine of TSh 5,000', $byRule['Late payment']);
        $this->assertStringContainsString('Dormant', $byRule['Late payment']);
        $this->assertSame('3% a month, flat, up to 3 months, up to TSh 300,000, up to 2 times your shares, 1 guarantor(s)', $byRule['Loan: Emergency Loan']);

        // With Shares off, nothing about shares.
        $noShares = array_column(vk_guide_rules_rows($figures, false, false), 1, 0);
        $this->assertArrayNotHasKey('Price of one share', $noShares);
        $this->assertStringNotContainsString('shares', $noShares['Loan: Emergency Loan']);

        // Late rules switched off: no row. Swahili is filled in too.
        $figures['late']['enabled'] = false;
        $this->assertArrayNotHasKey('Late payment', array_column(vk_guide_rules_rows($figures, false, true), 1, 0));
        $sw = array_column(vk_guide_rules_rows($figures, true, true), 1, 0);
        $this->assertSame('TSh 5,000 kwa mwezi', $sw['Mfuko wa Jamii']);

        // The section shows exactly those rows.
        $section = $this->section(vk_guide_sections($this->ctx(['figures' => $figures]), false), 'your-rules');
        $this->assertSame(vk_guide_rules_rows($figures, false, true), $section['table']['rows']);
    }

    public function testNoSectionIsEmpty(): void
    {
        foreach ([false, true] as $sw) {
            foreach (vk_guide_sections($this->ctx(['figures' => ['rule' => 20000.0]]), $sw) as $s) {
                $this->assertTrue(!empty($s['intro']) || $s['steps'] || !empty($s['table']['rows']) || $s['notes'], "{$s['id']} is empty");
            }
        }
    }

    // ── the page ──────────────────────────────────────────────────────────────

    public function testThePageIsPublicButFiguresNeedASignIn(): void
    {
        $page = (string) file_get_contents(self::$root . '/guide.php');
        $this->assertStringNotContainsString('header.php', $page, 'no sign-in gate: it can be sent to a new group');
        $this->assertStringContainsString("\$signedIn = !empty(\$_SESSION['user_id']);", $page);
        $this->assertStringContainsString('if ($signedIn) {', $page);
        $this->assertLessThan(strpos($page, 'SELECT * FROM vicoba_loan_types'), strpos($page, 'if ($signedIn) {'));
        $this->assertStringContainsString("'figures'   => \$figures,", $page);
        // Everything printed goes through htmlspecialchars.
        $this->assertStringContainsString("htmlspecialchars((string) \$v, ENT_QUOTES, 'UTF-8')", $page);
        $this->assertDoesNotMatchRegularExpression('/<\?= \$sec\[/', $page);
        $this->assertStringContainsString("'shares'    => vk_feature('shares'),", $page);
    }

    public function testTheGuideIsLinkedFromTheAppAndThePanel(): void
    {
        $this->assertStringContainsString("'guide' => ROOT_DIR . '/guide.php',", (string) file_get_contents(self::$root . '/roots.php'));
        $this->assertStringContainsString("getUrl('guide')", (string) file_get_contents(self::$root . '/header.php'));
        $panel = (string) file_get_contents(self::$root . '/superadmin/src/pages/group.php');
        $this->assertStringContainsString("\$guide = 'https://' . \$g['subdomain'] . '.' . \$base . '/guide';", $panel);
        $this->assertStringContainsString('?lang=sw', $panel);
    }
}
