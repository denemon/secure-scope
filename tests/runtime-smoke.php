<?php
/**
 * Real WordPress runtime smoke checks executed through WP-CLI.
 *
 * @package Pyro_Scope
 */

use Pyro_Scope\Admin_Page;
use Pyro_Scope\Plugin;
use Pyro_Scope\Scan_Controller;
use Pyro_Scope\Scan_Session;
use Pyro_Scope\Settings;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress is not loaded.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$plugin_file = 'pyro-scope/pyro-scope.php';
$check(
    is_multisite() ? is_plugin_active_for_network($plugin_file) : is_plugin_active($plugin_file),
    'Pyro Scope is not active in the expected scope.'
);
$check(Plugin::VERSION === get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false)['Version'], 'Version mismatch.');
$weekly_scan = wp_next_scheduled(Scan_Controller::HOOK_WEEKLY);
$check(false !== $weekly_scan, 'Weekly scan is not scheduled.');
$weekly_delay = $weekly_scan - time();
$check(
    $weekly_delay >= WEEK_IN_SECONDS - HOUR_IN_SECONDS && $weekly_delay <= WEEK_IN_SECONDS,
    'The first weekly scan was not scheduled approximately one week after activation.'
);

$plugin = Plugin::get_instance();
$check(10 === has_action('wp_ajax_' . Scan_Controller::AJAX_ACTION, [$plugin->scan_controller, 'ajax_run_scan']), 'AJAX hook is missing.');
$check(10 === has_action(Scan_Controller::HOOK_WEEKLY, [$plugin->scan_controller, 'run_scheduled_scan']), 'Cron hook is missing.');
$check(['integrity', 'files', 'db', 'updates'] === $plugin->runner->enabled_steps($plugin->settings->modules()), 'Default scan steps changed.');

global $wpdb;
$autoload = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT autoload FROM `{$wpdb->options}` WHERE option_name = %s",
        Settings::OPTIONS_KEY
    )
);
$check(is_string($autoload) && !in_array($autoload, ['yes', 'on', 'auto-on'], true), 'Settings option is autoloaded.');

wp_dequeue_script('pyro-scope-admin-script');
wp_dequeue_style('pyro-scope-admin-style');
$plugin->admin_page->enqueue_assets('dashboard_page_unrelated');
$check(!wp_script_is('pyro-scope-admin-script', 'enqueued'), 'Admin script leaked to another screen.');
$check(!wp_style_is('pyro-scope-admin-style', 'enqueued'), 'Admin style leaked to another screen.');
$plugin->admin_page->enqueue_assets(Admin_Page::HOOK_SUFFIX);
$check(wp_script_is('pyro-scope-admin-script', 'enqueued'), 'Admin script was not enqueued.');
$check(wp_style_is('pyro-scope-admin-style', 'enqueued'), 'Admin style was not enqueued.');

$owner = Scan_Session::new_id();
$other = Scan_Session::new_id();
$check($plugin->session->acquire($owner), 'Could not acquire the real database lock.');

try {
    $check(!$plugin->session->acquire($other), 'A second owner acquired the live lock.');
    $check($plugin->session->owns($owner), 'The lock owner is not recognized.');
    $check($plugin->session->refresh($owner), 'The lock could not be refreshed.');

    $scan_data = $plugin->runner->new_scan_data();
    $stored = $plugin->session->store(
        $owner,
        [
            'data'  => $scan_data,
            'steps' => $plugin->runner->enabled_steps($scan_data['modules']),
        ]
    );
    $check($stored, 'The real transient session could not be stored.');
    $check(null !== $plugin->session->load($owner), 'The real transient session could not be loaded.');
} finally {
    $plugin->session->forget($owner);
    $plugin->session->release($owner);
}

$check(false === get_option(Settings::LOCK_KEY, false), 'The runtime smoke check left a lock behind.');

if (is_multisite()) {
    $subsite_ids = get_sites([
        'fields'       => 'ids',
        'number'       => 1,
        'site__not_in' => [get_main_site_id()],
    ]);
    $check([] !== $subsite_ids, 'The multisite fixture is missing.');
    switch_to_blog((int) $subsite_ids[0]);
    try {
        $check(false !== wp_next_scheduled(Scan_Controller::HOOK_WEEKLY), 'The subsite weekly scan is not scheduled.');
        new Settings();
        $subsite_autoload = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM `{$wpdb->options}` WHERE option_name = %s",
                Settings::OPTIONS_KEY
            )
        );
        $check(is_string($subsite_autoload) && !in_array($subsite_autoload, ['yes', 'on', 'auto-on'], true), 'Subsite settings are autoloaded.');

        $subsite_session = new Scan_Session();
        $subsite_owner = Scan_Session::new_id();
        $check($subsite_session->acquire($subsite_owner), 'Could not acquire the subsite lock.');
        $check($subsite_session->store($subsite_owner, ['data' => $plugin->runner->new_scan_data(), 'steps' => []]), 'Could not store the subsite session.');
        $check(null !== $subsite_session->load($subsite_owner), 'Could not load the subsite session.');
        $subsite_session->forget($subsite_owner);
        $subsite_session->release($subsite_owner);
        $check(false === get_option(Settings::LOCK_KEY, false), 'The subsite smoke check left a lock behind.');
    } finally {
        restore_current_blog();
    }
}

$site_ids = is_multisite() ? get_sites(['fields' => 'ids', 'number' => 0]) : [get_current_blog_id()];
foreach ($site_ids as $site_id) {
    $switched = (int) $site_id !== get_current_blog_id();
    if ($switched) {
        switch_to_blog((int) $site_id);
    }
    try {
        $deactivation_owner = 'cron-' . str_pad((string) get_current_blog_id(), 24, '0', STR_PAD_LEFT);
        $check($plugin->session->acquire($deactivation_owner), 'Could not prepare the deactivation lock.');
        $check(
            $plugin->session->store($deactivation_owner, ['data' => $plugin->runner->new_scan_data(), 'steps' => []]),
            'Could not prepare the deactivation session.'
        );
        $scheduled = wp_schedule_single_event(
            time() + HOUR_IN_SECONDS,
            Scan_Controller::HOOK_CONTINUE,
            [$deactivation_owner],
            true
        );
        $check(true === $scheduled, 'Could not prepare the continuation event.');
        update_option(Settings::RESULTS_KEY, ['probe' => true], false);
        update_option(Settings::TIMESTAMP_KEY, time(), false);
        set_transient(Settings::CHECKSUM_KEY, ['probe' => true], HOUR_IN_SECONDS);
    } finally {
        if ($switched) {
            restore_current_blog();
        }
    }
}

echo 'Runtime smoke passed: WordPress ' . get_bloginfo('version') . ', PHP ' . PHP_VERSION . PHP_EOL;
