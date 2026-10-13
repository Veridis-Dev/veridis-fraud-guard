<?php
declare(strict_types=1);

/**
 * Standalone CSV import benchmark.
 *
 * Safe default (no external database):
 *   php tests/benchmark/csv-import-benchmark.php --storage=memory --repeat=3
 *
 * Optional disposable MySQL storage:
 *   VFG_BENCH_MYSQL_SOCKET=/path/to/mysql.sock \
 *   VFG_BENCH_MYSQL_USER=root VFG_BENCH_MYSQL_PASSWORD=... \
 *   VFG_BENCH_MYSQL_DATABASE=vfg_benchmark_csv_import \
 *   php tests/benchmark/csv-import-benchmark.php --storage=mysql --repeat=3
 *
 * MySQL mode refuses every database name that does not begin with
 * "vfg_benchmark_". Each child run creates and drops its own suffixed database.
 * Dataset sizes above the public importer limit are split into consecutive
 * production-valid requests and reported with their request count.
 */

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This benchmark may run only from the command line.\n" );
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

$GLOBALS['vfg_benchmark_options'] = array();

function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
function sanitize_textarea_field( string $value ): string {
	$value = str_replace( array( "\r\n", "\r" ), "\n", strip_tags( $value ) );
	return trim( preg_replace( '/[ \t]+/', ' ', $value ) ?? $value );
}
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( string $value ): array|false { return parse_url( $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return gmdate( 'Y-m-d H:i:s' ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['vfg_benchmark_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = false ): bool {
	unset( $autoload );
	$GLOBALS['vfg_benchmark_options'][ $option ] = $value;
	return true;
}
function delete_option( string $option ): bool { unset( $GLOBALS['vfg_benchmark_options'][ $option ] ); return true; }

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

/**
 * Minimal wpdb-compatible adapter. Memory mode uses indexed arrays rather than
 * linear scans. MySQL mode is restricted to a disposable benchmark database.
 */
final class wpdb {
	public string $prefix = 'vfg_bench_';
	public string $last_error = '';
	public int $insert_id = 0;
	public int $query_count = 0;
	/** @var array{exists: int, blocklist_insert: int, row_fetch: int, activity_insert: int} */
	public array $query_types = array( 'exists' => 0, 'blocklist_insert' => 0, 'row_fetch' => 0, 'activity_insert' => 0 );

	/** @var array<int, array<string, mixed>> */
	private array $blocklist_by_id = array();
	/** @var array<string, int> */
	private array $blocklist_lookup = array();
	private int $next_id = 1;
	private int $activity_count = 0;
	private ?mysqli $mysqli = null;
	private ?string $database_name = null;

	public function __construct( private string $storage, string $run_suffix = '' ) {
		if ( 'mysql' === $storage ) {
			$this->connect_mysql( $run_suffix );
		} elseif ( 'memory' !== $storage ) {
			throw new InvalidArgumentException( 'Storage must be memory or mysql.' );
		}
	}

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		return preg_replace_callback(
			'/%[dis]/',
			function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position++ ];
				if ( '%d' === $match[0] ) return (string) (int) $value;
				if ( '%i' === $match[0] ) return '`' . str_replace( '`', '``', (string) $value ) . '`';
				$escaped = null !== $this->mysqli
					? $this->mysqli->real_escape_string( (string) $value )
					: addslashes( (string) $value );
				return "'{$escaped}'";
			},
			$query
		) ?? $query;
	}

	public function get_var( string $query ): mixed {
		$this->count_query( 'exists' );
		if ( null !== $this->mysqli ) return $this->mysql_scalar( $query );

		if ( preg_match( "/type = '([^']+)' AND normalized_value = '([^']+)'/", $query, $match ) ) {
			return $this->blocklist_lookup[ $this->key( stripslashes( $match[1] ), stripslashes( $match[2] ) ) ] ?? null;
		}
		return null;
	}

	public function get_row( string $query ): ?object {
		$this->count_query( 'row_fetch' );
		if ( null !== $this->mysqli ) {
			$result = $this->mysql_query( $query );
			$row    = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
			if ( $result instanceof mysqli_result ) $result->free();
			return is_array( $row ) ? (object) $row : null;
		}

		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			$row = $this->blocklist_by_id[ (int) $match[1] ] ?? null;
			return is_array( $row ) ? (object) $row : null;
		}
		return null;
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data, array $formats = array() ): int|false {
		unset( $formats );
		$is_activity = $this->prefix . 'veridis_fraud_guard_activity' === $table;
		$this->count_query( $is_activity ? 'activity_insert' : 'blocklist_insert' );

		if ( null !== $this->mysqli ) return $this->mysql_insert( $table, $data );
		if ( $is_activity ) {
			++$this->activity_count;
			return 1;
		}

		$key = $this->key( (string) $data['type'], (string) $data['normalized_value'] );
		if ( isset( $this->blocklist_lookup[ $key ] ) ) return false;
		$id                         = $this->next_id++;
		$this->insert_id            = $id;
		$data['id']                 = $id;
		$this->blocklist_by_id[$id] = $data;
		$this->blocklist_lookup[$key] = $id;
		return 1;
	}

	public function seed( string $type, string $value ): void {
		$row = array(
			'type'             => $type,
			'value'            => $value,
			'normalized_value' => $value,
			'note'             => 'Existing benchmark entry',
			'created_at'       => '2026-01-01 00:00:00',
			'updated_at'       => '2026-01-01 00:00:00',
		);
		if ( null !== $this->mysqli ) {
			$result = $this->mysql_insert( $this->prefix . 'veridis_fraud_guard_blocklist', $row );
			if ( false === $result ) throw new RuntimeException( 'Could not seed disposable benchmark storage: ' . $this->last_error );
			return;
		}

		$key = $this->key( $type, $value );
		if ( isset( $this->blocklist_lookup[ $key ] ) ) return;
		$id                         = $this->next_id++;
		$row['id']                  = $id;
		$this->blocklist_by_id[$id] = $row;
		$this->blocklist_lookup[$key] = $id;
	}

	public function reset_metrics(): void {
		$this->query_count = 0;
		$this->query_types = array( 'exists' => 0, 'blocklist_insert' => 0, 'row_fetch' => 0, 'activity_insert' => 0 );
	}

	public function destroy(): void {
		if ( null === $this->mysqli || null === $this->database_name ) return;
		$database = $this->database_name;
		$this->mysqli->select_db( 'information_schema' );
		if ( false === $this->mysqli->query( "DROP DATABASE `{$database}`" ) ) {
			throw new RuntimeException( 'Could not remove disposable benchmark database: ' . $this->mysqli->error );
		}
		$this->database_name = null;
	}

	private function connect_mysql( string $run_suffix ): void {
		if ( ! extension_loaded( 'mysqli' ) ) throw new RuntimeException( 'MySQL mode requires the mysqli extension.' );
		$base = (string) getenv( 'VFG_BENCH_MYSQL_DATABASE' );
		if ( 1 !== preg_match( '/^vfg_benchmark_[a-z0-9_]+$/', $base ) ) {
			throw new RuntimeException( 'VFG_BENCH_MYSQL_DATABASE must begin with vfg_benchmark_ and contain only lowercase letters, digits, and underscores.' );
		}
		$suffix = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $run_suffix ) ) ?? '';
		$name   = substr( $base . '_' . trim( $suffix, '_' ), 0, 63 );
		if ( $name === $base || ! str_starts_with( $name, 'vfg_benchmark_' ) ) throw new RuntimeException( 'A unique disposable benchmark database name could not be constructed.' );

		mysqli_report( MYSQLI_REPORT_OFF );
		$host     = (string) ( getenv( 'VFG_BENCH_MYSQL_HOST' ) ?: 'localhost' );
		$user     = (string) getenv( 'VFG_BENCH_MYSQL_USER' );
		$password = (string) getenv( 'VFG_BENCH_MYSQL_PASSWORD' );
		$port     = (int) ( getenv( 'VFG_BENCH_MYSQL_PORT' ) ?: 0 );
		$socket   = (string) getenv( 'VFG_BENCH_MYSQL_SOCKET' );
		$mysqli   = mysqli_init();
		if ( false === $mysqli || ! $mysqli->real_connect( $host, $user, $password, null, $port, '' !== $socket ? $socket : null ) ) {
			throw new RuntimeException( 'Could not connect to the explicitly configured benchmark MySQL server.' );
		}
		$this->mysqli = $mysqli;
		$this->database_name = $name;
		if ( false === $mysqli->query( "CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
			throw new RuntimeException( 'Could not create disposable benchmark database: ' . $mysqli->error );
		}
		try {
			$mysqli->select_db( $name );
			$this->create_schema();
		} catch ( Throwable $error ) {
			$mysqli->select_db( 'information_schema' );
			$mysqli->query( "DROP DATABASE `{$name}`" );
			$this->database_name = null;
			throw $error;
		}
	}

	private function create_schema(): void {
		$blocklist = $this->prefix . 'veridis_fraud_guard_blocklist';
		$activity  = $this->prefix . 'veridis_fraud_guard_activity';
		$queries   = array(
			"CREATE TABLE `{$blocklist}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type VARCHAR(20) NOT NULL,
				value VARCHAR(255) NOT NULL,
				normalized_value VARCHAR(191) NOT NULL,
				note TEXT NOT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY (id), UNIQUE KEY type_normalized (type, normalized_value)
			) ENGINE=InnoDB",
			"CREATE TABLE `{$activity}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_type VARCHAR(20) NOT NULL,
				outcome VARCHAR(20) NOT NULL,
				source VARCHAR(30) NOT NULL,
				order_id BIGINT UNSIGNED NULL,
				matched_types VARCHAR(255) NOT NULL,
				matched_identifiers LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY (id)
			) ENGINE=InnoDB",
		);
		foreach ( $queries as $query ) {
			if ( false === $this->mysqli?->query( $query ) ) throw new RuntimeException( 'Could not create disposable benchmark schema.' );
		}
	}

	/** @param array<string, mixed> $data */
	private function mysql_insert( string $table, array $data ): int|false {
		$allowed = array( $this->prefix . 'veridis_fraud_guard_blocklist', $this->prefix . 'veridis_fraud_guard_activity' );
		if ( ! in_array( $table, $allowed, true ) ) throw new RuntimeException( 'Benchmark adapter rejected an unexpected table.' );
		$columns = array_map( static fn( string $column ): string => "`{$column}`", array_keys( $data ) );
		$values  = array_map(
			fn( mixed $value ): string => null === $value ? 'NULL' : "'" . $this->mysqli?->real_escape_string( (string) $value ) . "'",
			array_values( $data )
		);
		$result = $this->mysqli?->query( "INSERT INTO `{$table}` (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' );
		if ( true !== $result ) {
			$this->last_error = (string) $this->mysqli?->error;
			return false;
		}
		$this->last_error = '';
		$this->insert_id  = (int) $this->mysqli?->insert_id;
		return 1;
	}

	private function mysql_scalar( string $query ): mixed {
		$result = $this->mysql_query( $query );
		$row    = $result instanceof mysqli_result ? $result->fetch_row() : null;
		if ( $result instanceof mysqli_result ) $result->free();
		return is_array( $row ) ? $row[0] : null;
	}

	private function mysql_query( string $query ): mysqli_result|bool {
		$result = $this->mysqli?->query( $query ) ?? false;
		$this->last_error = false === $result ? (string) $this->mysqli?->error : '';
		return $result;
	}

	private function count_query( string $type ): void {
		++$this->query_count;
		++$this->query_types[$type];
	}

	private function key( string $type, string $normalized ): string { return $type . "\0" . $normalized; }
}

