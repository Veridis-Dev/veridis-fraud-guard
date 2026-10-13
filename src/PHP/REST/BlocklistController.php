<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Core\Access;

final class BlocklistController {
	private const NAMESPACE = 'veridis-fraud-guard/v1';

	public function __construct( private BlocklistService $service ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/blocklist',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'list' ),
					'args'                => array(
						'search'   => array( 'type' => 'string', 'default' => '' ),
						'type'     => array( 'type' => 'string', 'default' => '', 'enum' => array_merge( array( '' ), BlocklistNormalizer::TYPES ) ),
						'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
						'per_page' => array( 'type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100 ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'create' ),
					'args'                => $this->write_args( true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/blocklist/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'PATCH',
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'update' ),
					'args'                => $this->write_args( false ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'delete' ),
				),
			)
		);
	}

	public function list( \WP_REST_Request $request ): \WP_REST_Response {
		$data = $this->service->list(
			(string) $request->get_param( 'search' ),
			(string) $request->get_param( 'type' ),
			(int) ( $request->get_param( 'page' ) ?: 1 ),
			(int) ( $request->get_param( 'per_page' ) ?: 25 )
		);

		return $this->response( $data );
	}

	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $this->service->create( $this->body( $request ), BlocklistActivityRecorder::SOURCE_ADMIN );
		return is_wp_error( $data ) ? $data : $this->response( $data, 201 );
	}

	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $this->service->update( (int) $request['id'], $this->body( $request ), BlocklistActivityRecorder::SOURCE_ADMIN );
		return is_wp_error( $data ) ? $data : $this->response( $data );
	}

	public function delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = $this->service->delete( (int) $request['id'], BlocklistActivityRecorder::SOURCE_ADMIN );
		return is_wp_error( $result ) ? $result : $this->response( array( 'deleted' => true, 'id' => (int) $request['id'] ) );
	}

	/** @return array<string, mixed> */
	private function body( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : array();
	}

	private function response( mixed $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** @return array<string, array<string, mixed>> */
	private function write_args( bool $required ): array {
		return array(
			'type'  => array( 'type' => 'string', 'required' => $required, 'enum' => BlocklistNormalizer::TYPES ),
			'value' => array( 'type' => 'string', 'required' => $required ),
			'note'  => array( 'type' => 'string', 'required' => false ),
		);
	}
}
