<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for core/ai_crypto.php — the API-key encryption used by AI Settings.
 * The key must never be stored in plain text.
 */
class AiCryptoTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../core/ai_crypto.php';
    }

    public function test_round_trip_returns_original(): void
    {
        $plain = 'sk-ant-secret-ABC123456789';
        $enc = aiEncryptSecret($plain);
        $this->assertSame($plain, aiDecryptSecret($enc));
    }

    public function test_ciphertext_is_not_plaintext(): void
    {
        $plain = 'sk-openai-TOPSECRET';
        $enc = aiEncryptSecret($plain);
        $this->assertStringNotContainsString('TOPSECRET', $enc);
        $this->assertStringNotContainsString('sk-openai', $enc);
    }

    public function test_each_encryption_uses_fresh_iv(): void
    {
        // Same plaintext should produce different ciphertext (random IV).
        $a = aiEncryptSecret('same-value');
        $b = aiEncryptSecret('same-value');
        $this->assertNotSame($a, $b);
        // …but both decrypt back to the same plaintext.
        $this->assertSame('same-value', aiDecryptSecret($a));
        $this->assertSame('same-value', aiDecryptSecret($b));
    }

    public function test_empty_string_round_trips_safely(): void
    {
        $this->assertSame('', aiEncryptSecret(''));
        $this->assertNull(aiDecryptSecret(''));
    }

    public function test_garbage_ciphertext_returns_null_not_error(): void
    {
        $this->assertNull(aiDecryptSecret('not-valid-base64-or-cipher!!!'));
    }
}
