<?php
namespace Veridis\FraudGuard\Admin;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Core\Access;
use Veridis\FraudGuard\Orders\OrderBlocklistManager;

final class OrdersList {
	private const COLUMN = 'veridis_fraud_guard';

	public function __construct(
		private OrderBlocklistManager $manager,
		private string $plugin_file,
		private string $version
	) {}

	public function register(): void {
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_classic_column' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** @param array<string, string> $columns
	 *  @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		if ( ! Access::allowed() || isset( $columns[ self::COLUMN ] ) ) {
			return $columns;
		}

		$result   = array();
		$inserted = false;
		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;
			if ( ! $inserted && in_array( $key, array( 'order_status', 'status' ), true ) ) {
				$result[ self::COLUMN ] = __( 'Fraud Guard', 'veridis-fraud-guard' );
				$inserted               = true;
			}
		}

		if ( ! $inserted ) {
			$result[ self::COLUMN ] = __( 'Fraud Guard', 'veridis-fraud-guard' );
		}

		return $result;
	}

	public function render_classic_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$this->render_status( wc_get_order( $post_id ) );
	}

	public function render_hpos_column( string $column, mixed $order ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$this->render_status( $order instanceof \WC_Order ? $order : wc_get_order( $order ) );
	}

	public function enqueue( string $hook ): void {
		if ( ! Access::allowed() || ! $this->is_orders_list_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'veridis-fraud-guard-orders-list',
			plugin_dir_url( $this->plugin_file ) . 'assets/order-list.css',
			array(),
			$this->version
		);
	}

	private function render_status( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			$this->render_unknown();
			return;
		}

		$status = $this->manager->status( $order );
		if ( ! $status['determined'] ) {
			$this->render_unknown();
			return;
		}

		if ( 0 === $status['blocked_count'] ) {
			echo '<span class="vfg-order-list-status is-clear">' . esc_html__( 'Clear', 'veridis-fraud-guard' ) . '</span>';
			return;
		}

		$labels = array_map( array( $this, 'type_label' ), $status['blocked_types'] );
		$title  = sprintf(
			/* translators: %s: comma-separated blocklisted identifier types. */
			__( 'Blocked identifiers: %s', 'veridis-fraud-guard' ),
			implode( ', ', $labels )
		);
		$label = sprintf(
			/* translators: %s: localized number of matched identifier types. */
			__( 'Blocked · %s', 'veridis-fraud-guard' ),
			number_format_i18n( $status['blocked_count'] )
		);

		printf(
			'<span class="vfg-order-list-status is-blocked" title="%1$s" aria-label="%2$s">%3$s</span>',
			esc_attr( $title ),
			esc_attr( $label . '. ' . $title ),
			esc_html( $label )
		);
	}

	private function render_unknown(): void {
		echo '<span class="vfg-order-list-status is-unknown" aria-label="' . esc_attr__( 'Fraud Guard status unavailable', 'veridis-fraud-guard' ) . '">&mdash;</span>';
	}

	private function type_label( string $type ): string {
		$labels = array(
			'email'  => __( 'Email', 'veridis-fraud-guard' ),
			'domain' => __( 'Domain', 'veridis-fraud-guard' ),
			'phone'  => __( 'Phone', 'veridis-fraud-guard' ),
			'ip'     => __( 'IP', 'veridis-fraud-guard' ),
		);

		return $labels[ $type ] ?? $type;
	}

	private function is_orders_list_screen( string $hook ): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		if ( 'edit.php' === $hook && 'edit-shop_order' === $screen->id ) {
			return true;
		}

		if ( 'woocommerce_page_wc-orders' !== $hook ) {
			return false;
		}

		$hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		$action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		return $screen->id === $hpos_screen && 'edit' !== $action;
	}
}
