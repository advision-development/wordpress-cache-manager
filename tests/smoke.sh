#!/usr/bin/env bash
#
# The plugin in a real WordPress, in containers: install, use, break things around it, and scan it.
#
#   ./tests/smoke.sh            # WordPress on PHP 8.3
#   PHP=7.4 ./tests/smoke.sh    # on the floor the header claims
#
# What it holds, beyond the unit suites:
#   - activating it, pressing every button and running every mode leaves every page answering
#     200 with no fatal, no "critical error", and nothing from this plugin in the PHP error log
#   - a background mode finishes through WP-Cron, in order
#   - deactivating a layer's plugin between two clears makes that step "skipped", nothing else
#   - WordPress Malware Quick Scan, installed from its public release, reports nothing about this
#     plugin beyond the findings FOOTPRINT.md says to expect
#
# Uses its own network and container names and removes them when done. Touches nothing else.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-8.3}"
NET="advcm-smoke"
DB="advcm-smoke-db"
WP="advcm-smoke-wp"
PORT="${PORT:-8089}"
URL="http://localhost:${PORT}"
WORK=""
FAILED=0

cleanup() {
	docker rm -f "${WP}" "${DB}" >/dev/null 2>&1 || true
	docker network rm "${NET}" >/dev/null 2>&1 || true
	[[ -n "${WORK}" ]] && rm -rf "${WORK}"
	return 0
}
trap cleanup EXIT

pass() { echo "PASS  $*"; }
fail() { echo "FAIL  $*"; FAILED=1; }

wpcli() {
	# In the WordPress container's network namespace, so "localhost" inside WP-CLI is the site —
	# the warm-up requests the site's own home URL, as it does on a real host.
	docker run --rm -i --network "container:${WP}" --volumes-from "${WP}" --user 33:33 \
		-e WORDPRESS_DB_HOST="${DB}" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
		wordpress:cli "$@" 2>&1
}

# Runs a PHP file inside WordPress, prints its PASS/FAIL lines, and fails the smoke on any FAIL.
wpeval() {
	local out
	docker cp "$1" "${WP}:/var/www/html/advcm-smoke-eval.php" >/dev/null
	out="$(wpcli wp eval-file /var/www/html/advcm-smoke-eval.php)"
	echo "${out}" | grep -E "^(PASS|FAIL)" || { echo "FAIL  $(basename "$1") printed no result:"; echo "${out}" | tail -5; FAILED=1; }
	if echo "${out}" | grep -q "^FAIL"; then FAILED=1; fi
}

cleanup
WORK="$(mktemp -d)"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name "${WP}" --network "${NET}" -p "${PORT}:80" \
	-e WORDPRESS_DB_HOST="${DB}" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
	-e WORDPRESS_DEBUG=1 \
	-v "${ROOT}/wordpress-cache-manager:/var/www/html/wp-content/plugins/wordpress-cache-manager:ro" \
	"wordpress:php${PHP}-apache" >/dev/null

for _ in $(seq 1 60); do
	curl -s -o /dev/null "${URL}/wp-login.php" && break
	sleep 2
done

# Errors to a file the script can read, not to the page. And Apache on the mapped port too, so the
# site can reach its own home URL from inside the container, as a real site does — the warm-up
# requests it.
docker exec "${WP}" bash -c "printf 'log_errors=On\nerror_log=/tmp/php-errors.log\ndisplay_errors=Off\n' > /usr/local/etc/php/conf.d/zz-advcm-smoke.ini && echo 'Listen ${PORT}' >> /etc/apache2/ports.conf && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:80 *:${PORT}>/' /etc/apache2/sites-enabled/000-default.conf && apache2ctl graceful" >/dev/null 2>&1 || true

# The database container takes a while to accept connections; WordPress cannot install before.
installed=0
for _ in $(seq 1 40); do
	if wpcli wp core install --url="${URL}" --title=smoke --admin_user=admin --admin_password=admin --admin_email=a@example.test --skip-email >/dev/null; then
		installed=1
		break
	fi
	sleep 3
