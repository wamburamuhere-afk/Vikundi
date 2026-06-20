<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for core/ai_prompt_builder.php — turns field + controls into chat messages.
 * Works without a database (aiGetBasePrompt falls back gracefully).
 */
class AiPromptBuilderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../core/ai_prompt_builder.php';
    }

    public function test_returns_system_and_user_messages(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'hello']);
        $this->assertCount(2, $m);
        $this->assertSame('system', $m[0]['role']);
        $this->assertSame('user', $m[1]['role']);
    }

    public function test_system_prompt_forbids_changing_data(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'x']);
        // The system prompt must constrain the model to only drafting text.
        $this->assertStringContainsStringIgnoringCase('only draft', $m[0]['content']);
        $this->assertStringContainsStringIgnoringCase('never invent', $m[0]['content']);
    }

    public function test_instruction_is_included(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'Remind about the meeting']);
        $this->assertStringContainsString('Remind about the meeting', $m[1]['content']);
    }

    public function test_swahili_language_requested(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'x', 'language' => 'sw']);
        $this->assertStringContainsString('Swahili', $m[1]['content']);
    }

    public function test_english_language_requested_by_default(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'x', 'language' => 'en']);
        $this->assertStringContainsString('English', $m[1]['content']);
    }

    public function test_tone_is_applied(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'message', 'instruction' => 'x', 'tone' => 'urgent']);
        $this->assertStringContainsStringIgnoringCase('urgent', $m[1]['content']);
    }

    public function test_current_text_included_for_improve(): void
    {
        $m = aiBuildMessages(['module' => 'general', 'field_type' => 'improve', 'current_text' => 'fix this txt']);
        $this->assertStringContainsString('fix this txt', $m[1]['content']);
    }

    public function test_context_skips_id_fields(): void
    {
        $m = aiBuildMessages([
            'module' => 'general', 'field_type' => 'message', 'instruction' => 'x',
            'context' => ['member_id' => 999, 'group_name' => 'Umoja Group'],
        ]);
        $this->assertStringContainsString('Umoja Group', $m[1]['content']);
        $this->assertStringNotContainsString('999', $m[1]['content']);
    }

    public function test_base_prompt_lookup_function_exists(): void
    {
        $this->assertTrue(function_exists('aiGetBasePrompt'));
    }
}
