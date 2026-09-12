<?php
/**
 * Integrated toolbar, session-bound transport and live snapshot presentation.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** One optional live consumer of the existing Request Inspector recorder. */
final class Live_Inspector {
	/**
	 * Current request's live consumer.
	 *
	 * @var self|null Current request's live consumer.
	 */
	private static $current;
	/**
	 * Immutable authenticated identity.
	 *
	 * @var array Immutable authenticated identity.
	 */
	private $identity;
	/**
	 * Collection policy.
	 *
	 * @var array Collection policy.
	 */
	private $settings;
	/**
	 * Passive collector registry.
	 *
	 * @var Live_Collectors Passive collector registry.
	 */
	private $collectors;
	/**
	 * Whether eligible HTML actually mounted a dock root.
	 *
	 * @var bool Whether eligible HTML actually mounted a dock root.
	 */
	private $mounted = false;

	/** Register through the existing plugin bootstrap. */
	public static function boot() {
		add_action( 'init', array( self::class, 'start' ), 0 );
		add_action( 'admin_bar_menu', array( self::class, 'menu' ), 90 );
		add_action( 'admin_bar_menu', array( self::class, 'context_menu' ), 91 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_footer', array( self::class, 'mount' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( self::class, 'mount' ), PHP_INT_MAX );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**

	 * Site-qualified user preference key.
	 *
	 * @return string Site-qualified user preference key.
	 */
	public static function preference_key() {
		return 'request_inspector_live_' . get_current_blog_id();
	}

	/**
	 * Default to enabled while preserving an explicitly saved account preference.
	 *
	 * @return bool Whether the current account enables live inspection.
	 */
	public static function account_enabled() {
		$user = get_current_user_id();
		$key  = self::preference_key();
		return $user > 0 && ( ! metadata_exists( 'user', $user, $key ) || (bool) get_user_meta( $user, $key, true ) );
	}

	/**

	 * Current user's existing diagnostic permission.
	 *
	 * @return bool Current user's existing diagnostic permission.
	 */
	public static function permission() {
		return is_user_logged_in() && current_user_can( Settings::get()['view_capability'] );
	}

	/**

	 * Registry shared by toolbar and panel navigation.
	 *
	 * @return array Registry shared by toolbar and panel navigation.
	 */
	public static function panels() {
		return array(
			'overview'     => __( 'Overview', 'request-inspector' ),
			'timeline'     => __( 'Timeline', 'request-inspector' ),
			'database'     => __( 'Database Queries', 'request-inspector' ),
			'timings'      => __( 'Timings', 'request-inspector' ),
			'logs'         => __( 'Logs', 'request-inspector' ),
			'request'      => __( 'Request', 'request-inspector' ),
			'admin'        => __( 'Admin Screen', 'request-inspector' ),
			'scripts'      => __( 'Scripts', 'request-inspector' ),
			'styles'       => __( 'Styles', 'request-inspector' ),
			'hooks'        => __( 'Hooks & Actions', 'request-inspector' ),
			'languages'    => __( 'Languages', 'request-inspector' ),
			'http'         => __( 'HTTP API Calls', 'request-inspector' ),
			'transients'   => __( 'Transient Updates', 'request-inspector' ),
			'caps'         => __( 'Capability Checks', 'request-inspector' ),
			'environment'  => __( 'Environment', 'request-inspector' ),
			'conditionals' => __( 'Conditionals', 'request-inspector' ),
			'php'          => __( 'PHP Diagnostics', 'request-inspector' ),
		);
	}

	/** Begin live observation only after user authentication is available. */
	public static function start() {
		$settings = Settings::get();
		if ( ! $settings['live_enabled'] || ! self::permission() || ! self::account_enabled() || ! Live_Store::session() || 1 !== (int) get_option( 'request_inspector_live_schema' ) || wp_installing() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( str_contains( rawurldecode( $uri ), 'request-inspector/v1' ) || str_contains( $uri, 'page=request-inspector' ) || str_contains( $uri, 'wp-login.php' ) || str_contains( $uri, 'wp-json/' ) || str_contains( $uri, 'rest_route=' ) || ( ! is_admin() && ! $settings['live_frontend'] ) ) {
			return;
		}
		if ( $settings['capture_cidrs'] && ! Policy::matches( Policy::client( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) ), $settings['trusted_proxies'] ), $settings['capture_cidrs'] ) ) {
			return;
		}
		try {
			$recorder = Plugin::$recorder;
			if ( ! $recorder ) {
				$live_policy         = $settings;
				$live_policy['mode'] = 'all';
				$recorder            = new Recorder( $live_policy );
				$recorder->register( false );
				Plugin::$recorder = $recorder;
			}
			$busy          = Storage::$busy;
			Storage::$busy = true;
			try {
				$generation = (int) ( ( new Storage() )->state()['generation'] ?? 0 );
			} finally {
				Storage::$busy = $busy;
			}
			self::$current             = new self();
			self::$current->settings   = $settings;
			self::$current->identity   = array(
				'uuid'       => $recorder->uuid,
				'site'       => get_current_blog_id(),
				'owner'      => get_current_user_id(),
				'session'    => Live_Store::session(),
				'expires'    => time() + 600,
				'generation' => $generation,
			);
			$recorder->live            = self::$current;
			self::$current->collectors = new Live_Collectors( $recorder, $settings );
			if ( ! headers_sent() ) {
				nocache_headers();
			}
		} catch ( \Throwable $exception ) {
			self::$current = null;
		}
	}

	/**

	 * Whether this response supports a toolbar dock.
	 *
	 * @return bool Whether this response supports a toolbar dock.
	 */
	private static function supported() {
		if ( ! self::$current || ! is_admin_bar_showing() || ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_embed() || is_robots() || is_trackback() ) {
			return false;
		}
		$code = http_response_code();
		if ( $code && ( $code < 200 || $code >= 300 ) ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			if ( str_starts_with( strtolower( $header ), 'content-disposition:' ) || ( str_starts_with( strtolower( $header ), 'content-type:' ) && ! str_contains( strtolower( $header ), 'text/html' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**

	 * Core-owned toolbar.
	 *
	 * @param \WP_Admin_Bar $bar Core-owned toolbar.
	 */
	public static function menu( $bar ) {
		if ( ! Settings::get()['live_enabled'] || ! self::permission() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'request-inspector-live',
				'title' => esc_html__( 'Request Inspector', 'request-inspector' ),
				'href'  => admin_url( 'admin.php?page=request-inspector-settings' ),
			)
		);
		if ( ! self::supported() ) {
			$bar->add_node(
				array(
					'id'     => 'request-inspector-live-settings',
					'parent' => 'request-inspector-live',
					'title'  => esc_html__( 'Live settings / unavailable on this request', 'request-inspector' ),
					'href'   => admin_url( 'admin.php?page=request-inspector-settings' ),
				)
			);
			return;
		}
		foreach ( self::panels() as $id => $label ) {
			$bar->add_node(
				array(
					'id'     => 'request-inspector-live-' . $id,
					'parent' => 'request-inspector-live',
					'title'  => esc_html( $label ),
					'href'   => '#ri-live-' . $id,
				)
			);
		}
	}

	/**
	 * Add true context shortcuts without interpreting them as permissions.
	 *
	 * @param \WP_Admin_Bar $bar Core toolbar.
	 */
	public static function context_menu( $bar ) {
		if ( ! self::supported() ) {
			return;
		}
		foreach ( array( 'is_admin', 'is_blog_admin', 'is_network_admin' ) as $conditional ) {
			if ( call_user_func( $conditional ) ) {
				$bar->add_node(
					array(
						'id'     => 'request-inspector-live-context-' . $conditional,
						'parent' => 'request-inspector-live',
						'title'  => esc_html( $conditional . '()' ),
						'href'   => '#ri-live-conditionals',
					)
				);
			}
		}
	}

	/** Enqueue the feature entry through the existing WordPress runtime. */
	public static function assets() {
		if ( ! self::supported() ) {
			return;
		}
		$file = REQUEST_INSPECTOR_DIR . 'admin/build/live.asset.php';
		if ( ! is_file( $file ) ) {
			return;
		}
		$asset = require $file;
		wp_enqueue_script( 'request-inspector-live', plugins_url( 'admin/build/live.js', REQUEST_INSPECTOR_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'request-inspector-live', plugins_url( 'admin/build/live.css', REQUEST_INSPECTOR_FILE ), array(), $asset['version'] );
		wp_style_add_data( 'request-inspector-live', 'rtl', 'replace' );
		wp_set_script_translations( 'request-inspector-live', 'request-inspector', REQUEST_INSPECTOR_DIR . 'languages' );
	}

	/**

	 * Signed admission ticket, still requiring session authorization.
	 *
	 * @return string Signed admission ticket, still requiring session authorization.
	 */
	private function ticket() {
		$body = base64_encode( wp_json_encode( $this->identity ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of signed identity JSON, never executable code.
		return $body . '.' . hash_hmac( 'sha256', $body, wp_salt( 'nonce' ) );
	}

	/** Output no captured data into the host document. */
	public static function mount() {
		if ( ! self::supported() || self::$current->mounted ) {
			return;
		}
		self::$current->mounted = true;
		$id                     = self::$current->identity['uuid'];
		$config                 = array(
			'uuid'          => $id,
			'panels'        => self::panels(),
			'expires'       => self::$current->identity['expires'],
			'summaryUrl'    => rest_url( 'request-inspector/v1/live/' . $id . '/summary' ),
			'panelUrl'      => rest_url( 'request-inspector/v1/live/' . $id . '/panels/' ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'ticket'        => self::$current->ticket(),
			'logo'          => plugins_url( 'asserts/logo.png', REQUEST_INSPECTOR_FILE ),
			'site'          => get_current_blog_id(),
			'preferenceKey' => 'ri-dock-' . get_current_blog_id() . '-' . get_current_user_id(),
			'dashboard'     => admin_url( 'admin.php?page=request-inspector' ),
		);
		echo '<div id="ri-live-inspector-root" data-config="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
	}

	/** Define integrated feature routes; no additional plugin namespace. */
	public static function routes() {
		register_rest_route(
			'request-inspector/v1',
			'/live/preferences',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( self::class, 'preferences' ),
				'permission_callback' => array( self::class, 'permission' ),
			)
		);
		register_rest_route(
			'request-inspector/v1',
			'/live/(?P<uuid>[a-f0-9-]{36})/(?P<view>summary|panels/(?P<panel>[a-z]+))',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'read' ),
				'permission_callback' => array( self::class, 'authorize' ),
			)
		);
	}

	/**
	 * Read or change only the authenticated user's own live preference.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function preferences( $request ) {
		if ( 'POST' === $request->get_method() ) {
			$value = $request->get_param( 'enabled' );
			if ( ! is_bool( $value ) ) {
				return new \WP_Error( 'ri_live_preference', __( 'Expected a boolean preference.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			update_user_meta( get_current_user_id(), self::preference_key(), $value );
		}
		return self::response(
			array(
				'enabled'      => self::account_enabled(),
				'site_enabled' => Settings::get()['live_enabled'],
				'schema_ready' => 1 === (int) get_option( 'request_inspector_live_schema' ),
			)
		);
	}

	/**
	 * Validate signature, current capability, owner, session and purge epoch.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool
	 */
	public static function authorize( $request ) {
		if ( ! self::permission() || ! Settings::get()['live_enabled'] || ! self::account_enabled() || 1 !== (int) get_option( 'request_inspector_live_schema' ) ) {
			return false;
		}
		$ticket = $request->get_header( 'x-ri-live-ticket' );
		if ( ! is_string( $ticket ) || strlen( $ticket ) > 2048 ) {
			return false;
		}
		$parts = explode( '.', $ticket );
		if ( count( $parts ) !== 2 || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) {
			return false;
		}
		$data = json_decode( (string) base64_decode( $parts[0], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode signed identity JSON only after signature validation.
		return is_array( $data ) && ( $data['uuid'] ?? '' ) === $request['uuid'] && ( $data['owner'] ?? 0 ) === get_current_user_id() && ( $data['site'] ?? 0 ) === get_current_blog_id() && ( $data['session'] ?? '' ) === Live_Store::session() && Live_Store::session() !== '' && ( $data['expires'] ?? 0 ) >= time() && ( $data['expires'] ?? 0 ) <= time() + 600 && ( $data['generation'] ?? -1 ) === (int) ( ( new Storage() )->state()['generation'] ?? 0 );
	}

	/**
	 * Return only the requested bounded panel or summary.
	 *
	 * @param \WP_REST_Request $request Authorized request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function read( $request ) {
		$data = Live_Store::get( $request['uuid'] );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! $data ) {
			return self::response(
				array(
					'code'    => 'ri_live_pending',
					'message' => __( 'Snapshot is not ready or could not be retained. Retry shortly.', 'request-inspector' ),
				),
				202
			);
		}
		$redactor = new Redactor( Settings::get()['custom_keys'] );
		if ( 'summary' === $request['view'] ) {
			$data['summary']['url'] = $redactor->url( $data['summary']['url'] );
			unset( $data['panels'] );
			return self::response( $data );
		}
		$panel = $request['panel'];
		if ( ! isset( $data['panels'][ $panel ] ) ) {
			return new \WP_Error( 'ri_live_panel', __( 'Unknown live panel.', 'request-inspector' ), array( 'status' => 404 ) );
		}
		$result = $data['panels'][ $panel ];
		// Re-redact each bounded row so stricter current policies apply to live reads.
		$result['rows'] = array_map( array( $redactor, 'value' ), $result['rows'] );
		return self::response( $result );
	}

	/**
	 * Private response helper.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function response( $data, $status = 200 ) {
		return new \WP_REST_Response(
			$data,
			$status,
			array(
				'Cache-Control' => 'private, no-store, max-age=0',
				'Pragma'        => 'no-cache',
			)
		);
	}

	/**
	 * Publish sanitized data only after the recorder's named freeze boundary.
	 *
	 * @param array       $root Frozen request.
	 * @param array       $children Frozen outgoing calls.
	 * @param string|null $trace Exact history link if retained.
	 */
	public function publish( array $root, array $children, $trace ) {
		if ( ! $this->mounted || ! Settings::get()['live_enabled'] || get_current_user_id() !== $this->identity['owner'] || Live_Store::session() !== $this->identity['session'] ) {
			return;
		}
		$redactor = new Redactor( $this->settings['custom_keys'] );
		$metadata = $root['metadata'];
		$panels   = array();
		foreach ( self::panels() as $id => $label ) {
			$panels[ $id ] = array(
				'id'       => $id,
				'label'    => $label,
				'status'   => 'available',
				'reason'   => '',
				'rows'     => array(),
				'dropped'  => 0,
				'coverage' => $metadata['coverage'],
			);
		}
		$summary                      = array(
			'elapsed_us'    => $metadata['request_elapsed_us'],
			'observed_us'   => $root['duration_us'],
			'memory_bytes'  => $metadata['memory_peak'],
			'query_count'   => $metadata['connection_queries'],
			'database_us'   => $metadata['database_available'] ? $metadata['query_us'] : null,
			'timed_queries' => $metadata['queries'],
			'issues'        => $metadata['issues'],
			'dropped'       => $metadata['dropped'],
			'coverage'      => $metadata['coverage'],
			'boundary'      => $metadata['freeze_boundary'],
			'method'        => $root['method'],
			'url'           => $root['url'],
			'status'        => $root['status'],
		);
		$panels['overview']['rows'][] = $summary;
		$panels['overview']['rows'][] = $metadata['live_environment'] ?? array();
		$panels['overview']['reason'] = __( 'Server request time ends at the recorder freeze boundary. Database time covers observed timed queries; it is not necessarily the entire connection total. Memory is the PHP allocated-process peak.', 'request-inspector' );
		$panels['request']['rows'][]  = array(
			'method'           => $root['method'],
			'url'              => $root['url'],
			'context'          => $root['type'],
			'status'           => $root['status'],
			'request_headers'  => $metadata['request_headers'],
			'response_headers' => $redactor->headers( $this->response_headers() ),
			'bodies'           => $root['bodies'],
		);
		foreach ( $children as $index => &$child ) {
			$child['live_id'] = 'http-' . $index;
		}
		unset( $child );
		global $wp;
		if ( ! is_admin() && $wp instanceof \WP && did_action( 'wp' ) ) {
			$panels['request']['rows'][] = $redactor->value(
				array(
					'matched_rule' => $wp->matched_rule,
					'query_vars'   => $wp->query_vars,
				)
			);
		}
		$panels['http']['rows']       = $children;
		$panels['timeline']['rows'][] = array(
			'name'            => __( 'Observed request', 'request-inspector' ),
			'event_type'      => 'execution',
			'start_offset_us' => 0,
			'duration_us'     => $root['duration_us'],
		);
		foreach ( $root['events'] as $index => $event ) {
			$event['live_id'] = 'event-' . $index;
			$type             = $event['event_type'];
			$panel            = array(
				'db_query' => 'database',
				'error'    => 'php',
				'hook'     => 'hooks',
			)[ $type ] ?? ( $event['metadata']['live_panel'] ?? '' );
			if ( 'timeline' !== $panel && isset( $panels[ $panel ] ) ) {
				$panels[ $panel ]['rows'][] = $event;
			}
			$panels['timeline']['rows'][] = array_intersect_key( $event, array_flip( array( 'name', 'live_id', 'event_type', 'component', 'start_offset_us', 'duration_us' ) ) ) + array( 'metadata' => array( 'live_panel' => $panel ) );
		}
		foreach ( $children as $child ) {
			$panels['timeline']['rows'][] = array(
				'name'            => $child['url'],
				'event_type'      => 'http',
				'live_id'         => $child['live_id'],
				'start_offset_us' => $child['metadata']['start_offset_us'] ?? null,
				'duration_us'     => $child['duration_us'],
			);
		}
		usort( $panels['timeline']['rows'], static fn( $left, $right ) => ( $left['start_offset_us'] ?? 0 ) <=> ( $right['start_offset_us'] ?? 0 ) );
		$panels['timings']['rows'] = array_merge( $panels['timings']['rows'], $this->collectors->incomplete() );
		foreach ( array(
			'database' => 'database_available',
			'php'      => 'php_available',
			'hooks'    => 'hooks_available',
		) as $panel => $field ) {
			if ( ! $metadata[ $field ] ) {
				$panels[ $panel ]['status'] = 'disabled';
				$panels[ $panel ]['reason'] = __( 'Collector disabled or constrained by environment policy. SQL timing additionally requires externally enabled SAVEQUERIES.', 'request-inspector' );
			}
		}
		foreach ( array( 'timings', 'logs', 'transients', 'caps' ) as $panel ) {
			if ( ! Settings::detailed( $this->settings ) || ! $this->settings[ 'caps' === $panel ? 'live_caps' : 'live_events' ] ) {
				$panels[ $panel ]['status'] = 'disabled';
				$panels[ $panel ]['reason'] = __( 'Enable this optional observer in Request Inspector settings for future requests.', 'request-inspector' );
			}
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen ) {
			$panels['admin']['rows'][] = $redactor->value(
				array(
					'id'          => $screen->id,
					'base'        => $screen->base,
					'post_type'   => $screen->post_type,
					'taxonomy'    => $screen->taxonomy,
					'parent_base' => $screen->parent_base,
				)
			);
		} else {
			$panels['admin']['status'] = 'not_applicable';
		}
		foreach ( array( 'scripts', 'styles' ) as $kind ) {
			$panels[ $kind ]['rows']   = $this->collectors->assets( $kind );
			$panels[ $kind ]['reason'] = __( 'WordPress registry state at snapshot time; printed does not prove browser execution. Inline content is excluded. Script modules are not included in this registry.', 'request-inspector' );
		}
		$panels['environment']['rows'][] = $metadata['live_environment'] ?? array(
			'wordpress'                => get_bloginfo( 'version' ),
			'php'                      => PHP_VERSION,
			'environment'              => wp_get_environment_type(),
			'site'                     => $this->identity['site'],
			'multisite'                => is_multisite(),
			'php_memory_limit'         => ini_get( 'memory_limit' ),
			'max_execution_seconds'    => ini_get( 'max_execution_time' ),
			'persistent_cache'         => wp_using_ext_object_cache(),
			'opcache_extension_loaded' => extension_loaded( 'Zend OPcache' ),
			'cache_hit_ratio'          => null,
			'cache_reason'             => __( 'No verified counter adapter is active; counters are unavailable.', 'request-inspector' ),
		);
		$panels['languages']['reason']   = sprintf( /* translators: 1: site locale, 2: user locale. */ __( 'Site locale: %1$s; user locale: %2$s. Translation entries describe observed load candidates, not confirmed success.', 'request-inspector' ), get_locale(), get_user_locale() );
		$conditions                      = array( 'is_admin', 'is_blog_admin', 'is_network_admin', 'is_user_admin', 'is_rtl', 'is_ssl' );
		if ( ! is_admin() && did_action( 'wp' ) ) {
			$conditions = array_merge( $conditions, array( 'is_404', 'is_archive', 'is_home', 'is_front_page', 'is_page', 'is_single', 'is_search', 'is_singular', 'is_feed', 'is_preview' ) );
		}
		foreach ( $conditions as $condition ) {
			$panels['conditionals']['rows'][] = array(
				'condition' => $condition . '()',
				'value'     => (bool) call_user_func( $condition ),
			);
		}
		$panels['caps']['reason']  = __( 'Observed user_has_cap filter-stage values are not guaranteed final authorization decisions; later filters and super-admin paths may differ.', 'request-inspector' );
		$panels['hooks']['reason'] = __( 'Hook occurrences and registered callbacks are observable; callback execution durations are unavailable.', 'request-inspector' );
		$registry                  = array();
		$remaining                 = 180000;
		foreach ( $panels as $id => &$panel ) {
			$panel_bytes             = 0;
			$panel['observed_count'] = count( $panel['rows'] );
			$panel['dropped']        = $this->collectors->dropped[ $id ] ?? 0;
			$accepted                = array();
			foreach ( $panel['rows'] as $row ) {
				$size = strlen( wp_json_encode( $row ) );
				if ( $size > 16000 || $size > $remaining || $panel_bytes + $size > 24000 || count( $accepted ) >= 100 ) {
					++$panel['dropped'];
					continue;
				}
				$remaining   -= $size;
				$panel_bytes += $size;
				$accepted[]   = $row;
			}
			$panel['rows'] = $accepted;
			if ( $panel['dropped'] && 'available' === $panel['status'] ) {
				$panel['status'] = 'partial';
			}
			$registry[] = array_diff_key( $panel, array( 'rows' => true ) );
		}
		unset( $panel );
		Live_Store::put(
			$this->identity,
			array(
				'schema'    => 'request-inspector/live/1',
				'uuid'      => $this->identity['uuid'],
				'site'      => $this->identity['site'],
				'expires'   => $this->identity['expires'],
				'summary'   => $summary,
				'registry'  => $registry,
				'panels'    => $panels,
				'trace'     => $trace,
				'retention' => $trace ? 'retained' : 'not_retained_by_history_policy_or_admission',
			)
		);
	}

	/**

	 * Response header names and values for shared redaction.
	 *
	 * @return array Response header names and values for shared redaction.
	 */
	private function response_headers() {
		$result = array();
		foreach ( headers_list() as $header ) {
			$parts = explode( ':', $header, 2 );
			if ( count( $parts ) === 2 ) {
				$result[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $result;
	}

	/**
	 * Developer log API; harmless when this feature is inactive.
	 *
	 * @param string $level Severity.
	 * @param string $message Message.
	 * @param array  $context Bounded diagnostic context.
	 * @return bool
	 */
	public static function log( $level, $message, array $context = array() ) {
		return self::$current ? self::$current->collectors->log( $level, $message, $context ) : false;
	}

	/**

	 * Timer label.
	 *
	 * @param string $name Timer label.

	 * @return string|null
	 */
	public static function timer_start( $name ) {
		return self::$current ? self::$current->collectors->start( $name ) : null;
	}

	/**

	 * Timer handle.
	 *
	 * @param string $handle Timer handle.

	 * @return bool
	 */
	public static function timer_stop( $handle ) {
		return self::$current ? self::$current->collectors->stop( $handle ) : false;
	}

	/**

	 * Timer handle.
	 *
	 * @param string $handle Timer handle.

	 * @return bool
	 */
	public static function timer_lap( $handle ) {
		return self::$current ? self::$current->collectors->stop( $handle, true ) : false;
	}
}
