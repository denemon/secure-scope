# Pyro Scope

WordPress security monitoring plugin for file signatures, database content, core integrity, and plugin updates. It can run standalone or alongside Pyro Shield.

Pyro Scope is not a complete vulnerability scanner. A clean result means that every enabled check completed without matching the implemented rules; it does not guarantee that a site is malware-free.

- Stable tag: 3.5.2
- License: GPLv2 or later
- PHP: 8.1 or later
- WordPress: 6.0 or later (tested through 7.0)

## Checks

### File scan

The scanner walks files under `ABSPATH` and inspects PHP-family files (`php`, `php3`, `php4`, `php5`, `php7`, `phtml`, `phar`, `phps`, and `inc`) plus JavaScript. It detects:

- variable calls to command-execution functions;
- obfuscated `eval` / `assert` calls;
- request-input includes, evals, command execution, and PHP file writes;
- uploaded-file moves to PHP destinations; and
- PHP-family files in the resolved uploads directories.

Inert uploads guard files of at most 200 bytes are ignored only when they contain an opening tag, comments, and whitespace and produce no output. User-configured exclusions are matched at directory boundaries. No path is excluded by default.

File contents are read in 1 MB chunks with a 64 KB overlap. A request reads at most 16 MB and stores the current file offset and overlap tail, so one large file resumes in the next request. A file that changes between batches is reported as an error instead of being treated as clean.

### Database scan

The scanner checks published posts, regular WordPress options, and approved comments for:

- obfuscated code calls;
- `javascript:` or `data:` script and iframe sources; and
- inline event attributes on commonly abused HTML elements.

Ordinary script tags and HTTPS embeds are not findings. WordPress transient options are excluded because they are mutable caches, including Pyro Scope's own checksum cache.

Rows use keyset pagination. The query reads 20,001 characters to identify values beyond the 20,000-character inline limit. Oversized values resume from a saved character offset in 100,000-character windows with a 2,000-character overlap, up to 20 windows per request. End-of-value and read failures are distinct states.

### Core integrity

Local core files are compared with the locale-aware checksum table from WordPress.org. The scanner reports deleted, unreadable, modified, linked, and unexpected files under `wp-admin` and `wp-includes`; `wp-content` is excluded from core integrity.

The checksum table is validated, cached for one hour by WordPress version and locale, and reused across batches. Checksum comparison and unexpected-file traversal both resume from stored cursors.

### Plugin updates

The plugin calls WordPress's native update check and reads the standard `update_plugins` site transient. A missing timestamp, update response, or no-update response is an error and cannot be displayed as “no updates.” Plugins without a standard update provider may appear in neither response; Pyro Scope does not invent update status for them.

This is a version check, not a CVE or advisory feed. An available update does not necessarily mean that the installed version is vulnerable.

## Scan execution

Manual scans use authenticated admin AJAX requests; weekly scans use WP-Cron. Enabled stages run in this order:

1. `integrity`
2. `files`
3. `db`
4. `updates`

Manual and scheduled scans share a 15-minute lock and transient-backed session. Lock acquisition, expired-lock replacement, refresh, and release use compare-and-swap database operations. One process cannot overwrite or delete a replacement owner's lock.

Each AJAX request runs one batch. A cron request runs as many batches as its wall-clock budget permits, stores progress after every batch, and schedules one continuation event when work remains. Activation schedules the first weekly run one week later. Deactivation removes the active session, lock, weekly event, and continuation events.

The per-batch time budget is half of PHP's `max_execution_time`, capped at 8 seconds and floored at 0.25 seconds. Unlimited runtimes use 8 seconds. External checksum requests use only the remaining batch time. The cron request budget defaults to half of `max_execution_time`, capped at 20 seconds, or 20 seconds when unlimited.

## Limits and failure states

