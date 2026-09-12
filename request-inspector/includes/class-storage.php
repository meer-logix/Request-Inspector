<?php
/**
 * Site-scoped telemetry storage and bounded maintenance.
 *
 * @package RequestInspector
 */

namespace RequestInspector;

defined( 'ABSPATH' ) || exit;

/**
 * Repositories for custom tables. No captured data is kept in options.
 */
final class Storage {
	/**
	 * Connection owned by WordPress.
	 *
	 * @var \wpdb Connection owned by WordPress.
	 */
	private $db;
	/**
	 * Fixed table names.
	 *
	 * @var array Fixed table names.
	 */
	public $tables;
	/**
	 * Suppress instrumentation during internal operations.
	 *
	 * @var bool Suppress instrumentation during internal operations.
	 */
	public static $busy = false;

	/**
	 * Initialize the current site's table names.
	 */
	public function __construct() {
		global $wpdb;
		$this->db     = $wpdb;
		$this->tables = array();
		foreach ( array( 'requests', 'events', 'bodies', 'control' ) as $name ) {
			$this->tables[ $name ] = $wpdb->prefix . 'request_inspector_' . $name;
		}
	}

	/**
	 * Install repeatable schema.
	 *
	 * @return void
	 */
	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $this->db->get_charset_collate();
		$r       = $this->tables['requests'];
		$e       = $this->tables['events'];
		$b       = $this->tables['bodies'];
		$c       = $this->tables['control'];
		dbDelta(
			"CREATE TABLE $r (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trace_id varchar(36) NOT NULL,
			parent_id bigint(20) unsigned DEFAULT NULL,
			generation bigint(20) unsigned NOT NULL DEFAULT 1,
			complete tinyint(1) NOT NULL DEFAULT 0,
			direction varchar(16) NOT NULL,
			type varchar(24) NOT NULL,
			method varchar(16) NOT NULL,
			url text NOT NULL,
			url_hash char(64) NOT NULL,
			status smallint(5) unsigned DEFAULT NULL,
			duration_us bigint(20) unsigned NOT NULL DEFAULT 0,
			component varchar(191) NOT NULL DEFAULT 'Unknown',
			has_error tinyint(1) NOT NULL DEFAULT 0,
			is_sample tinyint(1) NOT NULL DEFAULT 0,
			context_only tinyint(1) NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			reserved_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			reserved_records int(10) unsigned NOT NULL DEFAULT 0,
			metadata longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY started (started_at,id),
			KEY trace (trace_id),
			KEY parent (parent_id),
			KEY status (status,started_at),
			KEY component (component,started_at),
			KEY duration (duration_us),
			KEY generation (generation,complete),
			KEY url_hash (url_hash)
		) $collate;"
		);
		dbDelta(
			"CREATE TABLE $e (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			event_type varchar(24) NOT NULL,
			name text NOT NULL,
			duration_us bigint(20) unsigned DEFAULT NULL,
			start_offset_us bigint(20) unsigned DEFAULT NULL,
			component varchar(191) NOT NULL DEFAULT 'Unknown',
			metadata longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY request_type (request_id,event_type,id)
		) $collate;"
		);
		dbDelta(
			"CREATE TABLE $b (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			direction varchar(16) NOT NULL,
			metadata longtext NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_direction (request_id,direction)
		) $collate;"
		);
		dbDelta(
			"CREATE TABLE $c (
			id tinyint(1) NOT NULL,
			generation bigint(20) unsigned NOT NULL DEFAULT 1,
			used_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			records bigint(20) unsigned NOT NULL DEFAULT 0,
			remaining int(10) unsigned NOT NULL DEFAULT 0,
			expires bigint(20) unsigned NOT NULL DEFAULT 0,
			target_hash char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id)
		) $collate;"
		);
		$this->db->query( $this->db->prepare( 'INSERT IGNORE INTO %i (id) VALUES (1)', $c ) );
		update_option( 'request_inspector_schema', REQUEST_INSPECTOR_VERSION, false );
		Audit::install();
	}

	/**
	 * Read control state.
	 *
	 * @return array
	 */
	public function state() {
		return (array) $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id=1', $this->tables['control'] ), ARRAY_A );
	}

	/**
	 * Record an exact persisted-row boundary for saved scopes.
	 *
	 * @return int
	 */
	public function latest_id() {
		return (int) $this->db->get_var( $this->db->prepare( 'SELECT COALESCE(MAX(id),0) FROM %i', $this->tables['requests'] ) );
	}

	/**
	 * Arm bounded capture.
	 *
	 * @param int    $count Root count.
	 * @param string $hash Optional targeted token hash.
	 * @return bool
	 */
	public function arm( $count, $hash = '' ) {
		return false !== $this->db->update(
			$this->tables['control'],
			array(
				'remaining'   => $count,
				'expires'     => time() + 600,
				'target_hash' => $hash,
			),
			array( 'id' => 1 )
		);
	}

	/**
	 * Claim.
	 *
	 * @param string $hash Target hash.
	 * @return bool
	 */
	public function claim( $hash = '' ) {
		return 1 === $this->db->query( $this->db->prepare( 'UPDATE %i SET remaining=remaining-1 WHERE id=1 AND remaining>0 AND expires>=%d AND target_hash=%s', $this->tables['control'], time(), $hash ) );
	}

	/**
	 * Persist a fully sanitized execution. Caller never delegates a host transaction.
	 *
	 * @param array $rows Root followed by children, each with events and bodies.
	 * @param int   $generation Snapshot generation.
	 * @return bool
	 */
	public function write( array $rows, $generation ) {
		if ( ! $rows ) {
			return false;
		}
		self::$busy = true;
		$settings   = Settings::get();
		$bytes      = strlen( wp_json_encode( $rows ) ) + count( $rows ) * 512;
		$count      = count( $rows );
		$ids        = array();
		$success    = false;
		try {
			$reserved = $this->db->query( $this->db->prepare( 'UPDATE %i SET used_bytes=used_bytes+%d, records=records+%d WHERE id=1 AND generation=%d AND used_bytes+%d<=%d AND records+%d<=%d', $this->tables['control'], $bytes, $count, $generation, $bytes, $settings['max_storage_mb'] * 1048576, $count, $settings['max_records'] ) );
			if ( 1 !== $reserved ) {
				return false;
			}
			$root_id = null;
			foreach ( $rows as $index => $row ) {
				$events = $row['events'] ?? array();
				$bodies = $row['bodies'] ?? array();
				unset( $row['events'], $row['bodies'] );
				$row['generation']       = $generation;
				$row['parent_id']        = $root_id;
				$row['reserved_bytes']   = 0 === $index ? $bytes : 0;
				$row['reserved_records'] = 0 === $index ? $count : 0;
				$row['metadata']         = wp_json_encode( $row['metadata'] );
				$row['url_hash']         = hash( 'sha256', $row['url'] );
				if ( false === $this->db->insert( $this->tables['requests'], $row ) ) {
					return false;
				}
				$id      = $this->db->insert_id;
				$ids[]   = $id;
				$root_id = $root_id ?? $id;
				foreach ( $events as $event ) {
					$event['request_id'] = $id;
					$event['metadata']   = wp_json_encode( $event['metadata'] );
					if ( false === $this->db->insert( $this->tables['events'], $event ) ) {
						return false;
					}
				}
				foreach ( $bodies as $direction => $body ) {
					if ( false === $this->db->insert(
						$this->tables['bodies'],
						array(
							'request_id' => $id,
							'direction'  => $direction,
							'metadata'   => wp_json_encode( $body ),
						)
					) ) {
						return false;
					}
				}
			}
			if ( (int) ( $this->state()['generation'] ?? 0 ) !== (int) $generation ) {
				return false;
			}
			$success = false !== $this->db->query( $this->db->prepare( 'UPDATE %i SET complete=1 WHERE trace_id=%s AND generation=%d', $this->tables['requests'], $rows[0]['trace_id'], $generation ) );
			return $success;
		} finally {
			if ( ! $success && isset( $reserved ) && 1 === $reserved ) {
				$removed = true;
				foreach ( array_reverse( $ids ) as $id ) {
					if ( ! $this->delete_row( $id ) ) {
						$removed = false;
						break;
					}
				}
				if ( $removed ) {
					$this->refund( $bytes, $count, $generation );
				}
			}
			self::$busy = false;
		}
	}

	/**
	 * Refund.
	 *
	 * @param int $bytes Bytes.
	 * @param int $count Rows.
	 * @param int $generation Generation.
	 * @return void
	 */
	private function refund( $bytes, $count, $generation ) {
		$this->db->query( $this->db->prepare( 'UPDATE %i SET used_bytes=GREATEST(0,CAST(used_bytes AS SIGNED)-%d), records=GREATEST(0,CAST(records AS SIGNED)-%d) WHERE id=1 AND generation=%d', $this->tables['control'], $bytes, $count, $generation ) );
	}

	/**
	 * Delete row.
	 *
	 * @param int $id Request ID.
	 * @return bool Whether every deletion succeeded.
	 */
	private function delete_row( $id ) {
		return false !== $this->db->delete( $this->tables['bodies'], array( 'request_id' => $id ) )
			&& false !== $this->db->delete( $this->tables['events'], array( 'request_id' => $id ) )
			&& false !== $this->db->delete( $this->tables['requests'], array( 'id' => $id ) );
	}

	/**
	 * Remove an entire trace to preserve parent integrity.
	 *
	 * @param string $trace Trace ID.
	 * @return bool Whether trace deletion completed.
	 */
	public function delete_trace( $trace ) {
		$lock = 'ri_' . substr( hash( 'sha256', $this->tables['requests'] . $trace ), 0, 40 );
		if ( '1' !== (string) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return false;
		}
		try {
			if ( false === $this->db->update( $this->tables['requests'], array( 'complete' => 0 ), array( 'trace_id' => $trace ) ) ) {
				return false;
			}
			$rows = $this->db->get_results( $this->db->prepare( 'SELECT id,reserved_bytes,reserved_records,generation FROM %i WHERE trace_id=%s ORDER BY id DESC', $this->tables['requests'], $trace ), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( ! $this->delete_row( $row['id'] ) ) {
					return false;
				}
				$this->refund( $row['reserved_bytes'], $row['reserved_records'], $row['generation'] );
			}
			return true;
		} finally {
			$this->db->get_var( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Invalidate current/in-flight captures immediately.
	 *
	 * @return int
	 */
	public function purge() {
		$this->db->query( $this->db->prepare( 'UPDATE %i SET generation=generation+1,used_bytes=0,records=0,remaining=0 WHERE id=1', $this->tables['control'] ) );
		$this->cleanup();
		return (int) ( $this->state()['generation'] ?? 0 );
	}

	/**
	 * Bounded cron worker; repeated calls converge.
	 *
	 * @return void
	 */
	public function cleanup() {
		self::$busy = true;
		try {
			$settings = Settings::get();
			$state    = $this->state();
			$before   = gmdate( 'Y-m-d H:i:s', time() - $settings['retention_days'] * DAY_IN_SECONDS );
			$stale    = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
			$traces   = $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT trace_id FROM %i WHERE generation<%d OR started_at<%s OR (complete=0 AND started_at<%s) LIMIT 50', $this->tables['requests'], $state['generation'] ?? 0, $before, $stale ) );
			foreach ( $traces as $trace ) {
				$this->delete_trace( $trace );
			}
			if ( (int) ( $state['records'] ?? 0 ) >= $settings['max_records'] * 0.9 || (int) ( $state['used_bytes'] ?? 0 ) >= $settings['max_storage_mb'] * 1048576 * 0.9 ) {
				$oldest = $this->db->get_col( $this->db->prepare( 'SELECT trace_id FROM %i WHERE parent_id IS NULL ORDER BY id ASC LIMIT 50', $this->tables['requests'] ) );
				foreach ( $oldest as $trace ) {
					$this->delete_trace( $trace );
				}
			}
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * Where.
	 *
	 * @param array $filters Validated filters.
	 * @return string
	 */
	private function where( array $filters ) {
		$state = $this->state();
		$where = $this->db->prepare( 'complete=1 AND generation=%d', $state['generation'] ?? 0 );
		foreach ( array( 'method', 'type', 'component', 'direction', 'trace_id' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= $this->db->prepare( " AND `$key`=%s", $filters[ $key ] );
			}
		}
		if ( empty( $filters['trace_id'] ) && empty( $filters['include_context'] ) ) {
			$where .= ' AND context_only=0';
		}
		if ( empty( $filters['include_samples'] ) ) {
			$where .= ' AND is_sample=0';
		}
		foreach ( array( 'search', 'url' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= $this->db->prepare( ' AND url LIKE %s', '%' . $this->db->esc_like( $filters[ $key ] ) . '%' );
			}
		}
		foreach ( array(
			'status_min'   => 'status>=',
			'status_max'   => 'status<=',
			'duration_min' => 'duration_us>=',
			'duration_max' => 'duration_us<=',
			'id_min'       => 'id>=',
			'id_max'       => 'id<=',
		) as $key => $expression ) {
			if ( isset( $filters[ $key ] ) && '' !== $filters[ $key ] ) {
				$value  = (int) $filters[ $key ];
				$where .= $this->db->prepare( " AND $expression%d", str_starts_with( $key, 'duration' ) ? $value * 1000 : $value );
			}
		}
		foreach ( array(
			'after'  => '>=',
			'before' => '<=',
		) as $key => $op ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= $this->db->prepare( " AND started_at $op %s", $filters[ $key ] );
			}
		}
		if ( ! empty( $filters['error_only'] ) ) {
			$where .= ' AND has_error=1';
		}
		return $where;
	}

	/**
	 * Decode.
	 *
	 * @param array $row SQL row.
	 * @return array
	 */
	private function decode( array $row ) {
		$metadata        = json_decode( $row['metadata'] ?? '{}', true );
		$row['metadata'] = is_array( $metadata ) ? $metadata : array();
		$row['id']       = (string) $row['id'];
		if ( isset( $row['duration_us'] ) ) {
			$row['duration_ms'] = (int) $row['duration_us'] / 1000;
		}
		return $row;
	}

	/**
	 * Listing.
	 *
	 * @param array $filters Validated filters.
	 * @return array
	 */
	public function listing( array $filters ) {
		$where = $this->where( $filters );
		$size  = min( 100, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sort  = ( $filters['sort'] ?? '' ) === 'duration' ? 'duration_us DESC,id DESC' : 'started_at DESC,id DESC';
		$sql   = $this->db->prepare( "SELECT * FROM %i WHERE $where ORDER BY $sort LIMIT %d OFFSET %d", $this->tables['requests'], $size, ( $page - 1 ) * $size );
		$items = $this->db->get_results( $sql, ARRAY_A );
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i WHERE $where", $this->tables['requests'] ) );
		return array(
			'items'    => array_map( array( $this, 'decode' ), $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $size,
		);
	}

	/**
	 * Stats.
	 *
	 * @param array $filters Validated filters.
	 * @return array
	 */
	public function stats( array $filters ) {
		$where = $this->where( $filters );
		$slow  = Settings::get()['slow_ms'] * 1000;
		return (array) $this->db->get_row( $this->db->prepare( "SELECT COUNT(*) total,COALESCE(SUM(duration_us>=%d),0) slow,COALESCE(SUM(has_error),0) failed,COALESCE(SUM(direction='outgoing' AND has_error=1),0) external_failed FROM %i WHERE $where", $slow, $this->tables['requests'] ), ARRAY_A );
	}

	/**
	 * Request.
	 *
	 * @param int $id Request ID.
	 * @return array|null
	 */
	public function request( $id ) {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id=%d AND complete=1 AND generation=%d', $this->tables['requests'], $id, $this->state()['generation'] ?? 0 ), ARRAY_A );
		return $row ? $this->decode( $row ) : null;
	}

	/**
	 * Bodies.
	 *
	 * @param int $id Request ID.
	 * @return array
	 */
	public function bodies( $id ) {
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT direction,metadata FROM %i WHERE request_id=%d', $this->tables['bodies'], $id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['direction'] ] = json_decode( $row['metadata'], true );
		}
		return $out;
	}

	/**
	 * Events.
	 *
	 * @param array $filters Event filters.
	 * @return array
	 */
	public function events( array $filters ) {
		$where = $this->db->prepare( 'r.complete=1 AND r.generation=%d', $this->state()['generation'] ?? 0 );
		if ( empty( $filters['include_samples'] ) ) {
			$where .= ' AND r.is_sample=0';
		}
		if ( ! empty( $filters['severity'] ) ) {
			$where .= 'not_deprecated' === $filters['severity']
				? " AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.severity'))<>'deprecated'"
				: $this->db->prepare( " AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.severity'))=%s", $filters['severity'] );
		}
		if ( ! empty( $filters['slow_only'] ) ) {
			$where .= $this->db->prepare( ' AND e.duration_us>=%d', Settings::get()['slow_query_ms'] * 1000 );
		}
		if ( ! empty( $filters['duplicates_only'] ) ) {
			$where .= " AND JSON_EXTRACT(e.metadata,'$.duplicate_count')>1";
		}
		foreach ( array(
			'after'  => '>=',
			'before' => '<=',
		) as $key => $operator ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= $this->db->prepare( " AND r.started_at $operator %s", $filters[ $key ] );
			}
		}
		foreach ( array( 'request_id', 'event_type', 'component' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where .= $this->db->prepare( " AND e.$key=%s", $filters[ $key ] );
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$where .= $this->db->prepare( ' AND e.name LIKE %s', '%' . $this->db->esc_like( $filters['search'] ) . '%' );
		}
		$size  = min( 100, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql   = $this->db->prepare( "SELECT e.* FROM %i e INNER JOIN %i r ON e.request_id=r.id WHERE $where ORDER BY e.id ASC LIMIT %d OFFSET %d", $this->tables['events'], $this->tables['requests'], $size, ( $page - 1 ) * $size );
		$items = $this->db->get_results( $sql, ARRAY_A );
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM %i e INNER JOIN %i r ON e.request_id=r.id WHERE $where", $this->tables['events'], $this->tables['requests'] ) );
		return array(
			'items'    => array_map( array( $this, 'decode' ), $items ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $size,
		);
	}
}
