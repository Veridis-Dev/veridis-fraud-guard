<?php
namespace Veridis\FraudGuard\CSV;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistService;

final class BlocklistCsvImporter {
	public const MAX_BYTES = 10 * 1024 * 1024;
	public const MAX_ROWS = 1000;
	public const MAX_DIAGNOSTICS = 100;

	public function __construct( private BlocklistService $service ) {}

	/** @return array<string, mixed>|\WP_Error */
	public function import( string $path, int $size ): array|\WP_Error {
		$file_error = $this->validate_file( $path, $size );
		if ( is_wp_error( $file_error ) ) {
			return $file_error;
		}

		$preflight = $this->preflight( $path );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv() requires a stream for the validated uploaded temporary file.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return $this->file_error( 'vfg_csv_unreadable', __( 'The CSV file could not be read.', 'veridis-fraud-guard' ) );
		}

		$result = array(
			'processed'          => 0,
			'imported'           => 0,
			'duplicates'         => 0,
			'invalid'            => 0,
			'storage_failures'   => 0,
			'diagnostics'        => array(),
			'diagnostics_limited' => false,
		);
		$header_seen = false;
		$data_row    = 0;

		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( $this->blank_row( $row ) ) {
				continue;
			}
			if ( ! $header_seen ) {
				$header_seen = true;
				continue;
			}

			++$data_row;
			++$result['processed'];
			if ( count( $row ) > $preflight['column_count'] ) {
				++$result['invalid'];
				$this->diagnostic( $result, $data_row, 'invalid', 'row', 'vfg_csv_too_many_columns', __( 'The row contains more columns than the CSV header.', 'veridis-fraud-guard' ) );
				continue;
			}

			$input = array(
				'type'  => $this->cell( $row, $preflight['columns']['type'] ),
				'value' => SpreadsheetFormulaProtector::unescape( $this->cell( $row, $preflight['columns']['value'] ) ),
				'note'  => isset( $preflight['columns']['note'] )
					? SpreadsheetFormulaProtector::unescape( $this->cell( $row, $preflight['columns']['note'] ) )
					: '',
			);
			$created = $this->service->create( $input, BlocklistActivityRecorder::SOURCE_ADMIN );
			if ( ! is_wp_error( $created ) ) {
				++$result['imported'];
				continue;
			}

			$code    = $created->get_error_code();
			$data    = $created->get_error_data();
			$field   = is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '';
			$message = $created->get_error_message();
			if ( 'vfg_duplicate_blocklist_entry' === $code ) {
				++$result['duplicates'];
				$this->diagnostic( $result, $data_row, 'duplicate', $field, $code, $message );
			} elseif ( 'vfg_blocklist_database_error' === $code ) {
				++$result['storage_failures'];
				$this->diagnostic( $result, $data_row, 'storage_failure', $field, $code, $message );
			} else {
				++$result['invalid'];
				$this->diagnostic( $result, $data_row, 'invalid', $field, $code, $message );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the CSV stream opened above after bounded row processing.
		fclose( $handle );
		return $result;
	}

	private function validate_file( string $path, int $size ): bool|\WP_Error {
		$actual_size = is_file( $path ) ? filesize( $path ) : false;
		if ( false !== $actual_size ) {
			$size = max( $size, (int) $actual_size );
		}
		if ( $size <= 0 ) {
			return $this->file_error( 'vfg_csv_empty', __( 'Choose a non-empty CSV file.', 'veridis-fraud-guard' ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return $this->file_error( 'vfg_csv_too_large', __( 'The CSV file cannot exceed 10 MB.', 'veridis-fraud-guard' ), 413 );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return $this->file_error( 'vfg_csv_unreadable', __( 'The CSV file could not be read.', 'veridis-fraud-guard' ) );
		}
		return true;
	}

	/** @return array{columns: array<string, int>, column_count: int}|\WP_Error */
	private function preflight( string $path ): array|\WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv() requires a stream for the validated uploaded temporary file.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return $this->file_error( 'vfg_csv_unreadable', __( 'The CSV file could not be read.', 'veridis-fraud-guard' ) );
		}

		$columns = null;
		$count   = 0;
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			if ( $this->blank_row( $row ) ) {
				continue;
			}
			foreach ( $row as $cell ) {
				if ( null !== $cell && 1 !== preg_match( '//u', (string) $cell ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the validated upload stream before returning the UTF-8 error.
					fclose( $handle );
					return $this->file_error( 'vfg_csv_invalid_utf8', __( 'The CSV file must use valid UTF-8 encoding.', 'veridis-fraud-guard' ) );
				}
			}

			if ( null === $columns ) {
				$header = array_map( static fn( mixed $cell ): string => trim( (string) $cell ), $row );
				if ( isset( $header[0] ) ) {
					$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] ) ?? $header[0];
				}
				$columns = $this->validate_header( $header );
				if ( is_wp_error( $columns ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the validated upload stream before returning the header error.
					fclose( $handle );
					return $columns;
				}
				$column_count = count( $header );
				continue;
			}

			++$count;
			if ( $count > self::MAX_ROWS ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the validated upload stream before returning the row-limit error.
				fclose( $handle );
				return $this->file_error( 'vfg_csv_too_many_rows', __( 'The CSV file cannot contain more than 1,000 data rows.', 'veridis-fraud-guard' ), 413 );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the validated upload stream after preflight completes.
		fclose( $handle );

		if ( null === $columns ) {
			return $this->file_error( 'vfg_csv_empty', __( 'Choose a non-empty CSV file.', 'veridis-fraud-guard' ) );
		}

		return array( 'columns' => $columns, 'column_count' => $column_count );
	}

	/** @param string[] $header
	 *  @return array<string, int>|\WP_Error
	 */
	private function validate_header( array $header ): array|\WP_Error {
		$allowed = array( 'type', 'value', 'note' );
		if ( count( $header ) !== count( array_unique( $header ) ) ) {
			return $this->file_error( 'vfg_csv_duplicate_header', __( 'The CSV header cannot contain duplicate columns.', 'veridis-fraud-guard' ) );
		}
		foreach ( $header as $column ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				return $this->file_error( 'vfg_csv_unknown_header', __( 'The CSV header may contain only type, value, and note columns.', 'veridis-fraud-guard' ) );
			}
		}
		if ( ! in_array( 'type', $header, true ) || ! in_array( 'value', $header, true ) ) {
			return $this->file_error( 'vfg_csv_missing_header', __( 'The CSV header must contain type and value columns.', 'veridis-fraud-guard' ) );
		}

		$columns = array();
		foreach ( $header as $index => $column ) {
			$columns[ $column ] = $index;
		}
		return $columns;
	}

	/** @param array<int, mixed> $row */
	private function blank_row( array $row ): bool {
		return 1 === count( $row ) && ( null === $row[0] || '' === trim( (string) $row[0] ) );
	}

	/** @param array<int, mixed> $row */
	private function cell( array $row, int $index ): string {
		return isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
	}

	/** @param array<string, mixed> $result */
	private function diagnostic( array &$result, int $row, string $category, string $field, string $code, string $message ): void {
		if ( count( $result['diagnostics'] ) >= self::MAX_DIAGNOSTICS ) {
			$result['diagnostics_limited'] = true;
			return;
		}
		$result['diagnostics'][] = array(
			'row'      => $row,
			'category' => $category,
			'field'    => $field,
			'code'     => $code,
			'message'  => $message,
		);
	}

	private function file_error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status, 'field' => 'file' ) );
	}
}
