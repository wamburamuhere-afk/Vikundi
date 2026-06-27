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
            'password'         => 'secret12',
            'confirm_password' => 'secret12',
            'terms'            => '1',
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

    // ----- reg_password_errors (audit M6: central password policy) -----------

    public function test_strong_password_has_no_errors(): void
    {
        $this->assertSame([], reg_password_errors('secret12'));
        $this->assertSame([], reg_password_errors('Vikundi2024'));
    }

    public function test_short_password_is_rejected(): void
    {
        $errors = reg_password_errors('abc1');
        $this->assertContains('Password must be at least 8 characters.', $errors);
    }

    public function test_password_without_a_digit_is_rejected(): void
    {
        $errors = reg_password_errors('onlyletters');
        $this->assertContains('Password must contain at least one number.', $errors);
    }

    public function test_password_without_a_letter_is_rejected(): void
    {
        $errors = reg_password_errors('12345678');
        $this->assertContains('Password must contain at least one letter.', $errors);
    }

    public function test_password_policy_messages_localize(): void
    {
        $errors = reg_password_errors('abc', 'sw');
        $this->assertContains('Nywila lazima iwe na herufi 8 au zaidi.', $errors);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $post = $this->validPost();
        $post['password'] = 'weak';            // < 8 and no digit
        $post['confirm_password'] = 'weak';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('Password must be at least 8 characters.', $errors);
        $this->assertContains('Password must contain at least one number.', $errors);
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

    // ----- name format ------------------------------------------------------

    public function test_invalid_name_format_is_reported(): void
    {
        $post = $this->validPost();
        $post['first_name'] = '12345';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('First name contains invalid characters (use letters only, e.g. John).', $errors);
    }

    public function test_names_with_apostrophe_hyphen_and_spaces_pass(): void
    {
        foreach (["N'Goma", 'Al-Amin', 'Mary Jane', 'Peter'] as $name) {
            $this->assertTrue(reg_valid_name($name), "Expected '$name' to be a valid name");
        }
    }

    // ----- NIDA -------------------------------------------------------------

    public function test_nida_validated_only_when_present(): void
    {
        $post = $this->validPost();
        $post['nida_number'] = '';
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));

        $post['nida_number'] = '123';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('The NIDA number must be 20 digits.', $errors);

        $post['nida_number'] = '1234 5678 9012 3456 7890'; // 20 digits with spaces
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));
    }

    public function test_spouse_nida_validated_only_when_present(): void
    {
        $post = $this->validPost();
        $post['spouse_nida'] = '99';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('The spouse NIDA number must be 20 digits.', $errors);
    }

    // ----- entrance fee -----------------------------------------------------

    public function test_negative_fee_is_reported_and_blank_is_allowed(): void
    {
        $post = $this->validPost();
        $post['entrance_fee'] = '';
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));

        $post['entrance_fee'] = '-500';
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('The registration fee must be a positive number.', $errors);
    }

    // ----- children ages ----------------------------------------------------

    public function test_child_age_out_of_range_is_reported_with_row_number(): void
    {
        $post = $this->validPost();
        $post['child_age'] = ['5', '999'];
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('Child #2 age is not valid (use a number 0–120).', $errors);
    }

    public function test_blank_child_ages_are_ignored(): void
    {
        $post = $this->validPost();
        $post['child_age'] = ['', '7', ''];
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));
    }

    // ----- terms ------------------------------------------------------------

    public function test_terms_must_be_accepted(): void
    {
        $post = $this->validPost();
        unset($post['terms']);
        $errors = validate_registration_input($post, $this->validFiles(), 'en');
        $this->assertContains('You must accept the terms and conditions to register.', $errors);
    }

    public function test_terms_accepted_with_on_value(): void
    {
        $post = $this->validPost();
        $post['terms'] = 'on';
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en'));
    }

    // ----- admin "Register New Member" path (requireTerms = false) -----------

    public function test_terms_not_required_when_flag_is_false(): void
    {
        $post = $this->validPost();
        unset($post['terms']); // admin form has no terms checkbox
        $this->assertSame([], validate_registration_input($post, $this->validFiles(), 'en', false));
    }

    public function test_admin_path_still_enforces_all_other_rules(): void
    {
        $post = $this->validPost();
        unset($post['terms']);
        $post['email'] = 'bad';
        $post['phone'] = '12';
        $post['nida_number'] = '123';
        $errors = validate_registration_input($post, $this->validFiles(), 'en', false);
        $this->assertContains('Please enter a valid email address, e.g. john@example.com', $errors);
        $this->assertContains('Please enter a valid phone number, e.g. 0712345678 or +255712345678', $errors);
        $this->assertContains('The NIDA number must be 20 digits.', $errors);
        $this->assertNotContains('You must accept the terms and conditions to register.', $errors);
    }

    public function test_admin_create_does_not_require_password_when_auto_generated(): void
    {
        // Regression: the admin "Register New Member" form never collects a password
        // (it is auto-generated as username@123). The call must pass requirePassword=false
        // (args: requireTerms=false, requireSlip=true, requirePassword=false) — otherwise
        // every admin member creation failed with "Password is required."
        $post = $this->validPost();
        unset($post['terms'], $post['password'], $post['confirm_password']);
        $errors = validate_registration_input($post, $this->validFiles(), 'en', false, true, false);
        $this->assertSame([], $errors);
    }

    public function test_admin_create_still_rejects_missing_password_when_required(): void
    {
        // Documents the bug: with the default requirePassword=true and no password,
        // the admin submission is rejected — which is what broke member creation.
        $post = $this->validPost();
        unset($post['terms'], $post['password'], $post['confirm_password']);
        $errors = validate_registration_input($post, $this->validFiles(), 'en', false); // requirePassword defaults true
        $this->assertContains('Password is required.', $errors);
    }

    // ----- public registration honeypot wiring (form <-> handler) -----------

    public function test_honeypot_field_name_is_in_sync_and_neutral(): void
    {
        $form    = (string) file_get_contents(__DIR__ . '/../../register.php');
        $handler = (string) file_get_contents(__DIR__ . '/../../actions/process_registration.php');

        // The form renders the neutral honeypot name and the handler checks the same key.
        $this->assertStringContainsString('name="hp_token"', $form);
        $this->assertStringContainsString("\$_POST['hp_token']", $handler);

        // The old autofill-magnet name ("contact_website" / a "Website" field) is gone
        // from both — it was being auto-filled and falsely flagging real users as bots.
        $this->assertStringNotContainsString('contact_website', $form);
        $this->assertStringNotContainsString('contact_website', $handler);
    }

    // ----- profile EDIT path (no slip / password / terms required) ----------

    public function test_edit_mode_does_not_require_slip_password_or_terms(): void
    {
        // Only the entered identity fields, no files, no password, no terms.
        $post = [
            'first_name' => 'John',
            'last_name'  => 'Doe',
            'email'      => 'john@example.com',
            'phone'      => '0712345678',
        ];
        $errors = validate_registration_input($post, [], 'en', false, false, false);
        $this->assertSame([], $errors);
    }

    public function test_edit_mode_still_enforces_formats(): void
    {
        $post = [
            'first_name'  => 'John',
            'last_name'   => 'Doe',
            'email'       => 'bad-email',
            'phone'       => 'abc',
            'nida_number' => '123',
        ];
        $errors = validate_registration_input($post, [], 'en', false, false, false);
        $this->assertContains('Please enter a valid email address, e.g. john@example.com', $errors);
        $this->assertContains('Please enter a valid phone number, e.g. 0712345678 or +255712345678', $errors);
        $this->assertContains('The NIDA number must be 20 digits.', $errors);
        $this->assertNotContains('Password is required.', $errors);
        $this->assertNotContains('Please upload your payment slip.', $errors);
    }

    // ----- phone normalization ----------------------------------------------

    public function test_phone_normalization_canonicalises_tz_numbers(): void
    {
        $this->assertSame('+255712345678', reg_normalize_phone('0712345678'));
        $this->assertSame('+255712345678', reg_normalize_phone('255712345678'));
        $this->assertSame('+255712345678', reg_normalize_phone('+255712345678'));
        $this->assertSame('+255712345678', reg_normalize_phone('071 234 5678'));
        $this->assertSame('+255712345678', reg_normalize_phone('071-234-5678'));
        $this->assertSame('', reg_normalize_phone(''));
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
