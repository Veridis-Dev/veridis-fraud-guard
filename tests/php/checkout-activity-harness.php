<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	final class RouteException extends \RuntimeException {
		public function __construct( string $error_code, string $message, int $status ) {
			unset( $error_code );
			parent::__construct( $message, $status );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/../../' );

	/** @var array<string, mixed> */
	$GLOBALS['vfg_test_options'] = array();
	$GLOBALS['vfg_test_now']     = '2026-10-09 12:00:00';

	function __( string $message, string $domain = '' ): string {
		unset( $domain );
		return $message;
	}

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['vfg_test_options'][ $key ] ?? $default;
	}

	function current_time( string $type, bool $gmt = false ): string {
		unset( $gmt );
		return 'Y-m-d' === $type ? substr( $GLOBALS['vfg_test_now'], 0, 10 ) : $GLOBALS['vfg_test_now'];
	}

	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-only-secret-' . $scheme;
	}

	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}

	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
	}

	function sanitize_textarea_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function is_email( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	function wp_parse_url( string $value ): array|false {
		return parse_url( $value );
	}

	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}

	final class WP_Error {
		/** @var array<string, string[]> */
		public array $errors = array();

		public function __construct( string $code = '', string $message = '', mixed $data = null ) {
			unset( $data );
			if ( '' !== $code ) {
				$this->add( $code, $message );
			}
		}

		public function add( string $code, string $message ): void {
			$this->errors[ $code ][] = $message;
		}
	}

	final class wpdb {
		public string $prefix = 'wp_';
		/** @var string[] */
		public array $matching_types = array();
		/** @var array<int, array<string, mixed>> */
		public array $activity_rows = array();
		public bool $fail_activity_insert = false;
		/** @var mixed[] */
		private array $prepared_args = array();

		public function prepare( string $query, mixed ...$args ): string {
			$this->prepared_args = array_slice( $args, 1 );
			return str_replace( '%i', '`' . str_replace( '`', '``', (string) $args[0] ) . '`', $query );
		}

		/** @return string[] */
		public function get_col( string $query ): array {
			unset( $query );
			return $this->matching_types;
		}

		/** @param array<string, mixed> $data */
		public function insert( string $table, array $data, array $formats = array() ): int|false {
			unset( $formats );
			if ( ! str_ends_with( $table, 'veridis_fraud_guard_activity' ) || $this->fail_activity_insert ) {
				return false;
			}

			if ( null !== $data['order_id'] ) {
				foreach ( $this->activity_rows as $row ) {
					if ( $row['order_id'] === $data['order_id'] && $row['event_type'] === $data['event_type'] ) {
						return false;
					}
				}
			}

			$data['occurrence_count'] = 1;
			$this->activity_rows[] = $data;
			return 1;
		}

		public function query( string $query ): int|false {
			if ( ! str_contains( $query, 'ON DUPLICATE KEY UPDATE' ) || $this->fail_activity_insert ) {
				return false;
			}

			[ $source, $matched_types, $matched_identifiers, $deduplication_key, $created_at ] = $this->prepared_args;
			foreach ( $this->activity_rows as &$row ) {
				if ( ( $row['deduplication_key'] ?? null ) === $deduplication_key ) {
					$row['occurrence_count'] = (int) ( $row['occurrence_count'] ?? 1 ) + 1;
					$row['matched_identifiers'] = $matched_identifiers;
					$row['created_at'] = $created_at;
					unset( $row );
					return 2;
				}
			}
			unset( $row );

			$this->activity_rows[] = array(
				'event_type'          => 'block',
				'outcome'             => 'rejected',
				'source'              => $source,
				'order_id'            => null,
				'matched_types'        => $matched_types,
				'matched_identifiers' => $matched_identifiers,
				'occurrence_count'     => 1,
				'deduplication_key'    => $deduplication_key,
				'created_at'           => $created_at,
			);
			return 1;
		}
	}

	final class WC_Geolocation {
		public static function get_ip_address(): string {
			return '203.0.113.7';
		}
	}

	final class WP_REST_Server {
		public const CREATABLE = 'POST';
	}

	final class WP_REST_Request {
		public function __construct( private string $method = 'POST' ) {}
		public function get_method(): string { return $this->method; }
	}

	class WC_Order {
		/** @var array<string, string> */
		private array $meta = array();
		/** @var string[] */
		public array $notes = array();

		public function __construct(
			private int $id,
			private string $email,
			private string $phone,
			private string $ip,
			private string $payment_method,
			private string $status = 'pending'
		) {}

		public function get_id(): int { return $this->id; }
		public function get_billing_email(): string { return $this->email; }
		public function get_billing_phone(): string { return $this->phone; }
		public function get_customer_ip_address(): string { return $this->ip; }
		public function get_payment_method(): string { return $this->payment_method; }
		public function has_status( string $status ): bool { return $this->status === $status; }
		public function set_status( string $status ): void { $this->status = $status; }
		public function get_meta( string $key, bool $single = true ): string {
			unset( $single );
			return $this->meta[ $key ] ?? '';
		}
		public function update_meta_data( string $key, string $value ): void { $this->meta[ $key ] = $value; }
		public function save_meta_data(): void {}
		public function add_order_note( string $note ): void { $this->notes[] = $note; }
	}

	require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistEntry.php';
	require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
	require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistRepository.php';
	require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistService.php';
	require_once __DIR__ . '/../../src/PHP/Orders/OrderIdentityResolver.php';
	require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
	require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutMode.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutRejectionMessage.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutBlocklistEvaluator.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutActivityRecorder.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/ClassicCheckoutBlockEnforcement.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/CodCheckoutHoldEnforcement.php';
	require_once __DIR__ . '/../../src/PHP/Checkout/StoreApiCheckoutBlockEnforcement.php';

	use Veridis\FraudGuard\Activity\ActivityRepository;
	use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
	use Veridis\FraudGuard\Blocklist\BlocklistRepository;
	use Veridis\FraudGuard\Blocklist\BlocklistService;
	use Veridis\FraudGuard\Checkout\CheckoutActivityRecorder;
	use Veridis\FraudGuard\Checkout\CheckoutBlocklistEvaluator;
	use Veridis\FraudGuard\Checkout\CheckoutMode;
	use Veridis\FraudGuard\Checkout\CheckoutRejectionMessage;
	use Veridis\FraudGuard\Checkout\ClassicCheckoutBlockEnforcement;
	use Veridis\FraudGuard\Checkout\CodCheckoutHoldEnforcement;
	use Veridis\FraudGuard\Checkout\StoreApiCheckoutBlockEnforcement;
	use Veridis\FraudGuard\Orders\OrderIdentityResolver;

	/** @return array{wpdb, CheckoutActivityRecorder, CheckoutBlocklistEvaluator, ClassicCheckoutBlockEnforcement, CodCheckoutHoldEnforcement, StoreApiCheckoutBlockEnforcement} */
	function vfg_context(): array {
		global $wpdb;
		$wpdb       = new wpdb();
		$normalizer = new BlocklistNormalizer();
		$blocklist  = new BlocklistService( new BlocklistRepository( $wpdb ), $normalizer );
		$evaluator  = new CheckoutBlocklistEvaluator( $blocklist, new OrderIdentityResolver() );
		$activity   = new CheckoutActivityRecorder( new ActivityRepository( $wpdb ) );

		return array(
			$wpdb,
			$activity,
			$evaluator,
			new ClassicCheckoutBlockEnforcement( $evaluator, $activity ),
			new CodCheckoutHoldEnforcement( $evaluator, $activity ),
			new StoreApiCheckoutBlockEnforcement( $evaluator, $activity ),
		);
	}

	function vfg_mode( string $mode ): void {
		$GLOBALS['vfg_test_options'] = array( CheckoutMode::OPTION => $mode );
	}

	function vfg_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	// Block: request-local repeats are skipped; separate identical requests are atomically coalesced.
	[ $database, , $evaluator, $classic ] = vfg_context();
	vfg_mode( CheckoutMode::BLOCK );
	$database->matching_types = array( 'email', 'domain' );
	$errors = new WP_Error();
	$data   = array( 'billing_email' => 'blocked@example.com', 'billing_phone' => '+40 712 345 678' );
	$classic->validate( $data, $errors );
	$classic->validate( $data, $errors );
	vfg_assert( isset( $errors->errors['veridis_fraud_guard_checkout_blocked'] ), 'Blocked checkout was not rejected.' );
	vfg_assert( 1 === count( $database->activity_rows ), 'Block event was not request-deduplicated.' );
	vfg_assert( 'block' === $database->activity_rows[0]['event_type'] && 'rejected' === $database->activity_rows[0]['outcome'], 'Block event semantics are incorrect.' );
	vfg_assert( null === $database->activity_rows[0]['order_id'], 'Rejected checkout persisted an order ID.' );
	vfg_assert( 1 === $database->activity_rows[0]['occurrence_count'], 'A request-local callback repeat changed the occurrence count.' );
	vfg_assert(
		array(
			array( 'type' => 'email', 'value' => 'blocked@example.com' ),
			array( 'type' => 'domain', 'value' => 'example.com' ),
		) === json_decode( (string) $database->activity_rows[0]['matched_identifiers'], true ),
		'Multiple Classic matches were not stored with their canonical values.'
	);
	$classic->validate( $data, new WP_Error() );
	vfg_assert( 1 === count( $database->activity_rows ) && 2 === $database->activity_rows[0]['occurrence_count'], 'A separate Classic attempt was not coalesced and counted.' );

	// Fresh recorder instances model separate PHP requests sharing one database. The unique key and upsert
	// keep identical traffic bounded even when every request starts without in-memory deduplication state.
	for ( $attempt = 0; $attempt < 50; ++$attempt ) {
		$fresh_activity = new CheckoutActivityRecorder( new ActivityRepository( $database ) );
		$fresh_classic  = new ClassicCheckoutBlockEnforcement( $evaluator, $fresh_activity );
		$fresh_errors   = new WP_Error();
		$fresh_classic->validate( $data, $fresh_errors );
		vfg_assert( isset( $fresh_errors->errors['veridis_fraud_guard_checkout_blocked'] ), 'Activity coalescing bypassed Classic checkout blocking.' );
	}
	vfg_assert( 1 === count( $database->activity_rows ) && 52 === $database->activity_rows[0]['occurrence_count'], 'Separate identical requests caused unbounded Activity rows or lost attempt counts.' );
	vfg_assert( 64 === strlen( $database->activity_rows[0]['deduplication_key'] ) && ! str_contains( $database->activity_rows[0]['deduplication_key'], 'blocked@example.com' ), 'Coalescing persisted a raw or predictable identifier key.' );

	// A materially different matched identity remains independently visible.
	$distinct_errors = new WP_Error();
	( new ClassicCheckoutBlockEnforcement( $evaluator, new CheckoutActivityRecorder( new ActivityRepository( $database ) ) ) )->validate(
		array( 'billing_email' => 'different@example.com', 'billing_phone' => '+40 712 345 678' ),
		$distinct_errors
	);
	vfg_assert( isset( $distinct_errors->errors['veridis_fraud_guard_checkout_blocked'] ), 'Distinct Classic checkout was not blocked.' );
	vfg_assert( 2 === count( $database->activity_rows ) && 1 === $database->activity_rows[1]['occurrence_count'], 'A distinct matched identity was incorrectly coalesced.' );
	$GLOBALS['vfg_test_now'] = '2026-10-10 00:00:01';
	$next_day_errors = new WP_Error();
	( new ClassicCheckoutBlockEnforcement( $evaluator, new CheckoutActivityRecorder( new ActivityRepository( $database ) ) ) )->validate( $data, $next_day_errors );
	vfg_assert( isset( $next_day_errors->errors['veridis_fraud_guard_checkout_blocked'] ) && 3 === count( $database->activity_rows ), 'A new site-calendar day was not preserved as a distinct blocked-attempt record.' );

	// Store API rejections use the Blocks source and remain request-deduplicated.
	$GLOBALS['vfg_test_now'] = '2026-10-09 12:00:00';
	[ $database, , $evaluator, , , $store ] = vfg_context();
	vfg_mode( CheckoutMode::BLOCK );
	$database->matching_types = array( 'domain' );
	$order   = new WC_Order( 55, 'blocked@example.com', '', '203.0.113.7', 'stripe', 'checkout-draft' );
	$request = new WP_REST_Request();
	$GLOBALS['vfg_test_options'][ CheckoutRejectionMessage::OPTION ] = "We can't accept A & B.";
	$store_message = '';
	for ( $attempt = 0; $attempt < 2; $attempt++ ) {
		try {
			$store->validate( $order, $request );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException $exception ) {
			$store_message = $exception->getMessage();
			// Expected checkout rejection.
		}
	}
	vfg_assert( "We can't accept A & B." === $store_message, 'Store API rejection message was HTML-escaped or otherwise changed.' );
	vfg_assert( 1 === count( $database->activity_rows ), 'Store API Block event was not request-deduplicated.' );
	vfg_assert( 1 === $database->activity_rows[0]['occurrence_count'], 'Store API request-local repeat changed the occurrence count.' );
	vfg_assert( 'blocks' === $database->activity_rows[0]['source'], 'Store API Block source is incorrect.' );
	for ( $attempt = 0; $attempt < 25; ++$attempt ) {
		$fresh_store = new StoreApiCheckoutBlockEnforcement( $evaluator, new CheckoutActivityRecorder( new ActivityRepository( $database ) ) );
		try {
			$fresh_store->validate( $order, new WP_REST_Request() );
			throw new RuntimeException( 'Activity coalescing bypassed Store API checkout blocking.' );
		} catch ( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException ) {
			// Expected checkout rejection.
		}
	}
	vfg_assert( 1 === count( $database->activity_rows ) && 26 === $database->activity_rows[0]['occurrence_count'], 'Store API did not use the same bounded cross-request recording semantics as Classic checkout.' );
	vfg_assert( null === $database->activity_rows[0]['order_id'], 'Rejected Store API draft ID was persisted.' );
	vfg_assert( array( array( 'type' => 'domain', 'value' => 'example.com' ) ) === json_decode( (string) $database->activity_rows[0]['matched_identifiers'], true ), 'Store API domain match value is incorrect.' );

	// Hold: no event is written at filter time; it is written once after the confirmed transition.
	[ $database, , , , $hold ] = vfg_context();
	vfg_mode( CheckoutMode::HOLD );
	$database->matching_types = array( 'phone' );
	$order = new WC_Order( 101, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'cod' );
	$hold->inspect_classic_order( 101, array(), $order );
	vfg_assert( 'on-hold' === $hold->filter_cod_status( 'processing', $order ), 'Matched COD order was not held.' );
	vfg_assert( array() === $database->activity_rows, 'Hold event was written before the status transition.' );
	$order->set_status( 'on-hold' );
	$hold->finalize_cod_hold( 101, 'pending', 'on-hold', $order );
	$hold->finalize_cod_hold( 101, 'pending', 'on-hold', $order );
	vfg_assert( 1 === count( $database->activity_rows ), 'Hold event was not transition-deduplicated.' );
	vfg_assert( 'hold' === $database->activity_rows[0]['event_type'] && 'held' === $database->activity_rows[0]['outcome'], 'Hold event semantics are incorrect.' );
	vfg_assert( 101 === $database->activity_rows[0]['order_id'], 'Hold event did not retain its order ID.' );
	vfg_assert( array( array( 'type' => 'phone', 'value' => '+40712345678' ) ) === json_decode( (string) $database->activity_rows[0]['matched_identifiers'], true ), 'Hold phone match value is incorrect.' );
	$blocks_order = new WC_Order( 102, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'cod' );
	$hold->inspect_store_api_order( $blocks_order );
	$hold->filter_cod_status( 'processing', $blocks_order );
	$blocks_order->set_status( 'on-hold' );
	$hold->finalize_cod_hold( 102, 'pending', 'on-hold', $blocks_order );
	vfg_assert( 2 === count( $database->activity_rows ) && 'blocks' === $database->activity_rows[1]['source'], 'Blocks Hold source is incorrect.' );

	// Hold with a non-COD payment preserves the order and records a warning, never a held event.
	[ $database, , , , $hold ] = vfg_context();
	vfg_mode( CheckoutMode::HOLD );
	$database->matching_types = array( 'email', 'domain', 'phone', 'ip' );
	$order = new WC_Order( 150, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'stripe' );
	$hold->inspect_classic_order( 150, array(), $order );
	$hold->inspect_classic_order( 150, array(), $order );
	vfg_assert( 'processing' === $hold->filter_cod_status( 'processing', $order ), 'Non-COD Hold changed the payment result status.' );
	vfg_assert( $order->has_status( 'pending' ), 'Non-COD Hold changed the stored order status.' );
	vfg_assert( 1 === count( $order->notes ) && 1 === count( $database->activity_rows ), 'Classic non-COD warning was not deduplicated.' );
	vfg_assert( 'warn' === $database->activity_rows[0]['event_type'] && 'flagged' === $database->activity_rows[0]['outcome'], 'Non-COD Hold was recorded as a held event.' );
	vfg_assert( 'classic' === $database->activity_rows[0]['source'] && 150 === $database->activity_rows[0]['order_id'], 'Classic non-COD warning context is incorrect.' );
	vfg_assert(
		array(
			array( 'type' => 'email', 'value' => 'buyer@example.com' ),
			array( 'type' => 'domain', 'value' => 'example.com' ),
			array( 'type' => 'phone', 'value' => '+40712345678' ),
			array( 'type' => 'ip', 'value' => '203.0.113.7' ),
		) === json_decode( (string) $database->activity_rows[0]['matched_identifiers'], true ),
		'Non-COD Hold did not retain every exact matched identifier.'
	);

	$blocks_order = new WC_Order( 151, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'paypal', 'checkout-draft' );
	$hold->inspect_store_api_order( $blocks_order );
	$hold->inspect_store_api_order( $blocks_order );
	$hold->finalize_cod_hold( 151, 'pending', 'processing', $blocks_order );
	vfg_assert( $blocks_order->has_status( 'checkout-draft' ), 'Blocks non-COD Hold changed the stored order status.' );
	vfg_assert( 1 === count( $blocks_order->notes ) && 2 === count( $database->activity_rows ), 'Blocks non-COD warning was not deduplicated.' );
	vfg_assert( 'warn' === $database->activity_rows[1]['event_type'] && 'flagged' === $database->activity_rows[1]['outcome'] && 'blocks' === $database->activity_rows[1]['source'], 'Blocks non-COD warning semantics are incorrect.' );

	// Warn: the historical marker, note, and event are each applied once.
	[ $database, , , , $warn ] = vfg_context();
	vfg_mode( CheckoutMode::WARN );
	$database->matching_types = array( 'ip' );
	$order = new WC_Order( 202, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'stripe' );
	$warn->inspect_store_api_order( $order );
	$warn->inspect_store_api_order( $order );
	vfg_assert( 1 === count( $database->activity_rows ), 'Warn event was not marker-deduplicated.' );
	vfg_assert( 1 === count( $order->notes ), 'Warn order note was duplicated.' );
	vfg_assert( 'warn' === $database->activity_rows[0]['event_type'] && 'flagged' === $database->activity_rows[0]['outcome'], 'Warn event semantics are incorrect.' );
	vfg_assert( 'blocks' === $database->activity_rows[0]['source'], 'Warn source was not recorded as Blocks.' );
	vfg_assert( array( array( 'type' => 'ip', 'value' => '203.0.113.7' ) ) === json_decode( (string) $database->activity_rows[0]['matched_identifiers'], true ), 'Warn IP match value is incorrect.' );
	$classic_order = new WC_Order( 203, 'buyer@example.com', '+40 712 345 678', '203.0.113.7', 'stripe' );
	$warn->inspect_classic_order( 203, array(), $classic_order );
	$warn->inspect_classic_order( 203, array(), $classic_order );
	vfg_assert( 2 === count( $database->activity_rows ) && 'classic' === $database->activity_rows[1]['source'], 'Classic Warn source or deduplication is incorrect.' );

	// Off and clean checkouts do not create activity.
	[ $database, , , $classic ] = vfg_context();
	$database->matching_types = array( 'email' );
	vfg_mode( CheckoutMode::OFF );
	$classic->validate( $data, new WP_Error() );
	vfg_assert( array() === $database->activity_rows, 'Off mode created activity.' );
	vfg_mode( CheckoutMode::BLOCK );
	$database->matching_types = array();
	$classic->validate( $data, new WP_Error() );
	vfg_assert( array() === $database->activity_rows, 'Clean checkout created activity.' );

	// A failed insert cannot change the rejection decision.
	[ $database, , , $classic ] = vfg_context();
	vfg_mode( CheckoutMode::BLOCK );
	$database->matching_types       = array( 'email' );
	$database->fail_activity_insert = true;
	$errors = new WP_Error();
	$classic->validate( $data, $errors );
	vfg_assert( isset( $errors->errors['veridis_fraud_guard_checkout_blocked'] ), 'Logging failure changed the Block decision.' );
	vfg_assert( array() === $database->activity_rows, 'Failed insert unexpectedly persisted activity.' );

	// The recorder accepts only canonical, typed values and excludes unrelated input.
	[ $database, $activity ] = vfg_context();
	$activity->record_block(
		CheckoutActivityRecorder::SOURCE_CLASSIC,
		array( 'email', 'domain', 'blocked@example.com', '203.0.113.7' ),
		array(
			array( 'type' => 'email', 'value' => 'blocked@example.com' ),
			array( 'type' => 'domain', 'value' => 'example.com' ),
			array( 'type' => 'ip', 'value' => '203.0.113.7' ),
			array( 'type' => 'unsupported', 'value' => 'secret' ),
		),
		new \stdClass()
	);
	$serialized = json_encode( $database->activity_rows );
	vfg_assert( false !== $serialized, 'Activity rows could not be encoded.' );
	vfg_assert( str_contains( $serialized, 'blocked@example.com' ) && str_contains( $serialized, 'example.com' ), 'Exact matched identifiers were not persisted.' );
	vfg_assert( ! str_contains( $serialized, '203.0.113.7' ) && ! str_contains( $serialized, 'secret' ), 'Unmatched or unsupported identifiers were persisted.' );
	vfg_assert( 'email,domain' === $database->activity_rows[0]['matched_types'], 'Matched types were not canonicalized.' );

	echo "Checkout activity harness: PASS\n";
}
