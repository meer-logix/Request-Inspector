<?php
/**
 * Permission-preserving WP-CLI commands.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Use --url for multisite and --user for authorization. */
final class Cli {
	/**
	 * Enforce REST-equivalent operator permissions.
	 *
	 * @param bool $manage Management operation.
	 * @return void
	 */
	private function permission( $manage = false ) {
		if ( ! current_user_can( $manage ? 'manage_options' : Settings::get()['view_capability'] ) ) {
			\WP_CLI::error( 'Use --user with an authorized diagnostic operator.' );
		}
	}

	/**
	 * List latest sanitized captures.
	 *
	 * @subcommand list
	 * @return void
	 */
	public function listing() {
		$this->permission();
		\WP_CLI::line( wp_json_encode( ( new Storage() )->listing( array() ), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Show a sanitized trace.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Retained request ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function show( $args ) {
		$this->permission();
		$result = Exports::trace( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Export sanitized JSON to standard output.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Retained request ID.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function export( $args ) {
		$this->permission( true );
		if ( ! Audit::add( 'cli_export', (int) $args[0] ) ) {
			\WP_CLI::error( 'Audit storage unavailable.' );
		}
		$result = Exports::create( (int) $args[0] );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( $result['content'] );
	}

	/**
	 * Enable or disable recording with validated policy.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : on or off.
	 * [--mode=<mode>]
	 * : Recording mode.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function record( $args, $assoc ) {
		$this->permission( true );
		if ( ! in_array( $args[0], array( 'on', 'off' ), true ) ) {
			\WP_CLI::error( 'State must be on or off.' );
		}
		$request = new \WP_REST_Request( 'POST', '/request-inspector/v1/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$settings = Settings::get();
		$request->set_body(
			wp_json_encode(
				array(
					'revision' => $settings['revision'],
					'enabled'  => 'on' === $args[0],
					'mode'     => $assoc['mode'] ?? $settings['mode'],
				)
			)
		);
		$result = ( new Api() )->save_settings( $request );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( 'Recording policy updated.' );
	}

	/**
	 * Purge captures after confirmation.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirm without prompting.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc Named arguments.
	 * @return void
	 */
	public function purge( $args, $assoc ) {
		$this->permission( true );
		\WP_CLI::confirm( 'Purge captures for the selected site?', $assoc );
		if ( ! Audit::add( 'cli_purge' ) ) {
			\WP_CLI::error( 'Audit storage unavailable.' );
		}
		( new Storage() )->purge();
		\WP_CLI::success( 'Captures invalidated; bounded maintenance removes remaining rows.' );
	}
}
