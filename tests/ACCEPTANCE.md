# Pyro Scope Acceptance Criteria

This document defines the observable behavior that must remain true while the
plugin is hardened. A release is acceptable only when every applicable check
passes in the declared support matrix and every verification gap is reported.

## Phase 0 baseline

Baseline recorded on 2026-08-10 before production-code changes:

- PHP 8.5.3 CLI
- PHPUnit 10.5.64
- 34 tests and 133 assertions passing
- all project PHP files passing `php -l`
- `composer validate --strict --no-check-publish` passing
- build shell script and admin JavaScript syntax checks passing

This baseline uses WordPress test doubles. It does not yet prove compatibility
with the declared PHP 8.1 minimum, a real WordPress database, WP-Cron,
multisite, an external object cache, or supported browser environments. Those
gaps must be closed before a hardened release is described as verified.

## Phase 1 verification gates

- `composer check` must pass Composer security audit, PHP syntax checks,
  WordPress Coding Standards, PHP 8.1+ compatibility, and PHPUnit.
- `npm audit --audit-level=high` must report no vulnerabilities.
- Plugin Check must complete in strict mode with no errors or warnings against
  the files included in a production release.
- WordPress 7.0.2 with PHP 8.4 must pass on a real single-site MySQL runtime.
- WordPress 6.0.12 with PHP 8.1 must pass on a real multisite MySQL runtime,
  including a secondary site's lifecycle and site-local state.
- Both runtime environments must pass activation, deactivation, hook, asset,
  option-autoload, lock, transient-session, and debug-log smoke checks.

## Required behavior

### Loading and lifecycle

- Ordinary public requests return before plugin classes or hooks are loaded.
- Admin, authenticated AJAX, WP-Cron, and WP-CLI contexts can initialize the
  plugin without warnings, notices, deprecations, or fatal errors.
- Activation schedules at most one weekly scan event.
- Deactivation removes weekly and continuation events, the active session, and
  the lock without deleting completed scan results or settings.
- Uninstall removes every plugin-owned option, transient, and cron event from a
  single site and from every site in a multisite network.

### Administration and settings

- Only a user with `manage_options` can access or modify plugin settings.
- A settings update requires a valid nonce.
- Checkbox settings are stored as `0` or `1`.
- Whitelist entries are ABSPATH-relative and reject absolute paths, drive
  prefixes, null bytes, and `.` or `..` traversal segments.
- A failed option write is shown as a failure and does not change the in-memory
  configuration.
- CSS and JavaScript load only on the Pyro Scope admin screen.

### Manual scan

- Starting a scan requires a valid nonce and `manage_options`.
- Starting a scan returns a random, valid scan ID only after the exclusive lock
  and resumable session have both been stored.
- A second scan cannot acquire a live lock.
- Every subsequent request must present the expected scan ID and next step.
- Cancel and finish remove the caller's session and lock.
- A stale owner cannot refresh, overwrite, or release a replacement owner's
  lock.
- Storage, lock, scanner, or rendering failures never produce a clean result.

### Scheduled scan

- Weekly and continuation runs use the same stages and result semantics as a
  manual scan.
- Work is saved after each batch and resumes from the saved cursor.
- At most one continuation event is scheduled for an unfinished scan.
- Loss of the lock, session, storage, or continuation schedule never produces
  a clean result.
- A completed or explicitly incomplete scheduled run releases its session and
  lock.

### Scan results

- Enabled stages run in this order: integrity, files, database, updates.
- Disabled, clean, findings, failed, and incomplete states remain visibly
  distinct.
- Any read failure, query failure, unsafe symlink, limit, remote failure,
  malformed update metadata, or storage failure marks the scan incomplete.
- Dynamic file paths, database locations, findings, errors, and log lines are
  escaped before insertion into HTML.
- A clean result means only that every enabled implemented check completed
  without a match; it is never presented as a malware-free guarantee.

### File scan

- Only configured PHP-family and JavaScript extensions are content-scanned.
- Whitelist paths match directory boundaries and cannot escape ABSPATH.
- Symlinked directories are not traversed; file symlinks cannot escape ABSPATH.
- PHP-family files in resolved upload directories are reported except for
  inert guard stubs.
- Large files resume by byte offset with overlap, and a file changed between
  batches is reported as incomplete.
- Entry, file, byte, finding, and time limits are enforced without reporting a
  partial scan as clean.

### Database scan

- Only published posts, non-transient options, and approved comments are
  scanned.
- Rows use keyset pagination and no query runs inside a row-processing loop.
- Oversized values resume by character offset with overlap through their real
  end.
- Query and long-value read failures are distinct from a clean end of data.
- Row, chunk, finding, and time limits are enforced without reporting a
  partial scan as clean.

### Core integrity and plugin updates

