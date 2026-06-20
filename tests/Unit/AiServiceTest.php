<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for core/ai_service.php — provider-agnostic AI layer.
 * Verifies the pure helpers and the "never throw / degrade gracefully" contract.
 */
class AiServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../core/ai_service.php';
    }

    public function test_three_providers_are_supported(): void
    {
        $p = aiProviderModels();
        $this->assertArrayHasKey('openai', $p);
        $this->assertArrayHasKey('anthropic', $p);
        $this->assertArrayHasKey('google', $p);
    }

    public function test_each_provider_lists_models(): void
    {
        foreach (aiProviderModels() as $key => $info) {
            $this->assertNotEmpty($info['models'], "$key must list at least one model");
            $this->assertArrayHasKey('label', $info);
        }
    }

    public function test_cost_estimate_known_models_positive(): void
    {
        $this->assertGreaterThan(0, aiEstimateCost('gpt-4o-mini', 1000, 1000));
        $this->assertGreaterThan(0, aiEstimateCost('claude-haiku-4-5', 1000, 1000));
        $this->assertGreaterThan(0, aiEstimateCost('gemini-2.0-flash', 1000, 1000));
    }

    public function test_cost_estimate_unknown_model_is_zero(): void
    {
        $this->assertSame(0.0, aiEstimateCost('some-unknown-model', 1000, 1000));
    }

    public function test_cost_estimate_scales_with_tokens(): void
    {
        $small = aiEstimateCost('gpt-4o', 100, 100);
        $large = aiEstimateCost('gpt-4o', 10000, 10000);
        $this->assertGreaterThan($small, $large);
    }

    public function test_complete_without_config_returns_error_not_exception(): void
    {
        // No DB / no settings in this context → must degrade gracefully, never throw.
        $res = aiComplete([['role' => 'user', 'content' => 'hi']]);
        $this->assertIsArray($res);
        $this->assertFalse($res['ok']);
        $this->assertArrayHasKey('error', $res);
    }

    public function test_generate_clamps_variation_count(): void
    {
        // Even if asked for 10, it must not crash; with no config it returns ok=false.
        $res = aiGenerate([['role' => 'user', 'content' => 'hi']], 10);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('results', $res);
    }

    public function test_service_file_has_all_three_adapters(): void
    {
        $src = file_get_contents(__DIR__ . '/../../core/ai_service.php');
        $this->assertStringContainsString('_aiCallOpenAI', $src);
        $this->assertStringContainsString('_aiCallAnthropic', $src);
        $this->assertStringContainsString('_aiCallGemini', $src);
    }

    public function test_service_decrypts_key_via_crypto(): void
    {
        $src = file_get_contents(__DIR__ . '/../../core/ai_service.php');
        $this->assertStringContainsString('aiDecryptSecret', $src);
    }
}
