<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the SMS feature: real gateway transport, the admin SMS Settings
 * page and its save/test endpoints, the SMS Center page/API, routing & menu.
 */
class SmsSettingsTest extends TestCase
{
    private string $helper;
    private string $save;
    private string $test;
    private string $settings;
    private string $center;
    private string $centerApi;
    private string $roots;
    private string $header;

    protected function setUp(): void
    {
        $r = __DIR__ . '/../../';
        $this->helper    = file_get_contents($r . 'includes/sms_helper.php');
        $this->save      = file_get_contents($r . 'api/sms/save_settings.php');
        $this->test      = file_get_contents($r . 'api/sms/test_connection.php');
        $this->settings  = file_get_contents($r . 'app/constant/settings/sms_settings.php');
        $this->center    = file_get_contents($r . 'app/constant/communication/sms_center.php');
        $this->centerApi = file_get_contents($r . 'api/sms_center.php');
        $this->roots     = file_get_contents($r . 'roots.php');
        $this->header    = file_get_contents($r . 'header.php');
    }

    // ----- Real transport ---------------------------------------------------

    public function test_helper_sends_via_real_gateways_not_simulation(): void
    {
        $this->assertStringContainsString('apisms.beem.africa', $this->helper);
        $this->assertStringContainsString('api.africastalking.com', $this->helper);
        $this->assertStringContainsString('api.twilio.com', $this->helper);
        $this->assertStringContainsString('curl_exec', $this->helper);
    }

    public function test_helper_no_longer_simulates_success(): void
    {
        // The old helper logged and returned success without sending.
        $this->assertStringNotContainsString('we simulate a successful send', $this->helper);
    }

    public function test_config_requires_credentials_before_ready(): void
    {
        $this->assertStringContainsString("'has_gateway'", $this->helper);
        $this->assertStringContainsString('aiDecryptSecret', $this->helper);
    }

    public function test_send_sms_backward_compatible_wrapper_kept(): void
    {
        $this->assertStringContainsString('function send_sms(', $this->helper);
        $this->assertStringContainsString('sms_send(', $this->helper);
    }

    public function test_logs_to_sms_logs_not_loan_table(): void
    {
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sms_logs', $this->helper);
        $this->assertStringContainsString('INSERT INTO sms_logs', $this->helper);
    }

    // ----- Save endpoint ----------------------------------------------------

    public function test_save_admin_gated_and_encrypts_secrets(): void
    {
        $this->assertStringContainsString('isAdmin()', $this->save);
        $this->assertStringContainsString("canEdit('system_settings')", $this->save);
        $this->assertStringContainsString('aiEncryptSecret', $this->save);
        $this->assertStringContainsString("strpos(\$apiKey, '•') === false", $this->save);
    }

    public function test_save_requires_post_and_audits(): void
    {
        $this->assertStringContainsString("REQUEST_METHOD'] !== 'POST'", $this->save);
        $this->assertStringContainsString('logActivity(', $this->save);
    }

    public function test_test_endpoint_sends_real_sms(): void
    {
        $this->assertStringContainsString('sms_send(', $this->test);
        $this->assertStringContainsString('isAdmin()', $this->test);
    }

    // ----- Settings page ----------------------------------------------------

    public function test_settings_page_admin_only_and_bilingual(): void
    {
        $this->assertStringContainsString("redirectTo('unauthorized')", $this->settings);
        $this->assertStringContainsString('Mipangilio ya SMS', $this->settings);
        $this->assertStringContainsString('.claude/ui-constants.md', $this->settings);
    }

    public function test_settings_page_has_masked_secret_and_buttons(): void
    {
        $this->assertStringContainsString('id="sms_api_key"', $this->settings);
        $this->assertStringContainsString('type="password"', $this->settings);
        $this->assertStringContainsString('id="btnTest"', $this->settings);
        $this->assertStringContainsString('Swal.fire', $this->settings);
        $this->assertDoesNotMatchRegularExpression('/(?<![\w$.])alert\s*\(/', $this->settings);
    }

    // ----- SMS Center -------------------------------------------------------

    public function test_center_gates_permission_and_ui_constants(): void
    {
        $this->assertStringContainsString("requireViewPermission('message_center')", $this->center);
        $this->assertStringContainsString('.claude/ui-constants.md', $this->center);
        $this->assertStringContainsString("dom: 'rtipB'", $this->center);
        $this->assertStringContainsString('bi-gear-fill', $this->center);
    }

    public function test_center_uses_ajax_select2_for_recipients(): void
    {
        $this->assertStringContainsString('minimumInputLength: 0', $this->center);
        $this->assertStringContainsString("action: 'search_recipients'", $this->center);
    }

    public function test_center_api_actions_and_security(): void
    {
        foreach (["case 'list'", "case 'get'", "case 'send'", "case 'resend'", "case 'delete'", "case 'search_recipients'"] as $a) {
            $this->assertStringContainsString($a, $this->centerApi);
        }
        $this->assertStringContainsString('canCreate(', $this->centerApi);
        $this->assertStringContainsString('canDelete(', $this->centerApi);
        $this->assertStringContainsString('logCreate(', $this->centerApi);
        $this->assertStringContainsString('$pdo->prepare(', $this->centerApi);
    }

    // ----- Routing & menu ---------------------------------------------------

    public function test_routes_registered(): void
    {
        $this->assertStringContainsString("'sms_center' => COMMUNICATION_DIR . '/sms_center.php'", $this->roots);
        $this->assertStringContainsString("'sms_settings' => SETTINGS_DIR . '/sms_settings.php'", $this->roots);
        $this->assertStringContainsString("'api/sms_center' => API_DIR . '/sms_center.php'", $this->roots);
        $this->assertStringContainsString("'api/sms/save_settings' => API_DIR . '/sms/save_settings.php'", $this->roots);
    }

    public function test_menu_links_sms_and_settings(): void
    {
        $this->assertStringContainsString("getUrl('sms_center')", $this->header);
        $this->assertStringContainsString("getUrl('sms-settings')", $this->header);
        // The old dead SMS placeholder must be gone.
        $this->assertDoesNotMatchRegularExpression('/href="#"><i class="bi bi-phone"><\/i>/', $this->header);
    }
}
