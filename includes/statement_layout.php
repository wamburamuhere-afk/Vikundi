<?php
/**
 * includes/statement_layout.php — the shared skeleton for the four statements.
 *
 *   Member Statement of Contributions   Group Statement of Contributions
 *   Member Statement of Transactions    Group Statement of Transactions
 *
 * The layout follows the NSSF member statement the group handed us as the model,
 * because every Tanzanian who has ever drawn a pension recognises it on sight:
 * organisation header, a two-column details panel, a full-width benefits bar, then
 * a year-by-month breakdown grid. Familiar beats clever for a document members
 * will be handed across a table.
 *
 * Four pages need that skeleton, so it lives here once. The rendering functions
 * take plain arrays and echo — no database access, no globals — so a page decides
 * WHAT to show and this file decides how it looks.
 */

if (!function_exists('stmt_group')) {
    /**
     * The group's identity for the statement header: name, logo, registration.
     * Missing values return empty rather than a placeholder — a statement with a
     * blank registration line is honest; one showing "Reg/2026/001" is a lie.
     */
    function stmt_group(PDO $pdo): array
    {
        $s = $pdo->query("SELECT setting_key, setting_value FROM group_settings")
                 ->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'name'         => $s['group_name'] ?? '',
            'logo'         => $s['group_logo'] ?? ($s['logo'] ?? ''),
            'registration' => $s['registration_number'] ?? '',
            'org_type'     => $s['organization_type'] ?? '',
            'phone'        => $s['group_phone'] ?? '',
            'address'      => $s['physical_address'] ?? '',
        ];
    }
}

if (!function_exists('stmt_month_labels')) {
    /** Abbreviated month names for the twelve grid columns. */
    function stmt_month_labels(bool $isSw): array
    {
        return $isSw
            ? ['JAN','FEB','MAC','APR','MEI','JUN','JUL','AGO','SEP','OKT','NOV','DES']
            : ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    }
}

if (!function_exists('stmt_as_of_label')) {
    /** "AUG 2026" — the period the whole document is true as of. */
    function stmt_as_of_label(DateTimeInterface $asOf, bool $isSw): string
    {
        $labels = stmt_month_labels($isSw);
        return $labels[(int) $asOf->format('n') - 1] . ' ' . $asOf->format('Y');
    }
}

if (!function_exists('stmt_head')) {
    /**
     * The organisation block and the document title. `$title` is the wording the
     * group asked for verbatim — "Member Statement of Contributions as of ..." —
     * so it is passed in rather than assembled here.
     */
    function stmt_head(array $group, string $title, string $asOfLabel): void
    {
        $logo = trim((string) $group['logo']);
        ?>
        <div class="vk-stmt-head text-center">
            <?php if ($logo !== ''): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="" class="vk-stmt-logo">
            <?php endif; ?>
            <div class="vk-stmt-org"><?= htmlspecialchars(strtoupper($group['name'])) ?></div>
            <?php if (trim((string) $group['registration']) !== ''): ?>
                <div class="vk-stmt-sub"><?= htmlspecialchars($group['registration']) ?></div>
            <?php endif; ?>
            <?php if (trim((string) $group['address']) !== ''): ?>
                <div class="vk-stmt-sub"><?= htmlspecialchars($group['address']) ?></div>
            <?php endif; ?>
            <div class="vk-stmt-title"><?= htmlspecialchars(strtoupper($title . ' ' . $asOfLabel)) ?></div>
        </div>
        <?php
    }
}

if (!function_exists('stmt_panels')) {
    /**
     * The two-column details block. Each side is [heading, [label => value, ...]].
     * Values are echoed as given, so callers format money and dates themselves —
     * this file must not decide that a blank field should read "N/A".
     */
    function stmt_panels(array $left, array $right): void
    {
        $rows = max(count($left['rows']), count($right['rows']));
        $lk = array_keys($left['rows']);
        $rk = array_keys($right['rows']);
        ?>
        <table class="vk-stmt-panels">
            <tr>
                <th colspan="2" class="vk-stmt-bar"><?= htmlspecialchars(strtoupper($left['heading'])) ?></th>
                <th colspan="2" class="vk-stmt-bar"><?= htmlspecialchars(strtoupper($right['heading'])) ?></th>
            </tr>
            <?php for ($i = 0; $i < $rows; $i++): ?>
            <tr>
                <td class="vk-stmt-label"><?= isset($lk[$i]) ? htmlspecialchars($lk[$i]) : '' ?></td>
                <td class="vk-stmt-value"><?= isset($lk[$i]) ? $left['rows'][$lk[$i]] : '' ?></td>
                <td class="vk-stmt-label"><?= isset($rk[$i]) ? htmlspecialchars($rk[$i]) : '' ?></td>
                <td class="vk-stmt-value"><?= isset($rk[$i]) ? $right['rows'][$rk[$i]] : '' ?></td>
            </tr>
            <?php endfor; ?>
        </table>
        <?php
    }
}

