<?php
/**
 * Clear output from a previous disposable runtime-smoke invocation.
 *
 * @package Pyro_Scope
 */

if (!defined('WP_CONTENT_DIR')) {
    throw new RuntimeException('WordPress is not loaded.');
}

$debug_log = WP_CONTENT_DIR . '/debug.log';
if (is_file($debug_log) && !unlink($debug_log)) {
    throw new RuntimeException('Could not clear the WordPress debug log.');
}
