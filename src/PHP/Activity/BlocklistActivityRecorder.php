<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;

final class BlocklistActivityRecorder {
	public const SOURCE_ADMIN = 'admin';
	public const SOURCE_ORDER_PANEL = 'order_panel';

	private const EVENT_TYPE = 'blocklist';
	private const ACTIONS = array( 'added', 'edited', 'unblocked' );

	public function __construct( private ActivityRepository $repository ) {}

	public function record( string $action, string $source, string $identifier_type, ?int $order_id = null ): void {
		if (
			! in_array( $action, self::ACTIONS, true )
			|| ! in_array( $source, array( self::SOURCE_ADMIN, self::SOURCE_ORDER_PANEL ), true )
			|| ! in_array( $identifier_type, BlocklistNormalizer::TYPES, true )
		) {
			return;
		}

		try {
			$this->repository->insert(
				array(
					'event_type'    => self::EVENT_TYPE,
					'outcome'       => $action,
					'source'        => $source,
					'order_id'      => $order_id && $order_id > 0 ? $order_id : null,
					'matched_types' => $identifier_type,
				)
			);
		} catch ( \Throwable ) {
			// Audit persistence is best-effort and must never reverse a completed mutation.
		}
	}
}
