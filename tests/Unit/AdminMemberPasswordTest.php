<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Admin-created members get a deterministic initial password of username@123
 * (the admin no longer types one). Self-registration still uses a typed password.
 */
class AdminMemberPasswordTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private function src(string $rel): string
    {
        return file_get_contents(self::ROOT . $rel);
    }

    public function testHandlerDerivesPasswordFromUsername(): void
    {
        $h = $this->src('actions/add_member.php');
        $this->assertStringContainsString("\$password = \$username . '@123';", $h, 'password = username@123');
        // The typed password is no longer trusted.
        $this->assertStringNotContainsString("\$_POST['password']", $h);
    }

    public function testWizardHasNoTypedPasswordInputs(): void
    {
        $page = $this->src('app/bms/customer/customers.php');
        $this->assertStringNotContainsString('name="password"', $page);
        $this->assertStringNotContainsString('name="confirm_password"', $page);
        // The password-match JS check is gone (the fields no longer exist).
        $this->assertStringNotContainsString("\$('#reg_password').val()", $page);
    }

    public function testWizardExplainsTheAutoPassword(): void
    {
        $this->assertStringContainsString('username@123', $this->src('app/bms/customer/customers.php'));
    }

    public function testSelfRegistrationStillUsesTypedPassword(): void
    {
        // Unchanged: the public flow reads the member's own password.
        $this->assertStringContainsString("\$_POST['password']", $this->src('actions/process_registration.php'));
    }

    public function testPasswordRoundTrips(): void
    {
        $username = 'bkessy';
        $hash = password_hash($username . '@123', PASSWORD_DEFAULT);
        $this->assertTrue(password_verify('bkessy@123', $hash));
    }
}
