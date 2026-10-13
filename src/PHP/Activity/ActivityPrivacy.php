<?php
namespace Veridis\FraudGuard\Activity;

defined( 'ABSPATH' ) || exit;

final class ActivityPrivacy {
	public const CRON_HOOK = 'veridis_fraud_guard_purge_activity_identifiers';
	public const DEFAULT_RETENTION_DAYS = ActivityIdentifierRetention::DEFAULT_DAYS;
	public const LAST_RUN_OPTION = 'veridis_fraud_guard_activity_retention_last_run';
	public const FALLBACK_INTERVAL_SECONDS = 43200;

	private const ERASER_ID = 'veridis-fraud-guard-activity-identifiers';
	private const ERASER_BATCH_SIZE = 100;
	private const EXPORTER_ID = 'veridis-fraud-guard-activity';
	private const EXPORTER_BATCH_SIZE = 100;

	private ActivityIdentifierRetention $retention;

	public function __construct( private ActivityRepository $repository, ?ActivityIdentifierRetention $retention = null ) {
		$this->retention = $retention ?? new ActivityIdentifierRetention();
	}

	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'purge_expired' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}

		$this->maybe_purge_expired();
	}

	public static function activate(): void {
		( new self( new ActivityRepository() ) )->purge_expired();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function purge_expired( ?\DateTimeImmutable $now = null ): int {
		$now     = $this->utc_now( $now );
		$removed = $this->repository->purge_identifiers_before( $this->retention->cutoff( $now ) );
		update_option( self::LAST_RUN_OPTION, $now->getTimestamp(), false );

		return $removed;
	}

	public function maybe_purge_expired( ?\DateTimeImmutable $now = null ): int {
		$now      = $this->utc_now( $now );
		$last_run = (int) get_option( self::LAST_RUN_OPTION, 0 );
		if ( $last_run > 0 && $last_run > $now->getTimestamp() - self::FALLBACK_INTERVAL_SECONDS ) {
			return 0;
		}

		// Record the attempt first to reduce duplicate cleanup from overlapping requests.
		update_option( self::LAST_RUN_OPTION, $now->getTimestamp(), false );
		return $this->repository->purge_identifiers_before( $this->retention->cutoff( $now ) );
	}

	/**
	 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Veridis Fraud Guard checkout activity', 'veridis-fraud-guard' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
	 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Veridis Fraud Guard checkout activity', 'veridis-fraud-guard' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);

		return $exporters;
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_personal_data( string $email_address, int $page = 1 ): array {
		$email  = strtolower( trim( $email_address ) );
		$rows   = $this->repository->privacy_rows_page( max( 1, $page ), self::EXPORTER_BATCH_SIZE );
		$cutoff = $this->retention->cutoff();
		$data   = array();

		foreach ( $rows as $row ) {
			if ( ! $this->retention->identifiers_are_current( (string) $row->created_at, $cutoff ) ) {
				continue;
			}

			$identifiers = MatchedIdentifierSet::decode( $row->matched_identifiers ?? null );
			if ( ! $this->snapshot_matches_email( $identifiers, $email ) ) {
				continue;
			}

			$item_data = array(
				array( 'name' => __( 'Event type', 'veridis-fraud-guard' ), 'value' => (string) $row->event_type ),
				array( 'name' => __( 'Outcome', 'veridis-fraud-guard' ), 'value' => (string) $row->outcome ),
				array( 'name' => __( 'Source', 'veridis-fraud-guard' ), 'value' => (string) $row->source ),
				array( 'name' => __( 'Matched identifier types', 'veridis-fraud-guard' ), 'value' => (string) $row->matched_types ),
				array( 'name' => __( 'Occurrence count', 'veridis-fraud-guard' ), 'value' => (string) (int) $row->occurrence_count ),
				array( 'name' => __( 'Recorded at (UTC)', 'veridis-fraud-guard' ), 'value' => (string) $row->created_at ),
			);

			if ( null !== $row->order_id ) {
				$item_data[] = array( 'name' => __( 'Order reference', 'veridis-fraud-guard' ), 'value' => (string) (int) $row->order_id );
			}

			foreach ( $identifiers as $identifier ) {
				if ( 'email' === $identifier['type'] && $email !== strtolower( $identifier['value'] ) ) {
					continue;
				}

				$item_data[] = array(
					'name'  => $this->identifier_label( $identifier['type'] ),
					'value' => $identifier['value'],
				);
			}

			$data[] = array(
				'group_id'    => self::EXPORTER_ID,
				'group_label' => __( 'Veridis Fraud Guard checkout activity', 'veridis-fraud-guard' ),
				'item_id'     => 'activity-' . (int) $row->id,
				'data'        => $item_data,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::EXPORTER_BATCH_SIZE,
		);
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} */
	public function erase_personal_data( string $email_address, int $page = 1 ): array {
		$email = strtolower( trim( $email_address ) );
		$rows  = $this->repository->identifier_rows_page( max( 1, $page ), self::ERASER_BATCH_SIZE );
		$ids   = array();

		foreach ( $rows as $row ) {
			$identifiers = MatchedIdentifierSet::decode( $row->matched_identifiers ?? null );
			if ( $this->snapshot_matches_email( $identifiers, $email ) ) {
				$ids[] = (int) $row->id;
			}
		}

		$removed  = $this->repository->clear_identifiers( $ids );
		$retained = count( $ids ) > $removed;
		$messages = $retained
			? array( __( 'Some Fraud Guard checkout identifiers could not be erased. Try the erasure request again.', 'veridis-fraud-guard' ) )
			: array();

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => count( $rows ) < self::ERASER_BATCH_SIZE,
		);
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Veridis Fraud Guard', 'veridis-fraud-guard' ),
			wp_kses_post(
				__(
					'<p>Veridis Fraud Guard stores exact email addresses, phone numbers, IP addresses, and email domains added to the Blocklist, together with normalized versions used for exact matching. It also stores any Blocklist notes written by the merchant. Blocklist entries and notes are retained until the merchant edits or removes them, or the site owner deletes the plugin through WordPress.</p><p>When a checkout is blocked, held, or flagged, Fraud Guard stores the exact Blocklist identifiers that matched with the Activity event. Repeated identical blocked attempts from the same checkout source on one site-calendar day are combined into one Activity record with an occurrence count. A keyed, non-reversible deduplication value is stored for this purpose; it is not exposed by Fraud Guard interfaces. Authorized WooCommerce administrators can view current retained values. Exact checkout-match identifiers and the associated deduplication value are retained for 90 days by default. After that retention boundary they are no longer returned or used for deduplication. Physical database cleanup is performed by scheduled WordPress tasks and may occur later if WP-Cron is delayed or disabled.</p><p>Other Activity metadata remains for operational reporting while Fraud Guard is installed, including the event type, outcome, checkout source, matched identifier types, occurrence count, timestamp, and an order reference when an order exists. Deleting the plugin removes the Fraud Guard Activity table. Fraud Guard may also add a private WooCommerce order note and a boolean order marker recording that an order was held or flagged. These notes and markers remain with the WooCommerce order as historical order data after uninstall.</p><p>WordPress personal data export and erasure requests locate Activity events by an exact matching email address. Exports include current retained match identifiers and Activity event context, but do not include Blocklist records or WooCommerce order data. Erasure removes the full stored identifier snapshot and deduplication value for matching events. Email-based requests cannot locate events that stored only a phone number, IP address, or email domain, and erasure does not remove Blocklist entries, Activity metadata, occurrence counts, WooCommerce orders, order notes, or order markers.</p>',
					'veridis-fraud-guard'
				)
			)
		);
	}

	private function utc_now( ?\DateTimeImmutable $now ): \DateTimeImmutable {
		$utc = new \DateTimeZone( 'UTC' );
		return ( $now ?? new \DateTimeImmutable( 'now', $utc ) )->setTimezone( $utc );
	}

	/** @param array<int, array{type: string, value: string}> $identifiers */
	private function snapshot_matches_email( array $identifiers, string $email ): bool {
		foreach ( $identifiers as $identifier ) {
			if ( 'email' === $identifier['type'] && $email === strtolower( $identifier['value'] ) ) {
				return true;
			}
		}

		return false;
	}

	private function identifier_label( string $type ): string {
		return match ( $type ) {
			'email'  => __( 'Matched email address', 'veridis-fraud-guard' ),
			'phone'  => __( 'Matched phone number', 'veridis-fraud-guard' ),
			'ip'     => __( 'Matched IP address', 'veridis-fraud-guard' ),
			'domain' => __( 'Matched email domain', 'veridis-fraud-guard' ),
			default  => __( 'Matched identifier', 'veridis-fraud-guard' ),
		};
	}
}
