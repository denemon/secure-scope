# CLAUDE.md

## Mission

You are working on a WordPress plugin.

Your primary objective is not merely to produce code that looks correct.

Your objective is to produce code that is:

1. Functionally correct in a real WordPress runtime.
2. Secure according to WordPress security practices.
3. Compatible with the supported WordPress, PHP, and database versions.
4. Backward-compatible unless a breaking change is explicitly requested.
5. Verified through automated tests and, where appropriate, real browser/API interaction.
6. Resistant to regressions, edge cases, invalid input, partial failures, and repeated execution.
7. Observable enough that failures can be diagnosed.
8. Safe to activate, upgrade, deactivate, and uninstall.

Never treat implementation as complete until behavior has been verified.

---

# Core Principle: Evidence Over Assumption

Never claim that something works unless you have evidence that it works.

A code review is not proof of runtime behavior.

Static analysis is not proof of runtime behavior.

A passing unit test with mocked WordPress functions is not proof of WordPress integration behavior.

Whenever practical, verify behavior against a real WordPress installation.

For every meaningful implementation or bug fix:

1. Understand the expected behavior.
2. Identify likely failure modes.
3. Implement the smallest correct change.
4. Run static checks.
5. Run automated tests.
6. Run integration tests against WordPress when applicable.
7. Exercise the actual user-facing/API behavior when applicable.
8. Inspect logs and unexpected warnings.
9. Re-test related behavior for regressions.
10. Report exactly what was and was not verified.

Never say:

* "This should work."
* "This is probably fixed."
* "It looks correct."

when verification is possible.

Instead, verify it.

---

# Definition of Done

A task is complete only when all applicable requirements below are satisfied.

## Implementation

* The requested behavior is implemented.
* Existing behavior unrelated to the task is preserved.
* No unnecessary architectural changes were introduced.
* Public APIs remain backward-compatible unless explicitly requested otherwise.
* Error handling is intentional and predictable.
* The implementation follows existing project conventions.

## Validation

At minimum, run all relevant available checks:

* PHP syntax validation
* PHPUnit tests
* WordPress integration tests
* PHPCS / WordPress Coding Standards
* PHPStan or Psalm if configured
* JavaScript/TypeScript linting if applicable
* JavaScript unit tests if applicable
* Build process if applicable
* Browser/E2E tests if applicable
* Plugin activation test
* Relevant REST/AJAX/CLI requests
* Upgrade/migration tests when data structures change

A task must not be reported as fully verified if relevant checks were skipped.

---

# Never Hide Verification Gaps

If a test cannot be run because of missing infrastructure, credentials, services, Docker, browser dependencies, WordPress installation, or another external requirement:

1. State exactly which verification could not be performed.
2. State why.
3. Perform every remaining verification step that is possible.
4. Do not present unverified behavior as verified.

Example:

"PHPUnit and PHPCS passed. I could not execute the browser-based checkout flow because the local WordPress test environment is unavailable."

This is preferable to making an assumption.

---

# Understand the Repository Before Editing

Before making substantial changes, inspect the project.

At minimum, determine where applicable:

* Main plugin bootstrap file
* Plugin namespace/prefix
* Minimum PHP version
* Supported WordPress versions
* Composer configuration
* npm/package configuration
* PHPUnit configuration
* WordPress test environment
* Docker/wp-env configuration
* Coding standards configuration
* Static analysis configuration
* Build pipeline
* CI workflow
* Existing architectural patterns
* Database schema/migrations
* REST routes
* AJAX handlers
* WP-CLI commands
* Cron jobs
* Admin pages
* Gutenberg blocks
* Frontend assets
* Existing tests

Do not introduce a new framework, architecture, build system, dependency, or convention before understanding what the project already uses.

Prefer consistency with the repository over personal preference.

---

# WordPress Runtime Verification

Whenever behavior depends on WordPress internals, prefer testing it inside an actual WordPress runtime.

Examples include:

* Hooks
* Filters
* Roles
* Capabilities
* Nonces
* REST API
* AJAX
* WP_Query
* Metadata
* Options
* Transients
* Cron
* Rewrite rules
* Shortcodes
* Blocks
* Widgets
* Plugin activation
* Plugin deactivation
* Multisite behavior
* User authentication
* Upload handling
* Database upgrades
* WooCommerce integration
* Third-party plugin integration

Mocks may be used for isolated unit tests, but they should not replace integration tests for behavior that depends on WordPress.

---

# Test the Behavior, Not the Implementation

Prefer tests that describe externally observable behavior.

Bad test:

* Verifies that a specific private method was called.

Better test:

* Creates the relevant WordPress state.
* Performs the operation.
* Confirms the expected WordPress state or output.

Tests should survive internal refactoring whenever the public behavior remains unchanged.

---

# Reproduce Bugs Before Fixing Them

For bug fixes, whenever feasible:

1. Create a failing test or reproducible scenario first.
2. Confirm the failure.
3. Implement the fix.
4. Confirm the test now passes.
5. Run related regression tests.

