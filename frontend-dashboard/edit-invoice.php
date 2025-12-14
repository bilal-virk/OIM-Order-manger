<?php
/**
 * Frontend Edit Invoice Page
 * URL example: /oim-dashboard/edit-invoice/114
 */

// --- Get invoice ID cleanly ---
$invoice_id = null;

// 1. From rewrite rule
$maybe_id = get_query_var('item_id');
if (!empty($maybe_id) && is_numeric($maybe_id)) {
    $invoice_id = intval($maybe_id);
}

// 2. Fallback from last URI segment
if (!$invoice_id) {
    $uri = trim($_SERVER['REQUEST_URI'], '/');
    $parts = explode('/', $uri);
    $last = end($parts);
    if (is_numeric($last)) {
        $invoice_id = intval($last);
    }
}

// 3. Final fallback (query string)
if (!$invoice_id) {
    $invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : null;
}

if (!$invoice_id) {
    error_log("DEBUG: edit-invoice.php - No invoice ID. Query vars: " . print_r($GLOBALS['wp_query']->query_vars, true));
    echo "<h2>No invoice ID provided.</h2>";
    return;
}

// --- Render invoice page ---
if (class_exists('OIM_Invoices')) {
    ob_start();
    try {
        $invoice_obj = new OIM_Invoices();       
        $invoice_obj->render_edit_invoice_page($invoice_id); 
    } catch (Exception $e) {
        echo "<p>Error loading invoice: " . esc_html($e->getMessage()) . "</p>";
    }
    $content = ob_get_clean();
    echo $content;
}

?>
