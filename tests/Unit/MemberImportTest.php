<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Members bulk-upload (PR-2): a simple, header-named template parsed by the pure
 * helpers in includes/member_import.php, then inserted with an auto username +
 * password username@123.
 */
class MemberImportTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public static function setUpBeforeClass(): void
    {
        require_once self::ROOT . 'includes/member_import.php';
    }

    public function testRequiredFieldsAreEnforced(): void
    {
        // first_name, last_name and phone are required; middle_name is not.
        $err = member_import_parse_row(['first_name' => 'Juma', 'phone' => '0712']);
        $this->assertIsString($err);
        $this->assertStringContainsString('last_name', $err);
        $this->assertStringNotContainsString('middle_name', $err);

        $missingPhone = member_import_parse_row(['first_name' => 'A', 'last_name' => 'C']);
        $this->assertIsString($missingPhone);
        $this->assertStringContainsString('phone', $missingPhone);
    }

    public function testTwoWordNameWithoutMiddleParses(): void
    {
        // Many rosters (e.g. the M-Koba statement) carry only first + surname.
        $row = member_import_parse_row([
            'first_name' => 'Consesa', 'last_name' => 'Munishi', 'phone' => '0767276015',
        ]);
        $this->assertIsArray($row);
        $this->assertSame('Consesa', $row['first_name']);
        $this->assertSame('', $row['middle_name']);
        $this->assertSame('Munishi', $row['last_name']);
        $this->assertSame('0767276015', $row['phone']);
    }

    public function testValidRowNormalises(): void
    {
        $row = member_import_parse_row([
            'first_name' => 'Juma', 'middle_name' => 'Hassan', 'last_name' => 'Kessy',
            'phone' => '255712345678.00', 'gender' => 'm', 'nida' => '19900101.00',
            'initial_savings' => '20,000', 'region' => 'Mwanza',
        ]);
        $this->assertIsArray($row);
        $this->assertSame('255712345678', $row['phone']);   // Excel ".00" dropped, digits kept
        $this->assertSame('Male', $row['gender']);          // normalised
        $this->assertSame('19900101', $row['nida']);
        $this->assertSame(20000.0, $row['initial_savings']); // comma stripped
        $this->assertSame('Tanzania', $row['country']);      // default
    }

    public function testGenderVariants(): void
    {
        foreach (['f', 'Female', 'mwanamke'] as $g) {
            $row = member_import_parse_row(['first_name' => 'A', 'middle_name' => 'B', 'last_name' => 'C', 'phone' => '07', 'gender' => $g]);
            $this->assertSame('Female', $row['gender']);
        }
    }

    public function testTemplateHasExpectedHeaders(): void
    {
        $header = trim(explode("\n", file_get_contents(self::ROOT . 'templates/members_template.csv'))[0]);
        $cols = explode(',', $header);
        foreach (['first_name', 'middle_name', 'last_name', 'phone', 'gender', 'email', 'nida',
                  'initial_savings', 'region', 'district', 'ward', 'street', 'house_number'] as $c) {
            $this->assertContains($c, $cols, "template must include the $c column");
        }
    }

    public function testImporterIsHeaderBasedAndAutoPassword(): void
    {
        $h = file_get_contents(self::ROOT . 'ajax/process_member_import.php');
        $this->assertStringContainsString('member_import_parse_row', $h);
        $this->assertStringContainsString("\$username . '@123'", $h);
        // No longer the old positional "Expected 41 columns" importer.
        $this->assertStringNotContainsString('Expected 41', $h);
    }

    public function testImportModalUsesSimpleTemplate(): void
    {
        $page = file_get_contents(self::ROOT . 'app/bms/customer/customers.php');
        $this->assertStringContainsString('actions/download_members_template', $page);
        $this->assertStringNotContainsString('File must have 41 columns', $page);
    }
}
