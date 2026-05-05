<?php
/**
 * API: Export Products
 * Generates a CSV file of products for Excel/Download.
 */
require_once __DIR__ . '/../roots.php';

// Check permissions (basic check for now)
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

try {
    // Prepare headers for download
    $filename = "products_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, ['SKU', 'Product Name', 'Category', 'Brand', 'Cost Price', 'Selling Price', 'Stock Quantity', 'Unit', 'Status']);

    // Fetch products joining with categories and brands (if applicable)
    $query = "
        SELECT 
            p.sku, 
            p.product_name, 
            c.category_name, 
            p.brand_name, 
            p.cost_price, 
            p.selling_price, 
            p.stock_quantity, 
            p.unit, 
            p.status
        FROM products p
        LEFT JOIN product_categories c ON p.category_id = c.category_id
        ORDER BY p.product_name ASC
    ";

    $stmt = $pdo->query($query);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (PDOException $e) {
    die("Export Failed: " . $e->getMessage());
}
