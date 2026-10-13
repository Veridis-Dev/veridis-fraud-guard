<?php
namespace Veridis\FraudGuard\Admin;

defined( 'ABSPATH' ) || exit;

use Veridis\FraudGuard\Core\Access;

final class AdminPage {
	private string $hook = '';

	public function register(): void {
		$this->hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Veridis Fraud Guard', 'veridis-fraud-guard' ),
			__( 'Fraud Guard', 'veridis-fraud-guard' ),
			Access::CAPABILITY,
			'veridis-fraud-guard',
			array( $this, 'render' )
		);
	}

	public function hook(): string {
		return $this->hook;
	}

	public function render(): void {
		if ( ! Access::allowed() ) {
			wp_die( esc_html__( 'You do not have access to Veridis Fraud Guard.', 'veridis-fraud-guard' ) );
		}
		?>
		<div id="veridis-fraud-guard-root" class="vfg-app">
			<noscript><?php echo esc_html__( 'Veridis Fraud Guard requires JavaScript for its administration screen.', 'veridis-fraud-guard' ); ?></noscript>
		</div>
		<?php
	}
}
