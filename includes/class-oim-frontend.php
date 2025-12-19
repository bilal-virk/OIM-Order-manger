<?php
// includes/class-oim-frontend.php
if (! defined('ABSPATH')) exit;

class OIM_Frontend {

    public static function init() {
        add_shortcode('order_form', [__CLASS__, 'render_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_oim_handle_upload', [__CLASS__, 'handle_ajax_upload']);


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
    //$dsriver_link = home_url('/oim-dashboard/driver-upload/' . $token . '/');
    $driver_link = home_url('/oim/track/' . $token . '/');

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
        'order_id' => $data['internal_order_id'],
        'invoice_number' => $invoice_number, // ✅ Use the invoice_number from insert_order
        'data' => maybe_serialize($data),
        'attachments' => !empty($data['attachments']) ? wp_json_encode($data['attachments']) : '',
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
        //$driver_link = home_url('/oim-dashboard/driver-upload/' . $token . '/');
        $driver_link = home_url('/oim/track/' . $token . '/');
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
        'attachments' => !empty($data['attachments']) ? wp_json_encode($data['attachments']) : '',
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
<title>Upload Documents for Order ' . esc_html($order['internal_order_id']) . '</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">';
    wp_head();
    echo '
</head>
<body>
<div class="oim-container">
';
echo '<div class="oim-header">
    <h2><i class="fas fa-file-invoice"></i>Upload Documents for Order ' . esc_html($order['internal_order_id']) . '</h2>
   
</div>
 <div class="order-info">
        <div class="order-item">
            <span class="label">Loading Date</span>
            <span class="value">' . esc_html($data['loading_date']) . '</span>
        </div>
        <div class="order-item">
            <span class="label">Unloading Date</span>
            <span class="value">' . esc_html($data['unloading_date']) . '</span>
        </div>
        <div class="order-item">
            <span class="label">Loading City</span>
            <span class="value">' . esc_html($data['loading_city']) . '</span>
        </div>
        <div class="order-item">
            <span class="label">Unloading City</span>
            <span class="value">' . esc_html($data['unloading_city']) . '</span>
        </div>
    </div>';

if (isset($_GET['uploaded'])) {
    echo '<div class="oim-successd"><i class="fas fa-check-circle"></i> File(s) uploaded successfully!</div>';
}



echo '<div class="upload-section">
<h3><i class="fas fa-cloud-upload-alt"></i>Upload New Documents</h3>

<form method="post" enctype="multipart/form-data" id="driver-upload-form">';
wp_nonce_field('oim_driver_upload_' . $token);
echo '
<div class="upload-area" id="upload-area">
    <div class="upload-icon">
        <i class="fas fa-cloud-upload-alt"></i>
    </div>
    <div class="upload-text">
        <h4>Click to upload or drag and drop</h4>
        <p>PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, ZIP (Max 10MB per file)</p>
    </div>
    <button type="button" id="add-file-btn">
        <i class="fas fa-plus-circle"></i> Select Files
    </button>
</div>

<div id="file-inputs">
  <div class="file-group">
    <input type="file" name="driver_docs[]" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.txt,.rtf" multiple>
  </div>
</div>

<ul id="file-preview"></ul>

<div class="submit-container" style="display: none;" id="submit-container">
    <button type="submit" id="submit-btn"><i class="fas fa-upload"></i> Upload All Files</button>
</div>
</form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
  const fileInputs = document.getElementById("file-inputs");
  const addBtn = document.getElementById("add-file-btn");
  const preview = document.getElementById("file-preview");
  const uploadArea = document.getElementById("upload-area");
  const submitContainer = document.getElementById("submit-container");
  const submitBtn = document.getElementById("submit-btn");
  const mainFileInput = fileInputs.querySelector("input[type=file]");
  
  let selectedFiles = [];

  function formatFileSize(bytes) {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const sizes = ["Bytes", "KB", "MB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + " " + sizes[i];
  }

  function getFileIcon(filename) {
    const ext = filename.split(".").pop().toLowerCase();
    const iconMap = {
      "jpg": "fa-file-image", "jpeg": "fa-file-image", "png": "fa-file-image", 
      "gif": "fa-file-image", "webp": "fa-file-image",
      "pdf": "fa-file-pdf",
      "doc": "fa-file-word", "docx": "fa-file-word",
      "xls": "fa-file-excel", "xlsx": "fa-file-excel", "csv": "fa-file-excel",
      "zip": "fa-file-archive", "rar": "fa-file-archive", "7z": "fa-file-archive",
      "txt": "fa-file-alt", "rtf": "fa-file-alt"
    };
    return iconMap[ext] || "fa-file";
  }

  function refreshPreview() {
    preview.innerHTML = "";
    
    if (selectedFiles.length === 0) {
      submitContainer.style.display = "none";
      return;
    }

    submitContainer.style.display = "flex";
    
    selectedFiles.forEach((file, index) => {
      const li = document.createElement("li");
      
      const fileInfo = document.createElement("div");
      fileInfo.className = "file-info";
      
      const fileIcon = document.createElement("div");
      fileIcon.className = "file-icon";
      fileIcon.innerHTML = `<i class="fas ${getFileIcon(file.name)}"></i>`;
      
      const fileDetails = document.createElement("div");
      fileDetails.className = "file-details";
      
      const fileName = document.createElement("span");
      fileName.className = "file-name";
      fileName.textContent = file.name;
      
      const fileSize = document.createElement("span");
      fileSize.className = "file-size";
      fileSize.textContent = formatFileSize(file.size);
      
      fileDetails.appendChild(fileName);
      fileDetails.appendChild(fileSize);
      
      fileInfo.appendChild(fileIcon);
      fileInfo.appendChild(fileDetails);
      
      const removeBtn = document.createElement("button");
      removeBtn.type = "button";
      removeBtn.innerHTML = `<i class="fas fa-trash"></i> Remove`;
      removeBtn.onclick = () => {
        selectedFiles.splice(index, 1);
        refreshPreview();
      };
      
      li.appendChild(fileInfo);
      li.appendChild(removeBtn);
      preview.appendChild(li);
    });
  }

  function handleFiles(files) {
    for (let file of files) {
      if (!selectedFiles.some(f => f.name === file.name && f.size === file.size)) {
        selectedFiles.push(file);
      }
    }
    refreshPreview();
  }

  addBtn.addEventListener("click", () => {
    mainFileInput.click();
  });

  mainFileInput.addEventListener("change", (e) => {
    if (e.target.files.length > 0) {
      handleFiles(e.target.files);
    }
  });

  // Drag and drop functionality
  ["dragenter", "dragover", "dragleave", "drop"].forEach(eventName => {
    uploadArea.addEventListener(eventName, (e) => {
      e.preventDefault();
      e.stopPropagation();
    });
  });

  ["dragenter", "dragover"].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => {
      uploadArea.classList.add("drag-over");
    });
  });

