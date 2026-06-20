<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/sms_helper.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Admin-only: one admin configures the gateway once; everyone else just sends.
if (!isAuthenticated()) { redirectTo('login'); }
if (!isAdmin() && !canEdit('system_settings')) {
    http_response_code(403);
    redirectTo('unauthorized');
}

$gateways = sms_gateways($is_sw);
$cfg      = sms_get_config($pdo);
$has_key  = $cfg['has_gateway'] || $cfg['api_key'] !== '';
$has_sec  = $cfg['api_secret'] !== '';

require_once __DIR__ . '/../../../header.php';
?>

<div class="container py-4" style="max-width: 860px;">
    <div class="row mb-3">
        <div class="col-12">
            <h2 class="mb-1"><i class="bi bi-phone-vibrate text-primary"></i> <?= $is_sw ? 'Mipangilio ya SMS' : 'SMS Settings' ?></h2>
            <p class="text-muted mb-0">
                <?= $is_sw
                    ? 'Sanidi lango moja la SMS kwa ajili ya kutuma. Baada ya hapo, kila mtu anaweza kutuma SMS bila kujali mipangilio hii.'
                    : 'Set up one SMS gateway for sending. After this, anyone can send SMS without touching these settings.' ?>
            </p>
        </div>
    </div>

    <div class="mb-3">
        <?php if ($cfg['has_gateway'] && $cfg['enabled']): ?>
            <span class="vk-badge" style="background:#0d6efd;color:#fff"><i class="bi bi-check-circle me-1"></i><?= $is_sw ? 'Imeunganishwa' : 'Active' ?></span>
            <span class="text-muted small ms-1"><?= safe_output($gateways[$cfg['provider']]['label'] ?? $cfg['provider']) ?></span>
        <?php elseif ($cfg['has_gateway'] && !$cfg['enabled']): ?>
            <span class="vk-badge" style="background:#6c757d;color:#fff"><?= $is_sw ? 'Imezimwa' : 'Disabled' ?></span>
        <?php else: ?>
            <span class="vk-badge" style="background:#e9ecef;color:#495057"><i class="bi bi-exclamation-circle me-1"></i><?= $is_sw ? 'Bado haijasanidiwa' : 'Not set up yet' ?></span>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-sliders me-2 text-primary"></i><?= $is_sw ? 'Sanidi Lango la SMS' : 'Gateway Configuration' ?></h6>
        </div>
        <div class="card-body">
            <form id="smsSettingsForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="sms_provider" class="form-label fw-semibold"><?= $is_sw ? 'Mtoa Huduma' : 'Gateway' ?></label>
                        <select class="form-select select2-static" id="sms_provider" name="sms_provider">
                            <?php foreach ($gateways as $key => $g): ?>
                            <option value="<?= $key ?>" <?= $cfg['provider'] === $key ? 'selected' : '' ?>><?= safe_output($g['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small" id="gatewayHelp"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="sms_sender_id" class="form-label fw-semibold"><?= $is_sw ? 'Sender ID' : 'Sender ID' ?></label>
                        <input type="text" class="form-control" id="sms_sender_id" name="sms_sender_id"
                               value="<?= safe_output($cfg['sender_id']) ?>" placeholder="VIKUNDI" maxlength="20">
                        <div class="form-text small" data-field="sender_id"><?= $is_sw ? 'Jina/namba itakayoonekana kwa mpokeaji.' : 'The name/number recipients will see.' ?></div>
                    </div>

                    <div class="col-md-6" data-field="username">
                        <label for="sms_username" class="form-label fw-semibold"><?= $is_sw ? 'Jina la Mtumiaji (Username)' : 'Username' ?></label>
                        <input type="text" class="form-control" id="sms_username" name="sms_username" value="<?= safe_output($cfg['username']) ?>" autocomplete="username">
                    </div>

                    <div class="col-md-6" data-field="api_key">
                        <label for="sms_api_key" class="form-label fw-semibold"><?= $is_sw ? 'API Key' : 'API Key' ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="sms_api_key" name="sms_api_key" autocomplete="off"
                                   placeholder="<?= $has_key ? '••••••••••  (' . ($is_sw ? 'imehifadhiwa' : 'saved') . ')' : ($is_sw ? 'Bandika API Key' : 'Paste your API Key') ?>">
                            <button class="btn btn-outline-secondary" type="button" data-toggle-pass="#sms_api_key" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <div class="col-md-6" data-field="api_secret">
                        <label for="sms_api_secret" class="form-label fw-semibold"><?= $is_sw ? 'API Secret / Token' : 'API Secret / Token' ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="sms_api_secret" name="sms_api_secret" autocomplete="off"
                                   placeholder="<?= $has_sec ? '••••••••••  (' . ($is_sw ? 'imehifadhiwa' : 'saved') . ')' : ($is_sw ? 'Bandika Secret/Token' : 'Paste Secret / Token') ?>">
                            <button class="btn btn-outline-secondary" type="button" data-toggle-pass="#sms_api_secret" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <div class="col-12" data-field="base_url">
                        <label for="sms_base_url" class="form-label fw-semibold"><?= $is_sw ? 'URL ya Lango (Base URL)' : 'Gateway Base URL' ?></label>
                        <input type="url" class="form-control" id="sms_base_url" name="sms_base_url" value="<?= safe_output($cfg['base_url']) ?>" placeholder="https://api.example.com/send">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="sms_enabled" name="sms_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="sms_enabled"><?= $is_sw ? 'Washa utumaji wa SMS' : 'Enable SMS sending' ?></label>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4" id="btnSave">
                        <i class="bi bi-check-circle me-1"></i><?= $is_sw ? 'Hifadhi' : 'Save' ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary px-4" id="btnTest">
                        <i class="bi bi-send me-1"></i><?= $is_sw ? 'Tuma SMS ya Jaribio' : 'Send Test SMS' ?>
                    </button>
                    <a href="<?= getUrl('sms_center') ?>" class="btn btn-outline-secondary px-4 ms-auto">
                        <i class="bi bi-arrow-left me-1"></i><?= $is_sw ? 'Rudi Kituo cha SMS' : 'Back to SMS Center' ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <i class="bi bi-shield-lock text-primary me-1"></i>
        <?= $is_sw
            ? 'API Key na Secret zako zimehifadhiwa kwa usimbaji fiche (AES-256) na hazionyeshwi tena.'
            : 'Your API Key and Secret are stored encrypted (AES-256) and are never shown again.' ?>
    </div>
</div>

<?php include("footer.php"); ?>

<style>
.vk-badge { display:inline-block; padding:.3rem .7rem; border-radius:.35rem; font-size:.8rem; font-weight:600; }
.select2-container--bootstrap-5 .select2-selection { min-height: 44px; border-color:#b6ccfe; }
</style>

<script>
(function () {
    const isSw     = <?= $is_sw ? 'true' : 'false' ?>;
    const GATEWAYS = <?= json_encode($gateways, JSON_UNESCAPED_UNICODE) ?>;
    const SAVE_URL = '<?= getUrl('api/sms/save_settings') ?>';
    const TEST_URL = '<?= getUrl('api/sms/test_connection') ?>';
    const t = (en, sw) => isSw ? sw : en;

    function applyGateway() {
        const key = $('#sms_provider').val();
        const g = GATEWAYS[key] || { fields: [] };
        $('#gatewayHelp').text(g.help || '');
        // sender_id is always shown; toggle the rest based on the gateway's needs.
        ['username', 'api_key', 'api_secret', 'base_url'].forEach(f => {
            $('[data-field="' + f + '"]').toggleClass('d-none', !(g.fields || []).includes(f));
        });
    }
    $('#sms_provider').on('change', applyGateway);

    $('[data-toggle-pass]').on('click', function () {
        const $i = $($(this).data('toggle-pass'));
        const show = $i.attr('type') === 'password';
        $i.attr('type', show ? 'text' : 'password');
        $(this).find('i').attr('class', show ? 'bi bi-eye-slash' : 'bi bi-eye');
    });

    if ($.fn.select2) {
        $('#sms_provider').select2({ theme:'bootstrap-5', width:'100%', minimumResultsForSearch: Infinity });
    }
    applyGateway();

    $('#smsSettingsForm').on('submit', function (e) {
        e.preventDefault();
        const $b = $('#btnSave').prop('disabled', true);
        $.post(SAVE_URL, $(this).serialize(), null, 'json')
            .done(res => {
                if (res.success) Swal.fire({ icon:'success', title:t('Saved!','Imehifadhiwa!'), text:res.message, timer:2000, showConfirmButton:false }).then(() => location.reload());
                else Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:res.message });
            })
            .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Failed to save.','Imeshindwa kuhifadhi.') }))
            .always(() => $b.prop('disabled', false));
    });

    $('#btnTest').on('click', function () {
        Swal.fire({
            title: t('Send a test SMS','Tuma SMS ya jaribio'),
            input: 'tel',
            inputLabel: t('Send the test to which phone number?','Tuma jaribio kwa namba gani ya simu?'),
            inputPlaceholder: '0712 345 678',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: t('Send','Tuma')
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
            $.post(TEST_URL, { test_phone: r.value || '' }, null, 'json')
                .done(res => {
                    if (res.success) Swal.fire({ icon:'success', title:t('Sent!','Imetumwa!'), text:res.message });
                    else Swal.fire({ icon:'error', title:t('Not delivered','Haikufika'), text:res.message });
                })
                .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Test failed.','Jaribio limeshindwa.') }));
        });
    });
})();
</script>
