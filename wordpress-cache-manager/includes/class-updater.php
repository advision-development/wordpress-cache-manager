<?php
/**
 * Updates from this plugin's own GitHub releases.
 *
 * Copied from WordPress Access Quick Scan's updater, where every rule below was paid for; the
 * scanner-specific parts (its findings, and the reasoning about an out-of-date scanner reporting
 * green) are gone, the pinning and the version comparison are unchanged.
 *
 * The plugin does not live on wordpress.org, so WordPress has nowhere to ask whether a newer
 * version exists and the Plugins screen shows no update however many releases are published.
 * This tells it where to ask.
 *
 * **This is the most dangerous thing in the plugin, by a distance.** Every other class reads.
 * This one hands WordPress a URL and WordPress downloads it, unzips it over the plugin
 * directory and runs it on the next request. A wrong answer here is arbitrary PHP on the site,
 * on every site in the fleet at once. So:
 *
 * - **The download URL is checked against a pinned host, owner and repository**, not taken from
 *   the response. The response is JSON from a remote server; if it is tampered with or the
 *   repository moves, the answer is to install nothing rather than to install from wherever the
 *   JSON points. The scanners apply the same rule to fetched detection rules, and code deserves
 *   it more than rules do.
 * - **TLS verification is never turned off.** Not as a fallback, not behind a filter. A plugin
 *   that would rather install something than nothing is a delivery mechanism.
 * - **A version is only ever offered upwards.** `version_compare( '0.9', '0.10' )` is not the
 *   comparison people expect and `version_compare( '1.2', '1.2.0' )` reports less-than, so both
 *   sides are padded to three components first. Without that a release can read as older than
 *   the copy installed and the update never appears, or worse, a downgrade does.
 *
 * **Failures are cached too.** GitHub allows 60 unauthenticated requests an hour per IP, and a
 * hosting provider's sites share one. Retrying on every admin page load is how one site being
 * rate-limited becomes every site on that host being rate-limited.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teaches WordPress where this plugin's releases are.
 */
class ADVCM_Updater {

	/** The only host a package may be downloaded from. */
	const HOST = 'github.com';

	/** The only account whose releases are this plugin's. */
	const OWNER = 'advision-development';

	/** The repository, which is also the plugin's directory name. */
	const REPO = 'wordpress-cache-manager';

	/** Where the answer is cached. */
	const CACHE = 'advcm_release';

	/** The endpoint that re-checks on request. */
	const CHECK_ACTION = 'advcm_check_release';

	/** How long a successful answer is trusted. */
	const CACHE_TTL = 43200;

