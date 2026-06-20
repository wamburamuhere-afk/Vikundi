<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/backup.php';
// header.php loads config ($pdo), permissions, getUrl, and auto-enforces the
// page permission for 'backup_restore'.
require_once __DIR__ . '/../../../header.php';

// Second layer of defence: backup & restore is destructive (a restore
// overwrites the whole database), so it stays admin-only even if header.php's
// permission gate is later relaxed for other roles.
if (!isAdmin()) {
    header('Location: ' . getUrl('dashboard'));
    exit;
}

$backupsDir = ROOT_DIR . '/backups/';
if (!is_dir($backupsDir)) mkdir($backupsDir, 0755, true);

$autoBackupNotice = '';

// ── Auto daily backup (best-effort safety net) ───────────────────────────────
// Fires when an admin opens this page and a day has passed since the last auto
// backup. Uses the shared dump helper (views handled) and prunes auto/pre_restore
// files older than 7 days. Manual/uploaded backups are never auto-pruned.
function runAutoBackup($pdo, $backupsDir) {
    $markerFile = $backupsDir . '.last_auto_backup';
    $lastRun = file_exists($markerFile) ? (int) file_get_contents($markerFile) : 0;
    if ((time() - $lastRun) < 86400) return null;

    $filename = 'auto_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupsDir . $filename;
    try {
        vikundi_write_dump($pdo, $filepath);
        file_put_contents($markerFile, time());
        vikundi_prune_backups($backupsDir, 7);
        return $filename;
    } catch (Exception $e) {
        if (file_exists($filepath)) @unlink($filepath);
        error_log("Auto backup failed: " . $e->getMessage());
        return null;
    }
}

$autoResult = runAutoBackup($pdo, $backupsDir);
if ($autoResult) $autoBackupNotice = $autoResult;

$backups = array_filter(glob($backupsDir . '*.sql'), 'is_file');
rsort($backups);
$dbSize  = vikundi_db_size_mb($pdo);
$apiUrl  = getUrl('api/backup_actions');
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-hdd-network"></i> Backup &amp; Restore</h2>
                    <p class="text-muted mb-0">Manage database backups and system restoration points</p>
                </div>
                <span class="badge bg-info p-2 rounded-pill fs-6">Database: <?= htmlspecialchars((string) $dbSize) ?> MB</span>
            </div>

            <?php if ($autoBackupNotice): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-clock-history me-2"></i>
                    <strong>Auto backup created:</strong> <?= htmlspecialchars($autoBackupNotice) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Top Cards ── -->
    <div class="row">

        <!-- Create Backup -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-cloud-arrow-down text-primary" style="font-size:3rem;"></i>
                    </div>
                    <h5 class="fw-bold">Create New Backup</h5>
                    <p class="text-muted small mb-4">Generate a complete snapshot of your current database state.</p>
                    <button type="button" class="btn btn-primary w-100 py-2" onclick="createBackup()">
                        <i class="bi bi-plus-circle me-2"></i>Generate Backup
                    </button>
                </div>
            </div>
        </div>

        <!-- Upload & Restore -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4">
                <div class="card-body p-4 text-center">
                    <div class="mb-3">
                        <i class="bi bi-cloud-arrow-up text-success" style="font-size:3rem;"></i>
                    </div>
                    <h5 class="fw-bold">Restore from File</h5>
                    <p class="text-muted small mb-4">Upload a .sql file to restore your database to a previous state.</p>
                    <div class="input-group mb-3">
                        <input type="file" class="form-control" id="uploadBackupFile" accept=".sql">
                    </div>
                    <button type="button" class="btn btn-success w-100 py-2" onclick="uploadRestore()">
                        <i class="bi bi-upload me-2"></i>Upload &amp; Restore
                    </button>
                </div>
            </div>
        </div>

        <!-- Info -->
        <div class="col-md-4 mb-4">
            <div class="card border-0 shadow-sm h-100 rounded-4 bg-light">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>Important Notes</h5>
                    <ul class="text-muted small ps-3 mb-0">
                        <li class="mb-2">Restoring a backup will <strong>overwrite</strong> all current data.</li>
                        <li class="mb-2">A pre-restore snapshot is taken automatically before every restore.</li>
                        <li class="mb-2">Auto backups run daily and keep the last 7 files.</li>
                        <li>Large restores may take a few minutes.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Backups Table ── -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white p-4 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">Existing Backups</h5>
                    <span class="badge bg-secondary"><?= count($backups) ?> Files</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="backupsTable" class="table table-hover align-middle mb-0 w-100">
                            <thead class="bg-light text-muted small text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">Filename</th>
                                    <th>Date Created</th>
                                    <th>Size</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="backupsTableBody">
                                <?php foreach ($backups as $backup):
                                    $fn    = basename($backup);
                                    $bytes = filesize($backup);
                                    $fsize = $bytes >= 1048576
                                        ? round($bytes / 1048576, 2) . ' MB'
                                        : round($bytes / 1024, 2) . ' KB';
                                    $fdate = date('d M Y, h:i A', filemtime($backup));
                                    $fnJs  = addslashes($fn);
                                ?>
                                    <tr id="row-<?= md5($fn) ?>">
                                        <td class="ps-4 fw-bold text-dark">
                                            <i class="bi bi-file-earmark-code text-secondary me-2"></i>
                                            <?= htmlspecialchars($fn) ?>
                                        </td>
                                        <td class="text-muted"><?= htmlspecialchars($fdate) ?></td>
                                        <td><?= htmlspecialchars($fsize) ?></td>
                                        <td class="text-end pe-4">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    <i class="bi bi-gear"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <button type="button" class="dropdown-item"
                                                            onclick="restoreBackup('<?= $fnJs ?>')">
                                                            <i class="bi bi-clock-history me-2 text-warning"></i> Restore
                                                        </button>
                                                    </li>
                                                    <li>
                                                        <a href="<?= htmlspecialchars(getUrl('download_backup')) ?>?file=<?= urlencode($fn) ?>"
                                                           class="dropdown-item">
                                                            <i class="bi bi-download me-2 text-primary"></i> Download
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <button type="button" class="dropdown-item text-danger"
                                                            onclick="deleteBackup('<?= $fnJs ?>', '<?= md5($fn) ?>')">
                                                            <i class="bi bi-trash me-2"></i> Delete
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const BACKUP_API = '<?= addslashes($apiUrl) ?>';

