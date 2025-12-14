<?php
// includes/class-oim-frontend.php
if (! defined('ABSPATH')) exit;

class OIM_Frontend {

    public static function init() {
        add_shortcode('order_form', [__CLASS__, 'render_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_oim_handle_upload', [__CLASS__, 'handle_ajax_upload']);
        add_action('wp_ajax_nopriv_oim_handle_upload', [__CLASS__, 'handle_ajax_upload']);

        // Fallback non-AJAX (regular POST)
        add_action('admin_post_nopriv_oim_frontend_submit', [__CLASS__, 'handle_submission_fallback']);
        add_action('admin_post_oim_frontend_submit', [__CLASS__, 'handle_submission_fallback']);

        // Driver link rewrite & handler
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_driver_page']);
    }

    public static function enqueue_assets() {
        wp_enqueue_style('oim-frontend-css', OIM_PLUGIN_URL . 'assets/oim-frontend.css', [], OIM_VERSION);
        wp_enqueue_script('oim-frontend-js', OIM_PLUGIN_URL . 'assets/oim-frontend.js', ['jquery'], OIM_VERSION, true);
        wp_localize_script('oim-frontend-js', 'oim_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'security' => wp_create_nonce('oim_ajax_nonce')
        ]);
    }

    public static function render_form() {
        ob_start();
        include OIM_PLUGIN_DIR . 'templates/frontend-form.php';
        return ob_get_clean();
    }

