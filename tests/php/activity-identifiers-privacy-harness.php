<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['vfg_options']        = array( 'veridis_fraud_guard_db_version' => '3' );
$GLOBALS['vfg_schema_sql']     = array();
$GLOBALS['vfg_scheduled']      = array();
$GLOBALS['vfg_hooks']          = array();
$GLOBALS['vfg_policy']         = array();
$GLOBALS['vfg_cleared_hooks']  = array();
$GLOBALS['vfg_retention_days'] = null;

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['vfg_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	unset( $autoload );
	$GLOBALS['vfg_options'][ $key ] = $value;
	return true;
}

function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['vfg_options'] ) ) {
		return false;
	}
	$GLOBALS['vfg_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['vfg_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['vfg_options'][ $key ] );
	return true;
}

function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000001';
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $key, $group );
	return true;
}

function dbDelta( array $queries ): void {
	$GLOBALS['vfg_schema_sql'] = $queries;
}

function add_action( string $hook, callable $callback ): void {
	$GLOBALS['vfg_hooks']['actions'][ $hook ] = $callback;
}

function add_filter( string $hook, callable $callback ): void {
	$GLOBALS['vfg_hooks']['filters'][ $hook ] = $callback;
}

function apply_filters( string $hook, mixed $value ): mixed {
	if ( 'veridis_fraud_guard_activity_identifier_retention_days' === $hook && null !== $GLOBALS['vfg_retention_days'] ) {
		return $GLOBALS['vfg_retention_days'];
	}
	return $value;
}

function wp_next_scheduled( string $hook ): int|false {
	return $GLOBALS['vfg_scheduled'][ $hook ] ?? false;
}

function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	$GLOBALS['vfg_scheduled'][ $hook ] = $timestamp;
	$GLOBALS['vfg_scheduled'][ $hook . ':recurrence' ] = $recurrence;
	return true;
}

function wp_clear_scheduled_hook( string $hook ): int|false {
	$GLOBALS['vfg_cleared_hooks'][] = $hook;
	unset( $GLOBALS['vfg_scheduled'][ $hook ], $GLOBALS['vfg_scheduled'][ $hook . ':recurrence' ] );
	return 1;
}

function wp_kses_post( string $content ): string {
	return $content;
}

function wp_add_privacy_policy_content( string $plugin_name, string $policy_text ): void {
	$GLOBALS['vfg_policy'] = compact( 'plugin_name', 'policy_text' );
}

