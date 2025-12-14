<?php
/**
 * Template: Driver Upload Page
 * Accessible via: /oim-dashboard/driver-upload/{token}/
 */

if (!defined('ABSPATH')) exit;

$token = get_query_var('oim_driver_token');

// Validate token and get order
if (!$token || !($order = OIM_DB::get_order_by_token($token))) {
    echo '<div class="oim-card"><div class="oim-card-content"><div class="oim-empty-state">
        <i class="fas fa-exclamation-triangle" style="color:#ef4444;font-size:48px;margin-bottom:16px;"></i>
        <h3>Invalid or Expired Link</h3>
        <p>This upload link is not valid or has expired.</p>
    </div></div></div>';
    return;
}

// Include upload handler
if (!function_exists('wp_handle_upload')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

$data = maybe_unserialize($order['data']);
$upload_error = '';
$uploaded_count = isset($_GET['uploaded']) ? intval($_GET['uploaded']) : 0;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['driver_docs']['name'][0])) {
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'oim_driver_upload_' . $token)) {
        $upload_error = 'Security check failed.';
    } else {
        $count = 0;
        foreach ($_FILES['driver_docs']['name'] as $key => $name) {
            if (empty($name)) continue;
            $file = [
                'name' => $_FILES['driver_docs']['name'][$key],
                'type' => $_FILES['driver_docs']['type'][$key],
                'tmp_name' => $_FILES['driver_docs']['tmp_name'][$key],
                'error' => $_FILES['driver_docs']['error'][$key],
                'size' => $_FILES['driver_docs']['size'][$key],
            ];
            $upload = wp_handle_upload($file, ['test_form' => false]);
            if (!empty($upload['url'])) {
                OIM_DB::add_document($order['internal_order_id'], basename($upload['url']), $upload['type'], $upload['url']);
                $count++;
            }
        }
        if ($count > 0) {
            wp_safe_redirect(add_query_arg('uploaded', $count, home_url('/oim-dashboard/driver-upload/' . $token . '/')));
            exit;
        }
    }
}

$docs = OIM_DB::get_documents($order['internal_order_id']);
?>

<!-- Page Header -->
<div class="oim-page-header">
    <div class="oim-page-title-section">
        <h1 class="oim-page-title"><i class="fas fa-truck"></i> Driver Upload Portal</h1>
        <p class="oim-page-subtitle">Order #<?php echo esc_html($order['internal_order_id']); ?></p>
    </div>
</div>

<?php if ($uploaded_count > 0): ?>
<div class="oim-notice oim-notice-success">
    <i class="fas fa-check-circle"></i> <?php echo $uploaded_count; ?> file(s) uploaded successfully!
</div>
<?php endif; ?>

<?php if ($upload_error): ?>
<div class="oim-notice oim-notice-error">
    <i class="fas fa-exclamation-circle"></i> <?php echo esc_html($upload_error); ?>
</div>
<?php endif; ?>

<!-- Order Info -->
<div class="oim-card">
    <div class="oim-card-header">
        <div class="oim-card-icon"><i class="fas fa-clipboard-list"></i></div>
        <div><h2 class="oim-card-title">Order Information</h2></div>
    </div>
    <div class="oim-card-content">
        <div class="oim-grid-2">
            <div class="oim-shipment-box eloading">
                <h4><i class="fas fa-arrow-up"></i> Loading</h4>
                <p><strong>Date:</strong> <?php echo esc_html($data['loading_date'] ?? '-'); ?></p>
                <p><strong>City:</strong> <?php echo esc_html($data['loading_city'] ?? '-'); ?>, <?php echo esc_html($data['loading_country'] ?? ''); ?></p>
                <p><strong>Company:</strong> <?php echo esc_html($data['loading_company_name'] ?? '-'); ?></p>
            </div>
            <div class="oim-shipment-box unloading">
                <h4><i class="fas fa-arrow-down"></i> Unloading</h4>
                <p><strong>Date:</strong> <?php echo esc_html($data['unloading_date'] ?? '-'); ?></p>
                <p><strong>City:</strong> <?php echo esc_html($data['unloading_city'] ?? '-'); ?>, <?php echo esc_html($data['unloading_country'] ?? ''); ?></p>
                <p><strong>Company:</strong> <?php echo esc_html($data['unloading_company_name'] ?? '-'); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Upload Form -->
<div class="oim-card">
    <div class="oim-card-header">
        <div class="oim-card-icon" style="background:linear-gradient(135deg,#10b981,#059669);"><i class="fas fa-cloud-upload-alt"></i></div>
        <div><h2 class="oim-card-title">Upload Documents</h2></div>
    </div>
    <div class="oim-card-content">
        <form method="post" enctype="multipart/form-data" id="driver-upload-form">
            <?php wp_nonce_field('oim_driver_upload_' . $token); ?>
            <div class="oim-upload-area" id="upload-drop-area">
                <i class="fas fa-cloud-upload-alt" style="font-size:48px;color:#9ca3af;margin-bottom:16px;"></i>
                <p style="font-weight:600;margin-bottom:4px;">Drag & drop files here</p>
                <p style="color:#6b7280;font-size:13px;margin-bottom:16px;">or click button below</p>
                <input type="file" name="driver_docs[]" id="driver-file-input" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" style="display:none;">
                <button type="button" class="oim-btn oim-btn-secondary" onclick="document.getElementById('driver-file-input').click();">
                    <i class="fas fa-folder-open"></i> Select Files
                </button>
            </div>
            <div id="file-preview" style="margin-top:16px;"></div>
            <button type="submit" class="oim-btn oim-btn-primary" id="upload-btn" style="display:none;margin-top:16px;">
                <i class="fas fa-upload"></i> Upload Files
            </button>
        </form>
    </div>
