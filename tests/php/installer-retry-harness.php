<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_current_blog']     = 1;
$GLOBALS['vfg_options']          = array();
$GLOBALS['vfg_schema']           = array();
$GLOBALS['vfg_dbdelta_calls']    = array();
$GLOBALS['vfg_schema_sql']       = array();
$GLOBALS['vfg_fail_schema']      = array();
$GLOBALS['vfg_can_manage']       = false;
$GLOBALS['vfg_hooks']            = array();
$GLOBALS['vfg_uuid']             = 0;
$GLOBALS['vfg_complete_on_lock'] = false;

function vfg_site_id(): int {
	return $GLOBALS['vfg_current_blog'];
}

function vfg_switch_site( int $site_id ): void {
	$GLOBALS['vfg_current_blog'] = $site_id;
	$GLOBALS['wpdb']->prefix     = 'wp_' . $site_id . '_';
	$GLOBALS['wpdb']->options    = 'wp_' . $site_id . '_options';
}

function get_option( string $key, mixed $default = false ): mixed {
	$site_id = vfg_site_id();
	return array_key_exists( $key, $GLOBALS['vfg_options'][ $site_id ] ?? array() )
		? $GLOBALS['vfg_options'][ $site_id ][ $key ]
		: $default;
}

function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	unset( $autoload );
	$GLOBALS['vfg_options'][ vfg_site_id() ][ $key ] = $value;
	return true;
}

function add_option( string $key, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );
	$site_id = vfg_site_id();
	if ( array_key_exists( $key, $GLOBALS['vfg_options'][ $site_id ] ?? array() ) ) {
		return false;
	}
	$GLOBALS['vfg_options'][ $site_id ][ $key ] = $value;
	if ( 'veridis_fraud_guard_db_migration_lock' === $key && true === $GLOBALS['vfg_complete_on_lock'] ) {
		$GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_version'] = '5';
		$GLOBALS['vfg_complete_on_lock'] = false;
	}
	return true;
}

function delete_option( string $key ): bool {
	$site_id = vfg_site_id();
	if ( ! array_key_exists( $key, $GLOBALS['vfg_options'][ $site_id ] ?? array() ) ) {
		return false;
	}
	unset( $GLOBALS['vfg_options'][ $site_id ][ $key ] );
	return true;
}

function wp_generate_uuid4(): string {
	++$GLOBALS['vfg_uuid'];
	return sprintf( '00000000-0000-4000-8000-%012d', $GLOBALS['vfg_uuid'] );
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $key, $group );
	return true;
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability && true === $GLOBALS['vfg_can_manage'];
}

function add_action( string $hook, callable $callback ): void {
	$GLOBALS['vfg_hooks'][ $hook ] = $callback;
}

function __( string $message, string $domain = '' ): string {
	unset( $domain );
	return $message;
}

