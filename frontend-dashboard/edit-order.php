<?php
/**
 * Frontend Edit Order Page
 * URL example: /oim-dashboard/edit-order/49
 */

// --- Get order ID cleanly ---
$order_id = null;

// 1. From rewrite rule
$maybe_id = get_query_var('item_id');
if (!empty($maybe_id) && is_numeric($maybe_id)) {
    $order_id = intval($maybe_id);
}

// 2. Fallback from last URI segment
if (!$order_id) {
    $uri = trim($_SERVER['REQUEST_URI'], '/');
    $parts = explode('/', $uri);
    $last = end($parts);
    if (is_numeric($last)) {
        $order_id = intval($last);
    }
}

// 3. Final fallback (query string)
if (!$order_id) {
    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
}

if (!$order_id) {
    echo "<h2>No order ID provided.</h2>";
    return;
}

if (class_exists('OIM_Admin')) {
    ob_start();
    try {
        OIM_Admin::render_edit_order($order_id);
    } catch (Exception $e) {
        echo "<p>Error loading order: " . esc_html($e->getMessage()) . "</p>";
    }
    $content = ob_get_clean();
    $content = str_replace("submit_button(", "<button type='submit' class='button button-primary'>Save</button><!-- replaced -->", $content);

    echo $content;
}

?>
