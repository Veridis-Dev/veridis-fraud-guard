<?php
namespace Veridis\FraudGuard\Core;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {
	private const OPTIONS = array(
		'veridis_fraud_guard_db_version',
		'veridis_fraud_guard_db_migration_retry_after',
		'veridis_fraud_guard_db_migration_lock',
		'veridis_fraud_guard_checkout_mode',
		'veridis_fraud_guard_checkout_block_enabled',
		'veridis_fraud_guard_checkout_rejection_message',
		'veridis_fraud_guard_activity_retention_last_run',
		'veridis_fraud_guard_blocklist_storage_health',
	);

	private const TABLE_SUFFIXES = array(
		'veridis_fraud_guard_blocklist',
		'veridis_fraud_guard_activity',
	);

	private const CRON_HOOKS = array(
		'veridis_fraud_guard_purge_activity_identifiers',
	);

	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::cleanup_site();
			return;
		}

		if ( ! function_exists( 'get_sites' ) || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
			return;
		}

		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);

			if ( ! is_array( $sites ) ) {
				return;
			}

			foreach ( $sites as $site ) {
				$site_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : (int) $site;
				if ( $site_id < 1 ) {
					continue;
				}

				switch_to_blog( $site_id );
				try {
					self::cleanup_site();
				} finally {
					restore_current_blog();
				}
			}

			$offset += count( $sites );
		} while ( 100 === count( $sites ) );
	}

	public static function cleanup_site(): void {
		global $wpdb;

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}