if (!function_exists('stmt_bar_table')) {
    /**
     * The full-width band NSSF uses for "LAST PAID BENEFIT DETAILS". Here it carries
     * Condolences — what the group paid out when this member lost a beneficiary.
     * `$rows` is a list of associative rows; `$columns` maps key => heading.
     */
    function stmt_bar_table(string $heading, array $columns, array $rows, string $emptyText): void
    {
        ?>
        <table class="vk-stmt-panels vk-stmt-bartable">
            <tr><th colspan="<?= count($columns) ?>" class="vk-stmt-bar"><?= htmlspecialchars(strtoupper($heading)) ?></th></tr>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?= count($columns) ?>" class="vk-stmt-empty"><?= htmlspecialchars($emptyText) ?></td></tr>
            <?php else: ?>
                <tr>
                    <?php foreach ($columns as $head): ?>
                        <td class="vk-stmt-label"><?= htmlspecialchars($head) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach (array_keys($columns) as $key): ?>
                        <td class="vk-stmt-value"><?= $row[$key] ?? '' ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
        <?php
    }
}

if (!function_exists('stmt_calendar')) {
    /**
     * The breakdown grid: one row per year, twelve month columns, a year total.
     * Takes cs_calendar_grid() output directly.
     *
     * Cells are coloured by state, and the state set is why this reads correctly:
     * a month before the member joined is grey and empty, NOT red. Red means "you
     * owed this and did not pay it", and saying that about someone who had not yet
     * joined is the single most likely way to start an argument in a meeting.
     */
    function stmt_calendar(array $grid, bool $isSw): void
    {
        $months = stmt_month_labels($isSw);
        $classes = [
            'paid'        => 'vk-c-paid',
            'partial'     => 'vk-c-partial',
            'unpaid'      => 'vk-c-unpaid',
            'advance'     => 'vk-c-advance',
            'before_join' => 'vk-c-before',
            'future'      => 'vk-c-future',
            'no_target'   => 'vk-c-notarget',
        ];
        ?>
        <table class="vk-stmt-grid">
            <thead>
                <tr>
                    <th class="vk-stmt-bar"><?= $isSw ? 'MWAKA' : 'YEAR' ?></th>
                    <?php foreach ($months as $m): ?>
                        <th class="vk-stmt-bar"><?= $m ?></th>
                    <?php endforeach; ?>
                    <th class="vk-stmt-bar vk-stmt-bar-total"><?= $isSw ? 'JUMLA' : 'TOTAL' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grid['years'] as $year => $cells): ?>
                <tr>
                    <td class="vk-stmt-year"><?= (int) $year ?></td>
                    <?php $yearTotal = 0.0; ?>
                    <?php foreach ($cells as $cell):
                        $yearTotal += $cell['allocated'];
                        $cls = $classes[$cell['status']] ?? '';
                    ?>
                        <td class="<?= $cls ?>">
                            <?php if ($cell['status'] === 'before_join' || $cell['status'] === 'future'): ?>
                                &nbsp;
                            <?php else: ?>
                                <?= number_format($cell['allocated'], 0) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="vk-stmt-rowtotal"><?= number_format($yearTotal, 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}

if (!function_exists('stmt_summary')) {
    /**
     * Target vs Actual per year, then the grand total — the block the group asked
     * for beneath the breakdown.
     *
     * The total row prints `paid`, not the sum of the yearly actuals. When the group
     * has no monthly rule nothing can land on a month, every year reads 0, and a
     * summed total would tell a member who has paid for years that they have paid
     * nothing. See cs_year_summary().
     */
    function stmt_summary(array $summary, bool $isSw, string $currency = 'TSh'): void
    {
        $t = $summary['total'];
        ?>
        <table class="vk-stmt-grid vk-stmt-summary">
            <thead>
                <tr>
                    <th class="vk-stmt-bar"><?= $isSw ? 'MWAKA' : 'YEAR' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'KIASI KINACHOTAKIWA' : 'TARGET' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'KILICHOTOLEWA' : 'ACTUAL' ?></th>
                    <th class="vk-stmt-bar"><?= $isSw ? 'TOFAUTI' : 'VARIANCE' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary['years'] as $year => $row): ?>
                <tr>
                    <td class="vk-stmt-year"><?= (int) $year ?></td>
                    <td class="vk-stmt-num"><?= number_format($row['target'], 0) ?></td>
                    <td class="vk-stmt-num"><?= number_format($row['actual'], 0) ?></td>
                    <td class="vk-stmt-num <?= $row['variance'] < 0 ? 'vk-neg' : ($row['variance'] > 0 ? 'vk-pos' : '') ?>">
                        <?= $row['variance'] < 0 ? '(' . number_format(abs($row['variance']), 0) . ')' : number_format($row['variance'], 0) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="vk-stmt-total">
                    <td><?= $isSw ? 'JUMLA' : 'TOTAL' ?></td>
                    <td class="vk-stmt-num"><?= number_format($t['target'], 0) ?></td>
                    <td class="vk-stmt-num"><?= number_format($t['actual'], 0) ?></td>
                    <td class="vk-stmt-num <?= $t['variance'] < 0 ? 'vk-neg' : ($t['variance'] > 0 ? 'vk-pos' : '') ?>">
                        <?= $t['variance'] < 0 ? '(' . number_format(abs($t['variance']), 0) . ')' : number_format($t['variance'], 0) ?>
                    </td>
                </tr>
                <?php if ($t['unallocated'] > 0): ?>
                <tr class="vk-stmt-note">
                    <td colspan="4">
                        <?= $isSw
                            ? 'Kikundi hakina kiwango cha kila mwezi kilichowekwa, kwa hivyo fedha hazijagawanywa kwenye miezi. Jumla aliyotoa: '
                            : 'The group has no monthly amount set, so money is not spread across months. Total contributed: ' ?>
                        <strong><?= $currency ?> <?= number_format($t['paid'], 0) ?></strong>
                    </td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
        <?php
    }
}

