<?php
namespace Veridis\FraudGuard\Blocklist;

defined( 'ABSPATH' ) || exit;

final class BlocklistNormalizer {
	public const TYPES = array( 'email', 'domain', 'phone', 'ip' );

	/**
	 * @return array{value: string, normalized_value: string}|\WP_Error
	 */
	public function normalize( string $type, string $value ): array|\WP_Error {
		$type  = sanitize_key( $type );
		$value = trim( $value );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return $this->error( 'type', __( 'Choose a supported blocklist type.', 'veridis-fraud-guard' ) );
		}

		if ( '' === $value ) {
			return $this->error( 'value', __( 'Enter a value to block.', 'veridis-fraud-guard' ) );
		}

		if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return $this->error( 'value', __( 'The value contains unsupported control characters.', 'veridis-fraud-guard' ) );
		}

		return match ( $type ) {
			'email'  => $this->email( $value ),
			'domain' => $this->domain( $value ),
			'phone'  => $this->phone( $value ),
			'ip'     => $this->ip( $value ),
		};
	}

	/** @return array{value: string, normalized_value: string}|\WP_Error */
	private function email( string $value ): array|\WP_Error {
		$normalized = strtolower( $value );
		if ( ! is_email( $normalized ) ) {
			return $this->error( 'value', __( 'Enter a valid email address.', 'veridis-fraud-guard' ) );
		}

		return $this->bounded( $normalized, $normalized );
	}

	/** @return array{value: string, normalized_value: string}|\WP_Error */
	private function domain( string $value ): array|\WP_Error {
		$display = strtolower( rtrim( $value, '.' ) );
		$domain  = $display;

		if ( preg_match( '#^([a-z][a-z0-9+.-]*)://#i', $domain, $matches ) ) {
			if ( ! in_array( strtolower( $matches[1] ), array( 'http', 'https' ), true ) ) {
				return $this->error( 'value', __( 'Only a domain name is allowed.', 'veridis-fraud-guard' ) );
			}

			$parts = wp_parse_url( $domain );
			if (
				! is_array( $parts )
				|| empty( $parts['host'] )
				|| ! empty( $parts['query'] )
				|| ! empty( $parts['fragment'] )
				|| ! empty( $parts['user'] )
				|| ! empty( $parts['pass'] )
				|| ! empty( $parts['port'] )
				|| ( isset( $parts['path'] ) && ! in_array( $parts['path'], array( '', '/' ), true ) )
			) {
				return $this->error( 'value', __( 'Enter a domain without a path, query, port, or credentials.', 'veridis-fraud-guard' ) );
			}
			$domain  = strtolower( rtrim( (string) $parts['host'], '.' ) );
			$display = $domain;
		} elseif ( str_contains( $domain, '/' ) || str_contains( $domain, '?' ) || str_contains( $domain, '#' ) || str_contains( $domain, ':' ) ) {
			return $this->error( 'value', __( 'Enter a domain without a path, query, or port.', 'veridis-fraud-guard' ) );
		}

		$ascii = $domain;
		if ( preg_match( '/[^\x20-\x7E]/', $ascii ) ) {
			if ( ! function_exists( 'idn_to_ascii' ) ) {
				return $this->error( 'value', __( 'Internationalized domains are not supported by this server.', 'veridis-fraud-guard' ) );
			}
			$converted = idn_to_ascii( $ascii, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false === $converted ) {
				return $this->error( 'value', __( 'Enter a valid domain name.', 'veridis-fraud-guard' ) );
			}
			$ascii = strtolower( $converted );
		}

		if (
			strlen( $ascii ) > 191
			|| filter_var( $ascii, FILTER_VALIDATE_IP )
			|| ! preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii )
		) {
			return $this->error( 'value', __( 'Enter a valid domain name.', 'veridis-fraud-guard' ) );
		}

		return $this->bounded( $display, $ascii );
	}

	/** @return array{value: string, normalized_value: string}|\WP_Error */
	private function phone( string $value ): array|\WP_Error {
		if ( ! preg_match( '/^\+?[0-9().\-\s]+$/', $value ) ) {
			return $this->error( 'value', __( 'Enter a phone number without letters or an extension.', 'veridis-fraud-guard' ) );
		}

		$international = str_starts_with( $value, '+' );
		$digits        = preg_replace( '/\D+/', '', $value );
		$digits        = is_string( $digits ) ? $digits : '';
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
			return $this->error( 'value', __( 'Enter a phone number containing 7 to 15 digits.', 'veridis-fraud-guard' ) );
		}

		$normalized = ( $international ? '+' : '' ) . $digits;
		return $this->bounded( $value, $normalized );
	}

	/** @return array{value: string, normalized_value: string}|\WP_Error */
	private function ip( string $value ): array|\WP_Error {
		if ( false === filter_var( $value, FILTER_VALIDATE_IP ) ) {
			return $this->error( 'value', __( 'Enter one exact IPv4 or IPv6 address. CIDR ranges and wildcards are not supported.', 'veridis-fraud-guard' ) );
		}

		$binary     = inet_pton( $value );
		$normalized = false === $binary ? false : inet_ntop( $binary );
		if ( false === $normalized ) {
			return $this->error( 'value', __( 'Enter a valid IP address.', 'veridis-fraud-guard' ) );
		}

		return $this->bounded( $normalized, strtolower( $normalized ) );
	}

	/** @return array{value: string, normalized_value: string}|\WP_Error */
	private function bounded( string $value, string $normalized ): array|\WP_Error {
		if ( strlen( $value ) > 255 || strlen( $normalized ) > 191 ) {
			return $this->error( 'value', __( 'The value is too long.', 'veridis-fraud-guard' ) );
		}

		return array( 'value' => $value, 'normalized_value' => $normalized );
	}

	private function error( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'vfg_invalid_blocklist_entry', $message, array( 'status' => 400, 'field' => $field ) );
	}
}