| Limit | Value |
|---|---:|
| Filesystem entries | 250,000 per scan |
| Executable files | 50,000 per scan |
| File content | 16 MB per request |
| Database rows | 200 per batch |
| Oversized DB windows | 20 per batch |
| File findings | 1,000 per scan |
| Database findings | 1,000 per scan |
| Integrity findings | 1,000 per scan |
| Unexpected core entries | 100,000 per scan |
| File/core entries | 1,000 per batch |

Reaching a scan or finding limit marks the result incomplete. Directory and file read failures, unsafe symlinks, database query failures, disappearing oversized values, checksum API failures, incomplete update metadata, lost locks, and result-storage failures are also recorded as incomplete states. Disabled, clean, findings, failed, and incomplete results are rendered separately.

A very large single directory must still be listed before its entries can be split into batches. A signature split by more than the configured file or database overlap can evade a match. Pattern rules cannot detect every obfuscation technique.

## Configuration

The `pyro_scope_options` option contains only these keys:

- `enable_scanner` — file and database checks (default: `1`)
- `enable_integrity` — core checksum check (default: `1`)
- `enable_updates` — plugin update check (default: `1`)
- `whitelist_paths` — `ABSPATH`-relative file-scan exclusions (default: `[]`)

Whitelist input rejects absolute paths, drive-qualified paths, null bytes, and `.` / `..` traversal segments. A settings database failure is shown in the admin screen and does not update the in-memory configuration.

The `pyro_scope_cron_seconds` filter changes the wall-clock budget for one cron request. A value of `0` is useful for forcing exactly one batch per cron invocation in tests.

## Storage and cleanup

- Completed results: non-autoloaded `pyro_scope_last_scan_results` option
- Last completed timestamp: non-autoloaded `pyro_scope_last_scan_timestamp` option
- Active lock: non-autoloaded `pyro_scope_scan_lock` option
- Partial sessions: 15-minute `pyro_scope_scan_*` transients
- Core checksums: one-hour `pyro_scope_core_checksums` transient
- Plugin updates: WordPress's native `update_plugins` site transient

Uninstall removes settings, results, timestamps, locks, scan sessions, the checksum cache, and both cron hooks on every site in a multisite network. Pyro Scope never deletes or quarantines detected content.

## Security boundaries

- Every AJAX stage requires `manage_options`, a valid nonce, the random scan ID, and ownership of the active lock.
- Result HTML escapes every dynamic value in the template.
- Live log lines are inserted with `document.createTextNode`, not HTML.
- Checksum paths cannot be absolute or contain traversal, drive, or null-byte components.
- Symlinked directories are not traversed, and file symlinks cannot escape `ABSPATH`.
- External communication is limited to WordPress.org core checksums and WordPress's native update mechanism.
- Public requests return before plugin classes are loaded because Pyro Scope has no public hooks.

## Installation and release build

1. Upload `pyro-scope-<version>.zip` in the WordPress plugin installer, or copy its `pyro-scope` directory into `wp-content/plugins/`.
2. Activate Pyro Scope.
3. Open the Pyro Scope admin page to configure and run a scan.

Build a production archive from the source checkout:

```sh
./bin/build-release.sh
```

The archive has a top-level `pyro-scope` directory. `.distignore` excludes tests, Composer files and dependencies, release archives, repository metadata, agent instructions, local settings, and OS metadata.

Verify that the production ZIP installs, activates, runs, deactivates, and
uninstalls cleanly across the supported WordPress/PHP environments:

```sh
npm run test:release
```

The final audit also exercises lock, transient, deactivation, and uninstall
behavior against Redis 7.4 and Redis Object Cache 2.8.0:

```sh
npm run test:object-cache
```

## Development

Install and run the PHP 8.1-compatible PHPUnit suite:

```sh
composer install
composer check
```

Run the real WordPress matrix and Plugin Check in disposable Docker environments:

```sh
npm install
npm run check
```

The runtime matrix covers WordPress 7.0.2 with PHP 8.4 on a single site and
WordPress 6.0.12 with PHP 8.1 on multisite. It verifies activation,
deactivation, hooks, conditional assets, non-autoloaded settings, concurrent
real-MySQL locking and replacement-owner protection, transient sessions,
Plugin Check, and a clean WordPress debug log.

