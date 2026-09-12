<?php
/**
 * Bounded optional observations for the integrated live inspector.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Passive collectors share the recorder's event budget. */
final class Live_Collectors {
	/**
	 * Request-local recorder.
	 *
	 * @var Recorder Request-local recorder.
	 */
	private $recorder;
	/**
	 * Shared sanitization policy.
	 *
	 * @var Redactor Shared sanitization policy.
	 */
	private $redactor;
	/**
	 * Admission counters by collector.
	 *
	 * @var array Admission counters by collector.
	 */
	private $counts = array();
	/**
	 * Dropped observations by collector.
	 *
	 * @var array Dropped observations by collector.
	 */
	public $dropped = array();
	/**
	 * Open explicit timers.
	 *
	 * @var array Open explicit timers.
	 */
	private $timers = array();
	/**
	 * Observer recursion guard.
	 *
	 * @var bool Observer recursion guard.
	 */
	private $busy = false;
	/**
	 * Optional developer event collection.
	 *
	 * @var bool Optional developer event collection.
	 */
	private $events;
	/**
	 * Origin site.
	 *
	 * @var int Origin site.
	 */
	private $site;

	/**
	 * Register supported passive observation hooks.
	 *
	 * @param Recorder $recorder Shared recorder.
	 * @param array    $settings Validated settings.
	 */
	public function __construct( Recorder $recorder, array $settings ) {
		$this->recorder = $recorder;
		$this->site     = get_current_blog_id();
		$this->redactor = new Redactor( $settings['custom_keys'] );
		$this->events   = $settings['live_events'] && Settings::detailed( $settings );
		foreach ( array( 'wp_loaded', 'parse_request', 'wp', 'template_redirect', 'admin_init', 'current_screen', 'wp_enqueue_scripts', 'admin_enqueue_scripts', 'wp_footer', 'admin_footer' ) as $hook ) {
			add_action( $hook, array( $this, 'lifecycle' ), PHP_INT_MAX, 0 );
		}
		add_filter( 'load_translation_file', array( $this, 'translation' ), PHP_INT_MAX, 3 );
		add_filter( 'load_script_translation_file', array( $this, 'script_translation' ), PHP_INT_MAX, 3 );
		if ( $this->events ) {
			$prefix = version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ? 'set' : 'setted';
			add_action( $prefix . '_transient', array( $this, 'transient' ), 10, 3 );
			add_action( $prefix . '_site_transient', array( $this, 'site_transient' ), 10, 3 );
			add_action( 'deleted_transient', array( $this, 'deleted_transient' ), 10, 1 );
			add_action( 'deleted_site_transient', array( $this, 'deleted_site_transient' ), 10, 1 );
		}
		if ( $settings['live_caps'] && Settings::detailed( $settings ) ) {
			add_filter( 'user_has_cap', array( $this, 'capabilities' ), PHP_INT_MAX, 3 );
		}
	}

	/** Observe named lifecycle milestones without retaining hook arguments. */
	public function lifecycle() {
		$this->record(
			'timeline',
			current_filter(),
			array(
				'kind'        => 'lifecycle',
				'origin_site' => $this->site,
			)
		);
	}

	/**
	 * Retain a sanitized event without re-entering observers.
	 *
	 * @param string   $panel Panel identifier.
	 * @param string   $name Event label.
	 * @param array    $data Scalar diagnostic context.
	 * @param int|null $duration Measured duration.
	 * @param int|null $offset Monotonic start offset.
	 * @return bool
	 */
	private function record( $panel, $name, array $data, $duration = null, $offset = null ) {
		if ( $this->busy || Storage::$busy || get_current_blog_id() !== $this->site ) {
			return false;
		}
		$this->counts[ $panel ] = ( $this->counts[ $panel ] ?? 0 ) + 1;
		if ( $this->counts[ $panel ] > 100 ) {
			$this->dropped[ $panel ] = ( $this->dropped[ $panel ] ?? 0 ) + 1;
			return false;
		}
		$this->busy = true;
		try {
			$accepted = $this->recorder->add_event(
				$name,
				array(
					'live_panel' => $panel,
					'details'    => $data,
				),
				$duration,
				$offset
			);
			if ( ! $accepted ) {
				$this->dropped[ $panel ] = ( $this->dropped[ $panel ] ?? 0 ) + 1;
			}
			return $accepted;
		} catch ( \Throwable $exception ) {
			$this->dropped[ $panel ] = ( $this->dropped[ $panel ] ?? 0 ) + 1;
			return false;
		} finally {
			$this->busy = false;
		}
	}

