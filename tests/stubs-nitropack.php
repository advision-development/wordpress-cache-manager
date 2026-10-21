<?php
/**
 * NitroPack's SDK as far as CacheManager reads it, shaped like the real one (NitroPack 1.19.4 on
 * BMR, read 2026-10-21): the classes, namespaces and the path rule are the SDK's own.
 *
 * @package ADVCM
 */

namespace NitroPack\SDK {
	if ( ! class_exists( 'NitroPack\SDK\Device' ) ) {
		class Device {
			public static function getKnownTypes() {
				return array( 'mobile', 'tablet', 'desktop' );
			}
		}
	}

	if ( ! class_exists( 'NitroPack\SDK\Pagecache' ) ) {
		class Pagecache {
			// As the SDK: version 2 is md5 of the URL; an invalidated copy is the same folder + "_i".
			public static function getUrlDir( $dataDir, $url, $useInvalidated = false, $pathVersion = 1 ) {
				$safe = str_replace( array( '/', '?', ':', ';', '=', '&', '.', '--', '%', '~' ), '-', $url );
				$dir  = $dataDir . '/' . ( 2 === (int) $pathVersion ? md5( $url ) : md5( $safe ) );

				return $useInvalidated ? $dir . '_i' : $dir;
			}
		}
	}
}

namespace NitroPack\Url {
	if ( ! class_exists( 'NitroPack\Url\Url' ) ) {
		class Url {
			private $url;

			public function __construct( $url ) {
				$this->url = $url;
			}

			public function getNormalized( $resolvePathNavigation = true, $includeHash = true ) {
				return strtolower( $this->url );
			}
		}
	}
}

namespace {
	/**
	 * A stand-in SDK object: a cache directory, a config, and an API whose answers a test sets.
	 */
	class Stub_Nitro_Sdk {
		public $dir;
		public $config;
		public $api;

		public function __construct( $dir, $expire = 86400, $stale = 300, $version = 2 ) {
			$this->dir    = $dir;
			$this->config = (object) array(
				'URLPathVersion' => $version,
				'PageCache'      => (object) array( 'ExpireTime' => $expire, 'StaleExpireTime' => $stale ),
			);
		}

		public function getCacheDir() {
			return $this->dir;
		}

		public function getConfig() {
			return $this->config;
		}

		public function getApi() {
			return $this->api;
		}
	}

	/**
	 * Write a copy file into the stub cache: device, URL, kind (fresh|stale|invalidated), mtime.
	 */
	function nitro_copy( $dir, $device, $url, $kind, $mtime ) {
		$folder = \NitroPack\SDK\Pagecache::getUrlDir( $dir . '/' . $device, strtolower( $url ), 'invalidated' === $kind, 2 );
		@mkdir( $folder, 0777, true );
		$file = $folder . '/ssl-d41d8cd98f00b204.html' . ( 'stale' === $kind ? '.stale' : '' );
		file_put_contents( $file, '<html></html>' );
		touch( $file, $mtime );

		return $file;
	}
}