Do not modify code based solely on speculation about the cause.

Determine the actual failure path.

---

# Regression Protection

Every bug fix should include a regression test when practical.

The test should:

* Fail before the fix.
* Pass after the fix.
* Represent the actual failure condition.
* Avoid depending unnecessarily on implementation details.

If a regression test cannot reasonably be added, explain why.

---

# WordPress Security Requirements

Security requirements are mandatory.

## Authorization

Authentication is not authorization.

For privileged operations, verify the appropriate capability using functions such as:

```php
current_user_can()
```

Never assume that:

* Being logged in is sufficient.
* A nonce proves authorization.
* An admin page is inaccessible to unauthorized requests.
* A REST callback is safe because the UI hides it.

Every privileged server-side action must enforce authorization independently.

---

# Nonces

Use WordPress nonces for CSRF protection when appropriate.

Use:

```php
wp_nonce_field()
wp_verify_nonce()
check_admin_referer()
check_ajax_referer()
```

as appropriate.

A nonce is not a replacement for a capability check.

Sensitive operations generally require both:

* CSRF protection
* Authorization

---

# Input Validation and Sanitization

Treat all external input as untrusted.

This includes:

* `$_GET`
* `$_POST`
* `$_REQUEST`
* `$_COOKIE`
* REST API parameters
* AJAX parameters
* Shortcode attributes
* Block attributes
* WP-CLI arguments
* Imported files
* Remote API responses
* Database content originating from users

Validate input according to its expected type and domain.

Use appropriate WordPress utilities where applicable, including:

```php
sanitize_text_field()
sanitize_key()
sanitize_email()
absint()
intval()
floatval()
wp_unslash()
esc_url_raw()
```

Do not sanitize blindly.

Validation should reject values that are not valid for the domain.

Prefer allowlists over denylists.

---

# Output Escaping

Escape output at the point of output.

Use context-appropriate escaping:

```php
esc_html()
esc_attr()
esc_url()
esc_textarea()
wp_kses()
wp_kses_post()
```

Do not assume that previously sanitized data is safe for every output context.

Sanitization and escaping solve different problems.

---

# SQL Safety

Never concatenate untrusted input into SQL.

Use `$wpdb->prepare()` where parameters are required.

Prefer WordPress APIs over direct SQL when practical.

If direct SQL is necessary:

* Use the correct table prefix.
* Use placeholders.
* Handle multisite correctly.
* Check query errors when meaningful.
* Avoid destructive operations without explicit necessity.
* Consider indexes for frequently queried columns.

Never assume table names are always prefixed with `wp_`.

---

# REST API Security

Every REST route must have an intentional `permission_callback`.

Do not use:

```php
'permission_callback' => '__return_true'
```

unless the endpoint is intentionally public.

For public endpoints:

* Assume they will be abused.
* Validate every argument.
* Avoid exposing sensitive data.
* Consider rate-related resource consumption.
* Prevent unrestricted expensive queries.

Test REST routes using actual HTTP requests when practical.

Verify:

* Unauthenticated access
* Authenticated unauthorized access
* Authorized access
* Invalid input
* Missing input
* Malformed input
* Correct status codes
* Correct response structure

---

# AJAX Security

For AJAX handlers:

* Verify nonce where appropriate.
* Verify capability where appropriate.
* Sanitize and validate all input.
* Escape output where applicable.
* Return structured responses with `wp_send_json_success()` or `wp_send_json_error()` when appropriate.
* Test both valid and invalid requests.

Remember that AJAX endpoints can be invoked independently of the UI.

---

# File Upload Security

For file uploads:

* Validate MIME type.
* Validate extension.
* Do not trust the client-provided MIME type.
* Use WordPress upload APIs where possible.
* Prevent executable content from being written into unsafe locations.
* Enforce capability checks.
* Enforce file size limits where appropriate.
* Safely handle duplicate names.
* Test malicious or unexpected input.

---

# Remote Request Safety

When calling external services:

* Use the WordPress HTTP API.
* Handle timeouts.
* Handle WP_Error.
* Handle non-2xx responses.
* Validate response structures.
* Avoid assuming JSON decoding succeeds.
* Escape or sanitize remote content before rendering it.
* Avoid SSRF risks when URLs can be user-controlled.
* Use `wp_safe_remote_get()` / `wp_safe_remote_post()` where appropriate.

Tests should cover network failure and malformed responses when practical.

---

# Data Integrity

Any operation that modifies persistent data must consider:

* Duplicate execution
* Partial execution
* Failure halfway through
* Retry behavior
* Concurrent requests
* Existing legacy data
* Unexpected data types
* Missing rows/options/meta
* Migration from previous versions

Prefer idempotent operations where practical.

Running an operation twice should not corrupt data.

---

# Database Schema Changes

Database changes require special care.

When modifying tables or stored data:

