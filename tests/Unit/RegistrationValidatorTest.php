<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the pure (DB-free) registration validation in
 * includes/registration_validator.php:
 *  - reg_valid_email()
 *  - reg_valid_phone()
 *  - validate_registration_input()
 *
 * These mirror the client-side format rules in register.php and prove the
 * server reports the SPECIFIC problem for each kind of bad input.
 */
class RegistrationValidatorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/registration_validator.php';
    }

    /** A fully valid set of posted fields. */
    private function validPost(): array
    {
        return [
            'first_name'       => 'John',
            'last_name'        => 'Doe',
            'email'            => 'john@example.com',
            'phone'            => '0712345678',
            'password'         => 'secret1',
            'confirm_password' => 'secret1',
        ];
    }

    /** A valid required file set (payment slip present, no photo). */
    private function validFiles(): array
    {
        return [
            'kianzio_slip' => [
                'name'     => 'slip.pdf',
                'type'     => 'application/pdf',
                'tmp_name' => '/tmp/php123',
                'error'    => UPLOAD_ERR_OK,
                'size'     => 1024,
            ],
        ];
    }

    // ----- reg_valid_email --------------------------------------------------

    public function test_valid_emails_pass(): void
    {
        $this->assertTrue(reg_valid_email('john@example.com'));
        $this->assertTrue(reg_valid_email('  member.name+tag@vikundi.co.tz  '));
    }

    public function test_invalid_emails_fail(): void
    {
        $this->assertFalse(reg_valid_email('not-an-email'));
        $this->assertFalse(reg_valid_email('foo@'));
        $this->assertFalse(reg_valid_email('@bar.com'));
        $this->assertFalse(reg_valid_email(''));
    }

    // ----- reg_valid_phone --------------------------------------------------

    public function test_valid_phones_pass(): void
    {
        $this->assertTrue(reg_valid_phone('0712345678'));          // TZ national
        $this->assertTrue(reg_valid_phone('+255712345678'));       // international
        $this->assertTrue(reg_valid_phone('255712345678'));        // without +
        $this->assertTrue(reg_valid_phone('071 234 5678'));        // spaces tolerated
        $this->assertTrue(reg_valid_phone('071-234-5678'));        // dashes tolerated
    }

    public function test_invalid_phones_fail(): void
    {
        $this->assertFalse(reg_valid_phone('12'));                 // too short
        $this->assertFalse(reg_valid_phone('abc123'));             // letters
        $this->assertFalse(reg_valid_phone('07123456789012345'));  // too long
        $this->assertFalse(reg_valid_phone(''));
    }

    // ----- validate_registration_input: happy path --------------------------

    public function test_fully_valid_input_has_no_errors(): void
    {
        $errors = validate_registration_input($this->validPost(), $this->validFiles(), 'en');
        $this->assertSame([], $errors);
    }

    // ----- required fields --------------------------------------------------

    public function test_missing_required_fields_are_named(): void
    {
        $errors = validate_registration_input([], [], 'en');
        $joined = implode("\n", $errors);
        $this->assertStringContainsString('First name is required.', $joined);
        $this->assertStringContainsString('Last name is required.', $joined);
        $this->assertStringContainsString('Email address is required.', $joined);
        $this->assertStringContainsString('Phone number is required.', $joined);
        $this->assertStringContainsString('Password is required.', $joined);
        $this->assertStringContainsString('Please upload your payment slip.', $joined);
    }

    // ----- format errors ----------------------------------------------------

    public function test_invalid_email_format_is_reported(): void
    {
        $post = $this->validPost();
        $post['email'] = 'bad-email';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('Please enter a valid email address, e.g. john@example.com', $errors);
    }

    public function test_invalid_phone_format_is_reported(): void
    {
        $post = $this->validPost();
        $post['phone'] = '12';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('Please enter a valid phone number, e.g. 0712345678 or +255712345678', $errors);
    }

    public function test_password_mismatch_is_reported(): void
    {
        $post = $this->validPost();
        $post['confirm_password'] = 'different';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('Passwords do not match.', $errors);
    }

    public function test_password_match_not_checked_when_confirm_absent(): void
    {
        $post = $this->validPost();
        unset($post['confirm_password']);
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertSame([], $errors);
    }

    // ----- optional spouse email / relative phones --------------------------

    public function test_spouse_email_validated_only_when_present(): void
    {
        $post = $this->validPost();
        $post['spouse_email'] = '';
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));

        $post['spouse_email'] = 'nope';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('The spouse email address is not valid.', $errors);
    }

    public function test_relative_phone_validated_only_when_present(): void
    {
        $post = $this->validPost();
        $post['father_phone'] = '';
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));

        $post['father_phone'] = 'abc';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains("Father's phone number is not valid.", $errors);
    }

    // ----- payment slip -----------------------------------------------------

    public function test_payment_slip_wrong_type_is_reported(): void
    {
        $files = $this->validFiles();
        $files['kianzio_slip']['name'] = 'note.txt';
        $files['kianzio_slip']['type'] = 'text/plain';
        $errors = validate_registration_input($this->validPost(), $files, 'en');
        $this->assertContains('The payment slip must be a JPG, PNG, or PDF file.', $errors);
    }

    public function test_payment_slip_accepts_jpg_png_pdf(): void
    {
        foreach (['receipt.JPG', 'receipt.png', 'receipt.pdf', 'receipt.jpeg'] as $name) {
            $files = $this->validFiles();
            $files['kianzio_slip']['name'] = $name;
            $this->assertSame(
                [],
                validate_registration_input($this->validPost(), $files, 'en'),
                "Expected $name to be accepted"
            );
        }
    }

    public function test_payment_slip_too_large_is_reported(): void
    {
        $files = $this->validFiles();
        $files['kianzio_slip']['error'] = UPLOAD_ERR_INI_SIZE;
        $errors = validate_registration_input($this->validPost(), $files, 'en');
        $this->assertContains('The payment slip file is too large.', $errors);
    }

    // ----- passport photo ---------------------------------------------------

    public function test_passport_photo_skipped_when_not_uploaded(): void
    {
        $files = $this->validFiles();
        $files['passport_photo'] = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $this->assertSame([], validate_registration_input($this->validPost(), $files, 'en'));
    }

    public function test_passport_photo_wrong_type_is_reported(): void
    {
        $files = $this->validFiles();
        $files['passport_photo'] = ['name' => 'pic.gif', 'type' => 'image/gif', 'tmp_name' => '/tmp/p', 'error' => UPLOAD_ERR_OK, 'size' => 500];
        $errors = validate_registration_input($this->validPost(), $files, 'en');
        $this->assertContains('The passport photo must be a JPG or PNG image.', $errors);
    }

    public function test_passport_photo_too_large_is_reported(): void
    {
        $files = $this->validFiles();
        $files['passport_photo'] = ['name' => 'p.png', 'type' => 'image/png', 'tmp_name' => '/tmp/p', 'error' => UPLOAD_ERR_OK, 'size' => 3 * 1024 * 1024];
        $errors = validate_registration_input($this->validPost(), $files, 'en');
        $this->assertContains('The passport photo must be smaller than 2MB.', $errors);
    }

    public function test_valid_passport_photo_passes(): void
    {
        $files = $this->validFiles();
        $files['passport_photo'] = ['name' => 'me.png', 'type' => 'image/png', 'tmp_name' => '/tmp/p', 'error' => UPLOAD_ERR_OK, 'size' => 500 * 1024];
        $this->assertSame([], validate_registration_input($this->validPost(), $files, 'en'));
    }

    // ----- Swahili messages -------------------------------------------------

    public function test_swahili_messages_are_used(): void
    {
        $post = $this->validPost();
        $post['email'] = 'bad';
        $errors = validate_registration_input($post, $this->validFiles(), 'sw');
        $this->assertContains('Barua pepe si sahihi, mfano john@example.com', $errors);
    }
}
