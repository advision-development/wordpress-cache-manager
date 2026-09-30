<?php
/**
 * Updates from this plugin's own releases.
 *
 * This is the only class that hands WordPress a URL to download, unzip over the plugin
 * directory and run, so the assertions are mostly refusals: a package hosted anywhere but the
 * pinned repository, a tag that is not a version, a release with no zip, and a version that is
 * not newer than the one installed.
 *
 * The version comparison has its own section because the failure is silent in both directions:
 * an unpadded compare either hides an available update or offers a downgrade, and both look
 * like the updater simply not working.
 */

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * The bit of WP_Error this file needs.
 */
class WP_Error {

	public $message;

	public function __construct( $message = '' ) {
		$this->message = $message;
	}
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function plugin_basename( $file ) {
	return 'wordpress-cache-manager/wordpress-cache-manager.php';
}

function esc_url( $url ) {
	return $url;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function wp_nonce_url( $url, $action ) {
	return $url . '&_wpnonce=abc';
}

function esc_html__( $text, $domain = '' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function get_site_transient( $key ) {
	return isset( $GLOBALS['site_transients'][ $key ] ) ? $GLOBALS['site_transients'][ $key ] : false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['site_transients'][ $key ] = $value;

	return true;
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['site_transients'][ $key ] );

	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

/**
 * The scheduled event that performs unattended updates.
 *
 * Controllable because its absence is one of the things blockers() reports, and a stub that
 * always answered would make that assertion pass with the check deleted.
 *
 * @param string $hook Event name.
 * @return int|false
 */
function wp_next_scheduled( $hook ) {
	$planted = isset( $GLOBALS['scheduled'] ) ? $GLOBALS['scheduled'] : array();

	return array_key_exists( $hook, $planted ) ? $planted[ $hook ] : false;
}

$GLOBALS['scheduled']       = array( 'wp_maybe_auto_update' => 2000000000 );
$GLOBALS['filter_values']   = array();
$GLOBALS['site_transients'] = array();
$GLOBALS['hooks']           = array();

require __DIR__ . '/bootstrap.php';

define( 'ADVCM_FILE', ADVCM_DIR . 'wordpress-cache-manager.php' );

load_class( 'updater' );

/**
 * An API response with a given body.
 *
 * @param mixed $body JSON-encodable body, or a raw string.
 * @param int   $code HTTP status.
 * @return array
 */
function responded( $body, $code = 200 ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
	);
}

/**
 * A release body shaped like GitHub's.
 *
 * @param string $tag    Tag name.
 * @param array  $assets Assets.
 * @return array
 */
function release_body( $tag, array $assets ) {
	return array(
		'tag_name'     => $tag,
		'body'         => 'Release notes.',
		'published_at' => '2026-08-05T12:00:00Z',
		'assets'       => $assets,
	);
}

/**
 * An asset shaped like GitHub's.
 *
 * @param string $name Filename.
 * @param string $url  Download URL.
 * @return array
 */
function asset( $name, $url ) {
	return array( 'name' => $name, 'browser_download_url' => $url );
}

$good = 'https://github.com/advision-development/wordpress-cache-manager/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip';

// ------------------------------------------------------------------ the happy path

$release = ADVCM_Updater::parse( responded( release_body( 'v9.0.0', array( asset( 'wordpress-cache-manager-9.0.0.zip', $good ) ) ) ) );

check( 'a release is read', ! empty( $release ) );
check( 'and its version comes off the tag without the v', '9.0.0' === $release['version'], $release['version'] );
check( 'and the package is the asset URL', $good === $release['package'] );
check( 'and the notes come through', 'Release notes.' === $release['notes'] );

// ------------------------------------------------------- where a package may come from

// The pinning. Everything in the response is remote text, including the URL WordPress is about
// to download and unzip over the plugin directory.
$elsewhere = array(
	'a different host'                 => 'https://evil.test/advision-development/wordpress-cache-manager/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip',
	// Refused by the prefix rather than by a host comparison: a URL's authority ends at the
	// first slash, so a prefix reaching into the path pins the host. This case is the reason
	// that is true and worth keeping even though one check covers it.
	'a host that merely starts the same' => 'https://github.com.evil.test/advision-development/wordpress-cache-manager/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip',
	// The prefix pins the host but not the repository: HTTP clients resolve `..` out of a path
	// before sending it, so this one starts with the prefix and downloads from whatever
	// account the traversal lands on — still github.com, still a 200, not this plugin.
	'another repository reached by traversal' => 'https://github.com/advision-development/wordpress-cache-manager/releases/download/../../../../someone-else/their-repo/releases/download/v1/wordpress-cache-manager-9.0.0.zip',
	'a different owner'                => 'https://github.com/somebody-else/wordpress-cache-manager/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip',
	'a different repository'           => 'https://github.com/advision-development/something-else/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip',
	'plain http'                       => 'http://github.com/advision-development/wordpress-cache-manager/releases/download/v9.0.0/wordpress-cache-manager-9.0.0.zip',
);

foreach ( $elsewhere as $label => $url ) {
	check(
		'a package on ' . $label . ' is refused',
		'' === ADVCM_Updater::package_in( array( asset( 'wordpress-cache-manager-9.0.0.zip', $url ) ) ),
		'the URL is checked against the pinned repository, not trusted'
	);
}

// A release carrying several files must not have one of the others installed as the plugin.
check(
	'an asset that is not this plugin\'s zip is refused',
	'' === ADVCM_Updater::package_in(
		array( asset( 'notes.txt', 'https://github.com/advision-development/wordpress-cache-manager/releases/download/v9.0.0/notes.txt' ) )
	)
);

check(
	'and one named after another plugin is refused',
	'' === ADVCM_Updater::package_in(
		array( asset( 'wordpress-malware-quick-scan-9.0.0.zip', 'https://github.com/advision-development/wordpress-cache-manager/releases/download/v9.0.0/wordpress-malware-quick-scan-9.0.0.zip' ) )
	)
);

// The right asset is found even when it is not the first.
check(
	'the right asset is picked out of several',
	$good === ADVCM_Updater::package_in(
		array(
			asset( 'notes.txt', 'https://github.com/advision-development/wordpress-cache-manager/releases/download/v9.0.0/notes.txt' ),
			asset( 'wordpress-cache-manager-9.0.0.zip', $good ),
		)
	)
);

check( 'no assets is no package', '' === ADVCM_Updater::package_in( array() ) );
check( 'and a malformed asset list is no package', '' === ADVCM_Updater::package_in( 'not an array' ) );
check( 'and an asset with no URL is skipped', '' === ADVCM_Updater::package_in( array( array( 'name' => 'x.zip' ) ) ) );

// A release with no zip this plugin recognises offers nothing. Installing the tag's source
// archive instead would unzip a directory named after the tag beside the plugin rather than
// over it, leaving two copies installed.
check(
	'a release with no usable asset is not offered',
	array() === ADVCM_Updater::parse( responded( release_body( 'v9.0.0', array() ) ) ),
	'WordPress would otherwise install the source archive beside the plugin'
);

// ------------------------------------------------------------------- what a tag may be

check( 'a plain version tag is read', '1.2.3' === ADVCM_Updater::version_of( '1.2.3' ) );
check( 'a v-prefixed one too', '1.2.3' === ADVCM_Updater::version_of( 'v1.2.3' ) );
check( 'and whitespace is trimmed', '1.2.3' === ADVCM_Updater::version_of( "  v1.2.3\n" ) );

foreach ( array( 'main', 'v1.2.3-beta', 'release-1', '1.2.3.4', '', 'v', '1.2.x' ) as $tag ) {
	check(
		'the tag "' . $tag . '" is not a version',
		'' === ADVCM_Updater::version_of( $tag ),
		'a tag this plugin does not publish is not something to compare against'
	);
}

check( 'a tag that is not a version is not a release', array() === ADVCM_Updater::parse( responded( release_body( 'main', array( asset( 'wordpress-cache-manager-9.0.0.zip', $good ) ) ) ) ) );

// ----------------------------------------------------------------- the comparison

// version_compare( '1.2', '1.2.0' ) reports less-than, so an unpadded compare against a
// two-component header clears a site that has an update waiting.
check( '1.2 and 1.2.0 are the same version', ! ADVCM_Updater::is_newer( '1.2', '1.2.0' ) );
check( 'and neither is newer than the other', ! ADVCM_Updater::is_newer( '1.2.0', '1.2' ) );

// The other comparison people get wrong: as text, 0.10 sorts before 0.9.
check( '0.10.0 is newer than 0.9.0', ADVCM_Updater::is_newer( '0.10.0', '0.9.0' ) );
check( 'and 0.9.0 is not newer than 0.10.0', ! ADVCM_Updater::is_newer( '0.9.0', '0.10.0' ) );

check( '0.7.0 is newer than 0.6.0', ADVCM_Updater::is_newer( '0.7.0', '0.6.0' ) );
check( 'the same version is not newer', ! ADVCM_Updater::is_newer( '0.6.0', '0.6.0' ) );

// Never downgrade. A repository whose latest release is behind the installed copy — a release
// deleted, a tag moved — must not roll the site back.
check(
	'an older release is never offered',
	! ADVCM_Updater::is_newer( '0.5.0', '0.6.0' ),
	'a moved or deleted tag must not roll a site back'
);

check( 'a version that is not a version is never newer', ! ADVCM_Updater::is_newer( 'main', '0.6.0' ) );
check( 'and an unreadable installed version offers nothing', ! ADVCM_Updater::is_newer( '9.0.0', 'nonsense' ) );

check( 'padding fills to three components', '1.0.0' === ADVCM_Updater::normalize( '1' ) );
check( 'and leaves three alone', '1.2.3' === ADVCM_Updater::normalize( '1.2.3' ) );
check( 'and rejects what is not a version', '' === ADVCM_Updater::normalize( '1.2.3-rc1' ) );

// ------------------------------------------------------------- bad responses

check( 'a transport error is no release', array() === ADVCM_Updater::parse( new WP_Error( 'down' ) ) );
check( 'a 404 is no release', array() === ADVCM_Updater::parse( responded( release_body( 'v9.0.0', array() ), 404 ) ) );
check( 'a 403 is no release', array() === ADVCM_Updater::parse( responded( '', 403 ) ) );

// A rate-limited GitHub answers 403 with a JSON body that has no tag_name. It must not be read
// as "no update available" in a way that hides one, nor crash.
check( 'a rate-limit body is no release', array() === ADVCM_Updater::parse( responded( array( 'message' => 'API rate limit exceeded' ), 403 ) ) );

check( 'an empty body is no release', array() === ADVCM_Updater::parse( responded( '' ) ) );
check( 'HTML instead of JSON is no release', array() === ADVCM_Updater::parse( responded( '<html>nope</html>' ) ) );
check( 'a JSON array is no release', array() === ADVCM_Updater::parse( responded( array( 1, 2, 3 ) ) ) );
check( 'a body with no tag is no release', array() === ADVCM_Updater::parse( responded( array( 'assets' => array() ) ) ) );

// ------------------------------------------------------- the transient WordPress reads

$_transient           = new stdClass();
$_transient->response = array();
$_transient->no_update = array();

$GLOBALS['site_transients'] = array(
	'advcm_release' => array(
		'version'   => '9.9.9',
		'package'   => $good,
		'notes'     => '',
		'published' => '',
	),
);

$offered = ADVCM_Updater::offer( $_transient );
$file    = 'wordpress-cache-manager/wordpress-cache-manager.php';

check( 'a newer release lands in the transient', isset( $offered->response[ $file ] ) );
check( 'under this plugin\'s own file', $file === $offered->response[ $file ]->plugin );
check( 'with the version to install', '9.9.9' === $offered->response[ $file ]->new_version );
check( 'and the package to install it from', $good === $offered->response[ $file ]->package );

// WordPress reads no_update to decide whether the row offers automatic updates at all, so an
// up-to-date plugin has to say so rather than staying silent.
$GLOBALS['site_transients']['advcm_release']['version'] = '0.0.1';

$current = ADVCM_Updater::offer( $_transient );

check( 'an older release is not offered as an update', ! isset( $current->response[ $file ] ) );
check( 'and is reported as no update rather than omitted', isset( $current->no_update[ $file ] ) );

// Something else having filtered this into a different shape must be handed back untouched:
// building the object here would discard whatever that was.
check( 'a non-object transient is returned untouched', false === ADVCM_Updater::offer( false ) );
check( 'and so is a null one', null === ADVCM_Updater::offer( null ) );

// ------------------------------------------------------------- the details panel

$GLOBALS['site_transients']['advcm_release']['version'] = '9.9.9';

$info = ADVCM_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'wordpress-cache-manager' ) );

