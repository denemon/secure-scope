<?php
/*
Plugin Name: Pyro Scope
Description: WordPressのファイル・データベース・コア整合性・プラグイン更新を確認するセキュリティ監視プラグイン
Version: 3.5.2
Author: Ikkido-den (一揆堂田)
Requires PHP: 8.1
Requires at least: 6.0
License: GPL-2.0-or-later
Text Domain: pyro-scope
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

// 公開側には出力もフックもないため、管理画面・AJAX・cron・WP-CLI 以外では終了する。
if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

require_once __DIR__ . '/src/class-settings.php';
require_once __DIR__ . '/src/class-scan-session.php';
require_once __DIR__ . '/src/class-integrity-scanner.php';
require_once __DIR__ . '/src/class-file-scanner.php';
require_once __DIR__ . '/src/class-db-scanner.php';
require_once __DIR__ . '/src/class-update-scanner.php';
require_once __DIR__ . '/src/class-scan-runner.php';
require_once __DIR__ . '/src/class-results-renderer.php';
require_once __DIR__ . '/src/class-admin-page.php';
require_once __DIR__ . '/src/class-scan-controller.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( Pyro_Scope\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Pyro_Scope\Plugin::class, 'deactivate' ) );

Pyro_Scope\Plugin::get_instance();
