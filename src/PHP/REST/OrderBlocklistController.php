<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Core\Access;
use Veridis\FraudGuard\Orders\OrderBlocklistManager;

final class OrderBlocklistController {
	private const NAMESPACE = 'veridis-fraud-guard/v1';

	public function __construct( private OrderBlocklistManager $manager ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/blocklist',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'get' ),
					'args'                => $this->order_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'block_customer' ),
					'args'                => $this->order_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/blocklist/(?P<type>email|phone|ip|domain)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'block' ),
					'args'                => $this->args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'unblock' ),
					'args'                => $this->args(),
				),
			)
		);
	}

	public function get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		return is_wp_error( $order ) ? $order : $this->response( array( 'identifiers' => $this->manager->state( $order ) ) );
	}

	public function block( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$result = $this->manager->block( $order, (string) $request['type'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->response( $result, 'blocked' === $result['result'] ? 201 : 200 );
	}

	public function block_customer( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$result = $this->manager->block_customer( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->response( $result, (int) ( $result['added_count'] ?? 0 ) > 0 ? 201 : 200 );
	}

	public function unblock( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$order = $this->order( (int) $request['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$result = $this->manager->unblock( $order, (string) $request['type'] );
		return is_wp_error( $result ) ? $result : $this->response( $result );
	}

	private function order( int $order_id ): \WC_Order|\WP_Error {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return new \WP_Error(
				'vfg_order_not_found',
				__( 'The WooCommerce order could not be found.', 'veridis-fraud-guard' ),
				array( 'status' => 404 )
			);
		}

		return $order;
	}

	/** @return array<string, array<string, mixed>> */
	private function args(): array {
		return array(
			'id'   => array( 'type' => 'integer', 'minimum' => 1 ),
			'type' => array( 'type' => 'string', 'enum' => BlocklistNormalizer::TYPES ),
		);
	}

	/** @return array<string, array<string, int|string>> */
	private function order_args(): array {
		return array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) );
	}

	private function response( mixed $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
