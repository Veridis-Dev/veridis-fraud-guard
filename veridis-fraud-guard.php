<?php
/**
 * Plugin Name: Veridis Fraud Guard for WooCommerce
 * Plugin URI: https://veridis.dev/products/fraud-guard/
 * Description: WooCommerce checkout protection using a merchant-controlled blocklist with Block, Hold, and Warn modes.
 * Version: 1.0.1
 * Author: Veridis
 * Author URI: https://veridis.dev/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.2
 * WC tested up to: 11.2
 * Text Domain: veridis-fraud-guard
 */

defined( 'ABSPATH' ) || exit;

define( 'VERIDIS_FRAUD_GUARD_VERSION', '1.0.1' );

$veridis_fraud_guard_path = plugin_dir_path( __FILE__ );

spl_autoload_register(
	static function ( string $class ) use ( $veridis_fraud_guard_path ): void {
		$prefix = 'Veridis\\FraudGuard\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$file = $veridis_fraud_guard_path . 'src/PHP/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	array( \Veridis\FraudGuard\Core\Installer::class, 'activate' )
);

register_activation_hook(
	__FILE__,
	array( \Veridis\FraudGuard\Activity\ActivityPrivacy::class, 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( \Veridis\FraudGuard\Activity\ActivityPrivacy::class, 'deactivate' )
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		$plugin = new \Veridis\FraudGuard\Core\Plugin(
			__FILE__,
			VERIDIS_FRAUD_GUARD_VERSION
		);
		$plugin->boot();
	},
	20
);
