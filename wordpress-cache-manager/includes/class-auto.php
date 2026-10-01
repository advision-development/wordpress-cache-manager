<?php
/**
 * Auto-clear: when a post is published, clear the pages that list it.
 *
 * Why it exists. A listing page — an `/analysis/` page, say — is a Page
 * whose content is a query over posts. NitroPack tags a cached page with the posts it rendered
 * (`post:<id>`) and invalidates by those tags when a post is saved, so a page that has never
 * rendered the **new** post carries no tag for it and is not invalidated. With a 30-day expiry it
 * keeps showing the old list until somebody clears it by hand. A rule here says "when a post of
 * this kind is published, these URLs are stale", which is the one fact NitroPack cannot know.
 *
 * The rules this class holds:
 *
 * - **Off until somebody writes a rule.** No rule, or none switched on, and the hook returns
 *   after one option read. Installing the version that has this does nothing by itself.
 * - **The save never waits and never fails because of this.** The hook only marks the rule as
 *   triggered — one `add_option()` — and schedules one event. The clear runs later, in WP-Cron.
 *   The hook goes through `ADVCM_Safe`, so a fault here costs a log line, not the editor's save.
 * - **One clear per window, however many posts.** A rule triggered again while it is already
 *   marked is a no-op: `add_option()` inserts only when the row is absent, so two saves racing
 *   cannot both lose or both double it. Everything marked within WINDOW seconds goes out as one
 *   job, so a burst of twenty posts is one clear of `/analysis/`, not twenty.
 * - **Only the URLs a rule names**, filtered to this site's own like any per-URL clear, and never
 *   the whole site. A rule cannot ask for anything a person pressing "Clear these pages" could not.
 * - **NitroPack is invalidated, not purged, by default.** An auto-clear runs every time the
 *   editorial team publishes; a purge would serve the listing un-optimized after each one. A rule
 *   may ask for a purge.
 * - **Rules are written by administrators** (`advcm_purge_hard`). Anyone who can clear may read
 *   them and test a post against them.
 * - **`wp_after_insert_post`, not `transition_post_status`.** The block editor saves through REST,
 *   which sets a post's terms *after* the status transition, so a rule matching on a category
 *   would miss every new post written in Gutenberg. `wp_after_insert_post` (WordPress 5.6) fires
 *   once terms and meta are saved, for the classic editor, REST, `wp_update_post()` and a
 *   scheduled post going live alike.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules, the save hook and the deferred clear.
 */
final class ADVCM_Auto {

	/** Where the rules are kept. */
	const RULES = 'advcm_rules';

	/** A triggered rule is a row named this prefix plus its id, until the clear picks it up. */
	const MARK = 'advcm_auto_';

	/** The event the clear runs on. */
	const FLUSH_HOOK = 'advcm_auto_flush';

	/** The admin-post action that writes rules. */
	const SAVE = 'advcm_rules';

	/** The nonce action for testing a post against the rules. */
	const TEST = 'advcm_rules_test';

	/** The job source of an auto-clear, as History shows it. */
	const SOURCE = 'auto';

	/** Seconds between the first save that triggers a rule and the clear. */
	const WINDOW = 60;

	/** How many rules a site may have. */
	const MAX_RULES = 20;

	/** How many URLs one rule may name. */
	const MAX_URLS = 10;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( 'wp_after_insert_post', array( __CLASS__, 'saved' ), 10, 4 );

