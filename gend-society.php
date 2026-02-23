<?php
/**
 * Plugin Name: GenD Society
 * Plugin URI:  https://gend.me
 * Description: Futuristic glassmorphic WordPress admin experience with custom menus, redesigned backend, and dynamic frontend sidebar.
 * Version:     1.0.0
 * Author:      GenD
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

// Dashboard overrides (Standalone)
require_once GS_DIR . 'inc/dashboard-overview.php';
require_once GS_DIR . 'inc/feature-cards.php';
require_once GS_DIR . 'inc/pages/dashboard.php';
