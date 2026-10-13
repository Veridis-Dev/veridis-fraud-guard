<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_can_manage_woocommerce'] = false;
$GLOBALS['vfg_valid_nonce']            = 'valid-rest-nonce';
$GLOBALS['vfg_registered_route']       = array();

function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
function sanitize_textarea_field( string $value ): string {
	$value = str_replace( array( "\r\n", "\r" ), "\n", strip_tags( $value ) );
	return trim( preg_replace( '/[ \t]+/', ' ', $value ) ?? $value );
}
function sanitize_file_name( string $value ): string { return basename( preg_replace( '/[^A-Za-z0-9._-]/', '-', $value ) ?? '' ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( string $value ): array|false { return parse_url( $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-10-10 12:00:00'; }
function current_user_can( string $capability ): bool { return 'manage_woocommerce' === $capability && true === $GLOBALS['vfg_can_manage_woocommerce']; }
function wp_verify_nonce( string $nonce, string $action ): bool {
	return ( 'wp_rest' === $action && $nonce === $GLOBALS['vfg_valid_nonce'] )
		|| ( 'veridis_fraud_guard_export_blocklist' === $action && 'valid-export-nonce' === $nonce );
}
function register_rest_route( string $namespace, string $route, array $config ): void { $GLOBALS['vfg_registered_route'] = compact( 'namespace', 'route', 'config' ); }

final class WP_Error {
	public function __construct( private string $code = '', private string $message = '', private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

final class WP_REST_Server { public const CREATABLE = 'POST'; }
final class WP_REST_Request {
	/** @param array<string, string> $headers
	 *  @param array<string, mixed> $files
	 */
	public function __construct( private array $headers = array(), private array $files = array() ) {}
	public function get_header( string $name ): string { return $this->headers[ strtolower( $name ) ] ?? ''; }
	/** @return array<string, mixed> */
	public function get_file_params(): array { return $this->files; }
}
final class WP_REST_Response {
	/** @var array<string, string> */
	public array $headers = array();
	public function __construct( private mixed $data, private int $status = 200 ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

final class wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	/** @var array<int, array<string, mixed>> */
	public array $blocklist_rows = array();
	/** @var array<int, array<string, mixed>> */
	public array $activity_rows = array();
	public string $fail_value = '';

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		return preg_replace_callback(
			'/%[dis]/',
			static function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position++ ];
				return match ( $match[0] ) {
					'%d' => (string) (int) $value,
					'%i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
					default => "'" . addslashes( (string) $value ) . "'",
				};
			},
			$query
		) ?? $query;
	}

	public function get_var( string $query ): mixed {
		if ( preg_match( "/type = '([^']+)' AND normalized_value = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->blocklist_rows as $row ) {
				if ( $row['type'] === stripslashes( $match[1] ) && $row['normalized_value'] === stripslashes( $match[2] ) ) {
					return $row['id'];
				}
			}
		}
		return null;
	}

	public function get_row( string $query ): ?object {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			foreach ( $this->blocklist_rows as $row ) if ( $row['id'] === (int) $match[1] ) return (object) $row;
		}
		return null;
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		if ( ! str_contains( $query, 'ORDER BY type ASC' ) ) return array();
		$rows = $this->blocklist_rows;
		usort( $rows, static fn( array $a, array $b ): int => array( $a['type'], $a['normalized_value'], $a['id'] ) <=> array( $b['type'], $b['normalized_value'], $b['id'] ) );
		if ( preg_match( "/WHERE type > '([^']+)' OR \(type = '([^']+)' AND normalized_value > '([^']+)'\) OR \(type = '([^']+)' AND normalized_value = '([^']+)' AND id > (\d+)\)/", $query, $after ) ) {
			$key  = array( stripslashes( $after[1] ), stripslashes( $after[3] ), (int) $after[6] );
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => array( $row['type'], $row['normalized_value'], $row['id'] ) > $key ) );
		}
		preg_match( '/LIMIT (\d+)/', $query, $limit );
		$rows = array_slice( $rows, 0, isset( $limit[1] ) ? (int) $limit[1] : 500 );
		return array_map( static fn( array $row ): object => (object) $row, $rows );
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data, array $formats = array() ): int|false {
		unset( $formats );
		if ( str_contains( $table, '_activity' ) ) {
			$data['id'] = count( $this->activity_rows ) + 1;
			$this->activity_rows[] = $data;
			return 1;
		}
		if ( $data['value'] === $this->fail_value ) return false;
		foreach ( $this->blocklist_rows as $row ) {
			if ( $row['type'] === $data['type'] && $row['normalized_value'] === $data['normalized_value'] ) return false;
		}
		$this->insert_id = count( $this->blocklist_rows ) + 1;
		$data['id']      = $this->insert_id;
		$this->blocklist_rows[] = $data;
		return 1;
	}
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistEntry.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/BlocklistActivityRecorder.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistService.php';
require_once __DIR__ . '/../../src/PHP/CSV/SpreadsheetFormulaProtector.php';
require_once __DIR__ . '/../../src/PHP/CSV/BlocklistCsvImporter.php';
require_once __DIR__ . '/../../src/PHP/CSV/BlocklistCsvExporter.php';
require_once __DIR__ . '/../../src/PHP/Core/Access.php';
require_once __DIR__ . '/../../src/PHP/REST/BlocklistImportController.php';
require_once __DIR__ . '/../../src/PHP/Admin/BlocklistExportController.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Admin\BlocklistExportController;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\CSV\BlocklistCsvExporter;
use Veridis\FraudGuard\CSV\BlocklistCsvImporter;
use Veridis\FraudGuard\REST\BlocklistImportController;

function vfg_assert( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }
/** @return array{wpdb, BlocklistService, BlocklistCsvImporter, BlocklistRepository} */
function vfg_csv_stack(): array {
	$db       = new wpdb();
	$repo     = new BlocklistRepository( $db );
	$activity = new BlocklistActivityRecorder( new ActivityRepository( $db ) );
	$service  = new BlocklistService( $repo, new BlocklistNormalizer(), $activity );
	return array( $db, $service, new BlocklistCsvImporter( $service ), $repo );
}
/** @return array{string, int} */
function vfg_csv_file( string $contents ): array {
	$path = tempnam( sys_get_temp_dir(), 'vfg-csv-' );
	if ( false === $path ) throw new RuntimeException( 'Could not create test file.' );
	file_put_contents( $path, $contents );
	return array( $path, strlen( $contents ) );
}

// UTF-8, BOM, all types, quoting, multiline notes, partial validation, duplicates, and Activity privacy.
[ $db, $service, $importer, $repository ] = vfg_csv_stack();
$csv = "\xEF\xBB\xBFtype,value,note\r\n"
	. "email,Blocked@Example.com,\"Chargeback, confirmed\"\r\n"
	. "phone,\"+40 712 345 678\",\"Prima linie\nA doua linie – București\"\r\n"
	. "ip,2001:0db8:0:0:0:0:0:1,\r\n"
	. "domain,https://Example.NET/,Disposable\r\n"
	. "email,blocked@example.com,Must not overwrite\r\n"
	. "ip,192.168.1.*,Invalid wildcard\r\n";
[ $path, $size ] = vfg_csv_file( $csv );
$result = $importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $result ), 'Valid mixed CSV was rejected.' );
vfg_assert( 6 === $result['processed'] && 4 === $result['imported'] && 1 === $result['duplicates'] && 1 === $result['invalid'] && 0 === $result['storage_failures'], 'Import totals are incorrect.' );
vfg_assert( 4 === count( $db->blocklist_rows ) && 'Chargeback, confirmed' === $db->blocklist_rows[0]['note'], 'Quoted content or duplicate policy is incorrect.' );
vfg_assert( "+40712345678" === $db->blocklist_rows[1]['normalized_value'] && str_contains( $db->blocklist_rows[1]['note'], "\n" ), 'Phone or multiline UTF-8 normalization failed.' );
vfg_assert( '2001:db8::1' === $db->blocklist_rows[2]['value'] && 'example.net' === $db->blocklist_rows[3]['value'], 'IP or domain canonicalization failed.' );
vfg_assert( 4 === count( $db->activity_rows ) && ! str_contains( json_encode( $db->activity_rows ) ?: '', 'Blocked@Example.com' ) && ! str_contains( json_encode( $db->activity_rows ) ?: '', 'Chargeback' ), 'Import Activity leaked private content or missed additions.' );

