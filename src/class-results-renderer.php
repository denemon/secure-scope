<?php
/**
 * Renders stored scan data to HTML. This is the escaping trust boundary:
 * every dynamic value must be escaped inside the template.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Results_Renderer {

	/**
	 * スキャン結果をHTMLとして生成する（テンプレート内で全動的値をesc_html済み）
	 *
	 * @param array<string, mixed> $results
	 * @return string エスケープ済みHTML
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- the included template reads $results from this method scope.
	public function render( array $results ): string {
		ob_start();
		include dirname( __DIR__ ) . '/templates/scan-results.php';
		return (string) ob_get_clean();
	}
}
