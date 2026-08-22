<?php
/**
 * Composition root: builds the object graph and wires it to WordPress hooks.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Plugin {

	public const VERSION = '3.5.2';

	private static ?self $instance = null;

	public readonly Settings $settings;
	public readonly Scan_Session $session;
	public readonly Results_Renderer $renderer;
	public readonly Scan_Runner $runner;
	public readonly Admin_Page $admin_page;
	public readonly Scan_Controller $scan_controller;

	private function __construct() {
		$this->settings = new Settings();

		$this->session         = new Scan_Session();
		$this->renderer        = new Results_Renderer();
		$this->runner          = new Scan_Runner(
			$this->settings,
			new Integrity_Scanner(),
			new File_Scanner( $this->settings ),
			new Db_Scanner(),
			new Update_Scanner()
		);
		$this->admin_page      = new Admin_Page( $this->settings, $this->runner, $this->renderer );
		$this->scan_controller = new Scan_Controller( $this->runner, $this->session, $this->renderer );

		$this->add_hooks();
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate( bool $network_wide = false ): void {
		self::for_each_site(
			$network_wide,
			static function (): void {
				if ( ! wp_next_scheduled( Scan_Controller::HOOK_WEEKLY ) ) {
					wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', Scan_Controller::HOOK_WEEKLY );
				}
			}
		);
	}

	public static function deactivate( bool $network_wide = false ): void {
		self::for_each_site(
			$network_wide,
			static function (): void {
				// 継続イベントは owner を引数に持つため、フック単位で全件消す。
				wp_unschedule_hook( Scan_Controller::HOOK_WEEKLY );
				wp_unschedule_hook( Scan_Controller::HOOK_CONTINUE );
				$lock = get_option( Settings::LOCK_KEY, false );
				if ( is_array( $lock ) && is_string( $lock['owner'] ?? null ) ) {
					delete_transient( Settings::SESSION_PREFIX . $lock['owner'] );
				}
				delete_option( Settings::LOCK_KEY );
			}
		);
	}

	private function add_hooks(): void {
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . Scan_Controller::AJAX_ACTION, array( $this->scan_controller, 'ajax_run_scan' ) );
		add_action( Scan_Controller::HOOK_WEEKLY, array( $this->scan_controller, 'run_scheduled_scan' ) );
		add_action( Scan_Controller::HOOK_CONTINUE, array( $this->scan_controller, 'continue_scheduled_scan' ) );
	}

	private static function for_each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}
	}
}
