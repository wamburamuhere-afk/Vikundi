<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Handle document actions (Delete/Download) - MUST BE BEFORE HEADER for downloads
$action = $_GET['action'] ?? '';
$document_id = isset($_GET['document_id']) ? (int)$_GET['document_id'] : 0;

if ($action === 'download' && $document_id > 0) {
    downloadDocumentLocal($pdo, $document_id);
    exit;
}

// Enforce permission
requireViewPermission('library');
require_once 'header.php';

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    $response = handleDocumentUploadLocal($pdo, $_POST, $_FILES);
    $lang_upload = $_SESSION['preferred_language'] ?? 'en';
    if ($response['success']) {
        $msg = ($lang_upload === 'sw') ? 'Hati imepakiwa kwa mafanikio!' : 'Document uploaded successfully!';
        $btn = ($lang_upload === 'sw') ? 'Sawa' : 'OK';
        echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = bootstrap.Modal.getInstance(document.getElementById('uploadDocumentModal'));
    if (modal) modal.hide();
    Swal.fire({
        icon: 'success',
        title: '$msg',
        confirmButtonText: '$btn',
        confirmButtonColor: '#198754'
    }).then(function() {
        if (window.documentsTableInstance) {
            window.documentsTableInstance.ajax.reload();
        }
    });
});
</script>";
    } else {
        $err_title = ($lang_upload === 'sw') ? 'Hitilafu!' : 'Error!';
        $err_btn   = ($lang_upload === 'sw') ? 'Sawa' : 'OK';
        $err_msg   = htmlspecialchars($response['message']);
        echo "<script>
document.addEventListener('DOMContentLoaded', function() {
    Swal.fire({
        icon: 'error',
        title: '$err_title',
        text: '$err_msg',
        confirmButtonText: '$err_btn',
        confirmButtonColor: '#dc3545'
    });
});
</script>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_delete_doc_id'])) {
    $did = (int)$_POST['_delete_doc_id'];
    $response = deleteDocumentLocal($pdo, $did);
    if ($response['success']) {
        header('Location: ' . getUrl('library') . '?msg=deleted'); exit;
    } else {
        header('Location: ' . getUrl('library') . '?delerr=' . urlencode($response['message'])); exit;
    }
}
// download section moved to top

