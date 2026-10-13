<?php
namespace Veridis\FraudGuard\Core;

defined( 'ABSPATH' ) || exit;

final class Access {
	public const CAPABILITY = 'manage_woocommerce';

	public static function allowed(): bool {
		return current_user_can( self::CAPABILITY );
	}
}