function esc_html__( string $message, string $domain = '' ): string {
	return htmlspecialchars( __( $message, $domain ), ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return $url;
}

function add_query_arg( string $key, string $value ): string {
	return '/wp-admin/admin.php?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
}

function wp_nonce_url( string $url, string $action ): string {
	return $url . '&_wpnonce=nonce-' . rawurlencode( $action );
}

/** @param string[] $queries */
function dbDelta( array $queries ): void {
	$site_id = vfg_site_id();
	$GLOBALS['vfg_dbdelta_calls'][ $site_id ] = ( $GLOBALS['vfg_dbdelta_calls'][ $site_id ] ?? 0 ) + 1;
	$GLOBALS['vfg_schema_sql'][ $site_id ]    = $queries;
	if ( true === ( $GLOBALS['vfg_fail_schema'][ $site_id ] ?? false ) ) {
		return;
	}

	$schema                             = $GLOBALS['vfg_schema'][ $site_id ] ?? array();
	$schema['blocklist_table']          = true;
	$schema['activity_table']           = true;
	$schema['lookup_index']             = true;
	$schema['identifier_column']        = true;
	$schema['occurrence_column']        = true;
	$schema['deduplication_column']     = true;
	$schema['deduplication_index']      = true;
	$GLOBALS['vfg_schema'][ $site_id ] = $schema;
}

final class wpdb {
	public string $prefix = 'wp_1_';
	public string $options = 'wp_1_options';
	public bool $throw_on_lock_insert = false;

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		return preg_replace_callback(
			'/%[is]/',
			static function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position++ ];
				return '%i' === $match[0]
					? '`' . str_replace( '`', '``', (string) $value ) . '`'
					: "'" . addslashes( (string) $value ) . "'";
			},
			$query
		) ?? $query;
	}

	public function get_row( string $query ): ?object {
		$schema = $GLOBALS['vfg_schema'][ vfg_site_id() ] ?? array();
		if ( str_contains( $query, "Key_name = 'order_event_type_lookup'" ) ) {
			return ! empty( $schema['lookup_index'] ) ? (object) array( 'Non_unique' => 1 ) : null;
		}
		if ( str_contains( $query, "Key_name = 'deduplication_key'" ) ) {
			return ! empty( $schema['deduplication_index'] ) ? (object) array( 'Non_unique' => 0 ) : null;
		}
		if ( str_contains( $query, "Key_name = 'order_event_type'" ) ) {
			return ! empty( $schema['legacy_unique_index'] ) ? (object) array( 'Non_unique' => 0 ) : null;
		}
		if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
			preg_match( "/LIKE '([^']+)'/", $query, $column );
			$key = match ( $column[1] ?? '' ) {
				'matched_identifiers' => 'identifier_column',
				'occurrence_count' => 'occurrence_column',
				'deduplication_key' => 'deduplication_column',
				default => '',
			};
			return '' !== $key && ! empty( $schema[ $key ] ) ? (object) array( 'Field' => $column[1] ) : null;
		}
		return null;
	}

	public function get_var( string $query ): ?string {
		$schema = $GLOBALS['vfg_schema'][ vfg_site_id() ] ?? array();
		$query  = str_replace( '\\', '', $query );
		if ( str_contains( $query, 'veridis_fraud_guard_blocklist' ) && ! empty( $schema['blocklist_table'] ) ) {
			return $this->prefix . 'veridis_fraud_guard_blocklist';
		}
		if ( str_contains( $query, 'veridis_fraud_guard_activity' ) && ! empty( $schema['activity_table'] ) ) {
			return $this->prefix . 'veridis_fraud_guard_activity';
		}
		return null;
	}

	public function query( string $query ): int|false {
		if ( str_contains( $query, 'DROP INDEX `order_event_type`' ) ) {
			$GLOBALS['vfg_schema'][ vfg_site_id() ]['legacy_unique_index'] = false;
			return 1;
		}
		return false;
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where */
	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		unset( $formats, $where_formats );
		if ( $this->throw_on_lock_insert ) {
			throw new RuntimeException( 'Simulated options-table failure with sensitive details.' );
		}
		if ( $table !== $this->options || 'veridis_fraud_guard_db_migration_lock' !== ( $where['option_name'] ?? '' ) ) {
			return false;
		}
		$site_id = vfg_site_id();
		$current = $GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_migration_lock'] ?? null;
		if ( $current !== ( $where['option_value'] ?? null ) ) {
			return 0;
		}
		$GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_migration_lock'] = $data['option_value'];
		return 1;
	}

	/** @param array<string, mixed> $where */
	public function delete( string $table, array $where, array $formats = array() ): int|false {
		unset( $formats );
		if ( $table !== $this->options || 'veridis_fraud_guard_db_migration_lock' !== ( $where['option_name'] ?? '' ) ) {
			return false;
		}
		$site_id = vfg_site_id();
		$current = $GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_migration_lock'] ?? null;
		if ( $current !== ( $where['option_value'] ?? null ) ) {
			return 0;
		}
		unset( $GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_migration_lock'] );
		return 1;
	}
}

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function vfg_seed_v4( int $site_id, int $legacy_rows = 0 ): void {
	$GLOBALS['vfg_options'][ $site_id ]['veridis_fraud_guard_db_version'] = '4';
	$GLOBALS['vfg_schema'][ $site_id ] = array(
		'blocklist_table'     => true,
		'activity_table'      => true,
		'legacy_unique_index' => true,
		'legacy_rows'         => $legacy_rows,
	);
}

