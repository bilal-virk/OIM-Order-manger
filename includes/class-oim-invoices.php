<?php
if (!defined('ABSPATH')) exit;
// class-oim-invoices.php
class OIM_Invoices {

    // Default email options (can be overridden from settings if you store them elsewhere)
    private $email_options = [
        'from_name'  => 'AIS Datys',
        'from_email' => 'noreply@aisdatys.online',
        'subject'    => 'Invoice for Order #{order_id}',
        'email_body' => "Hello,\n\nPlease find your invoice for Order #{order_id}.\n\nCompany: {company_name}\nOrder ID: {order_id}\n\nThank you for your business!\n\nBest regards,\nAIS Datys"
    ];

    public function __construct() {
        // Create tables on init
        
        add_action('admin_menu', [$this, 'register_invoices_submenu'], 20);
        add_action('wp_ajax_oim_get_order_logs', 'oim_get_order_logs');        
        add_action('wp_ajax_oim_import_payments', array($this, 'handle_payment_import'));
        add_action('wp_ajax_oim_get_invoice_send_logs', [$this, 'ajax_get_invoice_send_logs']);
        add_action('wp_ajax_nopriv_oim_get_invoice_send_logs', [$this, 'ajax_get_invoice_send_logs']); // optional if frontend

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_oim_send_invoice', [$this, 'send_invoice_email']);
        add_action('admin_post_oim_delete_invoice', [$this, 'delete_invoice']);
        add_action('admin_post_oim_download_invoice', [$this, 'download_invoice_txt']);
        add_action('admin_post_oim_bulk_action', [$this, 'handle_bulk_actions']);
        add_action('admin_post_oim_save_invoice', [$this, 'save_invoice']);
        add_action('admin_post_oim_export_all_txt', [$this, 'handle_export_all_txt']);
        add_action('admin_post_oim_import_payments', [$this, 'handle_payment_import']);        
        add_action('admin_post_oim_bulk_download_txt', [$this, 'bulk_download_invoices_txt']);
        $this->init_email_options();
        add_action('wp_mail_failed', [$this, 'log_email_error']);
        add_action('admin_post_oim_export_invoice', [$this, 'export_invoice_txt']);
        add_action('admin_post_oim_export_invoice_pdf', [$this, 'export_invoice_pdf']);
    }
    /***************************************************************************
     * Create send logs table
     ***************************************************************************/
    
    public static function enqueue_assets() {
    wp_enqueue_style('oim-frontend-css', OIM_PLUGIN_URL . 'assets/oim-frontend.css', [], OIM_VERSION);
    wp_enqueue_script('oim-frontend-js', OIM_PLUGIN_URL . 'assets/oim-frontend.js', ['jquery'], OIM_VERSION, true);
    wp_localize_script('oim-frontend-js', 'oim_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'security' => wp_create_nonce('oim_ajax_nonce')
    ]);
}

    public function export_invoice_txt() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;

    $invoice_id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$invoice_id) wp_die('Missing invoice ID');

    $nonce = $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'oim_download_invoice_' . $invoice_id) &&
        !wp_verify_nonce($nonce, 'oim_download_invoice')) {
        wp_die('Security check failed.');
    }

    // Load invoice from DB
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id = %d",
        $invoice_id
    ));
    if (!$invoice) wp_die('Invoice not found.');

    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) $invoice_data = json_decode($invoice->data, true) ?: [];

    // Update export date and flag
    $export_timestamp = current_time('Y-m-d H:i:s');
    $invoice_data['invoice_export_date'] = $export_timestamp;
    $invoice_data['invoice_export_flag'] = 'true';

    // Save updated data to DB
    $wpdb->update(
        "{$wpdb->prefix}oim_invoices",
        ['data' => maybe_serialize($invoice_data)],
        ['id' => $invoice_id],
        ['%s'],
        ['%d']
    );

    // Build invoice text
    if (class_exists('OIM_Invoice') && method_exists('OIM_Invoice', 'build_invoice_text')) {
        $text = OIM_Invoice::build_invoice_text($invoice_id, $invoice_data);

        $filename = 'invoice-' . sanitize_title($invoice_data['internal_order_id'] ?? $invoice_id) . '.txt';

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($text));
        echo $text;
        exit;
    }

    wp_die('Unable to generate invoice.');
}
public function handle_payment_import() {
    // Check if it's an AJAX request
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_die('Invalid request');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized access.'));
    }

    // Verify nonce
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'oim_import_payments_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    // Check if file was uploaded
    if (!isset($_FILES['payment_file']) || $_FILES['payment_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'No file uploaded or upload error.';
        if (isset($_FILES['payment_file']['error'])) {
            $upload_errors = array(
                UPLOAD_ERR_INI_SIZE => 'File exceeds maximum upload size.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.'
            );
            $error_message = isset($upload_errors[$_FILES['payment_file']['error']]) 
                ? $upload_errors[$_FILES['payment_file']['error']] 
                : $error_message;
        }
        wp_send_json_error(array('message' => $error_message));
    }

    $file = $_FILES['payment_file'];
    
    // Validate file type (only .txt files)
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'txt') {
        wp_send_json_error(array('message' => 'Only .txt files are allowed.'));
    }

    // Validate file size (optional, e.g., max 5MB)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        wp_send_json_error(array('message' => 'File size exceeds maximum limit of 5MB.'));
    }

    // Read file content
    $file_content = file_get_contents($file['tmp_name']);
    if ($file_content === false) {
        wp_send_json_error(array('message' => 'Failed to read file content.'));
    }

    // Parse the file and process payments
    $result = $this->process_payment_file($file_content);
    
    if ($result['success']) {
        $message = sprintf(
            'Payment import completed! %d invoice(s) updated successfully. %d invoice(s) not found.',
            $result['updated'],
            $result['not_found']
        );
        
        wp_send_json_success(array(
            'message' => $message,
            'updated' => $result['updated'],
            'not_found' => $result['not_found']
        ));
    } else {
        wp_send_json_error(array('message' => $result['error']));
    }
}

/**
 * Process payment file content
 * Parses the text file and updates invoices
 */
private function process_payment_file($file_content) {
    global $wpdb;
    $invoices_table = $wpdb->prefix . 'oim_invoices';
    
    $updated_count = 0;
    $not_found_count = 0;
    $processed_invoices = [];
    
    // Split file into lines
    $lines = explode("\n", $file_content);
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip empty lines and header lines (R00)
        if (empty($line) || strpos($line, 'R00') === 0) {
            continue;
        }
        
        // Only process R01 lines (payment records)
        if (strpos($line, 'R01') !== 0) {
            continue;
        }
        
        // Split line by tab character
        $parts = preg_split('/\t+/', $line);
        
        // Validate we have enough parts
        // Format: R01 [date] [amount1] [amount2] [currency] [invoice_num] ...
        if (count($parts) < 6) {
            continue;
        }
        
        // Extract invoice number (6th column, index 5) and amount (3rd column, index 2)
        $invoice_number = trim($parts[5]);
        $amount = floatval(str_replace(',', '.', trim($parts[2])));
        
        // Skip if invoice number or amount is empty/zero
        if (empty($invoice_number) || $amount <= 0) {
            continue;
        }
        
        // Find invoice by invoice number
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$invoices_table} WHERE invoice_number = %s",
            $invoice_number
        ));
        
        if (!$invoice) {
            $not_found_count++;
            continue;
        }
        
        // Parse invoice data
        $invoice_data = maybe_unserialize($invoice->data);
        if (!is_array($invoice_data)) {
            $invoice_data = json_decode($invoice->data, true) ?: [];
        }
        
        // Get current values
        $current_amount_paid = floatval($invoice_data['amount_paid'] ?? 0);
        $total_price = floatval($invoice_data['oim_total_price'] ?? 0);
        
        // Add new payment to existing amount paid
        $new_amount_paid = $current_amount_paid + $amount;
        $invoice_data['amount_paid'] = $new_amount_paid;
        
        // Calculate remaining balance
        $remaining_balance = $total_price - $new_amount_paid;
        $invoice_data['oim_total_price_to_be_paid'] = round($remaining_balance, 2);
        
        // Update invoice status based on payment
        if ($remaining_balance <= 0.01) {
            // Fully paid (allowing 1 cent tolerance for rounding)
            $invoice_data['invoice_status'] = 'paid';
        } elseif ($new_amount_paid > 0) {
            // Partially paid
            $invoice_data['invoice_status'] = 'partial';
        } else {
            // Not paid
            $invoice_data['invoice_status'] = 'unpaid';
        }
        
        // Add payment history entry
        if (!isset($invoice_data['payment_history'])) {
            $invoice_data['payment_history'] = [];
        }
        
        $invoice_data['payment_history'][] = [
            'date' => current_time('Y-m-d H:i:s'),
            'amount' => $amount,
            'method' => 'Bank Transfer (Import)',
            'imported_by' => wp_get_current_user()->display_name
        ];
        
        // Update database
        $update_result = $wpdb->update(
            $invoices_table,
            ['data' => maybe_serialize($invoice_data)],
            ['id' => $invoice->id],
            ['%s'],
            ['%d']
        );
        
        if ($update_result !== false) {
            $updated_count++;
            $processed_invoices[] = [
                'invoice_number' => $invoice_number,
                'amount' => $amount,
                'new_total_paid' => $new_amount_paid,
                'status' => $invoice_data['invoice_status']
            ];
        }
    }
    
    return [
        'success' => true,
        'updated' => $updated_count,
        'not_found' => $not_found_count,
        'details' => $processed_invoices
    ];
}

/**
 * Add this HTML section to render_invoices_page() after the "Export Invoices" section
 */
private static function render_payment_import_section() {
    ?>
    <!-- Section: Import Payments -->
    <div class="oim-section">
        <div class="oim-section-header">
            <h2>Import Payments</h2>
            <span class="oim-section-description">Upload a text file to automatically update invoice payment status</span>
        </div>
        <div class="oim-section-content">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" class="oim-import-form">
                <?php wp_nonce_field('oim_import_payments_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="oim_import_payments">
                
                <div class="oim-file-upload-wrapper">
                    <div class="oim-file-input-container">
                        <label for="payment_file" class="oim-file-label">
                            <span class="dashicons dashicons-upload"></span>
                            <span class="oim-file-label-text">Choose Payment File (.txt)</span>
                        </label>
                        <input type="file" 
                               id="payment_file" 
                               name="payment_file" 
                               accept=".txt" 
                               required 
                               class="oim-file-input">
                        <span class="oim-file-name" id="file-name-display">No file selected</span>
                    </div>
                    
                    <div class="oim-file-info">
                        <p><strong>File Format:</strong> Tab-delimited text file (.txt)</p>
                        <p><strong>Expected Columns:</strong></p>
                        <ul>
                            <li>Column 3: Payment Amount (e.g., 1488.3000)</li>
                            <li>Column 6: Invoice Number (e.g., 20250985)</li>
                        </ul>
                        <p class="oim-help-text">
                            <span class="dashicons dashicons-info"></span>
                            The system will automatically match invoice numbers and update payment amounts.
                        </p>
                    </div>
                </div>
                
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-database-import"></span>
                    Import Payments
                </button>
            </form>
        </div>
    </div>

    <style>
    .oim-file-upload-wrapper {
        display: flex;
        flex-direction: column;
        gap: 20px;
        margin-bottom: 15px;
    }

    .oim-file-input-container {
        display: flex;
        align-items: center;
        gap: 15px;
        flex-wrap: wrap;
    }

    .oim-file-label {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: #2271b1;
        color: #fff;
        border-radius: 4px;
        cursor: pointer;
        transition: background 0.2s;
        font-weight: 500;
    }

    .oim-file-label:hover {
        background: #135e96;
    }

    .oim-file-label .dashicons {
        font-size: 18px;
        width: 18px;
        height: 18px;
    }

    .oim-file-input {
        display: none;
    }

    .oim-file-name {
        color: #666;
        font-style: italic;
        font-size: 14px;
    }

    .oim-file-name.has-file {
        color: #2271b1;
        font-style: normal;
        font-weight: 500;
    }

    .oim-file-info {
        background: #f8f9fa;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 15px;
    }

    .oim-file-info p {
        margin: 8px 0;
        font-size: 14px;
    }

    .oim-file-info ul {
        margin: 8px 0 8px 20px;
        font-size: 14px;
    }

    .oim-file-info li {
        margin: 4px 0;
    }

    .oim-help-text {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #135e96;
        font-size: 13px;
        margin-top: 10px !important;
    }

    .oim-help-text .dashicons {
        font-size: 16px;
        width: 16px;
        height: 16px;
    }

    .button-primary .dashicons {
        margin-right: 5px;
        font-size: 16px;
        width: 16px;
        height: 16px;
        vertical-align: middle;
    }
    </style>

    <script>
    jQuery(document).ready(function($) {
        // File input change handler
        $('#payment_file').on('change', function() {
    var fileName = $(this).val().split('\\').pop();
    var $fileNameDisplay = $('#file-name-display');
    
    if (fileName) {
        $fileNameDisplay.text(fileName).addClass('has-file');
    } else {
        $fileNameDisplay.text('No file selected').removeClass('has-file');
    }
});

// Form validation and AJAX submission for payment import
$('#payment-import-form').on('submit', function(e) {
    e.preventDefault(); // Prevent default form submission
    
    var fileInput = $('#payment_file')[0];
    
    if (!fileInput.files || fileInput.files.length === 0) {
        showNotice('error', 'Please select a file to upload.');
        return false;
    }

    var fileName = fileInput.files[0].name;
    var fileExtension = fileName.split('.').pop().toLowerCase();
    
    if (fileExtension !== 'txt') {
        showNotice('error', 'Please select a .txt file.');
        return false;
    }

    // Show loading indicator
    var $button = $(this).find('button[type="submit"]');
    var originalText = $button.html();
    $button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    
    // Prepare form data
    var formData = new FormData(this);
    
    // Send AJAX request
    $.ajax({
        url: ajaxurl, // WordPress AJAX URL
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                showNotice('success', response.data.message);
                
                // Reset form
                $('#payment-import-form')[0].reset();
                $('#file-name-display').text('No file selected').removeClass('has-file');
                
                // Optionally reload invoice table if visible
                if (typeof loadInvoices === 'function') {
                    setTimeout(function() {
                        loadInvoices();
                    }, 1000);
                }
            } else {
                showNotice('error', response.data.message || 'An error occurred during import.');
            }
        },
        error: function(xhr, status, error) {
            showNotice('error', 'Failed to process payment import. Please try again.');
            console.error('AJAX Error:', error);
        },
        complete: function() {
            // Restore button state
            $button.prop('disabled', false).html(originalText);
        }
    });
});
    </script>

    <style>
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    </style>
    <?php
}






public function export_invoice_pdf() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $invoice_id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$invoice_id) wp_die('Missing invoice ID');

    $nonce = $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'oim_download_invoice_' . $invoice_id) &&
        !wp_verify_nonce($nonce, 'oim_download_invoice')) {
        wp_die('Security check failed.');
    }

    global $wpdb;

    // Fetch invoice from DB
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id = %d",
        $invoice_id
    ));

    if (!$invoice) wp_die('Invoice not found.');

    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) $invoice_data = json_decode($invoice->data, true) ?: [];

    $internal_order_id = $invoice_data['internal_order_id'] ?? null;
    if (!$internal_order_id) wp_die('Invoice does not have an internal order ID.');

    // PDF file path
    $upload_dir = wp_upload_dir();
    $pdf_file = $upload_dir['basedir'] . '/oim_invoices/invoice-' . $internal_order_id . '.pdf';

    if (!file_exists($pdf_file)) {
        // Return error page with back button
        wp_die(
            '<div style="font-family: sans-serif; padding: 40px; text-align: center;">
                <div style="background: #fef3c7; border: 1px solid #fcd34d; color: #92400e; padding: 20px; border-radius: 10px; max-width: 500px; margin: 0 auto;">
                    <p style="font-size: 18px; margin: 0 0 15px;">⚠️ You need to issue the invoice first to generate the PDF.</p>
                    <a href="' . esc_url(site_url('/oim-dashboard/invoices/')) . '" style="display: inline-block; background: #6366f1; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600;">← Back to Invoices</a>
                </div>
            </div>',
            'PDF Not Available',
            ['back_link' => true]
        );
    }

    // Force download PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($pdf_file) . '"');
    header('Content-Length: ' . filesize($pdf_file));
    readfile($pdf_file);
    exit;
}


    
    public function bulk_download_invoices_txt() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;

    $invoice_ids = $_POST['order_ids'] ?? [];
    if (!is_array($invoice_ids) || empty($invoice_ids)) {
        wp_die('No invoices selected.');
    }

    // Verify nonce
    $nonce = $_POST['_wpnonce'] ?? '';
    if (!wp_verify_nonce($nonce, 'oim_bulk_action_invoices')) {
        wp_die('Security check failed.');
    }

    $invoices_table = $wpdb->prefix . 'oim_invoices';
    $all_texts = [];

    foreach ($invoice_ids as $invoice_id) {
        $invoice_id = intval($invoice_id);
        if (!$invoice_id) continue;

        // Load invoice
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$invoices_table} WHERE id = %d",
            $invoice_id
        ));
        if (!$invoice) continue;

        $invoice_data = maybe_unserialize($invoice->data);
        if (!is_array($invoice_data)) $invoice_data = json_decode($invoice->data, true) ?: [];

        // Update export date and flag
        $export_timestamp = current_time('Y-m-d H:i:s');
        $invoice_data['invoice_export_date'] = $export_timestamp;
        $invoice_data['invoice_export_flag'] = 'true';

        // Save back to DB
        $wpdb->update(
            $invoices_table,
            ['data' => maybe_serialize($invoice_data)],
            ['id' => $invoice_id],
            ['%s'],
            ['%d']
        );

        // Generate invoice text
        if (class_exists('OIM_Invoice') && method_exists('OIM_Invoice', 'build_invoice_text')) {
            $text = OIM_Invoice::build_invoice_text($invoice_id, $invoice_data);
            $all_texts[] = $text;
        }
    }

    if (empty($all_texts)) wp_die('No valid invoices found for export.');

    // Combine all texts with separator
    $final_text = implode("\n\n====================\n\n", $all_texts);

    // Send TXT download
    $filename = 'invoices-' . date('Ymd-His') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($final_text));
    echo $final_text;
    exit;
}


    /***************************************************************************
     * Log invoice send action
     ***************************************************************************/
    public function log_invoice_send($invoice_id, $sent_to_email) {
        global $wpdb;
        $current_user = wp_get_current_user();

        $wpdb->insert(
            $wpdb->prefix . 'oim_invoice_send_logs',
            [
                'invoice_id' => $invoice_id,
                'sent_by_user_id' => $current_user->ID,
                'sent_by_username' => $current_user->user_login,
                'sent_by_display_name' => $current_user->display_name,
                'sent_to_email' => $sent_to_email,
                'sent_at' => current_time('mysql')
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s']
        );
    }

    /***************************************************************************
     * AJAX handler to get invoice send logs
     ***************************************************************************/
    public function ajax_get_invoice_send_logs() {
    $invoice_id = intval($_GET['invoice_id'] ?? 0);

    if (!$invoice_id) {
        wp_send_json_error(['message' => 'Invalid invoice ID']);
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'oim_invoice_send_logs';
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} WHERE invoice_id = %d ORDER BY sent_at DESC",
        $invoice_id
    ), ARRAY_A);

    if (!$logs) {
        wp_send_json_success([
            'logs' => [],
            'message' => 'No logs found for this invoice'
        ]);
        return;
    }

    wp_send_json_success(['logs' => $logs]);
}

    public function handle_save_invoice() {
        $this->process_invoice('save');
    }

    public function handle_send_invoice() {
        $this->process_invoice('send');
    }

    /***************************************************************************
     * Initialization & helpers
     ***************************************************************************/

    private function init_email_options() {
        // If you save email settings to an option 'oim_company_email_settings' (array), merge them
        $saved = get_option('oim_company_email_settings', []);
        if (!empty($saved) && is_array($saved)) {
            $this->email_options = wp_parse_args($saved, $this->email_options);
        }

        // fallback ensure from_email is set
        if (empty($this->email_options['from_email'])) {
            $this->email_options['from_email'] = get_option('admin_email');
        }
        if (empty($this->email_options['from_name'])) {
            $this->email_options['from_name'] = get_bloginfo('name');
        }
    }

    private function get_email_option($key, $replacements = []) {
        $value = $this->email_options[$key] ?? '';
        foreach ($replacements as $ph => $rep) {
            // allow both {placeholder} and #placeholder forms
            $value = str_replace('{' . $ph . '}', $rep, $value);
            $value = str_replace('#' . $ph, $rep, $value);
        }
        return $value;
    }

    public function log_email_error($wp_error) {
        if ($wp_error instanceof WP_Error) {
            error_log('OIM Invoice Email Error: ' . $wp_error->get_error_message());
        } else {
            error_log('OIM Invoice Email Error: ' . print_r($wp_error, true));
        }
    }

    /***************************************************************************
     * Admin menu registration (includes hidden edit page)
     ***************************************************************************/
    public function register_invoices_submenu() {
    static $done = false;
    if ($done) return; // prevent duplicate menus
    $done = true;

    $parent_slug = 'oim_orders';

    if (isset($GLOBALS['submenu'][$parent_slug])) {
        // If "Orders" menu exists, add under it
        add_submenu_page(
            $parent_slug,
            'Invoices Management',
            'Invoices',
            'manage_options',
            'oim_invoices',
            [$this, 'render_invoices_page']
        );
    } else {
        // Otherwise create top-level menu
        add_menu_page(
            'Invoices Management',
            'Invoices',
            'manage_options',
            'oim_invoices',
            [$this, 'render_invoices_page'],
            'dashicons-media-text',
            58
        );
    }

    // Hidden edit page (accessed via URL)
    add_submenu_page(
        null,
        'Edit Invoice',
        'Edit Invoice',
        'manage_options',
        'oim_edit_invoice',
        [$this, 'render_edit_invoice_page']
    );

    // ✅ Hidden "Send Log" page (accessed via button)
    add_submenu_page(
        null,
        'Send Log',
        'Send Log',
        'manage_options',
        'oim_send_logs',
        [$this, 'render_send_log_page']
    );
}


