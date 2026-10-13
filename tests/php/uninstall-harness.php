<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_current_blog']   = 1;
$GLOBALS['vfg_blog_stack']     = array();
$GLOBALS['vfg_is_multisite']   = false;
$GLOBALS['vfg_site_ids']       = array( 1 );
$GLOBALS['vfg_options']        = array();
$GLOBALS['vfg_tables']         = array();
$GLOBALS['vfg_cron']           = array();
$GLOBALS['vfg_orders']         = array();
$GLOBALS['vfg_woo_accesses']   = 0;
$GLOBALS['vfg_switched_blogs'] = array();

function is_multisite(): bool {
	return $GLOBALS['vfg_is_multisite'];
}

/** @return int[] */
function get_sites( array $args ): array {
	return array_slice( $GLOBALS['vfg_site_ids'], (int) $args['offset'], (int) $args['number'] );
}

function switch_to_blog( int $site_id ): bool {
	$GLOBALS['vfg_blog_stack'][]     = $GLOBALS['vfg_current_blog'];
	$GLOBALS['vfg_current_blog']     = $site_id;
	$GLOBALS['vfg_switched_blogs'][] = $site_id;
	$GLOBALS['wpdb']->prefix         = 'wp_' . $site_id . '_';
	return true;
}

function restore_current_blog(): bool {
	$site_id = array_pop( $GLOBALS['vfg_blog_stack'] );
	if ( null === $site_id ) {
		return false;
	}

	$GLOBALS['vfg_current_blog'] = $site_id;
	$GLOBALS['wpdb']->prefix     = 'wp_' . $site_id . '_';
	return true;
}

function delete_option( string $option ): bool {
	$site_id = $GLOBALS['vfg_current_blog'];
	if ( ! array_key_exists( $option, $GLOBALS['vfg_options'][ $site_id ] ?? array() ) ) {
		return false;
	}

	unset( $GLOBALS['vfg_options'][ $site_id ][ $option ] );
	return true;
}

function wp_clear_scheduled_hook( string $hook ): int|false {
	$site_id = $GLOBALS['vfg_current_blog'];
	if ( empty( $GLOBALS['vfg_cron'][ $site_id ][ $hook ] ) ) {
		return 0;
	}

	unset( $GLOBALS['vfg_cron'][ $site_id ][ $hook ] );
	return 1;
}

final class wpdb {
	public string $prefix = 'wp_1_';
	/** @var string[] */
	public array $queries = array();

	public function prepare( string $query, mixed ...$args ): string {
		return str_replace( '%i', '`' . str_replace( '`', '``', (string) $args[0] ) . '`', $query );
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( preg_match( '/DROP TABLE IF EXISTS `([^`]+)`/', $query, $match ) ) {
			unset( $GLOBALS['vfg_tables'][ $GLOBALS['vfg_current_blog'] ][ $match[1] ] );
		}
		return 0;
	}
}

/**
 * This active-WooCommerce sentinel deliberately fails if uninstall touches orders.
 * Its presence also proves cleanup does not depend on WooCommerce being unavailable.
 */
function wc_get_orders( array $args ): array {
	++$GLOBALS['vfg_woo_accesses'];
	throw new RuntimeException( 'Uninstall must not query WooCommerce orders.' );
}

