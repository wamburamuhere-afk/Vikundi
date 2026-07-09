<?php
// app/constant/meetings/meeting_print.php — printable record of a single meeting:
// details, agenda/minutes, attendance (present/absent) and attached documents
// (images inline, other file types listed).
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('meetings');

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$id = intval($_GET['id'] ?? 0);
if (!$id) { redirectTo('meetings'); }

global $pdo;

$stmt = $pdo->prepare("SELECT m.*, TRIM(CONCAT_WS(' ', u.first_name, u.last_name)) AS creator_name
                       FROM meetings m LEFT JOIN users u ON m.created_by = u.user_id WHERE m.id = ?");
$stmt->execute([$id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) { redirectTo('meetings'); }

// Roster + attendance for this meeting.
$members = $pdo->prepare("
    SELECT c.customer_id,
           TRIM(CONCAT_WS(' ', c.first_name, c.middle_name, c.last_name)) AS name,
           COALESCE(a.status, 'absent') AS att_status
      FROM customers c
      LEFT JOIN meeting_attendance a ON a.member_id = c.customer_id AND a.meeting_id = :mid
     WHERE (c.status IS NULL OR c.status <> 'deleted') AND COALESCE(c.is_deceased, 0) = 0
     ORDER BY c.first_name, c.last_name
");
$members->execute(['mid' => $id]);
$roster = $members->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../includes/meeting_helpers.php';
$att = vk_attendance_summary(array_map(fn($r) => ['status' => $r['att_status']], $roster));

// Attached documents (same gated component as the meeting view).
require_once __DIR__ . '/../../../includes/expense_attachments.php';
$__docs = vk_fetch_expense_attachments($pdo, 'meeting', $id);

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'KUMBUKUMBU YA MKUTANO' : 'MEETING RECORD'); ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('meeting_view') ?>?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">

            <!-- Meeting details -->
            <h5 class="fw-bold text-primary mb-1"><?= safe_output($m['title']) ?>
                <span class="badge bg-secondary text-uppercase align-middle" style="font-size:.6em;"><?= safe_output($m['meeting_type']) ?></span>
            </h5>
            <table class="table table-sm table-borderless mb-3" style="max-width:640px;">
                <tr><td class="text-muted fw-semibold" style="width:35%"><?= $t('Date', 'Tarehe') ?></td><td><?= date('d M Y', strtotime($m['meeting_date'])) ?><?= $m['meeting_time'] ? ' · ' . date('h:i A', strtotime($m['meeting_time'])) : '' ?></td></tr>
                <tr><td class="text-muted fw-semibold"><?= $t('Location', 'Mahali') ?></td><td><?= $m['location'] ? safe_output($m['location']) : '—' ?></td></tr>
                <tr><td class="text-muted fw-semibold"><?= $t('Status', 'Hali') ?></td><td><?= ucfirst($m['status']) ?></td></tr>
                <tr><td class="text-muted fw-semibold"><?= $t('Recorded by', 'Imeandikwa na') ?></td><td><?= safe_output($m['creator_name'] ?: '—') ?></td></tr>
            </table>

            <?php if (!empty($m['agenda'])): ?>
                <h6 class="fw-bold small text-uppercase text-muted mb-1"><?= $t('Agenda', 'Ajenda') ?></h6>
                <div class="border rounded p-2 bg-light small mb-3" style="white-space:pre-wrap;"><?= safe_output($m['agenda']) ?></div>
            <?php endif; ?>
            <?php if (!empty($m['minutes'])): ?>
                <h6 class="fw-bold small text-uppercase text-muted mb-1"><?= $t('Minutes', 'Muhtasari') ?></h6>
                <div class="border rounded p-2 bg-light small mb-3" style="white-space:pre-wrap;"><?= safe_output($m['minutes']) ?></div>
            <?php endif; ?>

            <!-- Attendance -->
            <h6 class="fw-bold small text-uppercase text-muted mb-2 mt-3 border-top pt-3">
                <?= $t('Attendance', 'Mahudhurio') ?>
                <span class="fw-normal">— <?= $t('Present', 'Waliohudhuria') ?>: <b><?= $att['present'] ?></b> / <?= $att['total'] ?>
                &nbsp;·&nbsp; <?= $t('Absent', 'Waliokosa') ?>: <b><?= $att['absent'] ?></b></span>
            </h6>
            <?php
            // Compact 2-column register: members flow down the left column, then
            // continue down the right — half the height of one-row-per-member.
            $att_cell = function (?array $r, int $idx) use ($t): string {
                if ($r === null) return '<td></td><td></td><td></td>';
                $present = $r['att_status'] === 'present';
                $name = safe_output($r['name'] !== '' ? $r['name'] : ('Member #' . (int) $r['customer_id']));
                $badge = '<span class="badge bg-' . ($present ? 'success' : 'secondary') . '">'
                       . ($present ? $t('Present', 'Yupo') : $t('Absent', 'Hayupo')) . '</span>';
                return '<td class="text-muted">' . $idx . '</td><td>' . $name . '</td><td class="text-center">' . $badge . '</td>';
            };
            $n = count($roster);
            $half = (int) ceil($n / 2);
            ?>
            <table class="table table-bordered table-sm align-middle">
                <thead class="table-light text-center small">
                    <tr>
                        <th style="width:34px">#</th><th class="text-start"><?= $t('Member', 'Mwanachama') ?></th><th style="width:78px"><?= $t('Att.', 'Mahud.') ?></th>
                        <th style="width:34px">#</th><th class="text-start"><?= $t('Member', 'Mwanachama') ?></th><th style="width:78px"><?= $t('Att.', 'Mahud.') ?></th>
                    </tr>
                </thead>
                <tbody class="small">
                    <?php if (!$roster): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3"><?= $t('No members yet.', 'Hakuna wanachama bado.') ?></td></tr>
                    <?php else: for ($i = 0; $i < $half; $i++):
                        $left  = $roster[$i] ?? null;
                        $right = $roster[$i + $half] ?? null;
                    ?>
                        <tr>
                            <?= $att_cell($left, $i + 1) ?>
                            <?= $att_cell($right, $right !== null ? $i + $half + 1 : 0) ?>
                        </tr>
                    <?php endfor; endif; ?>
                </tbody>
            </table>

            <!-- Attached documents: images inline, other files listed -->
            <?php echo vk_render_attachments_print($__docs, $is_sw); ?>
        </div>
    </div>
</div>

<style>
    .vk-print-docs { margin-top: 14px; }
    .vk-docs-title { font-weight: 700; text-transform: uppercase; font-size: 12px; color: #34435a; border-bottom: 1px solid #dde3ea; padding-bottom: 4px; margin-bottom: 8px; }
    .vk-doc-img { margin: 0 0 12px; text-align: center; page-break-inside: avoid; }
    /* Show the whole image large enough to read (e.g. a scanned attendance sheet),
       capped so one image never overflows a printed page. */
    .vk-doc-img img { max-width: 100%; max-height: 165mm; object-fit: contain; border: 1px solid #ccd3dc; border-radius: 4px; }
    .vk-doc-img figcaption { font-size: 10px; color: #5b6776; margin-top: 3px; }
    .vk-doc-list { margin-top: 6px; }
    .vk-doc-list-h { font-weight: 700; font-size: 11px; text-transform: uppercase; color: #5b6776; margin-bottom: 3px; }
    .vk-doc-list ul { margin: 0; padding-left: 18px; }
    .vk-doc-list li { font-size: 12px; margin: 2px 0; }
    .vk-doc-meta { color: #98a2b0; font-size: 10px; }
</style>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