1. Determine the existing schema.
2. Preserve existing installations.
3. Add an explicit migration path.
4. Make migrations safe to run more than once whenever possible.
5. Test both fresh installation and upgrade.
6. Test existing data preservation.
7. Test partially migrated or unexpected states when relevant.

Never assume that all users install the plugin from scratch.

---

# Plugin Version Upgrades

When behavior depends on plugin version:

* Test a fresh installation.
* Test upgrade from the oldest supported relevant schema/version.
* Test upgrade from the immediately previous version.
* Confirm migrations are not repeatedly destructive.
* Confirm stored plugin version is updated only when appropriate.

Upgrade code should be defensive.

---

# Activation

Plugin activation must be tested whenever activation logic exists.

Verify:

* Activation succeeds on a clean installation.
* Required options/tables are created.
* Existing data is not unnecessarily overwritten.
* Re-activation is safe.
* Rewrite rules are flushed only when necessary.
* Activation does not generate PHP warnings/notices.
* Activation failure is handled clearly when prerequisites are missing.

Do not perform expensive work on every request when it belongs in activation or migration logic.

---

# Deactivation

Deactivation should normally disable runtime behavior without deleting user data.

Verify:

* Scheduled events are unscheduled where appropriate.
* Temporary resources are cleaned up where appropriate.
* Persistent user data remains unless the plugin explicitly defines otherwise.
* Re-activation works.

---

# Uninstall

Uninstall behavior must be deliberate.

Never delete user data merely because the plugin is deactivated.

If uninstall deletes data:

* Use `uninstall.php` or appropriate WordPress uninstall hooks.
* Verify that uninstall was actually initiated by WordPress.
* Consider multisite.
* Delete only data owned by the plugin.
* Do not delete shared data.
* Test uninstall separately.

Destructive uninstall behavior should be explicit and documented.

---

# Hooks and Filters

When adding hooks:

* Use the appropriate hook timing.
* Avoid duplicate registration.
* Use intentional priorities.
* Use the correct accepted argument count.
* Avoid unexpectedly mutating global state.

When implementing filters:

* Return a value of the expected type.
* Preserve incoming data unless modification is intentional.
* Avoid throwing uncaught errors into unrelated WordPress execution.

Test important hooks through WordPress execution rather than only invoking callback methods directly.

---

# Cron and Scheduled Events

For WP-Cron behavior:

* Avoid scheduling duplicate events.
* Ensure events are unscheduled appropriately.
* Make callbacks safe to execute more than once.
* Handle failures without corrupting state.
* Avoid assuming exact execution timing.
* Avoid long-running work when a queue/batch architecture is more appropriate.

Test:

* Initial scheduling
* Duplicate scheduling attempts
* Callback execution
* Repeated callback execution
* Deactivation cleanup

---

# Multisite

If the plugin may run on multisite, explicitly determine whether behavior is:

* Per-site
* Network-wide
* User-wide
* Global

Do not accidentally mix:

```php
get_option()
```

with:

```php
get_site_option()
```

Network activation should be considered separately from normal activation.

Database table creation, migrations, uninstall, and scheduled events require special multisite attention.

If multisite is not supported, document that limitation explicitly.

---

# Internationalization

User-facing strings should use WordPress internationalization functions when the plugin supports translation.

Examples:

```php
__()
_e()
esc_html__()
esc_html_e()
esc_attr__()
esc_attr_e()
```

Use the correct plugin text domain.

Do not construct translatable sentences by concatenating fragments when avoidable.

---

# Date and Time Handling

Do not assume the server timezone equals the WordPress timezone.

Prefer WordPress-aware date/time APIs.

Be careful with:

* `time()`
* `date()`
* `strtotime()`
* UTC timestamps
* Site-local timestamps

Test timezone-sensitive behavior when relevant.

---

# Caching and Transients

Caching must not change correctness.

When using:

* Transients
* Object cache
* Static caches
* Persistent caches

consider:

* Cache invalidation
* Expiration
* Missing cache entries
* Stale values
* Persistent object-cache environments
* Multisite keys
* User-specific data

Never rely on a cache as the only source of permanent state.

---

# WordPress Query Performance

Avoid unnecessary database queries.

Watch for:

* Queries inside loops
* Repeated option lookups that bypass useful caching
* Unbounded `WP_Query`
* Loading entire tables into memory
* Expensive meta queries
* Missing indexes in custom tables
* REST endpoints returning unlimited result sets

Correctness comes first, but obviously pathological implementations should not be accepted.

---

# PHP Compatibility

Respect the plugin's declared minimum PHP version.

Do not use syntax or standard-library functionality newer than the supported PHP version unless the minimum version is intentionally being changed.

If changing the minimum PHP version:

* Update metadata.
* Update documentation.
* Update CI.
* Treat it as a compatibility change.

Run the test suite against supported PHP versions when CI or local tooling makes this possible.

---

# WordPress Compatibility

Do not assume APIs from the newest WordPress release are available if older WordPress versions are supported.

When using recently introduced WordPress APIs:

* Confirm the minimum supported WordPress version contains them.
* Add compatibility handling if appropriate.
* Update the minimum WordPress requirement only when explicitly intended.

---

# Third-Party Plugin Integrations

Never assume a third-party plugin is:

* Installed
* Active
* Initialized
* At a particular version
* Returning valid data

Check integration prerequisites safely.

Avoid fatal errors when optional dependencies are unavailable.

When possible, test both:

1. Dependency active.
2. Dependency missing/inactive.

For version-sensitive integrations, test the supported version boundaries.

---

# WooCommerce-Specific Rules

If the plugin integrates with WooCommerce:

* Use WooCommerce APIs instead of directly manipulating internal data where practical.
* Consider HPOS compatibility.
* Do not assume orders are stored only as `shop_order` posts.
* Test guest and registered-user flows when applicable.
* Test taxes, coupons, refunds, and different order statuses when relevant.
* Avoid relying on frontend-only protections for checkout behavior.

Declare compatibility with WooCommerce features only when verified.

---

# Admin UI

For admin functionality, test:

* Correct capability requirements
* Direct URL access
* Missing/invalid nonce
* Invalid form values
* Valid submission
* Error messages
* Success messages
* Repeated submission
* Escaped rendered output
* Existing stored values
* Empty values

The UI is not the security boundary.

The server-side handler is.

---

# Frontend UI and Browser Testing

For user-visible behavior, use browser-level testing when practical.

Verify the actual workflow rather than only individual PHP functions.

Examples:

* Form submission
* Login-dependent behavior
* Admin settings
* Gutenberg block behavior
* Checkout behavior
* JavaScript interaction
* Redirect behavior
* Error messages
* Successful state changes

A browser test should verify meaningful outcomes rather than merely checking that the page loaded.

---

# JavaScript

For plugin JavaScript:

* Do not rely on globals unless intentionally provided by WordPress or the plugin.
* Escape dynamic output.
* Treat API responses as untrusted.
* Handle network errors.
* Handle unexpected response shapes.
* Avoid duplicate event handlers.
* Avoid race conditions where possible.
* Preserve accessibility.

Run the configured lint/build/test commands before completion.

---

# Gutenberg / Block Editor

For blocks:

* Distinguish editor behavior from frontend rendering.
* Test block serialization when relevant.
* Preserve compatibility with existing saved block markup.
* Treat changes to saved markup as migration-sensitive.
* Avoid triggering "This block contains unexpected or invalid content" for existing content.
* Test server-side rendered blocks in WordPress.
* Validate and escape dynamic block attributes.

Backward compatibility of previously saved blocks is especially important.

---

# Accessibility

Do not introduce obvious accessibility regressions.

For UI changes, consider:

* Semantic HTML
* Associated form labels
* Keyboard navigation
* Focus management
* Button vs link semantics
* Accessible names
* Error messages
* ARIA only where appropriate

Do not replace semantic HTML with ARIA unnecessarily.

---

# Error Handling

Never silently swallow meaningful failures.

Failures should be handled according to context:

* Return `WP_Error` when appropriate.
* Return useful REST status codes.
* Return structured AJAX errors.
* Log developer-relevant unexpected failures where the project supports logging.
* Display safe, actionable user-facing messages where appropriate.

Do not expose:

* Stack traces
* Credentials
* API secrets
* SQL
* Internal filesystem paths
* Sensitive personally identifiable information

to end users.

---

# Logging

Logging must not leak sensitive data.

Do not log:

* Passwords
* Authentication cookies
* Nonces unless strictly necessary for temporary debugging
* API secrets
* Authorization headers
* Full payment information
* Sensitive personal data

Remove temporary debug logging before finalizing unless logging is intentionally part of the feature.

---

# WordPress Debug Mode

When possible, run important flows with:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

After testing, inspect the debug log.

A feature that appears to work but produces PHP warnings, notices, deprecations, or fatal errors is not considered fully verified.

Distinguish project-owned warnings from unrelated third-party/environment warnings.

---

# Clean Runtime Requirement

Important flows should ideally execute without:

* PHP fatal errors
* PHP warnings
* PHP notices
* Unhandled exceptions
* JavaScript exceptions
* Unexpected REST errors
* Unexpected database errors

Do not ignore warnings merely because the visible output looks correct.

---

# Automated Test Strategy

Use the appropriate testing level.

## Unit Tests

Use for isolated business logic that does not require WordPress runtime behavior.

Examples:

* Parsing
* Normalization
* Calculation
* Value objects
* Transformation functions

Keep these tests fast.

## WordPress Integration Tests

Use when behavior depends on WordPress.

Examples:

* Metadata
* Options
* Posts
* Users
* Roles
* Capabilities
* Hooks
* Filters
* REST routes
* Cron
* Database operations

Prefer WordPress factories when available.

## End-to-End Tests

Use for critical real workflows.

Examples:

* Admin configuration
* User registration
* Checkout
* Form submission
* Block editor
* Authentication flows

Do not use E2E tests as a substitute for all lower-level tests.