// Helper functions (kept from original but renamed to avoid conflicts if needed)
function handleDocumentUploadLocal($pdo, $post_data, $files) {
    try {
        $upload_dir = 'uploads/document_library/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory");
            }
        }

        $file = $files['document_file'];
        $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'txt', 'zip', 'rar'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 50 * 1024 * 1024; // 50MB

        if (!in_array($file_ext, $allowed_types)) {
            throw new Exception("File type not allowed.");
        }

        if ($file['size'] > $max_size) {
            throw new Exception("File size exceeds 50MB limit");
        }

        $filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file['name']);
        $target_path = $upload_dir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $target_path)) {
            throw new Exception("Failed to upload file");
        }

        $category_id = !empty($post_data['category_id']) ? $post_data['category_id'] : null;
        if ($category_id === 'other' && !empty($post_data['other_category'])) {
            $chk = $pdo->prepare("SELECT id FROM document_categories WHERE category_name = ? OR category_name_sw = ?");
            $chk->execute([$post_data['other_category'], $post_data['other_category']]);
            $existing = $chk->fetchColumn();
            if ($existing) {
                $category_id = $existing;
            } else {
                $ins = $pdo->prepare("INSERT INTO document_categories (category_name, category_name_sw) VALUES (?, ?)");
                $ins->execute([$post_data['other_category'], $post_data['other_category']]);
                $category_id = $pdo->lastInsertId();
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO documents (
                document_name, description, file_path, original_filename, 
                file_size, file_type, category_id, version, tags, access_level, uploaded_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $post_data['document_name'],
            $post_data['description'] ?? '',
            $target_path,
            $file['name'],
            $file['size'],
            $file_ext,
            $category_id,
            $post_data['version'] ?? '1.0',
            $post_data['tags'] ?? '',
            $post_data['access_level'] ?? 'private',
            $_SESSION['user_id']
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function deleteDocumentLocal($pdo, $document_id) {
    try {
        $stmt = $pdo->prepare("SELECT file_path, uploaded_by FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) throw new Exception("Document not found");

        if ($_SESSION['user_role'] !== 'Admin' && $document['uploaded_by'] != $_SESSION['user_id']) {
            throw new Exception("Permission denied");
        }

        if (file_exists($document['file_path'])) {
            unlink($document['file_path']);
        }

        $pdo->prepare("DELETE FROM document_downloads WHERE document_id = ?")->execute([$document_id]);
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function downloadDocumentLocal($pdo, $document_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            die("Document record not found.");
        }

        $file_path = $document['file_path'];
        // Fix path if it's relative but we are in the root file
        if (!file_exists($file_path) && file_exists('uploads/document_library/' . basename($file_path))) {
             $file_path = 'uploads/document_library/' . basename($file_path);
        }

        if (!file_exists($file_path)) {
            die("Physical file not found at: " . $file_path);
        }

        $pdo->prepare("INSERT INTO document_downloads (document_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")
            ->execute([$document_id, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
        
        $pdo->prepare("UPDATE documents SET download_count = download_count + 1 WHERE id = ?")->execute([$document_id]);

        // IMPORTANT: Clean ALL buffers to prevent corruption
        while (ob_get_level()) {
            ob_end_clean();
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        if (!$mime_type || $mime_type == 'text/plain') {
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if ($ext == 'pdf') $mime_type = 'application/pdf';
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . ($mime_type ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . $document['original_filename'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
        // Final check to ensure no output
        if (headers_sent()) {
            die("Headers already sent. Cannot download file.");
        }
        
        readfile($file_path);
        exit;
    } catch (Exception $e) {
        die("Download Error: " . $e->getMessage());
    }
}

$categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$lang  = $_SESSION['preferred_language'] ?? 'en';
$is_sw = ($lang === 'sw');
$flash_msg = $_GET['msg']   ?? '';
$flash_err = urldecode($_GET['delerr'] ?? '');
?>

<?php if ($flash_msg === 'deleted'): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'success',title:'<?= $is_sw ? "Imefutwa!" : "Deleted!" ?>',text:'<?= $is_sw ? "Hati imefutwa kwa mafanikio." : "Document deleted successfully." ?>',confirmButtonColor:'#0d6efd',confirmButtonText:'<?= $is_sw ? "Sawa" : "OK" ?>'}));</script>
<?php elseif (!empty($flash_err)): ?>
<script>document.addEventListener('DOMContentLoaded',()=>Swal.fire({icon:'error',title:'<?= $is_sw ? "Hitilafu!" : "Error!" ?>',text:'<?= addslashes($flash_err) ?>',confirmButtonColor:'#dc3545',confirmButtonText:'<?= $is_sw ? "Sawa" : "OK" ?>'}));</script>
<?php endif; ?>

<!-- Hidden form for delete — submitted by SweetAlert, no AJAX needed -->
<form id="deleteDocForm" method="POST" style="display:none;">
    <input type="hidden" name="_delete_doc_id" id="_delete_doc_id_val" value="">
</form>

<div class="container-fluid px-4 mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-folder2-open"></i> <?= $is_sw ? 'Maktaba ya Nyaraka' : 'Document Library' ?></h2>
                    <p class="text-muted mb-0"><?= $is_sw ? 'Simamia na panga nyaraka za kikundi chako kwa usalama' : 'Manage and organize your group documents securely' ?></p>
                </div>
                <div>
                    <?php if (canCreate('documents')): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="bi bi-cloud-upload"></i> <?= $is_sw ? 'Pakia Hati' : 'Upload Document' ?>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success btn-sm" id="exportDocuments">
                        <i class="bi bi-download"></i> <?= $is_sw ? 'Hamisha Orodha' : 'Export List' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-docs">0</h4>
                            <p class="mb-0"><?= $is_sw ? 'Jumla ya Nyaraka' : 'Total Documents' ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-files" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-size">0 KB</h4>
                            <p class="mb-0"><?= $is_sw ? 'Nafasi Iliyotumika' : 'Storage Used' ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-hdd-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-recent-uploads">0</h4>
                            <p class="mb-0"><?= $is_sw ? 'Zilizopakiwa Hivi Karibuni' : 'Recent Uploads' ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cloud-arrow-up" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-recent-downloads">0</h4>
                            <p class="mb-0"><?= $is_sw ? 'Zilizopakuliwa Hivi Karibuni' : 'Recent Downloads' ?></p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-download" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> <?= $is_sw ? 'Vichujio na Utafutaji' : 'Filters & Search' ?></h6>
        </div>
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label"><?= $is_sw ? 'Aina ya Nyaraka' : 'Category' ?></label>
                        <select class="form-select" id="categoryFilter">
                            <option value=""><?= $is_sw ? 'Aina Zote' : 'All Categories' ?></option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= $is_sw ? htmlspecialchars($cat['category_name_sw'] ?: $cat['category_name']) : htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= $is_sw ? 'Aina ya Faili' : 'File Type' ?></label>
                        <select class="form-select" id="typeFilter">
                            <option value=""><?= $is_sw ? 'Aina Zote' : 'All Types' ?></option>
                            <option value="pdf">PDF</option>
                            <option value="doc">Word (.doc/docx)</option>
                            <option value="xls">Excel (.xls/xlsx)</option>
                            <option value="jpg"><?= $is_sw ? 'Picha (JPG/PNG)' : 'Image (JPG/PNG)' ?></option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label"><?= $is_sw ? 'Kiwango cha Ufikiaji' : 'Access Level' ?></label>
                        <select class="form-select" id="accessFilter">
                            <option value=""><?= $is_sw ? 'Yote' : 'All Access' ?></option>
                            <option value="public"><?= $is_sw ? 'Wazi' : 'Public' ?></option>
                            <option value="private"><?= $is_sw ? 'Binafsi' : 'Private' ?></option>
                            <option value="restricted"><?= $is_sw ? 'Mdhibitiwa' : 'Restricted' ?></option>
                        </select>
                    </div>
                    <div class="col-md-12 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="bi bi-arrow-clockwise"></i> <?= $is_sw ? 'Futa' : 'Clear' ?>
                        </button>
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-filter"></i> <?= $is_sw ? 'Chuja' : 'Apply Filters' ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Documents Table Card -->
    <div class="card">
        <div class="card-header custom-table-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?= $is_sw ? 'Orodha ya Nyaraka' : 'Document List' ?></h5>
                <span class="badge bg-light text-dark" id="stat-records-filtered">0 <?= $is_sw ? 'nyaraka' : 'documents' ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="documentsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th style="width: 50px;"><?= $is_sw ? 'Namba' : 'S/NO' ?></th>
                            <th><?= $is_sw ? 'Jina la Hati' : 'Document Name' ?></th>
                            <th><?= $is_sw ? 'Aina' : 'Category' ?></th>
                            <th><?= $is_sw ? 'Ukubwa' : 'Size' ?></th>
                            <th><?= $is_sw ? 'Vipakuliwa' : 'Downloads' ?></th>
                            <th><?= $is_sw ? 'Aliyepakia' : 'Uploaded By' ?></th>
                            <th><?= $is_sw ? 'Tarehe' : 'Uploaded At' ?></th>
                            <th><?= $is_sw ? 'Ufikiaji' : 'Access' ?></th>
                            <th class="text-end"><?= $is_sw ? 'Vitendo' : 'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Upload Document Modal -->
<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-cloud-upload"></i> <?= $is_sw ? 'Pakia Hati Mpya' : 'Upload New Document' ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="document_name" class="form-label"><?= $is_sw ? 'Jina la Hati' : 'Document Title' ?></label>
                            <input type="text" class="form-control" id="document_name" name="document_name" required placeholder="<?= $is_sw ? 'mfano: Katiba ya Kikundi' : 'e.g. Group Constitution' ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="category_id" class="form-label"><?= $is_sw ? 'Aina ya Nyaraka' : 'Category' ?></label>
                            <div id="category_select_wrapper">
                                <select class="form-select" id="category_id" name="category_id" onchange="toggleCategoryInput(this)">
                                    <option value=""><?= $is_sw ? 'Chagua Aina' : 'Select Category' ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['id'] ?>"><?= $is_sw ? htmlspecialchars($cat['category_name_sw'] ?: $cat['category_name']) : htmlspecialchars($cat['category_name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="other"><?= $is_sw ? 'Nyingine (Taja)' : 'Other (Specify)' ?></option>
                                </select>
                            </div>
                            <div id="category_text_wrapper" style="display:none;">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="other_category" id="other_category" placeholder="<?= $is_sw ? 'Taja aina ya hati...' : 'Specify document type...' ?>">
                                    <button class="btn btn-outline-secondary" type="button" onclick="resetCategorySelect()"><i class="bi bi-x-lg"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label"><?= $is_sw ? 'Maelezo' : 'Description' ?></label>
                            <textarea class="form-control" id="description" name="description" rows="2" placeholder="<?= $is_sw ? 'Maelezo mafupi ya hati hii...' : 'Brief details about the document...' ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="version" class="form-label"><?= $is_sw ? 'Toleo' : 'Version' ?></label>
                            <input type="text" class="form-control" id="version" name="version" value="1.0">
                        </div>
                        <div class="col-md-6">
                            <label for="access_level" class="form-label"><?= $is_sw ? 'Kiwango cha Ufikiaji' : 'Access Level' ?></label>
                            <select class="form-select" id="access_level" name="access_level">
                                <option value="private"><?= $is_sw ? 'Binafsi' : 'Private' ?></option>
                                <option value="restricted"><?= $is_sw ? 'Mdhibitiwa' : 'Restricted' ?></option>
                                <option value="public"><?= $is_sw ? 'Wazi' : 'Public' ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="tags" class="form-label"><?= $is_sw ? 'Lebo (tenganisha kwa koma)' : 'Tags (comma separated)' ?></label>
                            <input type="text" class="form-control" id="tags" name="tags" placeholder="<?= $is_sw ? 'katiba, mkutano, michango' : 'constitution, meeting, contributions' ?>">
                        </div>
                        <div class="col-12">
                            <label for="document_file" class="form-label"><?= $is_sw ? 'Chagua Faili' : 'File Selection' ?></label>
                            <input type="file" class="form-control" id="document_file" name="document_file" required>
                            <div class="form-text"><?= $is_sw ? 'PDF, Word, Excel, Picha zinaruhusiwa. Ukubwa wa juu ni 50MB.' : 'PDF, Word, Excel, Images are allowed. Max size 50MB.' ?></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= $is_sw ? 'Ghairi' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary" <?= !canCreate('library') ? 'disabled title="No Permission"' : '' ?>><?= $is_sw ? 'Pakia Sasa' : 'Start Upload' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<?php
// Calculate direct base URL to bypass router for API calls
$doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
$proj_root = str_replace('\\', '/', ROOT_DIR);
$base_path = trim(str_ireplace($doc_root, '', $proj_root), '/');
$api_base = (!empty($base_path) ? '/' . $base_path : '') . '/api';
?>
<script>
$(document).ready(function() {
    const userPermissions = {
        canEdit: <?= canEdit('library') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('library') ? 'true' : 'false' ?>
    };

    const table = $('#documentsTable').DataTable({
        dom: "<'row'<'col-12'tr>><'row mt-3'<'col-md-5'i><'col-md-7'p>>",
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= $api_base ?>/get_documents.php',
            data: function(d) {
                d.category_id = $('#categoryFilter').val();
                d.file_type = $('#typeFilter').val();
                d.access_level = $('#accessFilter').val();
            },
            dataSrc: function(json) {
                const stats = json.stats;
                $('#stat-total-docs').text(stats.totalDocuments);
                $('#stat-total-size').text(formatFileSize(stats.totalSize));
                $('#stat-recent-uploads').text(stats.recentUploads);
                $('#stat-recent-downloads').text(stats.recentDownloads);
                $('#stat-records-filtered').text(json.recordsFiltered + ' ' + (_sw ? 'nyaraka' : 'documents'));
                return json.data;
            }
        },
        language: {
            processing: _sw ? 'Inapakia...' : 'Processing...',
            lengthMenu: _sw ? 'Onyesha _MENU_ nyaraka' : 'Show _MENU_ documents',
            zeroRecords: _sw ? 'Hakuna nyaraka zilizopatikana' : 'No documents found',
            info: _sw ? 'Inaonyesha _START_ hadi _END_ kati ya _TOTAL_' : 'Showing _START_ to _END_ of _TOTAL_',
            infoEmpty: _sw ? 'Inaonyesha 0 hadi 0 kati ya 0' : 'Showing 0 to 0 of 0',
            infoFiltered: _sw ? '(imechujwa kutoka _MAX_ jumla)' : '(filtered from _MAX_ total)',
            search: _sw ? 'Tafuta:' : 'Search:',
            paginate: {
                first: _sw ? 'Mwanzo' : 'First',
                last: _sw ? 'Mwisho' : 'Last',
                next: _sw ? 'Mbele' : 'Next',
                previous: _sw ? 'Nyuma' : 'Previous'
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                className: 'text-center',
                render: (data, t, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'document_name',
                render: function(data, t, row) {
                    return `
                    <div class="d-flex align-items-center">
                        <i class="bi ${getFileIcon(row.file_type)} ${getFileIconColor(row.file_type)} fs-4 me-2"></i>
                        <div>
                            <strong>${escapeHtml(data)}</strong><br>
                            <small class="text-muted">${escapeHtml(row.original_filename)}</small>
                        </div>
                    </div>`;
                }
            },
            { 
                data: 'category_name',
                render: (data, t, row) => {
                    return data ? `<span class="badge mb-1" style="background-color: ${row.category_color || '#6c757d'}">${escapeHtml(data)}</span>` : '<span class="text-muted small">General</span>';
                }
            },
            { 
                data: 'file_size',
                render: function(data) { return formatFileSize(data); }
            },
            { 
                data: 'download_count',
                className: 'text-center'
            },
            { data: 'uploaded_by_name' },
            { 
                data: 'uploaded_at',
                render: function(data) {
                    return new Date(data).toLocaleDateString(_sw ? 'sw-TZ' : 'en-US', {month:'short', day:'numeric', year:'numeric'});
                }
            },
            { 
                data: 'access_level',
                render: function(data) {
                    let color = data === 'public' ? 'success' : (data === 'restricted' ? 'warning' : 'secondary');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle text-capitalize px-3">${data}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: function(data, t, row) {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="document_library.php?action=download&document_id=${row.id}"><i class="bi bi-download"></i> ${_sw ? 'Pakua' : 'Download'}</a></li>
                            <li><a class="dropdown-item" href="${row.file_path}" target="_blank"><i class="bi bi-eye"></i> ${_sw ? 'Angalia' : 'View Online'}</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.id})"><i class="bi bi-trash"></i> ${_sw ? 'Futa' : 'Delete'}</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        order: [[6, 'desc']],
        buttons: [
            {
                extend: 'excelHtml5',
                title: 'Vikundi Document List',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }
            },
            {
                extend: 'pdfHtml5',
                title: 'Vikundi Document List',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6, 7] }
            }
        ]
    });

    // Handle the custom export button click
    $('#exportDocuments').on('click', function() {
        table.button('.buttons-excel').trigger();
    });

    // Assign globally so SweetAlert reload works
    window.documentsTableInstance = table;
});

function applyFilters() { $('#documentsTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#filterForm')[0].reset();
    $('#documentsTable').DataTable().ajax.reload();
}

const _sw = <?= ($is_sw ? 'true' : 'false') ?>;
function confirmDelete(id) {
    Swal.fire({
        icon: 'warning',
        title: _sw ? 'Una uhakika?' : 'Are you sure?',
        text: _sw ? 'Hati hii itafutwa kabisa!' : 'This document will be permanently deleted!',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: _sw ? 'Ndio, Futa!' : 'Yes, Delete!',
        cancelButtonText: _sw ? 'Ghairi' : 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('_delete_doc_id_val').value = id;
            document.getElementById('deleteDocForm').submit();
        }
    });
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function getFileIcon(ext) {
    const icons = {
        pdf: 'bi-file-earmark-pdf',
        doc: 'bi-file-earmark-word',
        docx: 'bi-file-earmark-word',
        xls: 'bi-file-earmark-excel',
        xlsx: 'bi-file-earmark-excel',
        png: 'bi-file-earmark-image',
        jpg: 'bi-file-earmark-image',
        jpeg: 'bi-file-earmark-image',
        zip: 'bi-file-earmark-zip',
        txt: 'bi-file-earmark-text'
    };
    return icons[ext] || 'bi-file-earmark';
}

function getFileIconColor(ext) {
    if (ext === 'pdf') return 'text-danger';
    if (ext === 'doc' || ext === 'docx') return 'text-primary';
    if (ext === 'xls' || ext === 'xlsx') return 'text-success';
    if (ext.match(/jpg|jpeg|png|gif/)) return 'text-info';
    return 'text-secondary';
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}

function toggleCategoryInput(select) {
    if (select.value === 'other') {
        $('#category_select_wrapper').hide();
        $('#category_text_wrapper').show();
        $('#other_category').focus();
    }
}

function resetCategorySelect() {
    $('#category_text_wrapper').hide();
    $('#other_category').val('');
    $('#category_select_wrapper').show();
    $('#category_id').val('');
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border: none !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(25, 135, 84, 0.1); }

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
}

.custom-code {
    color: #0d6efd !important;
    background-color: #f0f7ff !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.custom-table-header { border-bottom: 2px solid #e9ecef; }
#documentsTable thead th { font-weight: 600; border-bottom: none; }
.dropdown-toggle::after { display: none; }

/* FIX: Modal overlap with high z-index header */
.modal { z-index: 100005 !important; }
.modal-backdrop { z-index: 100004 !important; }
</style>

<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>