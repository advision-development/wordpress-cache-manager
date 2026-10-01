<?php
/**
 * What a press does: validate, run, go back and show the result.
 *
 * One admin-post action for every button, behind the capability and a nonce. The request is
 * built here from the form and nothing else: the layers a job may touch are the registry's, the
 * URLs are filtered to this site's own, and the two options with a cost are dropped unless the
 * user holds the stronger capability.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles purge requests.
 */
final class ADVCM_Controller {

	/** The admin-post action. */
	const ACTION = 'advcm_purge';

	/** The admin-post action that runs a stuck background job now. */
	const RESUME = 'advcm_resume';

	/** When a site-wide clear last started. */
	const SITE_WIDE_AT = 'advcm_site_wide_at';

	/** The admin-ajax action the screen calls to move a due background job. Logged in only. */
	const TICK = 'advcm_tick';

	/**
	 * How long a site-wide job blocks another one.
	 *
	 * Two people pressing "purge site" a minute apart is two full invalidations of NitroPack and
	 * the host cache for nothing; the second is refused and pointed at the first.
	 */
	const SITE_WIDE_GAP = 120;

	/** How many per-URL clears one person may start in a minute. */
	const URL_JOBS_PER_MINUTE = 10;

	/** The most a URL list may weigh, in bytes. Fifty URLs of 2 KB each fit with room. */
	const MAX_INPUT = 110000;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( 'admin_post_' . self::ACTION, array( __CLASS__, 'guarded_handle' ) );
		ADVCM_Safe::action( 'admin_post_' . self::RESUME, array( __CLASS__, 'guarded_resume' ) );

