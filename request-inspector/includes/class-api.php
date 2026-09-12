<?php
/**
 * Capability-protected REST interface.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard REST controller.
 */
final class Api {
	/**
	 * Serialize an export with an atomic per-operator rate limit.
	 *
	 * @param \WP_REST_Request $request Export request.
	 * @return array|\WP_Error
	 */
	public function export( $request ) {
		global $wpdb;
		$key  = 'ri_export_' . get_current_blog_id() . '_' . get_current_user_id();
		$lock = substr( $key, 0, 64 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cross-worker export lock cannot be cached.
			return new \WP_Error( 'ri_busy', __( 'An export is already running.', 'request-inspector' ), array( 'status' => 429 ) );
		}
		try {
			if ( get_transient( $key ) ) {
				return new \WP_Error( 'ri_rate', __( 'Wait a few seconds before exporting again.', 'request-inspector' ), array( 'status' => 429 ) );
			}
			set_transient( $key, true, 3 );
			if ( ! Audit::add( 'export', (int) $request['id'] ) ) {
				return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
			}
			return Exports::create( $request['id'], $request['format'] );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release connection-owned export lock.
		}
	}

	/**
	 * View.
	 *
	 * @return bool View permission.
	 */
	public function view() {
		return current_user_can( Settings::get()['view_capability'] ); }
	/**
	 * Manage.
	 *
	 * @return bool Management permission.
	 */
	public function manage() {
		return current_user_can( 'manage_options' ); }