Use them to prove that the integrated system works.

---

# Test Edge Cases

Do not test only the happy path.

Consider applicable cases such as:

* Empty input
* Missing input
* `0`
* `"0"`
* `false`
* `null`
* Empty arrays
* Large values
* Negative values
* Unicode
* Emoji
* HTML
* Script-like input
* Quotes
* Backslashes
* Duplicate requests
* Repeated execution
* Unauthorized user
* Logged-out user
* Deleted referenced object
* Missing dependency
* Network timeout
* Invalid JSON
* Database failure
* Expired nonce
* Invalid nonce
* Multisite
* Different timezone
* Existing legacy data

Select edge cases based on the actual feature rather than testing arbitrary combinations.

---

# Test Permissions Explicitly

For any privileged feature, test at least:

1. Administrator or intended authorized role succeeds.
2. Logged-in unauthorized user fails.
3. Logged-out request fails when authentication is required.

Do not infer permission behavior from UI visibility.

---

# Verify Failure Behavior

Successful behavior is only half of correctness.

Tests should also verify that invalid operations fail safely.

Verify:

* Correct error code
* Correct HTTP status
* No unintended state mutation
* No partial data corruption
* No sensitive information exposure

An operation that rejects invalid input but still modifies the database is a failure.

---

# Test Idempotency

For operations likely to be repeated, execute them more than once during testing.

Examples:

* Activation
* Migration
* Cron scheduling
* Webhooks
* Import processing
* REST retry
* AJAX retry
* Background jobs

Confirm that duplicate execution does not create duplicate or corrupted state.

---

# Concurrency

When state can be updated concurrently, consider race conditions.

Examples:

* Inventory
* Counters
* Token consumption
* Job acquisition
* Webhook processing
* Unique record creation
* Rate limits

Avoid read-then-write logic that assumes another request cannot modify state between operations.

Concurrency tests should be added for high-risk paths when practical.

---

# Preserve Existing Tests

Do not weaken tests merely to make a change pass.

Never:

* Remove an assertion without understanding why.
* Mark a failing test as skipped merely to get green CI.
* Increase arbitrary timeouts to hide a race condition.
* Replace meaningful assertions with weaker ones.
* Delete a regression test because implementation changed.

If an existing test is genuinely incorrect because requirements changed, update it deliberately and explain why.

---

# No Test-Only Production Hacks

Do not modify production behavior solely to satisfy tests.

Avoid:

* Environment checks that bypass logic only under PHPUnit.
* Public methods added only to expose private internals to tests.
* Hardcoded test values in production logic.
* Disabling security behavior in test environments unless the test specifically controls that dependency.

Design for testability through clean interfaces and separation of concerns.

---

# Minimal Change Principle

Prefer the smallest change that correctly solves the problem.

Do not perform unrelated refactoring while fixing a bug unless the refactoring is necessary for correctness or verification.

Large unrelated diffs:

* Increase regression risk.
* Make review harder.
* Make verification less trustworthy.

If broader refactoring is valuable but unnecessary, keep it separate.

---

# Backward Compatibility

Assume existing users have:

* Existing plugin options
* Existing metadata
* Existing custom table rows
* Existing content generated by earlier plugin versions
* Existing integrations calling public hooks/functions
* Existing custom code depending on documented behavior

Do not change:

* Public function signatures
* Hook names
* Filter semantics
* Option structures
* Database formats
* REST response structures
* Shortcode behavior
* Saved block markup

without considering backward compatibility.

If breaking compatibility is intentional, make it explicit.

---

# Hooks Are Public APIs

Actions and filters exposed by the plugin should be treated as APIs.

Changing:

* Hook names
* Argument order
* Argument count
* Expected value types
* Execution timing

can break third-party extensions.

Avoid such changes unless necessary and deliberate.

---

# WordPress Coding Standards

Follow WordPress Coding Standards where consistent with the repository.

Prefer standard WordPress APIs and conventions.

Do not mass-format unrelated files.

Formatting changes should remain scoped to the code being modified unless the task explicitly requests broader cleanup.

---

# Dependencies

Do not add a new production dependency unless it provides meaningful value.

Before adding one:

* Check whether WordPress already provides the required functionality.
* Check whether the project already has an equivalent dependency.
* Consider PHP compatibility.
* Consider package size.
* Consider maintenance/security risk.
* Consider namespace conflicts.

Composer production dependencies must be correctly packaged for plugin distribution.

---

# Namespacing and Global Scope

Avoid polluting the WordPress global namespace.

Use the project's existing namespace or prefix conventions.

Functions, classes, constants, option names, cron hooks, REST namespaces, AJAX actions, database tables, and script handles should be sufficiently unique.

---

# Autoloading

If Composer autoloading is used:

* Follow the existing autoload structure.
* Ensure production dependencies are available in packaged builds.
* Do not assume development dependencies exist in production.
* Test a production-style installation when packaging behavior changes.

---

# Secrets

Never commit credentials or secrets.

