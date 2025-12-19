<?php
/**
 * Plugin Name: OIM Order Manager
 * Description: Orders with frontend form, Excel import, PDF generation, driver upload links.
 * Version: 1.2
 * Author: Muhammad Bilal
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

define('OIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OIM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OIM_VERSION', '2.2');

// ============================================
// ACTIVATION/DEACTIVATION HOOKS
// ============================================

// add_action('phpmailer_init', function($phpmailer) {
//         $phpmailer->isSMTP();
//         $phpmailer->Host       = 'smtp.hostinger.com';
//         $phpmailer->SMTPAuth   = true;
//         $phpmailer->Port       = 587;
//         $phpmailer->Username   = 'bilal@webxclinic.com';
//         $phpmailer->Password   = '4VqIU[qQ';
//         $phpmailer->SMTPSecure = 'tls';
//         $phpmailer->From       = 'bilal@webxclinic.com';
//         $phpmailer->FromName   = 'Muhammad Bilal';
//     });

//     add_filter('wp_mail_from', function($email) {
//         return 'bilal@webxclinic.com';
//     });

//     add_filter('wp_mail_from_name', function($name) {
//         return 'Muhammad Bilal';
//     });
register_activation_hook(__FILE__, function() {
    // Load required files first
    if (file_exists(OIM_PLUGIN_DIR . 'includes/class-oim-db.php')) {
        require_once OIM_PLUGIN_DIR . 'includes/class-oim-db.php';
        OIM_DB::create_tables();
    }
    
    // Create database table for logs
    global $wpdb;
    $table_logs = $wpdb->prefix . 'oim_send_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_logs (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id BIGINT(20) UNSIGNED NOT NULL,
        sender_id BIGINT(20) UNSIGNED,
        sender_name VARCHAR(255),
        sent_at DATETIME NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Register rewrite rules
    if (!class_exists('OIM_Frontend')) {
        require_once OIM_PLUGIN_DIR . 'includes/class-oim-frontend.php';
    }
    OIM_Frontend::add_rewrite_rules();
    
    // Register dashboard rules
    add_rewrite_rule('^oim-dashboard/?$', 'index.php?oim_page=dashboard', 'top');
    add_rewrite_rule('^oim-dashboard/orders/?$', 'index.php?oim_page=orders', 'top');
    add_rewrite_rule('^oim-dashboard/orders/new/?$', 'index.php?oim_page=new_order', 'top');
    add_rewrite_rule(
    '^oim-dashboard/invoices/?$',
    'index.php?oim_page=invoices',
    'top'
);
    add_rewrite_rule('^oim-dashboard/settings/?$', 'index.php?oim_page=settings', 'top');
    add_rewrite_rule('^oim-dashboard/edit-order/?$', 'index.php?oim_page=edit_order', 'top');
    add_rewrite_rule('^oim-dashboard/edit-invoice/?$', 'index.php?oim_page=edit_invoice', 'top');
    
    // Register admin page rules
    add_rewrite_rule('^oim-orders/?$', 'index.php?oim_page=oim_orders', 'top');
    add_rewrite_rule('^oim-invoices/?$', 'index.php?oim_page=oim_invoices', 'top');
    add_rewrite_rule('^oim-settings/?$', 'index.php?oim_page=oim_settings', 'top');
    add_rewrite_rule(
        '^oim-dashboard/driver-upload/([^/]+)/?$',
        'index.php?oim_page=driver_upload&oim_driver_token=$matches[1]',
        'top'
    );
    

    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

// ============================================
// EMAIL CONFIGURATION
// ============================================

add_filter('wp_mail_from_name', function($from_name) {
    return 'Aisdatys';
});

add_action('wp_mail_failed', function($error) {
    $log_file = WP_CONTENT_DIR . '/smtp-error-log.txt';
    $message = date('Y-m-d H:i:s') . ' — ' . $error->get_error_message() . PHP_EOL;
    file_put_contents($log_file, $message, FILE_APPEND);
});

// ============================================
// LOAD PLUGIN FILES
// ============================================

add_action('plugins_loaded', function() {
    // Load bundled libraries
    if (file_exists(OIM_PLUGIN_DIR . 'lib/dompdf/vendor/autoload.php')) {
        require_once OIM_PLUGIN_DIR . 'lib/dompdf/vendor/autoload.php';
    }
    if (file_exists(OIM_PLUGIN_DIR . 'lib/phpspreadsheet/vendor/autoload.php')) {
        require_once OIM_PLUGIN_DIR . 'lib/phpspreadsheet/vendor/autoload.php';
    }

    // Load plugin classes
    $includes = [
        'includes/class-oim-db.php',
        'includes/class-oim-invoices.php',
        'includes/class-oim-invoice.php',
        'includes/class-oim-frontend.php',
        'includes/class-oim-admin.php',
        'includes/class-oim-rest-api.php'
    ];

    foreach ($includes as $include) {
        if (file_exists(OIM_PLUGIN_DIR . $include)) {
            require_once OIM_PLUGIN_DIR . $include;
        }
    }
    
    
    
    // Initialize frontend
    if (class_exists('OIM_Frontend')) {
        add_action('init', ['OIM_Frontend', 'add_rewrite_rules']);
        add_filter('query_vars', ['OIM_Frontend', 'query_vars']);
        add_action('template_redirect', ['OIM_Frontend', 'maybe_handle_driver_page']);
        
        if (method_exists('OIM_Frontend', 'init')) {
            OIM_Frontend::init();
        }
    }
    
    // Initialize admin
    if (class_exists('OIM_Admin') && method_exists('OIM_Admin', 'init')) {
        OIM_Admin::init();
    }
    
    // Initialize invoice
    if (class_exists('OIM_Invoice') && method_exists('OIM_Invoice', 'init')) {
        OIM_Invoice::init();
    }
    
    // Initialize admin invoices page only in admin area
    if (is_admin() && class_exists('OIM_Invoices')) {
        new OIM_Invoices();
    }
});
// Initialize cron schedules
add_action('plugins_loaded', function () {
    if (!class_exists('OIM_Invoices')) {
        require_once OIM_PLUGIN_DIR . 'includes/class-oim-invoices.php';
    }

    // Add custom cron schedule
    add_filter('cron_schedules', ['OIM_Invoices', 'add_quarterhour_cron']);

    // Initialize cron hooks
    OIM_Invoices::init_cron_hook();
    OIM_Invoices::schedule_invoice_reminders();
}, 20);    
// ============================================
// REWRITE RULES
// ============================================

/**
 * Register dashboard rewrite rules
 */
