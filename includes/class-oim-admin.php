<?php
// includes/class-oim-admin.php
if (! defined('ABSPATH')) exit;

class OIM_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        // admin actions
        add_action('admin_post_oim_import_excel', [__CLASS__, 'handle_import_excel']);
        add_action('admin_post_oim_save_order', [__CLASS__, 'handle_save_order']);
        add_action('admin_post_oim_delete_order', [__CLASS__, 'handle_delete_order']);
        add_action('admin_post_oim_delete_doc', [__CLASS__, 'handle_delete_doc']);
        add_action('admin_post_oim_delete_attachment', ['OIM_Admin', 'handle_delete_attachment']);
        add_action('admin_post_oim_delete_attachment', 'oim_handle_delete_attachment');
add_action('admin_post_nopriv_oim_delete_attachment', 'oim_handle_delete_attachment'); // Add this
add_action('admin_post_oim_delete_doc', 'handle_delete_doc');
add_action('admin_post_nopriv_oim_delete_doc', 'handle_delete_doc'); 
    }

    public static function admin_menu() {
        add_menu_page('OIM Orders', 'OIM Orders', 'manage_options', 'oim_orders', [__CLASS__, 'page_orders'], 'dashicons-list-view', 58);
        add_submenu_page('oim_orders', 'OIM Settings', 'Settings', 'manage_options', 'oim_settings', [__CLASS__, 'page_settings']); // ,customer_company_ID_crn, customer_tax_ID
    }


function oim_handle_delete_doc() {
    $doc_id = intval($_GET['doc_id']);
    $order_id = intval($_GET['order_id']);
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'oim_order_documents', ['id' => $doc_id]);
    wp_die();
}

