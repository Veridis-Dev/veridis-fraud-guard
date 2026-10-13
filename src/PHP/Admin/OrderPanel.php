<?php
namespace Veridis\FraudGuard\Admin;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Core\Access;
use Veridis\FraudGuard\Orders\OrderBlocklistManager;

final class OrderPanel {
	private const SCRIPT_HANDLE = 'veridis-fraud-guard-order-panel';
	private const COD_HOLD_APPLIED_META = '_veridis_fraud_guard_cod_hold_applied';
	private const NON_COD_HOLD_WARNING_META = '_veridis_fraud_guard_checkout_warning_added';
	private const WARN_RECORDED_META = '_veridis_fraud_guard_warn_recorded';

	public function __construct(
		private OrderBlocklistManager $manager,
		private string $plugin_file,
		private string $version
	) {}

	public function register(): void {
		if ( ! Access::allowed() ) {
			return;
		}

		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'veridis-fraud-guard-order',
				__( 'Veridis Fraud Guard', 'veridis-fraud-guard' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	public function render( mixed $order_or_post ): void {
		$order = $this->resolve_order( $order_or_post );
		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Fraud Guard could not load this order.', 'veridis-fraud-guard' ) . '</p>';
			return;
		}

		$identifiers   = $this->manager->state( $order );
		$blocked_count = count(
			array_filter(
				$identifiers,
				static fn( array $identifier ): bool => (bool) $identifier['blocked']
			)
		);
		$missing_count = count(
			array_filter(
				$identifiers,
				static fn( array $identifier ): bool => (bool) $identifier['bulk_actionable'] && ! (bool) $identifier['blocked']
			)
		);
		$hold_active          = 'yes' === $order->get_meta( self::COD_HOLD_APPLIED_META, true ) && $order->has_status( 'on-hold' );
		$non_cod_hold_warning = 'yes' === $order->get_meta( self::NON_COD_HOLD_WARNING_META, true );
		$warn_recorded        = 'yes' === $order->get_meta( self::WARN_RECORDED_META, true );
		$details_id           = 'vfg-order-identifiers-' . $order->get_id();
		?>
		<div class="vfg-order-panel" data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>" data-blocked-count="<?php echo esc_attr( (string) $blocked_count ); ?>">
			<div class="vfg-order-panel__status <?php echo $blocked_count > 0 ? 'has-blocked' : 'is-clear'; ?>" data-vfg-status>
				<span class="vfg-order-panel__status-mark" aria-hidden="true"></span>
				<span class="vfg-order-panel__status-copy">
					<strong data-vfg-status-title><?php echo esc_html( $blocked_count > 0 ? __( 'Blocked customer', 'veridis-fraud-guard' ) : __( 'Customer clear', 'veridis-fraud-guard' ) ); ?></strong>
					<span data-vfg-status-text><?php echo esc_html( $this->blocked_status_label( $blocked_count ) ); ?></span>
				</span>
			</div>

			<?php if ( $hold_active ) : ?>
				<?php $this->render_checkout_notice( __( 'Held by Fraud Guard', 'veridis-fraud-guard' ), __( 'This COD order was placed on hold because the customer matched the blocklist. Review before shipping.', 'veridis-fraud-guard' ) ); ?>
			<?php endif; ?>

			<?php if ( $non_cod_hold_warning ) : ?>
				<?php $this->render_checkout_notice( __( 'Flagged by Fraud Guard', 'veridis-fraud-guard' ), __( 'This non-COD order matched the blocklist while Hold mode was active. Fraud Guard did not change the payment or order status.', 'veridis-fraud-guard' ) ); ?>
			<?php endif; ?>

			<?php if ( $warn_recorded ) : ?>
				<?php $this->render_checkout_notice( __( 'Flagged by Fraud Guard', 'veridis-fraud-guard' ), __( 'This order matched the blocklist at checkout. Review the customer details before fulfillment.', 'veridis-fraud-guard' ) ); ?>
			<?php endif; ?>

			<?php $this->render_identity_summary( $identifiers ); ?>

			<?php if ( $identifiers ) : ?>
				<button type="button" class="vfg-order-panel__primary" data-vfg-primary data-primary-action="<?php echo esc_attr( $missing_count > 0 ? 'bulk-block' : 'manage' ); ?>" data-focus-action="<?php echo esc_attr( $blocked_count > 0 ? 'unblock' : 'block' ); ?>"<?php if ( 0 === $missing_count ) : ?> aria-controls="<?php echo esc_attr( $details_id ); ?>" aria-expanded="false"<?php endif; ?>>
					<span data-vfg-primary-label><?php echo esc_html( $missing_count > 0 ? __( 'Block customer', 'veridis-fraud-guard' ) : __( 'Manage identifiers', 'veridis-fraud-guard' ) ); ?></span>
					<span class="vfg-order-panel__primary-arrow" aria-hidden="true">→</span>
					<span class="vfg-order-panel__spinner" aria-hidden="true"></span>
				</button>
				<details class="vfg-order-panel__details" data-vfg-details id="<?php echo esc_attr( $details_id ); ?>">
					<summary>
						<span><?php echo esc_html__( 'Individual identifiers', 'veridis-fraud-guard' ); ?></span>
						<span class="vfg-order-panel__chevron" aria-hidden="true"></span>
					</summary>
					<div class="vfg-order-panel__identifiers" data-vfg-identifiers>
						<?php foreach ( $identifiers as $identifier ) : ?>
							<?php $this->render_identifier( $identifier ); ?>
						<?php endforeach; ?>
					</div>
				</details>
			<?php else : ?>
				<p class="vfg-order-panel__empty"><?php echo esc_html__( 'No customer identifiers are available on this order.', 'veridis-fraud-guard' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function enqueue( string $hook ): void {
		if ( ! $this->is_order_edit_screen( $hook ) || ! Access::allowed() ) {
			return;
		}

		$path       = plugin_dir_path( $this->plugin_file );
		$url        = plugin_dir_url( $this->plugin_file );
		$dev_server = '';
		if (
			defined( 'VERIDIS_FRAUD_GUARD_DEV_SERVER' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
		) {
			$dev_server = untrailingslashit( esc_url_raw( VERIDIS_FRAUD_GUARD_DEV_SERVER ) );
		}

		if ( '' !== $dev_server ) {
			wp_enqueue_script( 'veridis-fraud-guard-order-vite', $dev_server . '/@vite/client', array(), null, true );
			wp_enqueue_script( self::SCRIPT_HANDLE, $dev_server . '/src/OrderPanel/main.ts', array( 'veridis-fraud-guard-order-vite' ), null, true );
		} else {
			$manifest_path = $path . 'build/manifest.json';
			$manifest      = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : array();
			$entry         = is_array( $manifest ) ? ( $manifest['src/OrderPanel/main.ts'] ?? null ) : null;

			if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_file( $path . 'build/' . $entry['file'] ) ) {
				add_action(
					'admin_notices',
					static function (): void {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Veridis Fraud Guard order assets are missing. Run npm run build in the plugin directory.', 'veridis-fraud-guard' ) . '</p></div>';
					}
				);
				return;
			}

			foreach ( $entry['css'] ?? array() as $index => $stylesheet ) {
				wp_enqueue_style(
					'veridis-fraud-guard-order-' . (int) $index,
					$url . 'build/' . ltrim( (string) $stylesheet, '/' ),
					array(),
					$this->version
				);
			}

			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				$url . 'build/' . ltrim( (string) $entry['file'], '/' ),
				array(),
				$this->version,
				true
			);
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.veridisFraudGuardOrder = ' . wp_json_encode( $this->script_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 3 );
	}

	public function module_tag( string $tag, string $handle, string $src ): string {
		unset( $src );
		if ( ! in_array( $handle, array( self::SCRIPT_HANDLE, 'veridis-fraud-guard-order-vite' ), true ) ) {
			return $tag;
		}

		$pattern    = '/<script(?![^>]*\\btype=)(?=[^>]*\\bid="' . preg_quote( $handle . '-js', '/' ) . '")/';
		$module_tag = preg_replace( $pattern, '<script type="module"', $tag, 1 );
		return is_string( $module_tag ) ? $module_tag : $tag;
	}

	private function render_checkout_notice( string $title, string $body ): void {
		?>
		<div class="vfg-order-panel__checkout-notice" role="note">
			<span class="vfg-order-panel__checkout-notice-mark" aria-hidden="true">!</span>
			<span class="vfg-order-panel__checkout-notice-copy">
				<strong><?php echo esc_html( $title ); ?></strong>
				<span><?php echo esc_html( $body ); ?></span>
			</span>
		</div>
		<?php
	}

	/** @param array<string, bool|int|string> $identifier */
	private function render_identifier( array $identifier ): void {
		$type       = (string) $identifier['type'];
		$blocked    = (bool) $identifier['blocked'];
		$actionable = (bool) $identifier['actionable'];
		?>
		<div class="vfg-order-panel__row" data-vfg-identifier="<?php echo esc_attr( $type ); ?>" data-vfg-blocked="<?php echo $blocked ? '1' : '0'; ?>" data-vfg-actionable="<?php echo $actionable ? '1' : '0'; ?>">
			<div class="vfg-order-panel__content">
				<span class="vfg-order-panel__label"><?php echo esc_html( $this->type_label( $type ) ); ?></span>
				<span class="vfg-order-panel__value" title="<?php echo esc_attr( (string) $identifier['value'] ); ?>"><?php echo esc_html( (string) $identifier['value'] ); ?></span>
			</div>
			<div class="vfg-order-panel__meta">
				<?php if ( $actionable ) : ?>
					<button type="button" class="vfg-order-panel__button vfg-order-panel__action <?php echo $blocked ? 'is-unblock' : 'is-block'; ?>" data-vfg-action="<?php echo esc_attr( $blocked ? 'unblock' : 'block' ); ?>" data-vfg-type="<?php echo esc_attr( $type ); ?>">
						<span data-vfg-button-label><?php echo esc_html( $blocked ? __( 'Unblock', 'veridis-fraud-guard' ) : _x( 'Block', 'verb: add an identifier to the blocklist', 'veridis-fraud-guard' ) ); ?></span>
						<span class="vfg-order-panel__spinner" aria-hidden="true"></span>
					</button>
				<?php else : ?>
					<span class="vfg-order-panel__unavailable"><?php echo esc_html__( 'Unavailable', 'veridis-fraud-guard' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** @param array<int, array<string, bool|int|string>> $identifiers */
	private function render_identity_summary( array $identifiers ): void {
		$summary_types = array( 'email', 'phone', 'ip' );
		?>
		<dl class="vfg-order-panel__summary">
			<?php foreach ( $identifiers as $identifier ) : ?>
				<?php if ( in_array( (string) $identifier['type'], $summary_types, true ) ) : ?>
					<div>
						<dt>
							<span class="dashicons <?php echo esc_attr( $this->summary_icon_class( (string) $identifier['type'] ) ); ?>" aria-hidden="true"></span>
							<span class="vfg-order-panel__sr-only"><?php echo esc_html( $this->type_label( (string) $identifier['type'] ) ); ?></span>
						</dt>
						<dd title="<?php echo esc_attr( (string) $identifier['value'] ); ?>"><?php echo esc_html( (string) $identifier['value'] ); ?></dd>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</dl>
		<?php
	}

	private function blocked_status_label( int $count ): string {
		if ( 0 === $count ) {
			return __( 'No blocked identifiers', 'veridis-fraud-guard' );
		}

		return sprintf(
			/* translators: %s: localized number of blocked order identifiers. */
			_n( '%s blocked identifier', '%s blocked identifiers', $count, 'veridis-fraud-guard' ),
			number_format_i18n( $count )
		);
	}

	private function summary_icon_class( string $type ): string {
		$icons = array(
			'email' => 'dashicons-email-alt',
			'phone' => 'dashicons-phone',
			'ip'    => 'dashicons-location',
		);

		return $icons[ $type ] ?? 'dashicons-admin-users';
	}

	private function resolve_order( mixed $order_or_post ): ?\WC_Order {
		if ( $order_or_post instanceof \WC_Order ) {
			return $order_or_post;
		}

		$order_id = is_object( $order_or_post ) && isset( $order_or_post->ID ) ? (int) $order_or_post->ID : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		return $order instanceof \WC_Order && 'shop_order' === $order->get_type() ? $order : null;
	}

	private function is_order_edit_screen( string $hook ): bool {
		if ( ! in_array( $hook, array( 'post.php', 'woocommerce_page_wc-orders' ), true ) ) {
			return false;
		}
		if ( 'woocommerce_page_wc-orders' === $hook ) {
			$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
			$order_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
			if ( 'edit' !== $action || $order_id < 1 ) {
				return false;
			}
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$order_screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$order_screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		return in_array( $screen->id, array_unique( $order_screens ), true );
	}

	/** @return array<string, mixed> */
	private function script_config(): array {
		return array(
			'restUrl' => esc_url_raw( rest_url( 'veridis-fraud-guard/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'strings' => array(
				'block'              => _x( 'Block', 'verb: add an identifier to the blocklist', 'veridis-fraud-guard' ),
				'unblock'            => __( 'Unblock', 'veridis-fraud-guard' ),
				'cancel'             => __( 'Cancel', 'veridis-fraud-guard' ),
				'blockTitle'         => __( 'Block this identifier?', 'veridis-fraud-guard' ),
				'blockDescription'   => __( 'This identifier will be added to the blocklist.', 'veridis-fraud-guard' ),
				'unblockTitle'       => __( 'Unblock this identifier?', 'veridis-fraud-guard' ),
				'unblockDescription' => __( 'This identifier will be removed from the blocklist.', 'veridis-fraud-guard' ),
				'closeDialog'        => __( 'Close dialog', 'veridis-fraud-guard' ),
				'working'            => __( 'Updating blocklist…', 'veridis-fraud-guard' ),
				'error'              => __( 'The blocklist could not be updated. Please try again.', 'veridis-fraud-guard' ),
				'email'              => __( 'Email', 'veridis-fraud-guard' ),
				'phone'              => __( 'Phone', 'veridis-fraud-guard' ),
				'ip'                 => __( 'IP address', 'veridis-fraud-guard' ),
				'domain'             => __( 'Email domain', 'veridis-fraud-guard' ),
				'blockedCountLabels' => $this->blocked_status_labels(),
				'blockCustomer'      => __( 'Block customer', 'veridis-fraud-guard' ),
				'manageBlocked'      => __( 'Manage identifiers', 'veridis-fraud-guard' ),
				'customerClear'      => __( 'Customer clear', 'veridis-fraud-guard' ),
				'blockedCustomer'    => __( 'Blocked customer', 'veridis-fraud-guard' ),
				'notifications'      => __( 'Notifications', 'veridis-fraud-guard' ),
				'dismissNotification' => __( 'Dismiss notification', 'veridis-fraud-guard' ),
			),
		);
	}

	/** @return string[] */
	private function blocked_status_labels(): array {
		$labels = array();
		for ( $count = 0; $count <= 4; $count++ ) {
			$labels[] = $this->blocked_status_label( $count );
		}

		return $labels;
	}

	private function type_label( string $type ): string {
		$labels = array(
			'email'  => __( 'Email', 'veridis-fraud-guard' ),
			'phone'  => __( 'Phone', 'veridis-fraud-guard' ),
			'ip'     => __( 'IP address', 'veridis-fraud-guard' ),
			'domain' => __( 'Email domain', 'veridis-fraud-guard' ),
		);

		return $labels[ $type ] ?? $type;
	}
}
