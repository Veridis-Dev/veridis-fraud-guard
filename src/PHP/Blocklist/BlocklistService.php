<?php
namespace Veridis\FraudGuard\Blocklist;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;

final class BlocklistService {
	public function __construct(
		private BlocklistRepository $repository,
		private BlocklistNormalizer $normalizer,
		private ?BlocklistActivityRecorder $activity = null
	) {}

	/** @return array<string, mixed> */
	public function list( string $search = '', string $type = '', int $page = 1, int $per_page = 25 ): array {
		$type     = sanitize_key( $type );
		$search   = sanitize_text_field( $search );
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		if ( ! in_array( $type, array_merge( array( '' ), BlocklistNormalizer::TYPES ), true ) ) {
			$type = '';
		}

		$result      = $this->repository->list( $search, $type, $page, $per_page );
		$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

		return array(
			'items'      => array_map( static fn( BlocklistEntry $entry ): array => $entry->to_array(), $result['items'] ),
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $result['total'],
				'total_pages' => $total_pages,
			),
			'counts'     => $this->repository->summary(),
		);
	}

	/** @param array<string, mixed> $input */
	public function create( array $input, string $activity_source = '', ?int $order_id = null ): array|\WP_Error {
		$data = $this->validated_data( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $this->repository->exists( $data['type'], $data['normalized_value'] ) ) {
			return $this->duplicate_error();
		}

		$result = $this->repository->insert( $data );
		if ( is_wp_error( $result ) ) {
			return $this->repository->exists( $data['type'], $data['normalized_value'] ) ? $this->duplicate_error() : $result;
		}

		$this->record_activity( 'added', $activity_source, $result->type, $order_id );
		return $result->to_array();
	}

	/** @param array<string, mixed> $input */
	public function update( int $id, array $input, string $activity_source = '', ?int $order_id = null ): array|\WP_Error {
		$current = $this->repository->find( $id );
		if ( null === $current ) {
			return $this->not_found_error();
		}

		$data = $this->validated_data(
			array(
				'type'  => $input['type'] ?? $current->type,
				'value' => $input['value'] ?? $current->value,
				'note'  => $input['note'] ?? $current->note,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( $this->repository->exists( $data['type'], $data['normalized_value'], $id ) ) {
			return $this->duplicate_error();
		}

		if (
			$current->type === $data['type']
			&& $current->value === $data['value']
			&& $current->normalized_value === $data['normalized_value']
			&& $current->note === $data['note']
		) {
			return $current->to_array();
		}

		$result = $this->repository->update( $id, $data );
		if ( is_wp_error( $result ) ) {
			return $this->repository->exists( $data['type'], $data['normalized_value'], $id ) ? $this->duplicate_error() : $result;
		}

		$this->record_activity( 'edited', $activity_source, $result->type, $order_id );
		return $result->to_array();
	}

	public function delete( int $id, string $activity_source = '', ?int $order_id = null ): bool|\WP_Error {
		$current = $this->repository->find( $id );
		if ( null === $current ) {
			return $this->not_found_error();
		}

		$result = $this->repository->delete( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return $this->not_found_error();
		}

		$this->record_activity( 'unblocked', $activity_source, $current->type, $order_id );
		return true;
	}

	/** @return array<string, int|string>|\WP_Error|null */
	public function find( string $type, string $value ): array|\WP_Error|null {
		$normalized = $this->normalizer->normalize( $type, $value );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$entry = $this->repository->find_by_normalized( sanitize_key( $type ), $normalized['normalized_value'] );
		return $entry ? $entry->to_array() : null;
	}

	/**
	 * @param array<string, string> $values_by_type
	 * @return array{actionable_types: string[], matched_types: string[], matched_identifiers: array<int, array{type: string, value: string}>, storage_available: bool}
	 */
	public function match_types( array $values_by_type ): array {
		$normalized_by_type = array();
		foreach ( $values_by_type as $type => $value ) {
			$normalized = $this->normalizer->normalize( (string) $type, (string) $value );
			if ( is_wp_error( $normalized ) ) {
				continue;
			}
			$normalized_by_type[ sanitize_key( (string) $type ) ] = $normalized['normalized_value'];
		}

		$matched_types = $this->repository->matching_types( $normalized_by_type );
		if ( is_wp_error( $matched_types ) ) {
			return array(
				'actionable_types'    => array_keys( $normalized_by_type ),
				'matched_types'       => array(),
				'matched_identifiers' => array(),
				'storage_available'   => false,
			);
		}

		$matched_identifiers = array();
		foreach ( BlocklistNormalizer::TYPES as $type ) {
			if ( in_array( $type, $matched_types, true ) && isset( $normalized_by_type[ $type ] ) ) {
				$matched_identifiers[] = array( 'type' => $type, 'value' => $normalized_by_type[ $type ] );
			}
		}

		return array(
			'actionable_types'    => array_keys( $normalized_by_type ),
			'matched_types'       => $matched_types,
			'matched_identifiers' => $matched_identifiers,
			'storage_available'   => true,
		);
	}

	/** @return array{total: int, email: int, domain: int, phone: int, ip: int} */
	public function summary(): array {
		return $this->repository->summary();
	}

	/** @return array{status: string} */
	public function storage_health(): array {
		return $this->repository->storage_health();
	}

	/** @param array<string, mixed> $input
	 *  @return array{type: string, value: string, normalized_value: string, note: string}|\WP_Error
	 */
	private function validated_data( array $input ): array|\WP_Error {
		$type  = isset( $input['type'] ) && is_scalar( $input['type'] ) ? (string) $input['type'] : '';
		$value = isset( $input['value'] ) && is_scalar( $input['value'] ) ? (string) $input['value'] : '';
		$note  = isset( $input['note'] ) && is_scalar( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';

		if ( strlen( $note ) > 2000 ) {
			return new \WP_Error( 'vfg_invalid_blocklist_entry', __( 'Keep the note under 2,000 characters.', 'veridis-fraud-guard' ), array( 'status' => 400, 'field' => 'note' ) );
		}

		$normalized = $this->normalizer->normalize( $type, $value );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		return array(
			'type'             => sanitize_key( $type ),
			'value'            => $normalized['value'],
			'normalized_value' => $normalized['normalized_value'],
			'note'             => $note,
		);
	}

	private function duplicate_error(): \WP_Error {
		return new \WP_Error( 'vfg_duplicate_blocklist_entry', __( 'That value is already on this blocklist.', 'veridis-fraud-guard' ), array( 'status' => 409, 'field' => 'value' ) );
	}

	private function not_found_error(): \WP_Error {
		return new \WP_Error( 'vfg_blocklist_entry_not_found', __( 'That blocklist entry no longer exists.', 'veridis-fraud-guard' ), array( 'status' => 404 ) );
	}

	private function record_activity( string $action, string $source, string $type, ?int $order_id ): void {
		if ( null !== $this->activity && '' !== $source ) {
			$this->activity->record( $action, $source, $type, $order_id );
		}
	}
}
