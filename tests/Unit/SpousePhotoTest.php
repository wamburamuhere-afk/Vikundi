<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Registration: optional spouse passport photo. Guards the migration (column +
 * registered in the runner), that both forms collect spouse_photo, and that both
 * handlers upload and persist it.
 */
class SpousePhotoTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public function testMigrationDeclaresColumnAndIsRegistered(): void
    {
        $mig = file_get_contents(self::ROOT . 'database/add_spouse_photo_column.php');
        $this->assertStringContainsString('spouse_photo', $mig);

        $runner = file_get_contents(self::ROOT . 'database/migrate.php');
        $this->assertStringContainsString('add_spouse_photo_column.php', $runner,
            'spouse photo migration must run automatically via migrate.php');
    }

    public function testBothFormsCollectSpousePhoto(): void
    {
        foreach (['register.php', 'app/bms/customer/customers.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $this->assertStringContainsString('name="spouse_photo"', $src, "$f must collect spouse_photo");
        }
    }

    public function testBothHandlersPersistSpousePhoto(): void
    {
        foreach (['actions/process_registration.php', 'actions/add_member.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            $this->assertStringContainsString("vk_save_photo('spouse_photo')", $src, "$f must upload spouse_photo");
            $this->assertStringContainsString('spouse_photo', $src);
        }
    }
}
