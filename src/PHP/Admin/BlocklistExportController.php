<?php
namespace Veridis\FraudGuard\Admin;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\CSV\BlocklistCsvExporter;
use Veridis\FraudGuard\Core\Access;

final class BlocklistExportController {
	public const ACTION = 'veridis_fraud_guard_export_blocklist';
	public const NONCE_ACTION = 'veridis_fraud_guard_export_blocklist';

	public function __construct( private BlocklistCsvExporter $exporter ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	public function download(): never {
		$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$authorization = $this->allowed( $nonce );
		if ( is_wp_error( $authorization ) ) {
			wp_die( esc_html( $authorization->get_error_message() ), '', array( 'response' => 403 ) );
		}

		$filename = 'veridis-fraud-guard-blocklist-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );

		$stream = fopen( 'php://output', 'wb' );
		if ( false === $stream ) {
			wp_die( esc_html__( 'The blocklist export could not be generated.', 'veridis-fraud-guard' ), '', array( 'response' => 500 ) );
		}
		$result = $this->exporter->export( $stream );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the php://output stream after the incremental CSV export completes.
		fclose( $stream );
		if ( is_wp_error( $result ) ) {
			exit;
		}
		exit;
	}

	public function allowed( string $nonce ): bool|\WP_Error {
		if ( ! Access::allowed() ) {
			return new \WP_Error( 'vfg_export_forbidden', __( 'You do not have access to export the blocklist.', 'veridis-fraud-guard' ), array( 'status' => 403 ) );
		}
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new \WP_Error( 'vfg_export_invalid_nonce', __( 'Your export link expired. Reload the page and try again.', 'veridis-fraud-guard' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