check( 'the details panel answers for this plugin', is_object( $info ) && '9.9.9' === $info->version );
check( 'and names the download', $good === $info->download_link );

// Answering another plugin's request would replace its panel with this one's.
check(
	'and not for another plugin',
	false === ADVCM_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ),
	'answering would replace that plugin\'s panel with this one\'s'
);

check( 'nor for another action', false === ADVCM_Updater::details( false, 'query_plugins', (object) array( 'slug' => 'wordpress-cache-manager' ) ) );
check( 'nor a request with no slug', false === ADVCM_Updater::details( false, 'plugin_information', new stdClass() ) );

// Release notes are remote text rendered inside wp-admin, so they are escaped like every other
// untrusted string this plugin prints.
$GLOBALS['site_transients']['advcm_release']['notes'] = '<script>alert(1)</script>';

$escaped = ADVCM_Updater::details( false, 'plugin_information', (object) array( 'slug' => 'wordpress-cache-manager' ) );

check(
	'release notes are escaped',
	false === strpos( $escaped->sections['changelog'], '<script>' ),
	'the modal is no more trustworthy a place to print raw remote markup than the report is'
);

// ----------------------------------------------------------------- the source itself

$source = file_get_contents( ADVCM_DIR . 'includes/class-updater.php' );
$code   = '';

foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

// Tokenised, because the docblock says why verification is never turned off and an assertion
// that cannot tell a mention from a call fails on the comment written to prevent the call.
check(
	'TLS verification is never turned off',
	false === strpos( str_replace( ' ', '', $code ), "'sslverify'=>false" ),
	'a plugin that would rather install something than nothing is a delivery mechanism'
);

check( 'and it is turned on explicitly', false !== strpos( str_replace( ' ', '', $code ), "'sslverify'=>true" ) );

check(
	'nothing is fetched over plain http',
	false === strpos( $code, "'http://" ) && false === strpos( $code, '"http://' ),
	'the package is code, and this is the request that finds it'
);

// A failure has to be cached, or a rate-limited site retries on every admin page load and one
// site being rate-limited becomes every site sharing that IP.
check(
	'failures are cached too',
	false !== strpos( $code, 'self::FAILURE_TTL' ),
	'GitHub allows 60 unauthenticated requests an hour per IP, shared across a host'
);

// The cache is a site transient, so uninstall has to delete it as one: on multisite the plain
// function looks in the wrong place and leaves the row behind.
check(
	'the cached answer is named in uninstall.php',
	false !== strpos( file_get_contents( ADVCM_DIR . 'uninstall.php' ), ADVCM_Updater::CACHE ),
	'this is the first thing this plugin stores, and uninstall.php said it stored nothing'
);

