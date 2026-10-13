<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_can_manage_woocommerce'] = false;
$GLOBALS['vfg_registered_route']       = array();
$GLOBALS['vfg_orders']                 = array();
$GLOBALS['vfg_retention_days']         = null;

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function apply_filters( string $hook, mixed $value ): mixed {
	if ( 'veridis_fraud_guard_activity_identifier_retention_days' === $hook && null !== $GLOBALS['vfg_retention_days'] ) {
		return $GLOBALS['vfg_retention_days'];
	}
	return $value;
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability && true === $GLOBALS['vfg_can_manage_woocommerce'];
}

function register_rest_route( string $namespace, string $route, array $config ): void {
	$GLOBALS['vfg_registered_route'] = compact( 'namespace', 'route', 'config' );
}

function wc_get_order( int $order_id ): WC_Order|false {
	return $GLOBALS['vfg_orders'][ $order_id ] ?? false;
}

final class WP_REST_Server {
	public const READABLE = 'GET';
}

final class WP_REST_Request {
	/** @param array<string, mixed> $params */
	public function __construct( private array $params = array() ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
}

final class WP_REST_Response {
	/** @var array<string, string> */
	public array $headers = array();
	public function __construct( private mixed $data, private int $status = 200 ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	public function get_data(): mixed { return $this->data; }
	public function get_status(): int { return $this->status; }
}

class WC_Order {
	public function __construct( private string $number, private string $url ) {}
	public function get_order_number(): string { return $this->number; }
	public function get_edit_order_url(): string { return $this->url; }
}

final class wpdb {
	public string $prefix = 'wp_';
	/** @param array<int, array<string, mixed>> $rows */
	public function __construct( private array $rows ) {}

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

	public function get_var( string $query ): int {
		return count( $this->filtered_rows( $query ) );
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		if ( str_contains( $query, 'GROUP BY event_type' ) ) {
			$counts = array();
			foreach ( $this->filtered_rows( $query ) as $row ) {
				$counts[ $row['event_type'] ] = ( $counts[ $row['event_type'] ] ?? 0 ) + (int) ( $row['occurrence_count'] ?? 1 );
			}
			return array_map(
				static fn( string $type, int $count ): object => (object) array( 'event_type' => $type, 'event_count' => $count ),
				array_keys( $counts ),
				array_values( $counts )
			);
		}

		$rows = $this->filtered_rows( $query );
		usort(
			$rows,
			static fn( array $left, array $right ): int => array( $right['created_at'], $right['id'] ) <=> array( $left['created_at'], $left['id'] )
		);
		preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $pagination );
		if ( $pagination ) {
			$rows = array_slice( $rows, (int) $pagination[2], (int) $pagination[1] );
		}

		return array_map( static fn( array $row ): object => (object) $row, $rows );
	}