public static function schedule_invoice_reminders() {
    if (!wp_next_scheduled('oim_invoice_reminder_cron')) {
        wp_schedule_event(time(), 'quarterhour', 'oim_invoice_reminder_cron');
    }
}

/**
 * Hook cron to our function
 */
public static function init_cron_hook() {
    add_action('oim_invoice_reminder_cron', [__CLASS__, 'process_invoice_reminders']);
}

/**
 * Process invoice reminders
 */
public static function process_invoice_reminders() {
    global $wpdb;
    $invoices_table = $wpdb->prefix . 'oim_invoices';
    
    // Get all exported invoices
    $invoices = $wpdb->get_results("SELECT * FROM {$invoices_table}", ARRAY_A);

    foreach ($invoices as $invoice) {
        $data = maybe_unserialize($invoice['data']);
        if (!is_array($data)) {
            $data = json_decode($invoice['data'], true) ?: [];
        }

        // Skip if no due date
        if (empty($data['invoice_due_date'])) continue;

        $due_date = strtotime($data['invoice_due_date']);
        $today = strtotime(current_time('Y-m-d'));

        $days_past_due = ($today - $due_date) / 86400;

        $reminder_type = false;
        $subject = '';
        $message = '';

        // Determine reminder type and email content
        if ($days_past_due === 0) {
            $reminder_type = 'Reminder 0';
            $subject = "Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']} - Due Today";
            $message = "Hello,\n\n";
            $message .= "We would like to kindly remind you that today is the due date of invoice no. {$data['invoice_number']}, issued for your order no. {$data['internal_order_id']}.\n\n";
            $message .= "Thank you for taking care of this payment and for settling the invoice on time. If the payment has already been made, please disregard this message.\n\n";
            $message .= "Best regards,\nThank you for your cooperation.";
        }
        elseif ($days_past_due === 3) {
            $reminder_type = 'First Reminder';
            $subject = "1st Reminder – Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']}";
            $message = "Hello,\n\n";
            $message .= "We would like to inform you that we have not yet received payment for invoice no. {$data['invoice_number']}, issued for your order no. {$data['internal_order_id']}, which was due on {$data['invoice_due_date']}.\n\n";
            $message .= "Please arrange the payment as soon as possible. If the payment has already been made, kindly disregard this message.\n\n";
            $message .= "Thank you for your cooperation and understanding.\nBest regards,\nWe appreciate your timely settlement of obligations.";
        }
        elseif ($days_past_due === 6) {
            $reminder_type = 'Next Reminder';
            $subject = "Urgent Payment Notice – Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']}";
            $message = "Hello,\n\n";
            $message .= "Despite our previous reminder, we have not yet received payment for invoice no. {$data['invoice_number']}, related to order no. {$data['internal_order_id']}, which is now significantly overdue.\n\n";
            $message .= "We kindly request that you immediately attend to this matter and settle the outstanding amount without further delay. If the payment has already been made, please send us a confirmation so we can update our records.\n\n";
            $message .= "If payment is not received promptly, we will be forced to proceed with debt recovery, including statutory interest and a fixed compensation fee of €40.\n\nThank you for your immediate attention to this matter.\nBest regards,\nBilling and Accounts Department";
        }
        elseif ($days_past_due === 15) {
            $reminder_type = 'Second Reminder';
            $subject = "Urgent Payment Notice – Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']}";
            $message = "Hello,\n\n";
            $message .= "Invoice no. {$data['invoice_number']}, issued for order no. {$data['internal_order_id']}, is significantly overdue, and we have not yet received the payment.\n\n";
            $message .= "We kindly request that you settle the outstanding amount immediately or contact us without delay regarding the status of the payment. If the payment has already been made, please send us a confirmation.\n\n";
            $message .= "Should the payment not be received shortly, we will be obliged to proceed with further legal recovery actions, including statutory interest and a fixed compensation fee of €40.\n\nThank you for giving this matter your immediate attention.";
        }
        elseif ($days_past_due === 20) {
            $reminder_type = 'Third Reminder';
            $subject = "Urgent Payment Notice – Immediate Action Required – Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']}";
            $message = "Hello,\n\n";
            $message .= "Invoice no. {$data['invoice_number']}, issued for order no. {$data['internal_order_id']}, is significantly overdue, and we have not yet received the payment.\n\n";
            $message .= "We kindly request that you settle the outstanding amount immediately or contact us without delay regarding the status of the payment. If the payment has already been made, please send us a confirmation.\n\n";
            $message .= "Should the payment not be received shortly, we will be obliged to proceed with further legal recovery actions, including statutory interest and a fixed compensation fee of €40.\n\nThank you for giving this matter your immediate attention.";
        }
        elseif ($days_past_due > 20 && ($days_past_due - 20) % 5 === 0) {
            $reminder_type = 'Fourth Reminder';
            $subject = "Payment Reminder – Invoice No. {$data['invoice_number']} / Order No. {$data['internal_order_id']}";
            $message = "Dear Business Partner (REMINDER),\n\n";
            $message .= "During the review of our accounting records, we have identified that invoice no. {$data['invoice_number']}, issued for order no. {$data['internal_order_id']}, has not yet been paid as of today. The list of outstanding invoices with full details can be found in the attachment.\n\n";
            $message .= "Please note that delayed payment is contrary to applicable legislation and may result in additional financial consequences.\n\n";
            $message .= "If the payment has been made within the last seven days, please disregard this reminder.\n\n";
            $message .= "Under EU Regulation No. 1896/2006 of the European Parliament and of the Council of 12 December 2006, establishing a European order for payment procedure, you are required to pay a fixed penalty of €40 for each overdue invoice. Additionally, we will claim statutory interest for late payment as permitted by law.\n\n";
            $message .= "If the outstanding amount is not settled immediately, we will be forced to initiate debt recovery proceedings, including legal action. Furthermore, please note that in the case of continued non-payment and lack of communication, we will be compelled to publish a negative review of your company on Google and other platforms, which may impact your reputation.\n\n";
            $message .= "We kindly ask you to arrange prompt payment to avoid further complications. Should you have any questions or require additional information, please do not hesitate to contact us.\n\nBest regards,\nBilling and Accounts Department";
        }

        // Send email if reminder type is matched
        if ($reminder_type && !empty($subject) && !empty($message)) {
            $to = $data['customer_email'] ?? get_option('oim_company_email', '');
            if ($to) wp_mail($to, $subject, $message);

            // Update invoice export flag and date
            $data['invoice_export_flag'] = 'true';
            $data['invoice_export_date'] = current_time('Y-m-d H:i:s');

            $wpdb->update(
                $invoices_table,
                ['data' => maybe_serialize($data)],
                ['id' => $invoice['id']],
                ['%s'],
                ['%d']
            );
        }
    }
}


/**
 * Add custom interval for 15 minutes
 */
