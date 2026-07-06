<?php
// app/constant/meetings/meetings_print.php — printable list of meetings held,
// honouring the same filters as the meetings page (status / type / date range).
ob_start();
require_once __DIR__ . '/../../../roots.php';
requireViewPermission('meetings');

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

// --- filters (mirror the meetings list) --------------------------------------
$validYmd = function ($d) {
    $d = trim((string) $d);
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($d !== '' && $dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$fStatus = in_array($_GET['status'] ?? '', ['scheduled', 'held', 'cancelled'], true) ? $_GET['status'] : '';
$fType   = in_array($_GET['type'] ?? '', ['regular', 'special', 'agm'], true) ? $_GET['type'] : '';
$fFrom   = $validYmd($_GET['date_from'] ?? '');
$fTo     = $validYmd($_GET['date_to'] ?? '');

$where = ['1=1'];
$params = [];
if ($fStatus !== '') { $where[] = 'm.status = ?';        $params[] = $fStatus; }
if ($fType !== '')   { $where[] = 'm.meeting_type = ?';  $params[] = $fType; }
if ($fFrom !== '')   { $where[] = 'm.meeting_date >= ?'; $params[] = $fFrom; }
if ($fTo !== '')     { $where[] = 'm.meeting_date <= ?'; $params[] = $fTo; }
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT m.id, m.title, m.meeting_type, m.meeting_date, m.meeting_time, m.location, m.status,
           (SELECT COUNT(*) FROM meeting_attendance a WHERE a.meeting_id = m.id AND a.status = 'present') AS present_count
      FROM meetings m
     WHERE $whereSql
     ORDER BY m.meeting_date DESC, m.id DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$held_count = 0;
foreach ($rows as $r) { if ($r['status'] === 'held') $held_count++; }

// Human-readable filter summary for the printout.
$scope = [];
if ($fStatus !== '') $scope[] = $t('Status', 'Hali') . ': ' . ucfirst($fStatus);
if ($fType !== '')   $scope[] = $t('Type', 'Aina') . ': ' . strtoupper($fType);
if ($fFrom !== '' || $fTo !== '') $scope[] = $t('Period', 'Kipindi') . ': ' . ($fFrom ?: '…') . ' → ' . ($fTo ?: '…');
$scopeText = $scope ? implode(' · ', $scope) : $t('All meetings', 'Mikutano yote');

$typeBadge   = ['agm' => 'danger', 'special' => 'warning', 'regular' => 'secondary'];
$statusBadge = ['held' => 'success', 'cancelled' => 'danger', 'scheduled' => 'info'];

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f8f9fa;min-height:90vh;">
    <?php PrintHeader::css(); ?>
    <div class="d-none d-print-block">
        <?php PrintHeader::render($pdo, $is_sw ? 'MIKUTANO' : 'MEETINGS REGISTER'); ?>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <a href="<?= getUrl('meetings') ?>" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="bi bi-arrow-left me-1"></i><?= $t('Back', 'Rudi') ?></a>
        <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="window.print()"><i class="bi bi-printer me-1"></i><?= $t('Print', 'Chapisha') ?></button>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="mb-3">
                <h5 class="fw-bold text-primary mb-1"><?= $t('Meetings Register', 'Rejista ya Mikutano') ?></h5>
                <div class="small text-muted">
                    <b><?= $t('Scope', 'Wigo') ?>:</b> <?= htmlspecialchars($scopeText) ?>
                    &nbsp;·&nbsp; <b><?= $t('Total', 'Jumla') ?>:</b> <?= count($rows) ?>
                    &nbsp;·&nbsp; <b><?= $t('Held', 'Yaliyofanyika') ?>:</b> <?= $held_count ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">#</th>
                            <th><?= $t('Title', 'Kichwa') ?></th>
                            <th><?= $t('Date', 'Tarehe') ?></th>
                            <th class="text-center"><?= $t('Type', 'Aina') ?></th>
                            <th class="text-center"><?= $t('Present', 'Waliohudhuria') ?></th>
                            <th class="text-center"><?= $t('Status', 'Hali') ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <?php if (!$rows): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4"><?= $t('No meetings found.', 'Hakuna mikutano.') ?></td></tr>
                        <?php else: $i = 1; foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <span class="fw-semibold"><?= safe_output($r['title']) ?></span>
                                    <?php if (!empty($r['location'])): ?><div class="text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i><?= safe_output($r['location']) ?></div><?php endif; ?>
                                </td>
                                <td><?= date('d M Y', strtotime($r['meeting_date'])) ?><?= $r['meeting_time'] ? ' · ' . date('h:i A', strtotime($r['meeting_time'])) : '' ?></td>
                                <td class="text-center"><span class="badge bg-<?= $typeBadge[$r['meeting_type']] ?? 'secondary' ?>-subtle text-<?= $typeBadge[$r['meeting_type']] ?? 'secondary' ?> border text-uppercase"><?= safe_output($r['meeting_type']) ?></span></td>
                                <td class="text-center"><?= (int) $r['present_count'] ?></td>
                                <td class="text-center"><span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>"><?= safe_output($r['status']) ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php includeFooter(); ob_end_flush(); ?>