function oim_handle_delete_attachment() {
    $order_id = intval($_GET['order_id']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'oim_delete_attachment_' . $order_id)) {
        wp_die('Security check failed');
    }
    $file = $_GET['file'];
    wp_die();
}
public static function page_settings() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    // Handle form submission
    if (isset($_POST['oim_settings_submit'])) {
        check_admin_referer('oim_settings');

        // Save all settings
        $fields = [
            'oim_company_email'    => 'sanitize_email',
            'oim_api_key'          => 'sanitize_text_field',
            'oim_company_bank'     => 'sanitize_text_field',
            'oim_company_bic'      => 'sanitize_text_field',
            'oim_payment_title'    => 'sanitize_text_field',
            'oim_tin'              => 'sanitize_text_field',
            'oim_company_account'  => 'sanitize_text_field',
            'oim_company_iban'     => 'sanitize_text_field',
            'oim_crn'              => 'sanitize_text_field',
            'oim_company_supplier' => 'sanitize_text_field',
            'oim_headquarters'     => 'sanitize_text_field',
            'oim_invoice_currency' => 'sanitize_text_field',
            'oim_our_reference'    => 'sanitize_text_field',
            'oim_company_phone'    => 'sanitize_text_field',
            'oim_percent_vat'      => 'sanitize_text_field',
            'oim_company_web'      => 'esc_url_raw',
        ];

        foreach ($fields as $field => $sanitize) {
            if (isset($_POST[$field])) {
                update_option($field, call_user_func($sanitize, $_POST[$field]));
            }
        }

        echo '<div class="oim-notice oim-notice-success"><i class="fas fa-check-circle"></i><div><strong>Success!</strong><p>Settings saved successfully!</p></div></div>';
    }

    // Load settings
    $options = [];
    $fields = [
        'oim_company_email'    => get_option('admin_email'),
        'oim_api_key'          => '',
        'oim_company_bank'     => '',
        'oim_company_bic'      => '',
        'oim_payment_title'    => '',
        'oim_tin'              => '',
        'oim_invoice_currency' => '',
        'oim_company_account'  => '',
        'oim_company_iban'     => '',
        'oim_company_supplier' => '',
        'oim_headquarters'     => '',
        'oim_crn'              => '',
        'oim_our_reference'    => '',
        'oim_company_phone'    => '',
        'oim_company_web'      => '',
        'oim_percent_vat'      => '',
    ];

    foreach ($fields as $field => $default) {
        $options[$field] = get_option($field, $default);
    }
    ?>
    <div class="wrap oim-settings-wrap">
        <!-- Modern Page Header -->
        <div class="oim-page-header">
            <div class="oim-page-title-section">
                <h1 class="oim-page-title">
                    <i class="fas fa-cog"></i>
                    OIM Settings
                </h1>
                <p class="oim-page-subtitle">Configure your company information and system preferences</p>
            </div>
        </div>

        <form method="post" class="oim-settings-form">
            <?php wp_nonce_field('oim_settings'); ?>

            <!-- Company Information Section -->
            <div class="oim-card">
                <div class="oim-card-header">
                    <div class="oim-card-title-group">
                        <div class="oim-card-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="oim-card-title-wrapper">
                            <h2 class="oim-card-title">Company Information</h2>
                            <p class="oim-card-description">Basic company details for invoices and communications</p>
                        </div>
                    </div>
                </div>
                <div class="oim-card-content">
                    <div class="oim-settings-grid">
                        <div class="oim-setting-item">
                            <label for="oim_company_email" class="oim-setting-label">
                                <i class="fas fa-envelope"></i>
                                Company Email
                            </label>
                            <input type="email" 
                                   id="oim_company_email" 
                                   name="oim_company_email" 
                                   value="<?php echo esc_attr($options['oim_company_email']); ?>" 
                                   class="oim-input" 
                                   placeholder="company@example.com">
                            <p class="oim-setting-description">Primary email for company communications</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_phone" class="oim-setting-label">
                                <i class="fas fa-phone"></i>
                                Telephone
                            </label>
                            <input type="text" 
                                   id="oim_company_phone" 
                                   name="oim_company_phone" 
                                   value="<?php echo esc_attr($options['oim_company_phone']); ?>" 
                                   class="oim-input" 
                                   placeholder="+1 (555) 123-4567">
                            <p class="oim-setting-description">Company phone number</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_web" class="oim-setting-label">
                                <i class="fas fa-globe"></i>
                                Website
                            </label>
                            <input type="url" 
                                   id="oim_company_web" 
                                   name="oim_company_web" 
                                   value="<?php echo esc_attr($options['oim_company_web']); ?>" 
                                   class="oim-input" 
                                   placeholder="https://www.example.com">
                            <p class="oim-setting-description">Company website URL</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_supplier" class="oim-setting-label">
                                <i class="fas fa-tag"></i>
                                Supplier
                            </label>
                            <input type="text" 
                                   id="oim_company_supplier" 
                                   name="oim_company_supplier" 
                                   value="<?php echo esc_attr($options['oim_company_supplier']); ?>" 
                                   class="oim-input" 
                                   placeholder="Company name">
                            <p class="oim-setting-description">Supplier/company name</p>
                        </div>

                        <div class="oim-setting-item oim-setting-item-full">
                            <label for="oim_headquarters" class="oim-setting-label">
                                <i class="fas fa-map-marker-alt"></i>
                                Company Headquarters
                            </label>
                            <input type="text" 
                                   id="oim_headquarters" 
                                   name="oim_headquarters" 
                                   value="<?php echo esc_attr($options['oim_headquarters']); ?>" 
                                   class="oim-input" 
                                   placeholder="123 Main Street, City, Country">
                            <p class="oim-setting-description">Complete company address</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tax & Registration Section -->
            <div class="oim-card">
                <div class="oim-card-header">
                    <div class="oim-card-title-group">
                        <div class="oim-card-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <div class="oim-card-title-wrapper">
                            <h2 class="oim-card-title">Tax & Registration</h2>
                            <p class="oim-card-description">Tax identification and registration numbers</p>
                        </div>
                    </div>
                </div>
                <div class="oim-card-content">
                    <div class="oim-settings-grid">
                        <div class="oim-setting-item">
                            <label for="oim_tin" class="oim-setting-label">
                                <i class="fas fa-hashtag"></i>
                                TIN (Tax Identification Number)
                            </label>
                            <input type="text" 
                                   id="oim_tin" 
                                   name="oim_tin" 
                                   value="<?php echo esc_attr($options['oim_tin']); ?>" 
                                   class="oim-input" 
                                   placeholder="DIČ">
                            <p class="oim-setting-description">Tax identification number (DIČ)</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_crn" class="oim-setting-label">
                                <i class="fas fa-hashtag"></i>
                                CRN (Company Registration Number)
                            </label>
                            <input type="text" 
                                   id="oim_crn" 
                                   name="oim_crn" 
                                   value="<?php echo esc_attr($options['oim_crn']); ?>" 
                                   class="oim-input" 
                                   placeholder="IČO">
                            <p class="oim-setting-description">Company registration number (IČO)</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_percent_vat" class="oim-setting-label">
                                <i class="fas fa-percent"></i>
                                VAT Percentage
                            </label>
                            <input type="text" 
                                   id="oim_percent_vat" 
                                   name="oim_percent_vat" 
                                   value="<?php echo esc_attr($options['oim_percent_vat']); ?>" 
                                   class="oim-input" 
                                   placeholder="20">
                            <p class="oim-setting-description">Default VAT percentage (e.g., 20 for 20%)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Banking Information Section -->
            <div class="oim-card">
                <div class="oim-card-header">
                    <div class="oim-card-title-group">
                        <div class="oim-card-icon">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="oim-card-title-wrapper">
                            <h2 class="oim-card-title">Banking Information</h2>
                            <p class="oim-card-description">Bank account details for payments</p>
                        </div>
                    </div>
                </div>
                <div class="oim-card-content">
                    <div class="oim-settings-grid">
                        <div class="oim-setting-item">
                            <label for="oim_company_bank" class="oim-setting-label">
                                <i class="fas fa-university"></i>
                                Company Bank
                            </label>
                            <input type="text" 
                                   id="oim_company_bank" 
                                   name="oim_company_bank" 
                                   value="<?php echo esc_attr($options['oim_company_bank']); ?>" 
                                   class="oim-input" 
                                   placeholder="Bank name">
                            <p class="oim-setting-description">Name of your bank</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_bic" class="oim-setting-label">
                                <i class="fas fa-code"></i>
                                BIC/SWIFT Code
                            </label>
                            <input type="text" 
                                   id="oim_company_bic" 
                                   name="oim_company_bic" 
                                   value="<?php echo esc_attr($options['oim_company_bic']); ?>" 
                                   class="oim-input" 
                                   placeholder="ABCDUS33XXX">
                            <p class="oim-setting-description">Bank Identifier Code</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_account" class="oim-setting-label">
                                <i class="fas fa-credit-card"></i>
                                Account Number
                            </label>
                            <input type="text" 
                                   id="oim_company_account" 
                                   name="oim_company_account" 
                                   value="<?php echo esc_attr($options['oim_company_account']); ?>" 
                                   class="oim-input" 
                                   placeholder="1234567890">
                            <p class="oim-setting-description">Bank account number</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_company_iban" class="oim-setting-label">
                                <i class="fas fa-money-check"></i>
                                IBAN
                            </label>
                            <input type="text" 
                                   id="oim_company_iban" 
                                   name="oim_company_iban" 
                                   value="<?php echo esc_attr($options['oim_company_iban']); ?>" 
                                   class="oim-input" 
                                   placeholder="SK31 1200 0000 1234 5678 9012">
                            <p class="oim-setting-description">International Bank Account Number</p>
                        </div>

                        <div class="oim-setting-item oim-setting-item-full">
                            <label for="oim_payment_title" class="oim-setting-label">
                                <i class="fas fa-file-invoice"></i>
                                Payment Title
                            </label>
                            <input type="text" 
                                   id="oim_payment_title" 
                                   name="oim_payment_title" 
                                   value="<?php echo esc_attr($options['oim_payment_title']); ?>" 
                                   class="oim-input" 
                                   placeholder="Invoice payment reference">
                            <p class="oim-setting-description">Default payment reference/title</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Invoice Settings Section -->
            <div class="oim-card">
                <div class="oim-card-header">
                    <div class="oim-card-title-group">
                        <div class="oim-card-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="oim-card-title-wrapper">
                            <h2 class="oim-card-title">Invoice Settings</h2>
                            <p class="oim-card-description">Default settings for invoice generation</p>
                        </div>
                    </div>
                </div>
                <div class="oim-card-content">
                    <div class="oim-settings-grid">
                        <div class="oim-setting-item">
                            <label for="oim_invoice_currency" class="oim-setting-label">
                                <i class="fas fa-dollar-sign"></i>
                                Invoice Currency
                            </label>
                            <input type="text" 
                                   id="oim_invoice_currency" 
                                   name="oim_invoice_currency" 
                                   value="<?php echo esc_attr($options['oim_invoice_currency']); ?>" 
                                   class="oim-input" 
                                   placeholder="EUR">
                            <p class="oim-setting-description">Default currency code (EUR, USD, etc.)</p>
                        </div>

                        <div class="oim-setting-item">
                            <label for="oim_our_reference" class="oim-setting-label">
                                <i class="fas fa-bookmark"></i>
                                Our Reference
                            </label>
                            <input type="text" 
                                   id="oim_our_reference" 
                                   name="oim_our_reference" 
                                   value="<?php echo esc_attr($options['oim_our_reference']); ?>" 
                                   class="oim-input" 
                                   placeholder="Reference code">
                            <p class="oim-setting-description">Default internal reference</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- API Settings Section -->
            <div class="oim-card">
                <div class="oim-card-header">
                    <div class="oim-card-title-group">
                        <div class="oim-card-icon">
                            <i class="fas fa-key"></i>
                        </div>
                        <div class="oim-card-title-wrapper">
                            <h2 class="oim-card-title">API Settings</h2>
                            <p class="oim-card-description">REST API configuration for external integrations</p>
                        </div>
                    </div>
                </div>
                <div class="oim-card-content">
                    <div class="oim-settings-grid">
                        <div class="oim-setting-item oim-setting-item-full">
                            <label for="oim_api_key" class="oim-setting-label">
                                <i class="fas fa-key"></i>
                                API Key (REST Import)
                            </label>
                            <input type="text" 
                                   id="oim_api_key" 
                                   name="oim_api_key" 
                                   value="<?php echo esc_attr($options['oim_api_key']); ?>" 
                                   class="oim-input" 
                                   placeholder="Enter a secret API key">
                            <p class="oim-setting-description">Set a secret key for REST Excel import via API</p>
                            
                            <?php if (!empty($options['oim_api_key'])): ?>
                                <div class="oim-api-info">
                                    <div class="oim-api-info-header">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>API Configuration</strong>
                                    </div>
                                    <div class="oim-api-info-content">
                                        <div class="oim-api-info-item">
                                            <label>Endpoint:</label>
                                            <code class="oim-code-block"><?php echo esc_url(rest_url('oim/v1/import-excel')); ?></code>
                                        </div>
                                        <div class="oim-api-info-item">
                                            <label>Method:</label>
                                            <code class="oim-code-inline">POST</code>
                                        </div>
                                        <div class="oim-api-info-item">
                                            <label>Header:</label>
                                            <code class="oim-code-inline">X-API-Key: <?php echo esc_attr($options['oim_api_key']); ?></code>
                                        </div>
                                        <div class="oim-api-info-item">
                                            <label>File Parameter:</label>
                                            <code class="oim-code-inline">excel_file</code>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <div class="oim-settings-footer">
                <button type="submit" name="oim_settings_submit" class="oim-btn oim-btn-primary oim-btn-large">
                    <i class="fas fa-save"></i>
                    Save All Settings
                </button>
                <p class="oim-settings-footer-note">
                    <i class="fas fa-info-circle"></i>
                    Changes will take effect immediately after saving
                </p>
            </div>
        </form>
    </div>
    <?php
}


    public static function page_orders() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;

    // Handle bulk delete action
    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'delete' && isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
        if (!wp_verify_nonce($_POST['_wpnonce'], 'oim_bulk_action')) {
            wp_die('Security check failed');
        }

        $order_ids = array_map('intval', $_POST['order_ids']);
        $order_ids = array_filter($order_ids);
        
        if (!empty($order_ids)) {
            $orders_table = $wpdb->prefix . 'oim_orders';
            $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
            $sql = $wpdb->prepare("DELETE FROM {$orders_table} WHERE id IN ($placeholders)", $order_ids);
            $result = $wpdb->query($sql);
            
            if ($result !== false) {
                echo '<div class="oim-notice oim-notice-success"><i class="fas fa-check-circle"></i><p>' . sprintf(_n('%d order deleted successfully.', '%d orders deleted successfully.', count($order_ids), 'oim'), count($order_ids)) . '</p></div>';
            } else {
                echo '<div class="oim-notice oim-notice-error"><i class="fas fa-exclamation-circle"></i><p>Error deleting orders. Please try again.</p></div>';
            }
        }
    }

    // Handle view/edit
    if (isset($_GET['view_order'])) {
        self::render_view_order(intval($_GET['view_order']));
        return;
    }
    if (isset($_GET['edit_order'])) {
        self::render_edit_order(intval($_GET['edit_order']));
        return;
    }

    // --- Filters & Sorting ---
    $search = sanitize_text_field($_GET['s'] ?? '');
    $email_filter = sanitize_text_field($_GET['customer_email'] ?? '');
    $date_from = sanitize_text_field($_GET['date_from'] ?? '');
    $date_to = sanitize_text_field($_GET['date_to'] ?? '');
    $order_by = sanitize_text_field($_GET['orderby'] ?? 'created_at');
    $order_dir = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));
    $document_filter = sanitize_text_field($_GET['document_filter'] ?? '');
    $attachment_filter = sanitize_text_field($_GET['attachment_filter'] ?? '');

    if (!in_array($order_dir, ['ASC', 'DESC'])) $order_dir = 'DESC';

    $allowed_sort_columns = ['id', 'internal_order_id', 'created_at'];
    if (!in_array($order_by, $allowed_sort_columns)) $order_by = 'created_at';

    // --- Base Query ---
    $orders_table = $wpdb->prefix . 'oim_orders';
    $sql = "SELECT id, internal_order_id, data, created_at, attachments FROM {$orders_table} WHERE 1=1";

    // --- Apply Filters ---
    if ($search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $sql .= $wpdb->prepare(" AND (internal_order_id LIKE %s OR data LIKE %s)", $like, $like);
    }
    if ($email_filter) {
        $like = '%' . $wpdb->esc_like($email_filter) . '%';
        $sql .= $wpdb->prepare(" AND data LIKE %s", $like);
    }
    if ($date_from) {
        $sql .= $wpdb->prepare(" AND created_at >= %s", $date_from);
    }
    if ($date_to) {
        $sql .= $wpdb->prepare(" AND created_at <= %s", $date_to);
    }
    
    // --- Document Filter ---
    if ($document_filter === 'with') {
        $docs_table = $wpdb->prefix . 'oim_documents';
        $sql .= " AND EXISTS (SELECT 1 FROM {$docs_table} WHERE order_id = {$orders_table}.id)";
    } elseif ($document_filter === 'without') {
        $docs_table = $wpdb->prefix . 'oim_documents';
        $sql .= " AND NOT EXISTS (SELECT 1 FROM {$docs_table} WHERE order_id = {$orders_table}.id)";
    }

    // --- Attachment Filter ---
    if ($attachment_filter === 'with') {
        $sql .= " AND (attachments IS NOT NULL AND attachments != '' AND attachments != 'a:0:{}' AND attachments != '[]')";
    } elseif ($attachment_filter === 'without') {
        $sql .= " AND (attachments IS NULL OR attachments = '' OR attachments = 'a:0:{}' OR attachments = '[]')";
    }

    // --- Sorting ---
    $sql .= " ORDER BY {$order_by} {$order_dir}";

    // --- Get Orders ---
    $orders = $wpdb->get_results($sql, ARRAY_A);
    
    // helper to safely parse 'data' field
    $parse_order_data = function($raw) {
        if (empty($raw)) return [];
        $un = maybe_unserialize($raw);
        if (is_array($un)) return $un;
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return [];
    };
    ?>

    <div class="wrap oim-orders-wrap">
        <!-- Modern Page Header -->
        <div class="oim-page-header">
            <div class="oim-page-title-section">
                <h1 class="oim-page-title">
                    <i class="fas fa-shopping-cart"></i>
                    Order Management
                </h1>
                <p class="oim-page-subtitle">Manage and track all your orders in one place</p>
            </div>
        </div>
        <div class="oim-action-buttons-row">
            <button type="button" class="oim-btn oim-btn-secondary oim-btn-icon" id="toggle-import-btn">
                <i class="fas fa-file-import"></i>
                Import Orders
            </button>
            <button type="button" class="oim-btn oim-btn-secondary oim-btn-icon" id="new-order-btn" >
                <i class="fas fa-order"></i>
                <a href="<?php echo home_url('/oim-dashboard/orders/new'); ?>" 
                class="oim-btn-icon">
                    <i class="fas fa-plus-circle"></i>
                    New Order
                </a>
            </button>

            <button type="button" class="oim-btn oim-btn-secondary oim-btn-icon" id="toggle-filter-btn">
                <i class="fas fa-filter"></i>
                Filters
            </button>
        </div>
        <!-- Section 1: Import Orders -->
        <div class="oim-card oim-toggle-section" style="display: none;" id="import-payments-section">

                <div class="oim-card-title-group">
                    <div class="oim-card-icon">
                        <i class="fas fa-file-upload"></i>
                    </div>
                    <div class="oim-card-title-wrapper">
                        <h2 class="oim-card-title">Import Orders</h2>
                        <p class="oim-card-description">Upload Excel files to import orders into the system</p>
                    </div>
                </div>
                
            <div class="oim-card-content" id="import-section">
                <?php
                if (isset($_GET['import_result'])) {
                    $result = json_decode(base64_decode($_GET['import_result']), true);

                    if ($result) {
                        echo '<div class="oim-import-result-container">';
                        echo '<script>jQuery(document).ready(function($) { $("#import-payments-section").slideToggle(300); });</script>';

                        // Error handling
                        if (!empty($result['error'])) {
                            echo '<div class="oim-notice oim-notice-error">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Import Failed</strong>
                                    <p>' . esc_html($result['error']) . '</p>
                                </div>
                            </div>';
                        } else {
                            $imported   = intval($result['imported'] ?? 0);
                            $duplicates = intval($result['duplicates'] ?? 0);
                            $skipped    = intval($result['skipped'] ?? 0);
                            $invoices   = intval($result['invoices_created'] ?? 0);
                            $total      = $imported + $duplicates + $skipped;

                            $imported_refs   = $result['imported_refs'] ?? [];
                            $duplicate_refs  = $result['duplicate_refs'] ?? [];
                            $skipped_refs    = $result['skipped_refs'] ?? [];

                            // Success message
                            if ($imported > 0) {
                                echo '<div class="oim-notice oim-notice-success">
                                    <i class="fas fa-check-circle"></i>
                                    <div>
                                        <strong>Import Successful!</strong>
                                        <p>' . esc_html($imported) . ' order(s) imported successfully.</p>
                                    </div>
                                </div>';
                            } else {
                                echo '<div class="oim-notice oim-notice-warning">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        <strong>No New Orders Imported</strong>
                                        <p>Please check for missing or duplicate invoice numbers.</p>
                                    </div>
                                </div>';
                            }

                            // Summary cards
                            echo '<div class="oim-import-stats-grid">';
                            echo '<div class="oim-stat-card">
                                <div class="oim-stat-icon oim-stat-primary">
                                    <i class="fas fa-file-invoice"></i>
                                </div>
                                <div class="oim-stat-content">
                                    <div class="oim-stat-value">' . esc_html($total) . '</div>
                                    <div class="oim-stat-label">Total Processed</div>
                                </div>
                            </div>';
                            
                            echo '<div class="oim-stat-card">
                                <div class="oim-stat-icon oim-stat-success">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="oim-stat-content">
                                    <div class="oim-stat-value">' . esc_html($imported) . '</div>
                                    <div class="oim-stat-label">Orders Created</div>
                                </div>
                            </div>';
                            
                            echo '<div class="oim-stat-card">
                                <div class="oim-stat-icon oim-stat-info">
                                    <i class="fas fa-receipt"></i>
                                </div>
                                <div class="oim-stat-content">
                                    <div class="oim-stat-value">' . esc_html($invoices) . '</div>
                                    <div class="oim-stat-label">Invoices Created</div>
                                </div>
                            </div>';
                            
                            echo '<div class="oim-stat-card">
                                <div class="oim-stat-icon oim-stat-warning">
                                    <i class="fas fa-exclamation-triangle"></i>
                                </div>
                                <div class="oim-stat-content">
                                    <div class="oim-stat-value">' . esc_html($duplicates) . '</div>
                                    <div class="oim-stat-label">Duplicates</div>
                                </div>
                            </div>';
                            echo '</div>';

                            // Detailed breakdown
                            echo '<div class="oim-import-details">';

                            $render_list = function ($title, $items, $icon, $type) {
                                if (empty($items)) return;
                                $typeClass = 'oim-detail-' . $type;
                                echo '<details class="oim-details-accordion ' . $typeClass . '">';
                                echo '<summary class="oim-details-summary">
                                    <i class="' . $icon . '"></i>
                                    <span>' . esc_html($title) . '</span>
                                    <span class="oim-badge">' . count($items) . '</span>
                                </summary>';
                                echo '<ul class="oim-details-list">';
                                foreach ($items as $item) {
                                    if (preg_match('/^(.*?)\s*\(Invoice:\s*(.*?)\)$/i', $item, $matches)) {
                                        $customer_ref = trim($matches[1]);
                                        $invoice_no   = trim($matches[2]);
                                        echo '<li class="oim-detail-item">
                                            <div class="oim-detail-row">
                                                <span class="oim-detail-key">Customer Ref:</span>
                                                <span class="oim-detail-val">' . esc_html($customer_ref) . '</span>
                                            </div>
                                            <div class="oim-detail-row">
                                                <span class="oim-detail-key">Invoice:</span>
                                                <span class="oim-detail-val">' . esc_html($invoice_no) . '</span>
                                            </div>
                                        </li>';
                                    } else {
                                        echo '<li class="oim-detail-item">' . esc_html($item) . '</li>';
                                    }
                                }
                                echo '</ul>';
                                echo '</details>';
                            };

                            $render_list('Imported Orders', $imported_refs, 'fas fa-check-circle', 'success');
                            $render_list('Duplicate Orders Rejected', $duplicate_refs, 'fas fa-clone', 'warning');
                            $render_list('Skipped Rows', $skipped_refs, 'fas fa-times-circle', 'error');

                            echo '</div>';
                        }

                        echo '</div>';
                    }
                }
                ?>

                <form method="post" enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>" class="oim-import-form">
                    <?php wp_nonce_field('oim_import_excel'); ?>
                    <input type="hidden" name="action" value="oim_import_excel">
                    
                    <div class="oim-file-upload-area">
    <div class="oim-upload-icon">
        <i class="fas fa-cloud-upload-alt"></i>
    </div>
    <div class="oim-upload-content">
        <span class="oim-upload-title">Upload Excel File</span>
        <span class="oim-upload-subtitle">Drag and drop or click button below</span>
        
        <!-- Hidden file input -->
        <input type="file" name="excel_file" id="excel-file-input" accept=".xls,.xlsx" class="oim-file-input" style="display: none;">
        
        <!-- Select File Button -->
        <button type="button" class="oim-btn oim-btn-secondary oim-btn-select-file" id="select-file-btn">
            <i class="fas fa-folder-open"></i>
            Select Excel File
        </button>
        
        <!-- File Info (shown after selection) -->
        <div class="oim-file-info" id="file-info-box" style="display: none;">
            <i class="fas fa-file-excel"></i>
            <span class="oim-file-name" id="selected-file-name"></span>
            <button type="button" class="oim-file-remove" id="remove-file-btn" aria-label="Remove file">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    
    <!-- Submit Button -->
    <button type="submit" class="oim-btn oim-btn-primary oim-btn-import" id="import-submit-btn">
        <i class="fas fa-upload"></i>
        Import Excel File
    </button>
