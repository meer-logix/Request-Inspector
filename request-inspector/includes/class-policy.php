<?php
/**
 * Capture network policy and bounded pattern grammar.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Policy never trusts forwarding headers from an unconfigured peer. */
final class Policy {
	/**
	 * Match an IPv4 or IPv6 CIDR.
	 *
	 * @param string $ip Candidate address.
	 * @param string $cidr Network.
	 * @return bool
	 */
	public static function contains( $ip, $cidr ) {
		$parts = explode( '/', $cidr, 2 );
		if ( false === filter_var( $parts[0], FILTER_VALIDATE_IP ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$network = inet_pton( $parts[0] );
		$address = inet_pton( $ip );
		if ( false === $network || false === $address || strlen( $network ) !== strlen( $address ) ) {
			return false;
		}
		$bits = isset( $parts[1] ) ? filter_var( $parts[1], FILTER_VALIDATE_INT ) : strlen( $network ) * 8;
		if ( false === $bits || $bits < 0 || $bits > strlen( $network ) * 8 ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$tail  = $bits % 8;
		return substr( $address, 0, $bytes ) === substr( $network, 0, $bytes ) && ( 0 === $tail || ( ord( $address[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) === ( ord( $network[ $bytes ] ) & ( 255 << ( 8 - $tail ) ) ) );
	}

	/**
	 * Check a network set.
	 *
	 * @param string $ip Address.
	 * @param array  $networks CIDRs.
	 * @return bool
	 */
	public static function matches( $ip, array $networks ) {
		foreach ( $networks as $network ) {
			if ( self::contains( $ip, $network ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve the first untrusted hop from a bounded forwarded chain.
	 *
	 * @param string $peer Direct connection peer.
	 * @param string $forwarded Forwarded address list.
	 * @param array  $trusted Trusted proxy networks.
	 * @return string
	 */
	public static function client( $peer, $forwarded, array $trusted ) {
		if ( ! self::matches( $peer, $trusted ) || strlen( $forwarded ) > 1024 ) {
			return $peer;
		}
		$chain = explode( ',', $forwarded );
		if ( count( $chain ) > 10 ) {
			return $peer;
		}
		foreach ( array_reverse( $chain ) as $hop ) {
			if ( ! self::matches( $peer, $trusted ) ) {
				break;
			}
			$hop = trim( $hop );
			if ( false === filter_var( $hop, FILTER_VALIDATE_IP ) ) {
				return '';
			}
			$peer = $hop;
		}
		return $peer;
	}

	/**
	 * Validate restricted PCRE without groups, alternation or unbounded quantifiers.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return bool
	 */
	public static function pattern( $pattern ) {
		if ( ! is_string( $pattern ) || '' === $pattern || strlen( $pattern ) > 120 || ! preg_match( '/^[a-zA-Z0-9 _.:@\[\]\-\^${},]+$/D', $pattern ) ) {
			return false;
		}
		preg_match_all( '/\{(\d+)(?:,(\d+))?\}/', $pattern, $quantifiers, PREG_SET_ORDER );
		$without_quantifiers = preg_replace( '/\{\d+(?:,\d+)?\}/', '', $pattern );
		if ( str_contains( $without_quantifiers, '{' ) || str_contains( $without_quantifiers, '}' ) ) {
			return false;
		}
		foreach ( $quantifiers as $quantifier ) {
			if ( (int) $quantifier[1] > 64 || ( isset( $quantifier[2] ) && (int) $quantifier[2] > 64 ) ) {
				return false;
			}
		}
		// Grammar excludes recursion/backreferences/lookarounds; compile without changing PHP reporting.
		return false !== @preg_match( '~(*LIMIT_MATCH=2000)(*LIMIT_DEPTH=100)' . $pattern . '~u', 'synthetic-preview' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid user patterns are rejected, not emitted as PHP warnings.
	}
}
