<?php
// includes/class-oim-db.php
if (! defined('ABSPATH')) exit;

class OIM_DB {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $orders_table = $wpdb->prefix . 'oim_orders';
        $docs_table   = $wpdb->prefix . 'oim_order_documents';
        $invoices_table = $wpdb->prefix . 'oim_invoices';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // orders
        $sql = "CREATE TABLE {$orders_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            internal_order_id VARCHAR(50) NOT NULL,
            token VARCHAR(80) DEFAULT '',
            data LONGTEXT NOT NULL,
            attachments LONGTEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY internal_order_id (internal_order_id),
            KEY token (token)
        ) {$charset};";
        dbDelta($sql);

        // documents
        $sql = "CREATE TABLE {$docs_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            filename VARCHAR(255) NOT NULL,
            mime VARCHAR(100),
            file_url TEXT,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};";
        dbDelta($sql);

        // invoices
        $sql = "CREATE TABLE {$invoices_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            invoice_number VARCHAR(100),
            data LONGTEXT,
            pdf_url TEXT,
            approved TINYINT(1) DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};";
        dbDelta($sql);

        // flush rewrite rules just to be safe
        flush_rewrite_rules();
    }
    public static function get_logs_by_invoice_id($invoice_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'oim_send_logs';

    $logs = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name WHERE invoice_id = %d ORDER BY id DESC",
            $invoice_id
        ),
        ARRAY_A
    );

    // Format logs for frontend readability
    foreach ($logs as &$log) {
        $log['date'] = isset($log['sent_at']) ? date('Y-m-d H:i:s', strtotime($log['sent_at'])) : '-';
        $log['status'] = 'Sent'; // static, since you log only successful sends
        $log['message'] = 'Invoice sent by ' . ($log['sender_name'] ?? 'Unknown');
    }

    return $logs;
}


    public static function insert_order($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        
        // ✅ Generate or use existing internal_order_id
        $internal = isset($data['internal_order_id']) && $data['internal_order_id'] 
            ? sanitize_text_field($data['internal_order_id']) 
            : self::generate_internal_order_id();
        
        // ✅ Generate invoice_number with INV prefix if not provided
        if (empty($data['invoice_number'])) {
            $data['invoice_number'] = 'INV' . $internal;
        }
        
        $token = wp_generate_password(24, false, false);
        $attachments = '';
        if (isset($data['attachments'])) {
            $attachments = is_array($data['attachments']) ? wp_json_encode($data['attachments']) : $data['attachments'];
        }
        
        $order_data = $data;
        unset($order_data['internal_order_id'], $order_data['attachments'], $order_data['created_at']);
        
        $row = [
            'internal_order_id' => $internal,
            'token' => $token,
            'data' => maybe_serialize($order_data),
            'attachments' => $attachments,
            'created_at' => isset($data['created_at']) ? $data['created_at'] : current_time('mysql')
        ];
        
        $result = $wpdb->insert($table, $row);
        if ($result === false) {
            error_log('Order insert failed! Error: ' . $wpdb->last_error);
            return false;
        }
        
        $order_id = $wpdb->insert_id;
        
        // ✅ Email notification section
        try {
            $company_email = get_option('oim_company_email', get_option('admin_email'));
            $driver_link = home_url('/oim-dashboard/driver-upload/' . $token . '/');
            $internal_order_id = $internal;
            $invoice_number = $data['invoice_number'];
            
            add_filter('wp_mail_content_type', function() { return 'text/html'; });
            
            $company_subject = 'New Order: ' . esc_html($internal_order_id);
            $company_message = '<html><body style="font-family:Arial,sans-serif;background:#f9f9f9;padding:20px;">
                <div style="background:#fff;padding:20px;border-radius:6px;max-width:680px;margin:auto;">
                    <h2 style="color:#4B0082;">New Order Received</h2>
                    <p><strong>Internal Order ID:</strong> ' . esc_html($internal_order_id) . '</p>
                    <p><strong>Invoice Number:</strong> ' . esc_html($invoice_number) . '</p>
                    <p><strong>Driver Link:</strong> <a href="' . esc_url($driver_link) . '" style="color:#4CAF50;">' . esc_html($driver_link) . '</a></p>
                    <table style="width:100%;border-collapse:collapse;margin-top:10px;">
                        <tr><th style="text-align:left;padding:6px 8px;border-bottom:1px solid #eee;">Field</th><th style="text-align:left;padding:6px 8px;border-bottom:1px solid #eee;">Value</th></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Customer Reference</th><td>' . esc_html($data['customer_reference'] ?? 'N/A') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Loading Date</th><td>' . esc_html($data['loading_date'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Loading City</th><td>' . esc_html($data['loading_city'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Loading Country</th><td>' . esc_html($data['loading_country'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Unloading Date</th><td>' . esc_html($data['unloading_date'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Unloading City</th><td>' . esc_html($data['unloading_city'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Unloading Country</th><td>' . esc_html($data['unloading_country'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Truck Number</th><td>' . esc_html($data['truck_number'] ?? '') . '</td></tr>
                        <tr><th style="text-align:left;padding:6px 8px;">Customer Price</th><td>' . esc_html($data['customer_price'] ?? '') . '</td></tr>
                    </table>
                    <p style="margin-top:18px;font-size:12px;color:#666;">This is an automated email. Please do not reply.</p>
                </div>
            </body></html>';
            
            wp_mail($company_email, $company_subject, $company_message);
            
            remove_filter('wp_mail_content_type', function() { return 'text/html'; });
        } catch (Exception $e) {
            error_log('Order email failed: ' . $e->getMessage());
        }
        
        return [
            'id' => $order_id,
            'token' => $token,
            'internal_order_id' => $internal,
            'invoice_number' => $data['invoice_number']
        ];
    }


    public static function get_order_by_id($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
    }

    public static function get_order_by_token($token) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE token=%s", $token), ARRAY_A);
    }

    public static function get_orders($limit = 200) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    }

    public static function update_order($id, $new_data_array) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        // fetch existing
        $existing = self::get_order_by_id($id);
        if (!$existing) return false;
        $data = maybe_unserialize($existing['data']);
        if (!is_array($data)) $data = [];
        // merge
        $data = array_merge($data, $new_data_array);
        $row = [
            'data' => maybe_serialize($data),
            'attachments' => isset($new_data_array['attachments']) ? wp_json_encode($new_data_array['attachments']) : $existing['attachments']
        ];
        return $wpdb->update($table, $row, ['id' => $id]);
    }

    public static function delete_order($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_orders';
        // delete associated documents physically and rows
        $docs = self::get_documents($id);
        foreach ($docs as $d) {
            if (!empty($d['file_url'])) {
                $basedir = wp_upload_dir()['basedir'];
                $baseurl = wp_upload_dir()['baseurl'];
                $file_path = str_replace($baseurl, $basedir, $d['file_url']);
                if (file_exists($file_path)) @unlink($file_path);
            }
            // delete row
            $wpdb->delete($wpdb->prefix . 'oim_order_documents', ['id' => $d['id']], ['%d']);
        }
        // delete invoices pdfs
        $inv_table = $wpdb->prefix . 'oim_invoices';
        $invoices = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$inv_table} WHERE order_id=%d", $id), ARRAY_A);
        foreach ($invoices as $inv) {
            if (!empty($inv['pdf_url'])) {
                $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $inv['pdf_url']);
                if (file_exists($file_path)) @unlink($file_path);
            }
            $wpdb->delete($inv_table, ['id' => $inv['id']], ['%d']);
        }
        // finally delete order row
        return $wpdb->delete($table, ['id' => $id], ['%d']);
    }
    public static function update_attachments($order_id, $attachments) {
    global $wpdb;
    $table = $wpdb->prefix . 'oim_orders';
    $wpdb->update(
        $table,
        ['attachments' => maybe_serialize($attachments)],
        ['id' => $order_id],
        ['%s'],
        ['%d']
    );
}


    public static function add_document($order_id, $filename, $mime, $url) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_order_documents';
        $wpdb->insert($table, [
            'order_id' => $order_id,
            'filename' => $filename,
            'mime' => $mime,
            'file_url' => $url,
            'created_at' => current_time('mysql')
        ]);
        return $wpdb->insert_id;
    }

    public static function get_documents($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_order_documents';
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE order_id=%d ORDER BY id DESC", $order_id), ARRAY_A);
    }

    public static function delete_document($doc_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'oim_order_documents';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $doc_id), ARRAY_A);
        if ($row) {
            // delete physical file
            if (!empty($row['file_url'])) {
                $file_path = str_replace(wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $row['file_url']);
                if (file_exists($file_path)) @unlink($file_path);
            }
            $wpdb->delete($table, ['id' => $doc_id], ['%d']);
        }
        return $row;
    }

    // In class-oim-db.php

