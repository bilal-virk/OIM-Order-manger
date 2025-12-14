<?php
// includes/class-oim-rest-api.php
if (!defined('ABSPATH')) exit;

class OIM_REST_API {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes() {
        register_rest_route('oim/v1', '/import-excel', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_import_request'],
            'permission_callback' => [__CLASS__, 'verify_api_key']
        ]);
    }

    /**
     * Verify API key from request
     */
    public static function verify_api_key($request) {
        // Get API key from settings
        $stored_api_key = get_option('oim_api_key', '');
        
        // If no API key is set in settings, reject
        if (empty($stored_api_key)) {
            return new WP_Error(
                'no_api_key_configured',
                'API key is not configured in plugin settings.',
                ['status' => 500]
            );
        }
        
        // Check for API key in header (preferred method)
        $api_key = $request->get_header('X-API-Key');
        
        // Fallback: Check in Authorization header
        if (empty($api_key)) {
            $auth_header = $request->get_header('Authorization');
            if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
                $api_key = substr($auth_header, 7);
            }
        }
        
        // Fallback: Check in query parameter (less secure, but convenient)
        if (empty($api_key)) {
            $api_key = $request->get_param('api_key');
        }
        
        // Verify API key
        if (empty($api_key) || $api_key !== $stored_api_key) {
            return new WP_Error(
                'invalid_api_key',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }
        
        return true;
    }

    /**
     * Handle Excel import via REST API
     */
    public static function handle_import_request($request) {
        // Get uploaded files
        $files = $request->get_file_params();
        
        if (empty($files['excel_file'])) {
            return new WP_Error(
                'no_file',
                'No file uploaded. Please upload an Excel file with the key "excel_file".',
                ['status' => 400]
            );
        }
        
        $file = $files['excel_file'];
        
        // Validate file type
        $allowed_extensions = ['xlsx', 'xls'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            return new WP_Error(
                'invalid_file_type',
                'Invalid file type. Only .xlsx and .xls files are allowed.',
                ['status' => 400]
            );
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error(
                'upload_error',
                'File upload error: ' . $file['error'],
                ['status' => 500]
            );
        }
        
        // Handle file upload using WordPress
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        
        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => [
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'xls' => 'application/vnd.ms-excel'
            ]
        ]);
        
        if (!empty($upload['error'])) {
            return new WP_Error(
                'upload_failed',
                'Upload failed: ' . $upload['error'],
                ['status' => 500]
            );
        }
        
        if (empty($upload['file'])) {
            return new WP_Error(
                'upload_failed',
                'Upload failed: file path is empty.',
                ['status' => 500]
            );
        }
        
        // Import Excel using existing function
        $result = OIM_DB::import_excel($upload['file']);
        
        // Clean up uploaded file
        if (file_exists($upload['file'])) {
            unlink($upload['file']);
        }
        
        // Check if import had errors
        if (isset($result['error'])) {
            return new WP_Error(
                'import_error',
                $result['error'],
                ['status' => 500]
            );
        }
        
        // Return success response
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Excel file imported successfully',
            'data' => [
                'imported' => $result['imported'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
                'duplicates' => $result['duplicates'] ?? 0,
                'invoices_created' => $result['invoices_created'] ?? 0
            ]
        ], 200);
    }
}

// Initialize the REST API
OIM_REST_API::init();