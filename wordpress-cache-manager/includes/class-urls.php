<?php
/**
 * Which URLs a per-URL request may name.
 *
 * Only this site's own. A URL is either a path (`/analysis/`) or an absolute URL whose host is
 * this site's host, with or without `www.`. Anything else is refused and named, not dropped in
 * silence — a list that loses an entry quietly reads as a purge that did not work.
 *
 * The URLs reach NitroPack's API and WP Rocket's cache paths, so a foreign host is not merely
 * pointless: it is this site asking a vendor to act on somebody else's address.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL validation and resolution.
 */
final class ADVCM_Urls {

	/** How many URLs one request may carry. */
	const MAX = 50;

	/**
	 * Split raw input into accepted absolute URLs and refused entries.
	 *
	 * @param string|array $input One per line, or an array.
	 * @param string       $home  This site's home URL.
	 * @return array array( accepted => string[], refused => string[] )
	 */
	public static function parse( $input, $home ) {
		$lines    = is_array( $input ) ? $input : preg_split( '~[\r\n]+~', (string) $input );
		$home     = wp_parse_url( (string) $home );
		$scheme   = isset( $home['scheme'] ) ? $home['scheme'] : 'https';
		$host     = isset( $home['host'] ) ? strtolower( $home['host'] ) : '';
		$port     = isset( $home['port'] ) ? (int) $home['port'] : 0;
		$accepted = array();
		$refused  = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$url = self::absolute( $line, $scheme, $host, $port );

			if ( '' === $url ) {
				$refused[] = $line;
				continue;
			}

			$accepted[ $url ] = $url;
		}

		$accepted = array_values( $accepted );

		if ( count( $accepted ) > self::MAX ) {
			foreach ( array_slice( $accepted, self::MAX ) as $over ) {
				$refused[] = $over . ' (over the limit of ' . self::MAX . ')';
			}

			$accepted = array_slice( $accepted, 0, self::MAX );
		}

		return array(
			'accepted' => $accepted,
			'refused'  => $refused,
		);
	}

	/**
	 * One entry as an absolute URL on this site, or empty.
	 *
	 * @param string $line   The entry.
	 * @param string $scheme This site's scheme.
	 * @param string $host   This site's host.
	 * @param int    $port   This site's port, 0 when it is the scheme's default.
	 * @return string
	 */
	private static function absolute( $line, $scheme, $host, $port ) {
		// The origin every accepted URL is rebuilt on. The port is part of it: a site served on
		// :8080 has its pages cached under :8080, and a purge of the same path on :80 clears
		// nothing — found by running the plugin, not by the suite.
		$origin = $scheme . '://' . $host . ( $port ? ':' . $port : '' );

		if ( '' === $host ) {
			return '';
		}

		// A path. `//host/…` is protocol-relative and names a host, so it is not a path.
		// Given this site's own host, so it takes the same path as an absolute URL below.
		if ( '/' === $line[0] && ( strlen( $line ) < 2 || '/' !== $line[1] ) ) {
			$line = $origin . $line;
		}

		$parts = wp_parse_url( $line );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		// Credentials in a URL are never a page on this site.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		if ( self::bare( strtolower( $parts['host'] ) ) !== self::bare( $host ) ) {
			return '';
		}

		// Same host on another port is another server. A URL naming no port is taken as this
		// site's, the same leniency as http for https: somebody pasting an address is naming the
		// page, not the connection.
		if ( ( isset( $parts['port'] ) ? (int) $parts['port'] : $port ) !== $port ) {
			return '';
		}

		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $origin . self::clean_path( $path ) . $query;
	}

	/**
	 * A host without a leading `www.`.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	private static function bare( $host ) {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * A path with no fragment and no dot segments.
	 *
	 * @param string $path Path, possibly with a query or fragment.
	 * @return string
	 */
	private static function clean_path( $path ) {
		$path = preg_replace( '~#.*$~', '', $path );

		// `.` and `..` have no business in a cache purge, so they are resolved here and no vendor
		// ever sees one — resolved, not dropped, because `/a/../b/` is `/b/` to every cache in
		// front of the site, and a purge of `/a/b/` would clear a page nobody named. Segment by
		// segment, because a single str_replace misses `/..` at the end.
		$trailing = '/' === substr( $path, -1 ) || '/..' === substr( $path, -3 ) || '/.' === substr( $path, -2 );
		$segments = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}

			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}

			$segments[] = $segment;
		}

		$path = '/' . implode( '/', $segments );

		return ( $trailing && '/' !== $path ) ? $path . '/' : $path;
	}

	/**
	 * The post ids a list of URLs resolves to.
	 *
	 * The front page resolves to the page set as front page, since `url_to_postid()` answers 0
	 * for the home URL.
	 *
	 * @param string[] $urls Absolute URLs.
	 * @return int[]
	 */
	public static function post_ids( array $urls ) {
		$ids  = array();
		$home = untrailingslashit( home_url() );

		foreach ( $urls as $url ) {
			$id = (int) url_to_postid( $url );

			if ( 0 === $id && untrailingslashit( $url ) === $home ) {
				$id = (int) get_option( 'page_on_front', 0 );
			}

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}
}
