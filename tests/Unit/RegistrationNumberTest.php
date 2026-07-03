<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Member registration number — leadership-assigned, unique, hidden from other
 * members. Pure test on the masking; source-guards on the migration, the
 * leadership-gated writer, admin-create storage, the public "assigned later"
 * note, and the preview/approval prompt.
 */
class RegistrationNumberTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../helpers.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    public function testMigrationRegisteredAndAddsColumn(): void
    {
        $this->assertStringContainsString('add_registration_number.php', $this->src('database/migrate.php'));
        $mig = $this->src('database/add_registration_number.php');
        $this->assertStringContainsString('customers', $mig);
        $this->assertStringContainsString('registration_number', $mig);
    }

    public function testRegistrationNumberIsMaskedFromOtherMembers(): void
    {
        // vk_mask_member_row must blank it, so a member never sees another's number.
        $masked = vk_mask_member_row([
            'first_name' => 'A', 'registration_number' => 'REG/2026/001', 'nida_number' => '123',
        ]);
        $this->assertNull($masked['registration_number']);
        $this->assertSame('A', $masked['first_name']); // non-sensitive kept
    }

    public function testWriterIsLeadershipGatedAndUnique(): void
    {
        $s = $this->src('actions/save_registration_number.php');
        $this->assertStringContainsString('require_auth.php', $s);
        $this->assertStringContainsString('require_csrf.php', $s);
        $this->assertStringContainsString("requirePermissionJson('edit', 'customers')", $s);
        // uniqueness via the shared helper (single source of truth)
        $this->assertStringContainsString('vk_registration_number_taken', $s);
    }

    public function testUniquenessHelperExcludesSelf(): void
    {
        // The shared helper carries the "except this member" uniqueness rule.
        $h = $this->src('helpers.php');
        $this->assertStringContainsString('function vk_registration_number_taken', $h);
        $this->assertStringContainsString('customer_id <> ?', $h);
        // all three writers use it
        $this->assertStringContainsString('vk_registration_number_taken', $this->src('actions/add_member.php'));
        $this->assertStringContainsString('vk_registration_number_taken', $this->src('app/constant/profile/profile.php'));
    }

    public function testEditFormHasFieldAndSaves(): void
    {
        $p = $this->src('app/constant/profile/profile.php');
        // the edit form carries the field, and the customers UPDATE writes it
        $this->assertStringContainsString('name="registration_number"', $p);
        $this->assertStringContainsString('registration_number = ?', $p);
    }

    public function testAdminCreateStoresRegistrationNumber(): void
    {
        $s = $this->src('actions/add_member.php');
        $this->assertStringContainsString('registration_number', $s);
        // uniqueness guard on admin create
        $this->assertStringContainsString('already used by another member', $s);
    }

    public function testPublicFormShowsAssignedLaterNote(): void
    {
        $s = $this->src('register.php');
        // an informational note, not an input the member fills
        $this->assertStringContainsString('Assigned by the administration', $s);
        $this->assertStringNotContainsString('name="registration_number"', $s);
    }

    public function testApprovalPromptsForNumber(): void
    {
        $s = $this->src('app/bms/customer/customers.php');
        $this->assertStringContainsString('promptRegThenActivate', $s);
        $this->assertStringContainsString('save_registration_number', $s);
    }

    public function testListingHasStandaloneRegColumn(): void
    {
        // The member listing shows the registration number as its own column
        // (desktop table header + mobile card row), not just a badge tucked in
        // the NIDA cell. Masking still applies — vk_mask_member_row nulls it, so
        // non-leadership viewers see '—'.
        $s = $this->src('app/bms/customer/customers.php');
        $this->assertStringContainsString('Reg. No.', $s);              // desktop + mobile label
        $this->assertStringContainsString('Namba ya Usajili', $s);      // swahili label
        // the print spacer spans every column, so the colspan must track the new count
        $this->assertStringContainsString('colspan="9"', $s);
    }
}
