#!/usr/bin/env bash
set -euo pipefail

plugin_root=$(cd "$(dirname "$0")/.." && pwd)
wp_env="$plugin_root/node_modules/.bin/wp-env"
archive=$("$plugin_root/bin/build-release.sh")
archive_name=$(basename "$archive")
plugin_version=${archive_name#pyro-scope-}
plugin_version=${plugin_version%.zip}
release_root=$(mktemp -d "${TMPDIR:-/tmp}/pyro-scope-release.XXXXXX")
current_config=

cleanup() {
    if [[ -n "$current_config" ]]; then
        "$wp_env" --config="$current_config" cleanup --force >/dev/null || true
    fi
    find "$release_root" -depth -delete
}
trap cleanup EXIT

archive_files=$(zipinfo -1 "$archive")
unzip -tq "$archive" >/dev/null
grep -Fxq 'pyro-scope/pyro-scope.php' <<<"$archive_files"
grep -Fxq 'pyro-scope/uninstall.php' <<<"$archive_files"
grep -Fxq "Stable tag: $plugin_version" "$plugin_root/readme.txt"
grep -Fxq -- "- Stable tag: $plugin_version" "$plugin_root/README.md"
grep -Fxq "= $plugin_version =" "$plugin_root/readme.txt"
if grep -Ev '^pyro-scope/' <<<"$archive_files" | grep -q .; then
    echo 'Release archive contains an entry outside the pyro-scope directory.' >&2
    exit 1
fi
if grep -Eq '^pyro-scope/(bin|tests|vendor|node_modules)/|^pyro-scope/\.|^pyro-scope/(AGENTS|CLAUDE|composer|package|phpcs|phpunit)|^pyro-scope/pyro-scope-.*\.zip$' <<<"$archive_files"; then
    echo 'Release archive contains a development-only file.' >&2
    exit 1
fi
first_hash=$(php -r 'echo hash_file("sha256", $argv[1]);' "$archive")
"$plugin_root/bin/build-release.sh" >/dev/null
second_hash=$(php -r 'echo hash_file("sha256", $argv[1]);' "$archive")
if [[ "$first_hash" != "$second_hash" ]]; then
    echo 'Repeated release builds produced different archives.' >&2
    exit 1
fi

run_wp() {
    "$wp_env" --config="$current_config" run cli wp "$@"
}

for source_config in "$plugin_root/.wp-env.json" "$plugin_root/.wp-env.wp60.json"; do
    current_config="$release_root/$(basename "$source_config")"
    php -r '
        $config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $config["plugins"] = [];
        $config["mappings"] = ["wp-content/pyro-scope-release" => $argv[3]];
        file_put_contents($argv[2], json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    ' "$source_config" "$current_config" "$plugin_root"

    "$wp_env" --config="$current_config" start --update
    if run_wp plugin is-installed pyro-scope >/dev/null 2>&1; then
        echo 'Pyro Scope was present before the release archive was installed.' >&2
        exit 1
    fi
    run_wp plugin install "wp-content/pyro-scope-release/$archive_name"
    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-reset-activation.php

    if [[ "$source_config" == *'.wp-env.wp60.json' ]]; then
        run_wp plugin activate pyro-scope -- --network
    else
        run_wp plugin activate pyro-scope
    fi

    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-clear-debug-log.php
    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-smoke.php

    if [[ "$source_config" == *'.wp-env.wp60.json' ]]; then
        run_wp plugin deactivate pyro-scope -- --network
    else
        run_wp plugin deactivate pyro-scope
    fi
    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-deactivation-smoke.php
    run_wp plugin uninstall pyro-scope
    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-uninstall-smoke.php
    run_wp eval-file wp-content/pyro-scope-release/tests/runtime-debug-log-smoke.php

    "$wp_env" --config="$current_config" cleanup --force
    current_config=
done

trap - EXIT
find "$release_root" -depth -delete
echo "Release archive passed clean installation checks: $archive_name"
