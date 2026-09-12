<?php
/**
 * Privileged, explicitly gated diagnostic actions.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Supported advanced controls exclude arbitrary SQL and secret reconstruction. */
final class Advanced {
	/**
	 * Register admin-only advanced endpoints.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( array( 'audit', 'preview', 'integrations', 'replay', 'explain', 'search' ) as $action ) {
			register_rest_route(
				'request-inspector/v1',
				'/advanced/' . $action,
				array(
					'methods'             => in_array( $action, array( 'audit', 'integrations' ), true ) ? 'GET' : 'POST',
					'permission_callback' => array( new Api(), 'manage' ),
					'callback'            => array( $this, $action ),
				)
			);
		}
	}

	/**
	 * List minimized audit data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function audit( $request ) {
		return Audit::listing( (int) ( $request['page'] ?? 1 ) );
	}

	/**
	 * Preview fixed synthetic data through the production sanitizer.
	 *
	 * @return array
	 */
	public function preview() {
		return ( new Redactor( Settings::get()['custom_keys'] ) )->value(
			array(
				'token'   => 'synthetic-secret',
				'email'   => 'synthetic@example.invalid',
				'invoice' => 'INV-12345',
				'nested'  => array(
					'password' => 'synthetic-password',
					'safe'     => 'Example fixture',
				),
			)
		);
	}