public static function add_quarterhour_cron($schedules) {
    $schedules['quarterhour'] = [
        'interval' => 900, // 15 minutes
        'display' => __('Every 15 Minutes')
    ];
    return $schedules;
}

    /***************************************************************************
     * List page
     ***************************************************************************/
    public static function render_invoices_page() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;

    // Handle bulk actions
    if (isset($_POST['bulk_action']) && isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'oim_bulk_action_invoices')) {
        wp_die('Security check failed');
    }

    $order_ids = array_map('intval', $_POST['order_ids']);
    $order_ids = array_filter($order_ids);

    if (!empty($order_ids)) {
        global $wpdb;
        $invoices_table = $wpdb->prefix . 'oim_invoices';

        if ($_POST['bulk_action'] === 'delete') {
            $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
            $sql = $wpdb->prepare("DELETE FROM {$invoices_table} WHERE id IN ($placeholders)", $order_ids);
            $result = $wpdb->query($sql);

            if ($result !== false) {
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(_n('%d invoice deleted.', '%d invoices deleted.', count($order_ids), 'oim'), count($order_ids)) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>Error deleting invoices.</p></div>';
            }
        }

        elseif ($_POST['bulk_action'] === 'download_txt') {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Force download headers
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="selected_invoices.txt"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');

    // Clear any output buffering to prevent HTML wrapping
    while (ob_get_level()) ob_end_clean();

    foreach ($order_ids as $invoice_id) {
        $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$invoices_table} WHERE id=%d", $invoice_id));
        if (!$invoice) continue;

        $invoice_data = maybe_unserialize($invoice->data);
        if (!is_array($invoice_data)) {
            $invoice_data = json_decode($invoice->data, true) ?: [];
        }

        // Ensure required fields
        $invoice_number    = $invoice_data['invoice_number'] ?? ($invoice->invoice_number ?? 'N/A');
        $internal_order_id = $invoice_data['internal_order_id'] ?? ($invoice->internal_order_id ?? 'N/A');
        $current_user_obj = wp_get_current_user();
        $current_user = $current_user_obj->display_name ?: 'AUTOMAT';
        $loading_city        = $invoice_data['loading_city'] ?? '—';
        $vat_id =       $invoice_data['vat_id'] ?? '—';
        $loading_country     = $invoice_data['loading_country'] ?? '—';
        $unloading_city      = $invoice_data['unloading_city'] ?? '—';
        $unloading_country   = $invoice_data['unloading_country'] ?? '—';
        $truck_number        = $invoice_data['truck_number'] ?? '—';
        $loading_date        = $invoice_data['loading_date'] ?? '—';
        $unloading_date      = $invoice_data['unloading_date'] ?? '—';
        $customer_order_nr   = $invoice_data['customer_order_number'] ?? '—';
        $internal_order_id   = $invoice_data['internal_order_id'] ?? '—';
        $invoice_export_date = $invoice_data['invoice_export_date'];
        $invoice_export_flag = $invoice_data['invoice_export_flag'];
        $vat_id_upper = strtoupper(trim($vat_id));
        $is_slovak_vat = (strpos($vat_id_upper, 'SK') === 0);

        // ✅ BUILD REVERSE CHARGE TEXT IF NOT SLOVAK VAT
        $reverse_charge_text = '';
        if (!$is_slovak_vat && $vat_id !== '—' && !empty($vat_id)) {
            $reverse_charge_text = "\nWithout VAT according to §15 of the VAT Act - reverse charge.";
        }


        // Build the multi-line text safely
        $invoice_text = <<<EOT
        We invoicing you for cargo transport {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
        with truck {$truck_number} date {$loading_date} - {$unloading_date} your order Nr. {$customer_order_nr}.
        → Fakturujeme Vám prepravu tovaru {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
        vozidlom {$truck_number} v dňoch {$loading_date} - {$unloading_date}, Vaša objednávka č. {$customer_order_nr}.{$reverse_charge_text}
        
        Our reference / Naša referencia: {$internal_order_id}
        Issued by / Vystavila: {$current_user}
        Telephone / Telefón: 00421 915 794 911
        E-mail: datys@datys.sk
        Web: www.datys.sk
        EOT;
        // Output plain TXT
        echo "==============================\n";
        echo $invoice_text . "\n";
        echo "==============================\n\n";

        // Update export flag/date
        $export_timestamp = current_time('Y-m-d H:i:s');
        $invoice_data['invoice_export_flag'] = 'true';
        $invoice_data['invoice_export_date'] = $export_timestamp;

        $wpdb->update(
            $invoices_table,
            ['data' => maybe_serialize($invoice_data)],
            ['id' => $invoice_id],
            ['%s'],
            ['%d']
        );
    }

    exit; // Stop further output
}



        elseif ($_POST['bulk_action'] === 'send_pdf') {
            foreach ($order_ids as $invoice_id) {
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$invoices_table} WHERE id=%d", $invoice_id));
                if (!$invoice) continue;

                $invoice_data = maybe_unserialize($invoice->data);
                if (!is_array($invoice_data)) {
                    $invoice_data = json_decode($invoice->data, true) ?: [];
                }

                // Build PDF and send email (implement your PDF generation here)
                // Example:
                $to = $invoice_data['customer_email'] ?? get_option('oim_company_email', '');
                $subject = "Invoice #{$invoice_data['invoice_number']} / Order #{$invoice_data['internal_order_id']}";
                $message = "Dear Customer,\n\nPlease find your invoice attached.\n\nBest regards.";
                
                // TODO: generate PDF and attach
                // wp_mail($to, $subject, $message, [], [$pdf_file_path]);
            }

            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(_n('%d PDF(s) sent.', '%d PDFs sent.', count($order_ids), 'oim'), count($order_ids)) . '</p></div>';
        }
    }
}


    // Handle view/edit
    if (isset($_GET['view_invoice'])) {
        self::render_view_invoice(intval($_GET['view_invoice']));
        return;
    }
    if (isset($_GET['edit_invoice'])) {
        self::render_edit_invoice(intval($_GET['edit_invoice']));
        return;
    }

    // --- Filters & Sorting ---
    $search = sanitize_text_field($_GET['s'] ?? '');
    $status_filter = sanitize_text_field($_GET['status'] ?? '');
    $date_from = sanitize_text_field($_GET['date_from'] ?? '');
    $date_to = sanitize_text_field($_GET['date_to'] ?? '');
    $order_by = sanitize_text_field($_GET['orderby'] ?? 'created_at');
    
    $order_dir = strtoupper(sanitize_text_field($_GET['order'] ?? 'DESC'));
    if (!in_array($order_dir, ['ASC', 'DESC'])) $order_dir = 'DESC';

    $allowed_sort_columns = ['id', 'invoice_number', 'status', 'created_at'];
    if (!in_array($order_by, $allowed_sort_columns)) $order_by = 'created_at';

    // --- Base Query ---
    $invoices_table = $wpdb->prefix . 'oim_invoices';
    $sql = "SELECT * FROM {$invoices_table} WHERE 1=1";
    
    
    // (Optional) Display or use it elsewhere
    // echo nl2br($invoice_text);
    

    // --- Apply Filters ---
    
    if ($search) {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $sql .= $wpdb->prepare(" AND (invoice_number LIKE %s OR data LIKE %s)", $like, $like);
    }
    if ($status_filter) {
        $sql .= $wpdb->prepare(" AND status = %s", $status_filter);
    }
    if ($date_from) {
        $sql .= $wpdb->prepare(" AND created_at >= %s", $date_from);
    }
    if ($date_to) {
        $sql .= $wpdb->prepare(" AND created_at <= %s", $date_to);
    }

    // --- Sorting ---
    $sql .= " ORDER BY {$order_by} {$order_dir}";

    // --- Get Invoices ---
    $invoices = $wpdb->get_results($sql, ARRAY_A);

    // helper to safely parse 'data' field
    $parse_invoice_data = function($raw) {
        if (empty($raw)) return [];
        $un = maybe_unserialize($raw);
        if (is_array($un)) return $un;
        $json = json_decode($raw, true);
        if (is_array($json)) return $json;
        return [];
    };

    // Helper function for sorting links
    $sort_link = function($column) use ($order_by, $order_dir) {
        $new_order = ($order_by === $column && $order_dir === 'ASC') ? 'DESC' : 'ASC';
        $params = $_GET;
        $params['page'] = 'oim_invoices';
        $params['orderby'] = $column;
        $params['order'] = $new_order;
        return admin_url('admin.php?' . http_build_query($params));
    };
    ?>

    <div class="wrap oim-orders-wrap">
        
        <div class="oim-page-header">
            <div class="oim-page-title-section">
                <h1 class="oim-page-title">
                    <i class="fas fa-shopping-cart"></i>
                    Invoice Management
                </h1>
                <p class="oim-page-subtitle">Manage and track all your invoices in one place</p>
            </div>
        </div>


        <!-- Notices -->
        <?php
        if (!empty($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Invoice saved successfully!</p></div>';
        }
        
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html($_GET['error']) . '</p></div>';
        }
        if (!empty($_GET['payment_import_success'])) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Payment Import Complete:</strong> ' . esc_html($_GET['payment_import_success']) . '</p></div>';
            }

            // Error messages
        if (!empty($_GET['error'])) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html($_GET['error']) . '</p></div>';
            }
        ?>

        <!-- Section 1: Export All -->
<div class="oim-action-buttons-row">
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block;">
                <?php wp_nonce_field('oim_export_all_txt_nonce', '_wpnonce'); ?>
                <input type="hidden" name="action" value="oim_export_all_txt">
                <button type="submit" class="oim-btn oim-btn-secondary oim-btn-icon">
                    <i class="fas fa-file-export"></i>
                    Export All
                </button>
            </form>

            <button type="button" class="oim-btn oim-btn-secondary oim-btn-icon" id="toggle-import-btn">
                <i class="fas fa-file-import"></i>
                Import Payments
            </button>

            <button type="button" class="oim-btn oim-btn-secondary oim-btn-icon" id="toggle-filter-btn">
                <i class="fas fa-filter"></i>
                Filters
            </button>
        </div>

        <!-- Hidden Import Section (toggles on button click) -->
        <div class="oim-card oim-toggle-section" id="import-payments-section" style="display: none;">
            <div class="oim-card-header">
                <div class="oim-card-title-group">
                    <div class="oim-card-icon">
                        <i class="fas fa-file-import"></i>
                    </div>
                    <div class="oim-card-title-wrapper">
                        <h2 class="oim-card-title">Import Payments</h2>
                    </div>
                </div>
            </div>
            <div class="oim-card-content">
                <form method="post" enctype="multipart/form-data" id="payment-import-form">
                    <?php wp_nonce_field('oim_import_payments_nonce', '_wpnonce'); ?>
                    <input type="hidden" name="action" value="oim_import_payments">
                    
                    <div class="oim-form-row">
                        <input type="file" name="payment_file" id="payment_file" accept=".txt" required class="oim-input">
                        <button type="submit" class="oim-btn oim-btn-primary">
                            <i class="fas fa-upload"></i>
                            Upload
                        </button>
                    </div>
                    
                    <p class="oim-form-help">Expected: Column 3 = Payment Amount, Column 6 = Invoice Number</p>
                </form>
            </div>
        </div>

        <!-- Hidden Filter Section (toggles on button click) -->
        <div class="oim-card oim-toggle-section" id="filter-invoices-section" style="display: none;">
            <div class="oim-card-header">
                <div class="oim-card-title-group">
                    <div class="oim-card-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="oim-card-title-wrapper">
                        <h2 class="oim-card-title">Filter Invoices</h2>
                    </div>
                </div>
            </div>
            <div class="oim-card-content">
                <form method="get" action="<?php echo esc_url( site_url('/oim-dashboard/invoices') ); ?>">
                    <div class="oim-filter-grid">
                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-search"></i>
                                Search
                            </label>
                            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search invoices..." class="oim-input">
                        </div>
                        
                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-tag"></i>
                                Status
                            </label>
                            <select name="status" class="oim-select">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                                <option value="sent" <?php selected($status_filter, 'sent'); ?>>Sent</option>
                                <option value="paid" <?php selected($status_filter, 'paid'); ?>>Paid</option>
                            </select>
                        </div>

                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-calendar-alt"></i>
                                Date From
                            </label>
                            <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" class="oim-input">
                        </div>

                        <div class="oim-filter-item">
                            <label class="oim-filter-label">
                                <i class="fas fa-calendar-check"></i>
                                Date To
                            </label>
                            <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" class="oim-input">
                        </div>
                    </div>

                    <div class="oim-filter-actions">
                        <button type="submit" class="oim-btn oim-btn-primary">
                            <i class="fas fa-check"></i>
                            Apply
                        </button>
                        <a href="<?php echo esc_url( site_url('/oim-dashboard/invoices') ); ?>" class="oim-btn oim-btn-secondary">
                            <i class="fas fa-redo"></i>
                            Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>


        <!-- Section 3: Invoice Management -->
        <div class="oim-card">
            
            <div class="oim-section-content">
                <form method="post" action="" id="bulk-action-form">
                    <?php wp_nonce_field('oim_bulk_action_invoices'); ?>
                    
                    <div class="oim-table-toolbar">
                        <div class="oim-bulk-actions-wrapper">
                            <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
                            <select name="bulk_action" id="bulk-action-selector-top" class="oim-select">
                                <option value="">Bulk Actions</option>
                                <option value="send_pdf">Send PDF</option>
                                
                                <option value="download_txt">Download TXT</option>
                                <option value="delete">Delete Selected</option>
                            </select>
                            <input type="submit" id="doaction" class="oim-btn oim-btn-secondary" value="Apply">
                        </div>
                        <div class="oim-orders-count-badge">
                            <i class="fas fa-list"></i>
                            <?php echo count($invoices); ?> invoice(s) found
                        </div>
                    </div>

                    <!-- Active Filters Display -->
                    <div id="oim-active-filters-container" class="oim-active-filters" style="display: none;">
                        <!-- Filter tags will be inserted here -->
                    </div>

                    <!-- Invoices Table with Scroll -->
                    <div class="oim-table-wrapper">
    <table class="wp-list-table widefat fixed striped table-view-list oim-orders-table">
        <thead>
            <tr>
    <th class="manage-column column-cb check-column">
        <input type="checkbox" id="cb-select-all-1">
    </th>
    <th>Invoice Number</th>
    <th>Internal Order Number</th>
    <th>Issued Date</th>
    <th>Sent Date</th>
    <th>Taxable Supply Date</th>
    <th>Due Date</th>
    <th>Due in Days</th>
    <th>Customer Company Name</th>
    <th>Customer Company ID (IČO - CRN)</th>
    <th>Customer Tax ID (DIČ)</th>
    <th>Customer VAT Number</th>
    <th>Invoice Status</th>
    <th>Invoice Total Amount (with VAT)</th>
    <th>Invoice Currency</th>
    <th>Amount Paid So Far</th>
    <th>VAT Amount</th>
    <th>Invoice Total Amount (without VAT)</th>
    <th>Invoice Text / Description</th>
    <th>Place of Delivery</th>
    <th>Invoice Creation Date</th>
    <th>Export Date</th>
    <th>Export Flag</th>
