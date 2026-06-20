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

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>