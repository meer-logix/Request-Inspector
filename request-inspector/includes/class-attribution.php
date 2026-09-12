<?php
/**
 * Bounded caller attribution without argument capture.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves observed caller files to components.
 */
final class Attribution {
	/**
	 * File.
	 *
	 * @param string $file Absolute file.
	 * @return array
	 */
	public static function file( $file ) {
		$file  = wp_normalize_path( $file );
		$roots = array(
			'plugin'    => WP_PLUGIN_DIR,
			'mu-plugin' => WPMU_PLUGIN_DIR,
			'theme'     => WP_CONTENT_DIR . '/themes',
		);
		foreach ( $roots as $type => $root ) {
			$prefix = trailingslashit( wp_normalize_path( $root ) );
			if ( str_starts_with( strtolower( $file ), strtolower( $prefix ) ) ) {
				$relative = substr( $file, strlen( $prefix ) );
				return array(
					'component' => explode( '/', $relative )[0],
					'kind'      => $type,
					'file'      => $type . '/' . $relative,
				);
			}
		}
		$root = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( str_starts_with( strtolower( $file ), strtolower( $root ) ) ) {
			return array(
				'component' => 'Core',
				'kind'      => 'core',
				'file'      => substr( $file, strlen( $root ) ),
			);
		}
		return array(
			'component' => 'Unknown',
			'kind'      => 'unknown',
			'file'      => basename( $file ),
		);
	}

	/**
	 * Caller.
	 *
	 * @param bool $full Whether to include trace frames.
	 * @return array
	 */
	public static function caller( $full = false ) {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Diagnostic attribution is bounded and excludes all callback arguments.
		$out    = array(
			'component' => 'Unknown',
			'file'      => null,
			'line'      => null,
			'function'  => null,
			'trace'     => array(),
		);
		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) || str_starts_with( wp_normalize_path( $frame['file'] ), wp_normalize_path( REQUEST_INSPECTOR_DIR ) ) ) {
				continue;
			}
			$location             = self::file( $frame['file'] );
			$location['line']     = (int) ( $frame['line'] ?? 0 );
			$location['function'] = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . $frame['function'];
			$location['function'] = preg_replace( '/@anonymous.*/s', '@anonymous', $location['function'] );
			if ( $full ) {
				$out['trace'][] = $location;
			}
			if ( 'Unknown' === $out['component'] || ( 'Core' === $out['component'] && 'Core' !== $location['component'] ) ) {
				$out = array_merge( $out, $location );
			}
		}
		return $out;
	}
}