final class wpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	/** @var array<int, array<string, mixed>> */
	public array $activity_rows;
	/** @var array<int, array<string, mixed>> */
	public array $blocklist_rows = array( array( 'id' => 40, 'type' => 'email', 'value' => 'target@example.com' ) );
	public int $purge_queries = 0;

	/** @param array<int, array<string, mixed>> $activity_rows */
	public function __construct( array $activity_rows ) {
		$this->activity_rows = $activity_rows;
	}

	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( string $value ): string { return $value; }

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
		if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
			preg_match( "/LIKE '([^']+)'/", $query, $column );
			return (object) array( 'Field' => $column[1] ?? 'matched_identifiers' );
		}
		if ( str_contains( $query, "Key_name = 'order_event_type_lookup'" ) ) {
			return (object) array( 'Non_unique' => 1 );
		}
		if ( str_contains( $query, "Key_name = 'deduplication_key'" ) ) {
			return (object) array( 'Non_unique' => 0 );
		}
		return null;
	}

	public function get_var( string $query ): string|null {
		if ( str_contains( $query, 'veridis_fraud_guard_blocklist' ) ) {
			return 'wp_veridis_fraud_guard_blocklist';
		}
		if ( str_contains( $query, 'veridis_fraud_guard_activity' ) ) {
			return 'wp_veridis_fraud_guard_activity';
		}
		return null;
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		if ( ! str_contains( $query, 'SELECT id, matched_identifiers' ) ) {
			return array();
		}

		$rows = array_values( array_filter( $this->activity_rows, static fn( array $row ): bool => in_array( $row['event_type'], array( 'block', 'hold', 'warn' ), true ) ) );
		usort( $rows, static fn( array $left, array $right ): int => $left['id'] <=> $right['id'] );
		preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $limits );
		$rows = $limits ? array_slice( $rows, (int) $limits[2], (int) $limits[1] ) : $rows;
		return array_map( static fn( array $row ): object => (object) array( 'id' => $row['id'], 'matched_identifiers' => $row['matched_identifiers'] ), $rows );
	}

	public function query( string $query ): int|false {
		$count = 0;
		if ( str_contains( $query, 'created_at <' ) ) {
			++$this->purge_queries;
			preg_match( "/created_at < '([^']+)'/", $query, $cutoff );
			foreach ( $this->activity_rows as &$row ) {
				if ( in_array( $row['event_type'], array( 'block', 'hold', 'warn' ), true ) && null !== $row['matched_identifiers'] && '' !== $row['matched_identifiers'] && $row['created_at'] < $cutoff[1] ) {
					$row['matched_identifiers'] = null;
					$row['deduplication_key'] = null;
					++$count;
				}
			}
			unset( $row );
			return $count;
		}

		if ( preg_match( '/WHERE id IN \(([^)]+)\)/', $query, $ids_match ) ) {
			$ids = array_map( 'intval', explode( ',', $ids_match[1] ) );
			foreach ( $this->activity_rows as &$row ) {
				if ( in_array( $row['id'], $ids, true ) && null !== $row['matched_identifiers'] ) {
					$row['matched_identifiers'] = null;
					$row['deduplication_key'] = null;
					++$count;
				}
			}
			unset( $row );
			return $count;
		}

		return 0;
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where */
	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		unset( $formats, $where_formats );
		if ( $this->options !== $table || ! isset( $where['option_name'], $where['option_value'] ) ) {
			return false;
		}
		$current = $GLOBALS['vfg_options'][ $where['option_name'] ] ?? null;
		if ( $current !== $where['option_value'] ) {
			return 0;
		}
		$GLOBALS['vfg_options'][ $where['option_name'] ] = $data['option_value'];
		return 1;
	}

	/** @param array<string, mixed> $where */
	public function delete( string $table, array $where, array $formats = array() ): int|false {
		unset( $formats );
		if ( $this->options !== $table || ! isset( $where['option_name'], $where['option_value'] ) ) {
			return false;
		}
		$current = $GLOBALS['vfg_options'][ $where['option_name'] ] ?? null;
		if ( $current !== $where['option_value'] ) {
			return 0;
		}
		unset( $GLOBALS['vfg_options'][ $where['option_name'] ] );
		return 1;
	}
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityPrivacy.php';
require_once __DIR__ . '/../../src/PHP/Core/Installer.php';

use Veridis\FraudGuard\Activity\ActivityPrivacy;
use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Core\Installer;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$target_snapshot = '[{"type":"email","value":"target@example.com"},{"type":"phone","value":"+40712345678"}]';
$GLOBALS['wpdb'] = new wpdb(
	array(
		array( 'id' => 1, 'event_type' => 'block', 'matched_types' => 'email,phone', 'matched_identifiers' => $target_snapshot, 'occurrence_count' => 7, 'deduplication_key' => str_repeat( 'a', 64 ), 'created_at' => '2026-10-01 10:00:00' ),
		array( 'id' => 2, 'event_type' => 'warn', 'matched_types' => 'email', 'matched_identifiers' => '[{"type":"email","value":"other@example.com"}]', 'created_at' => '2026-10-02 10:00:00' ),
		array( 'id' => 3, 'event_type' => 'hold', 'matched_types' => 'email', 'matched_identifiers' => $target_snapshot, 'occurrence_count' => 4, 'deduplication_key' => str_repeat( 'b', 64 ), 'created_at' => '2026-06-01 10:00:00' ),
		array( 'id' => 4, 'event_type' => 'blocklist', 'matched_types' => 'email', 'matched_identifiers' => $target_snapshot, 'created_at' => '2026-06-01 10:00:00' ),
	)
);

