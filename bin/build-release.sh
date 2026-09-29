#!/usr/bin/env bash
set -euo pipefail

plugin_root=$(cd "$(dirname "$0")/.." && pwd)
plugin_version=$(php -r '$source=file_get_contents($argv[1]); preg_match("/^Version:\\s*(.+)$/m", $source, $match); echo trim($match[1]);' "$plugin_root/pyro-scope.php")
build_root=$(mktemp -d "${TMPDIR:-/tmp}/pyro-scope-build.XXXXXX")
trap 'rm -rf -- "$build_root"' EXIT

mkdir "$build_root/pyro-scope"
rsync -a --exclude-from="$plugin_root/.distignore" "$plugin_root/" "$build_root/pyro-scope/"
touch -r "$plugin_root/pyro-scope.php" "$build_root/pyro-scope"
rm -f -- "$plugin_root/pyro-scope-$plugin_version.zip"
(
    cd "$build_root"
    TZ=UTC zip -Xqr "$plugin_root/pyro-scope-$plugin_version.zip" pyro-scope
)

echo "$plugin_root/pyro-scope-$plugin_version.zip"