public static function insert_invoice($invoice) {
    global $wpdb;
    $table = $wpdb->prefix . 'oim_invoices';
    
    // ✅ Add format specifiers for proper data typing and security
    $result = $wpdb->insert(
        $table, 
        $invoice,
        [
            '%d',  // order_id
            '%s',  // invoice_number
            '%s',  // data
            '%s',  // pdf_url
            '%d',  // approved
            '%s'   // created_at
        ]
    );
    
    // ✅ Add error logging if insert fails
    if ($result === false) {
        error_log('Invoice insert failed!');
        error_log('Error: ' . $wpdb->last_error);
        error_log('Query: ' . $wpdb->last_query);
        error_log('Data: ' . print_r($invoice, true));
        return false;
    }
    
    return $wpdb->insert_id;
}

    /**
     * Import Excel expecting headers that match (or normalize to) form input names.
     * Returns ['imported'=>N] or ['error'=>'...']
     */
   /**
 * FIXED: import_excel()
 * - Calculates VAT and totals when invoice is created
 * - Initializes all invoice-specific columns
 * - Includes company settings in invoice data
 */
private static function get_field_dictionary()
{
    return [
        'internal order id (automatically assigned by the system)' => 'internal_order_id',
        'internal order id' => 'internal_order_id',
        'customer – vat id' => 'vat_id',
        'Customer â€" VAT ID'  => 'vat_id',
        'customer - vat id' => 'vat_id',
        'Customer – VAT ID' => 'vat_id',
        'customer vat id' => 'vat_id',
        'vat id' => 'vat_id',
        'customer company name' => 'customer_company_name',
        'customer country' => 'customer_country',
        'customer price' => 'customer_price',
        'invoice number for this order' => 'invoice_number',
        'invoice number' => 'invoice_number',
        'invoice due date in days' => 'invoice_due_date_in_days',
        'loading date' => 'loading_date',
        'loading country' => 'loading_country',
        'loading zip code' => 'loading_zip',
        'loading zip' => 'loading_zip',
        'loading city' => 'loading_city',
        'loading name (loading company name)' => 'loading_company_name',
        'loading company name' => 'loading_company_name',
        'loading name' => 'loading_company_name',
        'unloading date' => 'unloading_date',
        'unloading country' => 'unloading_country',
        'unloading zip code' => 'unloading_zip',
        'unloading zip' => 'unloading_zip',
        'unloading city' => 'unloading_city',
        'unloading company name' => 'unloading_company_name',
        'customer reference (customer\'s order number)' => 'customer_reference',
        'customer reference (customers order number)' => 'customer_reference',
        'customer reference' => 'customer_reference',
        'customer e-mail' => 'customer_email',
        'customer email' => 'customer_email',
        'customer e mail' => 'customer_email',
        'customer email 2 (extra - for invoicing)' => 'customer_company_email',
        'customer email 2 (extra for invoicing)' => 'customer_company_email',
        'customer email 2' => 'customer_company_email',
        'customer company email' => 'customer_company_email',
        'customer phone' => 'customer_phone',
        'customer phone number (extra)' => 'customer_company_phone_number',
        'customer phone number' => 'customer_company_phone_number',
        'customer company phone number' => 'customer_company_phone_number',
        'customer adress' => 'customer_company_address',
        'customer address' => 'customer_company_address',
        'customer company address' => 'customer_company_address',
        'customer address extra (postal adress)' => 'customer_company_address',
        'customer address extra (postal address)' => 'customer_company_address',
        'customer address extra' => 'customer_company_address',
        'order note' => 'order_note',
        'truck number' => 'truck_number',
        'customer company id (ičo - crn)' => 'customer_company_ID_crn',
        'customer company id (ico - crn)' => 'customer_company_ID_crn',
        'customer company id (crn)' => 'customer_company_ID_crn',
        'customer company id' => 'customer_company_ID_crn',
        'customer tax id (dič)' => 'customer_tax_ID',
        'customer tax id (dic)' => 'customer_tax_ID',
        'customer tax id' => 'customer_tax_ID',
    ];
}