require_once ABSPATH . 'src/PHP/Blocklist/BlocklistEntry.php';
require_once ABSPATH . 'src/PHP/Blocklist/BlocklistNormalizer.php';
require_once ABSPATH . 'src/PHP/Blocklist/BlocklistRepository.php';
require_once ABSPATH . 'src/PHP/Activity/ActivityRepository.php';
require_once ABSPATH . 'src/PHP/Activity/BlocklistActivityRecorder.php';
require_once ABSPATH . 'src/PHP/Blocklist/BlocklistService.php';
require_once ABSPATH . 'src/PHP/CSV/SpreadsheetFormulaProtector.php';
require_once ABSPATH . 'src/PHP/CSV/BlocklistCsvImporter.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\CSV\BlocklistCsvImporter;

/** @return array{0: string, 1: string} */
function vfg_benchmark_identifier( int $index ): array {
	return match ( $index % 4 ) {
		0       => array( 'email', "user-{$index}@example.test" ),
		1       => array( 'phone', '+407' . str_pad( (string) $index, 7, '0', STR_PAD_LEFT ) ),
		2       => array( 'ip', '198.51.' . intdiv( $index, 250 ) . '.' . ( ( $index % 250 ) + 1 ) ),
		default => array( 'domain', "bench-{$index}.example" ),
	};
}

