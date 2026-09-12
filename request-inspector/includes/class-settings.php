<?php
/**
 * Validated configuration and recording policy.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Site-local configuration.
 */
final class Settings {
	/**
	 * Option name.
	 */
	const OPTION = 'request_inspector_settings';

	/**
	 * Get secure defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => false,
			'live_enabled'        => true,
			'live_frontend'       => true,
			'live_events'         => false,
			'live_caps'           => false,
			'mode'                => 'external',
			'request_body'        => false,
			'response_body'       => false,
			'database'            => false,
			'hooks'               => false,
			'capture_cidrs'       => array(),
			'trusted_proxies'     => array(),
			'redaction_patterns'  => array(),
			'adaptive_sampling'   => false,
			'replay_enabled'      => false,
			'replay_hosts'        => array(),
			'php_errors'          => true,
			'stack_traces'        => false,
			'production_guard'    => true,
			'max_records'         => 5000,
			'retention_days'      => 7,
			'max_storage_mb'      => 250,
			'max_body_kb'         => 64,
			'slow_ms'             => 500,
			'slow_query_ms'       => 50,
			'sample_percent'      => 100,
			'component'           => '',
			'custom_keys'         => array(),
			'view_capability'     => 'manage_options',
			'delete_on_uninstall' => false,
			'revision'            => 1,
		);
	}

	/**
	 * Read settings.
	 *
	 * @return array
	 */
	public static function get() {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * Validate a complete or partial configuration.
	 *
	 * @param array $input Proposed settings.
	 * @return array|\WP_Error
	 */
	public static function validate( array $input ) {
		$current = self::get();
		if ( ! isset( $input['revision'] ) || (int) $input['revision'] !== $current['revision'] ) {
			return new \WP_Error( 'ri_conflict', __( 'Settings changed. Reload before saving.', 'request-inspector' ), array( 'status' => 409 ) );
		}
		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				return new \WP_Error( 'ri_setting', __( 'Unknown setting.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			if ( is_bool( self::defaults()[ $key ] ) ) {
				if ( ! is_bool( $value ) ) {
					return new \WP_Error( 'ri_boolean', __( 'Expected a boolean setting.', 'request-inspector' ), array( 'status' => 400 ) );
				}
			}
		}
		$next = array_merge( $current, $input );
		foreach ( array( 'capture_cidrs', 'trusted_proxies' ) as $field ) {
			if ( ! is_array( $next[ $field ] ) || count( $next[ $field ] ) > 20 ) {
				return new \WP_Error( 'ri_network', __( 'Use at most twenty valid CIDR networks.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			foreach ( $next[ $field ] as $network ) {
				if ( ! is_string( $network ) || ! Policy::contains( explode( '/', $network )[0], $network ) ) {
					return new \WP_Error( 'ri_network', __( 'Invalid IPv4 or IPv6 CIDR.', 'request-inspector' ), array( 'status' => 400 ) );
				}
			}
		}
		if ( ! is_array( $next['redaction_patterns'] ) || count( $next['redaction_patterns'] ) > 5 || ! is_array( $next['replay_hosts'] ) || count( $next['replay_hosts'] ) > 10 ) {
			return new \WP_Error( 'ri_rules', __( 'Too many or invalid advanced rules.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		foreach ( $next['redaction_patterns'] as $pattern ) {
			if ( ! Policy::pattern( $pattern ) ) {
				return new \WP_Error( 'ri_pattern', __( 'Use restricted patterns: literals, character classes and bounded repetitions up to 64. Groups and unbounded repetition are unsupported.', 'request-inspector' ), array( 'status' => 400 ) );
			}
		}
		foreach ( $next['replay_hosts'] as $host ) {
			if ( ! is_string( $host ) || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host ) ) {
				return new \WP_Error( 'ri_host', __( 'Use lowercase exact domain names without schemes, ports or wildcards.', 'request-inspector' ), array( 'status' => 400 ) );
			}
		}
		if ( ! in_array( $next['mode'], array( 'all', 'external', 'rest_ajax', 'errors', 'slow', 'component', 'next', 'current' ), true ) ) {
			return new \WP_Error( 'ri_mode', __( 'Invalid recording mode.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		$ranges = array(
			'max_records'    => array( 10, 50000 ),
			'retention_days' => array( 1, 30 ),
			'max_storage_mb' => array( 1, 1024 ),
			'max_body_kb'    => array( 1, 256 ),
			'slow_ms'        => array( 1, 60000 ),
			'slow_query_ms'  => array( 1, 10000 ),
			'sample_percent' => array( 1, 100 ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( ! is_numeric( $next[ $key ] ) || (float) (int) $next[ $key ] !== (float) $next[ $key ] || $next[ $key ] < $range[0] || $next[ $key ] > $range[1] ) {
				return new \WP_Error( 'ri_range', __( 'A numeric setting is outside its supported range.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			$next[ $key ] = (int) $next[ $key ];
		}
		if ( ! is_string( $next['component'] ) || strlen( $next['component'] ) > 191 || ! is_array( $next['custom_keys'] ) || count( $next['custom_keys'] ) > 50 ) {
			return new \WP_Error( 'ri_rules', __( 'Invalid component or redaction rules.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		foreach ( $next['custom_keys'] as $key ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_\-]{1,80}$/D', $key ) ) {
				return new \WP_Error( 'ri_rule', __( 'Use field names containing letters, numbers, underscores or hyphens.', 'request-inspector' ), array( 'status' => 400 ) );
			}
		}
		if ( ! in_array( $next['view_capability'], array( 'manage_options', 'edit_others_posts' ), true ) ) {
			return new \WP_Error( 'ri_capability', __( 'Unsupported viewing capability.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		$next['component']   = sanitize_text_field( $next['component'] );
		$next['custom_keys'] = array_values( array_unique( array_map( 'strtolower', $next['custom_keys'] ) ) );
		$next['revision']    = $current['revision'] + 1;
		return $next;
	}

	/**
	 * Explain effective detailed instrumentation.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function detailed( array $settings ) {
		return ! $settings['production_guard'] || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
