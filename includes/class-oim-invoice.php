<?php
// includes/class-oim-invoice.php

use Dompdf\Dompdf;
use Dompdf\Options;

if (!defined('ABSPATH')) exit;

class OIM_Invoice {

    private static $allowed_fields = [
        'customer_reference', 'vat_id', 'customer_email', 'customer_company_name',
        'customer_country', 'customer_price', 'invoice_number', 'invoice_due_date_in_days',
        'customer_company_email', 'customer_company_phone_number', 'customer_company_address',
        'loading_company_name', 'loading_date', 'loading_country', 'loading_zip', 'loading_city',
        'unloading_company_name', 'unloading_date', 'unloading_country', 'unloading_zip', 'unloading_city',
        'internal_order_id', 'order_note', 'truck_number', 'customer_phone', 'customer_company_ID_crn', 'customer_tax_ID', 'invoice_due_date'
    ];

    private static $field_labels = [
                'internal_order_id' => 'Internal order ID (automatically assigned by the system)',
                'vat_id' => 'Customer - VAT ID',
                'customer_company_name' => 'Customer company name',
                'customer_country' => 'Customer country',
                'customer_price' => 'Customer price',
                'invoice_number' => 'Invoice number for this order',
                'invoice_due_date_in_days' => 'Invoice due date in days',

                'loading_date' => 'Loading date',
                'loading_country' => 'Loading country',
                'loading_zip' => 'Loading ZIP code',
                'loading_city' => 'Loading city',
                'loading_company_name' => 'Loading name (loading company name)',

                'unloading_date' => 'Unloading date',
                'unloading_country' => 'Unloading country',
                'unloading_zip' => 'Unloading ZIP code',
                'unloading_city' => 'Unloading city',
                'unloading_company_name' => 'Unloading company name',

                'customer_reference' => 'Customer reference (customer’s order number)',
                'customer_email' => 'Customer e-mail',
                'customer_company_email' => 'Customer Email 2 (extra - for invoicing)',
                'customer_phone' => 'Customer Phone',
                'customer_company_phone_number' => 'Customer Phone Number (extra)',

                // Only one address field exists in allowed list, so it maps to the postal address
                'customer_company_address' => 'Customer Address extra (postal adress)',

                'order_note' => 'Order Note',
                'truck_number' => 'Truck number',

                'customer_company_ID_crn' => 'Customer Company ID (IČO - CRN)',
                'customer_tax_ID' => 'Customer Tax ID (DIČ)'
            ];


    public static function init() {
        // Hook for cleanup if needed - removed for now to prevent the error
        // add_action('wp_loaded', [__CLASS__, 'maybe_cleanup_old_files']);
    }

    /**
     * Sanitize filename
     */
    private static function sanitize_filename($name) {
        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $name);
    }

    /**
     * Get upload directory for invoices
     */
    private static function get_upload_dir() {
        $upload_dir = wp_upload_dir();
        $invoice_dir = $upload_dir['basedir'] . '/oim_invoices';
        
        if (!file_exists($invoice_dir)) {
            wp_mkdir_p($invoice_dir);
            
            // Add security file
            self::add_directory_protection($invoice_dir);
        }
        
        return $invoice_dir;
    }

    /**
     * Add directory protection
     */
    private static function add_directory_protection($dir) {
        $htaccess_file = $dir . '/.htaccess';
        $index_file = $dir . '/index.html';
        
        // Add .htaccess to block direct access
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "Order deny,allow\nDeny from all\n");
        }
        
        // Add blank index file
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body><p>Directory access is forbidden.</p></body></html>');
        }
    }

    /**
     * Format field value for display
     */
    private static function format_field_value($value) {
        if (is_array($value)) {
            return implode(', ', array_filter($value));
        }
        
        if (is_numeric($value) && strpos($value, '.') !== false) {
            return number_format(floatval($value), 2);
        }
        
        if (strtotime($value) !== false) {
            return date('Y-m-d H:i:s', strtotime($value));
        }
        
        return $value;
    }

    /**
     * Get field label
     */
    private static function get_field_label($field_key) {
        return self::$field_labels[$field_key] ?? ucwords(str_replace('_', ' ', $field_key));
    }

    /**
     * Build invoice HTML with improved styling
     */
/**
 * Build invoice HTML with new design matching the uploaded invoice
 */