if (!function_exists('stmt_legend')) {
    /** Key to the cell colours. Printed, because the page is handed to people. */
    function stmt_legend(bool $isSw): void
    {
        $items = $isSw
            ? ['vk-c-paid' => 'Imelipwa', 'vk-c-partial' => 'Sehemu', 'vk-c-unpaid' => 'Haijalipwa',
               'vk-c-advance' => 'Malipo ya mbele', 'vk-c-before' => 'Kabla ya kujiunga']
            : ['vk-c-paid' => 'Paid in full', 'vk-c-partial' => 'Partial', 'vk-c-unpaid' => 'Not paid',
               'vk-c-advance' => 'Paid in advance', 'vk-c-before' => 'Before joining'];
        ?>
        <div class="vk-stmt-legend">
            <?php foreach ($items as $cls => $text): ?>
                <span class="vk-legend-item"><span class="vk-legend-swatch <?= $cls ?>"></span><?= htmlspecialchars($text) ?></span>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('stmt_css')) {
    /** One stylesheet for all four statements, screen and print. */
    function stmt_css(): void
    {
        ?>
        <style>
        :root { --vk-stmt-bar:#0f4c81; --vk-stmt-bar2:#12609f; }

        .vk-stmt-sheet { background:#fff; padding:18px 20px; border-radius:10px; }
        .vk-stmt-head { margin-bottom:14px; }
        .vk-stmt-logo { max-height:72px; margin-bottom:6px; }
        .vk-stmt-org { font-weight:800; font-size:15pt; letter-spacing:.02em; color:#111; }
        .vk-stmt-sub { font-size:9.5pt; color:#555; }
        .vk-stmt-title { font-weight:800; font-size:11.5pt; margin-top:8px; color:#111; }

        .vk-stmt-panels, .vk-stmt-grid { width:100%; border-collapse:collapse; margin-bottom:14px; }
        .vk-stmt-panels td, .vk-stmt-grid td, .vk-stmt-grid th { border:1px solid #cfd6dd; }

        .vk-stmt-bar {
            background:linear-gradient(135deg,var(--vk-stmt-bar),var(--vk-stmt-bar2));
            color:#fff; font-weight:800; font-size:8.5pt; letter-spacing:.04em;
            padding:7px 9px; text-align:left; border:1px solid var(--vk-stmt-bar);
        }
        .vk-stmt-grid .vk-stmt-bar { text-align:center; }
        .vk-stmt-bar-total { background:#0b3a63; }

        .vk-stmt-label { padding:6px 9px; font-size:9pt; color:#444; width:17%; background:#f6f8fa; }
        .vk-stmt-value { padding:6px 9px; font-size:9pt; font-weight:700; color:#111; width:33%; }
        .vk-stmt-empty { padding:10px; font-size:9pt; color:#777; text-align:center; font-style:italic; }
        .vk-stmt-bartable .vk-stmt-value { width:auto; }

        .vk-stmt-grid td { padding:6px 4px; text-align:center; font-size:9pt; }
        .vk-stmt-year { font-weight:800; background:#f6f8fa; }
        .vk-stmt-rowtotal { font-weight:800; background:#0b3a63; color:#fff; }
        .vk-stmt-num { text-align:right; padding-right:10px; font-variant-numeric:tabular-nums; }
        .vk-stmt-summary td { font-size:9.5pt; }
        .vk-stmt-total td { font-weight:800; background:#0b3a63; color:#fff; }
        .vk-stmt-note td { font-size:8.5pt; color:#555; text-align:left; background:#fffbe6; }
        .vk-pos { color:#0a7d3f; font-weight:800; }
        .vk-neg { color:#b02a37; font-weight:800; }
        .vk-stmt-total .vk-pos, .vk-stmt-total .vk-neg { color:#fff; }

        .vk-c-paid     { background:#1c7c4a; color:#fff; font-weight:700; }
        .vk-c-partial  { background:#e0a800; color:#111; font-weight:700; }
        .vk-c-unpaid   { background:#c23c48; color:#fff; font-weight:700; }
        .vk-c-advance  { background:#2f6fa8; color:#fff; font-weight:700; }
        .vk-c-before   { background:repeating-linear-gradient(45deg,#eef1f4,#eef1f4 4px,#e3e7ec 4px,#e3e7ec 8px); }
        .vk-c-future   { background:#fbfcfd; }
        .vk-c-notarget { background:#eef4fa; color:#333; }

        .vk-stmt-legend { display:flex; flex-wrap:wrap; gap:14px; font-size:8.5pt; color:#444; margin-bottom:14px; }
        .vk-legend-item { display:inline-flex; align-items:center; gap:5px; }
        .vk-legend-swatch { width:13px; height:13px; border:1px solid #b9c2cb; display:inline-block; }

        @media print {
            @page { size:A4 landscape; margin:10mm; }
            .header-wrapper, .navbar, .top-header, .bottom-header, .d-print-none, .no-print, .btn, footer, .modal { display:none !important; }
            body { padding-top:0 !important; margin:0 !important; background:#fff !important; }
            .container-fluid, .container { width:100% !important; max-width:none !important; padding:0 !important; margin:0 !important; }
            .vk-stmt-sheet { padding:0; border-radius:0; }
            .vk-stmt-panels, .vk-stmt-grid { page-break-inside:avoid; }
            .vk-stmt-grid tr { page-break-inside:avoid; }
            /* Colour carries the meaning here, so it must survive the printer. */
            .vk-stmt-bar, .vk-stmt-bar-total, .vk-stmt-rowtotal, .vk-stmt-total td,
            .vk-c-paid, .vk-c-partial, .vk-c-unpaid, .vk-c-advance, .vk-c-before,
            .vk-c-notarget, .vk-stmt-year, .vk-stmt-label, .vk-stmt-note td {
                -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important;
            }
            .vk-stmt-bar { background:var(--vk-stmt-bar) !important; }
        }
        </style>
        <?php
    }
}