	/**
	 * How long a failure is remembered.
	 *
	 * Shorter than a success so a transient outage does not hide an update for half a day, and
	 * long enough that a rate-limited site is not what keeps it rate-limited.
	 */
	const FAILURE_TTL = 3600;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'offer' ) );
		ADVCM_Safe::filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		ADVCM_Safe::action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 2 );

		// Applied unattended; see automatically() for why, what that trusts, and the way out.
		ADVCM_Safe::filter( 'auto_update_plugin', array( __CLASS__, 'automatically' ), 10, 2 );
		ADVCM_Safe::filter( 'plugin_auto_update_setting_html', array( __CLASS__, 'explain_auto_update' ), 10, 2 );
		ADVCM_Safe::action( 'admin_post_' . self::CHECK_ACTION, array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * Install releases unattended, unless the site says otherwise.
	 *
	 * **This was documented as the opposite, and it was wrong.** The comment copied from WordPress
	 * Access Quick Scan said this refused unattended updates while the code below answered `true`;
	 * a security review on 2026-10-01 read both. The code is what the fleet needs: a cache plugin
	 * that purges in an old order, or skips a layer it has since learned to clear, is wrong on
	 * every site at once, and nobody presses Update on 160 sites. So releases install themselves,
	 * and this says plainly what that trusts.
	 *
	 * It trusts **whoever can publish a release** of the pinned repository. Every other check in
	 * this file assumes the danger is a *tampered answer* — a URL pointing somewhere else, a
	 * response that is not GitHub's — and none of them helps if a release is genuinely published by
	 * somebody who should not have been able to. What stands in that way lives outside this file:
	 *
	 * - the release workflow builds only a tag whose commit is already on main, so code must have
	 *   been merged through a pull request before any site can receive it;
	 * - its actions are pinned to commits, the build runs read-only, and only a separate job holding
	 *   no third-party code can publish;
	 * - each release publishes per-file checksums, so an installed copy can be compared with it.
	 *
	 * Not yet in place, and the owner's decision because they are repository settings: a ruleset
	 * that lets only maintainers create `v*` tags, and a protected environment requiring a review
	 * before the publish job runs. With those, a stolen token alone cannot ship to the fleet.
	 *
	 * A site that must not take unattended updates returns false from `advcm_auto_update` in its
	 * own mu-plugin. It is code rather than a checkbox on purpose — see `explain_auto_update()`.
	 *
	 * @param bool|null $update Whether WordPress intends to update it.
	 * @param mixed     $item   The plugin being considered.
	 * @return bool|null
	 */
	public static function automatically( $update, $item ) {
		$file = self::basename();

		if ( is_object( $item ) && isset( $item->plugin ) && $file === $item->plugin ) {
			/**
			 * Filter whether this plugin updates itself unattended. Return false to opt out.
			 *
			 * @param bool $auto Whether to update unattended.
			 */
			return (bool) apply_filters( 'advcm_auto_update', true );
		}

		// Not ours. Handing back what arrived leaves every other plugin's setting alone —
		// returning true here would quietly switch automatic updates on site-wide.
		return $update;
	}

	/**
	 * Why this plugin cannot update itself on this site, as reason codes.
	 *
	 * `automatically()` above answers WordPress's filter with `true`, and that answer is the
	 * last word on nothing: WordPress asks several other questions first, and a site can
	 * refuse every unattended update without anything here knowing. Measured on a 165-site
	 * fleet, eighteen sites sat between one and three releases behind on WordPress Malware Quick Scan
	 * while reporting on time every day — cron plainly alive, and the console able to say
	 * they were behind and not one word about why.
	 *
	 * It matters here for a reason of this plugin's own: the purge order and the adapters are
	 * the plugin. An old copy purges the layers it knew about, in the order it knew, and reports
	 * success for a site whose stack has since changed under it.
	 *
	 * Only gates that can be read locally and answered certainly, each a real refusal in
	 * `WP_Automatic_Updater` rather than a guess at one:
	 *
	 * - `AUTOMATIC_UPDATER_DISABLED` and the `automatic_updater_disabled` filter turn every
	 *   unattended update off. Managed hosts set the constant.
	 * - `DISALLOW_FILE_MODS` blocks installs and updates outright — and it is the constant
	 *   WordPress Access Quick Scan recommends, so a hardened site never updates this either.
	 * - This plugin's own `advcm_auto_update` filter, because the escape hatch being used is
	 *   an answer rather than a fault.
	 * - A version-control checkout at the plugin directory or the install root, which
	 *   WordPress refuses to update over.
	 *
	 * **`wp_maybe_auto_update` is deliberately not one of them, and that is a correction.**
	 * A sixth gate reported the scheduled event being absent, reasoning that nothing would
	 * attempt the update without it. True as a sentence and false as a test: it reads the cron
	 * array from inside another cron event, and WordPress reschedules that event on `init`, so
	 * the moment this looks is not representative. The first site to report it had updated
	 * itself to the newest release that same night through WordPress's own path, which cannot
	 * happen unless the event ran — and it fired on every install that could report it.
	 *
	 * Worse than noise. `apply_requested()` refuses on any blocker, so a false one here would
	 * have had the console's update button decline across the whole fleet and blame each
	 * site's own configuration for it. Everything left reads a constant or a filter: facts
	 * that say the same thing whenever they are asked.
	 *
	 * **Deliberately not a verdict on whether an update would succeed.** Filesystem
	 * credentials, disk space and a package that fails to unzip are all real ways for this to
	 * fail and none can be established without attempting it. This answers the narrower
	 * question it can answer honestly: whether the site will even try.
	 *
	 * @return array Reason codes, empty when nothing here stands in the way.
	 */
	public static function blockers() {
		$found = array();

		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$found[] = 'AUTOMATIC_UPDATER_DISABLED';
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			$found[] = 'DISALLOW_FILE_MODS';
		}

		if ( function_exists( 'apply_filters' ) ) {
			if ( apply_filters( 'automatic_updater_disabled', false ) ) {
				$found[] = 'automatic_updater_disabled_filter';
			}

			// Read rather than re-running automatically(): the question is what the site
			// answered, and the site answers through this filter.
			if ( ! apply_filters( 'advcm_auto_update', true ) ) {
				$found[] = 'advcm_auto_update_filter';
			}
		}

		if ( self::under_version_control() ) {
			$found[] = 'version_control_checkout';
		}

		return $found;
	}

	/**
	 * Whether a checkout sits over the plugin or the install root.
	 *
	 * The same four directories WordPress looks for, asked of the two paths that matter here
	 * rather than of every parent. `is_vcs_checkout()` walks upwards and will find a
	 * repository the site is merely deployed inside, which is not the same claim.
	 *
	 * @return bool
	 */
	private static function under_version_control() {
		$roots = array();

		if ( defined( 'ADVCM_DIR' ) ) {
			$roots[] = ADVCM_DIR;
		}

		if ( defined( 'ABSPATH' ) ) {
			$roots[] = ABSPATH;
		}

		foreach ( $roots as $root ) {
			foreach ( array( '.git', '.svn', '.hg', '.bzr' ) as $dir ) {
				if ( is_dir( rtrim( (string) $root, "/\\" ) . '/' . $dir ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Bring this plugin up to a version the console has asked for.
	 *
	 * The console says which version it has seen published. This decides whether that is
	 * newer than what is installed and, if so, runs the same upgrade WordPress would have run
	 * on its own schedule — from `offer()`, which is pinned, so the package still comes from
	 * the one repository this plugin will accept and from nowhere else.
	 *
	 * **It refuses on every blocker `blockers()` reports, and that is deliberate.** A site
	 * that sets `AUTOMATIC_UPDATER_DISABLED` or `DISALLOW_FILE_MODS` has said it does not take
	 * unattended updates, and a remote button that overrode it would turn a hardening
	 * constant into a decoration — which is precisely the class of fault this plugin exists to
	 * report. The press is attended at the console and unattended here; those are not the
	 * same thing, and the constant means the second. The site stays behind, `blockers()` says
	 * why, and somebody can make that call with the reason in front of them.
	 *
	 * Returns a word rather than a boolean because four outcomes are worth telling apart when
	 * this is read back out of a log: nothing was asked, it was already current, something
	 * stood in the way, or it ran.
	 *
	 * @param string $version The version the console asked for.
	 * @return string One of `idle`, `current`, `blocked`, `updated`, `failed`.
	 */
	public static function apply_requested( $version ) {
		if ( ! is_string( $version ) || '' === $version ) {
			return 'idle';
		}

		// Upwards only, through the one comparison this file already owns — a second
		// version_compare here is the padding lesson learned twice and applied once.
		if ( ! self::is_newer( $version, ADVCM_VERSION ) ) {
			return 'current';
		}

		if ( ! empty( self::blockers() ) ) {
			return 'blocked';
		}

		/*
		 * Loaded defensively, because this runs hourly on every site in the fleet and a bare
		 * `require_once` on a path that is not there is a fatal error rather than a failed
		 * update. WordPress ships both of these, so their absence means something is already
		 * wrong with the install — and the honest response to that is to decline the update,
		 * not to take the request down with it.
		 */
		foreach ( array( 'update.php', 'class-wp-upgrader.php' ) as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return 'failed';
		}

		// Ask GitHub again rather than trusting the cache: the console has just said a newer
		// release exists, and a cached "no update" from before it was published is exactly
		// the state this button is for.
		self::release( true );
		delete_site_transient( 'update_plugins' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( self::basename() );

		// This plugin and nothing else. WordPress's own unattended pass updates everything
		// due at once, and a button that quietly upgraded every plugin on 165 sites would be
		// a far larger thing than the one somebody pressed.
		return ( true === $result ) ? 'updated' : 'failed';
	}

	/**
	 * Say why the auto-update toggle is not there.
	 *
	 * @param string $html   The markup WordPress was going to print.
	 * @param string $plugin Plugin file being rendered.
	 * @return string
	 */
	public static function explain_auto_update( $html, $plugin ) {
		if ( self::basename() !== $plugin ) {
			return $html;
		}

		// The state of the last check goes here rather than only the policy. A row showing no
		// update cannot be told apart from a check that never ran or one that failed, and this
		// cell is where somebody wondering is already looking.
		return '<span class="description">'
			. esc_html__( 'Updates install themselves. The purge order and the adapters live in the plugin, so an old copy purges an old idea of the site — this one keeps itself current.', 'advcm' )
			. '<br />' . esc_html( self::status_text() )
			. '<br /><a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for a new release now', 'advcm' ) . '</a>'
			. '</span>';
	}

	/**
	 * This plugin's entry in the plugin list, e.g. `wordpress-cache-manager/…php`.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( ADVCM_FILE );
	}

	/**
	 * Add this plugin to what WordPress believes has an update.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public static function offer( $transient ) {
		if ( ! is_object( $transient ) ) {
			// Something else has filtered this into a shape WordPress does not use. Handing it
			// back untouched is the only safe move: building the object here would discard
			// whatever that was.
			return $transient;
		}

		$release = self::release();

		if ( empty( $release ) ) {
			return $transient;
		}

		$file = self::basename();

		if ( ! self::is_newer( $release['version'], ADVCM_VERSION ) ) {
			// No update. WordPress reads `no_update` to decide whether the row offers automatic
			// updates at all, so an up-to-date plugin says so rather than staying silent.
			if ( isset( $transient->response[ $file ] ) ) {
				unset( $transient->response[ $file ] );
			}

			$transient->no_update[ $file ] = self::entry( $release );

			return $transient;
		}

		$transient->response[ $file ] = self::entry( $release );

		return $transient;
	}

	/**
	 * The object WordPress expects per plugin.
	 *
	 * @param array $release Result of release().
	 * @return object
	 */
	private static function entry( array $release ) {
		return (object) array(
			'id'            => self::OWNER . '/' . self::REPO,
			'slug'          => ADVCM_SLUG,
			'plugin'        => self::basename(),
			'new_version'   => $release['version'],
			'url'           => 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO,
			'package'       => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'tested'        => '',
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * The "View details" panel, which would otherwise ask wordpress.org.
	 *
	 * Without this, the link WordPress prints beside an available update opens a modal that
	 * reports the plugin does not exist — a control that looks like it works and does not.
	 *
	 * @param mixed  $result The value being filtered.
	 * @param string $action Which plugins_api action was asked for.
	 * @param mixed  $args   Its arguments.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || ADVCM_SLUG !== $args->slug ) {
			// Another plugin's request. Answering it would replace its panel with this one's.
			return $result;
		}

		$release = self::release();

		if ( empty( $release ) ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Advision Cache Management',
			'slug'          => ADVCM_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://advisiondevelopment.com/">Advision Development</a>',
			'homepage'      => 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO,
			'download_link' => $release['package'],
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'sections'      => array(
				// The release notes as GitHub returned them. Escaped, because this is remote
				// text rendered inside wp-admin: the same rule the report screen follows for
				// evidence, and the modal is no more trustworthy a place to print raw markup.
				'changelog' => '<pre>' . esc_html( $release['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * Throw the cached answer away after an update runs.
	 *
	 * Without this the site carries a cached "newer version available" for up to twelve hours
	 * after installing it, and the row keeps offering an update that is already applied.
	 *
	 * @param object $upgrader The upgrader instance.
	 * @param array  $extra    What it did.
	 * @return void
	 */
	public static function forget( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || ! isset( $extra['type'] ) || 'plugin' !== $extra['type'] ) {
			return;
		}

		delete_site_transient( self::CACHE );
	}

	/**
	 * The latest release, or an empty array when there is not one to be had.
	 *
	 * @return array array( version, package, notes, published ) or array()
	 */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );

		if ( ! $force && is_array( $cached ) ) {
			// A remembered failure is stored with its reason, which is also what this returns
			// nothing for — so a rate-limited site stops asking rather than asking harder, and
			// status() can still say what went wrong.
			return isset( $cached['version'] ) ? $cached : array();
		}

		$response = wp_remote_get(
			'https://api.' . self::HOST . '/repos/' . self::OWNER . '/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				// Never relaxed. A plugin that would rather install something than nothing is a
				// delivery mechanism, and this one downloads code.
				'sslverify' => true,
				'headers' => array(
					'Accept' => 'application/vnd.github+json',
					// GitHub rejects requests without one.
					'User-Agent' => self::REPO . '/' . ADVCM_VERSION,
				),
			)
		);

		$release = self::parse( $response );

		if ( empty( $release ) ) {
			// Why it failed, not merely that it did. The screen prints this: a check that
			// silently found nothing is indistinguishable from one that never ran, which is the
			// question somebody staring at a plugin row with no update actually has.
			$stored = array(
				'failed'  => true,
				'reason'  => self::failure_reason( $response ),
				'checked' => time(),
			);
		} else {
			$stored            = $release;
			$stored['checked'] = time();
		}

		set_site_transient(
			self::CACHE,
			$stored,
			empty( $release ) ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $release;
	}

	/**
	 * Where pressing "check now" goes.
	 *
	 * @return string
	 */
	public static function check_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::CHECK_ACTION ), self::CHECK_ACTION );
	}

	/**
	 * Re-check on request, then go back to where the press came from.
	 *
	 * Two caches sit between a published release and a row on the Plugins screen: this
	 * plugin's, and WordPress's own `update_plugins`, which it refreshes twice a day. Clearing
	 * only the first leaves somebody pressing a button that changes nothing they can see, so
	 * both go.
	 *
	 * @return void
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for plugin updates on this site.', 'advcm' ) );
		}

		check_admin_referer( self::CHECK_ACTION );

		delete_site_transient( self::CACHE );
		self::release( true );

		// WordPress's own list, or the row keeps showing what it decided this morning.
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Why a check did not produce a release.
	 *
	 * Named rather than guessed at. WordPress Malware Quick Scan's quarantine row printed one
	 * cause for every reason a read could fail and sent somebody to look in the wrong place; the lesson
	 * written down from that is that an operator told the truth is unknown is better off than
	 * one told a confident wrong answer.
	 *
	 * @param mixed $response Result of wp_remote_get().
	 * @return string
	 */
	private static function failure_reason( $response ) {
		if ( is_wp_error( $response ) ) {
			return __( 'the site could not reach github.com', 'advcm' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 403 === $code || 429 === $code ) {
			// GitHub allows 60 unauthenticated requests an hour per IP, and a hosting
			// provider's sites share one.
			return __( 'github.com refused the request, which on shared hosting is usually its hourly limit being reached by other sites on the same address', 'advcm' );
		}

		// GitHub answers 404 for a repository it will not show an anonymous caller, not only for
		// one with no release. This repository was internal when it was created, and every check
		// read "no published release" while a release existed — so the sentence names both.
		if ( 404 === $code ) {
			return __( 'github.com has no published release to report, or the repository is not public', 'advcm' );
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'github.com answered with status %d', 'advcm' ), $code );
		}

		return __( 'the answer arrived but did not name a release this plugin would install', 'advcm' );
	}

	/**
	 * What the last check knows, for printing.
	 *
	 * Exists because a plugin row showing no update cannot be told apart from a check that
	 * never ran, one that failed, or one that ran before the release was published. That is the
	 * same fault as a control that silently never initialises: the screen has to say which.
	 *
	 * @return array array( state, version, reason, checked )
	 */
	public static function status() {
		$cached = get_site_transient( self::CACHE );

		if ( ! is_array( $cached ) ) {
			return array(
				'state'   => 'never',
				'version' => '',
				'reason'  => '',
				'checked' => 0,
			);
		}

		$checked = isset( $cached['checked'] ) ? (int) $cached['checked'] : 0;

		if ( ! isset( $cached['version'] ) ) {
			return array(
				'state'   => 'failed',
				'version' => '',
				'reason'  => isset( $cached['reason'] ) ? (string) $cached['reason'] : '',
				'checked' => $checked,
			);
		}

		return array(
			'state'   => self::is_newer( $cached['version'], ADVCM_VERSION ) ? 'available' : 'current',
			'version' => (string) $cached['version'],
			'reason'  => '',
			'checked' => $checked,
		);
	}

	/**
	 * One sentence describing the last check.
	 *
	 * @return string
	 */
	public static function status_text() {
		$status = self::status();

		switch ( $status['state'] ) {
			case 'never':
				return __( 'This site has not checked for a new release yet.', 'advcm' );
			case 'failed':
				return sprintf(
					/* translators: %s: why the check failed. */
					__( 'The last check for a new release did not succeed: %s.', 'advcm' ),
					$status['reason']
				);
			case 'available':
				return sprintf(
					/* translators: %s: version number. */
					__( 'Release %s is available. WordPress shows it on the Plugins screen once it next refreshes its own update list, which it does twice a day — pressing Check again on the Updates screen does it now.', 'advcm' ),
					$status['version']
				);
		}

		return sprintf(
			/* translators: %s: version number. */
			__( 'Up to date. The newest release is %s.', 'advcm' ),
			$status['version']
		);
	}

	/**
	 * Read a release out of the API response.
	 *
	 * Split out from the fetch so it can be tested against a real response body without a
	 * network, which is the only way the pinning below gets exercised.
	 *
	 * @param mixed $response Result of wp_remote_get().
	 * @return array
	 */
	public static function parse( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return array();
		}

		$version = self::version_of( $body['tag_name'] );

		if ( '' === $version ) {
			return array();
		}

		$package = self::package_in( isset( $body['assets'] ) ? $body['assets'] : array(), (string) $body['tag_name'] );

		if ( '' === $package ) {
			// A release with no zip this plugin recognises. Offering the update anyway would
			// have WordPress download the tag's source archive, whose top-level directory is
			// named after the tag rather than after the plugin — WordPress would install it
			// alongside the copy already there instead of replacing it.
			return array();
		}

		return array(
			'version'   => $version,
			'package'   => $package,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);
	}

	/**
	 * The version a tag names.
	 *
	 * @param string $tag Tag name, with or without the leading v.
	 * @return string Empty when the tag is not a version.
	 */
	public static function version_of( $tag ) {
		$tag = trim( (string) $tag );

		if ( 0 === strpos( $tag, 'v' ) ) {
			$tag = substr( $tag, 1 );
		}

		// Digits and dots only. A tag naming a branch, or carrying a suffix this plugin does
		// not publish, is not a version to compare against.
		if ( ! preg_match( '~^[0-9]+(\.[0-9]+){0,2}$~', $tag ) ) {
			return '';
		}

		return $tag;
	}

	/**
	 * The download URL of the one asset this plugin will install.
	 *
	 * The pinning lives here. Everything in the response is remote text, including the URL
	 * WordPress is about to download and unzip over the plugin directory — so it is checked
	 * against the host, owner and repository compiled into this file rather than trusted.
	 *
	 * @param array       $assets The release's assets.
	 * @param string|null $tag    The release's tag, which the URL must carry.
	 * @return string Empty when none of them qualifies.
	 */
	public static function package_in( $assets, $tag = null ) {
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$prefix = 'https://' . self::HOST . '/' . self::OWNER . '/' . self::REPO . '/releases/download/';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
				continue;
			}

			$url = (string) $asset['browser_download_url'];

			// One check, and it is enough: a URL's authority ends at the first slash after the
			// scheme, so a prefix that reaches into the path pins the host exactly. A
			// lookalike like https://github.com.evil.test/advision-development/… does not
			// start with this string, and neither does http:// — the scheme is in the prefix
			// too.
			//
			// This was two checks. The second parsed the host and compared it, which read as
			// defence in depth and was unreachable: removing it failed no assertion, because
			// nothing that passes the prefix can have another host. Dead code justified by a
			// comment claiming otherwise is worse than either on its own — the next reader
			// would have believed the claim.
			if ( 0 !== strpos( $url, $prefix ) ) {
				continue;
			}

			// A prefix pins the host but not the repository, because HTTP clients resolve dot
			// segments out of a path before sending it — RFC 3986's remove_dot_segments. So
			// …/wordpress-cache-manager/releases/download/../../../../someone/their-repo/…
			// starts with the prefix and downloads from another account's release. A literal `..`
			// was refused here; a security review on 2026-10-01 showed `%2e%2e` gets through,
			// because WordPress's HTTP library decodes it before resolving. So the rest of the
			// URL must have exactly the shape this repository's releases have — a version tag and
			// the zip's name, digits and dots only — which leaves no room for a dot segment in
			// any spelling, a percent sign, a backslash, a query or a fragment.
			$rest = substr( $url, strlen( $prefix ) );

			if ( ! preg_match( '~^v?([0-9]+(?:\.[0-9]+){0,2})/' . preg_quote( self::REPO, '~' ) . '-[0-9]+(?:\.[0-9]+){0,2}\.zip$~', $rest, $m ) ) {
				continue;
			}

			// And the tag in the URL is the release's own tag, so an asset uploaded to one
			// release cannot be served as another's.
			if ( null !== $tag && self::version_of( $tag ) !== $m[1] ) {
				continue;
			}

			// The zip this plugin builds. A release carrying several files must not have one of
			// the others installed as the plugin.
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';

			if ( 0 !== strpos( $name, self::REPO . '-' ) || '.zip' !== substr( $name, -4 ) ) {
				continue;
			}

			return $url;
		}

		return '';
	}

	/**
	 * Whether one version is newer than another.
	 *
	 * Both sides padded to three components first. `version_compare( '1.2', '1.2.0' )` reports
	 * less-than, so an unpadded comparison against a two-component header clears a site that
	 * has an update waiting — the scanners have the same lesson written down about
	 * checking WordPress's own version.
	 *
	 * @param string $remote    The release's version.
	 * @param string $installed The version running.
	 * @return bool
	 */
	public static function is_newer( $remote, $installed ) {
		$remote    = self::normalize( $remote );
		$installed = self::normalize( $installed );

		if ( '' === $remote || '' === $installed ) {
			return false;
		}

		return version_compare( $remote, $installed, '>' );
	}

	/**
	 * A version padded to three numeric components.
	 *
	 * @param string $version Version string.
	 * @return string Empty when it is not a version.
	 */
	public static function normalize( $version ) {
		$version = trim( (string) $version );

		if ( ! preg_match( '~^[0-9]+(\.[0-9]+){0,2}$~', $version ) ) {
			return '';
		}

		$parts = explode( '.', $version );

		while ( count( $parts ) < 3 ) {
			$parts[] = '0';
		}

		return implode( '.', $parts );
	}
}
