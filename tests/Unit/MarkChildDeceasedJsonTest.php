<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for markChildDeceasedJson() (helpers.php).
 *
 * Guards the fix for: approving a child's death expense used to DELETE the child
 * from children_data (member's profile then omitted them). It must now mark the
 * child deceased while retaining the entry and its sibling indexes.
 */
class MarkChildDeceasedJsonTest extends TestCase
{
    private string $json;

    protected function setUp(): void
    {
        $this->json = json_encode([
            ['name' => 'Asha', 'age' => '9', 'gender' => 'Female'],
            ['name' => 'Said', 'age' => '6', 'gender' => 'Male'],
        ]);
    }

    public function testMarksTargetChildDeceasedAndKeepsSiblings(): void
    {
        $data = json_decode(markChildDeceasedJson($this->json, 1, '2026-06-24'), true);

        $this->assertCount(2, $data, 'Child must be retained, not removed');
        $this->assertSame(1, $data[1]['is_deceased']);
        $this->assertSame('2026-06-24', $data[1]['deceased_date']);
        $this->assertArrayNotHasKey('is_deceased', $data[0], 'Sibling must be untouched');
        $this->assertSame('Said', $data[1]['name'], 'Index must be preserved (no reindex)');
    }

    public function testMissingIndexReturnsOriginalUnchanged(): void
    {
        $this->assertSame($this->json, markChildDeceasedJson($this->json, 9, '2026-06-24'));
    }

    public function testNullAndEmptyAreReturnedUnchanged(): void
    {
        $this->assertNull(markChildDeceasedJson(null, 0, '2026-06-24'));
        $this->assertSame('', markChildDeceasedJson('', 0, '2026-06-24'));
    }

    public function testInvalidJsonIsReturnedUnchanged(): void
    {
        $this->assertSame('not json', markChildDeceasedJson('not json', 0, '2026-06-24'));
    }
}
