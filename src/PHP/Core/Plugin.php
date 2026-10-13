<?php
namespace Veridis\FraudGuard\Core;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Admin\AdminPage;
use Veridis\FraudGuard\Admin\Assets;
use Veridis\FraudGuard\Admin\BlocklistExportController;
use Veridis\FraudGuard\Admin\OrderPanel;
use Veridis\FraudGuard\Admin\OrdersList;
use Veridis\FraudGuard\Activity\ActivityRepository;
use Veridis\FraudGuard\Activity\ActivityPrivacy;
use Veridis\FraudGuard\Activity\ActivityService;
use Veridis\FraudGuard\Activity\BlocklistActivityRecorder;
use Veridis\FraudGuard\Blocklist\BlocklistNormalizer;
use Veridis\FraudGuard\Blocklist\BlocklistRepository;
use Veridis\FraudGuard\Blocklist\BlocklistService;
use Veridis\FraudGuard\Checkout\CheckoutBlocklistEvaluator;
use Veridis\FraudGuard\Checkout\CheckoutActivityRecorder;
use Veridis\FraudGuard\Checkout\ClassicCheckoutBlockEnforcement;
use Veridis\FraudGuard\Checkout\CodCheckoutHoldEnforcement;
use Veridis\FraudGuard\Checkout\StoreApiCheckoutBlockEnforcement;
use Veridis\FraudGuard\CSV\BlocklistCsvExporter;
use Veridis\FraudGuard\CSV\BlocklistCsvImporter;
use Veridis\FraudGuard\REST\BlocklistController;
use Veridis\FraudGuard\REST\BlocklistImportController;
use Veridis\FraudGuard\REST\ActivityController;
use Veridis\FraudGuard\REST\CheckoutSettingsController;
use Veridis\FraudGuard\REST\OrderBlocklistController;
use Veridis\FraudGuard\REST\OverviewController;
use Veridis\FraudGuard\Orders\OrderBlocklistManager;
use Veridis\FraudGuard\Orders\OrderIdentityResolver;

final class Plugin {
	private string $file;
	private string $version;

	public function __construct( string $file, string $version ) {
		$this->file    = $file;
		$this->version = $version;
	}

	public function boot(): void {
		Installer::register();
		Installer::maybe_install();

		$page             = new AdminPage();
		$activity_store   = new ActivityRepository();
		$activity_privacy = new ActivityPrivacy( $activity_store );
		$repository       = new BlocklistRepository();
		$blocklist_audit  = new BlocklistActivityRecorder( $activity_store );
		$service          = new BlocklistService( $repository, new BlocklistNormalizer(), $blocklist_audit );
		$resolver         = new OrderIdentityResolver();
		$orders           = new OrderBlocklistManager( $service, $resolver );
		$checkout         = new CheckoutBlocklistEvaluator( $service, $resolver );
		$activity         = new CheckoutActivityRecorder( $activity_store );
		$activity_service = new ActivityService( $activity_store );
		$classic_checkout = new ClassicCheckoutBlockEnforcement( $checkout, $activity );
		$cod_hold         = new CodCheckoutHoldEnforcement( $checkout, $activity );
		$store_checkout   = new StoreApiCheckoutBlockEnforcement( $checkout, $activity );
		$order_panel      = new OrderPanel( $orders, $this->file, $this->version );
		$order_list       = new OrdersList( $orders, $this->file, $this->version );
		$csv_import       = new BlocklistImportController( new BlocklistCsvImporter( $service ) );
		$csv_export       = new BlocklistExportController( new BlocklistCsvExporter( $repository ) );
		$classic_checkout->register();
		$cod_hold->register();
		$store_checkout->register();
		$activity_privacy->register();
		add_action( 'admin_menu', array( $page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( new Assets( $page, $this->file, $this->version ), 'enqueue' ) );
		add_action( 'add_meta_boxes', array( $order_panel, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $order_panel, 'enqueue' ) );
		$order_list->register();
		$csv_export->register();
		add_action( 'rest_api_init', array( new OverviewController( $this->version, $service, $activity_service ), 'register' ) );
		add_action( 'rest_api_init', array( new ActivityController( $activity_service ), 'register' ) );
		add_action( 'rest_api_init', array( new BlocklistController( $service ), 'register' ) );
		add_action( 'rest_api_init', array( $csv_import, 'register' ) );
		add_action( 'rest_api_init', array( new OrderBlocklistController( $orders ), 'register' ) );
		add_action( 'rest_api_init', array( new CheckoutSettingsController(), 'register' ) );
	}
}