		// wp_ajax_ only, never wp_ajax_nopriv_: nobody logged out can make this site do anything.
		ADVCM_Safe::action( 'wp_ajax_' . self::TICK, array( __CLASS__, 'handle_tick' ) );
	}

	/**
	 * Where a form posts.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * A link that purges one URL (the admin bar's "purge this page").
	 *
	 * @param string $url The page.
	 * @return string
	 */
	public static function purge_url_link( $url ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'scope'  => 'urls',
					'mode'   => ADVCM_Modes::recommended( 'urls' ),
					'urls'   => rawurlencode( $url ),
				),
				self::url()
			),
			self::ACTION
		);
	}

	/**
	 * A link that purges the site.
	 *
	 * @return string
	 */
	public static function purge_site_link() {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'scope'  => 'all',
					'mode'   => ADVCM_Modes::recommended( 'all' ),
				),
				self::url()
			),
			self::ACTION
		);
	}

	/**
	 * A press, guarded: anything that throws sends the person back to the screen with what went
	 * wrong, rather than leaving them on a blank admin-post.php.
	 *
	 * @return void
	 */
	public static function guarded_handle() {
		try {
			self::handle();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::ACTION, $e );
			self::back( '', __( 'The clear could not be started because of an error in this plugin. It has been written to the PHP error log; nothing on the site was changed by it.', 'advcm' ) );
		}
	}

	/**
	 * The resume button, guarded the same way.
	 *
	 * @return void
	 */
	public static function guarded_resume() {
		try {
			self::handle_resume();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::RESUME, $e );
			self::back( '', __( 'The job could not be continued because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Handle a press.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache on this site.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$request = self::request( wp_unslash( $_REQUEST ), current_user_can( ADVCM_Capabilities::HARD ) );

		if ( is_string( $request ) ) {
			self::back( '', $request );
		}

		$request['by']     = get_current_user_id();
		$request['source'] = isset( $_REQUEST['source'] ) && 'admin-bar' === $_REQUEST['source'] ? 'admin-bar' : 'wp-admin'; // phpcs:ignore

		$busy = self::busy( $request );

		if ( '' !== $busy ) {
			self::back( '', $busy );
		}

		$job = ADVCM_Runner::start( $request );

		self::back( $job['id'], '' );
	}

	/**
	 * Build a request from form input, or say why not.
	 *
	 * @param array $input Unslashed request input.
	 * @param bool  $hard  Whether the user holds the stronger capability.
	 * @return array|string The request, or a sentence explaining the refusal.
	 */
	public static function request( array $input, $hard ) {
		$scope = isset( $input['scope'] ) && 'urls' === $input['scope'] ? 'urls' : 'all';

		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : '';

		$request = array(
			'scope'   => $scope,
			// Only a mode this site can run; anything else is the recommended one for the scope.
			'mode'    => in_array( $mode, ADVCM_Modes::available(), true ) ? $mode : ADVCM_Modes::recommended( $scope ),
			'urls'    => array(),
			'refused' => array(),
			'layers'  => array(),
			'options' => array(),
		);

		if ( 'urls' === $scope ) {
			// Already decoded once by PHP. Decoding again would corrupt a URL whose query
			// carries an encoded character.
			$raw = isset( $input['urls'] ) ? (string) $input['urls'] : '';

			// Bounded before anything else reads it: the list is stored in the job, and the job is
			// rewritten after every step.
			if ( strlen( $raw ) > self::MAX_INPUT ) {
				return __( 'That list is too long. Up to 50 URLs at a time.', 'advcm' );
			}

			$parsed = ADVCM_Urls::parse( $raw, home_url() );

			if ( empty( $parsed['accepted'] ) ) {
				return empty( $parsed['refused'] )
					? __( 'No URL was given.', 'advcm' )
					: sprintf( __( 'None of those URLs are on this site: %s', 'advcm' ), implode( ', ', $parsed['refused'] ) );
			}

			$request['urls']    = $parsed['accepted'];
			$request['refused'] = $parsed['refused'];
		}

		// Choosing layers is for administrators only, and never sent by the screen. An editor who
		// could ask for builder CSS alone would delete the CSS files and leave every page cache
		// serving pages that link to them — the failure this plugin exists to prevent. Found by a
		// security review; the runner also adds back every layer that stores pages (plan()).
		if ( $hard && isset( $input['layers'] ) && is_array( $input['layers'] ) ) {
			$known = array();

			foreach ( ADVCM_Runner::adapters() as $adapter ) {
				$known[] = $adapter->id();
			}

			// Only ids the registry knows. An unknown one is not a layer to look for.
			$request['layers'] = array_values( array_intersect( array_map( 'strval', $input['layers'] ), $known ) );
		}

		// What a whole-site clear does to NitroPack is one choice, not two checkboxes: "leave it" and
		// "purge it" were separate boxes that could both be ticked, and the clear then left NitroPack
		// alone and dropped the purge without a word. Now exactly one of three arrives.
		//
		// - invalidate: the default, and what anything unrecognised becomes.
		// - leave: open to everyone who can clear, since it does less, not more. NitroPack is the
		//   one layer that may be left out: it serves pages with CSS it combined and hosts itself,
		//   so it is not left pointing at Elementor files a clear just deleted — which is why the
		//   page caches cannot be left out the same way.
		// - purge: administrators only; anyone else asking gets the default.
		if ( 'all' === $scope && isset( $input['nitropack'] ) ) {
			// A string or nothing: an array (two answers at once) is no answer, and casting one
			// would also raise a warning on PHP 8.
			$choice = is_string( $input['nitropack'] ) ? $input['nitropack'] : '';

			if ( 'leave' === $choice ) {
				$request['options']['except'] = array( 'nitropack' );
			} elseif ( 'purge' === $choice && $hard ) {
				$request['options']['nitropack_mode'] = 'purge';
			}
		}

		if ( $hard ) {
			if ( ! empty( $input['override_hold'] ) ) {
				$request['options']['override_hold'] = true;
			}
		}

		return $request;
	}

	/**
	 * Why a site-wide request must wait, or empty.
	 *
	 * @param array $request The request.
	 * @return string
	 */
	public static function busy( array $request ) {
		if ( 'all' !== $request['scope'] ) {
			return self::url_rate( get_current_user_id() );
		}

		foreach ( ADVCM_Jobs::all() as $job ) {
			if ( 'all' === $job['scope'] && 'running' === $job['state'] && ! ADVCM_Runner::stuck( $job ) ) {
				return __( 'A clear of the whole site is still running. Its progress is below.', 'advcm' );
			}
		}

		// The last site-wide start lives in its own row, not in the job history: the history is a
		// short buffer that a run of small clears can push a site-wide one out of, and the rate
		// limit with it. add_option() inserts only when the row is absent, so two presses at the
		// same moment cannot both claim a first start.
		if ( add_option( self::SITE_WIDE_AT, time(), '', false ) ) {
			return '';
		}

		if ( time() - (int) get_option( self::SITE_WIDE_AT, 0 ) < self::SITE_WIDE_GAP ) {
			return sprintf(
				/* translators: %d: seconds. */
				__( 'The whole site was cleared less than %d seconds ago. Its result is below.', 'advcm' ),
				self::SITE_WIDE_GAP
			);
		}

		update_option( self::SITE_WIDE_AT, time(), false );

		return '';
	}

	/**
	 * Why one person must wait before another per-URL clear, or empty.
	 *
	 * Each per-URL clear can purge fifty URLs at NitroPack and request a hundred cold pages, all
	 * while the person waits; a script repeating that is load on the site, not cache management.
	 *
	 * @param int $user User id.
	 * @return string
	 */
	private static function url_rate( $user ) {
		$key   = 'advcm_rate_' . (int) $user;
		$count = (int) get_transient( $key );

		if ( $count >= self::URL_JOBS_PER_MINUTE ) {
			return sprintf(
				/* translators: %d: how many. */
				__( 'That is %d page clears in a minute. Wait a moment, or clear the whole site instead.', 'advcm' ),
				self::URL_JOBS_PER_MINUTE
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return '';
	}

	/**
	 * Run a stuck background job now.
	 *
	 * @return void
	 */
	public static function handle_resume() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache on this site.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::RESUME );

		$id  = isset( $_POST['job'] ) ? sanitize_text_field( wp_unslash( $_POST['job'] ) ) : ''; // phpcs:ignore -- verified above.
		$job = ADVCM_Jobs::get( $id );

		if ( ! is_array( $job ) || ! ADVCM_Runner::stuck( $job ) ) {
			self::back( $id, __( 'That job is not stuck; it is either moving or finished.', 'advcm' ) );
		}

		ADVCM_Runner::resume_now( $id );

		self::back( $id, '' );
	}

	/**
	 * Move due background jobs, for the screen's own refresh.
	 *
	 * @return void
	 */
	public static function handle_tick() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) || ! check_ajax_referer( self::TICK, '_ajax_nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}

		try {
			$moved = ADVCM_Runner::tick();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'wp_ajax_' . self::TICK, $e );
			$moved = 0;
		}

		wp_send_json_success( array( 'moved' => $moved ) );
	}

	/**
	 * Back to the status screen, with the job or the refusal.
	 *
	 * @param string $job    Job id.
	 * @param string $notice Refusal.
	 * @return void
	 */
	private static function back( $job, $notice ) {
		$args = array( 'page' => ADVCM_Screen::SLUG );

		if ( '' !== $job ) {
			$args['advcm_job'] = $job;
		}

		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'tools.php' ) ) );
		exit;
	}
}
