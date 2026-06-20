<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static-source tests for the Email Templates backend (comms > Email Templates).
 * These three endpoints were previously missing/stub — assert they now exist
 * and follow Vikundi API conventions (session + RBAC + prepared + audit + JSON).
 */
class EmailTemplatesApiTest extends TestCase
{
    private string $get;
    private string $save;
    private string $delete;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../api/';
        $this->get    = file_get_contents($base . 'get_email_templates.php');
        $this->save   = file_get_contents($base . 'save_email_template.php');
        $this->delete = file_get_contents($base . 'delete_email_template.php');
    }

    public function test_get_is_not_the_old_stub(): void
    {
        // Old stub was 10 lines returning recordsTotal:0; real one queries the table.
        $this->assertStringContainsString('FROM email_templates', $this->get);
        $this->assertStringContainsString('email_ensure_templates_table', $this->get);
    }

    public function test_all_endpoints_check_authentication(): void
    {
        foreach (['get' => $this->get, 'save' => $this->save, 'delete' => $this->delete] as $name => $src) {
            $this->assertStringContainsString("isset(\$_SESSION['user_id'])", $src, "$name must check auth");
        }
    }

    public function test_endpoints_enforce_permissions(): void
    {
        $this->assertStringContainsString('canView(', $this->get);
        $this->assertStringContainsString('canCreate(', $this->save);
        $this->assertStringContainsString('canEdit(', $this->save);
        $this->assertStringContainsString('canDelete(', $this->delete);
    }

    public function test_mutations_write_audit_logs(): void
    {
        $this->assertStringContainsString('logCreate(', $this->save);
        $this->assertStringContainsString('logUpdate(', $this->save);
        $this->assertStringContainsString('logDelete(', $this->delete);
    }

    public function test_mutations_use_prepared_statements(): void
    {
        $this->assertStringContainsString('$pdo->prepare(', $this->save);
        $this->assertStringContainsString('$pdo->prepare(', $this->delete);
    }

    public function test_save_requires_post(): void
    {
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $this->save);
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $this->delete);
    }

    public function test_endpoints_return_json(): void
    {
        foreach ([$this->get, $this->save, $this->delete] as $src) {
            $this->assertStringContainsString("header('Content-Type: application/json')", $src);
        }
    }

    public function test_save_validates_required_fields(): void
    {
        $this->assertStringContainsString('Template name is required', $this->save);
        $this->assertStringContainsString('Subject is required', $this->save);
        $this->assertStringContainsString('Content is required', $this->save);
    }

    public function test_get_is_bilingual(): void
    {
        $this->assertStringContainsString('preferred_language', $this->get);
        $this->assertStringContainsString('Permission denied', $this->get);
        $this->assertStringContainsString('Huna ruhusa', $this->get);
    }
}
