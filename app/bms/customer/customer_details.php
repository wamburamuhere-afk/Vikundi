<?php
require_once HEADER_FILE;

// Set language
$lang = $_SESSION['preferred_language'] ?? 'en';
$isSw = ($lang === 'sw');

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Access control for Members
if (str_contains($user_role_lower, 'member') || str_contains($user_role_lower, 'mwanachama') || str_contains($user_role_lower, 'mjumbe')) {
    $user_check_stmt = $pdo->prepare("SELECT customer_id FROM customers WHERE user_id = ?");
    $user_check_stmt->execute([$_SESSION['user_id']]);
    $own_customer_id = (int)$user_check_stmt->fetchColumn();
    
    if ($customer_id !== $own_customer_id) {
        header("Location: " . getUrl('dashboard') . "?error=Unauthorized Access");
        exit();
    }
}

// Fetch customer data with related information
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        u.avatar as user_avatar,
        u.status as user_status,
        u.user_id as user_id_ref
    FROM customers c
    LEFT JOIN users u ON c.user_id = u.user_id
    WHERE c.customer_id = ?
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die($isSw ? "Mwanachama hajapatikana" : "Customer not found");
}

// Format names
$customer_name = safe_output($customer['customer_name'] ?: ($customer['first_name'] . ' ' . $customer['last_name']));

// Parse Children Data
$children = [];
if (!empty($customer['children_data'])) {
    $children = json_decode($customer['children_data'], true);
    if (!is_array($children)) $children = [];
}

// Translations to match the form exactly
$labels = [
    'details_title' => $isSw ? 'Taarifa za Mwanachama' : 'Member Details',
    'back_btn' => $isSw ? 'Rudi' : 'Back to Customers',
    'edit_btn' => $isSw ? 'Hariri' : 'Edit Member',
    'profile_photo' => $isSw ? 'Picha ya Mwanachama' : 'Member Photo',
    'quick_info' => $isSw ? 'Taarifa Muhimu' : 'Quick Information',
    'member_id' => $isSw ? 'Namba ya Mwanachama' : 'Member ID',
    'phone' => $isSw ? 'Simu' : 'Phone',
    'email' => $isSw ? 'Barua Pepe' : 'Email Address',
    'registered' => $isSw ? 'Amesajiliwa' : 'Registered',
    'personal_info' => $isSw ? 'HATUA YA 1: TAARIFA BINAFSI' : 'PHASE 1: PERSONAL INFORMATION',
    'wanufaika_title' => $isSw ? 'WANUFAIKA: MWENZI (SPOUSE), WATOTO, NA WAZAZI' : 'BENEFICIARIES: SPOUSE, CHILDREN, AND PARENTS',
    'spouse' => $isSw ? 'MWENZI (MKE/MUME)' : 'SPOUSE (WIFE/HUSBAND)',
    'children' => $isSw ? 'WATOTO' : 'CHILDREN',
    'parents' => $isSw ? 'TAARIFA ZA WAZAZI' : 'PARENTS INFORMATION',
    'father' => $isSw ? 'TAARIFA ZA BABA' : 'Father\'s Details',
    'mother' => $isSw ? 'TAARIFA ZA MAMA' : 'Mother\'s Details',
    'parent_name' => $isSw ? 'Jina Kamili' : 'Full Name',
    'location' => $isSw ? 'Mahali anapoishi' : 'Location',
    'residence' => $isSw ? 'HATUA YA 2: MAKAO NA MAKAZI' : 'PHASE 2: RESIDENCE & ADDRESS',
    'guarantor' => $isSw ? 'MDHAMINI WA MWANACHAMA' : 'MEMBER\'S GUARANTOR',
    'relationship' => $isSw ? 'Uhusiano' : 'Relationship',
    'nok' => $isSw ? 'MSIMAMIZI WA MIRATHI (NEXT OF KIN)' : 'NEXT OF KIN',
    'finance' => $isSw ? 'HATUA YA 3: KIINGILIO NA USALAMA' : 'PHASE 3: ENTRANCE FEE & SECURITY'
];

