<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

final class CheckoutRejectionMessage {
	public const OPTION = 'veridis_fraud_guard_checkout_rejection_message';
	public const DEFAULT_MESSAGE = 'We cannot process your order at this time. Please contact us for assistance.';
	public const MAX_LENGTH = 500;

	public static function current(): string {
		$message = get_option( self::OPTION, null );
		if ( ! is_string( $message ) ) {
			return self::default_message();
		}

		$message = self::sanitize( $message );
		return self::is_valid( $message ) ? $message : self::default_message();
	}

	public static function default_message(): string {
		// Keep the literal here so WordPress gettext tooling can extract it.
		return __( 'We cannot process your order at this time. Please contact us for assistance.', 'veridis-fraud-guard' );
	}

	public static function sanitize( string $message ): string {
		return trim( sanitize_textarea_field( $message ) );
	}

	public static function is_valid( mixed $message ): bool {
		if ( ! is_string( $message ) ) {
			return false;
		}

		$message = self::sanitize( $message );
		return '' !== $message && self::length( $message ) <= self::MAX_LENGTH;
	}

	public static function length( string $message ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
	}
}
