<?php
/**
 * Plugin Name: Request Inspector
 * Description: Inspect sanitized WordPress HTTP requests, database queries and PHP diagnostics in your dashboard.
 * Version: 0.10.2
 * Author: Hammad Farooq Meer
 * Author URI: https://hammadmeer.netlify.app
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: request-inspector
 *
 * @package RequestInspector
 */

namespace RequestInspector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REQUEST_INSPECTOR_VERSION', '0.10.2' );
define( 'REQUEST_INSPECTOR_FILE', __FILE__ );
define( 'REQUEST_INSPECTOR_DIR', plugin_dir_path( __FILE__ ) );

require_once REQUEST_INSPECTOR_DIR . 'includes/class-settings.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-policy.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-redactor.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-storage.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-audit.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-attribution.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-hooks.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-exports.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-recorder.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-live-store.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-live-collectors.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-live-inspector.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-api.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-advanced.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-workflows.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-admin.php';
require_once REQUEST_INSPECTOR_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );
Plugin::boot();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once REQUEST_INSPECTOR_DIR . 'includes/class-cli.php';
	\WP_CLI::add_command( 'request-inspector', new Cli() );
}
