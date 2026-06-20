<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure (DB-free) helpers in includes/email_helper.php:
 *  - email_is_valid()
 *  - email_parse_recipients()
 *  - email_render_template()
 */
class EmailHelperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/email_helper.php';
    }

    // ----- email_is_valid ---------------------------------------------------

    public function test_valid_email_passes(): void
    {
        $this->assertTrue(email_is_valid('john@example.com'));
        $this->assertTrue(email_is_valid('  member.name+tag@vikundi.co.tz  '));
    }

    public function test_invalid_email_fails(): void
    {
        $this->assertFalse(email_is_valid('not-an-email'));
        $this->assertFalse(email_is_valid(''));
        $this->assertFalse(email_is_valid('foo@'));
        $this->assertFalse(email_is_valid('@bar.com'));
    }

    // ----- email_parse_recipients ------------------------------------------

    public function test_parses_mixed_separators(): void
    {
        $result = email_parse_recipients("a@x.com, b@x.com; c@x.com\nd@x.com e@x.com");
        $this->assertSame(['a@x.com', 'b@x.com', 'c@x.com', 'd@x.com', 'e@x.com'], $result);
    }

    public function test_drops_invalid_and_deduplicates_case_insensitively(): void
    {
        $result = email_parse_recipients('A@X.com, a@x.com, bad, , b@x.com');
        $this->assertSame(['a@x.com', 'b@x.com'], $result);
    }

    public function test_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], email_parse_recipients(''));
        $this->assertSame([], email_parse_recipients('   '));
    }

    // ----- email_render_template -------------------------------------------

    public function test_replaces_known_placeholders(): void
    {
        $out = email_render_template('Hello {{member_name}}, welcome to {{group_name}}.', [
            'member_name' => 'Pendo',
            'group_name'  => 'Tujikomboe',
        ]);
        $this->assertSame('Hello Pendo, welcome to Tujikomboe.', $out);
    }

    public function test_tolerates_whitespace_inside_braces(): void
    {
        $out = email_render_template('Hi {{ member_name }}', ['member_name' => 'Asha']);
        $this->assertSame('Hi Asha', $out);
    }

    public function test_unknown_placeholders_are_left_untouched(): void
    {
        $out = email_render_template('Dear {{member_name}}, ref {{loan_id}}', ['member_name' => 'Juma']);
        $this->assertSame('Dear Juma, ref {{loan_id}}', $out);
    }

    public function test_empty_vars_returns_template_unchanged(): void
    {
        $tpl = 'No {{tokens}} here change';
        $this->assertSame($tpl, email_render_template($tpl, []));
    }

    // ----- email_template_types --------------------------------------------

    public function test_template_types_default_english(): void
    {
        $types = email_template_types(false);
        $this->assertSame(['general', 'loan', 'payment', 'security'], array_keys($types));
        $this->assertSame('General', $types['general']);
    }

    public function test_template_types_swahili_labels(): void
    {
        $types = email_template_types(true);
        $this->assertSame(['general', 'loan', 'payment', 'security'], array_keys($types));
        $this->assertSame('Mkopo', $types['loan']);
        $this->assertNotSame('General', $types['general']); // localised
    }
}