The current suite contains 35 tests and 139 assertions. It covers initialization, assets, result states and saved logs, malformed stored data, settings failures, whitelist validation, large-file byte-offset resumption, file traversal state, DB errors and pagination, transient exclusion, oversized-value resumption and end detection, checksum validation and traversal, lock races, cron continuation, AJAX cleanup, and update-metadata completeness.

Phase 3 browser acceptance covers authenticated settings submission, a complete
multi-request manual scan, lock-conflict recovery, ARIA progress state, browser
console output, and desktop/mobile-width layouts against the real wp-env site.
Phase 4 release acceptance installs the generated production ZIP into clean
single-site and multisite environments and verifies its complete lifecycle.
Phase 5 runs the concurrency and lifecycle gates with Redis Object Cache and
verifies the final persisted state after uninstall.
Phase 6 assigns a new patch version and verifies that repeated release builds
produce the same archive hash.

Production responsibilities are separated as follows:

| File | Responsibility |
|---|---|
| `pyro-scope.php` | Header, context guard, class loading, lifecycle hooks, boot |
| `src/class-plugin.php` | Object composition, WordPress hooks, and site-aware lifecycle |
| `src/class-settings.php` | Supported options, validation, persisted keys |
| `src/class-scan-session.php` | CAS lock and resumable session storage |
| `src/class-scan-runner.php` | Step order, accumulated state, result persistence |
| `src/class-scan-controller.php` | AJAX and WP-Cron lifecycle |
| `src/class-admin-page.php` | Admin menu, assets, settings, page rendering |
| `src/class-results-renderer.php` | Escaped result rendering boundary |
| `src/class-file-scanner.php` | Resumable filesystem scan |
| `src/class-db-scanner.php` | Resumable posts/options/comments scan |
| `src/class-integrity-scanner.php` | Resumable WordPress core integrity scan |
| `src/class-update-scanner.php` | Native plugin update check |

## Upgrade notes

Version 3.5.2 requires no settings migration. Its first automatic weekly scan
is scheduled one week after activation instead of running immediately.

Version 3.5.1 intentionally removes obsolete compatibility paths. The former `enable_vuln` setting is replaced by `enable_updates`, the default whitelist is empty, old result shapes are not rendered, and pre-3.x public JSON logs are not migrated. Re-save settings and run a fresh scan after upgrading.

## Changelog

### 3.5.2

- Delayed the first weekly scan until one week after activation to prevent it from competing with an initial manual scan.
- Added browser, real-MySQL concurrency, persistent Redis cache, and clean release-install verification.
- Verified that deactivation preserves completed data and uninstall removes plugin data across multisite.
- Made repeated release builds produce byte-identical ZIP archives.

### 3.5.1

- Removed the legacy public-log migration, old cache cleanup, test-only synchronous scan APIs, redundant Composer archive configuration, stale release archives, and unused test stubs.
- Renamed the update stage and setting from vulnerability terminology to `updates` / `enable_updates`.
- Removed the default Pyro Shield exclusion and fixed absolute-path whitelist validation.
- Made file content, oversized DB values, checksum comparison, and unexpected-core traversal resumable within explicit time, byte, item, and finding limits.
- Fixed same-second lock refreshes, refresh/release races, empty DB chunk handling, transient-cache self-scanning, incomplete update metadata, settings write errors, and checksum path validation.
- Stopped loading plugin classes on ordinary public requests and excluded development instructions from release archives.

### 3.5.0

- Added wall-clock batch budgets, resumable core checksum comparison, keyset DB scanning, CAS lock acquisition, and multisite uninstall cleanup.

### 3.4.0

- Moved results into private WordPress options, split manual and scheduled work into stages, adopted native plugin-update metadata, expanded signature coverage, and added production ZIP packaging.