Installer::activate();
$schema = implode( "\n", $GLOBALS['vfg_schema_sql'] );
vfg_assert( '5' === Installer::SCHEMA_VERSION && '5' === $GLOBALS['vfg_options']['veridis_fraud_guard_db_version'], 'Schema migration version was not applied.' );
vfg_assert( str_contains( $schema, 'matched_identifiers text DEFAULT NULL' ), 'Activity identifier column is missing from dbDelta schema.' );
vfg_assert( str_contains( $schema, 'occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1' ), 'Activity occurrence counter is missing from dbDelta schema.' );
vfg_assert( str_contains( $schema, 'UNIQUE KEY deduplication_key (deduplication_key)' ), 'Activity coalescing key is not protected by a unique index.' );
vfg_assert( 4 === count( $GLOBALS['wpdb']->activity_rows ), 'Schema migration removed existing Activity events.' );

$GLOBALS['vfg_options'][ ActivityPrivacy::LAST_RUN_OPTION ] = time();
ActivityPrivacy::activate();
vfg_assert( null === $GLOBALS['wpdb']->activity_rows[2]['matched_identifiers'] && null === $GLOBALS['wpdb']->activity_rows[2]['deduplication_key'], 'Reactivation did not clear overdue identifier data and its coalescing key.' );
vfg_assert( (int) get_option( ActivityPrivacy::LAST_RUN_OPTION, 0 ) > 0, 'Activation did not record retention cleanup.' );
$GLOBALS['wpdb']->activity_rows[2]['matched_identifiers'] = $target_snapshot;

$privacy = new ActivityPrivacy( new ActivityRepository( $GLOBALS['wpdb'] ) );
$privacy->register();
vfg_assert( isset( $GLOBALS['vfg_hooks']['actions'][ ActivityPrivacy::CRON_HOOK ] ), 'Retention callback was not registered.' );
vfg_assert( 'daily' === $GLOBALS['vfg_scheduled'][ ActivityPrivacy::CRON_HOOK . ':recurrence' ], 'Daily retention event was not scheduled.' );
vfg_assert( isset( $GLOBALS['vfg_hooks']['filters']['wp_privacy_personal_data_erasers'] ), 'WordPress privacy eraser was not registered.' );

$removed = $privacy->purge_expired( new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) ) );
vfg_assert( 1 === $removed && null === $GLOBALS['wpdb']->activity_rows[2]['matched_identifiers'], 'Expired checkout identifiers were not purged.' );
vfg_assert( $target_snapshot === $GLOBALS['wpdb']->activity_rows[3]['matched_identifiers'], 'Retention changed an administrative event.' );
vfg_assert( 0 === $privacy->purge_expired( new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) ) ), 'Repeated retention cleanup was not idempotent.' );

$GLOBALS['wpdb']->activity_rows[0]['created_at']          = '2026-10-09 10:00:00';
$GLOBALS['wpdb']->activity_rows[1]['matched_identifiers'] = '[{"type":"email","value":"other@example.com"}]';
$GLOBALS['wpdb']->activity_rows[1]['created_at']          = '2026-10-05 10:00:00';
$GLOBALS['vfg_retention_days']                            = 3;
vfg_assert( 1 === $privacy->purge_expired( new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) ) ), 'Custom retention filter was not applied to physical cleanup.' );
$GLOBALS['vfg_retention_days'] = null;
$GLOBALS['wpdb']->activity_rows[1]['matched_identifiers'] = '[{"type":"email","value":"other@example.com"}]';