/** @return array<string, mixed> */
function vfg_benchmark_run( string $storage, string $scenario, int $rows, string $suffix ): array {
	$database = new wpdb( $storage, $suffix );
	$paths    = array();
	$handle   = null;

	try {
		for ( $start = 0; $start < $rows; $start += BlocklistCsvImporter::MAX_ROWS ) {
			$path = tempnam( sys_get_temp_dir(), 'vfg-csv-benchmark-' );
			if ( false === $path ) throw new RuntimeException( 'Could not create the isolated benchmark CSV.' );
			$paths[] = $path;
			$handle  = fopen( $path, 'wb' );
			if ( false === $handle ) throw new RuntimeException( 'Could not open the isolated benchmark CSV.' );
			fputcsv( $handle, array( 'type', 'value', 'note' ), ',', '"', '' );
			$end = min( $rows, $start + BlocklistCsvImporter::MAX_ROWS );
			for ( $index = $start; $index < $end; ++$index ) {
				[ $type, $value ] = vfg_benchmark_identifier( $index );
				$is_duplicate = 'duplicates' === $scenario || ( 'mixed' === $scenario && 1 === $index % 4 );
				$is_invalid   = 'mixed' === $scenario && 0 === $index % 4;
				if ( $is_duplicate ) $database->seed( $type, $value );
				fputcsv(
					$handle,
					array( $is_invalid ? 'email' : $type, $is_invalid ? "invalid-{$index}" : $value, "Benchmark row {$index}" ),
					',',
					'"',
					''
				);
			}
			fclose( $handle );
			$handle = null;
		}

		$repository = new BlocklistRepository( $database );
		$activity   = new BlocklistActivityRecorder( new ActivityRepository( $database ) );
		$service    = new BlocklistService( $repository, new BlocklistNormalizer(), $activity );
		$importer   = new BlocklistCsvImporter( $service );
		$database->reset_metrics();
		$memory_start = memory_get_usage( true );
		$started      = hrtime( true );
		$result       = array( 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'storage_failures' => 0 );
		foreach ( $paths as $path ) {
			$chunk = $importer->import( $path, (int) filesize( $path ) );
			if ( is_wp_error( $chunk ) ) throw new RuntimeException( 'Import failed: ' . $chunk->get_error_code() );
			foreach ( array_keys( $result ) as $total ) $result[$total] += (int) $chunk[$total];
		}
		$seconds      = ( hrtime( true ) - $started ) / 1_000_000_000;

		return array(
			'storage'                => $storage,
			'scenario'               => $scenario,
			'rows'                   => $rows,
			'requests'               => count( $paths ),
			'rows_per_request'       => BlocklistCsvImporter::MAX_ROWS,
			'seconds'                => $seconds,
			'milliseconds_per_row'   => ( $seconds * 1000 ) / $rows,
			'memory_start_bytes'     => $memory_start,
			'peak_memory_bytes'      => memory_get_peak_usage( true ),
			'incremental_peak_bytes' => max( 0, memory_get_peak_usage( true ) - $memory_start ),
			'queries'                => $database->query_count,
			'queries_per_row'        => $database->query_count / $rows,
			'query_types'            => $database->query_types,
			'import_result'          => array_intersect_key( $result, array_flip( array( 'processed', 'imported', 'duplicates', 'invalid', 'storage_failures' ) ) ),
			'php'                    => PHP_VERSION,
			'memory_limit'           => ini_get( 'memory_limit' ),
			'max_execution_time'     => ini_get( 'max_execution_time' ),
		);
	} finally {
		if ( is_resource( $handle ) ) fclose( $handle );
		foreach ( $paths as $path ) if ( is_file( $path ) ) unlink( $path );
		$database->destroy();
	}
}