// Idempotent retry does not update notes or add Activity.
[ $path, $size ] = vfg_csv_file( $csv );
$retry = $importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $retry ) && 0 === $retry['imported'] && 5 === $retry['duplicates'] && 1 === $retry['invalid'] && 4 === count( $db->activity_rows ), 'Import retry was not idempotent.' );
vfg_assert( 'Chargeback, confirmed' === $db->blocklist_rows[0]['note'], 'Duplicate import overwrote an existing note.' );

// Storage failures are distinct from invalid rows.
$db->fail_value = 'storage@example.com';
[ $path, $size ] = vfg_csv_file( "type,value\nemail,storage@example.com\nemail,not-an-email\n" );
$failed = $importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $failed ) && 1 === $failed['storage_failures'] && 1 === $failed['invalid'], 'Storage and validation failures were conflated.' );

// Header, encoding, size, and row limits fail before mutation.
foreach ( array(
	array( "email,value\na@example.com,x\n", 'vfg_csv_unknown_header' ),
	array( "type,note\nemail,x\n", 'vfg_csv_missing_header' ),
	array( "type,type,value\nemail,email,x\n", 'vfg_csv_duplicate_header' ),
	array( "type,value\nemail,\xC3\x28\n", 'vfg_csv_invalid_utf8' ),
) as [ $contents, $code ] ) {
	[ $limit_db, , $limit_importer ] = vfg_csv_stack();
	[ $path, $size ] = vfg_csv_file( $contents );
	$error = $limit_importer->import( $path, $size );
	unlink( $path );
	vfg_assert( is_wp_error( $error ) && $code === $error->get_error_code() && array() === $limit_db->blocklist_rows, 'Preflight validation failed for ' . $code . '.' );
}
[ $limit_db, , $limit_importer ] = vfg_csv_stack();
[ $path, $size ] = vfg_csv_file( "type,value\nemail,a@example.com\n" );
$error = $limit_importer->import( $path, BlocklistCsvImporter::MAX_BYTES + 1 );
unlink( $path );
vfg_assert( is_wp_error( $error ) && 'vfg_csv_too_large' === $error->get_error_code() && array() === $limit_db->blocklist_rows, 'Size limit did not fail before mutation.' );

