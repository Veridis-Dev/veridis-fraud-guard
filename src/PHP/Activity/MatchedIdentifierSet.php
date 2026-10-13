<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;

final class MatchedIdentifierSet {
	/**
	 * @param array<int, array{type?: mixed, value?: mixed}> $identifiers
	 * @param string[]|null                                  $allowed_types
	 * @return array<int, array{type: string, value: string}>
	 */
	public static function normalize( array $identifiers, ?array $allowed_types = null ): array {
		$allowed = null === $allowed_types
			? BlocklistNormalizer::TYPES
			: array_values( array_intersect( BlocklistNormalizer::TYPES, $allowed_types ) );
		$result  = array();
		$seen    = array();

		foreach ( $identifiers as $identifier ) {
			if ( ! is_array( $identifier ) || ! isset( $identifier['type'], $identifier['value'] ) || ! is_scalar( $identifier['type'] ) || ! is_scalar( $identifier['value'] ) ) {
				continue;
			}

			$type  = sanitize_key( (string) $identifier['type'] );
			$value = trim( (string) $identifier['value'] );
			$key   = $type . "\0" . $value;
			if (
				! in_array( $type, $allowed, true )
				|| '' === $value
				|| strlen( $value ) > 191
				|| preg_match( '/[\x00-\x1F\x7F]/', $value )
				|| isset( $seen[ $key ] )
			) {
				continue;
			}

			$seen[ $key ] = true;
			$result[]     = array( 'type' => $type, 'value' => $value );
		}

		return $result;
	}

	/** @param array<int, array{type?: mixed, value?: mixed}> $identifiers */
	public static function encode( array $identifiers, ?array $allowed_types = null ): ?string {
		$identifiers = self::normalize( $identifiers, $allowed_types );
		if ( ! $identifiers ) {
			return null;
		}

		$encoded = wp_json_encode( $identifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : null;
	}

	/** @return array<int, array{type: string, value: string}> */
	public static function decode( mixed $encoded, ?array $allowed_types = null ): array {
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return array();
		}

		$decoded = json_decode( $encoded, true );
		return is_array( $decoded ) ? self::normalize( $decoded, $allowed_types ) : array();
	}
}