Examples:

* API keys
* OAuth secrets
* Database credentials
* Private tokens
* Production URLs containing credentials

Use environment variables or established project configuration patterns.

Tests must use dummy/local credentials.

---

# Build and Distribution Verification

If the plugin has a build/package process, verify the distributed artifact rather than only the source tree.

Confirm that:

* Required PHP files are included.
* Required vendor dependencies are included.
* Built JS/CSS assets are included.
* Development-only files are excluded where intended.
* Source-only files are not accidentally required at runtime.
* The plugin can activate from the packaged artifact.

A source checkout working does not prove that the release ZIP works.

---

# Release Artifact Smoke Test

When practical for release-related tasks:

1. Build the distribution ZIP.
2. Install it into a clean WordPress instance.
3. Activate it.
4. Exercise critical functionality.
5. Inspect logs.
6. Confirm expected assets and dependencies are present.

This is one of the strongest forms of verification before release.

---

# Commands

Before inventing commands, inspect the repository for the supported commands.

Typical commands may include:

```bash
composer install
composer test
composer lint
composer phpcs
composer phpstan
vendor/bin/phpunit
vendor/bin/phpcs
vendor/bin/phpstan
npm install
npm ci
npm test
npm run lint
npm run build
npm run test:e2e
npx wp-env start
npx wp-env run tests-cli wp plugin activate <plugin>
```

Use project-defined scripts where available.

Do not assume these exact commands exist.

---

# WP-CLI Verification

Use WP-CLI when it provides a reliable way to inspect or exercise behavior.

Examples:

```bash
wp plugin status
wp plugin activate <plugin>
wp option get <option>
wp option update <option> <value>
wp user list
wp cron event list
wp rewrite list
wp db query
wp eval
```

Prefer repeatable CLI verification over manually guessing database state.

Do not perform destructive operations against an environment unless you know it is disposable.

---

# Database Inspection

When testing database behavior, inspect actual persisted state when useful.

Verify:

* Correct rows exist.
* Incorrect rows do not exist.
* Types/formats are as expected.
* Duplicate rows are not created.
* Migrations preserve previous values.

Do not assume a successful API response guarantees correct persistence.

---

# Browser Verification

For browser-visible tasks, verify what the user actually experiences.

Check applicable states:

* Initial page load
* Loading state
* Empty state
* Success state
* Validation error
* Server error
* Unauthorized state
* Repeated action
* Page refresh
* Back/forward navigation

Inspect the browser console for unexpected JavaScript errors.

---

# API Verification

For API-facing behavior, test real requests where practical.

Verify:

* Method
* URL
* Authentication
* Authorization
* Input schema
* Status code
* Response schema
* Side effects
* Failure response
* Repeated request behavior

Do not validate only the PHP callback in isolation if routing, authentication, or serialization is part of the behavior.

---

# Observability During Development

When diagnosing an issue:

* Reproduce it.
* Inspect relevant logs.
* Inspect HTTP responses.
* Inspect persisted state.
* Inspect registered hooks/routes where useful.
* Reduce the issue to the smallest reproducible case.

Do not repeatedly modify code without collecting new evidence.

---

# Root Cause Before Patch

Fix root causes rather than symptoms.

Before finalizing a bug fix, be able to explain:

1. What triggered the bug?
2. Why did the previous implementation behave incorrectly?
3. Why does the new implementation prevent the failure?
4. Which test proves the regression is fixed?

Avoid arbitrary guards whose only justification is that they make the error disappear.

---

# No Broad Exception Suppression

Do not suppress failures with:

```php
try {
    // ...
} catch ( \Throwable $e ) {
}
```

unless deliberately ignoring the failure is correct behavior.

Do not use PHP error suppression (`@`) to conceal a bug.

Handle expected failures explicitly.

Unexpected failures should remain diagnosable.

---

# Assertions Must Be Meaningful

Tests should assert outcomes precisely.

Prefer:

```php
$this->assertSame( 200, $response->get_status() );
$this->assertSame( 'expected', $actual );
```

over vague assertions such as:

```php
$this->assertNotEmpty( $actual );
```

when an exact expected value is known.

Strict assertions reduce false positives.

---

# Deterministic Tests

Tests must avoid unnecessary dependence on:

* Current date
* Current timezone
* Random values
* External network
* Execution order
* Shared mutable state

Freeze/control time where the project architecture supports it.

Use deterministic fixtures.

Clean up state between tests.

A flaky test is a defect.

---

# External APIs in Tests

Automated tests should not depend on live third-party APIs unless they are explicitly integration tests designed for that purpose.

Prefer:

* Mocked HTTP responses for deterministic tests.
* Separate optional live integration tests for external compatibility.

Test:

* Success
* Timeout
* Network failure
* Invalid JSON
* Authentication failure
* Rate limit response
* Unexpected response shape

---

# Avoid Over-Mocking WordPress

Do not mock WordPress APIs merely because integration setup requires more effort.

