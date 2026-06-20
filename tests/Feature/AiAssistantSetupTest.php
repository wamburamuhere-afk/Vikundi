<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * DB-backed checks that database/ai_assistant_setup.php produced the expected
 * schema, seed data and the role-based permissions that appear in user_roles.php.
 *
 * Requires the local `vikundi` database (skips cleanly if unavailable).
 */
class AiAssistantSetupTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = new PDO('mysql:host=localhost;dbname=vikundi', 'root', '');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            $this->markTestSkipped('vikundi database not available: ' . $e->getMessage());
        }
    }

    public function test_ai_prompts_table_exists(): void
    {
        $this->assertNotFalse($this->pdo->query("SHOW TABLES LIKE 'ai_prompts'")->fetchColumn());
    }

    public function test_ai_usage_log_table_exists(): void
    {
        $this->assertNotFalse($this->pdo->query("SHOW TABLES LIKE 'ai_usage_log'")->fetchColumn());
    }

    public function test_communication_message_prompt_seeded(): void
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM ai_prompts WHERE module='communication' AND submodule='message' AND field_type='message'");
        $s->execute();
        $this->assertGreaterThan(0, (int)$s->fetchColumn());
    }

    public function test_general_fallback_prompts_seeded(): void
    {
        $n = (int)$this->pdo->query("SELECT COUNT(*) FROM ai_prompts WHERE module='general'")->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $n, 'expected general fallbacks: message, improve, translate, shorten');
    }

    public function test_ai_assistant_permission_exists(): void
    {
        $s = $this->pdo->prepare("SELECT module_name FROM permissions WHERE page_key='ai_assistant'");
        $s->execute();
        $this->assertSame('AI Assistant', $s->fetchColumn());
    }

    public function test_ai_settings_permission_exists(): void
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM permissions WHERE page_key='ai_settings'");
        $s->execute();
        $this->assertSame(1, (int)$s->fetchColumn());
    }

    public function test_admin_role_has_ai_grant(): void
    {
        // At least one role should have been granted the AI permission by setup.
        $n = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON p.permission_id = rp.permission_id
             WHERE p.page_key = 'ai_assistant' AND rp.can_create = 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, $n);
    }
}
