<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member-family edit form (E2): add/replace passport photos later for
 * member/spouse/parents/children. Guards the upload helper, that the edit form
 * collects the photo inputs, that the UPDATE persists them, and — critically —
 * that an empty upload keeps the existing photo (never wipes it).
 */
class MemberEditPhotosTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(self::ROOT . 'app/constant/profile/profile.php');
    }

    public function testUploadHelperExists(): void
    {
        $helpers = file_get_contents(self::ROOT . 'helpers.php');
        $this->assertStringContainsString('function vk_upload_photo', $helpers);
    }

    public function testSelectLoadsPhotoColumns(): void
    {
        foreach (['c.father_photo', 'c.mother_photo', 'c.spouse_photo'] as $col) {
            $this->assertStringContainsString($col, $this->src, "SELECT must load $col to show/keep it");
        }
    }

    public function testEditFormHasPhotoInputs(): void
    {
        foreach (['father_photo', 'mother_photo', 'spouse_photo'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $this->src, "edit form must have $name");
        }
        $this->assertStringContainsString('name="child_photo[]"', $this->src, 'children must have a photo input');
    }

    public function testUpdatePersistsPhotoColumns(): void
    {
        foreach (['father_photo = ?', 'mother_photo = ?', 'spouse_photo = ?'] as $set) {
            $this->assertStringContainsString($set, $this->src, "UPDATE must set $set");
        }
    }

    public function testEmptyUploadKeepsExistingPhoto(): void
    {
        // The "never wipe" rule: new upload OR keep the member's current value.
        $this->assertStringContainsString("vk_upload_photo('father_photo', \$__photo_dir) ?? (\$member['father_photo'] ?? '')", $this->src);
        $this->assertStringContainsString("vk_upload_photo('spouse_photo', \$__photo_dir) ?? (\$member['spouse_photo'] ?? '')", $this->src);
        // Children: a new upload replaces, else the existing photo is kept.
        $this->assertStringContainsString('vk_save_child_photo($child_files, $idx, $__photo_dir)', $this->src);
        $this->assertStringContainsString("existing_children[\$idx]['photo']", $this->src);
    }
}
