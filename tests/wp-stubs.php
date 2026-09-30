<?php
/**
 * Minimal WordPress function stubs so scanner internals can run on the CLI.
 *
 * Every stub is guarded, so an individual harness can declare a richer version
 * of any of these before requiring this file.
 */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = '' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		return trim( strip_tags( (string) $text ) );
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	function get_locale() {
		return isset( $GLOBALS['stub_locale'] ) ? $GLOBALS['stub_locale'] : 'en_US';
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		$store = isset( $GLOBALS['stub_user_meta'] ) ? $GLOBALS['stub_user_meta'] : array();

		return isset( $store[ $user_id ][ $key ] ) ? $store[ $user_id ][ $key ] : ( $single ? '' : array() );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $type ) {
		$known = isset( $GLOBALS['stub_post_types'] ) ? $GLOBALS['stub_post_types'] : array( 'post', 'page' );

		return in_array( $type, $known, true );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id ) {
		$store = isset( $GLOBALS['stub_post_status'] ) ? $GLOBALS['stub_post_status'] : array();

		return isset( $store[ $id ] ) ? $store[ $id ] : 'publish';
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}

if ( ! function_exists( 'get_edit_user_link' ) ) {
	function get_edit_user_link( $id ) {
		return 'https://example.test/wp-admin/user-edit.php?user_id=' . (int) $id;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Honours a value a harness planted, so a filter that exists as an escape hatch can be
	 * shown to be one.
	 *
	 * It returned `$value` unconditionally, which meant an assertion that a filter is
	 * consulted passed whether or not the code consulted it — the escape hatch could have
	 * been deleted and every suite would still have agreed it worked.
	 */
	function apply_filters( $hook, $value ) {
		$planted = isset( $GLOBALS['filter_values'] ) ? $GLOBALS['filter_values'] : array();

		return array_key_exists( $hook, $planted ) ? $planted[ $hook ] : $value;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );

		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( $string, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return untrailingslashit( $string ) . '/';
	}
}

if ( ! function_exists( 'wp_check_invalid_utf8' ) ) {
	function wp_check_invalid_utf8( $string, $strip = false ) {
		return $string;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/*
	 * Network-wide options. Reached through the quarantine module, which the malware
	 * plugin's export now consults to decide whether a finding can offer that action —
	 * so a report built in a test walks into it without a WordPress around.
	 */
	function get_site_option( $name, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://example.test';
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) {
		return (string) $number;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- stub.
	}
}