	/** @return array<int, array<string, mixed>> */
	private function filtered_rows( string $query ): array {
		$rows = $this->rows;
		if ( preg_match( '/event_type IN \(([^)]+)\)/', $query, $scope ) ) {
			preg_match_all( "/'([^']+)'/", $scope[1], $matches );
			$included = array_map( 'stripslashes', $matches[1] );
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( $row['event_type'], $included, true ) ) );
		}
		if ( preg_match( "/event_type = '([^']+)'/", $query, $match ) ) {
			$rows = array_values( array_filter( $rows, static fn( array $row ): bool => $row['event_type'] === stripslashes( $match[1] ) ) );
		}
		return $rows;
	}
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Core/Access.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityService.php';
require_once __DIR__ . '/../../src/PHP/REST/ActivityController.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\ActivityIdentifierRetention;
use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\REST\ActivityController;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$rows = array(
	array( 'id' => 1, 'event_type' => 'block', 'outcome' => 'rejected', 'source' => 'classic', 'order_id' => null, 'matched_types' => 'email,raw@example.com', 'created_at' => '2026-10-01 10:00:00' ),
	array( 'id' => 2, 'event_type' => 'warn', 'outcome' => 'flagged', 'source' => 'blocks', 'order_id' => 12, 'matched_types' => 'ip,203.0.113.7', 'matched_identifiers' => '[{"type":"ip","value":"203.0.113.7"}]', 'created_at' => '2026-10-02 10:00:00' ),
	array( 'id' => 3, 'event_type' => 'hold', 'outcome' => 'held', 'source' => 'classic', 'order_id' => 11, 'matched_types' => 'phone', 'created_at' => '2026-10-03 10:00:00' ),
	array( 'id' => 4, 'event_type' => 'block', 'outcome' => 'rejected', 'source' => 'blocks', 'order_id' => null, 'matched_types' => 'domain', 'occurrence_count' => 3, 'created_at' => '2026-10-04 10:00:00' ),
	array( 'id' => 5, 'event_type' => 'warn', 'outcome' => 'flagged', 'source' => 'classic', 'order_id' => 99, 'matched_types' => 'email,domain', 'matched_identifiers' => '[{"type":"email","value":"customer@example.com"},{"type":"domain","value":"example.com"}]', 'created_at' => '2026-10-05 10:00:00' ),
	array( 'id' => 6, 'event_type' => 'blocklist', 'outcome' => 'added', 'source' => 'admin', 'order_id' => null, 'matched_types' => 'email', 'matched_identifiers' => '[{"type":"email","value":"must-not-leak@example.com"}]', 'created_at' => '2026-10-06 10:00:00' ),
	array( 'id' => 7, 'event_type' => 'blocklist', 'outcome' => 'edited', 'source' => 'admin', 'order_id' => null, 'matched_types' => 'phone,raw-phone-value', 'created_at' => '2026-10-07 10:00:00' ),
	array( 'id' => 8, 'event_type' => 'blocklist', 'outcome' => 'unblocked', 'source' => 'order_panel', 'order_id' => 12, 'matched_types' => 'domain', 'created_at' => '2026-10-08 10:00:00' ),
	array( 'id' => 9, 'event_type' => 'warn', 'outcome' => 'flagged', 'source' => 'classic', 'order_id' => 21, 'matched_types' => 'email,domain,phone,ip', 'matched_identifiers' => '[{"type":"email","value":"hold-warning@example.com"},{"type":"domain","value":"example.com"},{"type":"phone","value":"+40712345678"},{"type":"ip","value":"203.0.113.21"}]', 'created_at' => '2026-10-09 09:00:00' ),
	array( 'id' => 10, 'event_type' => 'unsupported', 'outcome' => 'secret', 'source' => 'unknown', 'order_id' => null, 'matched_types' => 'email', 'created_at' => '2026-10-09 10:00:00' ),
);
$GLOBALS['vfg_orders'][12] = new WC_Order( 'WC-12', '/admin/order/12' );
$service                   = new ActivityService( new ActivityRepository( new wpdb( $rows ) ) );
$controller                = new ActivityController( $service );
$controller->register();

$route = $GLOBALS['vfg_registered_route'];
vfg_assert( 'veridis-fraud-guard/v1' === $route['namespace'] && '/activity' === $route['route'], 'Activity route was not registered correctly.' );
vfg_assert( array( '', 'checkout', 'block', 'hold', 'warn', 'blocklist' ) === $route['config']['args']['event_type']['enum'], 'Event filter validation is incomplete.' );
vfg_assert( 1 === $route['config']['args']['page']['minimum'] && 100 === $route['config']['args']['per_page']['maximum'], 'Pagination validation is incomplete.' );

$permission = $route['config']['permission_callback'];
vfg_assert( false === $permission(), 'Unauthorized user received Activity access.' );
$GLOBALS['vfg_can_manage_woocommerce'] = true;
vfg_assert( true === $permission(), 'manage_woocommerce user was denied Activity access.' );