?>

<div class="container mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-person-badge-fill text-primary"></i> <?= $labels['details_title'] ?></h2>
            <p class="text-muted small"><?= ($isSw ? 'Taarifa zote kumhusu ' : 'Detailed information about ') . safe_output($customer_name) ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if (($customer['user_status'] ?? '') === 'pending'): ?>
                <button onclick="approveMember(<?= $customer['user_id_ref'] ?>)" class="btn btn-success btn-sm">
                    <i class="bi bi-check-circle"></i> <?= $isSw ? 'Mkubali (Approve)' : 'Approve' ?>
                </button>
                <button onclick="rejectMember(<?= $customer['user_id_ref'] ?>)" class="btn btn-danger btn-sm">
                    <i class="bi bi-x-circle"></i> <?= $isSw ? 'Mkatae (Reject)' : 'Reject' ?>
                </button>
            <?php endif; ?>
            <a href="<?= getUrl('customers') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> <?= $labels['back_btn'] ?>
            </a>
            <a href="<?= getUrl('customers/edit') ?>?id=<?= $customer_id ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-pencil"></i> <?= $labels['edit_btn'] ?>
            </a>
        </div>
    </div>

    <?php if (($customer['user_status'] ?? '') === 'pending'): ?>
    <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4" style="border-radius: 12px;">
        <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-warning"></i>
        <div>
            <h6 class="mb-0 fw-bold"><?= $isSw ? 'Maombi Yanayosubiri Uhakiki' : 'Application Pending Verification' ?></h6>
            <p class="mb-0 small text-muted"><?= $isSw ? 'Mwanachama huyu bado hajapata idhini. Hakiki taarifa zake kisha chukua hatua.' : 'This member has not been approved yet. Verify the details and take action using the buttons above.' ?></p>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Upande wa kushoto (Picha na Info fupi) -->
        <div class="col-md-3 mb-4">
            <div class="card border-0 shadow-sm mb-4 text-center p-3">
                <div class="mb-3">
                    <?php 
                    $photo_path = !empty($customer['user_avatar']) ? 'uploads/avatars/' . $customer['user_avatar'] : ($customer['photo_path'] ?? '');
                    if (!empty($photo_path) && file_exists(ROOT_DIR . '/' . $photo_path)): ?>
                        <img src="<?= BASE_URL . '/' . safe_output($photo_path) ?>" 
                             class="img-fluid rounded-4 shadow-sm" 
                             style="width: 100%; max-height: 250px; object-fit: cover;" 
                             alt="Profile Photo">
                    <?php else: ?>
                        <div class="bg-light rounded-4 py-5 text-muted opacity-50">
                            <i class="bi bi-person-circle fs-1"></i>
                            <p class="mb-0 small mt-2"><?= $isSw ? 'Picha Haipo' : 'No Photo' ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <h5 class="fw-bold mb-0 text-truncate px-2"><?= $customer_name ?></h5>
                <span class="badge bg-primary bg-opacity-10 text-primary mt-2">ID: #<?= safe_output($customer['customer_id']) ?></span>
                <hr class="my-3 mx-2">
                <div class="text-start small px-2">
                    <p class="mb-1 text-muted"><i class="bi bi-telephone text-primary me-1"></i> <?= safe_output($customer['phone']) ?></p>
                    <p class="mb-0 text-muted"><i class="bi bi-envelope text-primary me-1"></i> <span class="text-break"><?= safe_output($customer['email']) ?></span></p>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm p-3 bg-light">
                <p class="text-muted small mb-1"><?= $isSw ? 'Tarehe aliojisajili:' : 'Registered Date:' ?></p>
                <h6 class="fw-bold mb-0"><?= date('M d, Y', strtotime($customer['created_at'])) ?></h6>
            </div>
        </div>

        <!-- Upande wa kulia (Data Zote) -->
        <div class="col-md-9">
            
            <!-- 1. HATUA YA 1: TAARIFA BINAFSI -->
            <div class="card border-0 shadow-sm mb-4 border-start border-4 border-primary">
                <div class="card-header bg-primary bg-opacity-10 border-0 py-3">
                    <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-person-fill me-2"></i> <?= $labels['personal_info'] ?></h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-3">
                            <label class="text-muted mb-0"><?= $isSw ? 'Jinsia' : 'Gender' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['gender'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted mb-0"><?= $isSw ? 'Tarehe Kuzaliwa' : 'Birth Date' ?>:</label>
                            <p class="fw-bold mb-0"><?= !empty($customer['dob']) ? date('M d, Y', strtotime($customer['dob'])) : 'N/A' ?></p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted mb-0"><?= $isSw ? 'Namba ya NIDA' : 'NIDA No' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['nida_number'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted mb-0"><?= $isSw ? 'Dini' : 'Religion' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['religion'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted mb-0"><?= $isSw ? 'Mkoa aliozaliwa' : 'Birth Region' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['birth_region'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted mb-0"><?= $isSw ? 'Hali ya Ndoa' : 'Marital Status' ?>:</label>
                            <p class="mb-0"><span class="badge bg-light text-dark border"><?= safe_output($customer['marital_status'] ?? 'Single') ?></span></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. WANUFAIKA CORE SECTION (Important!) -->
            <div class="card border-0 shadow-sm mb-4 border-start border-4 border-primary">
                <div class="card-header bg-primary bg-opacity-10 border-0 py-3">
                    <h5 class="mb-0 text-primary fw-bold"><i class="bi bi-people-fill me-2"></i> <?= $isSw ? 'WANUFAIKA (BENEFICIARIES)' : 'BENEFICIARIES' ?></h5>
                </div>
                <div class="card-body">
                    
                    <!-- 1. Parents Area -->
                    <div class="mb-4">
                        <h6 class="text-primary fw-bold border-bottom pb-1 small mb-3"><i class="bi bi-person-heart me-1"></i> 1. <?= $labels['parents'] ?></h6>
                        <div class="row g-4 ps-2">
                            <div class="col-md-6 border-end">
                                <p class="text-muted small mb-2 fw-bold text-uppercase border-bottom pb-1" style="font-size: 0.65rem;"><?= $isSw ? 'Taarifa za Baba' : 'Father\'s Details' ?></p>
                                <div class="mb-1">
                                    <label class="text-muted mb-0 small">Jina:</label>
                                    <p class="fw-bold mb-0 small text-primary"><?= safe_output($customer['father_name'] ?: 'N/A') ?></p>
                                </div>
                                <div class="mb-1">
                                    <label class="text-muted mb-0 small">Simu:</label>
                                    <p class="fw-bold mb-0 small"><?= safe_output($customer['father_phone'] ?: 'N/A') ?></p>
                                </div>
                                <div class="mb-0">
                                    <label class="text-muted mb-0 small">Mahali:</label>
                                    <p class="fw-semibold mb-0 small text-muted"><?= safe_output($customer['father_location'] ?: 'N/A') ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <p class="text-muted small mb-2 fw-bold text-uppercase border-bottom pb-1" style="font-size: 0.65rem;"><?= $isSw ? 'Taarifa za Mama' : 'Mother\'s Details' ?></p>
                                <div class="mb-1">
                                    <label class="text-muted mb-0 small">Jina:</label>
                                    <p class="fw-bold mb-0 small text-primary"><?= safe_output($customer['mother_name'] ?: 'N/A') ?></p>
                                </div>
                                <div class="mb-1">
                                    <label class="text-muted mb-0 small">Simu:</label>
                                    <p class="fw-bold mb-0 small"><?= safe_output($customer['mother_phone'] ?: 'N/A') ?></p>
                                </div>
                                <div class="mb-0">
                                    <label class="text-muted mb-0 small">Mahali:</label>
                                    <p class="fw-semibold mb-0 small text-muted"><?= safe_output($customer['mother_location'] ?: 'N/A') ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Wife/Husband -->
                    <?php if (!empty($customer['spouse_first_name'])): ?>
                    <div class="mb-4 pt-2">
                        <h6 class="text-primary fw-bold border-bottom pb-1 small mb-2"><i class="bi bi-heart-fill me-1"></i> 2. <?= $labels['spouse'] ?></h6>
                        <div class="row g-2 small ps-2">
                            <div class="col-md-6">
                                <label class="text-muted mb-0">Jina:</label>
                                <p class="fw-bold mb-0"><?= safe_output($customer['spouse_first_name'].' '.($customer['spouse_middle_name']?' '.$customer['spouse_middle_name']:'').' '.$customer['spouse_last_name']) ?></p>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted mb-0">Simu:</label>
                                <p class="fw-bold mb-0"><?= safe_output($customer['spouse_phone'] ?: 'N/A') ?></p>
                            </div>
                            <div class="col-md-3">
                                <label class="text-muted mb-0">NIDA:</label>
                                <p class="fw-bold mb-0"><?= safe_output($customer['spouse_nida'] ?: 'N/A') ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 3. Children -->
                    <div class="mb-0 pt-2">
                        <h6 class="text-primary fw-bold border-bottom pb-1 small mb-2"><i class="bi bi-person-check-fill me-1"></i> 3. <?= $labels['children'] ?> (<?= count($children) ?>)</h6>
                        <?php if (count($children) > 0): ?>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered mb-0" style="font-size: 0.85rem;">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="ps-2">Jina la Mtoto</th>
                                        <th class="text-center" style="width: 80px;">Umri</th>
                                        <th style="width: 120px;">Jinsia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($children as $c): ?>
                                    <tr>
                                        <td class="ps-2 py-2 fw-semibold"><?= safe_output($c['name']) ?></td>
                                        <td class="text-center py-2"><?= safe_output($c['age']) ?></td>
                                        <td class="py-2"><?= safe_output($c['gender']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <p class="text-muted small ps-2 mt-2 italic"><i class="bi bi-info-circle me-1"></i> Hakuna taarifa za watoto zilizosajiliwa.</p>
                        <?php endif; ?>
                    </div>

                </div>
            </div>


            <!-- 3. HATUA YA 2: MAKAO NA MAKAZI -->
            <div class="card border-0 shadow-sm mb-4 border-start border-4 border-success">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 text-success fw-bold"><i class="bi bi-geo-alt-fill me-2"></i> <?= $labels['residence'] ?></h6>
                </div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-4">
                            <label class="text-muted mb-0"><?= $isSw ? 'Mkoa/State' : 'Region/State' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['state'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted mb-0"><?= $isSw ? 'Wilaya' : 'District' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['district'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted mb-0"><?= $isSw ? 'Kata' : 'Ward' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['ward'] ?? 'N/A') ?></p>
                        </div>
                        <div class="col-md-12">
                            <label class="text-muted mb-0"><?= $isSw ? 'Mtaa na Nyumba' : 'Street & House' ?>:</label>
                            <p class="fw-bold mb-0"><?= safe_output($customer['street'] ?? 'N/A') ?> - <?= $isSw ? 'Nyumba No' : 'House No' ?>: <?= safe_output($customer['house_number'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 4. MDHAMINI NA MSIMAMIZI -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                        <div class="card-header bg-white border-bottom py-3">
                            <h6 class="fw-bold text-info mb-0 small text-uppercase"><i class="bi bi-shield-lock-fill me-1"></i> <?= $labels['guarantor'] ?></h6>
                        </div>
                        <div class="card-body py-3">
                            <p class="mb-1 small">Jina: <strong><?= safe_output($customer['guarantor_name'] ?: 'N/A') ?></strong></p>
                            <p class="mb-1 small">Simu: <strong><?= safe_output($customer['guarantor_phone'] ?: 'N/A') ?></strong></p>
                            <p class="mb-0 small text-muted">Mahali: <?= safe_output($customer['guarantor_location'] ?: 'N/A') ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                        <div class="card-header bg-white border-bottom py-3">
                            <h6 class="fw-bold text-primary mb-0 small text-uppercase"><i class="bi bi-person-check-fill me-1"></i> <?= $labels['nok'] ?></h6>
                        </div>
                        <div class="card-body py-3">
                            <p class="mb-1 small">Majina: <strong><?= safe_output($customer['next_of_kin_name'] ?: 'N/A') ?></strong></p>
                            <p class="mb-1 small">Simu: <strong><?= safe_output($customer['next_of_kin_phone'] ?: 'N/A') ?></strong></p>
                            <p class="mb-0 small text-muted">Uhusiano: <?= safe_output($customer['next_of_kin_relationship'] ?: 'N/A') ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. HATUA YA 3: KIINGILIO NA USALAMA -->
            <div class="card border-0 shadow-sm bg-dark text-white p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5 class="mb-0 fw-bold text-warning"><i class="bi bi-cash-coin me-2"></i> <?= $labels['finance'] ?></h5>
                        <p class="mb-0 text-white-50 small"><?= $isSw ? 'Kiasi alicholipa wakati anajisajili kwenye mfumo.' : 'Total fee paid during registration.' ?></p>
                    </div>
                    <div class="col-md-4 text-md-end text-center mt-3 mt-md-0">
                        <h2 class="fw-bold mb-0 text-success text-nowrap"><?= number_format($customer['initial_savings'] ?? 0, 0) ?> <small class="fs-6 text-white-50">Tsh</small></h2>
                    </div>
                </div>
            </div>

            <p class="text-center text-muted small mt-4 mb-2">
                <?= $isSw ? 'Mwisho wa taarifa za mwanachama.' : 'End of member details report.' ?>
            </p>

        </div>
    </div>
</div>

<style>
.card { border-radius: 15px; }
.badge { font-weight: 500; font-size: 0.75rem; }
.form-label { margin-bottom: 2px; }
.border-start { border-width: 5px !important; }
</style>

<?php include("footer.php"); ?>

<script>
function approveMember(userId) {
    const isSwahili = <?= $isSw ? 'true' : 'false' ?>;
    Swal.fire({
        title: isSwahili ? 'Je, una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Unataka kumkubali mwanachama huyu kuwa mwanachama hai?' : 'Do you want to approve this member and activate their account?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: isSwahili ? 'Ndiyo, mkubali!' : 'Yes, approve!',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/approve_member') ?>',
                type: 'POST',
                data: { user_id: userId, action: 'approve' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: isSwahili ? 'Amekubaliwa!' : 'Approved!',
                            text: isSwahili ? 'Mwanachama sasa amekuwa hai (Active).' : 'The member has been activated successfully.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire(isSwahili ? 'Imeshindikana' : 'Failed', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', isSwahili ? 'Hitilafu ya seva imetokea.' : 'A server error occurred.', 'error');
                }
            });
        }
    });
}

function rejectMember(userId) {
    const isSwahili = <?= $isSw ? 'true' : 'false' ?>;
    Swal.fire({
        title: isSwahili ? 'Je, una uhakika?' : 'Are you sure?',
        text: isSwahili ? 'Unataka kukataa maombi haya ya uwanachama?' : 'Do you want to reject this membership application?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: isSwahili ? 'Ndiyo, mkatae!' : 'Yes, reject!',
        cancelButtonText: isSwahili ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl('actions/approve_member') ?>',
                type: 'POST',
                data: { user_id: userId, action: 'reject' },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: isSwahili ? 'Imekataliwa!' : 'Rejected!',
                            text: isSwahili ? 'Maombi yamekataliwa kikamilifu.' : 'The application has been rejected.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = '<?= getUrl('customers/approvals') ?>';
                        });
                    } else {
                        Swal.fire(isSwahili ? 'Imeshindikana' : 'Failed', response.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', isSwahili ? 'Hitilafu ya seva imetokea.' : 'A server error occurred.', 'error');
                }
            });
        }
    });
}
</script>