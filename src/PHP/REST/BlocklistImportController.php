<?php
namespace Veridis\FraudGuard\REST;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\CSV\BlocklistCsvImporter;
use Veridis\FraudGuard\Core\Access;

final class BlocklistImportController {
	private const NAMESPACE = 'veridis-fraud-guard/v1';

	public function __construct( private BlocklistCsvImporter $importer ) {}

	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/blocklist/import',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'allowed' ),
				'callback'            => array( $this, 'import' ),
			)
		);
	}

	public function allowed( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! Access::allowed() ) {
			return new \WP_Error( 'vfg_forbidden', __( 'You do not have access to import the blocklist.', 'veridis-fraud-guard' ), array( 'status' => 403 ) );
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'vfg_invalid_nonce', __( 'Your session expired. Reload the page and try again.', 'veridis-fraud-guard' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( null === $file ) {
			return $this->upload_error( 'vfg_csv_missing_file', __( 'Choose a CSV file to import.', 'veridis-fraud-guard' ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			return $this->upload_error( 'vfg_csv_upload_failed', $this->upload_message( $error ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return $this->upload_error( 'vfg_csv_invalid_extension', __( 'Choose a file with a .csv extension.', 'veridis-fraud-guard' ) );
		}

		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $path || ! is_uploaded_file( $path ) ) {
			return $this->upload_error( 'vfg_csv_invalid_upload', __( 'The uploaded CSV file is not valid.', 'veridis-fraud-guard' ) );
		}

		$size   = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$result = $this->importer->import( $path, $size );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new \WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	private function upload_message( int $error ): string {
		return match ( $error ) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __( 'The CSV file exceeds the server upload limit.', 'veridis-fraud-guard' ),
			UPLOAD_ERR_PARTIAL => __( 'The CSV file was only partially uploaded.', 'veridis-fraud-guard' ),
			UPLOAD_ERR_NO_FILE => __( 'Choose a CSV file to import.', 'veridis-fraud-guard' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'The server is missing a temporary upload directory.', 'veridis-fraud-guard' ),
			UPLOAD_ERR_CANT_WRITE => __( 'The server could not write the uploaded CSV file.', 'veridis-fraud-guard' ),
			UPLOAD_ERR_EXTENSION => __( 'A server extension stopped the CSV upload.', 'veridis-fraud-guard' ),
			default => __( 'The CSV file could not be uploaded.', 'veridis-fraud-guard' ),
		};
	}

	private function upload_error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400, 'field' => 'file' ) );
	}
}
