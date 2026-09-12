<?php
/**
 * Retention-respecting saved scopes, comparisons and reports.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/** Saved scope metadata never pins or copies captured content. */
final class Workflows {
	/**
	 * Register authenticated workflow routes.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( array( 'sessions', 'compare', 'report', 'regression' ) as $action ) {
			register_rest_route(
				'request-inspector/v1',
				'/workflows/' . $action,
				array(
					'methods'             => 'POST',
					'permission_callback' => array( new Api(), 'view' ),
					'callback'            => array( $this, $action ),
				)
			);
		}
	}

	/**
	 * Check access to a saved scope.
	 *
	 * @param array $scope Saved scope.
	 * @return bool
	 */
	private function allowed( array $scope ) {
		return current_user_can( 'manage_options' ) || get_current_user_id() === $scope['owner'] || in_array( get_current_user_id(), $scope['shared_with'], true );
	}

	/**
	 * Read live saved scope metadata; expired scopes are inaccessible.
	 *
	 * @return array
	 */
	private function scopes() {
		return array_filter( (array) get_option( 'request_inspector_scopes', array() ), fn( $scope ) => $scope['expires'] > time() );
	}

	/**
	 * Create, stop, share, revoke, delete or inspect a local saved scope.
	 *
	 * @param \WP_REST_Request $request Operation.
	 * @return array|\WP_Error
	 */
	public function sessions( $request ) {
		$operation = $request['operation'] ?? 'list';
		if ( 'list' === $operation ) {
			return array_values( array_filter( $this->scopes(), array( $this, 'allowed' ) ) );
		}
		if ( 'show' === $operation ) {
			return $this->members( $request['id'] );
		}
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $operation, array( 'create', 'stop', 'share', 'revoke', 'delete' ), true ) ) {
			return new \WP_Error( 'ri_scope_access', __( 'Only administrators can manage saved scopes.', 'request-inspector' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$lock = 'ri_scopes_' . substr( hash( 'sha256', $wpdb->prefix ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,1)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Serialize bounded option updates across workers.
			return new \WP_Error( 'ri_busy', __( 'Saved scopes are being updated.', 'request-inspector' ), array( 'status' => 409 ) );
		}
		try {
			wp_cache_delete( 'request_inspector_scopes', 'options' );
			$scopes = $this->scopes();
			$id     = is_string( $request['id'] ) ? $request['id'] : '';
			if ( 'create' === $operation ) {
				if ( count( $scopes ) >= 20 || ! is_string( $request['name'] ) || '' === trim( $request['name'] ) || strlen( $request['name'] ) > 80 ) {
					return new \WP_Error( 'ri_scope_limit', __( 'Use a name of 1–80 characters; at most twenty active saved scopes are retained.', 'request-inspector' ), array( 'status' => 400 ) );
				}
				$id                        = wp_generate_uuid4();
				$scopes[ $id ]             = array(
					'id'             => $id,
					'name'           => ( new Redactor() )->text( sanitize_text_field( $request['name'] ) ),
					'owner'          => get_current_user_id(),
					'started'        => gmdate( 'Y-m-d H:i:s' ),
					'stopped'        => null,
					'expires'        => time() + min( 7, Settings::get()['retention_days'] ) * DAY_IN_SECONDS,
					'shared_with'    => array(),
					'recording_mode' => Settings::get()['mode'],
				);
				$scopes[ $id ]['first_id'] = ( new Storage() )->latest_id() + 1;
			} elseif ( ! isset( $scopes[ $id ] ) ) {
				return new \WP_Error( 'ri_scope_missing', __( 'Saved scope expired or was removed.', 'request-inspector' ), array( 'status' => 404 ) );
			} elseif ( 'stop' === $operation ) {
				$scopes[ $id ]['stopped'] = gmdate( 'Y-m-d H:i:s' );
				$scopes[ $id ]['last_id'] = ( new Storage() )->latest_id();
			} elseif ( 'delete' === $operation ) {
				unset( $scopes[ $id ] );
			} elseif ( 'revoke' === $operation ) {
				$scopes[ $id ]['shared_with'] = array();
			} elseif ( 'share' === $operation ) {
				$users = $request['users'];
				if ( ! is_array( $users ) || count( $users ) > 20 ) {
					return new \WP_Error( 'ri_scope_users', __( 'Share with at most twenty local user IDs.', 'request-inspector' ), array( 'status' => 400 ) );
				}
				foreach ( $users as $user ) {
					if ( ! is_int( $user ) || ! user_can( $user, Settings::get()['view_capability'] ) ) {
						return new \WP_Error( 'ri_scope_users', __( 'Every shared user must already have diagnostic viewing permission.', 'request-inspector' ), array( 'status' => 400 ) );
					}
				}
				$scopes[ $id ]['shared_with'] = array_values( array_unique( $users ) );
			}
			if ( ! Audit::add( 'scope_' . $operation ) ) {
				return new \WP_Error( 'ri_audit', __( 'Audit storage unavailable.', 'request-inspector' ), array( 'status' => 503 ) );
			}
			update_option( 'request_inspector_scopes', $scopes, false );
			return array_values( array_filter( $scopes, array( $this, 'allowed' ) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release scope metadata lock.
		}
	}

	/**
	 * Resolve retained trace membership by the saved time window.
	 *
	 * @param string $id Scope ID.
	 * @return array|\WP_Error
	 */
	private function members( $id ) {
		$scope = is_string( $id ) ? ( $this->scopes()[ $id ] ?? null ) : null;
		if ( ! $scope || ! $this->allowed( $scope ) ) {
			return new \WP_Error( 'ri_scope_missing', __( 'Saved scope is unavailable.', 'request-inspector' ), array( 'status' => 404 ) );
		}
		$page = ( new Storage() )->listing(
			array(
				'id_min'          => $scope['first_id'] ?? 0,
				'id_max'          => $scope['last_id'] ?? PHP_INT_MAX,
				'per_page'        => 100,
				'direction'       => 'incoming',
				'include_context' => true,
			)
		);
		return array(
			'scope'     => $scope,
			'requests'  => $page['items'],
			'total'     => $page['total'],
			'partial'   => $page['total'] > 100,
			'retention' => 'global_capture_retention_applies_no_pinning',
		);
	}

	/**
	 * Define comparability without treating different routes or policies as equal.
	 *
	 * @param array $row Request.
	 * @return array
	 */
	private function signature( array $row ) {
		$parts = wp_parse_url( $row['url'] );
		return array(
			'method'          => $row['method'],
			'route'           => ( $parts['host'] ?? '' ) . preg_replace( '~/[0-9]+(?=/|$)~', '/:id', $parts['path'] ?? '/' ),
			'direction'       => $row['direction'],
			'context'         => $row['type'],
			'wp'              => $row['metadata']['wp'] ?? null,
			'php'             => $row['metadata']['php'] ?? null,
			'mode'            => $row['metadata']['mode'] ?? null,
			'sample_percent'  => $row['metadata']['sample_percent'] ?? null,
			'database'        => $row['metadata']['database_available'] ?? false,
			'hooks'           => $row['metadata']['hooks_available'] ?? false,
			'capture_options' => $row['metadata']['capture_options'] ?? null,
		);
	}

	/**
	 * Compare 2–5 retained requests with sanitized payload excerpts.
	 *
	 * @param \WP_REST_Request $request Request IDs.
	 * @return array|\WP_Error
	 */
	public function compare( $request ) {
		$ids = $request['ids'];
		if ( ! is_array( $ids ) || count( $ids ) < 2 || count( $ids ) > 5 ) {
			return new \WP_Error( 'ri_compare', __( 'Select two to five distinct request IDs.', 'request-inspector' ), array( 'status' => 400 ) );
		}
		$storage    = new Storage();
		$rows       = array();
		$signatures = array();
		$complete   = true;
		$seen_ids   = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) || ! ctype_digit( (string) $id ) ) {
				return new \WP_Error( 'ri_id', __( 'Invalid request ID.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			if ( isset( $seen_ids[ (string) $id ] ) ) {
				return new \WP_Error( 'ri_id', __( 'Select distinct request IDs.', 'request-inspector' ), array( 'status' => 400 ) );
			}
			$seen_ids[ (string) $id ] = true;
			$row                      = $storage->request( $id );
			if ( ! $row ) {
				return new \WP_Error( 'ri_missing', __( 'A selected capture expired.', 'request-inspector' ), array( 'status' => 404 ) );
			}
			$signature    = $this->signature( $row );
			$complete     = $complete && null !== $signature['wp'] && null !== $signature['php'] && null !== $signature['mode'] && null !== $signature['capture_options'];
			$signatures[] = wp_json_encode( $signature );
			$bodies       = $storage->bodies( $id );
			foreach ( $bodies as &$body ) {
				$body = null === $body['content'] ? $body : ( new Redactor( Settings::get()['custom_keys'] ) )->body( $body['content'], 'application/json', true, 8192 );
			}
			unset( $body );
			$rows[] = array(
				'id'          => $row['id'],
				'signature'   => $signature,
				'duration_ms' => $row['duration_ms'],
				'queries'     => $row['metadata']['queries'] ?? null,
				'issues'      => $row['metadata']['issues'] ?? null,
				'status'      => $row['status'],
				'bodies'      => $bodies,
			);
		}
		foreach ( $rows as &$row ) {
			$row['delta_ms'] = $row['duration_ms'] - $rows[0]['duration_ms'];
		}
		unset( $row );
		return array(
			'comparable' => $complete && 1 === count( array_unique( $signatures ) ),
			'requests'   => $rows,
			'note'       => 'Route normalization does not prove identical workload; compare collection coverage and payloads.',
		);
	}

	/**
	 * Report inclusive activity for at most 1,000 recent requests.
	 *
	 * @return array
	 */
	public function report() {
		$groups  = array();
		$storage = new Storage();
		for ( $page = 1; $page <= 10; ++$page ) {
			$data = $storage->listing(
				array(
					'page'     => $page,
					'per_page' => 100,
				)
			);
			foreach ( $data['items'] as $row ) {
				$key            = $row['component'];
				$groups[ $key ] = $groups[ $key ] ?? array(
					'component'    => $key,
					'requests'     => 0,
					'failures'     => 0,
					'inclusive_ms' => 0,
				);
				++$groups[ $key ]['requests'];
				$groups[ $key ]['failures']     += (int) $row['has_error'];
				$groups[ $key ]['inclusive_ms'] += $row['duration_ms'];
			}
			if ( $page * 100 >= $data['total'] ) {
				break;
			}
		}
		foreach ( $groups as &$group ) {
			$group['failure_rate'] = $group['failures'] / $group['requests'];
		}
		unset( $group );
		return array(
			'components' => array_values( $groups ),
			'partial'    => $data['total'] > 1000,
			'scope'      => 'latest_1000_retained_non_sample_requests',
			'timing'     => 'inclusive_elapsed_not_exclusive_cpu_or_causal_blame',
		);
	}

	/**
	 * Compare saved-scope distributions with a minimum sample and variance gate.
	 *
	 * @param \WP_REST_Request $request Baseline and candidate scope IDs.
	 * @return array|\WP_Error
	 */
	public function regression( $request ) {
		$sets      = array( $this->members( $request['baseline'] ), $this->members( $request['candidate'] ) );
		$summary   = array();
		$signature = null;
		$seen      = array();
		foreach ( $sets as $set ) {
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			if ( count( $set['requests'] ) < 10 ) {
				return array(
					'status'            => 'insufficient_samples',
					'minimum_per_scope' => 10,
				);
			}
			$values = array();
			foreach ( $set['requests'] as $row ) {
				if ( isset( $seen[ $row['id'] ] ) ) {
					return array( 'status' => 'overlapping_samples_select_disjoint_scopes' );
				}
				$seen[ $row['id'] ] = true;
				$details            = $this->signature( $row );
				if ( null === $details['wp'] || null === $details['php'] || null === $details['mode'] || null === $details['capture_options'] ) {
					return array( 'status' => 'incomplete_capture_policy_metadata' );
				}
				$current   = wp_json_encode( $details );
				$signature = $signature ?? $current;
				if ( $signature !== $current ) {
					return array( 'status' => 'incomparable_workloads_or_capture_policies' );
				}
				$values[] = $row['duration_ms'];
			}
			$count     = count( $values );
			$mean      = array_sum( $values ) / $count;
			$variance  = array_sum( array_map( fn( $value ) => ( $value - $mean ) ** 2, $values ) ) / ( $count - 1 );
			$summary[] = array(
				'count'        => $count,
				'mean_ms'      => $mean,
				'margin_95_ms' => 1.96 * sqrt( $variance / $count ),
				'partial'      => $set['partial'],
			);
		}
		$delta  = $summary[1]['mean_ms'] - $summary[0]['mean_ms'];
		$signal = $delta > max( 10, $summary[0]['mean_ms'] * 0.2 ) && $summary[1]['mean_ms'] - $summary[1]['margin_95_ms'] > $summary[0]['mean_ms'] + $summary[0]['margin_95_ms'];
		return array(
			'status'        => $signal ? 'review_regression_signal' : 'no_clear_regression_signal',
			'samples'       => $summary,
			'delta_ms'      => $delta,
			'notifications' => 'disabled_manual_review_required',
		);
	}
}
