<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Admin add-member modal split into a 6-step wizard (PR-1). Guards the new step
 * panes, that no field was dropped in the restructure, that the divs stay
 * balanced, and that the spouse stays conditional while children moved out to be
 * always shown.
 */
class AdminRegistrationStepperTest extends TestCase
{
    private const FILE = __DIR__ . '/../../app/bms/customer/customers.php';

    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(self::FILE);
    }

    public function testSixStepPanesExist(): void
    {
        foreach (['personal', 'residence', 'parents', 'family', 'guarantor', 'account'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '" role="tabpanel"/', $this->src,
                "step pane #$id must exist");
        }
    }

    public function testStepperDeclaresSixSteps(): void
    {
        // The nav is generated from a $__steps array of 6 entries.
        $this->assertStringContainsString("['personal',", $this->src);
        $this->assertStringContainsString("['account',", $this->src);
    }

    public function testNoFieldDroppedInRestructure(): void
    {
        // Representative fields from every section must survive the move.
        foreach (['country', 'house_number', 'passport_photo',
                  'father_first_name', 'father_house_number', 'mother_first_name',
                  'spouse_first_name', 'spouse_nida',
                  'guarantor_country', 'guarantor_house_number', 'guarantor_member_id'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $this->src, "$name must still be collected");
        }
    }

    public function testSpouseStaysConditionalChildrenAlwaysShown(): void
    {
        // familyFieldsAdmin (spouse) is opened once and closed once...
        $this->assertSame(1, substr_count($this->src, 'id="familyFieldsAdmin"'), 'one spouse wrapper');
        $this->assertSame(1, substr_count($this->src, 'close familyFieldsAdmin'), 'closed once (before children)');
        // ...and the children table input remains present (now outside the wrapper).
        $this->assertStringContainsString('id="childrenTableAdmin"', $this->src);
    }

    public function testFormDivsBalanced(): void
    {
        $start = strpos($this->src, '<form id="addMemberForm"');
        $seg = substr($this->src, $start, strpos($this->src, '</form>', $start) - $start);
        $this->assertSame(substr_count($seg, '<div'), substr_count($seg, '</div>'),
            'the add-member form must have balanced <div> tags after the split');
    }
}
