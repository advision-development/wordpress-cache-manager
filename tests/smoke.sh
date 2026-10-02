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
#   - auto-clear: a rule written through the screen by an administrator (and refused to an editor),
#     posts published through the block editor's REST path and wp_insert_post, a burst coalesced
#     into one clear run by the site's real WP-Cron, and a fault in the hook that never fails a save;
#     a post only in a subcategory firing its parent's rule, an "any content" rule firing on a page
#     and not on a builder template, History naming the posts, and the pause stopping a publish
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
	# KEEP=1 leaves the site running after the run, to look at it in a browser. The next run
	# removes it first either way.
	if [[ "${KEEP:-0}" == "1" && "${FINISHED:-0}" == "1" ]]; then
		echo "      left running at ${URL} (admin/admin, ed/ed); ./tests/smoke.sh removes it"
		return 0
	fi
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
	if grep -q "^FAIL" <<< "${out}"; then FAILED=1; fi
}

cleanup
WORK="$(mktemp -d)"
docker network create "${NET}" >/dev/null
docker run -d --name "${DB}" --network "${NET}" -e MARIADB_ROOT_PASSWORD=r -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name "${WP}" --network "${NET}" -p "${PORT}:80" \
	-e WORDPRESS_DB_HOST="${DB}" -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
	-e WORDPRESS_DEBUG=1 \
	-e WORDPRESS_CONFIG_EXTRA="define( 'WP_AUTO_UPDATE_CORE', false );" \
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
# The newest WordPress the image's PHP runs, rather than whatever the image shipped: the PHP 7.4
# image carries WordPress 6.1, and the scanner and Elementor no longer install on it. WordPress
# would otherwise update itself in the background, mid-run, and once a WP-CLI command read its
# files half-copied — so background core updates are off in this container and this is the one.
wpcli wp core update >/dev/null || true
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
grep -q "Success" <<< "${out}" && pass "activates" || fail "activates: ${out}"
title="$(wpcli wp plugin get wordpress-cache-manager --field=title)"
[[ "${title}" == "CacheManager" ]] && pass "the Plugins screen lists it as CacheManager" || fail "the Plugins screen lists it as '${title}'"

# --------------------------------------------------------------------------- every page

check_pages() {
	local label="$1" jar="${WORK}/jar"

	rm -f "${jar}"
	curl -s -c "${jar}" -b "${jar}" -o /dev/null "${URL}/wp-login.php"
	curl -s -c "${jar}" -b "${jar}" -o /dev/null --data-urlencode "log=ed" --data-urlencode "pwd=ed" -d "wp-submit=Log+In&testcookie=1" --data-urlencode "redirect_to=${URL}/wp-admin/" "${URL}/wp-login.php"

	for path in "/" "/?p=1" "/wp-admin/" "/wp-admin/plugins.php" "/wp-admin/tools.php?page=advcm-cache" "/wp-admin/tools.php?page=advcm-cache&tab=clear" "/wp-admin/tools.php?page=advcm-cache&tab=check" "/wp-admin/tools.php?page=advcm-cache&tab=history" "/wp-admin/tools.php?page=advcm-cache&tab=auto" "/wp-admin/tools.php?page=advcm-cache&tab=nonsense"; do
		local code body
		body="$(curl -s -L -b "${jar}" -w '\n%{http_code}' "${URL}${path}")"
		code="$(echo "${body}" | tail -1)"

		if [[ "${code}" != "200" && "${code}" != "403" ]] || grep -qiE "critical error|fatal error|There has been a critical" <<< "${body}"; then
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

# The screen's own tick, as the browser sends it: logged in, with the nonce the page printed.
# It needs a job still running when the page loads. Here the site can reach itself, so WP-Cron
# finishes a plain Balanced clear before the page is fetched; a temporary mu-plugin gives Careful a
# long pause before the warm-up (a place pauses are allowed), and is removed before the scan.
docker exec -i -u 33 "${WP}" bash -c "mkdir -p /var/www/html/wp-content/mu-plugins && cat > /var/www/html/wp-content/mu-plugins/advcm-smoke-pause.php" <<'MU'
<?php
add_filter( 'advcm_modes', function ( $m ) { $m['careful']['pause_before'] = array( 7 => 600 ); return $m; } );
MU
cat > "${WORK}/careful.php" <<'PHP'
<?php
$j = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => 'careful' ) );
ADVCM_Runner::run( $j['id'] );
$j = ADVCM_Jobs::get( $j['id'] );
echo ( 'running' === $j['state'] && $j['next_at'] > time() ? 'PASS  ' : 'FAIL  ' ), 'a Careful clear is paused before its warm-up: ', $j['state'], "\n";
PHP
wpeval "${WORK}/careful.php"

