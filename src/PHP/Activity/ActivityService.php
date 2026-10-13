<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;

final class ActivityService {
	public const CHECKOUT_EVENT_TYPES = array( 'block', 'hold', 'warn' );
	public const EVENT_TYPES = array( 'block', 'hold', 'warn', 'blocklist' );
	public const FILTERS = array( '', 'checkout', 'block', 'hold', 'warn', 'blocklist' );

	private const OUTCOMES = array(
		'block'     => array( 'rejected' ),
		'hold'      => array( 'held' ),
		'warn'      => array( 'flagged' ),
		'blocklist' => array( 'added', 'edited', 'unblocked' ),
	);

	private const SOURCES = array(
		'block'     => array( 'classic', 'blocks' ),
		'hold'      => array( 'classic', 'blocks' ),
		'warn'      => array( 'classic', 'blocks' ),
		'blocklist' => array( 'admin', 'order_panel' ),
	);

	private ActivityIdentifierRetention $retention;

	public function __construct( private ActivityRepository $repository, ?ActivityIdentifierRetention $retention = null ) {
		$this->retention = $retention ?? new ActivityIdentifierRetention();
	}

	/** @return array<string, mixed> */
	public function list( string $event_type = '', int $page = 1, int $per_page = 20 ): array {
		$event_type = sanitize_key( $event_type );
		if ( ! in_array( $event_type, self::FILTERS, true ) ) {
			$event_type = '';
		}

		$included_types   = 'checkout' === $event_type ? self::CHECKOUT_EVENT_TYPES : self::EVENT_TYPES;
		$exact_type       = in_array( $event_type, self::EVENT_TYPES, true ) ? $event_type : '';
		$page             = max( 1, $page );
		$per_page         = min( 100, max( 1, $per_page ) );
		$result           = $this->repository->list( $exact_type, $page, $per_page, $included_types );
		$total_page       = max( 1, (int) ceil( $result['total'] / $per_page ) );
		$retention_cutoff = $this->retention->cutoff();

		return array(
			'items'      => array_map( fn( object $row ): array => $this->present( $row, $retention_cutoff ), $result['items'] ),
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $result['total'],
				'total_pages' => $total_page,
			),
			'counts'     => $this->repository->summary( self::EVENT_TYPES ),
		);
	}

	/** @return array{counts: array{total: int, today: int, last_7_days: int}, recent: array<int, array<string, mixed>>} */
	public function dashboard( int $recent_limit = 5, ?\DateTimeImmutable $now = null ): array {
		$site_timezone = wp_timezone();
		$site_now      = null === $now
			? new \DateTimeImmutable( 'now', $site_timezone )
			: $now->setTimezone( $site_timezone );
		$today_start   = $site_now->setTime( 0, 0, 0 );
		$tomorrow      = $today_start->modify( '+1 day' );
		$seven_start   = $today_start->modify( '-6 days' );
		$utc           = new \DateTimeZone( 'UTC' );

		$counts = $this->repository->checkout_dashboard_summary(
			self::CHECKOUT_EVENT_TYPES,
			$today_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$tomorrow->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$seven_start->setTimezone( $utc )->format( 'Y-m-d H:i:s' )
		);
		$recent           = $this->repository->recent( self::CHECKOUT_EVENT_TYPES, $recent_limit );
		$retention_cutoff = $this->retention->cutoff( $now );

		return array(
			'counts' => $counts,
			'recent' => array_map( fn( object $row ): array => $this->present( $row, $retention_cutoff ), $recent ),
		);
	}

	/** @return array<string, mixed> */
	private function present( object $row, string $retention_cutoff ): array {
		$order_id   = isset( $row->order_id ) ? (int) $row->order_id : 0;
		$event_type = sanitize_key( (string) $row->event_type );
		$outcome     = sanitize_key( (string) $row->outcome );
		$source      = sanitize_key( (string) $row->source );
		$outcome     = in_array( $outcome, self::OUTCOMES[ $event_type ] ?? array(), true ) ? $outcome : 'unknown';
		$source      = in_array( $source, self::SOURCES[ $event_type ] ?? array(), true ) ? $source : 'unknown';
		$types      = array_values(
			array_intersect(
				BlocklistNormalizer::TYPES,
				array_filter( array_map( 'sanitize_key', explode( ',', (string) $row->matched_types ) ) )
			)
		);

		return array(
			'id'                  => (int) $row->id,
			'event_type'          => $event_type,
			'outcome'             => $outcome,
			'source'              => $source,
			'order_id'            => $order_id > 0 ? $order_id : null,
			'order'               => $order_id > 0 ? $this->order_reference( $order_id ) : null,
			'matched_types'       => $types,
			'matched_identifiers' => in_array( $event_type, self::CHECKOUT_EVENT_TYPES, true )
				&& $this->retention->identifiers_are_current( (string) $row->created_at, $retention_cutoff )
				? MatchedIdentifierSet::decode( $row->matched_identifiers ?? null, $types )
				: array(),
			'occurrence_count'    => max( 1, (int) ( $row->occurrence_count ?? 1 ) ),
			'created_at'          => (string) $row->created_at,
		);
	}

	/** @return array{id: int, number: string, edit_url: string|null} */
	private function order_reference( int $order_id ): array {
		$reference = array( 'id' => $order_id, 'number' => (string) $order_id, 'edit_url' => null );
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $reference;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $reference;
		}

		$reference['number'] = (string) $order->get_order_number();
		$edit_url            = $order->get_edit_order_url();
		$reference['edit_url'] = is_string( $edit_url ) && '' !== $edit_url ? $edit_url : null;

		return $reference;
	}
}
