<?php
/**
 * Minimal PHPUnit bootstrap for Pyro Scope.
 *
 * This test setup intentionally avoids WP_UnitTestCase and database access.
 * Instead, it provides a very small set of WordPress function stubs so we can
 * cover core plugin behavior with plain PHPUnit.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(sys_get_temp_dir(), '/\\') . '/pyro-scope-phpunit/wp/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', rtrim(ABSPATH, '/\\') . '/wp-content');
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = ''
        ) {
        }
    }
}

final class Pyro_Scope_Test_WPDB
{
    public string $posts = 'wp_posts';
    public string $options = 'wp_options';
    public string $comments = 'wp_comments';
    public string $last_error = '';
    /** @var list<mixed> */
    public array $prepared_args = [];

    public function prepare(string $query, mixed ...$args): string
    {
        $this->prepared_args = $args;
        return $query;
    }

    public function query(string $query): int|false
    {
        $before_query = $GLOBALS['pyro_scope_test_wp']['before_db_query'] ?? null;
        if (is_callable($before_query)) {
            $before_query($query);
        }

        // ロック取得と同じ原子性を再現する: 既存行があれば INSERT IGNORE は 0 行。
        if (str_starts_with($query, 'INSERT IGNORE INTO `wp_options`')) {
            [$key, $serialized_value] = $this->prepared_args;
            if (array_key_exists((string) $key, $GLOBALS['pyro_scope_test_wp']['options'])) {
                return 0;
            }
            $GLOBALS['pyro_scope_test_wp']['options'][(string) $key] = maybe_unserialize((string) $serialized_value);
            return 1;
        }

        if (str_starts_with($query, 'DELETE FROM `wp_options`')) {
            [$key, $serialized_old] = $this->prepared_args;
            $current = get_option((string) $key, false);
            if (maybe_serialize($current) !== $serialized_old) {
                return 0;
            }
            unset($GLOBALS['pyro_scope_test_wp']['options'][(string) $key]);
            return 1;
        }

        if (!str_starts_with($query, 'UPDATE `wp_options`')) {
            return 0;
        }
        [$serialized_new, $key, $serialized_old] = $this->prepared_args;
        $current = get_option((string) $key, false);
        if (maybe_serialize($current) !== $serialized_old) {
            return 0;
        }
        if ($serialized_new === $serialized_old) {
            return 0;
        }
        $GLOBALS['pyro_scope_test_wp']['options'][(string) $key] = unserialize((string) $serialized_new);
        return 1;
    }

    /** @return list<object> */
    public function get_results(string $query): array
    {
        return [];
    }

    public function get_var(string $query): ?string
    {
        return null;
    }
}

/**
 * Reset the fake WordPress state for each test.
 */
function pyro_scope_test_reset_wordpress_state(): void
{
    $root = rtrim(ABSPATH, '/\\');
    pyro_scope_test_remove_path($root);

    $GLOBALS['pyro_scope_test_wp'] = [
        'options'            => [],
        'actions'            => [],
        'filters'            => [],
        'styles'             => [],
        'scripts'            => [],
        'localized_scripts'  => [],
        'menu_pages'         => [],
        'scheduled'          => [],
        // プラグイン本体は一度しか require されないので、登録済みフックは保持する。
        'activation_hooks'   => $GLOBALS['pyro_scope_test_plugin_hooks']['activation_hooks'] ?? [],
        'deactivation_hooks' => $GLOBALS['pyro_scope_test_plugin_hooks']['deactivation_hooks'] ?? [],
        'site_transients'    => [],
        'transients'         => [],
        'plugins'            => [],
        'remote_get'         => null,
        'update_plugins'     => null,
        'update_option_failures' => [],
        'before_db_query'    => null,
        'current_user_can'   => true,
        'ajax_response'      => null,
    ];
    $GLOBALS['wpdb'] = new Pyro_Scope_Test_WPDB();
    $GLOBALS['wp_local_package'] = '';

    wp_mkdir_p(ABSPATH . 'wp-content/uploads');
    wp_mkdir_p(ABSPATH . 'wp-content/plugins');
    wp_mkdir_p(ABSPATH . 'wp-content/mu-plugins');
}

/**
 * Remove a file or directory tree.
 */
function pyro_scope_test_remove_path(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path)) {
        unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}

/**
 * Compare two WordPress callbacks.
 */
