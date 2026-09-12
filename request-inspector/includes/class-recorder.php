<?php
/**
 * Request-local, bounded HTTP, database and PHP collectors.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates observation without changing application results.
 */
final class Recorder {
	/**
	 * Authorized live consumer.
	 *
	 * @var Live_Inspector|null Authorized live consumer.
	 */
	public $live;
	/**
	 * Whether historical admission was authorized.
	 *
	 * @var bool Whether historical admission was authorized.
	 */
	private $history = true;
	/**
	 * Stable identity allocated before HTML output.
	 *
	 * @var string Stable identity allocated before HTML output.
	 */
	public $uuid;
	/**
	 * Observed hook event indexes, keyed by a request-local digest.
	 *
	 * @var array
	 */
	private $hook_indexes = array();
	/**
	 * Settings snapshot.
	 *
	 * @var array Settings snapshot.
	 */
	private $settings;
	/**
	 * Storage.
	 *
	 * @var Storage Storage.
	 */
	private $storage;
	/**
	 * Sanitizer.
	 *
	 * @var Redactor Sanitizer.
	 */
	private $redactor;
	/**
	 * Monotonic start.
	 *
	 * @var int Monotonic start.
	 */
	private $start;
	/**
	 * Wall start.
	 *
	 * @var float Wall start.
	 */
	private $wall;
	/**
	 * Origin site.
	 *
	 * @var int Origin site.
	 */
	private $site;
	/**
	 * Purge generation.
	 *
	 * @var int Purge generation.
	 */
	private $generation;
	/**
	 * Outgoing rows.
	 *
	 * @var array Outgoing rows.
	 */
	private $children = array();
	/**
	 * Events.
	 *
	 * @var array Events.
	 */
	private $events = array();
	/**
	 * Sanitized pending HTTP records.
	 *
	 * @var array Sanitized pending HTTP records.
	 */
	private $pending = array();
	/**
	 * Incoming body metadata.
	 *
	 * @var array Incoming body metadata.
	 */
	private $bodies = array();
	/**
	 * Incoming response headers.
	 *
	 * @var array Incoming response headers.
	 */
	private $response_headers = array();
	/**
	 * Observed REST status.
	 *
	 * @var int|null Observed REST status.
	 */
	private $status;
	/**
	 * Route.
	 *
	 * @var string Route.
	 */
	private $route = '';
	/**
	 * Finalized.
	 *
	 * @var bool Finalized.
	 */
	private $done = false;
	/**
	 * Internal request.
	 *
	 * @var bool Internal request.
	 */
	private $internal = false;
	/**
	 * Previous error handler.
	 *
	 * @var mixed Previous error handler.
	 */
	private $previous;
	/**
	 * Error-handler recursion.
	 *
	 * @var bool Error-handler recursion.
	 */
	private $handling = false;
	/**
	 * Retained bytes.
	 *
	 * @var int Retained bytes.
	 */
	private $bytes = 0;
	/**
	 * Dropped events.
	 *
	 * @var int Dropped events.
	 */
	private $dropped = 0;
	/**
	 * Observed database queries.
	 *
	 * @var int Observed database queries.
	 */
	private $query_count = 0;
	/**
	 * Observed database time.
	 *
	 * @var int Observed database time.
	 */
	private $query_us = 0;
	/**
	 * Observed PHP issues.
	 *
	 * @var int Observed PHP issues.
	 */
	private $issue_count = 0;
	/**
	 * Observed outgoing failure.
	 *
	 * @var bool Observed outgoing failure.
	 */
	private $http_error = false;
	/**
	 * Exact query occurrence counts.
	 *
	 * @var array Exact query occurrence counts.
	 */
	private $duplicates = array();
	/**
	 * Per-execution fingerprint key.
	 *
	 * @var string Per-execution fingerprint key.
	 */
	private $fingerprint_key;
	/**
	 * Error fingerprints.
	 *
	 * @var array Error fingerprints.
	 */
	private $issues = array();