$accepted_rows = "type,value\n\n";
for ( $index = 0; $index < BlocklistCsvImporter::MAX_ROWS; ++$index ) {
	$accepted_rows .= "email,accepted{$index}@example.com\n";
	if ( 0 === $index % 100 ) $accepted_rows .= "\n";
}
[ $accepted_db, , $accepted_importer ] = vfg_csv_stack();
[ $path, $size ] = vfg_csv_file( $accepted_rows );
$accepted = $accepted_importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $accepted ) && BlocklistCsvImporter::MAX_ROWS === $accepted['processed'] && BlocklistCsvImporter::MAX_ROWS === $accepted['imported'], 'Exactly 1,000 non-blank rows were not accepted.' );
vfg_assert( BlocklistCsvImporter::MAX_ROWS === count( $accepted_db->blocklist_rows ) && BlocklistCsvImporter::MAX_ROWS === count( $accepted_db->activity_rows ), 'Blank rows changed import or Activity totals.' );

$rejected_rows = "type,value\n\n";
for ( $index = 0; $index <= BlocklistCsvImporter::MAX_ROWS; ++$index ) {
	$rejected_rows .= "email,rejected{$index}@example.com\n";
	if ( 0 === $index % 100 ) $rejected_rows .= "\n";
}
[ $limit_db, , $limit_importer ] = vfg_csv_stack();
[ $path, $size ] = vfg_csv_file( $rejected_rows );
$error = $limit_importer->import( $path, $size );
unlink( $path );
vfg_assert( is_wp_error( $error ) && 'vfg_csv_too_many_rows' === $error->get_error_code() && array() === $limit_db->blocklist_rows && array() === $limit_db->activity_rows, '1,001 non-blank rows did not fail before mutation.' );

// Diagnostics are capped while totals remain complete.
$invalid_csv = "type,value\n" . str_repeat( "email,invalid\n", BlocklistCsvImporter::MAX_DIAGNOSTICS + 5 );
[ , , $diagnostic_importer ] = vfg_csv_stack();
[ $path, $size ] = vfg_csv_file( $invalid_csv );
$diagnostics = $diagnostic_importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $diagnostics ) && 105 === $diagnostics['invalid'] && 100 === count( $diagnostics['diagnostics'] ) && true === $diagnostics['diagnostics_limited'], 'Diagnostic cap or totals are incorrect.' );

// Formula protection is reversible for every dangerous prefix and leading apostrophes.
[ $source_db, $source_service, , $source_repository ] = vfg_csv_stack();
$formula_entries = array(
	array( 'email', 'formula@example.com', '=SUM(1,1)' ),
	array( 'phone', '+40 700 000 001', '+command' ),
	array( 'ip', '203.0.113.9', '-command' ),
	array( 'domain', 'formula.example', '@command' ),
	array( 'email', 'apostrophe@example.com', "'=legitimate" ),
);
foreach ( $formula_entries as [ $type, $value, $note ] ) {
	vfg_assert( ! is_wp_error( $source_service->create( compact( 'type', 'value', 'note' ), BlocklistActivityRecorder::SOURCE_ADMIN ) ), 'Formula fixture could not be created.' );
}
$stream = fopen( 'php://temp', 'w+b' );
vfg_assert( is_resource( $stream ), 'Could not create export stream.' );
$exported_count = ( new BlocklistCsvExporter( $source_repository ) )->export( $stream );
rewind( $stream );
$exported = stream_get_contents( $stream );
fclose( $stream );
vfg_assert( 5 === $exported_count && is_string( $exported ) && str_starts_with( $exported, "\xEF\xBB\xBFtype,value,note\r\n" ), 'Export metadata is incorrect.' );
vfg_assert( str_contains( $exported, "'+40 700 000 001" ) && str_contains( $exported, "'=SUM(1,1)" ) && str_contains( $exported, "''=legitimate" ), 'Spreadsheet formula escaping is incomplete.' );
vfg_assert( ! str_contains( $exported, 'normalized_value' ) && ! str_contains( $exported, 'created_at' ), 'Private/internal fields leaked into export.' );

