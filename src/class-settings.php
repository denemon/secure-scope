<?php
/**
 * Plugin settings and the single owner of every persisted option key.
 *
 * @package Pyro_Scope
 */

declare(strict_types=1);

namespace Pyro_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

final class Settings {

	/**
	 * Persisted option keys. Nothing outside this class may hard-code them;
	 * uninstall.php reads them from here as well.
	 */
	public const OPTIONS_KEY    = 'pyro_scope_options';
	public const RESULTS_KEY    = 'pyro_scope_last_scan_results';
	public const TIMESTAMP_KEY  = 'pyro_scope_last_scan_timestamp';
	public const LOCK_KEY       = 'pyro_scope_scan_lock';
	public const SESSION_PREFIX = 'pyro_scope_scan_';
	/** Transient holding the WordPress.org checksum table for the running scan. */
	public const CHECKSUM_KEY = 'pyro_scope_core_checksums';

	/** @var array<string, mixed> */
	private array $options;

	/** @var array<string, mixed> */
	private const DEFAULTS = array(
		'enable_scanner'   => 1,
		'enable_integrity' => 1,
		'enable_updates'   => 1,
		'whitelist_paths'  => array(),
	);

	public function __construct() {
		$this->load();
	}

	private function load(): void {
		/** @var array<string, mixed>|false $saved */
		$saved = get_option( self::OPTIONS_KEY, false );

		if ( false === $saved ) {
			add_option( self::OPTIONS_KEY, self::DEFAULTS, '', 'no' );
			$this->options = self::DEFAULTS;
			return;
		}

		$known         = array_intersect_key( (array) $saved, self::DEFAULTS );
		$this->options = array_replace( self::DEFAULTS, $known );
		if ( $this->options !== $saved ) {
			update_option( self::OPTIONS_KEY, $this->options, false );
		}
	}

	/** @return array<string, mixed> */
	public function all(): array {
		return $this->options;
	}

	/** @param array<string, mixed> $options */
	public function save( array $options ): bool {
		$options = array(
			'enable_scanner'   => ! empty( $options['enable_scanner'] ) ? 1 : 0,
			'enable_integrity' => ! empty( $options['enable_integrity'] ) ? 1 : 0,
			'enable_updates'   => ! empty( $options['enable_updates'] ) ? 1 : 0,
			'whitelist_paths'  => is_array( $options['whitelist_paths'] ?? null )
				? array_values( $options['whitelist_paths'] )
				: array(),
		);
		$saved   = update_option( self::OPTIONS_KEY, $options, false );
		if ( ! $saved && get_option( self::OPTIONS_KEY, false ) !== $options ) {
			return false;
		}

		$this->options = $options;
		return true;
	}

	/** @return array{scanner: bool, integrity: bool, updates: bool} */
	public function modules(): array {
		return array(
			'scanner'   => ! empty( $this->options['enable_scanner'] ),
			'integrity' => ! empty( $this->options['enable_integrity'] ),
			'updates'   => ! empty( $this->options['enable_updates'] ),
		);
	}

	/**
	 * Configured exclusions resolved to absolute, normalised directories.
	 *
	 * @return list<string>
	 */
	public function whitelist_directories(): array {
		$whitelist  = array();
		$configured = is_array( $this->options['whitelist_paths'] ?? null ) ? $this->options['whitelist_paths'] : array();
		foreach ( $configured as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$relative = self::normalize_whitelist_entry( $entry );
			if ( null !== $relative ) {
				$whitelist[] = wp_normalize_path( ABSPATH . $relative );
			}
		}

		return $whitelist;
	}

	/** Reject absolute paths and traversal segments; return an ABSPATH-relative path. */
	public static function normalize_whitelist_entry( string $entry ): ?string {
		$entry = trim( wp_normalize_path( $entry ) );
		if ( '' === $entry
			|| str_starts_with( $entry, '/' )
			|| str_contains( $entry, ':' )
			|| str_contains( $entry, "\0" )
			|| preg_match( '#(^|/)\.\.?(/|$)#', $entry )
		) {
			return null;
		}
		return rtrim( (string) preg_replace( '#/+#', '/', $entry ), '/' );
	}
}
