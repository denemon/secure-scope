<?php
/**
 * Resumable filesystem scan for known malicious code signatures.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class File_Scanner {

	private const MAX_SCAN_FILES   = 50000;
	private const MAX_SCAN_ENTRIES = 250000;
	/** Counted per scan type; Db_Scanner enforces the same ceiling separately. */
	private const MAX_FINDINGS         = 1000;
	private const BATCH_ENTRIES        = 1000;
	private const READ_CHUNK           = 1048576;
	private const READ_OVERLAP         = 65536;
	private const READ_BYTES_PER_BATCH = 16777216;
	/** `<?php // Silence is golden.` 程度のガードファイルを判定する上限サイズ。 */
	private const STUB_MAX_BYTES = 200;

	/** @var list<string> */
	private const SCANNED_EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'phps', 'inc', 'js' );

	/** @var array<string, string> ファイルスキャン用シグネチャ定義 */
	private const SIGNATURES = array(
		'Variable Function Execution'  => '/\$[a-zA-Z0-9_]+\s*\(\s*[`\'"](pass(thru)?|shell_exec|system|exec|popen|proc_open)/i',
		'Obfuscated Eval'              => '/(eval|assert|preg_replace)\s*\(\s*(\'|")\s*\.\s*(\'|")\s*\.\s*/i',
		'Advanced Obfuscation'         => '/(eval|assert)\s*\(\s*(gzuncompress|gzinflate|base64_decode|str_rot13)\s*\(/i',
		'File Upload Webshell'         => '/move_uploaded_file\s*\(\s*\$_FILES\s*\[\s*[\'"].*?[\'"]\s*\]\s*\[\s*[\'"]tmp_name[\'"]\s*\][^;]{0,500}\.php/i',
		'Remote Code Execution'        => '/(include|require)(_once)?\s*[\s(]\s*\$_GET\s*\[/i',
		'Eval Base64 Decode'           => '/\beval\s*\(\s*base64_decode\s*\(/i',
		'User Input Include'           => '/(include|require)(_once)?\s*[\s(]\s*\$_(REQUEST|POST)\s*\[/i',
		'User Input Eval'              => '/\beval\s*\(\s*\$_(REQUEST|POST)\s*\[/i',
		'PHP File Write From Input'    => '/file_put_contents\s*\([^;]{0,500}\.php[^;]{0,500}\$_(GET|POST|REQUEST|FILES)/is',
		'Command Execution From Input' => '/\b(system|exec|shell_exec|passthru|popen|proc_open)\s*\([^;]*(\$_(GET|POST|REQUEST|COOKIE)|filter_input\s*\()/i',
	);

	public function __construct( private readonly Settings $settings ) {
	}

	/**
	 * @param array<string, mixed>|null $state
	 * @param float                     $deadline microtime(true) 基準の打ち切り時刻。件数上限だけでは
	 *                                            1バッチが max_execution_time を超え得るため必須。
	 * @return array{findings: array<string, string>, errors: list<string>, state: array<string, mixed>, done: bool}
	 */
	public function scan_batch( ?array $state, float $deadline = INF ): array {
		if ( null === $state ) {
			$state = array(
				'directories'     => array( rtrim( wp_normalize_path( ABSPATH ), '/' ) ),
				'directory_index' => 0,
				'after'           => '',
				'entries'         => 0,
				'files'           => 0,
				'findings'        => 0,
				'file'            => null,
			);
		}

		$whitelist = $this->settings->whitelist_directories();

		$findings     = array();
		$errors       = array();
		$processed    = 0;
		$bytes_read   = 0;
		$done         = false;
		$uploads_dirs = $this->uploads_directories();

		if ( is_array( $state['file'] ?? null ) ) {
			$scan          = $this->scan_file_contents( $state['file'], $deadline, $bytes_read );
			$state['file'] = $scan['state'];
			if ( ! $scan['done'] ) {
				return compact( 'findings', 'errors', 'state' ) + array( 'done' => false );
			}
			$state['file'] = null;
			if ( null !== $scan['error'] ) {
				$errors[] = $scan['error'];
			} elseif ( null !== $scan['cause'] ) {
				$findings[ $scan['path'] ] = $scan['cause'];
				++$state['findings'];
				if ( $state['findings'] >= self::MAX_FINDINGS ) {
					$errors[] = 'Finding limit reached (' . self::MAX_FINDINGS . ' findings).';
					return compact( 'findings', 'errors', 'state' ) + array( 'done' => true );
				}
			}
		}

		while ( $processed < self::BATCH_ENTRIES && microtime( true ) < $deadline ) {
			$index = (int) $state['directory_index'];
			if ( ! isset( $state['directories'][ $index ] ) ) {
				$done = true;
				break;
			}

			$directory = (string) $state['directories'][ $index ];
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories are reported as incomplete scan errors.
			$entries = @scandir( $directory, SCANDIR_SORT_NONE );
			if ( ! is_array( $entries ) ) {
				$errors[] = 'Could not read directory: ' . $directory;
				++$state['directory_index'];
				$state['after'] = '';
				continue;
			}
			// SCANDIR_SORT_ASCENDING は strcoll（ロケール依存）で並べるため、
			// 再開判定の strcmp と順序が食い違って取りこぼす。バイト順で確定させる。
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
				++$processed;
				if ( $state['entries'] > self::MAX_SCAN_ENTRIES ) {
					$errors[] = 'File traversal limit reached (' . self::MAX_SCAN_ENTRIES . ' entries).';
					$done     = true;
					break 2;
				}

				$path = wp_normalize_path( $directory . '/' . $name );
				if ( is_link( $path ) ) {
					$target = realpath( $path );
					if ( false === $target || ! $this->is_path_in_directory( wp_normalize_path( $target ), ABSPATH ) ) {
						$errors[] = 'Symlink target is unavailable or outside ABSPATH: ' . $path;
						continue;
					}
					if ( is_dir( $path ) ) {
						$errors[] = 'Symlinked directory was not traversed: ' . $path;
						continue;
					}
				}
				if ( is_dir( $path ) ) {
					if ( ! $this->is_path_in_any_directory( $path, $whitelist ) ) {
						$state['directories'][] = $path;
					}
					continue;
				}
				if ( ! is_file( $path ) || ! $this->is_executable_extension( $path ) ) {
					continue;
				}

				++$state['files'];
				if ( $state['files'] > self::MAX_SCAN_FILES ) {
					$errors[] = 'File scan limit reached (' . self::MAX_SCAN_FILES . ' executable files).';
					$done     = true;
					break 2;
				}
				if ( $this->is_path_in_any_directory( $path, $whitelist ) ) {
					continue;
				}
				if ( ! is_readable( $path ) ) {
					$errors[] = 'Unreadable file: ' . $path;
					continue;
				}

				$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				if ( 'js' !== $extension
					&& $this->is_path_in_any_directory( $path, $uploads_dirs )
					&& ! $this->is_inert_stub( $path )
				) {
					$findings[ $path ] = 'Executable PHP-like file in uploads directory';
				} else {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesystem races are checked when the file scan starts.
					$file_size = @filesize( $path );
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesystem races are checked when the file scan starts.
					$file_mtime    = @filemtime( $path );
					$state['file'] = array(
						'path'   => $path,
						'offset' => 0,
						'tail'   => '',
						'size'   => $file_size,
						'mtime'  => $file_mtime,
					);
					$scan          = $this->scan_file_contents( $state['file'], $deadline, $bytes_read );
					$state['file'] = $scan['state'];
					if ( ! $scan['done'] ) {
						return compact( 'findings', 'errors', 'state' ) + array( 'done' => false );
					}
					$state['file'] = null;
					if ( null !== $scan['error'] ) {
						$errors[] = $scan['error'];
					} elseif ( null !== $scan['cause'] ) {
						$findings[ $path ] = $scan['cause'];
					}
				}

				if ( isset( $findings[ $path ] ) ) {
					++$state['findings'];
					if ( $state['findings'] >= self::MAX_FINDINGS ) {
						$errors[] = 'Finding limit reached (' . self::MAX_FINDINGS . ' findings).';
						$done     = true;
						break 2;
					}
				}
			}

			if ( $directory_finished ) {
				// 走査済みのパスは捨てる（セッションtransientの肥大を防ぐ）。
				// unset ではなく空文字にするのは、空配列を serialize 往復させると
				// 次の追加キーが 0 に巻き戻り、以降のディレクトリを取りこぼすため。
				$state['directories'][ $index ] = '';
				++$state['directory_index'];
				$state['after'] = '';
			}
			if ( ! $directory_finished || $done ) {
				break;
			}
		}

		if ( ! $done && ! isset( $state['directories'][ (int) $state['directory_index'] ] ) ) {
			$done = true;
		}

		return compact( 'findings', 'errors', 'state', 'done' );
	}

	/**
	 * @param array{path: string, offset: int, tail: string, size: int|false, mtime: int|false} $state
	 * @return array{cause: ?string, error: ?string, path: string, state: array<string, mixed>, done: bool}
	 */
	private function scan_file_contents( array $state, float $deadline, int &$bytes_read ): array {
		$path      = $state['path'];
		$is_link   = is_link( $path );
		$link_path = $is_link ? realpath( $path ) : null;
		if ( ! $this->is_path_in_directory( $path, ABSPATH )
			|| ! $this->is_executable_extension( $path )
			|| ( $is_link
				&& ( false === $link_path
					|| ! $this->is_path_in_directory( wp_normalize_path( $link_path ), ABSPATH ) ) )
		) {
			return compact( 'path', 'state' ) + array(
				'cause' => null,
				'error' => 'File changed or became unsafe during scan: ' . $path,
				'done'  => true,
			);
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat is handled as a changed file.
		$file_size = @filesize( $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed stat is handled as a changed file.
		$file_mtime = @filemtime( $path );
		if ( $state['size'] !== $file_size || $state['mtime'] !== $file_mtime ) {
			return compact( 'path', 'state' ) + array(
				'cause' => null,
				'error' => 'File changed during scan: ' . $path,
				'done'  => true,
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- chunked reads are bounded and open failures are reported.
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return compact( 'path', 'state' ) + array(
				'cause' => null,
				'error' => 'Could not open file: ' . $path,
				'done'  => true,
			);
		}

		try {
			if ( $state['offset'] > 0 && 0 !== fseek( $handle, $state['offset'] ) ) {
				return compact( 'path', 'state' ) + array(
					'cause' => null,
					'error' => 'Could not resume file: ' . $path,
					'done'  => true,
				);
			}
			while ( ! feof( $handle )
				&& microtime( true ) < $deadline
				&& $bytes_read < self::READ_BYTES_PER_BATCH
			) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see chunked-read rationale above.
				$chunk = fread( $handle, self::READ_CHUNK );
				if ( false === $chunk ) {
					return compact( 'path', 'state' ) + array(
						'cause' => null,
						'error' => 'Could not read file: ' . $path,
						'done'  => true,
					);
				}
				if ( '' === $chunk ) {
					break;
				}
				$state['offset'] += strlen( $chunk );
				$bytes_read      += strlen( $chunk );
				$buffer           = $state['tail'] . $chunk;
				foreach ( self::SIGNATURES as $cause => $pattern ) {
					if ( 1 === preg_match( $pattern, $buffer ) ) {
						return compact( 'cause', 'path', 'state' ) + array(
							'error' => null,
							'done'  => true,
						);
					}
				}
				$state['tail'] = substr( $buffer, -self::READ_OVERLAP );
			}
		} finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the chunked-read handle above.
			fclose( $handle );
		}

		return compact( 'path', 'state' ) + array(
			'cause' => null,
			'error' => null,
			'done'  => $state['offset'] >= (int) $state['size'],
		);
	}

	/**
	 * uploads の実際の場所。定数で移設されている場合も、マルチサイトの
	 * sites/N を含む場合も拾えるように両方を対象にする。
	 *
	 * @return list<string>
	 */
	private function uploads_directories(): array {
		// $create_dir = false: スキャン中に日付ディレクトリを作らせない。
		$uploads = wp_upload_dir( null, false );
		$roots   = array( wp_normalize_path( WP_CONTENT_DIR . '/uploads' ) );
		if ( is_string( $uploads['basedir'] ?? null ) && '' !== $uploads['basedir'] ) {
			$roots[] = wp_normalize_path( $uploads['basedir'] );
		}

		return array_values( array_unique( $roots ) );
	}

	/**
	 * 開始タグ・コメント・空白しか含まないファイルか。
	 * uploads 配下の `index.php` ガードは無害なので誤検知にしない。
	 */
	private function is_inert_stub( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable files are not treated as inert stubs.
		$size = @filesize( $path );
		if ( false === $size || $size > self::STUB_MAX_BYTES ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local read; failure is handled below.
		$contents = @file_get_contents( $path, false, null, 0, self::STUB_MAX_BYTES );
		if ( ! is_string( $contents ) ) {
			return false;
		}
		/*
		 * PHPの1行コメントは閉じタグでも終わる。行コメントの直後に閉じタグを置いて
		 * スクリプトを続ける書き方はコメントだけに見えて出力を持つので、
		 * 閉じタグ以降に中身があるものは無害と見なさない。
		 */
		$close = strpos( $contents, '?>' );
		if ( false !== $close && '' !== trim( substr( $contents, $close + 2 ) ) ) {
			return false;
		}
		$stripped = preg_replace(
			'#^\s*<\?php\b|//[^\r\n]*|\#[^\r\n]*|/\*.*?\*/|\?>#s',
			'',
			$contents
		);

		return is_string( $stripped ) && '' === trim( $stripped );
	}

	/** @param list<string> $directories */
	private function is_path_in_any_directory( string $path, array $directories ): bool {
		foreach ( $directories as $directory ) {
			if ( $this->is_path_in_directory( $path, $directory ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_path_in_directory( string $path, string $directory ): bool {
		$path      = rtrim( wp_normalize_path( $path ), '/' );
		$directory = rtrim( wp_normalize_path( $directory ), '/' );
		return $path === $directory || str_starts_with( $path, $directory . '/' );
	}

	private function is_executable_extension( string $path ): bool {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::SCANNED_EXTENSIONS, true );
	}
}