function vfg_seed_site( int $site_id ): void {
	$GLOBALS['vfg_options'][ $site_id ] = array(
		'veridis_fraud_guard_db_version'                  => '4',
		'veridis_fraud_guard_db_migration_retry_after'    => 123,
		'veridis_fraud_guard_db_migration_lock'           => '456:test-lock',
		'veridis_fraud_guard_checkout_mode'               => 'hold',
		'veridis_fraud_guard_checkout_block_enabled'      => 'yes',
		'veridis_fraud_guard_checkout_rejection_message'  => 'No order.',
		'veridis_fraud_guard_activity_retention_last_run' => 123,
		'veridis_fraud_guard_blocklist_storage_health'    => 'unavailable',
		'veridis_fraud_guard_unrelated_prefix_option'     => 'keep-prefix',
		'other_plugin_option'                             => 'keep-other',
	);
	$GLOBALS['vfg_tables'][ $site_id ] = array(
		'wp_' . $site_id . '_veridis_fraud_guard_blocklist' => true,
		'wp_' . $site_id . '_veridis_fraud_guard_activity'  => true,
		'wp_' . $site_id . '_other_plugin_table'             => true,
	);
	$GLOBALS['vfg_cron'][ $site_id ] = array(
		'veridis_fraud_guard_purge_activity_identifiers' => true,
		'other_plugin_event'                             => true,
	);
	$GLOBALS['vfg_orders'][ $site_id ] = array(
		array(
			'id'                => 70 + $site_id,
			'status'            => 'on-hold',
			'payment_reference' => 'payment-kept-' . $site_id,
			'refunds'           => array( 'refund-kept' ),
			'customer_id'       => 100 + $site_id,
			'notes'             => array( 'Fraud Guard private order note', 'Customer note' ),
			'meta'              => array(
				'_veridis_fraud_guard_cod_hold_applied'       => 'yes',
				'_veridis_fraud_guard_checkout_warning_added' => 'yes',
				'_veridis_fraud_guard_warn_recorded'          => 'yes',
				'_another_plugin_order_marker'                => 'keep',
			),
		),
	);
}

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** @return string[] */
function vfg_allowlisted_options(): array {
	return array(
		'veridis_fraud_guard_db_version',
		'veridis_fraud_guard_db_migration_retry_after',
		'veridis_fraud_guard_db_migration_lock',
		'veridis_fraud_guard_checkout_mode',
		'veridis_fraud_guard_checkout_block_enabled',
		'veridis_fraud_guard_checkout_rejection_message',
		'veridis_fraud_guard_activity_retention_last_run',
		'veridis_fraud_guard_blocklist_storage_health',
	);
}

