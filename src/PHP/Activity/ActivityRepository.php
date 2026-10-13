<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

final class ActivityRepository {
	private \wpdb $wpdb;
	private string $table;

	public function __construct( ?\wpdb $database = null ) {
		global $wpdb;
		$this->wpdb  = $database ?? $wpdb;
		$this->table = $this->wpdb->prefix . 'veridis_fraud_guard_activity';
	}

	/**
	 * @param array{event_type: string, outcome: string, source: string, order_id: int|null, matched_types: string, matched_identifiers?: string|null} $event
	 */
	public function insert( array $event ): bool {
		try {
			$result = $this->wpdb->insert(
				$this->table,
				array(
					'event_type'          => $event['event_type'],
					'outcome'             => $event['outcome'],
					'source'              => $event['source'],
					'order_id'            => $event['order_id'],
					'matched_types'        => $event['matched_types'],
					'matched_identifiers' => $event['matched_identifiers'] ?? null,
					'created_at'          => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		} catch ( \Throwable ) {
			return false;
		}

		return false !== $result;
	}

	/**
	 * Atomically insert or count an identical rejected checkout attempt.
	 */
	public function record_rejected_attempt( string $source, string $matched_types, ?string $matched_identifiers, string $deduplication_key ): bool {
		$wpdb = $this->wpdb;
		try {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i
						(event_type, outcome, source, order_id, matched_types, matched_identifiers, occurrence_count, deduplication_key, created_at)
					VALUES ('block', 'rejected', %s, NULL, %s, %s, 1, %s, %s)
					ON DUPLICATE KEY UPDATE
						occurrence_count = occurrence_count + IF(occurrence_count < 18446744073709551615, 1, 0),
						matched_identifiers = VALUES(matched_identifiers),
						created_at = VALUES(created_at)",
					$this->table,
					$source,
					$matched_types,
					$matched_identifiers,
					$deduplication_key,
					current_time( 'mysql', true )
				)
			);
		} catch ( \Throwable ) {
			return false;
		}

		return false !== $result;
	}

	/**
	 * @param string[] $included_event_types
	 * @return array{items: object[], total: int}
	 */
	public function list( string $event_type, int $page, int $per_page, array $included_event_types = array() ): array {
		$wpdb  = $this->wpdb;
		$where = array();
		$args  = array( $this->table );
		if ( $included_event_types ) {
			$where[] = 'event_type IN (' . implode( ', ', array_fill( 0, count( $included_event_types ), '%s' ) ) . ')';
			array_push( $args, ...$included_event_types );
		}
		if ( '' !== $event_type ) {
			$where[] = 'event_type = %s';
			$args[] = $event_type;
		}
		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where_sql, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above from fixed clauses and placeholders.
		$total     = (int) $wpdb->get_var( $count_sql );
		$offset    = ( $page - 1 ) * $per_page;
		$list_sql  = 'SELECT id, event_type, outcome, source, order_id, matched_types, matched_identifiers, occurrence_count, created_at FROM %i' . $where_sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$list_args = array_merge( $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list_sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		$items     = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_args ) );

