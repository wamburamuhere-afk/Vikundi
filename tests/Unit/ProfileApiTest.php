<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/api_profile.php';

/**
 * Module 18 — Profile (own account, own preferences, own password, own avatar).
 *
 * SCOPE, mirrored from includes/api_profile.php's own header: every endpoint
 * here is deliberately SELF-ONLY for every authenticated user — no ?id
 * override anywhere — mirroring app/constant/profile/my_settings.php, NOT
 * app/constant/profile/profile.php (that page's own comment: "Ordinary
 * view-only Members cannot edit any profile — including their own," which
 * belongs to Module 3/Members, not this one).
 *
 * Unlike Module 17 (Settings & Roles), which is Admin/Chairperson-only via
 * vk_api_settings_require_admin(), and unlike Module 16 (Communication),
 * which leadership-gates sending — NONE of this module's five endpoints call
 * vk_api_require_permission(), vk_api_can(), or vk_api_is_admin(). The only
 * gate is vk_api_require_auth(): being logged in at all is sufficient,
 * exactly like my_settings.php.
 *
 * avatar_url is built by THIS module's own vk_api_avatar_url(), which points
 * at api/v1/avatar.php (token-authed) — never helpers.php's vk_avatar_url(),
 * which points at the session-gated api/get_upload.php and 401s a
 * token-authenticated mobile client fetching its own avatar (confirmed live
 * before api/v1/avatar.php existed). vk_api_avatar_url()'s absolute-URL
 * construction mirrors includes/api_group_settings.php's own
 * vk_group_settings_logo_url() (see tests/Unit/GroupLogoTest.php for the
 * precedent this file's own URL-construction tests copy).
 *
 * Password policy is includes/registration_validator.php's
 * reg_password_errors() (8+ chars, a letter, a number) — the SAME policy
 * includes/api_settings.php's vk_api_users_validate_password() already
 * enforces for admin-created accounts (see SettingsApiTest) — deliberately
 * stricter than my_settings.php's own web form, which only required 6
 * characters with no letter/number rule. That is documented, deliberate
 * hardening, not a discrepancy.
 */
final class ProfileApiTest extends TestCase
{
    private static function code(string $rel): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(__DIR__ . '/../../' . $rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }

    /** Extracts every <option value="..."> inside <select name="$selectName">...</select>. */
    private static function extractSelectOptionValues(string $code, string $selectName): array
    {
        if (!preg_match('/<select[^>]*name="' . preg_quote($selectName, '/') . '"[^>]*>(.*?)<\/select>/s', $code, $m)) {
            return [];
        }
        preg_match_all('/<option value="([^"]*)"/', $m[1], $opts);
        return $opts[1];
    }

    private const ENDPOINT_FILES = [
        'api/v1/profile.php',
        'api/v1/profile_settings.php',
        'api/v1/profile_password.php',
        'api/v1/profile_avatar.php',
        'api/v1/avatar.php',
    ];

    private const SERVER = [
        'HTTP_HOST'     => 'demo.vikundi.bjptechnologies.co.tz',
        'HTTPS'         => 'on',
        'DOCUMENT_ROOT' => '/home/site/public_html',
    ];

    // ── vk_api_avatar_url(): absolute-URL construction ──────────────────────

    public function testAvatarUrlReturnsEmptyStringForNullStored(): void
    {
        $this->assertSame('', vk_api_avatar_url(null));
    }

    public function testAvatarUrlReturnsEmptyStringForAnEmptyOrBlankStored(): void
    {
        $this->assertSame('', vk_api_avatar_url(''));
        $this->assertSame('', vk_api_avatar_url('   '));
    }

    public function testAvatarUrlStripsAnyPathPrefixToTheBasenameBeforeUse(): void
    {
        $url = vk_api_avatar_url('uploads/avatars/x.png', []);
        $this->assertSame('/api/v1/avatar?name=x.png', $url);
        $this->assertStringNotContainsString('uploads/avatars', $url);
    }

    public function testAvatarUrlWithNoHostReturnsTheAppRelativeForm(): void
    {
        // No HTTP_HOST/SERVER_NAME in the override — no scheme, no host.
        $this->assertSame('/api/v1/avatar?name=x.png', vk_api_avatar_url('x.png', []));
    }

