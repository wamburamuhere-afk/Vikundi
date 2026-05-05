<?php
// File: reports.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Enforce permission
autoEnforcePermission('reports');
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Reports</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light rounded shadow-sm mb-4">
        <div class="container-fluid">
            <span class="navbar-brand fw-bold text-success"><i class="bi bi-graph-up me-2"></i> Reports Dashboard</span>
        </div>
    </nav>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card" style="background-color: #e3f2fd !important; border-left: 5px solid #1565c0 !important;">
                <div class="card-body">
                    <h5 class="card-title fw-bold text-primary"><i class="bi bi-receipt me-2"></i> Sales Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            Daily Sales
                            <a href="<?= getUrl('reports') ?>?report=daily_sales" class="btn btn-sm btn-outline-primary">View</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            Sales by Customer
                            <a href="<?= getUrl('reports') ?>?report=sales_customer" class="btn btn-sm btn-outline-primary">View</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card">
                <div class="card-body">
                    <h5 class="card-title fw-bold text-success"><i class="bi bi-calculator me-2"></i> Financial Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center text-dark fw-bold">
                            Income Statement
                            <a href="<?= getUrl('income_statement') ?>" class="btn btn-sm btn-primary shadow-sm">Open</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            Balance Sheet
                            <a href="<?= getUrl('reports') ?>?report=balance_sheet" class="btn btn-sm btn-outline-success">View</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 custom-stat-card" style="background-color: #fff8e1 !important; border-left: 5px solid #f57f17 !important;">
                <div class="card-body">
                    <h5 class="card-title fw-bold text-warning-dark"><i class="bi bi-box me-2"></i> Inventory Reports</h5>
                    <ul class="list-group list-group-flush bg-transparent">
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            Stock Valuation
                            <a href="<?= getUrl('reports') ?>?report=stock_value" class="btn btn-sm btn-outline-warning">View</a>
                        </li>
                        <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center">
                            Low Stock Alert
                            <a href="<?= getUrl('reports') ?>?report=low_stock" class="btn btn-sm btn-outline-warning">View</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Soft Green Theme from expenses.php */
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 1rem;
    transition: transform 0.2s;
    border-left: 5px solid #0f5132 !important;
}
.custom-stat-card:hover { transform: translateY(-5px); }
.custom-stat-card h5, 
.custom-stat-card p, 
.custom-stat-card li {
    color: black !important;
}
.text-warning-dark { color: #f57f17 !important; }
.card { border-radius: 0.75rem; }
</style>

<?php includeFooter(); ?>