function vfg_assert_site_cleaned( int $site_id ): void {
	vfg_assert( ! isset( $GLOBALS['vfg_tables'][ $site_id ]['wp_' . $site_id . '_veridis_fraud_guard_blocklist'] ), "Site {$site_id} Blocklist table was not removed." );
	vfg_assert( ! isset( $GLOBALS['vfg_tables'][ $site_id ]['wp_' . $site_id . '_veridis_fraud_guard_activity'] ), "Site {$site_id} Activity table was not removed." );
	vfg_assert( isset( $GLOBALS['vfg_tables'][ $site_id ]['wp_' . $site_id . '_other_plugin_table'] ), "Site {$site_id} unrelated table was removed." );

	foreach ( vfg_allowlisted_options() as $option ) {
		vfg_assert( ! isset( $GLOBALS['vfg_options'][ $site_id ][ $option ] ), "Site {$site_id} allowlisted option {$option} was not removed." );
	}

	vfg_assert( isset( $GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_unrelated_prefix_option'] ), "Site {$site_id} non-allowlisted prefixed option was removed." );
	vfg_assert( isset( $GLOBALS['vfg_options'][ $site_id ]['other_plugin_option'] ), "Site {$site_id} unrelated option was removed." );
	vfg_assert( ! isset( $GLOBALS['vfg_cron'][ $site_id ]['veridis_fraud_guard_purge_activity_identifiers'] ), "Site {$site_id} Fraud Guard cron event was not cleared." );
	vfg_assert( isset( $GLOBALS['vfg_cron'][ $site_id ]['other_plugin_event'] ), "Site {$site_id} unrelated cron event was cleared." );
}

$GLOBALS['wpdb'] = new wpdb();

require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityPrivacy.php';
require_once __DIR__ . '/../../src/PHP/Core/Uninstaller.php';

use Veridis\FraudGuard\Activity\ActivityPrivacy;
use Veridis\FraudGuard\Core\Uninstaller;

// Deactivation clears only the scheduled task; data, settings, and orders remain intact.
vfg_seed_site( 1 );
$deactivation_options = $GLOBALS['vfg_options'][1];
$deactivation_tables  = $GLOBALS['vfg_tables'][1];
$deactivation_orders  = $GLOBALS['vfg_orders'][1];
ActivityPrivacy::deactivate();
vfg_assert( $deactivation_options === $GLOBALS['vfg_options'][1], 'Deactivation changed plugin options.' );
vfg_assert( $deactivation_tables === $GLOBALS['vfg_tables'][1], 'Deactivation changed plugin tables.' );
vfg_assert( $deactivation_orders === $GLOBALS['vfg_orders'][1], 'Deactivation changed WooCommerce order data.' );
vfg_assert( ! isset( $GLOBALS['vfg_cron'][1]['veridis_fraud_guard_purge_activity_identifiers'] ), 'Deactivation did not clear the retention task.' );

// Single-site uninstall removes only allowlisted plugin data and never enters WooCommerce.
$GLOBALS['vfg_cron'][1]['veridis_fraud_guard_purge_activity_identifiers'] = true;
$single_site_orders = $GLOBALS['vfg_orders'][1];
Uninstaller::cleanup_site();
vfg_assert_site_cleaned( 1 );
vfg_assert( $single_site_orders === $GLOBALS['vfg_orders'][1], 'Uninstall changed WooCommerce order data.' );
vfg_assert( isset( $GLOBALS['vfg_orders'][1][0]['meta']['_veridis_fraud_guard_cod_hold_applied'] ), 'Uninstall removed a Fraud Guard technical order marker.' );
vfg_assert( 0 === $GLOBALS['vfg_woo_accesses'], 'Uninstall accessed WooCommerce orders.' );
vfg_assert( ! str_contains( implode( "\n", $GLOBALS['wpdb']->queries ), 'postmeta' ) && ! str_contains( implode( "\n", $GLOBALS['wpdb']->queries ), 'wc_orders' ), 'Uninstall used direct SQL against WooCommerce storage.' );

// Repeating cleanup is harmless and still does not touch order data.
$queries_before_repeat = count( $GLOBALS['wpdb']->queries );
Uninstaller::cleanup_site();
vfg_assert( $queries_before_repeat + 2 === count( $GLOBALS['wpdb']->queries ), 'Repeated uninstall did not remain idempotent.' );
vfg_assert( $single_site_orders === $GLOBALS['vfg_orders'][1], 'Repeated uninstall changed WooCommerce order data.' );
vfg_assert( 0 === $GLOBALS['vfg_woo_accesses'], 'Repeated uninstall accessed WooCommerce orders.' );

// Multisite cleanup visits every site while preserving each site's complete order snapshot.
$GLOBALS['vfg_is_multisite'] = true;
$GLOBALS['vfg_site_ids']     = array( 1, 2 );
vfg_seed_site( 1 );
vfg_seed_site( 2 );
$multisite_orders = $GLOBALS['vfg_orders'];
Uninstaller::uninstall();
vfg_assert_site_cleaned( 1 );
vfg_assert_site_cleaned( 2 );
vfg_assert( $multisite_orders === $GLOBALS['vfg_orders'], 'Multisite uninstall changed WooCommerce order data.' );
vfg_assert( array( 1, 2 ) === $GLOBALS['vfg_switched_blogs'], 'Multisite uninstall did not visit every site.' );
vfg_assert( 1 === $GLOBALS['vfg_current_blog'] && array() === $GLOBALS['vfg_blog_stack'], 'Multisite uninstall did not restore the original site.' );
vfg_assert( 0 === $GLOBALS['vfg_woo_accesses'], 'Multisite uninstall accessed WooCommerce orders.' );

// The guarded WordPress entry point applies the same policy.
$GLOBALS['vfg_is_multisite'] = false;
vfg_seed_site( 1 );
$entry_orders = $GLOBALS['vfg_orders'][1];
define( 'WP_UNINSTALL_PLUGIN', 'veridis-fraud-guard/veridis-fraud-guard.php' );
require __DIR__ . '/../../uninstall.php';
vfg_assert_site_cleaned( 1 );
vfg_assert( $entry_orders === $GLOBALS['vfg_orders'][1], 'The uninstall.php entry point changed WooCommerce order data.' );
vfg_assert( 0 === $GLOBALS['vfg_woo_accesses'], 'The uninstall.php entry point accessed WooCommerce orders.' );

echo "Uninstall harness: PASS\n";