[ $roundtrip_db, , $roundtrip_importer ] = vfg_csv_stack();
[ $path, $size ] = vfg_csv_file( $exported );
$roundtrip = $roundtrip_importer->import( $path, $size );
unlink( $path );
vfg_assert( ! is_wp_error( $roundtrip ) && 5 === $roundtrip['imported'], 'Export could not be re-imported.' );
$source_pairs = array_map( static fn( array $row ): array => array( $row['type'], $row['value'], $row['note'] ), $source_db->blocklist_rows );
$target_pairs = array_map( static fn( array $row ): array => array( $row['type'], $row['value'], $row['note'] ), $roundtrip_db->blocklist_rows );
sort( $source_pairs );
sort( $target_pairs );
vfg_assert( $source_pairs === $target_pairs, 'Formula-safe export/import did not preserve values and notes exactly.' );

// Export pagination emits every entry once across repository batches.
[ , $paged_service, , $paged_repository ] = vfg_csv_stack();
for ( $index = 0; $index < 501; ++$index ) {
	$entry = $paged_service->create( array( 'type' => 'email', 'value' => "paged{$index}@example.com", 'note' => '' ) );
	vfg_assert( ! is_wp_error( $entry ), 'Paged export fixture could not be created.' );
}
$stream = fopen( 'php://temp', 'w+b' );
vfg_assert( is_resource( $stream ), 'Could not create paged export stream.' );
$paged_count = ( new BlocklistCsvExporter( $paged_repository ) )->export( $stream );
rewind( $stream );
$seen = array();
$header = fgetcsv( $stream, 0, ',', '"', '' );
while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) $seen[] = $row[1];
fclose( $stream );
vfg_assert( 501 === $paged_count && 501 === count( $seen ) && 501 === count( array_unique( $seen ) ), 'Paged export omitted or duplicated entries.' );
vfg_assert( isset( $header[0] ) && "\xEF\xBB\xBFtype" === $header[0], 'Paged export header is incorrect.' );

// REST registration, capability, nonce, missing upload, and response contract.
$controller = new BlocklistImportController( $importer );
$controller->register();
vfg_assert( 'veridis-fraud-guard/v1' === $GLOBALS['vfg_registered_route']['namespace'] && '/blocklist/import' === $GLOBALS['vfg_registered_route']['route'], 'Import route is incorrect.' );
$request = new WP_REST_Request( array( 'x-wp-nonce' => 'valid-rest-nonce' ) );
$denied  = $controller->allowed( $request );
vfg_assert( is_wp_error( $denied ) && 'vfg_forbidden' === $denied->get_error_code(), 'Capability failure was not enforced.' );
$GLOBALS['vfg_can_manage_woocommerce'] = true;
$bad_nonce = $controller->allowed( new WP_REST_Request( array( 'x-wp-nonce' => 'invalid' ) ) );
vfg_assert( is_wp_error( $bad_nonce ) && 'vfg_invalid_nonce' === $bad_nonce->get_error_code(), 'Nonce failure was not enforced.' );
vfg_assert( true === $controller->allowed( $request ), 'Valid capability and nonce were rejected.' );
$missing = $controller->import( $request );
vfg_assert( is_wp_error( $missing ) && 'vfg_csv_missing_file' === $missing->get_error_code(), 'Missing upload error is incorrect.' );

$GLOBALS['vfg_can_manage_woocommerce'] = false;
$export_controller = new BlocklistExportController( new BlocklistCsvExporter( $repository ) );
$export_denied = $export_controller->allowed( 'valid-export-nonce' );
vfg_assert( is_wp_error( $export_denied ) && 'vfg_export_forbidden' === $export_denied->get_error_code(), 'Export capability failure was not enforced.' );
$GLOBALS['vfg_can_manage_woocommerce'] = true;
$export_nonce = $export_controller->allowed( 'invalid' );
vfg_assert( is_wp_error( $export_nonce ) && 'vfg_export_invalid_nonce' === $export_nonce->get_error_code(), 'Export nonce failure was not enforced.' );
vfg_assert( true === $export_controller->allowed( 'valid-export-nonce' ), 'Valid export authorization was rejected.' );

echo "Blocklist CSV harness: PASS\n";