add_action('init', function () {

    // Dashboard pages (simple)
    add_rewrite_rule('^oim-dashboard/?$', 'index.php?oim_page=dashboard', 'top');
    add_rewrite_rule('^oim-dashboard/orders/?$', 'index.php?oim_page=orders', 'top');
    add_rewrite_rule(
    '^oim-dashboard/invoices/?$',
    'index.php?oim_page=invoices',
    'top'
);
    add_rewrite_rule('^oim-dashboard/settings/?$', 'index.php?oim_page=settings', 'top');
    add_rewrite_rule('^oim-dashboard/orders/new/?$', 'index.php?oim_page=new_order', 'top');
    

    // Edit ORDER (with ID)
    add_rewrite_rule(
        '^oim-dashboard/edit-order/([0-9]+)/?$',
        'index.php?oim_page=edit_order&item_id=$1',
        'top'
    );
    add_rewrite_rule(
        '^oim-dashboard/driver-upload/([^/]+)/?$',
        'index.php?oim_page=driver_upload&oim_driver_token=$matches[1]',
        'top'
    );
    // Edit INVOICE (with ID)
    

}, 10);


/**
 * Register admin page rewrite rules
 */
add_action('init', function() {
    add_rewrite_rule('^oim-orders/?$', 'index.php?oim_page=oim_orders', 'top');
    add_rewrite_rule('^oim-invoices/?$', 'index.php?oim_page=oim_invoices', 'top');
    add_rewrite_rule('^oim-settings/?$', 'index.php?oim_page=oim_settings', 'top');
}, 20);

/**
 * Add custom query variables
 */
add_filter('query_vars', function($vars) {
    $vars[] = 'oim_page';
    $vars[] = 'item_id';
    $vars[] = 'oim_driver_token';
    $vars[] = 'error';  // <-- REQUIRED
    $vars[] = 'sent';   // <-- REQUIRED
    $vars[] = 'oim_error';
    return $vars;
});
function oim_register_routes() {

    add_rewrite_rule(
        '^oim-dashboard/edit-order/([0-9]+)/?',
        'index.php?pagename=oim-dashboard&edit_order=$matches[1]',
        'top'
    );
}
add_action('init', 'oim_register_routes');
function oim_query_vars($vars) {
    $vars[] = 'edit_order';
    return $vars;
}
add_filter('query_vars', 'oim_query_vars');



// ============================================
// ENQUEUE STYLES & SCRIPTS
// ============================================

/**
 * Early enqueue for dashboard pages
 * This runs before template_redirect to ensure assets are loaded
 */
add_action('parse_request', function($wp) {
    if (isset($wp->query_vars['oim_page'])) {
        // Force enqueue on the next wp_enqueue_scripts hook
        add_action('wp_enqueue_scripts', 'oim_enqueue_dashboard_assets', 1);
    }
});
wp_enqueue_style(
            'oim-upload-style',
            OIM_PLUGIN_URL . 'assets/upload.css',
            [],
            OIM_VERSION
        );
/**
 * Enqueue dashboard assets
 */
