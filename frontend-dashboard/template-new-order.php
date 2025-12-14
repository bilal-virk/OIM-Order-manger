<?php
/**
 * Template: New Order Page
 * Uses existing [order_form] shortcode
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}
?>

<div >
    <!-- Page Header -->
    <div class="oim-page-header">
        <div class="oim-page-title-section">
            <h1 class="oim-page-title">
                <i class="fas fa-plus-circle"></i>
                Create New Order
            </h1>
            <p class="oim-page-subtitle">Fill in the order details below to create a new order</p>
        </div>
        <div class="oim-page-actions">
            <a href="<?php echo home_url('/oim-dashboard/orders'); ?>" class="oim-btn oim-btn-secondary">
                <i class="fas fa-arrow-left"></i>
                Back to Orders
            </a>
        </div>
    </div>

    <!-- Order Form Shortcode -->
    <div class="oim-order-form-container">
        <?php echo do_shortcode('[order_form]'); ?>
    </div>
</div>

<style>
/* New Order Page Specific Styles */
.oim-new-order-wrap {
    max-width: 1400px;
    margin: 0 auto;
    padding: 24px;
}

.oim-page-actions {
    margin-left: auto;
}

.oim-order-form-container {
    margin-top: 24px;
}

/* Ensure form styling matches dashboard */
.oim-order-form-container .oim-card {
    margin-bottom: 16px;
}
</style>