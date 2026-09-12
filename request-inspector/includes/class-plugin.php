<?php
/**
 * WordPress lifecycle integration.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks plugin services into WordPress.
 */
final class Plugin {
	/**
	 * The single active recorder for this request.
	 *
	 * @var Recorder|null The single active recorder for this request.
	 */
	public static $recorder;
	/**
	 * Register services.
	 *
	 * @return void
	 */
	public static function boot() {
		Live_Inspector::boot();
		add_action( 'rest_api_init', array( new Api(), 'register' ) );
		add_action( 'rest_api_init', array( new Advanced(), 'register' ) );
		add_action( 'rest_api_init', array( new Workflows(), 'register' ) );
		$admin = new Admin();
		add_action( 'admin_menu', array( $admin, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'assets' ) );
		add_action( 'request_inspector_cleanup', array( self::class, 'cleanup' ) );
		add_action( 'wp_initialize_site', array( self::class, 'new_site' ), 200 );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
		add_action( 'admin_init', array( self::class, 'upgrade' ) );
		add_action( 'plugins_loaded', array( self::class, 'capture' ), 0 );
	}
	/**
	 * Activate.
	 *
	 * @param bool $network_wide Network activation.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			do {
				$sites = get_sites(
					array(
						'number' => 100,
						'offset' => $offset,
						'fields' => 'ids',
					)
				);
				foreach ( $sites as $site ) {
					switch_to_blog( $site );
					self::install_site();
					restore_current_blog(); }
				$offset += 100;
				$count   = count( $sites );
			} while ( 100 === $count );
		} else {
			self::install_site(); }
	}
	/**
	 * Install current site.
	 *
	 * @return void
	 */
	private static function install_site() {
		( new Storage() )->install();
		Live_Store::install();
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		if ( ! wp_next_scheduled( 'request_inspector_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'request_inspector_cleanup' ); }
	}
	/**
	 * New site.
	 *
	 * @param \WP_Site $site New site.
	 * @return void
	 */
	public static function new_site( $site ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active_for_network( plugin_basename( REQUEST_INSPECTOR_FILE ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			self::install_site();
			restore_current_blog(); }
	}
	/**
	 * Deactivate.
	 *
	 * @param bool $network_wide Network deactivation.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( ! $network_wide || ! is_multisite() ) {
			wp_clear_scheduled_hook( 'request_inspector_cleanup' );
			return;
		}
		$offset = 0;
		do {
			$sites = $network_wide && is_multisite() ? get_sites(
				array(
					'number' => 100,
					'offset' => $offset,
					'fields' => 'ids',
				)
			) : array( get_current_blog_id() );
			foreach ( $sites as $site ) {
				switch_to_blog( $site );
				try {
					wp_clear_scheduled_hook( 'request_inspector_cleanup' );
				} finally {
					restore_current_blog();
				}
			}
			$offset += 100;
			$count   = count( $sites );
		} while ( $network_wide && 100 === $count );
	}
	/**
	 * Run bounded current-site maintenance.
	 *
	 * @return void
	 */
	public static function cleanup() {
		Audit::cleanup();
		Live_Store::cleanup();
		( new Storage() )->cleanup(); }
	/**
	 * Add recommended privacy text.
	 *
	 * @return void
	 */
	public static function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Request Inspector', 'request-inspector' ), wp_kses_post( __( 'When enabled by an administrator, Request Inspector stores local diagnostic metadata and optionally sanitized request/response bodies. Diagnostic content can contain personal data despite redaction. Review recording scope and retention, restrict access, and purge captures when debugging is finished. The plugin sends no telemetry to an external service.', 'request-inspector' ) ) );
		}
	}
	/**
	 * Begin recording after plugin bootstrap.
	 *
	 * @return void
	 */
	public static function capture() {
		$settings = Settings::get();
		if ( ! $settings['enabled'] || wp_installing() ) {
			return; }
		if ( $settings['capture_cidrs'] ) {
			$peer      = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
			if ( ! Policy::matches( Policy::client( $peer, $forwarded, $settings['trusted_proxies'] ), $settings['capture_cidrs'] ) ) {
				return;
			}
		}
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		// Exclude management calls before collectors start, including plain-permalink REST routes.
		if ( str_contains( rawurldecode( $uri ), 'request-inspector/v1' ) || str_contains( $uri, 'page=request-inspector' ) ) {
			return; }
		try {
			$recorder = new Recorder( $settings );
			if ( $recorder->register() ) {
				self::$recorder = $recorder;
			}
		} catch ( \Throwable $exception ) {
			// Observability failure must not break the site or log application data.
			return;
		}
	}

	/**
	 * Upgrade the current site's schema on authorized dashboard access.
	 *
	 * @return void
	 */
	public static function upgrade() {
		if ( current_user_can( 'manage_options' ) && ( get_option( 'request_inspector_schema' ) !== REQUEST_INSPECTOR_VERSION || 1 !== (int) get_option( 'request_inspector_live_schema' ) ) ) {
			self::install_site();
		}
	}
}
