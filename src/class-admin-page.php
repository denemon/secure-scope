<?php
/**
 * Admin screen: menu entry, assets, settings form handling and rendering.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Admin_Page {

	public const MENU_SLUG   = 'pyro-scope';
	public const HOOK_SUFFIX = 'toplevel_page_pyro-scope';

	public function __construct(
		private readonly Settings $settings,
		private readonly Scan_Runner $runner,
		private readonly Results_Renderer $renderer
	) {
	}

	public function register_menu(): void {
		add_menu_page(
			'Pyro Scope',
			'Pyro Scope',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-shield'
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}
		$base_url = plugin_dir_url( dirname( __DIR__ ) . '/pyro-scope.php' );
		wp_enqueue_style(
			'pyro-scope-admin-style',
			$base_url . 'assets/css/pyro-scope-admin.css',
			array(),
			Plugin::VERSION
		);
		wp_enqueue_script(
			'pyro-scope-admin-script',
			$base_url . 'assets/js/pyro-scope-admin.js',
			array( 'jquery' ),
			Plugin::VERSION,
			true
		);
		wp_localize_script(
			'pyro-scope-admin-script',
			'PyroScopeAjax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( Scan_Controller::NONCE ),
			)
		);
	}

	public function render(): void {
		$settings_status = $this->handle_settings_post();

		// --- テンプレート変数の準備 ---
		$scan_results_html = '';
		$last_results      = $this->runner->last_results();
		if ( false !== $last_results ) {
			$scan_results_html = $this->renderer->render( $last_results );
		}

		$options             = $this->settings->all();
		$last_scan_timestamp = get_option( Settings::TIMESTAMP_KEY );
		include dirname( __DIR__ ) . '/templates/admin-page.php';
	}

	/** Persist a submitted settings form; null means that no form was submitted. */
	private function handle_settings_post(): ?bool {
		if ( ! isset( $_POST['pyro_scope_save_settings'] ) ) {
			return null;
		}

		check_admin_referer( 'pyro_scope_settings_nonce' );

		$whitelist = array();
		if ( isset( $_POST['whitelist_paths'] ) && is_string( $_POST['whitelist_paths'] ) ) {
			$lines = explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['whitelist_paths'] ) ) );
			foreach ( $lines as $line ) {
				$normalized = Settings::normalize_whitelist_entry( $line );
				if ( null !== $normalized ) {
					$whitelist[] = $normalized;
				}
			}
			$whitelist = array_values( array_unique( $whitelist ) );
		}

		return $this->settings->save(
			array(
				'enable_scanner'   => isset( $_POST['enable_scanner'] ) ? 1 : 0,
				'enable_integrity' => isset( $_POST['enable_integrity'] ) ? 1 : 0,
				'enable_updates'   => isset( $_POST['enable_updates'] ) ? 1 : 0,
				'whitelist_paths'  => $whitelist,
			)
		);
	}
}