		return array( 'items' => $items ?: array(), 'total' => $total );
	}

	/**
	 * @param string[] $included_event_types
	 * Total includes every requested event type; named counts remain checkout-only.
	 *
	 * @return array{total: int, block: int, hold: int, warn: int}
	 */
	public function summary( array $included_event_types = array() ): array {
		$wpdb    = $this->wpdb;
		$summary = array( 'total' => 0, 'block' => 0, 'hold' => 0, 'warn' => 0 );
		$sql     = 'SELECT event_type, COALESCE(SUM(occurrence_count), 0) AS event_count FROM %i';
		$args    = array( $this->table );
		if ( $included_event_types ) {
			$placeholders = implode( ', ', array_fill( 0, count( $included_event_types ), '%s' ) );
			$sql         .= ' WHERE event_type IN (' . $placeholders . ')';
			array_push( $args, ...$included_event_types );
		}
		$sql  .= ' GROUP BY event_type';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only fixed clauses and generated placeholders; every identifier and value is prepared here.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

		foreach ( $rows ?: array() as $row ) {
			$type = (string) $row->event_type;
			$summary['total'] += (int) $row->event_count;
			if ( array_key_exists( $type, $summary ) && 'total' !== $type ) {
				$summary[ $type ] = (int) $row->event_count;
			}
		}

		return $summary;
	}

	/**
	 * @param string[] $included_event_types
	 * @return array{total: int, today: int, last_7_days: int}
	 */
	public function checkout_dashboard_summary(
		array $included_event_types,
		string $today_start_utc,
		string $tomorrow_start_utc,
		string $seven_day_start_utc
	): array {
		$wpdb         = $this->wpdb;
		$placeholders = implode( ', ', array_fill( 0, count( $included_event_types ), '%s' ) );
		$sql          = "SELECT
			COALESCE(SUM(occurrence_count), 0) AS total,
			COALESCE(SUM(CASE WHEN created_at >= %s AND created_at < %s THEN occurrence_count ELSE 0 END), 0) AS today,
			COALESCE(SUM(CASE WHEN created_at >= %s AND created_at < %s THEN occurrence_count ELSE 0 END), 0) AS last_7_days
			FROM %i
			WHERE event_type IN ({$placeholders})";
		$args         = array_merge(
			array( $today_start_utc, $tomorrow_start_utc, $seven_day_start_utc, $tomorrow_start_utc, $this->table ),
			$included_event_types
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only fixed SQL and generated placeholders; every identifier and value is prepared here.
		$row          = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );

		return array(
			'total'       => isset( $row->total ) ? (int) $row->total : 0,
			'today'       => isset( $row->today ) ? (int) $row->today : 0,
			'last_7_days' => isset( $row->last_7_days ) ? (int) $row->last_7_days : 0,
		);
	}

	/**
	 * @param string[] $included_event_types
	 * @return object[]
	 */
	public function recent( array $included_event_types, int $limit ): array {
		$wpdb         = $this->wpdb;
		$limit        = min( 20, max( 1, $limit ) );
		$placeholders = implode( ', ', array_fill( 0, count( $included_event_types ), '%s' ) );
		$sql          = "SELECT id, event_type, outcome, source, order_id, matched_types, matched_identifiers, occurrence_count, created_at
			FROM %i
			WHERE event_type IN ({$placeholders})
			ORDER BY created_at DESC, id DESC
			LIMIT %d";
		$args         = array_merge( array( $this->table ), $included_event_types, array( $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only fixed SQL and generated placeholders; every identifier and value is prepared here.
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

		return $rows ?: array();
	}

	public function purge_identifiers_before( string $before_utc ): int {
		$wpdb   = $this->wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i
				SET matched_identifiers = NULL,
					deduplication_key = NULL
				WHERE event_type IN ('block', 'hold', 'warn')
					AND (
						(matched_identifiers IS NOT NULL AND matched_identifiers <> '')
						OR deduplication_key IS NOT NULL
					)
					AND created_at < %s",
				$this->table,
				$before_utc
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/** @return object[] */
	public function identifier_rows_page( int $page, int $per_page = 100 ): array {
		$wpdb     = $this->wpdb;
		$page     = max( 1, $page );
		$per_page = min( 500, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, matched_identifiers
				FROM %i
				WHERE event_type IN ('block', 'hold', 'warn')
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$this->table,
				$per_page,
				$offset
			)
		);

		return $rows ?: array();
	}

	/** @return object[] */
	public function privacy_rows_page( int $page, int $per_page = 100 ): array {
		$wpdb     = $this->wpdb;
		$page     = max( 1, $page );
		$per_page = min( 500, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, event_type, outcome, source, order_id, matched_types, matched_identifiers, occurrence_count, created_at
				FROM %i
				WHERE event_type IN ('block', 'hold', 'warn')
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$this->table,
				$per_page,
				$offset
			)
		);

		return $rows ?: array();
	}

	/** @param int[] $event_ids */
	public function clear_identifiers( array $event_ids ): int {
		$wpdb     = $this->wpdb;
		$event_ids = array_values( array_unique( array_filter( array_map( 'absint', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
		// The dynamic fragment consists exclusively of one %d placeholder per validated event ID.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$result       = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET matched_identifiers = NULL, deduplication_key = NULL WHERE id IN (' . $placeholders . ')',
				$this->table,
				...$event_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return false === $result ? 0 : (int) $result;
	}
}
