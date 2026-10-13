<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['vfg_hooks']     = array();
$GLOBALS['vfg_options']   = array( 'veridis_fraud_guard_activity_retention_last_run' => time() );
$GLOBALS['vfg_scheduled'] = array( 'veridis_fraud_guard_purge_activity_identifiers' => time() + 3600 );

function __( string $message, string $domain = '' ): string {
	if ( 'veridis-fraud-guard' !== $domain ) {
		throw new RuntimeException( 'Unexpected text domain.' );
	}
	return $message;
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function absint( mixed $value ): int { return abs( (int) $value ); }
function apply_filters( string $hook, mixed $value ): mixed { return $value; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['vfg_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	unset( $autoload );
	$GLOBALS['vfg_options'][ $key ] = $value;
	return true;
}
function add_action( string $hook, callable $callback ): void { $GLOBALS['vfg_hooks']['actions'][ $hook ] = $callback; }
function add_filter( string $hook, callable $callback ): void { $GLOBALS['vfg_hooks']['filters'][ $hook ] = $callback; }
function wp_next_scheduled( string $hook ): int|false { return $GLOBALS['vfg_scheduled'][ $hook ] ?? false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	unset( $recurrence );
	$GLOBALS['vfg_scheduled'][ $hook ] = $timestamp;
	return true;
}
function wp_kses_post( string $content ): string { return $content; }
function wp_add_privacy_policy_content( string $plugin_name, string $policy_text ): void { unset( $plugin_name, $policy_text ); }

final class wpdb {
	public string $prefix = 'wp_';

	/** @param array<int, array<string, mixed>> $activity_rows */
	public function __construct( public array $activity_rows ) {}

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		return preg_replace_callback(
			'/%[di]/',
			static function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position++ ];
				return '%d' === $match[0]
					? (string) (int) $value
					: '`' . str_replace( '`', '``', (string) $value ) . '`';
			},
			$query
		) ?? $query;
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		if ( ! str_contains( $query, 'SELECT id, event_type, outcome' ) ) {
			return array();
		}

		$rows = array_values(
			array_filter(
				$this->activity_rows,
				static fn( array $row ): bool => in_array( $row['event_type'], array( 'block', 'hold', 'warn' ), true )
			)
		);
		usort( $rows, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );
		preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $limits );
		$rows = array_slice( $rows, (int) $limits[2], (int) $limits[1] );
		return array_map( static fn( array $row ): object => (object) $row, $rows );
	}
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityPrivacy.php';

use Veridis\FraudGuard\Activity\ActivityIdentifierRetention;
use Veridis\FraudGuard\Activity\ActivityPrivacy;
use Veridis\FraudGuard\Activity\ActivityRepository;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return array<string, mixed> */
function vfg_row( int $id, string $identifiers, string $created_at = '2026-10-01 10:00:00', string $event_type = 'block' ): array {
	return array(
		'id'                  => $id,
		'event_type'          => $event_type,
		'outcome'             => 'rejected',
		'source'              => 'classic',
		'order_id'            => 700 + $id,
		'matched_types'        => 'email,phone',
		'matched_identifiers' => $identifiers,
		'occurrence_count'    => 2,
		'deduplication_key'   => 'private-' . $id,
		'created_at'          => $created_at,
		'order_note'          => 'private note ' . $id,
	);
}

$target = '[{"type":"email","value":"Target@Example.com"},{"type":"email","value":"unrelated-in-snapshot@example.com"},{"type":"phone","value":"+40712345678"}]';
$other  = '[{"type":"email","value":"other@example.com"},{"type":"ip","value":"203.0.113.9"}]';
$rows   = array(
	vfg_row( 1, $target ),
	vfg_row( 2, $other ),
	vfg_row( 3, $target, '2026-06-01 10:00:00' ),
	vfg_row( 4, '' ),
	vfg_row( 5, $target, '2026-10-01 10:00:00', 'blocklist' ),
);
for ( $id = 6; $id <= 99; ++$id ) {
	$rows[] = vfg_row( $id, $other );
}
$rows[] = vfg_row( 100, $target );
$rows[] = vfg_row( 101, $other );
$rows[] = vfg_row( 102, $target, '2026-10-02 10:00:00', 'hold' );

$database = new wpdb( $rows );
$privacy  = new ActivityPrivacy(
	new ActivityRepository( $database ),
	new ActivityIdentifierRetention( new DateTimeImmutable( '2026-10-12 12:00:00', new DateTimeZone( 'UTC' ) ) )
);
$privacy->register();
vfg_assert( isset( $GLOBALS['vfg_hooks']['filters']['wp_privacy_personal_data_exporters'] ), 'WordPress privacy exporter was not registered.' );

$registered = $privacy->register_exporter( array() );
vfg_assert( isset( $registered['veridis-fraud-guard-activity']['callback'], $registered['veridis-fraud-guard-activity']['exporter_friendly_name'] ), 'Exporter registration structure is incorrect.' );

$page_one = $privacy->export_personal_data( '  TARGET@example.com ', 1 );
$page_two = $privacy->export_personal_data( 'target@example.com', 2 );
vfg_assert( array( 'data', 'done' ) === array_keys( $page_one ) && false === $page_one['done'], 'First exporter response structure or completion state is incorrect.' );
vfg_assert( array( 'data', 'done' ) === array_keys( $page_two ) && true === $page_two['done'], 'Final exporter response structure or completion state is incorrect.' );

$exported = array_merge( $page_one['data'], $page_two['data'] );
$item_ids = array_column( $exported, 'item_id' );
vfg_assert( array( 'activity-1', 'activity-100', 'activity-102' ) === $item_ids, 'Pagination missed, duplicated, or incorrectly matched Activity records.' );
vfg_assert( count( $item_ids ) === count( array_unique( $item_ids ) ), 'Exporter duplicated an Activity record across pages.' );

foreach ( $exported as $item ) {
	vfg_assert( 'veridis-fraud-guard-activity' === $item['group_id'] && 'Veridis Fraud Guard checkout activity' === $item['group_label'], 'WordPress exporter item structure is incorrect.' );
	vfg_assert( isset( $item['data'] ) && is_array( $item['data'] ), 'Exporter item data is missing.' );
	foreach ( $item['data'] as $field ) {
		vfg_assert( isset( $field['name'], $field['value'] ) && is_string( $field['name'] ) && is_string( $field['value'] ), 'Exporter fields must contain string names and values.' );
	}
}

$serialized = json_encode( $exported );
vfg_assert( is_string( $serialized ) && str_contains( $serialized, 'Target@Example.com' ) && str_contains( $serialized, '+40712345678' ), 'Matching retained snapshot data was not exported.' );
vfg_assert( str_contains( $serialized, 'Event type' ) && str_contains( $serialized, 'Occurrence count' ) && str_contains( $serialized, 'Order reference' ), 'Useful Activity event context was not exported.' );
vfg_assert( ! str_contains( $serialized, 'other@example.com' ) && ! str_contains( $serialized, 'unrelated-in-snapshot@example.com' ), 'An unrelated email address was exposed.' );
vfg_assert( ! str_contains( $serialized, '203.0.113.9' ) && ! str_contains( $serialized, 'private-') && ! str_contains( $serialized, 'private note' ), 'Unauthorized Activity or order data was exposed.' );
vfg_assert( ! in_array( 'activity-3', $item_ids, true ) && ! in_array( 'activity-4', $item_ids, true ) && ! in_array( 'activity-5', $item_ids, true ), 'Expired, erased, or Blocklist data was exported.' );

echo "Activity personal data exporter harness: PASS\n";
