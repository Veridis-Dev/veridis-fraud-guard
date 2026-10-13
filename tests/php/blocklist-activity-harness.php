<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_test_options'] = array();
$GLOBALS['vfg_schema_sql']   = array();

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}

function _x( string $message, string $context, string $domain = '' ): string {
	unset( $context, $domain );
	return $message;
}

function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	unset( $domain );
	return 1 === $number ? $single : $plural;
}

function esc_html( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $message, string $domain = '' ): string {
	return esc_html( __( $message, $domain ) );
}

function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function number_format_i18n( int|float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals, '.', ',' );
}

function is_email( string $value ): bool {
	return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}

function wp_parse_url( string $value ): array|false {
	return parse_url( $value );
}

function current_time( string $type, bool $gmt = false ): string {
	unset( $type, $gmt );
	return '2026-10-10 12:00:00';
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['vfg_test_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool $autoload = false ): bool {
	unset( $autoload );
	$GLOBALS['vfg_test_options'][ $key ] = $value;
	return true;
}

function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['vfg_test_options'] ) ) {
		return false;
	}
	$GLOBALS['vfg_test_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	if ( ! array_key_exists( $key, $GLOBALS['vfg_test_options'] ) ) {
		return false;
	}
	unset( $GLOBALS['vfg_test_options'][ $key ] );
	return true;
}

function wp_generate_uuid4(): string {
	return '00000000-0000-4000-8000-000000000001';
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $key, $group );
	return true;
}

/** @param string[] $queries */
function dbDelta( array $queries ): void {
	$GLOBALS['vfg_schema_sql'] = $queries;
	if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
		$GLOBALS['wpdb']->lookup_index = true;
	}
}

final class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private mixed $data = null
	) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}

class wpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public int $insert_id = 0;
	public bool $fail_activity_insert = false;
	public string $fail_blocklist_type = '';
	public bool $legacy_unique_index = false;
	public bool $legacy_index_dropped = false;
	public bool $lookup_index = false;
	/** @var array<int, array<string, mixed>> */
	public array $blocklist_rows = array();
	/** @var array<int, array<string, mixed>> */
	public array $activity_rows = array();

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

	public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4'; }
	public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }

	public function get_row( string $query ): ?object {
		if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
			preg_match( "/LIKE '([^']+)'/", $query, $column );
			return (object) array( 'Field' => $column[1] ?? 'matched_identifiers' );
		}
		if ( str_starts_with( $query, 'SHOW INDEX' ) ) {
			if ( str_contains( $query, "Key_name = 'order_event_type_lookup'" ) ) {
				return $this->lookup_index ? (object) array( 'Non_unique' => 1 ) : null;
			}
			if ( str_contains( $query, "Key_name = 'deduplication_key'" ) ) {
				return (object) array( 'Non_unique' => 0 );
			}
			return $this->legacy_unique_index ? (object) array( 'Non_unique' => 0 ) : null;
		}
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			$row = $this->blocklist_rows[ (int) $match[1] ] ?? null;
			return $row ? (object) $row : null;
		}
		if ( preg_match( "/type = '([^']+)' AND normalized_value = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->blocklist_rows as $row ) {
				if ( $row['type'] === stripslashes( $match[1] ) && $row['normalized_value'] === stripslashes( $match[2] ) ) {
					return (object) $row;
				}
			}
		}
		return null;
	}

	public function get_var( string $query ): mixed {
		if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
			return str_contains( $query, 'activity' ) ? 'wp_veridis_fraud_guard_activity' : 'wp_veridis_fraud_guard_blocklist';
		}
		if ( preg_match( "/type = '([^']+)' AND normalized_value = '([^']+)'/", $query, $match ) ) {
			preg_match( '/id != (\d+)/', $query, $excluded );
			foreach ( $this->blocklist_rows as $row ) {
				if (
					$row['type'] === stripslashes( $match[1] )
					&& $row['normalized_value'] === stripslashes( $match[2] )
					&& ( ! $excluded || $row['id'] !== (int) $excluded[1] )
				) {
					return $row['id'];
				}
			}
		}
		return null;
	}

	/** @return string[] */
	public function get_col( string $query ): array {
		if ( str_contains( $query, 'normalized_value' ) ) {
			preg_match_all( "/type = '([^']+)' AND normalized_value = '([^']+)'/", $query, $matches, PREG_SET_ORDER );
			$types = array();
			foreach ( $matches as $match ) {
				foreach ( $this->blocklist_rows as $row ) {
					if ( $row['type'] === stripslashes( $match[1] ) && $row['normalized_value'] === stripslashes( $match[2] ) ) {
						$types[] = $row['type'];
					}
				}
			}
			return array_values( array_unique( $types ) );
		}
		return array_values( array_unique( array_column( $this->blocklist_rows, 'type' ) ) );
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data, array $formats = array() ): int|false {
		unset( $formats );
		if ( str_ends_with( $table, 'veridis_fraud_guard_activity' ) ) {
			if ( $this->fail_activity_insert ) return false;
			$this->activity_rows[] = $data;
			return 1;
		}
		if ( '' !== $this->fail_blocklist_type && $this->fail_blocklist_type === ( $data['type'] ?? '' ) ) {
			return false;
		}

		$this->insert_id = $this->blocklist_rows ? max( array_keys( $this->blocklist_rows ) ) + 1 : 1;
		$data['id']      = $this->insert_id;
		$this->blocklist_rows[ $this->insert_id ] = $data;
		return 1;
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where */
	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		unset( $formats, $where_formats );
		if ( $this->options === $table && isset( $where['option_name'], $where['option_value'] ) ) {
			$current = $GLOBALS['vfg_test_options'][ $where['option_name'] ] ?? null;
			if ( $current !== $where['option_value'] ) {
				return 0;
			}
			$GLOBALS['vfg_test_options'][ $where['option_name'] ] = $data['option_value'];
			return 1;
		}
		$id = (int) $where['id'];
		if ( ! isset( $this->blocklist_rows[ $id ] ) ) return false;
		$this->blocklist_rows[ $id ] = array_merge( $this->blocklist_rows[ $id ], $data );
		return 1;
	}

	/** @param array<string, mixed> $where */
	public function delete( string $table, array $where, array $formats = array() ): int|false {
		unset( $formats );
		if ( $this->options === $table && isset( $where['option_name'], $where['option_value'] ) ) {
			$current = $GLOBALS['vfg_test_options'][ $where['option_name'] ] ?? null;
			if ( $current !== $where['option_value'] ) {
				return 0;
			}
			unset( $GLOBALS['vfg_test_options'][ $where['option_name'] ] );
			return 1;
		}
		$id = (int) $where['id'];
		if ( ! isset( $this->blocklist_rows[ $id ] ) ) return 0;
		unset( $this->blocklist_rows[ $id ] );
		return 1;
	}

	public function query( string $query ): int|false {
		if ( str_contains( $query, 'DROP INDEX `order_event_type`' ) ) {
			$this->legacy_unique_index = false;
			$this->legacy_index_dropped = true;
			return 1;
		}
		return false;
	}
}

class WC_Order {
	/** @var array<string, string> */
	private array $meta;

	public function __construct(
		private int $id,
		private string $email,
		private string $phone = '',
		private string $ip = '',
		private string $status = 'pending',
		array $meta = array()
	) {
		$this->meta = $meta;
	}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return (string) $this->id; }
	public function get_billing_email(): string { return $this->email; }
	public function get_billing_phone(): string { return $this->phone; }
	public function get_customer_ip_address(): string { return $this->ip; }
	public function get_meta( string $key, bool $single = true ): string {
		unset( $single );
		return $this->meta[ $key ] ?? '';
	}
	public function has_status( string $status ): bool { return $this->status === $status; }
}

