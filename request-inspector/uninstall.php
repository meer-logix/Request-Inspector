<?php
/**
 * Remove plugin-owned data only when explicitly configured.
 *
 * @package RequestInspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; }

$request_inspector_offset = 0;
do {
	$request_inspector_sites = is_multisite() ? get_sites(
		array(
			'number' => 100,
			'offset' => $request_inspector_offset,
			'fields' => 'ids',
		)
	) : array( get_current_blog_id() );
	foreach ( $request_inspector_sites as $request_inspector_site ) {
		if ( is_multisite() ) {
			switch_to_blog( $request_inspector_site );
		}
		wp_clear_scheduled_hook( 'request_inspector_cleanup' );
		$request_inspector_settings = get_option( 'request_inspector_settings', array() );
		if ( ! empty( $request_inspector_settings['delete_on_uninstall'] ) ) {
			global $wpdb;
			foreach ( array( 'bodies', 'events', 'requests', 'control', 'audit', 'live' ) as $request_inspector_table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'request_inspector_' . $request_inspector_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove only plugin-owned tables after explicit uninstall deletion consent.
			}
			delete_option( 'request_inspector_settings' );
			delete_option( 'request_inspector_schema' );
			delete_option( 'request_inspector_live_schema' );
			delete_metadata( 'user', 0, 'request_inspector_live_' . get_current_blog_id(), '', true );
			delete_option( 'request_inspector_scopes' );
		}
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}
	$request_inspector_offset += 100;
	$request_inspector_count   = count( $request_inspector_sites );
} while ( is_multisite() && 100 === $request_inspector_count );