public static function build_invoice_html($order_id, $order_data, $driver_documents = null) {
    if (is_string($order_data)) {
        $order_data = json_decode($order_data, true);
    }
    if (!is_array($order_data)) $order_data = [];
    
    // ✅ FETCH DRIVER DOCUMENTS IF NOT PROVIDED
    if ($driver_documents === null) {
        $internal_order_id = $order_data['internal_order_id'] ?? '';
        if (!empty($internal_order_id)) {
            $driver_documents = OIM_Invoices::get_documents_by_order_id($internal_order_id);
            error_log('Auto-fetching documents for order: ' . $internal_order_id . ' - Found: ' . count($driver_documents));
        } else {
            $driver_documents = [];
            error_log('No internal_order_id found, skipping document fetch');
        }
    }
    
    // ✅ FILTER ONLY IMAGES FROM DOCUMENTS - Using correct column name 'mime'
    $driver_images = array_filter($driver_documents, function($doc) {
        return isset($doc['mime']) && strpos($doc['mime'], 'image/') === 0;
    });
    
    error_log('Processing invoice with ' . count($driver_images) . ' driver images');
    
    // List of all settings keys
    $settings_keys = [
    'oim_company_email',
    'oim_api_key',
    'oim_company_bank',
    'oim_company_bic',
    'oim_payment_title',
    'oim_tin',
    'oim_company_account',
    'oim_company_iban',
    'oim_crn',
    'oim_company_supplier',
    'oim_headquarters',
    'oim_invoice_currency',
    'oim_our_reference',
    'oim_company_phone',
    'oim_percent_vat',
    'oim_company_web'
];

    
    // Use invoice-specific settings first, fallback to global settings
    $settings = [];
    foreach ($settings_keys as $key) {
        $settings[$key] = $order_data[$key] ?? get_option($key, '');
    }
    
    // Calculate dates
    
    $current_user_obj = wp_get_current_user();
    $current_user = $current_user_obj->display_name ?: 'AUTOMAT';
    $loading_city        = $order_data['loading_city'] ?? '—';
    $vat_id =       $order_data['vat_id'] ?? '—';
    $loading_country     = $order_data['loading_country'] ?? '—';
    $unloading_city      = $order_data['unloading_city'] ?? '—';
    $unloading_country   = $order_data['unloading_country'] ?? '—';
    $truck_number        = $order_data['truck_number'] ?? '—';
    $loading_date        = $order_data['loading_date'] ?? '—';
    $unloading_date      = $order_data['unloading_date'] ?? '—';
    $customer_order_nr   = $order_data['customer_order_number'] ?? '—';
    $internal_order_id   = $order_data['internal_order_id'] ?? '—';
    $vat_id_upper = strtoupper(trim($vat_id));
    $is_slovak_vat = (strpos($vat_id_upper, 'SK') === 0);

    // DEFAULT VAT percent (from settings or fallback to 23)
    $vat_percent = $settings['oim_percent_vat'] ?? 23;
    $vat_percent = 0;
    $reverse_charge_text = "\nWithout VAT according to §15 of the VAT Act - reverse charge.";
    if ($is_slovak_vat) {
        $vat_percent = 23;
        $reverse_charge_text = '';
    }
    $invoice_date = $order_data['created_at'] ?? current_time('Y-m-d');
    $due_days = intval($order_data['invoice_due_date_in_days'] ?? 30);
    $due_date = date('Y-m-d', strtotime($invoice_date . " + {$due_days} days"));
    $taxable_payment_date = !empty($order_data['loading_date']) ? $order_data['loading_date'] : $invoice_date;
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
    // Calculate price
    $price = floatval($order_data['oim_total_price'] ?? $settings['oim_total_price'] ?? 0);
    
    ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?php echo esc_html($order_data['invoice_number'] ?? $order_id); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            line-height: 1.3;
            color: #000;
        }
        
        .invoice-container {
            width: 100%;
            max-width: 190mm;
            margin: 0 auto;
            padding: 10mm;
        }
        
        .invoice-title {
            text-align: right;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 12px;
            letter-spacing: 2px;
        }
        
        .header-grid {
            width: 100%;
            border: 2px solid #000;
            margin-bottom: 8px;
            border-collapse: collapse;
        }
        
        .header-grid td {
            padding: 8px;
            vertical-align: top;
            font-size: 8px;
        }
        
        .header-grid td.wide {
            width: 60%;
            border-right: 1px solid #000;
        }
        
        .header-grid td.narrow {
            width: 40%;
        }
        
        .header-grid tr:first-child td {
            border-bottom: 1px solid #000;
        }
        
        .supplier-label {
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        
        .supplier-name {
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 6px;
        }
        
        .supplier-info p {
            margin: 1px 0;
            font-size: 8px;
        }
        
        .invoice-details-box table {
            width: 100%;
            font-size: 8px;
        }
        
        .invoice-details-box td {
            padding: 2px 4px;
        }
        
        .invoice-details-box td:first-child {
            width: 55%;
        }
        
        .customer-section {
            width: 100%;
            border: 2px solid #000;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        
        .customer-section td {
            padding: 8px;
            vertical-align: top;
            font-size: 8px;
        }
        
        .customer-section td:first-child {
            border-right: 1px solid #000;
        }
        
        .customer-section h3 {
            font-size: 9px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        
        .customer-section p {
            margin: 1px 0;
            font-size: 8px;
        }
        
        .transport-info {
            border: 2px solid #000;
            margin-bottom: 8px;
        }
        
        .transport-header {
            padding: 4px 8px;
            font-weight: bold;
            font-size: 8px;
            border-bottom: 1px solid #000;
        }
        
        .transport-content {
            padding: 6px 8px;
            font-size: 8px;
        }
        
        .services-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            border: 2px solid #000;
        }
        
        .services-table th {
            padding: 4px 8px;
            text-align: left;
            font-size: 8px;
            font-weight: bold;
            border-bottom: 1px solid #000;
        }
        
        .services-table td {
            padding: 6px 8px;
            font-size: 8px;
        }
        
        .services-table tr:first-child td {
            border-bottom: 1px solid #000;
        }
        
        .services-table td.amount {
            text-align: right;
            font-weight: normal;
        }
        
        .totals-section {
            border: 2px solid #000;
            margin-bottom: 8px;
        }
        
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .totals-table td {
            padding: 3px 8px;
            font-size: 8px;
        }
        
        .totals-table td:first-child {
            text-align: center;
            font-weight: bold;
            width: 15%;
        }
        .header-grid td.narrow table tr td {
    border-bottom: none !important;
}
        
        .totals-table td:nth-child(2) {
            text-align: right;
            width: 40%;
        }
        
        .totals-table td:nth-child(3) {
            text-align: right;
            width: 45%;
        }
        
        .totals-table tr.total-row {
            background: #f0f0f0;
        }
        
        .totals-table tr.total-row td {
            font-weight: bold;
            font-size: 9px;
        }
        
        .totals-table tr:last-child td {
            border-bottom: none;
        }
        
        .footer-info {
            border: 2px solid #000;
            padding: 8px;
            margin-bottom: 15px;
        }
        
        .footer-info p {
            margin: 2px 0;
            font-size: 8px;
        }
        
        .footer-signature {
    width: 100%;
    font-size: 8px;
    margin-top: 10px;
}

.signature-row {
    margin-bottom: 10px;
}



.signature-row p {
    margin: 0;
}

        
        .page-break {
            page-break-after: always;
        }
        
        .driver-documents {
            page-break-before: always;
            margin-top: 20px;
        }
        
        .driver-documents h2 {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 15px;
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
        }
        
        .document-item {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .document-caption {
            text-align: center;
            font-size: 10px;
            margin-bottom: 8px;
            font-weight: bold;
        }
        
        .document-image {
            width: 100%;
            max-width: 100%;
            height: auto;
            border: 1px solid #ccc;
        }
        
        .footer-note {
            text-align: center;
            font-size: 7px;
            margin-top: 15px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="invoice-container">
        <!-- Invoice Title -->
        <div class="invoice-title">INVOICE</div>
        
        <!-- Header Grid -->
        <table class="header-grid">
            <tr>
                <td class="wide">
                    <div class="supplier-label">Supplier:</div>
                    <div class="supplier-name"><?php echo esc_html($settings['oim_company_supplier'] ?: 'Your Company Name'); ?></div>
                    <div class="supplier-info">
                        <p><strong>Company Headquarters:</strong></p>
                        <p><?php echo nl2br(esc_html($settings['oim_headquarters'])); ?></p>
                        <p><strong>CRN:</strong> <?php echo nl2br(esc_html($settings['oim_crn'])); ?></p>
                        <?php if (!empty($order_data['supplier_vat'])): ?>
                        <p><strong>VAT:</strong> <?php echo esc_html($order_data['supplier_vat']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($order_data['supplier_vat_id'])): ?>
                        <p><strong>VAT ID:</strong> <?php echo esc_html($order_data['supplier_vat_id']); ?></p>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="narrow">
                    <div class="invoice-details-box">
                        <table>
                            <tr>
                                <td>Invoice No.:</td>
                                <td><strong><?php echo esc_html($order_data['invoice_number'] ?? 'INV-' . $order_id); ?></strong></td>
                            </tr>
                            <tr>
                                <td>Variable symbol:</td>
                                <td><strong><?php echo esc_html($order_data['invoice_number'] ?? $order_id); ?></strong></td>
                            </tr>
                            <tr>
                                <td>Order No.:</td>
                                <td><strong><?php echo esc_html($order_data['internal_order_id'] ?? $order_id); ?></strong></td>
                            </tr>
                            <tr>
                                <td>from:</td>
                                <td><strong><?php echo esc_html(date('d.m.Y', strtotime($invoice_date))); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="wide">
                    <div class="supplier-info">
                        <p><strong>Bank:</strong> <?php echo esc_html($settings['oim_company_bank']); ?></p>
                        <p><?php echo nl2br(esc_html($settings['oim_headquarters'])); ?></p>
                        <p><strong>BIC:</strong> <?php echo esc_html($settings['oim_company_bic']); ?></p>
                        <p><strong>Account:</strong> <?php echo esc_html($settings['oim_company_account']); ?></p>
                        <p><strong>IBAN:</strong> <?php echo esc_html($settings['oim_company_iban']); ?></p>
                    </div>
                </td>
                <td class="narrow">
                    <div class="invoice-details-box">
                        <table>
                            <tr>
                                <td>Customer Company ID:</td>
                                <td><strong><?php echo nl2br(esc_html($order_data['customer_company_ID_crn'])); ?></strong></td>
                            </tr>
                            <tr>
                                <td>Customer Tax ID:</td>
                                <td><strong><?php echo nl2br(esc_html($order_data['customer_tax_ID'])); ?></strong></td>
                            </tr>
                            <tr>
                                <td>VAT:</td>
                                <td><strong><?php echo esc_html($order_data['customer_vat'] ?? ''); ?></strong></td>
                            </tr>
                            <tr>
                                <td>VAT ID:</td>
                                <td><strong><?php echo esc_html($order_data['vat_id'] ?? ''); ?></strong></td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <!-- Customer Section -->
        <table class="customer-section">
            <tr>
                <td style="width: 50%;">
                    <h3>Customer:</h3>
                    <p><strong><?php echo esc_html($order_data['customer_company_name'] ?? 'N/A'); ?></strong></p>
                    <?php if (!empty($order_data['customer_company_address'])): ?>
                    <p><?php echo esc_html($order_data['customer_company_address']); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html($order_data['customer_city'] ?? ''); ?> <?php echo esc_html($order_data['customer_zip'] ?? ''); ?></p>
                    <p><?php echo esc_html($order_data['customer_country'] ?? ''); ?></p>
                </td>
                <td style="width: 50%;">
                    <table style="width: 100%;">
                        <tr>
                            <td style="padding: 2px 0;">Maturity date:</td>
                            <td style="padding: 2px 0;"><strong><?php echo esc_html(date('d.m.Y', strtotime($due_date))); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;">Issued date:</td>
                            <td style="padding: 2px 0;"><strong><?php echo esc_html(date('d.m.Y', strtotime($invoice_date))); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;">Invoice sending date:</td>
                            <td style="padding: 2px 0;"><strong><?php echo esc_html(date('d.m.Y', strtotime($invoice_date))); ?></strong></td>
                        </tr>
                        <tr>
                            <td style="padding: 2px 0;">Taxable payment:</td>
                            <td style="padding: 2px 0;"><strong><?php echo esc_html(date('d.m.Y', strtotime($taxable_payment_date))); ?></strong></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Transport/Mode Info -->
        <div class="transport-info">
            <div class="transport-header">
                Mode of transport: <?php echo esc_html($order_data['mode_of_transport'] ?? 'Road Transport'); ?>
            </div>
            <div class="transport-content">
                <strong>Dest. place:</strong> <?php echo esc_html($order_data['unloading_city'] ?? ''); ?>, <?php echo esc_html($order_data['unloading_country'] ?? ''); ?><br>
                <strong>Consignee:</strong> <?php echo esc_html($order_data['unloading_company_name'] ?? ''); ?>
            </div>
        </div>

        <!-- Services Table -->
        <table class="services-table">
            <thead>
                <tr>
                    <th>Designation of delivery (goods - services)</th>
                    <th style="width: 15%; text-align: center;">VAT (%)</th>
                    <th style="width: 20%; text-align: right;">Price without VAT</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php echo esc_html($invoice_text); ?>.
                        <br><br>
                        <strong>with amount of</strong>
                    </td>
                    <td class="amount"><?php echo esc_html($vat_percent); ?> %</td>
                    <td class="amount"><?php echo number_format($price, 2, '.', ' '); ?> <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?></td>
                </tr>
                <tr>
                    <td colspan="3">
                        <strong>Total price to be paid:</strong>
                        <div style="text-align: right; font-size: 11px; font-weight: bold; margin-top: 4px;">
                            <?php echo number_format($price, 2, '.', ' '); ?> <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="totals-section">
            <table class="totals-table">
                <tr>
                    <td>% VAT</td>
                    <td>Amount</td>
                    <td>VAT</td>
                </tr>
                <tr>
                    <td>0 %</td>
                    <td><?php echo number_format($price, 2, '.', ' '); ?> <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?></td>
                    <td></td>
                </tr>
                <tr>
                    <td>10 %</td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>23 %</td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Round</td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td>Inadvance</td>
                    <td></td>
                    <td></td>
                </tr>
                <tr class="total-row">
                    <td>Total price</td>
                    <td><?php echo number_format($price, 2, '.', ' '); ?> <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?></td>
                    <td>0.00 <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?></td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" style="text-align: center;">
                        <strong>Total price</strong>
                        <span style="float: right;"><?php echo number_format($price, 2, '.', ' '); ?> <?php echo esc_html($settings['oim_invoice_currency'] ?? '€'); ?></span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Footer Info -->
        <div class="footer-info">
            <p><strong>Attachment:</strong></p>
            <p><?php echo esc_html($order_data['order_note'] ?? ''); ?></p>
            <br>
            <p><?php echo esc_html($reverse_charge_text ?? ''); ?></p>
        </div>

        <!-- Footer Signature -->
        <div class="footer-signature">

    <div class="signature-row">
        
        <p><strong>Our reference:</strong> <?php echo esc_html($settings['oim_our_reference'] ?? ''); ?></p>
    </div>

    <div class="signature-row">
        
        <p><strong>Issued by:</strong> <?php echo esc_html($order_data['oim_issued_by'] ?? ''); ?></p>
    </div>

    <div class="signature-row">
        
        <p><strong>Signature:</strong></p>
    </div>

    <div class="signature-row">
        
        <p><strong>Telephone:</strong> <?php echo esc_html($settings['oim_company_phone'] ?? ''); ?></p>
    </div>

    <div class="signature-row">
        
        <p><strong>Email:</strong> <?php echo esc_html($settings['oim_company_email'] ?? ''); ?></p>
    </div>

    <div class="signature-row">
        
        <p><strong>Web:</strong> <?php echo esc_html($settings['oim_company_web'] ?? ''); ?></p>
    </div>

</div>
    </div>

    <?php if (!empty($driver_images)): ?>
<!-- Driver Documents Section - Each document on separate page -->
<?php 
$doc_count = 1;
foreach ($driver_images as $doc): 
    $file_url = $doc['file_url'] ?? '';
    
    if (!empty($file_url)):
        // Convert local path to URL if needed
        if (strpos($file_url, 'http') !== 0) {
            $upload_base = wp_upload_dir();
            $file_url = str_replace($upload_base['basedir'], $upload_base['baseurl'], $file_url);
        }
?>
<div style="page-break-before: always; padding: 10mm;">
    <div style="text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #000;">
        DRIVER DOCUMENT <?php echo $doc_count; ?> 
    </div>
    <div style="text-align: center; font-size: 10px; margin-bottom: 10px; font-weight: bold;">
        <?php echo esc_html($doc['filename'] ?? 'Driver Document'); ?>
    </div>
    <div style="text-align: center;">
        <img src="<?php echo esc_url($file_url); ?>" 
             alt="Driver Document <?php echo $doc_count; ?>" 
             style="max-width: 100%; height: auto; border: 1px solid #ccc;">
    </div>
</div>
<?php 
    $doc_count++;
    endif;
endforeach; 
?>
<?php endif; ?>

</body>
</html>
<?php
    return ob_get_clean();
}
/**
 * Generate PDF invoice with driver documents
 */
/**
 * Generate PDF invoice with driver documents - FIXED VERSION
 */
public static function generate_pdf_for_invoice_html($html, $internal_order_id) {
    if (!class_exists('\Dompdf\Dompdf')) {
        return ['error' => 'PDF library (Dompdf) not available.'];
    }

    $upload_dir = self::get_upload_dir();
    $safe_filename = self::sanitize_filename($internal_order_id);
    $filename = 'invoice-' . $safe_filename . '.pdf';
    $filepath = $upload_dir . '/' . $filename;

    try {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        
        // ✅ FIXED: Removed base_path() - not needed for WordPress
        // WordPress handles paths differently
        $options->set('tempDir', sys_get_temp_dir());
        
        // Enable image support
        $options->set('isPhpEnabled', false);
        $options->set('debugPng', false);
        $options->set('debugKeepTemp', false);
        $options->set('debugCss', false);
        $options->set('debugLayout', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Save PDF file
        file_put_contents($filepath, $dompdf->output());
        
        // Verify file was created
        if (!file_exists($filepath)) {
            return ['error' => 'Failed to create PDF file.'];
        }

        $upload_base = wp_upload_dir();
        $fileurl = str_replace($upload_base['basedir'], $upload_base['baseurl'], $filepath);

        return [
            'path' => $filepath,
            'url' => $fileurl,
            'filename' => $filename,
            'size' => filesize($filepath)
        ];

    } catch (Exception $e) {
        error_log('PDF Generation Error: ' . $e->getMessage());
        echo '<div class="error"><p> PDF generation failed: '.  $e->getMessage() .'</p></div>';
        
    }
}

/**
 * Generate text file invoice (keep existing functionality)
 */
public static function generate_text_file_for_invoice($text, $internal_order_id) {
    $upload_dir = self::get_upload_dir();
    $safe_filename = self::sanitize_filename($internal_order_id);
    $filename = 'invoice-' . $safe_filename . '.txt';
    $filepath = $upload_dir . '/' . $filename;

    $result = file_put_contents($filepath, $text);
    
    if ($result === false) {
        return ['error' => 'Failed to create text file.'];
    }

    $upload_base = wp_upload_dir();
    $fileurl = str_replace($upload_base['basedir'], $upload_base['baseurl'], $filepath);

    return [
        'path' => $filepath,
        'url' => $fileurl,
        'filename' => $filename,
        'size' => filesize($filepath)
    ];
}

/**
 * Build invoice text (keep existing - no changes needed)
 */
public static function build_invoice_text($order_id, $order_data) {
    if (is_string($order_data)) {
        $order_data = maybe_unserialize($order_data);
    }
    if (!is_array($order_data)) {
        return "Error: Invalid invoice data";
    }
    
    // List of all settings keys
    $settings_keys = [
        'oim_company_email', 'oim_company_bank', 'oim_company_bic',
        'oim_payment_title', 'oim_company_account', 'oim_company_iban', 
        'oim_company_supplier', 'oim_headquarters', 'oim_crn', 
        'oim_our_reference', 'oim_issued_by', 'oim_company_phone', 
        'oim_company_web', 'oim_price'
    ];
    
    // ✅ Use invoice-specific settings first, fallback to global settings
    $settings = [];
    foreach ($settings_keys as $key) {
        $settings[$key] = $order_data[$key] ?? get_option($key, '');
    }
    $current_user_obj = wp_get_current_user();
    $current_user = $current_user_obj->display_name ?: 'AUTOMAT';
    $loading_city        = $order_data['loading_city'] ?? '—';
    $vat_id =       $order_data['vat_id'] ?? '—';
    $loading_country     = $order_data['loading_country'] ?? '—';
    $unloading_city      = $order_data['unloading_city'] ?? '—';
    $unloading_country   = $order_data['unloading_country'] ?? '—';
    $truck_number        = $order_data['truck_number'] ?? '—';
    $loading_date        = $order_data['loading_date'] ?? '—';
    $unloading_date      = $order_data['unloading_date'] ?? '—';
    $customer_order_nr   = $order_data['customer_order_number'] ?? '—';
    $internal_order_id   = $order_data['internal_order_id'] ?? '—';
    $invoice_export_date = $order_data['invoice_export_date'];
    $invoice_export_flag = $order_data['invoice_export_flag'];
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
    $export_timestamp = current_time('Y-m-d H:i:s');
    update_post_meta($order_id, 'invoice_export_flag', true);
    update_post_meta($order_id, 'invoice_export_date', $export_timestamp);

    return $invoice_text;


    
    $internal_order_id = $order_data['internal_order_id'] ?? $order_id;
    $invoice_number = $order_data['invoice_number'] ?? 'INV-' . $internal_order_id;
    
    // Calculate dates
    $invoice_date = $order_data['created_at'] ?? current_time('Y-m-d');
    $due_days = intval($order_data['invoice_due_date_in_days'] ?? 30);
    $due_date = date('Y-m-d', strtotime($invoice_date . " + {$due_days} days"));
    
    $text = "";
    $text .= str_repeat("=", 70) . "\n";
    $text .= "                              INVOICE\n";
    $text .= str_repeat("=", 70) . "\n\n";
    
    // Company Header
    $text .= strtoupper($settings['oim_company_supplier'] ?: 'Your Company Name') . "\n";
    $text .= $settings['oim_headquarters'] . "\n";
    $text .= "CRN: " . $settings['oim_crn'] . "\n";
    $text .= "Phone: " . $settings['oim_company_phone'] . "\n";
    $text .= "Email: " . $settings['oim_company_email'] . "\n";
    if (!empty($settings['oim_company_web'])) {
        $text .= "Web: " . $settings['oim_company_web'] . "\n";
    }
    $text .= "\n" . str_repeat("-", 70) . "\n\n";
    
    // Invoice Details
    $text .= sprintf("%-25s %s\n", "Invoice Number:", $invoice_number);
    $text .= sprintf("%-25s %s\n", "Order ID:", $internal_order_id);
    $text .= sprintf("%-25s %s\n", "Invoice Date:", date('d/m/Y', strtotime($invoice_date)));
    $text .= sprintf("%-25s %s\n", "Due Date:", date('d/m/Y', strtotime($due_date)));
    $text .= "\n" . str_repeat("-", 70) . "\n\n";
    
    // Bill To
    $text .= "BILL TO:\n";
    $text .= sprintf("%-25s %s\n", "Company:", $order_data['customer_company_name'] ?? 'N/A');
    $text .= sprintf("%-25s %s\n", "Country:", $order_data['customer_country'] ?? 'N/A');
    if (!empty($order_data['customer_company_address'])) {
        $text .= sprintf("%-25s %s\n", "Address:", $order_data['customer_company_address']);
    }
    $text .= sprintf("%-25s %s\n", "Email:", $order_data['customer_company_email'] ?? $order_data['customer_email'] ?? 'N/A');
    if (!empty($order_data['customer_company_phone_number'])) {
        $text .= sprintf("%-25s %s\n", "Phone:", $order_data['customer_company_phone_number']);
    }
    if (!empty($order_data['vat_id'])) {
        $text .= sprintf("%-25s %s\n", "VAT ID:", $order_data['vat_id']);
    }
    $text .= "\n";
    
    // Reference
    $text .= "REFERENCE:\n";
    if (!empty($order_data['customer_reference'])) {
        $text .= sprintf("%-25s %s\n", "Customer Reference:", $order_data['customer_reference']);
    }
    if (!empty($settings['oim_our_reference'])) {
        $text .= sprintf("%-25s %s\n", "Our Reference:", $settings['oim_our_reference']);
    }
    if (!empty($settings['oim_issued_by'])) {
        $text .= sprintf("%-25s %s\n", "Issued By:", $settings['oim_issued_by']);
    }
    if (!empty($order_data['truck_number'])) {
        $text .= sprintf("%-25s %s\n", "Truck Number:", $order_data['truck_number']);
    }
    $text .= "\n" . str_repeat("-", 70) . "\n\n";
    
    // Shipment Details
    $text .= "SHIPMENT DETAILS:\n\n";
    $text .= "LOADING:\n";
    $text .= sprintf("  %-23s %s\n", "Company:", $order_data['loading_company_name'] ?? 'N/A');
    $text .= sprintf("  %-23s %s %s\n", "Location:", 
        $order_data['loading_city'] ?? '', 
        $order_data['loading_zip'] ?? ''
    );
    $text .= sprintf("  %-23s %s\n", "Country:", $order_data['loading_country'] ?? '');
    if (!empty($order_data['loading_date'])) {
        $text .= sprintf("  %-23s %s\n", "Date:", date('d/m/Y', strtotime($order_data['loading_date'])));
    }
    
    $text .= "\nUNLOADING:\n";
    $text .= sprintf("  %-23s %s\n", "Company:", $order_data['unloading_company_name'] ?? 'N/A');
    $text .= sprintf("  %-23s %s %s\n", "Location:", 
        $order_data['unloading_city'] ?? '', 
        $order_data['unloading_zip'] ?? ''
    );
    $text .= sprintf("  %-23s %s\n", "Country:", $order_data['unloading_country'] ?? '');
    if (!empty($order_data['unloading_date'])) {
        $text .= sprintf("  %-23s %s\n", "Date:", date('d/m/Y', strtotime($order_data['unloading_date'])));
    }
    
    $text .= "\n" . str_repeat("-", 70) . "\n\n";
    
    // Services
    $text .= "SERVICES:\n\n";
    $description = $settings['oim_payment_title'] ?: 'Logistics Services';
    $route = sprintf("Route: %s, %s → %s, %s",
        $order_data['loading_city'] ?? 'N/A',
        $order_data['loading_country'] ?? '',
        $order_data['unloading_city'] ?? 'N/A',
        $order_data['unloading_country'] ?? ''
    );
    
    $text .= $description . "\n";
    $text .= $route . "\n\n";
    
    // Totals
    $amount = floatval($order_data['customer_price'] ?? $settings['oim_price'] ?? 0);
    $text .= str_repeat("-", 70) . "\n";
    $text .= sprintf("%55s € %10s\n", "Subtotal:", number_format($amount, 2, '.', ','));
    $text .= str_repeat("=", 70) . "\n";
    $text .= sprintf("%55s € %10s\n", "TOTAL AMOUNT:", number_format($amount, 2, '.', ','));
    $text .= str_repeat("=", 70) . "\n\n";
    
    // Payment Information
    $text .= "PAYMENT INFORMATION:\n";
    $text .= sprintf("%-25s %s\n", "Bank:", $settings['oim_company_bank']);
    $text .= sprintf("%-25s %s\n", "IBAN:", $settings['oim_company_iban']);
    $text .= sprintf("%-25s %s\n", "BIC/SWIFT:", $settings['oim_company_bic']);
    $text .= sprintf("%-25s %s\n", "Account:", $settings['oim_company_account']);
    $text .= "\nPayment due within {$due_days} days.\n";
    $text .= "Please reference invoice number on payment.\n\n";
    
    $text .= str_repeat("=", 70) . "\n";
    $text .= "                     Thank you for your business\n";
    $text .= "         " . $settings['oim_company_supplier'] . " | " . $settings['oim_company_email'] . "\n";
    $text .= str_repeat("=", 70) . "\n";
    
    return $text;
}

/**
 * Cleanup old invoice files (keep existing)
 */
public static function maybe_cleanup_old_files($days_old = 30) {
    $days_old = intval($days_old);
    if ($days_old <= 0) {
        $days_old = 30;
    }
    
    $upload_dir = self::get_upload_dir();
    $files = glob($upload_dir . '/invoice-*.{pdf,txt}', GLOB_BRACE);
    
    if (!is_array($files)) {
        return;
    }
    
    $cutoff_time = time() - ($days_old * 24 * 60 * 60);

    foreach ($files as $file) {
        if (file_exists($file) && is_file($file)) {
            if (filemtime($file) < $cutoff_time) {
                @unlink($file);
            }
        }
    }
}


    /**
     * Get list of generated invoices
     */
    public static function get_generated_invoices() {
        $upload_dir = self::get_upload_dir();
        $files = glob($upload_dir . '/invoice-*.{pdf,txt}', GLOB_BRACE);
        $invoices = [];

        if (!is_array($files)) {
            return $invoices;
        }

        foreach ($files as $file) {
            if (file_exists($file)) {
                $invoices[] = [
                    'filename' => basename($file),
                    'path' => $file,
                    'url' => str_replace(wp_upload_dir()['basedir'], wp_upload_dir()['baseurl'], $file),
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                ];
            }
        }

        return $invoices;
    }
}

// Initialize the class
OIM_Invoice::init();