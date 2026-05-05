<?php
ob_start();
require_once 'header.php';

// Check permissions
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('sms_templates');
}
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-chat-left-dots"></i> SMS Templates</h2>
                    <p class="text-muted mb-0">Manage system SMS notifications and text message templates</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#templateModal" onclick="resetForm()">
                        <i class="bi bi-plus-circle"></i> Create Template
                    </button>
                    <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#testSmsModal">
                        <i class="bi bi-send"></i> Send Test SMS
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-templates">0</h4>
                            <p class="mb-0">Total Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-chat-quote" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-active-templates">0</h4>
                            <p class="mb-0">Active Templates</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Templates Table Card -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-primary">SMS Template Library</h5>
                <span class="badge bg-light text-dark" id="stat-records-filtered">0 templates</span>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="templatesTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>Template Name</th>
                            <th>Message Content</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th class="text-end">Actions</th>
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

<!-- Template Create/Edit Modal -->
<div class="modal fade" id="templateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Create SMS Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="templateForm">
                <input type="hidden" id="template_id" name="id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label for="template_name" class="form-label">Template Name *</label>
                            <input type="text" class="form-control" id="template_name" name="template_name" required placeholder="e.g. Payment Reminder">
                        </div>
                        <div class="col-12">
                            <label for="template_type" class="form-label">Type</label>
                            <select class="form-select" id="template_type" name="template_type">
                                <option value="general">General</option>
                                <option value="loan">Loan Related</option>
                                <option value="payment">Payment/Collection</option>
                                <option value="security">Security/Auth</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="content" class="form-label">SMS Content *</label>
                            <textarea class="form-control" id="content" name="content" rows="4" maxlength="160" required placeholder="Dear {{customer_name}}, your payment is due..."></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <div class="form-text">Use {{customer_name}}, {{amount}}, etc.</div>
                                <div class="small text-muted"><span id="charCount">0</span>/160</div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label class="form-check-label" for="is_active">Template is Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">Save Template</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send Test SMS Modal -->
<div class="modal fade" id="testSmsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Test SMS</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="testSmsForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_template_id" class="form-label">Select Template</label>
                        <select class="form-select" id="test_template_id" name="template_id" required>
                            <option value="">Choose a template...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="test_phone" class="form-label">Recipient Phone *</label>
                        <input type="text" class="form-control" id="test_phone" name="phone" required placeholder="+254700000000">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="testSendBtn">Send Now</button>
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
.custom-stat-card h4, .custom-stat-card p, .custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}
#templatesTable thead th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; border-bottom: none; }
.badge-active { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; }
.badge-inactive { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
.dropdown-toggle::after { display: none; }
</style>

<script>
$(document).ready(function() {
    const table = $('#templatesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '/api/get_sms_templates.php',
            dataSrc: function(json) {
                $('#stat-total-templates').text(json.stats.totalTemplates);
                $('#stat-active-templates').text(json.stats.activeTemplates);
                $('#stat-records-filtered').text(json.recordsFiltered + ' templates');
                
                let options = '<option value="">Choose a template...</option>';
                json.data.forEach(t => {
                    options += `<option value="${t.id}">${t.template_name}</option>`;
                });
                $('#test_template_id').html(options);
                
                return json.data;
            }
        },
        columns: [
            { 
                data: 'template_name',
                render: data => `<strong>${escapeHtml(data)}</strong>`
            },
            { 
                data: 'content',
                render: data => `<div class="text-truncate" style="max-width: 300px;">${escapeHtml(data)}</div>`
            },
            { 
                data: 'template_type',
                render: data => `<span class="badge bg-light text-dark border">${data}</span>`
            },
            { 
                data: 'is_active',
                render: data => data == 1 
                    ? '<span class="badge badge-active">Active</span>' 
                    : '<span class="badge badge-inactive">Inactive</span>'
            },
            { 
                data: 'created_at',
                render: data => new Date(data).toLocaleDateString()
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick='editTemplate(${JSON.stringify(row)})'><i class="bi bi-pencil"></i> Edit</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteTemplate(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>
                        </ul>
                    </div>`
            }
        ],
        order: [[4, 'desc']]
    });

    // Character counter
    $('#content').on('input', function() {
        $('#charCount').text($(this).val().length);
    });

    // Handle Form Submit
    $('#templateForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#saveBtn');
        btn.prop('disabled', true).text('Saving...');
        
        $.ajax({
            url: '/api/save_sms_template.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    $('#templateModal').modal('hide');
                    table.ajax.reload();
                    if (typeof showToast === 'function') {
                        showToast('success', response.message);
                    } else {
                        alert(response.message);
                    }
                } else {
                    alert('Error: ' + response.message);
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Save Template');
            }
        });
    });

    // Handle Test SMS Submit
    $('#testSmsForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $('#testSendBtn');
        btn.prop('disabled', true).text('Sending...');
        
        $.ajax({
            url: '/api/test_sms_config.php',
            type: 'POST',
            data: $(this).serialize(),
            success: function(response) {
                if (response.success) {
                    if (typeof showToast === 'function') {
                        showToast('success', 'Test SMS sent successfully!');
                    } else {
                        alert('Test SMS sent successfully!');
                    }
                    $('#testSmsModal').modal('hide');
                } else {
                    alert('Failed: ' + response.message);
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Send Now');
            }
        });
    });
});

function resetForm() {
    $('#modalTitle').text('Create SMS Template');
    $('#templateForm')[0].reset();
    $('#template_id').val('');
    $('#charCount').text('0');
}

function editTemplate(data) {
    $('#modalTitle').text('Edit SMS Template');
    $('#template_id').val(data.id);
    $('#template_name').val(data.template_name);
    $('#template_type').val(data.template_type);
    $('#content').val(data.content);
    $('#is_active').prop('checked', data.is_active == 1);
    $('#charCount').text(data.content.length);
    $('#templateModal').modal('show');
}

function deleteTemplate(id) {
    if (confirm('Are you sure you want to delete this template?')) {
        $.post('/api/delete_sms_template.php', {id: id}, function(response) {
            if (response.success) {
                $('#templatesTable').DataTable().ajax.reload();
            } else {
                alert(response.message);
            }
        });
    }
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}
</script>

<?php
include("footer.php");
ob_end_flush();
?>