If the feature's correctness depends on WordPress semantics, use the WordPress test environment.

Mock only boundaries whose behavior is intentionally outside the scope of the test.

---

# Test Data Cleanup

Tests must clean up after themselves unless the framework handles isolation.

Avoid leaving behind:

* Users
* Posts
* Options
* Transients
* Scheduled events
* Files
* Custom table rows
* Temporary directories

Test pollution can hide order-dependent bugs.

---

# Static Analysis

Treat static analysis findings as potential defects rather than cosmetic warnings.

Pay attention to:

* Nullable values
* Incorrect return types
* Impossible branches
* Undefined properties
* Invalid array shapes
* Unsafe dynamic calls

Do not add broad ignore rules unless the analyzer is demonstrably wrong and the exception is narrowly scoped.

---

# PHP Warnings and Deprecations

New warnings or deprecations introduced by changed code are failures.

This includes compatibility issues on newer PHP versions.

When practical, test with the highest supported PHP version because it often exposes deprecated behavior earlier.

---

# Performance Regression Awareness

For performance-sensitive changes, compare the meaningful work before and after.

Look for:

* Additional DB queries
* Remote requests
* File I/O
* Large loops
* Repeated deserialization
* Autoloaded option growth
* Heavy logic executed on every request

Avoid premature optimization, but do not accept obvious regressions.

---

# Autoloaded Options

Be careful when storing large data in autoloaded WordPress options.

Do not place unbounded or large payloads into autoloaded options without a reason.

For large datasets, consider custom tables, non-autoloaded options, or another appropriate storage model.

---

# Privacy

Collect and persist only data necessary for the plugin's functionality.

When handling personal data:

* Avoid unnecessary duplication.
* Avoid logging it.
* Respect deletion/export behavior when applicable.
* Document retention behavior where appropriate.
* Do not expose it through REST/admin endpoints without authorization.

---

# Destructive Operations

Be extremely cautious with:

* DELETE
* DROP
* TRUNCATE
* Bulk option deletion
* File deletion
* User deletion
* Content deletion

Before implementing destructive behavior, verify:

* Correct authorization
* Correct target scope
* Correct identifiers
* Expected ownership
* Failure behavior
* Whether the operation needs confirmation
* Whether recovery is expected

Never run destructive test operations against production data.

---

# Production Safety

Assume production data matters.

Never suggest or execute a destructive database command without clearly establishing that the target environment is disposable or that the user explicitly intends the operation.

Prefer reversible changes.

For migrations, favor approaches that preserve existing data until the migration is confirmed successful.

---

# Do Not Modify WordPress Core

Never solve plugin behavior by modifying:

* WordPress core
* Third-party plugin source
* Theme source

unless the task explicitly concerns that codebase.

Plugin functionality must not depend on manual core patches.

---

# Do Not Patch Generated Dependencies

Do not edit:

* `vendor/`
* `node_modules/`
* WordPress core

to implement project functionality.

Change the project's source or dependency version instead.

---

# Documentation and Comments

Comments should explain why something exists, not restate what the code visibly does.

Document:

* Non-obvious constraints
* Compatibility workarounds
* Security assumptions
* Data migration decisions
* Third-party API behavior
* Intentional unusual behavior

Do not add comments that will immediately become stale.

---

# Public API Documentation

When adding or changing public hooks, functions, REST routes, configuration, shortcode attributes, or filters, update relevant documentation when the project contains such documentation.

Documentation should match actual verified behavior.

---

# Implementation Workflow

For non-trivial work, follow this process.

## Step 1: Inspect

Identify:

* Relevant code
* Existing tests
* Related hooks
* Data flow
* Security boundaries
* Existing conventions

## Step 2: Reproduce or Specify

For bugs:

* Reproduce the failure.

For features:

* Define observable acceptance criteria.

## Step 3: Add/Update Tests

Create a failing test when practical.

## Step 4: Implement

Make the smallest correct change.

## Step 5: Static Validation

Run applicable:

* Syntax checks
* PHPCS
* Static analysis
* JS lint

## Step 6: Automated Tests

Run the narrowest relevant tests first.

Then run the broader suite.

## Step 7: Runtime Verification

Exercise the feature in WordPress.

Use:

* WP-CLI
* HTTP requests
* Browser tests
* Admin UI
* REST API
* AJAX

as applicable.

## Step 8: Inspect Failure Signals

Check:

* WordPress debug log
* PHP output
* Browser console
* API errors
* DB errors

## Step 9: Regression Check

Run related and full tests as appropriate.

## Step 10: Report Evidence

Summarize:

* What changed
* Why
* Tests executed
* Runtime verification executed
* Any unverified areas

---

# Required Final Verification Report

After completing a coding task, report verification in a concise factual format.

Example:

