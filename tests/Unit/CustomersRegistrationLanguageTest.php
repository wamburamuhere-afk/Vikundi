<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the "Register New Member" modal in
 * app/bms/customer/customers.php.
 *
 * Bug: many field labels in the registration modal were hardcoded in English
 * and stayed English even when the session language was Swahili, producing a
 * mixed-language form. These tests assert every previously-hardcoded label now
 * carries a Swahili translation and that the old English-only markup is gone.
 */
class CustomersRegistrationLanguageTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $filePath = __DIR__ . '/../../app/bms/customer/customers.php';
        $this->src = file_get_contents($filePath);
    }

    // -------------------------------------------------------------------------
    // Swahili translations are now present
    // -------------------------------------------------------------------------

    public function test_personal_tab_labels_translated(): void
    {
        $this->assertStringContainsString('Mkoa wa Kuzaliwa', $this->src);   // Region of Birth
        $this->assertStringContainsString('Hali ya Ndoa', $this->src);       // Marital Status
        $this->assertStringContainsString('Tarehe ya Kuzaliwa', $this->src); // Date of Birth
        $this->assertStringContainsString('Namba ya NIDA', $this->src);      // NIDA Number
    }

    public function test_marital_status_options_translated(): void
    {
        $this->assertStringContainsString('Hana Ndoa', $this->src); // Single
        $this->assertStringContainsString('Ana Ndoa', $this->src);  // Married
        $this->assertStringContainsString('Mjane', $this->src);     // Widowed
        $this->assertStringContainsString('Talaka', $this->src);    // Divorced
    }

    public function test_parents_labels_translated(): void
    {
        $this->assertStringContainsString('JINA LA BABA', $this->src);           // Father's Name
        $this->assertStringContainsString('JINA LA MAMA', $this->src);           // Mother's Name
        $this->assertStringContainsString('MKOA/WILAYA ANAPOISHI', $this->src);  // Region/District
        $this->assertStringContainsString('KATA/KIJIJI/MTAA', $this->src);       // Ward/Village/Street
    }

    public function test_spouse_labels_translated(): void
    {
        // The spouse heading was already translated; the field labels were not.
        $this->assertStringContainsString('Jina la Kwanza', $this->src); // First Name
        $this->assertStringContainsString('Jina la Kati', $this->src);   // Middle Name
        $this->assertStringContainsString('Jina la Mwisho', $this->src); // Last Name
        $this->assertStringContainsString('Barua Pepe', $this->src);     // Email
    }

    public function test_children_section_translated(): void
    {
        $this->assertStringContainsString('JINA LA MTOTO', $this->src); // Child Name header
        $this->assertStringContainsString('UMRI', $this->src);          // Age header
        $this->assertStringContainsString('Ongeza Mtoto', $this->src);  // Add Child button
    }

    public function test_guarantor_labels_translated(): void
    {
        $this->assertStringContainsString('JINA LA MDHAMINI', $this->src);       // Guarantor's Name
        $this->assertStringContainsString('UHUSIANO NA MWANACHAMA', $this->src); // Relationship
        $this->assertStringContainsString('MKOA ANAPOISHI', $this->src);         // Region where living
    }

    public function test_account_tab_labels_translated(): void
    {
        $this->assertStringContainsString('Nenosiri la Awali', $this->src);   // Initial Password
        $this->assertStringContainsString('Thibitisha Nenosiri', $this->src); // Confirm Password
        $this->assertStringContainsString(
            'Mwanachama anaweza kubadilisha nenosiri na lugha',
            $this->src
        ); // footer note
    }

    // -------------------------------------------------------------------------
    // The old English-only markup must not come back
    // -------------------------------------------------------------------------

    public function test_no_hardcoded_english_only_labels_remain(): void
    {
        $forbidden = [
            '>Region of Birth</label>',
            '>Marital Status</label>',
            '>Date of Birth</label>',
            '>NIDA Number</label>',
            '>Single</option>',
            '>CHILD NAME</th>',
            '>RELATIONSHIP WITH MEMBER</label>',
            '>REGION WHERE LIVING</label>',
            '>Initial Password *</label>',
            '>Confirm Password *</label>',
        ];

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $this->src,
                "Hardcoded English-only markup '$needle' should be wrapped in a language ternary."
            );
        }
    }

    // -------------------------------------------------------------------------
    // SweetAlert popup titles must be bilingual (a mixed EN-title/SW-body popup
    // was reported when the server returned a Swahili email-duplicate error).
    // -------------------------------------------------------------------------

    public function test_error_popup_titles_are_bilingual(): void
    {
        // The generic error title must no longer be hardcoded English.
        $this->assertStringNotContainsString("title: 'Error'", $this->src);
        // Bilingual error title is present instead.
        $this->assertStringContainsString("'Hitilafu' : 'Error'", $this->src);
        // Password mismatch + receipt-required popups translated too.
        $this->assertStringContainsString('Hitilafu ya Nenosiri', $this->src);
        $this->assertStringContainsString('Risiti Inahitajika!', $this->src);
    }
}
