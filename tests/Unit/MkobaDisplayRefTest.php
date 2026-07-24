<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * mkoba_display_ref() hides an Excel-mangled scientific-notation reference
 * (e.g. "3.75E+15") — which is unrecoverable junk — behind a dash, while letting
 * every genuine M-Koba receipt/TRANS_ID through untouched. Used on the
 * reconciliation view so the one excluded, corrupted row shows "—" not "3.75E+15".
 */
class MkobaDisplayRefTest extends TestCase
{
    public function testScientificNotationIsHidden(): void
    {
        $this->assertSame('—', mkoba_display_ref('3.75E+15'));
        $this->assertSame('—', mkoba_display_ref('3.83E+15'));
        $this->assertSame('—', mkoba_display_ref('1.2e9'));
        $this->assertSame('—', mkoba_display_ref('4E+15'));
    }

    public function testEmptyOrNullFallsBackToDash(): void
    {
        $this->assertSame('—', mkoba_display_ref(''));
        $this->assertSame('—', mkoba_display_ref(null));
        $this->assertSame('—', mkoba_display_ref('   '));
    }

    public function testGenuineReferencesPassThrough(): void
    {
        // Ordinary alphanumeric receipts.
        $this->assertSame('DBS2N6S4DVM', mkoba_display_ref('DBS2N6S4DVM'));
        // The long "contribute for other member" TRANS_ID — the underscore keeps it
        // out of scientific notation, so it must NOT be mistaken for junk.
        $this->assertSame('3820502806778077_0783459353', mkoba_display_ref('3820502806778077_0783459353'));
        // A plain long number that isn't scientific notation stays.
        $this->assertSame('60243499376', mkoba_display_ref('60243499376'));
    }

    public function testCustomFallbackHonoured(): void
    {
        $this->assertSame('N/A', mkoba_display_ref('3.75E+15', 'N/A'));
    }
}
