<?php
namespace Veridis\FraudGuard\Admin;

defined( 'ABSPATH' ) || exit;

final class Assets {
	private AdminPage $page;
	private string $plugin_file;
	private string $version;

	public function __construct( AdminPage $page, string $plugin_file, string $version ) {
		$this->page        = $page;
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	public function enqueue( string $hook ): void {
		if ( $hook !== $this->page->hook() ) {
			return;
		}

		$path   = plugin_dir_path( $this->plugin_file );
		$url    = plugin_dir_url( $this->plugin_file );
		$config = array(
			'restUrl'   => esc_url_raw( rest_url( 'veridis-fraud-guard/v1/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'timeZone'  => wp_timezone_string(),
			'exportUrl' => esc_url_raw(
				html_entity_decode(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=' . BlocklistExportController::ACTION ),
						BlocklistExportController::NONCE_ACTION
					),
					ENT_QUOTES,
					'UTF-8'
				)
			),
			'version'   => $this->version,
		);

		$dev_server = '';
		if (
			defined( 'VERIDIS_FRAUD_GUARD_DEV_SERVER' )
			&& in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
		) {
			$dev_server = untrailingslashit( esc_url_raw( VERIDIS_FRAUD_GUARD_DEV_SERVER ) );
		}

		if ( '' !== $dev_server ) {
			wp_enqueue_script( 'veridis-fraud-guard-vite', $dev_server . '/@vite/client', array(), null, true );
			wp_enqueue_script(
				'veridis-fraud-guard-app',
				$dev_server . '/src/AdminApp/main.tsx',
				array( 'wp-element', 'wp-i18n', 'veridis-fraud-guard-vite' ),
				null,
				true
			);
			add_action(
				'admin_head',
				static function () use ( $dev_server ): void {
					$preamble = 'import RefreshRuntime from ' . wp_json_encode( $dev_server . '/@react-refresh' ) . '; RefreshRuntime.injectIntoGlobalHook(window); window.$RefreshReg$ = () => {}; window.$RefreshSig$ = () => (type) => type; window.__vite_plugin_react_preamble_installed__ = true;';
					wp_print_inline_script_tag( $preamble, array( 'type' => 'module' ) );
				}
			);
		} else {
			$manifest_path = $path . 'build/manifest.json';
			$manifest      = is_readable( $manifest_path ) ? json_decode( (string) file_get_contents( $manifest_path ), true ) : array();
			$entry         = is_array( $manifest ) ? ( $manifest['src/AdminApp/main.tsx'] ?? null ) : null;

			if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_file( $path . 'build/' . $entry['file'] ) ) {
				add_action(
					'admin_notices',
					static function (): void {
						echo '<div class="notice notice-error"><p>' . esc_html__( 'Veridis Fraud Guard assets are missing. Run npm install and npm run build in the plugin directory.', 'veridis-fraud-guard' ) . '</p></div>';
					}
				);
				return;
			}

			foreach ( $entry['css'] ?? array() as $index => $stylesheet ) {
				wp_enqueue_style(
					'veridis-fraud-guard-' . (int) $index,
					$url . 'build/' . ltrim( (string) $stylesheet, '/' ),
					array(),
					$this->version
				);
			}

			wp_enqueue_script(
				'veridis-fraud-guard-app',
				$url . 'build/' . ltrim( (string) $entry['file'], '/' ),
				array( 'wp-element', 'wp-i18n' ),
				$this->version,
				true
			);
		}

		wp_set_script_translations( 'veridis-fraud-guard-app', 'veridis-fraud-guard', $path . 'languages' );
		wp_add_inline_script(
			'veridis-fraud-guard-app',
			'window.veridisFraudGuard = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
		add_filter( 'script_loader_tag', array( $this, 'module_tag' ), 10, 3 );
	}

	public function module_tag( string $tag, string $handle, string $src ): string {
		unset( $src );
		if ( ! in_array( $handle, array( 'veridis-fraud-guard-app', 'veridis-fraud-guard-vite' ), true ) ) {
			return $tag;
		}

		$pattern    = '/<script(?![^>]*\\btype=)(?=[^>]*\\bid="' . preg_quote( $handle . '-js', '/' ) . '")/';
		$module_tag = preg_replace( $pattern, '<script type="module"', $tag, 1 );
		return is_string( $module_tag ) ? $module_tag : $tag;
	}
}