$GLOBALS['wpdb']->activity_rows[2]['matched_identifiers']   = $target_snapshot;
$GLOBALS['vfg_options'][ ActivityPrivacy::LAST_RUN_OPTION ] = 0;
$queries_before_fallback                               = $GLOBALS['wpdb']->purge_queries;
vfg_assert( 1 === $privacy->maybe_purge_expired( new DateTimeImmutable( '2026-10-10 12:00:00', new DateTimeZone( 'UTC' ) ) ), 'The boot fallback did not clean an overdue identifier when WP-Cron had not run.' );
vfg_assert( $queries_before_fallback + 1 === $GLOBALS['wpdb']->purge_queries, 'The fallback did not perform one cleanup query.' );
vfg_assert( 0 === $privacy->maybe_purge_expired( new DateTimeImmutable( '2026-10-10 13:00:00', new DateTimeZone( 'UTC' ) ) ), 'The cleanup fallback ignored its throttle interval.' );
vfg_assert( $queries_before_fallback + 1 === $GLOBALS['wpdb']->purge_queries, 'The throttled fallback repeated an expensive cleanup query.' );

$erasure = $privacy->erase_personal_data( 'TARGET@example.com', 1 );
vfg_assert( true === $erasure['items_removed'] && false === $erasure['items_retained'] && true === $erasure['done'], 'Personal data erasure result is incorrect.' );
vfg_assert( null === $GLOBALS['wpdb']->activity_rows[0]['matched_identifiers'] && null === $GLOBALS['wpdb']->activity_rows[0]['deduplication_key'] && 7 === $GLOBALS['wpdb']->activity_rows[0]['occurrence_count'] && 'email,phone' === $GLOBALS['wpdb']->activity_rows[0]['matched_types'], 'Erasure did not remove the full snapshot and coalescing key while preserving historical types and counts.' );
vfg_assert( '[{"type":"email","value":"other@example.com"}]' === $GLOBALS['wpdb']->activity_rows[1]['matched_identifiers'], 'Erasure removed another customer\'s identifiers.' );
vfg_assert( 1 === count( $GLOBALS['wpdb']->blocklist_rows ) && 'target@example.com' === $GLOBALS['wpdb']->blocklist_rows[0]['value'], 'Retention or erasure changed the Blocklist.' );

$privacy->add_policy_content();
vfg_assert( str_contains( $GLOBALS['vfg_policy']['policy_text'], 'normalized versions' ) && str_contains( $GLOBALS['vfg_policy']['policy_text'], 'Blocklist notes' ), 'Privacy policy omits Blocklist storage.' );
vfg_assert( str_contains( $GLOBALS['vfg_policy']['policy_text'], '90 days' ) && str_contains( $GLOBALS['vfg_policy']['policy_text'], 'WP-Cron is delayed or disabled' ), 'Privacy policy overstates scheduled Activity cleanup.' );
vfg_assert( str_contains( $GLOBALS['vfg_policy']['policy_text'], 'combined into one Activity record' ) && str_contains( $GLOBALS['vfg_policy']['policy_text'], 'non-reversible deduplication value' ), 'Privacy policy omits blocked-attempt coalescing.' );
vfg_assert( str_contains( $GLOBALS['vfg_policy']['policy_text'], 'order reference' ) && str_contains( $GLOBALS['vfg_policy']['policy_text'], 'order marker' ), 'Privacy policy omits retained order metadata.' );
vfg_assert( str_contains( $GLOBALS['vfg_policy']['policy_text'], 'stored only a phone number, IP address, or email domain' ), 'Privacy policy omits email eraser limitations.' );
$GLOBALS['wpdb']->activity_rows[1]['matched_identifiers'] = '[{"type":"email","value":"still-retained@example.com"}]';
ActivityPrivacy::deactivate();
vfg_assert( in_array( ActivityPrivacy::CRON_HOOK, $GLOBALS['vfg_cleared_hooks'], true ), 'Retention schedule was not removed on deactivation.' );
vfg_assert( null !== $GLOBALS['wpdb']->activity_rows[1]['matched_identifiers'], 'Deactivation destructively cleared a current identifier snapshot.' );

echo "Activity identifiers privacy harness: PASS\n";
