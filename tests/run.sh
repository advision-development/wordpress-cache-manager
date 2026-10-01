#!/usr/bin/env bash
#
# Run every CLI harness. These do not need a WordPress install: the handful of
# WordPress functions the plugin touches are stubbed in wp-stubs.php.

set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

STATUS=0

for suite in test-runner.php test-jobs.php test-adapters.php test-urls.php test-controller.php test-capabilities.php test-updater.php test-labels.php test-background.php test-warm.php test-safe.php test-footprint.php test-loopback.php; do
	echo "=============================================================="
	echo "  ${suite}"
	echo "=============================================================="

	if ! php "${suite}"; then
		STATUS=1
	fi

	echo
done

if [[ "${STATUS}" -ne 0 ]]; then
	echo "SUITE FAILED" >&2
fi

exit "${STATUS}"
