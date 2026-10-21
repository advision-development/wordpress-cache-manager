<?php
/**
 * What only the optimizer's own service knows: pages waiting to rebuild, its last purge, which
 * tags a page carries, which pages show a post.
 *
 * NitroPack answers these from its API (a remote call with the site's key, 2 to 2.5 s each,
 * measured on BMR on 2026-10-21). So they are asked only when a person asks, never in a page
 * view, a save or WP-Cron: the summary when the Optimizer tab opens, the lookups through
 * admin-ajax after the page has loaded, so a slow answer never holds the tab. Each answer is
 * cached ten minutes, and a person gets LOOKS_PER_MINUTE lookups a minute. An answer that fails is
 * a message ("NitroPack did not answer"), and what is read from disk is still shown.
 *
 * Only reads. Nothing here purges, invalidates or changes a setting.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizer lookups.
 */
final class ADVCM_Optimizer {

	/** The ajax action and nonce. */
	const LOOKUP = 'advcm_optimizer_lookup';

	/** The admin-post action and nonce of "Refresh now". */
	const REFRESH = 'advcm_optimizer_refresh';

	/** The summary's transient. */
	const SUMMARY = 'advcm_optimizer_summary';

	/** A lookup's transient, + md5( kind|value ). */
	const LOOK = 'advcm_optimizer_look_';

	/** A person's lookups this minute, + user id. */
	const RATE = 'advcm_rate_look_';

	/** Seconds an answer is kept, and a failure. */
	const CACHE      = 600;
	const CACHE_FAIL = 60;

	/** Lookups a minute per person. */
	const LOOKS_PER_MINUTE = 10;

	/** Tags and pages kept from an answer. */
	const TAGS_KEEP  = 100;
	const PAGES_KEEP = 50;

	/** Characters kept of a purge reason, a label, a URL. */
	const TEXT_CHARS = 200;
	const URL_CHARS  = 2048;

	/** The order tags are shown in. */
	const KINDS = array( 'post', 'single', 'term', 'author', 'type', 'other' );

	/**
	 * Hook in. wp_ajax_ only, never wp_ajax_nopriv_: nobody logged out can ask.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( 'wp_ajax_' . self::LOOKUP, array( __CLASS__, 'handle_lookup' ) );
		ADVCM_Safe::action( 'admin_post_' . self::REFRESH, array( __CLASS__, 'guarded_refresh' ) );
	}

	/**
	 * "Refresh now": the summary asked again, and the oldest-copies scan started (it runs in
	 * WP-Cron; this only schedules it). Recorded under Last changes.
	 *
	 * @param int $user Who asked.
	 * @return string Empty when done, else why not.
	 */
	public static function refresh( $user ) {
		delete_transient( self::SUMMARY );

		$was     = ADVCM_Optimizer_Index::state();
		$refusal = ADVCM_Optimizer_Index::start( (int) $user );

		if ( '' === $refusal ) {
			$resumed = $was['started'] && ! $was['done'];

			ADVCM_Log::add( 'optimizer', $resumed ? 'scan_resumed' : 'scan', $resumed ? __( 'Oldest copies scan picked up where it stopped', 'advcm' ) : __( 'Oldest copies scan started', 'advcm' ) );
		}

		return $refusal;
	}