function oim_enqueue_dashboard_assets() {
    $oim_page = get_query_var('oim_page');
    $dashboard_pages = ['dashboard', 'orders', 'invoices', 'settings', 'edit_order', 'edit_invoice', 'new_order', 'driver_upload']; 
    $public_pages = ['driver_upload'];
    if (in_array($oim_page, $dashboard_pages)) {
        // Main frontend styles
        wp_enqueue_style(
            'oim-frontend-style',
            OIM_PLUGIN_URL . 'assets/styles.css',
            [],
            OIM_VERSION
        );

        // Admin-style frontend CSS
        wp_enqueue_style(
            'oim-frontend-admin-style',
            OIM_PLUGIN_URL . 'assets/oim-frontend.css',
            [],
            OIM_VERSION
        );

        // Frontend JavaScript
        wp_enqueue_script(
            'oim-frontend-script',
            OIM_PLUGIN_URL . 'assets/oim-frontend.js',
            ['jquery'],
            OIM_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('oim-frontend-script', 'oimAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oim_nonce'),
            'siteUrl' => site_url(),
            'dashboardUrl' => site_url('/oim-dashboard')
        ]);
    }
}

/**
 * Enqueue admin styles and scripts
 */
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'oim') !== false) {
        wp_enqueue_style(
            'oim-admin-style',
            OIM_PLUGIN_URL . 'assets/oim-frontend.css',
            [],
            OIM_VERSION
        );
        
        wp_enqueue_script(
            'oim-admin-script',
            OIM_PLUGIN_URL . 'assets/oim-frontend.js',
            ['jquery'],
            OIM_VERSION,
            true
        );
        
        wp_localize_script('oim-admin-script', 'oimAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oim_nonce')
        ]);
    }
});

// ============================================
// TEMPLATE REDIRECT - DASHBOARD ROUTER
// ============================================

add_action('template_redirect', function() {
    // Don't run in admin area
    if (is_admin()) return;

    $page = get_query_var('oim_page');
    if (!$page) return;

    // Check if this is a dashboard page
    $dashboard_pages = ['dashboard', 'orders', 'invoices', 'settings', 'edit_order', 'edit_invoice', 'new_order', 'driver_upload']; // Added new_order
    $public_pages = ['driver_upload'];
    if (!in_array($page, $dashboard_pages)) return;

    // Security: Check if user is logged in
    if (in_array($page, $dashboard_pages) && !is_user_logged_in()) {
        $redirect_url = site_url($_SERVER['REQUEST_URI']);
        wp_redirect(wp_login_url($redirect_url));
        exit;
    }

    $path = OIM_PLUGIN_DIR . 'frontend-dashboard/';

    // Start output buffering for page content
    ob_start();

    // Load the appropriate page template
    switch ($page) {
        case 'dashboard':
            if (file_exists($path . 'home.php')) {
                include $path . 'home.php';
            }
            break;
        case 'driver_upload':
                if (file_exists($path . 'driver-upload.php')) {
                    include $path . 'driver-upload.php';
                }
                break;
        case 'orders':
            if (file_exists($path . 'orders.php')) {
                include $path . 'orders.php';
            }
            break;

        case 'invoices':
            if (file_exists($path . 'invoices.php')) {
                include $path . 'invoices.php';
            }
            break;

        case 'settings':
            if (file_exists($path . 'settings.php')) {
                include $path . 'settings.php';
            }
            break;

        case 'edit_order':
    $order_id = absint(get_query_var('item_id'));
    include $path . 'edit-order.php';
    break;

        case 'edit_invoice':
            if (file_exists($path . 'edit-invoice.php')) {
                include $path . 'edit-invoice.php';
            }
            break;
        case 'new_order':
            if (file_exists($path . 'template-new-order.php')) {
                include $path . 'template-new-order.php';
            } else {
                echo '<p>New order template not found.</p>';
            }
            break;
        
        default:
            echo '<p>Page not found.</p>';
            break;
    }

    // Get the page content
    $content = ob_get_clean();

    // Include the dashboard layout wrapper
    if (file_exists($path . 'dashboard.php')) {
        include $path . 'dashboard.php';
    }
    
    exit;
}, 5); 

// Early priority

// ============================================
// UTILITY FUNCTIONS
// ============================================

/**
 * Get dashboard URL
 */
function oim_get_dashboard_url($page = '') {
    $base = site_url('/oim-dashboard');
    return $page ? $base . '/' . $page : $base;
}

/**
 * Check if current page is OIM dashboard
 */
function oim_is_dashboard_page() {
    $oim_page = get_query_var('oim_page');
    $dashboard_pages = ['dashboard', 'orders', 'invoices', 'settings', 'edit_order', 'edit_invoice', 'new_order', 'driver_upload'];
    $public_pages = ['driver_upload'];
    return in_array($oim_page, $dashboard_pages);
}

/**
 * Get current dashboard page
 */
function oim_get_current_page() {
    return get_query_var('oim_page');
}

add_action('init', function () {
    add_rewrite_tag('%item_id%', '([0-9]+)');

    add_rewrite_rule(
        '^oim-dashboard/edit-invoice/([0-9]+)/?$',
        'index.php?oim_page=edit_invoice&item_id=$1',
        'top'
    );
});

add_action('admin_init', function () {
    if (isset($_GET['oim_run_cron'])) {
        error_log('[OIM CRON] Manual trigger');
        do_action('oim_invoice_reminder_cron');
        wp_die('OIM cron executed. Check debug.log');
    }
});
