<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The module the group calls Condolences (Swahili: Rambirambi) used to be labelled
 * eleven different ways across the product — "Funeral Support", "Funeral Aid",
 * "Death Benefits & Expenses", "Death Assistance Report", "Misaada ya Misiba",
 * "Msaada wa Msiba" and more, often two different names on one screen. This test
 * holds the single name in place.
 *
 * It reads the source files rather than re-implementing their logic. That
 * distinction matters here: tests/Unit/ResponsivePrintTier3Test.php asserts on its
 * OWN inline copy of the report's label code, so when the product was renamed it
 * carried on passing while documenting a name that no longer existed anywhere. A
 * test that never opens the file it describes cannot notice the file changing.
 *
 * DELIBERATE BOUNDARY — this rename is display-only. Three classes of string keep
 * their old wording on purpose, and the second half of this test pins them down so
 * nobody "finishes the job" and breaks something:
 *   - activity-log module names and descriptions, which are written to the DB;
 *     changing them would split the audit history in two
 *   - the "Death Assistance" document-category name, which is a lookup KEY
 *     (SELECT ... WHERE category_name = ?) — renaming it orphans the live category
 *     and silently creates a duplicate
 *   - table names, route slugs and permission keys
 */
class CondolencesNamingTest extends TestCase
{
    /** Labels that must no longer appear anywhere a user can read them. */
    private const RETIRED_LABELS = [
        'Funeral Support',
        'Funeral Supports',
        'Funeral Aid',
        'Funeral Assistance',
        'Death Benefit',
        'Death Benefits',
        'Death Expense Details',
        'Death Assistance Report',
        'Death Assistance Details',
        'Death Assistance Expenses',
        'Misaada ya Misiba',
        'Misaada ya Msiba',
        'Msaada wa Msiba',
        'Msaada wa Misiba',
        'Mafao ya Misiba',
        // Action labels. The first pass renamed the module heading but left the
        // primary button reading "Record New Death" — the module said Condolences
        // while its own button said Death, which is worse than either name alone.
        'Record New Death',
        'Record Death & Assistance',
        'Rekodi Msiba Mpya',
        'Rekodi Msiba na Msaada',
        'Death Cases',
        'Death Report',
        'Idadi ya Misiba',
        'Ripoti ya Misiba',
        // The synonym family. Two passes missed these because they never say
        // "death" or "funeral" at all — the module was also called Aid, Benefit,
        // Msaada and Mafao, so a sweep for the obvious words left tiles reading
        // "TOTAL AID PAID" under a heading reading "Condolences".
        'Total Aid Paid',
        'Jumla ya Misaada',
        'Benefit Paid',
        'Benefit (TSh)',
        'Benefits Received',
        'Misaada Aliyopokea',
        'Kiasi cha Msaada',
        'Contribution vs Benefit',
    ];