private static function match_header_to_field($header, $field_dictionary)
{
    $header_normalized = strtolower(trim($header));
    $header_normalized = preg_replace('/\s+/', ' ', $header_normalized);
    
    foreach ($field_dictionary as $pattern => $field_name) {
        $pattern_normalized = strtolower(trim($pattern));
        $pattern_normalized = preg_replace('/\s+/', ' ', $pattern_normalized);
        
        if ($header_normalized === $pattern_normalized) {
            return $field_name;
        }
        
        if (strpos($header_normalized, $pattern_normalized) !== false) {
            return $field_name;
        }
    }
    
    return null;
}

public static function import_excel($file_path)
{
    if (!file_exists($file_path)) {
        return ['error' => 'File not found'];
    }

    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        return ['error' => 'PhpSpreadsheet not installed.'];
    }

    global $wpdb;

    $imported = 0;
    $skipped = 0;
    $duplicates = 0;
    $invoices_created = 0;
    $imported_list = [];
    $skipped_list = [];
    $duplicate_list = [];

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 1) {
            return ['error' => 'No rows in sheet'];
        }

        $field_dictionary = self::get_field_dictionary();
        $firstRowKey = array_key_first($rows);
        $headerRow = $rows[$firstRowKey];
        $headers = [];

        foreach ($headerRow as $col => $val) {
            $h = trim((string)$val);
            $matched_field = self::match_header_to_field($h, $field_dictionary);
            
            if ($matched_field) {
                $headers[$col] = $matched_field;
            } else {
                $norm = strtolower($h);
                $norm = preg_replace('/[^a-z0-9]+/', '_', $norm);
                $norm = trim($norm, '_');
                $headers[$col] = $norm;
            }
        }

        $allowed = [
            'customer_reference', 'vat_id', 'customer_email', 'customer_company_name',
            'customer_country', 'customer_price', 'invoice_number', 'invoice_due_date_in_days',
            'customer_company_email', 'customer_company_phone_number', 'customer_company_address',
            'loading_company_name', 'loading_date', 'loading_country', 'loading_zip', 'loading_city',
            'unloading_company_name', 'unloading_date', 'unloading_country', 'unloading_zip', 'unloading_city',
            'order_note', 'truck_number' , 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
        ];

        $invoices_table = $wpdb->prefix . 'oim_invoices';
        $existing_invoices = $wpdb->get_col("SELECT invoice_number FROM {$invoices_table} WHERE invoice_number IS NOT NULL AND invoice_number != ''");

        $existing_invoice_numbers = [];
        foreach ($existing_invoices as $invoice_num) {
            $existing_invoice_numbers[strtolower(trim((string)$invoice_num))] = true;
        }

        $local_invoice_numbers = [];

        foreach ($rows as $rIndex => $row) {
            if ($rIndex == $firstRowKey) continue;

            $assoc = [];
            foreach ($headers as $col => $key) {
                $assoc[$key] = isset($row[$col]) ? trim((string)$row[$col]) : '';
            }

            $data = [];
            foreach ($allowed as $k) {
                if (isset($assoc[$k])) $data[$k] = sanitize_text_field($assoc[$k]);
            }

            $reference = $data['customer_reference'] ?? 'N/A';
            $invoice_raw = $data['invoice_number'] ?? '';
            $invoice = trim($invoice_raw);

            if ($invoice === '') {
                $temp_internal_id = self::generate_internal_order_id();
                $invoice = 'INV' . $temp_internal_id;
                $data['invoice_number'] = $invoice;
                $data['internal_order_id'] = $temp_internal_id;
            }

            $invoice_lc = strtolower($invoice);

            $invoice_exists_in_db = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$invoices_table} WHERE invoice_number = %s", $invoice));

            if ($invoice_exists_in_db > 0 || isset($local_invoice_numbers[$invoice_lc]) || isset($existing_invoice_numbers[$invoice_lc])) {
                $duplicates++;
                $duplicate_list[] = "{$reference} (Invoice: {$invoice})";
                $local_invoice_numbers[$invoice_lc] = true;
                continue;
            }

            $settings_keys = [
                'oim_company_email', 'oim_company_bank', 'oim_company_bic',
                'oim_payment_title', 'oim_company_account', 'oim_company_iban', 
                'oim_company_supplier', 'oim_headquarters', 'oim_crn', 'oim_tin',
                'oim_our_reference', 'oim_issued_by', 'oim_company_phone', 
                'oim_company_web', 'oim_invoice_currency', 'oim_percent_vat'
            ];
            
            foreach ($settings_keys as $key) {
                if (empty($data[$key])) {
                    $data[$key] = get_option($key, '');
                }
            }

            $data = OIM_Frontend::calculate_invoice_totals($data);
            $data['invoice_status'] = 'pending';
            $data['amount_paid'] = 0;
            $data['invoice_issue_date'] = '';
            $data['invoice_sent_date'] = '';
            $data['invoice_export_date'] = '';
            $data['invoice_export_flag'] = '';
            $data['oim_taxable_payment_date'] = '';
            $data['oim_total_price_to_be_paid'] = $data['oim_total_price'];

            if (!isset($data['internal_order_id'])) {
                $data['internal_order_id'] = self::generate_internal_order_id();
            }
            $data['created_at'] = current_time('mysql');

            $order_result = self::insert_order($data);

            if ($order_result && !empty($order_result['id'])) {
                $invoice_data = $data;
                unset($invoice_data['attachments']);

                $invoice_record = [
                    'order_id' => $order_result['id'],
                    'invoice_number' => $invoice,
                    'data' => maybe_serialize($invoice_data),
                    'pdf_url' => '',
                    'approved' => 0,
                    'created_at' => $data['created_at']
                ];

                $invoice_id = self::insert_invoice($invoice_record);

                if ($invoice_id) {
                    $invoices_created++;
                    $imported++;
                    $imported_list[] = "{$reference} (Invoice: {$invoice})";
                    $local_invoice_numbers[$invoice_lc] = true;
                    $existing_invoice_numbers[$invoice_lc] = true;
                } else {
                    $skipped++;
                    $skipped_list[] = "{$reference} (Invoice: {$invoice}) - Invoice creation failed";
                }
            } else {
                $skipped++;
                $skipped_list[] = "{$reference} (Invoice: {$invoice}) - Order creation failed";
            }
        }

        $report_lines = [];
        $report_lines[] = "✅ Imported: {$imported}";
        foreach ($imported_list as $s) $report_lines[] = " • " . $s;
        $report_lines[] = "⚠️ Duplicates Rejected: {$duplicates}";
        foreach ($duplicate_list as $s) $report_lines[] = " • " . $s;
        $report_lines[] = "⏭️ Skipped (missing or failed): {$skipped}";
        foreach ($skipped_list as $s) $report_lines[] = " • " . $s;

        return [
            'imported' => $imported,
            'imported_refs' => $imported_list,
            'skipped' => $skipped,
            'skipped_refs' => $skipped_list,
            'duplicates' => $duplicates,
            'duplicate_refs' => $duplicate_list,
            'invoices_created' => $invoices_created,
            'report' => nl2br(esc_html(implode("\n", $report_lines)))
        ];

    } catch (Exception $e) {
        error_log('Excel Import Exception: ' . $e->getMessage());
        return ['error' => 'Error reading Excel: ' . $e->getMessage()];
    }
}

    // helper
    public static function generate_internal_order_id() {
    global $wpdb;
    $prefix = date('y');
    
    $orders_table = $wpdb->prefix . 'oim_orders';
    $invoices_table = $wpdb->prefix . 'oim_invoices';
    
    // Get all internal_order_ids from orders table
    $order_ids = $wpdb->get_col("SELECT internal_order_id FROM {$orders_table} WHERE internal_order_id IS NOT NULL");
    
    // Get all internal_order_ids from invoices data field
    $invoices_data = $wpdb->get_col("SELECT data FROM {$invoices_table} WHERE data IS NOT NULL AND data != ''");
    $invoice_order_ids = [];
    
    foreach ($invoices_data as $serialized_data) {
        $data = maybe_unserialize($serialized_data);
        if (is_array($data) && isset($data['internal_order_id']) && !empty($data['internal_order_id'])) {
            $invoice_order_ids[] = $data['internal_order_id'];
        }
    }
    
    // Combine all IDs from both tables
    $all_ids = array_merge($order_ids, $invoice_order_ids);
    
    $next = 1;
    
    // Find the highest existing ID with current year prefix
    foreach ($all_ids as $id) {
        if (strpos($id, $prefix) === 0) {
            $num = substr($id, strlen($prefix));
            if (is_numeric($num)) {
                $current_num = intval($num);
                if ($current_num >= $next) {
                    $next = $current_num + 1;
                }
            }
        }
    }
    
    return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
}
}