jar="${WORK}/jar-tick"
curl -s -c "${jar}" -b "${jar}" -o /dev/null "${URL}/wp-login.php"
curl -s -c "${jar}" -b "${jar}" -o /dev/null --data-urlencode "log=ed" --data-urlencode "pwd=ed" -d "wp-submit=Log+In&testcookie=1" --data-urlencode "redirect_to=${URL}/wp-admin/" "${URL}/wp-login.php"
page="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache")"
nonce="$(echo "${page}" | sed -n 's/.*"_ajax_nonce",\("[^"]*"\).*/\1/p' | tr -d '"' | head -1)"
tick_in="$(curl -s -b "${jar}" -d "action=advcm_tick&_ajax_nonce=${nonce}" "${URL}/wp-admin/admin-ajax.php")"
tick_out="$(curl -s -d "action=advcm_tick&_ajax_nonce=${nonce}" "${URL}/wp-admin/admin-ajax.php")"
if [[ -n "${nonce}" ]] && grep -q '"success":true' <<< "${tick_in}"; then
	pass "the screen's tick answers a logged-in editor"
else
	fail "the screen's tick did not answer (nonce: '${nonce}'; page: $(echo "${page}" | grep -o '<title>[^<]*' | head -1); running jobs shown: $(echo "${page}" | grep -c 'advcm_tick'))"
fi
if grep -q '"success":true' <<< "${tick_out}"; then
	fail "the screen's tick answered somebody logged out"
else
	pass "and nobody logged out"
fi
if grep -q "cannot reach itself" <<< "${page}"; then fail "the site was reported unable to reach itself"; else pass "the loopback check found the site reachable"; fi
docker exec "${WP}" rm -f /var/www/html/wp-content/mu-plugins/advcm-smoke-pause.php

# The admin bar's whole-site clear asks first. It was an onclick the admin bar's esc_js() broke, so
# it never asked; now a listener. The page is checked for the listener and for no onclick left.
front="$(curl -s -b "${jar}" "${URL}/")"
if grep -q 'wp-admin-bar-advcm-site' <<< "${front}" && grep -q 'querySelector("#wp-admin-bar-advcm-site > a")' <<< "${front}" && ! grep -q 'onclick="return confirm' <<< "${front}"; then
	pass "the admin bar's whole-site clear asks before it runs"
else
	fail "the admin bar's whole-site clear has no working confirmation"
fi

# The menu is CacheManager; and "Leave NitroPack as it is" is offered only where NitroPack is,
# which this site does not have.
clear_tab="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=clear")"
if grep -q ">CacheManager<" <<< "${clear_tab}" && ! grep -q "Adv Cache" <<< "${clear_tab}" && ! grep -q 'name="nitropack"' <<< "${clear_tab}"; then
	pass "the menu reads CacheManager, and no NitroPack option where there is no NitroPack"
else
	fail "the menu label or the NitroPack option is wrong"
fi

# Status lists only what a clear runs here: Elementor is installed, WP Rocket and NitroPack are not.
status="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=status")"
if grep -q "<strong>Elementor CSS</strong>" <<< "${status}" && ! grep -q "WP Rocket" <<< "${status}" && ! grep -q "NitroPack" <<< "${status}" && ! grep -q "not installed" <<< "${status}"; then
	pass "Status lists only the layers a clear runs here"
else
	fail "Status lists layers that are not on this site"
fi

# History follows the same rule: no row for a layer that was never on the site.
history="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=history")"
if grep -q "Elementor CSS" <<< "${history}" && ! grep -q "WP Rocket page cache" <<< "${history}"; then
	pass "History lists only the layers that ran"
else
	fail "History lists layers that were never on this site"
fi

