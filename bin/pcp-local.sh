#!/usr/bin/env bash
# Local headless Plugin Check (PCP) on the built wordpress.org tree -- the same checks
# the wordpress.org submission form and .github/workflows/compliance.yml run, in ~2 min,
# with no MySQL (SQLite drop-in) inside a throwaway wordpress:cli-php8.2 container.
#
#   node bin/build-dist.js          # builds dist/wporg/gend-society
#   bash bin/pcp-local.sh [dir]     # default dir: dist/wporg/gend-society
#
# Prints "PCP ERRORS: <n>" and "PCP WARNINGS: <n>" plus errors per file, and writes
# the raw report to dist/pcp.json (git-ignored; PCP_OUT=<file> to put it elsewhere).
# Downloads Plugin Check 2.1.0, the SQLite drop-in and WordPress core once into
# .cache/pcp/ (git-ignored).
#
# WPCS / PHPCompatibilityWP without Composer (PCP bundles PHPCS 3 + WPCS 3.4.1), after
# one run of this script has filled .cache/pcp/:
#   docker run --rm -v "$PWD/.cache/pcp:/s/tools:ro" -v "$PWD/dist/wporg/gend-society:/p:ro" php:8.2-cli \
#     php /s/tools/plugin-check/vendor/squizlabs/php_codesniffer/bin/phpcs --standard=WordPress \
#     --sniffs=WordPress.NamingConventions.PrefixAllGlobals --runtime-set prefixes gend_society,gend_gs \
#     --extensions=php --report=summary /p
# (on Git Bash prefix with MSYS_NO_PATHCONV=1). With Composer: composer install &&
#   vendor/bin/phpcs --standard=phpcs.xml.dist dist/wporg/gend-society
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
BUILD=${1:-$ROOT/dist/wporg/gend-society}
CACHE=$ROOT/.cache/pcp
OUT=${PCP_OUT:-$ROOT/dist/pcp.json}
PCP_VERSION=2.1.0
IMAGE=${PCP_IMAGE:-wordpress:cli-php8.2}

[ -f "$BUILD/gend-society.php" ] || { echo "pcp-local: $BUILD/gend-society.php not found (run node bin/build-dist.js first)" >&2; exit 1; }
[ "$(basename "$BUILD")" = "gend-society" ] || { echo "pcp-local: build dir must be named gend-society (the slug)" >&2; exit 1; }

# Docker paths: Windows Git Bash needs C:/... paths and no MSYS path mangling.
hostpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi; }

mkdir -p "$CACHE"
fetch() { # fetch <url> <dir-name-inside-cache>
	[ -d "$CACHE/$2" ] && return
	local zip="$CACHE/$2.zip"
	echo "pcp-local: downloading $1"
	curl -fsSL --retry 3 "$1" -o "$(hostpath "$zip")"
	(cd "$CACHE" && unzip -q -o "$zip")
	rm -f "$zip"
	[ -d "$CACHE/$2" ] || { echo "pcp-local: $1 did not unpack to $2/" >&2; exit 1; }
}
fetch "https://downloads.wordpress.org/plugin/plugin-check.${PCP_VERSION}.zip" plugin-check
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.zip" sqlite-database-integration

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
	docker pull "$IMAGE" >/dev/null 2>&1 || { IMAGE="mirror.gcr.io/library/$IMAGE"; docker pull "$IMAGE" >/dev/null; }
fi

OUTDIR=$(mktemp -d)
trap 'rm -rf "$OUTDIR"' EXIT

MSYS_NO_PATHCONV=1 docker run --rm -u 0 \
	-v "$(hostpath "$CACHE"):/s/tools" \
	-v "$(hostpath "$BUILD"):/s/plugin/gend-society:ro" \
	-v "$(hostpath "$OUTDIR"):/s/out" \
	--entrypoint sh "$IMAGE" -c '
set -e
W="php -d memory_limit=1G /usr/local/bin/wp --allow-root"
if [ ! -f /s/tools/wp-core/wp-load.php ]; then
	$W core download --path=/s/tools/wp-core --quiet
fi
mkdir -p /tmp/wp && cp -r /s/tools/wp-core/. /tmp/wp/ && cd /tmp/wp
cp -r /s/tools/sqlite-database-integration wp-content/plugins/
cp wp-content/plugins/sqlite-database-integration/db.copy wp-content/db.php
sed -i "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/tmp/wp/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" wp-content/db.php
$W config create --dbname=x --dbuser=x --dbpass=x --skip-check --quiet
$W core install --url=http://localhost --title=t --admin_user=a --admin_password=a --admin_email=a@example.com --skip-email --quiet
cp -r /s/tools/plugin-check /s/plugin/gend-society wp-content/plugins/
$W plugin activate plugin-check --quiet
$W plugin activate gend-society 2>&1 | tee /s/out/activate.txt
$W plugin check gend-society --categories=plugin_repo,security --format=json \
	--require=./wp-content/plugins/plugin-check/cli.php > /s/out/pcp.json 2>/s/out/pcp.err || true
php -r '"'"'
$raw = file_get_contents("/s/out/pcp.json");
$files = array(); $e = 0; $w = 0;
foreach ( preg_split("/^FILE: /m", $raw) as $block ) {
	$nl = strpos($block, "\n"); if ( false === $nl ) { continue; }
	$file = trim(substr($block, 0, $nl)); $rows = json_decode(trim(substr($block, $nl + 1)), true);
	if ( ! is_array($rows) ) { continue; }
	foreach ( $rows as $r ) {
		if ( "ERROR" === $r["type"] ) { $e++; $files[$file] = ( $files[$file] ?? 0 ) + 1; } else { $w++; }
	}
}
arsort($files);
foreach ( $files as $f => $n ) { printf("  %4d  %s\n", $n, $f); }
echo "PCP ERRORS: $e\nPCP WARNINGS: $w\n";
'"'"'
'

cp "$OUTDIR/pcp.json" "$OUT"
grep -qi "error" "$OUTDIR/activate.txt" && { echo "pcp-local: activation output:"; cat "$OUTDIR/activate.txt"; }
[ -s "$OUTDIR/pcp.err" ] && { echo "pcp-local: plugin check stderr:"; head -20 "$OUTDIR/pcp.err"; }
echo "pcp-local: report written to $OUT"
