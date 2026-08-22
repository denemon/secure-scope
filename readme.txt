=== Pyro Scope ===
Contributors: ikkido-den
Tags: security, malware, integrity, scanner, monitoring
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 3.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks WordPress files, database content, core checksums, and plugin update status in resumable batches.

== Description ==

Pyro Scope is a security monitoring plugin. It detects configured malicious code patterns, checks unexpected or modified WordPress core files, scans published posts, regular options, and approved comments, and reports available plugin updates.

Large files, oversized database values, checksum comparison, and unexpected-core traversal resume across requests. Manual scans and WP-Cron share an expiring compare-and-swap lock so overlapping scans cannot overwrite each other's state.

Pyro Scope is not a complete vulnerability scanner, malware-removal tool, WAF, or substitute for a professional security review. Plugin update checks compare versions using WordPress update metadata; they do not query a CVE database.

== Installation ==

1. Upload the release package to the WordPress plugin installer.
2. Activate Pyro Scope.
3. Open Pyro Scope in the WordPress administration menu.
4. Review the settings and run the first manual scan.

== Frequently Asked Questions ==

= Does Pyro Scope delete detected files? =

No. It reports findings only.

= Does the plugin guarantee that a site is malware-free? =

No. Pattern scanning has inherent limits. A clean result means that the enabled checks completed without matching the implemented rules.

= Is Pyro Shield required? =

No. Pyro Scope works independently. No path, including Pyro Shield, is excluded from scanning by default.

= Why has a scheduled scan not finished? =

Scheduled scans run in batches through WP-Cron, which only runs when the site receives a request. Each run stores progress, but a site that receives no request for 15 minutes lets the session expire. Low-traffic sites should invoke wp-cron.php from a real scheduler.

= What does the plugin store, and is it removed on uninstall? =

Pyro Scope stores settings, the latest result and timestamp, one short-lived lock, one checksum-cache transient, temporary scan-session transients, and two cron hooks. It creates no custom tables, posts, or user metadata. WordPress uninstall removes this data on every site of a multisite network.

= What changed in 3.5.2? =

The first automatic weekly scan now runs one week after activation instead of immediately. No settings migration is required.

== Changelog ==

= 3.5.2 =

* Delayed the first weekly scan until one week after activation to avoid competing with an initial manual scan.
* Added browser, real-MySQL concurrency, persistent Redis cache, and clean release-install verification.
* Verified multisite deactivation and uninstall cleanup boundaries.
* Made repeated release builds produce byte-identical ZIP archives.

= 3.5.1 =

* Made a single large file, a single oversized DB value, and unexpected-core traversal resumable across requests.
* Fixed same-second lock refresh failures and compare-and-swap races during refresh and release.
* Fixed end-of-value DB chunks being reported as read failures and excluded mutable transient caches from DB scanning.
* Fixed absolute whitelist paths, incomplete plugin update metadata, settings write errors, and unsafe checksum paths.
* Renamed the update stage and setting to `updates` and `enable_updates`, with an empty default whitelist.
* Removed legacy migration code, old cache cleanup, test-only scan APIs, duplicate packaging configuration, stale artifacts, and unused test stubs.
* Stopped loading plugin classes on ordinary public requests and tightened production-package exclusions.

= 3.5.0 =

* Added wall-clock batch budgets, resumable core checksum comparison, keyset DB scanning, atomic lock acquisition, and multisite uninstall cleanup.

= 3.4.0 =

* Moved results into private WordPress options, split manual and scheduled work into stages, adopted native plugin-update metadata, expanded detection, and added production ZIP packaging.
