<?php
namespace Veridis\FraudGuard\Orders;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistService;

final class OrderBlocklistManager {
	public function __construct(
		private BlocklistService $blocklist,
		private OrderIdentityResolver $resolver
	) {}

	/** @return array<int, array{type: string, value: string, blocked: bool, actionable: bool, bulk_actionable: bool}> */
	public function state( \WC_Order $order ): array {
		$state      = array();
		$identities = $this->resolver->resolve( $order );
		$matches    = $this->blocklist->match_types( $identities );
		$actionable = array_fill_keys( $matches['actionable_types'], true );
		$blocked    = array_fill_keys( $matches['matched_types'], true );

		foreach ( $identities as $type => $value ) {
			$is_actionable = isset( $actionable[ $type ] );
			$state[]       = array(
				'type'            => $type,
				'value'           => $value,
				'blocked'         => isset( $blocked[ $type ] ),
				'actionable'      => $is_actionable,
				'bulk_actionable' => $is_actionable && in_array( $type, OrderIdentityResolver::BULK_CUSTOMER_TYPES, true ),
			);
		}

		return $state;
	}

	/** @return array{determined: bool, blocked_count: int, blocked_types: string[]} */
	public function status( \WC_Order $order ): array {
		$state       = $this->state( $order );
		$actionable  = array_filter( $state, static fn( array $identifier ): bool => (bool) $identifier['actionable'] );
		$blocked     = array_filter( $actionable, static fn( array $identifier ): bool => (bool) $identifier['blocked'] );
		$blocked_types = array_values(
			array_map( static fn( array $identifier ): string => (string) $identifier['type'], $blocked )
		);

		return array(
			'determined'    => (bool) $actionable,
			'blocked_count' => count( $blocked_types ),
			'blocked_types' => $blocked_types,
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function block( \WC_Order $order, string $type ): array|\WP_Error {
		$value = $this->resolver->get( $order, $type );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$existing = $this->blocklist->find( $type, $value );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( null !== $existing ) {
			return $this->result( $order, 'already_blocked', __( 'This identifier is already blocked.', 'veridis-fraud-guard' ) );
		}

		$created = $this->blocklist->create(
			array(
				'type'  => $type,
				'value' => $value,
				'note'  => sprintf(
					/* translators: %s: WooCommerce order number. */
					__( 'Blocked from WooCommerce order #%s', 'veridis-fraud-guard' ),
					$order->get_order_number()
				),
			),
			BlocklistActivityRecorder::SOURCE_ORDER_PANEL,
			$order->get_id()
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return $this->result( $order, 'blocked', __( 'Identifier added to the blocklist.', 'veridis-fraud-guard' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function block_customer( \WC_Order $order ): array|\WP_Error {
		$state            = array_values(
			array_filter(
				$this->state( $order ),
				static fn( array $identifier ): bool => (bool) $identifier['bulk_actionable']
			)
		);
		$actionable_count = count( $state );
		$missing = array_filter(
			$state,
			static fn( array $identifier ): bool => ! (bool) $identifier['blocked']
		);

		if ( 0 === $actionable_count ) {
			return $this->result( $order, 'nothing_to_block', __( 'No blockable customer identifiers are available on this order.', 'veridis-fraud-guard' ) );
		}

		if ( ! $missing ) {
			return $this->result( $order, 'already_blocked', __( 'All available customer identifiers are already blocked.', 'veridis-fraud-guard' ) );
		}

		$added       = 0;
		$failed      = 0;
		$first_error = null;
		foreach ( $missing as $identifier ) {
			$result = $this->block( $order, (string) $identifier['type'] );
			if ( is_wp_error( $result ) ) {
				if ( 'vfg_duplicate_blocklist_entry' === $result->get_error_code() ) {
					continue;
				}
				++$failed;
				$first_error ??= $result;
				continue;
			}
			if ( 'blocked' === $result['result'] ) {
				++$added;
			}
		}

		if ( 0 === $added ) {
			if ( $first_error instanceof \WP_Error ) {
				return $first_error;
			}
			return $this->result( $order, 'already_blocked', __( 'All available customer identifiers are already blocked.', 'veridis-fraud-guard' ) );
		}

		if ( $failed > 0 ) {
			$added_message = sprintf(
				/* translators: %s: localized number of identifiers added to the blocklist. */
				_n( '%s identifier was added to the blocklist.', '%s identifiers were added to the blocklist.', $added, 'veridis-fraud-guard' ),
				number_format_i18n( $added )
			);
			$failed_message = sprintf(
				/* translators: %s: localized number of identifiers that could not be added. */
				_n( '%s identifier could not be added.', '%s identifiers could not be added.', $failed, 'veridis-fraud-guard' ),
				number_format_i18n( $failed )
			);

			return $this->bulk_result( $order, $added, $failed, $added_message . ' ' . $failed_message );
		}

		return $this->bulk_result(
			$order,
			$added,
			0,
			sprintf(
				/* translators: %s: localized number of identifiers added to the blocklist. */
				_n( '%s identifier added to the blocklist.', '%s identifiers added to the blocklist.', $added, 'veridis-fraud-guard' ),
				number_format_i18n( $added )
			)
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function unblock( \WC_Order $order, string $type ): array|\WP_Error {
		$value = $this->resolver->get( $order, $type );
		if ( is_wp_error( $value ) ) {
			return $value;
		}

		$existing = $this->blocklist->find( $type, $value );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( null === $existing ) {
			return $this->result( $order, 'already_unblocked', __( 'This identifier is not currently blocked.', 'veridis-fraud-guard' ) );
		}

		$deleted = $this->blocklist->delete(
			(int) $existing['id'],
			BlocklistActivityRecorder::SOURCE_ORDER_PANEL,
			$order->get_id()
		);
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return $this->result( $order, 'unblocked', __( 'Identifier removed from the blocklist.', 'veridis-fraud-guard' ) );
	}

	/** @return array<string, mixed> */
	private function result( \WC_Order $order, string $result, string $message ): array {
		return array(
			'result'      => $result,
			'message'     => $message,
			'identifiers' => $this->state( $order ),
		);
	}

	/** @return array<string, mixed> */
	private function bulk_result( \WC_Order $order, int $added, int $failed, string $message ): array {
		return array(
			'result'       => $failed > 0 ? 'partially_blocked' : 'blocked',
			'message'      => $message,
			'added_count'  => $added,
			'failed_count' => $failed,
			'identifiers'  => $this->state( $order ),
		);
	}
}