    // AJAX upload handler
    // AJAX upload handler
public static function handle_ajax_upload() {
    check_ajax_referer('oim_ajax_nonce', 'security');

    // Include wp_handle_upload for frontend
    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $allowed_fields = [
        'customer_reference','vat_id','customer_email','customer_company_name',
        'customer_country','customer_price','invoice_number','invoice_due_date_in_days',
        'customer_company_email','customer_company_phone_number','customer_company_address',
        'loading_company_name','loading_date','loading_country','loading_zip','loading_city',
        'unloading_company_name','unloading_date','unloading_country','unloading_zip','unloading_city', 'order_note', 'truck_number', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
    ];

    $data = [];
    foreach ($allowed_fields as $key) {
        $data[$key] = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
    }

    // ✅ Generate internal_order_id early (before invoice number check)
    $data['internal_order_id'] = OIM_DB::generate_internal_order_id();
    
    // ✅ Auto-generate invoice_number with INV prefix if missing
    if (empty($data['invoice_number']) || trim($data['invoice_number']) === '') {
        $data['invoice_number'] = 'INV' . $data['internal_order_id'];
    }

    // ✅ CHECK: Validate invoice number (now it will always have a value)
    if (!empty($data['invoice_number']) && trim($data['invoice_number']) !== '') {
        global $wpdb;
        $invoice_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}oim_invoices WHERE invoice_number = %s",
            sanitize_text_field($data['invoice_number'])
        ));
        
        if ($invoice_exists > 0) {
            wp_send_json_error('Invoice number already exists. Please use a different invoice number.');
            return;
        }
    }

    // Handle attachments
    $data['attachments'] = [];
    if (!empty($_FILES['attachments']['name'][0])) {
        $files = self::restructure_files_array($_FILES['attachments']);
        foreach ($files as $file) {
            $upload = wp_handle_upload($file, ['test_form' => false]);
            if (!empty($upload['url'])) {
                $data['attachments'][] = $upload['url'];
            } elseif (!empty($upload['error'])) {
                wp_send_json_error('File upload failed: ' . $upload['error']);
                return;
            }
        }
    }

    $data['created_at'] = current_time('mysql');

    try {
        $res = OIM_DB::insert_order($data);
        if (!$res || empty($res['id'])) {
            throw new Exception('Failed to create order');
        }
        $order_id = $res['id'];
        $token = $res['token'];
        $invoice_number = $res['invoice_number']; // ✅ Get invoice_number from insert_order response
    } catch (Exception $e) {
        wp_send_json_error('Failed to create order: ' . $e->getMessage());
        return;
    }

    // Driver link
    $driver_link = home_url('/oim-dashboard/driver-upload/' . $token . '/');

    // Build invoice HTML & PDF
    try {
        $invoice_html = OIM_Invoice::build_invoice_html($order_id, $data);
        $pdf_result = OIM_Invoice::generate_pdf_for_invoice_html($invoice_html, $data['internal_order_id']);
    } catch (Exception $e) {
        error_log('PDF generation failed: ' . $e->getMessage());
        $pdf_result = ['url' => ''];
    }

    // ✅ Insert invoice with the invoice_number from data (already set above)
    $invoice_row = [
        'order_id' => $order_id,
        'invoice_number' => $invoice_number, // ✅ Use the invoice_number from insert_order
        'data' => maybe_serialize($data),
        'pdf_url' => $pdf_result['url'] ?? '',
        'approved' => 0,
        'created_at' => current_time('mysql')
    ];
    
    $invoice_result = OIM_DB::insert_invoice($invoice_row);
    
    if (!$invoice_result) {
        wp_send_json_error('Failed to create invoice. Please try again.');
        return;
    }

    // Email company
    $company_email = get_option('oim_company_email', get_option('admin_email'));
    $internal_order_id = $data['internal_order_id'] ?? '';
    
    wp_send_json_success([
        'message' => 'Order created successfully!',
        'internal_order_id' => $data['internal_order_id'],
        'invoice_number' => $invoice_number, // ✅ Return invoice_number in response
        'driver_link' => $driver_link,
        'pdf_url' => $pdf_result['url'] ?? ''
    ]);
}
public static function calculate_invoice_totals($invoice_data) {
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

    // Fallback non-AJAX submission
    // Fallback non-AJAX submission
/**
 * FIXED: handle_submission_fallback()
 * Changes:
 * - Initializes export_flag as empty string (false)
 * - Calculates oim_total_price_to_be_paid
 * - Initializes oim_taxable_payment_date as empty
 */
public static function handle_submission_fallback() {
    if (empty($_POST)) {
        wp_redirect(home_url()); 
        exit;
    }
    
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'oim_frontend_nonce')) {
        $redirect = add_query_arg([
            'oim_error' => '1',
            'error_msg' => rawurlencode('Invalid submission. Security check failed.')
        ], wp_get_referer() ? wp_get_referer() : home_url());
        wp_safe_redirect($redirect);
        exit;
    }

    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    // Build data
    $allowed = [
        'customer_reference', 'vat_id', 'customer_email', 'customer_company_name',
        'customer_country', 'customer_price', 'invoice_number', 'invoice_due_date_in_days',
        'customer_company_email', 'customer_company_phone_number', 'customer_company_address',
        'loading_company_name', 'loading_date', 'loading_country', 'loading_zip', 'loading_city',
        'unloading_company_name', 'unloading_date', 'unloading_country', 'unloading_zip', 'unloading_city', 
        'order_note', 'truck_number', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
    ];

    $data = [];
    foreach ($allowed as $k) {
        $data[$k] = isset($_POST[$k]) ? sanitize_text_field(wp_unslash($_POST[$k])) : '';
    }

    // Validate invoice number
    if (!empty($data['invoice_number']) && trim($data['invoice_number']) !== '') {
        global $wpdb;
        $invoice_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}oim_invoices WHERE invoice_number = %s",
            sanitize_text_field($data['invoice_number'])
        ));
        
        if ($invoice_exists > 0) {
            $redirect = add_query_arg([
                'oim_error' => '1',
                'error_msg' => rawurlencode('Invoice number already exists.')
            ], wp_get_referer() ? wp_get_referer() : home_url());
            wp_safe_redirect($redirect);
            exit;
        }
    }

    // Include company settings
    $settings_keys = [
        'oim_company_email', 'oim_company_bank', 'oim_company_bic',
        'oim_payment_title', 'oim_company_account', 'oim_company_iban', 
        'oim_company_supplier', 'oim_headquarters', 'oim_crn', 'oim_tin',
        'oim_our_reference', 'oim_issued_by', 'oim_company_phone', 
        'oim_company_web', 'oim_invoice_currency', 'oim_percent_vat'
    ];
    
    foreach ($settings_keys as $key) {
        $data[$key] = get_option($key, '');
    }

    // ✅ CALCULATE INVOICE TOTALS ON CREATION
    $data = self::calculate_invoice_totals($data);

    // ✅ INITIALIZE INVOICE-SPECIFIC FIELDS
    $data['invoice_status'] = 'pending';
    $data['amount_paid'] = 0;
    $data['invoice_issue_date'] = ''; // Empty until issued
    $data['invoice_sent_date'] = ''; // Empty until sent
    $data['invoice_export_date'] = ''; // Empty until exported
    $data['invoice_export_flag'] = ''; // Empty (false) until email sent
    $data['oim_taxable_payment_date'] = ''; // Empty until invoice issued
    
    // ✅ CALCULATE REMAINING BALANCE (initially equals total since nothing paid)
    $data['oim_total_price_to_be_paid'] = $data['oim_total_price'];

    // Handle file uploads
    $saved_urls = [];
    if (!empty($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $files = self::restructure_files_array($_FILES['attachments']);
        foreach ($files as $file) {
            $upload = wp_handle_upload($file, ['test_form' => false]);
            if (!empty($upload['url'])) {
                $saved_urls[] = $upload['url'];
            } elseif (!empty($upload['error'])) {
                $redirect = add_query_arg([
                    'oim_error' => '1',
                    'error_msg' => rawurlencode('File upload failed: ' . $upload['error'])
                ], wp_get_referer() ? wp_get_referer() : home_url());
                wp_safe_redirect($redirect);
                exit;
            }
        }
        if ($saved_urls) {
            $data['attachments'] = $saved_urls;
        }
    }

    // Generate order ID
    $data['internal_order_id'] = OIM_DB::generate_internal_order_id();
    $data['created_at'] = current_time('mysql');

    // Insert order
    try {
        $res = OIM_DB::insert_order($data);
        if (!$res || empty($res['id'])) {
            throw new Exception('Failed to create order');
        }
        $order_id = $res['id'];
        $token = $res['token'];
        $driver_link = home_url('/oim-dashboard/driver-upload/' . $token . '/');
    } catch (Exception $e) {
        $redirect = add_query_arg([
            'oim_error' => '1',
            'error_msg' => rawurlencode('Failed to create order: ' . $e->getMessage())
        ], wp_get_referer() ? wp_get_referer() : home_url());
        wp_safe_redirect($redirect);
        exit;
    }

    // Generate PDF
    try {
        $invoice_html = OIM_Invoice::build_invoice_html($order_id, $data);
        $pdf_result = OIM_Invoice::generate_pdf_for_invoice_html($invoice_html, $data['internal_order_id']);
    } catch (Exception $e) {
        error_log('PDF generation failed: ' . $e->getMessage());
        $pdf_result = ['url' => ''];
    }

    // Create invoice with calculated data
    $invoice_number = !empty($data['invoice_number']) && trim($data['invoice_number']) !== '' 
        ? sanitize_text_field($data['invoice_number']) 
        : 'INV-' . $data['internal_order_id'];
    
    $invoice_row = [
        'order_id' => $order_id,
        'invoice_number' => $invoice_number,
        'data' => maybe_serialize($data), // Includes all calculated values
        'pdf_url' => $pdf_result['url'] ?? '',
        'approved' => 0,
        'created_at' => current_time('mysql')
    ];
    
    $result = $wpdb->insert(
        $wpdb->prefix . 'oim_invoices',
        $invoice_row,
        ['%d', '%s', '%s', '%s', '%d', '%s']
    );
    
    if ($result === false) {
        error_log('Invoice insert FAILED: ' . $wpdb->last_error);
        $redirect = add_query_arg([
            'oim_error' => '1',
            'error_msg' => rawurlencode('Failed to create invoice.')
        ], wp_get_referer() ? wp_get_referer() : home_url());
        wp_safe_redirect($redirect);
        exit;
    }

    $invoice_id = $wpdb->insert_id;

    // Send emails
    $company_email = get_option('oim_company_email', get_option('admin_email'));
    wp_mail($company_email, 'New Order: ' . $data['internal_order_id'], "Order created. Driver link: {$driver_link}");
    
    if (!empty($data['customer_email'])) {
        wp_mail($data['customer_email'], 'Order Received: ' . $data['internal_order_id'], 'Thank you. Order ID: ' . $data['internal_order_id']);
    }

    // Success redirect
    $redirect = add_query_arg([
        'oim_created' => '1',
        'order_id' => rawurlencode($data['internal_order_id']),
        'pdf' => rawurlencode($pdf_result['url'] ?? ''),
        'link' => rawurlencode($driver_link)
    ], wp_get_referer() ? wp_get_referer() : home_url());
    
    wp_safe_redirect($redirect);
    exit;
}


    // Rewrite rule for driver link
    public static function add_rewrite_rules() {
        add_rewrite_rule('^oim/track/([^/]+)/?$', 'index.php?oim_token=$matches[1]', 'top');
    }

    public static function query_vars($vars) {
        $vars[] = 'oim_token';
        return $vars;
    }

    // Driver upload page
    public static function maybe_handle_driver_page() {
    $token = get_query_var('oim_token');
    if (!$token) return;

    // Include wp_handle_upload
    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $order = OIM_DB::get_order_by_token($token);
    if (!$order) {
        wp_die('Invalid link.');
    }

    // Handle file upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['driver_docs']['name'][0])) {
        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'oim_driver_upload_' . $token)) {
            wp_die('Invalid upload.');
        }

        $files = self::restructure_files_array($_FILES['driver_docs']);
        $uploaded_files = [];

        // Process each uploaded file
        foreach ($files as $file) {
            $upload = wp_handle_upload($file, ['test_form' => false]);
            if (!empty($upload['url'])) {
                $uploaded_files[] = [
                    'url' => $upload['url'],
                    'mime' => $upload['type']
                ];
            }
        }

        // Save all uploaded files to database
        foreach ($uploaded_files as $file) {
            OIM_DB::add_document($order['internal_order_id'], basename($file['url']), $file['mime'], $file['url']);
        }

        wp_safe_redirect(add_query_arg('uploaded', '1', home_url('/oim/track/' . $token . '/')));
        exit;
    }

    // Render the page
    $data = unserialize($order['data']);
    status_header(200);
    nocache_headers();

    echo '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Upload Documents for Order ' . esc_html($order['internal_order_id']) . '</title>';
    wp_head();
    echo '<style>
