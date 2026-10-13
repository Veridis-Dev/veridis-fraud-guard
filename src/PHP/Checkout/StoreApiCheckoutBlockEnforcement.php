<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

final class StoreApiCheckoutBlockEnforcement {
	public function __construct(
		private CheckoutBlocklistEvaluator $evaluator,
		private CheckoutActivityRecorder $activity
	) {}

	public function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate' ), 10, 2 );
	}

	public function validate( \WC_Order $order, \WP_REST_Request $request ): void {
		if ( \WP_REST_Server::CREATABLE !== $request->get_method() ) {
			return;
		}

		if ( CheckoutMode::BLOCK !== CheckoutMode::current() ) {
			return;
		}

		$decision = $this->evaluator->evaluate_order( $order );
		if ( ! $decision['blocked'] ) {
			return;
		}
		$this->activity->record_block( CheckoutActivityRecorder::SOURCE_BLOCKS, $decision['matched_types'], $decision['matched_identifiers'], $request );

		throw new RouteException(
			'veridis_fraud_guard_checkout_blocked',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- RouteException serializes sanitized plain text into REST JSON, not HTML.
			CheckoutRejectionMessage::current(),
			400
		);
	}
}