  ["dragleave", "drop"].forEach(eventName => {
    uploadArea.addEventListener(eventName, () => {
      uploadArea.classList.remove("drag-over");
    });
  });

  uploadArea.addEventListener("drop", (e) => {
    const files = e.dataTransfer.files;
    if (files.length > 0) {
      handleFiles(files);
    }
  });

  // Form submission
  document.getElementById("driver-upload-form").addEventListener("submit", (e) => {
    if (selectedFiles.length === 0) {
      e.preventDefault();
      alert("Please select at least one file to upload.");
      return;
    }

    // Create a new DataTransfer object to hold our files
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => {
      dataTransfer.items.add(file);
    });
    
    // Update the file input with selected files
    mainFileInput.files = dataTransfer.files;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = \'<i class="fas fa-spinner fa-spin"></i> Uploading...\';
  });
});
</script>
';

$docs = OIM_DB::get_documents($order['internal_order_id']);
if ($docs) {
    echo '<div class="documents-section">';
    echo '<h3><i class="fas fa-folder-open"></i>Uploaded Documents<span class="doc-count">' . count($docs) . '</span></h3>';
    echo '<ul class="oim-doc-list">';
    foreach ($docs as $d) {
        $ext = strtolower(pathinfo($d['filename'], PATHINFO_EXTENSION));
        $icon = 'fa-file';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $icon = 'fa-file-image';
        elseif ($ext === 'pdf') $icon = 'fa-file-pdf';
        elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
        elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) $icon = 'fa-file-excel';
        elseif (in_array($ext, ['zip', 'rar', '7z'])) $icon = 'fa-file-archive';
        
        echo '<li>
            <div class="doc-info">
                <div class="doc-icon"><i class="fas ' . $icon . '"></i></div>
                <span class="doc-name">' . esc_html($d['filename']) . '</span>
            </div>
            <a href="' . esc_url($d['file_url']) . '" target="_blank"><i class="fas fa-download"></i> Download</a>
        </li>';
    }
    echo '</ul>';
    echo '</div>';
} else {
    echo '<div class="documents-section">';
    echo '<div class="oim-empty-state">
        <i class="fas fa-inbox"></i>
        <p>No documents uploaded yet.</p>
    </div>';
    echo '</div>';
}

wp_footer();
echo '</div>
<footer>
    © ' . date('Y') . ' Order Management System. All rights reserved.
</footer>
</body></html>';
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
