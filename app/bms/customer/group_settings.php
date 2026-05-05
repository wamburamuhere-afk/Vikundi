<?php
// app/bms/customer/group_settings.php
require_once 'header.php';

if (!in_array($user_role, ['Admin', 'Secretary', 'Katibu'])) {
    header("Location: " . getUrl('dashboard') . "?error=Access Denied");
    exit();
}

// Fetch all group settings
$settings_raw = $pdo->query("SELECT setting_key, setting_value FROM group_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// Helper to get a setting with a default
function gs($settings, $key, $default = '') {
    return (string)($settings[$key] ?? $default);
}
?>

<div class="row mb-0">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center py-3 px-1 border-bottom mb-4">
            <div>
                <h4 class="mb-0 fw-bold text-primary">
                    <i class="bi bi-gear-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mipangilio ya Kikundi' : 'Group Settings' ?>
                </h4>
                <small class="text-muted"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Weka sheria na vigezo vya mfumo wa vikundi' : 'Configure group rules and system parameters' ?></small>
            </div>
            <!-- REMOVED ADMIN ONLY BADGE -->
        </div>
    </div>
</div>

<div id="alertBox"></div>

<form id="settingsForm" enctype="multipart/form-data">
<div class="row g-4">
    <!-- KADI 1: TAARIFA ZA KIKUNDI -->
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-people-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Msingi za Kikundi' : 'Group Basic Information' ?></h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Jina la Kikundi' : 'Group Name' ?></label>
                        <input type="text" name="group_name" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_name')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Usajili' : 'Registration Number' ?></label>
                        <input type="text" name="group_registration_number" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_registration_number')) ?>" placeholder="e.g. Reg/2026/001">
                    </div>
                    <div class="col-md-4">
                         <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Logo ya Kikundi' : 'Group Logo' ?></label>
                         <div class="d-flex align-items-center gap-3">
                            <div class="bg-light p-1 rounded border overflow-hidden" style="width: 50px; height: 50px;">
                                <img id="logoPreview" src="assets/images/<?= gs($settings_raw, 'group_logo', 'logo1.png') ?>" style="width:100%; height:100%; object-fit:contain;">
                            </div>
                            <input type="file" name="group_logo" class="form-control border-secondary-subtle" accept="image/*" onchange="previewFile(this)">
                         </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tarehe ya Kuanzishwa' : 'Founded Date' ?></label>
                        <input type="date" name="group_founded_date" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'group_founded_date') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwanzo wa Michango' : 'Contribution Start Date' ?></label>
                        <input type="date" name="contribution_start_date" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'contribution_start_date') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku ya Mkutano' : 'Meeting Day' ?></label>
                        <select name="meeting_day" class="form-select border-secondary-subtle">
                            <?php 
                            $days_sw = ['Jumatatu','Jumanne','Jumatano','Alhamisi','Ijumaa','Jumamosi','Jumapili'];
                            $days_en = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                            foreach ($days_sw as $idx => $d_sw): 
                                $d_en = $days_en[$idx];
                            ?>
                            <option value="<?= $d_sw ?>" <?= gs($settings_raw, 'meeting_day') === $d_sw ? 'selected' : '' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? $d_sw : $d_en ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mzunguko (Cycle)' : 'Cycle Type' ?></label>
                        <select name="cycle_type" id="cycle_type" class="form-select border-secondary-subtle">
                            <option value="monthly" <?= gs($settings_raw,'cycle_type') === 'monthly' ? 'selected':'' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kila Mwezi' : 'Monthly' ?></option>
                            <option value="weekly" <?= gs($settings_raw,'cycle_type') === 'weekly' ? 'selected':'' ?>><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kila Wiki' : 'Weekly' ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sarafu (Currency)' : 'Currency' ?></label>
                        <select name="currency" class="form-select border-secondary-subtle">
                            <option value="TZS" <?= gs($settings_raw, 'currency', 'TZS') === 'TZS' ? 'selected' : '' ?>>TZS - Shilingi ya Tanzania</option>
                            <option value="USD" <?= gs($settings_raw, 'currency') === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                            <option value="KES" <?= gs($settings_raw, 'currency') === 'KES' ? 'selected' : '' ?>>KES - Kenyan Shilling</option>
                            <option value="UGX" <?= gs($settings_raw, 'currency') === 'UGX' ? 'selected' : '' ?>>UGX - Ugandan Shilling</option>
                            <option value="EUR" <?= gs($settings_raw, 'currency') === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Idadi ya Wanachama' : 'Max Members' ?></label>
                        <input type="number" name="max_members" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'max_members', '30') ?>">
                    </div>

                    <!-- NEW GROUP FIELDS -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Email ya Kikundi' : 'Group Email' ?></label>
                        <input type="email" name="group_email" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_email')) ?>" placeholder="kikundi@example.com">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Namba ya Simu' : 'Phone Number' ?></label>
                        <input type="text" name="group_phone" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_phone')) ?>" placeholder="+255...">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Website' : 'Website' ?></label>
                        <input type="url" name="group_website" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_website')) ?>" placeholder="https://...">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Postal Address' : 'Postal Address' ?></label>
                        <textarea name="group_postal_address" class="form-control border-secondary-subtle" rows="2" placeholder="P.O Box..."><?= htmlspecialchars(gs($settings_raw, 'group_postal_address')) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Physical Address' : 'Physical Address' ?></label>
                        <textarea name="group_physical_address" class="form-control border-secondary-subtle" rows="2" placeholder="Building, Street..."><?= htmlspecialchars(gs($settings_raw, 'group_physical_address')) ?></textarea>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TIN Number' : 'TIN Number' ?></label>
                        <input type="text" name="group_tin" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_tin')) ?>" placeholder="123-456-789">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'VRN Number' : 'VRN Number' ?></label>
                        <input type="text" name="group_vrn" class="form-control border-secondary-subtle" value="<?= htmlspecialchars(gs($settings_raw, 'group_vrn')) ?>" placeholder="400...X">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KADI 2: MICHANGO -->
    <div class="col-12">
        <div class="card border border-primary-subtle shadow-sm h-100 rounded-4 overflow-hidden">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-coin me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Viwango vya Michango' : 'Contribution Rates' ?></h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 text-start">
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mchango wa Mzunguko (TZS)' : 'Cycle Contribution (TZS)' ?></label>
                        <input type="number" name="monthly_contribution" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'monthly_contribution', '10000') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Kiingilio (Entrance Fee)' : 'Entrance Fee' ?></label>
                        <input type="number" name="entrance_fee" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'entrance_fee', '20000') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KADI 4: SHERIA KALI (AUTO-TERMINATION) -->
    <div class="col-12">
        <div class="card border border-primary-subtle shadow-sm rounded-4 overflow-hidden">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-shield-lock-fill me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Sheria Kali ya Uanachama' : 'Strict Membership Policy' ?></h6>
            </div>
            <div class="card-body p-4 text-start">
                <div class="alert alert-primary border-primary py-2 small mb-4">
                    <i class="bi bi-info-circle-fill me-2"></i> <strong><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Tahadhari:' : 'Warning:' ?></strong> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ikiwa mwanachama atapitisha muda huu bila kulipa kiasi kilichopangwa, mfumo utamfuta (Terminate) moja kwa moja.' : 'If a member misses this deadline without paying the required amount, the system will auto-terminate them.' ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Siku ya Mwisho ya Malipo' : 'Payment Deadline Day' ?></label>
                        <select name="deadline_day" id="deadline_day" class="form-select border-secondary-subtle" data-current="<?= gs($settings_raw, 'deadline_day', '15') ?>">
                            <!-- Will be populated by JS -->
                        </select>
                        <small class="text-muted italic"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Mwisho wa kutoa mchango.' : 'Last day to submit payment.' ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Saa ya Mwisho (Deadline Time)' : 'Deadline Time (HH:MM)' ?></label>
                        <input type="time" name="deadline_time" class="form-control border-secondary-subtle" value="<?= gs($settings_raw, 'deadline_time', '23:59') ?>">
                        <small class="text-muted italic"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Muda utakaofika mwanachama anafutwa.' : 'Time at which member is auto-kicked.' ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Hali ya Auto-Termination' : 'Auto-Termination Status' ?></label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="auto_termination" value="on" <?= gs($settings_raw, 'auto_termination') === 'on' ? 'checked' : '' ?> style="transform: scale(1.3)">
                            <label class="form-check-label ms-2 fw-bold text-dark"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Washa / Tuma Nje Waliopitisha' : 'Enable / Auto-Kick Overdue' ?></label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Save Button -->
    <div class="col-12">
        <div class="d-flex justify-content-end gap-3 py-4 border-top">
            <button type="submit" class="btn btn-primary px-5 shadow rounded-pill fw-bold" id="saveBtn">
                <i class="bi bi-save me-2"></i> <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'HIFADHI MIPANGILIO' : 'SAVE GROUP SETTINGS' ?>
            </button>
        </div>
    </div>

