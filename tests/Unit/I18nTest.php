<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the JSON-based i18n helper (includes/i18n.php):
 *   t(), et(), current_lang(), i18n_load()
 *
 * No database or HTTP required. Translations are read from the real
 * lang/en.json and lang/sw.json files.
 */
class I18nTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/i18n.php';
    }

    protected function setUp(): void
    {
        unset($_SESSION['preferred_language']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['preferred_language']);
    }

    public function testDefaultsToEnglishWhenNoLanguageSet(): void
    {
        $this->assertSame('en', current_lang());
        $this->assertSame('Username', t('login.username'));
    }

    public function testReturnsSwahiliWhenSelected(): void
    {
        $_SESSION['preferred_language'] = 'sw';
        $this->assertSame('sw', current_lang());
        $this->assertSame('Jina la Mtumiaji', t('login.username'));
    }

    public function testUnsupportedLanguageFallsBackToEnglish(): void
    {
        $_SESSION['preferred_language'] = 'fr';
        $this->assertSame('en', current_lang());
        $this->assertSame('Username', t('login.username'));
    }

    public function testMissingKeyReturnsTheKeyItself(): void
    {
        // Never render blank: an unknown key falls back to the key string.
        $this->assertSame('totally.missing.key', t('totally.missing.key'));
    }

    public function testPlaceholderInterpolation(): void
    {
        $this->assertSame('3h ago', t('dashboard.hours_ago', ['n' => 3]));

        $_SESSION['preferred_language'] = 'sw';
        $this->assertSame('3h iliyopita', t('dashboard.hours_ago', ['n' => 3]));
    }

    public function testEtEscapesHtmlOutput(): void
    {
        // "Don't have an account yet?" — the apostrophe must be HTML-escaped.
        $this->assertStringContainsString('&#039;', et('login.no_account'));
    }

    public function testEveryEnglishKeyHasASwahiliTranslation(): void
    {
        $en = json_decode((string) file_get_contents(__DIR__ . '/../../lang/en.json'), true);
        $sw = json_decode((string) file_get_contents(__DIR__ . '/../../lang/sw.json'), true);

        $this->assertIsArray($en);
        $this->assertIsArray($sw);
        $this->assertSame(
            array_keys($en),
            array_keys($sw),
            'lang/en.json and lang/sw.json must contain exactly the same keys.'
        );
    }
}