$GLOBALS['wpdb'] = new wpdb();

require_once __DIR__ . '/../../src/PHP/Core/Access.php';
require_once __DIR__ . '/../../src/PHP/Core/Installer.php';

use Veridis\FraudGuard\Core\Installer;

// Fresh installation verifies schema v5 before saving the version.
Installer::activate();
vfg_assert( '5' === get_option( 'veridis_fraud_guard_db_version' ), 'Fresh installation did not save schema v5.' );
vfg_assert( 1 === $GLOBALS['vfg_dbdelta_calls'][1], 'Fresh installation did not run exactly once.' );
vfg_assert( false === get_option( 'veridis_fraud_guard_db_migration_lock' ), 'Fresh installation left its migration lock behind.' );
$fresh_sql = implode( "\n", $GLOBALS['vfg_schema_sql'][1] );
vfg_assert( str_contains( $fresh_sql, 'occurrence_count bigint(20) unsigned NOT NULL DEFAULT 1' ), 'Schema v5 lost the F-01 occurrence count.' );
vfg_assert( str_contains( $fresh_sql, 'UNIQUE KEY deduplication_key (deduplication_key)' ), 'Schema v5 lost the F-01 deduplication index.' );

// A successful v4 upgrade preserves representative legacy rows.
vfg_switch_site( 2 );
vfg_seed_v4( 2, 7 );
Installer::maybe_install();
vfg_assert( '5' === get_option( 'veridis_fraud_guard_db_version' ), 'Successful upgrade did not save schema v5.' );
vfg_assert( 7 === $GLOBALS['vfg_schema'][2]['legacy_rows'], 'Successful upgrade lost legacy Activity rows.' );
vfg_assert( empty( $GLOBALS['vfg_schema'][2]['legacy_unique_index'] ), 'Successful upgrade did not remove the legacy unique index.' );

// A failed migration leaves the old version and applies a one-hour public cooldown.
vfg_switch_site( 3 );
vfg_seed_v4( 3 );
$GLOBALS['vfg_fail_schema'][3] = true;
Installer::maybe_install();
$retry_after = (int) get_option( 'veridis_fraud_guard_db_migration_retry_after' );
vfg_assert( '4' === get_option( 'veridis_fraud_guard_db_version' ), 'Failed migration incorrectly saved schema v5.' );
vfg_assert( $retry_after >= time() + Installer::RETRY_DELAY_SECONDS - 2, 'Failed migration did not set the retry cooldown.' );
Installer::maybe_install();
vfg_assert( 1 === $GLOBALS['vfg_dbdelta_calls'][3], 'A public request retried during the cooldown.' );

// Automatic recovery occurs after the delay and clears the failure state.
$GLOBALS['vfg_options'][3]['veridis_fraud_guard_db_migration_retry_after'] = time() - 1;
$GLOBALS['vfg_fail_schema'][3] = false;
Installer::maybe_install();
vfg_assert( 2 === $GLOBALS['vfg_dbdelta_calls'][3] && '5' === get_option( 'veridis_fraud_guard_db_version' ), 'Delayed automatic recovery failed.' );
vfg_assert( false === get_option( 'veridis_fraud_guard_db_migration_retry_after' ), 'Successful recovery did not clear the cooldown.' );

// Only an authorized administrator can bypass the cooldown for immediate recovery.
vfg_switch_site( 4 );
vfg_seed_v4( 4 );
$GLOBALS['vfg_fail_schema'][4] = true;
Installer::maybe_install();
$GLOBALS['vfg_fail_schema'][4] = false;
vfg_assert( false === Installer::retry_install(), 'Unauthorized recovery bypassed the cooldown.' );
vfg_assert( 1 === $GLOBALS['vfg_dbdelta_calls'][4], 'Unauthorized recovery touched the schema.' );
$GLOBALS['vfg_can_manage'] = true;
vfg_assert( true === Installer::retry_install(), 'Authorized administrator could not recover immediately.' );
vfg_assert( '5' === get_option( 'veridis_fraud_guard_db_version' ), 'Authorized recovery did not save schema v5.' );

