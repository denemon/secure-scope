<?php
/**
 * Pyro Scope Uninstall
 *
 * Fired when the plugin is deleted.
 *
 * @package   Pyro_Scope
 */

// WordPressから呼び出されていない場合は、直接のアクセスを禁止
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// オプションキーは Settings、cronフック名は Scan_Controller が唯一の所有者
// （どちらもクラス定義のみで副作用なし）
require_once __DIR__ . '/src/class-settings.php';
require_once __DIR__ . '/src/class-scan-controller.php';

/** 1サイト分のデータを削除する。マルチサイトでは全サイトに対して実行する。 */
$pyro_scope_cleanup = static function (): void {
	global $wpdb;

	// === スキャンセッションのクリーンアップ ===

	// セッション transient の名前はランダムな owner を含むため、Redis 等の外部
	// オブジェクトキャッシュ環境では後段のSQLでは消せない（そもそも wp_options に
	// 行が無い）。owner が判明する経路はロックと継続イベントの2つだけ。ロックと
	// セッションは同じTTLで同時に更新されるので、どちらにも現れない owner の
	// セッションは既に失効している。
	$owners = array();
	$lock   = get_option( Pyro_Scope\Settings::LOCK_KEY, false );
	if ( is_array( $lock ) && is_string( $lock['owner'] ?? null ) ) {
		$owners[] = $lock['owner'];
	}
	// 引数付きイベントを引数不明のまま列挙できる公開APIが無いため cron 配列を直接読む。
	foreach ( (array) _get_cron_array() as $pyro_scope_events ) {
		foreach ( (array) ( $pyro_scope_events[ Pyro_Scope\Scan_Controller::HOOK_CONTINUE ] ?? array() ) as $event ) {
			$owner = $event['args'][0] ?? null;
			if ( is_string( $owner ) ) {
				$owners[] = $owner;
			}
		}
	}
	foreach ( array_unique( $owners ) as $owner ) {
		delete_transient( Pyro_Scope\Settings::SESSION_PREFIX . $owner );
	}

	delete_transient( Pyro_Scope\Settings::CHECKSUM_KEY );

	// 予約済みイベントの削除。通常は無効化フックが処理するが、無効化に失敗した
	// 環境でも cron 配列にゴミが残らないようにする（引数付きイベントも消す）。
	wp_unschedule_hook( Pyro_Scope\Scan_Controller::HOOK_WEEKLY );
	wp_unschedule_hook( Pyro_Scope\Scan_Controller::HOOK_CONTINUE );

	// === オプションのクリーンアップ ===

	$option_keys = array(
		Pyro_Scope\Settings::OPTIONS_KEY,
		Pyro_Scope\Settings::TIMESTAMP_KEY,
		Pyro_Scope\Settings::RESULTS_KEY,
		Pyro_Scope\Settings::LOCK_KEY,
	);

	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}

	// === Transient行の一括削除 ===
	// 外部オブジェクトキャッシュを使っていない環境で残る transient 行の取りこぼしを拾う。
	$like_scan         = $wpdb->esc_like( '_transient_' . Pyro_Scope\Settings::SESSION_PREFIX ) . '%';
	$like_scan_timeout = $wpdb->esc_like( '_transient_timeout_' . Pyro_Scope\Settings::SESSION_PREFIX ) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s OR `option_name` LIKE %s",
			$like_scan,
			$like_scan_timeout
		)
	);
	// 直接SQLで消した行はキャッシュに残るため、options グループを無効化する。
	if ( $deleted ) {
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
};

// オプション・transient・cron はすべてサイト単位なので、
// ネットワーク環境では全サイトを回らないと他サイトのデータが残る。
if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $pyro_scope_site_id ) {
		switch_to_blog( (int) $pyro_scope_site_id );
		$pyro_scope_cleanup();
		restore_current_blog();
	}
} else {
	$pyro_scope_cleanup();
}
