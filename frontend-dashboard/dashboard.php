<?php
/**
 * OIM Dashboard Layout Template
 * Dashboard.php
 * This file wraps all dashboard pages with a consistent layout.
 * The $content variable contains the page-specific content.
 */

// Security check - this should already be done in template_redirect, but double-check
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(site_url($_SERVER['REQUEST_URI'])));
    exit;
}

// Prevent WordPress theme from loading
remove_all_actions('wp_head');
remove_all_actions('wp_footer');
remove_all_actions('wp_print_styles');
remove_all_actions('wp_print_scripts');

// Re-add only essential WordPress hooks
add_action('wp_head', 'wp_enqueue_scripts', 1);
add_action('wp_head', 'wp_print_styles', 8);
add_action('wp_head', 'wp_print_head_scripts', 9);
add_action('wp_footer', 'wp_print_footer_scripts', 20);

// Get current user info
$current_user = wp_get_current_user();
$current_page = get_query_var('oim_page');
$base_url = site_url('/oim-dashboard');

// Get user initials for avatar
$user_initials = strtoupper(substr($current_user->display_name, 0, 1));
if (strpos($current_user->display_name, ' ') !== false) {
    $names = explode(' ', $current_user->display_name);
    $user_initials = strtoupper(substr($names[0], 0, 1) . substr(end($names), 0, 1));
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>OIM Dashboard - <?php bloginfo('name'); ?></title>
    
    <!-- Google Fonts - Premium Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <!-- Font Awesome for modern icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php wp_head(); ?>
    
    <style>
        :root {
            /* Modern Color Palette */
            --oim-primary: #6366f1;
            --oim-primary-hover: #4f46e5;
            --oim-primary-light: rgba(99, 102, 241, 0.1);
            --oim-primary-glow: rgba(99, 102, 241, 0.4);
            
            --oim-secondary: #8b5cf6;
            --oim-accent: #06b6d4;
            
            --oim-success: #10b981;
            --oim-warning: #f59e0b;
            --oim-error: #ef4444;
            
            /* Dark Theme Sidebar */
            --sidebar-bg: #0f172a;
            --sidebar-secondary: #1e293b;
            --sidebar-border: rgba(255, 255, 255, 0.06);
            --sidebar-text: #94a3b8;
            --sidebar-text-active: #ffffff;
            
            /* Light Theme Content */
            --content-bg: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            
            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            
            /* Typography */
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            
            /* Transitions */
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-base: 200ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: 300ms cubic-bezier(0.4, 0, 0.2, 1);
            
            /* Border Radius */
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            margin: 0;
            font-family: var(--font-sans);
            display: flex;
            height: 100vh;
            overflow: hidden;
            background: var(--content-bg);
            color: var(--text-primary);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        /* Files Section Styling */
.oim-files-section {
  margin-bottom: 20px;
}

.oim-files-section:last-child {
  margin-bottom: 0;
}

.oim-files-subtitle {
  font-size: 14px;
  font-weight: 600;
  color: #374151;
  margin: 0 0 12px 0;
  padding: 8px 12px;
  background: #f3f4f6;
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.oim-files-subtitle i {
  font-size: 16px;
}

.oim-docs-section .oim-files-subtitle {
  background: linear-gradient(135deg, #ecfdf5, #d1fae5);
  color: #065f46;
  border-left: 3px solid #10b981;
}

.oim-attachments-section .oim-files-subtitle {
  background: linear-gradient(135deg, #fef3c7, #fde68a);
  color: #92400e;
  border-left: 3px solid #f59e0b;
}

/* File Item */
.oim-file-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  margin-bottom: 8px;
  transition: all 0.2s ease;
}

.oim-file-item:hover {
  background: #fff;
  border-color: #6366f1;
  transform: translateX(4px);
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1);
}

.oim-doc-item {
  border-left: 3px solid #10b981;
}

.oim-attachment-item {
  border-left: 3px solid #f59e0b;
}

/* File Icon */
.oim-file-icon {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  border-radius: 6px;
  font-size: 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

/* File Info */
.oim-file-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.oim-file-link {
  color: #1f2937;
  text-decoration: none;
  font-weight: 500;
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.oim-file-link:hover {
  color: #6366f1;
  text-decoration: underline;
}

.oim-file-meta {
  font-size: 12px;
  color: #6b7280;
}

/* File Actions */
.oim-file-actions {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.oim-file-view,
.oim-file-download,
.oim-file-delete {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  text-decoration: none;
  font-size: 14px;
  transition: all 0.2s ease;
  cursor: pointer;
}

.oim-file-view {
  background: #dbeafe;
  color: #1e40af;
}

.oim-file-view:hover {
  background: #3b82f6;
  color: #fff;
}

.oim-file-download {
  background: #d1fae5;
  color: #065f46;
}

.oim-file-download:hover {
  background: #10b981;
  color: #fff;
}

.oim-file-delete {
  background: #fee2e2;
  color: #991b1b;
}

.oim-file-delete:hover {
  background: #ef4444;
  color: #fff;
}

/* Empty State */
.oim-files-empty {
  text-align: center;
  padding: 30px;
  color: #9ca3af;
}

.oim-files-empty i {
  font-size: 36px;
  margin-bottom: 8px;
  display: block;
}

/* Responsive */
@media (max-width: 768px) {
  .oim-file-item {
    flex-wrap: wrap;
  }
  
  .oim-file-actions {
    width: 100%;
    justify-content: flex-end;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
  }
}

        
        /* ========================================
           SIDEBAR STYLES
           ======================================== */
        .left-sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            z-index: 1000;
            transition: width var(--transition-slow);
            position: relative;
            border-right: 1px solid var(--sidebar-border);
        }
        
        .left-sidebar.collapsed {
            width: 80px;
        }
        
        /* Logo Section */
        .left-sidebar .logo {
            padding: 24px 20px;
            border-bottom: 1px solid var(--sidebar-border);
            background: linear-gradient(180deg, rgba(99, 102, 241, 0.08) 0%, transparent 100%);
            min-height: 80px;
            display: flex;
            align-items: center;
            overflow: hidden;
        }
        
        .left-sidebar .logo-icon {
            font-size: 24px;
            min-width: 40px;
            height: 40px;
            margin-right: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--oim-primary) 0%, var(--oim-secondary) 100%);
            border-radius: var(--radius-md);
            color: white;
            transition: margin var(--transition-slow);
            box-shadow: 0 4px 12px var(--oim-primary-glow);
        }
        
        .left-sidebar.collapsed .logo-icon {
            margin-right: 0;
        }
        
        .left-sidebar .logo-text {
            opacity: 1;
            transition: opacity var(--transition-base);
            white-space: nowrap;
        }
        
        .left-sidebar.collapsed .logo-text {
            opacity: 0;
            width: 0;
            overflow: hidden;
        }
        
        .left-sidebar .logo h2 {
            font-size: 17px;
            font-weight: 700;
            margin: 0;
            color: var(--sidebar-text-active);
            letter-spacing: -0.4px;
        }
        
        .left-sidebar .logo span {
            font-size: 11px;
            color: var(--sidebar-text);
            display: block;
            margin-top: 3px;
            font-weight: 500;
        }
        
        /* Menu Section */
        .left-sidebar .menu {
            flex: 1;
            padding: 20px 0;
            overflow-y: auto;
            overflow-x: hidden;
        }
        
        .left-sidebar .menu-section {
            margin-bottom: 24px;
        }
        
        .left-sidebar .menu-section-title {
            padding: 8px 24px;
            font-size: 10px;
            font-weight: 700;
            color: var(--sidebar-text);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            white-space: nowrap;
            transition: opacity var(--transition-base);
        }
        
        .left-sidebar.collapsed .menu-section-title {
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }
        
        /* Menu Items */
        .left-sidebar a.menu-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: all var(--transition-base);
            font-size: 14px;
            font-weight: 500;
            position: relative;
            white-space: nowrap;
            overflow: hidden;
            margin: 4px 12px;
            border-radius: var(--radius-md);
        }
        
        .left-sidebar.collapsed a.menu-item {
            padding: 12px;
            justify-content: center;
            margin: 4px 12px;
        }
        
        .left-sidebar a.menu-item .icon {
            margin-right: 12px;
            font-size: 18px;
            min-width: 24px;
            text-align: center;
            transition: margin var(--transition-slow), transform var(--transition-base);
        }
        
        .left-sidebar.collapsed a.menu-item .icon {
            margin-right: 0;
        }
        
        .left-sidebar a.menu-item .label {
            opacity: 1;
            transition: opacity var(--transition-base);
        }
        
        .left-sidebar.collapsed a.menu-item .label {
            opacity: 0;
            width: 0;
        }
        
        .left-sidebar a.menu-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--sidebar-text-active);
        }
        
        .left-sidebar a.menu-item:hover .icon {
            transform: scale(1.1);
        }
        
        .left-sidebar a.menu-item.active {
            background: linear-gradient(135deg, var(--oim-primary) 0%, var(--oim-secondary) 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 15px var(--oim-primary-glow);
        }
        
        .left-sidebar a.menu-item.active .icon {
            color: white;
        }
        
        /* User Info Section */
        .left-sidebar .user-info {
            padding: 20px;
            border-top: 1px solid var(--sidebar-border);
            background: var(--sidebar-secondary);
            transition: padding var(--transition-slow);
            overflow: hidden;
        }
        
        .left-sidebar.collapsed .user-info {
            padding: 16px 12px;
        }
        
        .left-sidebar .user-info {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
}

.left-sidebar .user-info .avatar {
    width: 44px;
    height: 44px;
    min-width: 44px;
    min-height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 14px;
    transition: margin var(--transition-slow);
    letter-spacing: -0.5px;
    overflow: hidden;
    border: 2px solid var(--oim-primary);
    background: linear-gradient(135deg, var(--oim-primary) 0%, #4f46e5 100%);
    color: #fff;
    flex-shrink: 0;
}

.left-sidebar .user-info .avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.left-sidebar .user-info .avatar .avatar-initials {
    font-size: 16px;
    font-weight: 600;
    line-height: 1;
}

/* Collapsed state */
.left-sidebar.collapsed .user-info {
    flex-direction: column;
    padding: 16px 8px;
}

.left-sidebar.collapsed .user-info .avatar {
    margin: 0 auto;
}

.left-sidebar.collapsed .user-info .details {
    display: none;
}
        
        .left-sidebar .user-info .details {
            transition: opacity var(--transition-base), height var(--transition-base);
        }
        
        .left-sidebar.collapsed .user-info .details {
            opacity: 0;
            height: 0;
            overflow: hidden;
        }
        
        .left-sidebar .user-info .name {
            color: var(--sidebar-text-active);
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .left-sidebar .user-info .email {
            color: var(--sidebar-text);
            font-size: 11px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Logout Button */
        .left-sidebar .logout {
            padding: 12px 16px;
            border-top: 1px solid var(--sidebar-border);
        }
        
        .left-sidebar .logout a {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            color: white;
            text-align: center;
            justify-content: center;
            border-radius: var(--radius-md);
            padding: 11px 16px;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all var(--transition-base);
            white-space: nowrap;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(244, 63, 94, 0.3);
        }
        
        .left-sidebar .logout a .icon {
            margin-right: 8px;
            font-size: 15px;
            transition: margin var(--transition-slow);
        }
        
        .left-sidebar.collapsed .logout a .icon {
            margin-right: 0;
        }
        
        .left-sidebar .logout a .label {
            transition: opacity var(--transition-base);
        }
        
        .left-sidebar.collapsed .logout a .label {
            opacity: 0;
            width: 0;
        }
        
        .left-sidebar .logout a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 63, 94, 0.4);
        }
        
        /* Toggle Button */
        .left-sidebar .toggle-sidebar {
            padding: 12px 16px 16px;
        }
        
        .left-sidebar .toggle-sidebar button {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            color: var(--sidebar-text);
            border: 1px solid var(--sidebar-border);
            border-radius: var(--radius-md);
            padding: 10px 16px;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-base);
            white-space: nowrap;
            overflow: hidden;
            font-family: var(--font-sans);
        }
        
        .left-sidebar .toggle-sidebar button:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--sidebar-text-active);
            border-color: rgba(255, 255, 255, 0.15);
        }
        
        .left-sidebar .toggle-sidebar button .icon {
            font-size: 14px;
            transition: transform var(--transition-slow), margin var(--transition-slow);
            margin-right: 8px;
        }
        
        .left-sidebar.collapsed .toggle-sidebar button .icon {
            margin-right: 0;
            transform: rotate(180deg);
        }
        
        .left-sidebar .toggle-sidebar button .label {
            transition: opacity var(--transition-base);
        }
        
        .left-sidebar.collapsed .toggle-sidebar button .label {
            opacity: 0;
            width: 0;
        }
        
        /* ========================================
           MAIN CONTENT AREA
           ======================================== */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--content-bg);
        }
        
        .content {
            flex: 1;
            padding: 28px;
            overflow-y: auto;
            background: var(--content-bg);
        }
        
        /* Scrollbar Styling */
        .left-sidebar .menu::-webkit-scrollbar,
        .content::-webkit-scrollbar {
            width: 5px;
        }
        
        .left-sidebar .menu::-webkit-scrollbar-track {
            background: transparent;
        }
        
        .left-sidebar .menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
        }
        
        .left-sidebar .menu::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        .content::-webkit-scrollbar-track {
            background: var(--content-bg);
        }
        
        .content::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 10px;
        }
        
        .content::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
        
        /* Tooltip for collapsed sidebar */
        .left-sidebar.collapsed .menu-item,
        .left-sidebar.collapsed .logout a,
        .left-sidebar.collapsed .toggle-sidebar button {
            position: relative;
        }
        
        .left-sidebar.collapsed .menu-item::after,
        .left-sidebar.collapsed .logout a::after,
        .left-sidebar.collapsed .toggle-sidebar button::after {
            content: attr(data-tooltip);
            position: absolute;
            left: calc(100% + 12px);
            background: var(--sidebar-secondary);
            color: var(--sidebar-text-active);
            padding: 8px 14px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity var(--transition-base);
            z-index: 10000;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--sidebar-border);
        }
        
        .left-sidebar.collapsed .menu-item:hover::after,
        .left-sidebar.collapsed .logout a:hover::after,
        .left-sidebar.collapsed .toggle-sidebar button:hover::after {
            opacity: 1;
        }
        
        /* ========================================
           RESPONSIVE STYLES
           ======================================== */
        @media (max-width: 1024px) {
            .left-sidebar {
                position: fixed;
                left: -280px;
                top: 0;
                bottom: 0;
                transition: left var(--transition-slow);
            }
            
            .left-sidebar.mobile-active {
                left: 0;
            }
            
            .left-sidebar.collapsed {
                left: -80px;
            }
            
            .left-sidebar.collapsed.mobile-active {
                left: 0;
            }
            
            .mobile-overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(15, 23, 42, 0.6);
                backdrop-filter: blur(4px);
                z-index: 999;
            }
            
            .mobile-overlay.active {
                display: block;
            }
            
            .content {
                padding: 20px;
            }
            
            .mobile-menu-toggle {
                display: flex !important;
            }
        }
        
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, var(--oim-primary) 0%, var(--oim-secondary) 100%);
            color: white;
            border: none;
            border-radius: 50%;
            font-size: 22px;
            cursor: pointer;
            box-shadow: 0 8px 24px var(--oim-primary-glow);
            z-index: 998;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-base);
        }
        
        .mobile-menu-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 12px 32px var(--oim-primary-glow);
        }
        
        /* Loading spinner */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Focus states for accessibility */
        .left-sidebar a.menu-item:focus,
        .left-sidebar .toggle-sidebar button:focus,
        .left-sidebar .logout a:focus {
            outline: 2px solid var(--oim-primary);
            outline-offset: 2px;
        }
    </style>
