<?php
namespace Veridis\FraudGuard\Core;

defined( 'ABSPATH' ) || exit;

final class Installer {
	public const SCHEMA_VERSION      = '5';
	public const RETRY_DELAY_SECONDS = 3600;
	public const LOCK_TTL_SECONDS    = 300;

	private const SCHEMA_OPTION      = 'veridis_fraud_guard_db_version';
	private const RETRY_AFTER_OPTION = 'veridis_fraud_guard_db_migration_retry_after';
	private const LOCK_OPTION        = 'veridis_fraud_guard_db_migration_lock';
	private const RETRY_ACTION       = 'veridis_fraud_guard_retry_schema';
	private const RETRY_QUERY_ARG    = 'veridis_fraud_guard_retry_schema';

	public static function activate(): void {
		self::attempt_install( true );
	}

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'handle_admin_retry' ) );
		add_action( 'admin_notices', array( self::class, 'render_admin_notice' ) );
	}

	public static function maybe_install(): void {
		try {
			if ( self::schema_is_current() ) {
				return;
			}

			$retry_after = (int) get_option( self::RETRY_AFTER_OPTION, 0 );
			if ( $retry_after > time() ) {
				return;
			}

			self::attempt_install();
		} catch ( \Throwable ) {
			self::defer_retry();
		}
	}

	public static function retry_install(): bool {
		if ( ! current_user_can( Access::CAPABILITY ) ) {
			return false;
		}

		return self::attempt_install( true );
	}

	public static function handle_admin_retry(): void {
		if ( ! isset( $_GET[ self::RETRY_QUERY_ARG ] ) ) {
			return;
		}

		if ( ! current_user_can( Access::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to retry the Fraud Guard database upgrade.', 'veridis-fraud-guard' ) );
		}

		check_admin_referer( self::RETRY_ACTION );
		self::retry_install();

		$redirect = remove_query_arg( array( self::RETRY_QUERY_ARG, '_wpnonce' ) );
		wp_safe_redirect( is_string( $redirect ) && '' !== $redirect ? $redirect : admin_url() );
		exit;
	}

	public static function render_admin_notice(): void {
		if ( self::schema_is_current() || ! current_user_can( Access::CAPABILITY ) ) {
			return;
		}

		$retry_url = wp_nonce_url(
			add_query_arg( self::RETRY_QUERY_ARG, '1' ),
			self::RETRY_ACTION
		);
		?>
		<div class="notice notice-error">
			<p>
				<?php echo esc_html__( 'Fraud Guard could not complete its database upgrade. Checkout protection will continue where storage is available.', 'veridis-fraud-guard' ); ?>
				<a href="<?php echo esc_url( $retry_url ); ?>"><?php echo esc_html__( 'Retry database upgrade now', 'veridis-fraud-guard' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function attempt_install( bool $ignore_cooldown = false ): bool {
		global $wpdb;

		$lock                 = null;
		$previous_suppression = null;
		try {
			if ( is_object( $wpdb ) && method_exists( $wpdb, 'suppress_errors' ) ) {
				$previous_suppression = $wpdb->suppress_errors( true );
			}

			if ( ! $ignore_cooldown ) {
				$retry_after = (int) get_option( self::RETRY_AFTER_OPTION, 0 );
				if ( $retry_after > time() ) {
					return false;
				}
			}

			$lock = self::acquire_lock();
			if ( null === $lock ) {
				return false;
			}
			if ( self::schema_is_current() ) {
				return true;
			}

			$installed = self::install();
			if ( $installed ) {
				try {
					delete_option( self::RETRY_AFTER_OPTION );
				} catch ( \Throwable ) {
					// A stale cooldown is harmless after the verified version was saved.
				}
				return true;
			}

			self::defer_retry();
			return false;
		} catch ( \Throwable ) {
			self::defer_retry();
			return false;
		} finally {
			if ( null !== $lock ) {
				self::release_lock( $lock );
			}
			if ( null !== $previous_suppression ) {
				try {
					$wpdb->suppress_errors( $previous_suppression );
				} catch ( \Throwable ) {
					// Failure isolation takes priority over restoring diagnostic display state.
				}
			}
		}
	}

	private static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$blocklist_table = $wpdb->prefix . 'veridis_fraud_guard_blocklist';
		$activity_table  = $wpdb->prefix . 'veridis_fraud_guard_activity';
		$charset_collate = $wpdb->get_charset_collate();
		$blocklist_sql   = "CREATE TABLE {$blocklist_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			value varchar(255) NOT NULL,
			normalized_value varchar(191) NOT NULL,
			note text NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_normalized_value (type, normalized_value),
			KEY type (type),
			KEY created_at (created_at)
		) {$charset_collate};";
		$activity_sql    = "CREATE TABLE {$activity_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(20) NOT NULL,
			outcome varchar(20) NOT NULL,
			source varchar(20) NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			matched_types varchar(100) NOT NULL,
			matched_identifiers text DEFAULT NULL,
			occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1,
			deduplication_key char(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY deduplication_key (deduplication_key),
			KEY order_event_type_lookup (order_id, event_type),
			KEY created_at_id (created_at, id),
			KEY event_type_created_at (event_type, created_at),
			KEY outcome_created_at (outcome, created_at),
			KEY source_created_at (source, created_at),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( array( $blocklist_sql, $activity_sql ) );
		$legacy_unique_index = $wpdb->get_row(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $activity_table, 'order_event_type' )
		);
		$legacy_index_removed = true;
		if ( $legacy_unique_index && 0 === (int) $legacy_unique_index->Non_unique ) {
			$legacy_index_removed = false !== $wpdb->query(
				$wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $activity_table, 'order_event_type' )
			);
		}
		$activity_lookup_index = $wpdb->get_row(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $activity_table, 'order_event_type_lookup' )
		);
		$activity_index_ready  = $activity_lookup_index && 1 === (int) $activity_lookup_index->Non_unique;
		$identifier_column     = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $activity_table, 'matched_identifiers' )
		);
		$occurrence_column     = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $activity_table, 'occurrence_count' )
		);
		$deduplication_column  = $wpdb->get_row(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $activity_table, 'deduplication_key' )
		);
		$deduplication_index   = $wpdb->get_row(
			$wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $activity_table, 'deduplication_key' )
		);
		$deduplication_ready   = $deduplication_index && 0 === (int) $deduplication_index->Non_unique;
		$installed_blocklist = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $blocklist_table ) )
		);
		$installed_activity  = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $activity_table ) )
		);
		$verified = $blocklist_table === $installed_blocklist
			&& $activity_table === $installed_activity
			&& $legacy_index_removed
			&& $activity_index_ready
			&& $identifier_column
			&& $occurrence_column
			&& $deduplication_column
			&& $deduplication_ready;
		if ( ! $verified ) {
			return false;
		}

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		return self::schema_is_current();
	}

	private static function schema_is_current(): bool {
		return self::SCHEMA_VERSION === get_option( self::SCHEMA_OPTION );
	}

	private static function acquire_lock(): ?string {
		global $wpdb;

		$token = ( time() + self::LOCK_TTL_SECONDS ) . ':' . wp_generate_uuid4();
		if ( add_option( self::LOCK_OPTION, $token, '', false ) ) {
			return $token;
		}

		$current = get_option( self::LOCK_OPTION, '' );
		if ( ! is_string( $current ) || self::lock_expires_at( $current ) > time() ) {
			return null;
		}

		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $token ),
			array( 'option_name' => self::LOCK_OPTION, 'option_value' => $current ),
			array( '%s' ),
			array( '%s', '%s' )
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return 1 === $updated ? $token : null;
	}

	private static function release_lock( string $token ): void {
		global $wpdb;

		try {
			$wpdb->delete(
				$wpdb->options,
				array( 'option_name' => self::LOCK_OPTION, 'option_value' => $token ),
				array( '%s', '%s' )
			);
			wp_cache_delete( self::LOCK_OPTION, 'options' );
		} catch ( \Throwable ) {
			// The expiring token allows recovery if releasing the lock fails.
		}
	}

	private static function lock_expires_at( string $lock ): int {
		$separator = strpos( $lock, ':' );
		return false === $separator ? 0 : max( 0, (int) substr( $lock, 0, $separator ) );
	}

	private static function defer_retry(): void {
		try {
			update_option( self::RETRY_AFTER_OPTION, time() + self::RETRY_DELAY_SECONDS, false );
		} catch ( \Throwable ) {
			// WordPress must keep booting even when its options table is unavailable.
		}
	}
}
