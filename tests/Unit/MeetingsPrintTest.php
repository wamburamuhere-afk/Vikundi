<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Meetings print: a printable meetings register and a single-meeting record.
 * Pure test covers the print attachments renderer (images inline, other files
 * listed); source-guards pin the two print pages (routed, permission-gated,
 * branded header + shared footer) and the Print buttons that open them.
 */
class MeetingsPrintTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../includes/expense_attachments.php';
    }

    private function src(string $relPath): string
    {
        return file_get_contents(__DIR__ . '/../../' . $relPath);
    }

    // --- pure: print attachments renderer -----------------------------------

    public function testEmptyAttachmentsRenderNothing(): void
    {
        $this->assertSame('', vk_render_attachments_print([], false));
    }

    public function testImagesInlineOtherFilesListed(): void
    {
        $docs = [
            ['id' => 1, 'document_name' => 'Attendance Photo', 'original_filename' => 'att.jpg', 'file_type' => 'jpg', 'file_size' => 204800],
            ['id' => 2, 'document_name' => 'Signed Minutes',   'original_filename' => 'min.pdf', 'file_type' => 'pdf', 'file_size' => 512000],
        ];
        $html = vk_render_attachments_print($docs, false);

        // The image is embedded inline via the gated download route.
        $this->assertStringContainsString('<img src="', $html);
        $this->assertStringContainsString('action=download', $html);
        $this->assertStringContainsString('document_id=1', $html);

        // The PDF is listed (name + type), NOT rendered as an image.
        $this->assertStringContainsString('Signed Minutes', $html);
        $this->assertStringContainsString('PDF', $html);
        $this->assertStringNotContainsString('document_id=2" ', $html); // no <img ...document_id=2">
        $this->assertSame(1, substr_count($html, '<img '));             // exactly the one image
    }

    // --- wiring (source guards) ---------------------------------------------

    public function testPrintPagesRoutedAndGated(): void
    {
        $roots = $this->src('roots.php');
        $this->assertStringContainsString("'meetings_print'", $roots);
        $this->assertStringContainsString("'meeting_print'", $roots);

        $list = $this->src('app/constant/meetings/meetings_print.php');
        $this->assertStringContainsString("requireViewPermission('meetings')", $list);
        $this->assertStringContainsString('PrintHeader::render', $list);
        $this->assertStringContainsString('PRINT_FOOTER_FILE', $list);

        $one = $this->src('app/constant/meetings/meeting_print.php');
        $this->assertStringContainsString("requireViewPermission('meetings')", $one);
        $this->assertStringContainsString('vk_render_attachments_print', $one);
        $this->assertStringContainsString('meeting_attendance', $one); // attendance rendered
    }

    public function testPrintButtonsWired(): void
    {
        $list = $this->src('app/constant/meetings/meetings.php');
        $this->assertStringContainsString('printMeetingsList', $list);
        $this->assertStringContainsString("getUrl('meetings_print')", $list);

        $view = $this->src('app/constant/meetings/meeting_view.php');
        $this->assertStringContainsString("getUrl('meeting_print')", $view);
    }
}
