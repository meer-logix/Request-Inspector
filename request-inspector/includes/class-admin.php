<?php
/**
 * Native admin integration and asset loading.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-scoped admin surface.
 */
final class Admin {
	/**
	 * Register native menus.
	 *
	 * @return void
	 */
	public function menu() {
		$cap = Settings::get()['view_capability'];
		add_menu_page( __( 'Request Inspector', 'request-inspector' ), __( 'Request Inspector', 'request-inspector' ), $cap, 'request-inspector', array( $this, 'render' ), 'dashicons-search', 80 );
		add_submenu_page( 'request-inspector', __( 'Request Explorer', 'request-inspector' ), __( 'Request Explorer', 'request-inspector' ), $cap, 'request-inspector', array( $this, 'render' ) );
		add_submenu_page( 'request-inspector', __( 'Database', 'request-inspector' ), __( 'Database', 'request-inspector' ), $cap, 'request-inspector-database', array( $this, 'render' ) );
		add_submenu_page( 'request-inspector', __( 'PHP Errors', 'request-inspector' ), __( 'PHP Errors', 'request-inspector' ), $cap, 'request-inspector-php', array( $this, 'render' ) );
		add_submenu_page( 'request-inspector', __( 'Settings', 'request-inspector' ), __( 'Settings', 'request-inspector' ), 'manage_options', 'request-inspector-settings', array( $this, 'render' ) );
	}
	/**
	 * Assets.
	 *
	 * @param string $hook Admin hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( ! str_contains( $hook, 'request-inspector' ) || ! current_user_can( Settings::get()['view_capability'] ) ) {
			return; }
		$file = REQUEST_INSPECTOR_DIR . 'admin/build/index.asset.php';
		if ( ! file_exists( $file ) ) {
			return; }
		$asset = require $file;
		wp_enqueue_script( 'request-inspector', plugins_url( 'admin/build/index.js', REQUEST_INSPECTOR_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'request-inspector', plugins_url( 'admin/build/index.css', REQUEST_INSPECTOR_FILE ), array(), $asset['version'] );
		wp_enqueue_style( 'request-inspector-layout', plugins_url( 'admin/build/style-index.css', REQUEST_INSPECTOR_FILE ), array( 'request-inspector' ), $asset['version'] );
		wp_style_add_data( 'request-inspector', 'rtl', 'replace' );
		wp_style_add_data( 'request-inspector-layout', 'rtl', 'replace' );
		wp_set_script_translations( 'request-inspector', 'request-inspector', REQUEST_INSPECTOR_DIR . 'languages' );
	}
	/**
	 * Render escaped app mount.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::get()['view_capability'] ) ) {
			return; }
		$logo = plugins_url( 'asserts/logo.png', REQUEST_INSPECTOR_FILE );
		echo '<div id="request-inspector-app" class="ri-app" data-logo="' . esc_url( $logo ) . '"><div class="ri-skeleton" role="status"><img src="' . esc_url( $logo ) . '" width="56" height="56" alt="" /><span class="screen-reader-text">' . esc_html__( 'Loading Request Inspector', 'request-inspector' ) . '</span></div></div>';
		if ( ! file_exists( REQUEST_INSPECTOR_DIR . 'admin/build/index.asset.php' ) ) {
			echo '<p>' . esc_html__( 'Build assets are missing. Install a complete release package.', 'request-inspector' ) . '</p>'; }
	}
}
