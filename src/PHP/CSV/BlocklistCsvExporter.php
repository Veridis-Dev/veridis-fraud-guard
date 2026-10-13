<?php
namespace Veridis\FraudGuard\CSV;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistEntry;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;

final class BlocklistCsvExporter {
	private const BATCH_SIZE = 500;

	public function __construct( private BlocklistRepository $repository ) {}

	/** @param resource $stream */
	public function export( $stream ): int|\WP_Error {
		if ( ! is_resource( $stream ) ) {
			return $this->write_error();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The caller supplies a validated output stream for incremental CSV delivery.
		if ( false === fwrite( $stream, "\xEF\xBB\xBF" ) || false === $this->write_row( $stream, array( 'type', 'value', 'note' ) ) ) {
			return $this->write_error();
		}

		$after = null;
		$count = 0;
		do {
			$entries = $this->repository->export_batch( self::BATCH_SIZE, $after );
			if ( is_wp_error( $entries ) ) {
				return $this->write_error();
			}
			foreach ( $entries as $entry ) {
				if ( false === $this->write_entry( $stream, $entry ) ) {
					return $this->write_error();
				}
				++$count;
			}
			if ( $entries ) {
				$last  = $entries[ count( $entries ) - 1 ];
				$after = array( 'type' => $last->type, 'normalized_value' => $last->normalized_value, 'id' => $last->id );
			}
		} while ( count( $entries ) === self::BATCH_SIZE );

		return $count;
	}

	/** @param resource $stream */
	private function write_entry( $stream, BlocklistEntry $entry ): bool {
		return $this->write_row(
			$stream,
			array(
				$entry->type,
				SpreadsheetFormulaProtector::escape( $entry->value ),
				SpreadsheetFormulaProtector::escape( $entry->note ),
			)
		);
	}

	/** @param resource $stream
	 *  @param string[] $row
	 */
	private function write_row( $stream, array $row ): bool {
		return false !== fputcsv( $stream, $row, ',', '"', '', "\r\n" );
	}

	private function write_error(): \WP_Error {
		return new \WP_Error( 'vfg_csv_export_failed', __( 'The blocklist export could not be generated.', 'veridis-fraud-guard' ), array( 'status' => 500 ) );
	}
}
