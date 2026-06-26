<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Registration PR-C: richer guarantor details. Covers the migration, the
 * auth-gated autofill endpoint, the six-field location on both forms, and the
 * "pull existing member" picker being present on the admin form only (the
 * public form must not expose the member directory to anonymous visitors).
 */
class GuarantorDetailsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    private const LOC_FIELDS = ['country', 'state', 'district', 'ward', 'street', 'house_number'];

    // ---- migration ----

    public function testMigrationDeclaresGuarantorColumns(): void
    {
        $src = file_get_contents(self::ROOT . 'database/add_guarantor_detail_columns.php');
        $this->assertStringContainsString('guarantor_member_id', $src);
        foreach (self::LOC_FIELDS as $f) {
            $this->assertStringContainsString("guarantor_{$f}", $src, "migration must add guarantor_{$f}");
        }
    }

    public function testMigrationIsRegisteredInRunner(): void
    {
        $runner = file_get_contents(self::ROOT . 'database/migrate.php');
        $this->assertStringContainsString('add_guarantor_detail_columns.php', $runner,
            'migration must run automatically via migrate.php');
    }

    // ---- autofill endpoint is auth-gated ----

    public function testAutofillEndpointRequiresAuth(): void
    {
        $src = file_get_contents(self::ROOT . 'api/get_guarantor_member.php');
        $this->assertStringContainsString('require_auth.php', $src,
            'member-detail endpoint must be behind authentication');
    }

    // ---- six-field location on BOTH forms ----

    public function testBothFormsHaveGuarantorLocation(): void
    {
        foreach (['register.php', 'app/bms/customer/customers.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            foreach (self::LOC_FIELDS as $field) {
                $this->assertStringContainsString('name="guarantor_' . $field . '"', $src,
                    "$f must collect guarantor_$field");
            }
        }
    }

    // ---- picker is ADMIN-ONLY ----

    public function testPickerOnAdminFormOnly(): void
    {
        $admin  = file_get_contents(self::ROOT . 'app/bms/customer/customers.php');
        $public = file_get_contents(self::ROOT . 'register.php');

        $this->assertStringContainsString('id="guarantorMemberSelect"', $admin, 'admin form must have the picker');
        $this->assertStringContainsString('name="guarantor_member_id"', $admin, 'admin form must capture the member link');
        $this->assertStringContainsString('get_guarantor_member', $admin, 'admin form must autofill from the endpoint');

        // Privacy: the public self-registration form must NOT expose a member picker.
        $this->assertStringNotContainsString('guarantorMemberSelect', $public, 'public form must not leak the member directory');
        $this->assertStringNotContainsString('name="guarantor_member_id"', $public);
    }

    // ---- handlers persist the new columns ----

    public function testHandlersPersistGuarantorColumns(): void
    {
        foreach (['actions/process_registration.php', 'actions/add_member.php'] as $f) {
            $src = file_get_contents(self::ROOT . $f);
            foreach (['guarantor_member_id', 'guarantor_country', 'guarantor_ward', 'guarantor_house_number'] as $col) {
                $this->assertStringContainsString($col, $src, "$f must persist $col");
            }
        }
        // Only the admin handler reads the member link from the picker.
        $this->assertStringContainsString("\$_POST['guarantor_member_id']",
            file_get_contents(self::ROOT . 'actions/add_member.php'));
    }
}