</head>
<body class="oim-dashboard-page oim-page-<?php echo esc_attr($current_page); ?>">

<!-- Mobile overlay -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<div class="left-sidebar" id="leftSidebar">
    <div class="logo">
        
        <div class="logo-text">
            <h2>OIM Dashboard</h2>
            <span>Order & Invoice Manager</span>
        </div>
    </div>
    
    <div class="menu">
        <div class="menu-section">
            <div class="menu-section-title">Main Menu</div>
            <?php
            $menu_items = [
                'orders' => ['label' => 'Orders', 'icon' => 'fas fa-shopping-cart', 'url' => $base_url . '/orders'],
                'invoices' => ['label' => 'Invoices', 'icon' => 'fas fa-file-invoice-dollar', 'url' => $base_url . '/invoices'],
                'settings' => ['label' => 'Settings', 'icon' => 'fas fa-cog', 'url' => $base_url . '/settings'],
            ];

            foreach ($menu_items as $page => $item) {
                $active_class = ($current_page === $page) ? ' active' : '';
                printf(
                    '<a href="%s" class="menu-item%s" data-tooltip="%s">
                        <span class="icon"><i class="%s"></i></span>
                        <span class="label">%s</span>
                    </a>',
                    esc_url($item['url']),
                    $active_class,
                    esc_attr($item['label']),
                    $item['icon'],
                    esc_html($item['label'])
                );
            }
            ?>
        </div>
    </div>

    <div class="user-info">
    <div class="avatar">
        <?php 
        $avatar_url = get_avatar_url($current_user->ID, ['size' => 128]);
        if ($avatar_url): ?>
            <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($current_user->display_name); ?>">
        <?php else: 
            // Fallback to initials
            $initials = strtoupper(substr($current_user->display_name, 0, 1));
            if (strpos($current_user->display_name, ' ') !== false) {
                $parts = explode(' ', $current_user->display_name);
                $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
            }
        ?>
            <span class="avatar-initials"><?php echo esc_html($initials); ?></span>
        <?php endif; ?>
    </div>
    <div class="details">
        <div class="name"><?php echo esc_html($current_user->display_name); ?></div>
        <div class="email"><?php echo esc_html($current_user->user_email); ?></div>
    </div>