:root {
    --primary-color: #5b67f1;
    --success-bg: #e6ffe6;
    --success-border: #b3e6b3;
    --card-bg: #fff;
    --border-color: #ddd;
    --text-color: #333;
    --radius: 10px;
}
body {
    font-family: "Segoe UI", Roboto, Arial, sans-serif;
    background: #f7f8fa;
    color: var(--text-color);
    margin: 0;
    padding: 40px 20px;
}
.oim-container {
    max-width: 700px;
    margin: 0 auto;
    background: var(--card-bg);
    padding: 30px 40px;
    border-radius: var(--radius);
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}
.oim-header {
    text-align: center;
    margin-bottom: 25px;
}
.oim-header h2 {
    margin: 0;
    color: var(--primary-color);
    font-weight: 600;
}
.oim-success {
    background: var(--success-bg);
    border: 1px solid var(--success-border);
    padding: 10px 14px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    text-align: center;
    font-size: 15px;
}
form {
    margin-top: 15px;
    text-align: center;
}
input[type="file"] {
    display: block;
    margin: 15px auto;
    padding: 8px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    width: 100%;
    max-width: 400px;
    background: #fafafa;
}
button[type="submit"], #add-file-btn {
    background: var(--primary-color);
    color: #fff;
    border: none;
    padding: 10px 24px;
    border-radius: var(--radius);
    cursor: pointer;
    font-size: 15px;
    transition: background 0.2s ease-in-out;
}
button[type="submit"]:hover, #add-file-btn:hover {
    background: #4c56d1;
}
#add-file-btn {
    display: inline-block;
    margin-bottom: 15px;
}
#file-preview {
    list-style: none;
    padding: 0;
    margin-top: 10px;
}
#file-preview li {
    background: #fafafa;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 8px 10px;
    margin-bottom: 5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