# How old a page's cache is, read on its tab. The first nonce there is the check's own form.
check="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=check")"
probe_nonce="$(grep -oE 'name="_wpnonce" value="[a-f0-9]+"' <<< "${check}" | head -1 | sed 's/.*value="//; s/"//')"
reading="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=check&advcm_probe=%2F&_wpnonce=${probe_nonce}")"
if grep -q "HTTP 200 in" <<< "${reading}" && grep -q "Age</th><td>" <<< "${reading}"; then
	pass "the screen reads a page's cache age: $(echo "${reading}" | sed -n 's/.*Age<\/th><td>\([^<]*\).*/\1/p' | head -1)"
else
	fail "the screen did not read a page's cache age (nonce '${probe_nonce}'): $(echo "${reading}" | grep -oE 'notice-error inline"><p>[^<]{0,200}|notice-warning inline"><p>[^<]{0,160}|Answer</th><td>[^<]*|Age</th><td>[^<]*' | head -5 | tr '\n' ' ')"
fi

# WP-Cron, by hand, until the job finishes.
cat > "${WORK}/drain.php" <<'PHP'
<?php
$id = get_option( 'advcm_smoke_job' );
for ( $i = 0; $i < 20; $i++ ) {
	$job = ADVCM_Jobs::get( $id );
	if ( 'running' !== $job['state'] ) { break; }
	wp_clear_scheduled_hook( 'advcm_continue', array( $id ) );
	// A pause is a wait, and run() refuses to start before it ends; let it run out here instead
	// of sleeping two minutes. The pauses themselves are held by test-background.php.
	if ( ! empty( $job['next_at'] ) && $job['next_at'] > time() ) { $job['next_at'] = time() - 1; ADVCM_Jobs::save( $job ); }
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

# ------------------------------------------------------------------------------ auto-clear

login() { # user, jar
	rm -f "$2"
	curl -s -c "$2" -b "$2" -o /dev/null "${URL}/wp-login.php"
	curl -s -c "$2" -b "$2" -o /dev/null --data-urlencode "log=$1" --data-urlencode "pwd=$1" -d "wp-submit=Log+In&testcookie=1" --data-urlencode "redirect_to=${URL}/wp-admin/" "${URL}/wp-login.php"
}

wpcli wp term create category Analysis --slug=analysis >/dev/null
wpcli wp post create --post_type=page --post_title=Analysis --post_name=analysis --post_status=publish >/dev/null

# Nothing to do before a rule exists: a publish schedules nothing.
cat > "${WORK}/auto-none.php" <<'PHP'
<?php
$cat = get_term_by( 'slug', 'analysis', 'category' );
wp_insert_post( array( 'post_title' => 'before any rule', 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ) );
echo ( false === wp_next_scheduled( 'advcm_auto_flush' ) ? 'PASS  ' : 'FAIL  ' ), "with no rule, publishing schedules nothing\n";
$post = get_post( wp_insert_post( array( 'post_title' => 'timed, no rule', 'post_status' => 'publish' ) ) );
$t    = microtime( true );
for ( $i = 0; $i < 1000; $i++ ) { ADVCM_Auto::saved( $post->ID, $post, true, $post ); }
printf( "PASS  with no rule the hook costs %.4f ms a save\n", ( microtime( true ) - $t ) * 1000 / 1000 );
PHP
wpeval "${WORK}/auto-none.php"

# The rule, through the screen's own form, as an administrator; an editor is refused.
admin_jar="${WORK}/jar-admin"
login admin "${admin_jar}"
auto_tab="$(curl -s -b "${admin_jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=auto")"
rules_nonce="$(grep -oE 'name="_wpnonce" value="[a-f0-9]+" /><input type="hidden" name="_wp_http_referer" value="[^"]*" /><input type="hidden" name="action" value="advcm_rules"' <<< "${auto_tab}" | head -1 | sed 's/.*name="_wpnonce" value="//; s/".*//')"
curl -s -b "${admin_jar}" -o /dev/null -d "action=advcm_rules&op=add&_wpnonce=${rules_nonce}&post_type=post&taxonomy=category&term=analysis&nitropack=invalidate&enabled=1" --data-urlencode "urls=/analysis/" "${URL}/wp-admin/admin-post.php"
rules="$(wpcli wp option get advcm_rules --format=json)"
if grep -q '"term_name":"Analysis"' <<< "${rules}" && grep -q 'analysis' <<< "${rules}" && grep -q '"enabled":true' <<< "${rules}"; then
	pass "an administrator adds a rule through the screen"
else
	fail "the rule was not added (nonce '${rules_nonce}'): ${rules}"
fi
ed_code="$(curl -s -b "${jar}" -o /dev/null -w '%{http_code}' -d "action=advcm_rules&op=add&_wpnonce=${rules_nonce}&post_type=post&urls=/x/" "${URL}/wp-admin/admin-post.php")"
[[ "${ed_code}" == "403" ]] && pass "an editor cannot write a rule (HTTP ${ed_code})" || fail "an editor writing a rule answered HTTP ${ed_code}"

cat > "${WORK}/auto-burst.php" <<'PHP'
<?php
$say = function ( $ok, $label ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n"; };
$cat = get_term_by( 'slug', 'analysis', 'category' );

// A draft, and a post in another category: nothing.
wp_insert_post( array( 'post_title' => 'a draft', 'post_status' => 'draft', 'post_category' => array( $cat->term_id ) ) );
wp_insert_post( array( 'post_title' => 'elsewhere', 'post_status' => 'publish', 'post_category' => array( 1 ) ) );
$say( false === wp_next_scheduled( 'advcm_auto_flush' ), 'a draft and a post outside the term schedule nothing' );

// The block editor's path: REST sets the categories after the post is inserted, which is why the
// hook is wp_after_insert_post. Run as an administrator through the REST server itself.
wp_set_current_user( 1 );
$r = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$r->set_body_params( array( 'title' => 'from the block editor', 'status' => 'publish', 'categories' => array( $cat->term_id ) ) );
$res = rest_do_request( $r );
$say( 201 === $res->get_status() && false !== wp_next_scheduled( 'advcm_auto_flush' ), 'a post published through REST, as the block editor does, schedules the clear (HTTP ' . $res->get_status() . ')' );
$first = wp_next_scheduled( 'advcm_auto_flush' );

// A burst: what each save costs, and that it is still one clear.
$ms = array();
for ( $i = 0; $i < 5; $i++ ) {
	$t = microtime( true );
	wp_insert_post( array( 'post_title' => 'burst ' . $i, 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ) );
	$ms[] = ( microtime( true ) - $t ) * 1000;
}
$cron  = _get_cron_array();
$count = 0;
foreach ( $cron as $at => $hooks ) { if ( isset( $hooks['advcm_auto_flush'] ) ) { $count += count( $hooks['advcm_auto_flush'] ); } }
$say( 1 === $count && $first === wp_next_scheduled( 'advcm_auto_flush' ), 'six posts published in the window are one scheduled clear (' . $count . ')' );
$say( $first - time() > 30, 'and it runs later, not in the save: in ' . ( $first - time() ) . ' s' );

// The hook's own cost, measured apart from wp_insert_post's.
$post = get_post( wp_insert_post( array( 'post_title' => 'timed', 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ) ) );
$t = microtime( true );
for ( $i = 0; $i < 200; $i++ ) { ADVCM_Auto::saved( $post->ID, $post, true, $post ); }
$hook = ( microtime( true ) - $t ) * 1000 / 200;
$say( $hook < 5, sprintf( 'the hook costs %.2f ms a save; a whole wp_insert_post took %.0f ms on average', $hook, array_sum( $ms ) / count( $ms ) ) );

// Due now, so the site's real WP-Cron runs it on the next request rather than in a minute.
wp_clear_scheduled_hook( 'advcm_auto_flush' );
wp_schedule_single_event( time() - 1, 'advcm_auto_flush' );
delete_transient( 'doing_cron' );
PHP
wpeval "${WORK}/auto-burst.php"

# WP-Cron as WordPress runs it: a visit, then the site requesting its own wp-cron.php.
curl -s -o /dev/null "${URL}/"
curl -s -o /dev/null "${URL}/wp-cron.php?doing_wp_cron"
cat > "${WORK}/auto-ran.php" <<'PHP'
<?php
$auto = array();
foreach ( ADVCM_Jobs::all() as $job ) { if ( isset( $job['source'] ) && 'auto' === $job['source'] ) { $auto[] = $job; } }
$job = isset( $auto[0] ) ? $auto[0] : null;
echo ( 1 === count( $auto ) ? 'PASS  ' : 'FAIL  ' ), 'WP-Cron ran exactly one auto-clear for the burst (', count( $auto ), ")\n";
echo ( $job && array( home_url( '/analysis/' ) ) === $job['urls'] && in_array( $job['state'], array( 'done', 'done with skips' ), true ) ? 'PASS  ' : 'FAIL  ' ), 'of /analysis/ alone: ', $job ? implode( ', ', $job['urls'] ) . ' — ' . $job['state'] : 'no job', "\n";
echo ( $job && 'invalidate' === $job['options']['nitropack_mode'] ? 'PASS  ' : 'FAIL  ' ), "asking NitroPack to invalidate\n";
$warm = ''; if ( $job ) { foreach ( $job['steps'] as $s ) { if ( 'warm' === $s['id'] ) { $warm = $s['status'] . ' — ' . $s['message']; } } }
echo ( 0 === strpos( $warm, 'ok' ) ? 'PASS  ' : 'FAIL  ' ), 'and the page was warmed: ', $warm, "\n";
global $wpdb;
$marks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'advcm\\_auto\\_%'" );
echo ( 0 === $marks && false === wp_next_scheduled( 'advcm_auto_flush' ) ? 'PASS  ' : 'FAIL  ' ), 'nothing left waiting after it (', $marks, " marks)\n";
PHP
wpeval "${WORK}/auto-ran.php"

history="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=history")"
grep -q "UTC — Auto-clear — ${URL}/analysis/" <<< "${history}" && pass "History names it Auto-clear" || fail "History does not show the auto-clear as Auto-clear"

# The Auto-clear tab for an editor: the rule is there, the forms are not; the test form answers.
auto_ed="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=auto")"
test_nonce="$(grep -oE 'name="_wpnonce" value="[a-f0-9]+"' <<< "${auto_ed}" | head -1 | sed 's/.*value="//; s/"//')"
tested="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=auto&advcm_test=$(wpcli wp post list --post_type=post --name=timed --field=ID | tr -d '\r')&_wpnonce=${test_nonce}")"
if grep -q 'in category &quot;Analysis&quot;' <<< "${auto_ed}" && ! grep -q 'value="add"' <<< "${auto_ed}" && grep -q "/analysis/</li>" <<< "${tested}"; then
	pass "an editor sees the rule and can test a post, and has no form to change it"
else
	fail "the Auto-clear tab for an editor is wrong: $(grep -oE 'notice[^>]*><p>[^<]*' <<< "${tested}" | head -2)"
fi

# 0.3.1: subcategories, "any content", what History says triggered a clear, and the pause.
curl -s -b "${admin_jar}" -o /dev/null -d "action=advcm_rules&op=add&_wpnonce=${rules_nonce}&post_type=*&nitropack=invalidate&enabled=1" --data-urlencode "urls=/" "${URL}/wp-admin/admin-post.php"
cat > "${WORK}/auto-more.php" <<'PHP'
<?php
$say = function ( $ok, $label ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n"; };
$ids = function ( $rule ) { $m = ADVCM_Auto::mark_from( get_option( 'advcm_auto_' . $rule, false ) ); $out = array(); foreach ( $m['posts'] as $p ) { $out[] = $p['id']; } return $out; };

$analysis = ''; $any = '';
foreach ( ADVCM_Auto::rules() as $id => $rule ) {
	if ( '*' === $rule['post_type'] ) { $any = $id; } elseif ( 'category' === $rule['taxonomy'] ) { $analysis = $id; }
}
$say( '' !== $any && 'any content is published' === ADVCM_Auto::describe( ADVCM_Auto::rules()[ $any ] ), 'an administrator adds an "any content" rule through the screen' );

$parent = get_term_by( 'slug', 'analysis', 'category' );
$child  = wp_insert_term( 'Analysis weekly', 'category', array( 'slug' => 'analysis-weekly', 'parent' => $parent->term_id ) );
$in_child = wp_insert_post( array( 'post_title' => 'Only in the subcategory', 'post_status' => 'publish', 'post_category' => array( $child['term_id'] ) ) );
$say( in_array( $in_child, $ids( $analysis ), true ) && false !== wp_next_scheduled( 'advcm_auto_flush' ), 'a post only in a subcategory triggers the parent category\'s rule' );

$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'A page for any content', 'post_status' => 'publish' ) );
$say( in_array( $page, $ids( $any ), true ) && ! in_array( $page, $ids( $analysis ), true ), 'a page published fires the "any content" rule, and not the category one' );

