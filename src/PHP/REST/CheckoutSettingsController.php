<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Checkout\CheckoutMode;
use Veridis\FraudGuard\Checkout\CheckoutRejectionMessage;
use Veridis\FraudGuard\Core\Access;

final class CheckoutSettingsController {
	private const NAMESPACE = 'veridis-fraud-guard/v1';

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings/checkout-protection',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'get' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( Access::class, 'allowed' ),
					'callback'            => array( $this, 'save' ),
					'args'                => array(
						'mode' => array(
							'type'              => 'string',
							'validate_callback' => array( $this, 'validate_mode' ),
						),
						'message' => array(
							'type'              => 'string',
							'validate_callback' => array( $this, 'validate_message' ),
						),
					),
				),
			)
		);
	}

	public function get(): \WP_REST_Response {
		return $this->response();
	}

	public function save( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$has_mode    = $request->has_param( 'mode' );
		$has_message = $request->has_param( 'message' );
		if ( ! $has_mode && ! $has_message ) {
			return new \WP_Error(
				'vfg_checkout_settings_empty_request',
				__( 'Provide a checkout protection mode or customer message to save.', 'veridis-fraud-guard' ),
				array( 'status' => 400 )
			);
		}

		$mode = $request->get_param( 'mode' );
		if ( $has_mode && ! CheckoutMode::is_valid( $mode ) ) {
			return $this->invalid_mode_error();
		}

		$message = $request->get_param( 'message' );
		if ( $has_message ) {
			$message_error = $this->validate_message( $message );
			if ( is_wp_error( $message_error ) ) {
				return $message_error;
			}
			$message = CheckoutRejectionMessage::sanitize( $message );
		}

		if ( $has_mode ) {
			update_option( CheckoutMode::OPTION, $mode, false );
			if ( $mode !== get_option( CheckoutMode::OPTION, null ) ) {
				return $this->save_error();
			}
		}

		if ( $has_message ) {
			update_option( CheckoutRejectionMessage::OPTION, $message, false );
			if ( $message !== get_option( CheckoutRejectionMessage::OPTION, null ) ) {
				return $this->save_error();
			}
		}

		return $this->response();
	}

	public function validate_mode( mixed $mode ): bool|\WP_Error {
		return CheckoutMode::is_valid( $mode ) ? true : $this->invalid_mode_error();
	}

	public function validate_message( mixed $message ): bool|\WP_Error {
		if ( ! is_string( $message ) ) {
			return $this->invalid_message_error( __( 'The customer message must be plain text.', 'veridis-fraud-guard' ) );
		}

		$sanitized = CheckoutRejectionMessage::sanitize( $message );
		if ( '' === $sanitized ) {
			return $this->invalid_message_error( __( 'The customer message cannot be empty.', 'veridis-fraud-guard' ) );
		}

		if ( CheckoutRejectionMessage::length( $sanitized ) > CheckoutRejectionMessage::MAX_LENGTH ) {
			return $this->invalid_message_error(
				sprintf(
					/* translators: %s: localized maximum customer message length. */
					__( 'The customer message cannot exceed %s characters.', 'veridis-fraud-guard' ),
					number_format_i18n( CheckoutRejectionMessage::MAX_LENGTH )
				)
			);
		}

		return true;
	}

	private function invalid_mode_error(): \WP_Error {
		return new \WP_Error(
			'vfg_invalid_checkout_mode',
			__( 'Choose a valid checkout protection mode: Off, Block, Hold, or Warn.', 'veridis-fraud-guard' ),
			array(
				'status' => 400,
				'field'  => 'mode',
			)
		);
	}

	private function invalid_message_error( string $message ): \WP_Error {
		return new \WP_Error(
			'vfg_invalid_checkout_message',
			$message,
			array(
				'status' => 400,
				'field'  => 'message',
			)
		);
	}

	private function save_error(): \WP_Error {
		return new \WP_Error(
			'vfg_checkout_settings_save_failed',
			__( 'Checkout protection settings could not be saved. Please try again.', 'veridis-fraud-guard' ),
			array( 'status' => 500 )
		);
	}

	private function response(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'mode'               => CheckoutMode::current(),
				'message'            => CheckoutRejectionMessage::current(),
				'message_max_length' => CheckoutRejectionMessage::MAX_LENGTH,
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
