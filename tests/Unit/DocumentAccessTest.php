<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Document Library access rules (includes/document_access.php):
 *   public     -> every logged-in user
 *   restricted -> leadership + the uploader
 *   private    -> the uploader + admins
 * Admins always see everything. Enforced in api/get_documents.php (listing) and
 * document_library.php (download).
 */
class DocumentAccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/document_access.php';
    }

    private function doc(string $level, int $owner = 99): array
    {
        return ['access_level' => $level, 'uploaded_by' => $owner];
    }

    // ----- vk_user_can_access_document --------------------------------------

    public function test_admin_sees_everything(): void
    {
        foreach (['public', 'restricted', 'private'] as $lvl) {
            $this->assertTrue(vk_user_can_access_document($this->doc($lvl), 1, true, true), "admin + $lvl");
        }
    }

    public function test_public_is_visible_to_any_user(): void
    {
        // ordinary member (not admin, not leader), not the owner
        $this->assertTrue(vk_user_can_access_document($this->doc('public'), 5, false, false));
    }

    public function test_private_only_owner_and_admin(): void
    {
        $owner = 99;
        $this->assertTrue(vk_user_can_access_document($this->doc('private', $owner), $owner, false, false), 'owner');
        $this->assertFalse(vk_user_can_access_document($this->doc('private', $owner), 5, false, true), 'leader, not owner');
        $this->assertFalse(vk_user_can_access_document($this->doc('private', $owner), 5, false, false), 'member, not owner');
    }

    public function test_restricted_owner_and_leaders_only(): void
    {
        $owner = 99;
        $this->assertTrue(vk_user_can_access_document($this->doc('restricted', $owner), 5, false, true), 'leader');
        $this->assertTrue(vk_user_can_access_document($this->doc('restricted', $owner), $owner, false, false), 'owner');
        $this->assertFalse(vk_user_can_access_document($this->doc('restricted', $owner), 5, false, false), 'ordinary member');
    }

    public function test_missing_access_level_defaults_to_private(): void
    {
        // No access_level key -> treated as private: visible only to owner/admin.
        $this->assertFalse(vk_user_can_access_document(['uploaded_by' => 99], 5, false, false));
        $this->assertTrue(vk_user_can_access_document(['uploaded_by' => 99], 99, false, false));
    }

    // ----- vk_document_visibility_where -------------------------------------

    public function test_visibility_sql_is_open_for_admin(): void
    {
        $params = [];
        $this->assertSame('1=1', vk_document_visibility_where('d', 1, true, true, $params));
        $this->assertSame([], $params);
    }

    public function test_visibility_sql_for_member_is_public_or_own(): void
    {
        $params = [];
        $sql = vk_document_visibility_where('d', 5, false, false, $params);
        $this->assertStringContainsString("d.access_level = 'public'", $sql);
        $this->assertStringContainsString('d.uploaded_by = :vis_uid', $sql);
        $this->assertStringNotContainsString("'restricted'", $sql, 'members must not see restricted');
        $this->assertSame(5, $params[':vis_uid']);
    }

    public function test_visibility_sql_for_leader_includes_restricted(): void
    {
        $params = [];
        $sql = vk_document_visibility_where('d', 5, false, true, $params);
        $this->assertStringContainsString("d.access_level = 'restricted'", $sql);
    }
}
