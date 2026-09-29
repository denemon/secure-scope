#!/usr/bin/env bash
set -euo pipefail

configs=(.wp-env.json .wp-env.wp60.json)
current_config=

cleanup_environment() {
    if [[ -n "$current_config" ]]; then
        npx wp-env --config="$current_config" cleanup --force >/dev/null || true
    fi
}
trap cleanup_environment EXIT

run_wp() {
    npx wp-env --config="$current_config" run cli wp "$@"
}

for current_config in "${configs[@]}"; do
    npx wp-env --config="$current_config" start --update
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-reset-activation.php

    if [[ "$current_config" == ".wp-env.wp60.json" ]]; then
        run_wp plugin activate pyro-scope -- --network
    else
        run_wp plugin activate pyro-scope
    fi

    bash bin/test-concurrency.sh "$current_config"
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-clear-debug-log.php
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-smoke.php

    if [[ "$current_config" == ".wp-env.json" ]]; then
        run_wp plugin install plugin-check --force --activate
        run_wp plugin check pyro-scope -- \
            --format=strict-table \
            --exclude-directories=bin,tests,vendor,node_modules \
            --exclude-files=.DS_Store,.distignore,.gitignore,.wp-env.json,.wp-env.wp60.json,AGENTS.md,CLAUDE.md,composer.json,composer.lock,package.json,package-lock.json,phpcs.xml.dist,phpunit.xml.dist,pyro-scope-3.5.2.zip
    fi

    if [[ "$current_config" == ".wp-env.wp60.json" ]]; then
        run_wp plugin deactivate pyro-scope -- --network
    else
        run_wp plugin deactivate pyro-scope
    fi
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-deactivation-smoke.php
    if [[ "$current_config" == ".wp-env.wp60.json" ]]; then
        run_wp plugin activate pyro-scope -- --network
    else
        run_wp plugin activate pyro-scope
    fi
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-debug-log-smoke.php

    npx wp-env --config="$current_config" cleanup --force
    current_config=
done

trap - EXIT