```text
Implemented:
- Prevented duplicate scheduled events during plugin activation.
- Added cleanup during deactivation.

Verified:
- PHPUnit: passed (142 tests).
- PHPCS: passed.
- Plugin activation: passed in local WordPress environment.
- Repeated activation: no duplicate cron event created.
- Deactivation: scheduled event removed.
- WP_DEBUG log: no plugin-generated warnings/notices.

Not verified:
- Multisite network activation was not tested because the local environment is single-site.
```

Never fabricate command output, test counts, or runtime results.

If you did not execute it, do not say that you did.

---

# Verification Priority

When time or environment constraints prevent exhaustive testing, prioritize in this order:

1. Security-critical behavior
2. Data integrity
3. Reproduction of the reported bug
4. Core requested behavior
5. Regression tests
6. Activation/upgrades
7. API/browser integration
8. Static quality checks
9. Peripheral edge cases

Still report any gaps.

---

# Critical Paths Require Stronger Evidence

The following require especially strong verification:

* Authentication
* Authorization
* Payments
* Checkout
* User account changes
* Data deletion
* Database migrations
* File uploads
* Webhooks
* External API synchronization
* Background jobs
* REST endpoints that modify data
* Admin settings
* Email delivery logic
* License validation
* Import/export
* WooCommerce order changes

For critical paths, prefer multiple layers of verification:

* Automated test
* WordPress integration test
* Real runtime/API/browser smoke test

---

# Acceptance Criteria Must Be Observable

Translate vague requirements into behavior that can be tested.

Example:

Requirement:

"Prevent duplicate imports."

Observable criteria:

* Submitting the same external ID twice creates one local record.
* The second request returns the existing result or an intentional duplicate response.
* No duplicate rows exist in the database.
* Concurrent duplicate requests do not create two records when concurrency protection is required.

Implement against observable outcomes.

---

# Think Like an Adversarial User

Before considering a feature complete, ask:

* What happens if the request is sent directly?
* What happens if parameters are modified manually?
* What happens if the request is repeated?
* What happens without authentication?
* What happens with a lower-privilege account?
* What happens if the referenced object does not exist?
* What happens if an external service fails?
* What happens if WordPress invokes this hook twice?
* What happens with legacy data?
* What happens after an upgrade?
* What happens on PHP's newest supported version?

Use these questions to identify tests that provide meaningful additional confidence.

---

# Do Not Guess WordPress Behavior

When uncertain about a WordPress API or lifecycle behavior:

1. Inspect the installed WordPress source or official documentation if available.
2. Write a small reproduction test when useful.
3. Verify the behavior in WordPress.

Do not build critical logic around remembered assumptions.

---

# Verify Existing Behavior Before Refactoring

Before refactoring code with unclear behavior:

* Read existing tests.
* Search for consumers.
* Search for hooks and filters.
* Search for documentation.
* Determine external/public usage.
* Add characterization tests when practical.

Preserve behavior first.

Improve architecture second.

---

# No Unnecessary Success Claims

Use precise language.

Allowed:

* "The PHPUnit suite passes."
* "I verified the REST endpoint returns 403 for a subscriber."
* "The migration preserved the test fixture data."
* "Static analysis passes."

Avoid:

* "Everything works perfectly."
* "This is production-ready."

unless evidence actually supports such a broad statement.

---

# When Tests Fail

Do not automatically modify production code just because a test fails.

Determine whether:

1. Production behavior is wrong.
2. The test expectation is wrong.
3. The environment is broken.
4. The test is flaky.
5. The failure is unrelated to the current change.

Fix the correct layer.

---

# When Existing Tests Already Fail

If tests fail before your change:

1. Establish the baseline when possible.
2. Identify whether new failures were introduced.
3. Avoid claiming the repository is fully green.
4. Report baseline failures separately from new failures.

Example:

```text
Baseline:
- 3 existing PHPUnit failures.

After change:
- Same 3 existing failures.
- No additional PHPUnit failures.
- New regression test passes.
```

---

# Git Discipline

Before finalizing:

* Review the diff.
* Ensure unrelated files were not changed.
* Ensure temporary debug code is removed.
* Ensure secrets are not present.
* Ensure generated files are intentional.
* Ensure test fixtures are intentional.

A clean diff is part of correctness.

---

# Never Optimize for Passing CI Alone

CI passing is necessary but not always sufficient.

A test suite can have blind spots.

For behavior involving UI, WordPress lifecycle, HTTP routing, plugin packaging, migrations, or external integrations, perform runtime verification when practical.

The objective is correct software, not merely a green pipeline.

---

# Preferred Mindset

Operate as both:

* The implementer who writes the change.
* The reviewer trying to reject it.
* The tester trying to break it.
* The attacker trying to misuse it.
* The existing user trying to upgrade without losing data.

Do not stop at the first successful result.

Try the failure path.

Try the repeated path.

Try the unauthorized path.

Try the legacy-data path when relevant.

---

# Final Rule

Code is not complete because it was written.

Code is complete when the requested behavior has been implemented and there is credible evidence that it behaves correctly in the environments and conditions that matter.

When verification is possible, verify.

When verification is impossible, say so explicitly.

Never substitute confidence for evidence.
