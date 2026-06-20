<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/ai_service.php';
requireViewPermission('ai_assistant');
require_once HEADER_FILE;

$is_sw     = ($_SESSION['preferred_language'] ?? 'en') === 'sw';
$configured = aiConfigured();
$me        = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$meInitial = strtoupper(substr($me !== '' ? $me : 'U', 0, 1));
?>

<div class="container-fluid mt-4" style="max-width: 920px;">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold text-primary mb-0"><i class="bi bi-robot me-2"></i><?= $is_sw ? 'Ongea na AI' : 'Chat with AI' ?></h4>
            <p class="text-muted small mb-0">
                <?= $is_sw
                    ? 'Msaidizi wa jumla — kuandika, kutafsiri na ushauri. Hauoni data ya kikundi wala haufanyi vitendo.'
                    : 'A general assistant — writing, translation and advice. It cannot see group data or perform actions.' ?>
            </p>
        </div>
        <button class="btn btn-outline-secondary btn-sm rounded-pill px-3" id="aiClearChat" title="<?= $is_sw ? 'Anza upya' : 'New chat' ?>">
            <i class="bi bi-arrow-clockwise me-1"></i><?= $is_sw ? 'Anza Upya' : 'New Chat' ?>
        </button>
    </div>

    <?php if (!$configured): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div>
                <?= $is_sw ? 'AI haijawekwa bado.' : 'AI is not set up yet.' ?>
                <?php if (canEdit('ai_settings') || isAdmin()): ?>
                    <a href="<?= getUrl('ai-settings') ?>" class="alert-link"><?= $is_sw ? 'Nenda kwenye Mipangilio ya AI' : 'Go to AI Settings' ?></a>.
                <?php else: ?>
                    <?= $is_sw ? 'Mwambie msimamizi aiwashe.' : 'Ask an administrator to enable it.' ?>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="ai-chat-window" id="aiChatWindow">
            <!-- Welcome -->
            <div class="ai-msg ai-msg-bot">
                <div class="ai-avatar ai-avatar-bot"><i class="bi bi-robot"></i></div>
                <div class="ai-bubble ai-bubble-bot">
                    <?= $is_sw
                        ? 'Habari! Mimi ni msaidizi wako wa AI. Naweza kukusaidia kuandika ujumbe, kutafsiri kati ya Kiingereza na Kiswahili, au kutoa ushauri. Niambie unahitaji nini.'
                        : 'Hello! I\'m your AI assistant. I can help you draft messages, translate between English and Swahili, or give advice. Tell me what you need.' ?>
                </div>
            </div>
        </div>

        <!-- Suggestion chips -->
        <div class="px-3 pt-2 d-flex flex-wrap gap-2" id="aiSuggestions">
            <?php
            $chips = $is_sw
                ? ['Andika ujumbe wa kuwakumbusha wanachama mchango', 'Tafsiri kwa Kiingereza', 'Nipe maneno ya shukrani kwa wanachama', 'Andika tangazo la mkutano']
                : ['Draft a reminder about monthly contributions', 'Translate this to Swahili', 'Write a thank-you message to members', 'Draft a meeting announcement'];
            foreach ($chips as $c): ?>
                <button type="button" class="ai-chip" data-text="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Input -->
        <div class="ai-chat-input p-3">
            <div class="d-flex align-items-end gap-2">
                <textarea id="aiChatInput" class="form-control" rows="1"
                          placeholder="<?= $is_sw ? 'Andika ujumbe wako... (Enter kutuma)' : 'Type your message... (Enter to send)' ?>"></textarea>
                <button class="btn btn-primary rounded-circle flex-shrink-0" id="aiChatSend" style="width:44px;height:44px;" title="<?= $is_sw ? 'Tuma' : 'Send' ?>">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
            <div class="form-text small mt-1"><i class="bi bi-shield-check me-1"></i><?= $is_sw ? 'Hauoni data ya kikundi. Thibitisha taarifa muhimu.' : 'No access to group data. Verify important details.' ?></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.ai-chat-window{height:52vh;min-height:340px;overflow-y:auto;padding:20px;background:#f7f8fa;}
.ai-msg{display:flex;gap:10px;margin-bottom:16px;align-items:flex-start;}
.ai-msg-user{flex-direction:row-reverse;}
.ai-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;flex-shrink:0;font-size:.85rem;}
.ai-avatar-bot{background:linear-gradient(135deg,#6f42c1,#0d6efd);}
.ai-avatar-user{background:linear-gradient(135deg,#0d6efd,#0a58ca);}
.ai-bubble{max-width:78%;padding:10px 14px;border-radius:14px;font-size:.92rem;line-height:1.5;white-space:pre-wrap;word-wrap:break-word;box-shadow:0 1px 2px rgba(0,0,0,.06);}
.ai-bubble-bot{background:#fff;color:#212529;border-top-left-radius:4px;}
.ai-bubble-user{background:linear-gradient(135deg,#0d6efd,#0a58ca);color:#fff;border-top-right-radius:4px;}
.ai-chip{border:1px solid #cfe2ff;background:#fff;color:#0d6efd;border-radius:18px;padding:5px 12px;font-size:.78rem;transition:all .15s;}
.ai-chip:hover{background:#0d6efd;color:#fff;}
.ai-chat-input{border-top:1px solid #eef0f2;background:#fff;}
.ai-chat-input textarea{resize:none;border-radius:14px;max-height:140px;}
.ai-typing{display:inline-flex;gap:4px;align-items:center;}
.ai-typing span{width:8px;height:8px;border-radius:50%;background:#adb5bd;animation:aiTy 1.2s infinite ease-in-out both;}
.ai-typing span:nth-child(2){animation-delay:-1.0s;}
.ai-typing span:nth-child(3){animation-delay:-0.8s;}
@keyframes aiTy{0%,80%,100%{transform:scale(0);}40%{transform:scale(1);}}
</style>

<?php if ($configured): ?>
<script>
(function(){
    const URL_CHAT = '<?= getUrl('api/ai/chat') ?>';
    const IS_SW = <?= $is_sw ? 'true' : 'false' ?>;
    const ME_INITIAL = <?= json_encode($meInitial) ?>;
    const win = document.getElementById('aiChatWindow');
    const input = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('aiChatSend');
    let history = [];   // {role, content}
    let busy = false;

    function esc(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
    function scroll(){ win.scrollTop = win.scrollHeight; }

    function addMsg(role, text){
        const isUser = role === 'user';
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ' + (isUser ? 'ai-msg-user' : 'ai-msg-bot');
        wrap.innerHTML =
            '<div class="ai-avatar ' + (isUser ? 'ai-avatar-user' : 'ai-avatar-bot') + '">' +
                (isUser ? esc(ME_INITIAL) : '<i class="bi bi-robot"></i>') + '</div>' +
            '<div class="ai-bubble ' + (isUser ? 'ai-bubble-user' : 'ai-bubble-bot') + '">' + esc(text) + '</div>';
        win.appendChild(wrap);
        scroll();
        return wrap;
    }

    function addTyping(){
        const wrap = document.createElement('div');
        wrap.className = 'ai-msg ai-msg-bot';
        wrap.id = 'aiTypingRow';
        wrap.innerHTML = '<div class="ai-avatar ai-avatar-bot"><i class="bi bi-robot"></i></div>' +
            '<div class="ai-bubble ai-bubble-bot"><span class="ai-typing"><span></span><span></span><span></span></span></div>';
        win.appendChild(wrap); scroll();
    }
    function removeTyping(){ const t = document.getElementById('aiTypingRow'); if(t) t.remove(); }

    function send(text){
        text = (text || input.value).trim();
        if (text === '' || busy) return;
        busy = true;
        input.value = ''; input.style.height = 'auto';
        document.getElementById('aiSuggestions').style.display = 'none';
        addMsg('user', text);
        history.push({role:'user', content:text});
        addTyping();
        sendBtn.disabled = true;

        jQuery.post(URL_CHAT, { message: text, history: JSON.stringify(history.slice(0, -1)) }, null, 'json')
            .done(function(res){
                removeTyping();
                if (res && res.success){
                    addMsg('assistant', res.reply);
                    history.push({role:'assistant', content:res.reply});
                } else {
                    addMsg('assistant', (res && res.message) ? res.message : (IS_SW ? 'Samahani, imeshindikana.' : 'Sorry, that failed.'));
                }
            })
            .fail(function(){ removeTyping(); addMsg('assistant', IS_SW ? 'Tatizo la mtandao. Jaribu tena.' : 'Network error. Please try again.'); })
            .always(function(){ busy = false; sendBtn.disabled = false; input.focus(); });
    }

    sendBtn.addEventListener('click', function(){ send(); });
    input.addEventListener('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); send(); }
    });
    input.addEventListener('input', function(){ this.style.height='auto'; this.style.height=Math.min(this.scrollHeight,140)+'px'; });

    document.querySelectorAll('.ai-chip').forEach(function(c){
        c.addEventListener('click', function(){ send(this.getAttribute('data-text')); });
    });

    document.getElementById('aiClearChat').addEventListener('click', function(){
        history = [];
        win.querySelectorAll('.ai-msg').forEach(function(m, i){ if (i>0) m.remove(); });
        document.getElementById('aiSuggestions').style.display = 'flex';
        input.focus();
    });

    input.focus();
})();
</script>
<?php endif; ?>

<?php
require_once FOOTER_FILE;
echo ob_get_clean();
