<?php
/**
 * Tools → CacheManager, and the admin bar menu.
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
		ADVCM_Safe::action( 'admin_menu', array( __CLASS__, 'menu' ) );
		ADVCM_Safe::action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		ADVCM_Safe::action( 'wp_after_admin_bar_render', array( __CLASS__, 'admin_bar_confirm' ) );
	}

	/**
	 * The Tools entry. Same capability as the page and the handler.
	 *
	 * @return void
	 */
	public static function menu() {
		add_management_page(
			__( 'CacheManager', 'advcm' ),
			__( 'CacheManager', 'advcm' ),
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
				'title' => __( 'CacheManager', 'advcm' ),
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
			)
		);
	}

	/**
	 * The confirmation for "Clear the whole site" in the admin bar.
	 *
	 * It was an `onclick` in the node's meta, and it never ran: the admin bar passes `onclick`
	 * through `esc_js()`, whose `stripslashes()` removes the escapes `wp_json_encode()` put in, so
	 * the handler was a JavaScript syntax error, the browser dropped it, and the link cleared the
	 * whole site on one click. Found by a security review on 2026-10-01. Now a listener attached
	 * after the bar renders, with the message as a JSON literal in a script, where nothing
	 * re-escapes it.
	 *
	 * @return void
	 */
	public static function admin_bar_confirm() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			return;
		}

		echo '<script>(function(){var a=document.querySelector("#wp-admin-bar-advcm-site > a");if(!a){return;}var m=' . wp_json_encode( self::site_warning() ) . ';a.addEventListener("click",function(e){if(!window.confirm(m)){e.preventDefault();}});})();</script>';
	}

	/**
	 * What clearing the site costs, said before it happens.
	 *
	 * @return string
	 */
	private static function site_warning() {
		return __( 'Clear every cache layer on the whole site? Every page is rebuilt on its next visit, so the site is slower for a while and the server takes extra load — more so on a busy site. To refresh one page, use "Clear this page" on that page instead.', 'advcm' );
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

		$tab = self::current_tab( $open, (bool) $notice );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'CacheManager', 'advcm' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
		}

		self::render_tabs( $tab );

		$sections = array(
			'status'  => function () {
				self::record_version();
				self::render_last_error();
				self::render_loopback();
				self::render_backlog();
				self::render_plan();
			},
			'clear'   => function () use ( $hard ) {
				self::render_forms( $hard );
			},
			'check'   => function () {
				self::render_probe();
			},
			'history' => function () use ( $open ) {
				self::render_jobs( $open );
			},
			'freshness' => function () use ( $hard ) {
				self::render_freshness( $hard );
			},
			'auto'    => function () use ( $hard ) {
				self::render_auto( $hard );
			},
			'settings' => function () use ( $hard ) {
				self::render_settings( $hard );
			},
		);

		// The tab on its own: if it throws it prints a notice, and the tabs above still lead to
		// the others — the screen that reports faults is not the first thing a fault takes away.
		try {
			$sections[ $tab ]();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'screen:' . $tab, $e );
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'This tab could not be shown (%s). The error is in the PHP error log.', 'advcm' ), $tab ) ) . '</p></div>';
		}

		// Who changed what in this tab's section, below its content and on its own guard: a fault
		// in the log never takes the tab with it.
		ADVCM_Safe::run(
			'screen:log',
			function () use ( $tab ) {
				self::render_log( $tab );
			}
		);

		ADVCM_Safe::run(
			'screen:tick',
			function () use ( $tab ) {
				self::render_tick( 'history' === $tab || 'freshness' === $tab, 'freshness' === $tab );
			}
		);

		echo '</div>';
	}

	/** How long a clear somebody asked for must wait before History says so, in seconds. */
	const WAIT_SHOWN = 5;

	/** The tabs, in order, by id. */
	const TABS = array( 'status', 'clear', 'check', 'history', 'freshness', 'auto', 'settings' );

	/**
	 * The tab to show: the one asked for, or History when a press has just landed with its job,
	 * or Clear when a press was refused, or Status.
	 *
	 * @param string $open   A job just started.
	 * @param bool   $notice Whether a refusal is waiting to be read.
	 * @return string
	 */
	private static function current_tab( $open, $notice ) {
		$asked = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- choosing a tab changes nothing.

		if ( in_array( $asked, self::TABS, true ) ) {
			return $asked;
		}

		if ( '' !== $open ) {
			return 'history';
		}

		return $notice ? 'clear' : 'status';
	}

	/**
	 * A tab's address.
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'tools.php' ) );
	}

	/**
	 * The tab bar, and a line on every other tab while a clear is running.
	 *
	 * @param string $current The tab shown.
	 * @return void
	 */
	private static function render_tabs( $current ) {
		$labels = array(
			'status'  => __( 'Status', 'advcm' ),
			'clear'   => __( 'Clear', 'advcm' ),
			'check'   => __( 'Cache age', 'advcm' ),
			'history' => __( 'History', 'advcm' ),
			'freshness' => __( 'Freshness', 'advcm' ),
			'auto'     => __( 'Auto-clear', 'advcm' ),
			'settings' => __( 'Settings', 'advcm' ),
		);

		echo '<nav class="nav-tab-wrapper" style="margin-bottom:12px">';

		foreach ( self::TABS as $tab ) {
			echo '<a href="' . esc_url( self::tab_url( $tab ) ) . '" class="nav-tab' . ( $current === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $labels[ $tab ] ) . '</a>';
		}

		echo '</nav>';

		if ( 'history' !== $current && self::running_job() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A clear is running.', 'advcm' ) . ' <a href="' . esc_url( self::tab_url( 'history' ) ) . '">' . esc_html__( 'See its progress', 'advcm' ) . '</a></p></div>';
		}
	}

	/**
	 * Whether any job is running.
	 *
	 * @return bool
	 */
	private static function running_job() {
		foreach ( ADVCM_Jobs::all() as $job ) {
			if ( 'running' === $job['state'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * While a clear runs, the screen moves it — on whichever tab is open.
	 *
	 * An admin-ajax call runs whatever step is due: the cron a site behind authentication never
	 * gets. On History the page then reloads to show it; on the other tabs it only keeps calling,
	 * so a list of URLs being typed on Clear is not thrown away by a reload.
	 *
	 * @param bool $reload   Whether to reload after each call.
	 * @param bool $listings The Freshness tab: also move a listings scan WP-Cron has left late.
	 * @return void
	 */
	private static function render_tick( $reload, $listings = false ) {
		// Also for an auto-clear WP-Cron has left a window past due: the tick runs it
		// (ADVCM_Auto::tick()), as it moves a background job.
		$late_clear = ADVCM_Auto::next_flush() > 0 && ADVCM_Auto::next_flush() <= time() - ADVCM_Auto::WINDOW;

		$scan = $listings ? ADVCM_Listings::state() : null;
		$scan = null !== $scan && $scan['started'] && ! $scan['done'];

		if ( ! self::running_job() && ! $late_clear && ! $scan ) {
			return;
		}

		if ( $reload ) {
			echo '<p class="description">' . esc_html__( 'While a clear is running this page moves it along and refreshes every 15 seconds. You can also leave; WP-Cron carries on where it can.', 'advcm' ) . '</p>';
		}

		$then = $reload ? 'setTimeout(function(){window.location.reload();},15000);' : 'setTimeout(tick,15000);';

		echo '<script>(function(){var tick=function(){var d=new FormData();d.append("action",' . wp_json_encode( ADVCM_Controller::TICK ) . ');d.append("_ajax_nonce",' . wp_json_encode( wp_create_nonce( ADVCM_Controller::TICK ) ) . ');' . ( $listings ? 'd.append("listings","1");' : '' ) . 'var done=function(){' . $then . '};fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:d,credentials:"same-origin"}).then(done,done);};tick();})();</script>';
	}

	/** The nonce action for the cache-age check. */
	const PROBE = 'advcm_probe';

	/**
	 * How old a page's cached copy is: a form, and the reading when one was asked for.
	 *
	 * A GET form with a nonce, so the reading is a link somebody can reload, and nothing that
	 * changes the site goes through it. The check itself is one request to the site's own page.
	 *
	 * @return void
	 */
	private static function render_probe() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified below before anything is requested.
		$asked = isset( $_GET['advcm_probe'] ) ? trim( wp_unslash( (string) $_GET['advcm_probe'] ) ) : '';
		$valid = '' !== $asked && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::PROBE );
		// phpcs:enable

		echo '<h2>' . esc_html__( 'How old is a page\'s cache', 'advcm' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="check" />';
		wp_nonce_field( self::PROBE, '_wpnonce', false );
		echo '<p><input type="text" name="advcm_probe" class="regular-text code" placeholder="/analysis/" value="' . esc_attr( $asked ) . '" /> ';
		submit_button( __( 'Check', 'advcm' ), 'secondary', '', false );
		echo '</p><p class="description">' . esc_html__( 'Requests the page once, as a visitor would, and reads what each cache in front of it says. Nothing is cleared. A cold page is built by this request.', 'advcm' ) . '</p></form>';

		if ( ! $valid ) {
			return;
		}

		// A reading is a link somebody can reload; a reload within ten minutes is not a new probe.
		ADVCM_Log::add_once( 'check', 'probe', substr( $asked, 0, 2048 ), 10 * MINUTE_IN_SECONDS );

		$reading = ADVCM_Probe::url( substr( $asked, 0, 2048 ) );

		if ( is_string( $reading ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $reading ) . '</p></div>';

			return;
		}

		$age  = $reading['age'];
		$rows = array();

		$rows[ __( 'Page', 'advcm' ) ]   = $reading['url'];
		$rows[ __( 'Answer', 'advcm' ) ] = $reading['code'] ? 'HTTP ' . $reading['code'] . ' in ' . $reading['ms'] . ' ms' : __( 'no answer', 'advcm' ) . ( isset( $reading['error'] ) ? ': ' . $reading['error'] : '' );

		if ( 401 === $reading['code'] || 403 === $reading['code'] ) {
			$rows[ __( 'Note', 'advcm' ) ] = __( 'The site refused its own request (HTTP authentication or a firewall), so the caches in front of it could not be read from here.', 'advcm' );
		}

		foreach ( $reading['layers'] as $layer => $said ) {
			$rows[ $layer ] = $said;
		}

		if ( null === $age['seconds'] ) {
			$rows[ __( 'Age', 'advcm' ) ] = __( 'unknown', 'advcm' ) . ' — ' . $age['source'];
		} else {
			$rows[ __( 'Age', 'advcm' ) ] = ( 'at most' === $age['how'] ? __( 'at most', 'advcm' ) . ' ' : '' ) . ADVCM_Probe::duration( $age['seconds'] ) . ' — ' . $age['source'];
		}

		if ( null !== $reading['max_ttl'] ) {
			$rows[ __( 'Kept for up to', 'advcm' ) ] = ADVCM_Probe::duration( $reading['max_ttl'] );
		}

		if ( isset( $reading['left'] ) ) {
			$rows[ __( 'Left before it expires', 'advcm' ) ] = ( 'at most' === $age['how'] ? __( 'at least', 'advcm' ) . ' ' : '' ) . ADVCM_Probe::duration( $reading['left'] );
		}

		if ( null !== $reading['hits'] ) {
			$rows[ __( 'Served from this copy', 'advcm' ) ] = sprintf( _n( '%d time', '%d times', $reading['hits'], 'advcm' ), $reading['hits'] );
		}

		// Both only on a site with NitroPack: elsewhere there is nothing to say and no row says it.
		if ( null !== $reading['nitropack_state'] ) {
			$rows[ __( 'NitroPack state in the page', 'advcm' ) ] = $reading['nitropack_state'];
		}

		$copy = ADVCM_Probe::nitropack_copy( $reading['url'] );

		if ( null !== $copy ) {
			$rows[ __( 'NitroPack copy', 'advcm' ) ] = 'fresh' === $copy['state']
				/* translators: %s: how long ago. */
				? sprintf( __( 'stored %s ago (fresh)', 'advcm' ), ADVCM_Probe::duration( max( 0, time() - $copy['at'] ) ) )
				/* translators: %s: how long ago. */
				: sprintf( __( 'stale, written %s ago — NitroPack serves it while it builds a new one', 'advcm' ), ADVCM_Probe::duration( max( 0, time() - $copy['at'] ) ) );
		}

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Record that this plugin's version changed since the Status tab last looked.
	 *
	 * Most updates are WordPress's own auto-update, inside WP-Cron, with nobody pressing anything,
	 * and the upgrader's hooks run in the old version's code — so the change is noticed here, the
	 * first time somebody opens Status afterwards, and recorded as nobody's. A site that never
	 * recorded a version only starts counting.
	 *
	 * @return void
	 */
	public static function record_version() {
		$seen = ADVCM_Settings::get()['version_seen'];

		if ( ADVCM_VERSION === $seen ) {
			return;
		}

		if ( '' !== $seen ) {
			/* translators: 1: version before, 2: version now. */
			ADVCM_Log::add( 'status', 'updated', sprintf( __( 'CacheManager updated from %1$s to %2$s', 'advcm' ), $seen, ADVCM_VERSION ), $seen, ADVCM_VERSION, false );
		}

		ADVCM_Settings::set_version_seen( ADVCM_VERSION );
	}

	/**
	 * WP-Cron running behind, and what it means for auto-clears.
	 *
	 * On BMR another plugin's duplicated event kept cron hours behind and an auto-clear ran 8.5
	 * hours late; nothing on this screen said so. Read-only: the cron option WordPress already holds.
	 *
	 * @return void
	 */
	private static function render_backlog() {
		$backlog = ADVCM_Auto::cron_backlog( time() );

		if ( 0 === $backlog['oldest'] || $backlog['oldest'] >= time() - ADVCM_Auto::BEHIND ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: how late, 2: how many events. */
				__( 'WP-Cron is %1$s behind, with %2$d events waiting. Scheduled work on this site runs late, other plugins\' included. An auto-clear is put ahead of them, and this screen runs one that is still late while it is open.', 'advcm' ),
				ADVCM_Probe::duration( time() - $backlog['oldest'] ),
				$backlog['due']
			)
		) . '</p></div>';
	}

	/**
	 * Whether this site can wake its own cron, checked here (and cached) because only this screen
	 * may spend an HTTP request on it.
	 *
	 * @return void
	 */
	private static function render_loopback() {
		$known = ADVCM_Safe::run( 'loopback', array( 'ADVCM_Modes', 'check_loopback' ), array( 'ok' => true, 'code' => 0 ) );

		if ( ! empty( $known['ok'] ) ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: %s: HTTP status, or "no answer". */
				__( 'This site cannot reach itself (%s), so WordPress cannot wake its own cron: scheduled events, this plugin\'s included, only run if a server cron calls wp-cron.php. Fast is recommended here. A Balanced or Careful clear still finishes while this screen is open, because the screen moves it.', 'advcm' ),
				$known['code'] ? 'HTTP ' . (int) $known['code'] : __( 'no answer', 'advcm' )
			)
		) . '</p></div>';
	}

	/**
	 * Who started a job or cleared a layer, in words.
	 *
	 * @param int    $by     User id.
	 * @param string $source Where it came from.
	 * @return string
	 */
	public static function who( $by, $source ) {
		if ( 'wp-cli' === $source ) {
			return 'WP-CLI';
		}

		if ( ADVCM_Auto::SOURCE === $source ) {
			return __( 'Auto-clear', 'advcm' );
		}

		$user = $by && function_exists( 'get_userdata' ) ? get_userdata( $by ) : false;

		return $user ? $user->display_name : __( 'unknown', 'advcm' );
	}

	/**
	 * The last fault the guards caught, if recent, so it is seen without reading a log.
	 *
	 * @return void
	 */
	private static function render_last_error() {
		$last = get_option( ADVCM_Safe::LAST_ERROR, array() );

		if ( ! is_array( $last ) || empty( $last['at'] ) || time() - (int) $last['at'] > DAY_IN_SECONDS ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>' . esc_html(
			sprintf(
				/* translators: 1: time, 2: hook, 3: error. */
				__( 'Caught at %1$s UTC in %2$s, without affecting the page: %3$s', 'advcm' ),
				gmdate( 'Y-m-d H:i', (int) $last['at'] ),
				$last['where'],
				$last['what']
			)
		) . '</p></div>';
	}

	/**
	 * The preflight for a site-wide clear, as a table.
	 *
	 * @return void
	 */
	private static function render_plan() {
		echo '<h2>' . esc_html__( 'The layers a clear runs on this site, in order', 'advcm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Checked as this page loaded: install or remove a cache plugin and it shows here the next time the page opens. Each layer is cleared only after the one beneath it, so none re-caches stale content from another, and each is checked again right before its step.', 'advcm' ) . '</p>';

		$adapters = array();

		foreach ( ADVCM_Runner::adapters() as $adapter ) {
			$adapters[ $adapter->id() ] = $adapter;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Layer', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Last cleared', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'advcm' ) . '</th>';
		echo '</tr></thead><tbody>';

		$last     = ADVCM_Jobs::layers();
		$reported = array();
		$shown    = 0;

		foreach ( ADVCM_Runner::plan( array( 'scope' => 'all' ) ) as $step ) {
			if ( 'run' !== $step['action'] ) {
				// Not a row: only what a clear actually runs here is listed. A layer that is on
				// the site but only reported (Bricks) gets a line below; one that is not on the
				// site is not mentioned at all — asked for, so the screen describes this site
				// rather than everything the plugin knows.
				if ( 0 === strpos( $step['message'], 'report only' ) ) {
					$reported[] = $step;
				}

				continue;
			}

			$shown++;

			echo '<tr>';
			echo '<td>' . esc_html( $step['stage'] . ' · ' . ADVCM_Stages::label( $step['stage'] ) ) . '</td>';
			echo '<td><strong>' . esc_html( $step['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( self::last_line( isset( $last[ $step['id'] ] ) ? $last[ $step['id'] ] : null ) ) . '</td>';
			echo '<td>' . esc_html( self::info_line( isset( $adapters[ $step['id'] ] ) ? $adapters[ $step['id'] ] : null, true ) ) . '</td>';
			echo '</tr>';
		}

		if ( 0 === $shown ) {
			echo '<tr><td colspan="4">' . esc_html__( 'None of the layers this plugin knows is on this site, so a clear has nothing to do here.', 'advcm' ) . '</td></tr>';
		}

		echo '</tbody></table>';

		foreach ( $reported as $step ) {
			echo '<p><strong>' . esc_html( $step['label'] ) . '</strong> — ' . esc_html__( 'on this site, shown and never cleared.', 'advcm' ) . ' ' . esc_html( self::info_line( isset( $adapters[ $step['id'] ] ) ? $adapters[ $step['id'] ] : null, true ) ) . '</p>';
		}

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

		$who  = self::who( isset( $last['by'] ) ? (int) $last['by'] : 0, isset( $last['source'] ) ? $last['source'] : '' );
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

		if ( ! ADVCM_Modes::background_available() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WP-Cron is turned off on this site (DISABLE_WP_CRON), so only Fast is offered: the background modes would wait for an event nothing fires. If a server cron calls wp-cron.php, the advcm_background_available filter turns them back on.', 'advcm' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Clear specific pages', 'advcm' ) . '</h2>';
		echo '<form method="post" action="' . $action . '">'; // phpcs:ignore -- escaped above.
		wp_nonce_field( ADVCM_Controller::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Controller::ACTION ) . '" />';
		echo '<input type="hidden" name="scope" value="urls" />';
		echo '<p><label for="advcm-urls">' . esc_html( sprintf( __( 'One URL or path per line, on this site only (up to %d).', 'advcm' ), ADVCM_Urls::MAX ) ) . '</label></p>';
		echo '<textarea id="advcm-urls" name="urls" rows="5" class="large-text code" placeholder="/analysis/"></textarea>';
		self::render_modes( 'urls' );
		submit_button( __( 'Clear these pages', 'advcm' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Clear the whole site', 'advcm' ) . '</h2>';
		echo '<form method="post" action="' . $action . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( self::site_warning() ) ) . ');">'; // phpcs:ignore -- escaped above.
		wp_nonce_field( ADVCM_Controller::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Controller::ACTION ) . '" />';
		echo '<input type="hidden" name="scope" value="all" />';
		self::render_modes( 'all' );

		// Only where NitroPack is on the site: an option for a layer that is not here is noise.
		$nitro = null;

		foreach ( ADVCM_Runner::adapters() as $adapter ) {
			if ( 'nitropack' === $adapter->id() ) {
				$nitro = $adapter;
			}
		}

		$found = null !== $nitro ? ADVCM_Safe::run( 'screen:nitropack', array( $nitro, 'detect' ), array( 'present' => false ) ) : array( 'present' => false );

		// One choice, three answers, so it cannot be asked for two contradictory things at once.
		if ( ! empty( $found['present'] ) ) {
			$choices = array(
				'invalidate' => array( __( 'Invalidate', 'advcm' ), __( 'NitroPack keeps serving its optimized copies while it rebuilds every page in the background. On a large site the rebuild takes hours and purges the host cache page by page as it goes.', 'advcm' ) ),
				'leave'      => array( __( 'Leave as it is', 'advcm' ), __( 'everything else is cleared and NitroPack is not touched. Pages NitroPack holds keep showing what they showed until it refreshes them itself, so use this when the change is somewhere NitroPack does not hold: pages it does not optimize, the object cache, the host cache.', 'advcm' ) ),
			);

			if ( $hard ) {
				$choices['purge'] = array( __( 'Purge', 'advcm' ), __( 'every page is served un-optimized until NitroPack rebuilds it, which on a large site takes hours.', 'advcm' ) );
			}

			echo '<fieldset style="margin:8px 0"><legend><strong>' . esc_html__( 'NitroPack', 'advcm' ) . '</strong></legend>';

			foreach ( $choices as $value => $choice ) {
				echo '<p style="margin:4px 0"><label><input type="radio" name="nitropack" value="' . esc_attr( $value ) . '"' . ( 'invalidate' === $value ? ' checked' : '' ) . ' /> ';
				echo '<strong>' . esc_html( $choice[0] ) . '</strong>' . ( 'invalidate' === $value ? ' <em>(' . esc_html__( 'recommended', 'advcm' ) . ')</em>' : '' ) . ' — ' . esc_html( $choice[1] );
				echo '</label></p>';
			}

			echo '</fieldset>';
		}

		if ( $hard ) {
			echo '<p><label><input type="checkbox" name="override_hold" value="1" /> ';
			echo esc_html__( 'Clear the page caches even if builder CSS fails. They may then store unstyled pages.', 'advcm' );
			echo '</label></p>';
		}

		submit_button( __( 'Clear the whole site', 'advcm' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The mode choice for a form, with the recommended one selected and every mode explained.
	 *
	 * @param string $scope `urls` or `all`.
	 * @return void
	 */
	private static function render_modes( $scope ) {
		$recommended = ADVCM_Modes::recommended( $scope );

		echo '<fieldset style="margin:8px 0"><legend><strong>' . esc_html__( 'How', 'advcm' ) . '</strong></legend>';

		foreach ( ADVCM_Modes::available() as $id ) {
			$mode = ADVCM_Modes::get( $id );

			echo '<p style="margin:4px 0"><label><input type="radio" name="mode" value="' . esc_attr( $id ) . '"' . ( $recommended === $id ? ' checked' : '' ) . ' /> ';
			echo '<strong>' . esc_html( $mode['label'] ) . '</strong>';

			if ( $recommended === $id ) {
				echo ' <em>(' . esc_html__( 'recommended', 'advcm' ) . ')</em>';
			}

			echo ' — ' . esc_html( $mode['summary'] ) . '</label></p>';
		}

		echo '</fieldset>';
	}

	/**
	 * Where a running job is, in words.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function progress_line( array $job ) {
		if ( 'running' !== $job['state'] ) {
			return '';
		}

		if ( ADVCM_Runner::stuck( $job ) ) {
			return __( 'stuck: WP-Cron has not picked up the next step. This happens on a site with no visitors, or a host that blocks the request WordPress makes to wake its own cron.', 'advcm' );
		}

		if ( ADVCM_Runner::locked( $job['id'] ) ) {
			return __( 'running a step now', 'advcm' );
		}

		if ( ! empty( $job['next_at'] ) && $job['next_at'] > time() ) {
			return sprintf(
				/* translators: 1: time, 2: seconds. */
				__( 'paused; the next step starts at %1$s UTC (in %2$d s)', 'advcm' ),
				gmdate( 'H:i:s', (int) $job['next_at'] ),
				(int) $job['next_at'] - time()
			);
		}

		if ( false === ADVCM_Modes::loopback_ok() ) {
			return __( 'moving while this screen is open: this site cannot wake its own cron', 'advcm' );
		}

		return __( 'waiting for WP-Cron to start the next step', 'advcm' );
	}

	/**
	 * The last jobs, each step with its result.
	 *
	 * @param string $open The job to show expanded.
	 * @return void
	 */
	private static function render_jobs( $open ) {
		$people = array();
		$auto   = array();

		foreach ( ADVCM_Jobs::all() as $id => $job ) {
			if ( isset( $job['source'] ) && ADVCM_Auto::SOURCE === $job['source'] ) {
				$auto[ $id ] = $job;
			} else {
				$people[ $id ] = $job;
			}
		}

		/* translators: %d: how many are kept. */
		self::render_job_list( __( 'Clears', 'advcm' ), sprintf( __( 'Started by somebody, or from WP-CLI. The last %d are kept.', 'advcm' ), ADVCM_Jobs::KEEP ), $people, $open );
		/* translators: %d: how many are kept. */
		self::render_job_list( __( 'Automatic clears', 'advcm' ), sprintf( __( 'Auto-clear rules and each post\'s own page. The last %d are kept, apart, so they never push out a clear somebody started.', 'advcm' ), ADVCM_Jobs::AUTO_KEEP ), $auto, $open );
	}

	/**
	 * One list of History.
	 *
	 * @param string $title Heading.
	 * @param string $about What the list holds.
	 * @param array  $jobs  Jobs, newest first.
	 * @param string $open  The job to open.
	 * @return void
	 */
	private static function render_job_list( $title, $about, array $jobs, $open ) {
		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<p class="description">' . esc_html( $about ) . '</p>';

		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'None yet.', 'advcm' ) . '</p>';

			return;
		}

		foreach ( $jobs as $job ) {
			$who     = self::who( (int) $job['by'], isset( $job['source'] ) ? $job['source'] : '' );
			$what    = 'all' === $job['scope'] ? __( 'whole site', 'advcm' ) : implode( ', ', $job['urls'] );
			$mode    = ADVCM_Modes::get( isset( $job['mode'] ) ? $job['mode'] : ADVCM_Modes::FAST );
			$summary = sprintf( '%s — %s — %s — %s — %s', gmdate( 'Y-m-d H:i', (int) $job['created'] ) . ' UTC', $who, $what, $mode['label'], $job['state'] ) . self::timings( $job );
			$running = 'running' === $job['state'];

			echo '<details' . ( ( $open === $job['id'] || $running ) ? ' open' : '' ) . ' style="margin:0 0 8px">';
			echo '<summary>' . esc_html( $summary ) . '</summary>';

			if ( $running ) {
				echo '<p><strong>' . esc_html( self::progress_line( $job ) ) . '</strong></p>';

				if ( ADVCM_Runner::stuck( $job ) ) {
					echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '">';
					wp_nonce_field( ADVCM_Controller::RESUME );
					echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Controller::RESUME ) . '" />';
					echo '<input type="hidden" name="job" value="' . esc_attr( $job['id'] ) . '" />';
					submit_button( __( 'Continue now, without the remaining pauses', 'advcm' ), 'secondary small', 'submit', false );
					echo '</form>';
				}
			}

			if ( ! empty( $job['refused'] ) ) {
				echo '<p>' . esc_html( __( 'Not on this site, so not cleared:', 'advcm' ) . ' ' . implode( ', ', $job['refused'] ) ) . '</p>';
			}

			self::render_triggers( $job );

			echo '<table class="widefat striped" style="margin-top:6px"><tbody>';

			foreach ( $job['steps'] as $step ) {
				// The same rule as Status: a layer that was not on the site when the clear was
				// planned is not a row. A layer that was there at the plan and gone by its step
				// still is — it says the site changed under the clear.
				if ( 'skip' === $step['action'] && 'skipped' === $step['status'] && ADVCM_Runner::LEFT_OUT !== $step['message'] ) {
					continue;
				}

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

	/**
	 * How long a clear took, and how long it waited: an automatic one from the first save that set
	 * it off, one somebody asked for from the request to its first step — the number that showed
	 * BMR's clears running hours late. "took" is empty while running.
	 *
	 * @param array $job Job.
	 * @return string " — took …", " — waited …", or both, or empty.
	 */
	private static function timings( array $job ) {
		$out     = '';
		$created = isset( $job['created'] ) ? (int) $job['created'] : 0;

		$first = 0;

		foreach ( isset( $job['options']['triggers'] ) && is_array( $job['options']['triggers'] ) ? $job['options']['triggers'] : array() as $trigger ) {
			if ( is_array( $trigger ) && ! empty( $trigger['at'] ) ) {
				$first = 0 === $first ? (int) $trigger['at'] : min( $first, (int) $trigger['at'] );
			}
		}

		if ( $first > 0 && $created > $first ) {
			/* translators: %s: a duration. */
			$out .= ' — ' . sprintf( __( 'waited %s', 'advcm' ), ADVCM_Probe::duration( $created - $first ) );
		} else {
			// A clear somebody asked for: from the request to its first step that ran. A Fast clear
			// runs in that request, so it shows nothing; a background one WP-Cron ran late shows how
			// late — the number that would have shown BMR's 8.5 hours.
			$ran = 0.0;

			foreach ( isset( $job['steps'] ) && is_array( $job['steps'] ) ? $job['steps'] : array() as $step ) {
				if ( is_array( $step ) && ! empty( $step['started'] ) ) {
					$ran = 0.0 === $ran ? (float) $step['started'] : min( $ran, (float) $step['started'] );
				}
			}

			if ( $ran > 0 && $created > 0 && $ran - $created >= self::WAIT_SHOWN ) {
				/* translators: %s: a duration. */
				$out .= ' — ' . sprintf( __( 'waited %s', 'advcm' ), ADVCM_Probe::duration( (int) ( $ran - $created ) ) );
			}
		}

		if ( ! empty( $job['finished'] ) && (int) $job['finished'] >= $created && $created > 0 ) {
			/* translators: %s: a duration. */
			$out .= ' — ' . sprintf( __( 'took %s', 'advcm' ), ADVCM_Probe::duration( (int) $job['finished'] - $created ) );
		}

		return $out;
	}

	/**
	 * What set an auto-clear off: each rule in words, and the posts whose saves triggered it.
	 *
	 * A job from before 0.3.1 has no `triggers` and shows nothing here; one whose rule was waiting
	 * across the update has the rule and says its posts were not recorded. Every value is from a
	 * stored row and printed escaped, the titles included — an author writes those.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	private static function render_triggers( array $job ) {
		if ( ! isset( $job['source'] ) || ADVCM_Auto::SOURCE !== $job['source'] || empty( $job['options']['triggers'] ) || ! is_array( $job['options']['triggers'] ) ) {
			return;
		}

		$changes = array(
			'published'   => __( 'published', 'advcm' ),
			'updated'     => __( 'updated', 'advcm' ),
			'unpublished' => __( 'unpublished', 'advcm' ),
		);

		echo '<div class="advcm-triggers" style="margin:8px 0 4px">';

		foreach ( $job['options']['triggers'] as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}

			$line  = isset( $trigger['line'] ) ? (string) $trigger['line'] : '';
			$posts = isset( $trigger['posts'] ) && is_array( $trigger['posts'] ) ? $trigger['posts'] : array();
			$total = max( isset( $trigger['total'] ) ? (int) $trigger['total'] : 0, count( $posts ) );

			echo '<p style="margin:6px 0 2px"><strong>' . esc_html( sprintf( __( 'When %s', 'advcm' ), $line ) ) . '</strong>';

			if ( $total > 0 ) {
				/* translators: %d: number of posts. */
				echo ' — ' . esc_html( sprintf( _n( 'triggered by %d post:', 'triggered by %d posts:', $total, 'advcm' ), $total ) );
			}

			echo '</p>';

			if ( empty( $posts ) ) {
				echo '<p class="description" style="margin:0 0 0 20px">' . esc_html__( 'Which posts triggered it was not recorded: it was waiting when this plugin was updated.', 'advcm' ) . '</p>';

				continue;
			}

			echo '<ul style="list-style:disc;margin:0 0 0 20px">';

			foreach ( $posts as $post ) {
				if ( ! is_array( $post ) || ! isset( $post['id'] ) ) {
					continue;
				}

				$id     = (int) $post['id'];
				$title  = isset( $post['title'] ) && '' !== trim( (string) $post['title'] ) ? (string) $post['title'] : __( '(no title)', 'advcm' );
				$change = isset( $post['change'], $changes[ $post['change'] ] ) ? $changes[ $post['change'] ] : '';
				$type   = isset( $post['type'] ) ? (string) $post['type'] : '';
				$live   = $id > 0 ? get_post_type( $id ) : false;
				// Asked only of a post that still exists in a type still registered: for a type a
				// plugin took away since the clear, WordPress answers "may not edit" with a notice
				// printed into this page, measured in the smoke run.
				$link = $live && post_type_exists( $live ) && current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id, 'raw' ) : '';

				echo '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) );
				echo ' <span class="description">' . esc_html( sprintf( '#%d · %s · %s', $id, $type, $change ) ) . '</span></li>';
			}

			if ( $total > count( $posts ) ) {
				/* translators: %d: number of posts. */
				echo '<li>' . esc_html( sprintf( __( 'and %d more', 'advcm' ), $total - count( $posts ) ) ) . '</li>';
			}

			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * The Auto-clear tab: the rules, what is waiting, a test against a post, and for
	 * administrators the forms that change the rules.
	 *
	 * @param bool $hard Whether the rules may be changed here.
	 * @return void
	 */
	private static function render_auto( $hard ) {
		$rules   = ADVCM_Auto::rules();
		$waiting = ADVCM_Auto::waiting();
		$next    = ADVCM_Auto::next_flush();
		$paused  = ADVCM_Auto::paused();
		$last    = ADVCM_Auto::last();

		echo '<h2>' . esc_html__( 'Clear listing pages when a post is published', 'advcm' ) . '</h2>';

		// First on the tab, above the explanation: a paused auto-clear read as a working one is
		// the failure this switch can cause, so it is the first thing anybody opening the tab sees.
		if ( $paused ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Auto-clear is paused — no rule runs.', 'advcm' ) . '</strong> ';
			echo esc_html( sprintf( __( 'Paused at %s UTC. Publishing clears nothing until it is resumed; the rules are kept as they are, each with its own on or off.', 'advcm' ), gmdate( 'Y-m-d H:i', (int) get_option( ADVCM_Auto::PAUSED, 0 ) ) ) ) . '</p>';

			echo '<p><a class="button button-primary" href="' . esc_url( self::tab_url( 'settings' ) ) . '">' . esc_html__( 'Resume it in Settings', 'advcm' ) . '</a></p>';
			echo '</div>';
		}

		echo '<p>' . esc_html( sprintf( __( 'A page that lists posts — a category page, a Page built from a query — is not refreshed by NitroPack when a new post appears on it, because it never rendered that post. A rule names those pages. When a matching post is published, updated while published, or unpublished, its pages are cleared about %d seconds later in the background, with every other post saved in between: one clear, never one per post. Each rule says whether NitroPack is purged, so the new list shows at once, or invalidated, so the old optimized copy serves while it rebuilds.', 'advcm' ), ADVCM_Auto::WINDOW ) ) . '</p>';

		// Each post's own page is a setting since 0.4.0, kept as a rule in the same row
		// (ADVCM_Auto::OWN) and switched on the Settings tab; this table is the listing rules.
		unset( $rules[ ADVCM_Auto::OWN ] );

		echo '<h3>' . esc_html__( 'Listing pages', 'advcm' ) . '</h3>';

		if ( ! empty( $waiting ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				$paused
					? sprintf( __( '%d rule(s) waiting from before the pause; the clear will drop them and clear nothing.', 'advcm' ), count( $waiting ) )
					: ( $next > 0
						? sprintf( __( '%1$d rule(s) waiting; the clear runs at %2$s UTC.', 'advcm' ), count( $waiting ), gmdate( 'H:i:s', $next ) )
						: sprintf( __( '%d rule(s) waiting, and no clear is scheduled: it will be scheduled by the next matching save.', 'advcm' ), count( $waiting ) ) )
			) . '</p></div>';
		}

		echo '<table class="widefat striped" style="margin-top:8px"><thead><tr>';
		echo '<th>' . esc_html__( 'When', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Clears', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'NitroPack', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'advcm' ) . '</th>';
		echo '<th>' . esc_html__( 'Last clear', 'advcm' ) . '</th>';
		if ( $hard ) {
			echo '<th></th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rules ) ) {
			echo '<tr><td colspan="' . ( $hard ? 6 : 5 ) . '">' . esc_html__( 'No rules: publishing a post clears nothing here.', 'advcm' ) . '</td></tr>';
		}

		foreach ( $rules as $rule ) {
			$state = $rule['enabled'] ? __( 'on', 'advcm' ) : __( 'off', 'advcm' );

			echo '<tr>';
			echo '<td>' . esc_html( ADVCM_Auto::describe( $rule ) ) . '</td>';
			echo '<td><code>' . implode( '</code><br><code>', array_map( 'esc_html', $rule['urls'] ) ) . '</code></td>';
			echo '<td>' . esc_html( 'purge' === $rule['nitropack'] ? __( 'purge', 'advcm' ) : __( 'invalidate', 'advcm' ) ) . '</td>';
			echo '<td><strong>' . esc_html( $state ) . '</strong>' . ( $rule['enabled'] && $paused ? ' — ' . esc_html__( 'paused', 'advcm' ) : '' ) . ( in_array( $rule['id'], $waiting, true ) ? ' — ' . esc_html__( 'waiting', 'advcm' ) : '' ) . '</td>';
			echo '<td>' . self::last_clear( isset( $last[ $rule['id'] ] ) ? $last[ $rule['id'] ] : null ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.

			if ( $hard ) {
				echo '<td>';
				self::rule_button( $rule['id'], $rule['enabled'] ? 'disable' : 'enable', $rule['enabled'] ? __( 'Switch off', 'advcm' ) : __( 'Switch on', 'advcm' ) );
				self::rule_button( $rule['id'], 'delete', __( 'Delete', 'advcm' ) );
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		self::render_rule_test( $rules );

		if ( ! $hard ) {
			echo '<p class="description">' . esc_html__( 'Changing rules has been restricted on this site.', 'advcm' ) . '</p>';

			return;
		}

		$nitropack = ADVCM_Settings::get()['nitropack_default'];

		echo '<h2>' . esc_html__( 'Add a rule', 'advcm' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '">';
		wp_nonce_field( ADVCM_Auto::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Auto::SAVE ) . '" />';
		echo '<input type="hidden" name="op" value="add" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th>' . esc_html__( 'When a post of type', 'advcm' ) . '</th><td><select name="post_type">';
		echo '<option value="' . esc_attr( ADVCM_Auto::ANY ) . '">' . esc_html__( 'Any content (every public post type)', 'advcm' ) . '</option>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			echo '<option value="' . esc_attr( $type->name ) . '"' . ( 'post' === $type->name ? ' selected' : '' ) . '>' . esc_html( $type->labels->singular_name . ' (' . $type->name . ')' ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( '"Any content", first in the list, is every post type a visitor can view, leaving out media, menus, reusable blocks, theme and page-builder templates, and form and snippet plugins\' records.', 'advcm' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'in the term (optional)', 'advcm' ) . '</th><td><select name="taxonomy">';
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			echo '<option value="' . esc_attr( $tax->name ) . '"' . ( 'category' === $tax->name ? ' selected' : '' ) . '>' . esc_html( $tax->labels->singular_name . ' (' . $tax->name . ')' ) . '</option>';
		}
		echo '</select> <input type="text" name="term" class="regular-text" placeholder="analysis" />';
		echo '<p class="description">' . esc_html__( 'A slug or a name. Empty: every post of that type. In a taxonomy with levels, such as categories, the term\'s subcategories count too: a rule on "news" also fires for a post filed only under "news-local", a child of "news" — as the "news" category page lists it.', 'advcm' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'is published, clear', 'advcm' ) . '</th><td><textarea name="urls" rows="3" class="large-text code" placeholder="/analysis/"></textarea>';
		echo '<p class="description">' . esc_html( sprintf( __( 'One URL or path per line, on this site only, up to %d.', 'advcm' ), ADVCM_Auto::MAX_URLS ) ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'NitroPack', 'advcm' ) . '</th><td>';
		// Purge first and recommended: an invalidated listing kept serving the old list — measured on
		// BMR, /analysis/ for 18 hours — and the old list is what a rule exists to replace.
		echo '<p style="margin:0 0 4px"><label><input type="radio" name="nitropack" value="purge"' . ( 'purge' === $nitropack ? ' checked' : '' ) . ' /> <strong>' . esc_html__( 'Purge', 'advcm' ) . '</strong> <em>(' . esc_html__( 'recommended', 'advcm' ) . ')</em> — ' . esc_html__( 'the new list shows at once, un-optimized for a few minutes until NitroPack rebuilds it.', 'advcm' ) . '</label></p>';
		echo '<p style="margin:0"><label><input type="radio" name="nitropack" value="invalidate"' . ( 'invalidate' === $nitropack ? ' checked' : '' ) . ' /> <strong>' . esc_html__( 'Invalidate', 'advcm' ) . '</strong> — ' . esc_html__( 'the old optimized copy, with the old list, serves until NitroPack rebuilds it, which can take hours.', 'advcm' ) . '</label></p>';
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Switch on now', 'advcm' ) . '</th><td><label><input type="checkbox" name="enabled" value="1" /> ' . esc_html__( 'Leave unticked to add it switched off and test a post against it first.', 'advcm' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Add the rule', 'advcm' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * A rule's last clear, as a cell: when, how many posts, how it went, and a link to the job
	 * while History still holds it. The state is the job's own when it is still there, since a
	 * clear recorded as running may have finished since.
	 *
	 * @param array|null $last The rule's entry in ADVCM_Auto::last().
	 * @return string HTML, escaped.
	 */
	private static function last_clear( $last ) {
		if ( ! is_array( $last ) || $last['at'] <= 0 ) {
			return esc_html__( 'never', 'advcm' );
		}

		$job   = '' !== $last['job'] ? ADVCM_Jobs::get( $last['job'] ) : null;
		$state = is_array( $job ) && isset( $job['state'] ) ? (string) $job['state'] : $last['state'];
		$when  = esc_html( gmdate( 'Y-m-d H:i', $last['at'] ) . ' UTC' );

		if ( is_array( $job ) ) {
			$when = '<a href="' . esc_url( add_query_arg( 'advcm_job', $last['job'], self::tab_url( 'history' ) ) ) . '">' . $when . '</a>';
		}

		/* translators: %d: number of posts. */
		$posts = $last['total'] > 0 ? sprintf( _n( '%d post', '%d posts', $last['total'], 'advcm' ), $last['total'] ) : __( 'posts not recorded', 'advcm' );

		return $when . '<br>' . esc_html( $posts . ( '' !== $state ? ' — ' . $state : '' ) );
	}

	/**
	 * One button that changes one rule.
	 *
	 * @param string $id    Rule id, or empty for pause and resume, which change no rule.
	 * @param string $op    enable, disable, delete, pause or resume.
	 * @param string $label Label.
	 * @param string $class Button class.
	 * @return void
	 */
	private static function rule_button( $id, $op, $label, $class = 'small' ) {
		$confirm = array(
			'delete' => __( 'Delete this rule?', 'advcm' ),
			'pause'  => __( 'Pause every auto-clear rule? Publishing will clear no listing page until it is resumed.', 'advcm' ),
		);

		echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '" style="display:inline"' . ( isset( $confirm[ $op ] ) ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm[ $op ] ) ) . ');"' : '' ) . '>';
		wp_nonce_field( ADVCM_Auto::SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Auto::SAVE ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		if ( '' !== $id ) {
			echo '<input type="hidden" name="rule" value="' . esc_attr( $id ) . '" />';
		}

		submit_button( $label, $class, 'submit', false );
		echo '</form> ';
	}

	/**
	 * The Settings tab: every setting a person may change, and nothing that could break the site
	 * or the release path (those stay filters and constants; see class-settings.php).
	 *
	 * @param bool $hard Whether the person may change settings.
	 * @return void
	 */
	private static function render_settings( $hard ) {
		$settings = ADVCM_Settings::get();
		$rules    = ADVCM_Auto::rules();
		$own      = isset( $rules[ ADVCM_Auto::OWN ] ) ? $rules[ ADVCM_Auto::OWN ] : null;
		$paused   = ADVCM_Auto::paused();
		$last     = ADVCM_Auto::last();

		echo '<h2>' . esc_html__( 'Settings', 'advcm' ) . '</h2>';

		if ( ! $hard ) {
			echo '<p class="description">' . esc_html__( 'Changing settings has been restricted on this site.', 'advcm' ) . '</p>';
		}

		// The pause first: a paused auto-clear read as a working one is the failure it can cause.
		echo '<h3>' . esc_html__( 'Auto-clear', 'advcm' ) . '</h3>';
		echo '<p><strong>' . esc_html( $paused ? __( 'Paused — no rule runs.', 'advcm' ) : __( 'Running.', 'advcm' ) ) . '</strong> ';
		echo esc_html__( 'Pausing stops every rule, and each post\'s own page, at once; each rule keeps its own on or off for when it is resumed.', 'advcm' ) . '</p>';

		if ( $hard ) {
			echo '<p>';
			self::settings_button( $paused ? 'resume' : 'pause', $paused ? __( 'Resume auto-clear', 'advcm' ) : __( 'Pause auto-clear', 'advcm' ), $paused ? 'primary' : 'secondary' );
			echo '</p>';
		}

		echo '<h3>' . esc_html__( 'Each post\'s own page', 'advcm' ) . '</h3>';
		echo '<p>' . esc_html__( 'When content is published, updated or unpublished, also clear its own page in the same clear, purging NitroPack, so an edit shows without anybody clearing it by hand.', 'advcm' ) . '</p>';
		echo '<p><strong>' . esc_html( null !== $own && $own['enabled'] ? __( 'On', 'advcm' ) : __( 'Off', 'advcm' ) ) . '</strong>';

		if ( null !== $own ) {
			echo ' — ' . self::last_clear( isset( $last[ ADVCM_Auto::OWN ] ) ? $last[ ADVCM_Auto::OWN ] : null ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}

		echo '</p>';

		if ( $hard ) {
			echo '<p>';
			self::settings_button( null !== $own ? 'own_off' : 'own_on', null !== $own ? __( 'Switch off', 'advcm' ) : __( 'Switch on', 'advcm' ) );
			echo '</p>';
		}

		$offered  = ADVCM_Settings::viewable_types();
		$excluded = null === $settings['excluded_types'] ? array_values( array_intersect( ADVCM_Auto::EXCLUDED, $offered ) ) : $settings['excluded_types'];

		echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '">';
		wp_nonce_field( ADVCM_Settings::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Settings::ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="save" />';
		echo '<input type="hidden" name="own_present" value="1" />';
		echo '<input type="hidden" name="excluded_present" value="1" />';
		echo '<input type="hidden" name="measure_present" value="1" />';

		echo '<p><label><input type="checkbox" name="own_skip_unchanged" value="1"' . ( $settings['own_skip_unchanged'] ? ' checked' : '' ) . ' /> ';
		echo esc_html__( 'Ignore saves that change nothing a visitor sees: title, content, excerpt, slug, status, password, date, parent and order all as they were.', 'advcm' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'A change only to custom fields or to a page builder\'s own data reads as unchanged, so that page is not cleared on its own; clearing it by hand still works. Listing rules always fire.', 'advcm' ) . '</p>';

		echo '<h3>' . esc_html__( 'Post types auto-clear leaves out', 'advcm' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'For each post\'s own page and for rules on "any content". Revisions, auto-drafts and menu items are always left out, and so are form, snippet and field-group records.', 'advcm' ) . '</p><p>';

		foreach ( $offered as $type ) {
			echo '<label style="display:inline-block;margin:0 16px 6px 0"><input type="checkbox" name="excluded[]" value="' . esc_attr( $type ) . '"' . ( in_array( $type, $excluded, true ) ? ' checked' : '' ) . ' /> ' . esc_html( $type ) . '</label>';
		}

		echo '</p>';

		echo '<h3>' . esc_html__( 'NitroPack for new rules', 'advcm' ) . '</h3>';

		foreach ( array(
			'purge'      => __( 'Purge — the new list shows at once, un-optimized for a few minutes.', 'advcm' ),
			'invalidate' => __( 'Invalidate — the old optimized copy, with the old list, serves until NitroPack rebuilds it.', 'advcm' ),
		) as $value => $label ) {
			echo '<p style="margin:0 0 4px"><label><input type="radio" name="nitropack_default" value="' . esc_attr( $value ) . '"' . ( $value === $settings['nitropack_default'] ? ' checked' : '' ) . ' /> ' . esc_html( $label ) . '</label></p>';
		}

		echo '<h3>' . esc_html__( 'Cache age before a clear', 'advcm' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="measure_before_clear" value="1"' . ( $settings['measure_before_clear'] ? ' checked' : '' ) . ' /> ';
		echo esc_html__( 'Before clearing some pages, ask each one how old its cached copy is, and keep the answer for the Freshness tab.', 'advcm' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'One request per page, as a visitor would make: at most 10 pages per clear, 5 seconds each, 20 seconds in all. Never for a whole-site clear. Only the caches this site has answer.', 'advcm' ) . '</p>';

		if ( $hard ) {
			submit_button( __( 'Save settings', 'advcm' ) );
		}

		echo '</form>';
	}

	/**
	 * A button that posts one operation to the Settings form.
	 *
	 * @param string $op    pause, resume, own_on, own_off.
	 * @param string $label Label.
	 * @param string $class Button class.
	 * @return void
	 */
	private static function settings_button( $op, $label, $class = 'secondary' ) {
		$confirm = array(
			'pause' => __( 'Pause every auto-clear rule? Publishing will clear no page until it is resumed.', 'advcm' ),
		);

		echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '" style="display:inline"' . ( isset( $confirm[ $op ] ) ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm[ $op ] ) ) . ');"' : '' ) . '>';
		wp_nonce_field( ADVCM_Settings::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Settings::ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		submit_button( $label, $class, 'submit', false );
		echo '</form> ';
	}

	/** Badge colours, from the WordPress admin palette. */
	const BADGES = array(
		'red'       => '#d63638',
		'amber'     => '#996800',
		'green'     => '#008a20',
		'grey'      => '#646970',
		'blue'      => '#2271b1',
		'unchecked' => '#646970',
	);

	/**
	 * A small coloured label.
	 *
	 * @param string $label Text.
	 * @param string $color Key of BADGES.
	 * @return string HTML.
	 */
	private static function badge( $label, $color ) {
		$hex = isset( self::BADGES[ $color ] ) ? self::BADGES[ $color ] : self::BADGES['grey'];

		return '<span style="display:inline-block;padding:1px 8px;border-radius:10px;color:#fff;background:' . esc_attr( $hex ) . ';font-size:12px;white-space:nowrap">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The Freshness tab: is a page old because a cache held it, or because its content is old?
	 *
	 * Three readings, each on its own guard: listings whose filter has matched nothing new for
	 * weeks (a scan a person starts), how old each page's cache was when a clear cleared it, and
	 * the pages people keep clearing by hand. All read-only; the only button starts the scan.
	 *
	 * @param bool $hard Unused: anybody who may clear may scan.
	 * @return void
	 */
	private static function render_freshness( $hard ) {
		echo '<p>' . esc_html__( 'A page can look old for two reasons: a cache kept an old copy, or the page itself has nothing new to show. Clearing fixes the first and does nothing for the second. This tab tells them apart.', 'advcm' ) . '</p>';

		foreach ( array( 'render_listings', 'render_measured', 'render_often' ) as $part ) {
			ADVCM_Safe::run(
				'screen:' . $part,
				function () use ( $part ) {
					self::$part();
				}
			);
		}
	}

	/**
	 * Frozen listings: the scan's state, its button, and what it found.
	 *
	 * @return void
	 */
	private static function render_listings() {
		$state = ADVCM_Listings::state();

		echo '<h2>' . esc_html__( 'Listings with nothing new', 'advcm' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: days, 2: days. */
				__( 'Elementor post lists and loop grids that filter by category or tag, on every page and template. Red: the newest post the filter matches is over %1$d days old, or the filter can match nothing. Amber: from %2$d days. A clear does not help these: the page needs posts in that category or tag, or a different filter.', 'advcm' ),
				ADVCM_Listings::FROZEN_DAYS,
				ADVCM_Listings::STALE_DAYS
			)
		) . '</p>';

		$running = $state['started'] && ! $state['done'];

		if ( ! $state['started'] ) {
			echo '<p>' . esc_html__( 'No scan yet.', 'advcm' ) . '</p>';
		} elseif ( $running ) {
			/* translators: 1: documents read, 2: in all, 3: how long ago, 4: person. */
			$line = sprintf( __( 'Scanning: %1$d of %2$d documents read, started %3$s ago by %4$s.', 'advcm' ), $state['scanned'], $state['total'], ADVCM_Probe::duration( time() - $state['started'] ), self::who( $state['by'], '' ) );

			if ( $state['next_at'] && time() - $state['next_at'] > ADVCM_Listings::LATE ) {
				$line .= ' ' . __( 'WP-Cron is late on this site; while this tab is open it moves the scan along.', 'advcm' );
			}

			echo '<p>' . esc_html( $line ) . '</p>';
		} else {
			/* translators: 1: how long ago, 2: date, 3: person, 4: documents, 5: listings, 6: up to date. */
			echo '<p>' . esc_html( sprintf( __( 'Last scan finished %1$s ago (%2$s UTC), started by %3$s: %4$d documents, %5$d listings, %6$d up to date.', 'advcm' ), ADVCM_Probe::duration( max( 0, time() - $state['done'] ) ), gmdate( 'Y-m-d H:i', $state['done'] ), self::who( $state['by'], '' ), $state['scanned'], $state['listings'], $state['green'] ) ) . '</p>';

			if ( 0 === $state['total'] ) {
				echo '<p class="description">' . esc_html__( 'This site has no published Elementor pages or templates, so there is nothing to check.', 'advcm' ) . '</p>';
			}
		}

		if ( $state['skipped'] ) {
			/* translators: %d: documents. */
			echo '<p class="description">' . esc_html( sprintf( _n( '%d document stopped a run and was stepped over.', '%d documents stopped a run and were stepped over.', $state['skipped'], 'advcm' ), $state['skipped'] ) ) . '</p>';
		}

		if ( $state['dropped'] ) {
			/* translators: 1: findings, 2: the cap. */
			echo '<p class="description">' . esc_html( sprintf( __( '%1$d more findings are not kept: only the worst %2$d are.', 'advcm' ), $state['dropped'], ADVCM_Listings::LISTINGS_KEEP ) ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( ADVCM_Controller::url() ) . '">';
		wp_nonce_field( ADVCM_Listings::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ADVCM_Listings::ACTION ) . '" />';
		submit_button( $running ? __( 'Pick up the scan', 'advcm' ) : __( 'Scan listings', 'advcm' ), 'secondary', '', false );
		echo ' <span class="description">' . esc_html__( 'Runs in the background, 20 documents at a time, and changes nothing.', 'advcm' ) . '</span></form>';

		if ( empty( $state['findings'] ) ) {
			return;
		}

		$labels = array(
			'red'       => __( 'nothing new', 'advcm' ),
			'amber'     => __( 'slowing', 'advcm' ),
			'unchecked' => __( 'not checked', 'advcm' ),
		);

		// Stored in this order already; sorted again so a row written by an older version reads
		// the same.
		$findings = $state['findings'];
		usort( $findings, array( 'ADVCM_Listings', 'compare' ) );

		echo '<div style="overflow-x:auto;margin-top:12px"><table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'State', 'advcm' ), __( 'Page', 'advcm' ), __( 'Widget', 'advcm' ), __( 'Filter', 'advcm' ), __( 'Newest match', 'advcm' ), __( 'Days', 'advcm' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $findings as $f ) {
			$verdict = isset( $f['verdict'] ) ? (string) $f['verdict'] : 'red';
			$title   = '' !== (string) $f['title'] ? (string) $f['title'] : '#' . (int) $f['doc'];
			$page    = '' !== (string) $f['url'] ? '<a href="' . esc_url( $f['url'] ) . '">' . esc_html( $title ) . '</a>' : esc_html( __( 'Template:', 'advcm' ) . ' ' . $title );

			$notes = array();

			if ( ! empty( $f['broken'] ) ) {
				$notes[] = __( 'broken filter: a term no longer exists', 'advcm' );
			}

			if ( ! empty( $f['cannot_match'] ) ) {
				/* translators: %s: post type. */
				$notes[] = sprintf( __( 'cannot match: %s posts do not have that taxonomy', 'advcm' ), $f['post_type'] );
			}

			if ( ! empty( $f['custom'] ) ) {
				$notes[] = __( 'custom query, not checked', 'advcm' );
			}

			$widget = $f['type'] . ( null !== $f['per_page'] ? ', ' . sprintf( _n( '%d post', '%d posts', (int) $f['per_page'], 'advcm' ), (int) $f['per_page'] ) : '' );

			if ( is_array( $f['newest'] ) ) {
				$newest = esc_html( gmdate( 'Y-m-d', (int) $f['newest']['date'] ) ) . ' <a href="' . esc_url( home_url( '/?p=' . (int) $f['newest']['id'] ) ) . '">' . esc_html( $f['newest']['title'] ) . '</a>';
			} else {
				$newest = empty( $f['custom'] ) && empty( $f['broken'] ) && empty( $f['cannot_match'] ) ? esc_html__( 'no post matches', 'advcm' ) : '—';
			}

			echo '<tr><td>' . self::badge( isset( $labels[ $verdict ] ) ? $labels[ $verdict ] : $verdict, $verdict ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- badge() escapes.
			echo '<td>' . $page . ' <span class="description">' . esc_html( $f['doc_type'] ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above.
			echo '<td>' . esc_html( $widget ) . '</td>';
			echo '<td><code>' . esc_html( $f['filter'] ) . '</code>' . ( $notes ? '<br><span class="description">' . esc_html( implode( '; ', $notes ) ) . '</span>' : '' ) . '</td>';
			echo '<td>' . $newest . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above.
			echo '<td>' . esc_html( null === $f['days'] ? '—' : (string) (int) $f['days'] ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * How old each page's cache was when a clear cleared it: the last 20, people's and automatic.
	 *
	 * @return void
	 */
	private static function render_measured() {
		echo '<h2>' . esc_html__( 'Cache age at clear', 'advcm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Before a clear of some pages, each page was asked how old its cached copy was. An old copy means a cache held the page; a young one means the page was already fresh and whatever looked old is the content. Only the caches this site has answer.', 'advcm' ) . '</p>';

		if ( ! ADVCM_Settings::get()['measure_before_clear'] ) {
			echo '<p class="description">' . esc_html__( 'Measuring is switched off in Settings.', 'advcm' ) . '</p>';
		}

		$rows = array_merge( ADVCM_Freshness::rows(), ADVCM_Freshness::rows( true ) );

		usort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['at'] - (int) $a['at'];
			}
		);

		$rows = array_slice( $rows, 0, 20 );

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No clear has been measured yet.', 'advcm' ) . '</p>';

			return;
		}

		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'When (UTC)', 'advcm' ), __( 'Page', 'advcm' ), __( 'Who', 'advcm' ), __( 'Answer', 'advcm' ), __( 'Cache age', 'advcm' ), __( 'Each cache', 'advcm' ), __( 'NitroPack', 'advcm' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i', (int) $row['at'] ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['url'] ) . '</code></td>';
			echo '<td>' . esc_html( self::who( (int) $row['by'], (string) $row['source'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['code'] ? 'HTTP ' . (int) $row['code'] : __( 'no answer', 'advcm' ) ) . '</td>';
			echo '<td>' . esc_html( self::age_words( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::layer_words( $row ) ) . '</td>';
			echo '<td>' . esc_html( self::nitro_words( $row ) ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * A measured age in words.
	 *
	 * @param array $row Measurement.
	 * @return string
	 */
	private static function age_words( array $row ) {
		if ( null === $row['age'] ) {
			return __( 'unknown', 'advcm' );
		}

		return ( 'at most' === $row['how'] ? __( 'at most', 'advcm' ) . ' ' : '' ) . ADVCM_Probe::duration( (int) $row['age'] );
	}

	/**
	 * Each layer's age, as the layers said it.
	 *
	 * @param array $row Measurement.
	 * @return string
	 */
	private static function layer_words( array $row ) {
		$parts = array();

		foreach ( isset( $row['ages'] ) && is_array( $row['ages'] ) ? $row['ages'] : array() as $layer => $seconds ) {
			$parts[] = $layer . ' ' . ADVCM_Probe::duration( (int) $seconds );
		}

		return empty( $parts ) ? '—' : implode( ', ', $parts );
	}

	/**
	 * NitroPack's side, on a site that has it.
	 *
	 * @param array $row Measurement.
	 * @return string
	 */
	private static function nitro_words( array $row ) {
		$parts = array();

		if ( ! empty( $row['nitro_state'] ) ) {
			$parts[] = 'NitroPack: ' . strtolower( (string) $row['nitro_state'] );
		}

		if ( ! empty( $row['nitro_copy'] ) && null !== $row['nitro_age'] ) {
			/* translators: 1: fresh or stale, 2: duration. */
			$parts[] = sprintf( __( 'copy %1$s, %2$s', 'advcm' ), $row['nitro_copy'], ADVCM_Probe::duration( (int) $row['nitro_age'] ) );
		}

		return empty( $parts ) ? '—' : implode( '; ', $parts );
	}

	/**
	 * Pages people keep clearing by hand.
	 *
	 * @return void
	 */
	private static function render_often() {
		echo '<h2>' . esc_html__( 'Cleared by hand, often', 'advcm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Pages somebody cleared twice or more, among the last 100 measured. With no rule, an auto-clear rule could do it on publish. With a young cache age, the clear was not what the page needed.', 'advcm' ) . '</p>';

		$often = ADVCM_Freshness::often_by_hand();

		if ( empty( $often ) ) {
			echo '<p>' . esc_html__( 'None.', 'advcm' ) . '</p>';

			return;
		}

		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';

		foreach ( array( __( 'Page', 'advcm' ), __( 'Clears', 'advcm' ), __( 'Latest (UTC)', 'advcm' ), __( 'Median cache age', 'advcm' ), __( 'Rule', 'advcm' ) ) as $head ) {
			echo '<th>' . esc_html( $head ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		foreach ( $often as $page ) {
			echo '<tr><td><code>' . esc_html( $page['url'] ) . '</code></td>';
			/* translators: %d: clears. */
			echo '<td>' . esc_html( sprintf( _n( '%d time', '%d times', $page['times'], 'advcm' ), $page['times'] ) ) . '</td>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d H:i', $page['last'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $page['median_age'] ? __( 'unknown', 'advcm' ) : ADVCM_Probe::duration( $page['median_age'] ) ) . '</td>';
			echo '<td>' . esc_html( $page['has_rule'] ? __( 'a rule clears it', 'advcm' ) : __( 'no rule clears this page', 'advcm' ) ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Who changed what in a tab's section: its last few records.
	 *
	 * @param string $section The tab, which is the section.
	 * @return void
	 */
	private static function render_log( $section ) {
		if ( ! in_array( $section, ADVCM_Log::SECTIONS, true ) ) {
			return;
		}

		$records = ADVCM_Log::recent( $section );

		echo '<h3 style="margin-top:24px">' . esc_html__( 'Last changes', 'advcm' ) . '</h3>';

		if ( empty( $records ) ) {
			echo '<p class="description">' . esc_html__( 'No changes yet.', 'advcm' ) . '</p>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'When (UTC)', 'advcm' ) . '</th><th>' . esc_html__( 'Who', 'advcm' ) . '</th><th>' . esc_html__( 'What', 'advcm' ) . '</th><th>' . esc_html__( 'Before → after', 'advcm' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $records as $record ) {
			$who    = '' !== $record['login'] ? $record['login'] : __( 'WordPress', 'advcm' );
			$change = '' === $record['before'] && '' === $record['after'] ? '' : $record['before'] . ' → ' . $record['after'];

			echo '<tr><td>' . esc_html( gmdate( 'Y-m-d H:i', (int) $record['at'] ) ) . '</td><td>' . esc_html( $who ) . '</td><td>' . esc_html( $record['summary'] ) . '</td><td>' . esc_html( $change ) . '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Test a post against the rules, switched off ones included: which would fire, and what they
	 * would clear. Changes nothing.
	 *
	 * @param array $rules Rules.
	 * @return void
	 */
	private static function render_rule_test( array $rules ) {
		// phpcs:disable WordPress.Security.NonceVerification -- verified below before anything is read.
		$asked = isset( $_GET['advcm_test'] ) ? trim( wp_unslash( (string) $_GET['advcm_test'] ) ) : '';
		$valid = '' !== $asked && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), ADVCM_Auto::TEST );
		// phpcs:enable

		echo '<h2>' . esc_html__( 'Test a post against the rules', 'advcm' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="auto" />';
		wp_nonce_field( ADVCM_Auto::TEST, '_wpnonce', false );
		echo '<p><input type="text" name="advcm_test" class="regular-text code" placeholder="' . esc_attr__( 'post ID or URL', 'advcm' ) . '" value="' . esc_attr( $asked ) . '" /> ';
		submit_button( __( 'Test', 'advcm' ), 'secondary', '', false );
		echo '</p><p class="description">' . esc_html__( 'Says which rules this post triggers when it is published or updated, switched-off rules included, and what they would clear. Nothing is cleared.', 'advcm' ) . '</p></form>';

		if ( ! $valid ) {
			return;
		}

		$asked = substr( $asked, 0, 2048 );
		$id    = ctype_digit( $asked ) ? (int) $asked : url_to_postid( $asked );
		$post  = $id > 0 ? get_post( $id ) : null;

		if ( ! is_object( $post ) || ! current_user_can( 'read_post', $post->ID ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No post found for that ID or URL.', 'advcm' ) . '</p></div>';

			return;
		}

		$all = array();

		foreach ( $rules as $key => $rule ) {
			$rule['enabled'] = true;
			$all[ $key ]     = $rule;
		}

		$hits = ADVCM_Auto::matching( $all, $post );

		echo '<p><strong>' . esc_html( sprintf( '%s (%s, %s)', get_the_title( $post ), $post->post_type, $post->post_status ) ) . '</strong></p>';

		if ( empty( $hits ) ) {
			echo '<p>' . esc_html__( 'No rule matches this post: publishing it clears nothing.', 'advcm' ) . '</p>';

			return;
		}

		echo '<ul style="list-style:disc;margin-left:20px">';

		foreach ( $hits as $hit ) {
			echo '<li>' . esc_html( ADVCM_Auto::describe( $hit ) . ' → ' . implode( ', ', $hit['urls'] ) ) . ( $rules[ $hit['id'] ]['enabled'] ? '' : ' <em>(' . esc_html__( 'switched off, so not yet', 'advcm' ) . ')</em>' ) . '</li>';
		}

		echo '</ul>';
	}
}
