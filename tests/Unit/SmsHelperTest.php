<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pure (DB-free, network-free) helper tests for includes/sms_helper.php:
 * sms_normalize_phone(), sms_segments(), sms_gateways().
 */
class SmsHelperTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // sms_helper requires config.php (DB connect). Skip that here by
        // loading only the function definitions if not already present.
        if (!function_exists('sms_normalize_phone')) {
            // config.php opens a PDO connection; the helper still defines the
            // pure functions regardless, so require it (DB may be available in CI).
            require_once __DIR__ . '/../../includes/sms_helper.php';
        }
    }

    // ----- sms_normalize_phone ---------------------------------------------

    public function test_local_zero_prefix_becomes_255(): void
    {
        $this->assertSame('255712345678', sms_normalize_phone('0712345678'));
    }

    public function test_nine_digit_local_becomes_255(): void
    {
        $this->assertSame('255712345678', sms_normalize_phone('712345678'));
    }

    public function test_international_plus_is_stripped(): void
    {
        $this->assertSame('255712345678', sms_normalize_phone('+255 712 345 678'));
    }

    public function test_empty_phone_returns_empty(): void
    {
        $this->assertSame('', sms_normalize_phone(''));
        $this->assertSame('', sms_normalize_phone('   '));
    }

    public function test_custom_country_code(): void
    {
        $this->assertSame('254712345678', sms_normalize_phone('0712345678', '254'));
    }

    // ----- sms_segments -----------------------------------------------------

    public function test_segments_single_for_160_chars(): void
    {
        $this->assertSame(1, sms_segments(str_repeat('a', 160)));
    }

    public function test_segments_two_over_160(): void
    {
        $this->assertSame(2, sms_segments(str_repeat('a', 161)));
    }

    public function test_segments_zero_for_empty(): void
    {
        $this->assertSame(0, sms_segments(''));
    }

    // ----- sms_gateways -----------------------------------------------------

    public function test_gateways_include_expected_providers(): void
    {
        $g = sms_gateways(false);
        foreach (['beem', 'africastalking', 'twilio', 'custom'] as $k) {
            $this->assertArrayHasKey($k, $g, "missing gateway: $k");
        }
    }

    public function test_beem_requires_key_secret_sender(): void
    {
        $this->assertSame(['api_key', 'api_secret', 'sender_id'], sms_gateways(false)['beem']['fields']);
    }

    public function test_africastalking_requires_username(): void
    {
        $this->assertContains('username', sms_gateways(false)['africastalking']['fields']);
    }

    public function test_gateway_help_is_localised(): void
    {
        $this->assertNotSame(
            sms_gateways(false)['beem']['help'],
            sms_gateways(true)['beem']['help']
        );
    }
}
