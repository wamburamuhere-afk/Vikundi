<?php

namespace Tests\Unit;

use DateTime;
use PHPUnit\Framework\TestCase;

/**
 * PR-A: children date-of-birth on the registration forms. Covers the server-side
 * age derivation (vk_age_from_dob) and guards that both forms collect child_dob
 * and both handlers persist it, and that the Family-tab labels are no longer
 * shouted in all-caps.
 */
class ChildDobTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public static function setUpBeforeClass(): void
    {
        require_once self::ROOT . 'helpers.php';
    }

    // ---- vk_age_from_dob() ----

    public function testExactBirthdayGivesWholeYears(): void
    {
        $tenYearsAgo = (new DateTime())->modify('-10 years')->format('Y-m-d');
        $this->assertSame(10, vk_age_from_dob($tenYearsAgo));
    }

    public function testDayBeforeBirthdayIsStillYounger(): void
    {
        // Birthday falls tomorrow -> not yet turned 10.
        $almostTen = (new DateTime())->modify('-10 years')->modify('+1 day')->format('Y-m-d');
        $this->assertSame(9, vk_age_from_dob($almostTen));
    }

    public function testFutureDateReturnsNull(): void
    {
        $future = (new DateTime())->modify('+1 year')->format('Y-m-d');
        $this->assertNull(vk_age_from_dob($future));
    }

    public function testEmptyOrInvalidReturnsNull(): void
    {
        $this->assertNull(vk_age_from_dob(''));
        $this->assertNull(vk_age_from_dob('   '));
        $this->assertNull(vk_age_from_dob('not-a-date'));
        $this->assertNull(vk_age_from_dob(null));
    }

    // ---- forms collect child_dob + derive age via JS ----

    public function testBothFormsCollectChildDob(): void
    {
        foreach (['register.php', 'app/bms/customer/customers.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $this->assertStringContainsString('name="child_dob[]"', $src, "$f must collect child_dob");
            $this->assertStringContainsString('vkChildAge', $src, "$f must derive age from DOB");
        }
    }

    // ---- both handlers persist dob into children_data ----

    public function testBothHandlersPersistDob(): void
    {
        foreach (['actions/process_registration.php', 'actions/add_member.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $this->assertStringContainsString("\$_POST['child_dob']", $src, "$f must read child_dob");
            $this->assertStringContainsString("'dob'", $src, "$f must store dob in children_data");
            $this->assertStringContainsString('vk_age_from_dob', $src, "$f must derive age server-side");
        }
    }

    // ---- Family-tab labels are Title Case, not all-caps ----

    public function testFamilyLabelsAreTitleCased(): void
    {
        $reg = file_get_contents(self::ROOT . 'register.php');
        // No shouted all-caps labels remain on the Family tab.
        $this->assertStringNotContainsString(">FATHER'S NAME</label>", $reg);
        $this->assertStringNotContainsString('>PHONE NUMBER</label>', $reg);
        $this->assertStringNotContainsString(">GUARANTOR'S NAME</label>", $reg);
        // Title-Cased labels are present (guarantor section + parent phone).
        $this->assertStringContainsString(">Guarantor's Name</label>", $reg);
        $this->assertStringContainsString('>Phone Number</label>', $reg);
    }
}
