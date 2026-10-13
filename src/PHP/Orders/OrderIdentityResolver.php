<?php
namespace Veridis\FraudGuard\Orders;

defined( 'ABSPATH' ) || exit;

final class OrderIdentityResolver {
	public const BULK_CUSTOMER_TYPES = array( 'email', 'phone', 'ip' );

	/** @return array<string, string> */
	public function resolve( \WC_Order $order ): array {
		return $this->resolve_values(
			(string) $order->get_billing_email(),
			(string) $order->get_billing_phone(),
			(string) $order->get_customer_ip_address()
		);
	}

	/** @return array<string, string> */
	public function resolve_values( string $billing_email, string $billing_phone, string $customer_ip ): array {
		$identities = array();
		$email      = trim( $billing_email );
		$phone      = trim( $billing_phone );
		$ip         = trim( $customer_ip );

		if ( '' !== $email ) {
			$identities['email'] = $email;
			$separator            = strrpos( $email, '@' );
			if ( false !== $separator && $separator < strlen( $email ) - 1 ) {
				$identities['domain'] = substr( $email, $separator + 1 );
			}
		}

		if ( '' !== $phone ) {
			$identities['phone'] = $phone;
		}

		if ( '' !== $ip ) {
			$identities['ip'] = $ip;
		}

		return $identities;
	}

	public function get( \WC_Order $order, string $type ): string|\WP_Error {
		$type       = sanitize_key( $type );
		$identities = $this->resolve( $order );
		if ( ! array_key_exists( $type, $identities ) ) {
			return new \WP_Error(
				'vfg_order_identifier_missing',
				__( 'This order does not contain that identifier.', 'veridis-fraud-guard' ),
				array( 'status' => 400 )
			);
		}

		return $identities[ $type ];
	}
}
