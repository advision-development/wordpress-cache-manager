<?php
/**
 * What NitroPack keeps for a page, read from the copies it stores on disk.
 *
 * The only code in this plugin that knows where NitroPack keeps its copies: the probe, Freshness,
 * the NitroPack tab and its scan all go through here, so there is one rule, not several.
 *
 * **Paths come from NitroPack's own SDK**, never written here: its cache directory
 * (`getCacheDir()`), its devices (`Device::getKnownTypes()`), its folder rule
 * (`Pagecache::getUrlDir()` with the config's `URLPathVersion`) and its URL normalization. If
 * NitroPack changes how it stores copies, the SDK's answer follows; if a piece the reader needs is
 * gone, the reader says NitroPack is not available rather than guess a path. A version of the
 * folder rule it has not seen (only 1 and 2 are known) counts as gone.
 *
 * **Read-only.** `is_dir`, `scandir`, `file_exists`, `filemtime` — never `hasCache()`, which
 * renames files when invalidated copies are on, and never an SDK call that writes or purges.
 *
 * **A copy's time is its file's mtime**: the SDK touches each file to the `x-cache-ctime` it
 * stores, which is the time the copy was made (on BMR on 2026-10-21 the file, the stored header
 * and the API's `lastOptimizedDate` agreed to the second). How long it lives is not stored: the SDK
 * takes created + `PageCache.ExpireTime`, then serves it stale for `StaleExpireTime` while it
 * rebuilds. So does this, as an estimate.
 *
 * Every call is guarded: anything missing, unreadable or throwing is an answer ("not available",
 * no copies), never an error into the page that asked.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NitroPack reader.
 */
final class ADVCM_Optimizer_Nitropack {

	/** The folder rule versions this reader knows. */
	const PATH_VERSIONS = array( 1, 2 );

	/** @var object|null|false Replaces the SDK, for tests; false = no override. */
	private static $sdk = false;

	/**
	 * Test seam: the SDK object, or null for none. Pass false to go back to NitroPack's own.
	 *
	 * @param object|null|false $sdk SDK.
	 * @return void
	 */
	public static function use_sdk( $sdk ) {
		self::$sdk = $sdk;
	}

	/**
	 * The optimizer's name, as people know it: the tab is called this, not "Optimizer".
	 *
	 * @return string
	 */
	public static function name() {
		return 'NitroPack';
	}

	/**
	 * Whether NitroPack's copies can be read on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		return '' === self::why_not();
	}

	/**
	 * Why NitroPack's copies cannot be read, or '' when they can.
	 *
	 * @return string
	 */
	public static function why_not() {
		try {
			$sdk = self::sdk();

			if ( null === $sdk ) {
				return __( 'NitroPack is not on this site.', 'advcm' );
			}

			if ( ! method_exists( $sdk, 'getCacheDir' ) || ! method_exists( $sdk, 'getConfig' ) ) {
				return __( 'This version of NitroPack does not say where it keeps its copies.', 'advcm' );
			}

			foreach ( array( 'NitroPack\SDK\Device', 'NitroPack\SDK\Pagecache', 'NitroPack\Url\Url' ) as $class ) {
				if ( ! class_exists( $class ) ) {
					/* translators: %s: a class name. */
					return sprintf( __( 'NitroPack\'s %s is missing.', 'advcm' ), $class );
				}
			}

			if ( ! in_array( self::path_version(), self::PATH_VERSIONS, true ) ) {
				return __( 'NitroPack names its folders with a version of its rule this plugin does not know, so it does not guess.', 'advcm' );
			}

			$dir = self::cache_dir();

			if ( '' === $dir || ! @is_dir( $dir ) || ! @is_readable( $dir ) ) {
				return __( 'NitroPack\'s cache directory is not there or cannot be read.', 'advcm' );
			}
		} catch ( Throwable $e ) {
			/* translators: %s: the error. */
			return sprintf( __( 'NitroPack could not be read: %s', 'advcm' ), ADVCM_Safe::without_paths( $e->getMessage() ) );
		}

		return '';
	}

	/**
	 * NitroPack's devices, as its SDK names them.
	 *
	 * @return string[]
	 */
	public static function devices() {
		if ( ! self::available() ) {
			return array();
		}

		try {
			return array_values( array_filter( (array) \NitroPack\SDK\Device::getKnownTypes(), 'is_string' ) );
		} catch ( Throwable $e ) {
			return array();
		}
	}

	/**
	 * How long a copy lives, and how long it is then served stale, in seconds.
	 *
	 * @return array expire, stale.
	 */
	public static function expire() {
		try {
			$config = self::sdk()->getConfig();

			return array(
				'expire' => isset( $config->PageCache->ExpireTime ) ? (int) $config->PageCache->ExpireTime : 0,
				'stale'  => isset( $config->PageCache->StaleExpireTime ) ? (int) $config->PageCache->StaleExpireTime : 0,
			);
		} catch ( Throwable $e ) {
			return array(
				'expire' => 0,
				'stale'  => 0,
			);
		}
	}

	/**
	 * A URL as NitroPack keys it.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( $url ) {
		try {
			return (string) ( new \NitroPack\Url\Url( (string) $url ) )->getNormalized();
		} catch ( Throwable $e ) {
			return (string) $url;
		}
	}

	/**
	 * A device's folder, or '' when it is not there.
	 *
	 * @param string $device Device.
	 * @return string
	 */
	public static function device_dir( $device ) {
		$dir = self::cache_dir() . '/' . $device;

		return @is_dir( $dir ) ? $dir : '';
	}

