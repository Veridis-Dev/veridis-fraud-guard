<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

final class CheckoutMode {
	public const OPTION = 'veridis_fraud_guard_checkout_mode';
	public const LEGACY_BLOCK_OPTION = 'veridis_fraud_guard_checkout_block_enabled';

	public const OFF = 'off';
	public const BLOCK = 'block';
	public const HOLD = 'hold';
	public const WARN = 'warn';

	/** @return string[] */
	public static function values(): array {
		return array( self::OFF, self::BLOCK, self::HOLD, self::WARN );
	}

	public static function is_valid( mixed $mode ): bool {
		return is_string( $mode ) && in_array( $mode, self::values(), true );
	}

	public static function current(): string {
		$mode = get_option( self::OPTION, null );
		if ( null === $mode ) {
			return 'yes' === get_option( self::LEGACY_BLOCK_OPTION, 'no' ) ? self::BLOCK : self::OFF;
		}

		return self::is_valid( $mode )
			? $mode
			: self::OFF;
	}
}