    public function testAvatarUrlIsAbsoluteWithHostAndSchemeWhenServerCarriesAHost(): void
    {
        $this->assertSame(
            'https://demo.vikundi.bjptechnologies.co.tz/api/v1/avatar?name=x.png',
            vk_api_avatar_url('x.png', self::SERVER)
        );
    }

    public function testAvatarUrlPlainHttpIsNotAdvertisedAsHttps(): void
    {
        $url = vk_api_avatar_url('x.png', ['HTTP_HOST' => 'localhost']);
        $this->assertStringStartsWith('http://', $url);
    }

    public function testAvatarUrlInASubdirectoryInstallKeepsItsBasePath(): void
    {
        // Local WAMP: the project sits at localhost/vikundi, so a URL without
        // the base path resolves nowhere. Same technique as
        // GroupLogoTest::testASubdirectoryInstallKeepsItsBasePath().
        $url = vk_api_avatar_url('a.png', [
            'HTTP_HOST'     => 'localhost',
            'DOCUMENT_ROOT' => dirname(__DIR__, 3),
        ]);
        $this->assertStringContainsString('/' . basename(dirname(__DIR__, 2)) . '/api/v1/avatar?name=a.png', $url);
    }

    public function testAvatarUrlEncodesASpaceInTheStoredName(): void
    {
        $this->assertStringContainsString('name=my%20avatar.png', vk_api_avatar_url('my avatar.png', self::SERVER));
    }

    // ── vk_api_profile_row(): the shaped users(+roles+customers) row ────────

    private static function profileRaw(array $over = []): array
    {
        return $over + [
            'user_id'     => 7,
            'username'    => 'jmwakyusa',
            'first_name'  => 'Juma',
            'middle_name' => 'M',
            'last_name'   => 'Mwakyusa',
            'email'       => 'juma@example.com',
            'phone'       => '255712345678',
            'avatar'      => 'uploads/avatars/avatar_123.png',
            'role_id'     => 4,
            'role_name'   => 'Treasurer',
            'customer_id' => 12,
            'status'      => 'active',
            'created_at'  => '2026-09-01 09:00:00',
            'last_login'  => '2026-09-08 07:30:00',
        ];
    }

    public function testProfileRowNeverIncludesThePasswordOrAnyHashDerivedField(): void
    {
        $row = vk_api_profile_row(self::profileRaw(['password' => '$2y$10$abundantlyhashedvalue']));
        $this->assertArrayNotHasKey('password', $row);
        foreach (array_keys($row) as $key) {
            $this->assertStringNotContainsStringIgnoringCase(
                'password',
                $key,
                "the shaped row must never carry a password-shaped key ($key)"
            );
        }
    }

    public function testProfileRowShapesTheCoreFields(): void
    {
        $row = vk_api_profile_row(self::profileRaw());
        $this->assertSame(7, $row['user_id']);
        $this->assertSame('jmwakyusa', $row['username']);
        $this->assertSame('Juma', $row['first_name']);
        $this->assertSame('M', $row['middle_name']);
        $this->assertSame('Mwakyusa', $row['last_name']);
        $this->assertSame('juma@example.com', $row['email']);
        $this->assertSame('255712345678', $row['phone']);
        $this->assertSame(4, $row['role_id']);
        $this->assertSame('Treasurer', $row['role_name']);
        $this->assertSame(12, $row['member_id']);
        $this->assertSame('active', $row['status']);
    }

    public function testProfileRowAvatarUrlIsBuiltFromTheAvatarKeyWithItsPathStripped(): void
    {
        $row = vk_api_profile_row(self::profileRaw(['avatar' => 'uploads/avatars/avatar_123.png']));
        $this->assertStringContainsString('/api/v1/avatar?name=avatar_123.png', $row['avatar_url']);
        $this->assertStringNotContainsString('uploads/avatars', $row['avatar_url']);
    }

    public function testProfileRowAvatarUrlIsNullWhenAvatarIsAbsent(): void
    {
        $raw = self::profileRaw();
        unset($raw['avatar']);
        $this->assertNull(vk_api_profile_row($raw)['avatar_url']);
    }

    public function testProfileRowAvatarUrlIsNullWhenAvatarIsExplicitlyNull(): void
    {
        $this->assertNull(vk_api_profile_row(self::profileRaw(['avatar' => null]))['avatar_url']);
    }