#file-preview button {
    background: #ff4c4c;
    border: none;
    color: #fff;
    padding: 2px 8px;
    border-radius: 4px;
    cursor: pointer;
}
#file-preview button:hover {
    background: #d12c2c;
}
.oim-doc-list {
    list-style: none;
    padding: 0;
    margin: 25px 0 0;
}
.oim-doc-list li {
    background: #fafafa;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 10px 15px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.oim-doc-list a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: 500;
}
.oim-doc-list a:hover {
    text-decoration: underline;
}
.oim-header h2 {
    font-size: 22px;
    color: #333;
    margin-bottom: 18px;
    text-align: center;
}
.oim-header .order-id {
    color: #4f46e5;
    font-weight: 600;
}
.order-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px 24px;
    margin-top: 10px;
}
.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 14px;
    transition: all 0.3s ease;
}
.order-item:hover {
    background: #f1f5ff;
    border-color: #4f46e5;
}
.order-item .label {
    font-weight: 600;
    color: #555;
}
.order-item .value {
    color: #222;
}
@media (max-width: 600px) {
    .order-info {
        grid-template-columns: 1fr;
    }
}
footer {
    text-align: center;
    margin-top: 30px;
    color: #888;
    font-size: 13px;
}
</style>
</head>
<body>
<div class="oim-container">
<div class="oim-headerss">
    <h2>Upload Documents for Order ' . esc_html($order['internal_order_id']) . '</h2>
    <div class="order-item">
        <span class="label">Loading Date:</span>
        <span class="value">' . esc_html($data['loading_date']) . '</span>
    </div>
    <div class="order-item">
        <span class="label">Unloading Date:</span>
        <span class="value">' . esc_html($data['unloading_date']) . '</span>
    </div>
    <div class="order-item">
        <span class="label">Loading City:</span>
        <span class="value">' . esc_html($data['loading_city']) . '</span>
    </div>
    <div class="order-item">
        <span class="label">Unloading City:</span>
        <span class="value">' . esc_html($data['unloading_city']) . '</span>
    </div>