	/**
	 * Search at most 200 sanitized rows from the last 24 hours.
	 *
	 * @param \WP_REST_Request $request Restricted pattern and field.
	 * @return array|\WP_Error
	 */
	public function search( $request ) {
		$pattern = $request['pattern'];
		$field   = $request['field'] ?? 'url';
		if ( ! Policy::pattern( $pattern ) || ! in_array( $field, array( 'url', 'db_query', 'error' ), true ) ) {
			return new \WP_Error( 'ri_pattern', __( 'Unsupported search pattern or field.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		$storage = new Storage();
		$found   = array();
		$scanned = 0;
		for ( $page = 1; $page <= 2; ++$page ) {
			$filters = array(
				'page'       => $page,
				'per_page'   => 100,
				'after'      => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				'event_type' => $field,
			);
			$rows    = 'url' === $field ? $storage->listing( $filters ) : $storage->events( $filters );
			foreach ( $rows['items'] as $row ) {
				++$scanned;
				$match = preg_match( '~(*LIMIT_MATCH=2000)(*LIMIT_DEPTH=100)' . $pattern . '~u', substr( 'url' === $field ? $row['url'] : $row['name'], 0, 8192 ) );
				if ( false === $match ) {
					return new \WP_Error( 'ri_pattern_limit', __( 'Pattern resource limit reached.', 'request-inspector' ), array( 'status' => 400 ) );
				}
				if ( $match ) {
					$found[] = $row;
				}
			}
		}
		return array(
			'items'   => $found,
			'scanned' => $scanned,
			'scope'   => 'last_24_hours_first_200_rows',
			'partial' => $rows['total'] > 200,
		);
	}

	/**
	 * Describe adapter coverage honestly.
	 *
	 * @return array
	 */
	public function integrations() {
		return array(
			'object_cache'    => array(
				'external_dropin' => wp_using_ext_object_cache(),
				'metrics'         => 'unavailable_without_a_measuring_adapter',
			),
			'graphql'         => array(
				'detected'         => defined( 'WPGRAPHQL_VERSION' ),
				'resolver_metrics' => 'unavailable',
				'http'             => 'normal_request_metadata_only',
			),
			'query_monitor'   => array(
				'detected' => class_exists( 'QueryMonitor' ),
				'contract' => 'coexists_without_replacing_wpdb_or_mutating_shared_query_logs',
			),
			'callback_timing' => 'unavailable_preserving_dispatch_semantics',
			'explain'         => 'staging_mariadb_synthetic_single_table_select_only',
		);
	}

	/**
	 * Refuse side-effecting diagnostics outside staging/development.
	 *
	 * @return bool
	 */
	private function staging() {
		return in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true );
	}

	/**
	 * Execute only an explicitly reviewed safe-method request to an allowed host.
	 *
	 * @param \WP_REST_Request $request Reviewed request.
	 * @return array|\WP_Error
	 */
	public function replay( $request ) {
		$settings = Settings::get();
		$url      = $request['url'];
		$method   = $request['method'] ?? 'GET';
		if ( ! $this->staging() || ! $settings['replay_enabled'] || true !== $request['confirm'] ) {
			return new \WP_Error( 'ri_replay_disabled', __( 'Replay requires staging, an enabled destination policy and explicit confirmation.', 'request-inspector' ), array( 'status' => 403 ) );
		}
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || ! in_array( $method, array( 'GET', 'HEAD' ), true ) || str_contains( rawurldecode( $url ), '[REDACTED]' ) ) {
			return new \WP_Error( 'ri_replay_input', __( 'Provide a reviewed synthetic URL and GET or HEAD. Redacted credentials cannot be reconstructed.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || ! in_array( $parts['host'] ?? '', $settings['replay_hosts'], true ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'ri_destination', __( 'Destination must be an allowed public HTTPS host on port 443.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		if ( ! Audit::add( 'replay_requested' ) ) {
			return new \WP_Error( 'ri_audit', __( 'Audit storage is unavailable; replay was not sent.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		$response = wp_safe_remote_request(
			$url,
			array(
				'method'              => $method,
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'cookies'             => array(),
				'headers'             => array(),
			)
		);
		$redactor = new Redactor( $settings['custom_keys'] );
		if ( is_wp_error( $response ) ) {
			return array(
				'error' => sanitize_key( $response->get_error_code() ),
				'sent'  => true,
			);
		}
		return array(
			'status'             => wp_remote_retrieve_response_code( $response ),
			'headers'            => $redactor->headers( wp_remote_retrieve_headers( $response ) ),
			'body'               => $redactor->body( wp_remote_retrieve_body( $response ), wp_remote_retrieve_header( $response, 'content-type' ), true ),
			'redirects_followed' => 0,
		);
	}

	/**
	 * Explain a synthetic restricted SELECT using MariaDB's per-statement timeout.
	 *
	 * @param \WP_REST_Request $request Reviewed synthetic SQL.
	 * @return array|\WP_Error
	 */
	public function explain( $request ) {
		global $wpdb;
		$sql = $request['sql'];
		if ( ! $this->staging() || true !== $request['confirm'] || ! is_string( $sql ) || strlen( $sql ) > 500 ) {
			return new \WP_Error( 'ri_explain_disabled', __( 'EXPLAIN requires staging and a confirmed synthetic SELECT.', 'request-inspector' ), array( 'status' => 403 ) );
		}
		if ( ! preg_match( '/^SELECT (?:\*|[a-zA-Z_][a-zA-Z0-9_]*(?:,[a-zA-Z_][a-zA-Z0-9_]*)*) FROM ([a-zA-Z_][a-zA-Z0-9_]*)(?: WHERE [a-zA-Z_][a-zA-Z0-9_]* ?(?:=|<|>|<=|>=) ?[0-9]{1,10})?(?: LIMIT [0-9]{1,4})?$/D', $sql, $match ) || ! str_starts_with( $match[1], $wpdb->prefix ) ) {
			return new \WP_Error( 'ri_select', __( 'Use a single-table SELECT with plain column names, an optional numeric comparison and LIMIT. Functions, joins, comments, strings and multiple statements are unsupported.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Explicit restricted diagnostic query on site-owned tables, with per-statement timeout.
		$allowed = array_merge( array_values( $wpdb->tables( 'blog', true ) ), array_values( ( new Storage() )->tables ) );
		if ( ! in_array( $match[1], $allowed, true ) ) {
			return new \WP_Error( 'ri_table', __( 'Only this site\'s WordPress and diagnostic tables are eligible.', 'request-inspector' ), array( 'status' => 403 ) );
		}
		$version = $wpdb->get_var( 'SELECT VERSION()' );
		if ( ! is_string( $version ) || ! str_contains( $version, 'MariaDB' ) ) {
			return new \WP_Error( 'ri_adapter', __( 'A per-statement timeout adapter is available only for MariaDB.', 'request-inspector' ), array( 'status' => 409 ) );
		}
		if ( ! Audit::add( 'explain_requested' ) ) {
			return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		$rows = $wpdb->get_results( 'SET STATEMENT max_statement_time=1 FOR EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Entire SQL is accepted only by the closed grammar above and the current-site table allowlist; no literals, comments, functions or additional statements are permitted.
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		if ( null === $rows ) {
			return new \WP_Error( 'ri_explain_failed', __( 'The synthetic statement could not be explained. Check its table and column names.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		return array(
			'plan'            => ( new Redactor() )->value( $rows ),
			'executed_select' => false,
			'timeout_seconds' => 1,
		);
	}
}