		// Always registered: a scheduled event nothing answers is what a scanner reports as an
		// orphaned cron event, and it would also never clear.
		ADVCM_Safe::action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		ADVCM_Safe::action( 'admin_post_' . self::SAVE, array( __CLASS__, 'guarded_save' ) );
	}

	/**
	 * Every stored rule, by id. Anything malformed in the row is dropped on the read.
	 *
	 * @return array
	 */
	public static function rules() {
		$stored = get_option( self::RULES, array() );
		$rules  = array();

		if ( ! is_array( $stored ) ) {
			return $rules;
		}

		foreach ( $stored as $id => $rule ) {
			if ( is_array( $rule ) && isset( $rule['post_type'], $rule['urls'] ) && is_array( $rule['urls'] ) && '' !== (string) $id ) {
				$rules[ (string) $id ] = array(
					'id'        => (string) $id,
					'enabled'   => ! empty( $rule['enabled'] ),
					'post_type' => (string) $rule['post_type'],
					'taxonomy'  => isset( $rule['taxonomy'] ) ? (string) $rule['taxonomy'] : '',
					'term'      => isset( $rule['term'] ) ? (int) $rule['term'] : 0,
					'term_name' => isset( $rule['term_name'] ) ? (string) $rule['term_name'] : '',
					'urls'      => array_values( array_map( 'strval', $rule['urls'] ) ),
					'nitropack' => isset( $rule['nitropack'] ) && 'purge' === $rule['nitropack'] ? 'purge' : 'invalidate',
				);
			}
		}

		return $rules;
	}

	/**
	 * The save hook. Marks every rule this post triggers and makes sure a clear is scheduled.
	 *
	 * @param int          $post_id     Post id.
	 * @param WP_Post      $post        The post as saved.
	 * @param bool         $update      Whether it existed before.
	 * @param WP_Post|null $post_before The post before this save, on an update.
	 * @return void
	 */
	public static function saved( $post_id, $post, $update = false, $post_before = null ) {
		$rules = self::rules();

		// The cost of this feature on a site that does not use it: the read above.
		if ( ! self::any_enabled( $rules ) || ! is_object( $post ) ) {
			return;
		}

		if ( ! self::visible_change( $post, $post_before ) ) {
			return;
		}

		$marked = 0;

		foreach ( self::matching( $rules, $post ) as $rule ) {
			// Inserts only when absent: a rule already waiting for its clear stays one entry.
			add_option( self::MARK . $rule['id'], time(), '', false );
			$marked++;
		}

		if ( $marked > 0 ) {
			self::schedule();
		}
	}

	/**
	 * Whether this save changes what a visitor can see: a post that is published now, or was
	 * published before this save (unpublished, trashed). A draft saved, a revision, an autosave
	 * changes nothing on a listing.
	 *
	 * @param object      $post        After.
	 * @param object|null $post_before Before.
	 * @return bool
	 */
	public static function visible_change( $post, $post_before ) {
		if ( ! isset( $post->post_type ) || in_array( $post->post_type, array( 'revision', 'auto-draft', 'nav_menu_item' ), true ) ) {
			return false;
		}

		$now    = isset( $post->post_status ) ? $post->post_status : '';
		$before = is_object( $post_before ) && isset( $post_before->post_status ) ? $post_before->post_status : '';

		return 'publish' === $now || 'publish' === $before;
	}

	/**
	 * Whether any rule is switched on.
	 *
	 * @param array $rules Rules.
	 * @return bool
	 */
	public static function any_enabled( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( $rule['enabled'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The switched-on rules a post triggers.
	 *
	 * @param array  $rules Rules.
	 * @param object $post  Post.
	 * @return array
	 */
	public static function matching( array $rules, $post ) {
		$out = array();

		foreach ( $rules as $rule ) {
			if ( ! $rule['enabled'] || ! isset( $post->post_type ) || $rule['post_type'] !== $post->post_type ) {
				continue;
			}

			if ( '' !== $rule['taxonomy'] && $rule['term'] > 0 && ! has_term( $rule['term'], $rule['taxonomy'], $post ) ) {
				continue;
			}

			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * Schedule the clear, once, WINDOW seconds from now.
	 *
	 * @return void
	 */
	private static function schedule() {
		if ( false !== wp_next_scheduled( self::FLUSH_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::WINDOW, self::FLUSH_HOOK );
	}

	/**
	 * When the next clear is due, or 0.
	 *
	 * @return int
	 */
	public static function next_flush() {
		$at = wp_next_scheduled( self::FLUSH_HOOK );

		return false === $at ? 0 : (int) $at;
	}

	/**
	 * The rules waiting for the next clear.
	 *
	 * @return string[] Rule ids.
	 */
	public static function waiting() {
		$out = array();

		foreach ( array_keys( self::rules() ) as $id ) {
			if ( false !== get_option( self::MARK . $id, false ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The deferred clear: every marked rule's URLs, as one per-URL job.
	 *
	 * Run inline (Fast) because this already is the background — a cron request nobody waits on.
	 * A rule switched off or deleted while it was waiting clears nothing.
	 *
	 * @return array|null The job, or null when there was nothing to clear.
	 */
	public static function flush() {
		$urls    = array();
		$purge   = false;
		$fired   = array();
		$enabled = self::rules();

		foreach ( array_keys( $enabled ) as $id ) {
			if ( false === get_option( self::MARK . $id, false ) ) {
				continue;
			}

			// Taken off before the clear runs: a post published while it runs marks the rule again
			// and schedules the next clear, rather than being lost into this one.
			delete_option( self::MARK . $id );

			$rule = $enabled[ $id ];

			if ( ! $rule['enabled'] ) {
				continue;
			}

			$fired[] = $id;
			$purge   = $purge || 'purge' === $rule['nitropack'];

			foreach ( $rule['urls'] as $url ) {
				$urls[ $url ] = $url;
			}
		}

		if ( empty( $urls ) ) {
			return null;
		}

		// Validated again: a rule was checked when it was written, but the site's address can
		// change after that, and the clear only ever names this site's own pages.
		$parsed = ADVCM_Urls::parse( array_values( $urls ), home_url() );

		if ( empty( $parsed['accepted'] ) ) {
			return null;
		}

		return ADVCM_Runner::start(
			array(
				'scope'   => 'urls',
				'urls'    => $parsed['accepted'],
				'refused' => $parsed['refused'],
				'mode'    => ADVCM_Modes::FAST,
				'source'  => self::SOURCE,
				'by'      => 0,
				'options' => array(
					'nitropack_mode' => $purge ? 'purge' : 'invalidate',
					'rules'          => $fired,
				),
			)
		);
	}

	/**
	 * Build a rule from form input, or say why not.
	 *
	 * @param array $input Unslashed input.
	 * @return array|string
	 */
	public static function rule_from( array $input ) {
		$type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : '';

		if ( '' === $type || ! post_type_exists( $type ) ) {
			return __( 'Choose a post type that exists on this site.', 'advcm' );
		}

		$taxonomy  = '';
		$term      = 0;
		$term_name = '';
		$raw_term  = isset( $input['term'] ) && is_string( $input['term'] ) ? trim( $input['term'] ) : '';

		if ( '' !== $raw_term ) {
			$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return __( 'Choose the taxonomy the term belongs to.', 'advcm' );
			}

			$found = get_term_by( 'slug', sanitize_title( $raw_term ), $taxonomy );

			if ( ! is_object( $found ) ) {
				$found = get_term_by( 'name', $raw_term, $taxonomy );
			}

			if ( ! is_object( $found ) ) {
				return sprintf( __( 'There is no term "%1$s" in %2$s.', 'advcm' ), $raw_term, $taxonomy );
			}

			$term      = (int) $found->term_id;
			$term_name = (string) $found->name;
		}

		$raw = isset( $input['urls'] ) && is_string( $input['urls'] ) ? $input['urls'] : '';

		if ( strlen( $raw ) > self::MAX_URLS * ( ADVCM_Urls::MAX_LENGTH + 2 ) ) {
			return sprintf( __( 'Up to %d URLs per rule.', 'advcm' ), self::MAX_URLS );
		}

		$parsed = ADVCM_Urls::parse( $raw, home_url() );

		if ( ! empty( $parsed['refused'] ) ) {
			return sprintf( __( 'Not on this site, so a rule cannot clear them: %s', 'advcm' ), implode( ', ', $parsed['refused'] ) );
		}

		if ( empty( $parsed['accepted'] ) ) {
			return __( 'Name at least one URL on this site for the rule to clear.', 'advcm' );
		}

		if ( count( $parsed['accepted'] ) > self::MAX_URLS ) {
			return sprintf( __( 'Up to %d URLs per rule.', 'advcm' ), self::MAX_URLS );
		}

		return array(
			'enabled'   => ! empty( $input['enabled'] ),
			'post_type' => $type,
			'taxonomy'  => $taxonomy,
			'term'      => $term,
			'term_name' => $term_name,
			'urls'      => $parsed['accepted'],
			'nitropack' => isset( $input['nitropack'] ) && 'purge' === $input['nitropack'] ? 'purge' : 'invalidate',
		);
	}

	/**
	 * Apply one change to the rules: add, switch on or off, delete. Or say why not.
	 *
	 * @param array $input Unslashed input.
	 * @return string Empty on success, else the refusal.
	 */
	public static function change( array $input ) {
		$op    = isset( $input['op'] ) && is_string( $input['op'] ) ? $input['op'] : '';
		$rules = self::rules();
		$id    = isset( $input['rule'] ) && is_string( $input['rule'] ) ? $input['rule'] : '';

		if ( 'add' === $op ) {
			if ( count( $rules ) >= self::MAX_RULES ) {
				return sprintf( __( 'This site already has %d rules, the most it may have.', 'advcm' ), self::MAX_RULES );
			}

			$rule = self::rule_from( $input );

			if ( is_string( $rule ) ) {
				return $rule;
			}

			$rules[ bin2hex( random_bytes( 4 ) ) ] = $rule;
		} elseif ( ( 'enable' === $op || 'disable' === $op || 'delete' === $op ) && isset( $rules[ $id ] ) ) {
			if ( 'delete' === $op ) {
				unset( $rules[ $id ] );
				delete_option( self::MARK . $id );
			} else {
				$rules[ $id ]['enabled'] = 'enable' === $op;
			}
		} else {
			return __( 'That rule no longer exists.', 'advcm' );
		}

		$stored = array();

		foreach ( $rules as $key => $rule ) {
			unset( $rule['id'] );
			$stored[ $key ] = $rule;
		}

		// Not autoloaded: read on a post save and on this screen, not on every page.
		update_option( self::RULES, $stored, false );

		return '';
	}

	/**
	 * The rules form's handler, guarded.
	 *
	 * @return void
	 */
	public static function guarded_save() {
		try {
			self::save();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::SAVE, $e );
			self::back( __( 'The rule could not be saved because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Handle the rules form.
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( ADVCM_Capabilities::HARD ) ) {
			wp_die( esc_html__( 'Only administrators can change auto-clear rules.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::SAVE );

		self::back( self::change( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	}

	/**
	 * Back to the Auto-clear tab, with a refusal if there was one.
	 *
	 * @param string $notice Refusal, or empty.
	 * @return void
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( ADVCM_Screen::tab_url( 'auto' ) );
		exit;
	}
}
