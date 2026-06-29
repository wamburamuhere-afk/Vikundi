<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for language consistency of the internal "Register New
 * Member" action handler, actions/add_member.php.
 *
 * Bug: server-side error messages (phone already in use, payment slip
 * required, etc.) were hardcoded in Swahili only and were shown by the
 * front-end under a hardcoded English "Error" title — producing a mixed
 * English/Swahili popup regardless of the selected language.
 *
 * Note: there is no email-duplicate message — admin-created members get an
 * auto-generated identity email (username@domain), unique by construction.
 *
 * Fix: all admin-facing messages now follow the admin's UI language
 * ($_SESSION['preferred_language']) and carry both EN and SW variants.
 */
class AddMemberLanguageTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $filePath = __DIR__ . '/../../actions/add_member.php';
        $this->src = file_get_contents($filePath);
    }

    public function test_message_language_follows_admin_ui_language(): void
    {
        // A single language source derived from the admin's session.
        $this->assertStringContainsString("\$ui_lang", $this->src);
        $this->assertStringContainsString("\$_SESSION['preferred_language']", $this->src);
        // Validation/dedup messages now use that source, not the member's account toggle.
        $this->assertStringContainsString('$val_lang = $ui_lang;', $this->src);
    }

    public function test_old_member_language_source_is_gone(): void
    {
        // The previous source tied admin-facing messages to the new member's
        // chosen account language, which could differ from the admin's UI.
        $this->assertStringNotContainsString(
            "in_array(\$preferred_lang, ['en', 'sw'], true) ? \$preferred_lang : 'en'",
            $this->src
        );
    }

    public function test_phone_duplicate_message_is_bilingual(): void
    {
        $this->assertStringContainsString(
            'Hii namba ya simu tayari imesajiliwa na mwanachama mwingine.',
            $this->src
        );
        $this->assertStringContainsString(
            'This phone number is already registered to another member.',
            $this->src
        );
    }

    public function test_other_admin_facing_messages_are_bilingual(): void
    {
        // Payment slip required
        $this->assertStringContainsString(
            'Please upload the payment slip first to complete this registration.',
            $this->src
        );
        // Required fields
        $this->assertStringContainsString('Please fill in all required fields (*).', $this->src);
        // Not logged in / no permission
        $this->assertStringContainsString('You are not logged in.', $this->src);
        $this->assertStringContainsString('You do not have permission to register a member.', $this->src);
    }
}
