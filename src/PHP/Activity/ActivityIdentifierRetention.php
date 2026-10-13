<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

final class ActivityIdentifierRetention {
	public const DEFAULT_DAYS = 90;

	public function __construct( private ?\DateTimeImmutable $reference_now = null ) {}

	public function days(): int {
		/**
		 * Filters how many days exact checkout-match identifiers are retained.
		 * Event metadata and matched identifier types are retained indefinitely.
		 */
		return max( 1, (int) apply_filters( 'veridis_fraud_guard_activity_identifier_retention_days', self::DEFAULT_DAYS ) );
	}

	public function cutoff( ?\DateTimeImmutable $now = null ): string {
		$utc = new \DateTimeZone( 'UTC' );
		$now = $now ?? $this->reference_now ?? new \DateTimeImmutable( 'now', $utc );
		$now = $now->setTimezone( $utc );

		return $now->modify( '-' . $this->days() . ' days' )->format( 'Y-m-d H:i:s' );
	}

	public function identifiers_are_current( string $created_at, string $cutoff ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return false;
		}

		return $created_at >= $cutoff;
	}
}