	/**
	 * The refresh button's handler, guarded.
	 *
	 * @return void
	 */
	public static function guarded_refresh() {
		try {
			self::handle_refresh();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::REFRESH, $e );
			self::back( __( 'The refresh could not start because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Refresh: anybody who may clear, with a nonce. Reading changes nothing.
	 *
	 * @return void
	 */
	public static function handle_refresh() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			wp_die( esc_html__( 'You are not allowed to refresh this.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::REFRESH );

		self::back( self::refresh( get_current_user_id() ) );
	}

	/**
	 * Back to the Optimizer tab, with the refusal if there was one.
	 *
	 * @param string $notice Refusal.
	 * @return void
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( ADVCM_Screen::tab_url( 'optimizer' ) );
		exit;
	}

	/**
	 * The site summary: the optimizer's own numbers, and the copies on disk.
	 *
	 * @return array available, why, pending (int|null), purge (array|null: at, url, tag, reason),
	 *               counts, expire, error, at.
	 */
	public static function summary() {
		if ( ! ADVCM_Optimizer_Nitropack::available() ) {
			return array(
				'available' => false,
				'why'       => ADVCM_Optimizer_Nitropack::why_not(),
			);
		}

		$cached = get_transient( self::SUMMARY );

		if ( is_array( $cached ) && isset( $cached['at'] ) ) {
			return $cached;
		}

		$summary = array(
			'available' => true,
			'why'       => '',
			'pending'   => null,
			'purge'     => null,
			'counts'    => ADVCM_Optimizer_Nitropack::folder_counts(),
			'expire'    => ADVCM_Optimizer_Nitropack::expire(),
			'error'     => '',
			'at'        => time(),
		);

		try {
			$api = self::api();

			$summary['pending'] = (int) $api->getPendingUrlsCount();

			$purge = (array) $api->getLastCachePurge();

			if ( ! empty( $purge ) ) {
				$summary['purge'] = array(
					'at'     => isset( $purge['timestamp'] ) ? (int) $purge['timestamp'] : 0,
					'url'    => isset( $purge['url'] ) && is_string( $purge['url'] ) ? substr( $purge['url'], 0, self::URL_CHARS ) : '',
					'tag'    => isset( $purge['tag'] ) && is_string( $purge['tag'] ) ? self::cut( $purge['tag'] ) : '',
					'reason' => isset( $purge['reason'] ) && is_string( $purge['reason'] ) ? self::cut( $purge['reason'] ) : '',
				);
			}
		} catch ( Throwable $e ) {
			$summary['pending'] = null;
			$summary['error']   = self::did_not_answer( $e );
		}

		// A failure is asked again in a minute; an answer is kept ten.
		set_transient( self::SUMMARY, $summary, '' === $summary['error'] ? self::CACHE : self::CACHE_FAIL );

		return $summary;
	}

	/**
	 * A lookup: `tags` for a URL on this site, or `tagged` for a post (an id or its URL).
	 *
	 * @param string $kind  tags or tagged.
	 * @param string $value URL, or post id.
	 * @param int    $user  Who asks, for the rate.
	 * @return array ok, error, and tags (tags) or post + pages (tagged).
	 */
	public static function lookup( $kind, $value, $user ) {
		if ( ! ADVCM_Optimizer_Nitropack::available() ) {
			return self::refused( ADVCM_Optimizer_Nitropack::why_not() );
		}

		if ( 'tags' === $kind ) {
			$parsed = ADVCM_Urls::parse( array( (string) $value ), home_url() );

			if ( empty( $parsed['accepted'] ) ) {
				return self::refused( __( 'That is not a page on this site.', 'advcm' ) );
			}

			$value = $parsed['accepted'][0];
		} elseif ( 'tagged' === $kind ) {
			$value = self::post_id( $value );

			if ( $value < 1 ) {
				return self::refused( __( 'That is not a post on this site.', 'advcm' ) );
			}
		} else {
			return self::refused( __( 'Unknown lookup.', 'advcm' ) );
		}

		$key    = self::LOOK . md5( $kind . '|' . $value );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rate = self::rate( $user );

		if ( '' !== $rate ) {
			return self::refused( $rate );
		}

		try {
			$answer = 'tags' === $kind ? self::tags( (string) $value ) : self::tagged( (int) $value );
		} catch ( Throwable $e ) {
			// Not cached: the person may well try again in a moment.
			return self::refused( self::did_not_answer( $e ) );
		}

		set_transient( $key, $answer, self::CACHE );

		return $answer;
	}

	/**
	 * The lookup's ajax handler.
	 *
	 * @return void
	 */
	public static function handle_lookup() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) || ! check_ajax_referer( self::LOOKUP, '_ajax_nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		$kind  = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$value = isset( $_POST['value'] ) ? trim( (string) wp_unslash( $_POST['value'] ) ) : '';
		// phpcs:enable

		try {
			$answer = self::lookup( $kind, substr( $value, 0, self::URL_CHARS ), get_current_user_id() );
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'wp_ajax_' . self::LOOKUP, $e );
			$answer = self::refused( __( 'The lookup failed because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}

		wp_send_json_success( $answer );
	}

	/**
	 * A page's tags, in words: posts first, newest first; then the page itself, terms, authors,
	 * page types, and anything else as it is.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private static function tags( $url ) {
		$raw  = array_slice( array_values( array_filter( (array) self::api()->getTags( $url ), 'is_string' ) ), 0, self::TAGS_KEEP );
		$tags = array();

		foreach ( $raw as $tag ) {
			$tags[] = self::tag( $tag );
		}

		$order = array_flip( self::KINDS );

		usort(
			$tags,
			function ( $a, $b ) use ( $order ) {
				if ( $a['kind'] !== $b['kind'] ) {
					return $order[ $a['kind'] ] < $order[ $b['kind'] ] ? -1 : 1;
				}

				if ( (int) $a['date'] !== (int) $b['date'] ) {
					return (int) $a['date'] > (int) $b['date'] ? -1 : 1;
				}

				return strcmp( $a['tag'], $b['tag'] );
			}
		);

		return array(
			'ok'    => true,
			'error' => '',
			'url'   => $url,
			'tags'  => $tags,
			'at'    => time(),
		);
	}

	/**
	 * One tag in words.
	 *
	 * @param string $tag Tag, as NitroPack names it.
	 * @return array tag, kind, label, url, date.
	 */
	private static function tag( $tag ) {
		$out = array(
			'tag'   => self::cut( $tag ),
			'kind'  => 'other',
			'label' => self::cut( $tag ),
			'url'   => '',
			'date'  => 0,
		);

		$parts = explode( ':', $tag, 2 );
		$name  = $parts[0];
		$id    = isset( $parts[1] ) ? (int) $parts[1] : 0;

		try {
			if ( ( 'post' === $name || 'single' === $name ) && $id > 0 ) {
				$post         = get_post( $id );
				$out['kind']  = $name;
				/* translators: %d: a post id. */
				$out['label'] = is_object( $post ) ? self::cut( (string) $post->post_title ) : sprintf( __( 'Post %d (no longer on the site)', 'advcm' ), $id );
				$out['url']   = is_object( $post ) ? substr( (string) get_permalink( $id ), 0, self::URL_CHARS ) : '';
				$out['date']  = is_object( $post ) && ! empty( $post->post_date_gmt ) ? (int) strtotime( $post->post_date_gmt . ' UTC' ) : 0;
			} elseif ( 'tax' === $name && $id > 0 ) {
				// NitroPack's WordPress plugin tags a term archive by its term_taxonomy_id.
				$term = get_term_by( 'term_taxonomy_id', $id );

				if ( is_object( $term ) ) {
					$link         = get_term_link( $term );
					$out['kind']  = 'term';
					$out['label'] = self::cut( $term->name . ' (' . $term->taxonomy . ')' );
					$out['url']   = is_string( $link ) ? substr( $link, 0, self::URL_CHARS ) : '';
				}
			} elseif ( 'author' === $name && $id > 0 ) {
				$user = get_userdata( $id );

				if ( is_object( $user ) ) {
					$out['kind']  = 'author';
					$out['label'] = self::cut( (string) $user->display_name );
				}
			} elseif ( 'pageType' === $name && isset( $parts[1] ) ) {
				$out['kind']  = 'type';
				$out['label'] = self::cut( $parts[1] );
			}
		} catch ( Throwable $e ) {
			return $out;
		}

		return $out;
	}

	/**
	 * The pages NitroPack says show a post, each with its copies' times per device.
	 *
	 * @param int $id Post id.
	 * @return array
	 */
	private static function tagged( $id ) {
		$rows = (array) self::api()->getTaggedUrls( 'post:' . $id, 1, self::PAGES_KEEP );

		// The SDK answers a list of rows; tolerate one wrapped in "urls".
		if ( isset( $rows['urls'] ) && is_array( $rows['urls'] ) ) {
			$rows = $rows['urls'];
		}

		$pages = array();

		foreach ( $rows as $row ) {
			$row = (array) $row;

			if ( empty( $row['url'] ) || ! is_string( $row['url'] ) ) {
				continue;
			}

			$url = substr( $row['url'], 0, self::URL_CHARS );

			if ( ! isset( $pages[ $url ] ) ) {
				if ( count( $pages ) >= self::PAGES_KEEP ) {
					continue;
				}

				$pages[ $url ] = array(
					'url'     => $url,
					'devices' => array(),
				);
			}

			$device = isset( $row['deviceType'] ) && is_string( $row['deviceType'] ) ? substr( $row['deviceType'], 0, 20 ) : 'desktop';

			$pages[ $url ]['devices'][ $device ] = isset( $row['lastOptimizedDate'] ) ? self::utc( $row['lastOptimizedDate'] ) : null;
		}

		$post = get_post( $id );

		return array(
			'ok'    => true,
			'error' => '',
			'post'  => array(
				'id'    => $id,
				'title' => is_object( $post ) ? self::cut( (string) $post->post_title ) : '',
				'url'   => is_object( $post ) ? substr( (string) get_permalink( $id ), 0, self::URL_CHARS ) : '',
			),
			'pages' => array_values( $pages ),
			'at'    => time(),
		);
	}

	/**
	 * A post id from an id or a URL on this site, or 0.
	 *
	 * @param string $value Id or URL.
	 * @return int
	 */
	private static function post_id( $value ) {
		$value = trim( (string) $value );

		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}

		$parsed = ADVCM_Urls::parse( array( $value ), home_url() );

		return empty( $parsed['accepted'] ) ? 0 : (int) url_to_postid( $parsed['accepted'][0] );
	}

	/**
	 * Count one lookup against a person's minute, or say why not.
	 *
	 * @param int $user User id.
	 * @return string
	 */
	private static function rate( $user ) {
		$key   = self::RATE . (int) $user;
		$count = (int) get_transient( $key );

		if ( $count >= self::LOOKS_PER_MINUTE ) {
			/* translators: %d: how many. */
			return sprintf( __( 'That is %d lookups in a minute. Wait a moment.', 'advcm' ), self::LOOKS_PER_MINUTE );
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return '';
	}

	/**
	 * NitroPack's API client, or an exception saying why there is none.
	 *
	 * @return object
	 * @throws RuntimeException When there is none.
	 */
	private static function api() {
		$sdk = ADVCM_Optimizer_Nitropack::sdk();
		$api = is_object( $sdk ) && method_exists( $sdk, 'getApi' ) ? $sdk->getApi() : null;

		if ( ! is_object( $api ) ) {
			throw new RuntimeException( __( 'this version of NitroPack has no API client', 'advcm' ) );
		}

		return $api;
	}

	/**
	 * "NitroPack did not answer", with why.
	 *
	 * @param Throwable $e What it threw.
	 * @return string
	 */
	private static function did_not_answer( $e ) {
		/* translators: %s: the error. */
		return sprintf( __( 'NitroPack did not answer (%s).', 'advcm' ), self::cut( ADVCM_Safe::without_paths( $e->getMessage() ) ) );
	}

	/**
	 * A refused or failed lookup.
	 *
	 * @param string $why Why.
	 * @return array
	 */
	private static function refused( $why ) {
		return array(
			'ok'    => false,
			'error' => (string) $why,
		);
	}

	/**
	 * A UTC date string from the API as a timestamp, or null.
	 *
	 * @param mixed $value Date.
	 * @return int|null
	 */
	private static function utc( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}

		$value = trim( (string) $value );
		$at    = strtotime( preg_match( '~(Z|UTC|[+-]\d\d:?\d\d)$~i', $value ) ? $value : $value . ' UTC' );

		return false === $at ? null : (int) $at;
	}

	/**
	 * Text, stripped and cut to TEXT_CHARS on a character.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function cut( $text ) {
		$text = wp_strip_all_tags( (string) $text );

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > self::TEXT_CHARS ? mb_substr( $text, 0, self::TEXT_CHARS - 1 ) . '…' : $text;
		}

		return strlen( $text ) > self::TEXT_CHARS ? substr( $text, 0, self::TEXT_CHARS ) : $text;
	}
}
