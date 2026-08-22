<?php
/**
 * Core file integrity check against the WordPress.org checksum API.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Integrity_Scanner {

	/** 1バッチで処理するチェックサムまたはディレクトリエントリ数。 */
	private const BATCH_ENTRIES    = 1000;
	private const MAX_SCAN_ENTRIES = 100000;
	private const MAX_FINDINGS     = 1000;

	/** チェックサム表のキャッシュ期間。1スキャン中に API を1回だけ叩くために使う。 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * md5 比較と追加ファイル走査を同じ件数・時間予算で分割実行する。
	 *
	 * @param array<string, mixed>|null $state
	 * @return array{findings: list<string>, errors: list<string>, state: array<string, mixed>, done: bool}
	 */
	public function scan_batch( ?array $state, float $deadline = INF ): array {
		$state ??= array(
			'phase'             => 'checksums',
			'index'             => 0,
			'directories'       => array(),
			'directory_index'   => 0,
			'after'             => '',
			'entries'           => 0,
			'traversal_entries' => 0,
			'findings'          => 0,
		);
		if ( microtime( true ) >= $deadline ) {
			return array(
				'findings' => array(),
				'errors'   => array(),
				'state'    => $state,
				'done'     => false,
			);
		}

		$checksums = $this->checksums( $deadline );
		if ( is_string( $checksums ) ) {
			return array(
				'findings' => array(),
				'errors'   => array( $checksums ),
				'state'    => $state,
				'done'     => true,
			);
		}

		$findings  = array();
		$errors    = array();
		$processed = 0;

		if ( 'checksums' === $state['phase'] ) {
			$files = array_keys( $checksums );
			$total = count( $files );
			for ( $index = (int) $state['index']; $index < $total; ++$index ) {
				if ( $processed >= self::BATCH_ENTRIES || microtime( true ) >= $deadline ) {
					return compact( 'findings', 'errors', 'state' ) + array( 'done' => false );
				}
				++$processed;
				++$state['entries'];

				$file_path = (string) $files[ $index ];
				$full_path = ABSPATH . $file_path;
				$finding   = null;
				if ( is_link( $full_path ) ) {
					$finding = 'File Linked: ' . $file_path;
				} elseif ( ! file_exists( $full_path ) ) {
					$finding = 'File Deleted: ' . $file_path;
				} elseif ( ! is_readable( $full_path ) ) {
					$finding = 'File Unreadable: ' . $file_path;
				} else {
					$local_checksum = md5_file( $full_path );
					if ( ! is_string( $local_checksum ) ) {
						$finding = 'File Unreadable: ' . $file_path;
					} elseif ( $local_checksum !== $checksums[ $file_path ] ) {
						$finding = 'File Modified: ' . $file_path;
					}
				}

				$state['index'] = $index + 1;
				if ( null !== $finding ) {
					$findings[] = $finding;
					++$state['findings'];
					if ( $state['findings'] >= self::MAX_FINDINGS ) {
						$errors[] = 'Integrity finding limit reached (' . self::MAX_FINDINGS . ' findings).';
						return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
					}
				}
			}

			$state['phase'] = 'added';
			foreach ( array( 'wp-admin', 'wp-includes' ) as $core_directory ) {
				$directory = wp_normalize_path( ABSPATH . $core_directory );
				if ( is_dir( $directory ) ) {
					$state['directories'][] = $directory;
				}
			}
		}

		$expected = array_fill_keys( array_keys( $checksums ), true );
		$root     = rtrim( wp_normalize_path( ABSPATH ), '/' );
		while ( $processed < self::BATCH_ENTRIES && microtime( true ) < $deadline ) {
			$index = (int) $state['directory_index'];
			if ( ! isset( $state['directories'][ $index ] ) ) {
				return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
			}

			$directory = (string) $state['directories'][ $index ];
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories are reported as incomplete scan errors.
			$entries = @scandir( $directory, SCANDIR_SORT_NONE );
			if ( ! is_array( $entries ) ) {
				$errors[] = 'Could not read core directory: ' . $directory;
				++$state['directory_index'];
				$state['after'] = '';
				continue;
			}
			sort( $entries, SORT_STRING );

			$directory_finished = true;
			foreach ( $entries as $name ) {
				if ( $processed >= self::BATCH_ENTRIES || microtime( true ) >= $deadline ) {
					$directory_finished = false;
					break;
				}
				if ( '.' === $name || '..' === $name || strcmp( $name, (string) $state['after'] ) <= 0 ) {
					continue;
				}

				$state['after'] = $name;
				++$state['entries'];
				++$state['traversal_entries'];
				++$processed;
				if ( $state['traversal_entries'] > self::MAX_SCAN_ENTRIES ) {
					$errors[] = 'Core traversal limit reached (' . self::MAX_SCAN_ENTRIES . ' entries).';
					return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
				}

				$path = wp_normalize_path( $directory . '/' . $name );
				if ( is_link( $path ) ) {
					$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
					if ( ! isset( $expected[ $relative ] ) ) {
						$findings[] = 'File Added: ' . $relative;
						++$state['findings'];
						if ( $state['findings'] >= self::MAX_FINDINGS ) {
							$errors[] = 'Integrity finding limit reached (' . self::MAX_FINDINGS . ' findings).';
							return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
						}
					}
					continue;
				}
				if ( is_dir( $path ) ) {
					$state['directories'][] = $path;
					continue;
				}
				if ( ! is_file( $path ) ) {
					continue;
				}

				$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
				if ( ! isset( $expected[ $relative ] ) ) {
					$findings[] = 'File Added: ' . $relative;
					++$state['findings'];
					if ( $state['findings'] >= self::MAX_FINDINGS ) {
						$errors[] = 'Integrity finding limit reached (' . self::MAX_FINDINGS . ' findings).';
						return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
					}
				}
			}

			if ( $directory_finished ) {
				$state['directories'][ $index ] = '';
				++$state['directory_index'];
				$state['after'] = '';
			}
			if ( ! $directory_finished ) {
				break;
			}
		}

		$done = ! isset( $state['directories'][ (int) $state['directory_index'] ] );
		return compact( 'findings', 'errors', 'state', 'done' );
	}

	/**
	 * WordPress.org の公式チェックサム表。バッチ間で再取得しないよう transient に置く。
	 *
	 * @return array<string, string>|string 表、または失敗メッセージ
	 */
	private function checksums( float $deadline ): array|string {
		global $wp_version, $wp_local_package;

		$locale = is_string( $wp_local_package ?? null ) && '' !== $wp_local_package
			? $wp_local_package
			: 'en_US';

		$cached = get_transient( Settings::CHECKSUM_KEY );
		if ( is_array( $cached )
			&& ( $cached['version'] ?? null ) === $wp_version
			&& ( $cached['locale'] ?? null ) === $locale
			&& is_array( $cached['checksums'] ?? null )
		) {
			return $cached['checksums'];
		}

		$url     = add_query_arg(
			array(
				'version' => $wp_version,
				'locale'  => $locale,
			),
			'https://api.wordpress.org/core/checksums/1.0/'
		);
		$timeout = 20.0;
		if ( is_finite( $deadline ) ) {
			$timeout = max( 0.1, min( $timeout, $deadline - microtime( true ) ) );
		}
		$response = wp_remote_get( $url, array( 'timeout' => $timeout ) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return 'Could not retrieve official checksums from WordPress.org API.';
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
			return 'Invalid checksum data received from API.';
		}

		$checksums = array();
		foreach ( $data['checksums'] as $file => $checksum ) {
			if ( ! is_string( $file ) ) {
				return 'Invalid checksum data received from API.';
			}
			$file = wp_normalize_path( (string) $file );
			if ( str_starts_with( $file, 'wp-content/' ) ) {
				continue;
			}
			if ( '' === $file
				|| str_starts_with( $file, '/' )
				|| str_contains( $file, ':' )
				|| str_contains( $file, "\0" )
				|| preg_match( '#(^|/)\.\.?(/|$)#', $file )
				|| ! is_string( $checksum )
				|| 1 !== preg_match( '/^[a-f0-9]{32}$/i', $checksum )
			) {
				return 'Invalid checksum data received from API.';
			}
			$checksums[ $file ] = strtolower( $checksum );
		}

		set_transient(
			Settings::CHECKSUM_KEY,
			array(
				'version'   => $wp_version,
				'locale'    => $locale,
				'checksums' => $checksums,
			),
			self::CACHE_TTL
		);

		return $checksums;
	}
}
