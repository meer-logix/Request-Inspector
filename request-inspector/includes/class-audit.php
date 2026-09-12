<?php
/**
 * Minimized site-local operation audit.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Audit contains actor IDs and operation IDs, never captured payloads or IPs. */
final class Audit {
	/**
	 * Expire audit entries independently of new operations.
	 *
	 * @return void
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s LIMIT 1000', $wpdb->prefix . 'request_inspector_audit', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded expiration of plugin-owned audit records.
	}
	/**
	 * Install the audit table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		$table   = $wpdb->prefix . 'request_inspector_audit';
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		operator_id bigint(20) unsigned NOT NULL,
		action varchar(40) NOT NULL,
		object_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY created (created_at)
		) $collate;"
		);
	}

	/**
	 * Append an operation and retain at most 1,000 records / 30 days.
	 *
	 * @param string $action Fixed operation name.
	 * @param int    $id Related object ID.
	 * @return bool
	 */
	public static function add( $action, $id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'request_inspector_audit';
		$lock  = 'ri_audit_' . substr( hash( 'sha256', $table ), 0, 40 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Plugin-owned audit requires live atomic writes and bounded retention.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) ) {
			return false;
		}
		try {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at<%s LIMIT 1000', $table, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
			$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( $count >= 1000 ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i ORDER BY id ASC LIMIT %d', $table, $count - 999 ) );
			}
			return false !== $wpdb->insert(
				$table,
				array(
					'operator_id' => get_current_user_id(),
					'action'      => sanitize_key( $action ),
					'object_id'   => (int) $id,
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Return a bounded administrative audit page.
	 *
	 * @param int $page Page number.
	 * @return array
	 */
	public static function listing( $page = 1 ) {
		self::cleanup();
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 50 OFFSET %d', $wpdb->prefix . 'request_inspector_audit', ( max( 1, min( 20, (int) $page ) ) - 1 ) * 50 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live bounded audit page, admin-only and never cached publicly.
	}
}
