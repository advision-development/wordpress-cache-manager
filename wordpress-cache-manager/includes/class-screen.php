<?php
/**
 * Tools → Adv Cache, and the admin bar menu.
 *
 * Every label says whose it is. A menu reading only "Cache" sits beside WP Rocket's, NitroPack's
 * and the host's own cache menus, and nobody can tell which one clears everything in order.
 *
 * The screen shows the plan before anybody presses anything: every layer this plugin knows, in
 * the order it would be cleared, and for each one whether it will run on this site or why not.
 * That is the preflight, and it is computed live on every view, so a plugin removed this morning
 * is already `not installed` here.
 *
 * Below it, the last jobs with every step's result. A press lands back here with its job open.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The status screen and admin bar.
 */
final class ADVCM_Screen {

	/** The page slug under Tools. */
	const SLUG = 'advcm-cache';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	/**
	 * The Tools entry. Same capability as the page and the handler.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'Advision Cache Management', 'advcm' ),
			__( 'Adv Cache', 'advcm' ),
			ADVCM_Capabilities::PURGE,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The admin bar menu: purge this page, purge the site, status.
	 *
	 * "Purge this page" appears on the front end only, where there is a page to name.
	 *
	 * @param WP_Admin_Bar $bar The bar.
	 * @return void
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_object( $bar ) || ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'advcm',
				'title' => __( 'Adv Cache', 'advcm' ),
				'href'  => admin_url( 'tools.php?page=' . self::SLUG ),
			)
		);

		if ( ! is_admin() ) {
			$here = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' ); // phpcs:ignore

			$bar->add_node(
				array(
					'parent' => 'advcm',
					'id'     => 'advcm-page',
					'title'  => __( 'Clear this page', 'advcm' ),
					'href'   => add_query_arg( 'source', 'admin-bar', ADVCM_Controller::purge_url_link( $here ) ),
				)
			);
		}

		$bar->add_node(
			array(
				'parent' => 'advcm',
				'id'     => 'advcm-site',
				'title'  => __( 'Clear the whole site', 'advcm' ),
				'href'   => add_query_arg( 'source', 'admin-bar', ADVCM_Controller::purge_site_link() ),
				'meta'   => array(
					'onclick' => 'return confirm(' . wp_json_encode( self::site_warning() ) . ');',
				),
			)
		);
	}

	/**
	 * What clearing the site costs, said before it happens.
	 *
	 * @return string
	 */
	private static function site_warning() {
		return __( 'Clear every cache layer on the whole site? Each page is rebuilt on its next visit, so the site is slower for a while. To refresh one page, use "Clear this page" on that page instead.', 'advcm' );
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache on this site.', 'advcm' ) );
		}

		$hard   = current_user_can( ADVCM_Capabilities::HARD );
		$open   = isset( $_GET['advcm_job'] ) ? sanitize_text_field( wp_unslash( $_GET['advcm_job'] ) ) : ''; // phpcs:ignore
		$notice = get_transient( 'advcm_notice_' . get_current_user_id() );

		if ( $notice ) {
			delete_transient( 'advcm_notice_' . get_current_user_id() );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Advision Cache Management', 'advcm' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
		}

		self::render_plan();
		self::render_forms( $hard );
		self::render_jobs( $open );

