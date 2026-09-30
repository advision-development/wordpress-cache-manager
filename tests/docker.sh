#!/usr/bin/env bash
#
# Run the suite, or build, inside PHP containers rather than a PHP installed on this machine.
# The same two versions CI runs: 7.4 is the floor the plugin header claims, 8.3 is current.
#
#   ./tests/docker.sh            # the suite on 7.4 and 8.3
#   ./tests/docker.sh build      # ./build.sh, to see the zip the release would publish

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "${1:-}" == "build" ]]; then
	# build.sh needs zip, unzip and rsync, which the php images do not carry. On 8.3 rather than
	# 7.4: the 7.4 image's Debian (bullseye) no longer serves its security pool, so apt fails there
	# (measured 2026-09-30). The 7.4 floor is still held — the suite above runs on 7.4, and so do CI
	# and the release workflow.
	exec docker run --rm -v "${ROOT}":/app -w /app php:8.3-cli bash -c \
		'apt-get update -qq >/dev/null && apt-get install -y -qq zip unzip rsync >/dev/null && ./build.sh'
fi

STATUS=0

for version in 7.4 8.3; do
	echo "######## PHP ${version}"
	docker run --rm -v "${ROOT}":/app -w /app "php:${version}-cli" ./tests/run.sh || STATUS=1
done

exit "${STATUS}"
