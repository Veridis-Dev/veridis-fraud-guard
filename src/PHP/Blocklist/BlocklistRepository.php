<?php
namespace Veridis\FraudGuard\Blocklist;

defined( 'ABSPATH' ) || exit;

final class BlocklistRepository {
	public const STORAGE_HEALTH_OPTION = 'veridis_fraud_guard_blocklist_storage_health';
	public const STORAGE_HEALTHY       = 'healthy';
	public const STORAGE_UNAVAILABLE   = 'unavailable';

	private \wpdb $wpdb;
	private string $table;
	private bool $storage_unavailable = false;

	public function __construct( ?\wpdb $database = null ) {
		global $wpdb;
		$this->wpdb  = $database ?? $wpdb;
		$this->table = $this->wpdb->prefix . 'veridis_fraud_guard_blocklist';
	}

	public function find( int $id ): ?BlocklistEntry {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id )
		);

		return $row ? BlocklistEntry::from_row( $row ) : null;
	}

	public function find_by_normalized( string $type, string $normalized ): ?BlocklistEntry {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE type = %s AND normalized_value = %s LIMIT 1',
				$this->table,
				$type,
				$normalized
			)
		);

		return $row ? BlocklistEntry::from_row( $row ) : null;
	}

	/**
	 * @param array<string, string> $normalized_by_type
	 * @return string[]|\WP_Error
	 */
	public function matching_types( array $normalized_by_type ): array|\WP_Error {
		$wpdb = $this->wpdb;
		if ( ! $normalized_by_type ) {
			return self::STORAGE_UNAVAILABLE === $this->storage_health()['status']
				? $this->storage_error()
				: array();
		}

		$clauses = array();
		$args    = array( $this->table );
		foreach ( $normalized_by_type as $type => $normalized ) {
			$clauses[] = '(type = %s AND normalized_value = %s)';
			$args[]    = $type;
			$args[]    = $normalized;
		}

		$sql = 'SELECT type FROM %i WHERE ' . implode( ' OR ', $clauses );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query contains only fixed clauses and placeholders; every identifier and value is prepared here.
			$types = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );
		} catch ( \Throwable ) {
			$this->mark_storage_unavailable();
			return $this->storage_error();
		}
		if ( $this->query_failed() ) {
			$this->mark_storage_unavailable();
			return $this->storage_error();
		}

		$this->mark_storage_healthy();

		return array_values( array_unique( array_map( 'strval', $types ?: array() ) ) );
	}

	/** @return array{items: BlocklistEntry[], total: int} */
	public function list( string $search, string $type, int $page, int $per_page ): array {
		$wpdb  = $this->wpdb;
		$where = array();
		$args  = array( $this->table );

		if ( '' !== $type ) {
			$where[] = 'type = %s';
			$args[]  = $type;
		}

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(value LIKE %s OR normalized_value LIKE %s OR note LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		$count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where_sql, ...$args );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above from fixed clauses and placeholders.
		$total      = (int) $wpdb->get_var( $count_sql );
		$offset     = ( $page - 1 ) * $per_page;
		$list_sql   = 'SELECT * FROM %i' . $where_sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d';
		$list_args  = array_merge( $args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $list_sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		$rows       = $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_args ) );
		$entries    = array_map( array( BlocklistEntry::class, 'from_row' ), $rows ?: array() );

		return array( 'items' => $entries, 'total' => $total );
	}

	/** @param array{type: string, normalized_value: string, id: int}|null $after
	 *  @return BlocklistEntry[]|\WP_Error
	 */
	public function export_batch( int $limit, ?array $after = null ): array|\WP_Error {
		$wpdb  = $this->wpdb;
		$limit = min( 1000, max( 1, $limit ) );
		$sql   = 'SELECT * FROM %i';
		$args  = array( $this->table );
		if ( null !== $after ) {
			$sql   .= ' WHERE type > %s OR (type = %s AND normalized_value > %s) OR (type = %s AND normalized_value = %s AND id > %d)';
			$args[] = $after['type'];
			$args[] = $after['type'];
			$args[] = $after['normalized_value'];
			$args[] = $after['type'];
			$args[] = $after['normalized_value'];
			$args[] = $after['id'];
		}
		$sql   .= ' ORDER BY type ASC, normalized_value ASC, id ASC LIMIT %d';
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
		if ( null === $rows && '' !== (string) $this->wpdb->last_error ) {
			return $this->database_error();
		}

		return array_map( array( BlocklistEntry::class, 'from_row' ), $rows ?: array() );
	}

	/** @return array{total: int, email: int, domain: int, phone: int, ip: int} */
	public function summary(): array {
		$wpdb    = $this->wpdb;
		$summary = array( 'total' => 0, 'email' => 0, 'domain' => 0, 'phone' => 0, 'ip' => 0 );
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT type, COUNT(*) AS entry_count FROM %i GROUP BY type', $this->table )
			);
		} catch ( \Throwable ) {
			$this->mark_storage_unavailable();
			return $summary;
		}
		if ( $this->query_failed() ) {
			$this->mark_storage_unavailable();
			return $summary;
		}

		$this->mark_storage_healthy();

		foreach ( $rows ?: array() as $row ) {
			$type = (string) $row->type;
			if ( array_key_exists( $type, $summary ) ) {
				$summary[ $type ] = (int) $row->entry_count;
				$summary['total'] += (int) $row->entry_count;
			}
		}

		return $summary;
	}

	/** @return array{status: string} */
	public function storage_health(): array {
		return array(
			'status' => $this->storage_unavailable || self::STORAGE_UNAVAILABLE === get_option( self::STORAGE_HEALTH_OPTION, '' )
				? self::STORAGE_UNAVAILABLE
				: self::STORAGE_HEALTHY,
		);
	}

	public function exists( string $type, string $normalized, int $exclude_id = 0 ): bool {
		$wpdb = $this->wpdb;
		$sql  = 'SELECT id FROM %i WHERE type = %s AND normalized_value = %s';
		$args = array( $this->table, $type, $normalized );
		if ( $exclude_id > 0 ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only fixed clauses and placeholders; every identifier and value is prepared here.
		return null !== $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
	}

	/** @param array{type: string, value: string, normalized_value: string, note: string} $data */
	public function insert( array $data ): BlocklistEntry|\WP_Error {
		$now    = current_time( 'mysql', true );
		$result = $this->wpdb->insert(
			$this->table,
			array_merge( $data, array( 'created_at' => $now, 'updated_at' => $now ) ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return $this->database_error();
		}

		$entry = $this->find( (int) $this->wpdb->insert_id );
		return $entry ?? $this->database_error();
	}

	/** @param array{type: string, value: string, normalized_value: string, note: string} $data */
	public function update( int $id, array $data ): BlocklistEntry|\WP_Error {
		$data['updated_at'] = current_time( 'mysql', true );
		$result             = $this->wpdb->update( $this->table, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );

		if ( false === $result ) {
			return $this->database_error();
		}

		$entry = $this->find( $id );
		return $entry ?? $this->database_error();
	}

	public function delete( int $id ): bool|\WP_Error {
		$result = $this->wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
		return false === $result ? $this->database_error() : $result > 0;
	}

	private function database_error(): \WP_Error {
		return new \WP_Error(
			'vfg_blocklist_database_error',
			__( 'The blocklist could not be saved. Please try again.', 'veridis-fraud-guard' ),
			array( 'status' => 500 )
		);
	}

	private function query_failed(): bool {
		return '' !== (string) ( $this->wpdb->last_error ?? '' );
	}

	private function mark_storage_unavailable(): void {
		$this->storage_unavailable = true;
		if ( self::STORAGE_UNAVAILABLE === get_option( self::STORAGE_HEALTH_OPTION, '' ) ) {
			return;
		}

		update_option( self::STORAGE_HEALTH_OPTION, self::STORAGE_UNAVAILABLE, false );
	}

	private function mark_storage_healthy(): void {
		$this->storage_unavailable = false;
		if ( self::STORAGE_UNAVAILABLE !== get_option( self::STORAGE_HEALTH_OPTION, '' ) ) {
			return;
		}

		delete_option( self::STORAGE_HEALTH_OPTION );
	}

	private function storage_error(): \WP_Error {
		return new \WP_Error(
			'vfg_blocklist_storage_unavailable',
			__( 'Blocklist storage is temporarily unavailable.', 'veridis-fraud-guard' ),
			array( 'status' => 500 )
		);
	}
}