	/**
	 * The folder NitroPack keeps a URL's copy in, for a device.
	 *
	 * @param string $device      Device.
	 * @param string $normalized  URL, normalized.
	 * @param bool   $invalidated The invalidated copy's folder.
	 * @return string
	 */
	public static function url_dir( $device, $normalized, $invalidated ) {
		try {
			return (string) \NitroPack\SDK\Pagecache::getUrlDir( self::cache_dir() . '/' . $device, $normalized, $invalidated, self::path_version() );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Every device's copy of a URL.
	 *
	 * @param string $url URL on this site.
	 * @return array device => array( created (int|null), lives_until (int|null), state: fresh|stale|invalidated|none ).
	 */
	public static function copies( $url ) {
		$out = array();

		if ( ! self::available() ) {
			return $out;
		}

		$normalized = self::normalize( $url );
		$expire     = self::expire()['expire'];

		foreach ( self::devices() as $device ) {
			$out[ $device ] = self::copy_in( $device, $normalized, $expire );
		}

		return $out;
	}

	/**
	 * One device's copy.
	 *
	 * @param string $device     Device.
	 * @param string $normalized URL, normalized.
	 * @param int    $expire     ExpireTime.
	 * @return array
	 */
	private static function copy_in( $device, $normalized, $expire ) {
		$none = array(
			'created'     => null,
			'lives_until' => null,
			'state'       => 'none',
		);

		try {
			$fresh = self::newest( self::url_dir( $device, $normalized, false ) );

			if ( null !== $fresh ) {
				$state = $fresh['stale'] ? 'stale' : 'fresh';
				$at    = $fresh['at'];
			} else {
				$invalid = self::newest( self::url_dir( $device, $normalized, true ) );

				if ( null === $invalid ) {
					return $none;
				}

				$state = 'invalidated';
				$at    = $invalid['at'];
			}

			return array(
				'created'     => $at,
				'lives_until' => $expire > 0 ? $at + $expire : null,
				'state'       => $state,
			);
		} catch ( Throwable $e ) {
			return $none;
		}
	}

	/**
	 * The newest copy file in a folder: a page (.html) before a stale one (.stale). A gzipped
	 * file (.html.gz, .stale.gz) counts as its page: NitroPack keeps one beside the other, and an
	 * install may keep only the gzipped one.
	 *
	 * @param string $folder Folder.
	 * @return array|null at, stale.
	 */
	public static function newest( $folder ) {
		if ( '' === $folder || ! @is_dir( $folder ) ) {
			return null;
		}

		$best = null;

		foreach ( (array) @scandir( $folder ) as $name ) {
			$name  = (string) $name;
			$base  = '.gz' === substr( $name, -3 ) ? substr( $name, 0, -3 ) : $name;
			$stale = '.stale' === substr( $base, -6 );

			if ( ! $stale && '.html' !== substr( $base, -5 ) ) {
				continue;
			}

			$at = @filemtime( $folder . '/' . $name );

			if ( false === $at ) {
				continue;
			}

			// A fresh page outranks a stale one whatever their times: it is what NitroPack serves.
			if ( null === $best || ( $best['stale'] && ! $stale ) || ( $best['stale'] === $stale && $at > $best['at'] ) ) {
				$best = array(
					'at'    => (int) $at,
					'stale' => $stale,
				);
			}
		}

		return $best;
	}

	/**
	 * How many folders each device holds, invalidated apart, and whether its folder is there
	 * and readable. Counts entries only; reads no file.
	 *
	 * @return array device => array( folders, invalidated, state: ok|missing|unreadable ).
	 */
	public static function folder_counts() {
		$out = array();

		foreach ( self::devices() as $device ) {
			$folders     = 0;
			$invalidated = 0;
			$state       = 'ok';

			try {
				$dir     = self::device_dir( $device );
				$entries = '' === $dir ? false : @scandir( $dir );

				if ( false === $entries ) {
					$state   = '' === $dir ? 'missing' : 'unreadable';
					$entries = array();
				}

				foreach ( $entries as $name ) {
					if ( '.' === $name || '..' === $name ) {
						continue;
					}

					if ( '_i' === substr( (string) $name, -2 ) ) {
						$invalidated++;
					} else {
						$folders++;
					}
				}
			} catch ( Throwable $e ) {
				$folders = 0;
				$state   = 'unreadable';
			}

			$out[ $device ] = array(
				'folders'     => $folders,
				'invalidated' => $invalidated,
				'state'       => $state,
			);
		}

		return $out;
	}

	/**
	 * NitroPack's cache directory, or ''.
	 *
	 * @return string
	 */
	public static function cache_dir() {
		try {
			$sdk = self::sdk();

			return null === $sdk || ! method_exists( $sdk, 'getCacheDir' ) ? '' : rtrim( (string) $sdk->getCacheDir(), '/' );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * The config's folder rule version, 1 when it says none (the SDK's own default).
	 *
	 * @return int
	 */
	private static function path_version() {
		$config = self::sdk()->getConfig();

		return ! empty( $config->URLPathVersion ) ? (int) $config->URLPathVersion : 1;
	}

	/**
	 * The SDK, or null.
	 *
	 * @return object|null
	 */
	public static function sdk() {
		if ( false !== self::$sdk ) {
			return is_object( self::$sdk ) ? self::$sdk : null;
		}

		if ( ! function_exists( 'get_nitropack_sdk' ) ) {
			return null;
		}

		try {
			$sdk = get_nitropack_sdk();
		} catch ( Throwable $e ) {
			return null;
		}

		return is_object( $sdk ) ? $sdk : null;
	}
}
