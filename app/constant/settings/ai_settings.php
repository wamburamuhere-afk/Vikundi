<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
requireViewPermission('ai_settings');
require_once HEADER_FILE;

$is_sw = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$cfg   = aiSettings();
$models = aiProviderModels();
$cap    = aiCapInfo();
$canEditAi = canEdit('ai_settings') || isAdmin();
?>

<div class="container-fluid mt-4" style="max-width: 960px;">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-2">
        <div>
            <h4 class="fw-bold text-primary mb-0">
                <i class="bi bi-robot me-2"></i><?= $is_sw ? 'Msaidizi wa AI' : 'AI Assistant' ?>
            </h4>
            <p class="text-muted small mb-0">
                <?= $is_sw
                    ? 'Unganisha akaunti yako ya AI ili kusaidia kuandika ujumbe na maandishi. Wewe unadhibiti modeli na gharama.'
                    : 'Connect your own AI account to help draft messages and text. You control the model and the cost.' ?>
            </p>
        </div>
        <span class="badge rounded-pill px-3 py-2 <?= $cfg['enabled'] ? 'bg-success' : 'bg-secondary' ?>">
            <i class="bi bi-<?= $cfg['enabled'] ? 'check-circle' : 'pause-circle' ?> me-1"></i>
            <?= $cfg['enabled'] ? ($is_sw ? 'Imewashwa' : 'Enabled') : ($is_sw ? 'Imezimwa' : 'Disabled') ?>
        </span>
    </div>

    <div class="row g-4">
        <!-- Settings form -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-sliders me-2 text-primary"></i><?= $is_sw ? 'Mipangilio' : 'Configuration' ?></h6>
                </div>
                <div class="card-body p-4">
                    <form id="aiSettingsForm">
                        <!-- Enable -->
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="ai_enabled" name="ai_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-bold" for="ai_enabled">
                                <?= $is_sw ? 'Washa Msaidizi wa AI' : 'Enable the AI Assistant' ?>
                            </label>
                            <div class="form-text"><?= $is_sw ? 'Ukizima, vitufe vyote vya AI vitafichwa mara moja.' : 'When off, every AI button is hidden instantly.' ?></div>
                        </div>

                        <div class="row g-3">
                            <!-- Provider -->
                            <div class="col-md-6">
                                <label class="form-label small fw-bold"><?= $is_sw ? 'Mtoa Huduma' : 'Provider' ?></label>
                                <select class="form-select" id="ai_provider" name="ai_provider">
                                    <?php foreach ($models as $key => $info): ?>
                                        <option value="<?= $key ?>" <?= $cfg['provider'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Model -->
                            <div class="col-md-6">
                                <label class="form-label small fw-bold"><?= $is_sw ? 'Modeli' : 'Model' ?></label>
                                <select class="form-select" id="ai_model" name="ai_model"></select>
                            </div>
                        </div>

                        <!-- API key -->
                        <div class="mt-3">
                            <label class="form-label small fw-bold"><?= $is_sw ? 'Ufunguo wa API (API Key)' : 'API Key' ?></label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="ai_api_key" name="ai_api_key"
                                       placeholder="<?= $cfg['has_key'] ? '••••••••••••  (' . ($is_sw ? 'imehifadhiwa' : 'saved') . ')' : ($is_sw ? 'Bandika ufunguo wako wa API' : 'Paste your API key') ?>"
                                       autocomplete="off">
                                <button class="btn btn-outline-secondary" type="button" id="toggleKey" tabindex="-1"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">
                                <?= $is_sw
                                    ? 'Huhifadhiwa kwa usimbaji fiche (encrypted) na haionyeshwi tena. Acha wazi ili kubaki na ule uliopo.'
                                    : 'Stored encrypted and never shown again. Leave blank to keep the existing key.' ?>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <!-- Creativity -->
                            <div class="col-md-6">
                                <label class="form-label small fw-bold"><?= $is_sw ? 'Ubunifu' : 'Creativity' ?></label>
                                <?php $t = $cfg['temperature']; ?>
                                <select class="form-select" name="ai_temperature">
                                    <option value="0.3" <?= $t <= 0.39 ? 'selected' : '' ?>><?= $is_sw ? 'Chini (sahihi zaidi)' : 'Low (more precise)' ?></option>
                                    <option value="0.6" <?= ($t > 0.39 && $t <= 0.74) ? 'selected' : '' ?>><?= $is_sw ? 'Wastani' : 'Medium' ?></option>
                                    <option value="0.9" <?= $t > 0.74 ? 'selected' : '' ?>><?= $is_sw ? 'Juu (ubunifu zaidi)' : 'High (more creative)' ?></option>
                                </select>
                            </div>
                            <!-- Monthly cap -->
                            <div class="col-md-6">
                                <label class="form-label small fw-bold"><?= $is_sw ? 'Kikomo cha Gharama kwa Mwezi (USD)' : 'Monthly Cost Cap (USD)' ?></label>
                                <input type="number" step="0.5" min="0" class="form-control" name="ai_monthly_cost_cap" value="<?= htmlspecialchars((string)$cfg['cost_cap']) ?>">
                                <div class="form-text"><?= $is_sw ? '0 = bila kikomo' : '0 = unlimited' ?></div>
                            </div>
                        </div>

                        <!-- Advanced -->
                        <div class="mt-3">
                            <a class="small text-decoration-none" data-bs-toggle="collapse" href="#advancedAi" role="button">
                                <i class="bi bi-gear me-1"></i><?= $is_sw ? 'Mipangilio ya Kina' : 'Advanced' ?>
                            </a>
                            <div class="collapse mt-2" id="advancedAi">
                                <label class="form-label small fw-bold"><?= $is_sw ? 'Base URL (kwa OpenAI-compatible / OpenRouter)' : 'Base URL (for OpenAI-compatible / OpenRouter)' ?></label>
                                <input type="text" class="form-control" name="ai_base_url" value="<?= htmlspecialchars($cfg['base_url']) ?>" placeholder="https://api.openai.com/v1">
                            </div>
                        </div>

                        <?php if ($canEditAi): ?>
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm" id="btnSaveAi">
                                <i class="bi bi-check-circle me-1"></i><?= $is_sw ? 'Hifadhi' : 'Save' ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-4" id="btnTestAi">
                                <i class="bi bi-plug me-1"></i><?= $is_sw ? 'Jaribu Muunganisho' : 'Test Connection' ?>
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info mt-4 mb-0 small"><i class="bi bi-info-circle me-1"></i><?= $is_sw ? 'Huna ruhusa ya kubadilisha mipangilio hii.' : 'You do not have permission to change these settings.' ?></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- Side: usage + how-to -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-3" style="background-color:#d1e7dd;">
                <div class="card-body">
                    <div class="small text-uppercase fw-bold text-dark mb-1"><?= $is_sw ? 'Matumizi Mwezi Huu' : 'This Month\'s Usage' ?></div>
                    <div class="display-6 fw-bold text-dark">$<?= number_format($cap['spent'], 4) ?></div>
                    <div class="small text-dark mt-1">
                        <?php if ($cap['cap'] > 0): ?>
                            <?= $is_sw ? 'Kikomo' : 'Cap' ?>: $<?= number_format($cap['cap'], 2) ?>
                            <?= $cap['exceeded'] ? '— <strong>' . ($is_sw ? 'Kimefikiwa' : 'reached') . '</strong>' : '' ?>
                        <?php else: ?>
                            <?= $is_sw ? 'Hakuna kikomo kilichowekwa' : 'No cap set' ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body small">
                    <h6 class="fw-bold"><i class="bi bi-shield-check text-success me-1"></i><?= $is_sw ? 'Usalama' : 'Safety' ?></h6>
                    <ul class="ps-3 mb-2 text-muted">
                        <li><?= $is_sw ? 'AI huandika maandishi tu — haibadilishi data yoyote.' : 'AI only drafts text — it never changes any data.' ?></li>
                        <li><?= $is_sw ? 'Hutumika tu na nafasi zilizoruhusiwa katika Nafasi za Watumiaji.' : 'Available only to roles granted it in User Roles.' ?></li>
                        <li><?= $is_sw ? 'Ufunguo wako wa API umehifadhiwa kwa usimbaji fiche.' : 'Your API key is stored encrypted.' ?></li>
                    </ul>
                    <a href="<?= getUrl('user_roles') ?>" class="small"><i class="bi bi-people me-1"></i><?= $is_sw ? 'Dhibiti nani anaweza kutumia AI' : 'Manage who can use AI' ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const AI_MODELS   = <?= json_encode($models) ?>;
const AI_CURRENT  = <?= json_encode(['provider' => $cfg['provider'], 'model' => $cfg['model']]) ?>;
const AI_SAVE_URL = '<?= getUrl('api/ai/save_settings') ?>';
const AI_TEST_URL = '<?= getUrl('api/ai/test_connection') ?>';
const AI_IS_SW    = <?= $is_sw ? 'true' : 'false' ?>;

function aiPopulateModels() {
    const provider = $('#ai_provider').val();
    const info = AI_MODELS[provider] || { models: [] };
    const $m = $('#ai_model').empty();
    info.models.forEach(function(m) {
        $m.append(`<option value="${m}" ${m === AI_CURRENT.model ? 'selected' : ''}>${m}</option>`);
    });
}

$(function() {
    aiPopulateModels();
    $('#ai_provider').on('change', aiPopulateModels);

    $('#toggleKey').on('click', function() {
        const $k = $('#ai_api_key');
        const t = $k.attr('type') === 'password' ? 'text' : 'password';
        $k.attr('type', t);
        $(this).find('i').toggleClass('bi-eye bi-eye-slash');
    });

    $('#aiSettingsForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#btnSaveAi').prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>' + (AI_IS_SW ? 'Inahifadhi...' : 'Saving...'));
        $.post(AI_SAVE_URL, $(this).serialize(), function(res) {
            if (res.success) {
                Swal.fire({ icon:'success', title: AI_IS_SW ? 'Imehifadhiwa!' : 'Saved!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }, 'json').fail(() => Swal.fire('Error', AI_IS_SW ? 'Tatizo la mtandao' : 'Network error', 'error'))
          .always(() => $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>' + (AI_IS_SW ? 'Hifadhi' : 'Save')));
    });

    $('#btnTestAi').on('click', function() {
        const $btn = $(this).prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>' + (AI_IS_SW ? 'Inajaribu...' : 'Testing...'));
        $.post(AI_TEST_URL, {}, function(res) {
            Swal.fire(res.success ? (AI_IS_SW ? 'Imefanikiwa' : 'Success') : 'Error', res.message, res.success ? 'success' : 'error');
        }, 'json').fail(() => Swal.fire('Error', AI_IS_SW ? 'Tatizo la mtandao' : 'Network error', 'error'))
          .always(() => $btn.prop('disabled', false).html('<i class="bi bi-plug me-1"></i>' + (AI_IS_SW ? 'Jaribu Muunganisho' : 'Test Connection')));
    });
});
</script>

<?php
require_once FOOTER_FILE;
echo ob_get_clean();
