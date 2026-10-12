<?php
/**
 * app/bms/loans/_apply_modal.php — the loan application form, shared by My Loans
 * (a member applying for themselves) and the Loans list (leadership applying on a
 * member's behalf).
 *
 * The including page sets:
 *   $apply_types    active loan types (vl_active_types())
 *   $apply_members  [] for a member applying for themselves, or every active
 *                   member [customer_id, name] when leadership is applying
 *   $apply_self_id  the applicant's own customer id (0 when leadership picks one)
 *   $t              fn(en, sw) translator
 *
 * Limits, the schedule and the totals are fetched from api/loan_preview — the
 * browser does no loan arithmetic of its own.
 */
$apply_all_members = $pdo->query(
    "SELECT customer_id, " . vl_member_name_sql('customers') . " AS name FROM customers WHERE status = 'active' ORDER BY first_name, last_name"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="modal fade" id="loanApplyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" id="loanApplyForm">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-bank me-2"></i><?= $t('Apply for a Loan', 'Omba Mkopo') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-start">
                <?php if (!$apply_types): ?>
                    <div class="alert alert-warning mb-0"><?= $t('The group has not set up any loan types yet. Leadership adds them under Group Settings.', 'Kikundi bado hakijaweka aina za mikopo. Uongozi huziweka kwenye Mipangilio ya Kikundi.') ?></div>
                <?php else: ?>
                <input type="hidden" name="action" value="apply">
                <div class="row g-3">
                    <?php if ($apply_members): ?>
                    <div class="col-12">
                        <label class="form-label fw-bold"><?= $t('Member', 'Mwanachama') ?></label>
                        <select name="customer_id" id="laMember" class="form-select" required>
                            <option value=""><?= $t('Choose a member…', 'Chagua mwanachama…') ?></option>
                            <?php foreach ($apply_members as $m): ?>
                            <option value="<?= (int) $m['customer_id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-6">
                        <label class="form-label fw-bold"><?= $t('Loan type', 'Aina ya mkopo') ?></label>
                        <select name="loan_type_id" id="laType" class="form-select" required>
                            <?php foreach ($apply_types as $ty): ?>
                            <option value="<?= (int) $ty['id'] ?>"><?= htmlspecialchars($ty['name']) ?> — <?= rtrim(rtrim(number_format((float) $ty['interest_rate'], 3), '0'), '.') ?>% <?= $t('a month', 'kwa mwezi') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold"><?= $t('Amount (TZS)', 'Kiasi (TZS)') ?></label>
                        <input type="number" name="amount" id="laAmount" class="form-control" min="1" step="any" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold"><?= $t('Months', 'Miezi') ?></label>
                        <input type="number" name="term_months" id="laMonths" class="form-control" min="1" step="1" required>
                    </div>
                    <div class="col-12">
                        <div class="small" id="laLimit"></div>
                    </div>
                    <div class="col-12" id="laGuarantorsWrap" style="display:none;">
                        <label class="form-label fw-bold"><span id="laGuarantorsLabel"></span></label>
                        <select name="guarantors[]" id="laGuarantors" class="form-select" multiple size="5">
                            <?php foreach ($apply_all_members as $m): ?>
                            <option value="<?= (int) $m['customer_id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $t('Hold Ctrl (or tap) to choose more than one. Each guarantor must accept before the loan goes for approval.', 'Bonyeza Ctrl (au gusa) kuchagua zaidi ya mmoja. Kila mdhamini lazima akubali kabla mkopo haujapelekwa kwa idhini.') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold"><?= $t('Purpose (optional)', 'Madhumuni (si lazima)') ?></label>
                        <input type="text" name="purpose" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12" id="laPreview"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?= $t('Cancel', 'Ghairi') ?></button>
                <?php if ($apply_types): ?>
                <button type="submit" class="btn btn-primary px-4" id="laSubmit"><i class="bi bi-send me-1"></i> <?= $t('Submit application', 'Tuma ombi') ?></button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const isSw = <?= json_encode(($_SESSION['preferred_language'] ?? 'en') === 'sw') ?>;
    const tr = (en, sw) => isSw ? sw : en;
    const selfId = <?= (int) $apply_self_id ?>;
    const previewUrl = '<?= getUrl("api/loan_preview") ?>';
    const actionUrl  = '<?= getUrl("actions/loan_action") ?>';
    const money = n => 'TZS ' + Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s == null ? '' : s).html();
    let timer = null;
    // Each preview request is numbered. Changing several fields quickly fires
    // several requests, and a slow early one can arrive after a later one; only
    // the answer to the LATEST request may update the form, or a stale reply
    // (for an amount that is no longer on screen) blanks the schedule.
    let previewSeq = 0;

    function borrower() { return $('#laMember').length ? Number($('#laMember').val() || 0) : selfId; }

    // The borrower cannot be picked as their own guarantor.
    function syncGuarantors() {
        const b = borrower();
        $('#laGuarantors option').each(function () {
            const own = Number(this.value) === b;
            $(this).prop('disabled', own);
            if (own) $(this).prop('selected', false);
        });
    }

    function refresh() {
        syncGuarantors();
        const type = $('#laType').val();
        const seq = ++previewSeq;
        if (!type || !borrower()) { $('#laLimit, #laPreview').empty(); return; }
        $.getJSON(previewUrl, {
            loan_type_id: type, customer_id: borrower(),
            amount: $('#laAmount').val() || 0, term_months: $('#laMonths').val() || 0
        }).done(function (res) {
            if (seq !== previewSeq || res.status !== 'success') return;
            const d = res.data;
            $('#laMonths').attr('max', d.max_term_months);

            let limit = d.max_amount === null
                ? tr('No borrowing limit on this loan type.', 'Hakuna kikomo kwa aina hii ya mkopo.')
                : tr('You can borrow up to ', 'Unaweza kukopa hadi ') + '<strong>' + money(d.max_amount) + '</strong>';
            limit += ' · ' + tr('share capital ', 'mtaji wa hisa ') + money(d.share_capital)
                   + ' · ' + tr('up to ', 'hadi miezi ') + d.max_term_months + tr(' months', '');
            $('#laLimit').html(limit).toggleClass('text-danger', !d.within_limit).toggleClass('text-muted', d.within_limit);

            $('#laGuarantorsWrap').toggle(d.guarantors_required > 0);
            $('#laGuarantorsLabel').text(tr('Guarantors — choose ', 'Wadhamini — chagua ') + d.guarantors_required);
            $('#laGuarantors').data('required', d.guarantors_required);

            if (!d.schedule.length) { $('#laPreview').empty(); return; }
            let rows = d.schedule.map(l => '<tr><td>' + l.seq + '</td><td>' + esc(l.due_date) + '</td><td class="text-end">'
                + money(l.principal) + '</td><td class="text-end">' + money(l.interest) + '</td><td class="text-end fw-bold">' + money(l.total) + '</td></tr>').join('');
            $('#laPreview').html(
                '<div class="alert alert-light border mb-2 py-2"><div class="row text-center">'
                + '<div class="col-4"><div class="small text-muted">' + tr('You borrow', 'Unakopa') + '</div><div class="fw-bold">' + money(d.totals.principal) + '</div></div>'
                + '<div class="col-4"><div class="small text-muted">' + tr('Interest', 'Riba') + '</div><div class="fw-bold text-danger">' + money(d.totals.interest) + '</div></div>'
                + '<div class="col-4"><div class="small text-muted">' + tr('You repay', 'Utarejesha') + '</div><div class="fw-bold text-primary">' + money(d.totals.total) + '</div></div>'
                + '</div></div>'
                + '<div class="table-responsive" style="max-height:220px;"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>#</th><th>'
                + tr('Due', 'Tarehe') + '</th><th class="text-end">' + tr('Principal', 'Mkopo') + '</th><th class="text-end">' + tr('Interest', 'Riba')
                + '</th><th class="text-end">' + tr('Instalment', 'Rejesho') + '</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                + '<div class="form-text">' + tr('Dates are counted from the day the loan is paid out.', 'Tarehe huhesabiwa kuanzia siku mkopo unapotolewa.') + '</div>'
            );
        });
    }

    $('#laType, #laMember').on('change', refresh);
    $('#laAmount, #laMonths').on('input', function () { clearTimeout(timer); timer = setTimeout(refresh, 300); });
    document.getElementById('loanApplyModal').addEventListener('shown.bs.modal', refresh);

    $('#loanApplyForm').on('submit', function (e) {
        e.preventDefault();
        const need = Number($('#laGuarantors').data('required') || 0);
        const have = ($('#laGuarantors').val() || []).length;
        if (need !== have) {
            Swal.fire(tr('Guarantors', 'Wadhamini'), tr('Choose exactly ', 'Chagua wadhamini ') + need + tr(' guarantor(s).', ' hasa.'), 'warning');
            return;
        }
        const btn = $('#laSubmit').prop('disabled', true);
        $.ajax({ url: actionUrl, type: 'POST', data: $(this).serialize(), dataType: 'json' })
            .always(function () { btn.prop('disabled', false); })
            .done(function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: res.message, timer: 1600, showConfirmButton: false }).then(() => location.reload());
                } else {
                    Swal.fire(tr('Not submitted', 'Halijatumwa'), res.message, 'error');
                }
            })
            .fail(function (xhr) {
                Swal.fire(tr('Error', 'Hitilafu'), (xhr.responseJSON && xhr.responseJSON.message) || tr('Server error.', 'Hitilafu ya seva.'), 'error');
            });
    });
})();
</script>
