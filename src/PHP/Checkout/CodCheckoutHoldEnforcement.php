<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

final class CodCheckoutHoldEnforcement {
	private const HOLD_APPLIED_META = '_veridis_fraud_guard_cod_hold_applied';
	private const WARNING_ADDED_META = '_veridis_fraud_guard_checkout_warning_added';
	private const WARN_RECORDED_META = '_veridis_fraud_guard_warn_recorded';

	/** @var array<int, array{object_id: int, source: string, matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>}> */
	private array $pending_cod_holds = array();

	/** @var array<int, string> */
	private array $checkout_sources = array();

	public function __construct(
		private CheckoutBlocklistEvaluator $evaluator,
		private CheckoutActivityRecorder $activity
	) {}

	public function register(): void {
		add_filter( 'woocommerce_cod_process_payment_order_status', array( $this, 'filter_cod_status' ), 999, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'finalize_cod_hold' ), 20, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'inspect_classic_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'inspect_store_api_order' ), 20, 1 );
	}

	public function filter_cod_status( string $status, mixed $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return $status;
		}

		$decision = $this->matched_cod_decision( $order );
		if ( null === $decision ) {
			return $status;
		}

		$order_id = $order->get_id();
		$source   = $this->checkout_sources[ $order_id ] ?? '';
		$this->pending_cod_holds[ $order_id ] = array(
			'object_id'           => spl_object_id( $order ),
			'source'              => $source,
			'matched_types'       => $decision['matched_types'],
			'matched_identifiers' => $decision['matched_identifiers'],
		);

		return 'on-hold';
	}

	public function finalize_cod_hold( int $order_id, string $from_status, string $to_status, mixed $order ): void {
		unset( $from_status );

		$pending = $this->pending_cod_holds[ $order_id ] ?? null;
		if ( ! $order instanceof \WC_Order || null === $pending || $pending['object_id'] !== spl_object_id( $order ) ) {
			return;
		}

		// Consume the request-local intent for any resulting transition so it cannot be reused later.
		unset( $this->pending_cod_holds[ $order_id ] );
		unset( $this->checkout_sources[ $order_id ] );

		if (
			'on-hold' !== $to_status
			|| ! $order->has_status( 'on-hold' )
			|| 'cod' !== $order->get_payment_method()
			|| 'yes' === $order->get_meta( self::HOLD_APPLIED_META, true )
		) {
			return;
		}

		if ( $this->add_note_once(
			$order,
			self::HOLD_APPLIED_META,
			__( 'Veridis Fraud Guard applied Hold because the customer matched the blocklist. Verify this order before fulfillment.', 'veridis-fraud-guard' )
		) ) {
			$this->activity->record_hold( $order_id, $pending['source'], $pending['matched_types'], $pending['matched_identifiers'] );
		}
	}

	/** @param array<string, mixed> $posted_data */
	public function inspect_classic_order( int $order_id, array $posted_data, mixed $order ): void {
		unset( $order_id, $posted_data );
		if ( $order instanceof \WC_Order ) {
			$this->checkout_sources[ $order->get_id() ] = CheckoutActivityRecorder::SOURCE_CLASSIC;
			$this->record_checkout_warning( $order, CheckoutActivityRecorder::SOURCE_CLASSIC );
		}
	}

	public function inspect_store_api_order( \WC_Order $order ): void {
		$this->checkout_sources[ $order->get_id() ] = CheckoutActivityRecorder::SOURCE_BLOCKS;
		$this->record_checkout_warning( $order, CheckoutActivityRecorder::SOURCE_BLOCKS );
	}

	/** @return array{matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>}|null */
	private function matched_cod_decision( \WC_Order $order ): ?array {
		if (
			CheckoutMode::HOLD !== CheckoutMode::current()
			|| 'cod' !== $order->get_payment_method()
			|| 'yes' === $order->get_meta( self::HOLD_APPLIED_META, true )
		) {
			return null;
		}

		$decision = $this->evaluator->evaluate_order( $order );
		return $decision['blocked']
			? array(
				'matched_types'       => $decision['matched_types'],
				'matched_identifiers' => $decision['matched_identifiers'],
			)
			: null;
	}

	private function record_checkout_warning( \WC_Order $order, string $source ): void {
		$mode = CheckoutMode::current();
		if ( CheckoutMode::HOLD === $mode && 'cod' !== $order->get_payment_method() ) {
			$meta_key = self::WARNING_ADDED_META;
			$note     = __( 'Veridis Fraud Guard warning: The customer matched the blocklist. Payment and order status were left unchanged.', 'veridis-fraud-guard' );
		} elseif ( CheckoutMode::WARN === $mode ) {
			$meta_key = self::WARN_RECORDED_META;
			$note     = __( 'Veridis Fraud Guard warning: The customer matched the blocklist at checkout. Review this order before fulfillment.', 'veridis-fraud-guard' );
		} else {
			return;
		}

		if ( 'yes' === $order->get_meta( $meta_key, true ) ) {
			return;
		}

		$decision = $this->evaluator->evaluate_order( $order );
		if ( ! $decision['blocked'] ) {
			return;
		}

		if ( $this->add_note_once( $order, $meta_key, $note ) ) {
			$this->activity->record_warn( $order->get_id(), $source, $decision['matched_types'], $decision['matched_identifiers'] );
		}
	}

	private function add_note_once( \WC_Order $order, string $meta_key, string $note ): bool {
		if ( 'yes' === $order->get_meta( $meta_key, true ) ) {
			return false;
		}

		$order->add_order_note( $note );
		$order->update_meta_data( $meta_key, 'yes' );
		$order->save_meta_data();

		return true;
	}
}
