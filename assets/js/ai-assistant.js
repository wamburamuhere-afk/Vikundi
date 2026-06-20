/*
 * assets/js/ai-assistant.js
 * Vikundi AI Assistant — reusable "Generate with AI" widget.
 *
 * Usage on a page:
 *   1. <script>window.AI_ASSIST_CONFIG = { generateUrl: '<?= getUrl("api/ai/generate") ?>', isSw: <?= $is_sw?'true':'false' ?> };</script>
 *   2. <script src="/assets/js/ai-assistant.js"></script>
 *   3. Add a button next to any field:
 *        <button type="button" class="ai-assist-btn"
 *                data-target="#message" data-module="communication"
 *                data-submodule="message" data-field-type="message"></button>
 *
 * The widget injects one shared modal and wires every .ai-assist-btn automatically.
 * It only drafts text into the target field — it never submits or changes data.
 */
(function () {
    'use strict';

    var CFG = window.AI_ASSIST_CONFIG || {};
    var IS_SW = !!CFG.isSw;
    var GEN_URL = CFG.generateUrl || '/api/ai/generate';

    function t(en, sw) { return IS_SW ? sw : en; }
    function esc(s) { return (s == null ? '' : String(s)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    var current = { target: null, module: 'general', submodule: '', fieldType: 'message' };

    // ── Styles ────────────────────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('ai-assist-styles')) return;
        var css = ''
            + '.ai-assist-btn{display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer;'
            + 'background:linear-gradient(135deg,#6f42c1,#0d6efd);color:#fff;font-size:.78rem;font-weight:600;'
            + 'padding:4px 12px;border-radius:20px;box-shadow:0 2px 6px rgba(13,110,253,.3);transition:transform .15s,box-shadow .15s;}'
            + '.ai-assist-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(13,110,253,.45);}'
            + '.ai-assist-btn .ai-spark{animation:aiSpark 2s infinite;}'
            + '@keyframes aiSpark{0%,100%{opacity:1;}50%{opacity:.4;}}'
            + '#aiAssistModal .modal-header{background:linear-gradient(135deg,#6f42c1,#0d6efd);color:#fff;border:0;}'
            + '#aiAssistModal .modal-content{border:0;border-radius:16px;overflow:hidden;}'
            + '#aiAssistModal .ai-seg{display:flex;flex-wrap:wrap;gap:6px;}'
            + '#aiAssistModal .ai-seg button{flex:1;min-width:80px;border:1px solid #dee2e6;background:#fff;border-radius:8px;'
            + 'padding:6px 8px;font-size:.8rem;font-weight:600;color:#495057;transition:all .15s;}'
            + '#aiAssistModal .ai-seg button.active{background:linear-gradient(135deg,#6f42c1,#0d6efd);color:#fff;border-color:transparent;}'
            + '#aiAssistModal .ai-result{border:1px solid #e9ecef;border-radius:12px;padding:14px;margin-bottom:12px;background:#fff;'
            + 'transition:box-shadow .15s,border-color .15s;}'
            + '#aiAssistModal .ai-result:hover{box-shadow:0 4px 14px rgba(0,0,0,.08);border-color:#cfe2ff;}'
            + '#aiAssistModal .ai-result-text{white-space:pre-wrap;font-size:.9rem;color:#212529;margin-bottom:10px;}'
            + '#aiAssistModal .ai-loading{text-align:center;padding:30px 0;color:#6f42c1;}'
            + '#aiAssistModal .ai-loading .dot{display:inline-block;width:10px;height:10px;margin:0 3px;border-radius:50%;'
            + 'background:#6f42c1;animation:aiBounce 1.2s infinite ease-in-out both;}'
            + '#aiAssistModal .ai-loading .dot:nth-child(2){animation-delay:-1.0s;background:#5a4fcf;}'
            + '#aiAssistModal .ai-loading .dot:nth-child(3){animation-delay:-0.8s;background:#0d6efd;}'
            + '@keyframes aiBounce{0%,80%,100%{transform:scale(0);}40%{transform:scale(1);}}'
            + '.ai-btn-generate{background:linear-gradient(135deg,#6f42c1,#0d6efd);border:0;color:#fff;font-weight:600;}'
            + '.ai-btn-generate:hover{filter:brightness(1.05);color:#fff;}';
        var st = document.createElement('style');
        st.id = 'ai-assist-styles';
        st.textContent = css;
        document.head.appendChild(st);
    }

    // ── Modal markup ────────────────────────────────────────────────────────────
    function injectModal() {
        if (document.getElementById('aiAssistModal')) return;
        var html =
        '<div class="modal fade" id="aiAssistModal" tabindex="-1" aria-hidden="true">'
        + '<div class="modal-dialog modal-dialog-centered modal-lg">'
        + '<div class="modal-content">'
        + '<div class="modal-header">'
        + '<h5 class="modal-title fw-bold"><i class="bi bi-stars me-2 ai-spark"></i>' + t('AI Writing Assistant', 'Msaidizi wa Kuandika (AI)') + '</h5>'
        + '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>'
        + '</div>'
        + '<div class="modal-body p-4">'
        + '  <div class="mb-3">'
        + '    <label class="form-label small fw-bold">' + t('What do you want to do?', 'Unataka kufanya nini?') + '</label>'
        + '    <div class="ai-seg" id="aiActionSeg">'
        + '      <button type="button" data-action="draft" class="active"><i class="bi bi-pencil-square me-1"></i>' + t('Draft', 'Andika') + '</button>'
        + '      <button type="button" data-action="improve"><i class="bi bi-magic me-1"></i>' + t('Improve', 'Boresha') + '</button>'
        + '      <button type="button" data-action="translate"><i class="bi bi-translate me-1"></i>' + t('Translate', 'Tafsiri') + '</button>'
        + '      <button type="button" data-action="shorten"><i class="bi bi-arrows-collapse me-1"></i>' + t('Shorten', 'Fupisha') + '</button>'
        + '    </div>'
        + '  </div>'
        + '  <div class="mb-3" id="aiInstructionWrap">'
        + '    <label class="form-label small fw-bold">' + t('Describe your message', 'Eleza ujumbe wako') + '</label>'
        + '    <textarea class="form-control" id="aiInstruction" rows="2" placeholder="' + t('e.g. Remind members the monthly contribution is due on the 5th', 'mf. Wakumbushe wanachama mchango wa mwezi unatakiwa tarehe 5') + '"></textarea>'
        + '  </div>'
        + '  <div class="row g-2 mb-3">'
        + '    <div class="col-6 col-md-3"><label class="form-label small fw-bold">' + t('Tone', 'Mtindo') + '</label>'
        + '      <select class="form-select form-select-sm" id="aiTone">'
        + '        <option value="friendly">' + t('Friendly', 'Kirafiki') + '</option>'
        + '        <option value="formal">' + t('Formal', 'Rasmi') + '</option>'
        + '        <option value="urgent">' + t('Urgent', 'Haraka') + '</option>'
        + '        <option value="encouraging">' + t('Encouraging', 'Kutia moyo') + '</option>'
        + '      </select></div>'
        + '    <div class="col-6 col-md-3"><label class="form-label small fw-bold">' + t('Length', 'Urefu') + '</label>'
        + '      <select class="form-select form-select-sm" id="aiLength">'
        + '        <option value="short">' + t('Short', 'Fupi') + '</option>'
        + '        <option value="medium" selected>' + t('Medium', 'Wastani') + '</option>'
        + '        <option value="long">' + t('Long', 'Ndefu') + '</option>'
        + '      </select></div>'
        + '    <div class="col-6 col-md-3"><label class="form-label small fw-bold">' + t('Language', 'Lugha') + '</label>'
        + '      <select class="form-select form-select-sm" id="aiLanguage">'
        + '        <option value="en"' + (IS_SW ? '' : ' selected') + '>English</option>'
        + '        <option value="sw"' + (IS_SW ? ' selected' : '') + '>Kiswahili</option>'
        + '      </select></div>'
        + '    <div class="col-6 col-md-3"><label class="form-label small fw-bold">' + t('Variations', 'Chaguo') + '</label>'
        + '      <select class="form-select form-select-sm" id="aiCount"><option value="1">1</option><option value="2" selected>2</option><option value="3">3</option></select></div>'
        + '  </div>'
        + '  <button type="button" class="btn ai-btn-generate w-100 rounded-pill py-2" id="aiGenerateBtn"><i class="bi bi-stars me-1"></i>' + t('Generate', 'Tengeneza') + '</button>'
        + '  <div id="aiResults" class="mt-4"></div>'
        + '</div>'
        + '</div></div></div>';
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.appendChild(wrap.firstChild);
    }

    function getModal() {
        return bootstrap.Modal.getOrCreateInstance(document.getElementById('aiAssistModal'));
    }

    function currentAction() {
        var a = document.querySelector('#aiActionSeg button.active');
        return a ? a.getAttribute('data-action') : 'draft';
    }

    function renderLoading() {
        document.getElementById('aiResults').innerHTML =
            '<div class="ai-loading"><span class="dot"></span><span class="dot"></span><span class="dot"></span>'
            + '<div class="mt-2 small">' + t('Thinking…', 'Inafikiria…') + '</div></div>';
    }

    function renderResults(results) {
        var box = document.getElementById('aiResults');
        if (!results || !results.length) {
            box.innerHTML = '<div class="text-center text-muted py-3">' + t('No suggestions returned.', 'Hakuna mapendekezo.') + '</div>';
            return;
        }
        var html = '<div class="small fw-bold text-muted mb-2">' + t('Click Insert to use a suggestion:', 'Bonyeza Weka kutumia pendekezo:') + '</div>';
        results.forEach(function (r, i) {
            html += '<div class="ai-result">'
                + '<div class="ai-result-text" id="aiRes' + i + '">' + esc(r) + '</div>'
                + '<div class="d-flex gap-2 justify-content-end">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary ai-copy" data-i="' + i + '"><i class="bi bi-clipboard me-1"></i>' + t('Copy', 'Nakili') + '</button>'
                + '<button type="button" class="btn btn-sm btn-primary ai-insert" data-i="' + i + '"><i class="bi bi-check-lg me-1"></i>' + t('Insert', 'Weka') + '</button>'
                + '</div></div>';
        });
        box.innerHTML = html;
        box._results = results;
    }

    function generate() {
        var action = currentAction();
        var targetEl = current.target ? document.querySelector(current.target) : null;
        var currentText = targetEl ? (targetEl.value || '') : '';
        var instruction = document.getElementById('aiInstruction').value.trim();

        if (action === 'draft' && instruction === '') {
            document.getElementById('aiInstruction').focus();
            return;
        }
        if (action !== 'draft' && currentText.trim() === '') {
            renderResults([]);
            document.getElementById('aiResults').innerHTML =
                '<div class="alert alert-warning small mb-0">' + t('The field is empty — type something first, or use Draft.', 'Sehemu haina maandishi — andika kitu kwanza, au tumia Andika.') + '</div>';
            return;
        }

        var fieldType = current.fieldType;
        if (action === 'improve') fieldType = 'improve';
        else if (action === 'translate') fieldType = 'translate';
        else if (action === 'shorten') fieldType = 'shorten';

        renderLoading();
        document.getElementById('aiGenerateBtn').disabled = true;

        jQuery.post(GEN_URL, {
            module: current.module,
            submodule: current.submodule,
            field_type: fieldType,
            instruction: instruction,
            current_text: (action === 'draft') ? '' : currentText,
            tone: document.getElementById('aiTone').value,
            length: document.getElementById('aiLength').value,
            language: document.getElementById('aiLanguage').value,
            result_count: document.getElementById('aiCount').value
        }, null, 'json')
        .done(function (res) {
            if (res && res.success) renderResults(res.results);
            else document.getElementById('aiResults').innerHTML =
                '<div class="alert alert-danger small mb-0">' + esc(res && res.message ? res.message : t('Could not generate.', 'Imeshindikana.')) + '</div>';
        })
        .fail(function () {
            document.getElementById('aiResults').innerHTML =
                '<div class="alert alert-danger small mb-0">' + t('Network error. Please try again.', 'Tatizo la mtandao. Jaribu tena.') + '</div>';
        })
        .always(function () { document.getElementById('aiGenerateBtn').disabled = false; });
    }

    function bind() {
        // Open from any AI button (delegated — works for dynamically added buttons too)
        jQuery(document).on('click', '.ai-assist-btn', function () {
            current.target    = this.getAttribute('data-target') || null;
            current.module    = this.getAttribute('data-module') || 'general';
            current.submodule = this.getAttribute('data-submodule') || '';
            current.fieldType = this.getAttribute('data-field-type') || 'message';
            document.getElementById('aiInstruction').value = '';
            document.getElementById('aiResults').innerHTML = '';
            jQuery('#aiActionSeg button').removeClass('active').filter('[data-action="draft"]').addClass('active');
            getModal().show();
        });

        jQuery(document).on('click', '#aiActionSeg button', function () {
            jQuery('#aiActionSeg button').removeClass('active');
            jQuery(this).addClass('active');
            var draft = currentAction() === 'draft';
            jQuery('#aiInstructionWrap').toggle(draft || currentAction() === 'translate' || true);
        });

        jQuery(document).on('click', '#aiGenerateBtn', generate);

        jQuery(document).on('click', '.ai-insert', function () {
            var i = +this.getAttribute('data-i');
            var box = document.getElementById('aiResults');
            var txt = (box._results || [])[i] || '';
            if (current.target) {
                var el = document.querySelector(current.target);
                if (el) { el.value = txt; el.dispatchEvent(new Event('input', { bubbles: true })); el.focus(); }
            }
            getModal().hide();
        });

        jQuery(document).on('click', '.ai-copy', function () {
            var i = +this.getAttribute('data-i');
            var box = document.getElementById('aiResults');
            var txt = (box._results || [])[i] || '';
            navigator.clipboard && navigator.clipboard.writeText(txt);
            var btn = this; var orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2 me-1"></i>' + t('Copied', 'Imenakiliwa');
            setTimeout(function () { btn.innerHTML = orig; }, 1500);
        });
    }

    function boot() {
        if (!window.jQuery || !window.bootstrap) { console.warn('VikundiAI: jQuery/Bootstrap required'); return; }
        injectStyles();
        injectModal();
        bind();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();

    window.VikundiAI = { open: function (opts) { jQuery('<button class="ai-assist-btn" data-target="' + (opts.target||'') + '" data-module="' + (opts.module||'general') + '" data-submodule="' + (opts.submodule||'') + '" data-field-type="' + (opts.fieldType||'message') + '">').appendTo('body').trigger('click').remove(); } };
})();
