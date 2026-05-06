<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';

// Check admin permissions
// Permissions are automatically enforced by header.php




// Fetch available roles from database (VICoBA specific)
$stmt = $pdo->query("SELECT * FROM roles ORDER BY role_name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Role Translation Map
$roleMap = [
    'admin' => ['en' => 'Admin (Super)', 'sw' => 'Msimamizi Mkuu'],
    'super admin' => ['en' => 'Super Admin', 'sw' => 'Msimamizi Mkuu'],
    'mwenyekiti' => ['en' => 'Chairman', 'sw' => 'Mwenyekiti'],
    'chairman' => ['en' => 'Chairman', 'sw' => 'Mwenyekiti'],
    'katibu' => ['en' => 'Secretary', 'sw' => 'Katibu'],
    'secretary' => ['en' => 'Secretary', 'sw' => 'Katibu'],
    'mhazini' => ['en' => 'Treasurer', 'sw' => 'Mhazini'],
    'treasurer' => ['en' => 'Treasurer', 'sw' => 'Mhazini'],
    'mjumbe' => ['en' => 'Board Member', 'sw' => 'Mjumbe'],
    'board member' => ['en' => 'Board Member', 'sw' => 'Mjumbe'],
    'member' => ['en' => 'Member', 'sw' => 'Mwanachama'],
    'mwanachama' => ['en' => 'Member', 'sw' => 'Mwanachama'],
    'member (mwanachama)' => ['en' => 'Member', 'sw' => 'Mwanachama'],
    'loan officer' => ['en' => 'Loan Officer', 'sw' => 'Afisa Mkopo']
];

$lang = $_SESSION['preferred_language'] ?? 'en';
?>

<div class="container-fluid mt-4 px-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="text-primary fw-bold"><i class="bi bi-people-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Usimamizi wa Watumiaji' : 'User Management' ?></h2>
            <p class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Simamia watumiaji wa mfumo na mamlaka yao' : 'Manage system users and their permissions' ?></p>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Watumiaji Wote' : 'All Users' ?></h5>
            <a href="add_user.php" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm">
                <i class="bi bi-person-plus me-1"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ongeza Mtumiaji' : 'Add New User' ?>
            </a>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive d-none d-md-block d-print-block">
                <table id="usersTable" class="table table-striped table-hover" style="width:100%">
                    <thead>
                        <tr>
                            <th>S/NO</th>
                            <th>ID</th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Username' : 'Username' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina Kamili' : 'Full Name' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Barua Pepe' : 'Email' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Wadhifa' : 'Role' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali' : 'Status' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwisho Kuingia' : 'Last Login' ?></th>
                            <th><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hatua' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
            <!-- ═══ CARD VIEW — Mobile Only ═══ -->
            <div class="p-3 d-md-none d-print-none" id="usersCardsWrapper"></div>
        </div>
    </div>
</div>

<!-- Role Assignment Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalLabel"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Badilisha Nafasi' : 'Assign Role' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="assignUserId" name="user_id">
                    <div class="mb-3">
                        <label for="userRole" class="form-label"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Chagua Nafasi' : 'Select Role' ?></label>
                        <select class="form-select" id="userRole" name="role_id" required>
                            <option value=""><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '-- Chagua hapa --' : '-- Select a role --' ?></option>
                            <?php foreach ($roles as $role): 
                                $r_name = strtolower($role['role_name']);
                                $display = $role['role_name'];
                                if (isset($roleMap[$r_name])) {
                                    $display = $roleMap[$r_name][$lang];
                                } elseif (str_contains($r_name, 'member')) {
                                    $display = $roleMap['member'][$lang];
                                }
                            ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($display) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ghairi' : 'Cancel' ?></button>
                <button type="button" class="btn btn-primary" id="saveRole"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hifadhi' : 'Save Changes' ?></button>
            </div>
        </div>
    </div>
</div>

<?php include("footer.php"); ?>



<style>
/* Compact dropdown styles */
.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 150px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.action-column {
    width: 80px;
    min-width: 80px;
    max-width: 80px;
}

/* Reduce table padding for more compact rows */
.table td, .table th {
    padding: 0.5rem;
}

/* Ensure action buttons stay on one line */
.action-buttons {
    white-space: nowrap;
}
</style>

<script>
$(document).ready(function() {
    var isSw = <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'true' : 'false' ?>;
    
    // Initialize DataTable with server-side processing
    var table = $('#usersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: 'ajax/get_users.php',
            type: 'GET',
            dataSrc: function(json) {
                console.log("Server response:", json); // Debugging
                if(json && json.data) {
                    return json.data;
                } else {
                    console.error("Unexpected data format:", json);
                    return [];
                }
            },
            error: function(xhr, error, thrown) {
                console.error("AJAX error:", xhr, error, thrown);
                showAlert('Error loading user data', 'danger');
            }
        },
        columns: [
            { 
                data: null,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                },
                orderable: false
            },
            { data: 'user_id' },
            { data: 'username' },
            { data: 'full_name' },
            { data: 'email' },
            { 
                data: 'role_name',
                render: function(data, type, row) {
                    if(!data) return '<span class="badge bg-secondary"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna Nafasi' : 'No Role' ?></span>';
                    
                    var isSw = <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'true' : 'false' ?>;
                    var roleDisplay = data;
                    var lowData = data.toLowerCase();
                    
                    // Unified translation mapping logic for JS
                    var jsRoleMap = {
                        'admin': {en: 'Admin', sw: 'Msimamizi Mkuu'},
                        'mwenyekiti': {en: 'Chairman', sw: 'Mwenyekiti'},
                        'katibu': {en: 'Secretary', sw: 'Katibu'},
                        'secretary': {en: 'Secretary', sw: 'Katibu'},
                        'mhazini': {en: 'Treasurer', sw: 'Mhazini'},
                        'treasurer': {en: 'Treasurer', sw: 'Mhazini'},
                        'mjumbe': {en: 'Board Member', sw: 'Mjumbe'},
                        'member': {en: 'Member', sw: 'Mwanachama'},
                        'mwanachama': {en: 'Member', sw: 'Mwanachama'},
                        'loan officer': {en: 'Loan Officer', sw: 'Afisa Mkopo'}
                    };

                    for (var key in jsRoleMap) {
                        if (lowData.includes(key)) {
                            roleDisplay = isSw ? jsRoleMap[key].sw : jsRoleMap[key].en;
                            break;
                        }
                    }

                    var roleClass = '';
                    if (lowData.includes('admin')) roleClass = 'danger';
                    else if (lowData.includes('chairman') || lowData.includes('mwenyekiti')) roleClass = 'success';
                    else if (lowData.includes('katibu') || lowData.includes('secretary')) roleClass = 'info';
                    else if (lowData.includes('treasurer') || lowData.includes('mhazini')) roleClass = 'warning';
                    else roleClass = 'primary';

                    return '<span class="badge bg-' + roleClass + '">' + roleDisplay + '</span>';
                }
            },
            { 
                data: 'is_active',
                render: function(data) {
                    return data == 1 
                        ? '<span class="badge bg-success">Active</span>' 
                        : '<span class="badge bg-secondary">Inactive</span>';
                }
            },
            { 
                data: 'last_login',
                render: function(data) {
                    if(!data || data === 'Never') {
                        return '<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hajawahi' : 'Never' ?>';
                    }
                    try {
                        // Replace space with T for ISO format compatibility (YYYY-MM-DD HH:MM:SS -> YYYY-MM-DDTHH:MM:SS)
                        var isoDate = data.replace(' ', 'T');
                        var date = new Date(isoDate);
                        if (isNaN(date.getTime())) return data; // Return raw if still invalid
                        
                        return date.toLocaleString('<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'sw-TZ' : 'en-US' ?>', {
                            year: 'numeric',
                            month: 'short',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                    } catch(e) {
                        return data;
                    }
                }
            },
            {
                data: null,
                className: 'action-column',
                render: function(data, type, row) {
                    var currentUserId = parseInt(<?= $_SESSION['user_id'] ?? 0 ?>);
                    var targetUserId = row.user_id ? parseInt(row.user_id) : 0;
                    var isCurrentUser = (targetUserId === currentUserId && targetUserId !== 0);
                    
                    var dropdownHtml = '<div class="dropdown action-dropdown">' +
                        '<button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">' +
                        '<i class="bi bi-gear-fill"></i>' +
                        '</button>' +
                        '<ul class="dropdown-menu shadow border-0 p-2">' +
                        (row.user_id ? '<li><a class="dropdown-item py-2 rounded assign-role" href="#" data-id="' + row.user_id + '" data-role="' + (row.role_id || '') + '"><i class="bi bi-person-gear"></i> ' + (isSw ? 'Gawa Nafasi' : 'Assign Role') + '</a></li>' : '');
                    
                    if (!isCurrentUser) {
                        var displayLabel = (row.full_name && row.full_name.trim() !== 'N/A') ? row.full_name : row.username;
                        var cid = row.customer_id || '';
                        var uid = row.user_id || '';
                        
                        if (row.is_active == 1) {
                            dropdownHtml += '<li><a class="dropdown-item py-2 rounded toggle-user" href="#" data-id="' + uid + '" data-custid="' + cid + '" data-fullname="' + displayLabel + '" data-action="inactive"><i class="bi bi-person-x"></i> ' + (isSw ? 'Zima Akaunti' : 'Deactivate') + '</a></li>';
                        } else {
                            dropdownHtml += '<li><a class="dropdown-item py-2 rounded toggle-user" href="#" data-id="' + uid + '" data-custid="' + cid + '" data-fullname="' + displayLabel + '" data-action="active"><i class="bi bi-person-check"></i> ' + (isSw ? 'Washa Akaunti' : 'Activate') + '</a></li>';
                        }
                        
                        dropdownHtml += '<li><hr class="dropdown-divider"></li>' +
                            '<li><a class="dropdown-item py-2 rounded delete-user text-danger" href="#" data-id="' + uid + '" data-custid="' + cid + '" data-fullname="' + displayLabel + '"><i class="bi bi-trash-fill"></i> ' + (isSw ? 'Futa Mtumiaji' : 'Delete User') + '</a></li>';
                    }
                    
                    dropdownHtml += '</ul></div>';
                    
                    return dropdownHtml;
                },
                orderable: false
            }
        ],
        language: {
            emptyTable: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna watumiaji' : 'No users found' ?>",
            zeroRecords: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna kinacholanda' : 'No matching users found' ?>",
            info: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Inaonyesha _START_ hadi _END_ kati ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_ users' ?>",
            infoEmpty: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hakuna watumiaji' : 'No users available' ?>",
            infoFiltered: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? '(imechujwa kutoka _MAX_)' : '(filtered from _MAX_ total users)' ?>",
            search: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tafuta:' : 'Search:' ?> _INPUT_",
            searchPlaceholder: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tafuta hapa...' : 'Search users...' ?>",
            lengthMenu: "<?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Onyesha _MENU_ kwa skrini' : 'Show _MENU_ users per page' ?>"
        },
        columnDefs: [
            { width: "5%", targets: 0 },  // S/NO
            { width: "5%", targets: 1 },  // ID
            { width: "10%", targets: 2 }, // Username
            { width: "15%", targets: 3 }, // Full Name
            { width: "15%", targets: 4 }, // Email
            { width: "10%", targets: 5 }, // Role
            { width: "8%", targets: 6 },  // Status
            { width: "15%", targets: 7 }, // Last Login
            { width: "12%", targets: 8 }  // Actions
        ],
        drawCallback: function() {
            renderUsersCards(this.api());
        }
    });

    function vkEscU(s) {
        if (s == null) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderUsersCards(api) {
        var isSw = <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'true' : 'false' ?>;
        var currentUserId = parseInt(<?= $_SESSION['user_id'] ?? 0 ?>);
        var jsRoleMap = {
            'admin': {en:'Admin',sw:'Msimamizi Mkuu'}, 'mwenyekiti': {en:'Chairman',sw:'Mwenyekiti'},
            'katibu': {en:'Secretary',sw:'Katibu'}, 'secretary': {en:'Secretary',sw:'Katibu'},
            'mhazini': {en:'Treasurer',sw:'Mhazini'}, 'treasurer': {en:'Treasurer',sw:'Mhazini'},
            'mjumbe': {en:'Board Member',sw:'Mjumbe'}, 'member': {en:'Member',sw:'Mwanachama'},
            'mwanachama': {en:'Member',sw:'Mwanachama'}, 'loan officer': {en:'Loan Officer',sw:'Afisa Mkopo'}
        };
        var roleColors = {'admin':'danger','mwenyekiti':'success','chairman':'success','katibu':'info','secretary':'info','mhazini':'warning','treasurer':'warning'};

        var html = '';
        api.rows({page:'current'}).data().each(function(row) {
            if (!row) return;
            var name = vkEscU(row.full_name || row.username || '');
            var initials = name.trim() ? name.trim().split(/\s+/).map(function(w){return w[0]||'';}).slice(0,2).join('').toUpperCase() : 'U';
            var roleName = row.role_name || '';
            var roleLow  = roleName.toLowerCase();
            var roleDisp = roleName;
            for (var key in jsRoleMap) {
                if (roleLow.includes(key)) { roleDisp = isSw ? jsRoleMap[key].sw : jsRoleMap[key].en; break; }
            }
            var roleClass = 'primary';
            for (var key in roleColors) { if (roleLow.includes(key)) { roleClass = roleColors[key]; break; } }
            var isActive = row.is_active == 1;
            var isCurrentUser = (parseInt(row.user_id) === currentUserId);
            var lastLogin = row.last_login && row.last_login !== 'Never'
                ? (function(d){ var dt=new Date(d.replace(' ','T')); return isNaN(dt)?d:dt.toLocaleDateString(isSw?'sw-TZ':'en-GB',{day:'2-digit',month:'short',year:'numeric'}); })(row.last_login)
                : (isSw?'Hajawahi':'Never');

            var actions = '<button class="btn vk-btn-action btn-primary assign-role" data-id="'+vkEscU(row.user_id)+'" data-role="'+vkEscU(row.role_id)+'" title="'+(isSw?'Gawa Nafasi':'Assign Role')+'"><i class="bi bi-person-gear"></i></button>';
            if (!isCurrentUser) {
                if (isActive) {
                    actions += '<button class="btn vk-btn-action btn-secondary toggle-user" data-id="'+vkEscU(row.user_id)+'" data-custid="'+vkEscU(row.customer_id)+'" data-fullname="'+vkEscU(row.full_name)+'" data-action="inactive" title="'+(isSw?'Zima':'Deactivate')+'"><i class="bi bi-person-x"></i></button>';
                } else {
                    actions += '<button class="btn vk-btn-action btn-success toggle-user" data-id="'+vkEscU(row.user_id)+'" data-custid="'+vkEscU(row.customer_id)+'" data-fullname="'+vkEscU(row.full_name)+'" data-action="active" title="'+(isSw?'Washa':'Activate')+'"><i class="bi bi-person-check"></i></button>';
                }
                actions += '<button class="btn vk-btn-action btn-danger delete-user" data-id="'+vkEscU(row.user_id)+'" data-custid="'+vkEscU(row.customer_id)+'" data-fullname="'+vkEscU(row.full_name)+'" title="'+(isSw?'Futa':'Delete')+'"><i class="bi bi-trash-fill"></i></button>';
            }

            html += '<div class="vk-member-card">'
                + '<div class="vk-card-header d-flex justify-content-between align-items-center gap-2">'
                + '<div class="d-flex align-items-center gap-2">'
                + '<div class="vk-card-avatar" style="background:linear-gradient(135deg,#0d6efd,#0a58ca);">'+initials+'</div>'
                + '<div><div class="fw-bold text-dark" style="font-size:13px;">'+name+'</div>'
                + '<small class="text-muted">'+vkEscU(row.username)+'</small></div></div>'
                + '<span class="badge bg-'+roleClass+' rounded-pill px-2" style="font-size:10px;">'+vkEscU(roleDisp)+'</span>'
                + '</div>'
                + '<div class="vk-card-body">'
                + '<div class="vk-card-row"><span class="vk-card-label">'+(isSw?'Barua Pepe':'Email')+'</span><span class="vk-card-value small text-muted">'+vkEscU(row.email)+'</span></div>'
                + '<div class="vk-card-row"><span class="vk-card-label">'+(isSw?'Hali':'Status')+'</span><span class="vk-card-value"><span class="badge bg-'+(isActive?'success':'secondary')+'">'+(isActive?'Active':'Inactive')+'</span></span></div>'
                + '<div class="vk-card-row"><span class="vk-card-label">'+(isSw?'Mwisho':'Last Login')+'</span><span class="vk-card-value small">'+vkEscU(lastLogin)+'</span></div>'
                + '</div>'
                + '<div class="vk-card-actions">'+actions+'</div>'
                + '</div>';
        });

        $('#usersCardsWrapper').html(html || '<div class="text-center py-5 text-muted"><p>'+(isSw?'Hakuna watumiaji':'No users found')+'</p></div>');
    }

    // Role Assignment Modal
    var roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    
    $(document).on('click', '.assign-role', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var currentRole = $(this).data('role');
        
        $('#assignUserId').val(userId);
        $('#userRole').val(currentRole);
        roleModal.show();
    });
    
    $('#saveRole').click(function() {
        var formData = $('#roleForm').serialize();
        
        $.ajax({
            url: '../../../actions/update_user_role.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            beforeSend: function() {
                $('#saveRole').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> ' + (isSw ? 'Inahifadhi...' : 'Saving...'));
            },
            complete: function() {
                $('#saveRole').prop('disabled', false).text(isSw ? 'Hifadhi' : 'Save Changes');
            },
            success: function(response) {
                if (response.success) {
                    showAlert(isSw ? 'Nafasi imetengwa kwa mafanikio' : 'Role assigned successfully', 'success');
                    table.ajax.reload(null, false);
                    roleModal.hide();
                } else {
                    showAlert(response.message || 'Error assigning role', 'danger');
                }
            },
            error: function(xhr) {
                showAlert('Error communicating with server: ' + xhr.statusText, 'danger');
            }
        });
    });

    // User status toggle
    $(document).on('click', '.toggle-user', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var custId = $(this).data('custid');
        var fullName = $(this).data('fullname');
        var action = $(this).data('action');
        
        var title = isSw ? (action == 'active' ? 'Unamwasha ' : 'Unamzimisha ') + fullName + '?' : (action == 'active' ? 'Activate ' : 'Deactivate ') + fullName + '?';
        var confirmBtn = isSw ? 'Ndiyo, Endelea' : 'Yes, Proceed';

        Swal.fire({
            title: title,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmBtn,
            cancelButtonText: isSw ? 'Abiri' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '../../../actions/update_user_status.php',
                    method: 'POST',
                    data: { 
                        user_id: userId,
                        customer_id: custId,
                        status: action
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: isSw ? 'Imefanikiwa' : 'Success',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            table.ajax.reload(null, false);
                        } else {
                            Swal.fire(isSw ? 'Kosa' : 'Error', response.message || 'Error updating user', 'error');
                        }
                    },
                    error: function(xhr) {
                        Swal.fire(isSw ? 'Kosa la Mtandao' : 'Network Error', xhr.statusText, 'error');
                    }
                });
            }
        });
    });

    // Delete user
    $(document).on('click', '.delete-user', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var custId = $(this).data('custid');
        var fullName = $(this).data('fullname');
        var row = $(this).closest('tr');
        
        var title = isSw ? 'Uhakika Kumfuta ' + fullName + '?' : 'Delete ' + fullName + '?';
        var text = isSw ? 'Mtumiaji huyu ataondolewa kabisa!' : 'This user will be permanently removed!';
        var confirmBtn = isSw ? 'Futa Sasa' : 'Delete Now';

        Swal.fire({
            title: title,
            text: text,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: confirmBtn,
            cancelButtonText: isSw ? 'Ghairi' : 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: '../../../actions/update_user_status.php',
                    method: 'POST',
                    data: { 
                        user_id: userId,
                        customer_id: custId,
                        status: 'deleted'
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: isSw ? 'Imefutwa!' : 'Deleted!',
                                text: response.message,
                                timer: 2000,
                                showConfirmButton: false
                            });
                            table.ajax.reload(null, false);
                        } else {
                            Swal.fire(isSw ? 'Kosa' : 'Error', response.message || 'Error deleting user', 'error');
                        }
                    },
                    error: function(xhr) {
                        Swal.fire(isSw ? 'Kosa la Mtandao' : 'Network Error', xhr.statusText, 'error');
                    }
                });
            }
        });
    });

    // Show alert message (Modern Swal implementation)
    function showAlert(message, type) {
        var icon = 'info';
        if (type == 'success') icon = 'success';
        if (type == 'danger' || type == 'error') icon = 'error';
        if (type == 'warning') icon = 'warning';

        Swal.fire({
            icon: icon,
            title: isSw ? (icon == 'success' ? 'Imefanikiwa' : 'Taarifa') : (icon == 'success' ? 'Success' : 'Notice'),
            text: message,
            confirmButtonColor: '#0d6efd',
            timer: 3000
        });
    }
});
</script>
<?php
// Flush the buffer
ob_end_flush();