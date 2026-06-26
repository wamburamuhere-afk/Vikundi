<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Recurrence guard (audit H6): the sensitive state-changing endpoints must keep
 * requiring the central CSRF guard, and header.php must keep emitting the token
 * + the fetch/jQuery wrappers that deliver it. Fails loudly if a future edit
 * drops protection from any of them.
 */
class CsrfCoverageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../';

    /** Endpoints wired to includes/require_csrf.php in this branch. */
    private const GUARDED_ENDPOINTS = [
        'actions/update_contribution.php',
        'actions/delete_death_expense.php',
        'actions/delete_petty_cash.php',
        'actions/process_death_expense.php',
        'actions/process_contribution.php',
        'actions/save_petty_cash.php',
        'actions/approve_death_expense.php',
        'actions/approve_petty_cash.php',
        'actions/update_user_role.php',
        'actions/update_user_status.php',
        'actions/approve_member.php',
        'api/account/save_account.php',
        'api/account/save_category.php',
        'api/account/delete_account.php',
        'api/account/delete_account_category.php',
        'api/account/create_reconciliation.php',
        'api/account/delete_reconciliation.php',
    ];

    public function testCentralGuardFileExists(): void
    {
        $this->assertFileExists(self::ROOT . 'includes/require_csrf.php');
    }

    public function testEveryGuardedEndpointRequiresCsrf(): void
    {
        foreach (self::GUARDED_ENDPOINTS as $relPath) {
            $full = self::ROOT . $relPath;
            $this->assertFileExists($full, "$relPath should exist");
            $this->assertStringContainsString(
                'require_csrf.php',
                (string) file_get_contents($full),
                "$relPath must require the central CSRF guard (audit H6)"
            );
        }
    }

    public function testNativeContributionFormCarriesCsrfField(): void
    {
        $html = (string) file_get_contents(self::ROOT . 'app/bms/customer/submit_contribution.php');
        $this->assertStringContainsString('csrf_field()', $html,
            'Native contribution form must emit a CSRF hidden field');
    }

    public function testHeaderEmitsTokenAndDeliveryHooks(): void
    {
        $header = (string) file_get_contents(self::ROOT . 'header.php');
        $this->assertStringContainsString('name="csrf-token"', $header, 'header.php must emit the CSRF meta token');
        $this->assertStringContainsString('window.fetch', $header, 'header.php must install the fetch() wrapper');
        $this->assertStringContainsString('ajaxSend', $header, 'header.php must install the jQuery ajaxSend hook');
    }
}
