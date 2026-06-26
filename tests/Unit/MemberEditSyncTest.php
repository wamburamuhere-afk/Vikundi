<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member-family edit form (E1): the profile edit screen must be in sync with the
 * registration fields — structured parent names + six-field location, child DOB,
 * guarantor six-field location — and must load + persist them. Also guards the
 * fixed parent-location bug (the form showed location but the handler never
 * saved it) and that re-encoding children preserves an existing photo.
 */
class MemberEditSyncTest extends TestCase
{
    private const FILE = __DIR__ . '/../../app/constant/profile/profile.php';

    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(self::FILE);
    }

    public function testSelectLoadsNewColumns(): void
    {
        // The form pre-fills from this SELECT, so it must fetch the new columns.
        foreach (['c.father_first_name', 'c.father_country', 'c.father_house_number',
                  'c.mother_state', 'c.guarantor_country', 'c.guarantor_ward'] as $col) {
            $this->assertStringContainsString($col, $this->src, "SELECT must load $col");
        }
    }

    public function testEditFormHasNewInputs(): void
    {
        foreach (['father_first_name', 'father_state', 'father_house_number',
                  'mother_first_name', 'mother_ward',
                  'guarantor_country', 'guarantor_house_number'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $this->src,
                "edit form must have $name");
        }
        $this->assertStringContainsString('name="child_dob[]"', $this->src, 'edit form must have child DOB');
    }

    public function testUpdateHandlerPersistsNewColumns(): void
    {
        // Structured names + six-field location for parents, location for guarantor.
        foreach (['father_first_name = ?', 'father_country = ?', 'mother_house_number = ?',
                  'guarantor_country = ?', 'guarantor_house_number = ?'] as $set) {
            $this->assertStringContainsString($set, $this->src, "UPDATE must set $set");
        }
        // legacy *_name kept populated from the structured parts.
        $this->assertStringContainsString('vk_full_name(', $this->src);
    }

    public function testParentLocationBugFixed(): void
    {
        // Previously the form showed parent location but the UPDATE never saved it.
        $this->assertStringContainsString('father_location = ?', $this->src,
            'UPDATE must now persist parent location');
        $this->assertStringContainsString('mother_location = ?', $this->src);
    }

    public function testChildrenReencodePreservesPhotoAndDob(): void
    {
        $this->assertStringContainsString("\$_POST['child_dob']", $this->src, 'edit must read child DOB');
        $this->assertStringContainsString("existing_children[\$idx]['photo']", $this->src,
            'editing must not wipe a child photo set at registration');
    }
}