	/**
	 * Record an explicit developer message, never an application log file.
	 *
	 * @param string $level Severity.
	 * @param string $message Message.
	 * @param array  $context Diagnostic context.
	 * @return bool
	 */
	public function log( $level, $message, array $context = array() ) {
		if ( ! $this->events || ! is_string( $message ) || ! in_array( $level, array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ), true ) ) {
			return false;
		}
		return $this->record(
			'logs',
			$message,
			array(
				'severity' => $level,
				'context'  => $context,
			)
		);
	}

	/**
	 * Start an explicit timer; duplicate labels retain distinct handles.
	 *
	 * @param string $name Timer label.
	 * @return string|null
	 */
	public function start( $name ) {
		if ( ! $this->events || ! is_string( $name ) || count( $this->timers ) >= 20 || ( $this->counts['timings'] ?? 0 ) >= 100 ) {
			return null;
		}
		$id                  = wp_generate_uuid4();
		$this->timers[ $id ] = array(
			'name'   => $this->redactor->text( $name ),
			'offset' => $this->recorder->offset(),
		);
		return $id;
	}

	/**
	 * Stop or lap an existing timer without guessing pairing.
	 *
	 * @param string $id Timer handle.
	 * @param bool   $lap Keep the timer open.
	 * @return bool
	 */
	public function stop( $id, $lap = false ) {
		if ( ! is_string( $id ) || ! isset( $this->timers[ $id ] ) ) {
			return false;
		}
		$timer    = $this->timers[ $id ];
		$duration = $this->recorder->offset() - $timer['offset'];
		if ( ! $lap ) {
			unset( $this->timers[ $id ] );
		}
		return $this->record(
			'timings',
			$timer['name'],
			array(
				'handle' => $id,
				'state'  => $lap ? 'lap' : 'complete',
			),
			$duration,
			$timer['offset']
		);
	}

	/**
	 * Observe a translation candidate without asserting successful loading.
	 *
	 * @param mixed  $file Candidate file.
	 * @param string $domain Text domain.
	 * @param string $locale Locale.
	 * @return mixed
	 */
	public function translation( $file, $domain = '', $locale = '' ) {
		$this->record(
			'languages',
			(string) $domain,
			array(
				'file'   => is_string( $file ) ? Attribution::file( $file )['file'] : null,
				'locale' => $locale,
				'state'  => 'load_candidate',
			)
		);
		return $file;
	}

	/**
	 * Observe a script translation candidate.
	 *
	 * @param mixed  $file Candidate file.
	 * @param string $handle Script handle.
	 * @param string $domain Text domain.
	 * @return mixed
	 */
	public function script_translation( $file, $handle = '', $domain = '' ) {
		$this->record(
			'languages',
			(string) $domain,
			array(
				'handle' => $handle,
				'file'   => is_string( $file ) ? Attribution::file( $file )['file'] : null,
				'state'  => 'script_load_candidate',
			)
		);
		return $file;
	}

	/**
	 * Observe a blog transient update; values are intentionally ignored.
	 *
	 * @param string $name Transient key.
	 * @param mixed  $value Ignored value.
	 * @param int    $expiry TTL seconds.
	 */
	public function transient( $name, $value, $expiry ) {
		$this->record(
			'transients',
			(string) $name,
			array(
				'scope'       => 'blog',
				'operation'   => 'set',
				'ttl_seconds' => (int) $expiry,
			)
		);
	}

	/**
	 * Observe a network transient update; values are ignored.
	 *
	 * @param string $name Transient key.
	 * @param mixed  $value Ignored value.
	 * @param int    $expiry TTL seconds.
	 */
	public function site_transient( $name, $value, $expiry ) {
		$this->record(
			'transients',
			(string) $name,
			array(
				'scope'       => 'network',
				'operation'   => 'set',
				'ttl_seconds' => (int) $expiry,
			)
		);
	}

	/**

	 * Deleted blog transient key.
	 *
	 * @param string $name Deleted blog transient key.
	 */
	public function deleted_transient( $name ) {
		$this->record(
			'transients',
			(string) $name,
			array(
				'scope'     => 'blog',
				'operation' => 'delete',
			)
		);
	}

	/**

	 * Deleted network transient key.
	 *
	 * @param string $name Deleted network transient key.
	 */
	public function deleted_site_transient( $name ) {
		$this->record(
			'transients',
			(string) $name,
			array(
				'scope'     => 'network',
				'operation' => 'delete',
			)
		);
	}

	/**
	 * Observe filter-stage capability results without changing them.
	 *
	 * @param array $allcaps Original primitive capabilities.
	 * @param array $caps Requested primitive requirements.
	 * @param array $args Original check arguments, not retained.
	 * @return array
	 */
	public function capabilities( $allcaps, $caps, $args ) {
		if ( $this->busy || Storage::$busy ) {
			return $allcaps;
		}
		if ( ( $this->counts['caps'] ?? 0 ) >= 100 ) {
			$this->dropped['caps'] = ( $this->dropped['caps'] ?? 0 ) + 1;
			return $allcaps;
		}
		$allowed = true;
		foreach ( $caps as $cap ) {
			$allowed = $allowed && ! empty( $allcaps[ $cap ] );
		}
		$this->record(
			'caps',
			is_string( $args[0] ?? null ) ? $args[0] : 'unknown',
			array(
				'requirements'     => array_slice( $caps, 0, 20 ),
				'observed_allowed' => $allowed,
				'stage'            => 'user_has_cap_filter_not_final_authorization',
			)
		);
		return $allcaps;
	}

	/**

	 * Incomplete timers at the named snapshot boundary.
	 *
	 * @return array Incomplete timers at the named snapshot boundary.
	 */
	public function incomplete() {
		return array_values(
			array_map(
				static fn( $timer ) => array(
					'name'            => $timer['name'],
					'start_offset_us' => $timer['offset'],
					'state'           => 'incomplete',
					'duration_us'     => null,
				),
				$this->timers
			)
		);
	}

	/**
	 * Read allowlisted environment facts and the known core cache adapter.
	 *
	 * @return array
	 */
	public static function environment() {
		global $wpdb, $wp_object_cache;
		$hits   = null;
		$misses = null;
		if ( ! wp_using_ext_object_cache() && $wp_object_cache instanceof \WP_Object_Cache && get_class( $wp_object_cache ) === 'WP_Object_Cache' ) {
			$hits   = is_numeric( $wp_object_cache->cache_hits ) ? (int) $wp_object_cache->cache_hits : null;
			$misses = is_numeric( $wp_object_cache->cache_misses ) ? (int) $wp_object_cache->cache_misses : null;
		}
		$memory = ini_get( 'memory_limit' );
		return array(
			'wordpress'             => get_bloginfo( 'version' ),
			'php'                   => PHP_VERSION,
			'database'              => $wpdb->db_version(),
			'environment'           => wp_get_environment_type(),
			'site'                  => get_current_blog_id(),
			'multisite'             => is_multisite(),
			'memory_limit_bytes'    => ! is_string( $memory ) || '-1' === $memory ? null : wp_convert_hr_to_bytes( $memory ),
			'max_execution_seconds' => (int) ini_get( 'max_execution_time' ),
			'persistent_cache'      => wp_using_ext_object_cache(),
			'opcache_enabled'       => extension_loaded( 'Zend OPcache' ) ? (bool) ini_get( 'opcache.enable' ) : false,
			'cache_hits'            => $hits,
			'cache_misses'          => $misses,
			'cache_hit_ratio'       => null !== $hits && null !== $misses && $hits + $misses > 0 ? $hits / ( $hits + $misses ) : null,
			'cache_counter_scope'   => null !== $hits ? 'core_request_cache_at_freeze' : 'unsupported_cache_adapter',
		);
	}

	/**
	 * Snapshot public dependency metadata without resolving or printing assets.
	 *
	 * @param string $kind scripts or styles.
	 * @return array
	 */
	public function assets( $kind ) {
		$registry = $GLOBALS[ 'wp_' . $kind ] ?? null;
		if ( ! $registry instanceof \WP_Dependencies ) {
			return array();
		}
		$rows = array();
		foreach ( $registry->registered as $handle => $asset ) {
			if ( count( $rows ) >= 200 ) {
				$this->dropped[ $kind ] = count( $registry->registered ) - 200;
				break;
			}
			$deps   = array_slice( $asset->deps, 0, 5 );
			$rows[] = array(
				'handle'               => $this->redactor->text( (string) $handle ),
				'source'               => is_string( $asset->src ) ? $this->redactor->url( $asset->src ) : '',
				'version'              => is_scalar( $asset->ver ) ? $this->redactor->text( (string) $asset->ver ) : null,
				'dependencies'         => $this->redactor->value( $deps ),
				'omitted_dependencies' => max( 0, count( $asset->deps ) - count( $deps ) ),
				'missing_dependencies' => array_values( array_filter( $deps, static fn( $dep ) => ! isset( $registry->registered[ $dep ] ) ) ),
				'state'                => in_array( $handle, $registry->done, true ) ? 'printed' : ( in_array( $handle, $registry->queue, true ) ? 'enqueued' : 'registered' ),
				'strategy'             => $this->redactor->text( (string) ( $asset->extra['strategy'] ?? '' ) ),
				'media'                => 'styles' === $kind && is_string( $asset->args ) ? $this->redactor->text( $asset->args ) : null,
			);
		}
		return $rows;
	}
}
