<?php
/**
 * Plugin Name: ACF Bulk Price Update
 * Description: Bulk update numeric ACF/post meta price fields with chunked processing, snapshot history, and rollback.
 * Version: 1.0.1
 * Author: ABPU
 * Text Domain: acf-bulk-price-update
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ABPU_VERSION', '1.0.1' );
define( 'ABPU_PLUGIN_FILE', __FILE__ );
define( 'ABPU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABPU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ABPU_PLUGIN_DIR . 'includes/class-abpu-activator.php';
require_once ABPU_PLUGIN_DIR . 'includes/class-abpu-db.php';
require_once ABPU_PLUGIN_DIR . 'includes/class-abpu-updater.php';
require_once ABPU_PLUGIN_DIR . 'includes/class-abpu-ajax.php';
require_once ABPU_PLUGIN_DIR . 'includes/class-abpu-admin.php';

register_activation_hook( __FILE__, array( 'ABPU_Activator', 'activate' ) );

/**
 * Bootstrap plugin hooks.
 */
function abpu_init() {
	ABPU_Ajax::init();
	if ( is_admin() ) {
		ABPU_Admin::init();
	}
}
add_action( 'plugins_loaded', 'abpu_init' );
