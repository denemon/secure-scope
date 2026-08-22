<?php
/**
 * Real-MySQL lock concurrency checks executed in parallel through WP-CLI.
 *
 * @package Pyro_Scope
 */

use Pyro_Scope\Scan_Session;
use Pyro_Scope\Settings;
use Pyro_Scope\Plugin;

if (!defined('ABSPATH')) {
    throw new RuntimeException('WordPress is not loaded.');
}

global $wpdb;

$mode = (string) ($args[0] ?? '');
$round = sanitize_key((string) ($args[1] ?? ''));
$value = (string) ($args[2] ?? '');
$prefix = 'pyro_scope_concurrency_' . $round . '_';
$barrier_key = $prefix . 'barrier';
$signal_key = $prefix . 'signal';
$result_key = $prefix . 'result_' . $value;
$owner_a = str_repeat('a', 32);
$owner_b = str_repeat('b', 32);
$session = new Scan_Session();

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read_option_directly = static function (string $key) use ($wpdb): mixed {
    return $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s",
            $key
        )
    );
};
$wait_for = static function (callable $condition, string $message): void {
    $deadline = microtime(true) + 20.0;
    while (!$condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(20000);
    }
};
$cleanup_round = static function () use ($barrier_key, $signal_key, $prefix): void {
    delete_option($barrier_key);
    delete_option($signal_key);
    for ($index = 1; $index <= 8; ++$index) {
        delete_option($prefix . 'result_' . $index);
    }
    delete_option($prefix . 'result_refresh');
};

if ('prepare' === $mode) {
    $cleanup_round();
    delete_option(Settings::LOCK_KEY);
    add_option($barrier_key, 0, '', 'no');
    if ('stale' === $round) {
        add_option(Settings::LOCK_KEY, ['owner' => 'expired', 'expires' => time() - 1], '', 'no');
    }
    echo 'Concurrency round prepared: ' . $round . PHP_EOL;
    return;
}

if ('contend' === $mode) {
    $index = (int) $value;
    $check($index >= 1 && $index <= 8, 'Invalid contender index.');
    $owner = str_pad((string) $index, 32, '0', STR_PAD_LEFT);
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
            $barrier_key
        )
    );
    $check(1 === $updated, 'Could not enter the concurrency barrier.');
    $wait_for(
        static fn (): bool => (int) $read_option_directly($barrier_key) >= 8,
        'The concurrency barrier timed out.'
    );
    $acquired = $session->acquire($owner);
    add_option($result_key, $acquired ? 'won' : 'lost', '', 'no');
    echo 'Contender ' . $index . ': ' . ($acquired ? 'won' : 'lost') . PHP_EOL;
    return;
}

if ('verify' === $mode) {
    $winners = [];
    for ($index = 1; $index <= 8; ++$index) {
        $result = get_option($prefix . 'result_' . $index, false);
        $check(in_array($result, ['won', 'lost'], true), 'A contender result is missing.');
        if ('won' === $result) {
            $winners[] = str_pad((string) $index, 32, '0', STR_PAD_LEFT);
        }
    }
    $check(1 === count($winners), 'Exactly one contender must acquire the lock.');
    $winner = $winners[0];
    $lock = get_option(Settings::LOCK_KEY, false);
    $check(is_array($lock) && $winner === ($lock['owner'] ?? null), 'The stored lock does not belong to the winner.');

    for ($index = 1; $index <= 8; ++$index) {
        $owner = str_pad((string) $index, 32, '0', STR_PAD_LEFT);
        if ($owner !== $winner) {
            $session->release($owner);
        }
    }
    $check($session->owns($winner), 'A losing contender released the winner lock.');
    $session->release($winner);
    $check(false === get_option(Settings::LOCK_KEY, false), 'The winner lock was not released.');
    $cleanup_round();
    echo 'Concurrency round passed: ' . $round . PHP_EOL;
    return;
}

if ('cas_prepare' === $mode) {
    $cleanup_round();
    delete_option(Settings::LOCK_KEY);
    add_option($signal_key, 'start', '', 'no');
    $check($session->acquire($owner_a), 'Could not prepare the original CAS owner.');
    echo 'CAS round prepared: ' . $round . PHP_EOL;
    return;
}

if ('cas_wait' === $mode) {
    $check(in_array($round, ['refresh', 'release'], true), 'Invalid CAS operation.');
    $check($session->owns($owner_a), 'The original CAS owner is missing.');
    update_option($signal_key, 'ready', false);
    $wait_for(
        static fn (): bool => 'go' === $read_option_directly($signal_key),
        'The CAS replacement timed out.'
    );
    if ('refresh' === $round) {
        update_option($prefix . 'result_refresh', $session->refresh($owner_a) ? 'refreshed' : 'blocked', false);
    } else {
        $session->release($owner_a);
    }
    echo 'CAS owner operation completed: ' . $round . PHP_EOL;
    return;
}

if ('cas_replace' === $mode) {
    $wait_for(
        static fn (): bool => 'ready' === $read_option_directly($signal_key),
        'The original CAS owner did not become ready.'
    );
    $replacement = ['owner' => $owner_b, 'expires' => time() + Scan_Session::TTL];
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = %s, autoload = 'no' WHERE option_name = %s",
            maybe_serialize($replacement),
            Settings::LOCK_KEY
        )
    );
    $check(1 === $updated, 'Could not install the replacement owner.');
    wp_cache_delete(Settings::LOCK_KEY, 'options');
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE `{$wpdb->options}` SET option_value = 'go' WHERE option_name = %s",
            $signal_key
        )
    );
    $check(1 === $updated, 'Could not release the CAS barrier.');
    echo 'CAS replacement installed: ' . $round . PHP_EOL;
    return;
}

if ('cas_verify' === $mode) {
    $lock = get_option(Settings::LOCK_KEY, false);
    $check(is_array($lock) && $owner_b === ($lock['owner'] ?? null), 'The stale owner changed or removed the replacement lock.');
    if ('refresh' === $round) {
        $check('blocked' === get_option($prefix . 'result_refresh', false), 'The stale owner refresh was not rejected.');
    }
    $session->release($owner_b);
    $cleanup_round();
    echo 'CAS round passed: ' . $round . PHP_EOL;
    return;
}

if ('controller' === $mode) {
    delete_option(Settings::LOCK_KEY);
    $manual_owner = str_repeat('c', 32);
    $check($session->acquire($manual_owner), 'Could not prepare the manual scan lock.');
    Plugin::get_instance()->scan_controller->run_scheduled_scan();
    $check($session->owns($manual_owner), 'The scheduled scan replaced the manual scan lock.');
    $session->release($manual_owner);
    $check(false === get_option(Settings::LOCK_KEY, false), 'The manual scan lock was not released.');
    echo 'Manual and scheduled scan exclusion passed.' . PHP_EOL;
    return;
}

throw new RuntimeException('Unknown concurrency mode.');
