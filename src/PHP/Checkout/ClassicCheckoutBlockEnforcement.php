<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

final class ClassicCheckoutBlockEnforcement {
	public const ENABLED_OPTION = CheckoutMode::LEGACY_BLOCK_OPTION;

	public function __construct(
		private CheckoutBlocklistEvaluator $evaluator,
		private CheckoutActivityRecorder $activity
	) {}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
	}

	/** @param array<string, mixed> $data */
	public function validate( array $data, \WP_Error $errors ): void {
		if ( CheckoutMode::BLOCK !== CheckoutMode::current() ) {
			return;
		}

		$email = isset( $data['billing_email'] ) && is_scalar( $data['billing_email'] ) ? (string) $data['billing_email'] : '';
		$phone = isset( $data['billing_phone'] ) && is_scalar( $data['billing_phone'] ) ? (string) $data['billing_phone'] : '';
		$ip    = class_exists( '\WC_Geolocation' ) ? (string) \WC_Geolocation::get_ip_address() : '';

		$decision = $this->evaluator->evaluate( $email, $phone, $ip );
		if ( ! $decision['blocked'] ) {
			return;
		}

		$errors->add(
			'veridis_fraud_guard_checkout_blocked',
			CheckoutRejectionMessage::current()
		);
		$this->activity->record_block( CheckoutActivityRecorder::SOURCE_CLASSIC, $decision['matched_types'], $decision['matched_identifiers'], $errors );
	}
}
