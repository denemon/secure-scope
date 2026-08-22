<?php
/**
 * Outdated plugin detection using WordPress's native update metadata.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Update_Scanner {

	/** @return array{findings: list<string>, errors: list<string>} */
	public function scan(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_update_plugins();

		$all_plugins      = get_plugins();
		$update_transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $update_transient ) ) {
			return array(
				'findings' => array(),
				'errors'   => array( 'WordPress update information is unavailable.' ),
			);
		}

		if (
			empty( $update_transient->last_checked )
			|| ! isset( $update_transient->response )
			|| ! is_array( $update_transient->response )
			|| ! isset( $update_transient->no_update )
			|| ! is_array( $update_transient->no_update )
		) {
			return array(
				'findings' => array(),
				'errors'   => array( 'WordPress update information is incomplete.' ),
			);
		}

		$responses = $update_transient->response;
		$findings  = array();
		foreach ( $responses as $plugin_file => $update_info ) {
			if ( ! isset( $all_plugins[ $plugin_file ] ) || ! is_object( $update_info ) || empty( $update_info->new_version ) ) {
				continue;
			}
			$meta       = $all_plugins[ $plugin_file ];
			$findings[] = $meta['Name'] . " (Installed: {$meta['Version']}, Latest: {$update_info->new_version})";
		}

		return array(
			'findings' => $findings,
			'errors'   => array(),
		);
	}
}
