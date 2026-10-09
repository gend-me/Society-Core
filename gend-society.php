<?php
/**
 * Plugin Name: GenD Society
 * Plugin URI:  https://gend.me
 * Description: Futuristic glassmorphic WordPress admin experience with custom menus, redesigned backend, and dynamic frontend sidebar.
 * Version:     1.1.7
 * Author:      By GenD
 * Author URI:  https://gend.me
 * Network:     true
 * Text Domain: gend-society
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GEND_SOCIETY_VERSION', '1.1.7');
define('GEND_SOCIETY_DIR', plugin_dir_path(__FILE__));
define('GEND_SOCIETY_URL', plugin_dir_url(__FILE__));
define('GEND_SOCIETY_FILE', __FILE__);

// GenD Match v12.0 Phase 86 — Tier B counsel gate (default false, DOM-absent +
// route-404 when off). Market auto-creation + subsidy funding are gated on this;
// the engine METHODS and the outcome recorder run flag-independent. Guarded so an
// operator can pre-define it truthy in wp-config without being clobbered, and so a
// re-define never fires. Defined BEFORE the collab requires so every collab class
// sees it.
if ( ! defined( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC' ) ) {
    define( 'GEND_SOCIETY_COLLAB_MARKET_PUBLIC', false );
}

// Modules load through the manifest (inc/bootstrap/manifest.php): one ordered
// table of every inc/ file with its tier (core|customer|container|hub|updater)
// and needs. The loader requires the entries allowed in the current runtime
// mode (inc/bootstrap/context.php: hub|container|standalone), in order, each
// file_exists-guarded. Add new modules to the manifest, not here. These two
// bootstrap requires are deliberately unguarded: a missing loader must fail
// loudly rather than silently load nothing.
require_once GEND_SOCIETY_DIR . 'inc/bootstrap/context.php';
require_once GEND_SOCIETY_DIR . 'inc/bootstrap/loader.php';
gend_society_load_modules();