done
[[ "${installed}" -eq 1 ]] || { echo "FAIL  WordPress did not install"; exit 1; }
wpcli wp user create ed ed@example.test --role=editor --user_pass=ed >/dev/null
# The newest Elementor where it installs. The PHP 7.4 image ships WordPress 6.1, which the newest
# Elementor refuses, so there an older one that still supports both — the plugin claims WordPress
# 5.8 and PHP 7.4, and that pair is exactly what this run is for.
if ! wpcli wp plugin install elementor --activate >/dev/null; then
	wpcli wp plugin install elementor --version=3.18.3 --activate >/dev/null
fi
echo "      WordPress $(wpcli wp core version), Elementor $(wpcli wp plugin get elementor --field=version), PHP ${PHP}"

# The scanner, from its public release — the same zip the fleet installs.
SCANNER_ZIP="$(curl -s https://api.github.com/repos/advision-development/wordpress-malware-quick-scan/releases/latest | sed -n 's/.*"browser_download_url": *"\([^"]*\.zip\)".*/\1/p' | head -1)"
wpcli wp plugin install "${SCANNER_ZIP}" --activate >/dev/null

out="$(wpcli wp plugin activate wordpress-cache-manager)"
echo "${out}" | grep -q "Success" && pass "activates" || fail "activates: ${out}"

# --------------------------------------------------------------------------- every page

check_pages() {
	local label="$1" jar="${WORK}/jar"

	rm -f "${jar}"
	curl -s -c "${jar}" -b "${jar}" -o /dev/null "${URL}/wp-login.php"
	curl -s -c "${jar}" -b "${jar}" -o /dev/null --data-urlencode "log=ed" --data-urlencode "pwd=ed" -d "wp-submit=Log+In&testcookie=1" --data-urlencode "redirect_to=${URL}/wp-admin/" "${URL}/wp-login.php"

	for path in "/" "/?p=1" "/wp-admin/" "/wp-admin/plugins.php" "/wp-admin/tools.php?page=advcm-cache"; do
		local code body
		body="$(curl -s -L -b "${jar}" -w '\n%{http_code}' "${URL}${path}")"
		code="$(echo "${body}" | tail -1)"

		if [[ "${code}" != "200" && "${code}" != "403" ]] || echo "${body}" | grep -qiE "critical error|fatal error|There has been a critical"; then
			fail "${label}: ${path} answered ${code}"
		fi
	done

	pass "${label}: every page answers, no fatal"
}

check_pages "after activation"

# ------------------------------------------------------------------ every mode, as WordPress

cat > "${WORK}/modes.php" <<'PHP'
<?php
wp_set_current_user( 2 );
$fail = 0;
$say  = function ( $ok, $label ) use ( &$fail ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n"; if ( ! $ok ) { $fail++; } };

$j = ADVCM_Runner::start( array( 'scope' => 'urls', 'urls' => array( home_url( '/' ) ), 'mode' => 'fast', 'by' => 2 ) );
$say( in_array( $j['state'], array( 'done', 'done with skips' ), true ), 'fast, one page: ' . $j['state'] );

$j = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => 'fast', 'by' => 2 ) );
$st = array(); foreach ( $j['steps'] as $s ) { $st[ $s['id'] ] = $s['status']; }
$say( 'ok' === $st['elementor'], 'fast, whole site: Elementor cleared (' . $st['elementor'] . ')' );
$say( 'ok' === $st['warm'], 'fast, whole site: home page warmed (' . $st['warm'] . ')' );

$j = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => 'balanced', 'by' => 2 ) );
$say( 'running' === $j['state'], 'balanced returns at once' );
update_option( 'advcm_smoke_job', $j['id'] );
PHP
# The continuations are run by hand below, one after another, so the pauses are not waited out:
# what is checked here is that a background job finishes, in order. The pauses themselves are
# held by test-background.php.
wpeval "${WORK}/modes.php"