</div>
                    
                    <div class="oim-form-notes">
                        <div class="oim-note-item">
                            <i class="fas fa-info-circle"></i>
                            <span>Supported formats: <strong>.xls, .xlsx</strong></span>
                        </div>
                        <div class="oim-note-item">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Invoice number is <strong>required</strong>. Duplicates are checked by invoice number.</span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Section 2: Filters and Search -->
        <div class="oim-card oim-toggle-section" style="display: none;" id="filter-invoices-section">
            <div data-target="filter-section">
                <div class="oim-card-title-group">
                    <div class="oim-card-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="oim-card-title-wrapper">
                        <h2 class="oim-card-title">Filter Orders</h2>
                        <p class="oim-card-description">Search and filter orders by various criteria</p>
                    </div>
                </div>
               
            </div>
            <div class="oim-card-content oim-card-collapsible" id="filter-section">
                <form method="get" action="" class="oim-filter-form">
                    <input type="hidden" name="page" value="oim_orders">
                    
                    <!-- Search Bar -->
                    <div class="oim-search-section">
                        <div class="oim-search-input-group">
                          
                            <input type="search" 
                                   id="order-search-input" 
                                   name="s" 
                                   value="<?php echo esc_attr($search); ?>" 
                                   placeholder="Search orders by ID, customer, invoice number..." 
                                   class="oim-input">
                            <button type="submit" class="oim-btn oim-btn-primary">
                                <i class="fas fa-search"></i>
                                Search
                            </button>
                        </div>
                    </div>
                    <br>
                    <!-- Filter Grid -->
                    <div class="oim-filter-grid">
                        <div class="oim-filter-item">
                            <label for="customer-email-filter" class="oim-filter-label">
                                <i class="fas fa-envelope"></i>
                                Customer Email
                            </label>
                            <input type="text" 
                                   id="customer-email-filter" 
                                   name="customer_email" 
                                   value="<?php echo esc_attr($email_filter); ?>" 
                                   placeholder="Enter email address" 
                                   class="oim-input">
                        </div>
                        
                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-calendar-alt"></i>
                                Date From
                            </label>
                            <input type="date" 
                                   id="date-from-filter" 
                                   name="date_from" 
                                   value="<?php echo esc_attr($date_from); ?>" 
                                   class="oim-input">
                        </div>

                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-calendar-check"></i>
                                Date To
                            </label>
                            <input type="date" 
                                   id="date-to-filter" 
                                   name="date_to" 
                                   value="<?php echo esc_attr($date_to); ?>" 
                                   class="oim-input">
                        </div>

                        <div class="oim-filter-item">
                            <label for="document-filter" class="oim-filter-label">
                                <i class="fas fa-file-alt"></i>
                                Documents
                            </label>
                            <select id="document-filter" name="document_filter" class="oim-select">
                                <option value="">All Orders</option>
                                <option value="with" <?php selected($document_filter, 'with'); ?>>With Documents</option>
                                <option value="without" <?php selected($document_filter, 'without'); ?>>Without Documents</option>
                            </select>
                        </div>

                        <div class="oim-filter-item">
                            <label for="attachment-filter" class="oim-filter-label">
                                <i class="fas fa-paperclip"></i>
                                Attachments
                            </label>
                            <select id="attachment-filter" name="attachment_filter" class="oim-select">
                                <option value="">All Orders</option>
                                <option value="with" <?php selected($attachment_filter, 'with'); ?>>With Attachments</option>
                                <option value="without" <?php selected($attachment_filter, 'without'); ?>>Without Attachments</option>
                            </select>
                        </div>
                    </div>

                    <!-- Filter Actions -->
                    <div class="oim-filter-actions">
                        <button type="submit" class="oim-btn oim-btn-primary">
                            <i class="fas fa-check"></i>
                            Apply Filters
                        </button>
                        <a href="<?php echo esc_url( site_url('/oim-dashboard/orders') ); ?>" class="oim-btn oim-btn-secondary">
                            <i class="fas fa-redo"></i>
                            Reset Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Section 3: Orders Management -->
        <div class="oim-card">
            
            <div class="oim-card-content">
                <form method="post" action="" id="bulk-action-form">
                    <?php wp_nonce_field('oim_bulk_action'); ?>
                    
                    <!-- Bulk Actions & Count -->
                    <div class="oim-table-toolbar">
                        <div class="oim-bulk-actions-wrapper">
                            <select name="bulk_action" id="bulk-action-selector-top" class="oim-select">
                                <option value="">Bulk Actions</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            <button type="submit" class="oim-btn oim-btn-secondary">
                                Apply
                            </button>
                        </div>
                        <div class="oim-orders-count-badge">
                            <i class="fas fa-list"></i>
                            <span><?php echo count($orders); ?> order(s) found</span>
                        </div>
                    </div>

                    <!-- Active Filters Display -->
                    <div id="oim-active-filters-container" class="oim-active-filters" style="display: none;"></div>

                    <!-- Orders Table -->
                    <div class="oim-table-wrapper">
                        <table class="oim-modern-table oim-orders-table">
                            <thead>
                                <tr>
                                    <th class="oim-col-checkbox">
                                        <input type="checkbox" id="cb-select-all-1">
                                    </th>
                                    <th>Internal Order ID</th>
                                    <th>Customer - VAT ID</th>
                                    <th>Customer Company Name</th>
                                    <th>Customer Country</th>
                                    <th>Customer Price</th>
                                    <th>Invoice Number</th>
                                    <th>Invoice Due Date (Days)</th>
                                    <th>Loading Date</th>
                                    <th>Loading Country</th>
                                    <th>Loading ZIP Code</th>
                                    <th>Loading City</th>
                                    <th>Loading Company Name</th>
                                    <th>Unloading Date</th>
                                    <th>Unloading Country</th>
                                    <th>Unloading ZIP Code</th>
                                    <th>Unloading City</th>
                                    <th>Unloading Company Name</th>
                                    <th>Customer Reference</th>
                                    <th>Customer E-mail</th>
                                    <th>Customer Email 2</th>
                                    <th>Customer Phone Number</th>
                                    <th>Customer Phone Number (extra)</th>
                                    <th>Customer Address</th>
                                    <th>Order Note</th>
                                    <th>Truck Number</th>
                                    <th>Customer Company ID (IČO - CRN)</th>
                                    <th>Customer Tax ID (DIČ)</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($orders)): ?>
                                    <?php foreach ($orders as $o):
                                        $data = $parse_order_data($o['data']);
                                        $docs = OIM_DB::get_documents($o['internal_order_id']);
                                        $attachments = maybe_unserialize($o['attachments']);
                                        if (is_string($attachments)) {
                                            $decoded = json_decode($attachments, true);
                                            if (json_last_error() === JSON_ERROR_NONE) $attachments = $decoded;
                                        }
                                        if (!is_array($attachments)) $attachments = [];

                                        $has_docs = !empty($docs);
                                        $has_attachments = !empty($attachments);
                                        $row_class = 'oim-order-row';
                                        if ($has_docs && $has_attachments) {
                                            $row_class .= ' oim-row-docs-attachments';
                                        } elseif ($has_docs) {
                                            $row_class .= ' oim-row-docs-only';
                                        } elseif ($has_attachments) {
                                            $row_class .= ' oim-row-attachments-only';
                                        }
                                        ?>
                                        <tr class="<?php echo esc_attr($row_class); ?>" 
                                            data-order-id="<?php echo esc_attr($o['id']); ?>" 
                                            data-internal-id="<?php echo esc_attr($o['internal_order_id']); ?>" 
                                            data-order-data="<?php echo esc_attr(json_encode($data)); ?>" 
                                            data-created-at="<?php echo esc_attr($o['created_at']); ?>" 
                                            data-documents="<?php echo esc_attr(json_encode($docs)); ?>" 
                                            data-attachments="<?php echo esc_attr(json_encode($attachments)); ?>">
                                            
                                            <td class="oim-col-checkbox">
                                                <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($o['id']); ?>" onclick="event.stopPropagation();">
                                            </td>
                                            <td><?php echo esc_html($o['internal_order_id']); ?></td>
                                            <td><?php echo esc_html($data['vat_id'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_company_name'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_country'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_price'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['invoice_number'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['invoice_due_date_in_days'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['loading_date'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['loading_country'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['loading_zip'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['loading_city'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['loading_company_name'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['unloading_date'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['unloading_country'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['unloading_zip'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['unloading_city'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['unloading_company_name'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_reference'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_email'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_company_email'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_phone'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_company_phone_number'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_company_address'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['order_note'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['truck_number'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_company_ID_crn'] ?? ''); ?></td>
                                            <td><?php echo esc_html($data['customer_tax_ID'] ?? ''); ?></td>
                                            <td><?php echo esc_html($o['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="29" class="oim-no-data">
                                            <div class="oim-no-orders-state">
                                                <div class="oim-no-orders-icon">
                                                    <i class="fas fa-inbox"></i>
                                                </div>
                                                <h3>No Orders Found</h3>
                                                <p>No orders match your current criteria. Try adjusting your filters.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>

        <!-- Order Preview Sidebar -->
        <div id="oim-order-sidebar" class="oim-modern-sidebar">
            <div class="oim-sidebar-header">
                <h3><i class="fas fa-info-circle"></i> Order Details</h3>
                <button type="button" class="oim-sidebar-close" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="oim-sidebar-actions">
                <a href="#" class="oim-action-btn oim-action-edit" id="oim-action-edit">
                    <i class="fas fa-edit"></i>
                    <span>Edit</span>
                </a>
                <a href="#" class="oim-action-btn oim-action-delete" id="oim-action-delete">
                    <i class="fas fa-trash-alt"></i>
                    <span>Delete</span>
                </a>
            </div>
            <div class="oim-sidebar-content">
                <div class="oim-sidebar-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading...</p>
                </div>
            </div>
            <div class="oim-sidebar-footer">
                <button type="button" class="oim-btn oim-btn-secondary oim-sidebar-prev" disabled>
                    <i class="fas fa-chevron-left"></i>
                    Previous
                </button>
                <button type="button" class="oim-btn oim-btn-secondary oim-sidebar-next" disabled>
                    Next
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>
        <div id="oim-sidebar-overlay" class="oim-sidebar-overlay"></div>


        <script>
            jQuery(document).ready(function($) {
  $(document).on('click', '.oim-file-delete', function(e) {
    e.preventDefault();
    
   
    
    const $btn = $(this);
    const $listItem = $btn.closest('.oim-document-item');
    const url = $btn.data('url') || $btn.attr('href'); // Support both button and link
    
    $btn.html('<span class="dashicons dashicons-update dashicons-spin"></span>');
    
    $.get(url, function() {
      $listItem.fadeOut(300, function() {
        $(this).remove();
        
        const $list = $listItem.closest('.oim-document-list');
        if ($list.find('.oim-document-item').length === 0) {
          const type = $list.closest('.oim-card').find('.oim-card-title').text();
          $list.replaceWith(`<p class="oim-empty-state">No ${type.toLowerCase()} found.</p>`);
        }
      });
    }).fail(function() {
      alert('Failed to delete file');
      $btn.html('<span class="dashicons dashicons-trash"></span>');
    });
  });
});


            jQuery(document).ready(function($) {
  // Handle delete for both documents and attachments
  $(document).on('click', '.oim-file-delete', function(e) {
    e.preventDefault();
    
   
    
    const $btn = $(this);
    const $fileItem = $btn.closest('.oim-file-item');
    const url = $btn.attr('href');
    
    $btn.html('<i class="fas fa-spinner fa-spin"></i>');
    
    $.get(url, function() {
      $fileItem.fadeOut(300, function() {
        $(this).remove();
        
        // Update count
        const $section = $fileItem.closest('.oim-files-section');
        const remaining = $section.find('.oim-file-item').length;
        $section.find('.oim-files-subtitle').html(
          $section.find('.oim-files-subtitle').html().replace(/\(\d+\)/, `(${remaining})`)
        );
        
        // Hide section if empty
        if (remaining === 0) {
          const $group = $section.closest('.documents-attachments');
          if ($group.find('.oim-file-item').length === 0) {
            $group.fadeOut(300);
          }
        }
      });
    }).fail(function() {
      alert('Failed to delete file');
      $btn.html('<i class="fas fa-trash-alt"></i>');
    });
  });
});
jQuery(document).ready(function($) {
    $('#toggle-import-btn').on('click', function() {
                $('#import-payments-section').slideToggle(300);
                $('#filter-invoices-section').slideUp(300);
            });

            $('#toggle-filter-btn').on('click', function() {
                $('#filter-invoices-section').slideToggle(300);
                $('#import-payments-section').slideUp(300);
            });
    let currentOrderIndex = -1;
    let allOrders = [];
    let columnFilters = {}; // Stores active filters for each column

    // Build orders array (parse JSON safely)
    $('.oim-order-row').each(function(index) {
        let $row = $(this);
        // data-order-data might be stringified JSON; ensure it's parsed:
        let rowData = $row.attr('data-order-data');
        let docsData = $row.attr('data-documents');
        let attachmentsData = $row.attr('data-attachments');

        try {
            rowData = (typeof $row.data('order-data') === 'object') ? $row.data('order-data') : JSON.parse(rowData);
        } catch (err) {
            rowData = $row.data('order-data') || {};
        }

        try {
            docsData = JSON.parse(docsData);
        } catch (err) {
            docsData = [];
        }

        try {
            attachmentsData = JSON.parse(attachmentsData);
        } catch (err) {
            attachmentsData = [];
        }

        allOrders.push({
            element: $row,
            id: $row.data('order-id'),
            data: rowData,
            createdAt: $row.data('created-at'),
            documents: docsData || [],
            attachments: attachmentsData || []
        });
    });

    // Select all checkboxes
    $('#cb-select-all-1').on('change', function() {
        $('input[name="order_ids[]"]').prop('checked', this.checked);
    });

    // Bulk action confirmation
    $('#bulk-action-form').on('submit', function(e) {
        if ($('select[name="bulk_action"]').val() === 'delete') {
            var checked = $('input[name="order_ids[]"]:checked').length;
            if (checked === 0) {
                alert('Please select at least one order to delete.');
                e.preventDefault();
                return false;
            }
            if (!confirm('Are you sure you want to delete ' + checked + ' order(s)? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Click row -> open sidebar
    $('.oim-order-row').on('click', function(e) {
        // ignore click if it came from a checkbox or action link
        if ($(e.target).is('input[type="checkbox"], a, button')) {
            return;
        }
        currentOrderIndex = allOrders.findIndex(order => String(order.id) === String($(this).data('order-id')));
        if (currentOrderIndex !== -1) {
            openSidebar(allOrders[currentOrderIndex]);
            $('.oim-order-row').removeClass('selected');
            $(this).addClass('selected');
        }
    });

    // Close sidebar
    $('.oim-sidebar-close, .oim-sidebar-overlay').on('click', function() {
        closeSidebar();
    });

    // Prev / Next
    $('.oim-sidebar-prev').on('click', function() {
        if (currentOrderIndex > 0) {
            currentOrderIndex--;
            openSidebar(allOrders[currentOrderIndex]);
            updateSelectedRow();
        }
    });
    $('.oim-sidebar-next').on('click', function() {
        if (currentOrderIndex < allOrders.length - 1) {
            currentOrderIndex++;
            openSidebar(allOrders[currentOrderIndex]);
            updateSelectedRow();
        }
    });
    
    function openSidebar(order) {
        const data = order.data || {};
        const createdAt = order.createdAt || '-';
        const internalId = order.element.data('internal-id') || '-';

        // Update action button URLs
        const baseUrl = '<?php echo site_url("/oim-dashboard"); ?>';
        const dashboardBase = oimAjax.dashboardUrl; // site.com/oim-dashboard

        $('#oim-action-edit').attr('href', dashboardBase + '/edit-order/' + order.id);
        $('#oim-action-view').attr('href', dashboardBase + '/orders/?view=' + order.id);


        const deleteUrl = '<?php echo admin_url("admin-post.php?action=oim_delete_order"); ?>&id=' + order.id;
        const deleteNonce = '<?php echo wp_create_nonce("oim_delete_order_"); ?>' + order.id;
        $('#oim-action-delete').attr('href', deleteUrl + '&_wpnonce=' + deleteNonce)
            .off('click').on('click', function(e) {
                if (!confirm('Are you sure you want to delete this order?')) {
                    e.preventDefault();
                    return false;
                }
            });

        let html = '<div class="oim-sidebar-content-wrapper">';

// 🟣 Order Information
html += `
  <div class="oim-detail-group order-info">
    <h4>Order Information</h4>
    ${renderDetailRow('Internal ID', internalId)}
    ${renderDetailRow('Created At', createdAt)}
    ${renderDetailRow('Truck Number', data.truck_number)}
  </div>
`;

// 🟢 Invoice Details
html += `
  <div class="oim-detail-group invoice-details">
    <h4>Invoice Details</h4>
    ${renderDetailRow('Invoice Number', data.invoice_number)}
    ${renderDetailRow('Due Date (days)', data.invoice_due_date_in_days)}
  </div>
`;

// 🔵 Customer Information
html += `
  <div class="oim-detail-group customer-info">
    <h4>Customer Information</h4>
    ${renderDetailRow('Reference', data.customer_reference)}
    ${renderDetailRow('VAT ID', data.vat_id)}
    ${renderDetailRow('Email', data.customer_email)}
    ${renderDetailRow('Company Name', data.customer_company_name)}
    ${renderDetailRow('Country', data.customer_country)}
    ${renderDetailRow('Price', data.customer_price)}
    ${renderDetailRow('Company Email', data.customer_company_email)}
    ${renderDetailRow('Company Phone', data.customer_company_phone_number)}
    ${renderDetailRow('Company Address', data.customer_company_address)}
    ${renderDetailRow('Customer Company ID (IČO - CRN)', data.customer_company_ID_crn)}
    ${renderDetailRow('Customer Tax ID (DIČ)', data.customer_tax_ID)}
  </div>
`;

// 🟠 Loading & Unloading Information (3-column layout)
html += `
  <div class="oim-detail-group loading-unloading">
    <h4>Loading & Unloading Information</h4>
    <div class="oim-triplet-table">
      <div class="oim-triplet-row oim-triplet-header">
        <div class="oim-triplet-cell oim-triplet-label">Field</div>
        <div class="oim-triplet-cell">Loading</div>
        <div class="oim-triplet-cell">Unloading</div>
      </div>
      ${createTripletRow('Company', data.loading_company_name, data.unloading_company_name)}
      ${createTripletRow('Date', data.loading_date, data.unloading_date)}
      ${createTripletRow('Country', data.loading_country, data.unloading_country)}
      ${createTripletRow('Zip Code', data.loading_zip, data.unloading_zip)}
      ${createTripletRow('City', data.loading_city, data.unloading_city)}
    </div>
  </div>
`;
    const docs = order.documents || [];

    const hasDocs = Array.isArray(docs) && docs.length > 0;
let hasAttachments = order.attachments && order.attachments.length > 0;
if (hasDocs || hasAttachments) {
  html += `
    <div class="oim-detail-group documents-attachments">
      <h4>Documents & Attachments</h4>
      <div class="oim-files-list">
  `;
  
  // Documents Section
  if (hasDocs) {
    html += `
      <div class="oim-files-section oim-docs-section">
        <h5 class="oim-files-subtitle">
          <i class="fas fa-file-alt"></i> Driver Documents (${order.documents.length})
        </h5>
    `;
    
    order.documents.forEach(function (doc) {
      const ext = doc.filename.split('.').pop().toLowerCase();
      let icon = 'fa-file';
      
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
        icon = 'fa-file-image';
      } else if (ext === 'pdf') {
        icon = 'fa-file-pdf';
      } else if (['doc', 'docx'].includes(ext)) {
        icon = 'fa-file-word';
      } else if (['xls', 'xlsx', 'csv'].includes(ext)) {
        icon = 'fa-file-excel';
      } else if (['zip', 'rar', '7z'].includes(ext)) {
        icon = 'fa-file-archive';
      }
      
      const deleteUrl = '<?php echo admin_url("admin-post.php?action=oim_delete_doc&_wpnonce=" . wp_create_nonce("oim_delete_doc")); ?>'
    + '&doc_id=' + doc.id
    + '&order_id=' + internalId;

      html += `
        <div class="oim-file-item oim-doc-item">
          <i class="fas ${icon}"></i>
          <a href="${doc.file_url}" target="_blank" class="oim-file-link">${doc.filename}</a>
          <div class="oim-file-actions">
            <a href="${doc.file_url}" target="_blank" class="oim-file-view" title="View">
              <i class="fas fa-eye"></i>
            </a>
            <a href="${doc.file_url}" download class="oim-file-download" title="Download">
              <i class="fas fa-download"></i>
            </a>
            <a href="${deleteUrl}" class="oim-file-delete" title="Delete">
              <i class="fas fa-trash-alt"></i>
            </a>
          </div>
        </div>
      `;
    });
    
    html += `</div>`;
  }

  // Attachments Section
  if (hasAttachments) {
    html += `
      <div class="oim-files-section oim-attachments-section">
        <h5 class="oim-files-subtitle">
          <i class="fas fa-paperclip"></i> Order Attachments (${order.attachments.length})
        </h5>
    `;
    
    order.attachments.forEach(function (url) {
      const filename = url.split('/').pop();
      const ext = filename.split('.').pop().toLowerCase();
      let icon = 'fa-file';
      
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
        icon = 'fa-file-image';
      } else if (ext === 'pdf') {
        icon = 'fa-file-pdf';
      } else if (['doc', 'docx'].includes(ext)) {
        icon = 'fa-file-word';
      } else if (['xls', 'xlsx', 'csv'].includes(ext)) {
        icon = 'fa-file-excel';
      } else if (['zip', 'rar', '7z'].includes(ext)) {
        icon = 'fa-file-archive';
      }
      
      const deleteUrl = '<?php echo admin_url("admin-post.php?action=oim_delete_attachment&_wpnonce=" . wp_create_nonce("oim_delete_attachment")); ?>'
    + '&order_id=' + order.id
    + '&file=' + encodeURIComponent(url);

      html += `
        <div class="oim-file-item oim-attachment-item">
          <i class="fas ${icon}"></i>
          <a href="${url}" target="_blank" class="oim-file-link">${filename}</a>
          <div class="oim-file-actions">
            <a href="${url}" target="_blank" class="oim-file-view" title="View">
              <i class="fas fa-eye"></i>
            </a>
            <a href="${url}" download class="oim-file-download" title="Download">
              <i class="fas fa-download"></i>
            </a>
            <a href="${deleteUrl}" class="oim-file-delete" title="Delete">
              <i class="fas fa-trash-alt"></i>
            </a>
          </div>
        </div>
      `;
    });
    
    html += `</div>`;
  }

  html += `
      </div>
    </div>
  `;
}
// 🟢 Order Note
html += `
  <div class="oim-detail-group notes">
    ${renderDetailRow('Order Note', data.order_note)}
  </div>
`;
html += '</div>'; // close wrapper


// ✅ Helper: Triplet Row Creator
function createTripletRow(label, loadingVal, unloadingVal) {
  return `
    <div class="oim-triplet-row">
      <div class="oim-triplet-cell oim-triplet-label">${label}</div>
      <div class="oim-triplet-cell">${loadingVal || '-'}</div>
      <div class="oim-triplet-cell">${unloadingVal || '-'}</div>
    </div>
  `;
}



        $('.oim-sidebar-content').html(html);
        $('#oim-order-sidebar').addClass('open');
        $('.oim-sidebar-overlay').addClass('active');

        updateNavigationButtons();
    }

    function renderDetailRow(label, value) {
        if (value === undefined || value === null || value === '') value = '-';
        return '<div class="oim-detail-row"><div class="oim-detail-label">' + label + ':</div><div class="oim-detail-value">' + $('<div>').text(value).html() + '</div></div>';
    }

    function closeSidebar() {
        $('#oim-order-sidebar').removeClass('open');
        $('.oim-sidebar-overlay').removeClass('active');
        $('.oim-order-row').removeClass('selected');
        currentOrderIndex = -1;
    }

    function updateSelectedRow() {
        $('.oim-order-row').removeClass('selected');
        if (currentOrderIndex >= 0 && currentOrderIndex < allOrders.length) {
            let selected = allOrders[currentOrderIndex].element;
            selected.addClass('selected');

            // scroll wrapper to show selected row centrally
            const tableWrapper = $('.oim-table-wrapper');
            if (tableWrapper.length) {
                const wrapperTop = tableWrapper.offset().top;
                const rowTop = selected.offset().top;
                const scrollTop = tableWrapper.scrollTop();
                const diff = rowTop - wrapperTop;
                tableWrapper.animate({ scrollTop: scrollTop + diff - tableWrapper.height()/2 }, 220);
            }
        }
    }

    function updateNavigationButtons() {
        $('.oim-sidebar-prev').prop('disabled', currentOrderIndex <= 0);
        $('.oim-sidebar-next').prop('disabled', currentOrderIndex >= allOrders.length - 1);
    }

    // Close on escape
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#oim-order-sidebar').hasClass('open')) {
            closeSidebar();
        }
    });

    // Arrow keys for navigation
    $(document).on('keydown', function(e) {
        if ($('#oim-order-sidebar').hasClass('open')) {
            if (e.key === 'ArrowLeft' && currentOrderIndex > 0) $('.oim-sidebar-prev').click();
            else if (e.key === 'ArrowRight' && currentOrderIndex < allOrders.length - 1) $('.oim-sidebar-next').click();
        }
    });

    /***********************
     * Column Resizing Logic
     ***********************/
    
    (function enableColumnResizing() {
        const $table = $('.oim-orders-table');
        if (!$table.length) return;

        // Calculate minimum width for each column based on header text
        const minWidths = [];

        $table.find('thead th').each(function(index) {
            const $th = $(this);

            // Measure header text to get minimum width
            const headerText = $th.text().trim();
            const $measure = $('<span>').text(headerText).css({
                'display': 'inline-block',
                'visibility': 'hidden',
                'position': 'absolute',
                'font-weight': '600',
                'padding': '10px 12px',
                'white-space': 'nowrap',
                'font-size': '14px'
            }).appendTo('body');

            const minWidth = 40;
            $measure.remove();

            minWidths[index] = minWidth;

            // Set initial minimum width
            if (!$th.hasClass('check-column')) {
    $th.css('min-width', minWidth + 'px');
}
            $table.find('tbody tr').each(function() {
                $(this).find('td').eq(index).css('min-width', minWidth + 'px');
            });
            
            // Add resizer handle (only if not checkbox column)
            if (!$th.hasClass('check-column')) {
                $th.append('<span class="oim-col-resizer" data-col-index="' + index + '" data-min-width="' + minWidth + '" style="pointer-events: auto;"></span>');
            }
        });

        let isResizing = false;
        let startX = 0;
        let $currentTh, startWidth, colIndex, minWidth;

        $table.on('mousedown', '.oim-col-resizer', function(e) {
    // Don't prevent if clicking on filter icon
    if ($(e.target).hasClass('oim-filter-icon') || $(e.target).hasClass('dashicons-filter')) {
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    isResizing = true;
    $('html').addClass('dragging');
    $currentTh = $(this).closest('th');
    startX = e.pageX;
    startWidth = $currentTh.width(); // Use width() instead of outerWidth()
    colIndex = $currentTh.index();
    minWidth = 40; // Allow minimum 40px
});

        $(document).on('mousemove', function(e) {
    if (!isResizing || !$currentTh) return;
    const dx = e.pageX - startX;
    let newWidth = Math.max(40, startWidth + dx);

    // Set width on header cell
    $currentTh.css({
        'width': newWidth + 'px',
        'min-width': newWidth + 'px',
        'max-width': newWidth + 'px',
        'box-sizing': 'border-box'
    });

    // Set width on all body cells in that column
    $table.find('tbody tr').each(function() {
        const $cell = $(this).find('td, th').eq(colIndex);
        $cell.css({
            'width': newWidth + 'px',
            'min-width': newWidth + 'px',
            'max-width': newWidth + 'px',
            'box-sizing': 'border-box'
        });
    });
});

        $(document).on('mouseup', function() {
            if (isResizing) {
                isResizing = false;
                $('html').removeClass('dragging');
                $currentTh = null;
            }
        });

        // Double-click to auto-fit column to content
        $table.on('dblclick', '.oim-col-resizer', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $th = $(this).closest('th');
            const idx = $th.index();
            const minW = parseInt($(this).attr('data-min-width')) || 80;
            let maxW = minW;

            // Measure cell content (limit to first 100 rows for performance)
            $table.find('tbody tr').slice(0, 100).each(function() {
                const $cell = $(this).find('td').eq(idx);
                if ($cell.length) {
                    const $clone = $cell.clone().css({
                        'display': 'inline-block',
                        'width': 'auto',
                        'position': 'absolute',
                        'visibility': 'hidden',
                        'white-space': 'nowrap',
                        'padding': '8px 12px'
                    }).appendTo('body');
                    const w = Math.ceil($clone.outerWidth()) + 8;
                    $clone.remove();
                    if (w > maxW) maxW = w;
                }
            });
            // Add this right after the minWidths calculation loop, before adding resizers:

// Force checkbox column to be narrow
$table.find('thead th.check-column').css({
    'width': '10px',
    'min-width': '10px',
    'max-width': '50px'
});
$table.find('tbody tr').each(function() {
    $(this).find('th.check-column, td.check-column').css({
        'width': '10px',
        'min-width': '10px',
        'max-width': '50px'
    });
});
            // Apply the calculated width
            $th.css({
                'width': maxW + 'px',
                'max-width': maxW + 'px'
            });
            $table.find('tbody tr').each(function() {
                $(this).find('td').eq(idx).css({
                    'width': maxW + 'px',
                    'max-width': maxW + 'px'
                });
            });
        });

    })();

    // Accessibility: prevent accidental text selection while resizing
    $(document).on('selectstart', function(e) {
        if ($('html').hasClass('dragging')) e.preventDefault();
    });

    /****************************
     * Excel-like Column Filtering
     ****************************/
    (function enableColumnFiltering() {
        const $table = $('.oim-orders-table');
        if (!$table.length) return;

        // Add filter icon to each header (except checkbox column)
        $table.find('thead th').each(function(index) {
            if ($(this).hasClass('check-column')) return;

            const $th = $(this);
            const headerText = $th.find('a').text() || $th.text().trim();
            const columnIndex = $th.index();

            $th.css('position', 'relative');
            const $filterIcon = $('<span class="oim-filter-icon dashicons dashicons-filter" data-column="' + columnIndex + '" data-column-name="' + headerText + '" style="position: relative; z-index: 100; pointer-events: auto; cursor: pointer !important; margin-right: 10px;"></span>');

            // Insert filter icon before resizer if it exists, otherwise just append
            const $resizer = $th.find('.oim-col-resizer');
            if ($resizer.length) {
                $resizer.before($filterIcon);
            } else {
                $th.append($filterIcon);
            }

            // Prevent header link from navigating when clicking the filter icon area
            $th.find('a').on('click', function(e) {
                if ($(e.target).hasClass('oim-filter-icon') || $(e.target).hasClass('dashicons-filter')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });
        });

        // Handle filter icon click with delegation
        $(document).on('click', '.oim-filter-icon', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $icon = $(this);
            const columnIndex = parseInt($icon.data('column'));
            const columnName = $icon.data('column-name');

            console.log('Filter icon clicked:', columnIndex, columnName);

            // If dropdown is already open for this column, close it
            const $existingDropdown = $icon.closest('th').find('.oim-filter-dropdown');
            if ($existingDropdown.length && $existingDropdown.hasClass('active')) {
                $existingDropdown.remove();
                $icon.removeClass('active');
                $icon.closest('th').css('z-index', '');
                return false;
            }

            // Close any open dropdowns
            $('.oim-filter-dropdown').remove();
            $('.oim-filter-icon').removeClass('active');
            $('thead th').css('z-index', '');

            // Build and show dropdown
            $icon.addClass('active');
const $dropdown = buildFilterDropdown(columnIndex, columnName);
console.log('Dropdown created:', $dropdown.length, $dropdown);

// Set higher z-index for the th to ensure dropdown is visible
const $th = $icon.closest('th');
$th.css({
    'z-index': '999999',
    'position': 'relative'
});

// CRITICAL FIX: Append to body instead of th to avoid overflow clipping
$dropdown.appendTo('body');
$dropdown.addClass('active');

// Position dropdown relative to the filter icon using getBoundingClientRect for accurate positioning
const iconRect = $icon[0].getBoundingClientRect();
const iconHeight = iconRect.height;
const scrollTop = $(window).scrollTop();
const scrollLeft = $(window).scrollLeft();

$dropdown.css({
    'position': 'absolute',
    'top': (iconRect.top + scrollTop + iconHeight + 5) + 'px',
    'left': (iconRect.left + scrollLeft) + 'px',
    'z-index': '999999',
    'display': 'block'
});

console.log('Dropdown added and activated');

            return false;
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.oim-filter-dropdown, .oim-filter-icon').length) {
                $('.oim-filter-dropdown').remove();
                $('.oim-filter-icon').removeClass('active');
                $('thead th').css('z-index', '');
            }
        });

        function buildFilterDropdown(columnIndex, columnName) {
            // Get all visible rows (after current filters)
            const $visibleRows = $table.find('tbody tr.oim-order-row:visible');

            // Adjust for checkbox column - we need to get the td index, not th index
            // The first th is checkbox (index 0), so td columns start from index 0
            const tdIndex = columnIndex - 1; // subtract 1 because first th is checkbox

            // Collect unique values from the column
            const valueMap = {};
            $visibleRows.each(function() {
                const cellValue = $(this).find('td').eq(tdIndex).text().trim();
                if (cellValue) {
                    valueMap[cellValue] = (valueMap[cellValue] || 0) + 1;
                }
            });

            // Sort values alphabetically
            const sortedValues = Object.keys(valueMap).sort((a, b) => a.localeCompare(b));

            // Build dropdown HTML
            const $dropdown = $('<div class="oim-filter-dropdown"></div>');

            // Search input
            const $search = $('<input type="text" class="oim-filter-search" placeholder="Search...">');
            $dropdown.append($search);

            // Select All option with checkbox
            const $selectAll = $('<div class="oim-filter-select-all"><input type="checkbox" id="select-all-' + columnIndex + '" class="select-all-checkbox"> <label for="select-all-' + columnIndex + '">Select All</label></div>');
            $dropdown.append($selectAll);

            // Options container
            const $options = $('<div class="oim-filter-options"></div>');

            const currentFilters = columnFilters[columnIndex] || [];

            sortedValues.forEach(value => {
                const count = valueMap[value];
                const isChecked = currentFilters.length === 0 || currentFilters.includes(value);
                const checkboxId = 'filter-' + columnIndex + '-' + value.replace(/[^a-zA-Z0-9]/g, '');

                const $option = $(`
                    <div class="oim-filter-option">
                        <input type="checkbox" id="${checkboxId}" value="${value}" ${isChecked ? 'checked' : ''}>
                        <label for="${checkboxId}">${value}</label>
                        <span class="oim-filter-count">(${count})</span>
                    </div>
                `);
                $options.append($option);
            });

            $dropdown.append($options);

            // Action buttons
            const $actions = $(`
                <div class="oim-filter-actions">
                    <button class="oim-filter-btn apply">Apply</button>
                    <button class="oim-filter-btn reset">Reset</button>
                </div>
            `);
            $dropdown.append($actions);

            // Update Select All checkbox state
            function updateSelectAllState() {
                const $selectAllCheckbox = $selectAll.find('.select-all-checkbox');
                const totalVisible = $options.find('input:checkbox:visible').length;
                const totalChecked = $options.find('input:checkbox:checked:visible').length;
                $selectAllCheckbox.prop('checked', totalVisible > 0 && totalVisible === totalChecked);
            }

            // Initialize Select All state
            updateSelectAllState();

            // Prevent dropdown from closing when clicking inside
            $dropdown.on('click', function(e) {
                e.stopPropagation();
            });

            // Search functionality
            $search.on('input', function(e) {
                e.stopPropagation();
                const searchTerm = $(this).val().toLowerCase();
                $options.find('.oim-filter-option').each(function() {
                    const text = $(this).find('label').text().toLowerCase();
                    $(this).toggle(text.includes(searchTerm));
                });
                updateSelectAllState();
            });

            // Select All functionality
            $selectAll.on('click', function(e) {
                e.stopPropagation();
                const $selectAllCheckbox = $(this).find('.select-all-checkbox');
                const shouldCheck = !$selectAllCheckbox.prop('checked');
                $selectAllCheckbox.prop('checked', shouldCheck);
                $options.find('input:checkbox:visible').prop('checked', shouldCheck);
            });

            // Handle checkbox clicks
            $options.on('click', 'input[type="checkbox"]', function(e) {
                e.stopPropagation();
                updateSelectAllState();
            });

            $options.on('click', '.oim-filter-option', function(e) {
                e.stopPropagation();
                const $checkbox = $(this).find('input[type="checkbox"]');
                $checkbox.prop('checked', !$checkbox.prop('checked'));
                updateSelectAllState();
            });

            // Apply filter
            $actions.find('.apply').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const selectedValues = [];
                $options.find('input:checkbox:checked').each(function() {
                    selectedValues.push($(this).val());
                });

                if (selectedValues.length === sortedValues.length) {
                    // All selected = no filter
                    delete columnFilters[columnIndex];
                } else {
                    columnFilters[columnIndex] = selectedValues;
                }

                applyFilters();
                $dropdown.remove();
                $('.oim-filter-icon').removeClass('active');
                $('thead th').css('z-index', '');

                return false;
            });

            // Reset filter for this column
            $actions.find('.reset').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                delete columnFilters[columnIndex];
                applyFilters();
                $dropdown.remove();
                $('.oim-filter-icon[data-column="' + columnIndex + '"]').removeClass('active');

                return false;
            });

            return $dropdown;
        }

        function applyFilters() {
            $table.find('tbody tr.oim-order-row').each(function() {
                const $row = $(this);
                let showRow = true;

                // Check each active filter
                for (let colIndex in columnFilters) {
                    const filterValues = columnFilters[colIndex];
                    const tdIndex = parseInt(colIndex) - 1; // Adjust for checkbox column
                    const cellValue = $row.find('td').eq(tdIndex).text().trim();

                    if (filterValues.length > 0 && !filterValues.includes(cellValue)) {
                        showRow = false;
                        break;
                    }
                }

                $row.toggle(showRow);
            });

            // Update order count
            const visibleCount = $table.find('tbody tr.oim-order-row:visible').length;
            $('.oim-orders-count').text(visibleCount + ' order(s) found');

            // Update active filters display
            updateActiveFiltersDisplay();

            // Rebuild allOrders array with visible rows only
            rebuildOrdersArray();
        }

        function updateActiveFiltersDisplay() {
            const $container = $('#oim-active-filters-container');
            $container.empty();

            if (Object.keys(columnFilters).length === 0) {
                $container.hide();
                return;
            }

            $container.show();

            // Add filter tags
            for (let colIndex in columnFilters) {
                const filterValues = columnFilters[colIndex];
                const $th = $table.find('thead th').eq(parseInt(colIndex));
                const columnName = $th.find('a').text() || $th.text().trim().replace('▼', '').trim();

                filterValues.forEach(value => {
                    const $tag = $(`
                        <div class="oim-filter-tag">
                            ${columnName}: ${value}
                            <span class="oim-filter-tag-remove" data-column="${colIndex}" data-value="${value}">×</span>
                        </div>
                    `);
                    $container.append($tag);
                });
            }

            // Add reset all button
            const $resetAll = $('<button class="oim-reset-all-filters">Reset All Filters</button>');
            $container.append($resetAll);

            // Handle individual filter tag removal
            $container.find('.oim-filter-tag-remove').on('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    
    const colIndex = $(this).data('column');
    const value = $(this).data('value');

    if (columnFilters[colIndex]) {
        columnFilters[colIndex] = columnFilters[colIndex].filter(v => v !== value);
        if (columnFilters[colIndex].length === 0) {
            delete columnFilters[colIndex];
            $('.oim-filter-icon[data-column="' + colIndex + '"]').removeClass('active');
        }
    }

    // Use setTimeout to ensure the click event completes before DOM manipulation
    setTimeout(function() {
        applyFilters();
    }, 0);
});

            // Handle reset all
            $resetAll.on('click', function() {
                columnFilters = {};
                $('.oim-filter-icon').removeClass('active');
                applyFilters();
            });
        }

        function rebuildOrdersArray() {
            allOrders = [];
            $table.find('tbody tr.oim-order-row:visible').each(function(index) {
                let $row = $(this);
                let rowData = $row.attr('data-order-data');
                let docsData = $row.attr('data-documents');
                let attachmentsData = $row.attr('data-attachments');

                try {
                    rowData = (typeof $row.data('order-data') === 'object') ? $row.data('order-data') : JSON.parse(rowData);
                } catch (err) {
                    rowData = $row.data('order-data') || {};
                }

                try {
                    docsData = JSON.parse(docsData);
                } catch (err) {
                    docsData = [];
                }

                try {
                    attachmentsData = JSON.parse(attachmentsData);
                } catch (err) {
                    attachmentsData = [];
                }

                allOrders.push({
                    element: $row,
                    id: $row.data('order-id'),
                    data: rowData,
                    createdAt: $row.data('created-at'),
                    documents: docsData || [],
                    attachments: attachmentsData || []
                });
            });
        }
    })();

});
jQuery(document).ready(function($) {
    // Handle collapsible section toggle
    $('.oim-card-trigger').on('click', function() {
        const $trigger = $(this);
        const targetId = $trigger.data('target');
        const $content = $('#' + targetId);
        const $icon = $trigger.find('.oim-section-toggle-icon');
        
        // Toggle the content
        $content.slideToggle(300, function() {
            // Update icon after animation completes
            if ($content.is(':visible')) {
                $icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
                $trigger.addClass('active');
            } else {
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
                $trigger.removeClass('active');
            }
        });
    });
    
    // Auto-expand sections if there are active filters or import results
    <?php if (!empty($search) || !empty($email_filter) || !empty($date_from) || !empty($date_to)): ?>
    $('#filter-section').show();
    $('.oim-card-trigger[data-target="filter-section"]')
        .addClass('active')
        .find('.oim-section-toggle-icon')
        .removeClass('dashicons-arrow-right-alt2')
        .addClass('dashicons-arrow-down-alt2');
    <?php endif; ?>
    
    <?php if (isset($_GET['import_result'])): ?>
    $('#import-section').show();
    $('.oim-card-trigger[data-target="import-section"]')
        .addClass('active')
        .find('.oim-section-toggle-icon')
        .removeClass('dashicons-arrow-right-alt2')
        .addClass('dashicons-arrow-down-alt2');
    <?php endif; ?>
});
// ========================================
// Excel File Upload Functionality
// ========================================