register_post_type( 'review', array( 'public' => true, 'label' => 'Reviews' ) );
register_post_type( 'bricks_template', array( 'public' => true, 'label' => 'Templates' ) );
$review   = wp_insert_post( array( 'post_type' => 'review', 'post_title' => 'A custom type', 'post_status' => 'publish' ) );
$template = wp_insert_post( array( 'post_type' => 'bricks_template', 'post_title' => 'A header template', 'post_status' => 'publish' ) );
$say( in_array( $review, $ids( $any ), true ) && ! in_array( $template, $ids( $any ), true ), 'and a public custom type too, but not a page-builder template' );

$job = ADVCM_Auto::flush();
wp_clear_scheduled_hook( 'advcm_auto_flush' );
$names = array();
foreach ( $job ? $job['options']['triggers'] : array() as $t ) { foreach ( $t['posts'] as $p ) { $names[] = $p['title'] . ' (' . $p['change'] . ')'; } }
$say( $job && in_array( 'Only in the subcategory (published)', $names, true ) && in_array( 'A page for any content (published)', $names, true ), 'the clear carries the posts that triggered it: ' . implode( ', ', $names ) );
$last = ADVCM_Auto::last();
$say( $job && isset( $last[ $any ], $last[ $analysis ] ) && $job['id'] === $last[ $any ]['job'], 'and each rule\'s last clear points at it' );
PHP
wpeval "${WORK}/auto-more.php"