</tr>

        </thead>
        <tbody>
            <?php if (!empty($invoices)): ?>
                <?php foreach ($invoices as $invoice):
                    $data = $parse_invoice_data($invoice['data']);
                    $current_user_obj = wp_get_current_user();
                    $current_user = $current_user_obj->display_name ?: 'AUTOMAT';
                    $loading_city        = $data['loading_city'] ?? '—';
                    $vat_id =       $data['vat_id'] ?? '—';
                    $loading_country     = $data['loading_country'] ?? '—';
                    $unloading_city      = $data['unloading_city'] ?? '—';
                    $unloading_country   = $data['unloading_country'] ?? '—';
                    $truck_number        = $data['truck_number'] ?? '—';
                    $loading_date        = $data['loading_date'] ?? '—';
                    $unloading_date      = $data['unloading_date'] ?? '—';
                    $customer_order_nr   = $data['customer_order_number'] ?? '—';
                    $internal_order_id   = $data['internal_order_id'] ?? '—';
                    $vat_id_upper = strtoupper(trim($vat_id));
                    $is_slovak_vat = (strpos($vat_id_upper, 'SK') === 0);

                    // ✅ BUILD REVERSE CHARGE TEXT IF NOT SLOVAK VAT
                    $reverse_charge_text = '';
                    if (!$is_slovak_vat && $vat_id !== '—' && !empty($vat_id)) {
                        $reverse_charge_text = "\nWithout VAT according to §15 of the VAT Act - reverse charge.";
                    }


                    // Build the multi-line text safely
                    $invoice_text = <<<EOT
                    We invoicing you for cargo transport {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
                    with truck {$truck_number} date {$loading_date} - {$unloading_date} your order Nr. {$customer_order_nr}.
                    → Fakturujeme Vám prepravu tovaru {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
                    vozidlom {$truck_number} v dňoch {$loading_date} - {$unloading_date}, Vaša objednávka č. {$customer_order_nr}.{$reverse_charge_text}
                    
                    Our reference / Naša referencia: {$internal_order_id}
                    Issued by / Vystavila: {$current_user}
                    Telephone / Telefón: 00421 915 794 911
                    E-mail: datys@datys.sk
                    Web: www.datys.sk
                    EOT;

                    // Fallbacks and derived values
                    $order_id = $data['internal_order_id'] ?? $invoice['internal_order_id'] ?? $invoice['id'];
                    $customer = $data['customer_company_name'] ?? $data['customer_name'] ?? 'N/A';

                    $unloading_date = isset($data['unloading_date']) ? strtotime($data['unloading_date']) : null;
                    $due_days = intval($data['invoice_due_date_in_days'] ?? 0);

                    // Derived calculations
                    $taxable_supply_date = $unloading_date
                        ? date('Y-m-d', $unloading_date + ($due_days * 86400))
                        : '—';

                    $due_date = '';

                // Check if invoice issue date exists and is not empty
                if (!empty($data['invoice_issue_date'])) {
                    // Convert to Y-m-d and add due days
                    $due_date = date(
                        'Y-m-d',
                        strtotime($data['invoice_issue_date'] . " +{$due_days} days")
                    );
                }

                ?>
                    <tr class="oim-order-row"
                        data-order-id="<?php echo esc_attr($invoice['id']); ?>"
                        data-invoice-number="<?php echo esc_attr($invoice['invoice_number'] ?? ''); ?>"
                        data-order-data="<?php echo esc_attr(json_encode($data)); ?>"
                        data-created-at="<?php echo esc_attr($invoice['created_at']); ?>"
                        data-status="<?php echo esc_attr($invoice['status'] ?? ''); ?>">

                        <th scope="row" class="check-column">
                            <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr($invoice['id']); ?>" onclick="event.stopPropagation();">
                        </th>

                        <td><?php echo esc_html($data['invoice_number'] ?? '—'); ?></td>
                        <td><?php echo esc_html($order_id); ?></td>
                        <td><?php echo esc_html($data['invoice_issue_date'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['invoice_sent_date'] ?? '—'); ?></td>
                        <td><?php echo esc_html($taxable_supply_date); ?></td>
                        <td><?php echo esc_html($due_date); ?></td>
                        <td><?php echo esc_html($data['invoice_due_date_in_days'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['customer_company_name'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['customer_company_ID_crn'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['customer_tax_ID'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['vat_id'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['invoice_status'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['oim_amount'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['oim_invoice_currency'] ?? 'EUR'); ?></td>
                        <td><?php echo esc_html($data['amount_paid'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['oim_vat'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['oim_without_vat_reverse_charge'] ?? '—'); ?></td>
                        
                        <td style="max-width:100px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
    <?php echo esc_html($invoice_text); ?>
</td>
                        <td><?php echo esc_html($data['customer_country'] ?? '—'); ?></td>
                        <td><?php echo esc_html($invoice['created_at'] ?? '—'); ?></td>
                        
                        
                        
                        <!-- <td><?php echo esc_html($data['oim_total_price'] ?? '—'); ?></td> -->
                        
                        
                        
                        
                        <!-- <td><?php echo esc_html($data['customer_reference'] ?? '—'); ?></td> -->
                        
                        
                        <!-- <td><?php echo esc_html($data['oim_invoice_sending_date'] ?? '—'); ?></td> -->
                        <td><?php echo esc_html($data['invoice_export_date'] ?? '—'); ?></td>
                        <td><?php echo esc_html($data['invoice_export_flag'] ?? '—'); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="25" class="no-orders">
                        <div class="oim-no-orders">
                            <span class="dashicons dashicons-media-document"></span>
                            <p>No invoices found matching your criteria.</p>
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

        <!-- Invoice Preview Sidebar -->
        <div id="oim-order-sidebar" class="oim-sidebar">
            <div class="oim-sidebar-header">
                <h3>Invoice Details</h3>
                <button type="button" class="oim-sidebar-close" aria-label="Close">&times;</button>
            </div>
            <div class="oim-sidebar-actions">
                <a href="#" class="oim-sidebar-action-btn edit" id="oim-action-edit">Edit</a>
                <a href="#" class="oim-sidebar-action-btn send" id="oim-action-send">Send PDF</a>
                <a href="#" class="oim-sidebar-action-btn download" id="oim-action-download">Download TXT</a>
                <a href="#" class="oim-sidebar-action-btn download" id="oim-action-download-pdf">Download PDF</a>
                <a href="#" class="oim-sidebar-action-btn delete" id="oim-action-delete">Delete</a>
                 <!-- <a href="<?php echo admin_url('admin.php?page=oim_send_logs&invoice_id=' . $invoice['id']); ?>" class="oim-sidebar-action-btn log">View Send Log</a> -->
            </div>
            <div class="oim-sidebar-content">
                <div class="oim-sidebar-loading">Loading...</div>
            </div>
            <div class="oim-sidebar-footer">
                <button type="button" class="button oim-sidebar-prev" disabled>← Previous</button>
                <button type="button" class="button oim-sidebar-next" disabled>Next →</button>
            </div>
        </div>
        <div id="oim-sidebar-overlay" class="oim-sidebar-overlay"></div>

        <script>
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
    let columnFilters = {};

    // Nonces for actions
    const nonces = {
        send: '<?php echo wp_create_nonce("oim_send_invoice"); ?>',
        download: '<?php echo wp_create_nonce("oim_download_invoice"); ?>',
        delete: '<?php echo wp_create_nonce("oim_delete_invoice"); ?>'
    };

    // Build invoices array
    $('.oim-order-row').each(function(index) {
        let $row = $(this);
        let rowData = $row.attr('data-order-data');

        try {
            rowData = (typeof $row.data('order-data') === 'object') ? $row.data('order-data') : JSON.parse(rowData);
        } catch (err) {
            rowData = $row.data('order-data') || {};
        }

        allOrders.push({
            element: $row,
            id: $row.data('order-id'),
            invoiceNumber: $row.data('invoice-number'),
            status: $row.data('status'),
            data: rowData,
            createdAt: $row.data('created-at')
        });
    });

    // Select all checkboxes
    $('#cb-select-all-1').on('change', function() {
        $('input[name="order_ids[]"]').prop('checked', this.checked);
    });

    // Bulk action confirmation
    $('#bulk-action-form').on('submit', function(e) {
        const action = $('select[name="bulk_action"]').val();
        if (action === 'delete') {
            var checked = $('input[name="order_ids[]"]:checked').length;
            if (checked === 0) {
                alert('Please select at least one invoice to delete.');
                e.preventDefault();
                return false;
            }
            if (!confirm('Are you sure you want to delete ' + checked + ' invoice(s)? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        }
    });

    // Click row -> open sidebar
    $('.oim-order-row').on('click', function(e) {
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
    function loadInvoiceLogs(invoiceId) {
    const $table = $('.oim-logs-table');
    const $tbody = $table.find('tbody');

    // Show loading state
    $tbody.html('<tr><td colspan="3" style="text-align:center;">Loading logs...</td></tr>');
    $table.show();

    $.ajax({
    url: oim_ajax.ajax_url, // <-- updated
    type: 'GET',
    data: {
        action: 'oim_get_invoice_send_logs',
        invoice_id: invoiceId,
        security: oim_ajax.security // optional if you verify nonce
    },
    success: function(response) {
        const $table = $('.oim-logs-table');
        const $tbody = $table.find('tbody');
        $tbody.empty();

        if (response.success && response.data.logs && response.data.logs.length > 0) {
            response.data.logs.forEach(log => {
                $tbody.append(`
                    <tr>
                        <td>${log.sent_at || '-'}</td>
                        <td>${log.sent_by_display_name || '-'} (${log.sent_by_username || '-'})</td>
                        <td>${log.sent_to_email || '-'}</td>
                    </tr>
                `);
            });
        } else {
            $tbody.html('<tr><td colspan="3" style="text-align:center;">No send logs available for this invoice.</td></tr>');
        }
    },
    error: function(xhr, status, error) {
        $('.oim-logs-table tbody').html('<tr><td colspan="3" style="text-align:center;color:red;">Failed to load logs.</td></tr>');
        console.error(error);
    }
});

}


    function openSidebar(order) {
        const data = order.data || {};
        const createdAt = order.createdAt || '-';
        const invoiceNumber = order.invoiceNumber || '-';
        const status = order.status || '-';
        const currentUser = (window.currentUser && window.currentUser.displayName) || 'AUTOMAT';

// Extract fields with fallbacks
const loadingCity = data.loading_city || '—';
const loadingCountry = data.loading_country || '—';
const unloadingCity = data.unloading_city || '—';
const unloadingCountry = data.unloading_country || '—';
const truckNumber = data.truck_number || '—';
const loadingDate = data.loading_date || '—';
const unloadingDate = data.unloading_date || '—';
const customerOrderNr = data.customer_order_number || '—';
const internalOrderId = data.internal_order_id || '—';

// Build the multi-line invoice text
const vatId = data.vat_id || '—';

// ✅ CHECK IF VAT ID STARTS WITH "SK"
const vatNormalized = vatId.toUpperCase().trim();
const isSlovak = vatNormalized.startsWith('SK');

// ✅ ADD REVERSE CHARGE TEXT IF NOT SLOVAK
let reverseChargeLine = '';
if (!isSlovak && vatId !== '—' && vatId !== '') {
    reverseChargeLine = '\n\nWithout VAT according to §15 of the VAT Act - reverse charge.';
}

// Build the multi-line invoice text
const invoiceText = `
We invoicing you for cargo transport ${loadingCity} /${loadingCountry}/ - ${unloadingCity} /${unloadingCountry}/
with truck ${truckNumber} date ${loadingDate} - ${unloadingDate} your order Nr. ${customerOrderNr}.
→ Fakturujeme Vám prepravu tovaru ${loadingCity} /${loadingCountry}/ - ${unloadingCity} /${unloadingCountry}/
vozidlom ${truckNumber} v dňoch ${loadingDate} - ${unloadingDate}, Vaša objednávka č. ${customerOrderNr}.${reverseChargeLine}

Our reference / Naša referencia: ${internalOrderId}
Issued by / Vystavila: ${currentUser}
Telephone / Telefón: 00421 915 794 911
E-mail: datys@datys.sk
Web: www.datys.sk
`;
        // Update Edit button URL
        const editUrl = '<?php echo site_url("/oim-dashboard/edit-invoice"); ?>';
        $('#oim-action-edit').attr('href', editUrl + '/' + order.id);



        // Send action
        $('#oim-action-send').off('click').on('click', function(e) {
            e.preventDefault();
            const form = $('<form method="post" action="<?php echo admin_url("admin-post.php"); ?>">' +
                '<input type="hidden" name="action" value="oim_send_invoice">' +
                '<input type="hidden" name="id" value="' + order.id + '">' +
                '<input type="hidden" name="_wpnonce" value="' + nonces.send + '">' +
                '</form>');
            $('body').append(form);
            form.submit();
        });

        // Download action
        $('#oim-action-download').off('click').on('click', function(e) {
    e.preventDefault();

    const form = $('<form method="post" action="<?php echo admin_url("admin-post.php"); ?>">'
        + '<input type="hidden" name="action" value="oim_export_invoice">'
        + '<input type="hidden" name="id" value="' + order.id + '">'
        + '<input type="hidden" name="_wpnonce" value="' + nonces.download + '">'
        + '</form>');

    $('body').append(form);
    form.submit();
});
$('#oim-action-download-pdf').off('click').on('click', function(e) {
    e.preventDefault();

    // Check invoice issue date
    if (!order.data.invoice_issue_date || order.data.invoice_issue_date.trim() === "") {
        showNotice('warning', 'Issue invoice first.');
        return; // stop and do NOT submit form
    }

    console.log('Initiating PDF download for order:', order.data.invoice_issue_date);

    const form = $('<form method="post" action="<?php echo admin_url("admin-post.php"); ?>">'
        + '<input type="hidden" name="action" value="oim_export_invoice_pdf">'
        + '<input type="hidden" name="id" value="' + order.id + '">'
        + '<input type="hidden" name="_wpnonce" value="' + nonces.download + '">'
        + '</form>');

    $('body').append(form);
    form.submit();
});
function showNotice(type, message) {
    const icons = {
        success: 'fa-check-circle',
        updated: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    // Remove existing notices
    $('.oim-notice-dynamic').remove();
    
    const notice = $(`
        <div class="oim-notice-dynamic ${type}" style="position:fixed;top:20px;right:20px;z-index:99999;max-width:400px;padding:16px 20px;border-radius:10px;display:flex;align-items:center;gap:12px;animation:slideIn 0.3s ease;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
            
            <span style="flex:1;">${message}</span>
            <button onclick="$(this).parent().remove();" style="background:none;border:none;cursor:pointer;opacity:0.6;font-size:16px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `);
    
    $('body').append(notice);
    
    // Auto dismiss after 5 seconds
    setTimeout(function() {
        notice.fadeOut(300, function() { $(this).remove(); });
    }, 5000);
}

        // Delete action
        // Delete action
$('#oim-action-delete').off('click').on('click', function(e) {
    e.preventDefault();
    if (!confirm('Are you sure you want to delete this invoice?')) return false;

    const form = $('<form method="post" action="<?php echo admin_url("admin-post.php"); ?>">' +
        '<input type="hidden" name="action" value="oim_delete_invoice">' +
        '<input type="hidden" name="id" value="' + order.id + '">' +
        '<input type="hidden" name="_wpnonce" value="' + nonces.delete + '">' +
        '</form>');
    $('body').append(form);
    form.submit();
});


        let html = '<div class="oim-sidebar-content-wrapper">';

        // Invoice Information
        html += `
          <div class="oim-detail-group order-info">
            <h4>Invoice Information</h4>
            ${renderDetailRow('Invoice ID', order.id)}
            ${renderDetailRow('Invoice Number', invoiceNumber)}
            ${renderDetailRow('Status', status)}
            ${renderDetailRow('Created At', createdAt)}
          </div>
        `;

        // Customer Information
        const orderId = data.internal_order_id || data.order_id || '-';
        const customer = data.customer_company_name || data.customer_name || '-';
        const email = data.customer_email || data.customer_company_email || '-';

        html += `
          <div class="oim-detail-group customer-info">
            <h4>Customer Information</h4>
            ${renderDetailRow('Order ID', orderId)}
            
            ${renderDetailRow('Email', email)}
            ${renderDetailRow('Phone Number', data.customer_phone)}
            ${renderDetailRow('Customer Company Name', customer)}
            ${renderDetailRow('Company Email', data.customer_company_email || '-')}
            ${renderDetailRow('Company Phone', data.customer_company_phone_number || '-')}
            ${renderDetailRow('Company Address', data.customer_company_address || '-')}
            ${renderDetailRow('Country', data.customer_country || '-')}
            ${renderDetailRow('VAT ID', data.vat_id || '-')}
            ${renderDetailRow('Customer Company ID (IČO - CRN) ', data.customer_company_ID_crn || '-')}
            ${renderDetailRow('Customer Tax ID (DIČ)', data.customer_tax_ID || '-')}
          </div>
        `;

        // Invoice Details
        html += `
          <div class="oim-detail-group invoice-details">
            <h4>Invoice Details</h4>
            ${renderDetailRow('Customer Reference', data.customer_reference || '-')}
            ${renderDetailRow('Customer Price', data.customer_price || '-')}
            ${renderDetailRow('Due Days', data.invoice_due_date_in_days || '-')}
          </div>
        `;

        // Loading & Unloading Information
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

// Append collapsible logs container (will be filled dynamically)

html += `
  <div class="oim-detail-group notes">
  <h4>Invoice Text</h4>
  <div class="oim-invoice-text-content">
    ${invoiceText}
    </div>
  </div>
`;

html += '</div>'; 
html += `
  <div class="oim-detail-group invoice-logs">
    <h4>Send Logs</h4>
    <div class="oim-logs-content">
      <table class="widefat fixed striped oim-logs-table" style="width:100%;">
        <thead>
          <tr>
            <th>Date & Time</th>
            <th>Sent By</th>
            <th>Sent To</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
`;
   $(document).off('click', '.oim-collapsible-header').on('click', '.oim-collapsible-header', function () {
  const content = $(this).next('.oim-collapsible-content');
  const icon = $(this).find('.toggle-icon');

  content.slideToggle(200);
  icon.text(icon.text() === '▼' ? '▲' : '▼');
});



        $('.oim-sidebar-content').html(html);
        $('#oim-order-sidebar').addClass('open');
        $('.oim-sidebar-overlay').addClass('active');

        updateNavigationButtons();

        // Load send logs for this invoice
        loadInvoiceLogs(order.id);
        
    }

    function createTripletRow(label, loadingVal, unloadingVal) {
        return `
          <div class="oim-triplet-row">
            <div class="oim-triplet-cell oim-triplet-label">${label}</div>
            <div class="oim-triplet-cell">${loadingVal || '-'}</div>
            <div class="oim-triplet-cell">${unloadingVal || '-'}</div>
          </div>
        `;
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

        $table.find('thead th').each(function(index) {
            if ($(this).hasClass('check-column')) return;

            const $th = $(this);
            const headerText = $th.find('a').text() || $th.text().trim();
            const columnIndex = $th.index();

            $th.css('position', 'relative');
            const $filterIcon = $('<span class="oim-filter-icon dashicons dashicons-filter" data-column="' + columnIndex + '" data-column-name="' + headerText + '" style="position: relative; z-index: 100; pointer-events: auto; cursor: pointer !important; margin-right: 10px;"></span>');

            const $resizer = $th.find('.oim-col-resizer');
            if ($resizer.length) {
                $resizer.before($filterIcon);
            } else {
                $th.append($filterIcon);
            }

            $th.find('a').on('click', function(e) {
                if ($(e.target).hasClass('oim-filter-icon') || $(e.target).hasClass('dashicons-filter')) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            });
        });

        $(document).on('click', '.oim-filter-icon', function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $icon = $(this);
            const columnIndex = parseInt($icon.data('column'));
            const columnName = $icon.data('column-name');

            const $existingDropdown = $icon.closest('th').find('.oim-filter-dropdown');
            if ($existingDropdown.length && $existingDropdown.hasClass('active')) {
                $existingDropdown.remove();
                $icon.removeClass('active');
                $icon.closest('th').css('z-index', '');
                return false;
            }

            $('.oim-filter-dropdown').remove();
            $('.oim-filter-icon').removeClass('active');
            $('thead th').css('z-index', '');

            $icon.addClass('active');
            const $dropdown = buildFilterDropdown(columnIndex, columnName);

            const $th = $icon.closest('th');
            $th.css({
                'z-index': '999999',
                'position': 'relative'
            });

            $dropdown.appendTo('body');
            $dropdown.addClass('active');

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

            return false;
        });

        $(document).on('click', function(e) {
            if (!$(e.target).closest('.oim-filter-dropdown, .oim-filter-icon').length) {
                $('.oim-filter-dropdown').remove();
                $('.oim-filter-icon').removeClass('active');
                $('thead th').css('z-index', '');
            }
        });

        function buildFilterDropdown(columnIndex, columnName) {
            const $visibleRows = $table.find('tbody tr.oim-order-row:visible');
            const tdIndex = columnIndex - 1;

            const valueMap = {};
            $visibleRows.each(function() {
                const cellValue = $(this).find('td').eq(tdIndex).text().trim();
                if (cellValue && cellValue !== '—') {
                    valueMap[cellValue] = (valueMap[cellValue] || 0) + 1;
                }
            });

            const sortedValues = Object.keys(valueMap).sort((a, b) => a.localeCompare(b));

            const $dropdown = $('<div class="oim-filter-dropdown"></div>');

            const $search = $('<input type="text" class="oim-filter-search" placeholder="Search...">');
            $dropdown.append($search);

            const $selectAll = $('<div class="oim-filter-select-all"><input type="checkbox" id="select-all-' + columnIndex + '" class="select-all-checkbox"> <label for="select-all-' + columnIndex + '">Select All</label></div>');
            $dropdown.append($selectAll);

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

            const $actions = $(`
                <div class="oim-filter-actions">
                    <button class="oim-filter-btn apply">Apply</button>
                    <button class="oim-filter-btn reset">Reset</button>
                </div>
            `);
            $dropdown.append($actions);

            function updateSelectAllState() {
                const $selectAllCheckbox = $selectAll.find('.select-all-checkbox');
                const totalVisible = $options.find('input:checkbox:visible').length;
                const totalChecked = $options.find('input:checkbox:checked:visible').length;
                $selectAllCheckbox.prop('checked', totalVisible > 0 && totalVisible === totalChecked);
            }

            updateSelectAllState();

            $dropdown.on('click', function(e) {
                e.stopPropagation();
            });

            $search.on('input', function(e) {
                e.stopPropagation();
                const searchTerm = $(this).val().toLowerCase();
                $options.find('.oim-filter-option').each(function() {
                    const text = $(this).find('label').text().toLowerCase();
                    $(this).toggle(text.includes(searchTerm));
                });
                updateSelectAllState();
            });

            $selectAll.on('click', function(e) {
                e.stopPropagation();
                const $selectAllCheckbox = $(this).find('.select-all-checkbox');
                const shouldCheck = !$selectAllCheckbox.prop('checked');
                $selectAllCheckbox.prop('checked', shouldCheck);
                $options.find('input:checkbox:visible').prop('checked', shouldCheck);
            });

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

            $actions.find('.apply').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                const selectedValues = [];
                $options.find('input:checkbox:checked').each(function() {
                    selectedValues.push($(this).val());
                });

                if (selectedValues.length === sortedValues.length) {
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

                for (let colIndex in columnFilters) {
                    const filterValues = columnFilters[colIndex];
                    const tdIndex = parseInt(colIndex) - 1;
                    const cellValue = $row.find('td').eq(tdIndex).text().trim();

                    if (filterValues.length > 0 && !filterValues.includes(cellValue)) {
                        showRow = false;
                        break;
                    }
                }

                $row.toggle(showRow);
            });

            const visibleCount = $table.find('tbody tr.oim-order-row:visible').length;
            $('.oim-orders-count').text(visibleCount + ' invoice(s) found');

            updateActiveFiltersDisplay();
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

            const $resetAll = $('<button class="oim-reset-all-filters">Reset All Filters</button>');
            $container.append($resetAll);

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

                try {
                    rowData = (typeof $row.data('order-data') === 'object') ? $row.data('order-data') : JSON.parse(rowData);
                } catch (err) {
                    rowData = $row.data('order-data') || {};
                }

                allOrders.push({
                    element: $row,
                    id: $row.data('order-id'),
                    invoiceNumber: $row.data('invoice-number'),
                    status: $row.data('status'),
                    data: rowData,
                    createdAt: $row.data('created-at')
                });
            });
        }
    })();

});
</script>

    </div>


    
<style>
/* Collapsible Section Styles */


.oim-section-trigger {
    cursor: pointer;
    user-select: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background-color 0.3s ease;
}

.oim-section-trigger:hover {
    background-color: #f5f5f5;
}

.oim-header-content {
    flex: 1;
}

.oim-toggle-icon {
    font-size: 24px;
    font-weight: bold;
    color: #2271b1;
    margin-left: 15px;
    transition: transform 0.3s ease;
    line-height: 1;
}

.oim-section-trigger.active .oim-toggle-icon {
    transform: rotate(45deg);
}

.oim-section-collapsible {
    overflow: hidden;
    transition: max-height 0.4s ease, opacity 0.3s ease, padding 0.3s ease;
    max-height: 0;
    opacity: 0;
}

.oim-section-collapsible.open {
    max-height: 2000px;
    opacity: 1;
    padding-top: 15px;
}

/* File Upload Styles */
.oim-file-upload-wrapper {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-bottom: 15px;
}

.oim-file-input-container {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.oim-file-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #2271b1;
    color: #fff;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s;
    font-weight: 500;
}

.oim-file-label:hover {
    background: #135e96;
}

.oim-file-input {
    display: none;
}

.oim-file-name {
    color: #666;
    font-style: italic;
    font-size: 14px;
}

.oim-file-name.has-file {
    color: #2271b1;
    font-style: normal;
    font-weight: 500;
}

.oim-file-info {
    background: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
}

.oim-file-info p {
    margin: 8px 0;
    font-size: 14px;
}

.oim-file-info ul {
    margin: 8px 0 8px 20px;
    font-size: 14px;
}

.oim-file-info li {
    margin: 4px 0;
}

.oim-help-text {
    display: flex;
    align-items: center;
    gap: 6px;
    color: #135e96;
    font-size: 13px;
    margin-top: 10px;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 782px) {
    .oim-section-trigger {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .oim-toggle-icon {
        position: absolute;
        right: 20px;
        top: 20px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Toggle collapsible sections
    $('.oim-section-trigger').on('click', function() {
        var $trigger = $(this);
        var $content = $trigger.next('.oim-section-collapsible');
        
        // Toggle active class on trigger
        $trigger.toggleClass('active');
        
        // Toggle content visibility with smooth animation
        if ($content.hasClass('open')) {
            $content.removeClass('open');
            setTimeout(function() {
                $content.css('display', 'none');
            }, 400);
        } else {
            $content.css('display', 'block');
            setTimeout(function() {
                $content.addClass('open');
            }, 10);
        }
    });
    
    // File input change handler
    $('#payment_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        var $fileNameDisplay = $('#file-name-display');
        
        if (fileName) {
            $fileNameDisplay.text(fileName).addClass('has-file');
        } else {
            $fileNameDisplay.text('No file selected').removeClass('has-file');
        }
    });

    // Form validation for payment import
    $('#payment-import-form').on('submit', function(e) {
        var fileInput = $('#payment_file')[0];
        
        if (!fileInput.files || fileInput.files.length === 0) {
            e.preventDefault();
            alert('Please select a file to upload.');
            return false;
        }

        var fileName = fileInput.files[0].name;
        var fileExtension = fileName.split('.').pop().toLowerCase();
        
        if (fileExtension !== 'txt') {
            e.preventDefault();
            alert('Please select a .txt file.');
            return false;
        }

        // Show loading indicator
        var $button = $(this).find('button[type="submit"]');
        var originalText = $button.text();
        $button.prop('disabled', true).text('Processing...');
        
        // Store original text for potential restoration
        $button.data('original-text', originalText);
    });
});
</script>

    <?php
}

// });
// </script>

//     </div>
//     <?php
// }


    /***************************************************************************
     * Edit invoice page (hidden submenu)
     *
     * This page shows invoice editable fields and settings defaults (settings NOT saved globally)
     * When saving we store the invoice-specific values into the oim_orders.data column.
     ***************************************************************************/
    
private static function calculate_invoice_totals($invoice_data) {
    // Get values
    $vat_id = strtoupper(trim($invoice_data['vat_id'] ?? ''));
    $customer_price = floatval($invoice_data['customer_price'] ?? 0);
    $setting_percent_vat = floatval(get_option('oim_percent_vat', 0));

    // Determine VAT percentage based on VAT ID
    if (strpos($vat_id, 'SK') === 0) {
        // Slovak VAT ID → apply VAT
        $invoice_data['oim_percent_vat'] = isset($invoice_data['oim_percent_vat']) && $invoice_data['oim_percent_vat'] !== '' 
            ? floatval($invoice_data['oim_percent_vat']) 
            : $setting_percent_vat;
    } else {
        // Non-Slovak VAT ID → 0% VAT
        $invoice_data['oim_percent_vat'] = 0;
    }

    // Calculate VAT amount and total
    $vat_percent = floatval($invoice_data['oim_percent_vat']);
    $invoice_data['oim_amount'] = $customer_price; // Base amount (without VAT)
    $invoice_data['oim_vat'] = round(($customer_price * $vat_percent) / 100, 2);
    $invoice_data['oim_total_price'] = round($customer_price + $invoice_data['oim_vat'], 2);

    return $invoice_data;
}
public static function get_documents_by_order_id($order_id) {
    global $wpdb;
    
    // Correct table name
    $table_name = $wpdb->prefix . 'oim_order_documents';
    
    // Fetch ALL documents for this order_id (multiple rows per order)
    // ✅ Using correct column names: created_at, filename, mime, file_url
    $documents = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE order_id = %s ORDER BY created_at ASC",
            $order_id
        ),
        ARRAY_A
    );
    
    if ($wpdb->last_error) {
        error_log('Database error in get_documents_by_order_id: ' . $wpdb->last_error);
        return [];
    }
    
    error_log('Fetched ' . count($documents) . ' documents for order_id: ' . $order_id);
    
    // Log each document for debugging
    foreach ($documents as $doc) {
        error_log('Document: ID=' . ($doc['id'] ?? 'N/A') . ', filename=' . ($doc['filename'] ?? 'N/A') . ', mime=' . ($doc['mime'] ?? 'N/A'));
    }
    
    return $documents ?: [];
}
public function render_edit_invoice_page($invoice_id = 0) {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    
    global $wpdb;
    $invoice_id = intval($invoice_id) ?? 0;
    error_log('Editing invoice with ID: ' . $invoice_id);
    if (!$invoice_id) wp_die('Invalid invoice ID');

    // Query invoice
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id=%d", 
        $invoice_id
    ));
    
    if (!$invoice) wp_die('Invoice not found');

    // Decode stored data ONCE
    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) {
        $invoice_data = json_decode($invoice->data, true) ?: [];
    }

    // ===== HANDLE "ISSUE INVOICE" BUTTON =====
    if (isset($_POST['issue_invoice'])) {
    check_admin_referer('oim_edit_invoice');

    // Only set issue date if not already set
    if (empty($invoice_data['invoice_issue_date'])) {
        $invoice_data['invoice_issue_date'] = current_time('Y-m-d H:i:s');

        // AUTO-SET TAXABLE PAYMENT DATE when invoice is issued
        $invoice_data['oim_taxable_payment_date'] = current_time('Y-m-d H:i:s');

        // Calculate invoice due date if due_days exists
        $due_days = !empty($invoice_data['invoice_due_days']) 
            ? intval($invoice_data['invoice_due_days']) 
            : 0;

        // Generate due date based on issue date + due days
        if ($due_days > 0) {
            $invoice_data['invoice_due_date'] = date(
                'Y-m-d H:i:s',
                strtotime($invoice_data['invoice_issue_date'] . " +{$due_days} days")
            );
        } else {
            // No due days → No due date
            $invoice_data['invoice_due_date'] = '';
        }
    }

    // Save to DB
    $wpdb->update(
        "{$wpdb->prefix}oim_invoices",
        ['data' => maybe_serialize($invoice_data)],
        ['id' => $invoice_id],
        ['%s'],
        ['%d']
    );

    echo '<div class="updated"><p>Invoice issued successfully on ' . esc_html($invoice_data['invoice_issue_date']) . '</p></div>';

    // Reload data
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id=%d", 
        $invoice_id
    ));
    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) {
        $invoice_data = json_decode($invoice->data, true) ?: [];
    }
}


    // ===== HANDLE "EXPORT INVOICE" BUTTON =====
    if (isset($_POST['export_invoice'])) {
        check_admin_referer('oim_edit_invoice');
        
        // Only set export date if not already set
        
        $export_timestamp = current_time('Y-m-d H:i:s');
        $invoice_data['invoice_export_date'] = $export_timestamp;
        $invoice_data['invoice_export_flag'] = 'true'; // Mark as exported
        
        
        $wpdb->update(
            "{$wpdb->prefix}oim_invoices",
            ['data' => maybe_serialize($invoice_data)],
            ['id' => $invoice_id],
            ['%s'],
            ['%d']
        );
        
       
        
        // Reload data
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id=%d", 
            $invoice_id
        ));
        $invoice_data = maybe_unserialize($invoice->data);
        if (!is_array($invoice_data)) {
            $invoice_data = json_decode($invoice->data, true) ?: [];
        }
        $this->download_invoice_txt($invoice_id);
    }

    // ===== HANDLE "SAVE" BUTTON =====
   // ===== HANDLE "SAVE" BUTTON =====
// ===== HANDLE "SAVE" BUTTON =====
if (isset($_POST['save']) || isset($_POST['oim_edit_invoice_submit'])) {
    check_admin_referer('oim_edit_invoice');

    // ✅ PRESERVE IMPORTANT DATES AND FLAGS - Store them before updating
    $preserved_issue_date = $invoice_data['invoice_issue_date'] ?? '';
    $preserved_sent_date = $invoice_data['invoice_sent_date'] ?? '';
    $preserved_export_date = $invoice_data['invoice_export_date'] ?? '';
    $preserved_export_flag = $invoice_data['invoice_export_flag'] ?? '';
    $preserved_taxable_date = $invoice_data['oim_taxable_payment_date'] ?? '';

    // ✅ ALL EDITABLE FIELDS (including oim_company_supplier, oim_issued_by, oim_signature)
    $fields = [
        'customer_reference', 'vat_id', 'customer_email', 'customer_company_name',
        'customer_country', 'customer_price', 'invoice_number', 'invoice_due_date_in_days',
        'customer_company_email', 'customer_company_phone_number', 'customer_company_address',
        'loading_company_name', 'loading_date', 'loading_country', 'loading_zip', 'loading_city',
        'unloading_company_name', 'unloading_date', 'unloading_country', 'unloading_zip', 'unloading_city',
        'internal_order_id', 'order_note', 'truck_number',
        'oim_with_amount_of', 'oim_attachment', 'oim_without_vat_reverse_charge',
        'oim_percent_vat', 'invoice_status', 'amount_paid', 'oim_invoice_currency',
        // ✅ ADD THESE THREE FIELDS
        'oim_company_supplier', 'oim_issued_by', 'oim_signature', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
    ];

    foreach ($fields as $f) {
        $invoice_data[$f] = sanitize_text_field($_POST[$f] ?? '');
    }

    // ✅ IF SUPPLIER IS EMPTY, FETCH FROM SETTINGS
    if (empty($invoice_data['oim_company_supplier']) || trim($invoice_data['oim_company_supplier']) === '') {
        $invoice_data['oim_company_supplier'] = get_option('oim_company_supplier', '');
    }

    // ✅ IF ISSUED BY IS EMPTY, USE CURRENT WORDPRESS USER
    if (empty($invoice_data['oim_issued_by']) || trim($invoice_data['oim_issued_by']) === '') {
        $current_user = wp_get_current_user();
        // Try full name first, fallback to display name, then username
        if (!empty($current_user->first_name) && !empty($current_user->last_name)) {
            $invoice_data['oim_issued_by'] = $current_user->first_name . ' ' . $current_user->last_name;
        } elseif (!empty($current_user->display_name)) {
            $invoice_data['oim_issued_by'] = $current_user->display_name;
        } else {
            $invoice_data['oim_issued_by'] = $current_user->user_login;
        }
    }

    // ✅ RESTORE ALL PRESERVED VALUES (don't let form overwrite them)
    $invoice_data['invoice_issue_date'] = $preserved_issue_date;
    $invoice_data['invoice_sent_date'] = $preserved_sent_date;
    $invoice_data['invoice_export_date'] = $preserved_export_date;
    $invoice_data['invoice_export_flag'] = 'false';
    $invoice_data['oim_taxable_payment_date'] = $preserved_taxable_date;

    // ✅ RECALCULATE VAT AND TOTALS
    $invoice_data = self::calculate_invoice_totals($invoice_data);

    // ✅ CALCULATE REMAINING BALANCE (Total - Amount Paid)
    $total_price = floatval($invoice_data['oim_total_price'] ?? 0);
    $amount_paid = floatval($invoice_data['amount_paid'] ?? 0);
    $invoice_data['oim_total_price_to_be_paid'] = round($total_price - $amount_paid, 2);

    // Update database
    $update_result = $wpdb->update(
        "{$wpdb->prefix}oim_invoices",
        [
            'data' => maybe_serialize($invoice_data),
            'invoice_number' => $invoice_data['invoice_number'],
        ],
        ['id' => $invoice_id],
        ['%s', '%s'],
        ['%d']
    );

    if ($update_result !== false) {
        echo '<div class="updated"><p>Invoice updated successfully.</p></div>';
    } else {
        echo '<div class="error"><p>Failed to update invoice. Database error.</p></div>';
    }

    // Reload
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id=%d", 
        $invoice_id
    ));
    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) {
        $invoice_data = json_decode($invoice->data, true) ?: [];
    }
}
    
    // ===== HANDLE "SAVE & SEND EMAIL" BUTTON =====