(function enableFileUpload() {
    const fileInput = document.getElementById('excel-file-input');
    const selectFileBtn = document.getElementById('select-file-btn');
    const fileInfoBox = document.getElementById('file-info-box');
    const selectedFileName = document.getElementById('selected-file-name');
    const removeFileBtn = document.getElementById('remove-file-btn');
    const uploadArea = document.querySelector('.oim-file-upload-area');
    const importForm = fileInput ? fileInput.closest('form') : null;
    
    if (!fileInput || !selectFileBtn) return;
    
    // Click "Select File" button to open file manager
    selectFileBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        fileInput.click();
    });
    
    // Handle file selection
    fileInput.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            const file = this.files[0];
            
            // Show file info with name
            if (selectedFileName) {
                selectedFileName.textContent = file.name;
            }
            if (fileInfoBox) {
                fileInfoBox.style.display = 'flex';
            }
            // Hide select button when file is selected
            if (selectFileBtn) {
                selectFileBtn.style.display = 'none';
            }
        } else {
            resetFileUpload();
        }
    });
    
    // Remove file button
    if (removeFileBtn) {
        removeFileBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            resetFileUpload();
        });
    }
    
    // Reset file upload state
    function resetFileUpload() {
        if (fileInput) {
            fileInput.value = '';
        }
        if (fileInfoBox) {
            fileInfoBox.style.display = 'none';
        }
        if (selectedFileName) {
            selectedFileName.textContent = '';
        }
        // Show select button again
        if (selectFileBtn) {
            selectFileBtn.style.display = 'inline-flex';
        }
    }
    
    // Form validation before submit
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            if (!fileInput.files || fileInput.files.length === 0) {
                e.preventDefault();
                alert('Please select an Excel file to import.');
                
                // Highlight the select button
                if (selectFileBtn) {
                    selectFileBtn.style.borderColor = '#ef4444';
                    selectFileBtn.style.background = '#fef2f2';
                    selectFileBtn.style.color = '#ef4444';
                    
                    setTimeout(function() {
                        selectFileBtn.style.borderColor = '';
                        selectFileBtn.style.background = '';
                        selectFileBtn.style.color = '';
                    }, 2000);
                }
                
                return false;
            }
            
            // Validate file type
            const file = fileInput.files[0];
            const fileExt = file.name.split('.').pop().toLowerCase();
            
            if (fileExt !== 'xls' && fileExt !== 'xlsx') {
                e.preventDefault();
                alert('Please select a valid Excel file (.xls or .xlsx)');
                return false;
            }
            
            return true;
        });
    }
    
    // ========================================
    // Drag and Drop Functionality
    // ========================================
    
    if (uploadArea) {
        // Prevent default behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });
        
        // Add visual feedback on drag
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, function() {
                uploadArea.classList.add('dragover');
            }, false);
        });
        
        // Remove visual feedback
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, function() {
                uploadArea.classList.remove('dragover');
            }, false);
        });
        
        // Handle drop
        uploadArea.addEventListener('drop', function(e) {
            const files = e.dataTransfer.files;
            
            if (files && files.length > 0) {
                const file = files[0];
                const fileExt = file.name.split('.').pop().toLowerCase();
                
                // Validate file type
                if (fileExt === 'xls' || fileExt === 'xlsx') {
                    // Set file to input
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;
                    
                    // Trigger change event
                    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                } else {
                    alert('Please select a valid Excel file (.xls or .xlsx)');
                }
            }
        });
    }
    
})();
</script>
<style>


