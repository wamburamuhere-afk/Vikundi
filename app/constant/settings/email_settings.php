<?php
// UI: complies with .claude/ui-constants.md (§UI-0…§UI-8)
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/email_helper.php';

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Admin-only: everyday (phone) users never see SMTP details — one admin sets
// this up once, then everyone just composes in the Email Center.
if (!isAuthenticated()) { redirectTo('login'); }
if (!isAdmin() && !canEdit('system_settings')) {
    http_response_code(403);
    redirectTo('unauthorized');
}

$providers = email_smtp_providers($is_sw);
$cfg       = email_get_config($pdo);
$has_pass  = $cfg['has_smtp'] || $cfg['smtp_password'] !== '';

require_once __DIR__ . '/../../../header.php';
?>

<div class="container py-4" style="max-width: 860px;">
    <div class="row mb-3">
        <div class="col-12">
            <h2 class="mb-1"><i class="bi bi-envelope-gear text-primary"></i> <?= $is_sw ? 'Mipangilio ya Barua Pepe' : 'Email Settings' ?></h2>
            <p class="text-muted mb-0">
                <?= $is_sw
                    ? 'Sanidi akaunti moja ya barua pepe kwa ajili ya kutuma. Baada ya hapo, kila mtu anaweza kutuma barua pepe bila kujali mipangilio hii.'
                    : 'Set up one email account for sending. After this, anyone can send emails without touching these settings.' ?>
            </p>
        </div>
    </div>

    <!-- Status badge -->
    <div class="mb-3">
        <?php if ($cfg['has_smtp'] && $cfg['enabled']): ?>
            <span class="vk-badge" style="background:#0d6efd;color:#fff"><i class="bi bi-check-circle me-1"></i><?= $is_sw ? 'Imeunganishwa' : 'Active' ?></span>
            <span class="text-muted small ms-1"><?= safe_output($cfg['smtp_username']) ?></span>
        <?php elseif ($cfg['has_smtp'] && !$cfg['enabled']): ?>
            <span class="vk-badge" style="background:#6c757d;color:#fff"><?= $is_sw ? 'Imezimwa' : 'Disabled' ?></span>
        <?php else: ?>
            <span class="vk-badge" style="background:#e9ecef;color:#495057"><i class="bi bi-exclamation-circle me-1"></i><?= $is_sw ? 'Bado haijasanidiwa' : 'Not set up yet' ?></span>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-sliders me-2 text-primary"></i><?= $is_sw ? 'Sanidi Utumaji' : 'Sending Configuration' ?></h6>
        </div>
        <div class="card-body">
            <form id="emailSettingsForm">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="email_provider" class="form-label fw-semibold"><?= $is_sw ? 'Mtoa Huduma' : 'Provider' ?></label>
                        <select class="form-select select2-static" id="email_provider" name="email_provider">
                            <?php foreach ($providers as $key => $p): ?>
                            <option value="<?= $key ?>" <?= $cfg['provider'] === $key ? 'selected' : '' ?>><?= safe_output($p['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small" id="providerHelp"></div>
                    </div>
                    <div class="col-md-6">
                        <label for="mail_from_name" class="form-label fw-semibold"><?= $is_sw ? 'Jina la Mtumaji' : 'From Name' ?></label>
                        <input type="text" class="form-control" id="mail_from_name" name="mail_from_name"
                               value="<?= safe_output($cfg['from_name']) ?>" placeholder="Vikundi">
                    </div>

                    <div class="col-md-6">
                        <label for="smtp_username" class="form-label fw-semibold"><?= $is_sw ? 'Anwani ya Barua Pepe' : 'Email Address' ?></label>
                        <input type="email" class="form-control" id="smtp_username" name="smtp_username"
                               value="<?= safe_output($cfg['smtp_username']) ?>" placeholder="yourgroup@gmail.com" autocomplete="username">
                        <div class="form-text small"><?= $is_sw ? 'Barua pepe itakayotuma (na kupokea majibu).' : 'The address emails are sent from (and replies go to).' ?></div>
                    </div>
                    <div class="col-md-6">
                        <label for="smtp_password" class="form-label fw-semibold"><?= $is_sw ? 'Nenosiri / App Password' : 'Password / App Password' ?></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="smtp_password" name="smtp_password" autocomplete="new-password"
                                   placeholder="<?= $has_pass ? '••••••••••  (' . ($is_sw ? 'imehifadhiwa' : 'saved') . ')' : ($is_sw ? 'Bandika nenosiri/App Password' : 'Paste password / App Password') ?>">
                            <button class="btn btn-outline-secondary" type="button" id="togglePass" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>

                    <!-- Custom-host advanced fields (hidden unless provider = custom) -->
                    <div class="col-12 d-none" id="customFields">
                        <div class="row g-3 border rounded p-2 m-0" style="background:#f8f9fa;">
                            <div class="col-md-6">
                                <label for="smtp_host" class="form-label small fw-semibold"><?= $is_sw ? 'Mwenyeji wa SMTP' : 'SMTP Host' ?></label>
                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= safe_output($cfg['smtp_host']) ?>" placeholder="mail.example.com">
                            </div>
                            <div class="col-md-3">
                                <label for="smtp_port" class="form-label small fw-semibold"><?= $is_sw ? 'Bandari' : 'Port' ?></label>
                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?= (int)$cfg['smtp_port'] ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="smtp_encryption" class="form-label small fw-semibold"><?= $is_sw ? 'Usimbaji' : 'Encryption' ?></label>
                                <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                    <option value="tls" <?= $cfg['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (587)</option>
                                    <option value="ssl" <?= $cfg['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                                    <option value="none" <?= $cfg['smtp_encryption'] === 'none' ? 'selected' : '' ?>><?= $is_sw ? 'Hakuna' : 'None' ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="email_enabled"><?= $is_sw ? 'Washa utumaji wa barua pepe' : 'Enable email sending' ?></label>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" class="btn btn-primary px-4" id="btnSave">
                        <i class="bi bi-check-circle me-1"></i><?= $is_sw ? 'Hifadhi' : 'Save' ?>
                    </button>
                    <button type="button" class="btn btn-outline-primary px-4" id="btnTest">
                        <i class="bi bi-send me-1"></i><?= $is_sw ? 'Tuma Jaribio' : 'Send Test Email' ?>
                    </button>
                    <a href="<?= getUrl('email_center') ?>" class="btn btn-outline-secondary px-4 ms-auto">
                        <i class="bi bi-arrow-left me-1"></i><?= $is_sw ? 'Rudi Kituo cha Barua Pepe' : 'Back to Email Center' ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <i class="bi bi-shield-lock text-primary me-1"></i>
        <?= $is_sw
            ? 'Nenosiri lako limehifadhiwa kwa usimbaji fiche (AES-256) na halionyeshwi tena.'
            : 'Your password is stored encrypted (AES-256) and is never shown again.' ?>
    </div>
</div>

<?php include("footer.php"); ?>

<style>
.vk-badge { display:inline-block; padding:.3rem .7rem; border-radius:.35rem; font-size:.8rem; font-weight:600; }
.select2-container--bootstrap-5 .select2-selection { min-height: 44px; border-color:#b6ccfe; }
</style>

<script>
(function () {
    const isSw      = <?= $is_sw ? 'true' : 'false' ?>;
    const PROVIDERS = <?= json_encode($providers, JSON_UNESCAPED_UNICODE) ?>;
    const SAVE_URL  = '<?= getUrl('api/email/save_settings') ?>';
    const TEST_URL  = '<?= getUrl('api/email/test_connection') ?>';
    const t = (en, sw) => isSw ? sw : en;

    function applyProvider() {
        const key = $('#email_provider').val();
        const p = PROVIDERS[key] || {};
        $('#providerHelp').text(p.help || '');
        $('#customFields').toggleClass('d-none', key !== 'custom');
        if (key !== 'custom') {
            $('#smtp_host').val(p.host || '');
            $('#smtp_port').val(p.port || 587);
            $('#smtp_encryption').val(p.encryption || 'tls');
        }
    }

    $('#email_provider').on('change', applyProvider);

    $('#togglePass').on('click', function () {
        const $i = $('#smtp_password');
        const show = $i.attr('type') === 'password';
        $i.attr('type', show ? 'text' : 'password');
        $(this).find('i').attr('class', show ? 'bi bi-eye-slash' : 'bi bi-eye');
    });

    // Init Select2 (static, no search needed for 4 presets)
    if ($.fn.select2) {
        $('#email_provider').select2({ theme:'bootstrap-5', width:'100%', minimumResultsForSearch: Infinity });
    }
    applyProvider();

    $('#emailSettingsForm').on('submit', function (e) {
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
            title: t('Send a test email','Tuma barua pepe ya jaribio'),
            input: 'email',
            inputLabel: t('Send the test to which address?','Tuma jaribio kwa anwani gani?'),
            inputPlaceholder: 'you@example.com',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: t('Send','Tuma')
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title:t('Sending...','Inatuma...'), allowOutsideClick:false, didOpen:() => Swal.showLoading() });
            $.post(TEST_URL, { test_email: r.value || '' }, null, 'json')
                .done(res => {
                    if (res.success) Swal.fire({ icon:'success', title:t('Sent!','Imetumwa!'), text:res.message });
                    else Swal.fire({ icon:'error', title:t('Not delivered','Haikufika'), text:res.message });
                })
                .fail(() => Swal.fire({ icon:'error', title:t('Error','Hitilafu'), text:t('Test failed.','Jaribio limeshindwa.') }));
        });
    });
})();
</script>