history="$(curl -s -b "${jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=history")"
if grep -q "When any content is published" <<< "${history}" && grep -q '>Only in the subcategory</a>' <<< "${history}" && grep -q '>A page for any content</a>' <<< "${history}" && grep -q "from the block editor" <<< "${history}" && grep -q "triggered by 7 posts" <<< "${history}"; then
	pass "History names each rule and the posts that triggered it, linked to their edit screens"
else
	fail "History does not show what triggered the auto-clears: $(grep -oE 'advcm-triggers.{0,300}' <<< "${history}" | head -2)"
fi
auto_tab="$(curl -s -b "${admin_jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=auto")"
if grep -q "<th>Last clear</th>" <<< "${auto_tab}" && grep -qE "UTC</a><br>[0-9]+ posts? — done" <<< "${auto_tab}" && grep -q "or under it" <<< "${auto_tab}"; then
	pass "the rules table shows each rule's last clear"
else
	fail "the rules table has no last clear: $(grep -oE 'Last clear.{0,200}' <<< "${auto_tab}" | head -1)"
fi

# The pause, through the same form: an editor is refused, an administrator pauses, a publish
# schedules nothing, and the tab says so.
ed_code="$(curl -s -b "${jar}" -o /dev/null -w '%{http_code}' -d "action=advcm_rules&op=pause&_wpnonce=${rules_nonce}" "${URL}/wp-admin/admin-post.php")"
paused="$(wpcli wp option get advcm_rules_paused 2>/dev/null || true)"
[[ "${ed_code}" == "403" && ! "${paused}" =~ ^[0-9]+$ ]] && pass "an editor cannot pause auto-clear (HTTP ${ed_code})" || fail "an editor pausing answered HTTP ${ed_code}, paused '${paused}'"
curl -s -b "${admin_jar}" -o /dev/null -d "action=advcm_rules&op=pause&_wpnonce=${rules_nonce}" "${URL}/wp-admin/admin-post.php"
cat > "${WORK}/auto-paused.php" <<'PHP'
<?php
$say = function ( $ok, $label ) { echo ( $ok ? 'PASS  ' : 'FAIL  ' ), $label, "\n"; };
$say( ADVCM_Auto::paused(), 'an administrator pauses auto-clear through the screen' );
$cat = get_term_by( 'slug', 'analysis', 'category' );
wp_insert_post( array( 'post_title' => 'published while paused', 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ) );
wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'a page while paused', 'post_status' => 'publish' ) );
global $wpdb;
$marks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'advcm\\_auto\\_%'" );
$say( false === wp_next_scheduled( 'advcm_auto_flush' ) && 0 === $marks, 'while paused, a publish schedules nothing and marks nothing (' . $marks . ' marks)' );
$post = get_post( wp_insert_post( array( 'post_title' => 'timed, paused', 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ) ) );
$t = microtime( true );
for ( $i = 0; $i < 1000; $i++ ) { ADVCM_Auto::saved( $post->ID, $post, true, $post ); }
printf( "PASS  while paused the hook costs %.4f ms a save\n", ( microtime( true ) - $t ) * 1000 / 1000 );
PHP
wpeval "${WORK}/auto-paused.php"
auto_tab="$(curl -s -b "${admin_jar}" "${URL}/wp-admin/tools.php?page=advcm-cache&tab=auto")"
if grep -q "Auto-clear is paused — no rule runs." <<< "${auto_tab}" && grep -q 'value="Resume auto-clear"' <<< "${auto_tab}"; then
	pass "the tab says auto-clear is paused, with the button to resume it"