# WP-Cron, by hand, until the job finishes.
cat > "${WORK}/drain.php" <<'PHP'
<?php
$id = get_option( 'advcm_smoke_job' );
for ( $i = 0; $i < 20; $i++ ) {
	$job = ADVCM_Jobs::get( $id );
	if ( 'running' !== $job['state'] ) { break; }
	wp_clear_scheduled_hook( 'advcm_continue', array( $id ) );
	sleep( 2 );
	ADVCM_Runner::run( $id );
}
$job = ADVCM_Jobs::get( $id );
$order = array(); foreach ( $job['steps'] as $s ) { if ( 'skipped' !== $s['status'] ) { $order[] = $s['id']; } }
echo ( 'running' !== $job['state'] ? 'PASS  ' : 'FAIL  ' ), 'balanced finishes through its continuations: ', $job['state'], ' — ', implode( ' > ', $order ), "\n";
PHP
wpeval "${WORK}/drain.php"

# ------------------------------------------------------------- a layer removed between clears

wpcli wp plugin deactivate elementor >/dev/null
cat > "${WORK}/removed.php" <<'PHP'
<?php
$j = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => 'fast', 'by' => 2 ) );
$st = array(); foreach ( $j['steps'] as $s ) { $st[ $s['id'] ] = $s['status'] . ' — ' . $s['message']; }
echo ( 0 === strpos( $st['elementor'], 'skipped' ) ? 'PASS  ' : 'FAIL  ' ), 'Elementor removed: ', $st['elementor'], "\n";
echo ( 0 === strpos( $st['warm'], 'ok' ) ? 'PASS  ' : 'FAIL  ' ), 'and the rest still ran: warm ', $st['warm'], "\n";
PHP
wpeval "${WORK}/removed.php"
check_pages "after a layer was removed"

# --------------------------------------------------------------------- the security scanner

cat > "${WORK}/scan.php" <<'PHP'
<?php
$state = WPMQS_Scan_Runner::start( 7 );
for ( $i = 0; $i < 400; $i++ ) {
	$state = WPMQS_Scan_State::get();
	if ( ! WPMQS_Scan_State::is_active( $state ) ) { break; }
	WPMQS_Scan_Runner::step( $state );
}
$report   = WPMQS_Report::last();
$expected = array( 'plugin_not_in_directory', 'recent_application_files', 'rule_suppressed_by_site', 'rule_exempted_by_site' );
$ours     = 0;
$bad      = 0;
foreach ( (array) $report['findings'] as $f ) {
	$text = json_encode( $f );
	if ( false === stripos( $text, 'wordpress-cache-manager' ) && false === stripos( $text, 'advcm' ) ) { continue; }
	$ours++;
	$rule = isset( $f['rule'] ) ? $f['rule'] : '?';
	if ( ! in_array( $rule, $expected, true ) ) { $bad++; echo 'FAIL  scanner: ', $rule, ' (', isset( $f['severity'] ) ? $f['severity'] : '?', ') ', isset( $f['target'] ) ? $f['target'] : '', "\n"; }
}
echo ( 0 === $bad ? 'PASS  ' : 'FAIL  ' ), 'WPMQS reports nothing about this plugin beyond what FOOTPRINT.md expects (', $ours, ' expected finding(s) mention it)', "\n";
PHP
wpeval "${WORK}/scan.php"

# ------------------------------------------------------------------------------ the log

docker exec "${WP}" bash -c "cat /tmp/php-errors.log 2>/dev/null" > "${WORK}/errors.log" || true

if grep -iE "advcm|wordpress-cache-manager" "${WORK}/errors.log" | grep -viE "Deprecated" >/dev/null; then
	fail "the PHP error log names this plugin:"
	grep -iE "advcm|wordpress-cache-manager" "${WORK}/errors.log" | head -10
else
	pass "nothing from this plugin in the PHP error log"
fi

out="$(wpcli wp plugin deactivate wordpress-cache-manager)"
echo "${out}" | grep -q "Success" && pass "deactivates" || fail "deactivates: ${out}"
check_pages "after deactivation"

if [[ "${FAILED}" -ne 0 ]]; then
	echo "SMOKE FAILED" >&2
	exit 1
fi

echo "smoke passed on PHP ${PHP}"
