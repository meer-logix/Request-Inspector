<?php
/**
 * Bounded, conservative sanitization before persistence.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Redacts structured data; never attempts to recover secrets.
 */
final class Redactor {
	/**
	 * Restricted additional text patterns.
	 *
	 * @var array
	 */
	private $patterns;
	/**
	 * Mask.
	 */
	const MASK = '[REDACTED]';
	/**
	 * Custom field names.
	 *
	 * @var array Custom field names.
	 */
	private $keys;
	/**
	 * Remaining node budget.
	 *
	 * @var int Remaining node budget.
	 */
	private $nodes = 0;
	/**
	 * Remaining aggregate structured-text bytes.
	 *
	 * @var int
	 */
	private $remaining_bytes = 262144;

	/**
	 * Initialize additional redaction keys.
	 *
	 * @param array $keys Custom names.
	 */
	public function __construct( array $keys = array() ) {
		$this->keys     = array_map( 'strtolower', $keys );
		$this->patterns = Settings::get()['redaction_patterns'];
	}

	/**
	 * Sensitive.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	public function sensitive( $key ) {
		$key = strtolower( (string) $key );
		return in_array( $key, $this->keys, true ) || (bool) preg_match( '/authorization|cookie|passw|passwd|token|secret|api.?key|nonce|credential|session|auth.?header|email|phone|address|credit|card.?number/', $key );
	}

	/**
	 * Value.
	 *
	 * @param mixed $value Input.
	 * @return mixed
	 */
	public function value( $value ) {
		$this->nodes           = 0;
		$this->remaining_bytes = 262144;
		return $this->walk( $value, 0 );
	}

	/**
	 * Walk.
	 *
	 * @param mixed $value Input.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private function walk( $value, $depth ) {
		if ( ++$this->nodes > 2000 || $depth > 15 ) {
			return '[OMITTED: limit]';
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $child ) {
				if ( $this->nodes >= 2000 ) {
					$out['_omitted'] = 'node_limit';
					break;
				}
				$safe_key         = is_int( $key ) ? $key : $this->text( substr( (string) $key, 0, 100 ) );
				$out[ $safe_key ] = $this->sensitive( $key ) ? self::MASK : $this->walk( $child, $depth + 1 );
			}
			return $out;
		}
		if ( is_object( $value ) || is_resource( $value ) ) {
			return '[OMITTED: object]';
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > $this->remaining_bytes ) {
				return '[OMITTED: aggregate byte limit]';
			}
			$this->remaining_bytes -= strlen( $value );
			return $this->text( $value );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param string $text Untrusted diagnostic text.
	 * @return string
	 */
	public function text( $text ) {
		foreach ( $this->patterns as $pattern ) {
			if ( ! Policy::pattern( $pattern ) ) {
				return '[OMITTED: invalid redaction policy]';
			}
			$text = preg_replace( '~(*LIMIT_MATCH=2000)(*LIMIT_DEPTH=100)' . $pattern . '~u', self::MASK, substr( $text, 0, 65536 ) );
			if ( null === $text ) {
				return '[OMITTED: pattern limit]';
			}
		}
		if ( strlen( $text ) > 65536 ) {
			return '[OMITTED: text limit]';
		}
		$text = wp_check_invalid_utf8( $text, true );
		$text = preg_replace( '/\b(Bearer|Basic)\s+[^\s,;"<>]+/i', '$1 [REDACTED]', $text );
		$text = preg_replace( '/\b(?:sk|pk|api|ghp|gho)[_\-][a-zA-Z0-9_\-]{8,}\b/', self::MASK, $text );
		$text = preg_replace( '/[a-zA-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', self::MASK, $text );
		$text = preg_replace( '/((?:password|passwd|token|secret|api[_-]?key|nonce|authorization|cookie)\s*[=:]\s*)[^\s&,;]+/i', '$1[REDACTED]', $text );
		return (string) $text;
	}

