<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * "No dead buttons" wiring tests for the email pages.
 *
 * These are STATIC tests: they cannot click a button in a real browser
 * (that needs an E2E tool such as Playwright/Selenium, which this project
 * does not run). Instead they discover every interactive control in the
 * page and prove it is wired to something real — every onclick names a
 * function that is defined, every data-bs-target opens a modal that exists,
 * and every form has a submit handler. That catches the realistic
 * regression: a button calling a renamed/removed handler or opening a
 * missing modal. The API actions behind these buttons are exercised
 * end-to-end separately.
 */
class EmailButtonsWiringTest extends TestCase
{
    /** @return array<string,string> page-label => source */
    private function pages(): array
    {
        $dir = __DIR__ . '/../../app/constant/communication/';
        return [
            'email_center'    => file_get_contents($dir . 'email_center.php'),
            'email_templates' => file_get_contents($dir . 'email_templates.php'),
        ];
    }

    /**
     * Every onclick="fn(...)" must call a function that is defined in the page
     * (either `window.fn =` or `function fn`).
     */
    public function test_every_onclick_handler_is_defined(): void
    {
        foreach ($this->pages() as $label => $src) {
            preg_match_all('/onclick="([a-zA-Z_][\w]*)\s*\(/', $src, $m);
            $handlers = array_unique($m[1]);
            $this->assertNotEmpty($handlers, "$label should have onclick handlers");

            foreach ($handlers as $fn) {
                $defined = preg_match('/window\.' . preg_quote($fn, '/') . '\s*=/', $src)
                        || preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $src);
                $this->assertTrue((bool)$defined, "$label: onclick handler '$fn()' is not defined");
            }
        }
    }

    /**
     * Every data-bs-target="#id" must point at an element with that id.
     */
    public function test_every_modal_trigger_targets_an_existing_modal(): void
    {
        foreach ($this->pages() as $label => $src) {
            preg_match_all('/data-bs-target="#([\w-]+)"/', $src, $m);
            foreach (array_unique($m[1]) as $id) {
                $this->assertMatchesRegularExpression(
                    '/id="' . preg_quote($id, '/') . '"/',
                    $src,
                    "$label: data-bs-target #$id has no matching element id"
                );
            }
        }
    }

    /**
     * Every <form id="x"> must have a submit handler bound to it.
     */
    public function test_every_form_has_a_submit_handler(): void
    {
        foreach ($this->pages() as $label => $src) {
            preg_match_all('/<form[^>]*\sid="([\w-]+)"/', $src, $m);
            $this->assertNotEmpty($m[1], "$label should contain at least one form");
            foreach (array_unique($m[1]) as $formId) {
                $this->assertStringContainsString(
                    "'#$formId').on('submit'",
                    $src,
                    "$label: form #$formId has no submit handler"
                );
            }
        }
    }

    /**
     * Buttons wired by id (#btnRefresh etc.) must have a click handler.
     */
    public function test_id_bound_action_buttons_have_click_handlers(): void
    {
        $expected = [
            'email_center'    => ['btnRefresh'],
            'email_templates' => ['btnRefresh', 'sendTestBtn'],
        ];
        $pages = $this->pages();
        foreach ($expected as $label => $ids) {
            foreach ($ids as $id) {
                $this->assertMatchesRegularExpression(
                    "/id=\"$id\"/",
                    $pages[$label],
                    "$label: button #$id should exist"
                );
                $this->assertStringContainsString(
                    "'#$id').on('click'",
                    $pages[$label],
                    "$label: button #$id has no click handler"
                );
            }
        }
    }

    /**
     * Each row-action handler in the Email Center hits the email_center API
     * with the matching action, so a click actually does something server-side.
     */
    public function test_email_center_row_actions_call_their_api_actions(): void
    {
        $src = $this->pages()['email_center'];
        $this->assertStringContainsString("action:'get'", $src);          // emailView
        $this->assertStringContainsString("?action=resend", $src);        // emailResend
        $this->assertStringContainsString("?action=delete", $src);        // emailDelete
        $this->assertStringContainsString("?action=send", $src);          // compose submit
    }

    /**
     * Each row-action handler in Email Templates hits its endpoint.
     */
    public function test_email_templates_actions_call_their_endpoints(): void
    {
        $src = $this->pages()['email_templates'];
        $this->assertStringContainsString('SAVE_URL', $src);    // create/update
        $this->assertStringContainsString('DELETE_URL', $src);  // delete
        $this->assertStringContainsString('GET_URL', $src);     // list/reload
    }
}