</div>';

if (isset($_GET['uploaded'])) {
    echo '<div class="oim-success">File(s) uploaded successfully.</div>';
}

echo '<form method="post" enctype="multipart/form-data" id="driver-upload-form">';
wp_nonce_field('oim_driver_upload_' . $token);
echo '
<div id="file-inputs">
  <div class="file-group">
    <input type="file" name="driver_docs[]" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.txt,.rtf" required>
  </div>
</div>
<button type="button" id="add-file-btn">+ Add another file</button>
<ul id="file-preview"></ul>
<button type="submit" style="margin-top:15px;">Upload</button>
</form>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const fileInputs = document.getElementById("file-inputs");
  const addBtn = document.getElementById("add-file-btn");
  const preview = document.getElementById("file-preview");

  function refreshPreview() {
    preview.innerHTML = "";
    const files = document.querySelectorAll("input[type=file]");
    files.forEach((input) => {
      const val = input.files[0] ? input.files[0].name : "";
      if (val) {
        const li = document.createElement("li");
        li.textContent = val + " ";
        const removeBtn = document.createElement("button");
        removeBtn.textContent = "Remove";
        removeBtn.type = "button";
        removeBtn.onclick = () => {
          input.parentElement.remove();
          refreshPreview();
        };
        li.appendChild(removeBtn);
        preview.appendChild(li);
      }
    });
  }

  addBtn.addEventListener("click", () => {
    const div = document.createElement("div");
    div.classList.add("file-group");
    const input = document.createElement("input");
    input.type = "file";
    input.name = "driver_docs[]";
    input.accept = ".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.txt,.rtf";
    input.addEventListener("change", refreshPreview);
    div.appendChild(input);
    fileInputs.appendChild(div);
  });

  fileInputs.addEventListener("change", refreshPreview);
});
</script>
';

$docs = OIM_DB::get_documents($order['internal_order_id']);
if ($docs) {
    echo '<h3 style="margin-top:30px;">Uploaded Documents</h3>';
    echo '<ul class="oim-doc-list">';
    foreach ($docs as $d) {
        echo '<li><span>' . esc_html($d['filename']) . '</span> <a href="' . esc_url($d['file_url']) . '" target="_blank">Download</a></li>';
    }
    echo '</ul>';
} else {
    echo '<p style="text-align:center;margin-top:30px;color:#777;">No documents uploaded yet.</p>';
}

wp_footer();
echo '</div></body></html>';
exit;
}



    // Helper to restructure $_FILES array
    private static function restructure_files_array($files) {
        $out = [];
        $count = count($files['name']);
        $keys = array_keys($files);
        for ($i = 0; $i < $count; $i++) {
            foreach ($keys as $k) {
                $out[$i][$k] = $files[$k][$i];
            }
        }
        return $out;
    }
}
