<?php
/**
 * Verify that uninstall removed every persisted plugin value.
 *
 * @package Pyro_Scope
 */

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress is not loaded.');
}

if ('run' === ($args[0] ?? null)) {
    define('WP_UNINSTALL_PLUGIN', true);
    require WP_PLUGIN_DIR . '/pyro-scope/uninstall.php';
}

$site_ids = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
foreach ($site_ids as $site_id) {
    $switched = (int) $site_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog((int) $site_id);
    }
    try {
        $option_keys = [
            'pyro_scope_options',
            'pyro_scope_last_scan_results',
            'pyro_scope_last_scan_timestamp',
            'pyro_scope_scan_lock',
        ];
        foreach ($option_keys as $key) {
            if (false !== get_option($key, false)) {
                throw new RuntimeException("The {$key} option survived uninstall.");
            }
        }
        if (false !== get_transient('pyro_scope_core_checksums')) {
            throw new RuntimeException('The checksum transient survived uninstall.');
        }
        if (false !== wp_next_scheduled('pyro_scope_weekly_scan_event')) {
            throw new RuntimeException('The weekly event survived uninstall.');
        }
        if (false !== wp_next_scheduled('pyro_scope_continue_scheduled_scan')) {
            throw new RuntimeException('A continuation event survived uninstall.');
        }
    } finally {
        if ($switched) {
            restore_current_blog();
        }
    }
}

echo 'Uninstall smoke passed.' . PHP_EOL;
