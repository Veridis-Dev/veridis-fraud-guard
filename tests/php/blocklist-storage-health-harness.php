<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

$GLOBALS['vfg_options']       = array();
$GLOBALS['vfg_option_writes'] = array( 'update' => 0, 'delete' => 0 );
$GLOBALS['vfg_can_manage']    = false;
$GLOBALS['vfg_route']         = array();

function __( string $message, string $domain = '' ): string { unset( $domain ); return $message; }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? ''; }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function is_email( string $value ): bool { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( string $value ): array|false { return parse_url( $value ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function apply_filters( string $hook, mixed $value ): mixed { unset( $hook ); return $value; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function current_time( string $type, bool $gmt = false ): string { unset( $type, $gmt ); return '2026-10-11 12:00:00'; }
function current_user_can( string $capability ): bool { return 'manage_woocommerce' === $capability && $GLOBALS['vfg_can_manage']; }
function get_option( string $option, mixed $default = false ): mixed {
	return array_key_exists( $option, $GLOBALS['vfg_options'] ) ? $GLOBALS['vfg_options'][ $option ] : $default;
}
function update_option( string $option, mixed $value, bool $autoload = false ): bool {
	unset( $autoload );
	$GLOBALS['vfg_options'][ $option ] = $value;
	++$GLOBALS['vfg_option_writes']['update'];
	return true;
}
function delete_option( string $option ): bool {
	unset( $GLOBALS['vfg_options'][ $option ] );
	++$GLOBALS['vfg_option_writes']['delete'];
	return true;
}
function register_rest_route( string $namespace, string $route, array $config ): void {
	$GLOBALS['vfg_route'] = compact( 'namespace', 'route', 'config' );
}

final class WP_Error {
	/** @var array<string, string[]> */
	public array $errors = array();
	public function __construct( string $code = '', string $message = '', mixed $data = null ) {
		unset( $data );
		if ( '' !== $code ) $this->add( $code, $message );
	}
	public function add( string $code, string $message ): void { $this->errors[ $code ][] = $message; }
}

final class WP_REST_Server { public const READABLE = 'GET'; }
final class WP_REST_Response {
	/** @var array<string, string> */
	public array $headers = array();
	public function __construct( private mixed $data ) {}
	public function header( string $name, string $value ): void { $this->headers[ $name ] = $value; }
	public function get_data(): mixed { return $this->data; }
}

final class WC_Geolocation {
	public static function get_ip_address(): string { return '203.0.113.8'; }
}

final class wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	/** @var string[] */
	public array $matching_types = array();
	/** @var array<int, array{type: string, entry_count: int}> */
	public array $summary_rows = array();
	public string $matching_failure = '';
	public string $summary_failure = '';
	public int $activity_inserts = 0;

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		return preg_replace_callback(
			'/%[dis]/',
			static function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position++ ];
				return match ( $match[0] ) {
					'%d' => (string) (int) $value,
					'%i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
					default => "'" . addslashes( (string) $value ) . "'",
				};
			},
			$query
		) ?? $query;
	}

	/** @return string[] */
	public function get_col( string $query ): array {
		unset( $query );
		$this->last_error = $this->matching_failure;
		return '' === $this->matching_failure ? $this->matching_types : array();
	}

	/** @return object[] */
	public function get_results( string $query ): array {
		if ( str_contains( $query, 'veridis_fraud_guard_blocklist' ) ) {
			$this->last_error = $this->summary_failure;
			return '' === $this->summary_failure
				? array_map( static fn( array $row ): object => (object) $row, $this->summary_rows )
				: array();
		}

		$this->last_error = '';
		return array();
	}

	public function get_row( string $query ): object {
		unset( $query );
		$this->last_error = '';
		return (object) array( 'total' => 0, 'today' => 0, 'last_7_days' => 0 );
	}

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data, array $formats = array() ): int {
		unset( $table, $data, $formats );
		++$this->activity_inserts;
		return 1;
	}
}

require_once __DIR__ . '/../../src/PHP/Core/Access.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistEntry.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistNormalizer.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistRepository.php';
require_once __DIR__ . '/../../src/PHP/Blocklist/BlocklistService.php';
require_once __DIR__ . '/../../src/PHP/Orders/OrderIdentityResolver.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityRepository.php';
require_once __DIR__ . '/../../src/PHP/Activity/MatchedIdentifierSet.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityIdentifierRetention.php';
require_once __DIR__ . '/../../src/PHP/Activity/ActivityService.php';
require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutMode.php';
require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutRejectionMessage.php';
require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutBlocklistEvaluator.php';
require_once __DIR__ . '/../../src/PHP/Checkout/CheckoutActivityRecorder.php';
require_once __DIR__ . '/../../src/PHP/Checkout/ClassicCheckoutBlockEnforcement.php';
require_once __DIR__ . '/../../src/PHP/REST/OverviewController.php';

use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Checkout\CheckoutActivityRecorder;
use Veridis\FraudGuard\Checkout\CheckoutBlocklistEvaluator;
use Veridis\FraudGuard\Checkout\CheckoutMode;
use Veridis\FraudGuard\Checkout\ClassicCheckoutBlockEnforcement;
use Veridis\FraudGuard\Core\Access;
use Veridis\FraudGuard\Orders\OrderIdentityResolver;
use Veridis\FraudGuard\REST\OverviewController;

