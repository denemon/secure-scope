<?php
/**
 * Entry points that drive a scan: the admin-ajax stepper and WP-Cron.
 * All scanning logic lives in Scan_Runner; this class only handles the
 * request/response and session lifecycle.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Scan_Controller {

	public const AJAX_ACTION   = 'pyro_scope_run_scan';
	public const NONCE         = 'pyro_scope_scan_nonce';
	public const HOOK_WEEKLY   = 'pyro_scope_weekly_scan_event';
	public const HOOK_CONTINUE = 'pyro_scope_continue_scheduled_scan';

	/** cron 1回あたりの壁時計予算。ブラウザを待たせないので AJAX より長く取る。 */
	private const CRON_SECONDS = 20;

	public function __construct(
		private readonly Scan_Runner $runner,
		private readonly Scan_Session $session,
		private readonly Results_Renderer $renderer
	) {
	}

	public function ajax_run_scan(): void {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$step = isset( $_POST['step'] ) && is_string( $_POST['step'] )
			? sanitize_key( wp_unslash( $_POST['step'] ) )
			: 'start';

		if ( 'start' === $step ) {
			$scan_id = Scan_Session::new_id();
			if ( ! $this->session->acquire( $scan_id ) ) {
				wp_send_json_error( array( 'message' => 'Another scan is already running.' ), 409 );
			}

			try {
				$scan_data = $this->runner->new_scan_data();
				$steps     = $this->runner->enabled_steps( $scan_data['modules'] );
				$stored    = $this->session->store(
					$scan_id,
					array(
						'data'  => $scan_data,
						'steps' => $steps,
					)
				);
			} catch ( \Throwable ) {
				$stored = false;
			}
			if ( ! $stored ) {
				$this->session->forget( $scan_id );
				$this->session->release( $scan_id );
				wp_send_json_error( array( 'message' => 'Could not initialize the scan.' ), 500 );
			}

			wp_send_json_success(
				array(
					'scan_id' => $scan_id,
					'steps'   => $steps,
				)
			);
		}

		$scan_id = isset( $_POST['scan_id'] ) && is_string( $_POST['scan_id'] )
			? sanitize_key( wp_unslash( $_POST['scan_id'] ) )
			: '';
		if ( ! Scan_Session::is_valid_id( $scan_id ) || ! $this->session->owns( $scan_id ) ) {
			wp_send_json_error( array( 'message' => 'The scan session is invalid or has expired.' ), 409 );
		}

		if ( 'cancel' === $step ) {
			$this->session->forget( $scan_id );
			$this->session->release( $scan_id );
			wp_send_json_success( array() );
		}

		$session = $this->session->load( $scan_id );
		if ( null === $session ) {
			$this->session->release( $scan_id );
			wp_send_json_error( array( 'message' => 'The scan session data is unavailable.' ), 410 );
		}

		if ( 'finish' === $step ) {
			if ( array() !== $session['steps'] ) {
				wp_send_json_error( array( 'message' => 'The scan still has pending steps.' ), 409 );
			}

			$scan_data = $session['data'];
			unset( $scan_data['progress'] );
			try {
				$this->runner->save_results( $scan_data );
				$html_output = $this->renderer->render( $scan_data );
			} finally {
				$this->session->forget( $scan_id );
				$this->session->release( $scan_id );
			}

			wp_send_json_success(
				array(
					'html'       => $html_output,
					'incomplete' => ! empty( $scan_data['scan_incomplete'] ),
				)
			);
		}

		if ( ( $session['steps'][0] ?? null ) !== $step ) {
			wp_send_json_error( array( 'message' => 'Unexpected scan step.' ), 409 );
		}

		$scan_data     = $session['data'];
		$log_offset    = count( $scan_data['log'] );
		$step_complete = true;
		try {
			$step_complete = $this->runner->run_step( $scan_data, $step );
		} catch ( \Throwable ) {
			$scan_data['log'][]           = 'Scan step failed: ' . $step . '.';
			$scan_data['scan_incomplete'] = true;
		}
		if ( $step_complete ) {
			array_shift( $session['steps'] );
		}
		$session['data'] = $scan_data;

		if ( ! $this->session->store( $scan_id, $session ) ) {
			$this->session->forget( $scan_id );
			$this->session->release( $scan_id );
			wp_send_json_error( array( 'message' => 'Could not save scan progress.' ), 500 );
		}
		if ( ! $this->session->refresh( $scan_id ) ) {
			$this->session->forget( $scan_id );
			$this->session->release( $scan_id );
			wp_send_json_error( array( 'message' => 'The scan lock was lost.' ), 409 );
		}

		wp_send_json_success(
			array(
				'log'           => array_slice( $scan_data['log'], $log_offset ),
				'incomplete'    => ! empty( $scan_data['scan_incomplete'] ),
				'step_complete' => $step_complete,
			)
		);
	}

	public function run_scheduled_scan(): void {
		$owner = Scan_Session::new_cron_owner();
		if ( ! $this->session->acquire( $owner ) ) {
			return;
		}

		try {
			$scan_data = $this->runner->new_scan_data();
			$session   = array(
				'data'  => $scan_data,
				'steps' => $this->runner->enabled_steps( $scan_data['modules'] ),
			);
			$stored    = $this->session->store( $owner, $session );
		} catch ( \Throwable ) {
			$stored = false;
		}
		if ( ! $stored ) {
			$this->session->forget( $owner );
			$this->session->release( $owner );
			return;
		}

		$this->continue_scheduled_scan( $owner );
	}

	public function continue_scheduled_scan( string $owner = '' ): void {
		if ( ! Scan_Session::is_valid_cron_owner( $owner ) || ! $this->session->owns( $owner ) ) {
			return;
		}

		$session = $this->session->load( $owner );
		if ( null === $session ) {
			$this->session->release( $owner );
			return;
		}

		$scan_data = $session['data'];
		// WP-Cron は「アクセスがあったとき」しか動かない。1回の起動で1バッチしか
		// 進めないと低トラフィックのサイトでは完了前に TTL が切れるため、
		// リクエスト予算の範囲で複数バッチをまとめて進める。
		$budget   = (float) apply_filters(
			'pyro_scope_cron_seconds',
			Scan_Runner::request_seconds( self::CRON_SECONDS )
		);
		$deadline = microtime( true ) + max( 0.0, $budget );
		do {
			$step = $session['steps'][0] ?? null;
			if ( ! is_string( $step ) ) {
				break;
			}
			try {
				$request_deadline = $budget > 0.0 ? $deadline : null;
				if ( $this->runner->run_step( $scan_data, $step, $request_deadline ) ) {
					array_shift( $session['steps'] );
				}
			} catch ( \Throwable ) {
				$scan_data['log'][]           = 'Scan step failed: ' . $step . '.';
				$scan_data['scan_incomplete'] = true;
				array_shift( $session['steps'] );
			}
			// バッチごとに保存する。予算内で複数バッチ進めるので、まとめて最後に
			// 保存するとプロセスを落とされたときにこの起動分がすべて失われる。
			$session['data'] = $scan_data;
			if ( array() !== $session['steps'] && ! $this->session->store( $owner, $session ) ) {
				$this->session->forget( $owner );
				$this->session->release( $owner );
				return;
			}
			if ( ! $this->session->refresh( $owner ) ) {
				$this->session->forget( $owner );
				$this->session->release( $owner );
				return;
			}
		} while ( array() !== $session['steps'] && microtime( true ) < $deadline );

		if ( array() === $session['steps'] ) {
			unset( $scan_data['progress'] );
			try {
				$this->runner->save_results( $scan_data );
			} finally {
				$this->session->forget( $owner );
				$this->session->release( $owner );
			}
			return;
		}

		$scheduled = false !== wp_next_scheduled( self::HOOK_CONTINUE, array( $owner ) )
			? true
			: wp_schedule_single_event( time() + 1, self::HOOK_CONTINUE, array( $owner ) );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			$scan_data['log'][]           = 'Could not schedule the next scan batch.';
			$scan_data['scan_incomplete'] = true;
			unset( $scan_data['progress'] );
			try {
				$this->runner->save_results( $scan_data );
			} finally {
				$this->session->forget( $owner );
				$this->session->release( $owner );
			}
		}
	}
}
