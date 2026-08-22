<?php
/**
 * Prepare wp-env's activation state and multisite fixture.
 *
 * @package Pyro_Scope
 */

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress is not loaded.');
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin_file = 'pyro-scope/pyro-scope.php';
if (is_multisite()) {
    deactivate_plugins($plugin_file, true, true);
}
deactivate_plugins($plugin_file, true, false);

if (is_multisite()) {
    $subsite_ids = get_sites([
        'fields'       => 'ids',
        'number'       => 1,
        'site__not_in' => [get_main_site_id()],
    ]);
    if ([] === $subsite_ids) {
        $network = get_network();
        $domain = is_subdomain_install() ? 'pyro-scope-smoke.' . $network->domain : $network->domain;
        $path = is_subdomain_install() ? $network->path : trailingslashit($network->path) . 'pyro-scope-smoke/';
        $site_id = wpmu_create_blog($domain, $path, 'Pyro Scope Smoke', 1, [], (int) $network->id);
        if (is_wp_error($site_id)) {
            throw new RuntimeException($site_id->get_error_message());
        }
    }
}