</style>
    </div>
    <?php
}

/**
 * Helper to generate sortable links that preserve filters.
 */
private static function sort_link($column) {
    $order = ($_GET['orderby'] ?? '') === $column && ($_GET['order'] ?? '') === 'ASC' ? 'DESC' : 'ASC';
    $query = array_merge($_GET, ['orderby' => $column, 'order' => $order]);
    return esc_url(add_query_arg($query, admin_url('admin.php')));
}


    public static function handle_import_excel() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    check_admin_referer('oim_import_excel');

    if (empty($_FILES['excel_file']['tmp_name'])) wp_die('No file uploaded');
    $upload = wp_handle_upload($_FILES['excel_file'], ['test_form' => false]);
    if (empty($upload['file'])) wp_die('Upload failed');
    $res = OIM_DB::import_excel($upload['file']);
    
    // Clean up the uploaded file
    if (file_exists($upload['file'])) {
        unlink($upload['file']);
    }
    
    // Redirect with full result data
    $result_param = base64_encode(json_encode($res));
    wp_safe_redirect(site_url('/oim-dashboard/orders/?import_result=' . urlencode($result_param)));
    exit;
}

    public static function render_view_order($id) {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $order = OIM_DB::get_order_by_id($id);
    if (!$order) wp_die('Order not found');
    $data = maybe_unserialize($order['data']);
    ?>
    <div class="wrap">
        <h1>Order: <?php echo esc_html($order['internal_order_id']); ?></h1>
        <table class="form-table">
            <?php foreach ($data as $k => $v): ?>
                <tr>
                    <th style="text-align:left;"><?php echo esc_html($k); ?></th>
                    <td><?php echo esc_html(is_array($v) ? json_encode($v) : $v); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h2>Documents</h2>
        <?php 
        $docs = OIM_DB::get_documents($order['internal_order_id']); 
        if ($docs): ?>
            <ul>
                <?php foreach ($docs as $d): ?>
                    <li>
                        <?php echo esc_html($d['filename']); ?> — 
                        <a href="<?php echo esc_url($d['file_url']); ?>" target="_blank">Download</a> | 
                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=oim_delete_doc&doc_id=' . $d['id'] . '&order_id=' . $order['id']), 'oim_delete_doc_' . $d['id']); ?>" 
                           onclick="return confirm('Delete this document?');">Delete</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No documents uploaded yet.</p>
        <?php endif; ?>

        <h2>Attachments</h2>
        <?php 
        $attachments = maybe_unserialize($order['attachments']);
        if (is_string($attachments)) {
            $decoded = json_decode($attachments, true);
            if (json_last_error() === JSON_ERROR_NONE) $attachments = $decoded;
        }

        if (!empty($attachments) && is_array($attachments)): ?>
            <ul>
                <?php foreach ($attachments as $url): ?>
                    <li>
                        <a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo basename($url); ?></a> | 
                        <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=oim_delete_attachment&order_id=' . $order['id'] . '&file=' . urlencode($url)), 'oim_delete_attachment_' . $order['id']); ?>" 
                           onclick="return confirm('Delete this attachment?');">Delete</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No attachments found.</p>
        <?php endif; ?>

        <p><a href="<?php echo admin_url('admin.php?page=oim_orders'); ?>">← Back to list</a></p>
    </div>
    <?php
}


    

    public static function render_edit_order($id) {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $order = OIM_DB::get_order_by_id($id);
    if (!$order) wp_die('Order not found');

    $data = maybe_unserialize($order['data']);
    $saved = false;
    
    $allowedFields = [
        'customer_reference','vat_id','customer_email','customer_company_name',
        'customer_country','customer_price','invoice_number','invoice_due_date_in_days',
        'customer_company_email','customer_company_phone_number','customer_company_address',
        'loading_company_name','loading_date','loading_country','loading_zip','loading_city',
        'unloading_company_name','unloading_date','unloading_country','unloading_zip','unloading_city', 
        'order_note', 'truck_number', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
    ];
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['order_id']) && intval($_POST['order_id']) === $id) {
        check_admin_referer('oim_save_order_' . $id);

        $new = [];
        foreach ($allowedFields as $f) {
            $new[$f] = isset($_POST[$f]) ? sanitize_text_field($_POST[$f]) : '';
        }

        OIM_DB::update_order($id, $new);
        $data = $new;
        $saved = true;
    }
    ?>
    <div class="wrap oim-invoice-edit">
        <div class="oim-header">
            <h1>Edit Order #<?php echo esc_html($order['internal_order_id']); ?></h1>
            <a href="<?php echo site_url('/oim-dashboard/orders'); ?>" class="button">← Back to Orders</a>
        </div>
        
        <?php if ($saved): ?>
            <div class="oim-notice oim-notice-success">
                Order saved successfully!
            </div>
        <?php endif; ?>

        <form method="post" class="oim-form">
            <?php wp_nonce_field('oim_save_order_' . $id); ?>
            <input type="hidden" name="order_id" value="<?php echo esc_attr($id); ?>">

            <div class="oim-layout">
                <!-- Main Content -->
                <div class="oim-main">
                    
                    <!-- Customer & Contact -->
                    <div class="oim-card">
                        <h3 class="oim-card-title">Customer & Contact</h3>
                        
                        <div class="oim-grid-3">
                            <div class="oim-field">
                                <label>Customer Reference</label>
                                <input type="text" name="customer_reference" value="<?php echo esc_attr($data['customer_reference'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Truck Number</label>
                                <input type="text" name="truck_number" value="<?php echo esc_attr($data['truck_number'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>VAT ID</label>
                                <input type="text" name="vat_id" value="<?php echo esc_attr($data['vat_id'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Customer Email</label>
                                <input type="email" name="customer_email" value="<?php echo esc_attr($data['customer_email'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Customer Phone</label>
                                <input type="text" name="customer_phone" value="<?php echo esc_attr($data['customer_phone'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Company Name</label>
                                <input type="text" name="customer_company_name" value="<?php echo esc_attr($data['customer_company_name'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Country</label>
                                <input type="text" name="customer_country" value="<?php echo esc_attr($data['customer_country'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Company Email</label>
                                <input type="email" name="customer_company_email" value="<?php echo esc_attr($data['customer_company_email'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Company Phone</label>
                                <input type="text" name="customer_company_phone_number" value="<?php echo esc_attr($data['customer_company_phone_number'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Customer Price</label>
                                <input type="number" step="0.01" name="customer_price" value="<?php echo esc_attr($data['customer_price'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Company ID (IČO - CRN)</label>
                                <input type="text" name="customer_company_ID_crn" value="<?php echo esc_attr($data['customer_company_ID_crn'] ?? ''); ?>">
                            </div>
                            <div class="oim-field">
                                <label>Tax ID (DIČ)</label>
                                <input type="text" name="customer_tax_ID" value="<?php echo esc_attr($data['customer_tax_ID'] ?? ''); ?>">
                            </div>
                            <div class="oim-field oim-span-3">
                                <label>Company Address</label>
                                <textarea name="customer_company_address" rows="2"><?php echo esc_textarea($data['customer_company_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Shipment Details -->
                    <div class="oim-card">
                        <h3 class="oim-card-title">Shipment Details</h3>
                        <div class="oim-grid-2">
                            <div>
                                <h4 class="oim-subtitle">Loading</h4>
                                <div class="oim-grid-2">
                                    <div class="oim-field">
                                        <label>Company Name</label>
                                        <input type="text" name="loading_company_name" value="<?php echo esc_attr($data['loading_company_name'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>Date</label>
                                        <input type="date" name="loading_date" value="<?php echo esc_attr($data['loading_date'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>Country</label>
                                        <input type="text" name="loading_country" value="<?php echo esc_attr($data['loading_country'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>ZIP</label>
                                        <input type="text" name="loading_zip" value="<?php echo esc_attr($data['loading_zip'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field oim-span-2">
                                        <label>City</label>
                                        <input type="text" name="loading_city" value="<?php echo esc_attr($data['loading_city'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h4 class="oim-subtitle">Unloading</h4>
                                <div class="oim-grid-2">
                                    <div class="oim-field">
                                        <label>Company Name</label>
                                        <input type="text" name="unloading_company_name" value="<?php echo esc_attr($data['unloading_company_name'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>Date</label>
                                        <input type="date" name="unloading_date" value="<?php echo esc_attr($data['unloading_date'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>Country</label>
                                        <input type="text" name="unloading_country" value="<?php echo esc_attr($data['unloading_country'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field">
                                        <label>ZIP</label>
                                        <input type="text" name="unloading_zip" value="<?php echo esc_attr($data['unloading_zip'] ?? ''); ?>">
                                    </div>
                                    <div class="oim-field oim-span-2">
                                        <label>City</label>
                                        <input type="text" name="unloading_city" value="<?php echo esc_attr($data['unloading_city'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Order Note -->
                    <div class="oim-card">
                        <h3 class="oim-card-title">Additional Information</h3>
                        <div class="oim-field">
                            <label>Order Note</label>
                            <textarea name="order_note" rows="3"><?php echo esc_textarea($data['order_note'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <!-- Documents Section -->
                    <!-- <div class="oim-card">
                        <h3 class="oim-card-title">Documents</h3>
                        <?php 
                        $docs = OIM_DB::get_documents($order['internal_order_id']); 
                        if ($docs): ?>
                            <ul class="oim-document-list">
                                <?php foreach ($docs as $d): ?>
                                    <li class="oim-document-item">
                                        <span class="dashicons dashicons-media-document"></span>
                                        <span class="oim-doc-name"><?php echo esc_html($d['filename']); ?></span>
                                        <div class="oim-doc-actions">
                                            <a href="<?php echo esc_url($d['file_url']); ?>" target="_blank" class="button button-small button-primary download_bt" title="Download">
    <span class="dashicons dashicons-download"></span>
</a>
                                            <button type="button" data-url="<?php echo admin_url('admin-post.php?action=oim_delete_doc&_wpnonce=' . wp_create_nonce('oim_delete_doc') . '&doc_id=' . $d['id'] . '&order_id=' . $order['id']); ?>" 
   class="button button-small button-link-delete oim-file-delete" title="Delete">
    <span class="dashicons dashicons-trash"></span>
</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="oim-empty-state">No documents uploaded yet.</p>
                        <?php endif; ?>
                    </div> -->

                    <!-- Attachments Section -->
                    <!-- <div class="oim-card">
                        <h3 class="oim-card-title">Attachments</h3>
                        <?php 
                        $attachments = maybe_unserialize($order['attachments']);
                        if (is_string($attachments)) {
                            $decoded = json_decode($attachments, true);
                            if (json_last_error() === JSON_ERROR_NONE) $attachments = $decoded;
                        }

                        if (!empty($attachments) && is_array($attachments)): ?>
                            <ul class="oim-document-list">
                                <?php foreach ($attachments as $url): ?>
                                    <li class="oim-document-item">
                                        <span class="dashicons dashicons-paperclip"></span>
                                        <span class="oim-doc-name"><?php echo esc_html(basename($url)); ?></span>
                                        <div class="oim-doc-actions">
                                           <a href="<?php echo esc_url($url); ?>" target="_blank" class="button button-small button-primary download_bt" title="Download">
    <span class="dashicons dashicons-download"></span>
</a>
                                            <button type="button" data-url="<?php echo admin_url('admin-post.php?action=oim_delete_attachment&_wpnonce=' . wp_create_nonce('oim_delete_attachment') . '&order_id=' . $order['id'] . '&file=' . urlencode($url)); ?>" 
   class="button button-small button-link-delete oim-file-delete" title="Delete">
    <span class="dashicons dashicons-trash"></span>
</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="oim-empty-state">No attachments found.</p>
                        <?php endif; ?>
                    </div> -->

                </div>

                <!-- Sidebar -->
                <div>
                    <div class="oim-card oim-sticky">
                        <h3 class="oim-card-title">Order Meta</h3>
                        
                        <div class="oim-field">
                            <label>Invoice Number</label>
                            <input type="text" name="invoice_number" value="<?php echo esc_attr($data['invoice_number'] ?? ''); ?>">
                        </div>
                        
                        <div class="oim-field">
                            <label>Due Days</label>
                            <input type="number" name="invoice_due_date_in_days" value="<?php echo esc_attr($data['invoice_due_date_in_days'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Invoice Due Date</label>
                            <input type="date" name="invoice_due_date" value="<?php echo esc_attr($data['invoice_due_date'] ?? ''); ?>">
                        </div>

                        <hr style="margin: 15px 0; border: none; border-top: 1px solid #e0e0e0;">

                        <div class="oim-field">
                            <label>Internal Order ID</label>
                            <input type="text" value="<?php echo esc_attr($order['internal_order_id']); ?>" readonly style="background: #f5f5f5;">
                        </div>

                        <div class="oim-field">
                            <label>Created At</label>
                            <input type="text" value="<?php echo esc_attr($order['created_at'] ?? 'N/A'); ?>" readonly style="background: #f5f5f5;">
                        </div>

                        <hr style="margin: 15px 0; border: none; border-top: 1px solid #e0e0e0;">

                        <!-- Action Buttons -->
                        <div class="oim-actions">
                            <button type="submit" class="button button-primary button-large">Save Changes</button>
                            <a href="<?php echo esc_url(home_url('/oim-dashboard/orders')); ?>" class="button button-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <style>
    /* Additional styles for document/attachment lists */
    .dashicons-spin {
    animation: dashicons-spin 1s infinite linear;
}

@keyframes dashicons-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
    .oim-doc-actions {
    display: flex;
    gap: 8px;
}

.oim-doc-actions .button {
    padding: 6px 12px !important;
    height: auto !important;
    line-height: 1 !important;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.oim-doc-actions .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.oim-doc-actions .download_bt {
    background: #fff !important;
    color: #fff !important;
}

.oim-doc-actions .download_bt:hover {
    background: #fff !important;
    color: #fff !important;
}

/* Delete Button - Red */
.oim-doc-actions .button-link-delete {
    background: #fff !important;
    border-color: #d63638 !important;
    color: #d63638 !important;
}

.oim-doc-actions .button-link-delete:hover {
    background: #d63638 !important;
    border-color: #d63638 !important;
    color: #fff !important;
}

.oim-doc-actions .button-link-delete .dashicons {
    color: inherit;
}
    .oim-document-list {
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .oim-document-item {
        display: flex;
        align-items: center;
        padding: 10px 12px;
        background: #f9f9f9;
        border-radius: 6px;
        margin-bottom: 8px;
        gap: 10px;
    }
    .oim-document-item .dashicons {
        color: #666;
    }
    .oim-doc-name {
        flex: 1;
        font-size: 13px;
    }
    .oim-doc-actions {
        display: flex;
        gap: 5px;
    }
    .oim-empty-state {
        color: #999;
        font-style: italic;
        margin: 0;
    }
    .oim-notice {
        padding: 12px 15px;
        border-radius: 6px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .oim-notice-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .oim-notice .dashicons {
        font-size: 18px;
    }
    </style>
    <?php
}

public static function handle_delete_attachment() {
    header('Content-Type: text/plain');
    
    if (!current_user_can('manage_options')) {
        echo 'unauthorized';
        die();
    }

    $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    $file_url = isset($_GET['file']) ? esc_url_raw($_GET['file']) : '';

    if (!$order_id || !$file_url) {
        echo 'missing';
        die();
    }

    check_admin_referer('oim_delete_attachment');  // Remove order_id suffix

    global $wpdb;
    $table = $wpdb->prefix . 'oim_orders';
    $attachments_json = $wpdb->get_var($wpdb->prepare("SELECT attachments FROM $table WHERE id = %d", $order_id));
    $attachments = json_decode($attachments_json, true) ?: [];

    $attachments = array_filter($attachments, function($url) use ($file_url) {
        return urldecode($url) !== urldecode($file_url);
    });
    $wpdb->update($table, ['attachments' => json_encode(array_values($attachments))], ['id' => $order_id]);
    die();
}







    public static function handle_save_order() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (empty($_POST['order_id'])) wp_die('Missing order id');
        $id = intval($_POST['order_id']);
        check_admin_referer('oim_save_order_' . $id);
        // gather allowed fields
        $allowedFields = [
            'customer_reference','vat_id','customer_email','customer_company_name',
            'customer_country','customer_price','invoice_number','invoice_due_date_in_days',
            'customer_company_email','customer_company_phone_number','customer_company_address',
            'loading_company_name','loading_date','loading_country','loading_zip','loading_city',
            'unloading_company_name','unloading_date','unloading_country','unloading_zip','unloading_city', 'order_note', 'truck_number' , 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
        ];
        $new = [];
        foreach ($allowedFields as $f) {
            $new[$f] = isset($_POST[$f]) ? sanitize_text_field($_POST[$f]) : '';
        }
        OIM_DB::update_order($id, $new);
        
        exit;
    }

    public static function handle_delete_order() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (empty($_GET['id'])) wp_die('Missing id');
        $id = intval($_GET['id']);
        check_admin_referer('oim_delete_order_' . $id);
        OIM_DB::delete_order($id);
        wp_safe_redirect(admin_url('admin.php?page=oim_orders'));
        exit;
    }

    public static function handle_delete_doc() {
    if (!current_user_can('manage_options')) {
        echo 'unauthorized';
        die();
    }
    
    if (empty($_GET['doc_id']) || empty($_GET['order_id'])) {
        echo 'missing';
        die();
    }
    
    $doc_id = intval($_GET['doc_id']);
    check_admin_referer('oim_delete_doc');
    
    OIM_DB::delete_document($doc_id);
    
    die();
}
}
