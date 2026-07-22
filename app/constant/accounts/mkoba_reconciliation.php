<?php
/**
 * M-Koba Reconciliation
 * ---------------------
 * Holds the imported M-Koba statement (every row, as received) next to Vikundi's
 * books and ties it out: which rows became member savings, which were correctly
 * excluded (transfers / account-openings / blank), and any contribution row that
 * did not land in the ledger (a discrepancy). The statement total, the savings
 * total and the excluded total add up — so a leader can match Vikundi against the
 * M-Koba statement line by line.
 *
 * Data source: `mkoba_statement_rows` (the statement mirror, built at import time
 * or via `php database/import_mkoba_oneoff.php <file> --mirror-only`).
 */
ob_start();
global $pdo;

require_once __DIR__ . '/../../../roots.php';
includeHeader();
requireViewPermission('mkoba_reconciliation');

$lang  = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');
$t = fn($en, $sw) => $is_sw ? $sw : $en;

// ── Batches (one per imported statement) ──
$batches = $pdo->query("SELECT batch, COUNT(*) c FROM mkoba_statement_rows GROUP BY batch ORDER BY MAX(id) DESC")->fetchAll(PDO::FETCH_ASSOC);
$selected = $_GET['batch'] ?? ($batches[0]['batch'] ?? '');

// ── Rows for the selected statement ──
$rows = [];
$sum = ['all_n' => 0, 'all_amt' => 0.0, 'imported_n' => 0, 'imported_amt' => 0.0,
        'excluded_n' => 0, 'excluded_amt' => 0.0, 'missing_n' => 0, 'missing_amt' => 0.0];