// DataTable on the backups list (search / sort / paginate; Actions column not
// sortable). Guarded so a missing DataTables plugin never breaks the buttons.
$(function () {
    if ($.fn.DataTable && !$.fn.DataTable.isDataTable('#backupsTable')) {
        $('#backupsTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [],
            columnDefs: [{ orderable: false, targets: -1 }],
            language: { emptyTable: 'No backups found. Create one to get started.', zeroRecords: 'No matching backups.' }
        });
    }
});

// ── Shared POST helper ───────────────────────────────────────────────────────
// NOTE: Vikundi has no CSRF subsystem yet, so (unlike BMS) no X-CSRF-Token
// header is sent. Access is gated server-side by session + canDelete().
function backupPost(data, isFormData = false) {
    if (!isFormData) {
        return fetch(BACKUP_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        }).then(r => r.json());
    }
    return fetch(BACKUP_API, { method: 'POST', body: data }).then(r => r.json());
}

function showLoading(title, text) {
    Swal.fire({ title, text, allowOutsideClick: false, allowEscapeKey: false, didOpen: () => Swal.showLoading() });
}

// ── CREATE BACKUP ────────────────────────────────────────────────────────────
function createBackup() {
    Swal.fire({
        icon: 'question',
        title: 'Generate Backup?',
        text: 'A full snapshot of the current database will be created.',
        showCancelButton: true,
        confirmButtonText: 'Yes, generate it',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        showLoading('Creating Backup…', 'Please wait, this may take a moment.');
        backupPost({ action: 'create_backup' })
            .then(res => {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Backup Created',
                        html: `<p>${res.message}</p><p class="text-muted small mb-0"><strong>${res.filename}</strong> &mdash; ${res.size}</p>`,
                        confirmButtonText: 'OK'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Failed', text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.' }));
    });
}

// ── RESTORE FROM EXISTING BACKUP ─────────────────────────────────────────────
function restoreBackup(filename) {
    Swal.fire({
        icon: 'warning',
        title: 'Restore Database?',
        html: `<p>You are about to restore:</p>
               <p class="fw-bold text-dark">${filename}</p>
               <p class="text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>
               This will <strong>overwrite all current data</strong>. This action cannot be undone.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, restore it',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        showLoading('Restoring Database…', 'Please wait — do not close this page.');
        backupPost({ action: 'restore_backup', filename })
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Restore Successful', text: res.message, confirmButtonText: 'OK' })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Restore Failed', text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.' }));
    });
}

// ── UPLOAD & RESTORE ─────────────────────────────────────────────────────────
function uploadRestore() {
    const fileInput = document.getElementById('uploadBackupFile');
    if (!fileInput.files.length) {
        Swal.fire({ icon: 'warning', title: 'No File Selected', text: 'Please select a .sql backup file first.' });
        return;
    }
    const file = fileInput.files[0];
    if (!file.name.toLowerCase().endsWith('.sql')) {
        Swal.fire({ icon: 'error', title: 'Invalid File', text: 'Only .sql files are allowed.' });
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: 'Upload & Restore?',
        html: `<p>You are about to upload and restore:</p>
               <p class="fw-bold text-dark">${file.name}</p>
               <p class="text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>
               This will <strong>overwrite all current data</strong>. This action cannot be undone.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Yes, upload & restore',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        showLoading('Uploading & Restoring…', 'Please wait — do not close this page.');
        const fd = new FormData();
        fd.append('action', 'upload_restore');
        fd.append('backup_file', file);
        backupPost(fd, true)
            .then(res => {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Restore Successful', text: res.message, confirmButtonText: 'OK' })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Restore Failed', text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.' }));
    });
}

// ── DELETE BACKUP ────────────────────────────────────────────────────────────
function deleteBackup(filename, rowHash) {
    Swal.fire({
        icon: 'warning',
        title: 'Delete Backup?',
        html: `<p class="fw-bold text-dark">${filename}</p><p class="mb-0">This backup file will be permanently deleted.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc3545',
        cancelButtonText: 'Cancel',
        reverseButtons: true
    }).then(result => {
        if (!result.isConfirmed) return;
        backupPost({ action: 'delete_backup', filename })
            .then(res => {
                if (res.success) {
                    const row = document.getElementById('row-' + rowHash);
                    if (row) row.remove();
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, timer: 1800, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Failed', text: res.message });
                }
            })
            .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.' }));
    });
}
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