</div>

    <div class="logout">
        <a href="<?php echo wp_logout_url($base_url); ?>" data-tooltip="Logout">
            <span class="icon"><i class="fas fa-sign-out-alt"></i></span>
            <span class="label">Logout</span>
        </a>
    </div>
    
    <div class="toggle-sidebar">
        <button id="toggleSidebar" type="button" data-tooltip="Collapse Sidebar">
            <span class="icon"><i class="fas fa-chevron-left"></i></span>
            <span class="label">Collapse</span>
        </button>
    </div>
</div>

<div class="main-content">
    <div class="content">
        <?php 
        // Output the page-specific content
        echo $content; 
        ?>
    </div>
</div>

<!-- Mobile menu toggle button -->
<button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Toggle menu">
    <i class="fas fa-bars"></i>
</button>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('leftSidebar');
    const toggleBtn = document.getElementById('toggleSidebar');
    const mobileToggleBtn = document.getElementById('mobileMenuToggle');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const toggleIcon = toggleBtn ? toggleBtn.querySelector('.icon i') : null;
    const toggleLabel = toggleBtn ? toggleBtn.querySelector('.label') : null;
    
    if (!sidebar || !toggleBtn) {
        console.error('Sidebar or toggle button not found');
        return;
    }
    
    // Check localStorage for saved state
    const sidebarState = localStorage.getItem('oim_sidebar_collapsed');
    if (sidebarState === 'true') {
        sidebar.classList.add('collapsed');
        updateToggleText();
    }
    
    // Toggle sidebar on desktop
    toggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        sidebar.classList.toggle('collapsed');
        const isCollapsed = sidebar.classList.contains('collapsed');
        
        // Save state to localStorage
        localStorage.setItem('oim_sidebar_collapsed', isCollapsed);
        updateToggleText();
    });
    
    // Toggle sidebar on mobile
    if (mobileToggleBtn) {
        mobileToggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('mobile-active');
            if (mobileOverlay) {
                mobileOverlay.classList.toggle('active');
            }
        });
    }
    
    // Close sidebar when clicking overlay on mobile
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', function() {
            sidebar.classList.remove('mobile-active');
            mobileOverlay.classList.remove('active');
        });
    }
    
    // Update toggle button text and icon
    function updateToggleText() {
        const isCollapsed = sidebar.classList.contains('collapsed');
        
        if (toggleLabel) {
            toggleLabel.textContent = isCollapsed ? 'Expand' : 'Collapse';
        }
        
        if (toggleIcon) {
            toggleIcon.className = isCollapsed ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
        }
        
        if (toggleBtn) {
            toggleBtn.setAttribute('data-tooltip', isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar');
        }
    }
    
    // Close mobile menu when clicking menu item
    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 1024) {
                sidebar.classList.remove('mobile-active');
                if (mobileOverlay) {
                    mobileOverlay.classList.remove('active');
                }
            }
        });
    });
    
    // Keyboard navigation
    document.addEventListener('keydown', function(e) {
        // Press 'B' to toggle sidebar (when not focused on input)
        if (e.key === 'b' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
            toggleBtn.click();
        }
        // Press Escape to close mobile menu
        if (e.key === 'Escape' && sidebar.classList.contains('mobile-active')) {
            sidebar.classList.remove('mobile-active');
            if (mobileOverlay) {
                mobileOverlay.classList.remove('active');
            }
        }
    });
});
</script>

<?php wp_footer(); ?>

</body>
</html>