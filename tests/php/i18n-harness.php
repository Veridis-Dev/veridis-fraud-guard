<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_i18n_options'] = array();
$GLOBALS['vfg_i18n_calls']   = array();

function __( string $message, string $domain = 'default' ): string {
	$GLOBALS['vfg_i18n_calls'][] = array( $message, $domain );
	if ( 'We cannot process your order at this time. Please contact us for assistance.' === $message ) {
		return 'Translated default checkout message';
	}

	return $message;
}

function _x( string $message, string $context, string $domain = 'default' ): string {
	$GLOBALS['vfg_i18n_calls'][] = array( $message, $domain, $context );
	return $message;
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	unset( $single, $plural, $domain );
	return match ( $number ) {
		1       => '%d singular-form identifier',
		2       => '%d few-form identifiers',
		default => '%d plural-form identifiers',
	};
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['vfg_i18n_options'][ $key ] ?? $default;
}

function sanitize_textarea_field( string $message ): string {
	return trim( strip_tags( $message ) );
}

function number_format_i18n( int|float $number, int $decimals = 0 ): string {
	return number_format( $number, $decimals, '.', ',' );
}

require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutRejectionMessage.php';
require_once __DIR__ . '/../../src/PHP/Admin/OrderPanel.php';

use Veridis\FraudGuard\Admin\OrderPanel;
use Veridis\FraudGuard\Checkout\CheckoutRejectionMessage;

function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true )
		);
	}
}

assert_same(
	'Translated default checkout message',
	CheckoutRejectionMessage::current(),
	'The built-in checkout message should pass through gettext.'
);

$calls_before = count( $GLOBALS['vfg_i18n_calls'] );
$GLOBALS['vfg_i18n_options'][ CheckoutRejectionMessage::OPTION ] = 'Merchant-authored checkout message';
assert_same(
	'Merchant-authored checkout message',
	CheckoutRejectionMessage::current(),
	'A merchant-authored checkout message must not be translated.'
);
assert_same(
	$calls_before,
	count( $GLOBALS['vfg_i18n_calls'] ),
	'Gettext must not receive the merchant-authored checkout message.'
);

$panel_reflection = new ReflectionClass( OrderPanel::class );
$panel            = $panel_reflection->newInstanceWithoutConstructor();
$labels_method    = $panel_reflection->getMethod( 'blocked_status_labels' );
$labels           = $labels_method->invoke( $panel );

assert_same(
	array(
		'No blocked identifiers',
		'1 singular-form identifier',
		'2 few-form identifiers',
		'3 plural-form identifiers',
		'4 plural-form identifiers',
	),
	$labels,
	'Every possible Order Panel count should be selected by WordPress plural rules on the server.'
);

$rejection_source = (string) file_get_contents( __DIR__ . '/../../src/PHP/Checkout/CheckoutRejectionMessage.php' );
if ( ! str_contains( $rejection_source, "__( 'We cannot process your order at this time. Please contact us for assistance.', 'veridis-fraud-guard' )" ) ) {
	throw new RuntimeException( 'The default checkout message is not a statically extractable gettext literal.' );
}

$panel_script = (string) file_get_contents( __DIR__ . '/../../src/OrderPanel/main.ts' );
if ( str_contains( $panel_script, 'oneBlocked' ) || str_contains( $panel_script, 'manyBlocked' ) ) {
	throw new RuntimeException( 'The Order Panel still assumes English one/many plural rules.' );
}

$order_panel_source = (string) file_get_contents( __DIR__ . '/../../src/PHP/Admin/OrderPanel.php' );
if ( ! str_contains( $order_panel_source, "_x( 'Block', 'verb: add an identifier to the blocklist', 'veridis-fraud-guard' )" ) ) {
	throw new RuntimeException( 'The ambiguous Order Panel Block action is missing gettext context.' );
}

echo "i18n harness passed\n";
