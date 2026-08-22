<?php
/**
 * Exclusive scan lock plus the resumable scan session it protects.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Scan_Session {

	/** Lock lifetime, also used as the session transient expiry. */
	public const TTL = 900;

	public static function new_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function new_cron_owner(): string {
		return 'cron-' . bin2hex( random_bytes( 12 ) );
	}

	public static function is_valid_id( string $scan_id ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $scan_id );
	}

	public static function is_valid_cron_owner( string $owner ): bool {
		return (bool) preg_match( '/^cron-[a-f0-9]{24}$/', $owner );
	}

	// --- lock ------------------------------------------------------------

	public function acquire( string $owner ): bool {
		global $wpdb;
		$new_lock = array(
			'owner'   => $owner,
			'expires' => time() + self::TTL,
		);

		// add_option() は INSERT ... ON DUPLICATE KEY UPDATE なので既存ロックを奪ってしまう。
		// 行の新規作成に成功した場合だけ取得成功とみなす（INSERT IGNORE は原子的）。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock acquisition followed by explicit cache invalidation.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
				Settings::LOCK_KEY,
				maybe_serialize( $new_lock )
			)
		);
		if ( 1 === $inserted ) {
			$this->flush_lock_cache();
			return true;
		}

		$lock = get_option( Settings::LOCK_KEY, false );
		if ( is_array( $lock ) && (int) ( $lock['expires'] ?? 0 ) > time() ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap followed by explicit cache invalidation.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s, `autoload` = 'no' WHERE `option_name` = %s AND `option_value` = %s",
				maybe_serialize( $new_lock ),
				Settings::LOCK_KEY,
				maybe_serialize( $lock )
			)
		);
		if ( 1 !== $updated ) {
			return false;
		}

		$this->flush_lock_cache();
		return true;
	}

	/** 直接SQLで書いた行を get_option() から見えるようにする。 */
	private function flush_lock_cache(): void {
		wp_cache_delete( Settings::LOCK_KEY, 'options' );
		// 同一リクエスト内で get_option() が「存在しない」をキャッシュしている場合がある。
		wp_cache_delete( 'notoptions', 'options' );
	}

	public function owns( string $owner ): bool {
		$lock = get_option( Settings::LOCK_KEY, false );
		return is_array( $lock )
			&& ( $lock['owner'] ?? null ) === $owner
			&& (int) ( $lock['expires'] ?? 0 ) > time();
	}

	public function refresh( string $owner ): bool {
		global $wpdb;

		$lock = get_option( Settings::LOCK_KEY, false );
		if ( ! is_array( $lock )
			|| ( $lock['owner'] ?? null ) !== $owner
			|| (int) ( $lock['expires'] ?? 0 ) <= time()
		) {
			return false;
		}

		$new_lock = array(
			'owner'   => $owner,
			// 同一秒内でも値を必ず変え、MySQL の更新件数が 0 にならないようにする。
			'expires' => max( (int) $lock['expires'] + 1, time() + self::TTL ),
		);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap followed by explicit cache invalidation.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->options}` SET `option_value` = %s, `autoload` = 'no' WHERE `option_name` = %s AND `option_value` = %s",
				maybe_serialize( $new_lock ),
				Settings::LOCK_KEY,
				maybe_serialize( $lock )
			)
		);
		if ( 1 !== $updated ) {
			return false;
		}

		$this->flush_lock_cache();
		return true;
	}

	public function release( string $owner ): void {
		global $wpdb;

		$lock = get_option( Settings::LOCK_KEY, false );
		if ( ! is_array( $lock ) || ( $lock['owner'] ?? null ) !== $owner ) {
			return;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional delete followed by explicit cache invalidation.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` = %s",
				Settings::LOCK_KEY,
				maybe_serialize( $lock )
			)
		);
		if ( 1 === $deleted ) {
			$this->flush_lock_cache();
		}
	}

	// --- session ---------------------------------------------------------

	/** @param array{data: array<string, mixed>, steps: list<string>} $session */
	public function store( string $owner, array $session ): bool {
		return (bool) set_transient( Settings::SESSION_PREFIX . $owner, $session, self::TTL );
	}

	/**
	 * @return array{data: array<string, mixed>, steps: list<string>}|null
	 *         Null when the session is missing or malformed.
	 */
	public function load( string $owner ): ?array {
		$session = get_transient( Settings::SESSION_PREFIX . $owner );
		if ( ! is_array( $session ) || ! is_array( $session['data'] ?? null ) || ! is_array( $session['steps'] ?? null ) ) {
			return null;
		}

		return $session;
	}

	public function forget( string $owner ): void {
		delete_transient( Settings::SESSION_PREFIX . $owner );
	}
}
