<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Roles PR-2: a view-only Member sees only limited data about OTHER members.
 * Covers the masking decision (canSeeMemberSensitiveData), the data-layer blanking
 * (vk_mask_member_row), and that the member list/details apply it server-side.
 */
class MemberDataMaskingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    public static function setUpBeforeClass(): void
    {
        require_once self::ROOT . 'helpers.php';
        require_once self::ROOT . 'core/permissions.php';
    }

    protected function setUp(): void { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    // ---- who may see sensitive data ----

    public function testOwnRecordAlwaysVisible(): void
    {
        $this->assertTrue(canSeeMemberSensitiveData(true));
    }

    public function testAdminAndChairpersonSeeEverything(): void
    {
        $_SESSION['role_id'] = 1;  // Admin
        $this->assertTrue(canSeeMemberSensitiveData(false));
        $_SESSION = [];
        $_SESSION['role_id'] = 2;  // Chairperson
        $this->assertTrue(canSeeMemberSensitiveData(false));
    }

    public function testViewOnlyMemberCannotSeeOthers(): void
    {
        // No admin role and no edit permission loaded = ordinary member.
        $this->assertFalse(canSeeMemberSensitiveData(false));
    }

    // ---- data-layer masking ----

    public function testMaskBlanksSensitiveButKeepsBasics(): void
    {
        $row = [
            'customer_name' => 'Juma Hassan', 'first_name' => 'Juma', 'last_name' => 'Hassan',
            'user_status' => 'active', 'state' => 'Mwanza', 'district' => 'Ilemela',
            'phone' => '0712000000', 'nida_number' => '20010101', 'email' => 'j@x.com',
            'address' => '12 Main St', 'city' => 'Mwanza', 'initial_savings' => 50000,
            'father_name' => 'Hassan', 'guarantor_phone' => '0713', 'spouse_nida' => '99',
            'children_data' => '[{"name":"A"}]', 'next_of_kin_phone' => '0714',
        ];
        $masked = vk_mask_member_row($row);

        // Kept (limited, normal data)
        $this->assertSame('Juma Hassan', $masked['customer_name']);
        $this->assertSame('Mwanza', $masked['state']);
        $this->assertSame('Ilemela', $masked['district']);
        $this->assertSame('active', $masked['user_status']);

        // Blanked
        foreach (['phone', 'nida_number', 'email', 'address', 'city', 'initial_savings',
                  'father_name', 'guarantor_phone', 'spouse_nida', 'children_data', 'next_of_kin_phone'] as $k) {
            $this->assertNull($masked[$k], "$k must be masked");
        }
    }

    public function testMaskOnlyTouchesPresentKeys(): void
    {
        $row = ['first_name' => 'A', 'phone' => '07'];
        $masked = vk_mask_member_row($row);
        $this->assertSame('A', $masked['first_name']);
        $this->assertNull($masked['phone']);
        $this->assertArrayNotHasKey('email', $masked); // not invented
    }

    // ---- views apply it server-side ----

    public function testListAndDetailsApplyMasking(): void
    {
        $list = file_get_contents(self::ROOT . 'app/bms/customer/customers.php');
        $this->assertStringContainsString('canSeeMemberSensitiveData()', $list);
        $this->assertStringContainsString('vk_mask_member_row', $list);

        $details = file_get_contents(self::ROOT . 'app/bms/customer/customer_details.php');
        $this->assertStringContainsString('canSeeMemberSensitiveData(', $details);
        $this->assertStringContainsString('vk_mask_member_row', $details);
    }
}