		echo '</div>';
	}

	/**
	 * The preflight for a site-wide clear, as a table.
	 *
	 * @return void
	 */
	private static function render_plan() {
		echo '<h2>' . esc_html__( 'What a full clear does here, in order', 'advcm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checked just now. Each layer is cleared only after the one beneath it, so none re-caches stale content from another. A layer that is not on this site is skipped and the rest still run.', 'advcm' ) . '</p>';

		$adapters = array();

		foreach ( ADVCM_Runner::adapters() as $adapter ) {
			$adapters[ $adapter->id() ] = $adapter;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Layer', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'On this site', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Last cleared', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'advcm' ) . '</th>';
		echo '</tr></thead><tbody>';

		$last = ADVCM_Jobs::layers();

		foreach ( ADVCM_Runner::plan( array( 'scope' => 'all' ) ) as $step ) {
			$will = 'run' === $step['action'];

			echo '<tr>';
			echo '<td>' . esc_html( $step['stage'] . ' · ' . ADVCM_Stages::label( $step['stage'] ) ) . '</td>';
			echo '<td>' . esc_html( $step['label'] ) . '</td>';
			echo '<td>' . ( $will ? '<strong>' . esc_html__( 'will be cleared', 'advcm' ) . '</strong>' : esc_html( __( 'skipped', 'advcm' ) . ' — ' . $step['message'] ) ) . '</td>';
			echo '<td>' . esc_html( self::last_line( isset( $last[ $step['id'] ] ) ? $last[ $step['id'] ] : null ) ) . '</td>';
			echo '<td>' . esc_html( self::info_line( isset( $adapters[ $step['id'] ] ) ? $adapters[ $step['id'] ] : null, $will ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * When a layer was last cleared, by whom, and how — from the per-layer summary, so it
	 * survives the job leaving the buffer.
	 *
	 * The last attempt and the last success are both shown when they differ: "failed today"
	 * alone would hide that it was cleared yesterday, and "cleared yesterday" alone would hide
	 * that today's attempt failed.
	 *
	 * @param array|null $last Summary entry.
	 * @return string
	 */
	public static function last_line( $last ) {
		if ( ! is_array( $last ) || empty( $last['at'] ) ) {
			return __( 'never, through this plugin', 'advcm' );
		}

		$user = ! empty( $last['by'] ) && function_exists( 'get_userdata' ) ? get_userdata( $last['by'] ) : false;
		$who  = $user ? $user->display_name : __( 'unknown', 'advcm' );
		$what = 'urls' === $last['scope'] ? __( 'some pages', 'advcm' ) : __( 'whole site', 'advcm' );
		$line = sprintf( '%s UTC — %s — %s — %s', gmdate( 'Y-m-d H:i', (int) $last['at'] ), $last['status'], $what, $who );

		if ( 'ok' !== $last['status'] ) {
			$line .= ' — ' . ( empty( $last['ok_at'] )
				? __( 'never cleared successfully', 'advcm' )
				: sprintf( __( 'last success %s UTC', 'advcm' ), gmdate( 'Y-m-d H:i', (int) $last['ok_at'] ) ) );
		}

		return $line;
	}

	/**
	 * A layer's facts on one line. Guarded like every other call into another plugin.
	 *
	 * @param ADVCM_Adapter|null $adapter Adapter.
	 * @param bool               $present Whether it was detected.
	 * @return string
	 */
	private static function info_line( $adapter, $present ) {
		if ( null === $adapter || ! $present ) {
			return '';
		}

		try {
			$info = $adapter->info();
		} catch ( Throwable $e ) {
			return 'could not read: ' . $e->getMessage();
		}

		$parts = array();

		foreach ( (array) $info as $key => $value ) {
			if ( '' !== (string) $value ) {
				$parts[] = $key . ': ' . $value;
			}
		}

		return implode( ' · ', $parts );
	}

	/**
	 * The two forms: clear URLs, clear the site.
	 *
	 * @param bool $hard Whether the stronger options are offered.
	 * @return void
	 */
	private static function render_forms( $hard ) {
		$action = esc_url( ADVCM_Controller::url() );

		echo '<h2>' . esc_html__( 'Clear specific pages', 'advcm' ) . '</h2>';
		echo '<form method="post" action="' . $action . '">'; // phpcs:ignore -- escaped above.
		wp_nonce_field( ADVCM_Controller::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Controller::ACTION ) . '" />';
		echo '<input type="hidden" name="scope" value="urls" />';
		echo '<p><label for="advcm-urls">' . esc_html( sprintf( __( 'One URL or path per line, on this site only (up to %d).', 'advcm' ), ADVCM_Urls::MAX ) ) . '</label></p>';
		echo '<textarea id="advcm-urls" name="urls" rows="5" class="large-text code" placeholder="/analysis/"></textarea>';
		submit_button( __( 'Clear these pages', 'advcm' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Clear the whole site', 'advcm' ) . '</h2>';
		echo '<form method="post" action="' . $action . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( self::site_warning() ) ) . ');">'; // phpcs:ignore -- escaped above.
		wp_nonce_field( ADVCM_Controller::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Controller::ACTION ) . '" />';
		echo '<input type="hidden" name="scope" value="all" />';

		if ( $hard ) {
			echo '<p><label><input type="checkbox" name="nitropack_mode" value="purge" /> ';
			echo esc_html__( 'Purge NitroPack instead of invalidating it. Every page is then served un-optimized until NitroPack rebuilds it, which on a large site takes hours.', 'advcm' );
			echo '</label></p>';
			echo '<p><label><input type="checkbox" name="override_hold" value="1" /> ';
			echo esc_html__( 'Clear the page caches even if builder CSS fails. They may then store unstyled pages.', 'advcm' );
			echo '</label></p>';
		}

		submit_button( __( 'Clear the whole site', 'advcm' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The last jobs, each step with its result.
	 *
	 * @param string $open The job to show expanded.
	 * @return void
	 */
	private static function render_jobs( $open ) {
		$jobs = ADVCM_Jobs::all();

		echo '<h2>' . esc_html__( 'Recent clears', 'advcm' ) . '</h2>';

		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'Nothing has been cleared through this plugin yet.', 'advcm' ) . '</p>';

			return;
		}

		foreach ( $jobs as $job ) {
			$user    = $job['by'] ? get_userdata( $job['by'] ) : false;
			$who     = $user ? $user->display_name : __( 'unknown', 'advcm' );
			$what    = 'all' === $job['scope'] ? __( 'whole site', 'advcm' ) : implode( ', ', $job['urls'] );
			$summary = sprintf( '%s — %s — %s — %s', gmdate( 'Y-m-d H:i', (int) $job['created'] ) . ' UTC', $who, $what, $job['state'] );

			echo '<details' . ( $open === $job['id'] ? ' open' : '' ) . ' style="margin:0 0 8px">';
			echo '<summary>' . esc_html( $summary ) . '</summary>';

			if ( ! empty( $job['refused'] ) ) {
				echo '<p>' . esc_html( __( 'Not on this site, so not cleared:', 'advcm' ) . ' ' . implode( ', ', $job['refused'] ) ) . '</p>';
			}

			echo '<table class="widefat striped" style="margin-top:6px"><tbody>';

			foreach ( $job['steps'] as $step ) {
				echo '<tr>';
				echo '<td>' . esc_html( $step['stage'] . ' · ' . $step['label'] ) . '</td>';
				echo '<td><strong>' . esc_html( $step['status'] ) . '</strong></td>';
				echo '<td>' . esc_html( $step['message'] ) . '</td>';
				echo '<td>' . esc_html( $step['ms'] ? $step['ms'] . ' ms' : '' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table></details>';
		}
	}
}