	/**
	 * Initialize request-local instrumentation.
	 *
	 * @param array $settings Validated settings.
	 */
	public function __construct( array $settings ) {
		$this->settings        = $settings;
		$this->uuid            = wp_generate_uuid4();
		$this->storage         = new Storage();
		$this->redactor        = new Redactor( $settings['custom_keys'] );
		$this->start           = hrtime( true );
		$this->wall            = microtime( true );
		$this->site            = get_current_blog_id();
		$this->fingerprint_key = random_bytes( 32 );
		$this->generation      = (int) ( $this->storage->state()['generation'] ?? 0 );
	}

	/**
	 * Register eligible instrumentation.
	 *
	 * @param bool $history Apply historical admission policy.
	 * @return bool
	 */
	public function register( $history = true ) {
		$this->history = $history;
		if ( $history ) {
			if ( $this->settings['adaptive_sampling'] ) {
				$state    = $this->storage->state();
				$pressure = max( (int) ( $state['records'] ?? 0 ) / $this->settings['max_records'], (int) ( $state['used_bytes'] ?? 0 ) / ( $this->settings['max_storage_mb'] * 1048576 ) );
				if ( $pressure >= 0.8 ) {
					$this->settings['sample_percent'] = max( 1, (int) ( $this->settings['sample_percent'] / 10 ) );
				}
			}
			if ( wp_rand( 1, 100 ) > $this->settings['sample_percent'] ) {
				return false;
			}
			if ( in_array( $this->settings['mode'], array( 'next', 'current' ), true ) ) {
				// This opaque opt-in control token is consumed only after hashing; it is never persisted raw.
				$token = isset( $_GET['ri_capture'] ) && is_string( $_GET['ri_capture'] ) ? sanitize_text_field( wp_unslash( $_GET['ri_capture'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A short-lived, single-use capture token is not a settings mutation.
				$hash  = 'current' === $this->settings['mode'] ? hash( 'sha256', $token ) : '';
				if ( ! $this->storage->claim( $hash ) ) {
					return false;
				}
			}
		}
		add_filter( 'http_request_args', array( $this, 'http_start' ), PHP_INT_MAX, 2 );
		add_filter( 'pre_http_request', array( $this, 'http_preempt' ), PHP_INT_MAX, 3 );
		add_action( 'http_api_debug', array( $this, 'http_finish' ), PHP_INT_MAX, 5 );
		add_filter( 'rest_post_dispatch', array( $this, 'rest_response' ), PHP_INT_MAX, 3 );
		if ( $this->settings['database'] && Settings::detailed( $this->settings ) && defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			add_filter( 'log_query_custom_data', array( $this, 'query' ), 10, 5 );
		}
		if ( $this->settings['php_errors'] || 'errors' === $this->settings['mode'] ) {
			$this->previous = set_error_handler( array( $this, 'error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Opt-in PHP collector preserves the previously registered handler.
		}
		register_shutdown_function( array( $this, 'finish' ) );
		if ( $this->settings['hooks'] && Settings::detailed( $this->settings ) ) {
			add_action( 'all', array( $this, 'hook' ), PHP_INT_MAX, 1 );
		}
		do_action( 'request_inspector_collector_ready', $this, 'request-inspector/1' );
		return true;
	}

	/**
	 * Accept a bounded event from trusted in-process collector extensions.
	 *
	 * @param string   $name Event name.
	 * @param array    $metadata Diagnostic metadata, sanitized before retention.
	 * @param int|null $duration_us Measured inclusive duration, if available.
	 * @param int|null $offset_us Explicit monotonic start offset, if supplied.
	 * @return bool
	 */
	public function add_event( $name, array $metadata, $duration_us = null, $offset_us = null ) {
		if ( ! $this->observing() || ! is_string( $name ) || ( null !== $duration_us && ( ! is_int( $duration_us ) || $duration_us < 0 || $duration_us > 60000000 ) ) ) {
			return false;
		}
		$this->handling = true;
		try {
			$event = array(
				'event_type'      => 'extension',
				'name'            => $this->redactor->text( $name ),
				'metadata'        => $this->redactor->value( $metadata ),
				'duration_us'     => $duration_us,
				'start_offset_us' => is_int( $offset_us ) && $offset_us >= 0 ? $offset_us : $this->offset(),
				'component'       => Attribution::caller( false )['component'],
			);
			if ( ! $this->retain( $event ) ) {
				return false;
			}
			$this->events[] = $event;
			return true;
		} catch ( \Throwable $exception ) {
			++$this->dropped;
			return false;
		} finally {
			$this->handling = false;
		}
	}

	/** Current monotonic offset in microseconds. */
	public function offset() {
		return (int) ( ( hrtime( true ) - $this->start ) / 1000 );
	}

	/**
	 * Observe a hook occurrence without retaining its arguments or altering dispatch.
	 *
	 * @param string $name Hook name.
	 * @return void
	 */
	public function hook( $name ) {
		if ( ! $this->observing() || $this->handling ) {
			return;
		}
		$key = hash_hmac( 'sha256', $name, $this->fingerprint_key );
		if ( isset( $this->hook_indexes[ $key ] ) ) {
			++$this->events[ $this->hook_indexes[ $key ] ]['metadata']['occurrences'];
			return;
		}
		if ( count( $this->hook_indexes ) >= 100 ) {
			++$this->dropped;
			return;
		}
		$this->handling = true;
		try {
			$metadata                   = Hooks::snapshot( $name );
			$metadata['occurrences']    = 1;
			$metadata['classification'] = 'unknown';
			$event                      = array(
				'event_type'      => 'hook',
				'name'            => $this->redactor->text( $name ),
				'duration_us'     => null,
				'start_offset_us' => (int) ( ( hrtime( true ) - $this->start ) / 1000 ),
				'component'       => 'Mixed',
				'metadata'        => $metadata,
			);
			if ( $this->retain( $event ) ) {
				$this->hook_indexes[ $key ] = count( $this->events );
				$this->events[]             = $event;
			}
		} catch ( \Throwable $exception ) {
			++$this->dropped;
		} finally {
			$this->handling = false;
		}
	}

	/**
	 * Observing.
	 *
	 * @return bool Whether this request remains observable.
	 */
	private function observing() {
		return ! $this->done && ! $this->internal && ! $this->handling && ! Storage::$busy && get_current_blog_id() === $this->site;
	}

	/**
	 * Http start.
	 *
	 * @param array  $args HTTP arguments.
	 * @param string $url Destination.
	 * @return array
	 */
	public function http_start( $args, $url ) {
		if ( ! $this->observing() || count( $this->pending ) >= 100 ) {
			return $args;
		}
		$headers = $this->redactor->headers( $args['headers'] ?? array() );
		$pending = array(
			'key'     => hash_hmac( 'sha256', $url, $this->fingerprint_key ),
			'start'   => hrtime( true ),
			'url'     => $this->redactor->url( $url ),
			'method'  => substr( sanitize_key( $args['method'] ?? 'GET' ), 0, 16 ),
			'headers' => $headers,
			'body'    => $this->redactor->body( $args['body'] ?? '', $headers['content-type'] ?? '', $this->settings['request_body'], $this->settings['max_body_kb'] * 1024 ),
			'caller'  => Attribution::caller( $this->settings['stack_traces'] && Settings::detailed( $this->settings ) ),
		);
		if ( $this->retain( $pending ) ) {
			$this->pending[] = $pending;
		}
		return $args;
	}

	/**
	 * Http preempt.
	 *
	 * @param mixed  $response Response.
	 * @param array  $args Args.
	 * @param string $url URL.
	 * @return mixed
	 */
	public function http_preempt( $response, $args, $url ) {
		if ( false !== $response ) {
			$this->http_finish( $response, 'response', 'short-circuit', $args, $url );
		}
		return $response;
	}

	/**
	 * Http finish.
	 *
	 * @param mixed  $response Response.
	 * @param string $context Context.
	 * @param string $transport Transport.
	 * @param array  $args Args.
	 * @param string $url URL.
	 * @return void
	 */
	public function http_finish( $response, $context, $transport, $args, $url ) {
		if ( ! $this->observing() || 'response' !== $context ) {
			return;
		}
		$key     = hash_hmac( 'sha256', (string) $url, $this->fingerprint_key );
		$pending = null;
		for ( $i = count( $this->pending ) - 1; $i >= 0; --$i ) {
			if ( $this->pending[ $i ]['key'] === $key ) {
				$pending      = $this->pending[ $i ];
				$this->bytes -= strlen( wp_json_encode( $pending ) );
				array_splice( $this->pending, $i, 1 );
				break;
			}
		}
		if ( ! $pending ) {
			++$this->dropped;
			return;
		}
		$error            = is_wp_error( $response );
		$code             = $error ? 0 : wp_remote_retrieve_response_code( $response );
		$status           = $code ? (int) $code : null;
		$this->http_error = $this->http_error || $error || $status >= 400;
		$headers          = $error ? array() : $this->redactor->headers( wp_remote_retrieve_headers( $response ) );
		$row              = array(
			'direction'   => 'outgoing',
			'type'        => $this->type(),
			'method'      => strtoupper( $pending['method'] ),
			'url'         => $pending['url'],
			'status'      => $status,
			'duration_us' => (int) ( ( hrtime( true ) - $pending['start'] ) / 1000 ),
			'component'   => $pending['caller']['component'],
			'has_error'   => (int) ( $error || $status >= 400 ),
			'metadata'    => array(
				'caller'           => $pending['caller'],
				'request_headers'  => $pending['headers'],
				'response_headers' => $headers,
				'transport'        => $transport,
				'error_code'       => $error ? sanitize_key( $response->get_error_code() ) : null,
				'timing'           => empty( $args['blocking'] ) ? 'dispatch_only' : 'http_api_observed',
				'start_offset_us'  => max( 0, (int) ( ( $pending['start'] - $this->start ) / 1000 ) ),
			),
			'bodies'      => array(
				'request'  => $pending['body'],
				'response' => $this->redactor->body( $error ? '' : wp_remote_retrieve_body( $response ), $headers['content-type'] ?? '', $this->settings['response_body'] && empty( $args['stream'] ), $this->settings['max_body_kb'] * 1024 ),
			),
		);
		if ( $this->retain( $row ) ) {
			$this->children[] = $row;
		}
	}

	/**
	 * Retain.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	private function retain( $value ) {
		$bytes = strlen( wp_json_encode( $value ) );
		if ( $this->bytes + $bytes > ( $this->live ? 1835008 : 2097152 ) || count( $this->events ) + count( $this->children ) >= 1000 ) {
			++$this->dropped;
			return false;
		}
		$this->bytes += $bytes;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param array  $data Logging metadata.
	 * @param string $query SQL.
	 * @param float  $time Duration.
	 * @param string $stack Caller.
	 * @param float  $start Wall start.
	 * @return array
	 */
	public function query( $data, $query, $time, $stack, $start ) {
		if ( ! $this->observing() ) {
			return $data;
		}
		++$this->query_count;
		$this->query_us += (int) ( $time * 1000000 );
		if ( count( $this->events ) >= 1000 || strlen( $query ) > 65536 ) {
			++$this->dropped;
			return $data;
		}
		$exact                      = hash_hmac( 'sha256', $query, $this->fingerprint_key );
		$this->duplicates[ $exact ] = ( $this->duplicates[ $exact ] ?? 0 ) + 1;
		$sql                        = $this->redactor->sql( $query );
		$caller                     = Attribution::caller( $this->settings['stack_traces'] );
		$event                      = array(
			'event_type'      => 'db_query',
			'name'            => $sql,
			'duration_us'     => (int) ( $time * 1000000 ),
			'start_offset_us' => max( 0, (int) ( ( $start - $this->wall ) * 1000000 ) ),
			'component'       => $caller['component'],
			'metadata'        => array(
				'caller'  => $caller,
				'group'   => $exact,
				'shape'   => hash( 'sha256', $sql ),
				'is_slow' => $time * 1000 >= $this->settings['slow_query_ms'],
				'rows'    => null,
				'clock'   => 'wall_calibrated',
			),
		);
		if ( $this->retain( $event ) ) {
			$this->events[] = $event;
		}
		return $data;
	}

	/**
	 * Error.
	 *
	 * @param int    $severity Severity.
	 * @param string $message Message.
	 * @param string $file File.
	 * @param int    $line Line.
	 * @return bool
	 */
	public function error( $severity, $message, $file, $line ) {
		if ( ! $this->handling && $this->observing() && ( error_reporting() & $severity ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting,PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Reads the existing mask without modifying reporting or display settings.
			$this->handling = true;
			try {
				$this->issue( $severity, $message, $file, $line, false );
			} catch ( \Throwable $exception ) {
				++$this->dropped;
			} finally {
				$this->handling = false;
			}
		}
		return is_callable( $this->previous ) ? (bool) call_user_func( $this->previous, $severity, $message, $file, $line ) : false;
	}

	/**
	 * Issue.
	 *
	 * @param int    $severity Severity.
	 * @param string $message Message.
	 * @param string $file File.
	 * @param int    $line Line.
	 * @param bool   $fatal Fatal.
	 * @return void
	 */
	private function issue( $severity, $message, $file, $line, $fatal ) {
		$key = hash_hmac( 'sha256', $severity . $message . $file . $line, $this->fingerprint_key );
		if ( isset( $this->issues[ $key ] ) ) {
			return;
		}
		++$this->issue_count;
		if ( count( $this->issues ) >= 100 ) {
			++$this->dropped;
			return;
		}
		$this->issues[ $key ] = true;
		$caller               = Attribution::file( $file );
		$caller['line']       = $line;
		$caller['trace']      = ! $fatal && $this->settings['stack_traces'] && Settings::detailed( $this->settings ) ? Attribution::caller( true )['trace'] : array();
		$severity_name        = $fatal ? 'fatal' : ( in_array( $severity, array( E_DEPRECATED, E_USER_DEPRECATED ), true ) ? 'deprecated' : ( in_array( $severity, array( E_NOTICE, E_USER_NOTICE ), true ) ? 'notice' : 'warning' ) );
		// Quoted values in errors can be application secrets. Retain structure, not interpolated values.
		$message = preg_replace( '/\x27[^\x27]*\x27|"[^"]*"/', '[VALUE]', substr( $message, 0, 4096 ) );
		$event   = array(
			'event_type'      => 'error',
			'name'            => $this->redactor->text( $message ),
			'duration_us'     => null,
			'start_offset_us' => (int) ( ( hrtime( true ) - $this->start ) / 1000 ),
			'component'       => $caller['component'],
			'metadata'        => array(
				'severity'      => $severity_name,
				'code'          => $severity,
				'caller'        => $caller,
				'source_reason' => 'source_capture_disabled',
			),
		);
		if ( $this->retain( $event ) ) {
			$this->events[] = $event;
		}
	}

	/**
	 * Rest response.
	 *
	 * @param mixed            $response REST result.
	 * @param \WP_REST_Server  $server Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function rest_response( $response, $server, $request ) {
		if ( str_starts_with( $request->get_route(), '/request-inspector/v1/' ) ) {
			$this->internal = true;
			return $response;
		}
		$this->route = $this->redactor->url( $request->get_route() );
		if ( $response instanceof \WP_HTTP_Response ) {
			$this->status             = $response->get_status();
			$this->response_headers   = $this->redactor->headers( $response->get_headers() );
			$this->bodies['response'] = $this->redactor->body( wp_json_encode( $response->get_data() ), 'application/json', $this->settings['response_body'], $this->settings['max_body_kb'] * 1024 );
		}
		$this->bodies['request'] = $this->redactor->body( $request->get_body(), (string) $request->get_header( 'content-type' ), $this->settings['request_body'], $this->settings['max_body_kb'] * 1024 );
		return $response;
	}

	/**
	 * Type.
	 *
	 * @return string Execution context.
	 */
	private function type() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli'; }
		if ( wp_doing_cron() ) {
			return 'cron'; }
		if ( wp_doing_ajax() ) {
			return 'ajax'; }
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest'; }
		if ( str_contains( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) ), 'wp-login.php' ) ) {
			return 'login'; }
		return is_admin() ? 'admin' : 'frontend';
	}

	/**
	 * Shutdown finalization. Never replaces an exception handler.
	 *
	 * @return void
	 */
	public function finish() {
		if ( $this->done || $this->internal ) {
			return; }
		$last = error_get_last();
		if ( ( $this->settings['php_errors'] || 'errors' === $this->settings['mode'] ) && $last && in_array( $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			$this->issue( $last['type'], $last['message'], $last['file'], $last['line'], true );
		}
		$this->done = true;
		global $wpdb;
		$frozen_count     = (int) $wpdb->num_queries;
		$frozen_peak      = memory_get_peak_usage( true );
		$live_environment = null;
		$request_start    = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null;
		$request_us       = $request_start && microtime( true ) >= $request_start ? (int) ( ( microtime( true ) - $request_start ) * 1000000 ) : null;
		$retained         = false;
		$type             = $this->type();
		$duration         = (int) ( ( hrtime( true ) - $this->start ) / 1000 );
		try {
			$live_environment = $this->live ? Live_Collectors::environment() : null;
		} catch ( \Throwable $exception ) {
			++$this->dropped;
		}
		$code   = http_response_code();
		$status = $this->status ?? ( $code ? $code : null );
		$error  = $this->http_error || $status >= 400 || $this->issue_count > 0;
		$mode   = $this->settings['mode'];
		$admit  = $this->history;
		if ( ( 'rest_ajax' === $mode && ! in_array( $type, array( 'rest', 'ajax' ), true ) ) || ( 'errors' === $mode && ! $error ) || ( 'slow' === $mode && $duration < $this->settings['slow_ms'] * 1000 ) || ( 'external' === $mode && ! $this->children ) ) {
			$admit = false; }
		if ( 'component' === $mode ) {
			$matches = array_filter( array_merge( $this->children, $this->events ), fn( $row ) => $row['component'] === $this->settings['component'] && 'Unknown' !== $row['component'] );
			if ( ! $matches ) {
				$admit = false; }
		}
		if ( ! $admit && ! $this->live ) {
			return;
		}
		foreach ( $this->events as &$event ) {
			if ( 'db_query' === $event['event_type'] ) {
				$event['metadata']['duplicate_count'] = $this->duplicates[ $event['metadata']['group'] ]; }
		}
		unset( $event );
		$headers = array();
		foreach ( $_SERVER as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification -- Observational input is redacted before storage; no setting is mutated.
			if ( str_starts_with( $key, 'HTTP_' ) ) {
				$headers[ str_replace( '_', '-', substr( $key, 5 ) ) ] = is_string( $value ) ? $value : ''; }
		}
		$uri          = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$uri          = remove_query_arg( 'ri_capture', $uri );
		$body_enabled = $this->settings['request_body'] && ! in_array( $type, array( 'login', 'cli' ), true );
		if ( ! isset( $this->bodies['request'] ) ) {
			$this->bodies['request'] = $this->redactor->body( array(), '', false );
			if ( $body_enabled && ! empty( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only diagnostics, sanitized structurally below.
				$this->bodies['request'] = $this->redactor->body( wp_unslash( $_POST ), 'application/x-www-form-urlencoded', true, $this->settings['max_body_kb'] * 1024 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Redactor is the bounded recursive sanitizer.
			}
		}
		if ( ! isset( $this->bodies['response'] ) ) {
			$this->bodies['response']           = $this->redactor->body( '', '', false );
			$this->bodies['response']['reason'] = 'non_rest_response_not_buffered';
		}
		$root  = array(
			'direction'    => 'incoming',
			'type'         => $type,
			'method'       => substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'CLI' ) ), 0, 16 ),
			'url'          => $this->redactor->url( $uri ),
			'status'       => $status,
			'duration_us'  => $duration,
			'component'    => 'Unknown',
			'has_error'    => (int) $error,
			'context_only' => (int) ( 'external' === $mode ),
			'events'       => $this->events,
			'bodies'       => $this->bodies,
			'metadata'     => array(
				'route'              => $this->route,
				'request_headers'    => $this->redactor->headers( $headers ),
				'response_headers'   => $this->response_headers,
				'wp'                 => get_bloginfo( 'version' ),
				'php'                => PHP_VERSION,
				'memory_peak'        => $frozen_peak,
				'connection_queries' => $frozen_count,
				'request_elapsed_us' => $request_us,
				'freeze_boundary'    => 'recorder_native_shutdown',
				'live_environment'   => $live_environment,
				'queries'            => $this->query_count,
				'query_us'           => $this->query_us,
				'issues'             => $this->issue_count,
				'dropped'            => $this->dropped + count( $this->pending ),
				'database_available' => $this->settings['database'] && Settings::detailed( $this->settings ) && defined( 'SAVEQUERIES' ) && SAVEQUERIES,
				'php_available'      => $this->settings['php_errors'] || 'errors' === $mode,
				'hooks_available'    => $this->settings['hooks'] && Settings::detailed( $this->settings ),
				'coverage'           => $this->history ? 'plugin_load_to_shutdown' : 'init_to_shutdown',
				'policy_revision'    => $this->settings['revision'],
				'mode'               => $this->settings['mode'],
				'capture_options'    => array(
					'request_body'     => $this->settings['request_body'],
					'response_body'    => $this->settings['response_body'],
					'php_errors'       => $this->settings['php_errors'],
					'stack_traces'     => $this->settings['stack_traces'],
					'redaction_rules'  => hash( 'sha256', wp_json_encode( array( $this->settings['custom_keys'], $this->settings['redaction_patterns'] ) ) ),
					'live_observation' => (bool) $this->live,
					'live_events'      => (bool) $this->live && $this->settings['live_events'],
					'live_caps'        => (bool) $this->live && $this->settings['live_caps'],
				),
				'sample_percent'     => $this->settings['sample_percent'],
			),
		);
		$trace = $this->uuid;
		$rows  = array_merge( array( $root ), $this->children );
		foreach ( $rows as &$row ) {
			$row['trace_id']   = $trace;
			$row['started_at'] = gmdate( 'Y-m-d H:i:s', (int) $this->wall ); }
		unset( $row );
		$switched = is_multisite() && get_current_blog_id() !== $this->site;
		if ( $switched ) {
			switch_to_blog( $this->site ); }
		try {
			if ( $admit && $this->storage->write( $rows, $this->generation ) ) {
				$retained = true;
				do_action( 'request_inspector_trace_stored', $trace, $rows, 'request-inspector/1' );
			}
		} catch ( \Throwable $exception ) {
			++$this->dropped;
		} finally {
			if ( $this->live ) {
				try {
					$this->live->publish( $root, $this->children, $retained ? $trace : null );
				} catch ( \Throwable $exception ) {
					// Live diagnostics must never change the application response.
					++$this->dropped;
				}
			}
			if ( $switched ) {
				restore_current_blog(); }
		}
	}
}
