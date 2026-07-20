<?php
// Close the main container opened in header.php
echo '</div>'; 

echo '<footer class="text-center py-3 text-muted d-print-none">';
$footer_text = ($_SESSION['preferred_language'] ?? 'en') === 'sw' 
    ? '© '.date('Y').' Mfumo wa Usimamizi wa Vikundi. Haki Zote Zimehifadhiwa.' 
    : '© '.date('Y').' Group Management System. All Rights Reserved.';
echo '<p>' . $footer_text . '</p>';
echo '</footer>';
?>

<!-- Bootstrap JS (self-hosted so navbar dropdowns work even if the CDN is blocked/flaky) -->
<script src="<?= function_exists('getUrl') ? getUrl('assets/js/bootstrap.bundle.min.js') : '/assets/js/bootstrap.bundle.min.js' ?>"></script>

<!-- Global Modal Close on Success (Only for ACTIONS, not for DATA FETCHING) -->
<script>
$(document).ajaxSuccess(function(event, xhr, settings, data) {
    // Close the open modal on POST success UNLESS the call opted out.
    // Pages that handle modal progression internally (wizards, multi-step
    // forms) pass skipModalClose: true in their $.ajax() options so the
    // global handler leaves the modal alone.
    if (settings.type === "POST" && data && data.success === true && !settings.skipModalClose) {
        if (!settings.url.includes('get_member_dependents') && !settings.url.includes('search_')) {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modalInstance = bootstrap.Modal.getInstance(openModal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        }
    }
});
</script>

<!-- Global fix: Bootstrap dropdowns clipped inside overflow containers.
     Row-action menus live inside .table-responsive (overflow-x:auto) and often a
     .card.overflow-hidden. Either wrapper clips the menu, forcing a scrollbar so
     the lower items ("Print", "Delete"...) are hidden until you scroll. While a
     dropdown is open we let its clipping ancestors overflow, then restore on close. -->
<script>
(function () {
    var KEY = '_vkClipFix';
    function clippingAncestors(el) {
        var out = [], n = el.parentElement;
        while (n && n !== document.body) { out.push(n); n = n.parentElement; }
        return out;
    }
    document.addEventListener('show.bs.dropdown', function (e) {
        var toggle = e.target.closest && e.target.closest('[data-bs-toggle="dropdown"]');
        if (!toggle) return;
        var saved = [];
        clippingAncestors(toggle).forEach(function (node) {
            var ov = getComputedStyle(node).overflow;
            if (ov && ov !== 'visible') {
                // Snapshot the exact inline style so we can restore it verbatim on close.
                saved.push([node, node.getAttribute('style')]);
                // 'important' priority is required: .overflow-hidden is overflow:hidden !important,
                // which a plain inline value cannot override. Inline !important wins.
                node.style.setProperty('overflow', 'visible', 'important');
            }
        });
        toggle[KEY] = saved;
    });
    document.addEventListener('hide.bs.dropdown', function (e) {
        var toggle = e.target.closest && e.target.closest('[data-bs-toggle="dropdown"]');
        if (!toggle || !toggle[KEY]) return;
        toggle[KEY].forEach(function (rec) {
            if (rec[1] === null) { rec[0].removeAttribute('style'); }
            else { rec[0].setAttribute('style', rec[1]); }
        });
        toggle[KEY] = null;
    });
})();
</script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>