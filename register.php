<?php
// register.php
require_once __DIR__ . '/roots.php';
require_once __DIR__ . '/includes/csrf.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev.vikundi.local</title>
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 40px 0;
        }
        .registration-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            margin: auto;
            background: #fff;
        }
        .registration-header {
            background: #0d6efd;
            color: white;
            padding: 30px;
            text-align: center;
        }
        .nav-pills .nav-link {
            border-radius: 10px;
            padding: 12px 20px;
            transition: all 0.3s;
            font-weight: 600;
            color: #6c757d;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
            color: white;
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
        }
        .form-control, .form-select {
            padding: 12px 15px;
            border-radius: 10px;
            border: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
            background-color: #fff;
        }
        .btn-next, .btn-submit {
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-next:hover, .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="registration-card">
        <div class="registration-header">
            <h2 class="fw-bold mb-1">Create an Account</h2>
            <p class="mb-0 opacity-75">Welcome to the group management system. Fill in your details to get started.</p>
        </div>
        
        <div class="card-body p-4 p-md-5">

            <form id="publicRegisterForm" enctype="multipart/form-data">
                <!-- Hidden Defaults -->
                <input type="hidden" name="user_role" value="Member">
                <input type="hidden" name="status" value="pending">
                <?= csrf_field() ?>
                <!-- Honeypot: real users never see or fill this; automated bots do -->
                <div aria-hidden="true" style="position:absolute; left:-9999px; top:-9999px; height:0; width:0; overflow:hidden;">
                    <label>Website
                        <input type="text" name="contact_website" tabindex="-1" autocomplete="off">
                    </label>
                </div>
                
                <div class="tab-content" id="registerTabsContent">
                    <!-- PHASE 1: PERSONAL INFORMATION -->
                    <div class="tab-pane fade show active" id="personal" role="tabpanel">
                        <h5 class="mb-4 text-primary fw-bold">Step 1: Personal & Residence Information</h5>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">First Name *</label>
                                <input type="text" name="first_name" id="pub_first_name" class="form-control" placeholder="e.g. John" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" placeholder="e.g. Mike">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Last Name / Surname *</label>
                                <input type="text" name="last_name" id="pub_last_name" class="form-control" placeholder="e.g. Doe" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email Address *</label>
                                <input type="email" name="email" class="form-control" placeholder="john@example.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Phone Number *</label>
                                <input type="tel" name="phone" class="form-control" placeholder="07xxxxxxxx" required>
                                <div class="form-text small">Format: 0712345678 or +255712345678</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">Select...</option>
                                    <option value="Mwanaume">Male</option>
                                    <option value="Mwanamke">Female</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Date of Birth</label>
                                <input type="date" name="dob" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">NIDA Number</label>
                                <input type="text" name="nida_number" class="form-control" placeholder="Your NIDA number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dini ya Mwanachama' : 'Member Religion' ?></label>
                                <div id="religion_field_wrapper">
                                    <select name="religion" id="religion_select" class="form-select" onchange="handleReligionChange(this)">
                                        <option value="Ukristo"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukristo' : 'Christianity' ?></option>
                                        <option value="Uislamu"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uislamu' : 'Islam' ?></option>
                                        <option value="Nyingine"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyingine (Other)' : 'Other' ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Region of Birth</label>
                                <input type="text" name="birth_region" class="form-control" placeholder="e.g. Mwanza">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Marital Status</label>
                                <select name="marital_status" id="marital_status" class="form-select" onchange="toggleFamilyFields(this.value)">
                                    <option value="Single">Single</option>
                                    <option value="Married" selected>Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Divorced">Divorced</option>
                                </select>
                            </div>

                            <!-- YOUR RESIDENCE INFORMATION -->
                            <div class="col-12 mt-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-geo-alt-fill me-2"></i>YOUR RESIDENCE INFORMATION</h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Country</label>
                                        <input type="text" name="country" class="form-control" value="Tanzania">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Region / State</label>
                                        <input type="text" name="state" class="form-control" placeholder="e.g. Dar es Salaam">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">District</label>
                                        <input type="text" name="district" class="form-control" placeholder="e.g. Kinondoni">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Ward</label>
                                        <input type="text" name="ward" class="form-control" placeholder="Ward">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Street / Village</label>
                                        <input type="text" name="street" class="form-control" placeholder="Street">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">House Number</label>
                                        <input type="text" name="house_number" class="form-control" placeholder="House No.">
                                    </div>
                                </div>
                            </div>

                            <!-- PASSPORT PHOTO MOVED TO END OF THIS SECTION -->
                            <div class="col-12 mt-4">
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <label class="form-label fw-bold"><i class="bi bi-camera me-2 text-primary"></i>Passport Photo (Optional)</label>
                                        <input type="file" name="passport_photo" class="form-control" accept="image/*">
                                        <div class="form-text small opacity-75">Accepted formats: JPG, PNG. Max size: 2MB</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="button" class="btn btn-primary btn-next btn-lg px-4" onclick="switchTab('residence')">
                                Continue <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- PHASE 2: FAMILY & BENEFICIARIES -->
                    <div class="tab-pane fade" id="residence" role="tabpanel">
                        <h5 class="mb-4 text-primary fw-bold">Step 2: Family & Beneficiaries</h5>

                        <!-- BENEFICIARIES SECTION -->
                        <h5 class="mt-4 mb-3 text-dark fw-bold border-bottom pb-2"><i class="bi bi-people-fill me-2"></i>BENEFICIARIES & FAMILY</h5>

                        <!-- 1: PARENTS INFORMATION -->
                        <div class="mb-4">
                            <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-person-heart me-2"></i>1. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WAZAZI WA MWANACHAMA' : 'MEMBER\'S PARENTS INFORMATION' ?></h6>
                            <div class="row g-4">
                                <!-- Father -->
                                <div class="col-md-6 border-end">
                                    <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Baba' : 'Father\'s Details' ?></p>
                                    <div class="row g-2">
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">First Name</label><input type="text" name="father_first_name" class="form-control form-control-sm" placeholder="First Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Middle Name</label><input type="text" name="father_middle_name" class="form-control form-control-sm" placeholder="Middle Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Last Name</label><input type="text" name="father_last_name" class="form-control form-control-sm" placeholder="Last Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Phone Number</label><input type="tel" name="father_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Country</label><input type="text" name="father_country" class="form-control form-control-sm" value="Tanzania"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Region / State</label><input type="text" name="father_state" class="form-control form-control-sm" placeholder="Region"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">District</label><input type="text" name="father_district" class="form-control form-control-sm" placeholder="District"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Ward</label><input type="text" name="father_ward" class="form-control form-control-sm" placeholder="Ward"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Street / Village</label><input type="text" name="father_street" class="form-control form-control-sm" placeholder="Street"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">House Number</label><input type="text" name="father_house_number" class="form-control form-control-sm" placeholder="House No."></div>
                                        <div class="col-12"><label class="form-label small mb-1 fw-bold">Passport Photo <span class="text-muted fw-normal">(Optional)</span></label><input type="file" name="father_photo" class="form-control form-control-sm" accept="image/*"></div>
                                    </div>
                                </div>
                                <!-- Mother -->
                                <div class="col-md-6">
                                    <p class="fw-bold text-muted small mb-3 border-bottom pb-1"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Taarifa za Mama' : 'Mother\'s Details' ?></p>
                                    <div class="row g-2">
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">First Name</label><input type="text" name="mother_first_name" class="form-control form-control-sm" placeholder="First Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Middle Name</label><input type="text" name="mother_middle_name" class="form-control form-control-sm" placeholder="Middle Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Last Name</label><input type="text" name="mother_last_name" class="form-control form-control-sm" placeholder="Last Name"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Phone Number</label><input type="tel" name="mother_phone" class="form-control form-control-sm" placeholder="0xxxxxxxxx"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Country</label><input type="text" name="mother_country" class="form-control form-control-sm" value="Tanzania"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Region / State</label><input type="text" name="mother_state" class="form-control form-control-sm" placeholder="Region"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">District</label><input type="text" name="mother_district" class="form-control form-control-sm" placeholder="District"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Ward</label><input type="text" name="mother_ward" class="form-control form-control-sm" placeholder="Ward"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">Street / Village</label><input type="text" name="mother_street" class="form-control form-control-sm" placeholder="Street"></div>
                                        <div class="col-6"><label class="form-label small mb-1 fw-bold">House Number</label><input type="text" name="mother_house_number" class="form-control form-control-sm" placeholder="House No."></div>
                                        <div class="col-12"><label class="form-label small mb-1 fw-bold">Passport Photo <span class="text-muted fw-normal">(Optional)</span></label><input type="file" name="mother_photo" class="form-control form-control-sm" accept="image/*"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 2: FAMILY INFORMATION (CONDITIONAL) -->
                        <div id="familyFields" style="display: none;">
                            <!-- 2: WIFE/HUSBAND INFORMATION -->
                            <div class="mb-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-heart-fill me-2"></i>2. <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA MWENZI' : 'WIFE/HUSBAND INFORMATION' ?></h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">First Name</label>
                                        <input type="text" name="spouse_first_name" class="form-control" placeholder="First Name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Middle Name</label>
                                        <input type="text" name="spouse_middle_name" class="form-control" placeholder="Middle Name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small">Last Name</label>
                                        <input type="text" name="spouse_last_name" class="form-control" placeholder="Last Name">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Email</label>
                                        <input type="email" name="spouse_email" class="form-control" placeholder="spouse@example.com">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small">Phone</label>
                                        <input type="tel" name="spouse_phone" class="form-control" placeholder="0xxxxxxxxx">
                                    </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Gender</label>
                                    <select name="spouse_gender" class="form-select">
                                        <option value="">Select...</option>
                                        <option value="Mwanaume">Male</option>
                                        <option value="Mwanamke">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">DOB</label>
                                    <input type="date" name="spouse_dob" class="form-control">
                                </div>
                                <div class="col-md-4">
                                     <label class="form-label fw-bold small"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Dini' : 'Religion' ?></label>
                                     <div id="spouse_religion_wrapper">
                                         <select name="spouse_religion" class="form-select" onchange="handleSpouseReligionChange(this)">
                                             <option value="Ukristo"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Ukristo' : 'Christianity' ?></option>
                                             <option value="Uislamu"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Uislamu' : 'Islam' ?></option>
                                             <option value="Nyingine"><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Nyingine' : 'Other' ?></option>
                                         </select>
                                     </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">NIDA Number</label>
                                    <input type="text" name="spouse_nida" class="form-control" placeholder="NIDA Number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Region of Birth</label>
                                    <input type="text" name="spouse_birth_region" class="form-control" placeholder="Birth Region">
                                </div>
                            </div>
                        </div>

                        <!-- 3: CHILDREN INFORMATION -->
                            <div class="mb-4">
                                <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><span class="me-2 badge bg-primary">3</span><i class="bi bi-people-fill me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'TAARIFA ZA WATOTO' : 'MEMBER\'S CHILDREN INFORMATION' ?></h6>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle" id="childrenTable">
                                    <thead class="bg-light small">
                                        <tr>
                                            <th class="text-center" style="width: 50px;">S/No</th>
                                            <th>Child Name</th>
                                            <th style="width: 160px;">Date of Birth</th>
                                            <th style="width: 90px;">Age</th>
                                            <th style="width: 130px;">Gender</th>
                                            <th style="width: 160px;">Photo <span class="text-muted fw-normal">(Optional)</span></th>
                                            <th class="text-center" style="width: 50px;">#</th>
                                        </tr>
                                    </thead>
                                    <tbody id="childrenList">
                                        <tr class="child-row">
                                            <td class="text-center fw-bold row-idx">1</td>
                                            <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Child Name"></td>
                                            <td><input type="date" name="child_dob[]" class="form-control form-control-sm border-0 bg-transparent" onchange="vkChildAge(this)"></td>
                                            <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Auto" readonly></td>
                                            <td>
                                                <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                                                    <option value="Mwanaume">Male</option>
                                                    <option value="Mwanamke">Female</option>
                                                </select>
                                            </td>
                                            <td><input type="file" name="child_photo[]" class="form-control form-control-sm border-0 bg-transparent" accept="image/*"></td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill mt-2" onclick="addChildRow()">
                                <i class="bi bi-plus-circle me-1"></i> Add Child
                            </button>
                            </div>
                        </div> <!-- End familyFields -->


                        <!-- 2.4: GUARANTOR INFORMATION -->
                        <div class="mb-4">
                            <h6 class="text-primary border-bottom pb-2 mb-3 fw-bold"><i class="bi bi-shield-check me-2"></i><?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'MDHAMINI WA MWANACHAMA' : 'MEMBER\'S GUARANTOR' ?></h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Guarantor's Name</label>
                                    <input type="text" name="guarantor_name" class="form-control" placeholder="Full Name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Phone Number</label>
                                    <input type="tel" name="guarantor_phone" class="form-control" placeholder="0xxxxxxxxx">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small">Relationship With Member</label>
                                    <input type="text" name="guarantor_rel" class="form-control" placeholder="Relationship">
                                </div>
                                <div class="col-12 mt-1"><small class="text-muted fw-bold">Guarantor's Physical Location</small></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">Country</label><input type="text" name="guarantor_country" class="form-control" value="Tanzania"></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">Region / State</label><input type="text" name="guarantor_state" class="form-control" placeholder="Region"></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">District</label><input type="text" name="guarantor_district" class="form-control" placeholder="District"></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">Ward</label><input type="text" name="guarantor_ward" class="form-control" placeholder="Ward"></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">Street / Village</label><input type="text" name="guarantor_street" class="form-control" placeholder="Street"></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">House Number</label><input type="text" name="guarantor_house_number" class="form-control" placeholder="House No."></div>
                            </div>
                        </div>


                        
                        
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-outline-secondary btn-next px-4" onclick="switchTab('personal')">
                                <i class="bi bi-arrow-left me-2"></i> Back
                            </button>
                            <button type="button" class="btn btn-primary btn-next btn-lg px-4" onclick="switchTab('account')">
                                Continue <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>

                    <!-- PHASE 3: ACCOUNT & PAYMENT -->
                    <div class="tab-pane fade" id="account" role="tabpanel">
                        <h5 class="mb-4 text-primary fw-bold">Step 3: Password & Registration Fee</h5>

                        <div class="alert alert-primary py-2 px-3 small border-0 bg-primary bg-opacity-10 mb-4" style="border-left: 4px solid #0d6efd !important;">
                            <i class="bi bi-person-badge-fill me-2 fs-6"></i>
                            <span id="pub_username_preview">
                                <?= ($_SESSION['preferred_language'] ?? 'en') === 'sw' ? 'Username yako itakuwa ni herufi ya kwanza ya jina lako na jina lako lote la mwisho.' : 'Your Username will be the first letter of your first name plus your full last name.' ?>
                            </span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Password *</label>
                                <div class="input-group">
                                    <input type="password" name="password" id="password" class="form-control" required placeholder="******">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                        <i class="bi bi-eye" id="password_icon"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Confirm Password *</label>
                                <div class="input-group">
                                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required placeholder="******">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                        <i class="bi bi-eye" id="confirm_password_icon"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <div class="card bg-light border-0">
                                    <div class="card-body">
                                        <label class="form-label fw-bold">Registration / Entrance Fee (Tsh)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-white border-end-0">Tsh</span>
                                            <input type="number" name="entrance_fee" class="form-control border-start-0" placeholder="e.g. 20,000">
                                        </div>
                                        <div class="mt-3">
                                            <label class="form-label fw-bold small">Upload Payment Slip *</label>
                                            <input type="file" name="kianzio_slip" id="kianzio_slip" class="form-control" required accept="image/*,.pdf">
                                            <div class="form-text small opacity-75">Accepted formats: JPG, PNG, or PDF</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- LANGUAGE SELECTION -->
                            <div class="col-12 mt-3">
                                <div class="card bg-light border-0 p-3 d-flex flex-row align-items-center justify-content-between" style="border-radius: 12px;">
                                    <div>
                                        <label class="form-label fw-bold mb-1"><i class="bi bi-globe2 me-2 text-primary"></i>Preferred Language <span class="text-muted fw-normal"></span></label>
                                        <p class="text-muted small mb-0">Choose the language for your account. Default is English.</p>
                                    </div>
                                    <div class="d-flex gap-2 mt-2 mt-md-0">
                                        <input type="hidden" name="preferred_language" id="preferred_language" value="en">
                                        <button type="button" id="btn_lang_en" onclick="setLang('en')"
                                            class="btn btn-primary fw-bold px-4 rounded-pill btn-lang"
                                            style="min-width: 100px;">
                                            🇬🇧 English
                                        </button>
                                        <button type="button" id="btn_lang_sw" onclick="setLang('sw')"
                                            class="btn btn-outline-primary fw-bold px-4 rounded-pill btn-lang"
                                            style="min-width: 100px;">
                                            🇹🇿 Kiswahili
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 mt-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="terms" name="terms" value="1" required>
                                    <label class="form-check-label small" for="terms">
                                        I agree to the terms and conditions of this group.
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-5 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary btn-next px-4" onclick="switchTab('residence')">
                                <i class="bi bi-arrow-left me-2"></i> Back
                            </button>
                            <button type="submit" class="btn btn-primary btn-submit btn-lg px-5 shadow">
                                <i class="bi bi-person-plus-fill me-2"></i> COMPLETE REGISTRATION
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            
            <div class="text-center mt-4">
                <p class="mb-0 text-muted">Already have an account? <a href="login.php" class="text-primary fw-bold text-decoration-none">Login Here</a></p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function handleReligionChange(sel) {
        if (sel.value === 'Nyingine') {
            const wrapper = document.getElementById('religion_field_wrapper');
            const isSw = <?= json_encode(($_SESSION['preferred_language'] ?? 'en') === 'sw') ?>;
            wrapper.innerHTML = `
                <div class="input-group">
                    <input type="text" name="religion" class="form-control" placeholder="${isSw ? 'Andika dini yako' : 'Type your religion'}" required>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetReligionSelect()" title="${isSw ? 'Rudia' : 'Reset'}">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            `;
        }
    }

    function handleSpouseReligionChange(select) {
        if (select.value === 'Nyingine') {
            // NOTE: NO 'required' here — field may be hidden/disabled for Single members
            document.getElementById('spouse_religion_wrapper').innerHTML = `
                <div class="input-group">
                    <input type="text" name="spouse_religion" class="form-control" placeholder="Specify religion">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetSpouseReligionSelect()" title="Reset">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            `;
        }
    }

    function resetSpouseReligionSelect() {
        document.getElementById('spouse_religion_wrapper').innerHTML = `
            <select name="spouse_religion" id="spouse_religion_select" class="form-select" onchange="handleSpouseReligionChange(this)">
                <option value="Ukristo">Christianity</option>
                <option value="Uislamu">Islam</option>
                <option value="Nyingine">Other</option>
            </select>
        `;
    }

    // audit/registration: derive a child's age from the entered date of birth.
    function vkChildAge(dobInput) {
        const row = dobInput.closest('tr');
        const ageInput = row ? row.querySelector('input[name="child_age[]"]') : null;
        if (!ageInput) return;
        const d = new Date(dobInput.value);
        if (!dobInput.value || isNaN(d.getTime())) { ageInput.value = ''; return; }
        const now = new Date();
        let age = now.getFullYear() - d.getFullYear();
        const m = now.getMonth() - d.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < d.getDate())) age--;
        ageInput.value = age >= 0 ? age : '';
    }

    function addChildRow() {
        const tbody = document.getElementById('childrenList');
        const rowCount = tbody.getElementsByClassName('child-row').length + 1;
        const newRow = document.createElement('tr');
        newRow.className = 'child-row';
        newRow.innerHTML = `
            <td class="text-center fw-bold row-idx">${rowCount}</td>
            <td><input type="text" name="child_name[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Name"></td>
            <td><input type="date" name="child_dob[]" class="form-control form-control-sm border-0 bg-transparent" onchange="vkChildAge(this)"></td>
            <td><input type="number" name="child_age[]" class="form-control form-control-sm border-0 bg-transparent" placeholder="Auto" readonly></td>
            <td>
                <select name="child_gender[]" class="form-select form-select-sm border-0 bg-transparent">
                    <option value="Mwanaume">Male</option>
                    <option value="Mwanamke">Female</option>
                </select>
            </td>
            <td><input type="file" name="child_photo[]" class="form-control form-control-sm border-0 bg-transparent" accept="image/*"></td>
            <td class="text-center">
                <button type="button" class="btn btn-sm text-danger border-0" onclick="removeRow(this)"><i class="bi bi-trash"></i></button>
            </td>
        `;
        tbody.appendChild(newRow);
        updateRowNumbers();
    }

    function removeRow(btn) {
        const row = btn.closest('tr');
        if (document.getElementsByClassName('child-row').length > 1) {
            row.remove();
            updateRowNumbers();
        }
    }

    function updateRowNumbers() {
        const rows = document.getElementsByClassName('row-idx');
        for (let i = 0; i < rows.length; i++) {
            rows[i].innerText = i + 1;
        }
    }

    function resetReligionSelect() {
        const wrapper = document.getElementById('religion_field_wrapper');
        const isSw = <?= json_encode(($_SESSION['preferred_language'] ?? 'en') === 'sw') ?>;
        wrapper.innerHTML = `
            <select name="religion" id="religion_select" class="form-select" onchange="handleReligionChange(this)">
                <option value="Ukristo">${isSw ? 'Ukristo' : 'Christianity'}</option>
                <option value="Uislamu">${isSw ? 'Uislamu' : 'Islam'}</option>
                <option value="Nyingine">${isSw ? 'Nyingine (Other)' : 'Other'}</option>
            </select>
        `;
    }

    function switchTab(tabId) {
        $('.tab-pane').removeClass('show active');
        $('#' + tabId).addClass('show active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function setLang(lang) {
        $('#preferred_language').val(lang);
        if (lang === 'en') {
            $('#btn_lang_en').removeClass('btn-outline-primary').addClass('btn-primary');
            $('#btn_lang_sw').removeClass('btn-primary').addClass('btn-outline-primary');
        } else {
            $('#btn_lang_sw').removeClass('btn-outline-primary').addClass('btn-primary');
            $('#btn_lang_en').removeClass('btn-primary').addClass('btn-outline-primary');
        }
    }

    $(document).ready(function() {
        // Dynamic Username Preview for Public Form
        function updatePubUsernamePreview() {
            const firstName = $('#pub_first_name').val().trim();
            const lastName = $('#pub_last_name').val().trim();
            const lang = $('#preferred_language').val();
            
            if (firstName !== '' && lastName !== '') {
                const firstInitial = firstName.charAt(0).toLowerCase();
                const lastNameSlug = lastName.toLowerCase().replace(/\s+/g, '');
                const generatedUsername = firstInitial + lastNameSlug;
                
                if (lang === 'sw') {
                    $('#pub_username_preview').html(`Sasa username yako ni: <strong class="text-primary fs-6 ms-1">${generatedUsername}</strong>`);
                } else {
                    $('#pub_username_preview').html(`Your Username will be: <strong class="text-primary fs-6 ms-1">${generatedUsername}</strong>`);
                }
            } else {
                if (lang === 'sw') {
                    $('#pub_username_preview').text('Username yako itakuwa ni herufi ya kwanza ya jina la kwanza na jina lako la mwisho lote.');
                } else {
                    $('#pub_username_preview').text('Your Username will be the first letter of your first name plus your full last name.');
                }
            }
        }

        $('#pub_first_name, #pub_last_name').on('input', updatePubUsernamePreview);
        // Also update when language changes
        window.setLangOrig = window.setLang;
        window.setLang = function(lang) {
            setLangOrig(lang);
            updatePubUsernamePreview();
        };

        // Initialize marital status toggle
        toggleFamilyFields($('#marital_status').val());

        $('#publicRegisterForm').on('submit', function(e) {
            e.preventDefault();

            // Real-time format validation gate: shows the exact problem inline
            // and jumps to the offending field instead of failing silently/late.
            if (typeof validateRegistrationForm === 'function' && !validateRegistrationForm()) {
                return;
            }

            // Detect selected language at submit time
            const lang = $('#preferred_language').val();
            const messages = {
                en: {
                    passwordMismatch: 'Passwords do not match!',
                    passwordError: 'Error',
                    waitLabel: 'Please wait...',
                    successTitle: 'Registration Successful!',
                    errorTitle: 'Error',
                    connectionError: 'Could not connect to the system. Please try again.'
                },
                sw: {
                    passwordMismatch: 'Nywila hazifanani!',
                    passwordError: 'Kosa',
                    waitLabel: 'Subiri...',
                    successTitle: 'Usajili Umefanikiwa!',
                    errorTitle: 'Hitilafu',
                    connectionError: 'Imeshindikana kuunganishwa na mfumo. Jaribu tena.'
                }
            };
            const m = messages[lang] || messages['en'];

            if ($('#password').val() !== $('#confirm_password').val()) {
                Swal.fire(m.passwordError, m.passwordMismatch, 'error');
                return;
            }

            // PAYMENT SLIP VALIDATION
            const slipInput = document.getElementById('kianzio_slip');
            if (!slipInput.files || slipInput.files.length === 0) {
                const slipMsg = lang === 'sw' ? 'Tafadhali pakia risiti ya malipo!' : 'Please upload the payment slip!';
                const slipTitle = lang === 'sw' ? 'Risiti Inahitajika' : 'Receipt Required';
                Swal.fire(slipTitle, slipMsg, 'warning');
                switchTab('account');
                return;
            }
            
            const submitBtn = $('.btn-submit');
            const originalHtml = submitBtn.html();
            submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> ' + m.waitLabel);

            let formData = new FormData(this);

            $.ajax({
                url: 'actions/process_registration.php',
                type: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: m.successTitle,
                            text: response.message,
                            confirmButtonColor: '#0d6efd'
                        }).then(() => {
                            window.location.href = 'login.php';
                        });
                    } else {
                        Swal.fire(m.errorTitle, response.message, 'error');
                        submitBtn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function() {
                    Swal.fire(m.errorTitle, m.connectionError, 'error');
                    submitBtn.prop('disabled', false).html(originalHtml);
                }
            });
        });
    });

    function togglePassword(fieldId) {
        const input = document.getElementById(fieldId);
        const icon = document.getElementById(fieldId + '_icon');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }

    function toggleFamilyFields(status) {
        const familyDiv = document.getElementById('familyFields');
        const inputs = familyDiv.querySelectorAll('input, select');
        if (status !== 'Single') {
            familyDiv.style.display = 'block';
            inputs.forEach(i => i.disabled = false);
        } else {
            familyDiv.style.display = 'none';
            inputs.forEach(i => i.disabled = true);
        }
    }
