<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Registration PR-B: richer parent details (structured name, six-field location,
 * optional passport photo). Covers the name-join helper, the migration's column
 * declarations, and guards that both forms collect the fields and both handlers
 * persist them.
 */
class ParentDetailsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** name parts of one parent's location/identity, per side. */
    private const PARENT_FIELDS = [
        'first_name', 'middle_name', 'last_name',
        'country', 'state', 'district', 'ward', 'street', 'house_number', 'photo',
    ];

    public static function setUpBeforeClass(): void
    {
        require_once self::ROOT . 'helpers.php';
    }

    // ---- vk_full_name() ----

    public function testFullNameJoinsParts(): void
    {
        $this->assertSame('John Mike Doe', vk_full_name('John', 'Mike', 'Doe'));
    }

    public function testFullNameDropsBlanksAndTrims(): void
    {
        $this->assertSame('John Doe', vk_full_name('  John ', '', ' Doe'));
        $this->assertSame('Mary', vk_full_name('Mary', null, null));
        $this->assertSame('', vk_full_name('', '', ''));
    }

    // ---- migration declares every parent column ----

    public function testMigrationDeclaresAllParentColumns(): void
    {
        // The migration generates father_/mother_ columns from one loop, so assert
        // the loop and each field suffix it declares.
        $src = file_get_contents(self::ROOT . 'database/add_parent_detail_columns.php');
        $this->assertStringContainsString("['father', 'mother']", $src, 'migration must cover both parents');
        foreach (self::PARENT_FIELDS as $field) {
            $this->assertStringContainsString('{$p}_' . $field, $src,
                "migration must declare {p}_{$field}");
        }
    }

    // ---- both forms collect the new parent fields ----

    public function testBothFormsCollectParentFields(): void
    {
        foreach (['register.php', 'app/bms/customer/customers.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            foreach (['father_first_name', 'father_state', 'father_house_number', 'father_photo',
                      'mother_first_name', 'mother_ward', 'mother_photo'] as $name) {
                $this->assertStringContainsString('name="' . $name . '"', $src,
                    "$f must collect $name");
            }
        }
    }

    // ---- both handlers persist the new parent columns ----

    public function testBothHandlersInsertParentColumns(): void
    {
        foreach (['actions/process_registration.php', 'actions/add_member.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            foreach (['father_first_name', 'father_country', 'father_photo',
                      'mother_last_name', 'mother_street', 'mother_photo'] as $col) {
                $this->assertStringContainsString($col, $src, "$f must persist $col");
            }
            // legacy single-column name kept populated for back-compat.
            $this->assertStringContainsString('vk_full_name(', $src, "$f must keep *_name populated");
        }
    }
}