</div>
</form>

<script>
function previewFile(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#logoPreview').attr('src', e.target.result);
        }
        reader.readAsDataURL(input.files[0]);
    }
}
function updateDeadlineOptions() {
    const cycle = $('#cycle_type').val();
    const deadlineSelect = $('#deadline_day');
    const currentVal = deadlineSelect.data('current');
    const lang = '<?= $_SESSION['preferred_language'] ?? 'en' ?>';
    
    deadlineSelect.empty();
    
    if (cycle === 'monthly') {
        for (let i = 1; i <= 31; i++) {
            let label = lang === 'sw' ? 'Siku ya ' + i : 'Day ' + i;
            deadlineSelect.append(`<option value="${i}" ${currentVal == i ? 'selected' : ''}>${label}</option>`);
        }
    } else if (cycle === 'weekly') {
        const daysSw = ['Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi', 'Jumapili'];
        const daysEn = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        daysSw.forEach((day, idx) => {
            let label = lang === 'sw' ? day : daysEn[idx];
            deadlineSelect.append(`<option value="${day}" ${currentVal == day ? 'selected' : ''}>${label}</option>`);
        });
    }
}

$('#cycle_type').on('change', updateDeadlineOptions);
$(document).ready(updateDeadlineOptions);

$('#settingsForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#saveBtn');
    const lang = '<?= $_SESSION['preferred_language'] ?? 'en' ?>';
    const savingText = lang === 'sw' ? 'Inahifadhi...' : 'Saving...';
    const originalText = btn.html();

    btn.prop('disabled', true).text(savingText);

    $.ajax({
        url: '<?= getUrl("actions/save_group_settings") ?>',
        type: 'POST',
        data: new FormData(this),
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html(originalText);
            if (res.success) {
                const title = lang === 'sw' ? 'Imehifadhiwa!' : 'Saved!';
                Swal.fire({ icon: 'success', title: title, timer: 2000, showConfirmButton: false });
            } else {
                const title = lang === 'sw' ? 'Hitilafu' : 'Error';
                Swal.fire(title, res.message, 'error');
            }
        },
        error: function() {
            btn.prop('disabled', false).html(originalText);
            const title = lang === 'sw' ? 'Hitilafu' : 'Error';
            const msg = lang === 'sw' ? 'Hitilafu ya seva.' : 'Server error.';
            Swal.fire(title, msg, 'error');
        }
    });
});
</script>

<?php require_once 'footer.php'; ?>