check(
	'and deleted as a site transient',
	false !== strpos( file_get_contents( ADVCM_DIR . 'uninstall.php' ), 'delete_site_transient' )
);

// ------------------------------------------------- never updates itself unattended

/*
 * The one risk the pinning cannot reduce: a release genuinely published from the pinned
 * repository by somebody who should not have been able to publish it is correctly hosted,
 * correctly named and every check in this file passes it.
 *
 * The answer used to be the plugin's own rule turned on itself — a person presses each
 * update. Across a fleet that inverts: updating 162 sites by hand per release is how the
 * project dies, and an out-of-date scanner reports green, which is worse than no scanner
 * because a green dashboard is read as an answer. The compensating control moves to where
 * publishing happens: 2FA on accounts that can publish, a protected environment on the
 * release workflow, required review before publishing.
 *
 * $file is the plugin's own entry, set where the transient is asserted above.
 */

check(
	'this plugin keeps itself current',
	true === ADVCM_Updater::automatically( false, (object) array( 'plugin' => $file ) ),
	'an out-of-date scanner reports green across a fleet, which is worse than no scanner'
);

check(
	'even when WordPress had not intended to',
	true === ADVCM_Updater::automatically( null, (object) array( 'plugin' => $file ) )
);

// The escape hatch, and it is code rather than a checkbox because the checkbox would not
// work — see the toggle assertion below.
$GLOBALS['filter_values']['advcm_auto_update'] = false;
check(
	'a site can refuse, in code',
	false === ADVCM_Updater::automatically( true, (object) array( 'plugin' => $file ) ),
	'a fleet-wide policy with no way out is a policy somebody edits the plugin to escape'
);
$GLOBALS['filter_values'] = array();