</div>

<!-- Uploaded Documents -->
<div class="oim-card">
    <div class="oim-card-header">
        <div class="oim-card-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb);"><i class="fas fa-folder-open"></i></div>
        <div><h2 class="oim-card-title">Uploaded Documents (<?php echo count($docs); ?>)</h2></div>
    </div>
    <div class="oim-card-content">
    <?php if ($docs): ?>
        <?php foreach ($docs as $doc): 
            $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
            $icon = 'fa-file';
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $icon = 'fa-file-image';
            elseif ($ext === 'pdf') $icon = 'fa-file-pdf';
            elseif (in_array($ext, ['doc', 'docx'])) $icon = 'fa-file-word';
            elseif (in_array($ext, ['xls', 'xlsx', 'csv'])) $icon = 'fa-file-excel';
            elseif (in_array($ext, ['zip', 'rar', '7z'])) $icon = 'fa-file-archive';
        ?>
        <div class="oim-doc-item">
            <i class="fas <?php echo $icon; ?>" style="font-size:20px;color:#3b82f6;margin-right:12px;"></i>
            <span style="flex:1;"><?php echo esc_html($doc['filename']); ?></span>
            <div class="oim-doc-actions">
                <a href="<?php echo esc_url($doc['file_url']); ?>" target="_blank" class="oim-btn oim-btn-view" title="View">
                    <i class="fas fa-eye"></i> View
                </a>
                <a href="<?php echo esc_url($doc['file_url']); ?>" download class="oim-btn oim-btn-download" title="Download">
                    <i class="fas fa-download"></i> Download
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="text-align:center;color:#6b7280;padding:30px;">No documents uploaded yet.</p>
    <?php endif; ?>
</div>
</div>

<style>
.oim-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
@media(max-width:768px){.oim-grid-2{grid-template-columns:1fr;}}
.oim-shipment-box{padding:20px;border-radius:10px;border:1px solid #e5e7eb;}
.oim-shipment-box.eloading{background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-color:#a7f3d0;}
.oim-shipment-box.unloading{background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;}
.oim-shipment-box h4{margin:0 0 12px;font-size:14px;display:flex;align-items:center;gap:8px;}
.oim-shipment-box p{margin:6px 0;font-size:13px;}
.oim-upload-area{border:2px dashed #d1d5db;border-radius:12px;padding:40px;text-align:center;background:#f9fafb;transition:all .2s;cursor:pointer;}
.oim-upload-area:hover,.oim-upload-area.dragover{border-color:#6366f1;background:#f5f3ff;}
.oim-doc-item{display:flex;align-items:center;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;}
.oim-notice{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:500;}
.oim-notice-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;}
.oim-notice-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('driver-file-input');
    const preview = document.getElementById('file-preview');
    const btn = document.getElementById('upload-btn');
    const area = document.getElementById('upload-drop-area');
    
    input.addEventListener('change', updatePreview);
    
    ['dragenter','dragover','dragleave','drop'].forEach(e => {
        area.addEventListener(e, ev => { ev.preventDefault(); ev.stopPropagation(); });
    });
    ['dragenter','dragover'].forEach(e => area.addEventListener(e, () => area.classList.add('dragover')));
    ['dragleave','drop'].forEach(e => area.addEventListener(e, () => area.classList.remove('dragover')));
    
    area.addEventListener('drop', e => {
        const dt = new DataTransfer();
        Array.from(e.dataTransfer.files).forEach(f => dt.items.add(f));
        input.files = dt.files;
        updatePreview();
    });
    
    function updatePreview() {
        preview.innerHTML = '';
        if (input.files.length === 0) { btn.style.display = 'none'; return; }
        btn.style.display = 'inline-flex';
        Array.from(input.files).forEach((f, i) => {
            const div = document.createElement('div');
            div.className = 'oim-doc-item';
            div.innerHTML = `<i class="fas fa-file" style="color:#10b981;margin-right:12px;"></i>
                <span style="flex:1;">${f.name} <small style="color:#6b7280;">(${(f.size/1024).toFixed(1)} KB)</small></span>
                <button type="button" onclick="removeFile(${i})" style="background:none;border:none;color:#ef4444;cursor:pointer;"><i class="fas fa-times"></i></button>`;
            preview.appendChild(div);
        });
    }
    
    window.removeFile = function(index) {
        const dt = new DataTransfer();
        Array.from(input.files).forEach((f, i) => { if (i !== index) dt.items.add(f); });
        input.files = dt.files;
        updatePreview();
    };
});
</script>