$response = $controller->list( new WP_REST_Request( array( 'page' => 1, 'per_page' => 2 ) ) );
$data     = $response->get_data();
vfg_assert( 200 === $response->get_status() && 'no-store' === $response->headers['Cache-Control'], 'Activity response metadata is incorrect.' );
vfg_assert( array( 9, 8 ) === array_column( $data['items'], 'id' ), 'All activity is not ordered newest first.' );
vfg_assert( 9 === $data['pagination']['total_items'] && 5 === $data['pagination']['total_pages'], 'All-activity pagination totals are incorrect.' );
vfg_assert( array( 'total' => 11, 'block' => 4, 'hold' => 1, 'warn' => 3 ) === $data['counts'], 'Summary counts do not include coalesced attempts or distinguish held orders from warnings.' );
vfg_assert( 'warn' === $data['items'][0]['event_type'] && 'flagged' === $data['items'][0]['outcome'], 'Non-COD Hold warning presentation is incorrect.' );
vfg_assert( 'unblocked' === $data['items'][1]['outcome'] && 'order_panel' === $data['items'][1]['source'] && array( 'domain' ) === $data['items'][1]['matched_types'], 'Administrative event presentation is incorrect.' );
vfg_assert( '/admin/order/12' === $data['items'][1]['order']['edit_url'], 'Order-panel event order reference is incorrect.' );

$filtered = $controller->list( new WP_REST_Request( array( 'event_type' => 'block', 'page' => 1, 'per_page' => 10 ) ) )->get_data();
vfg_assert( array( 4, 1 ) === array_column( $filtered['items'], 'id' ), 'Event filtering is incorrect.' );
vfg_assert( 2 === $filtered['pagination']['total_items'] && 11 === $filtered['counts']['total'] && 3 === $filtered['items'][0]['occurrence_count'], 'Filtered pagination or occurrence-aware global counts are incorrect.' );

$checkout = $controller->list( new WP_REST_Request( array( 'event_type' => 'checkout', 'page' => 1, 'per_page' => 10 ) ) )->get_data();
vfg_assert( array( 9, 5, 4, 3, 2, 1 ) === array_column( $checkout['items'], 'id' ) && 6 === $checkout['pagination']['total_items'], 'Checkout aggregate filtering is incorrect.' );
vfg_assert(
	array(
		array( 'type' => 'email', 'value' => 'hold-warning@example.com' ),
		array( 'type' => 'domain', 'value' => 'example.com' ),
		array( 'type' => 'phone', 'value' => '+40712345678' ),
		array( 'type' => 'ip', 'value' => '203.0.113.21' ),
	) === $checkout['items'][0]['matched_identifiers'],
	'Authorized Activity responses do not expose all exact non-COD warning matches.'
);
vfg_assert( array() === $checkout['items'][2]['matched_identifiers'], 'Historical event without stored values did not retain its type-only fallback.' );

$manual = $controller->list( new WP_REST_Request( array( 'event_type' => 'blocklist', 'page' => 1, 'per_page' => 2 ) ) )->get_data();
vfg_assert( array( 8, 7 ) === array_column( $manual['items'], 'id' ) && 3 === $manual['pagination']['total_items'] && 2 === $manual['pagination']['total_pages'], 'Manual-action filtering or pagination is incorrect.' );
vfg_assert( array( 'phone' ) === $manual['items'][1]['matched_types'], 'Administrative identifier types were not canonicalized.' );
vfg_assert( array() === $controller->list( new WP_REST_Request( array( 'event_type' => 'blocklist', 'page' => 2, 'per_page' => 2 ) ) )->get_data()['items'][0]['matched_identifiers'], 'Administrative event exposed a stored identifier payload.' );

$warn = $controller->list( new WP_REST_Request( array( 'event_type' => 'warn', 'page' => 1, 'per_page' => 10 ) ) )->get_data();
vfg_assert( array( 9, 5, 2 ) === array_column( $warn['items'], 'id' ) && 3 === $warn['pagination']['total_items'], 'Warn filtering excluded a non-COD Hold warning.' );
vfg_assert( null === $warn['items'][0]['order']['edit_url'] && '21' === $warn['items'][0]['order']['number'], 'Non-COD warning order fallback is incorrect.' );
vfg_assert( null === $warn['items'][1]['order']['edit_url'] && '99' === $warn['items'][1]['order']['number'], 'Missing order fallback is incorrect.' );
vfg_assert( '/admin/order/12' === $warn['items'][2]['order']['edit_url'] && 'WC-12' === $warn['items'][2]['order']['number'], 'WooCommerce order reference is incorrect.' );

