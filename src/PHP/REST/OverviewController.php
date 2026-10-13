<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Core\Access;
use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Checkout\CheckoutMode;

final class OverviewController {
	private string $version;

	public function __construct( string $version, private BlocklistService $blocklist, private ActivityService $activity ) {
		$this->version = $version;
	}

	public function register(): void {
		register_rest_route(
			'veridis-fraud-guard/v1',
			'/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => array( Access::class, 'allowed' ),
				'callback'            => array( $this, 'get' ),
			)
		);
	}

	public function get(): \WP_REST_Response {
		$blocklist = $this->blocklist->summary();
		$response = new \WP_REST_Response(
			array(
				'product'     => 'Veridis Fraud Guard',
				'version'     => $this->version,
				'status'      => 'foundation_ready',
				'woocommerce' => array(
					'active'  => class_exists( 'WooCommerce' ) || function_exists( 'WC' ),
					'version' => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
					'hpos'    => 'compatible',
				),
				'modules'     => array( 'blocklist', 'activity' ),
				'checkout_protection' => array(
					'mode'    => CheckoutMode::current(),
					'storage' => $this->blocklist->storage_health(),
				),
				'blocklist'   => $blocklist,
				'activity'    => $this->activity->dashboard(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