// ===== HANDLE "SAVE & SEND EMAIL" BUTTON =====
if (isset($_POST['oim_edit_invoice_submit_send'])) {
    check_admin_referer('oim_edit_invoice');

    // ✅ PRESERVE IMPORTANT DATES AND FLAGS
    $preserved_issue_date = $invoice_data['invoice_issue_date'] ?? '';
    $preserved_export_date = $invoice_data['invoice_export_date'] ?? '';
    $preserved_export_flag = $invoice_data['invoice_export_flag'] ?? '';
    $preserved_taxable_date = $invoice_data['oim_taxable_payment_date'] ?? '';

    // Save fields (including supplier, issued_by, signature)
    $fields = [
        'customer_reference', 'vat_id', 'customer_email', 'customer_company_name',
        'customer_country', 'customer_price', 'invoice_number', 'invoice_due_date_in_days', 'invoice_due_date',
        'customer_company_email', 'customer_company_phone_number', 'customer_company_address',
        'loading_company_name', 'loading_date', 'loading_country', 'loading_zip', 'loading_city',
        'unloading_company_name', 'unloading_date', 'unloading_country', 'unloading_zip', 'unloading_city',
        'internal_order_id', 'order_note', 'truck_number',
        'oim_with_amount_of', 'oim_attachment', 'oim_without_vat_reverse_charge',
        'invoice_status', 'oim_invoice_currency', 'amount_paid',
        'oim_company_supplier', 'oim_issued_by', 'oim_signature', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID'
    ];

    foreach ($fields as $f) {
        $invoice_data[$f] = sanitize_text_field($_POST[$f] ?? '');
    }

    // ✅ IF SUPPLIER IS EMPTY, FETCH FROM SETTINGS
    if (empty($invoice_data['oim_company_supplier']) || trim($invoice_data['oim_company_supplier']) === '') {
        $invoice_data['oim_company_supplier'] = get_option('oim_company_supplier', '');
    }

    // ✅ IF ISSUED BY IS EMPTY, USE CURRENT WORDPRESS USER
    if (empty($invoice_data['oim_issued_by']) || trim($invoice_data['oim_issued_by']) === '') {
        $current_user = wp_get_current_user();
        if (!empty($current_user->first_name) && !empty($current_user->last_name)) {
            $invoice_data['oim_issued_by'] = $current_user->first_name . ' ' . $current_user->last_name;
        } elseif (!empty($current_user->display_name)) {
            $invoice_data['oim_issued_by'] = $current_user->display_name;
        } else {
            $invoice_data['oim_issued_by'] = $current_user->user_login;
        }
    }

    // ✅ RESTORE PRESERVED VALUES
    $invoice_data['invoice_issue_date'] = $preserved_issue_date;
    $invoice_data['invoice_export_date'] = $preserved_export_date;
    $invoice_data['invoice_export_flag'] = 'false';
    $invoice_data['oim_taxable_payment_date'] = $preserved_taxable_date;

    // ✅ AUTO-SET SENT DATE when attempting to send email
    $invoice_data['invoice_sent_date'] = current_time('Y-m-d H:i:s');

    // ✅ RECALCULATE VAT AND TOTALS
    $invoice_data = self::calculate_invoice_totals($invoice_data);

    // ✅ CALCULATE REMAINING BALANCE
    $total_price = floatval($invoice_data['oim_total_price'] ?? 0);
    $amount_paid = floatval($invoice_data['amount_paid'] ?? 0);
    $invoice_data['oim_total_price_to_be_paid'] = round($total_price - $amount_paid, 2);

    // Send email
    $to = $invoice_data['customer_email'] ?? $invoice_data['customer_company_email'] ?? '';
    if (empty($to) || !is_email($to)) {
        // ✅ NO VALID EMAIL - DON'T CHANGE EXPORT FLAG
        $invoice_data['invoice_sent_date'] = ''; // Reset sent date
        
        $wpdb->update(
            "{$wpdb->prefix}oim_invoices",
            [
                'data' => maybe_serialize($invoice_data),
                'invoice_number' => $invoice_data['invoice_number'],
            ],
            ['id' => $invoice_id],
            ['%s', '%s'],
            ['%d']
        );
        
        echo '<div class="error"><p>No valid recipient email found. Invoice not sent.</p></div>';
    } else {
        $internal_order_id = $invoice_data['internal_order_id'] ?? $invoice_id;

        if (class_exists('OIM_Invoice')) {
            // ✅ FETCH DRIVER DOCUMENTS BY INTERNAL ORDER ID
            $driver_documents = OIM_Invoices::get_documents_by_order_id($internal_order_id);
            
            // ✅ LOG DOCUMENTS FOUND (for debugging)
            error_log('Fetching documents for order ID: ' . $internal_order_id);
            error_log('Documents found: ' . count($driver_documents));
            
            // ✅ PASS DOCUMENTS TO INVOICE HTML BUILDER
            $invoice_html = OIM_Invoice::build_invoice_html($invoice_id, $invoice_data);
            
            // ✅ PASS DOCUMENTS TO PDF GENERATOR
            $pdf = OIM_Invoice::generate_pdf_for_invoice_html($invoice_html, $internal_order_id);

            $pdf_url = '';
            if (is_array($pdf) && isset($pdf['url'])) {
                $pdf_url = $pdf['url'];
            } elseif (is_string($pdf)) {
                $pdf_url = $pdf;
            }

            $replacements = [
                'order_id'     => $internal_order_id,
                'company_name' => $invoice_data['customer_company_name'] ?? get_bloginfo('name'),
                'site_name'    => get_bloginfo('name')
            ];

            $subject = $this->get_email_option('subject', $replacements);
            $body_template = $this->get_email_option('email_body', $replacements);
            $body_html = nl2br(esc_html($body_template));

            if (!empty($pdf_url)) {
                $body_html .= '<p><a href="' . esc_url($pdf_url) . '" style="display:inline-block;padding:10px 15px;background:#4CAF50;color:#fff;text-decoration:none;border-radius:4px;">Download Invoice (PDF)</a></p>';
            }

            $message = '<html><body style="font-family:Arial,sans-serif;background:#f9f9f9;padding:20px;">
                <div style="background:#fff;padding:20px;border-radius:6px;max-width:680px;margin:auto;">
                    ' . $body_html . '
                    <p style="margin-top:18px;font-size:12px;color:#666;">This is an automated email. Please do not reply.</p>
                </div>
            </body></html>';

            add_filter('wp_mail_content_type', function() { return 'text/html'; });
            $sent = wp_mail($to, $subject, $message);
            remove_filter('wp_mail_content_type', function() { return 'text/html'; });

            if ($sent) {
                // ✅ EMAIL SENT SUCCESSFULLY - NOW SET EXPORT FLAG TO TRUE
                $invoice_data['invoice_export_flag'] = 'true';
                $this->log_invoice_send($invoice_id, $to);
                $wpdb->update(
                    "{$wpdb->prefix}oim_invoices",
                    [
                        'data' => maybe_serialize($invoice_data),
                        'invoice_number' => $invoice_data['invoice_number'],
                    ],
                    ['id' => $invoice_id],
                    ['%s', '%s'],
                    ['%d']
                );
                
                echo '<div class="updated"><p>✓ Invoice sent successfully on ' . esc_html($invoice_data['invoice_sent_date']) . ' with ' . count($driver_documents) . ' driver document(s) <strong>(Export flag set to TRUE)</strong></p></div>';
            } else {
                // ✅ EMAIL FAILED - DON'T CHANGE EXPORT FLAG, RESET SENT DATE
                $invoice_data['invoice_sent_date'] = '';
                
                $wpdb->update(
                    "{$wpdb->prefix}oim_invoices",
                    ['data' => maybe_serialize($invoice_data)],
                    ['id' => $invoice_id],
                    ['%s'],
                    ['%d']
                );
                
                echo '<div class="error"><p>✗ Failed to send email. Export flag unchanged.</p></div>';
            }
        }
    }
    
    // Reload
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id=%d", 
        $invoice_id
    ));
    $invoice_data = maybe_unserialize($invoice->data);
    if (!is_array($invoice_data)) {
        $invoice_data = json_decode($invoice->data, true) ?: [];
    }
}
    // ===== APPLY SETTINGS DEFAULTS =====
    $settings_fields = [
        'oim_company_email', 'oim_company_bank', 'oim_company_bic',
        'oim_payment_title', 'oim_company_account', 'oim_company_iban',
        'oim_company_supplier', 'oim_headquarters', 'oim_crn', 'oim_tin',
        'oim_our_reference', 'oim_issued_by', 'oim_company_phone',
        'oim_company_web', 'oim_invoice_currency', 'oim_percent_vat',
    ];

    foreach ($settings_fields as $field) {
        $option_value = get_option($field);
        if (!empty($option_value) && (empty($invoice_data[$field]) || !isset($invoice_data[$field]))) {
            $invoice_data[$field] = $option_value;
        }
    }

    // ✅ AUTO-CALCULATE before displaying form
    $invoice_data = self::calculate_invoice_totals($invoice_data);
    
    // ✅ CALCULATE REMAINING BALANCE for display
    $total_price = floatval($invoice_data['oim_total_price'] ?? 0);
    $amount_paid = floatval($invoice_data['amount_paid'] ?? 0);
    $invoice_data['oim_total_price_to_be_paid'] = round($total_price - $amount_paid, 2);

    // Continue with HTML form rendering...
    ?>