/** @return array<string, string> */
function vfg_benchmark_arguments(): array {
	$options = array();
	foreach ( array_slice( $_SERVER['argv'], 1 ) as $argument ) {
		if ( preg_match( '/^--([a-z-]+)=(.*)$/', $argument, $match ) ) $options[$match[1]] = $match[2];
		elseif ( '--child' === $argument ) $options['child'] = 'yes';
	}
	return $options;
}

/** @param float[] $values */
function vfg_benchmark_median( array $values ): float {
	sort( $values, SORT_NUMERIC );
	$count = count( $values );
	$middle = intdiv( $count, 2 );
	return 1 === $count % 2 ? $values[$middle] : ( $values[$middle - 1] + $values[$middle] ) / 2;
}

$arguments = vfg_benchmark_arguments();
$storage   = $arguments['storage'] ?? 'memory';
if ( isset( $arguments['child'] ) ) {
	$scenario = $arguments['scenario'] ?? '';
	$rows     = (int) ( $arguments['rows'] ?? 0 );
	if ( ! in_array( $scenario, array( 'new', 'duplicates', 'mixed' ), true ) || ! in_array( $rows, array( 100, 1000, 5000 ), true ) ) {
		throw new InvalidArgumentException( 'Invalid child benchmark scenario.' );
	}
	echo json_encode( vfg_benchmark_run( $storage, $scenario, $rows, $arguments['suffix'] ?? 'run' ), JSON_THROW_ON_ERROR ), "\n";
	exit( 0 );
}