// A live lock prevents concurrent migration work; an expired lock is recovered atomically.
vfg_switch_site( 5 );
vfg_seed_v4( 5 );
$GLOBALS['vfg_options'][5]['veridis_fraud_guard_db_migration_lock'] = ( time() + 60 ) . ':other-request';
Installer::maybe_install();
vfg_assert( 0 === ( $GLOBALS['vfg_dbdelta_calls'][5] ?? 0 ), 'A concurrent request bypassed the live migration lock.' );
$GLOBALS['vfg_options'][5]['veridis_fraud_guard_db_migration_lock'] = ( time() - 1 ) . ':stale-request';
Installer::maybe_install();
vfg_assert( 1 === $GLOBALS['vfg_dbdelta_calls'][5] && '5' === get_option( 'veridis_fraud_guard_db_version' ), 'An expired lock did not recover.' );

// A request that observed v4 re-checks after locking and avoids duplicate schema work.
vfg_switch_site( 9 );
vfg_seed_v4( 9 );
$GLOBALS['vfg_complete_on_lock'] = true;
Installer::maybe_install();
vfg_assert( 0 === ( $GLOBALS['vfg_dbdelta_calls'][9] ?? 0 ), 'A request repeated schema work completed by a concurrent request.' );

// Retry and lock state are isolated by each multisite blog's options table.
vfg_switch_site( 6 );
vfg_seed_v4( 6 );
$GLOBALS['vfg_fail_schema'][6] = true;
Installer::maybe_install();
vfg_switch_site( 7 );
vfg_seed_v4( 7 );
Installer::maybe_install();
vfg_assert( '4' === $GLOBALS['vfg_options'][6]['veridis_fraud_guard_db_version'], 'Site 6 failure state changed unexpectedly.' );
vfg_assert( '5' === $GLOBALS['vfg_options'][7]['veridis_fraud_guard_db_version'], 'Site 6 cooldown blocked site 7 migration.' );
vfg_assert( isset( $GLOBALS['vfg_options'][6]['veridis_fraud_guard_db_migration_retry_after'] ), 'Site 6 cooldown was not retained.' );
vfg_assert( ! isset( $GLOBALS['vfg_options'][7]['veridis_fraud_guard_db_migration_retry_after'] ), 'Site 7 inherited another site\'s cooldown.' );

// Unexpected lock-storage failures remain contained and do not expose their details.
vfg_switch_site( 8 );
vfg_seed_v4( 8 );
$GLOBALS['vfg_options'][8]['veridis_fraud_guard_db_migration_lock'] = ( time() - 1 ) . ':stale-request';
$GLOBALS['wpdb']->throw_on_lock_insert = true;
ob_start();
Installer::maybe_install();
$failure_output = (string) ob_get_clean();
$GLOBALS['wpdb']->throw_on_lock_insert = false;
vfg_assert( '' === $failure_output, 'Migration failure exposed internal database details.' );
vfg_assert( '4' === get_option( 'veridis_fraud_guard_db_version' ), 'Lock failure incorrectly saved schema v5.' );

ob_start();
Installer::render_admin_notice();
$notice = (string) ob_get_clean();
vfg_assert( str_contains( $notice, 'Retry database upgrade now' ), 'Failed migration did not expose administrator recovery.' );
vfg_assert( ! str_contains( $notice, 'sensitive details' ), 'Administrator notice exposed a database failure detail.' );

Installer::register();
vfg_assert( isset( $GLOBALS['vfg_hooks']['admin_init'], $GLOBALS['vfg_hooks']['admin_notices'] ), 'Administrator recovery hooks were not registered.' );

echo "Installer retry harness: PASS\n";
