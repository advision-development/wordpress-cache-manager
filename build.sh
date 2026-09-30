#!/usr/bin/env bash
#
# Build the installable plugin zip.
#
# Output: dist/wordpress-cache-manager-<version>.zip
# The zip root is the plugin folder, so it installs directly through
# Plugins -> Add New -> Upload Plugin.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SLUG="wordpress-cache-manager"
SRC="${ROOT}/${SLUG}"
DIST="${ROOT}/dist"

if [[ ! -d "${SRC}" ]]; then
	echo "error: plugin directory not found at ${SRC}" >&2
	exit 1
fi

VERSION="$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' "${SRC}/${SLUG}.php" | head -n1)"

if [[ -z "${VERSION}" ]]; then
	echo "error: could not read Version from the plugin header" >&2
	exit 1
fi

echo "==> linting"
find "${SRC}" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

echo "==> PHP 7.4 compatibility gate"
if grep -rnE '\bmatch\s*\(|\?->|str_contains|str_starts_with|str_ends_with|\benum |readonly ' --include='*.php' "${SRC}"; then
	echo "error: PHP 8-only syntax found (see matches above)" >&2
	exit 1
fi

echo "==> tests"
"${ROOT}/tests/run.sh" >/dev/null || {
	echo "error: test suite failed, run ./tests/run.sh to see why" >&2
	exit 1
}

echo "==> staging"
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT

mkdir -p "${STAGE}/${SLUG}"

rsync -a \
	--exclude '.*' \
	--exclude '*.zip' \
	--exclude 'node_modules' \
	"${SRC}/" "${STAGE}/${SLUG}/"

echo "==> zipping"
mkdir -p "${DIST}"
ZIP="${DIST}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"

( cd "${STAGE}" && zip -qr "${ZIP}" "${SLUG}" -x '*.DS_Store' )

echo
echo "built ${ZIP}"
unzip -l "${ZIP}"