function pyro_scope_test_callbacks_match(mixed $left, mixed $right): bool
{
    if (is_string($left) && is_string($right)) {
        return $left === $right;
    }

    if (is_array($left) && is_array($right) && count($left) === 2 && count($right) === 2) {
        [$left_target, $left_method] = array_values($left);
        [$right_target, $right_method] = array_values($right);

        if ($left_method !== $right_method) {
            return false;
        }

        if (is_object($left_target) && is_object($right_target)) {
            return $left_target === $right_target;
        }

        return $left_target === $right_target;
    }

    return $left === $right;
}

function register_activation_hook(string $file, callable $callback): void
{
    $GLOBALS['pyro_scope_test_wp']['activation_hooks'][$file] = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void
{
    $GLOBALS['pyro_scope_test_wp']['deactivation_hooks'][$file] = $callback;
}

function wp_next_scheduled(string $hook, array $args = []): bool
{
    return isset($GLOBALS['pyro_scope_test_wp']['scheduled'][$hook]);
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['pyro_scope_test_wp']['scheduled'][$hook] = [
        'timestamp'  => $timestamp,
        'recurrence' => $recurrence,
    ];

    return true;
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    $GLOBALS['pyro_scope_test_wp']['scheduled'][$hook][] = compact('timestamp', 'args');
    return true;
}

function wp_unschedule_hook(string $hook): int
{
    $removed = count((array) ($GLOBALS['pyro_scope_test_wp']['scheduled'][$hook] ?? []));
    unset($GLOBALS['pyro_scope_test_wp']['scheduled'][$hook]);

    return $removed;
}

function is_admin(): bool
{
    return true;
}

function wp_doing_cron(): bool
{
    return false;
}

function add_action(string $hook, callable|array|string $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['pyro_scope_test_wp']['actions'][$hook][] = [
        'callback'      => $callback,
        'priority'      => $priority,
        'accepted_args' => $accepted_args,
    ];

    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['pyro_scope_test_wp']['filters'][$hook][] = $callback;

    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['pyro_scope_test_wp']['filters'][$hook] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function has_action(string $hook, callable|array|string $callback): int|false
{
    foreach ($GLOBALS['pyro_scope_test_wp']['actions'][$hook] ?? [] as $registered) {
        if (pyro_scope_test_callbacks_match($registered['callback'], $callback)) {
            return $registered['priority'];
        }
    }

    return false;
}

function add_option(string $key, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
{
    if (array_key_exists($key, $GLOBALS['pyro_scope_test_wp']['options'])) {
        return false;
    }
    $GLOBALS['pyro_scope_test_wp']['options'][$key] = $value;
    return true;
}

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['pyro_scope_test_wp']['options'][$key] ?? $default;
}

function update_option(string $key, mixed $value, mixed $autoload = null): bool
{
    if (in_array($key, $GLOBALS['pyro_scope_test_wp']['update_option_failures'], true)) {
        return false;
    }
    $GLOBALS['pyro_scope_test_wp']['options'][$key] = $value;
    return true;
}

function delete_option(string $key): bool
{
    unset($GLOBALS['pyro_scope_test_wp']['options'][$key]);
    return true;
}

function add_menu_page(
    string $page_title,
    string $menu_title,
    string $capability,
    string $menu_slug,
    callable $callback,
    string $icon_url = '',
    ?int $position = null
): string {
    $GLOBALS['pyro_scope_test_wp']['menu_pages'][$menu_slug] = [
        'page_title' => $page_title,
        'menu_title' => $menu_title,
        'capability' => $capability,
        'callback'   => $callback,
        'icon_url'   => $icon_url,
        'position'   => $position,
    ];

    return $menu_slug;
}

function plugin_dir_url(string $file): string
{
    return 'http://example.org/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function wp_enqueue_style(string $handle, string $src, array $deps = [], string|bool|null $ver = false): void
{
    $GLOBALS['pyro_scope_test_wp']['styles'][$handle] = compact('src', 'deps', 'ver');
}

function wp_style_is(string $handle, string $status = 'enqueued'): bool
{
    return isset($GLOBALS['pyro_scope_test_wp']['styles'][$handle]);
}

function wp_enqueue_script(
    string $handle,
    string $src,
    array $deps = [],
    string|bool|null $ver = false,
    bool $in_footer = false
): void {
    $GLOBALS['pyro_scope_test_wp']['scripts'][$handle] = compact('src', 'deps', 'ver', 'in_footer');
}

function wp_script_is(string $handle, string $status = 'enqueued'): bool
{
    return isset($GLOBALS['pyro_scope_test_wp']['scripts'][$handle]);
}

function wp_localize_script(string $handle, string $object_name, array $l10n): bool
{
    $GLOBALS['pyro_scope_test_wp']['localized_scripts'][$handle] = [
        'object_name' => $object_name,
        'data'        => $l10n,
    ];

    return true;
}

function admin_url(string $path = ''): string
{
    return 'http://example.org/wp-admin/' . ltrim($path, '/');
}

function wp_create_nonce(string $action): string
{
    return 'nonce-' . $action;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

function sanitize_textarea_field(string $text): string
{
    $text = strip_tags($text);
    $text = preg_replace("/\r\n|\r/", "\n", $text);
    return trim((string) $text);
}

function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }

    return is_string($value) ? stripslashes($value) : $value;
}

function check_admin_referer(string $action = '', string $query_arg = '_wpnonce'): bool
{
    return true;
}

function check_ajax_referer(string $action = '', string|bool $query_arg = false, bool $stop = true): bool
{
    return true;
}

function current_user_can(string $capability): bool
{
    return (bool) $GLOBALS['pyro_scope_test_wp']['current_user_can'];
}

function wp_send_json_success(array $value = [], ?int $status_code = null): never
{
    $GLOBALS['pyro_scope_test_wp']['ajax_response'] = [
        'success'     => true,
        'data'        => $value,
        'status_code' => $status_code,
    ];

    throw new RuntimeException('wp_send_json_success');
}

function wp_send_json_error(array $value = [], ?int $status_code = null): never
{
    $GLOBALS['pyro_scope_test_wp']['ajax_response'] = [
        'success'     => false,
        'data'        => $value,
        'status_code' => $status_code,
    ];

    throw new RuntimeException('wp_send_json_error');
}

function wp_upload_dir(?string $time = null, bool $create_dir = true): array
{
    $basedir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads';
    if ($create_dir) {
        wp_mkdir_p($basedir);
    }

    return [
        'basedir' => $basedir,
        'baseurl' => 'http://example.org/wp-content/uploads',
    ];
}

function wp_mkdir_p(string $target): bool
{
    if (is_dir($target)) {
        return true;
    }

    return mkdir($target, 0777, true);
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function add_query_arg(array $args, string $url): string
{
    return $url . '?' . http_build_query($args);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function checked(bool $checked, bool $current = true, bool $display = true): string
{
    if ($checked === $current) {
        $result = 'checked="checked"';
        if ($display) {
            echo $result;
        }
        return $result;
    }

    return '';
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce'): void
{
    echo '<input type="hidden" name="' . esc_html($name) . '" value="' . esc_html(wp_create_nonce($action)) . '">';
}

function wp_date(string $format, int $timestamp): string
{
    return date($format, $timestamp);
}

function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    $handler = $GLOBALS['pyro_scope_test_wp']['remote_get'];

    if (is_callable($handler)) {
        return $handler($url, $args);
    }

    return new WP_Error('missing_remote_stub', 'No remote HTTP stub configured.');
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array|WP_Error $response): int
{
    if ($response instanceof WP_Error) {
        return 0;
    }

    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array|WP_Error $response): string
{
    if ($response instanceof WP_Error) {
        return '';
    }

    return (string) ($response['body'] ?? '');
}

function get_plugins(): array
{
    return $GLOBALS['pyro_scope_test_wp']['plugins'];
}

function wp_update_plugins(): void
{
    $handler = $GLOBALS['pyro_scope_test_wp']['update_plugins'];
    if (is_callable($handler)) {
        $handler();
    }
}

function get_site_transient(string $key): mixed
{
    return $GLOBALS['pyro_scope_test_wp']['site_transients'][$key] ?? false;
}

function get_transient(string $key): mixed
{
    return $GLOBALS['pyro_scope_test_wp']['transients'][$key] ?? false;
}

function set_transient(string $key, mixed $value, int $expiration = 0): bool
{
    $GLOBALS['pyro_scope_test_wp']['transients'][$key] = $value;
    return true;
}

function delete_transient(string $key): bool
{
    unset($GLOBALS['pyro_scope_test_wp']['transients'][$key]);
    return true;
}

function maybe_serialize(mixed $value): string
{
    return is_array($value) || is_object($value) ? serialize($value) : (string) $value;
}

function maybe_unserialize(string $value): mixed
{
    $restored = @unserialize($value);

    return $restored === false && $value !== serialize(false) ? $value : $restored;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

pyro_scope_test_reset_wordpress_state();

require_once dirname(__DIR__) . '/pyro-scope.php';

$GLOBALS['pyro_scope_test_plugin_hooks'] = [
    'activation_hooks'   => $GLOBALS['pyro_scope_test_wp']['activation_hooks'],
    'deactivation_hooks' => $GLOBALS['pyro_scope_test_wp']['deactivation_hooks'],
];