    public function testProfileRowMemberIdIsCastFromCustomerId(): void
    {
        $this->assertSame(12, vk_api_profile_row(self::profileRaw(['customer_id' => '12']))['member_id']);
    }

    public function testProfileRowMemberIdIsNullWhenCustomerIdIsAbsent(): void
    {
        $raw = self::profileRaw();
        unset($raw['customer_id']);
        $this->assertNull(vk_api_profile_row($raw)['member_id']);
    }

    public function testProfileRowMemberIdIsNullWhenCustomerIdIsExplicitlyNull(): void
    {
        $this->assertNull(vk_api_profile_row(self::profileRaw(['customer_id' => null]))['member_id']);
    }

    public function testProfileRowRoleIdIsNullWhenExplicitlyNull(): void
    {
        $this->assertNull(vk_api_profile_row(self::profileRaw(['role_id' => null]))['role_id']);
    }

    public function testProfileRowRoleNameDefaultsToNullWhenAbsent(): void
    {
        $raw = self::profileRaw();
        unset($raw['role_name']);
        $this->assertNull(vk_api_profile_row($raw)['role_name']);
    }

    public function testProfileRowStatusDefaultsToPendingWhenAbsent(): void
    {
        $raw = self::profileRaw();
        unset($raw['status']);
        $this->assertSame('pending', vk_api_profile_row($raw)['status']);
    }

    public function testProfileRowNameEmailPhoneDefaultToEmptyStringWhenAbsent(): void
    {
        $raw = self::profileRaw();
        unset($raw['first_name'], $raw['middle_name'], $raw['last_name'], $raw['email'], $raw['phone']);
        $row = vk_api_profile_row($raw);
        $this->assertSame('', $row['first_name']);
        $this->assertSame('', $row['middle_name']);
        $this->assertSame('', $row['last_name']);
        $this->assertSame('', $row['email']);
        $this->assertSame('', $row['phone']);
    }

    public function testProfileRowCreatedAtAndLastLoginAreNullWhenEmpty(): void
    {
        $row = vk_api_profile_row(self::profileRaw(['created_at' => null, 'last_login' => null]));
        $this->assertNull($row['created_at']);
        $this->assertNull($row['last_login']);
    }

    public function testProfileRowCreatedAtAndLastLoginAreFormattedAsDateAtomWhenPresent(): void
    {
        $row = vk_api_profile_row(self::profileRaw());
        $this->assertSame(date(DATE_ATOM, strtotime('2026-09-01 09:00:00')), $row['created_at']);
        $this->assertSame(date(DATE_ATOM, strtotime('2026-09-08 07:30:00')), $row['last_login']);
    }

    // ── vk_api_profile_validate_password(): wraps reg_password_errors() ─────

    public function testValidatePasswordRejectsAWeakPassword(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/weak_password/');
        vk_api_profile_validate_password('abc');
    }

    public function testValidatePasswordAcceptsAStrongPassword(): void
    {
        vk_api_profile_validate_password('Abcdefg1');
        $this->assertTrue(true, 'no exception means reg_password_errors() found nothing wrong');
    }

    public function testValidatePasswordUsesTheSuppliedLanguageForItsErrors(): void
    {
        try {
            vk_api_profile_validate_password('abc', 'sw');
            $this->fail('expected a weak_password refusal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'Nywila',
                $e->getMessage(),
                'the $lang argument must actually reach reg_password_errors()'
            );
        }
    }

    public function testValidatePasswordUsesTheSameStricterPolicyAsSettingsApiUsers(): void
    {
        // Both wrap reg_password_errors() with the identical 8+/letter/number
        // rule — a self-service change must not be held to a lower bar than
        // an admin-created account. 6-character passwords pass my_settings.php's
        // own weaker web check but must still fail here.
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/weak_password/');
        vk_api_profile_validate_password('abc123');
    }

    // ── the four settings enums ──────────────────────────────────────────────

    public function testSettingsLanguagesAreEnAndSw(): void
    {
        $this->assertSame(['en', 'sw'], vk_api_profile_settings_languages());
    }

    public function testSettingsThemesAreLightAndDark(): void
    {
        $this->assertSame(['light', 'dark'], vk_api_profile_settings_themes());
    }

