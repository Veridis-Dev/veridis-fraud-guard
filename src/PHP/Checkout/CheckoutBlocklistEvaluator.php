<?php
namespace Veridis\FraudGuard\Checkout;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Orders\OrderIdentityResolver;

final class CheckoutBlocklistEvaluator {
	public function __construct(
		private BlocklistService $blocklist,
		private OrderIdentityResolver $resolver
	) {}

	/**
	 * Evaluate validated Classic Checkout identity values.
	 *
	 * The customer IP must be resolved by the server-side caller, never read from
	 * a checkout form field.
	 *
	 * @return array{blocked: bool, matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>, has_usable_identifiers: bool, storage_available: bool}
	 */
	public function evaluate( string $billing_email, string $billing_phone, string $customer_ip ): array {
		return $this->evaluate_identities(
			$this->resolver->resolve_values( $billing_email, $billing_phone, $customer_ip )
		);
	}

	/** @return array{blocked: bool, matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>, has_usable_identifiers: bool, storage_available: bool} */
	public function evaluate_order( \WC_Order $order ): array {
		return $this->evaluate_identities( $this->resolver->resolve( $order ) );
	}

	/**
	 * @param array<string, string> $identities
	 * @return array{blocked: bool, matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>, has_usable_identifiers: bool, storage_available: bool}
	 */
	private function evaluate_identities( array $identities ): array {
		$matches     = $this->blocklist->match_types( $identities );
		$matched_set = array_fill_keys( $matches['matched_types'], true );
		$matched     = array_values(
			array_filter(
				$matches['actionable_types'],
				static fn( string $type ): bool => isset( $matched_set[ $type ] )
			)
		);

		return array(
			'blocked'                => (bool) $matched,
			'matched_types'          => $matched,
			'matched_identifiers'    => $matches['matched_identifiers'],
			'has_usable_identifiers' => (bool) $matches['actionable_types'],
			'storage_available'      => $matches['storage_available'],
		);
	}
}