	/**
	 * Register authenticated routes.
	 *
	 * @return void
	 */
	public function register() {
		$filters = array();
		foreach ( array( 'search', 'url', 'component', 'type', 'method', 'direction', 'event_type', 'sort', 'after', 'before', 'trace_id' ) as $name ) {
			$filters[ $name ] = array(
				'type'              => 'string',
				'maxLength'         => 191,
				'sanitize_callback' => 'sanitize_text_field',
			);
		}
		foreach ( array( 'page', 'per_page', 'status_min', 'status_max', 'duration_min', 'duration_max' ) as $name ) {
			$filters[ $name ] = array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 'page' === $name ? 10000 : ( 'per_page' === $name ? 100 : 1000000 ),
			);
		}
		$filters['severity']        = array(
			'type' => 'string',
			'enum' => array( '', 'fatal', 'warning', 'notice', 'deprecated', 'not_deprecated' ),
		);
		$filters['slow_only']       = array( 'type' => 'boolean' );
		$filters['duplicates_only'] = array( 'type' => 'boolean' );
		$filters['error_only']      = array( 'type' => 'boolean' );
		$filters['include_samples'] = array( 'type' => 'boolean' );
		$routes                     = array(
			'/requests/(?P<id>\d+)/export' => array(
				'POST',
				'export',
				true,
				array(
					'format' => array(
						'type'    => 'string',
						'enum'    => array( 'json', 'har', 'curl', 'sql', 'bodies' ),
						'default' => 'json',
					),
				),
			),
			'/requests'                    => array( 'GET', 'requests', false, $filters ),
			'/stats'                       => array( 'GET', 'stats', false, $filters ),
			'/events'                      => array( 'GET', 'events', false, $filters ),
			'/requests/(?P<id>\d+)'        => array( 'GET', 'detail', false, array() ),
			'/requests/(?P<id>\d+)/events' => array( 'GET', 'events', false, $filters ),
			'/requests/(?P<id>\d+)/bodies' => array( 'GET', 'bodies', false, array() ),
			'/settings'                    => array( 'GET', 'settings', false, array() ),
			'/health'                      => array( 'GET', 'health', false, array() ),
			'/components'                  => array( 'GET', 'components', false, array() ),
			'/recording/sessions'          => array(
				'POST',
				'arm',
				true,
				array(
					'count' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => 1000,
						'required' => true,
					),
				),
			),
			'/sample-data'                 => array( 'POST', 'samples', true, array() ),
			'/jobs/purge'                  => array( 'GET', 'purge_status', true, array() ),
		);
		foreach ( $routes as $route => $definition ) {
			register_rest_route(
				'request-inspector/v1',
				$route,
				array(
					'methods'             => $definition[0],
					'callback'            => array( $this, $definition[1] ),
					'permission_callback' => array( $this, $definition[2] ? 'manage' : 'view' ),
					'args'                => $definition[3],
				)
			);
		}
		register_rest_route(
			'request-inspector/v1',
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => array( $this, 'manage' ),
			)
		);
		register_rest_route(
			'request-inspector/v1',
			'/requests',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'manage' ),
				'args'                => array(
					'confirm' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'request-inspector/v1',
			'/requests/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'manage' ),
			)
		);
		// Separate, privileged test route is intentionally eligible for normal collection.
		register_rest_route(
			'request-inspector-test/v1',
			'/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'manage' ),
			)
		);
		add_filter( 'rest_post_dispatch', array( $this, 'private_headers' ), 10, 3 );
	}

	/**
	 * Private headers.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function private_headers( $response, $server, $request ) {
		if ( str_starts_with( $request->get_route(), '/request-inspector/v1' ) && $response instanceof \WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
			$response->header( 'X-Content-Type-Options', 'nosniff' );
		}
		return $response;
	}

	/**
	 * Missing.
	 *
	 * @return \WP_Error Missing record error.
	 */
	private function missing() {
		return new \WP_Error( 'ri_missing', __( 'This capture has expired or was deleted.', 'request-inspector' ), array( 'status' => 404 ) ); }
	/**
	 * Requests.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function requests( $request ) {
		return ( new Storage() )->listing( $request->get_params() ); }
	/**
	 * Stats.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function stats( $request ) {
		return ( new Storage() )->stats( $request->get_params() ); }
	/**
	 * Detail.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function detail( $request ) {
		return ( new Storage() )->request( $request['id'] ) ?? $this->missing(); }
	/**
	 * Bodies.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function bodies( $request ) {
		$storage = new Storage();
		return $storage->request( $request['id'] ) ? $storage->bodies( $request['id'] ) : $this->missing();
	}
	/**
	 * Events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function events( $request ) {
		$filters = $request->get_params();
		$storage = new Storage();
		if ( isset( $request['id'] ) ) {
			if ( ! $storage->request( $request['id'] ) ) {
				return $this->missing(); }
			$filters['request_id'] = $request['id'];
		}
		return $storage->events( $filters );
	}
	/**
	 * Settings.
	 *
	 * @return array Current settings and operation permissions.
	 */
	public function settings() {
		return array(
			'settings'         => Settings::get(),
			'can_manage'       => $this->manage(),
			'detailed_allowed' => Settings::detailed( Settings::get() ),
		); }
	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function save_settings( $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'ri_json', __( 'Expected JSON settings.', 'request-inspector' ), array( 'status' => 400 ) ); }
		global $wpdb;
		$lock = 'ri_settings_' . get_current_blog_id();
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A live database lock serializes settings updates across workers; caching is incorrect.
			return new \WP_Error( 'ri_busy', __( 'Another settings update is in progress.', 'request-inspector' ), array( 'status' => 409 ) ); }
		try {
			wp_cache_delete( Settings::OPTION, 'options' );
			$next = Settings::validate( $input );
			if ( is_wp_error( $next ) ) {
				return $next; }
			if ( ! Audit::add( 'policy_change' ) ) {
				return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable; settings were not changed.', 'request-inspector' ), array( 'status' => 503 ) );
			}
			if ( ! update_option( Settings::OPTION, $next, false ) ) {
				return new \WP_Error( 'ri_save', __( 'Settings could not be saved.', 'request-inspector' ), array( 'status' => 500 ) ); }
			return $this->settings();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the connection-owned settings lock.
	}
	/**
	 * Health.
	 *
	 * @return array Storage and collector state.
	 */
	public function health() {
		return array(
			'version'            => REQUEST_INSPECTOR_VERSION,
			'wp'                 => get_bloginfo( 'version' ),
			'php'                => PHP_VERSION,
			'state'              => ( new Storage() )->state(),
			'database_available' => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
			'detailed_allowed'   => Settings::detailed( Settings::get() ),
			'next_cleanup'       => wp_next_scheduled( 'request_inspector_cleanup' ),
		);
	}
	/**
	 * Components.
	 *
	 * @return array Available component identifiers.
	 */
	public function components() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array( 'Core', 'Unknown' );
		foreach ( array_keys( get_plugins() ) as $file ) {
			$out[] = explode( '/', $file )[0]; }
		foreach ( array_keys( wp_get_themes() ) as $theme ) {
			$out[] = $theme; }
		return array_values( array_unique( $out ) );
	}
	/**
	 * Arm.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function arm( $request ) {
		$settings = Settings::get();
		if ( ! in_array( $settings['mode'], array( 'next', 'current' ), true ) || ! $settings['enabled'] ) {
			return new \WP_Error( 'ri_arm', __( 'Enable Current Request or Next N mode before arming.', 'request-inspector' ), array( 'status' => 400 ) ); }
		$token = 'current' === $settings['mode'] ? bin2hex( random_bytes( 24 ) ) : '';
		( new Storage() )->arm( $token ? 1 : $request['count'], $token ? hash( 'sha256', $token ) : '' );
		return array(
			'expires_in' => 600,
			'target_url' => $token ? add_query_arg( 'ri_capture', $token, home_url( '/' ) ) : null,
		);
	}
	/**
	 * Delete.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function delete( $request ) {
		if ( ! Audit::add( 'delete_requested', (int) $request['id'] ) ) {
			return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		$storage = new Storage();
		$row     = $storage->request( $request['id'] );
		if ( ! $row ) {
			return $this->missing(); }
		if ( ! $storage->delete_trace( $row['trace_id'] ) ) {
			return new \WP_Error( 'ri_delete', __( 'Deletion could not finish. Retry or run maintenance.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		return array( 'deleted_trace' => $row['trace_id'] );
	}
	/**
	 * Purge.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function purge( $request ) {
		if ( ! Audit::add( 'purge_requested' ) ) {
			return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		if ( true !== $request['confirm'] ) {
			return new \WP_Error( 'ri_confirm', __( 'Purge must be explicitly confirmed.', 'request-inspector' ), array( 'status' => 400 ) ); }
		return array(
			'generation' => ( new Storage() )->purge(),
			'job'        => 'purge',
		);
	}
	/**
	 * Purge status.
	 *
	 * @return array Maintenance progress.
	 */
	public function purge_status() {
		$storage = new Storage();
		$storage->cleanup();
		global $wpdb;
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE generation<%d', $storage->tables['requests'], $storage->state()['generation'] ?? 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read current purge progress from plugin-owned tables.
		return array(
			'remaining' => $remaining,
			'done'      => 0 === $remaining,
		);
	}
	/**
	 * Ping.
	 *
	 * @return array Privileged deterministic test result.
	 */
	public function ping() {
		global $wpdb;
		$wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicit administrator-triggered diagnostic fixture must execute a real query.
		return array(
			'message' => __( 'Test request completed. Recording policy determines whether it is retained.', 'request-inspector' ),
			'time'    => gmdate( 'c' ),
		);
	}
	/**
	 * Samples.
	 *
	 * @return array|\WP_Error Insert one clearly marked sample trace.
	 */
	public function samples() {
		$storage = new Storage();
		if ( $storage->listing(
			array(
				'include_samples' => true,
				'search'          => '/sample/request-inspector',
			)
		)['total'] > 0 ) {
			return array( 'created' => false ); }
		$row = array(
			'trace_id'    => wp_generate_uuid4(),
			'direction'   => 'incoming',
			'type'        => 'rest',
			'method'      => 'GET',
			'url'         => '/sample/request-inspector',
			'status'      => 200,
			'duration_us' => 25000,
			'component'   => 'Core',
			'is_sample'   => 1,
			'started_at'  => gmdate( 'Y-m-d H:i:s' ),
			'metadata'    => array(
				'sample'   => true,
				'coverage' => 'synthetic_sample',
				'queries'  => 0,
				'issues'   => 0,
			),
			'bodies'      => array(
				'response' => ( new Redactor() )->body(
					array(
						'message' => 'Synthetic example',
						'token'   => 'sample-only',
					),
					'application/json',
					true
				),
			),
		);
		return $storage->write( array( $row ), (int) $storage->state()['generation'] ) ? array( 'created' => true ) : new \WP_Error( 'ri_storage', __( 'Storage capacity is unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
	}
}
