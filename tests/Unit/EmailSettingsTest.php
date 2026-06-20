<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Email Settings feature: the admin SMTP setup page and its
 * save/test endpoints. Verifies admin gating, encrypted password storage,
 * provider presets, bilingual UI, ui-constants compliance and wiring.
 */
class EmailSettingsTest extends TestCase
{
    private string $page;
    private string $save;
    private string $test;
    private string $helper;
    private string $roots;
    private string $header;

    protected function setUp(): void
    {
        $root = __DIR__ . '/../../';
        $this->page   = file_get_contents($root . 'app/constant/settings/email_settings.php');
        $this->save   = file_get_contents($root . 'api/email/save_settings.php');
        $this->test   = file_get_contents($root . 'api/email/test_connection.php');
        $this->helper = file_get_contents($root . 'includes/email_helper.php');
        $this->roots  = file_get_contents($root . 'roots.php');
        $this->header = file_get_contents($root . 'header.php');
    }

    // ----- Transport / helper ----------------------------------------------

    public function test_helper_uses_phpmailer_smtp_transport(): void
    {
        $this->assertStringContainsString('PHPMailer\\PHPMailer\\PHPMailer', $this->helper);
        $this->assertStringContainsString('function email_send_smtp', $this->helper);
        $this->assertStringContainsString('isSMTP()', $this->helper);
    }

    public function test_helper_falls_back_when_phpmailer_missing(): void
    {
        // class_exists guard so a missing library degrades gracefully.
        $this->assertStringContainsString("class_exists('PHPMailer", $this->helper);
        $this->assertStringContainsString('@mail(', $this->helper); // fallback path retained
    }

    public function test_config_only_reports_smtp_ready_when_fully_configured(): void
    {
        // has_smtp requires host + username + password all present.
        $this->assertStringContainsString("'has_smtp'", $this->helper);
        $this->assertStringContainsString("!empty(\$s['smtp_host']) && !empty(\$s['smtp_username']) && \$pass !== ''", $this->helper);
    }

    public function test_password_is_decrypted_via_existing_crypto(): void
    {
        $this->assertStringContainsString('aiDecryptSecret', $this->helper);
    }

    // ----- Save endpoint ----------------------------------------------------

    public function test_save_is_admin_gated(): void
    {
        $this->assertStringContainsString('isAdmin()', $this->save);
        $this->assertStringContainsString("canEdit('system_settings')", $this->save);
    }

    public function test_save_requires_post(): void
    {
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $this->save);
    }

    public function test_save_encrypts_password_and_skips_masked(): void
    {
        $this->assertStringContainsString('aiEncryptSecret', $this->save);
        // Don't overwrite when the field still shows the masked placeholder.
        $this->assertStringContainsString("strpos(\$newPass, '•') === false", $this->save);
    }

    public function test_save_applies_provider_presets(): void
    {
        $this->assertStringContainsString('email_smtp_providers', $this->save);
        $this->assertStringContainsString("\$provider !== 'custom'", $this->save);
    }

    public function test_save_writes_audit_log(): void
    {
        $this->assertStringContainsString('logActivity(', $this->save);
        $this->assertStringContainsString('Email Settings', $this->save);
    }

    // ----- Test-connection endpoint ----------------------------------------

    public function test_test_endpoint_admin_gated_and_sends_real_email(): void
    {
        $this->assertStringContainsString('isAdmin()', $this->test);
        $this->assertStringContainsString('email_send(', $this->test);
        $this->assertStringContainsString("header('Content-Type: application/json')", $this->test);
    }

    // ----- Page compliance --------------------------------------------------

    public function test_page_is_admin_only(): void
    {
        $this->assertStringContainsString('isAdmin()', $this->page);
        $this->assertStringContainsString("redirectTo('unauthorized')", $this->page);
    }

    public function test_page_references_ui_constants(): void
    {
        $this->assertStringContainsString('.claude/ui-constants.md', $this->page);
    }

    public function test_page_has_provider_presets_and_masked_password(): void
    {
        $this->assertStringContainsString('email_provider', $this->page);
        $this->assertStringContainsString('id="smtp_password"', $this->page);
        $this->assertStringContainsString('type="password"', $this->page);
        $this->assertStringContainsString('togglePass', $this->page); // show/hide
    }

    public function test_page_has_save_and_test_buttons(): void
    {
        $this->assertStringContainsString('id="btnSave"', $this->page);
        $this->assertStringContainsString('id="btnTest"', $this->page);
        $this->assertStringContainsString("'#emailSettingsForm').on('submit'", $this->page);
        $this->assertStringContainsString("'#btnTest').on('click'", $this->page);
    }

    public function test_page_shows_status_badge(): void
    {
        // Active / Disabled / Not set up.
        $this->assertMatchesRegularExpression('/Active|Imeunganishwa/', $this->page);
        $this->assertMatchesRegularExpression('/Not set up yet|haijasanidiwa/', $this->page);
    }

    public function test_page_is_bilingual_and_blue(): void
    {
        $this->assertStringContainsString('preferred_language', $this->page);
        $this->assertStringContainsString('Mipangilio ya Barua Pepe', $this->page);
        $this->assertStringContainsString('btn-primary', $this->page);
        $this->assertStringNotContainsString('btn-success', $this->page);
    }

    public function test_page_uses_sweetalert_not_native_dialogs(): void
    {
        $this->assertStringContainsString('Swal.fire', $this->page);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$.])alert\s*\(/', $this->page);
    }

    // ----- Routing & menu ---------------------------------------------------

    public function test_routes_registered(): void
    {
        $this->assertStringContainsString("'email_settings' => SETTINGS_DIR . '/email_settings.php'", $this->roots);
        $this->assertStringContainsString("'api/email/save_settings' => API_DIR . '/email/save_settings.php'", $this->roots);
        $this->assertStringContainsString("'api/email/test_connection' => API_DIR . '/email/test_connection.php'", $this->roots);
    }

    public function test_menu_links_to_email_settings(): void
    {
        $this->assertStringContainsString("getUrl('email-settings')", $this->header);
    }
}
