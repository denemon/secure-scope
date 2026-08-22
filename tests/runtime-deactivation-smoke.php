<?php
/**
 * Deactivation checks that do not require plugin classes to remain loaded.
 *
 * @package Pyro_Scope
 */

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress is not loaded.');
}

$site_ids = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
foreach ($site_ids as $site_id) {
    $switched = (int) $site_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog((int) $site_id);
    }
    try {
        $owner = 'cron-' . str_pad((string) get_current_blog_id(), 24, '0', STR_PAD_LEFT);
        if (false !== wp_next_scheduled('pyro_scope_weekly_scan_event')) {
            throw new RuntimeException('The weekly event survived deactivation.');
        }
        if (false !== wp_get_scheduled_event('pyro_scope_continue_scheduled_scan', [$owner])) {
            throw new RuntimeException('The owner continuation event survived deactivation.');
        }
        if (false !== get_option('pyro_scope_scan_lock', false)) {
            throw new RuntimeException('The scan lock survived deactivation.');
        }
        if (false !== get_transient('pyro_scope_scan_' . $owner)) {
            throw new RuntimeException('The active scan session survived deactivation.');
        }
        if (false === get_option('pyro_scope_options', false)) {
            throw new RuntimeException('Settings were deleted during deactivation.');
        }
        if (false === get_option('pyro_scope_last_scan_results', false)) {
            throw new RuntimeException('Completed results were deleted during deactivation.');
        }
        if (false === get_option('pyro_scope_last_scan_timestamp', false)) {
            throw new RuntimeException('The completed timestamp was deleted during deactivation.');
        }
        if (false === get_transient('pyro_scope_core_checksums')) {
            throw new RuntimeException('The checksum cache was deleted during deactivation.');
        }
    } finally {
        if ($switched) {
            restore_current_blog();
        }
    }
}

echo 'Deactivation smoke passed.' . PHP_EOL;