<div class="wrap oim-invoice-edit">
    <div class="oim-header">
        <h1>Edit Invoice #<?php echo esc_html($invoice_id); ?></h1>
        <a href="<?php echo site_url('/oim-dashboard/invoices'); ?>" class="button">Back to Invoices</a>

    </div>

    <form method="post" class="oim-form">
        <?php wp_nonce_field('oim_edit_invoice'); ?>

        <div class="oim-layout">
            <!-- Main Content -->
            <div class="oim-main">
                
                <!-- Customer & Contact -->
                <div class="oim-card">
                    <h3 class="oim-card-title">Customer & Contact</h3>
                    
                    <div class="oim-grid-3">
                        <div class="oim-field">
                            <label>Customer Reference</label>
                            <input type="text" name="customer_reference" value="<?php echo esc_attr($invoice_data['customer_reference'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Truck Number</label>
                            <input type="text" name="truck_number" value="<?php echo esc_attr($invoice_data['truck_number'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>VAT ID</label>
                            <input type="text" name="vat_id" value="<?php echo esc_attr($invoice_data['vat_id'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Customer Email</label>
                            <input type="email" name="customer_email" value="<?php echo esc_attr($invoice_data['customer_email'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Customer Phone Number</label>
                            <input type="text" name="customer_phone" value="<?php echo esc_attr($invoice_data['customer_phone'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Company Name</label>
                            <input type="text" name="customer_company_name" value="<?php echo esc_attr($invoice_data['customer_company_name'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Country</label>
                            <input type="text" name="customer_country" value="<?php echo esc_attr($invoice_data['customer_country'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Company Email</label>
                            <input type="email" name="customer_company_email" value="<?php echo esc_attr($invoice_data['customer_company_email'] ?? ''); ?>">
                        </div>
                        
                        <div class="oim-field">
                            <label>Phone Number (extra)</label>
                            <input type="text" name="customer_company_phone_number" value="<?php echo esc_attr($invoice_data['customer_company_phone_number'] ?? ''); ?>">
                        </div>
                        <div class="oim-field">
                            <label>Customer Price</label>
                            <input type="number" step="0.01" name="customer_price" value="<?php echo esc_attr($invoice_data['customer_price'] ?? ''); ?>">
                        </div>
                        <!-- , 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID' -->
                        <div class="oim-field oim-span-3"> 
                            <label>Customer Company ID (IČO - CRN)</label>
                            <input type="text" name="customer_company_ID_crn" value="<?php echo esc_attr($invoice_data['customer_company_ID_crn'] ?? ''); ?>">
                        </div>
                        <div class="oim-field oim-span-3">
                            <label>Customer Tax ID (DIČ)</label>
                            <input type="text" name="customer_tax_ID" value="<?php echo esc_attr($invoice_data['customer_tax_ID'] ?? ''); ?>">
                        </div>
                        <div class="oim-field oim-span-3">
                            <label>Customer Company Address</label>
                            <textarea name="customer_company_address" rows="2"><?php echo esc_textarea($invoice_data['customer_company_address'] ?? ''); ?></textarea>
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
                                    <input type="text" name="loading_company_name" value="<?php echo esc_attr($invoice_data['loading_company_name'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>Date</label>
                                    <input type="date" name="loading_date" value="<?php echo esc_attr($invoice_data['loading_date'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>Country</label>
                                    <input type="text" name="loading_country" value="<?php echo esc_attr($invoice_data['loading_country'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>ZIP</label>
                                    <input type="text" name="loading_zip" value="<?php echo esc_attr($invoice_data['loading_zip'] ?? ''); ?>">
                                </div>
                                <div class="oim-field oim-span-2">
                                    <label>City</label>
                                    <input type="text" name="loading_city" value="<?php echo esc_attr($invoice_data['loading_city'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div>
                            <h4 class="oim-subtitle">Unloading</h4>
                            <div class="oim-grid-2">
                                <div class="oim-field">
                                    <label>Company Name</label>
                                    <input type="text" name="unloading_company_name" value="<?php echo esc_attr($invoice_data['unloading_company_name'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>Date</label>
                                    <input type="date" name="unloading_date" value="<?php echo esc_attr($invoice_data['unloading_date'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>Country</label>
                                    <input type="text" name="unloading_country" value="<?php echo esc_attr($invoice_data['unloading_country'] ?? ''); ?>">
                                </div>
                                <div class="oim-field">
                                    <label>ZIP</label>
                                    <input type="text" name="unloading_zip" value="<?php echo esc_attr($invoice_data['unloading_zip'] ?? ''); ?>">
                                </div>
                                <div class="oim-field oim-span-2">
                                    <label>City</label>
                                    <input type="text" name="unloading_city" value="<?php echo esc_attr($invoice_data['unloading_city'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Company Settings -->
                <div class="oim-card">
                    <h3 class="oim-card-title">Company Settings <span class="oim-badge">Invoice-Specific</span></h3>
                    <div class="oim-grid-3">
                        <div class="oim-field">
                            <label>Supplier</label>
                            <input type="text" name="oim_company_supplier" value="<?php echo esc_attr($invoice_data['oim_company_supplier'] ?? ''); ?>">
                        </div>
                        
                        <div class="oim-field">
                            <label>Issued By</label>
                            <input type="text" name="oim_issued_by" value="<?php echo esc_attr($invoice_data['oim_issued_by'] ?? ''); ?>">
                        </div>
                        
                        <div class="oim-field">
                            <label>Signature</label>
                            <input type="text" name="oim_signature" value="<?php echo esc_attr($invoice_data['oim_signature'] ?? ''); ?>">
                        </div>
                        <!-- <div class="oim-field">
                            <label>Invoice Sending Date</label>
                            <input type="date" name="oim_invoice_sending_date" value="<?php echo esc_attr($invoice_data['oim_invoice_sending_date'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Taxable Payment Date</label>
                            <input type="date" name="oim_taxable_payment_date" value="<?php echo esc_attr($invoice_data['oim_taxable_payment_date'] ?? ''); ?>">
                        </div> -->

                        <div class="oim-field">
                            <label>With Amount Of</label>
                            <input type="text" name="oim_with_amount_of" value="<?php echo esc_attr($invoice_data['oim_with_amount_of'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Total Price To Be Paid</label>
                            <input type="text" name="oim_total_price_to_be_paid" value="<?php echo esc_attr($invoice_data['oim_total_price_to_be_paid'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Without VAT / Reverse Charge</label>
                            <input type="text" name="oim_without_vat_reverse_charge" value="<?php echo esc_attr($invoice_data['oim_without_vat_reverse_charge'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Percent VAT</label>
                            <input type="number" step="0.01" name="oim_percent_vat" value="<?php echo esc_attr($invoice_data['oim_percent_vat'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Amount</label>
                            <input type="number" step="0.01" name="oim_amount" value="<?php echo esc_attr($invoice_data['oim_amount'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>VAT</label>
                            <input type="number" step="0.01" name="oim_vat" value="<?php echo esc_attr($invoice_data['oim_vat'] ?? ''); ?>">
                        </div>

                        <div class="oim-field">
                            <label>Total Price</label>
                            <input type="number" step="0.01" name="oim_total_price" value="<?php echo esc_attr($invoice_data['oim_total_price'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

            </div>

            <!-- Sidebar -->
            <div>
                <div class="oim-card oim-sticky">
                    <h3 class="oim-card-title">Invoice Meta</h3>
                    
                    <div class="oim-field">
                        <label>Invoice Number</label>
                        <input type="text" name="invoice_number" value="<?php echo esc_attr($invoice_data['invoice_number'] ?? ''); ?>">
                    </div>
                    
                    <div class="oim-field">
                        <label>Invoice Currency</label>
                        <input type="text" name="oim_invoice_currency" value="<?php echo esc_attr($invoice_data['oim_invoice_currency'] ?? ''); ?>">
                    </div>
                    
                    <div class="oim-field">
                        <label>Due Days</label>
                        <input type="number" name="invoice_due_date_in_days" value="<?php echo esc_attr($invoice_data['invoice_due_date_in_days'] ?? ''); ?>">
                    </div>
                    
                    <div class="oim-field">
                        <label>Internal Order ID</label>
                        <input type="text" name="internal_order_id" value="<?php echo esc_attr($invoice_data['internal_order_id'] ?? ''); ?>">
                    </div>

                    <!-- NEW: Invoice Status -->
                    <div class="oim-field">
                        <label>Invoice Status</label>
                        <select name="invoice_status" class="oim-select">
                            <option value="unpaid" <?php selected($invoice_data['invoice_status'] ?? '', 'unpaid'); ?>>Not Paid</option>
                            <option value="paid" <?php selected($invoice_data['invoice_status'] ?? '', 'paid'); ?>>Paid</option>
                            <option value="partial" <?php selected($invoice_data['invoice_status'] ?? '', 'partial'); ?>>Partially Paid</option>
                            <option value="overdue" <?php selected($invoice_data['invoice_status'] ?? '', 'overdue'); ?>>Overdue</option>
                        </select>
                    </div>

                    <!-- NEW: Amount Paid -->
                    <div class="oim-field">
                        <label>Amount Paid So Far</label>
                        <input type="number" step="0.01" name="amount_paid" value="<?php echo esc_attr($invoice_data['amount_paid'] ?? '0.00'); ?>" placeholder="0.00">
                    </div>

                    <hr style="margin: 15px 0; border: none; border-top: 1px solid #e0e0e0;">

                    <!-- NEW: Invoice Dates Section -->
                    <h4 style="margin: 10px 0 5px; font-size: 13px; font-weight: 600; color: #555;">Invoice Dates</h4>
                    
                    <div class="oim-field">
    <label>Issue Date</label>
    <?php 
    $issue_date_full = $invoice_data['invoice_issue_date'] ?? '';
    // Extract only the date part (Y-m-d) from datetime (Y-m-d H:i:s)
    $issue_date_display = !empty($issue_date_full) ? date('Y-m-d', strtotime($issue_date_full)) : '';
    ?>
    <input type="date" 
           name="invoice_issue_date_display" 
           value="<?php echo esc_attr($issue_date_display); ?>" 
           readonly 
           style="background: #f5f5f5;">
    