    public function testSettingsTimezonesMatchTheFourOptions(): void
    {
        $this->assertSame(
            ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'UTC'],
            vk_api_profile_settings_timezones()
        );
    }

    public function testSettingsDateFormatsMatchTheFiveOptions(): void
    {
        $this->assertSame(
            ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY/MM/DD', 'YYYY-MM-DD', 'DD Mon YYYY'],
            vk_api_profile_settings_date_formats()
        );
    }

    // These two cross-check the API's hardcoded lists against my_settings.php's
    // own <select> options — a grep/string assertion against the actual web
    // file, not just the array in isolation, per the module brief.

    public function testMySettingsTimezoneDropdownStillListsExactlyWhatTheApiValidatesAgainst(): void
    {
        $code = self::code('app/constant/profile/my_settings.php');
        $this->assertSame(
            vk_api_profile_settings_timezones(),
            self::extractSelectOptionValues($code, 'timezone'),
            "my_settings.php's timezone <select> has drifted from vk_api_profile_settings_timezones()"
        );
    }

    public function testMySettingsDateFormatDropdownStillListsExactlyWhatTheApiValidatesAgainst(): void
    {
        $code = self::code('app/constant/profile/my_settings.php');
        $this->assertSame(
            vk_api_profile_settings_date_formats(),
            self::extractSelectOptionValues($code, 'date_format'),
            "my_settings.php's date_format <select> has drifted from vk_api_profile_settings_date_formats()"
        );
    }

    // ── vk_api_profile_settings_row(): preferences/notification JSON shaping ─

    public function testSettingsRowDefaultsWhenPreferencesAndNotificationPreferencesAreAbsent(): void
    {
        $row = vk_api_profile_settings_row([]);
        $this->assertSame('en', $row['language']);
        $this->assertSame('light', $row['theme']);
        $this->assertSame('Africa/Dar_es_Salaam', $row['timezone']);
        $this->assertSame('DD/MM/YYYY', $row['date_format']);
        $this->assertTrue($row['email_notifications']);
        $this->assertTrue($row['sms_notifications']);
    }

    public function testSettingsRowDegradesToDefaultsOnMalformedJsonRatherThanErroring(): void
    {
        $row = vk_api_profile_settings_row([
            'preferred_language'        => 'sw',
            'preferences'               => 'not json at all',
            'notification_preferences'  => '{also not json',
        ]);
        $this->assertSame('sw', $row['language'], 'a valid scalar column is unaffected by the malformed JSON columns');
        $this->assertSame('light', $row['theme']);
        $this->assertSame('Africa/Dar_es_Salaam', $row['timezone']);
        $this->assertSame('DD/MM/YYYY', $row['date_format']);
        $this->assertTrue($row['email_notifications']);
        $this->assertTrue($row['sms_notifications']);
    }

    public function testSettingsRowRoundTripsRealJsonIncludingTheBooleanCasts(): void
    {
        $row = vk_api_profile_settings_row([
            'preferred_language'       => 'sw',
            'preferences'              => json_encode(['theme' => 'dark', 'timezone' => 'Africa/Nairobi', 'date_format' => 'YYYY-MM-DD']),
            'notification_preferences' => json_encode(['email' => 0, 'sms' => 1]),
        ]);
        $this->assertSame('sw', $row['language']);
        $this->assertSame('dark', $row['theme']);
        $this->assertSame('Africa/Nairobi', $row['timezone']);
        $this->assertSame('YYYY-MM-DD', $row['date_format']);
        $this->assertFalse($row['email_notifications'], 'JSON 0 must cast to false, not stay truthy as a string');
        $this->assertTrue($row['sms_notifications']);
    }

    public function testSettingsRowLanguageDefaultsToEnWhenAbsent(): void
    {
        $this->assertSame('en', vk_api_profile_settings_row(['preferences' => '{}'])['language']);
    }

    // ── no endpoint checks a permission or admin gate ────────────────────────

    public function testNoEndpointChecksAnyPermissionOrAdminGate(): void
    {
        // Module 18 is deliberately self-only for every authenticated user —
        // unlike Module 17 (Settings & Roles), which is admin-only via
        // vk_api_settings_require_admin(). No file here may call
        // vk_api_require_permission(), vk_api_can(), or vk_api_is_admin().
        foreach (self::ENDPOINT_FILES as $file) {
            $code = self::code($file);
            $this->assertStringNotContainsString('vk_api_require_permission(', $code, $file);
            $this->assertStringNotContainsString('vk_api_can(', $code, $file);
            $this->assertStringNotContainsString('vk_api_is_admin(', $code, $file);
        }
    }

    public function testEveryEndpointReachesTheApiAuthMarker(): void
    {
        foreach (self::ENDPOINT_FILES as $file) {
            $this->assertStringContainsString('vk_api_require_auth(', self::code($file), $file);
        }
    }

    // ── structural: profile.php ──────────────────────────────────────────────

    public function testProfileAcceptsGetAndPutOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET', 'PUT'])", self::code('api/v1/profile.php'));
    }

    public function testProfileRequiresFirstAndLastName(): void
    {
        $this->assertStringContainsString("if (\$firstName === '' || \$lastName === '')", self::code('api/v1/profile.php'));
    }

    public function testProfileValidatesEmailFormat(): void
    {
        $this->assertStringContainsString('filter_var($email, FILTER_VALIDATE_EMAIL)', self::code('api/v1/profile.php'));
    }

    public function testProfileEmailUniquenessCheckExcludesTheCallersOwnRow(): void
    {
        $this->assertStringContainsString(
            'WHERE LOWER(email) = LOWER(?) AND user_id != ?',
            self::code('api/v1/profile.php')
        );
    }

    public function testProfileCapturesTheOldEmailBeforeUpdatingUsers(): void
    {
        $code = self::code('api/v1/profile.php');
        $oldEmail = strpos($code, "\$oldEmail = (string) \$existing['email'];");
        $updateUsers = strpos($code, 'UPDATE users SET first_name');
        $this->assertNotFalse($oldEmail, '$oldEmail capture not found');
        $this->assertNotFalse($updateUsers, 'UPDATE users statement not found');
        $this->assertLessThan($updateUsers, $oldEmail, 'the old email must be captured before the users row is overwritten');
    }

    public function testProfileUpdatesTheCustomersRowByTheOldEmailNotTheNewOne(): void
    {
        $code = self::code('api/v1/profile.php');
        $this->assertStringContainsString(
            'UPDATE customers SET first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ? WHERE LOWER(email) = LOWER(?)',
            $code
        );
        $this->assertStringContainsString(
            "->execute([\$firstName, \$middleName, \$lastName, \$email, \$phone, \$oldEmail]);",
            $code,
            'the customers UPDATE must be bound with $oldEmail, not the new $email'
        );
    }

    public function testProfileCustomersUpdateRunsAfterTheUsersUpdate(): void
    {
        $code = self::code('api/v1/profile.php');
        $usersUpdate = strpos($code, 'UPDATE users SET first_name');
        $customersUpdate = strpos($code, 'UPDATE customers SET first_name');
        $this->assertNotFalse($usersUpdate);
        $this->assertNotFalse($customersUpdate);
        $this->assertLessThan($customersUpdate, $usersUpdate);
    }

    public function testProfileWriteIsAuditedAgainstTheRealUser(): void
    {
        $code = self::code('api/v1/profile.php');
        $this->assertStringContainsString("\$_SESSION['user_id'] = \$callerId", $code);
        $this->assertMatchesRegularExpression("/logUpdate\([^;]*\\\$callerId\)/s", $code);
    }

    // ── structural: profile_settings.php ─────────────────────────────────────

    public function testProfileSettingsAcceptsGetAndPutOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET', 'PUT'])", self::code('api/v1/profile_settings.php'));
    }

    public function testProfileSettingsIsAPartialUpdateEachFieldFallsBackToTheExistingValue(): void
    {
        // A body with only {"theme":"dark"} must leave language/timezone/
        // date_format/notifications at their existing values, not reset to
        // defaults — every field falls back to $existing, never a hardcoded
        // default, when absent from the body.
        $code = self::code('api/v1/profile_settings.php');
        $this->assertStringContainsString("\$body['language'] ?? \$existing['language']", $code);
        $this->assertStringContainsString("\$body['theme'] ?? \$existing['theme']", $code);
        $this->assertStringContainsString("\$body['timezone'] ?? \$existing['timezone']", $code);
        $this->assertStringContainsString("\$body['date_format'] ?? \$existing['date_format']", $code);
        $this->assertStringContainsString("array_key_exists('email_notifications', \$body)", $code);
        $this->assertStringContainsString("\$existing['email_notifications']", $code);
        $this->assertStringContainsString("array_key_exists('sms_notifications', \$body)", $code);
        $this->assertStringContainsString("\$existing['sms_notifications']", $code);
    }

    public function testProfileSettingsFourEnumFieldsEachHaveADistinctErrorCode(): void
    {
        $code = self::code('api/v1/profile_settings.php');
        foreach ([
            'invalid_language',
            'invalid_theme',
            'invalid_timezone',
            'invalid_date_format',
        ] as $errorCode) {
            $this->assertStringContainsString("vk_api_error(422, '{$errorCode}'", $code, $errorCode);
        }
    }

    public function testProfileSettingsValidatesEachEnumAgainstItsOwnListFunction(): void
    {
        $code = self::code('api/v1/profile_settings.php');
        $this->assertStringContainsString('vk_api_profile_settings_languages()', $code);
        $this->assertStringContainsString('vk_api_profile_settings_themes()', $code);
        $this->assertStringContainsString('vk_api_profile_settings_timezones()', $code);
        $this->assertStringContainsString('vk_api_profile_settings_date_formats()', $code);
    }

    public function testProfileSettingsWriteIsAuditedAgainstTheRealUser(): void
    {
        $code = self::code('api/v1/profile_settings.php');
        $this->assertStringContainsString("\$_SESSION['user_id'] = \$callerId", $code);
        $this->assertMatchesRegularExpression("/logUpdate\([^;]*\\\$callerId\)/s", $code);
    }

    public function testProfileSettingsAlsoUpdatesTheSessionsPreferredLanguage(): void
    {
        $this->assertStringContainsString(
            "\$_SESSION['preferred_language'] = \$language;",
            self::code('api/v1/profile_settings.php')
        );
    }

    // ── structural: profile_password.php ─────────────────────────────────────

    public function testProfilePasswordIsPostOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['POST'])", self::code('api/v1/profile_password.php'));
    }

    public function testProfilePasswordVerifiesTheCurrentPasswordWithPasswordVerify(): void
    {
        $this->assertStringContainsString(
            'password_verify($currentPassword, $currentHash)',
            self::code('api/v1/profile_password.php')
        );
    }

    public function testProfilePasswordChecksRunInTheDocumentedOrder(): void
    {
        // fields required -> current password verified (401 wrong_password)
        // -> new/confirm match (422 mismatch) -> strength (422 weak_password)
        // -> only then the UPDATE.
        $code = self::code('api/v1/profile_password.php');

        $fieldsRequired = strpos($code, "vk_api_error(422, 'fields_required'");
        $wrongPassword  = strpos($code, "vk_api_error(401, 'wrong_password'");
        $mismatch       = strpos($code, "vk_api_error(422, 'mismatch'");
        $weakPassword   = strpos($code, 'vk_api_profile_validate_password($newPassword, $lang)');
        $update         = strpos($code, 'UPDATE users SET password');

        $this->assertNotFalse($fieldsRequired, 'fields_required check not found');
        $this->assertNotFalse($wrongPassword, 'wrong_password check not found');
        $this->assertNotFalse($mismatch, 'mismatch check not found');
        $this->assertNotFalse($weakPassword, 'weak_password validation call not found');
        $this->assertNotFalse($update, 'UPDATE users SET password statement not found');

        $this->assertLessThan($wrongPassword, $fieldsRequired, 'fields_required must run before the current password is verified');
        $this->assertLessThan($mismatch, $wrongPassword, 'the current password must be verified before the new/confirm match is checked');
        $this->assertLessThan($weakPassword, $mismatch, 'the new/confirm mismatch must be checked before password strength');
        $this->assertLessThan($update, $weakPassword, 'password strength must be validated before the UPDATE runs');
    }

    public function testProfilePasswordUpdateSetsPasswordChangedAtInTheSameStatementAsPassword(): void
    {
        $this->assertStringContainsString(
            'UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?',
            self::code('api/v1/profile_password.php')
        );
    }

    public function testProfilePasswordHashesTheNewPasswordBeforeStoringIt(): void
    {
        $this->assertStringContainsString(
            'password_hash($newPassword, PASSWORD_DEFAULT)',
            self::code('api/v1/profile_password.php')
        );
    }

    public function testProfilePasswordChangeIsAuditedAgainstTheRealUser(): void
    {
        $code = self::code('api/v1/profile_password.php');
        $this->assertStringContainsString("\$_SESSION['user_id'] = \$callerId", $code);
        $this->assertMatchesRegularExpression("/logUpdate\([^;]*\\\$callerId\)/s", $code);
    }

    // ── structural: profile_avatar.php ───────────────────────────────────────

    public function testProfileAvatarIsPostOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['POST'])", self::code('api/v1/profile_avatar.php'));
    }

    public function testProfileAvatarExpectsTheMultipartFieldNamedAvatar(): void
    {
        $this->assertStringContainsString("\$_FILES['avatar']", self::code('api/v1/profile_avatar.php'));
    }

    public function testProfileAvatarRefusesWhenNoFileWasUploaded(): void
    {
        $this->assertStringContainsString("vk_api_error(422, 'no_file'", self::code('api/v1/profile_avatar.php'));
    }

    public function testProfileAvatarIsBuiltOnTheSharedUploadStoreNotANaiveExtensionCheck(): void
    {
        $code = self::code('api/v1/profile_avatar.php');
        $this->assertStringContainsString('vk_api_store_upload(', $code);
        $this->assertStringNotContainsString('pathinfo($_FILES', $code, 'must not fall back to my_settings.php\'s own extension-only check');
    }

    public function testProfileAvatarEnforcesATwoMegabyteLimit(): void
    {
        $this->assertStringContainsString('2097152', self::code('api/v1/profile_avatar.php'));
    }

    public function testProfileAvatarStoresIntoTheUploadsAvatarsDirectory(): void
    {
        $this->assertStringContainsString(
            "dirname(__DIR__, 2) . '/uploads/avatars'",
            self::code('api/v1/profile_avatar.php')
        );
    }

    public function testProfileAvatarResponseUsesThisModulesOwnUrlBuilderNotHelpersVkAvatarUrl(): void
    {
        $code = self::code('api/v1/profile_avatar.php');
        $this->assertStringContainsString('vk_api_avatar_url($stored)', $code);
        $this->assertStringNotContainsString('vk_avatar_url(', $code, 'must not use the session-gated helpers.php URL builder');
    }

    public function testProfileAvatarChangeIsAuditedAgainstTheRealUser(): void
    {
        $code = self::code('api/v1/profile_avatar.php');
        $this->assertStringContainsString("\$_SESSION['user_id'] = \$callerId", $code);
        $this->assertMatchesRegularExpression("/logUpdate\([^;]*\\\$callerId\)/s", $code);
    }

    public function testProfileAvatarRefusalPrecedesTheWrite(): void
    {
        $code = self::code('api/v1/profile_avatar.php');
        $gate  = strpos($code, "vk_api_error(422, 'no_file'");
        $store = strpos($code, 'vk_api_store_upload(');
        $write = strpos($code, "UPDATE users SET avatar");
        $this->assertNotFalse($gate);
        $this->assertNotFalse($store);
        $this->assertNotFalse($write);
        $this->assertLessThan($store, $gate, 'the no-file refusal must run before the upload is stored');
        $this->assertLessThan($write, $store, 'the upload must be stored (and validated) before the users row is written');
    }

    // ── structural: avatar.php ───────────────────────────────────────────────

    public function testAvatarEndpointIsGetOnly(): void
    {
        $this->assertStringContainsString("vk_api_require_method(['GET'])", self::code('api/v1/avatar.php'));
    }

    public function testAvatarEndpointIsAdminNeutralAnyAuthenticatedUserNotSelfScoped(): void
    {
        // Mirrors api/get_upload.php's own type=avatar branch comment: "Any
        // authenticated user may see them" — this endpoint serves any avatar
        // by filename, no per-owner restriction, unlike get_upload.php's
        // signature branch.
        $code = self::code('api/v1/avatar.php');
        $this->assertStringContainsString('vk_api_require_auth();', $code);
        $this->assertStringNotContainsString('$callerId', $code, 'this endpoint must not scope by the caller\'s own id');
        $this->assertStringNotContainsString('$auth[', $code, 'the returned auth array is not even consulted beyond requiring it');
    }

    public function testAvatarEndpointValidatesTheNameCharsetBeforeAnyFilesystemCall(): void
    {
        $code = self::code('api/v1/avatar.php');
        $nameCheck = strpos($code, "preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}\$/'");
        $realpath  = strpos($code, 'realpath(');
        $this->assertNotFalse($nameCheck, 'filename charset whitelist not found');
        $this->assertNotFalse($realpath, 'realpath() containment check not found');
        $this->assertLessThan($realpath, $nameCheck, 'the filename charset whitelist must run before any filesystem call');
    }

    /**
     * The exact rule the endpoint applies to `name`, pinned as behaviour so a
     * loosening of it fails a test rather than passing review — same
     * technique as GatedUploadReaderTest::testNameConstraintRejectsTraversalAndSeparators().
     */
    public function testAvatarEndpointNameRegexRejectsTraversalAndPathSeparators(): void
    {
        $accept = static function (string $n): bool {
            return (bool) preg_match('/^[A-Za-z0-9_][A-Za-z0-9._-]{0,254}$/', $n) && !str_contains($n, '..');
        };

        $this->assertTrue($accept('avatar_7.png'));
        $this->assertTrue($accept('avatar_1775154296.JPG'));

        $this->assertFalse($accept('../../../etc/passwd'));
        $this->assertFalse($accept('a/b.png'));
        $this->assertFalse($accept('a\\b.png'));
        $this->assertFalse($accept('..'));
        $this->assertFalse($accept(''));
        $this->assertFalse($accept('x"><script>alert(1)</script>'));
    }

    public function testAvatarEndpointAppliesRealpathContainmentAgainstTheAvatarsDirectory(): void
    {
        $code = self::code('api/v1/avatar.php');
        $this->assertStringContainsString("realpath(dirname(__DIR__, 2) . '/uploads/avatars')", $code);
        $this->assertStringContainsString('strpos($path, $baseDir) !== 0', $code);
    }

    public function testAvatarEndpointRequiresBothAnExtensionWhitelistAndARealImageByteCheck(): void
    {
        // A .png-named file that is not really image bytes must be refused —
        // both checks must be required together (OR'd refusal conditions).
        $code = self::code('api/v1/avatar.php');
        $this->assertStringContainsString('VK_API_AVATAR_MIME', $code);
        $this->assertStringContainsString('getimagesize($path)', $code);
        $this->assertStringContainsString(
            "!isset(VK_API_AVATAR_MIME[\$ext]) || @getimagesize(\$path) === false",
            $code,
            'both the extension whitelist and the byte-content check must gate the same refusal'
        );
    }

    public function testAvatarEndpointSetsANoSniffHeaderOnTheServedFile(): void
    {
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', self::code('api/v1/avatar.php'));
    }

    public function testAvatarEndpointMimeWhitelistCoversTheCommonImageTypesOnly(): void
    {
        $code = self::code('api/v1/avatar.php');
        foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
            $this->assertStringContainsString("'{$ext}'", $code, $ext);
        }
        $this->assertStringNotContainsString("'svg'", $code, 'an SVG on this origin is stored XSS, same rule as the group logo upload');
    }

    // ── routing ───────────────────────────────────────────────────────────────

    public function testEveryEndpointIsNamedWhatTheRouterResolvesTo(): void
    {
        $expect = [
            'api/v1/profile'          => 'profile.php',
            'api/v1/profile/settings' => 'profile_settings.php',
            'api/v1/profile/password' => 'profile_password.php',
            'api/v1/profile/avatar'   => 'profile_avatar.php',
            'api/v1/avatar'           => 'avatar.php',
        ];
        foreach ($expect as $uri => $file) {
            if (preg_match('#^api/v1/([a-z0-9-]+)/(\d+)(?:/([a-z0-9_-]+))?$#', $uri, $m)) {
                $resolved = $m[1] . '_' . ($m[3] ?? 'detail') . '.php';
            } elseif (preg_match('#^api/v1/([a-z0-9-]+)/([a-z][a-z0-9_-]*)$#', $uri, $m)) {
                $resolved = $m[1] . '_' . $m[2] . '.php';
            } else {
                $resolved = basename($uri) . '.php';
            }
            $this->assertSame($file, $resolved, "{$uri} resolves elsewhere");
            $this->assertFileExists(__DIR__ . '/../../api/v1/' . $resolved);
        }
    }
}
