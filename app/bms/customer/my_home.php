<?php
// app/bms/customer/my_home.php — a member's personal home: their savings at a
// glance, this-year total, outstanding fines, next meeting, savings streak and a
// short contribution history. Self-scoped: shows only the logged-in member's own
// data. Ordinary members land here (see getLandingPage); leadership use the
// admin dashboard.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/require_login.php';
require_once __DIR__ . '/../../../includes/member_savings.php';
require_once __DIR__ . '/../../../helpers.php';

global $pdo;
$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$t = function ($en, $sw) use ($is_sw) { return $is_sw ? $sw : $en; };

$uid         = (int) ($_SESSION['user_id'] ?? 0);
$customer_id = vk_member_customer_id($pdo, $uid) ?? 0;

$first_name = '';
if ($customer_id > 0) {
    $nm = $pdo->prepare("SELECT first_name FROM customers WHERE customer_id = ?");
    $nm->execute([$customer_id]);
    $first_name = (string) $nm->fetchColumn();
}

// Figures — all from the shared helper so they match the member statement.
$savings      = vk_member_savings_total($pdo, $customer_id);
$this_year    = vk_member_savings_since($pdo, $customer_id, date('Y-01-01'));
$fines_due    = vk_member_outstanding_fines($pdo, $customer_id);
$monthly      = vk_member_monthly_savings($pdo, $customer_id, 12);   // ['YYYY-MM' => total]
$streak       = vk_contribution_streak(array_keys($monthly), date('Y-m'));
$saved_this_m = $monthly[date('Y-m')] ?? 0;

// Next scheduled meeting.
$mstmt = $pdo->prepare(
    "SELECT title, meeting_date, meeting_time, location
       FROM meetings
      WHERE status = 'scheduled' AND meeting_date >= CURDATE()
      ORDER BY meeting_date ASC, meeting_time ASC LIMIT 1"
);
$mstmt->execute();
$next_meeting = $mstmt->fetch(PDO::FETCH_ASSOC) ?: null;

$fmt = fn($n) => function_exists('format_currency') ? format_currency($n) : ('TSh ' . number_format($n, 2));

// Sparkline points (last up-to-12 months, chronological).
$spark = [];
$max   = max(1, $monthly ? max($monthly) : 1);
foreach ($monthly as $ym => $val) { $spark[] = ['ym' => $ym, 'val' => (float) $val, 'pct' => round(($val / $max) * 100)]; }

includeHeader();
?>

<div class="container-fluid py-4" id="main-content" style="background:#f6f8fb;min-height:90vh;">

    <div class="mb-4">
        <h3 class="fw-bold mb-1"><?= $t('Karibu', 'Karibu') ?><?= $first_name ? ', ' . htmlspecialchars($first_name) : '' ?> 👋</h3>
        <p class="text-muted mb-0"><?= $t('Here is your savings at a glance.', 'Hii ni muhtasari wa akiba yako.') ?></p>
    </div>

    <?php if ($customer_id <= 0): ?>
    <div class="alert alert-info"><?= $t('Your member profile is not set up yet. Please contact your group leadership.', 'Wasifu wako wa uanachama haujakamilika. Tafadhali wasiliana na uongozi wa kikundi.') ?></div>
    <?php else: ?>

    <!-- Headline cards -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:5px solid #1a7f4b !important;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold"><?= $t('Total savings', 'Jumla ya akiba') ?></div>
                    <div class="fs-4 fw-bold text-success"><?= $fmt($savings) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:5px solid #0f4c81 !important;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold"><?= $t('This year', 'Mwaka huu') ?></div>
                    <div class="fs-4 fw-bold text-primary"><?= $fmt($this_year) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:5px solid <?= $fines_due > 0 ? '#c0392b' : '#adb5bd' ?> !important;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold"><?= $t('Fines due', 'Faini') ?></div>
                    <div class="fs-4 fw-bold <?= $fines_due > 0 ? 'text-danger' : 'text-muted' ?>"><?= $fmt($fines_due) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100" style="border-left:5px solid #b8860b !important;">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold"><?= $t('Savings streak', 'Mfululizo wa akiba') ?></div>
                    <div class="fs-4 fw-bold" style="color:#b8860b;">
                        <?php if ($streak > 0): ?>
                            🔥 <?= $streak ?> <?= $streak === 1 ? $t('month', 'mwezi') : $t('months', 'miezi') ?>
                        <?php else: ?>
                            <span class="fs-6 text-muted"><?= $t('Start this month!', 'Anza mwezi huu!') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted">
                        <?= $saved_this_m > 0
                            ? $t('You have saved this month ✓', 'Umeweka akiba mwezi huu ✓')
                            : $t('Not yet saved this month', 'Bado hujaweka akiba mwezi huu') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Contribution history -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100"><div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-graph-up-arrow me-2"></i><?= $t('Your savings over the last 12 months', 'Akiba yako kwa miezi 12 iliyopita') ?></h6>
                <?php if ($spark): ?>
                <div class="d-flex align-items-end gap-2">
                    <?php foreach ($spark as $p): ?>
                    <div class="flex-fill text-center" title="<?= htmlspecialchars($p['ym'] . ' — ' . $fmt($p['val'])) ?>">
                        <!-- Fixed-height track so the bar's percentage height resolves
                             (a % height needs a parent with an explicit height). -->
                        <div class="d-flex align-items-end justify-content-center mx-auto" style="height:130px;">
                            <div class="rounded-top" style="width:70%;background:#1769aa;height:<?= max(4, (int) $p['pct']) ?>%;"></div>
                        </div>
                        <div class="text-muted mt-1" style="font-size:10px;"><?= htmlspecialchars(date('M', strtotime($p['ym'] . '-01'))) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0"><?= $t('No savings recorded yet. Your contributions will appear here.', 'Hakuna akiba iliyorekodiwa bado. Michango yako itaonekana hapa.') ?></p>
                <?php endif; ?>
            </div></div>
        </div>

        <!-- Next meeting + quick links -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-calendar-event me-2"></i><?= $t('Next meeting', 'Mkutano ujao') ?></h6>
                <?php if ($next_meeting): ?>
                    <div class="fw-bold"><?= htmlspecialchars($next_meeting['title']) ?></div>
                    <div class="text-muted small">
                        <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars(date('D, d M Y', strtotime($next_meeting['meeting_date']))) ?>
                        <?php if (!empty($next_meeting['meeting_time'])): ?> · <?= htmlspecialchars(date('H:i', strtotime($next_meeting['meeting_time']))) ?><?php endif; ?>
                    </div>
                    <?php if (!empty($next_meeting['location'])): ?>
                    <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($next_meeting['location']) ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-muted small"><?= $t('No upcoming meeting scheduled.', 'Hakuna mkutano uliopangwa.') ?></div>
                <?php endif; ?>
            </div></div>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <h6 class="fw-bold mb-2"><i class="bi bi-lightning-charge me-2"></i><?= $t('Quick links', 'Viungo vya haraka') ?></h6>
                <div class="d-grid gap-2">
                    <a href="<?= getUrl('member_statement') ?>" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-file-text me-2"></i><?= $t('My full statement', 'Taarifa yangu kamili') ?></a>
                    <a href="<?= getUrl('my_fines') ?>" class="btn btn-outline-secondary btn-sm text-start"><i class="bi bi-cash-coin me-2"></i><?= $t('My fines', 'Faini zangu') ?></a>
                </div>
            </div></div>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