$serialized = json_encode( $controller->list( new WP_REST_Request( array( 'per_page' => 100 ) ) )->get_data() );
vfg_assert( false !== $serialized && ! str_contains( $serialized, 'raw@example.com' ) && str_contains( $serialized, '203.0.113.7' ) && ! str_contains( $serialized, 'raw-phone-value' ) && ! str_contains( $serialized, 'unsupported' ) && ! str_contains( $serialized, 'must-not-leak@example.com' ), 'Activity REST did not isolate exact checkout matches from malformed type data or administrative events.' );
vfg_assert( array( 'email' ) === $controller->list( new WP_REST_Request( array( 'event_type' => 'block', 'page' => 2, 'per_page' => 1 ) ) )->get_data()['items'][0]['matched_types'], 'Matched types were not canonicalized.' );

$defensive = $service->list( 'invalid', 0, 500 );
vfg_assert( 1 === $defensive['pagination']['page'] && 100 === $defensive['pagination']['per_page'] && 9 === $defensive['pagination']['total_items'] && 11 === $defensive['counts']['total'], 'Service parameter bounds or occurrence totals are incorrect.' );

$retention_now  = new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) );
$retention_rows = array(
	array( 'id' => 20, 'event_type' => 'block', 'outcome' => 'rejected', 'source' => 'classic', 'order_id' => 31, 'matched_types' => 'email', 'matched_identifiers' => '[{"type":"email","value":"expired@example.com"}]', 'created_at' => '2026-07-12 11:59:59' ),
	array( 'id' => 21, 'event_type' => 'warn', 'outcome' => 'flagged', 'source' => 'blocks', 'order_id' => 32, 'matched_types' => 'email', 'matched_identifiers' => '[{"type":"email","value":"boundary@example.com"}]', 'created_at' => '2026-07-12 12:00:00' ),
	array( 'id' => 22, 'event_type' => 'hold', 'outcome' => 'held', 'source' => 'classic', 'order_id' => 33, 'matched_types' => 'phone', 'matched_identifiers' => '[{"type":"phone","value":"+40712345678"}]', 'created_at' => '2026-10-09 12:00:00' ),
);
$retention_service = new ActivityService(
	new ActivityRepository( new wpdb( $retention_rows ) ),
	new ActivityIdentifierRetention( $retention_now )
);
$retention_controller = new ActivityController( $retention_service );
$retention_data       = $retention_controller->list( new WP_REST_Request( array( 'event_type' => 'checkout', 'per_page' => 10 ) ) )->get_data();
$retention_items      = array_column( $retention_data['items'], null, 'id' );
vfg_assert( array() === $retention_items[20]['matched_identifiers'], 'Expired identifiers were exposed by Activity REST.' );
vfg_assert( array( array( 'type' => 'email', 'value' => 'boundary@example.com' ) ) === $retention_items[21]['matched_identifiers'], 'Identifiers at the retention boundary were hidden.' );
vfg_assert( array( array( 'type' => 'phone', 'value' => '+40712345678' ) ) === $retention_items[22]['matched_identifiers'], 'Current identifiers were hidden by Activity REST.' );
vfg_assert( array( 'email' ) === $retention_items[20]['matched_types'] && 31 === $retention_items[20]['order_id'], 'Retention removed Activity metadata or its order reference.' );
$GLOBALS['vfg_retention_days'] = 3;
$custom_retention_data  = $retention_controller->list( new WP_REST_Request( array( 'event_type' => 'checkout', 'per_page' => 10 ) ) )->get_data();
$custom_retention_items = array_column( $custom_retention_data['items'], null, 'id' );
vfg_assert( array() === $custom_retention_items[21]['matched_identifiers'], 'Activity REST did not apply the custom retention period.' );
vfg_assert( array( array( 'type' => 'phone', 'value' => '+40712345678' ) ) === $custom_retention_items[22]['matched_identifiers'], 'Custom retention hid a current Activity identifier.' );
$GLOBALS['vfg_retention_days'] = null;

echo "Activity REST harness: PASS\n";
