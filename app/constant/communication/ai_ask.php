<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
requireViewPermission('ai_ask_data');
require_once HEADER_FILE;

$is_sw      = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$configured = aiConfigured();
?>

<div class="container-fluid mt-4" style="max-width: 920px;">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-graph-up-arrow me-2"></i><?= $is_sw ? 'Uliza Vikundi' : 'Ask Vikundi' ?></h4>
            <p class="text-muted small mb-0">
                <?= $is_sw
                    ? 'Majibu kutoka kwa data halisi ya kikundi — kusoma tu, haibadilishi chochote.'
                    : 'Answers from your real group data — read-only, it changes nothing.' ?>
            </p>
        </div>
        <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="aiClearAsk">
            <i class="bi bi-arrow-clockwise me-1"></i><?= $is_sw ? 'Anza Upya' : 'New' ?>
        </button>
    </div>

    <?php if (!$configured): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <?= $is_sw ? 'AI haijawekwa bado.' : 'AI is not set up yet.' ?>
                <?php if (canEdit('ai_settings') || isAdmin()): ?>
                    <a href="<?= getUrl('ai-settings') ?>" class="alert-link"><?= $is_sw ? 'Mipangilio ya AI' : 'AI Settings' ?></a>.
                <?php else: ?>
                    <?= $is_sw ? 'Mwambie msimamizi aiwashe.' : 'Ask an administrator to enable it.' ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="ai-ask-window" id="aiAskWindow">
            <div class="ai-msg ai-msg-bot">
                <div class="ai-avatar ai-avatar-bot"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="ai-bubble ai-bubble-bot">
                    <?= $is_sw
                        ? 'Niulize swali kuhusu data ya kikundi — kwa mfano akiba, wachangiaji, wanachama, au vinavyosubiri idhini.'
                        : 'Ask me about your group\'s data — for example savings, contributors, members, or what is awaiting approval.' ?>
                </div>
            </div>
        </div>

        <div class="px-3 pt-2 d-flex flex-wrap gap-2" id="aiAskSuggestions">
            <?php
            $chips = $is_sw
                ? ['Jumla ya akiba yetu ni kiasi gani?', 'Wachangiaji 5 bora ni nani?', 'Tuna wanachama wangapi?', 'Nini kinasubiri idhini?', 'Mchango wa mwezi ni kiasi gani?']
                : ['What is our total savings?', 'Who are the top 5 contributors?', 'How many members do we have?', 'What is awaiting approval?', 'How much is the monthly contribution?'];
            foreach ($chips as $c): ?>
                <button type="button" class="ai-chip" data-text="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="ai-ask-input p-3">
            <div class="d-flex align-items-end gap-2">
                <textarea id="aiAskInput" class="form-control" rows="1"
                          placeholder="<?= $is_sw ? 'Andika swali lako... (Enter kutuma)' : 'Type your question... (Enter to send)' ?>"></textarea>
                <button class="btn btn-primary rounded-circle flex-shrink-0" id="aiAskSend" style="width:44px;height:44px;">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
            <div class="form-text small mt-1"><i class="bi bi-shield-check me-1"></i><?= $is_sw ? 'Kusoma tu — haifanyi mabadiliko. Thibitisha takwimu muhimu.' : 'Read-only — it makes no changes. Verify important figures.' ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.ai-ask-window{height:52vh;min-height:340px;overflow-y:auto;padding:20px;background:#f7f8fa;}
.ai-msg{display:flex;gap:10px;margin-bottom:16px;align-items:flex-start;}
.ai-msg-user{flex-direction:row-reverse;}
.ai-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0;font-size:.85rem;}
.ai-avatar-bot{background:linear-gradient(135deg,#198754,#0d6efd);}
.ai-avatar-user{background:linear-gradient(135deg,#0d6efd,#0a58ca);}
.ai-bubble{max-width:78%;padding:10px 14px;border-radius:14px;font-size:.92rem;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px 2px rgba(0,0,0,.06);}
.ai-bubble-bot{background:#fff;color:#212529;border-top-left-radius:4px;}
.ai-bubble-user{background:linear-gradient(135deg,#0d6efd,#0a58ca);color:#fff;border-top-right-radius:4px;}
.ai-source{font-size:.72rem;color:#6c757d;margin-top:6px;}
.ai-source .badge{background:#e7f1ff;color:#0d6efd;font-weight:600;margin-right:4px;}
.ai-chip{border:1px solid #badbcc;background:#fff;color:#198754;border-radius:18px;padding:5px 12px;font-size:.78rem;transition:all .15s;}
.ai-chip:hover{background:#198754;color:#fff;}
.ai-ask-input{border-top:1px solid #eef0f2;background:#fff;}
.ai-ask-input textarea{resize:none;border-radius:14px;max-height:140px;}
.ai-typing{display:inline-flex;gap:4px;align-items:center;}
.ai-typing span{width:8px;height:8px;border-radius:50%;background:#adb5bd;animation:aiTy 1.2s infinite ease-in-out both;}
.ai-typing span:nth-child(2){animation-delay:-1.0s;}
.ai-typing span:nth-child(3){animation-delay:-0.8s;}
@keyframes aiTy{0%,80%,100%{transform:scale(0);}40%{transform:scale(1);}}
</style>

<?php if ($configured): ?>
<script>
(function(){
    const URL_ASK = '<?= getUrl('api/ai/ask') ?>';
    const IS_SW = <?= $is_sw ? 'true' : 'false' ?>;
    const win = document.getElementById('aiAskWindow');
    const input = document.getElementById('aiAskInput');
    const sendBtn = document.getElementById('aiAskSend');
    let busy = false;

    function esc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function scroll(){ win.scrollTop = win.scrollHeight; }

    function addMsg(role, text, used){
        const isUser = role === 'user';
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ' + (isUser ? 'ai-msg-user' : 'ai-msg-bot');
        let src = '';
        if (!isUser && used && used.length){
            src = '<div class="ai-source"><i class="bi bi-database me-1"></i>' + (IS_SW ? 'Imetumia: ' : 'Used: ') +
                  used.map(function(u){ return '<span class="badge">' + esc(u) + '</span>'; }).join('') + '</div>';
        }
        wrap.innerHTML =
            '<div class="ai-avatar ' + (isUser ? 'ai-avatar-user' : 'ai-avatar-bot') + '">' +
                (isUser ? '<i class="bi bi-person"></i>' : '<i class="bi bi-graph-up-arrow"></i>') + '</div>' +
            '<div><div class="ai-bubble ' + (isUser ? 'ai-bubble-user' : 'ai-bubble-bot') + '">' + esc(text) + '</div>' + src + '</div>';
        win.appendChild(wrap); scroll();
    }

    function addTyping(){
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ai-msg-bot'; wrap.id = 'aiAskTyping';
        wrap.innerHTML = '<div class="ai-avatar ai-avatar-bot"><i class="bi bi-graph-up-arrow"></i></div>' +
            '<div class="ai-bubble ai-bubble-bot"><span class="ai-typing"><span></span><span></span><span></span></span></div>';
        win.appendChild(wrap); scroll();
    }
    function removeTyping(){ const t = document.getElementById('aiAskTyping'); if(t) t.remove(); }

    function ask(text){
        text = (text || input.value).trim();
        if (text === '' || busy) return;
        busy = true; input.value=''; input.style.height='auto';
        document.getElementById('aiAskSuggestions').style.display = 'none';
        addMsg('user', text);
        addTyping(); sendBtn.disabled = true;

        jQuery.post(URL_ASK, { question: text }, null, 'json')
            .done(function(res){
                removeTyping();
                if (res && res.success) addMsg('assistant', res.answer, res.used);
                else addMsg('assistant', (res && res.message) ? res.message : (IS_SW ? 'Samahani, imeshindikana.' : 'Sorry, that failed.'));
            })
            .fail(function(){ removeTyping(); addMsg('assistant', IS_SW ? 'Tatizo la mtandao. Jaribu tena.' : 'Network error. Please try again.'); })
            .always(function(){ busy=false; sendBtn.disabled=false; input.focus(); });
    }

    sendBtn.addEventListener('click', function(){ ask(); });
    input.addEventListener('keydown', function(e){ if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); ask(); }});
    input.addEventListener('input', function(){ this.style.height='auto'; this.style.height=Math.min(this.scrollHeight,140)+'px'; });
    document.querySelectorAll('.ai-chip').forEach(function(c){ c.addEventListener('click', function(){ ask(this.getAttribute('data-text')); }); });
    document.getElementById('aiClearAsk').addEventListener('click', function(){
        win.querySelectorAll('.ai-msg').forEach(function(m,i){ if(i>0) m.remove(); });
        document.getElementById('aiAskSuggestions').style.display = 'flex'; input.focus();
    });
    input.focus();
})();
</script>
<?php endif; ?>

<?php
require_once FOOTER_FILE;
echo ob_get_clean();
