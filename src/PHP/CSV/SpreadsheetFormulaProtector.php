<?php
namespace Veridis\FraudGuard\CSV;

defined( 'ABSPATH' ) || exit;

final class SpreadsheetFormulaProtector {
	private const DANGEROUS_PREFIXES = array( '=', '+', '-', '@', "\t", "\r" );

	public static function escape( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( "'" === $value[0] || in_array( $value[0], self::DANGEROUS_PREFIXES, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	public static function unescape( string $value ): string {
		if ( strlen( $value ) < 2 || "'" !== $value[0] ) {
			return $value;
		}

		if ( "'" === $value[1] || in_array( $value[1], self::DANGEROUS_PREFIXES, true ) ) {
			return substr( $value, 1 );
		}

		return $value;
	}
}
