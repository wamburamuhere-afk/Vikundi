<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Public registration form (register.php) split into the same 6-step wizard as
 * the admin form (PR-2), with the children card repeater and the
 * children-hidden-when-single behaviour. Pure UI — no field/handler change.
 */
class RegisterStepperTest extends TestCase
{
    private const FILE = __DIR__ . '/../../register.php';

    private string $src;

    protected function setUp(): void
    {
        $this->src = file_get_contents(self::FILE);
    }

    public function testSixStepPanesExist(): void
    {
        foreach (['personal', 'residence', 'parents', 'family', 'guarantor', 'account'] as $id) {
            $this->assertSame(1, preg_match_all('/id="' . $id . '" role="tabpanel"/', $this->src),
                "exactly one step pane #$id");
        }
    }

    public function testStepperPresent(): void
    {
        $this->assertStringContainsString('id="registerSteps"', $this->src);
        $this->assertStringContainsString("['personal',", $this->src);
        $this->assertStringContainsString("['guarantor',", $this->src);
    }

    public function testNoFieldDropped(): void
    {
        foreach (['country', 'house_number', 'passport_photo',
                  'father_first_name', 'mother_house_number', 'spouse_first_name', 'spouse_photo',
                  'child_name', 'child_dob', 'child_photo',
                  'guarantor_country', 'guarantor_house_number'] as $name) {
            $this->assertStringContainsString('name="' . $name, $this->src, "$name must still be collected");
        }
    }

    public function testChildrenAreCardsAndHideWhenSingle(): void
    {
        $this->assertStringContainsString('child-card', $this->src);
        $this->assertStringNotContainsString('childrenTable', $this->src, 'old table removed');
        $this->assertStringContainsString('id="childrenSection"', $this->src);
        $this->assertStringContainsString("['familyFields', 'childrenSection']", $this->src,
            'the marital toggle must hide both spouse and children');
        $this->assertStringContainsString('id="familyNote"', $this->src);
    }

    public function testFormDivsBalanced(): void
    {
        $start = strpos($this->src, '<form ');
        $seg = substr($this->src, $start, strpos($this->src, '</form>', $start) - $start);
        $this->assertSame(substr_count($seg, '<div'), substr_count($seg, '</div>'),
            'register form must have balanced <div> tags after the split');
    }
}
