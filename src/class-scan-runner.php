<?php
/**
 * Scan state machine: owns the step order, the accumulated scan data and its
 * persistence. Knows nothing about HTTP, cron or rendering.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Scan_Runner {

	/**
	 * 1バッチの壁時計予算。件数上限だけでは巨大ファイルやコアの md5 比較で
	 * max_execution_time を超えるため、どのステップも必ずこの秒数で打ち切る。
	 */
	public const BATCH_SECONDS = 8;

	/**
	 * このリクエストで安全に使える壁時計秒数。max_execution_time が設定されている
	 * ホストではその半分までに収め、途中でプロセスを殺されないようにする。
	 * 0（無制限。CLI や一部の cron 実行）のときは上限値をそのまま使う。
	 */
	public static function request_seconds( float $ceiling ): float {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return $ceiling;
		}

		return min( $ceiling, max( 0.25, $limit / 2 ) );
	}

	public function __construct(
		private readonly Settings $settings,
		private readonly Integrity_Scanner $integrity,
		private readonly File_Scanner $files,
		private readonly Db_Scanner $db,
		private readonly Update_Scanner $updates
	) {
	}

	/**
	 * @return array{log: list<string>, files: array<string, string>, db: array<string, string>, integrity: list<string>, integrity_errors: list<string>, updates: list<string>, update_errors: list<string>, modules: array{scanner: bool, integrity: bool, updates: bool}, scan_incomplete?: bool}
	 */
	public function new_scan_data(): array {
		return array(
			'log'              => array(),
			'files'            => array(),
			'db'               => array(),
			'integrity'        => array(),
			'integrity_errors' => array(),
			'updates'          => array(),
			'update_errors'    => array(),
			'modules'          => $this->settings->modules(),
		);
	}

	/**
	 * @param array{scanner: bool, integrity: bool, updates: bool} $modules
	 * @return list<string>
	 */
	public function enabled_steps( array $modules ): array {
		$steps = array();
		if ( $modules['integrity'] ) {
			$steps[] = 'integrity';
		}
		if ( $modules['scanner'] ) {
			$steps[] = 'files';
			$steps[] = 'db';
		}
		if ( $modules['updates'] ) {
			$steps[] = 'updates';
		}
		return $steps;
	}

	/**
	 * Run one batch of one step. Returns true when the step is finished.
	 *
	 * @param array<string, mixed> $scan_data
	 */
	public function run_step( array &$scan_data, string $step, ?float $request_deadline = null ): bool {
		$deadline = microtime( true ) + self::request_seconds( self::BATCH_SECONDS );
		if ( null !== $request_deadline ) {
			$deadline = min( $deadline, $request_deadline );
		}

		if ( 'integrity' === $step ) {
			if ( ! isset( $scan_data['progress']['integrity'] ) ) {
				$scan_data['log'][] = 'Checking file integrity against WordPress.org...';
			}
			$integrity_result                   = $this->integrity->scan_batch( $scan_data['progress']['integrity'] ?? null, $deadline );
			$scan_data['progress']['integrity'] = $integrity_result['state'];
			$scan_data['integrity']             = array_merge( $scan_data['integrity'], $integrity_result['findings'] );
			$scan_data['integrity_errors']      = array_merge(
				$scan_data['integrity_errors'],
				$integrity_result['errors']
			);
			foreach ( $integrity_result['errors'] as $error ) {
				$scan_data['log'][]           = 'Integrity check error: ' . $error;
				$scan_data['scan_incomplete'] = true;
			}
			if ( ! $integrity_result['done'] ) {
				$scan_data['log'][] = 'Checked ' . (int) $integrity_result['state']['entries'] . ' core entries...';
				return false;
			}
			if ( array() === $scan_data['integrity_errors'] ) {
				$scan_data['log'][] = count( $scan_data['integrity'] ) . ' integrity issues found.';
			}
			return true;
		}

		if ( 'files' === $step ) {
			if ( ! isset( $scan_data['progress']['files'] ) ) {
				$scan_data['log'][] = 'Starting file scan...';
			}
			$file_result                    = $this->files->scan_batch( $scan_data['progress']['files'] ?? null, $deadline );
			$scan_data['progress']['files'] = $file_result['state'];
			$scan_data['files']             = array_replace( $scan_data['files'], $file_result['findings'] );
			foreach ( $file_result['errors'] as $error ) {
				$scan_data['log'][]           = 'File scan error: ' . $error;
				$scan_data['scan_incomplete'] = true;
			}
			if ( $file_result['done'] ) {
				$scan_data['log'][] = count( $scan_data['files'] ) . ' suspicious files found.';
			}
			return $file_result['done'];
		}

		if ( 'db' === $step ) {
			if ( ! isset( $scan_data['progress']['db'] ) ) {
				$scan_data['log'][] = 'Starting DB scan...';
			}
			$db_result                   = $this->db->scan_batch( $scan_data['progress']['db'] ?? null, $deadline );
			$scan_data['progress']['db'] = $db_result['state'];
			$scan_data['db']             = array_replace( $scan_data['db'], $db_result['findings'] );
			foreach ( $db_result['errors'] as $error ) {
				$scan_data['log'][]           = 'DB scan error: ' . $error;
				$scan_data['scan_incomplete'] = true;
			}
			if ( $db_result['done'] ) {
				$scan_data['log'][] = count( $scan_data['db'] ) . ' suspicious DB entries found.';
			}
			return $db_result['done'];
		}

		if ( 'updates' === $step ) {
			$scan_data['log'][]         = 'Checking plugin updates...';
			$update_result              = $this->updates->scan();
			$scan_data['updates']       = $update_result['findings'];
			$scan_data['update_errors'] = $update_result['errors'];
			foreach ( $update_result['errors'] as $error ) {
				$scan_data['log'][]           = 'Plugin update check error: ' . $error;
				$scan_data['scan_incomplete'] = true;
			}
			$scan_data['log'][] = count( $scan_data['updates'] ) . ' outdated plugins detected.';
			return true;
		}

		throw new \InvalidArgumentException( 'Unknown scan step.' );
	}

	/** @param array<string, mixed> $scan_data */
	public function save_results( array &$scan_data ): void {
		$scan_timestamp = time();
		$stored_results = array(
			'scan_timestamp' => $scan_timestamp,
			'scan_data'      => $scan_data,
		);

		$saved = update_option( Settings::RESULTS_KEY, $stored_results, false );
		if ( ! $saved && get_option( Settings::RESULTS_KEY, false ) !== $stored_results ) {
			$scan_data['log'][]           = 'Warning: Failed to save scan results.';
			$scan_data['scan_incomplete'] = true;
			return;
		}

		update_option( Settings::TIMESTAMP_KEY, $scan_timestamp, false );
	}

	/** @return array<string, mixed>|false */
	public function last_results(): array|false {
		$stored = get_option( Settings::RESULTS_KEY, false );
		if ( ! is_array( $stored ) || ! is_array( $stored['scan_data'] ?? null ) ) {
			return false;
		}

		$scan_data = $stored['scan_data'];
		foreach ( array( 'log', 'files', 'db', 'integrity', 'integrity_errors', 'updates', 'update_errors', 'modules' ) as $key ) {
			if ( ! is_array( $scan_data[ $key ] ?? null ) ) {
				return false;
			}
		}

		return array_key_exists( 'updates', $scan_data['modules'] ) ? $scan_data : false;
	}
}
