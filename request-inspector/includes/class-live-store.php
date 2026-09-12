<?php
/**
 * Private, expiring live snapshots owned by Request Inspector.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Bounded site-local snapshot repository. */
final class Live_Store {

	/** Install only through the existing plugin lifecycle. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->prefix . 'request_inspector_live';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			uuid varchar(36) NOT NULL,
			owner bigint(20) unsigned NOT NULL,
			session char(64) NOT NULL,
			expires bigint(20) unsigned NOT NULL,
			generation bigint(20) unsigned NOT NULL,
			bytes int(10) unsigned NOT NULL,
			read_count smallint(5) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			PRIMARY KEY  (uuid),
			KEY expiry (expires),
			KEY ownership (owner,session,expires)
		) $charset;"
		);
		// Verify schema existence before enabling requests to use it.
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema verification cannot use object caching.
			update_option( 'request_inspector_live_schema', 1, false );
		}
	}

	/** Hash the authenticated session; never retain the raw token. */
	public static function session() {
		$token = wp_get_session_token();
		return $token ? hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ) : '';
	}

	/** Remove a bounded batch; reads independently enforce expiry. */
	public static function cleanup() {
		global $wpdb;
		if ( 1 !== (int) get_option( 'request_inspector_live_schema' ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires < %d LIMIT 50', $wpdb->prefix . 'request_inspector_live', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded maintenance on plugin-owned data.
	}

	/**
	 * Admit one immutable snapshot under a cross-worker quota lock.
	 *
	 * @param array $identity Authenticated request identity.
	 * @param array $payload Sanitized, bounded snapshot.
	 * @return bool
	 */
	public static function put( array $identity, array $payload ) {
		global $wpdb;
		$json = wp_json_encode( $payload );
		if ( ! $json || strlen( $json ) > 262144 || 1 !== (int) get_option( 'request_inspector_live_schema' ) ) {
			return false;
		}
		$table         = $wpdb->prefix . 'request_inspector_live';
		$lock          = 'ri_live_' . substr( hash( 'sha256', constant( 'DB_NAME' ) . $table ), 0, 48 );
		$busy          = Storage::$busy;
		Storage::$busy = true;
		try {
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic admission requires a connection-owned lock.
				return false;
			}
			try {
				self::cleanup();
				$usage = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS rows_used, COALESCE(SUM(bytes),0) AS bytes_used, COALESCE(SUM(owner=%d AND session=%s),0) AS owner_used FROM %i', $identity['owner'], $identity['session'], $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live admission totals cannot be cached.
				if ( ! $usage || (int) $usage['rows_used'] >= 200 || (int) $usage['owner_used'] >= 20 || (int) $usage['bytes_used'] + strlen( $json ) > 8388608 ) {
					return false;
				}
				return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Insert bounded private telemetry into the plugin-owned table.
					$table,
					array(
						'uuid'       => $identity['uuid'],
						'owner'      => $identity['owner'],
						'session'    => $identity['session'],
						'expires'    => $identity['expires'],
						'generation' => $identity['generation'],
						'bytes'      => strlen( $json ),
						'payload'    => $json,
					),
					array( '%s', '%d', '%s', '%d', '%d', '%d', '%s' )
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A snapshot is private temporary telemetry, not an option.
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release only this connection's admission lock.
			}
		} finally {
			Storage::$busy = $busy;
		}
	}

	/**
	 * Read only the current owner's session and generation.
	 *
	 * @param string $uuid Request identifier.
	 * @return array|\WP_Error|null
	 */
	public static function get( $uuid ) {
		global $wpdb;
		$generation = ( new Storage() )->state()['generation'] ?? 0;
		$updated    = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET read_count=read_count+1 WHERE uuid=%s AND owner=%d AND session=%s AND expires>=%d AND generation=%d AND read_count<120', $wpdb->prefix . 'request_inspector_live', $uuid, get_current_user_id(), self::session(), time(), $generation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic read quota under the same ownership predicate.
		$json       = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE uuid=%s AND owner=%d AND session=%s AND expires>=%d AND generation=%d', $wpdb->prefix . 'request_inspector_live', $uuid, get_current_user_id(), self::session(), time(), $generation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Private expiring snapshots must not be served from a shared cache.
		if ( false === $updated ) {
			return new \WP_Error( 'ri_live_storage', __( 'Private snapshot storage is unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
		}
		if ( $json && 1 !== $updated ) {
			return new \WP_Error( 'ri_live_rate', __( 'Snapshot read limit reached. Use another page request for new diagnostics.', 'request-inspector' ), array( 'status' => 429 ) );
		}
		$data = $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && 'request-inspector/live/1' === ( $data['schema'] ?? '' ) ? $data : null;
	}
}
