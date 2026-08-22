<?php
/**
 * Fail when WordPress recorded a PHP warning, notice, deprecation, or fatal.
 *
 * @package Pyro_Scope
 */

if (!defined('WP_CONTENT_DIR')) {
    throw new RuntimeException('WordPress is not loaded.');
}

$debug_log = WP_CONTENT_DIR . '/debug.log';
if (is_file($debug_log) && filesize($debug_log) > 0) {
    throw new RuntimeException("WordPress debug log is not empty:\n" . file_get_contents($debug_log));
}

echo 'WordPress debug log is clean.' . PHP_EOL;
