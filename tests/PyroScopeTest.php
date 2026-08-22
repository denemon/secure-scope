<?php
/**
 * Minimal PHPUnit coverage for Pyro Scope without database-backed WordPress tests.
 *
 * Scanner tests drive the same batched public APIs used by AJAX and cron.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Pyro_Scope\Db_Scanner;
use Pyro_Scope\File_Scanner;
use Pyro_Scope\Integrity_Scanner;
use Pyro_Scope\Plugin;
use Pyro_Scope\Results_Renderer;
use Pyro_Scope\Scan_Controller;
use Pyro_Scope\Scan_Session;
use Pyro_Scope\Settings;
use Pyro_Scope\Update_Scanner;

final class PyroScopeTest extends TestCase
{
    private Plugin $plugin;

    /** @var list<string> */
    private array $created_paths = [];

    protected function setUp(): void
    {
        parent::setUp();

        pyro_scope_test_reset_wordpress_state();
        $this->plugin = $this->boot();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created_paths) as $path) {
            pyro_scope_test_remove_path($path);
        }

        $this->created_paths = [];
        unset($GLOBALS['wpdb']);
        $_POST = [];
        parent::tearDown();
    }

    public function test_get_instance_initializes_defaults_and_hooks(): void
    {
        $expected = [
            'enable_scanner'   => 1,
            'enable_integrity' => 1,
            'enable_updates'   => 1,
            'whitelist_paths'  => [],
        ];

        // Singleton 初期化時にデフォルト設定と主要フックが登録される。
        $this->assertSame($expected, get_option(Settings::OPTIONS_KEY));
        $this->assertSame($expected, $this->plugin->settings->all());
        $this->assertSame(10, has_action('admin_menu', [$this->plugin->admin_page, 'register_menu']));
        $this->assertSame(10, has_action('admin_enqueue_scripts', [$this->plugin->admin_page, 'enqueue_assets']));
        $this->assertSame(10, has_action('wp_ajax_pyro_scope_run_scan', [$this->plugin->scan_controller, 'ajax_run_scan']));
        $this->assertSame(10, has_action('pyro_scope_weekly_scan_event', [$this->plugin->scan_controller, 'run_scheduled_scan']));
        $this->assertSame(10, has_action('pyro_scope_continue_scheduled_scan', [$this->plugin->scan_controller, 'continue_scheduled_scan']));
    }

    public function test_activation_schedules_the_first_weekly_scan_one_week_later_once(): void
    {
        $activate = $GLOBALS['pyro_scope_test_wp']['activation_hooks'][dirname(__DIR__) . '/pyro-scope.php'];
        $before = time();
        $activate();
        $after = time();

        $scheduled = $GLOBALS['pyro_scope_test_wp']['scheduled'][Scan_Controller::HOOK_WEEKLY];
        $this->assertGreaterThanOrEqual($before + WEEK_IN_SECONDS, $scheduled['timestamp']);
        $this->assertLessThanOrEqual($after + WEEK_IN_SECONDS, $scheduled['timestamp']);
        $this->assertSame('weekly', $scheduled['recurrence']);

        $activate();
        $this->assertSame($scheduled, $GLOBALS['pyro_scope_test_wp']['scheduled'][Scan_Controller::HOOK_WEEKLY]);
    }

    public function test_enqueue_scripts_only_enqueues_assets_on_plugin_screen(): void
    {
        // 対象外の管理画面では何も積まない。
        $this->plugin->admin_page->enqueue_assets('dashboard_page_dummy');
        $this->assertFalse(wp_style_is('pyro-scope-admin-style'));
        $this->assertFalse(wp_script_is('pyro-scope-admin-script'));

        // プラグイン画面では CSS / JS とローカライズデータを積む。
        $this->plugin->admin_page->enqueue_assets('toplevel_page_pyro-scope');

        $this->assertTrue(wp_style_is('pyro-scope-admin-style'));
        $this->assertTrue(wp_script_is('pyro-scope-admin-script'));
        $this->assertSame(
            'http://example.org/wp-content/plugins/' . basename(dirname(__DIR__)) . '/assets/css/pyro-scope-admin.css',
            $GLOBALS['pyro_scope_test_wp']['styles']['pyro-scope-admin-style']['src']
        );
        $this->assertSame(
            'PyroScopeAjax',
            $GLOBALS['pyro_scope_test_wp']['localized_scripts']['pyro-scope-admin-script']['object_name']
        );
        $this->assertSame(
            'http://example.org/wp-admin/admin-ajax.php',
            $GLOBALS['pyro_scope_test_wp']['localized_scripts']['pyro-scope-admin-script']['data']['ajax_url']
        );
    }

    public function test_generate_results_html_renders_scan_sections(): void
    {
        $results = [
            'integrity' => ['File Modified: wp-includes/load.php'],
            'integrity_errors' => [],
            'files'     => [ABSPATH . 'wp-content/mu-plugins/malicious.php' => 'Eval Base64 Decode'],
            'db'        => ['Option: suspicious_option' => 'Malicious Script'],
            'updates'   => ['Fixture Plugin (Installed: 1.0.0, Latest: 2.0.0)'],
            'update_errors' => [],
            'modules'   => ['scanner' => true, 'integrity' => true, 'updates' => true],
        ];

        // テンプレートの主要セクションが描画されることだけを最小確認する。
        $html = (new Results_Renderer())->render($results);

        $this->assertStringContainsString('コアファイルの整合性に関する問題', $html);
        $this->assertStringContainsString('wp-content/mu-plugins/malicious.php', $html);
        $this->assertStringContainsString('Option: suspicious_option', $html);
        $this->assertStringContainsString('Fixture Plugin (Installed: 1.0.0, Latest: 2.0.0)', $html);
    }

    public function test_generate_results_html_distinguishes_disabled_and_incomplete_states(): void
    {
        $html = (new Results_Renderer())->render([
            'integrity'      => [],
            'integrity_errors' => [],
            'files'          => [],
            'db'             => [],
            'updates'        => [],
            'update_errors'  => [],
            'modules'        => ['scanner' => false, 'integrity' => false, 'updates' => false],
            'scan_incomplete' => true,
        ]);

        $this->assertStringContainsString('スキャンは完了していません', $html);
        $this->assertStringContainsString('コアファイル整合性チェックは無効です', $html);
        $this->assertStringContainsString('ファイル・DBスキャンは無効です', $html);
        $this->assertStringContainsString('プラグイン更新チェックは無効です', $html);
        $this->assertStringNotContainsString('コアファイルの整合性は正常です', $html);
    }

    public function test_incomplete_results_keep_findings_and_an_escaped_saved_log_visible(): void
    {
        $html = (new Results_Renderer())->render([
            'log'              => ['Failure at <script>alert(1)</script>'],
            'integrity'        => ['File Modified: wp-admin/admin.php'],
            'integrity_errors' => ['Could not read core directory.'],
            'files'            => [],
            'db'               => [],
            'updates'          => [],
            'update_errors'    => [],
            'modules'          => ['scanner' => false, 'integrity' => true, 'updates' => false],
            'scan_incomplete'  => true,
        ]);

        $this->assertStringContainsString('Could not read core directory.', $html);
        $this->assertStringContainsString('File Modified: wp-admin/admin.php', $html);
        $this->assertStringContainsString('Failure at &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('Failure at <script>', $html);
    }

    public function test_malformed_stored_results_are_not_rendered(): void
    {
        update_option(Settings::RESULTS_KEY, [
            'scan_data' => [
                'modules' => ['updates' => true],
                'integrity' => 'not-an-array',
            ],
        ]);

        $this->assertFalse($this->plugin->runner->last_results());
    }

    public function test_execute_scan_marks_database_query_errors_incomplete(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $posts = 'wp_posts';
            public string $options = 'wp_options';
            public string $comments = 'wp_comments';
            public string $last_error = '';

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_results(string $query): array
            {
                $this->last_error = str_contains($query, '`wp_posts`') ? 'Fixture database failure.' : '';
                return [];
            }
        };
        $plugin = $this->boot([
            'enable_scanner'   => 1,
            'enable_integrity' => 0,
            'enable_updates'   => 0,
            'whitelist_paths'  => [],
        ]);

        $result = $this->run_scan($plugin);

        $this->assertTrue($result['scan_incomplete']);
        $this->assertContains('DB scan error: Failed to query published posts.', $result['log']);
        $this->assertSame([], $result['db']);
        $this->assertSame($result, get_option(Settings::RESULTS_KEY)['scan_data']);
        $this->assertSame($result, $plugin->runner->last_results());
        $this->assertStringContainsString(
            'スキャンは完了していません',
            $plugin->renderer->render($result)
        );
    }

    public function test_file_scan_flags_uploads_php_and_skips_whitelisted_paths(): void
    {
        $uploads_file = ABSPATH . 'wp-content/uploads/pyro-scope-tests/suspicious.php';
        $scan_target = ABSPATH . 'wp-content/mu-plugins/scan-target/malicious.php';
        $ignored_file = ABSPATH . 'wp-content/mu-plugins/whitelist/ignored.php';

        $this->create_file($uploads_file, "<?php\necho 'ok';\n");
        $this->create_file($scan_target, "<?php\neval(base64_decode('ZWNobyAiaGVsbG8iOw=='));\n");
        $this->create_file($ignored_file, "<?php\neval(base64_decode('ZWNobyAiaWdub3JlZCI7'));\n");

        // uploads 配下と非ホワイトリストの危険シグネチャのみ検出する。
        $result = $this->scan_batches($this->file_scanner(['wp-content/mu-plugins/whitelist']));

        $this->assertSame(
            'Executable PHP-like file in uploads directory',
            $result['findings'][wp_normalize_path($uploads_file)]
        );
        $this->assertSame(
            'Advanced Obfuscation',
            $result['findings'][wp_normalize_path($scan_target)]
        );
        $this->assertArrayNotHasKey(wp_normalize_path($ignored_file), $result['findings']);
    }

    public function test_file_scan_detects_common_backdoors_large_files_and_path_boundaries(): void
    {
        $trusted = ABSPATH . 'wp-content/plugins/trusted/ignored.php';
        $prefix_bypass = ABSPATH . 'wp-content/plugins/trusted-evil/backdoor.phtml';
        $large = ABSPATH . 'wp-content/mu-plugins/large-backdoor.php';

        $this->create_file($trusted, "<?php system(\$_GET['cmd']);");
        $this->create_file($prefix_bypass, "<?php system(\$_GET['cmd']);");
        $this->create_file($large, str_repeat(' ', 2097152) . "<?php eval(base64_decode('WA=='));\n");

        $result = $this->scan_batches($this->file_scanner(['wp-content/plugins/trusted']));

        $this->assertArrayNotHasKey(wp_normalize_path($trusted), $result['findings']);
        $this->assertSame('Command Execution From Input', $result['findings'][wp_normalize_path($prefix_bypass)]);
        $this->assertSame('Advanced Obfuscation', $result['findings'][wp_normalize_path($large)]);
        $this->assertSame([], $result['errors']);
    }

    public function test_single_large_file_resumes_from_its_byte_offset(): void
    {
        $large = ABSPATH . 'wp-content/mu-plugins/resumable-large.php';
        $this->create_file(
            $large,
            str_repeat(' ', 17 * 1024 * 1024) . "<?php eval(base64_decode('WA=='));"
        );

        $scanner = $this->file_scanner([]);
        $state = null;
        $findings = [];
        $saw_partial_file = false;
        do {
            $result = $scanner->scan_batch($state);
            $state = $result['state'];
            $findings = array_replace($findings, $result['findings']);
            $saw_partial_file = $saw_partial_file || is_array($state['file'] ?? null);
        } while (!$result['done']);

        $this->assertTrue($saw_partial_file);
        $this->assertSame('Advanced Obfuscation', $findings[wp_normalize_path($large)]);
    }

    public function test_file_scan_is_resumable_across_batches(): void
    {
        $directory = ABSPATH . 'wp-content/uploads/many-files';
        wp_mkdir_p($directory);
        $this->created_paths[] = $directory;
        for ($i = 0; $i < 1100; ++$i) {
            file_put_contents($directory . '/image-' . $i . '.jpg', 'x');
        }

        $scanner = $this->file_scanner([]);
        $state = null;
        $batches = 0;
        do {
            $result = $scanner->scan_batch($state);
            $state = $result['state'];
            ++$batches;
        } while (!$result['done']);

        $this->assertGreaterThan(1, $batches);
        $this->assertSame([], $result['errors']);
    }

    public function test_file_scan_marks_external_symlinks_incomplete(): void
    {
        $target = dirname(rtrim(ABSPATH, '/')) . '/outside-backdoor.php';
        $link = ABSPATH . 'linked-backdoor.php';
        $this->create_file($target, "<?php system(\$_GET['cmd']);");
        symlink($target, $link);
        $this->created_paths[] = $link;

        $result = $this->scan_batches($this->file_scanner([]));

        $this->assertContains(
            'Symlink target is unavailable or outside ABSPATH: ' . wp_normalize_path($link),
            $result['errors']
        );
    }

    public function test_db_scan_ignores_legitimate_embeds_and_uses_keyset_pagination(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $posts = 'wp_posts';
            public string $options = 'wp_options';
            public string $comments = 'wp_comments';
            public string $last_error = '';
            /** @var list<string> */
            public array $queries = [];

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_results(string $query): array
            {
                $this->queries[] = $query;
                if (str_contains($query, '`wp_posts`')) {
                    return [
                        (object) ['ID' => 1, 'post_content' => '<script>console.log("ok")</script>'],
                        (object) ['ID' => 2, 'post_content' => '<script src="javascript:alert(1)"></script>'],
                    ];
                }
                if (str_contains($query, '`wp_options`')) {
                    return [
                        (object) [
                            'option_id' => 3,
                            'option_name' => 'legitimate_embed',
                            'option_value' => '<iframe src="https://example.org/embed"></iframe>',
                        ],
                        (object) [
                            'option_id' => 4,
                            'option_name' => '_transient_cached_markup',
                            'option_value' => '<script src="javascript:alert(1)"></script>',
                        ],
                    ];
                }
                return [];
            }
        };

        $result = $this->scan_batches(new Db_Scanner());

        $this->assertSame(['Post ID: 2' => 'Dangerous Script Source'], $result['findings']);
        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('ORDER BY `ID` ASC', $wpdb->queries[0]);
        $this->assertStringNotContainsString('OFFSET', implode(' ', $wpdb->queries));
    }

    public function test_db_scan_reads_oversized_values_to_the_end(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $posts = 'wp_posts';
            public string $options = 'wp_options';
            public string $comments = 'wp_comments';
            public string $last_error = '';
            public int $chunks = 0;

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_results(string $query): array
            {
                if (str_contains($query, '`wp_options`')) {
                    return [(object) [
                        'option_id' => 9,
                        'option_name' => 'oversized_fixture',
                        'option_value' => str_repeat('x', 20001),
                    ]];
                }
                return [];
            }

            public function get_var(string $query): ?string
            {
                ++$this->chunks;
                // 悪性コードは切り詰め位置より後ろ（3チャンク目）にだけ存在する。
                return 'x' . ($this->chunks === 3 ? 'x eval(base64_decode("Zm9v"));' : 'still safe');
            }
        };

        // 切り詰めた残りをチャンクで読み切るので、検出できて「未完了」にもならない。
        $result = $this->scan_batches(new Db_Scanner());

        $this->assertSame(['Option: oversized_fixture' => 'Obfuscated Code'], $result['findings']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(3, $wpdb->chunks);
    }

    public function test_db_scan_resumes_inside_one_oversized_value(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $posts = 'wp_posts';
            public string $options = 'wp_options';
            public string $comments = 'wp_comments';
            public string $last_error = '';
            public int $chunks = 0;
            private int $option_queries = 0;

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_results(string $query): array
            {
                if (str_contains($query, '`wp_options`') && $this->option_queries++ === 0) {
                    return [(object) [
                        'option_id' => 10,
                        'option_name' => 'resumable_fixture',
                        'option_value' => str_repeat('x', 20001),
                    ]];
                }
                return [];
            }

            public function get_var(string $query): ?string
            {
                ++$this->chunks;
                return 'x' . ($this->chunks === 21 ? 'eval(base64_decode("WA=="));' : 'safe');
            }
        };

        $scanner = new Db_Scanner();
        $first = $scanner->scan_batch(null);
        $this->assertFalse($first['done']);
        $this->assertIsArray($first['state']['long']);
        $this->assertSame(20, $wpdb->chunks);

        $second = $scanner->scan_batch($first['state']);
        $this->assertTrue($second['done']);
        $this->assertSame(['Option: resumable_fixture' => 'Obfuscated Code'], $second['findings']);
        $this->assertSame(21, $wpdb->chunks);
    }

    public function test_db_scan_treats_the_end_of_a_long_value_as_success(): void
    {
        global $wpdb;
        $wpdb = new class {
            public string $posts = 'wp_posts';
            public string $options = 'wp_options';
            public string $comments = 'wp_comments';
            public string $last_error = '';
            private int $option_queries = 0;
            private int $chunks = 0;

            public function prepare(string $query, mixed ...$args): string
            {
                return $query;
            }

            public function get_results(string $query): array
            {
                if (str_contains($query, '`wp_options`') && $this->option_queries++ === 0) {
                    return [(object) [
                        'option_id' => 11,
                        'option_name' => 'long_safe_fixture',
                        'option_value' => str_repeat('x', 20001),
                    ]];
                }
                return [];
            }

            public function get_var(string $query): ?string
            {
                return ++$this->chunks === 1 ? 'xsafe remainder' : 'x';
            }
        };

        $result = $this->scan_batches(new Db_Scanner());

        $this->assertSame([], $result['findings']);
        $this->assertSame([], $result['errors']);
    }

    public function test_integrity_uses_site_locale_and_reports_added_executable_files(): void
    {
        global $wp_version, $wp_local_package;
        $wp_version = '6.7.2';
        $wp_local_package = 'ja';
        $requested_url = '';
        $GLOBALS['pyro_scope_test_wp']['remote_get'] = static function (string $url) use (&$requested_url): array {
            $requested_url = $url;
            return ['response' => ['code' => 200], 'body' => json_encode(['checksums' => []])];
        };
        $backdoor = ABSPATH . 'wp-admin/backdoor.phtml';
        $this->create_file($backdoor, "<?php system(\$_GET['cmd']);");

        $result = $this->scan_integrity(new Integrity_Scanner());

        $this->assertStringContainsString('locale=ja', $requested_url);
        $this->assertContains('File Added: wp-admin/backdoor.phtml', $result['findings']);
        $this->assertSame([], $result['errors']);
    }

    public function test_check_integrity_returns_error_when_remote_request_fails(): void
    {
        global $wp_version;

        $wp_version = '6.7';
        $GLOBALS['pyro_scope_test_wp']['remote_get'] = static fn (): WP_Error => new WP_Error(
            'http_error',
            'HTTP request failed.'
        );

        $result = $this->scan_integrity(new Integrity_Scanner());

        $this->assertSame([], $result['findings']);
        $this->assertSame(
            ['Could not retrieve official checksums from WordPress.org API.'],
            $result['errors']
        );
    }

    public function test_integrity_rejects_checksum_paths_outside_wordpress(): void
    {
        global $wp_version;
        $wp_version = '6.7';
        $GLOBALS['pyro_scope_test_wp']['remote_get'] = static fn (): array => [
            'response' => ['code' => 200],
            'body'     => json_encode(['checksums' => ['../secret' => md5('secret')]]),
        ];

        $result = $this->scan_integrity(new Integrity_Scanner());

        $this->assertSame([], $result['findings']);
        $this->assertSame(['Invalid checksum data received from API.'], $result['errors']);
    }

    public function test_scan_steps_are_split_and_lock_is_exclusive(): void
    {
        $scan_data = $this->plugin->runner->new_scan_data();
        $this->assertSame(
            ['integrity', 'files', 'db', 'updates'],
            $this->plugin->runner->enabled_steps($scan_data['modules'])
        );

        $session = $this->plugin->session;
        $this->assertTrue($session->acquire('first'));
        $first_expiry = get_option(Settings::LOCK_KEY)['expires'];
        $this->assertTrue($session->refresh('first'));
        $this->assertGreaterThan($first_expiry, get_option(Settings::LOCK_KEY)['expires']);
        $this->assertFalse($session->acquire('second'));
        $session->release('first');
        $this->assertTrue($session->acquire('second'));
        $session->release('second');

        update_option(Settings::LOCK_KEY, ['owner' => 'stale', 'expires' => time() - 1]);
        $this->assertTrue($session->acquire('third'));
        $session->release('third');
    }

    public function test_lock_refresh_and_release_cannot_overwrite_a_replacement_owner(): void
    {
        $session = new Scan_Session();
        $this->assertTrue($session->acquire('first'));
        $GLOBALS['pyro_scope_test_wp']['before_db_query'] = static function (string $query): void {
            if (str_starts_with($query, 'UPDATE `wp_options`')) {
                update_option(Settings::LOCK_KEY, ['owner' => 'second', 'expires' => time() + 900]);
            }
        };

        $this->assertFalse($session->refresh('first'));
        $this->assertSame('second', get_option(Settings::LOCK_KEY)['owner']);

        update_option(Settings::LOCK_KEY, ['owner' => 'first', 'expires' => time() + 900]);
        $GLOBALS['pyro_scope_test_wp']['before_db_query'] = static function (string $query): void {
            if (str_starts_with($query, 'DELETE FROM `wp_options`')) {
                update_option(Settings::LOCK_KEY, ['owner' => 'second', 'expires' => time() + 900]);
            }
        };

        $session->release('first');
        $this->assertSame('second', get_option(Settings::LOCK_KEY)['owner']);
    }

    public function test_scheduled_scan_continues_in_batches_without_duplicate_event_failure(): void
    {
        $directory = ABSPATH . 'wp-content/uploads/scheduled-batch';
        wp_mkdir_p($directory);
        $this->created_paths[] = $directory;
        for ($i = 0; $i < 1100; ++$i) {
            file_put_contents($directory . '/asset-' . $i . '.jpg', 'x');
        }
        $plugin = $this->boot([
            'enable_scanner' => 1,
            'enable_integrity' => 0,
            'enable_updates' => 0,
            'whitelist_paths' => [],
        ]);

        // 予算 0 でバッチごとに cron イベントへ引き継ぐ経路を通す。
        add_filter('pyro_scope_cron_seconds', static fn (): float => 0.0);

        $plugin->scan_controller->run_scheduled_scan();
        $lock = get_option(Settings::LOCK_KEY);
        $this->assertIsArray($lock, 'バッチが残っているのでロックは保持されたままになる。');
        $owner = $lock['owner'];
        $rounds = 0;
        for ($i = 0; $i < 8 && get_option(Settings::LOCK_KEY, false) !== false; ++$i) {
            ++$rounds;
            $plugin->scan_controller->continue_scheduled_scan($owner);
        }

        $saved = get_option(Settings::RESULTS_KEY)['scan_data'];
        $this->assertGreaterThan(1, $rounds);
        $this->assertFalse(get_option(Settings::LOCK_KEY, false));
        $this->assertNotContains('Could not schedule the next scan batch.', $saved['log']);
        $this->assertContains('0 suspicious DB entries found.', $saved['log']);
    }

    public function test_scheduled_scan_completes_within_one_cron_run_when_budget_allows(): void
    {
        $plugin = $this->boot([
            'enable_scanner'   => 1,
            'enable_integrity' => 0,
            'enable_updates'   => 0,
            'whitelist_paths'  => [],
        ]);

        // 既定予算では 1 回の cron 起動で走り切り、ロックも解放される。
        $plugin->scan_controller->run_scheduled_scan();

        $this->assertFalse(get_option(Settings::LOCK_KEY, false));
        $this->assertContains(
            '0 suspicious DB entries found.',
            get_option(Settings::RESULTS_KEY)['scan_data']['log']
        );
    }

    public function test_deactivation_clears_continuation_events_registered_with_arguments(): void
    {
        wp_schedule_single_event(time() + 1, Pyro_Scope\Scan_Controller::HOOK_CONTINUE, ['cron-abc']);
        update_option(Settings::LOCK_KEY, ['owner' => 'cron-abc', 'expires' => time() + 900]);
        set_transient(Settings::SESSION_PREFIX . 'cron-abc', ['active' => true], 900);

        $deactivate = $GLOBALS['pyro_scope_test_wp']['deactivation_hooks'][dirname(__DIR__) . '/pyro-scope.php'];
        $deactivate();

        // wp_clear_scheduled_hook() は引数付きイベントを消せないため wp_unschedule_hook() を使う。
        $this->assertFalse(wp_next_scheduled(Pyro_Scope\Scan_Controller::HOOK_CONTINUE));
        $this->assertFalse(get_option(Settings::LOCK_KEY, false));
        $this->assertFalse(get_transient(Settings::SESSION_PREFIX . 'cron-abc'));
    }

    public function test_uploads_guard_stub_is_not_reported_but_output_still_is(): void
    {
        $directory = 'wp-content/uploads/pyro-scope-stub/';
        $inert = [
            'index.php'    => "<?php\n// Silence is golden.\n",
            'closed.php'   => '<?php // Silence is golden. ?>',
            'block.php'    => "<?php\n/* nothing to see */\n",
            'empty.php'    => '',
        ];
        $suspicious = [
            'payload.php'  => "<?php\necho 'anything';\n",
            /* 1行コメントは閉じタグで終わるため、これは script を出力する。 */
            'sneaky.php'   => '<?php // x ?><script>alert(1)</script>',
            'shorttag.php' => '<?= $x ?>',
        ];
        foreach ($inert + $suspicious as $name => $contents) {
            $this->create_file(ABSPATH . $directory . $name, $contents);
        }

        $findings = $this->scan_batches($this->file_scanner([]))['findings'];

        foreach (array_keys($inert) as $name) {
            $this->assertArrayNotHasKey(wp_normalize_path(ABSPATH . $directory . $name), $findings, $name);
        }
        foreach (array_keys($suspicious) as $name) {
            $this->assertSame(
                'Executable PHP-like file in uploads directory',
                $findings[wp_normalize_path(ABSPATH . $directory . $name)] ?? null,
                $name
            );
        }
    }

    public function test_file_scan_keeps_traversing_after_the_state_round_trips(): void
    {
        // 走査済みディレクトリを落としたあと、state を serialize 往復させても
        // 残りのディレクトリを取りこぼさないこと（配列キーの巻き戻り対策）。
        $root = ABSPATH . 'wp-content/uploads/round-trip';
        $this->create_file($root . '/first/a.php', "<?php\n// clean\n");
        $this->create_file($root . '/first/nested/deep.php', "<?php eval(base64_decode('WA=='));");
        $this->create_file($root . '/second/b.php', "<?php eval(base64_decode('WQ=='));");

        $scanner = $this->file_scanner([]);
        $state = null;
        $findings = [];
        $batches = 0;
        do {
            $result = $scanner->scan_batch($state);
            // 実運用では transient 経由で serialize されるため、そこを再現する。
            $state = unserialize(serialize($result['state']));
            $findings += $result['findings'];
            $this->assertLessThan(200, ++$batches, 'スキャンが収束しない。');
        } while (!$result['done']);

        $this->assertArrayHasKey(wp_normalize_path($root . '/first/nested/deep.php'), $findings);
        $this->assertArrayHasKey(wp_normalize_path($root . '/second/b.php'), $findings);
    }

    public function test_integrity_check_resumes_across_batches(): void
    {
        global $wp_version;
        $wp_version = '6.7.2';
        $checksums = [];
        $directory = ABSPATH . 'wp-includes';
        wp_mkdir_p($directory);
        $this->created_paths[] = $directory;
        for ($i = 0; $i < 1200; ++$i) {
            $path = $directory . '/fixture-' . $i . '.php';
            file_put_contents($path, 'clean');
            $this->created_paths[] = $path;
            $checksums['wp-includes/fixture-' . $i . '.php'] = md5('clean');
        }
        file_put_contents($directory . '/fixture-1100.php', 'modified');
        $GLOBALS['pyro_scope_test_wp']['remote_get'] = static fn (): array => [
            'response' => ['code' => 200],
            'body'     => json_encode(['checksums' => $checksums]),
        ];

        $scanner = new Integrity_Scanner();
        $first = $scanner->scan_batch(null);

        $this->assertFalse($first['done'], '1200ファイルは1バッチに収まらない。');
        $this->assertSame(1000, $first['state']['index']);
        $this->assertSame([], $first['findings']);

        $second = $scanner->scan_batch($first['state']);

        $this->assertFalse($second['done'], '追加ファイル走査も同じバッチ予算で分割される。');
        $this->assertSame(1200, $second['state']['index']);
        $this->assertContains('File Modified: wp-includes/fixture-1100.php', $second['findings']);

        $third = $scanner->scan_batch($second['state']);
        $this->assertTrue($third['done']);
        $this->assertSame([], $third['errors']);
    }

    public function test_ajax_scan_session_finishes_and_releases_lock(): void
    {
        $plugin = $this->boot([
            'enable_scanner'   => 0,
            'enable_integrity' => 0,
            'enable_updates'   => 0,
            'whitelist_paths'  => [],
        ]);

        $started = $this->call_ajax($plugin, ['step' => 'start']);
        $scan_id = $started['data']['scan_id'];
        $this->assertTrue($started['success']);
        $this->assertSame([], $started['data']['steps']);

        $blocked = $this->call_ajax($plugin, ['step' => 'start']);
        $this->assertFalse($blocked['success']);
        $this->assertSame(409, $blocked['status_code']);

        $finished = $this->call_ajax($plugin, ['step' => 'finish', 'scan_id' => $scan_id]);
        $this->assertTrue($finished['success']);
        $this->assertStringContainsString('ファイル・DBスキャンは無効です', $finished['data']['html']);
        $this->assertFalse(get_option(Settings::LOCK_KEY, false));
        $this->assertFalse(get_transient(Settings::SESSION_PREFIX . $scan_id));
    }

    public function test_update_check_uses_wordpress_update_transient(): void
    {
        $GLOBALS['pyro_scope_test_wp']['plugins'] = [
            'fixture-plugin/fixture.php' => [
                'Name'    => 'Fixture Plugin',
                'Version' => '1.0.0',
            ],
            'local-plugin/local.php' => [
                'Name'    => 'Local Plugin',
                'Version' => '1.0.0',
            ],
        ];
        $GLOBALS['pyro_scope_test_wp']['site_transients']['update_plugins'] = (object) [
            'last_checked' => time(),
            'response' => [
                'fixture-plugin/fixture.php' => (object) ['new_version' => '9.9.9'],
            ],
            'no_update' => [],
        ];

        $result = (new Update_Scanner())->scan();

        $this->assertSame(
            ['Fixture Plugin (Installed: 1.0.0, Latest: 9.9.9)'],
            $result['findings']
        );
        $this->assertSame([], $result['errors']);
    }

    public function test_update_check_marks_missing_information_as_error(): void
    {
        $GLOBALS['pyro_scope_test_wp']['plugins'] = [
            'fixture-plugin/fixture.php' => [
                'Name'    => 'Fixture Plugin',
                'Version' => '1.0.0',
            ],
        ];

        $result = (new Update_Scanner())->scan();

        $this->assertSame([], $result['findings']);
        $this->assertSame(['WordPress update information is unavailable.'], $result['errors']);
    }

    public function test_update_check_rejects_incomplete_native_metadata(): void
    {
        $GLOBALS['pyro_scope_test_wp']['plugins'] = [
            'fixture-plugin/fixture.php' => ['Name' => 'Fixture', 'Version' => '1.0.0'],
        ];
        $GLOBALS['pyro_scope_test_wp']['site_transients']['update_plugins'] = (object) [
            'last_checked' => time(),
            'response' => [],
        ];

        $result = (new Update_Scanner())->scan();

        $this->assertSame([], $result['findings']);
        $this->assertSame(['WordPress update information is incomplete.'], $result['errors']);
    }

    public function test_update_failure_is_incomplete_and_never_rendered_as_clean(): void
    {
        $plugin = $this->boot([
            'enable_scanner' => 0,
            'enable_integrity' => 0,
            'enable_updates' => 1,
            'whitelist_paths' => [],
        ]);

        $result = $this->run_scan($plugin);
        $html = $plugin->renderer->render($result);

        $this->assertTrue($result['scan_incomplete']);
        $this->assertSame(['WordPress update information is unavailable.'], $result['update_errors']);
        $this->assertStringContainsString('プラグイン更新情報を確認できませんでした', $html);
        $this->assertStringNotContainsString('更新対象のプラグインは検出されませんでした', $html);
    }

    public function test_settings_form_persists_sanitized_whitelist(): void
    {
        $_POST = [
            'pyro_scope_save_settings' => '1',
            'enable_scanner'           => '1',
            'whitelist_paths'          => "wp-content/plugins/a\n/abs\n../up\nC:/win\n\nwp-content/plugins/a\n",
        ];

        ob_start();
        $this->plugin->admin_page->render();
        $html = ob_get_clean();

        $this->assertSame(
            [
                'enable_scanner'   => 1,
                'enable_integrity' => 0,
                'enable_updates'   => 0,
                'whitelist_paths'  => ['wp-content/plugins/a'],
            ],
            get_option(Settings::OPTIONS_KEY)
        );
        $this->assertSame(get_option(Settings::OPTIONS_KEY), $this->plugin->settings->all());
        $this->assertStringContainsString('設定を保存しました。', $html);
        $this->assertStringContainsString(
            '<textarea name="whitelist_paths" rows="5" cols="60" class="large-text">wp-content/plugins/a</textarea>',
            $html
        );
    }

    public function test_settings_form_reports_storage_failure(): void
    {
        $before = get_option(Settings::OPTIONS_KEY);
        $GLOBALS['pyro_scope_test_wp']['update_option_failures'][] = Settings::OPTIONS_KEY;
        $_POST = [
            'pyro_scope_save_settings' => '1',
            'enable_scanner'           => '1',
            'enable_updates'           => '1',
            'whitelist_paths'          => 'wp-content/plugins/new',
        ];

        ob_start();
        $this->plugin->admin_page->render();
        $html = ob_get_clean();

        $this->assertSame($before, get_option(Settings::OPTIONS_KEY));
        $this->assertSame($before, $this->plugin->settings->all());
        $this->assertStringContainsString('設定を保存できませんでした。', $html);
    }

    /**
     * Boot a fresh plugin instance, optionally with pre-seeded options.
     *
     * @param array<string, mixed>|null $options
     */
    private function boot(?array $options = null): Plugin
    {
        if ($options !== null) {
            update_option(Settings::OPTIONS_KEY, $options);
        }

        (new ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, null);

        return Plugin::get_instance();
    }

    /** @param list<string> $whitelist */
    private function file_scanner(array $whitelist): File_Scanner
    {
        update_option(Settings::OPTIONS_KEY, [
            'enable_scanner'   => 1,
            'enable_integrity' => 1,
            'enable_updates'   => 1,
            'whitelist_paths'  => $whitelist,
        ]);

        return new File_Scanner(new Settings());
    }

    /** @return array{findings: array<string, string>, errors: list<string>} */
    private function scan_batches(File_Scanner|Db_Scanner $scanner): array
    {
        $state = null;
        $findings = [];
        $errors = [];
        do {
            $batch = $scanner->scan_batch($state);
            $state = $batch['state'];
            $findings = array_replace($findings, $batch['findings']);
            $errors = array_merge($errors, $batch['errors']);
        } while (!$batch['done']);

        return ['findings' => $findings, 'errors' => array_values(array_unique($errors))];
    }

    /** @return array{findings: list<string>, errors: list<string>} */
    private function scan_integrity(Integrity_Scanner $scanner): array
    {
        $state = null;
        $findings = [];
        $errors = [];
        do {
            $batch = $scanner->scan_batch($state);
            $state = $batch['state'];
            $findings = array_merge($findings, $batch['findings']);
            $errors = array_merge($errors, $batch['errors']);
        } while (!$batch['done']);

        return ['findings' => $findings, 'errors' => array_values(array_unique($errors))];
    }

    /** @return array<string, mixed> */
    private function run_scan(Plugin $plugin): array
    {
        $scan_data = $plugin->runner->new_scan_data();
        foreach ($plugin->runner->enabled_steps($scan_data['modules']) as $step) {
            while (!$plugin->runner->run_step($scan_data, $step)) {
            }
        }
        unset($scan_data['progress']);
        $plugin->runner->save_results($scan_data);

        return $scan_data;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function call_ajax(Plugin $plugin, array $post): array
    {
        $_POST = $post;
        try {
            $plugin->scan_controller->ajax_run_scan();
            $this->fail('Expected the JSON response to terminate execution.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_send_json_', $e->getMessage());
        }

        return $GLOBALS['pyro_scope_test_wp']['ajax_response'];
    }

    private function create_file(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
            $this->created_paths[] = $directory;
        }

        file_put_contents($path, $contents);
        $this->created_paths[] = $path;
    }
}
