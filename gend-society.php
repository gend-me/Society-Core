<?php
/**
 * Plugin Name: GenD Society
 * Plugin URI:  https://gend.me
 * Description: Futuristic glassmorphic WordPress admin experience with custom menus, redesigned backend, and dynamic frontend sidebar.
 * Version:     1.0.1
 * Author:      By GenD
 * Author URI:  https://gend.me
 * Network:     true
 * Text Domain: gend-society
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GS_VERSION', '1.0.0');
define('GS_DIR', plugin_dir_path(__FILE__));
define('GS_URL', plugin_dir_url(__FILE__));

// Core includes
require_once GS_DIR . 'inc/admin-style.php';
require_once GS_DIR . 'inc/admin-menu.php';
require_once GS_DIR . 'inc/frontend-bar.php';
require_once GS_DIR . 'inc/live-view.php';

// GitHub Updater
require_once GS_DIR . 'inc/class-gend-github-updater.php';
new GenD_GitHub_Updater(__FILE__, 'gend-me/Society-Core');

// Dashboard overrides (Standalone)
require_once GS_DIR . 'inc/dashboard-overview.php';
require_once GS_DIR . 'inc/dashboard-app-management.php';
require_once GS_DIR . 'inc/feature-cards.php';
require_once GS_DIR . 'inc/pages/dashboard.php';

// Member profile pages (per-user CPT + BuddyPress embed)
require_once GS_DIR . 'inc/member-profile-pages.php';

// Member profile header (terminal-style header + nav bar)
require_once GS_DIR . 'inc/member-profile-header.php';

// Connections → Invite sub-tab (email/CSV → invite emails with affiliate URL)
require_once GS_DIR . 'inc/profile-invite.php';
require_once GS_DIR . 'inc/profile-invite-oauth.php';
require_once GS_DIR . 'inc/profile-invite-settings.php';

// Custom Login Styling
require_once GS_DIR . 'inc/login-style.php';

// OAuth login replacement — replaces wp-login.php with a "Sign in with
// gend.me" flow on every site that has gend-society active EXCEPT
// gend.me itself. Plays nice with login-style.php (CSS + animations
// still apply on action=lostpassword/register where we fall through
// to the native form).
require_once GS_DIR . 'inc/oauth-login.php';

// gend.me portal handshake + support access + feature gating
require_once GS_DIR . 'inc/portal-connect.php';
require_once GS_DIR . 'inc/support-access.php';
require_once GS_DIR . 'inc/feature-gates.php';
