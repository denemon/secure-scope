#!/usr/bin/env bash
set -euo pipefail

if [[ ${1:-} != "--inside" ]]; then
    config=${1:?wp-env config is required}
    exec npx wp-env --config="$config" run cli bash wp-content/plugins/pyro-scope/bin/test-concurrency.sh --inside
fi

output_dir=$(mktemp -d "${TMPDIR:-/tmp}/pyro-scope-concurrency.XXXXXX")
trap 'rm -rf -- "$output_dir"' EXIT

run_wp() {
    wp "$@"
}

run_contenders() {
    local round=$1
    local pids=()

    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php prepare "$round"
    for index in {1..8}; do
        run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php contend "$round" "$index" >"$output_dir/$round-$index.log" 2>&1 &
        pids+=("$!")
    done
    for index in {1..8}; do
        if ! wait "${pids[$((index - 1))]}"; then
            cat "$output_dir/$round-$index.log"
            return 1
        fi
    done
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php verify "$round"
}

run_cas() {
    local operation=$1
    local waiter_pid

    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php cas_prepare "$operation"
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php cas_wait "$operation" >"$output_dir/cas-$operation.log" 2>&1 &
    waiter_pid=$!
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php cas_replace "$operation"
    if ! wait "$waiter_pid"; then
        cat "$output_dir/cas-$operation.log"
        return 1
    fi
    run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php cas_verify "$operation"
}

run_contenders fresh
run_contenders stale
run_cas refresh
run_cas release
run_wp eval-file wp-content/plugins/pyro-scope/tests/runtime-concurrency.php controller controller