// Returning true for everything would quietly switch automatic updates on site-wide, which
// is a change to how the whole site is maintained and none of this plugin's business.
check(
	'another plugin keeps whatever WordPress decided',
	true === ADVCM_Updater::automatically( true, (object) array( 'plugin' => 'akismet/akismet.php' ) ),
	'returning true for everything would switch automatic updates on site-wide'
);

check( 'including when that was false', false === ADVCM_Updater::automatically( false, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
check( 'and a malformed item is left alone', true === ADVCM_Updater::automatically( true, 'not an object' ) );
check( 'as is one with no plugin file', true === ADVCM_Updater::automatically( true, new stdClass() ) );

/*
 * The toggle is replaced rather than left in place. `automatically()` answers the filter
 * unconditionally, so the checkbox WordPress would print could be switched off and change
 * nothing — a control that looks like it works and does not, which is the fault this plugin
 * exists to report rather than commit.
 */
check(
	'the replaced toggle says what is actually happening',
	false !== stripos( ADVCM_Updater::explain_auto_update( '<toggle />', $file ), 'install themselves' ),
	'a checkbox that cannot change the outcome is worse than a sentence'
);

check(
	'and another plugin keeps its own toggle',
	'<toggle />' === ADVCM_Updater::explain_auto_update( '<toggle />', 'akismet/akismet.php' )
);


// ------------------------------------------------------------- what the last check knows

// A plugin row showing no update cannot be told apart from a check that never ran, one that
// failed, and one that ran before the release existed. That is the same fault as a control that
// silently never initialises, so the screen has to say which.
$GLOBALS['site_transients'] = array();

$never = ADVCM_Updater::status();

check( 'with no check yet the state says so', 'never' === $never['state'], $never['state'] );
check( 'and the sentence says so too', false !== stripos( ADVCM_Updater::status_text(), 'has not checked' ) );

$GLOBALS['site_transients']['advcm_release'] = array( 'version' => '9.9.9', 'package' => $good, 'notes' => '', 'published' => '', 'checked' => 1750000000 );

$available = ADVCM_Updater::status();

check( 'a newer release reports available', 'available' === $available['state'], $available['state'] );
check( 'and names the version', '9.9.9' === $available['version'] );
check( 'and records when it was checked', 1750000000 === $available['checked'] );

// The sentence has to explain the delay, or somebody sees "0.27.1 is available" beside a row
// with no update link and concludes the updater is broken. WordPress refreshes its own list
// twice a day and that is the actual reason.
check(
	'the sentence explains why the row may not show it yet',
	false !== stripos( ADVCM_Updater::status_text(), 'Check again' ),
	'otherwise an available release beside a row with no update link reads as broken'
);

$GLOBALS['site_transients']['advcm_release']['version'] = '0.0.1';

check( 'an older release reports current', 'current' === ADVCM_Updater::status()['state'] );
check( 'and the sentence names the newest', false !== strpos( ADVCM_Updater::status_text(), '0.0.1' ) );

// A failure is remembered with its reason, so the row says what went wrong rather than nothing.
$GLOBALS['site_transients']['advcm_release'] = array( 'failed' => true, 'reason' => 'github.com refused the request', 'checked' => 1750000000 );

$failed = ADVCM_Updater::status();

check( 'a failed check reports failed', 'failed' === $failed['state'] );
check( 'and carries the reason', 'github.com refused the request' === $failed['reason'] );
check( 'and the sentence prints it', false !== strpos( ADVCM_Updater::status_text(), 'refused the request' ) );

// A remembered failure must not read as up to date. That is the whole point: "no update
// available" and "the check did not work" are different answers.
check(
	'a failed check does not read as up to date',
	false === stripos( ADVCM_Updater::status_text(), 'Up to date' ),
	'"no update available" and "the check did not work" are different answers'
);

// The cell somebody is already looking at carries the state and a way to re-check.
$cell = ADVCM_Updater::explain_auto_update( '<toggle />', $file );

check( 'the plugin row states the policy', false !== stripos( $cell, 'install themselves' ) );
check( 'and what the last check found', false !== stripos( $cell, 'did not succeed' ) );
check( 'and offers a re-check', false !== strpos( $cell, ADVCM_Updater::CHECK_ACTION ) );
check( 'with a nonce on it', false !== strpos( ADVCM_Updater::check_url(), '_wpnonce' ), 'it clears caches, so it is not a bare link' );

// Both caches, or the press changes nothing anybody can see: WordPress decides the row off its
// own update_plugins transient, which it refreshes twice a day.
$source = file_get_contents( ADVCM_DIR . 'includes/class-updater.php' );
$code   = '';

foreach ( token_get_all( $source ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}

	$code .= is_array( $token ) ? $token[1] : $token;
}

check(
	're-checking clears this plugin\'s cache and WordPress\'s own',
	false !== strpos( $code, "delete_site_transient( self::CACHE )" )
	&& false !== strpos( $code, "delete_site_transient( 'update_plugins' )" ),
	'clearing only one leaves a button that changes nothing anybody can see'
);

check(
	'and it is capability-checked and nonce-checked',
	false !== strpos( $code, "current_user_can( 'update_plugins' )" )
	&& false !== strpos( $code, 'check_admin_referer( self::CHECK_ACTION )' ),
	'it makes a network request and clears site-wide state'
);

// Each failure names its own cause. One cause printed for every reason a read can fail is the
// fault the quarantine row already paid for on a real site.
check( 'a transport failure is described as one', false !== stripos( ADVCM_Updater::status_text(), 'refused' ) );


// ------------------------------------------------- each failure names its own cause

// The sibling's quarantine row printed one cause for every reason a read could fail and sent
// somebody to look in the wrong place on a real site. The lesson written down from that is that
// an operator told the truth is unknown is better off than one given a confident wrong answer —
// so these have to differ from each other.
function wp_remote_get( $url, $args = array() ) {
	return $GLOBALS['next_response'];
}

/**
 * The reason a check stored for a given response.
 *
 * @param mixed $response What the request returns.
 * @return string
 */
function reason_for( $response ) {
	$GLOBALS['next_response']   = $response;
	$GLOBALS['site_transients'] = array();

	ADVCM_Updater::release();

	$stored = $GLOBALS['site_transients']['advcm_release'];

	return isset( $stored['reason'] ) ? $stored['reason'] : '';
}

$reasons = array(
	'unreachable' => reason_for( new WP_Error( 'timeout' ) ),
	'rate limit'  => reason_for( responded( array( 'message' => 'API rate limit exceeded' ), 403 ) ),
	'no release'  => reason_for( responded( array( 'message' => 'Not Found' ), 404 ) ),
	'server'      => reason_for( responded( '', 502 ) ),
	'unusable'    => reason_for( responded( array( 'tag_name' => 'main' ) ) ),
);

foreach ( $reasons as $label => $reason ) {
	check( 'a ' . $label . ' failure has a reason', '' !== $reason, $reason );
}

check(
	'a 404 says the repository may not be public, not only that there is no release',
	false !== strpos( $reasons['no release'], 'not public' ),
	$reasons['no release']
);

check(
	'and no two of them read the same',
	count( array_unique( array_values( $reasons ) ) ) === count( $reasons ),
	'one cause printed for every failure is how somebody gets sent to look in the wrong place'
);

// The one somebody will actually hit: a host whose other sites used up the hourly allowance.
check(
	'the rate-limit reason says it may be another site on the same address',
	false !== stripos( $reasons['rate limit'], 'same address' ),
	'otherwise it reads as this site being blocked'
);

// A failure is remembered rather than retried on every page load, and it records when.
check( 'a failure records when it happened', ! empty( $GLOBALS['site_transients']['advcm_release']['checked'] ) );

// Forcing a check ignores the cache. Without this the button would clear the cache and then
// read the value it just deleted, which happens to work — and would stop working the moment
// anything else populated it first.
$GLOBALS['next_response']   = responded( release_body( 'v9.9.9', array( asset( 'wordpress-cache-manager-9.9.9.zip', 'https://github.com/advision-development/wordpress-cache-manager/releases/download/v9.9.9/wordpress-cache-manager-9.9.9.zip' ) ) ) );
$GLOBALS['site_transients'] = array( 'advcm_release' => array( 'failed' => true, 'reason' => 'stale', 'checked' => 1 ) );

check( 'a normal read honours the cached failure', array() === ADVCM_Updater::release() );
check( 'and a forced one goes past it', '9.9.9' === ADVCM_Updater::release( true )['version'] );

// ------------------------------------------------- what stops this site taking an update
//
// automatically() answers WordPress's filter with true and that is the last word on nothing.
// It matters here for a reason of this plugin's own: the account rules an old copy knows are
// the rules it shipped with, so it answers those and stays silent about the rest — which
// reads exactly like a site with nothing wrong.


check( 'a site with nothing in the way reports nothing', array() === ADVCM_Updater::blockers(), implode( ',', ADVCM_Updater::blockers() ) );

$GLOBALS['filter_values']['automatic_updater_disabled'] = true;
check( "WordPress's own kill switch is read", in_array( 'automatic_updater_disabled_filter', ADVCM_Updater::blockers(), true ) );
$GLOBALS['filter_values'] = array();

$GLOBALS['filter_values']['advcm_auto_update'] = false;
check( "and so is this plugin's own", in_array( 'advcm_auto_update_filter', ADVCM_Updater::blockers(), true ) );
$GLOBALS['filter_values'] = array();

/*
 * The sibling's filter is not this plugin's. Reading the wrong name would make one plugin
 * report the other's escape hatch, and both are installed on every site in the fleet.
 */
$GLOBALS['filter_values']['wpmqs_auto_update'] = false;
check( "and the sibling's is not mistaken for it", array() === ADVCM_Updater::blockers(), implode( ',', ADVCM_Updater::blockers() ) );
$GLOBALS['filter_values'] = array();

/*
 * The inverse guarantee, and it is here because the gate it replaces was wrong.
 *
 * A sixth blocker reported `wp_maybe_auto_update` being absent from the cron array. The
 * reasoning read well — nothing attempts the update without it — and the test read well too,
 * which is the problem: both described the sentence rather than the site. It reads the cron
 * array from inside another cron event, and WordPress reschedules that event on `init`, so
 * the moment it looks is not representative.
 *
 * The first install to report it had updated itself to the newest release that same night,
 * through WordPress's own path, which cannot happen unless the event ran. It fired on every
 * install that could report it. And `apply_requested()` refuses on any blocker, so it would
 * have had the console's update button decline across the fleet and blame each site for it.
 *
 * This asserts it stays gone. Everything left in blockers() reads a constant or a filter.
 */
$GLOBALS['scheduled'] = array();
check(
	'an absent update event is not a blocker',
	array() === ADVCM_Updater::blockers(),
	'it fired on an install that had just updated itself, and it refused the update button'
);
$GLOBALS['scheduled'] = array( 'wp_maybe_auto_update' => 2000000000 );

check( 'and neither is a present one', array() === ADVCM_Updater::blockers() );

// More than one reason is more than one reason: reporting only the first would have somebody
// clear a constant and find the site still stuck.
$GLOBALS['filter_values']['automatic_updater_disabled'] = true;
$GLOBALS['filter_values']['advcm_auto_update']            = false;

check( 'every reason is named, not just the first', 2 === count( ADVCM_Updater::blockers() ), implode( ',', ADVCM_Updater::blockers() ) );

$GLOBALS['filter_values'] = array();

// Constants last: they cannot be undefined again, so every assertion above has to have run
// while they were absent.
define( 'DISALLOW_FILE_MODS', true );

check(
	'the constant the access scanner recommends for the file editors is read',
	in_array( 'DISALLOW_FILE_MODS', ADVCM_Updater::blockers(), true ),
	'a hardened site stops updating this plugin too, and has to be able to say so'
);

define( 'AUTOMATIC_UPDATER_DISABLED', true );

check( 'and so is the one managed hosts set', in_array( 'AUTOMATIC_UPDATER_DISABLED', ADVCM_Updater::blockers(), true ) );

// ------------------------------------------- what the console can and cannot ask for
//
// The console says which version it has seen published. It never says where to fetch from:
// this plugin pins downloads to one host, owner and repository, and a console able to name a
// location would be arbitrary code on 165 sites the moment it was compromised.

check( 'nothing asked is nothing done', 'idle' === ADVCM_Updater::apply_requested( '' ) );
check( 'and neither is a non-string', 'idle' === ADVCM_Updater::apply_requested( null ) );

// ADVCM_VERSION is 0.1.0 in this harness.
check( 'a version already installed is current', 'current' === ADVCM_Updater::apply_requested( '0.1.0' ) );
check( 'an older one is not a downgrade', 'current' === ADVCM_Updater::apply_requested( '0.0.9' ) );
check(
	'and the padding lesson is not relearned here',
	'current' === ADVCM_Updater::apply_requested( '0.1.0' ),
	'an unpadded compare reads 0.1 and 0.1.0 as different versions'
);

/*
 * The refusal the design rests on. A site that sets AUTOMATIC_UPDATER_DISABLED has said it
 * does not take unattended updates, and a remote button that overrode it would turn a
 * hardening constant into a decoration — the class of fault this plugin exists to report.
 * The press is attended at the console and unattended here, and the constant means the second.
 *
 * DISALLOW_FILE_MODS is defined above, so every blocker path is live by this point.
 */
check(
	'a site that refuses unattended updates is not overridden',
	'blocked' === ADVCM_Updater::apply_requested( '9.9.9' ),
	'it stays behind and blockers() says why'
);

finish();
