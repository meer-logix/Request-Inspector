<?php
/**
 * Bounded sanitized portable trace exports.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Exports remain in memory and never create public temporary files. */
final class Exports {
	/**
	 * Read and re-redact a trace under current policy.
	 *
	 * @param int $id Request ID.
	 * @return array|\WP_Error
	 */
	public static function trace( $id ) {
		$storage = new Storage();
		$record  = $storage->request( $id );
		if ( ! $record ) {
			return new \WP_Error( 'ri_missing', __( 'Capture unavailable.', 'request-inspector' ), array( 'status' => 404 ) );
		}
		$redactor  = new Redactor( Settings::get()['custom_keys'] );
		$page      = $storage->listing(
			array(
				'trace_id'        => $record['trace_id'],
				'per_page'        => 100,
				'include_samples' => true,
			)
		);
		$requests  = array();
		$bytes     = 0;
		$truncated = $page['total'] > 100;
		foreach ( $page['items'] as $row ) {
			$row['url']      = $redactor->url( $row['url'] );
			$row['metadata'] = $redactor->value( $row['metadata'] );
			$row['events']   = array();
			for ( $number = 1; $number <= 10; ++$number ) {
				$events = $storage->events(
					array(
						'request_id'      => $row['id'],
						'page'            => $number,
						'per_page'        => 100,
						'include_samples' => true,
					)
				);
				foreach ( $events['items'] as $event ) {
					$event['name']     = 'db_query' === $event['event_type'] ? $redactor->sql( $event['name'] ) : $redactor->text( $event['name'] );
					$event['metadata'] = $redactor->value( $event['metadata'] );
					$row['events'][]   = $event;
				}
				if ( $number * 100 >= $events['total'] ) {
					break;
				}
			}
			$row['bodies'] = $storage->bodies( $row['id'] );
			foreach ( $row['bodies'] as &$body ) {
				if ( null !== $body['content'] ) {
					$body = $redactor->body( $body['content'], 'application/json', true, Settings::get()['max_body_kb'] * 1024 );
				}
			}
			unset( $body );
			$bytes += strlen( wp_json_encode( $row ) );
			if ( $bytes > 5242880 ) {
				$truncated = true;
				break;
			}
			$requests[] = $row;
		}
		return array(
			'schema'      => 'request-inspector/1',
			'exported_at' => gmdate( 'c' ),
			'truncated'   => $truncated,
			'coverage'    => 'server_side_wordpress_only',
			'requests'    => $requests,
		);
	}

	/**
	 * Serialize a portable format.
	 *
	 * @param int    $id Request ID.
	 * @param string $format Format.
	 * @return array|\WP_Error
	 */
	public static function create( $id, $format = 'json' ) {
		$trace = self::trace( $id );
		if ( is_wp_error( $trace ) ) {
			return $trace;
		}
		$data      = 'bodies' === $format ? array_column( $trace['requests'], 'bodies', 'id' ) : $trace;
		$extension = 'json';
		$mime      = 'application/json';
		if ( 'har' === $format ) {
			$data      = self::har( $trace['requests'] );
			$extension = 'har';
		}
		$content = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( 'sql' === $format ) {
			$content = "-- Sanitized diagnostic text; placeholders may not be executable.\n";
			foreach ( $trace['requests'] as $row ) {
				foreach ( $row['events'] as $event ) {
					if ( 'db_query' === $event['event_type'] ) {
						$content .= $event['name'] . ";\n";
					}
				}
			}
			$extension = 'sql';
			$mime      = 'text/plain';
		} elseif ( 'curl' === $format ) {
			$record = ( new Storage() )->request( $id );
			if ( ! $record ) {
				return new \WP_Error( 'ri_missing', __( 'Capture expired.', 'request-inspector' ), array( 'status' => 404 ) );
			}
			$url       = ( new Redactor( Settings::get()['custom_keys'] ) )->url( $record['url'] );
			$content   = "# POSIX shell. Credentials and body omitted. Review before running.\n" . 'curl --request ' . self::quote( $record['method'] ) . ' --url ' . self::quote( $url ) . "\n";
			$extension = 'sh';
			$mime      = 'text/plain';
		}
		return array(
			'filename'  => 'request-inspector-' . (int) $id . '.' . $extension,
			'mime'      => $mime,
			'content'   => $content,
			'truncated' => $trace['truncated'],
		);
	}

	/**
	 * Project server HTTP entries into HAR.
	 *
	 * @param array $requests Sanitized requests.
	 * @return array
	 */
	private static function har( array $requests ) {
		$entries = array();
		foreach ( $requests as $row ) {
			if ( 'outgoing' !== $row['direction'] ) {
				continue;
			}
			$entries[] = array(
				'startedDateTime'   => str_replace( ' ', 'T', $row['started_at'] ) . 'Z',
				'time'              => $row['duration_ms'],
				'request'           => array(
					'method'      => $row['method'],
					'url'         => $row['url'],
					'httpVersion' => '',
					'cookies'     => array(),
					'headers'     => array(),
					'queryString' => array(),
					'headersSize' => -1,
					'bodySize'    => -1,
				),
				'response'          => array(
					'status'      => (int) $row['status'],
					'statusText'  => '',
					'httpVersion' => '',
					'cookies'     => array(),
					'headers'     => array(),
					'content'     => array(
						'size'     => 0,
						'mimeType' => '',
						'comment'  => 'Body omitted; original size unavailable.',
					),
					'redirectURL' => '',
					'headersSize' => -1,
					'bodySize'    => -1,
				),
				'cache'             => new \stdClass(),
				'timings'           => array(
					'send'    => 0,
					'wait'    => $row['duration_ms'],
					'receive' => 0,
				),
				'comment'           => 'Projection only: elapsed HTTP API span allocated to wait for HAR compatibility. Network phases and response sizes were not measured.',
				'_timingProjection' => true,
			);
		}
		return array(
			'log' => array(
				'version' => '1.2',
				'creator' => array(
					'name'    => 'Request Inspector',
					'version' => REQUEST_INSPECTOR_VERSION,
				),
				'entries' => $entries,
				'comment' => 'Sanitized server-side projection, not a browser capture.',
			),
		);
	}

	/**
	 * Quote a POSIX shell argument without executing it.
	 *
	 * @param string $value Argument.
	 * @return string
	 */
	private static function quote( $value ) {
		return "'" . str_replace( "'", "'\"'\"'", $value ) . "'";
	}
}
