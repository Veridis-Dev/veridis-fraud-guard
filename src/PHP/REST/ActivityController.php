<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\Core\Access;

final class ActivityController {
	private const NAMESPACE = 'veridis-fraud-guard/v1';

	public function __construct( private ActivityService $service ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/activity',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => array( Access::class, 'allowed' ),
				'callback'            => array( $this, 'list' ),
				'args'                => array(
					'event_type' => array( 'type' => 'string', 'default' => '', 'enum' => ActivityService::FILTERS ),
					'page'       => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
					'per_page'   => array( 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ),
				),
			)
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			$this->service->list(
				(string) $request->get_param( 'event_type' ),
				(int) ( $request->get_param( 'page' ) ?: 1 ),
				(int) ( $request->get_param( 'per_page' ) ?: 20 )
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