require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistEntry.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/BlocklistActivityRecorder.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistService.php';
require_once __DIR__ . '/../../src/PHP/Orders/OrderIdentityResolver.php';
require_once __DIR__ . '/../../src/PHP/Orders/OrderBlocklistManager.php';
require_once __DIR__ . '/../../src/PHP/Admin/OrderPanel.php';
require_once __DIR__ . '/../../src/PHP/Core/Installer.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Admin\OrderPanel;
use Veridis\FraudGuard\Core\Installer;
use Veridis\FraudGuard\Orders\OrderBlocklistManager;
use Veridis\FraudGuard\Orders\OrderIdentityResolver;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}

/** @return array{wpdb, BlocklistService} */
function vfg_service(): array {
	$database = new wpdb();
	$activity = new BlocklistActivityRecorder( new ActivityRepository( $database ) );
	$service  = new BlocklistService( new BlocklistRepository( $database ), new BlocklistNormalizer(), $activity );
	return array( $database, $service );
}

[ $database, $service ] = vfg_service();
$created = $service->create( array( 'type' => 'email', 'value' => 'blocked@example.com', 'note' => 'Manual' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( ! is_wp_error( $created ) && 1 === count( $database->activity_rows ), 'Successful administrative add was not logged once.' );
vfg_assert( 'blocklist' === $database->activity_rows[0]['event_type'] && 'added' === $database->activity_rows[0]['outcome'] && 'admin' === $database->activity_rows[0]['source'], 'Administrative add metadata is incorrect.' );

$duplicate = $service->create( array( 'type' => 'email', 'value' => 'blocked@example.com' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( is_wp_error( $duplicate ) && 1 === count( $database->activity_rows ), 'Duplicate add created activity.' );
$invalid = $service->create( array( 'type' => 'email', 'value' => 'not-an-email' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( is_wp_error( $invalid ) && 1 === count( $database->activity_rows ), 'Invalid add created activity.' );

$edited = $service->update( 1, array( 'note' => 'Changed' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( ! is_wp_error( $edited ) && 2 === count( $database->activity_rows ) && 'edited' === $database->activity_rows[1]['outcome'], 'Successful edit was not logged.' );
$unchanged = $service->update( 1, array( 'note' => 'Changed' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( ! is_wp_error( $unchanged ) && 2 === count( $database->activity_rows ), 'No-op edit created activity.' );

$deleted = $service->delete( 1, BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( true === $deleted && 3 === count( $database->activity_rows ) && 'unblocked' === $database->activity_rows[2]['outcome'], 'Successful unblock was not logged.' );
vfg_assert( is_wp_error( $service->delete( 1, BlocklistActivityRecorder::SOURCE_ADMIN ) ) && 3 === count( $database->activity_rows ), 'Repeated unblock created activity.' );

$serialized = json_encode( $database->activity_rows );
vfg_assert( false !== $serialized && ! str_contains( $serialized, 'blocked@example.com' ) && ! str_contains( $serialized, 'Manual' ), 'Raw blocklist data leaked into activity.' );

[ $failed_database, $failed_service ] = vfg_service();
$failed_database->fail_activity_insert = true;
$successful_without_audit = $failed_service->create( array( 'type' => 'phone', 'value' => '+40 712 345 678' ), BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( ! is_wp_error( $successful_without_audit ) && 1 === count( $failed_database->blocklist_rows ) && array() === $failed_database->activity_rows, 'Audit failure changed a successful blocklist operation.' );
$deleted_without_audit = $failed_service->delete( 1, BlocklistActivityRecorder::SOURCE_ADMIN );
vfg_assert( true === $deleted_without_audit && array() === $failed_database->blocklist_rows, 'Audit failure prevented a successful unblock.' );

// Checkout match snapshots are detached from Blocklist rows and survive unblocking.
[ $history_database, $history_service ] = vfg_service();
$history_service->create( array( 'type' => 'email', 'value' => 'historical@example.com' ) );
( new ActivityRepository( $history_database ) )->insert(
	array(
		'event_type'          => 'warn',
		'outcome'             => 'flagged',
		'source'              => 'classic',
		'order_id'            => 77,
		'matched_types'       => 'email',
		'matched_identifiers' => '[{"type":"email","value":"historical@example.com"}]',
	)
);
$history_service->delete( 1 );
vfg_assert( array() === $history_database->blocklist_rows, 'Historical test Blocklist row was not deleted.' );
vfg_assert( str_contains( (string) $history_database->activity_rows[0]['matched_identifiers'], 'historical@example.com' ), 'Deleting a Blocklist row erased its historical checkout identifier.' );

[ $order_database, $order_service ] = vfg_service();
$manager = new OrderBlocklistManager( $order_service, new OrderIdentityResolver() );
$order   = new WC_Order( 42, 'order@example.com' );
$blocked = $manager->block( $order, 'email' );
vfg_assert( ! is_wp_error( $blocked ) && 1 === count( $order_database->activity_rows ), 'Order-panel Block was not logged.' );
vfg_assert( 'order_panel' === $order_database->activity_rows[0]['source'] && 42 === $order_database->activity_rows[0]['order_id'], 'Order-panel metadata is incorrect.' );
$manager->block( $order, 'email' );
vfg_assert( 1 === count( $order_database->activity_rows ), 'Already-blocked order action created duplicate activity.' );
$manager->unblock( $order, 'email' );
vfg_assert( 2 === count( $order_database->activity_rows ) && 'unblocked' === $order_database->activity_rows[1]['outcome'], 'Order-panel Unblock was not logged.' );
$manager->unblock( $order, 'email' );
vfg_assert( 2 === count( $order_database->activity_rows ), 'Already-unblocked order action created duplicate activity.' );

// Bulk customer actions are limited to individual identifiers and never include the derived email domain.
[ $bulk_database, $bulk_service ] = vfg_service();
$bulk_manager = new OrderBlocklistManager( $bulk_service, new OrderIdentityResolver() );
$bulk_order   = new WC_Order( 60, 'customer@example.com', '+40 712 345 678', '203.0.113.60' );
$bulk_result  = $bulk_manager->block_customer( $bulk_order );
$bulk_types   = array_values( array_column( $bulk_database->blocklist_rows, 'type' ) );
vfg_assert( ! is_wp_error( $bulk_result ) && 3 === $bulk_result['added_count'] && 0 === $bulk_result['failed_count'], 'Bulk customer block counts are incorrect.' );
vfg_assert( array( 'email', 'phone', 'ip' ) === $bulk_types, 'Bulk customer block included a non-individual identifier or omitted a valid individual identifier.' );
vfg_assert( null === $bulk_service->find( 'domain', 'example.com' ), 'Bulk customer block created the derived email-domain entry.' );
$domain_state = array_values( array_filter( $bulk_result['identifiers'], static fn( array $identifier ): bool => 'domain' === $identifier['type'] ) );
vfg_assert( 1 === count( $domain_state ) && true === $domain_state[0]['actionable'] && false === $domain_state[0]['bulk_actionable'], 'Domain state did not preserve an explicit action while excluding bulk action.' );
$bulk_panel = new OrderPanel( $bulk_manager, __FILE__, '1.0.0' );
ob_start();
$bulk_panel->render( $bulk_order );
$bulk_panel_output = (string) ob_get_clean();
vfg_assert( str_contains( $bulk_panel_output, 'data-primary-action="manage"' ) && str_contains( $bulk_panel_output, 'Manage identifiers' ), 'Order-panel primary action still treats the unblocked domain as bulk work.' );
vfg_assert( str_contains( $bulk_panel_output, 'data-vfg-identifier="domain"' ) && str_contains( $bulk_panel_output, 'data-vfg-type="domain"' ), 'Order-panel explicit domain control was removed.' );

// A different customer at the same provider is unaffected when no domain entry exists.
$other_status = $bulk_manager->status( new WC_Order( 61, 'another@example.com' ) );
vfg_assert( true === $other_status['determined'] && 0 === $other_status['blocked_count'], 'A customer sharing only the email provider was incorrectly blocked.' );

// Shared public email providers receive the same protection against accidental domain creation.
[ $gmail_database, $gmail_service ] = vfg_service();
$gmail_manager = new OrderBlocklistManager( $gmail_service, new OrderIdentityResolver() );
$gmail_result  = $gmail_manager->block_customer( new WC_Order( 62, 'customer@gmail.com' ) );
vfg_assert( ! is_wp_error( $gmail_result ) && 1 === $gmail_result['added_count'], 'Gmail customer email was not bulk blocked.' );
vfg_assert( array( 'email' ) === array_values( array_column( $gmail_database->blocklist_rows, 'type' ) ), 'Bulk customer block created gmail.com.' );

// An intentional domain entry survives removal of every individual customer identifier.
$manual_domain = $bulk_manager->block( $bulk_order, 'domain' );
vfg_assert( ! is_wp_error( $manual_domain ) && 'blocked' === $manual_domain['result'], 'Explicit order-panel domain block failed.' );
foreach ( OrderIdentityResolver::BULK_CUSTOMER_TYPES as $customer_type ) {
	$unblocked = $bulk_manager->unblock( $bulk_order, $customer_type );
	vfg_assert( ! is_wp_error( $unblocked ) && 'unblocked' === $unblocked['result'], 'Individual customer identifier could not be unblocked.' );
}
vfg_assert( null !== $bulk_service->find( 'domain', 'example.com' ), 'Removing customer identifiers deleted the independently created domain entry.' );
$manual_domain_unblock = $bulk_manager->unblock( $bulk_order, 'domain' );
vfg_assert( ! is_wp_error( $manual_domain_unblock ) && 'unblocked' === $manual_domain_unblock['result'], 'Explicit order-panel domain unblock failed.' );

// Partial storage failures report persisted successes and failures without attempting a domain.
[ $partial_database, $partial_service ] = vfg_service();
$partial_database->fail_blocklist_type = 'phone';
$partial_manager = new OrderBlocklistManager( $partial_service, new OrderIdentityResolver() );
$partial_result  = $partial_manager->block_customer( new WC_Order( 63, 'partial@example.net', '+40 700 000 063', '203.0.113.63' ) );
vfg_assert( ! is_wp_error( $partial_result ) && 'partially_blocked' === $partial_result['result'], 'Partial bulk failure did not return a coherent result.' );
vfg_assert( 2 === $partial_result['added_count'] && 1 === $partial_result['failed_count'], 'Partial bulk failure counts are incorrect.' );
vfg_assert( array( 'email', 'ip' ) === array_values( array_column( $partial_database->blocklist_rows, 'type' ) ), 'Partial bulk failure persisted an incorrect identifier set.' );
vfg_assert( str_contains( $partial_result['message'], '2 identifiers were added' ) && str_contains( $partial_result['message'], '1 identifier could not be added' ), 'Partial bulk failure message does not match the result.' );

// Order-panel checkout notices use persisted intervention markers, not current settings or matches.
$panel = new OrderPanel( $manager, __FILE__, '1.0.0' );
$render_panel = static function ( WC_Order $render_order ) use ( $panel ): string {
	ob_start();
	$panel->render( $render_order );
	$output = ob_get_clean();
	return is_string( $output ) ? $output : '';
};

$GLOBALS['vfg_test_options']['veridis_fraud_guard_checkout_mode'] = 'off';
$non_cod_output = $render_panel(
	new WC_Order(
		150,
		'non-cod@example.com',
		'',
		'',
		'processing',
		array( '_veridis_fraud_guard_checkout_warning_added' => 'yes' )
	)
);
vfg_assert( str_contains( $non_cod_output, 'Flagged by Fraud Guard' ) && str_contains( $non_cod_output, 'This non-COD order matched the blocklist while Hold mode was active.' ), 'Non-COD Hold marker did not render its historical intervention notice.' );
vfg_assert( str_contains( $non_cod_output, 'Fraud Guard did not change the payment or order status.' ), 'Non-COD Hold notice did not explain unchanged checkout behavior.' );

$GLOBALS['vfg_test_options']['veridis_fraud_guard_checkout_mode'] = 'warn';
vfg_assert( str_contains( $render_panel( new WC_Order( 151, 'persisted@example.com', '', '', 'processing', array( '_veridis_fraud_guard_checkout_warning_added' => 'yes' ) ) ), 'This non-COD order matched the blocklist while Hold mode was active.' ), 'A Settings change hid the historical non-COD Hold notice.' );

$cod_output = $render_panel( new WC_Order( 152, 'cod@example.com', '', '', 'on-hold', array( '_veridis_fraud_guard_cod_hold_applied' => 'yes' ) ) );
vfg_assert( str_contains( $cod_output, 'Held by Fraud Guard' ) && str_contains( $cod_output, 'This COD order was placed on hold because the customer matched the blocklist. Review before shipping.' ), 'Existing COD Hold notice regressed.' );
vfg_assert( ! str_contains( $cod_output, 'This non-COD order matched' ), 'COD Hold incorrectly displayed the non-COD warning notice.' );

$warn_output = $render_panel( new WC_Order( 153, 'warn@example.com', '', '', 'processing', array( '_veridis_fraud_guard_warn_recorded' => 'yes' ) ) );
vfg_assert( str_contains( $warn_output, 'This order matched the blocklist at checkout. Review the customer details before fulfillment.' ), 'Existing Warn notice regressed.' );
vfg_assert( ! str_contains( $warn_output, 'This non-COD order matched' ), 'Ordinary Warn mode incorrectly displayed the non-COD Hold notice.' );

$plain_output = $render_panel( new WC_Order( 154, 'plain@example.com' ) );
vfg_assert( ! str_contains( $plain_output, 'vfg-order-panel__checkout-notice' ), 'Order without an intervention marker displayed a checkout notice.' );
vfg_assert( str_contains( $plain_output, 'data-vfg-action="block"' ) && str_contains( $plain_output, 'Block customer' ), 'Order-panel Block controls regressed.' );

$schema_database = new wpdb();
$schema_database->legacy_unique_index = true;
$schema_database->activity_rows[] = array( 'event_type' => 'hold' );
$GLOBALS['wpdb'] = $schema_database;
Installer::maybe_install();
$schema_sql = implode( "\n", $GLOBALS['vfg_schema_sql'] );
vfg_assert( '5' === get_option( 'veridis_fraud_guard_db_version' ), 'Schema version was not upgraded.' );
vfg_assert( str_contains( $schema_sql, 'matched_identifiers text DEFAULT NULL' ), 'Exact-match Activity schema column is missing.' );
vfg_assert( str_contains( $schema_sql, 'occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1' ) && str_contains( $schema_sql, 'UNIQUE KEY deduplication_key (deduplication_key)' ), 'Activity coalescing schema is incomplete.' );
vfg_assert( str_contains( $schema_sql, 'KEY order_event_type_lookup (order_id, event_type)' ) && ! str_contains( $schema_sql, 'UNIQUE KEY order_event_type' ), 'Activity lookup index schema is incorrect.' );
vfg_assert( $schema_database->legacy_index_dropped, 'Legacy unique Activity index was not removed.' );
vfg_assert( 1 === count( $schema_database->activity_rows ), 'Schema upgrade altered historical activity rows.' );

echo "Blocklist activity harness: PASS\n";
