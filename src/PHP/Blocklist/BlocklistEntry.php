<?php
namespace Veridis\FraudGuard\Blocklist;

defined( 'ABSPATH' ) || exit;

final class BlocklistEntry {
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $value,
		public readonly string $normalized_value,
		public readonly string $note,
		public readonly string $created_at,
		public readonly string $updated_at
	) {}

	/**
	 * @param object|array<string, mixed> $row Database row.
	 */
	public static function from_row( object|array $row ): self {
		$data = (array) $row;

		return new self(
			(int) $data['id'],
			(string) $data['type'],
			(string) $data['value'],
			(string) $data['normalized_value'],
			(string) $data['note'],
			(string) $data['created_at'],
			(string) $data['updated_at']
		);
	}

	/**
	 * @return array<string, int|string>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'type'             => $this->type,
			'value'            => $this->value,
			'normalized_value' => $this->normalized_value,
			'note'             => $this->note,
			'created_at'       => $this->created_at,
			'updated_at'       => $this->updated_at,
		);
	}
}
