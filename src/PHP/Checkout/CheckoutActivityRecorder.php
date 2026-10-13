<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\MatchedIdentifierSet;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;

final class CheckoutActivityRecorder {
	public const SOURCE_CLASSIC = 'classic';
	public const SOURCE_BLOCKS = 'blocks';
	private const BLOCK_DEDUPLICATION_CONTEXT = 'veridis-fraud-guard-block-activity-v1';

	/** @var array<string, true> */
	private array $recorded = array();

	/** @var \WeakMap<object, int> */
	private \WeakMap $attempt_ids;

	private int $next_attempt_id = 0;

	public function __construct( private ActivityRepository $repository ) {
		$this->attempt_ids = new \WeakMap();
	}

	/**
	 * @param string[]                                       $matched_types
	 * @param array<int, array{type: string, value: string}> $matched_identifiers
	 */
	public function record_block( string $source, array $matched_types, array $matched_identifiers, object $attempt ): void {
		if ( ! isset( $this->attempt_ids[ $attempt ] ) ) {
			$this->attempt_ids[ $attempt ] = ++$this->next_attempt_id;
		}

		$this->record_once( 'block:' . $source . ':' . $this->attempt_ids[ $attempt ], 'block', 'rejected', $source, null, $matched_types, $matched_identifiers, true );
	}

	/**
	 * @param string[]                                       $matched_types
	 * @param array<int, array{type: string, value: string}> $matched_identifiers
	 */
	public function record_hold( int $order_id, string $source, array $matched_types, array $matched_identifiers ): void {
		if ( $order_id <= 0 ) {
			return;
		}
		$this->record_once( 'hold:' . $order_id, 'hold', 'held', $source, $order_id, $matched_types, $matched_identifiers );
	}

	/**
	 * @param string[]                                       $matched_types
	 * @param array<int, array{type: string, value: string}> $matched_identifiers
	 */
	public function record_warn( int $order_id, string $source, array $matched_types, array $matched_identifiers ): void {
		if ( $order_id <= 0 ) {
			return;
		}
		$this->record_once( 'warn:' . $order_id, 'warn', 'flagged', $source, $order_id, $matched_types, $matched_identifiers );
	}

	/**
	 * @param string[]                                       $matched_types
	 * @param array<int, array{type: string, value: string}> $matched_identifiers
	 */
	private function record_once(
		string $deduplication_key,
		string $event_type,
		string $outcome,
		string $source,
		?int $order_id,
		array $matched_types,
		array $matched_identifiers,
		bool $coalesce_rejected_attempts = false
	): void {
		if (
			isset( $this->recorded[ $deduplication_key ] )
			|| ! in_array( $source, array( self::SOURCE_CLASSIC, self::SOURCE_BLOCKS ), true )
		) {
			return;
		}

		$types = array_values( array_intersect( BlocklistNormalizer::TYPES, array_unique( $matched_types ) ) );
		if ( ! $types ) {
			return;
		}

		// Mark the event before the best-effort insert so a failing database cannot
		// trigger repeated writes during the same checkout request.
		$this->recorded[ $deduplication_key ] = true;

		$encoded_identifiers = MatchedIdentifierSet::encode( $matched_identifiers, $types );
		try {
			if ( $coalesce_rejected_attempts ) {
				$this->repository->record_rejected_attempt(
					$source,
					implode( ',', $types ),
					$encoded_identifiers,
					$this->blocked_attempt_key( $source, $types, $encoded_identifiers )
				);
				return;
			}

			$this->repository->insert(
				array(
					'event_type'          => $event_type,
					'outcome'             => $outcome,
					'source'              => $source,
					'order_id'            => $order_id && $order_id > 0 ? $order_id : null,
					'matched_types'        => implode( ',', $types ),
					'matched_identifiers' => $encoded_identifiers,
				)
			);
		} catch ( \Throwable ) {
			// Activity is observational and must never affect checkout behavior.
		}
	}

	/** @param string[] $types */
	private function blocked_attempt_key( string $source, array $types, ?string $encoded_identifiers ): string {
		$payload = implode(
			"\n",
			array(
				self::BLOCK_DEDUPLICATION_CONTEXT,
				current_time( 'Y-m-d' ),
				$source,
				implode( ',', $types ),
				$encoded_identifiers ?? '',
			)
		);

		return hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}
}