</div>

                    <div class="oim-field">
                        <label>Last Sent Date</label>
                        <input type="text" name="invoice_sent_date" value="<?php echo esc_attr($invoice_data['invoice_sent_date'] ?? ''); ?>" readonly style="background: #f5f5f5;">
                        <?php if (empty($invoice_data['invoice_sent_date'])): ?>
                            <small style="color: #999;">Not sent yet</small>
                        <?php endif; ?>
                    </div>

                    <div class="oim-field">
                        <label>Export Date</label>
                        <input type="text" name="invoice_export_date" value="<?php echo esc_attr($invoice_data['invoice_export_date'] ?? ''); ?>" readonly style="background: #f5f5f5;">
                        <?php if (empty($invoice_data['invoice_export_date'])): ?>
                            <small style="color: #999;">Not exported yet</small>
                        <?php endif; ?>
                    </div>

                    <div class="oim-field">
                        <label>Export Flag</label>
                        <?php 
                        $export_flag = $invoice_data['invoice_export_flag'] ?? '';
                        $is_exported = ($export_flag === 'true' || $export_flag === true || $export_flag === '1');
                        ?>
                        <input type="text" 
                            name="invoice_export_flag_display" 
                            value="<?php echo $is_exported ? 'TRUE' : 'FALSE'; ?>" 
                            readonly 
                            style="background: <?php echo $is_exported ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $is_exported ? '#155724' : '#721c24'; ?>; font-weight: bold; border: 1px solid <?php echo $is_exported ? '#c3e6cb' : '#f5c6cb'; ?>;">
                        
                        
                        <!-- Hidden field to preserve actual value (not submitted in form) -->
                        <input type="hidden" name="invoice_export_flag_preserved" value="<?php echo esc_attr($export_flag); ?>">
                    </div>

                    <hr style="margin: 15px 0; border: none; border-top: 1px solid #e0e0e0;">

                    <!-- Action Buttons -->
                    <div class="oim-actions">
                        <button type="submit" name="save" value="1" class="button button-primary button-large">Save Changes</button>
                        
                        <button type="submit" 
                                name="issue_invoice" 
                                value="1" 
                                class="button button-secondary"
                                style="background: #ff9800; border-color: #ff9800; color: #fff;">
                            Issue Invoice
                        </button>

                        
                        <button type="submit" name="oim_edit_invoice_submit_send" value="1" class="button button-secondary">
                            Save & Send Email
                        </button>

                        <button type="submit" name="export_invoice" value="1" class="button button-secondary"
                                style="background: #2196F3; border-color: #2196F3; color: #fff;">
                            Export Invoice
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </form>
</div>

<style>
/* CSS for new elements only */
.oim-select {
    width: 100%;
}