    /**
     * Wording that stays. "Death certificate" is the real name of a real document,
     * and "Amefariki" describes a person who has died, not the module — renaming
     * either would be a euphemism, not a correction.
     */
    private const KEPT_WORDING = [
        'app/constant/accounts/expenses.php'     => 'Death Cert',
        'app/bms/customer/dormant_members.php'   => 'Amefariki',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Every UI file, with activity-log calls stripped first — those legitimately
     * still carry the old module name because they write it to the database.
     */
    private function uiFiles(): array
    {
        $files = [$this->root . '/header.php', $this->root . '/core/ai_insights.php'];
        $dir   = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root . '/app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($dir as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function displayText(string $path): string
    {
        $lines = preg_grep(
            '/log(Activity|Create|Update|Delete)\s*\(/',
            file($path),
            PREG_GREP_INVERT
        );
        return implode('', $lines);
    }

    // -------------------------------------------------------------------------
    // The name is now one name
    // -------------------------------------------------------------------------

    public function testNoRetiredLabelSurvivesInTheUserInterface(): void
    {
        $offences = [];
        foreach ($this->uiFiles() as $path) {
            $text = $this->displayText($path);
            foreach (self::RETIRED_LABELS as $label) {
                if (str_contains($text, $label)) {
                    $offences[] = str_replace($this->root . '/', '', $path) . ' still says "' . $label . '"';
                }
            }
        }
        $this->assertSame([], $offences, "retired labels found:\n" . implode("\n", $offences));
    }

    public function testWordingAboutActualDeathIsNotEuphemised(): void
    {
        foreach (self::KEPT_WORDING as $relative => $phrase) {
            $this->assertStringContainsString(
                $phrase,
                file_get_contents($this->root . '/' . $relative),
                "$relative should still say \"$phrase\" — it describes a real thing, not the module"
            );
        }
    }

    public function testNavigationUsesTheAgreedNameInBothLanguages(): void
    {
        $header = file_get_contents($this->root . '/header.php');
        $this->assertStringContainsString("'en' => 'Condolences'", $header);
        $this->assertStringContainsString("'sw' => 'Rambirambi'", $header);
    }

    public function testTranslationFilesCarryTheSingleSharedKey(): void
    {
        $en = json_decode(file_get_contents($this->root . '/lang/en.json'), true);
        $sw = json_decode(file_get_contents($this->root . '/lang/sw.json'), true);

        $this->assertSame('Condolences', $en['dashboard.condolences'] ?? null);
        $this->assertSame('Rambirambi', $sw['dashboard.condolences'] ?? null);

        // The two near-duplicate keys this replaced are gone from both files.
        foreach (['dashboard.funeral_support', 'dashboard.funeral_supports'] as $dead) {
            $this->assertArrayNotHasKey($dead, $en);
            $this->assertArrayNotHasKey($dead, $sw);
        }
    }

    public function testEveryTranslationKeyUsedExistsInBothLanguages(): void
    {
        // Renaming a key means updating every call site; a missed one renders the raw
        // key on the page. Cheap to check, and it covers the whole file, not just ours.
        $en = json_decode(file_get_contents($this->root . '/lang/en.json'), true);
        $sw = json_decode(file_get_contents($this->root . '/lang/sw.json'), true);

        $dashboard = file_get_contents($this->root . '/app/dashboard.php');
        preg_match_all("/\bet\(\s*'([^']+)'/", $dashboard, $m);

        foreach (array_unique($m[1]) as $key) {
            $this->assertArrayHasKey($key, $en, "dashboard.php calls et('$key') with no English entry");
            $this->assertArrayHasKey($key, $sw, "dashboard.php calls et('$key') with no Swahili entry");
        }
    }

    // -------------------------------------------------------------------------
    // The boundary — what this rename deliberately did NOT touch
    // -------------------------------------------------------------------------

    public function testDocumentCategoryLookupKeyIsUnchanged(): void
    {
        // "Death Assistance" here is matched against document_categories.category_name.
        // Rename it and the existing category stops resolving: a second one is created
        // and every previously attached document is stranded under the old one.
        $src = file_get_contents($this->root . '/actions/process_death_expense.php');
        $this->assertStringContainsString('$cat_name = "Death Assistance";', $src);
    }

    public function testActivityLogModuleNameIsUnchanged(): void
    {
        // Audit rows already in the database say "Death Expenses". Writing a new name
        // going forward would split the history across two labels with no migration.
        $src = file_get_contents($this->root . '/actions/approve_death_expense.php');
        $this->assertStringContainsString("logActivity('Approved', 'Death Expenses'", $src);
    }

    public function testRoutesAndPermissionKeysAreUnchanged(): void
    {
        // Route slugs are bookmarked and permission keys are seeded per role in the
        // database; renaming either locks people out for a cosmetic gain.
        $roots = file_get_contents($this->root . '/roots.php');
        $this->assertStringContainsString("'death_expenses'", $roots);
        $this->assertStringContainsString("'accounts/death_expenses'", $roots);
    }
}