if ($selected !== '') {
    $st = $pdo->prepare("SELECT * FROM mkoba_statement_rows WHERE batch = ? ORDER BY (sno+0), id");
    $st->execute([$selected]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $amt = (float) $r['amount'];
        $sum['all_n']++; $sum['all_amt'] += $amt;
        $sum[$r['outcome'] . '_n']++; $sum[$r['outcome'] . '_amt'] += $amt;
    }
}
// Books cross-check: total actually sitting in `contributions` for this statement.
$ledger_amt = 0.0;
if ($selected !== '') {
    $lc = $pdo->prepare("SELECT COALESCE(SUM(c.amount),0) FROM contributions c
                         JOIN mkoba_statement_rows m ON m.contribution_id = c.contribution_id
                         WHERE m.batch = ?");
    $lc->execute([$selected]);
    $ledger_amt = (float) $lc->fetchColumn();
}
$reconciled = ($sum['missing_n'] === 0) && (round($ledger_amt, 2) === round($sum['imported_amt'], 2));
$fmt = fn($n) => 'TSh ' . number_format((float) $n, 2);

$badge = function (string $outcome) use ($t): array {
    return match ($outcome) {
        'imported' => ['success', $t('Savings', 'Akiba')],
        'missing'  => ['danger',  $t('Missing', 'Haipo')],
        default    => ['secondary', $t('Excluded', 'Haihesabiki')],
    };
};
?>

<div class="container-fluid py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h3 class="fw-bold mb-1"><i class="bi bi-clipboard-check text-primary me-2"></i><?= $t('M-Koba Reconciliation', 'Ulinganishaji wa M-Koba') ?></h3>
      <p class="text-muted mb-0 small"><?= $t('The M-Koba statement, mirrored and tied out against the ledger.', 'Taarifa ya M-Koba, imeoneshwa na kulinganishwa na leja.') ?></p>
    </div>
    <?php if ($batches): ?>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="small fw-bold text-muted mb-0"><?= $t('Statement', 'Taarifa') ?></label>
      <select name="batch" class="form-select form-select-sm" style="min-width:260px" onchange="this.form.submit()">
        <?php foreach ($batches as $b): ?>
          <option value="<?= htmlspecialchars($b['batch']) ?>" <?= $b['batch'] === $selected ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['batch']) ?> (<?= (int) $b['c'] ?> <?= $t('rows', 'safu') ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <?php if (!$batches): ?>
    <div class="alert alert-info border-0 shadow-sm">
      <i class="bi bi-info-circle me-2"></i>
      <?= $t('No M-Koba statement has been imported yet.', 'Hakuna taarifa ya M-Koba iliyoingizwa bado.') ?>
      <span class="text-muted small d-block mt-1">
        <?= $t('Import one, or build the mirror from an existing import with', 'Ingiza moja, au jenga kioo kutoka uingizaji uliopo kwa') ?>
        <code>php database/import_mkoba_oneoff.php "&lt;file&gt;.csv" --mirror-only</code>.
      </span>
    </div>
  <?php else: ?>

  <!-- Tie-out banner -->
  <div class="alert <?= $reconciled ? 'alert-success' : 'alert-warning' ?> border-0 shadow-sm d-flex align-items-center">
    <i class="bi <?= $reconciled ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-4 me-3"></i>
    <div>
      <?php if ($reconciled): ?>
        <strong><?= $t('Reconciled.', 'Imelinganishwa.') ?></strong>
        <?= $t('Every row on the statement is accounted for, and the savings in the ledger match the statement exactly.',
               'Kila safu ya taarifa imehesabiwa, na akiba katika leja inalingana na taarifa.') ?>
      <?php else: ?>
        <strong><?= $t('Attention.', 'Angalizo.') ?></strong>
        <?= $sum['missing_n'] ?> <?= $t('statement row(s) did not reach the ledger — see the red “Missing” rows below.',
               'safu za taarifa hazikufika leja — angalia safu nyekundu za “Haipo” hapa chini.') ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary tie-out -->
  <div class="row g-3 mb-3">
    <?php
    $cards = [
      ['primary',   $t('Statement total', 'Jumla ya taarifa'), $sum['all_n'],      $sum['all_amt']],
      ['success',   $t('Savings (imported)', 'Akiba (imeingizwa)'), $sum['imported_n'], $sum['imported_amt']],
      ['secondary', $t('Excluded (transfers/openings)', 'Haihesabiki'), $sum['excluded_n'], $sum['excluded_amt']],
      ['danger',    $t('Missing (discrepancy)', 'Haipo (tofauti)'), $sum['missing_n'],  $sum['missing_amt']],
    ];
    foreach ($cards as [$col, $label, $n, $amt]): ?>
      <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-<?= $col ?>">
          <div class="card-body py-3">
            <div class="text-muted small text-uppercase fw-bold" style="font-size:11px"><?= $label ?></div>
            <div class="fs-4 fw-bold text-<?= $col ?>"><?= number_format($n) ?> <span class="fs-6 text-muted fw-normal"><?= $t('rows', 'safu') ?></span></div>
            <div class="small fw-semibold"><?= $fmt($amt) ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="text-muted small mb-3">
    <?= $t('Tie-out', 'Ulinganishaji') ?>:
    <strong><?= $fmt($sum['all_amt']) ?></strong> <?= $t('on the statement', 'kwenye taarifa') ?> =
    <strong class="text-success"><?= $fmt($sum['imported_amt']) ?></strong> <?= $t('savings', 'akiba') ?>
    + <strong class="text-secondary"><?= $fmt($sum['excluded_amt']) ?></strong> <?= $t('excluded', 'haihesabiki') ?>
    <?php if ($sum['missing_amt'] > 0): ?> + <strong class="text-danger"><?= $fmt($sum['missing_amt']) ?></strong> <?= $t('missing', 'haipo') ?><?php endif; ?>.
    <?= $t('In the ledger', 'Katika leja') ?>: <strong><?= $fmt($ledger_amt) ?></strong>.
  </p>

  <!-- Statement mirror -->
  <div class="card border-0 shadow-sm">
    <div class="card-body p-2 p-md-3">
      <div class="table-responsive">
        <table id="reconTable" class="table table-hover table-sm align-middle mb-0" style="width:100%">
          <thead>
            <tr class="small text-uppercase text-muted">
              <th>#</th>
              <th><?= $t('Receipt', 'Risiti') ?></th>
              <th><?= $t('Date', 'Tarehe') ?></th>
              <th><?= $t('Member', 'Mwanachama') ?></th>
              <th><?= $t('Member ID', 'Namba') ?></th>
              <th class="text-end"><?= $t('Amount', 'Kiasi') ?></th>
              <th><?= $t('Trans Type', 'Aina') ?></th>
              <th><?= $t('In Vikundi', 'Vikundi') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): [$bcol, $blab] = $badge($r['outcome']); ?>
              <tr>
                <td class="text-muted"><?= htmlspecialchars((string) $r['sno']) ?></td>
                <td class="fw-semibold small"><?= safe_output($r['receipt'], '—') ?></td>
                <td class="small text-nowrap"><?= $r['trans_date'] ? date('d/m/Y', strtotime($r['trans_date'])) : '—' ?></td>
                <td><?= safe_output($r['member_name'], '—') ?></td>
                <td class="small text-muted"><?= safe_output($r['member_id'], '—') ?></td>
                <td class="text-end fw-semibold text-nowrap">TSh <?= number_format((float) $r['amount'], 2) ?></td>
                <td class="small"><?= safe_output($r['trans_type'], '—') ?></td>
                <td>
                  <span class="badge rounded-pill bg-<?= $bcol ?> bg-opacity-10 text-<?= $bcol ?>" <?= $r['reason'] ? 'title="' . htmlspecialchars($r['reason']) . '"' : '' ?>><?= $blab ?></span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <script>
    $(function () {
      $('#reconTable').DataTable({
        order: [[0, 'asc']],
        pageLength: 25,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, '<?= $t('All', 'Zote') ?>']],
        language: { search: '<?= $t('Search', 'Tafuta') ?>:' }
      });
    });
  </script>

  <?php endif; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