.oim-field input[readonly] {
    cursor: not-allowed;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Form validation feedback
    $('.oim-form').on('submit', function() {
        $(this).find('input, textarea').each(function() {
            if ($(this).prop('required') && !$(this).val()) {
                $(this).css('border-color', '#dc3232');
            }
        });
    });
    
    // Remove error styling
    $('input, textarea').on('input change', function() {
        $(this).css('border-color', '#ddd');
    });

    // Update status badge color based on selection
    $('select[name="invoice_status"]').on('change', function() {
        var status = $(this).val();
        var colors = {
            'paid': '#4CAF50',
            'unpaid': '#f44336',
            'partial': '#ff9800',
            'overdue': '#9c27b0'
        };
        if (colors[status]) {
            $(this).css('border-left', '3px solid ' + colors[status]);
        }
    }).trigger('change');
});
</script>
<?php
}




    /***************************************************************************
     * Save invoice (called from admin-post.php?action=oim_save_invoice)
     * - Stores invoice data in oim_orders.data
     * - Deletes old pdf and regenerates PDF via OIM_Invoice::generate_pdf_for_invoice_html()
     * - If Save & Send clicked, sends email afterwards
     ***************************************************************************/
    public function save_invoice() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_die('Missing invoice ID');
    
    if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'oim_edit_invoice')) {
        wp_die('Security check failed.');
    }
    
    global $wpdb;
    $table_invoices = $wpdb->prefix . 'oim_invoices'; // ✅ CHANGED
    
    // Get invoice record
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_invoices} WHERE id = %d", 
        $id
    )); // ✅ CHANGED
    
    if (!$invoice) wp_die('Invoice not found.');
    
    // Get existing data, merge with posted invoice fields
    $existing = maybe_unserialize($invoice->data ?? '');
    if (!is_array($existing)) {
        $existing = json_decode($invoice->data ?? '{}', true) ?? [];
    }
    
    $posted = $_POST['invoice'] ?? [];
    
    // Sanitize posted invoice fields
    $clean = [];
    foreach ($posted as $k => $v) {
        if (stripos($k, 'email') !== false) {
            $clean[$k] = sanitize_email($v);
        } else {
            $clean[$k] = is_string($v) ? sanitize_text_field($v) : $v;
        }
    }
    
    $new_data = array_merge($existing, $clean);
    
    // Update invoice table
    $wpdb->update(
        $table_invoices,
        [
            'data' => maybe_serialize($new_data),
            'invoice_number' => $new_data['invoice_number'] ?? $invoice->invoice_number
        ],
        ['id' => $id],
        ['%s', '%s'],
        ['%d']
    ); // ✅ CHANGED
    
    // Regenerate PDF: remove old files first
    $internal_order_id = $new_data['internal_order_id'] ?? ($new_data['order_id'] ?? $id);
    $this->cleanup_invoice_files($internal_order_id);
    
    // Build HTML and generate PDF
    if (!class_exists('OIM_Invoice')) {        
        echo '<div class="error"><p>'.  urlencode('PDF generation class missing') .'</p></div>';
        exit;
    }
    
    $html = OIM_Invoice::build_invoice_html($id, $new_data);
    $pdf_result = OIM_Invoice::generate_pdf_for_invoice_html($html, $internal_order_id);
    
    if (isset($pdf_result['error'])) {        
        echo '<div class="error"><p>'. urlencode($pdf_result['error']) .'</p></div>';
        exit;
    }
    
    $pdf_url = $pdf_result['url'] ?? '';
    $pdf_path = $pdf_result['path'] ?? '';
    
    // Update PDF URL in invoice record
    if (!empty($pdf_url)) {
        $wpdb->update(
            $table_invoices,
            ['pdf_url' => $pdf_url],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }
    
    // If Save & Send was requested, email now
    if (isset($_POST['save_send'])) {
        $this->send_invoice_email(true, $id, $new_data, ['url' => $pdf_url, 'path' => $pdf_path]);
    } else {
        wp_redirect(site_url('/oim-dashboard/invoices&saved=1'));
        exit;
    }
}

    /***************************************************************************
     * Send invoice email
     * - If called via admin-post (normal flow) $manual==false and we read order from DB
     * - If invoked programmatically from save_invoice() we pass $manual=true and provide $id,$order_data,$pdf
     ***************************************************************************/
    public function send_invoice_email($manual = false, $id = null, $order_data = null, $pdf = null) {
        if (!$manual) {
            // Called via admin-post.php?action=oim_send_invoice - accept both POST and GET
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) wp_die('Missing order ID');

            // Check nonce - accept both formats (specific and generic)
            $nonce = $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
            $nonce_valid = wp_verify_nonce($nonce, 'oim_send_invoice_' . $id) ||
                           wp_verify_nonce($nonce, 'oim_send_invoice');

            if (empty($nonce) || !$nonce_valid) {
                wp_die('Security check failed.');
            }

            global $wpdb;
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id = %d", $id));
            if (!$order) {
                wp_redirect(site_url('/oim-dashboard/invoices&error=Invoice not found'));
                exit;
            }
            $order_data = maybe_unserialize($order->data ?? '');
            if (!is_array($order_data)) $order_data = json_decode($order->data ?? '{}', true) ?? [];

            // Ensure PDF exists/generate
            $internal_order_id = $order_data['internal_order_id'] ?? ($order_data['order_id'] ?? $id);
            $html = OIM_Invoice::build_invoice_html($id, $order_data);
            $pdf = OIM_Invoice::generate_pdf_for_invoice_html($html, $internal_order_id);
            if (isset($pdf['error'])) {                
                echo '<div class="error"><p>'. urlencode($pdf['error']) .'</p></div>';
                exit;
            }
        }

        // Validate target email
        $to = $order_data['customer_email'] ?? $order_data['customer_company_email'] ?? '';
        if (empty($to) || !is_email($to)) {
            wp_redirect(site_url('/oim-dashboard/invoices&error=' . urlencode('No valid recipient email found')));
            exit;
        }

        $internal_order_id = $order_data['internal_order_id'] ?? ($order_data['order_id'] ?? $id);
        $pdf_url = $pdf['url'] ?? '';

        // Prepare replacements (add more placeholders here if needed)
        $replacements = [
            'order_id'     => $internal_order_id,
            'company_name' => $order_data['customer_company_name'] ?? $order_data['company_name'] ?? get_bloginfo('name'),
            'site_name'    => get_bloginfo('name')
        ];

        $subject = $this->get_email_option('subject', $replacements);
        $body_template = $this->get_email_option('email_body', $replacements);

        // Ensure body is HTML. Replace newline with <br> for nicer formatting
        $body_html = nl2br(esc_html($body_template));

        // Add PDF link/button
        if ($pdf_url) {
            $body_html .= '<p><a href="' . esc_url($pdf_url) . '" style="display:inline-block;padding:10px 15px;background:#4CAF50;color:#fff;text-decoration:none;border-radius:4px;">Download Invoice (PDF)</a></p>';
        }

        // Wrap message in container
        $message = '<html><body style="font-family:Arial,sans-serif;background:#f9f9f9;padding:20px;">
            <div style="background:#fff;padding:20px;border-radius:6px;max-width:680px;margin:auto;">
                ' . $body_html . '
                <p style="margin-top:18px;font-size:12px;color:#666;">This is an automated email. Please do not reply.</p>
            </div>
        </body></html>';

        // Send email as HTML
        add_filter('wp_mail_content_type', [$this, 'set_html_mail_content_type']);
        function custom_wp_mail_from_name( $name ) {
            return 'Aisdatys';
        }
        add_filter( 'wp_mail_from_name', 'custom_wp_mail_from_name' );
        $headers = [];
        if ($from_email = get_option('oim_company_email')) {
            $headers[] = 'From: ' . get_bloginfo('name') . ' <' . $from_email . '>';
        }
        $sent = wp_mail($to, $subject, $message);
        remove_filter('wp_mail_content_type', [$this, 'set_html_mail_content_type']);

        if ($sent) {
            // Log/store invoice record (if desired)
            $this->store_invoice_record($id, $internal_order_id, $pdf_url, $order_data);

            // Log send action
            $this->log_invoice_send($id, $to);

            wp_redirect(site_url('/oim-dashboard/invoices') . '?sent=1');
            exit;
        } else {
            wp_redirect(add_query_arg('OIM_error', urlencode('Email sending failed. Check mail configuration.'), wp_get_referer()));
            
            exit;
        }
    }

    public function set_html_mail_content_type() {
        return 'text/html';
    }

    /***************************************************************************
     * Download TXT fallback
     ***************************************************************************/
    public function download_invoice_txt($invoice_id = 0) {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = intval($invoice_id);
        if (!$id) wp_die('Missing invoice ID');

        // Check nonce - accept both formats (specific and generic)
        $nonce = $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
        $nonce_valid = wp_verify_nonce($nonce, 'oim_download_invoice_' . $id) ||
                    wp_verify_nonce($nonce, 'oim_download_invoice');

        // if (empty($nonce) || !$nonce_valid) {
        //     wp_die('Security check failed.');
        // }
        
        global $wpdb;
        // ✅ FIX: Query from oim_invoices table
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}oim_invoices WHERE id = %d", 
            $id
        ));
        
        if (!$invoice) wp_die('Invoice not found.');
        
        $invoice_data = maybe_unserialize($invoice->data ?? '');
        if (!is_array($invoice_data)) {
            $invoice_data = json_decode($invoice->data ?? '{}', true) ?? [];
        }
        
        $text = '';
        
        // Use build_invoice_text if available
        if (class_exists('OIM_Invoice') && method_exists('OIM_Invoice', 'build_invoice_text')) {
            $text = OIM_Invoice::build_invoice_text($id, $invoice_data);
        }
        
        // Fallback if method doesn't exist
        if (empty($text)) {
            $internal_order_id = $invoice_data['internal_order_id'] ?? $invoice->id;
            $text .= "INVOICE\n========\n\n";
            $text .= "Invoice Number: " . ($invoice_data['invoice_number'] ?? 'N/A') . "\n";
            $text .= "Order ID: " . $internal_order_id . "\n\n";
            
            foreach ($invoice_data as $k => $v) {
                if (!is_array($v) && $v !== '') {
                    $text .= ucfirst(str_replace('_', ' ', $k)) . ': ' . $v . "\n";
                }
            }
        }
        
        $internal_order_id = $invoice_data['internal_order_id'] ?? $invoice->id;
        $filename = 'invoice-' . sanitize_title($internal_order_id) . '.txt';
        
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($text));
        echo $text;
    exit;
}

    /***************************************************************************
     * Delete invoice (cleans generated files + removes order row)
     ***************************************************************************/
    public function delete_invoice() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) wp_die('Missing invoice ID');

    $nonce = $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
    $nonce_valid = wp_verify_nonce($nonce, 'oim_delete_invoice_' . $id) || wp_verify_nonce($nonce, 'oim_delete_invoice');
    if (empty($nonce) || !$nonce_valid) wp_die('Security check failed.');

    global $wpdb;
    $table = $wpdb->prefix . 'oim_invoices';

    $deleted = $wpdb->delete($table, ['id' => $id]);

    // Redirect to dashboard invoices page
    if ($deleted) {
        wp_safe_redirect(site_url('/oim-dashboard/invoices/?deleted=1'));
        exit;
    } else {
        wp_safe_redirect(site_url('/oim-dashboard/invoices/?error=Failed+to+delete'));
        exit;
    }
}


    /***************************************************************************
     * Bulk actions (send/download/delete) - kept semantics from earlier version
     ***************************************************************************/
    public function handle_bulk_actions() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    // Verify nonce
    if (empty($_POST['_wpnonce']) || ! wp_verify_nonce($_POST['_wpnonce'], 'oim_bulk_action_nonce')) {
        wp_die('Security check failed.');
    }

    global $wpdb;
    $table_invoices = $wpdb->prefix . 'oim_invoices';

    // Get posted data
    $action    = sanitize_text_field($_POST['bulk_action'] ?? '');
    $order_ids = array_map('intval', $_POST['order_ids'] ?? []);

    if (empty($action) || empty($order_ids)) {
        wp_redirect(site_url('/oim-dashboard/invoices&error=' . urlencode('No action or invoices selected')));
        exit;
    }

    switch ($action) {

        case 'send_pdf':
            // Redirect to sending handler for the first invoice (you can extend to loop if you have a send API)
            $first_id = $order_ids[0];
            wp_redirect(admin_url('admin-post.php?action=oim_send_invoice&id=' . $first_id . '&_wpnonce=' . wp_create_nonce('oim_send_invoice_' . $first_id)));
            exit;

        case 'download_txt':
            // Directly generate and stream a TXT for selected invoices (no redirect)
            // This calls the export helper which sends headers and exits.
            $this->export_selected_invoices_as_txt($order_ids);
            // export_selected_invoices_as_txt() will call exit() after output.
            exit;

        case 'delete':
            $deleted_count = 0;
            foreach ($order_ids as $oid) {
                // fetch invoice record
                $invoice = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_invoices} WHERE id = %d", $oid));

                if ($invoice) {
                    // parse data (serialized or json)
                    $order_data = maybe_unserialize($invoice->data ?? '');
                    if (!is_array($order_data)) {
                        $order_data = json_decode($invoice->data ?? '{}', true) ?? [];
                    }

                    $internal_order_id = $order_data['internal_order_id'] ?? '';

                    // cleanup attached files (if your cleanup expects internal_order_id)
                    if (!empty($internal_order_id)) {
                        $this->cleanup_invoice_files($internal_order_id);
                    }

                    // delete invoice row from invoices table
                    $deleted = $wpdb->delete($table_invoices, ['id' => $oid], ['%d']);
                    if ($deleted !== false) {
                        $deleted_count++;
                    }
                }
            }

            wp_redirect(site_url('/oim-dashboard/invoices&deleted=' . intval($deleted_count)));
            exit;

        default:
            wp_redirect(site_url('/oim-dashboard/invoices&error=' . urlencode('Unknown action')));
            exit;
    }
}



    private function export_selected_invoices_as_txt($ids = []) {
    if (empty($ids) || !is_array($ids)) {
        wp_die('No invoices selected for export.');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'oim_invoices';

    // Securely build placeholders for prepared query
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $prepared = $wpdb->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id DESC", $ids);

    $invoices = $wpdb->get_results($prepared);

    if (!$invoices) {
        wp_die('No invoices found for selected IDs.');
    }

    // Build content
    $content = '';
    foreach ($invoices as $invoice) {
        // parse data
        $data = maybe_unserialize($invoice->data ?? '');
        if (!is_array($data)) {
            $data = json_decode($invoice->data ?? '{}', true) ?? [];
        }

        // ensure core fields present
        $data['invoice_number']    = $data['invoice_number'] ?? ($invoice->invoice_number ?? 'N/A');
        $data['internal_order_id'] = $data['internal_order_id'] ?? ($invoice->internal_order_id ?? 'N/A');
        $data['created_at']        = $data['created_at'] ?? ($invoice->created_at ?? 'N/A');

        $content .= "==============================\n";
        $content .= "Invoice ID: " . ($data['invoice_number'] ?: 'N/A') . "\n";
        $content .= "Internal ID: " . ($data['internal_order_id'] ?: 'N/A') . "\n";
        $content .= "Date: " . ($data['created_at'] ?: 'N/A') . "\n";
        $content .= "------------------------------\n";

        foreach ($data as $key => $value) {
            if (in_array($key, ['invoice_number', 'internal_order_id', 'created_at'])) continue;

            $label = ucwords(str_replace('_', ' ', $key));

            if (is_array($value)) {
                // flatten nested arrays (attachments etc.)
                $content .= "{$label}: " . implode(', ', array_map('strval', $value)) . "\n";
            } else {
                $content .= "{$label}: {$value}\n";
            }
        }

        $content .= "==============================\n\n";
    }

    // Send headers + output
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="selected_invoices.txt"');
    echo $content;
    exit;
}


public function handle_export_all_txt() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    global $wpdb;
    $table_invoices = $wpdb->prefix . 'oim_invoices';
    $invoices = $wpdb->get_results("SELECT * FROM {$table_invoices} ORDER BY id DESC");

    if (empty($invoices)) {
        wp_die('No invoices found.');
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="all_invoices.txt"');

    foreach ($invoices as $invoice) {
        // Extract data column (serialized or JSON)
        $invoice_data = maybe_unserialize($invoice->data ?? '');
        if (!is_array($invoice_data)) {
            $invoice_data = json_decode($invoice->data ?? '{}', true) ?: [];
        }

        // Add missing fields if not present
        $invoice_data['invoice_number']    = $invoice_data['invoice_number'] ?? ($invoice->invoice_number ?? 'N/A');
        $invoice_data['internal_order_id'] = $invoice_data['internal_order_id'] ?? ($invoice->internal_order_id ?? 'N/A');

        // Build invoice text using your build_invoice_text function
        if (method_exists($this, 'build_invoice_text')) {
            $invoice_text = OIM_Invoice::build_invoice_text($invoice->id, $invoice_data);
        } else {
            $invoice_text = "Invoice ID: {$invoice_data['invoice_number']} / Order ID: {$invoice_data['internal_order_id']}\n";
        }
        $current_user_obj = wp_get_current_user();
        $current_user = $current_user_obj->display_name ?: 'AUTOMAT';
        $loading_city        = $invoice_data['loading_city'] ?? '—';
        $vat_id =       $invoice_data['vat_id'] ?? '—';
        $loading_country     = $invoice_data['loading_country'] ?? '—';
        $unloading_city      = $invoice_data['unloading_city'] ?? '—';
        $unloading_country   = $invoice_data['unloading_country'] ?? '—';
        $truck_number        = $invoice_data['truck_number'] ?? '—';
        $loading_date        = $invoice_data['loading_date'] ?? '—';
        $unloading_date      = $invoice_data['unloading_date'] ?? '—';
        $customer_order_nr   = $invoice_data['customer_order_number'] ?? '—';
        $internal_order_id   = $invoice_data['internal_order_id'] ?? '—';
        $invoice_export_date = $invoice_data['invoice_export_date'];
        $invoice_export_flag = $invoice_data['invoice_export_flag'];
        $vat_id_upper = strtoupper(trim($vat_id));
        $is_slovak_vat = (strpos($vat_id_upper, 'SK') === 0);

        // ✅ BUILD REVERSE CHARGE TEXT IF NOT SLOVAK VAT
        $reverse_charge_text = '';
        if (!$is_slovak_vat && $vat_id !== '—' && !empty($vat_id)) {
            $reverse_charge_text = "\nWithout VAT according to §15 of the VAT Act - reverse charge.";
        }


        // Build the multi-line text safely
        $invoice_text = <<<EOT
        We invoicing you for cargo transport {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
        with truck {$truck_number} date {$loading_date} - {$unloading_date} your order Nr. {$customer_order_nr}.
        → Fakturujeme Vám prepravu tovaru {$loading_city} /{$loading_country}/ - {$unloading_city} /{$unloading_country}/
        vozidlom {$truck_number} v dňoch {$loading_date} - {$unloading_date}, Vaša objednávka č. {$customer_order_nr}.{$reverse_charge_text}
        
        Our reference / Naša referencia: {$internal_order_id}
        Issued by / Vystavila: {$current_user}
        Telephone / Telefón: 00421 915 794 911
        E-mail: datys@datys.sk
        Web: www.datys.sk
        EOT;

        echo "==============================\n";
        echo $invoice_text . "\n";
        echo "==============================\n\n";

        // ✅ Update export flag and date in invoices table
        $export_timestamp = current_time('Y-m-d H:i:s');
        $invoice_data['invoice_export_flag'] = 'true';
        $invoice_data['invoice_export_date'] = $export_timestamp;

        $wpdb->update(
            $table_invoices,
            ['data' => maybe_serialize($invoice_data)],
            ['id' => $invoice->id],
            ['%s'],
            ['%d']
        );
    }

    exit;
}






    /***************************************************************************
     * Cleanup generated files helper
     ***************************************************************************/
    private function cleanup_invoice_files($internal_order_id) {
        if (!$internal_order_id) return;
        $upload_dir = wp_upload_dir();
        $invoice_dir = trailingslashit($upload_dir['basedir']) . 'oim_invoices';
        if (!file_exists($invoice_dir)) return;

        $safe_filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$internal_order_id);
        $files = [
            $invoice_dir . '/invoice-' . $safe_filename . '.pdf',
            $invoice_dir . '/invoice-' . $safe_filename . '.txt'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }



public function process_invoice($action = 'save') {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    
    $id = intval($_POST['id'] ?? ($_GET['id'] ?? 0));
    if (!$id) wp_die('Missing invoice ID');
    
    // Security check
    $nonce_action = ($action === 'send') ? 'oim_send_invoice_' . $id : 'oim_edit_invoice';
    $nonce_value = $_POST['_wpnonce'] ?? ($_GET['_wpnonce'] ?? '');
    
    if (empty($nonce_value) || !wp_verify_nonce($nonce_value, $nonce_action)) {
        wp_die('Security check failed.');
    }
    
    global $wpdb;
    $table_invoices = $wpdb->prefix . 'oim_invoices'; // ✅ CHANGED
    
    // Get invoice record
    $invoice = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_invoices} WHERE id = %d", 
        $id
    )); // ✅ CHANGED
    
    if (!$invoice) wp_die('Invoice not found.');
    
    // Merge posted data
    $existing = maybe_unserialize($invoice->data ?? '') ?: [];
    if (!is_array($existing)) {
        $existing = json_decode($invoice->data ?? '{}', true) ?? [];
    }
    
    $posted = $_POST['invoice'] ?? [];
    $clean = [];
    
    foreach ($posted as $k => $v) {
        $clean[$k] = (stripos($k, 'email') !== false) ? sanitize_email($v) : sanitize_text_field($v);
    }
    
    $new_data = array_merge($existing, $clean);
    
    // Update invoice table
    $wpdb->update(
        $table_invoices,
        [
            'data' => maybe_serialize($new_data),
            'invoice_number' => $new_data['invoice_number'] ?? $invoice->invoice_number
        ],
        ['id' => $id],
        ['%s', '%s'],
        ['%d']
    ); // ✅ CHANGED
    
    // Regenerate PDF
    $internal_order_id = $new_data['internal_order_id'] ?? ($new_data['order_id'] ?? $id);
    $this->cleanup_invoice_files($internal_order_id);
    
    if (!class_exists('OIM_Invoice')) {
        wp_redirect(site_url('/oim-dashboard/invoices&error=' . urlencode('PDF generation missing')));
        exit;
    }
    
    $html = OIM_Invoice::build_invoice_html($id, $new_data);
    $pdf = OIM_Invoice::generate_pdf_for_invoice_html($html, $internal_order_id);
    
    if (isset($pdf['error'])) {
        echo '<div class="error"><p>'. urlencode($pdf['error']) .'</p></div>';
        
        exit;
    }
    
    // Update PDF URL in invoice
    if (!empty($pdf['url'])) {
        $wpdb->update(
            $table_invoices,
            ['pdf_url' => $pdf['url']],
            ['id' => $id],
            ['%s'],
            ['%d']
        );
    }
    
    // ✅ Send email if required
    if ($action === 'send') {
        $to = $new_data['customer_email'] ?? $new_data['customer_company_email'] ?? '';
        
        if (empty($to) || !is_email($to)) {
            wp_redirect(site_url('/oim-dashboard/invoices&error=' . urlencode('No valid recipient email found')));
            exit;
        }
        
        $replacements = [
            'order_id' => $internal_order_id,
            'company_name' => $new_data['customer_company_name'] ?? get_bloginfo('name'),
            'site_name' => get_bloginfo('name')
        ];
        
        $subject = $this->get_email_option('subject', $replacements);
        $body_tpl = $this->get_email_option('email_body', $replacements);
        $body_html = nl2br(esc_html($body_tpl));
        $body_html .= '<p><a href="' . esc_url($pdf['url']) . '" style="padding:10px 15px;background:#4CAF50;color:#fff;text-decoration:none;border-radius:4px;">Download Invoice (PDF)</a></p>';
        
        $message = '<html><body style="font-family:Arial,sans-serif;">
            <div style="background:#fff;padding:20px;border-radius:6px;max-width:680px;margin:auto;">'
            . $body_html .
            '<p style="margin-top:18px;font-size:12px;color:#666;">This is an automated email. Do not reply.</p>
            </div></body></html>';
        
        wp_mail($to, $subject, $message, [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->email_options['from_name'] . ' <' . $this->email_options['from_email'] . '>'
        ]);
        
        wp_redirect(site_url('/oim-dashboard/invoices&sent=1'));
        exit;
    }
    
    // Otherwise just saved
    wp_redirect(site_url('/oim-dashboard/invoices&saved=1'));
    exit;
}

// ✅ HELPER: No longer needed, but keeping for reference
// This was storing a duplicate record - you're already updating the invoice directly now
private function store_invoice_record($order_id, $internal_order_id, $pdf_url, $data) {
    // This function is now redundant since we update the invoice directly
    // But keeping it in case you have other uses
    global $wpdb;
    $table = $wpdb->prefix . 'oim_invoices';
    
    // Check if invoice already exists for this order
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE order_id = %d",
        $order_id
    ));
    
    if ($existing) {
        // Update existing
        $wpdb->update(
            $table,
            [
                'invoice_number' => $data['invoice_number'] ?? 'INV-' . $internal_order_id,
                'pdf_url' => $pdf_url,
                'data' => maybe_serialize($data)
            ],
            ['id' => $existing],
            ['%s', '%s', '%s'],
            ['%d']
        );
    } else {
        // Insert new
        $wpdb->insert(
            $table,
            [
                'order_id' => $order_id,
                'invoice_number' => $data['invoice_number'] ?? 'INV-' . $internal_order_id,
                'pdf_url' => $pdf_url,
                'data' => maybe_serialize($data),
                'approved' => 0,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }
}

function oim_get_order_logs() {
    check_ajax_referer('oim_get_order_logs');

    $order_id = intval($_POST['order_id']);
    global $wpdb;
    $logs_table = $wpdb->prefix . 'oim_logs'; // your actual log table name

    $logs = $wpdb->get_results($wpdb->prepare("
        SELECT log_date AS date, log_status AS status, log_message AS message
        FROM $logs_table
        WHERE order_id = %d
        ORDER BY log_date DESC
    ", $order_id));

    wp_send_json_success($logs);
}


}

/* instantiate */
new OIM_Invoices();