	/**
	 * Headers.
	 *
	 * @param mixed $headers Header collection.
	 * @return array
	 */
	public function headers( $headers ) {
		if ( $headers instanceof \Traversable ) {
			$headers = iterator_to_array( $headers );
		}
		if ( is_string( $headers ) ) {
			$parsed = array();
			foreach ( explode( "\n", $headers ) as $line ) {
				$pair = explode( ':', $line, 2 );
				if ( 2 === count( $pair ) ) {
					$parsed[ trim( $pair[0] ) ] = trim( $pair[1] );
				}
			}
			$headers = $parsed;
		}
		$out = array();
		foreach ( array_slice( (array) $headers, 0, 100, true ) as $key => $value ) {
			$key = strtolower( substr( (string) $key, 0, 100 ) );
			// Only known protocol metadata may retain values. Unknown headers can carry credentials.
			$out[ $key ] = ! $this->sensitive( $key ) && in_array( $key, array( 'content-type', 'content-length', 'accept', 'accept-encoding', 'cache-control', 'date', 'server', 'content-encoding', 'allow' ), true ) ? $this->value( $value ) : self::MASK;
		}
		return $out;
	}

	/**
	 * Url.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function url( $url ) {
		$parts = wp_parse_url( substr( $url, 0, 8192 ) );
		if ( ! is_array( $parts ) ) {
			return '[OMITTED: invalid URL]';
		}
		$out  = isset( $parts['host'] ) ? ( ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) ) : '';
		$out .= $this->text( $parts['path'] ?? '/' );
		if ( isset( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$out .= '?' . http_build_query( $this->value( $query ) );
		}
		return $out;
	}

	/**
	 * Only JSON and URL-encoded forms are eligible for body storage.
	 *
	 * @param mixed  $body Input.
	 * @param string $mime MIME type.
	 * @param bool   $enabled Consent.
	 * @param int    $limit Stored byte ceiling.
	 * @return array
	 */
	public function body( $body, $mime, $enabled, $limit = 65536 ) {
		$mime = is_string( $mime ) ? $mime : '';
		$out  = array(
			'content'       => null,
			'content_type'  => $this->text( $mime ),
			'reason'        => 'disabled',
			'original_size' => is_string( $body ) ? strlen( $body ) : null,
			'stored_size'   => 0,
			'is_truncated'  => false,
		);
		if ( ! $enabled ) {
			return $out;
		}
		if ( ! is_array( $body ) && ! is_string( $body ) && null !== $body ) {
			$out['reason'] = 'unsupported_body_type';
			return $out;
		}
		if ( is_string( $body ) && strlen( $body ) > 1048576 ) {
			$out['reason'] = 'parse_limit';
			return $out;
		}
		if ( is_array( $body ) ) {
			$data = $body;
		} elseif ( '' === $body || null === $body ) {
			$data = '';
		} elseif ( str_contains( $mime, 'json' ) ) {
			$data = json_decode( $body, true, 16 );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$out['reason'] = 'malformed_json';
				return $out;
			}
		} elseif ( str_contains( $mime, 'application/x-www-form-urlencoded' ) ) {
			parse_str( $body, $data );
		} else {
			$out['reason'] = 'unsupported_content_type';
			return $out;
		}
		$safe                = wp_json_encode( $this->value( $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$out['is_truncated'] = strlen( $safe ) > $limit;
		$out['content']      = mb_strcut( $safe, 0, $limit, 'UTF-8' );
		$out['stored_size']  = strlen( $out['content'] );
		$out['reason']       = $out['is_truncated'] ? 'stored_limit' : null;
		return $out;
	}

	/**
	 * Remove SQL values and comments; never execute the result.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function sql( $sql ) {
		if ( strlen( $sql ) > 65536 ) {
			return '[OMITTED: SQL limit]';
		}
		$pattern = '/(?:\x27(?:\\\\.|\x27\x27|[^\x27\\\\])*\x27|"(?:\\\\.|""|[^"\\\\])*"|\/\*[\s\S]*?\*\/|--[^\r\n]*|\#[^\r\n]*|\b0x[0-9a-f]+\b|\b\d+(?:\.\d+)?\b)/i';
		$safe    = preg_replace( $pattern, '?', $sql );
		if ( null === $safe || preg_match( '/[\x27"\\\\]/', $safe ) ) {
			return '[OMITTED: unrecognized SQL]';
		}
		return $this->text( preg_replace( '/\s+/', ' ', $safe ) );
	}
}
