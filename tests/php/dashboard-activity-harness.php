<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_site_timezone'] = new DateTimeZone( 'UTC' );
$GLOBALS['vfg_orders']        = array();
$GLOBALS['vfg_options']       = array();

function get_option( string $option, mixed $default = false ): mixed {
	return array_key_exists( $option, $GLOBALS['vfg_options'] ) ? $GLOBALS['vfg_options'][ $option ] : $default;
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function apply_filters( string $hook, mixed $value ): mixed {
	unset( $hook );
	return $value;
}

function wp_timezone(): DateTimeZone {
	return $GLOBALS['vfg_site_timezone'];
}

function wc_get_order( int $order_id ): WC_Order|false {
	return $GLOBALS['vfg_orders'][ $order_id ] ?? false;
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability;
}

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function is_email( string $value ): bool {
	return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}

function wp_parse_url( string $value ): array|false {
	return parse_url( $value );
}

final class WP_Error {}

final class WP_REST_Response {
	/** @var array<string, string> */
	public array $headers = array();
	public function __construct( private mixed $data ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	public function get_data(): mixed { return $this->data; }
}

class WC_Order {
	public function __construct( private string $number, private string $url ) {}
	public function get_order_number(): string { return $this->number; }
	public function get_edit_order_url(): string { return $this->url; }
}

final class wpdb {
	public string $prefix = 'wp_';
	/** @var string[] */
	public array $queries = array();

	/**
	 * @param array<int, array<string, mixed>> $activity_rows
	 * @param array<int, array{type: string}> $blocklist_rows
	 */
	public function __construct( private array $activity_rows = array(), private array $blocklist_rows = array() ) {}

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

	public function get_row( string $query ): ?object {
		$this->queries[] = $query;
		$rows            = $this->filtered_activity_rows( $query );
		preg_match_all( "/created_at >= '([^']+)' AND created_at < '([^']+)'/", $query, $windows, PREG_SET_ORDER );
		if ( 2 !== count( $windows ) ) {
			return null;
		}

		$count_window = static fn( array $items, string $start, string $end ): int => array_sum(
			array_map(
				static fn( array $row ): int => (int) ( $row['occurrence_count'] ?? 1 ),
				array_filter( $items, static fn( array $row ): bool => $row['created_at'] >= $start && $row['created_at'] < $end )
			)
		);

		return (object) array(
			'total'       => array_sum( array_map( static fn( array $row ): int => (int) ( $row['occurrence_count'] ?? 1 ), $rows ) ),
			'today'       => $count_window( $rows, $windows[0][1], $windows[0][2] ),
			'last_7_days' => $count_window( $rows, $windows[1][1], $windows[1][2] ),
		);
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		$this->queries[] = $query;
		if ( str_contains( $query, 'veridis_fraud_guard_blocklist' ) ) {
			$counts = array();
			foreach ( $this->blocklist_rows as $row ) {
				$counts[ $row['type'] ] = ( $counts[ $row['type'] ] ?? 0 ) + 1;
			}
			return array_map(
				static fn( string $type, int $count ): object => (object) array( 'type' => $type, 'entry_count' => $count ),
				array_keys( $counts ),
				array_values( $counts )
			);
		}

		$rows = $this->filtered_activity_rows( $query );
		usort( $rows, static fn( array $left, array $right ): int => array( $right['created_at'], $right['id'] ) <=> array( $left['created_at'], $left['id'] ) );
		if ( preg_match( '/LIMIT (\d+)/', $query, $limit ) ) {
			$rows = array_slice( $rows, 0, (int) $limit[1] );
		}

		return array_map( static fn( array $row ): object => (object) $row, $rows );
	}

	/** @return array<int, array<string, mixed>> */
	private function filtered_activity_rows( string $query ): array {
		$rows = $this->activity_rows;
		if ( preg_match( '/event_type IN \(([^)]+)\)/', $query, $scope ) ) {
			preg_match_all( "/'([^']+)'/", $scope[1], $matches );
			$included = array_map( 'stripslashes', $matches[1] );
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( $row['event_type'], $included, true ) ) );
		}
		return $rows;
	}
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistEntry.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistRepository.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistService.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityService.php';
require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutMode.php';
require_once __DIR__ . '/../../src/PHP/REST/OverviewController.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\ActivityIdentifierRetention;
use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Checkout\CheckoutMode;
use Veridis\FraudGuard\REST\OverviewController;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function vfg_activity_row( int $id, string $type, string $created_at, ?int $order_id = null, string $matched_types = 'email', ?string $matched_identifiers = null, int $occurrence_count = 1 ): array {
	$outcomes = array( 'block' => 'rejected', 'hold' => 'held', 'warn' => 'flagged', 'blocklist' => 'added' );
	$sources  = array( 'block' => 'classic', 'hold' => 'classic', 'warn' => 'blocks', 'blocklist' => 'admin' );
	return array(
		'id'                  => $id,
		'event_type'          => $type,
		'outcome'             => $outcomes[ $type ],
		'source'              => $sources[ $type ],
		'order_id'            => $order_id,
		'matched_types'       => $matched_types,
		'matched_identifiers' => $matched_identifiers,
		'occurrence_count'     => $occurrence_count,
		'created_at'          => $created_at,
	);
}

// New York falls back on 2026-11-01. The local calendar day spans 25 UTC hours.
$GLOBALS['vfg_site_timezone'] = new DateTimeZone( 'America/New_York' );
$rows = array(
	vfg_activity_row( 1, 'block', '2026-10-26 03:59:59', null, 'email,customer@example.com' ),
	vfg_activity_row( 2, 'block', '2026-10-26 04:00:00' ),
	vfg_activity_row( 3, 'hold', '2026-11-01 03:59:59' ),
	vfg_activity_row( 4, 'block', '2026-11-01 04:00:00', null, 'email', null, 3 ),
	vfg_activity_row( 5, 'warn', '2026-11-02 04:59:59', 12, 'ip,203.0.113.9', '[{"type":"ip","value":"203.0.113.9"}]' ),
	vfg_activity_row( 6, 'hold', '2026-11-02 05:00:00', 99 ),
	vfg_activity_row( 7, 'blocklist', '2026-11-01 18:00:00', null, 'phone,+40700000000' ),
	vfg_activity_row( 8, 'warn', '2026-11-01 18:30:00', 21, 'email,domain,phone,ip', '[{"type":"email","value":"hold-warning@example.com"},{"type":"domain","value":"example.com"},{"type":"phone","value":"+40712345678"},{"type":"ip","value":"203.0.113.21"}]' ),
);
$GLOBALS['vfg_orders'][12] = new WC_Order( 'WC-12', '/admin/order/12' );
$database = new wpdb( $rows, array( array( 'type' => 'email' ), array( 'type' => 'ip' ) ) );
$activity = new ActivityService( new ActivityRepository( $database ) );
$now      = new DateTimeImmutable( '2026-11-01 12:00:00', $GLOBALS['vfg_site_timezone'] );
$dashboard = $activity->dashboard( 5, $now );

vfg_assert( array( 'total' => 9, 'today' => 5, 'last_7_days' => 7 ) === $dashboard['counts'], 'Checkout-only totals did not count coalesced attempts or respect fall-DST calendar boundaries.' );
vfg_assert( array( 6, 5, 8, 4, 3 ) === array_column( $dashboard['recent'], 'id' ), 'Recent checkout events are not newest first or correctly limited.' );
vfg_assert( ! in_array( 7, array_column( $dashboard['recent'], 'id' ), true ), 'Administrative activity leaked into the Dashboard.' );
vfg_assert( '/admin/order/12' === $dashboard['recent'][1]['order']['edit_url'] && 'WC-12' === $dashboard['recent'][1]['order']['number'], 'Dashboard order reference is incorrect.' );
vfg_assert( null === $dashboard['recent'][0]['order']['edit_url'] && '99' === $dashboard['recent'][0]['order']['number'], 'Missing-order fallback is incorrect.' );

$serialized = json_encode( $dashboard );
vfg_assert( false !== $serialized && ! str_contains( $serialized, 'customer@example.com' ) && str_contains( $serialized, '203.0.113.9' ) && ! str_contains( $serialized, '+40700000000' ), 'Dashboard did not expose only explicitly stored checkout match values.' );
vfg_assert( array( array( 'type' => 'ip', 'value' => '203.0.113.9' ) ) === $dashboard['recent'][1]['matched_identifiers'], 'Dashboard exact match payload is incorrect.' );
vfg_assert( array() === $dashboard['recent'][0]['matched_identifiers'], 'Historical Dashboard event did not return a type-only fallback.' );
vfg_assert( 'warn' === $dashboard['recent'][2]['event_type'] && 'flagged' === $dashboard['recent'][2]['outcome'] && 4 === count( $dashboard['recent'][2]['matched_identifiers'] ), 'Dashboard did not present the non-COD Hold warning as a flagged intervention with all matches.' );
vfg_assert( str_contains( implode( "\n", $database->queries ), 'SUM(occurrence_count)' ) && str_contains( implode( "\n", $database->queries ), 'LIMIT 5' ), 'Dashboard queries are not bounded and occurrence-aware.' );

$blocklist = new BlocklistService( new BlocklistRepository( $database ), new BlocklistNormalizer() );
$controller = new OverviewController( '1.0.0', $blocklist, $activity );
$GLOBALS['vfg_options'][ CheckoutMode::OPTION ] = CheckoutMode::WARN;
$response  = $controller->get();
$payload   = $response->get_data();
vfg_assert( array( 'total' => 2, 'email' => 1, 'domain' => 0, 'phone' => 0, 'ip' => 1 ) === $payload['blocklist'], 'Overview blocklist data regressed.' );
vfg_assert( 9 === $payload['activity']['counts']['total'] && 3 === $payload['activity']['recent'][3]['occurrence_count'] && array( 6, 5, 8, 4, 3 ) === array_column( $payload['activity']['recent'], 'id' ), 'Overview Activity contract lost coalesced or non-COD Hold attempts.' );
vfg_assert( array( 'total', 'today', 'last_7_days' ) === array_keys( $payload['activity']['counts'] ) && 'no-store' === $response->headers['Cache-Control'], 'Overview Activity windows or cache policy are incorrect.' );
vfg_assert( array( 'blocklist', 'activity' ) === $payload['modules'], 'Overview module availability is incorrect.' );
vfg_assert( CheckoutMode::WARN === $payload['checkout_protection']['mode'], 'Overview did not return the effective checkout mode.' );

$baseline_blocklist = $payload['blocklist'];
$baseline_activity  = $payload['activity'];
foreach ( CheckoutMode::values() as $mode ) {
	$GLOBALS['vfg_options'] = array(
		CheckoutMode::OPTION              => $mode,
		CheckoutMode::LEGACY_BLOCK_OPTION => 'yes',
	);
	$mode_payload = $controller->get()->get_data();
	vfg_assert( $mode === $mode_payload['checkout_protection']['mode'], "Overview mode response is incorrect for {$mode}." );
	vfg_assert( $baseline_blocklist === $mode_payload['blocklist'] && $baseline_activity === $mode_payload['activity'], "Dashboard statistics regressed for {$mode} mode." );
}
$GLOBALS['vfg_options'] = array(
	CheckoutMode::OPTION              => CheckoutMode::OFF,
	CheckoutMode::LEGACY_BLOCK_OPTION => 'yes',
);
vfg_assert( CheckoutMode::OFF === $controller->get()->get_data()['checkout_protection']['mode'], 'An explicit Off mode did not override the legacy enabled flag.' );

unset( $GLOBALS['vfg_options'][ CheckoutMode::OPTION ] );
$GLOBALS['vfg_options'][ CheckoutMode::LEGACY_BLOCK_OPTION ] = 'yes';
vfg_assert( CheckoutMode::BLOCK === $controller->get()->get_data()['checkout_protection']['mode'], 'Legacy enabled mode did not fall back to Block.' );
$GLOBALS['vfg_options'][ CheckoutMode::LEGACY_BLOCK_OPTION ] = 'no';
vfg_assert( CheckoutMode::OFF === $controller->get()->get_data()['checkout_protection']['mode'], 'Missing mode without legacy enablement did not fall back to Off.' );

// New York springs forward on 2026-03-08. The local calendar day spans 23 UTC hours.
$spring_rows = array(
	vfg_activity_row( 1, 'block', '2026-03-08 04:59:59' ),
	vfg_activity_row( 2, 'block', '2026-03-08 05:00:00' ),
	vfg_activity_row( 3, 'warn', '2026-03-09 03:59:59' ),
	vfg_activity_row( 4, 'hold', '2026-03-09 04:00:00' ),
);
$spring = new ActivityService( new ActivityRepository( new wpdb( $spring_rows ) ) );
$spring_dashboard = $spring->dashboard( 5, new DateTimeImmutable( '2026-03-08 12:00:00', $GLOBALS['vfg_site_timezone'] ) );
vfg_assert( array( 'total' => 4, 'today' => 2, 'last_7_days' => 3 ) === $spring_dashboard['counts'], 'Spring-DST calendar boundaries are incorrect.' );

$empty = new ActivityService( new ActivityRepository( new wpdb() ) );
vfg_assert( array( 'counts' => array( 'total' => 0, 'today' => 0, 'last_7_days' => 0 ), 'recent' => array() ) === $empty->dashboard( 5, $now ), 'Empty Activity storage did not return real zero values.' );

$retention_now  = new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) );
$retention_rows = array(
	vfg_activity_row( 20, 'block', '2026-07-12 11:59:59', 31, 'email', '[{"type":"email","value":"expired@example.com"}]' ),
	vfg_activity_row( 21, 'warn', '2026-07-12 12:00:00', 32, 'email', '[{"type":"email","value":"boundary@example.com"}]' ),
	vfg_activity_row( 22, 'hold', '2026-10-09 12:00:00', 33, 'phone', '[{"type":"phone","value":"+40712345678"}]' ),
);
$retention_database = new wpdb( $retention_rows, array( array( 'type' => 'email' ) ) );
$retention_activity = new ActivityService(
	new ActivityRepository( $retention_database ),
	new ActivityIdentifierRetention( $retention_now )
);
$retention_overview = new OverviewController(
	'1.0.0',
	new BlocklistService( new BlocklistRepository( $retention_database ), new BlocklistNormalizer() ),
	$retention_activity
);
$retention_payload = $retention_overview->get()->get_data();
$retention_items   = array_column( $retention_payload['activity']['recent'], null, 'id' );
vfg_assert( array() === $retention_items[20]['matched_identifiers'], 'Expired identifiers were exposed by Dashboard REST.' );
vfg_assert( array( array( 'type' => 'email', 'value' => 'boundary@example.com' ) ) === $retention_items[21]['matched_identifiers'], 'Dashboard hid identifiers at the retention boundary.' );
vfg_assert( array( array( 'type' => 'phone', 'value' => '+40712345678' ) ) === $retention_items[22]['matched_identifiers'], 'Dashboard hid current identifiers.' );
vfg_assert( 3 === $retention_payload['activity']['counts']['total'] && 31 === $retention_items[20]['order_id'], 'Dashboard retention changed metrics or Activity metadata.' );

echo "Dashboard Activity harness: PASS\n";