function vfg_assert( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}

$database  = new wpdb();
$repository = new BlocklistRepository( $database );
$service    = new BlocklistService( $repository, new BlocklistNormalizer() );
$evaluator  = new CheckoutBlocklistEvaluator( $service, new OrderIdentityResolver() );

// Successful empty and matching queries are distinct from storage failures.
$empty = $evaluator->evaluate( 'buyer@example.com', '', '203.0.113.8' );
vfg_assert( false === $empty['blocked'] && true === $empty['storage_available'], 'A successful zero-match query was treated as a storage failure.' );
vfg_assert( array( 'update' => 0, 'delete' => 0 ) === $GLOBALS['vfg_option_writes'], 'Normal checkout caused a health-option write.' );

$database->matching_types = array( 'email', 'domain' );
$matched = $evaluator->evaluate( 'buyer@example.com', '', '203.0.113.8' );
vfg_assert( true === $matched['blocked'] && true === $matched['storage_available'] && array( 'email', 'domain' ) === $matched['matched_types'], 'A successful match query regressed.' );

// Missing, inaccessible, and failed table queries fail open and set one bounded signal.
foreach ( array( 'Table does not exist', 'SELECT command denied', 'Database connection lost' ) as $index => $failure ) {
	$database->matching_failure = $failure;
	$failed = $evaluator->evaluate( 'private@example.com', '+40712345678', '203.0.113.8' );
	vfg_assert( false === $failed['blocked'] && false === $failed['storage_available'], "Storage failure {$index} did not fail open." );
}
vfg_assert( 1 === $GLOBALS['vfg_option_writes']['update'], 'Repeated failures wrote the health option more than once.' );
vfg_assert( BlocklistRepository::STORAGE_UNAVAILABLE === $repository->storage_health()['status'], 'The storage failure signal was not retained.' );
$unverified = $service->match_types( array( 'email' => 'not-an-email' ) );
vfg_assert( false === $unverified['storage_available'], 'An input with no query incorrectly declared known-failing storage healthy.' );

// Classic Block checkout remains available and does not create false Activity.
$GLOBALS['vfg_options'][ CheckoutMode::OPTION ] = CheckoutMode::BLOCK;
$activity = new CheckoutActivityRecorder( new ActivityRepository( $database ) );
$classic  = new ClassicCheckoutBlockEnforcement( $evaluator, $activity );
$errors   = new WP_Error();
$classic->validate( array( 'billing_email' => 'private@example.com' ), $errors );
vfg_assert( array() === $errors->errors && 0 === $database->activity_inserts, 'Storage failure rejected checkout or recorded a false intervention.' );

// A verified successful query clears the persisted failure once; normal reads remain write-free.
$database->matching_failure = '';
$database->matching_types   = array();
$recovered = $evaluator->evaluate( 'buyer@example.com', '', '203.0.113.8' );
vfg_assert( true === $recovered['storage_available'] && BlocklistRepository::STORAGE_HEALTHY === $repository->storage_health()['status'], 'Verified storage recovery did not clear the failure state.' );
vfg_assert( 1 === $GLOBALS['vfg_option_writes']['delete'], 'Recovery did not clear the health option exactly once.' );
$evaluator->evaluate( 'buyer@example.com', '', '203.0.113.8' );
vfg_assert( array( 'update' => 1, 'delete' => 1 ) === $GLOBALS['vfg_option_writes'], 'Healthy checkout caused an unnecessary option write.' );

// Dashboard performs its own bounded storage verification and exposes only a sanitized admin status.
$database->summary_failure = 'SELECT denied for private@example.com in wp_veridis_fraud_guard_blocklist';
$activity_service = new ActivityService( new ActivityRepository( $database ) );
$controller = new OverviewController( '1.0.0', $service, $activity_service );
$controller->register();
$permission = $GLOBALS['vfg_route']['config']['permission_callback'];
vfg_assert( false === $permission(), 'An unauthorized user could access Dashboard health.' );
$GLOBALS['vfg_can_manage'] = true;
vfg_assert( true === $permission() && true === Access::allowed(), 'An authorized WooCommerce administrator could not access Dashboard health.' );

$payload = $controller->get()->get_data();
vfg_assert( BlocklistRepository::STORAGE_UNAVAILABLE === $payload['checkout_protection']['storage']['status'], 'Dashboard did not report a live storage failure.' );
vfg_assert( array( 'total' => 0, 'email' => 0, 'domain' => 0, 'phone' => 0, 'ip' => 0 ) === $payload['blocklist'], 'Dashboard failure fallback did not remain bounded and non-disruptive.' );
$serialized = json_encode( $payload ) ?: '';
vfg_assert( ! str_contains( $serialized, 'SELECT' ) && ! str_contains( $serialized, 'private@example.com' ) && ! str_contains( $serialized, 'denied' ), 'Dashboard exposed SQL, database details, or a customer identifier.' );

$database->summary_failure = '';
$database->summary_rows    = array( array( 'type' => 'email', 'entry_count' => 2 ) );
$healthy_payload = $controller->get()->get_data();
vfg_assert( BlocklistRepository::STORAGE_HEALTHY === $healthy_payload['checkout_protection']['storage']['status'] && 2 === $healthy_payload['blocklist']['total'], 'Dashboard verification did not recover after storage became healthy.' );

echo "Blocklist storage health harness: PASS\n";
