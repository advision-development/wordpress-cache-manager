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

	/**
	 * How long a site-wide job blocks another one.
	 *
	 * Two people pressing "purge site" a minute apart is two full invalidations of NitroPack and
	 * the host cache for nothing; the second is refused and pointed at the first.
	 */
	const SITE_WIDE_GAP = 120;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
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
		return wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'scope' => 'all' ), self::url() ), self::ACTION );
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

		$request = array(
			'scope'   => $scope,
			'urls'    => array(),
			'refused' => array(),
			'layers'  => array(),
			'options' => array(),
		);

		if ( 'urls' === $scope ) {
			// Already decoded once by PHP. Decoding again would corrupt a URL whose query
			// carries an encoded character.
			$raw = isset( $input['urls'] ) ? (string) $input['urls'] : '';

			$parsed = ADVCM_Urls::parse( $raw, home_url() );

			if ( empty( $parsed['accepted'] ) ) {
				return empty( $parsed['refused'] )
					? __( 'No URL was given.', 'advcm' )
					: sprintf( __( 'None of those URLs are on this site: %s', 'advcm' ), implode( ', ', $parsed['refused'] ) );
			}

			$request['urls']    = $parsed['accepted'];
			$request['refused'] = $parsed['refused'];
		}

		if ( isset( $input['layers'] ) && is_array( $input['layers'] ) ) {
			$known = array();

			foreach ( ADVCM_Runner::adapters() as $adapter ) {
				$known[] = $adapter->id();
			}

			// Only ids the registry knows. An unknown one is not a layer to look for.
			$request['layers'] = array_values( array_intersect( array_map( 'strval', $input['layers'] ), $known ) );
		}

		if ( $hard ) {
			if ( isset( $input['nitropack_mode'] ) && 'purge' === $input['nitropack_mode'] ) {
				$request['options']['nitropack_mode'] = 'purge';
			}

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
	private static function busy( array $request ) {
		if ( 'all' !== $request['scope'] ) {
			return '';
		}

		foreach ( ADVCM_Jobs::all() as $job ) {
			if ( 'all' === $job['scope'] && ( time() - (int) $job['created'] ) < self::SITE_WIDE_GAP ) {
				return sprintf(
					/* translators: %d: seconds. */
					__( 'The whole site was cleared less than %d seconds ago. Its result is below.', 'advcm' ),
					self::SITE_WIDE_GAP
				);
			}
		}

		return '';
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