else
	fail "the tab does not say auto-clear is paused"
fi
curl -s -b "${admin_jar}" -o /dev/null -d "action=advcm_rules&op=resume&_wpnonce=${rules_nonce}" "${URL}/wp-admin/admin-post.php"
paused="$(wpcli wp option get advcm_rules_paused 2>/dev/null || true)"
[[ ! "${paused}" =~ ^[0-9]+$ ]] && pass "and an administrator resumes it" || fail "resuming left it paused: '${paused}'"

# A fault in the hook: the rules option made to throw when read. The save must still succeed, and the
# fault be recorded by the guard. A save in the web server would log one expected line; the log check
# at the end leaves it out.
docker exec -i -u 33 "${WP}" bash -c "cat > /var/www/html/wp-content/mu-plugins/advcm-smoke-fault.php" <<'MU'
<?php
add_filter( 'option_advcm_rules', function () { throw new RuntimeException( 'smoke fault in the rules' ); } );
MU
cat > "${WORK}/auto-fault.php" <<'PHP'
<?php
$cat = get_term_by( 'slug', 'analysis', 'category' );
$id  = wp_insert_post( array( 'post_title' => 'saved through a fault', 'post_status' => 'publish', 'post_category' => array( $cat->term_id ) ), true );
echo ( ! is_wp_error( $id ) && 'publish' === get_post_status( $id ) ? 'PASS  ' : 'FAIL  ' ), "a post saves while the auto-clear hook throws\n";
// WP-CLI runs in its own container, so its error_log goes to its stderr; the guard's record of the
// fault is the option the screen reads.
$last = get_option( 'advcm_last_error' );
echo ( is_array( $last ) && 'wp_after_insert_post' === $last['where'] && false !== strpos( $last['what'], 'smoke fault in the rules' ) ? 'PASS  ' : 'FAIL  ' ), 'and the guard recorded the fault instead of passing it on: ', is_array( $last ) ? $last['where'] . ' — ' . $last['what'] : 'nothing', "\n";
PHP
wpeval "${WORK}/auto-fault.php"
docker exec "${WP}" rm -f /var/www/html/wp-content/mu-plugins/advcm-smoke-fault.php
check_pages "after auto-clear"

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

# Deprecations count too: production runs a newer PHP than either suite version, and a
# deprecation there is a fatal in the next one.
# Less the one line the auto-clear check caused on purpose.
grep -v "smoke fault in the rules" "${WORK}/errors.log" > "${WORK}/errors-unexpected.log" || true
if grep -iE "advcm|wordpress-cache-manager" "${WORK}/errors-unexpected.log" >/dev/null; then
	fail "the PHP error log names this plugin:"
	grep -iE "advcm|wordpress-cache-manager" "${WORK}/errors-unexpected.log" | head -10
else
	pass "nothing from this plugin in the PHP error log"
fi

out="$(wpcli wp plugin deactivate wordpress-cache-manager)"
grep -q "Success" <<< "${out}" && pass "deactivates" || fail "deactivates: ${out}"
check_pages "after deactivation"

FINISHED=1

if [[ "${FAILED}" -ne 0 ]]; then
	echo "SMOKE FAILED" >&2
	exit 1
fi

echo "smoke passed on PHP ${PHP}"
