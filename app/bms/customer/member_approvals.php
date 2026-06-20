<?php
// app/bms/customer/member_approvals.php
ob_start();
require_once HEADER_FILE;

$isSwahili = ($_SESSION['preferred_language'] ?? 'en') === 'sw';

// Allowed roles
$allowed_roles = ['Admin', 'Secretary', 'Katibu'];
if (!in_array($user_role, $allowed_roles)) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

// Fetch count of pending members for the stats badge
$stmt_count = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
$pending_count = $stmt_count->fetchColumn();
?>

<div class="container-fluid px-0 px-md-3">
    <div class="py-3 border-bottom mb-4 px-3 px-md-0">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h4 class="mb-0 fw-bold text-primary"><i class="bi bi-person-check-fill me-2"></i> <?= $isSwahili ? 'Uhakiki wa Wanachama Wapya' : 'New Member Verification' ?></h4>
                <p class="text-muted small mb-0"><?= $isSwahili ? 'Maombi yanayosubiri idhini ya uongozi' : 'Applications pending leadership approval' ?></p>
            </div>
            <div class="mt-2 mt-md-0 text-md-end">
                <span class="badge text-dark px-3 py-2 rounded-pill shadow-sm" style="background-color: #d1e7dd !important;">
                    <i class="bi bi-clock-history me-1"></i> <?= $isSwahili ? 'Maombi ' . $pending_count . ' Yanasubiri' : $pending_count . ' Pending' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Unified Controls Container -->
    <div class="row mb-4 px-3 px-md-0">
        <div class="col-12 d-flex flex-column flex-md-row align-items-md-center gap-3">
            <!-- Left Side: Action Tools -->
            <div class="d-flex align-items-center gap-2 overflow-auto no-scrollbar" id="action-tools" style="flex-wrap: nowrap !important; white-space: nowrap !important;"></div>

            <!-- Right Side: Search Box -->
            <div id="custom-search" class="flex-grow-1" style="max-width: 500px;"></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 15px;">
        <div class="card-body p-2 p-md-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 w-100" id="approvalsTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-3" style="width: 50px;">S/NO</th>
                            <th style="width: 120px;"><?= $isSwahili ? 'Tarehe' : 'Date' ?></th>
                            <th><?= $isSwahili ? 'Jina Kamili' : 'Full Name' ?></th>
                            <th><?= $isSwahili ? 'Mawasiliano' : 'Contact' ?></th>
                            <th class="text-end" style="width: 150px;"><?= $isSwahili ? 'Kiingilio' : 'Entrance Fee' ?></th>
                            <th style="width: 120px;"><?= $isSwahili ? 'Malipo / Slip' : 'Payment / Slip' ?></th>
                            <th class="text-end pe-3" style="width: 100px;"><?= $isSwahili ? 'Hatua' : 'Action' ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .no-scrollbar::-webkit-scrollbar { display: none; }
    .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    .dt-buttons { display: flex !important; gap: 8px !important; margin: 0 !important; }
    .dt-buttons .btn, .length-menu-wrapper { background-color: #fff !important; border: 1px solid #dee2e6 !important; border-radius: 8px !important; height: 35px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
    .dt-buttons .btn { color: #495057 !important; font-size: 0.75rem !important; padding: 0 12px !important; min-width: 65px !important; font-weight: 500 !important; }
    .length-menu-wrapper { overflow: hidden; }
    .length-menu-icon { padding: 0 8px; background: #f1f3f5; border-right: 1px solid #dee2e6; height: 100%; display: flex; align-items: center; color: #6c757d; font-size: 0.75rem; }
    .dataTables_length select { border: none !important; outline: none !important; padding: 0 5px !important; font-size: 0.8rem !important; font-weight: 600; cursor: pointer; background: transparent !important; appearance: none !important; -webkit-appearance: none !important; -moz-appearance: none !important; width: 50px !important; text-align: center; }
    .dataTables_length select::-ms-expand { display: none; }
    .dataTables_filter { text-align: left !important; width: 100% !important; }
    .dataTables_filter input { border-radius: 12px !important; padding: 0.5rem 1.2rem !important; width: 100% !important; background-color: #f8f9fa !important; border: 1px solid #e9ecef !important; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05) !important; font-size: 0.9rem; }
    .dataTables_filter input:focus { background-color: #fff !important; border-color: #0d6efd !important; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.1) !important; outline: none !important; }
    #approvalsTable { border-collapse: collapse !important; margin-top: 10px !important; }
    @media (max-width: 768px) {
        .dt-buttons .btn, .length-menu-wrapper { height: 30px !important; }
        .dt-buttons .btn { font-size: 0.65rem !important; padding: 0 8px !important; min-width: 50px !important; }
        .length-menu-icon { padding: 0 6px !important; font-size: 0.65rem !important; }
        .dataTables_length select { font-size: 0.7rem !important; width: 42px !important; padding: 0 5px !important; }
        .dataTables_filter input { font-size: 0.8rem !important; padding: 0.4rem 1rem !important; }
        #custom-search { max-width: none !important; }
        #approvalsTable { font-size: 0.7rem !important; }
    }
</style>

<script>
$(document).ready(function() {
    var table = $('#approvalsTable').DataTable({
        serverSide: true,
        processing: true,
        responsive: false,
        ajax: { url: '<?= getUrl('actions/fetch_pending_members') ?>', type: 'POST' },
        columns: [
            { data: 'sno', className: 'ps-3 fw-bold text-muted' },
            { data: 'date', className: 'small' },
            { data: 'name' },
            { data: 'contact', className: 'small' },
            { data: 'amount', orderable: false, className: 'text-end' },
            { data: 'slip', orderable: false, className: 'text-center' },
            { data: 'action', orderable: false, className: 'pe-3 text-end' }
        ],
        dom: 'Blfrtip',
        buttons: [
            {
                extend: 'print',
                text: '<i class="bi bi-printer me-1"></i> <?= $isSwahili ? 'Printi' : 'Print' ?>',
                className: 'btn btn-sm btn-white',
                title: '',
                exportOptions: { columns: ':not(:last-child)' },
                customize: function(win) {
                    $(win.document.head).append(`<style>
                        @page { margin: 10mm 8mm 16mm 8mm; }
                        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 11pt; color: #1a252f; line-height: 1.5; padding: 20px 20px 0 20px; }
                        table { width: 100% !important; border-collapse: collapse !important; }
                        table th, table td { border: 1px solid #000 !important; padding: 6px !important; }
                        table th { background-color: #f2f2f2 !important; -webkit-print-color-adjust: exact; }
                        .print-footer { position: fixed; bottom: 0; left: 0; right: 0; height: 16px; background: #fff; border-top: 1px solid #dee2e6; padding: 0 22px; text-align: center; display: flex; flex-direction: column; justify-content: flex-end; }
                        .print-footer p { margin: 0; font-size: 7px; color: #2c3e50; line-height: 1; }
                        .print-footer .brand { font-size: 7px; color: #3498db; font-weight: 600; }
                        tfoot.print-spacer { display: table-footer-group; }
                        tfoot.print-spacer td { height: 12px !important; border: none !important; }
                    </style><?php echo PrintHeader::popupCss(); ?>`);
                    $(win.document.body).find('h1').remove();
                    $(win.document.body).prepend(`<div class="vk-print-header">
                        <img src="<?= !empty($logo_base64) ? $logo_base64 : '/assets/images/' . ($group_logo ?? 'logo1.png') ?>" alt="Logo" class="vk-ph-logo">
                        <div class="vk-ph-org"><?= htmlspecialchars($group_name ?? '') ?></div>
                        <div class="vk-ph-sys">VICOBA Group Management System</div>
                        <div class="vk-ph-title"><?= $isSwahili ? 'UHAKIKI WA WANACHAMA WAPYA' : 'NEW MEMBER VERIFICATION' ?></div>
                        <div class="vk-ph-rule"></div>
                    </div>`);
                    const now = new Date();
                    const _d = now.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
                    const _t = now.toLocaleTimeString('en-GB', { hour12: false });
                    const _sw = <?= $isSwahili ? 'true' : 'false' ?>;
                    const _by = _sw ? 'Nyaraka hii imechapishwa na' : 'This document was Printed by';
                    const _on = _sw ? 'mnamo' : 'on';
                    const _at = _d + ' ' + (_sw ? 'saa' : 'at') + ' ' + _t;
                    $(win.document.body).append(`<div class="print-footer">
                        <p>${_by} <strong><?= htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? '')) ?></strong> &mdash; <strong><?= htmlspecialchars(ucfirst($user_role ?? 'User')) ?></strong> ${_on} ${_at}</p>
                        <p class="brand">Powered By BJP Technologies &copy; <?= date('Y') ?>, All Rights Reserved</p>
                    </div>`);
                    $(win.document.body).find('table').append('<tfoot class="print-spacer"><tr><td colspan="20">&nbsp;</td></tr></tfoot>');
                }
            },
            { extend: 'excel', text: '<i class="bi bi-file-earmark-excel me-1"></i> Excel', className: 'btn btn-sm btn-white' }
        ],
        language: {
            search: "",
            searchPlaceholder: "<?= $isSwahili ? 'Tafuta Maombi...' : 'Search Applications...' ?>",
            lengthMenu: '<div class="length-menu-wrapper"><div class="length-menu-icon"><i class="bi bi-list-ul"></i></div> _MENU_</div>',
            info: "<?= $isSwahili ? '_START_ - _END_ ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_' ?>",
            paginate: { 
                previous: "<?= $isSwahili ? 'Nyuma' : 'Previous' ?>", 
                next: "<?= $isSwahili ? 'Mbele' : 'Next' ?>" 
            },
            processing: '<div class="spinner-border spinner-border-sm text-primary"></div>'
        },
        order: [[1, 'desc']],
        pageLength: 25,
        initComplete: function() {
            $('.dataTables_filter').appendTo('#custom-search');
            $('.dt-buttons').appendTo('#action-tools');
            $('.dataTables_length').appendTo('#action-tools');
            $('.dataTables_length label').contents().filter(function() { return this.nodeType === 3; }).remove();
        }
    });
});

function approveMember(userId) {
    const isSwahili = <?= $isSwahili ? 'true' : 'false' ?>;
    Swal.fire({
        title: isSwahili ? 'Je, una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Unataka kumkubali mwanachama?' : 'Do you want to approve this member?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: isSwahili ? 'Ndiyo, mkubali!' : 'Yes, approve!',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/approve_member') ?>',
                type: 'POST',
                data: { user_id: userId, action: 'approve' },
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        Swal.fire({ 
                            icon: 'success', 
                            title: isSwahili ? 'Tayari!' : 'Done!', 
                            text: r.message, 
                            timer: 1500, 
                            showConfirmButton: false 
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                }
            });
        }
    });
}

function rejectMember(userId) {
    const isSwahili = <?= $isSwahili ? 'true' : 'false' ?>;
    Swal.fire({
        title: isSwahili ? 'Je, una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Unataka kumkataa mwanachama?' : 'Do you want to reject this application?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: isSwahili ? 'Ndiyo, mkatae!' : 'Yes, reject!',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/approve_member') ?>',
                type: 'POST',
                data: { user_id: userId, action: 'reject' },
                dataType: 'json',
                success: function(r) {
                    if (r.success) {
                        Swal.fire({ 
                            icon: 'success', 
                            title: isSwahili ? 'Tayari!' : 'Done!', 
                            text: r.message, 
                            timer: 1500, 
                            showConfirmButton: false 
                        }).then(() => location.reload());
                    }
                }
            });
        }
    });
}
</script>

<?php include PRINT_FOOTER_CSS_FILE; include PRINT_FOOTER_FILE; ?>
<?php
$content = ob_get_clean();
echo $content;
require_once FOOTER_FILE;
?>
