<?php
/**
 * Noninvasive hook registry inspection.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Inspect registrations without wrapping or invoking callbacks. */
final class Hooks {
	/**
	 * Snapshot at most twenty registered callbacks at the observation instant.
	 *
	 * @param string $name Observed hook name.
	 * @return array Registry metadata, never evidence that callbacks executed.
	 */
	public static function snapshot( $name ) {
		global $wp_filter;
		$redactor = new Redactor();
		$rows     = array();
		$total    = 0;
		$registry = isset( $wp_filter[ $name ] ) ? $wp_filter[ $name ]->callbacks : array();
		foreach ( $registry as $priority => $callbacks ) {
			$total += count( $callbacks );
			foreach ( $callbacks as $callback ) {
				if ( count( $rows ) >= 20 ) {
					continue;
				}
				$function = $callback['function'];
				$label    = 'Closure';
				$location = array(
					'component' => 'Unknown',
					'file'      => null,
				);
				try {
					if ( is_array( $function ) ) {
						$label      = ( is_object( $function[0] ) ? get_class( $function[0] ) : $function[0] ) . '::' . $function[1];
						$reflection = new \ReflectionMethod( $function[0], $function[1] );
					} elseif ( is_string( $function ) && str_contains( $function, '::' ) ) {
						$label      = $function;
						$parts      = explode( '::', $function, 2 );
						$reflection = new \ReflectionMethod( $parts[0], $parts[1] );
					} elseif ( is_object( $function ) && ! $function instanceof \Closure ) {
						$label      = get_class( $function ) . '::__invoke';
						$reflection = new \ReflectionMethod( $function, '__invoke' );
					} else {
						$label      = is_string( $function ) ? $function : 'Closure';
						$reflection = new \ReflectionFunction( $function );
					}
					$file     = $reflection->getFileName();
					$location = $file ? Attribution::file( $file ) : array(
						'component' => 'PHP',
						'file'      => null,
					);
				} catch ( \ReflectionException $exception ) {
					$label = 'Unavailable callback';
				}
				$label  = preg_replace( '/@anonymous.*/s', '@anonymous', $label );
				$rows[] = array_merge(
					$location,
					array(
						'function'      => $redactor->text( $label ),
						'priority'      => (int) $priority,
						'accepted_args' => (int) $callback['accepted_args'],
					)
				);
			}
		}
		return array(
			'callbacks'     => $rows,
			'registered'    => $total,
			'timed'         => 0,
			'timing_reason' => 'callback_wrapping_not_supported',
			'snapshot'      => 'first_observation',
		);
	}
}
