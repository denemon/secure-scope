<?php
/**
 * Scan results template.
 *
 * Called via output buffering from Pyro_Scope\Results_Renderer::render().
 * Available variables: $results (array)
 *
 * @var array<string, mixed> $results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

( static function ( array $results ): void {
	$modules           = is_array( $results['modules'] ?? null ) ? $results['modules'] : array();
	$scanner_enabled   = ! empty( $modules['scanner'] );
	$integrity_enabled = ! empty( $modules['integrity'] );
	$updates_enabled   = ! empty( $modules['updates'] );
	$scan_incomplete   = ! empty( $results['scan_incomplete'] );
	$scan_log          = is_array( $results['log'] ?? null ) ? $results['log'] : array();

	if ( $scan_incomplete ) : ?>
	<div class="pyro-scope-notice error">
		<h3>スキャンは完了していません</h3>
		<p>一部のチェックでエラーが発生しました。スキャンログを確認してください。</p>
	</div>
			<?php if ( array() !== $scan_log ) : ?>
		<details class="pyro-scope-saved-log">
			<summary>スキャンログ</summary>
			<pre><?php echo esc_html( implode( "\n", array_map( 'strval', $scan_log ) ) ); ?></pre>
		</details>
	<?php endif; ?>
	<?php endif; ?>

	<?php
	// === 整合性チェック結果 ===
	$integrity_issues = is_array( $results['integrity'] ?? null ) ? $results['integrity'] : array();
	$integrity_errors = is_array( $results['integrity_errors'] ?? null ) ? $results['integrity_errors'] : array();
	$integrity_count  = count( $integrity_issues );

	if ( false === $integrity_enabled ) :
		?>
	<div class="pyro-scope-notice info">
		<h3>コアファイル整合性チェックは無効です</h3>
	</div>
	<?php else : ?>
			<?php if ( array() !== $integrity_errors ) : ?>
		<div class="pyro-scope-notice error">
			<h3>Integrity Check Failed</h3>
			<ul style="list-style:disc; margin-left:20px;">
				<?php foreach ( $integrity_errors as $error ) : ?>
					<li><?php echo esc_html( (string) $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
		<?php if ( $integrity_count > 0 ) : ?>
		<div class="pyro-scope-notice warning">
			<h3><?php echo esc_html( (string) $integrity_count ); ?>件のコアファイルの整合性に関する問題が検出されました 危険</h3>
			<p>以下のWordPressコアファイルが、公式のファイルと異なります。改ざんの可能性が非常に高いです。</p>
			<ul style="list-style:disc; margin-left:20px;">
				<?php foreach ( $integrity_issues as $issue ) : ?>
					<li><code><?php echo esc_html( (string) $issue ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php elseif ( array() === $integrity_errors ) : ?>
		<div class="pyro-scope-notice info">
			<h3>コアファイルの整合性は正常です ✅</h3>
			<p>WordPressコアファイルは、WordPress.orgの公式ファイルと一致しています。</p>
		</div>
	<?php endif; ?>
	<?php endif; ?>

	<?php
	// === 不審ファイル検出結果 ===
	$files = is_array( $results['files'] ?? null ) ? $results['files'] : array();
	// === 不審DBエントリ検出結果 ===
	$db_entries = is_array( $results['db'] ?? null ) ? $results['db'] : array();

	if ( false === $scanner_enabled ) :
		?>
	<div class="pyro-scope-notice info">
		<h3>ファイル・DBスキャンは無効です</h3>
	</div>
	<?php elseif ( empty( $files ) && empty( $db_entries ) && ! $scan_incomplete ) : ?>
	<div class="pyro-scope-notice info">
		<h3>不審なファイル・DBエントリは検出されませんでした</h3>
	</div>
	<?php endif; ?>

	<?php
	if ( ! empty( $files ) ) :
		?>
	<h2>不審なファイルの検出結果 (<?php echo (int) count( $files ); ?>件)</h2>
	<table class="pyro-scope-results-table">
		<thead><tr><th>ファイルパス</th><th>原因 (検出シグネチャ)</th></tr></thead>
		<tbody>
				<?php foreach ( $files as $file_path => $cause ) : ?>
				<tr>
					<td><code><?php echo esc_html( str_replace( ABSPATH, '', (string) $file_path ) ); ?></code></td>
					<td><?php echo esc_html( (string) $cause ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php if ( ! empty( $db_entries ) ) : ?>
	<h2>不審なDBエントリの検出結果 (<?php echo (int) count( $db_entries ); ?>件)</h2>
	<table class="pyro-scope-results-table">
		<thead><tr><th>場所</th><th>原因 (検出パターン)</th></tr></thead>
		<tbody>
				<?php foreach ( $db_entries as $entry_location => $cause ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $entry_location ); ?></td>
					<td><?php echo esc_html( (string) $cause ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php
	// === プラグイン更新チェック結果 ===
	$update_entries = is_array( $results['updates'] ?? null ) ? $results['updates'] : array();
	$update_errors  = is_array( $results['update_errors'] ?? null ) ? $results['update_errors'] : array();
	if ( ! $updates_enabled ) :
		?>
	<div class="pyro-scope-notice info">
		<h3>プラグイン更新チェックは無効です</h3>
	</div>
	<?php elseif ( ! empty( $update_errors ) ) : ?>
	<div class="pyro-scope-notice error">
		<h3>プラグイン更新情報を確認できませんでした</h3>
		<ul style="list-style:disc; margin-left:20px;">
				<?php foreach ( $update_errors as $error ) : ?>
				<li><?php echo esc_html( (string) $error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php elseif ( ! empty( $update_entries ) ) : ?>
	<div class="pyro-scope-notice warning">
		<h3><?php echo (int) count( $update_entries ); ?>件のプラグインが最新版ではありません</h3>
		<p>以下のプラグインに更新があります。セキュリティ修正を含む可能性があるため、早急な更新を推奨します。</p>
		<ul style="list-style:disc; margin-left:20px;">
				<?php foreach ( $update_entries as $update ) : ?>
				<li><?php echo esc_html( (string) $update ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php else : ?>
	<div class="pyro-scope-notice info">
		<h3>更新対象のプラグインは検出されませんでした</h3>
	</div>
	<?php endif; ?>
	<?php
} )( $results );
