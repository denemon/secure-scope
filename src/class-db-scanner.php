<?php
/**
 * Resumable database scan over published posts, options and approved comments.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Db_Scanner {

	/** 1バッチの行数。行あたり最大 INLINE_CHARS 文字を読むためメモリ上限を決める。 */
	private const BATCH_SIZE = 200;
	/** バッチクエリで直接読む文字数。1文字余分に取得して超過を判定する。 */
	private const INLINE_CHARS          = 20000;
	private const CHUNK_CHARS           = 100000;
	private const CHUNK_OVERLAP         = 2000;
	private const LONG_CHUNKS_PER_BATCH = 20;
	/** Counted per scan type; File_Scanner enforces the same ceiling separately. */
	private const MAX_FINDINGS = 1000;

	/** @var array<string, string> */
	private const SIGNATURES = array(
		'Obfuscated Code'         => '/\b(eval|assert|base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i',
		'Dangerous Script Source' => '/<script\b[^>]*\bsrc\s*=\s*([\'\"])?\s*(javascript:|data:)/i',
		'Dangerous Iframe Source' => '/<iframe\b[^>]*\bsrc\s*=\s*([\'\"])?\s*(javascript:|data:)/i',
		'Inline Event Injection'  => '/<(?:img|svg|body|iframe|a)\b[^>]*\bon[a-z]+\s*=/i',
	);

	/**
	 * @param array<string, mixed>|null $state
	 * @return array{findings: array<string, string>, errors: list<string>, state: array<string, mixed>, done: bool}
	 */
	public function scan_batch( ?array $state, float $deadline = INF ): array {
		global $wpdb;
		$state      ??= array(
			'table'    => 0,
			'cursor'   => 0,
			'findings' => 0,
			'long'     => null,
		);
		$findings     = array();
		$errors       = array();
		$skip_options = array(
			'rewrite_rules',
			'active_plugins',
			'cron',
			Settings::OPTIONS_KEY,
			Settings::RESULTS_KEY,
			Settings::TIMESTAMP_KEY,
			Settings::LOCK_KEY,
		);

		while ( $state['table'] < 3 ) {
			if ( 0 === $state['table'] ) {
				$query           = $wpdb->prepare(
					"SELECT ID, LEFT(post_content, %d) AS post_content FROM `{$wpdb->posts}` WHERE `post_status` = 'publish' AND `ID` > %d ORDER BY `ID` ASC LIMIT %d",
					self::INLINE_CHARS + 1,
					$state['cursor'],
					self::BATCH_SIZE
				);
				$table           = $wpdb->posts;
				$id_field        = 'ID';
				$content_field   = 'post_content';
				$location_prefix = 'Post ID: ';
				$error_message   = 'Failed to query published posts.';
			} elseif ( 1 === $state['table'] ) {
				$query           = $wpdb->prepare(
					"SELECT option_id, option_name, LEFT(option_value, %d) AS option_value FROM `{$wpdb->options}` WHERE `option_id` > %d ORDER BY `option_id` ASC LIMIT %d",
					self::INLINE_CHARS + 1,
					$state['cursor'],
					self::BATCH_SIZE
				);
				$table           = $wpdb->options;
				$id_field        = 'option_id';
				$content_field   = 'option_value';
				$location_prefix = 'Option: ';
				$error_message   = 'Failed to query options.';
			} else {
				$query           = $wpdb->prepare(
					"SELECT comment_ID, LEFT(comment_content, %d) AS comment_content FROM `{$wpdb->comments}` WHERE `comment_approved` = '1' AND `comment_ID` > %d ORDER BY `comment_ID` ASC LIMIT %d",
					self::INLINE_CHARS + 1,
					$state['cursor'],
					self::BATCH_SIZE
				);
				$table           = $wpdb->comments;
				$id_field        = 'comment_ID';
				$content_field   = 'comment_content';
				$location_prefix = 'Comment ID: ';
				$error_message   = 'Failed to query approved comments.';
			}

			if ( is_array( $state['long'] ?? null ) ) {
				$long          = $this->scan_long_value_batch(
					$table,
					$id_field,
					$content_field,
					$state['long'],
					$deadline
				);
				$state['long'] = $long['state'];
				if ( null !== $long['error'] ) {
					$errors[] = $long['error'];
				}
				if ( null !== $long['cause'] ) {
					$findings[ (string) $long['location'] ] = $long['cause'];
					++$state['findings'];
				}
				if ( ! $long['done'] ) {
					return array(
						'findings' => $findings,
						'errors'   => $errors,
						'state'    => $state,
						'done'     => false,
					);
				}
				$state['long'] = null;
				if ( $state['findings'] >= self::MAX_FINDINGS ) {
					$errors[] = 'Finding limit reached (' . self::MAX_FINDINGS . ' findings).';
					return array(
						'findings' => $findings,
						'errors'   => $errors,
						'state'    => $state,
						'done'     => true,
					);
				}
			}

			if ( microtime( true ) >= $deadline ) {
				return array(
					'findings' => $findings,
					'errors'   => $errors,
					'state'    => $state,
					'done'     => false,
				);
			}

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- every branch prepares the uncached security-scan query above.
			$rows = $wpdb->get_results( $query );
			if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
				$errors[] = $error_message;
				++$state['table'];
				$state['cursor'] = 0;
				continue;
			}

			foreach ( $rows as $row ) {
				// cursor は「処理し終えた行」を指す。打ち切り時はこの行から再開する。
				if ( microtime( true ) >= $deadline ) {
					return array(
						'findings' => $findings,
						'errors'   => $errors,
						'state'    => $state,
						'done'     => false,
					);
				}
				$state['cursor'] = (int) $row->{$id_field};
				if ( 1 === $state['table'] ) {
					$option_name = (string) $row->option_name;
					if ( in_array( $option_name, $skip_options, true )
						|| str_starts_with( $option_name, '_transient_' )
						|| str_starts_with( $option_name, '_site_transient_' )
					) {
						continue;
					}
				}
				$location = 1 === $state['table']
					? $location_prefix . $row->option_name
					: $location_prefix . $row->{$id_field};

				$inline_content = (string) $row->{$content_field};
				$cause          = $this->match_signatures( $inline_content );
				if ( null === $cause && strlen( $inline_content ) > self::INLINE_CHARS ) {
					$state['long'] = array(
						'id'       => (int) $row->{$id_field},
						'offset'   => 1,
						'location' => $location,
					);
					$long          = $this->scan_long_value_batch(
						$table,
						$id_field,
						$content_field,
						$state['long'],
						$deadline
					);
					$state['long'] = $long['state'];
					$cause         = $long['cause'];
					if ( null !== $long['error'] ) {
						$errors[] = $long['error'];
					}
					if ( ! $long['done'] ) {
						return array(
							'findings' => $findings,
							'errors'   => $errors,
							'state'    => $state,
							'done'     => false,
						);
					}
					$state['long'] = null;
				}
				if ( null !== $cause ) {
					$findings[ $location ] = $cause;
					++$state['findings'];
				}
				if ( $state['findings'] >= self::MAX_FINDINGS ) {
					$errors[] = 'Finding limit reached (' . self::MAX_FINDINGS . ' findings).';
					return array(
						'findings' => $findings,
						'errors'   => $errors,
						'state'    => $state,
						'done'     => true,
					);
				}
			}

			if ( count( $rows ) === self::BATCH_SIZE ) {
				return array(
					'findings' => $findings,
					'errors'   => $errors,
					'state'    => $state,
					'done'     => false,
				);
			}
			++$state['table'];
			$state['cursor'] = 0;
		}

		return array(
			'findings' => $findings,
			'errors'   => $errors,
			'state'    => $state,
			'done'     => true,
		);
	}

	private function match_signatures( string $content ): ?string {
		foreach ( self::SIGNATURES as $cause => $pattern ) {
			if ( preg_match( $pattern, $content ) === 1 ) {
				return $cause;
			}
		}

		return null;
	}

	/**
	 * INLINE_CHARS を超える値を SUBSTRING で分割して読み、次バッチへ位置を引き継ぐ。
	 * オフセットも長さも「文字数」で扱うため、マルチバイトでもずれない。
	 *
	 * @param array{id: int, offset: int, location: string} $state
	 * @return array{cause: ?string, error: ?string, location: string, state: array<string, mixed>, done: bool}
	 */
	private function scan_long_value_batch(
		string $table,
		string $id_field,
		string $content_field,
		array $state,
		float $deadline
	): array {
		global $wpdb;
		$step   = self::CHUNK_CHARS - self::CHUNK_OVERLAP;
		$chunks = 0;

		while ( $chunks < self::LONG_CHUNKS_PER_BATCH
			&& microtime( true ) < $deadline
		) {
			++$chunks;
			// The identifiers come only from the three hard-coded table branches in scan_batch().
            // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared, uncached security scan.
			$stored = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT CONCAT('x', SUBSTRING(`{$content_field}`, %d, %d)) FROM `{$table}` WHERE `{$id_field}` = %d",
					$state['offset'],
					self::CHUNK_CHARS,
					$state['id']
				)
			);
            // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! is_string( $stored ) ) {
				return array(
					'cause'    => null,
					'error'    => 'Could not read the full database value: ' . $state['location'],
					'location' => $state['location'],
					'state'    => $state,
					'done'     => true,
				);
			}
			$chunk = substr( $stored, 1 );
			$cause = $this->match_signatures( $chunk );
			if ( null !== $cause ) {
				return compact( 'cause', 'state' ) + array(
					'error'    => null,
					'location' => $state['location'],
					'done'     => true,
				);
			}
			if ( '' === $chunk ) {
				return array(
					'cause'    => null,
					'error'    => null,
					'location' => $state['location'],
					'state'    => $state,
					'done'     => true,
				);
			}
			$state['offset'] += $step;
		}

		return array(
			'cause'    => null,
			'error'    => null,
			'location' => $state['location'],
			'state'    => $state,
			'done'     => false,
		);
	}
}
