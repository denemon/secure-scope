#!/usr/bin/env bash
set -euo pipefail

plugin_root=$(cd "$(dirname "$0")/.." && pwd)
wp_env="$plugin_root/node_modules/.bin/wp-env"
cache_root=$(mktemp -d "${TMPDIR:-/tmp}/pyro-scope-object-cache.XXXXXX")
cache_config="$cache_root/.wp-env.json"
redis_container="pyro-scope-object-cache-$$"
environment_started=0

cleanup() {
    if [[ 1 -eq "$environment_started" ]]; then
        "$wp_env" --config="$cache_config" cleanup --force >/dev/null || true
    fi
    docker stop "$redis_container" >/dev/null 2>&1 || true
    find "$cache_root" -depth -delete
}
trap cleanup EXIT

docker run --detach --rm --name "$redis_container" --publish 127.0.0.1::6379 redis:7.4-alpine >/dev/null
redis_port=$(docker port "$redis_container" 6379/tcp | sed 's/.*://')
php -r '
    $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $config["config"]["WP_REDIS_HOST"] = "host.docker.internal";
    $config["config"]["WP_REDIS_PORT"] = (int) $argv[3];
    file_put_contents($argv[2], json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
' "$plugin_root/.wp-env.json" "$cache_config" "$redis_port"

run_wp() {
    "$wp_env" --config="$cache_config" run cli wp "$@"
}

"$wp_env" --config="$cache_config" start --update
environment_started=1
run_wp plugin install redis-cache --version=2.8.0 --force --activate
run_wp redis enable
run_wp eval 'wp_cache_set("pyro-scope-probe", "ready", "pyro-scope", 300);'
run_wp eval 'if (!wp_using_ext_object_cache() || "ready" !== wp_cache_get("pyro-scope-probe", "pyro-scope")) { throw new RuntimeException("Persistent object cache did not retain data across requests."); }'

run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-reset-activation.php
run_wp plugin activate pyro-scope
bash "$plugin_root/bin/test-concurrency.sh" "$cache_config"
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-clear-debug-log.php
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-smoke.php
run_wp plugin deactivate pyro-scope
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-deactivation-smoke.php
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-uninstall-smoke.php run
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-debug-log-smoke.php

"$wp_env" --config="$cache_config" cleanup --force
environment_started=0
docker stop "$redis_container" >/dev/null
trap - EXIT
find "$cache_root" -depth -delete
echo 'Persistent object cache checks passed.'