- Core checksums come from WordPress.org for the installed version and locale.
- Checksum paths, checksum values, and response shape are validated before use.
- `wp-content` is excluded from core integrity.
- Missing, unreadable, modified, linked, and unexpected core files remain
  distinct findings.
- Plugin updates use WordPress's native update mechanism and site transient.
- Missing or incomplete native update metadata is an error, never “no updates.”

### Persistence and performance

- Settings, completed results, timestamps, and locks are non-autoloaded.
- Partial sessions and checksum data expire through the Transients API.
- No plugin database query, remote request, or asset load occurs on ordinary
  public requests.
- Scanner work remains resumable and bounded by the documented time, entry,
  byte, row, and finding limits.

## Phase 2 concurrency gates

- Eight independent WordPress processes racing for an absent lock must produce
  exactly one owner on real MySQL.
- Eight processes racing to replace an expired lock must produce exactly one
  replacement owner.
- Losing owners must not release the winning owner's lock.
- An owner whose cached lock was replaced before refresh or release must not
  overwrite or delete the replacement owner.
- A scheduled scan must not replace an active manual scan lock.
- These checks must pass in both declared WordPress/PHP runtime environments.

## Phase 3 browser gates

- The WordPress administrator can open the Pyro Scope screen without a browser
  console warning or error.
- Saving settings shows a success notice, preserves checkbox state, and removes
  invalid whitelist paths from the rendered form value.
- Starting a scan disables the button, reveals the progress indicator, updates
  `aria-valuenow`, streams plain-text log lines, renders the result, and restores
  the button when complete.
- A competing live lock produces a visible error, a red completed progress bar,
  and restores the button without leaving a browser or WordPress debug error.
- The screen remains readable at the default desktop viewport and at 390 px;
  result paths wrap without horizontal page overflow.
- Plugin activation schedules the first weekly scan one week in the future so
  opening the administration screen cannot immediately start a competing scan.

## Phase 4 release gates

- The production ZIP passes an integrity check and contains one top-level
  `pyro-scope` directory with the plugin and uninstall entry points.
- The ZIP contains no tests, dependencies, build tools, repository metadata,
  local environment configuration, agent instructions, or previous archives.
- A clean WordPress environment has no Pyro Scope installation before WP-CLI
  installs the production ZIP through the standard plugin installer.
- The installed ZIP passes activation, runtime, deactivation, uninstall, and
  debug-log checks on every declared WordPress/PHP environment.

## Phase 5 final audit gates

- Redis 7.4 and Redis Object Cache 2.8.0 are connected and retain a probe value
  across independent WordPress requests before plugin checks begin.
- Real Redis-backed option and transient caches pass the lock race,
  compare-and-swap, runtime, deactivation, and uninstall checks.
- Deactivation preserves settings, completed results, timestamps, and checksum
  cache while removing active scan state and scheduled events.
- Uninstall removes every plugin option, checksum transient, and cron event;
  the same assertions pass with and without a persistent object cache.

## Final audit result

The 3.5.2 release candidate passed every Phase 1–6 gate on 2026-08-10. Browser
acceptance used the Chromium-based Codex browser. WordPress 6.0 itself emits
Requests-library deprecation messages while its plugin installer runs on PHP
8.1; these occur before Pyro Scope loads, and the plugin lifecycle debug log is
clean. This verification supports release readiness but cannot guarantee that
the scanner detects every threat or that every hosting stack is defect-free.

## Phase 6 release finalization gates

- The plugin header, runtime version, README stable tag, WordPress.org stable
  tag, changelog, archive name, and installed version all identify 3.5.2.
- Two consecutive builds from the same checkout produce byte-identical ZIP
  archives and the same SHA-256 digest.
- The final 3.5.2 archive passes every existing release-installation gate.

## Verification commands

Run these commands from the plugin root before and after each completed phase:

```sh
composer validate --strict --no-check-publish
find . -path './vendor' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
./vendor/bin/phpunit
bash -n bin/build-release.sh
bash -n bin/test-concurrency.sh
bash -n bin/test-object-cache.sh
bash -n bin/test-release.sh
node --check assets/js/pyro-scope-admin.js
```

Phase 1 adds WordPress Coding Standards, PHP compatibility, Plugin Check, real
WordPress integration, multisite, and debug-log gates. Phase 2 adds real-MySQL
multi-process lock and compare-and-swap gates. Phase 3 adds authenticated admin,
AJAX state, failure recovery, console, and responsive browser checks. Phase 4
builds the production archive and installs it into clean single-site and
multisite environments. Phase 5 closes the persistent-object-cache gap and
verifies the final uninstall state. Phase 6 finalizes a unique release version
and reproducible archive. Passing the Phase 0 commands alone is not sufficient
for a production safety claim.

Run all automated Phase 1, Phase 2, Phase 4, Phase 5, and Phase 6 gates with:

```sh
composer check
npm install
npm run check
```
