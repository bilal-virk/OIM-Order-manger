
<?php
$g = array_change_key_case($_GET, CASE_LOWER);

// Show error (case-insensitive for the key name)
if (!empty($g['oim_error'])) {
    // decode just in case the redirect used urlencode()
    $error_text = urldecode($g['oim_error']);
    echo '<div class="oim-notice oim-error" style="padding:12px;background:#ffefef;border-left:4px solid #cc0000;margin-bottom:15px;border-radius:4px;">'
        . esc_html($error_text) .
        '</div>';
}

// Show success (accepts sent, SENT, Sent, etc.)
if (isset($g['sent']) && (string)$g['sent'] !== '') {
    // If you only want to treat sent=1 as success, uncomment the next line and comment the line after it:
    // if ((string)$g['sent'] === '1') {

    // If sent could be non-numeric (like a message), show the provided message; otherwise show default.
    $sent_val = urldecode($g['sent']);
    $sent_message = is_numeric($sent_val) ? 'Invoice sent successfully!.' : $sent_val;

    echo '<div class="oim-notice oim-success" style="padding:12px;background:#e6ffe6;border-left:4px solid #009900;margin-bottom:15px;border-radius:4px;">'
        . esc_html($sent_message) .
        '</div>';
}
if (isset($g['deleted']) && (string)$g['deleted'] !== '') {
    $deleted_val = urldecode($g['deleted']);
    $deleted_message = is_numeric($deleted_val) ? 'Invoice is deleted successfully.' : $deleted_val;

    echo '<div class="oim-notice oim-success" style="padding:12px;background:#e6ffe6;border-left:4px solid #009900;margin-bottom:15px;border-radius:4px;">'
        . esc_html($deleted_message) .
        '</div>';
}
if (class_exists('OIM_Invoices')) {
    OIM_Invoices::render_invoices_page();
}