$repeat = min( 10, max( 1, (int) ( $arguments['repeat'] ?? 3 ) ) );
$output = array(
	'environment' => array(
		'php'                => PHP_VERSION,
		'storage'            => $storage,
		'repeats'            => $repeat,
		'memory_limit'       => ini_get( 'memory_limit' ),
		'max_execution_time' => ini_get( 'max_execution_time' ),
	),
	'results' => array(),
);

foreach ( array( 100, 1000, 5000 ) as $rows ) {
	foreach ( array( 'new', 'duplicates', 'mixed' ) as $scenario ) {
		$runs = array();
		for ( $run = 1; $run <= $repeat; ++$run ) {
			$suffix  = "{$scenario}_{$rows}_{$run}_" . getmypid();
			$command = array(
				PHP_BINARY,
				__FILE__,
				'--child',
				"--storage={$storage}",
				"--scenario={$scenario}",
				"--rows={$rows}",
				"--suffix={$suffix}",
			);
			$descriptors = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
			$process = proc_open( $command, $descriptors, $pipes );
			if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not start isolated benchmark process.' );
			fclose( $pipes[0] );
			$stdout = stream_get_contents( $pipes[1] );
			$stderr = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$status = proc_close( $process );
			if ( 0 !== $status || ! is_string( $stdout ) ) {
				$details = '' !== trim( (string) $stderr ) ? $stderr : (string) $stdout;
				throw new RuntimeException( "Benchmark child failed: {$details}" );
			}
			$runs[] = json_decode( trim( $stdout ), true, 512, JSON_THROW_ON_ERROR );
		}

		$representative = $runs[0];
		$times          = array_map( static fn( array $run ): float => (float) $run['seconds'], $runs );
		$peaks          = array_map( static fn( array $run ): float => (float) $run['peak_memory_bytes'], $runs );
		$increments     = array_map( static fn( array $run ): float => (float) $run['incremental_peak_bytes'], $runs );
		$median_time    = vfg_benchmark_median( $times );
		$output['results'][] = array(
			'scenario'               => $scenario,
			'rows'                   => $rows,
			'requests'               => $representative['requests'],
			'rows_per_request'       => $representative['rows_per_request'],
			'median_seconds'         => $median_time,
			'milliseconds_per_row'   => ( $median_time * 1000 ) / $rows,
			'median_peak_bytes'      => (int) vfg_benchmark_median( $peaks ),
			'median_increment_bytes' => (int) vfg_benchmark_median( $increments ),
			'queries'                => $representative['queries'],
			'queries_per_row'        => $representative['queries_per_row'],
			'query_types'            => $representative['query_types'],
			'import_result'          => $representative['import_result'],
			'runs_seconds'           => $times,
		);
	}
}

echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), "\n";
