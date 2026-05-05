<?php
require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';

$sources = ['Website', 'Referral', 'Social Media', 'Event', 'Advertisement', 'Cold Call', 'Other'];
$statuses = ['New', 'Contacted', 'Qualified', 'Converted', 'Lost', 'Nurturing'];
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-person-plus"></i> Lead Generation</h2>
                    <p class="text-muted mb-0">Capture, track and convert potential customers into active loans</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLeadModal">
                        <i class="bi bi-person-plus-fill"></i> New Lead
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Total Leads</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-new">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">New Leads</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-star" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-score">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Avg. Lead Score</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-graph-up-arrow" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-converted">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Converted</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-person-check-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main List -->
        <div class="col-lg-9">
            <div class="card shadow-sm mb-4">
                <div class="card-header border-0 bg-white py-3">
                    <h5 class="mb-0 fw-bold">Active Prospects</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="leadsTable" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>Lead Name</th>
                                    <th>Contact</th>
                                    <th>Source</th>
                                    <th class="text-center">Score</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- AJAX Loaded -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Filters -->
        <div class="col-lg-3">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Filter Options</h6>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Lead Status</label>
                        <select class="form-select form-select-sm" id="filter_status">
                            <option value="">All Statuses</option>
                            <?php foreach ($statuses as $stat): ?>
                                <option value="<?= $stat ?>"><?= $stat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Source</label>
                        <select class="form-select form-select-sm" id="filter_source">
                            <option value="">All Sources</option>
                            <?php foreach ($sources as $src): ?>
                                <option value="<?= $src ?>"><?= $src ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-dark btn-sm w-100 shadow-sm" onclick="reloadTable()">Apply Search</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Lead Modal -->
<div class="modal fade" id="addLeadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Capture New Lead</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="leadForm">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Source</label>
                            <select name="source" class="form-select">
                                <?php foreach ($sources as $src): ?>
                                    <option value="<?= $src ?>"><?= $src ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lead Score (0-100)</label>
                            <input type="number" name="score" class="form-control" min="0" max="100" value="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Lead</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
.score-badge { width: 35px; height: 35px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: bold; }
</style>

<script>
$(document).ready(function() {
    const table = $('#leadsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/get_leads.php',
            data: d => {
                d.status = $('#filter_status').val();
                d.source = $('#filter_source').val();
            },
            dataSrc: json => {
                if (json.stats) {
                    $('#stat-total').text(json.stats.total);
                    $('#stat-new').text(json.stats.new);
                    $('#stat-score').text(json.stats.avg_score);
                    $('#stat-converted').text(json.stats.converted);
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: 'first_name',
                render: (data, t, row) => `<strong>${data} ${row.last_name}</strong><br><small class="text-muted"><i class="bi bi-person-badge"></i> ID: ${row.lead_id}</small>`
            },
            { 
                data: 'email',
                render: (data, t, row) => `<div>${data}</div><small class="text-muted">${row.phone}</small>`
            },
            { data: 'source' },
            { 
                data: 'score',
                className: 'text-center',
                render: data => {
                    let bg = 'bg-danger';
                    if (data > 80) bg = 'bg-success';
                    else if (data > 50) bg = 'bg-warning';
                    return `<div class="d-flex justify-content-center"><div class="score-badge ${bg} text-white">${data}</div></div>`;
                }
            },
            {
                data: 'status',
                render: data => {
                    let color = 'primary';
                    if (data === 'Converted') color = 'success';
                    if (data === 'Lost') color = 'danger';
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3">${data}</span>`;
                }
            },
            {
                data: 'created_at',
                render: data => new Date(data).toLocaleDateString()
            },
            {
                data: null,
                className: 'text-end',
                render: row => `<button class="btn btn-sm btn-outline-dark"><i class="bi bi-telephone"></i></button>
                                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>`
            }
        ]
    });
});
function reloadTable() { $('#leadsTable').DataTable().ajax.reload(); }

$('#leadForm').on('submit', function(e) {
    e.preventDefault();
    $.post('/api/save_lead.php', $(this).serialize(), function(res) {
        if (res.success) {
            bootstrap.Modal.getInstance($('#addLeadModal')[0]).hide();
            $('#leadForm')[0].reset();
            reloadTable();
            if (typeof showToast === 'function') showToast('success', res.message);
        } else {
            alert(res.message);
        }
    });
});
</script>

<?php include 'footer.php'; ?>
