<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Registration PR-D: optional per-child passport photo on the registration
 * forms. Covers the upload helper's guard logic and guards that both forms
 * collect child_photo and both handlers persist it into the children JSON.
 */
class ChildPhotoTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public static function setUpBeforeClass(): void
    {
        require_once self::ROOT . 'helpers.php';
    }

    // ---- vk_save_child_photo() guard logic (no upload -> '') ----

    public function testNoFilesArrayReturnsEmpty(): void
    {
        $this->assertSame('', vk_save_child_photo(null, 0, sys_get_temp_dir()));
    }

    public function testNoFileForRowReturnsEmpty(): void
    {
        $files = ['error' => [0 => UPLOAD_ERR_NO_FILE], 'name' => [0 => ''], 'tmp_name' => [0 => '']];
        $this->assertSame('', vk_save_child_photo($files, 0, sys_get_temp_dir()));
    }

    public function testMissingIndexReturnsEmpty(): void
    {
        $files = ['error' => [0 => UPLOAD_ERR_OK], 'name' => [0 => 'a.jpg'], 'tmp_name' => [0 => '/tmp/x']];
        $this->assertSame('', vk_save_child_photo($files, 5, sys_get_temp_dir()));
    }

    // ---- both forms collect child_photo (table cell + dynamic JS row) ----

    public function testBothFormsCollectChildPhoto(): void
    {
        foreach (['register.php', 'app/bms/customer/customers.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $count = substr_count($src, 'name="child_photo[]"');
            $this->assertGreaterThanOrEqual(2, $count,
                "$f must collect child_photo in both the static row and the JS-added row");
        }
    }

    // ---- both handlers persist photo into the children JSON ----

    public function testBothHandlersPersistChildPhoto(): void
    {
        foreach (['actions/process_registration.php', 'actions/add_member.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $this->assertStringContainsString("\$_FILES['child_photo']", $src, "$f must read child_photo files");
            $this->assertStringContainsString("'photo'", $src, "$f must store photo in the child entry");
            $this->assertStringContainsString('vk_save_child_photo', $src, "$f must use the upload helper");
        }
    }
}