</script>

<!--
    Real-time format guidance for registration.
    Every field that requires a specific format reports the exact problem the
    moment it happens (on blur / change / typing) and again on submit, instead of
    failing silently or only after the server rejects it. Nothing is removed or
    made newly mandatory here — this only surfaces problems early and clearly.
-->
<script>
(function () {
    'use strict';

    var REG_MESSAGES = {
        en: {
            email:        'Please enter a valid email address, e.g. john@example.com',
            phone:        'Please enter a valid phone number, e.g. 0712345678 or +255712345678',
            passMatch:    'Passwords do not match.',
            slipRequired: 'Please upload your payment slip before submitting.',
            slipType:     'The payment slip must be a JPG, PNG, or PDF file.',
            photoType:    'The passport photo must be a JPG or PNG image.',
            photoSize:    'The passport photo must be smaller than 2MB.',
            nameFormat:   'Please use letters only for the name (e.g. John).',
            nida:         'The NIDA number must be 20 digits.',
            fee:          'The registration fee must be a positive number.',
            childAge:     'Child age must be a number between 0 and 120.',
            fixTitle:     'Please check the form',
            fixText:      'Some fields need your attention — please review the highlighted messages below.'
        },
        sw: {
            email:        'Tafadhali weka barua pepe sahihi, mfano john@example.com',
            phone:        'Tafadhali weka namba sahihi ya simu, mfano 0712345678 au +255712345678',
            passMatch:    'Nywila hazifanani.',
            slipRequired: 'Tafadhali pakia risiti yako ya malipo kabla ya kutuma.',
            slipType:     'Risiti ya malipo lazima iwe faili la JPG, PNG, au PDF.',
            photoType:    'Picha ya pasipoti lazima iwe JPG au PNG.',
            photoSize:    'Picha ya pasipoti lazima iwe chini ya 2MB.',
            nameFormat:   'Tafadhali tumia herufi pekee kwa jina (mfano John).',
            nida:         'Namba ya NIDA lazima iwe na tarakimu 20.',
            fee:          'Ada ya usajili lazima iwe namba chanya.',
            childAge:     'Umri wa mtoto lazima uwe namba kati ya 0 na 120.',
            fixTitle:     'Tafadhali kagua fomu',
            fixText:      'Kuna sehemu zinazohitaji marekebisho — tafadhali angalia ujumbe ulioonyeshwa hapa chini.'
        }
    };

    function regLang() {
        var el = document.getElementById('preferred_language');
        var lang = el ? el.value : 'en';
        return REG_MESSAGES[lang] ? lang : 'en';
    }
    function msg(key) { return REG_MESSAGES[regLang()][key]; }

    function fieldByName(name) { return document.querySelector('[name="' + name + '"]'); }

    function isUsable(input) {
        // visible and enabled (skip disabled/hidden conditional fields, e.g. spouse when Single)
        return !!(input && input.offsetParent !== null && !input.disabled);
    }

    function showError(input, message) {
        if (!input) return;
        input.classList.add('is-invalid');
        var anchor = input.closest('.input-group') || input;
        var fb = anchor.parentNode.querySelector('.reg-feedback[data-for="' + input.name + '"]');
        if (!fb) {
            fb = document.createElement('div');
            fb.className = 'reg-feedback text-danger small mt-1';
            fb.setAttribute('data-for', input.name);
            anchor.parentNode.insertBefore(fb, anchor.nextSibling);
        }
        fb.innerHTML = '<i class="bi bi-exclamation-circle-fill me-1"></i>' + message;
        fb.style.display = 'block';
    }

    function clearError(input) {
        if (!input) return;
        input.classList.remove('is-invalid');
        var anchor = input.closest('.input-group') || input;
        var fb = anchor.parentNode.querySelector('.reg-feedback[data-for="' + input.name + '"]');
        if (fb) fb.style.display = 'none';
    }

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    function validEmail(v) { return EMAIL_RE.test(v.trim()); }
    function validPhone(v) {
        var digits = v.replace(/[\s\-()]/g, '');
        return /^\+?\d{9,13}$/.test(digits);
    }

    // ---- individual validators (return true when OK) ----
    function checkEmail(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; } // emptiness handled by native "required"
        if (!validEmail(v)) { showError(input, msg('email')); return false; }
        clearError(input); return true;
    }
    function checkPhone(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; }
        if (!validPhone(v)) { showError(input, msg('phone')); return false; }
        clearError(input); return true;
    }
    function checkPasswordMatch() {
        var p = document.getElementById('password');
        var c = document.getElementById('confirm_password');
        if (!p || !c) return true;
        if (c.value === '') { clearError(c); return true; }
        if (p.value !== c.value) { showError(c, msg('passMatch')); return false; }
        clearError(c); return true;
    }
    function checkSlip(requireFile) {
        var input = document.getElementById('kianzio_slip');
        if (!input) return true;
        var f = input.files && input.files[0];
        if (!f) {
            if (requireFile) { showError(input, msg('slipRequired')); return false; }
            clearError(input); return true;
        }
        var name = f.name.toLowerCase();
        var ok = /^(image\/jpeg|image\/png|application\/pdf)$/.test(f.type) ||
                 /\.(jpe?g|png|pdf)$/.test(name);
        if (!ok) { showError(input, msg('slipType')); return false; }
        clearError(input); return true;
    }
    function checkPhoto() {
        var input = fieldByName('passport_photo');
        if (!input) return true;
        var f = input.files && input.files[0];
        if (!f) { clearError(input); return true; }
        var name = f.name.toLowerCase();
        var typeOk = f.type === 'image/jpeg' || f.type === 'image/png' || /\.(jpe?g|png)$/.test(name);
        if (!typeOk) { showError(input, msg('photoType')); return false; }
        if (f.size > 2 * 1024 * 1024) { showError(input, msg('photoSize')); return false; }
        clearError(input); return true;
    }
    var NAME_RE = /^[\p{L}][\p{L}\s.'-]{1,49}$/u;
    function checkName(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; }   // emptiness handled by native "required"
        if (!NAME_RE.test(v)) { showError(input, msg('nameFormat')); return false; }
        clearError(input); return true;
    }
    function checkNida(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; }   // optional
        if (v.replace(/\D/g, '').length !== 20) { showError(input, msg('nida')); return false; }
        clearError(input); return true;
    }
    function checkFee(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; }   // optional
        if (isNaN(Number(v)) || Number(v) < 0) { showError(input, msg('fee')); return false; }
        clearError(input); return true;
    }
    function checkChildAge(input) {
        var v = input.value.trim();
        if (v === '') { clearError(input); return true; }   // optional
        if (!/^\d+$/.test(v) || Number(v) > 120) { showError(input, msg('childAge')); return false; }
        clearError(input); return true;
    }

    // ---- full-form gate (called on submit) ----
    function validateRegistrationForm() {
        var ok = true, firstInvalid = null;
        function fail(input) { ok = false; if (!firstInvalid && input) firstInvalid = input; }

        var email = fieldByName('email');
        if (email && !checkEmail(email)) fail(email);

        var phone = fieldByName('phone');
        if (phone && !checkPhone(phone)) fail(phone);

        if (!checkPasswordMatch()) fail(document.getElementById('confirm_password'));
        if (!checkSlip(true))      fail(document.getElementById('kianzio_slip'));
        if (!checkPhoto())         fail(fieldByName('passport_photo'));

        var spEmail = fieldByName('spouse_email');
        if (spEmail && isUsable(spEmail) && !checkEmail(spEmail)) fail(spEmail);

        ['father_phone', 'mother_phone', 'spouse_phone', 'guarantor_phone'].forEach(function (n) {
            var el = fieldByName(n);
            if (el && isUsable(el) && !checkPhone(el)) fail(el);
        });

        ['first_name', 'last_name'].forEach(function (n) {
            var el = fieldByName(n);
            if (el && isUsable(el) && !checkName(el)) fail(el);
        });
        ['nida_number', 'spouse_nida'].forEach(function (n) {
            var el = fieldByName(n);
            if (el && isUsable(el) && !checkNida(el)) fail(el);
        });
        var feeEl = fieldByName('entrance_fee');
        if (feeEl && isUsable(feeEl) && !checkFee(feeEl)) fail(feeEl);
        document.querySelectorAll('input[name="child_age[]"]').forEach(function (el) {
            if (isUsable(el) && !checkChildAge(el)) fail(el);
        });

        if (!ok && firstInvalid) {
            var pane = firstInvalid.closest('.tab-pane');
            if (pane && typeof switchTab === 'function') switchTab(pane.id);
            setTimeout(function () { try { firstInvalid.focus(); } catch (e) {} }, 150);
            if (window.Swal) { Swal.fire(msg('fixTitle'), msg('fixText'), 'warning'); }
        }
        return ok;
    }
    window.validateRegistrationForm = validateRegistrationForm;

    // ---- live wiring: report the moment the format is wrong ----
    function attach(input, validator) {
        if (!input) return;
        input.addEventListener('blur', function () { validator(input); });
        input.addEventListener('input', function () {
            if (input.classList.contains('is-invalid')) validator(input);
        });
    }

    function setup() {
        attach(fieldByName('email'), checkEmail);
        attach(fieldByName('spouse_email'), checkEmail);
        attach(fieldByName('phone'), checkPhone);
        ['father_phone', 'mother_phone', 'spouse_phone', 'guarantor_phone'].forEach(function (n) {
            attach(fieldByName(n), checkPhone);
        });

        var p = document.getElementById('password');
        var c = document.getElementById('confirm_password');
        if (p) p.addEventListener('input', checkPasswordMatch);
        if (c) { c.addEventListener('input', checkPasswordMatch); c.addEventListener('blur', checkPasswordMatch); }

        var slip = document.getElementById('kianzio_slip');
        if (slip) slip.addEventListener('change', function () { checkSlip(false); });

        var photo = fieldByName('passport_photo');
        if (photo) photo.addEventListener('change', function () { checkPhoto(); });

        attach(fieldByName('first_name'), checkName);
        attach(fieldByName('last_name'), checkName);
        attach(fieldByName('nida_number'), checkNida);
        attach(fieldByName('spouse_nida'), checkNida);
        attach(fieldByName('entrance_fee'), checkFee);

        // Child age rows can be added dynamically — use delegation.
        document.addEventListener('focusout', function (e) {
            if (e.target && e.target.name === 'child_age[]') checkChildAge(e.target);
        });
        document.addEventListener('input', function (e) {
            if (e.target && e.target.name === 'child_age[]' && e.target.classList.contains('is-invalid')) checkChildAge(e.target);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
</script>

</body>
</html>
