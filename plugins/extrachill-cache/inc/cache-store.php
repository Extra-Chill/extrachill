<?php
/**
 * Cache store: path resolution, key hashing, read/write/delete of cached
 * page payloads.
 *
 * This module is intentionally free of WordPress-plugin-API dependencies so it
 * can be reached from BOTH entry points:
 *   1. The advanced-cache.php drop-in (runs before WP loads) — SERVE path.
 *   2. The WordPress output-buffer callback — STORE path.
 *
 * Modeled on Breeze:
 *   - breeze_get_cache_base_path()  inc/functions.php:33
 *   - breeze_serve_cache()          inc/cache/execute-cache.php:597
 *   - breeze_cache()                inc/cache/execute-cache.php:291
 * Breeze stored a serialized array( 'body' => ..., 'headers' => ... ) keyed by
 * sha512 of the request URL. We keep the same on-disk shape (serialized
 * body+headers) but with a single, clearly-named directory and a strict TTL.
 *
 * @package ExtraChillCache
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'EXTRACHILL_CACHE_DROPIN' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_cache_base_dir' ) ) {
	/**
	 * Absolute base directory for cached payloads.
	 *
	 * When EXTRACHILL_CACHE_DIR is defined (normal plugin load) we use it.
	 * The drop-in defines it itself before including this file so the serve
	 * gate can resolve the same path without loading the plugin bootstrap.
	 *
	 * @return string Base dir WITHOUT trailing slash.
	 */
	function extrachill_cache_base_dir() {
		if ( defined( 'EXTRACHILL_CACHE_DIR' ) ) {
			return rtrim( EXTRACHILL_CACHE_DIR, '/\\' );
		}

		// Fallback mirrors the constant default in extrachill-cache.php.
		return rtrim( WP_CONTENT_DIR, '/\\' ) . '/cache/extrachill-cache';
	}
}

if ( ! function_exists( 'extrachill_cache_blog_dir' ) ) {
	/**
	 * Per-blog cache directory (multisite-aware).
	 *
	 * Breeze partitioned its cache by blog_id (inc/functions.php:33). We do the
	 * same so purging one site never touches another's cache.
	 *
	 * @param int $blog_id Blog ID. 0 = resolve from context.
	 * @return string Directory path WITHOUT trailing slash.
	 */
	function extrachill_cache_blog_dir( $blog_id = 0 ) {
		$base = extrachill_cache_base_dir();

		if ( empty( $blog_id ) ) {
			// In the drop-in layer the caller supplies blog_id explicitly.
			// Inside WP we can resolve it from the current site.
			if ( function_exists( 'get_current_blog_id' ) ) {
				$blog_id = get_current_blog_id();
			}
		}

		$blog_id = absint( $blog_id );
		if ( $blog_id > 0 ) {
			return $base . '/' . $blog_id;
		}

		return $base;
	}
}

if ( ! function_exists( 'extrachill_cache_key' ) ) {
	/**
	 * Hash a normalized cache identity into an on-disk filename stem.
	 *
	 * Breeze hashed the full request URL with sha512 (execute-cache.php:604).
	 * We keep sha512 and fold the device bucket into the identity string so
	 * desktop and mobile variants never collide.
	 *
	 * @param string $url_identity Normalized URL (scheme+host+path+kept query).
	 * @param string $device       'desktop' or 'mobile'.
	 * @return string sha512 hex digest.
	 */
	function extrachill_cache_key( $url_identity, $device = 'desktop' ) {
		$device = ( 'mobile' === $device ) ? 'mobile' : 'desktop';
		return hash( 'sha512', $device . '|' . $url_identity );
	}
}

if ( ! function_exists( 'extrachill_cache_file_path' ) ) {
	/**
	 * Full path to the cache file for a given key + blog.
	 *
	 * @param string $key     sha512 key from extrachill_cache_key().
	 * @param int    $blog_id Blog ID.
	 * @return string Absolute file path.
	 */
	function extrachill_cache_file_path( $key, $blog_id = 0 ) {
		return extrachill_cache_blog_dir( $blog_id ) . '/' . $key . '.html';
	}
}

if ( ! function_exists( 'extrachill_cache_url_identity' ) ) {
	/**
	 * Determine whether a query string contains tracking parameters only.
	 *
	 * @param string $query Query string without the leading question mark.
	 * @return bool True when every parameter is safe to discard.
	 */
	function extrachill_cache_query_is_tracking_only( $query ) {
		if ( '' === $query ) {
			return true;
		}

		parse_str( $query, $args );
		if ( empty( $args ) ) {
			return true;
		}

		$tracking_keys = array( 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga' );
		foreach ( array_keys( $args ) as $key ) {
			if ( 0 !== strpos( $key, 'utm_' ) && ! in_array( $key, $tracking_keys, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize a URL into the identity used by the cache key.
	 *
	 * Tracking-only query strings collapse to the canonical path. Functional
	 * query strings are retained, although request gates keep them out of the
	 * page cache.
	 *
	 * @param string $url Absolute URL.
	 * @return string Normalized identity, or an empty string when invalid.
	 */
	function extrachill_cache_normalize_url_identity( $url ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Shared with the pre-WordPress drop-in where wp_parse_url() is unavailable.
		$parts = parse_url( $url );
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$identity = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$identity .= ':' . (int) $parts['port'];
		}
		$identity .= isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		if ( ! empty( $parts['query'] ) && ! extrachill_cache_query_is_tracking_only( $parts['query'] ) ) {
			$identity .= '?' . $parts['query'];
		}

		return $identity;
	}

	/**
	 * Build a normalized URL identity string for the CURRENT request.
	 *
	 * Modeled on Breeze's breeze_get_url_path() (execute-cache.php:574): scheme
	 * + host + REQUEST_URI. We do NOT include cookies or user info here — this
	 * cache is anonymous-only (see the serve gate's logged-in bypass).
	 *
	 * @return string Normalized identity, or '' if the request can't be keyed.
	 */
	function extrachill_cache_url_identity() {
		if ( empty( $_SERVER['HTTP_HOST'] ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$is_https = ( ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
			|| ( ! empty( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] ) );
		$scheme   = $is_https ? 'https://' : 'http://';

		$host = strtolower( (string) $_SERVER['HTTP_HOST'] );
		$uri  = (string) $_SERVER['REQUEST_URI'];

		return extrachill_cache_normalize_url_identity( $scheme . rtrim( $host, '/' ) . $uri );
	}
}

if ( ! function_exists( 'extrachill_cache_delete_url' ) ) {
	/**
	 * Delete desktop and mobile cache variants for one absolute URL.
	 *
	 * @param string $url     Absolute URL.
	 * @param int    $blog_id Blog ID.
	 * @return int Number of files deleted.
	 */
	function extrachill_cache_delete_url( $url, $blog_id = 0 ) {
		$identity = extrachill_cache_normalize_url_identity( $url );
		if ( '' === $identity ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array( 'desktop', 'mobile' ) as $device ) {
			$path = extrachill_cache_file_path( extrachill_cache_key( $identity, $device ), $blog_id );
			if ( is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Targeted local cache invalidation; a concurrent miss is harmless.
				++$deleted;
			}
		}

		return $deleted;
	}
}

if ( ! function_exists( 'extrachill_cache_is_mobile_request' ) ) {
	/**
	 * Lightweight desktop/mobile bucketing.
	 *
	 * Breeze pulled in the full Mobile_Detect library (execute-cache.php:73).
	 * We deliberately keep this dependency-free: a coarse UA check is enough to
	 * separate mobile from desktop cache variants. If a UA is ambiguous it
	 * falls into the desktop bucket, which is the safe default.
	 *
	 * @return bool True if the request looks like a mobile device.
	 */
	function extrachill_cache_is_mobile_request() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return false;
		}

		$ua = (string) $_SERVER['HTTP_USER_AGENT'];

		// Tablets are treated as desktop (matches how Breeze desktop/mobile
		// split behaved for the platform's enabled config).
		if ( preg_match( '/iPad|Tablet/i', $ua ) ) {
			return false;
		}

		return (bool) preg_match( '/Mobile|Android.+Mobile|iPhone|iPod|BlackBerry|Opera Mini|IEMobile/i', $ua );
	}
}

if ( ! function_exists( 'extrachill_cache_read' ) ) {
	/**
	 * Read a cached payload if present and fresh.
	 *
	 * @param string $key     sha512 key.
	 * @param int    $blog_id Blog ID.
	 * @param int    $ttl     TTL in seconds.
	 * @return array|false array( 'body' => string, 'headers' => array ) or false.
	 */
	function extrachill_cache_read( $key, $blog_id = 0, $ttl = 0 ) {
		$path = extrachill_cache_file_path( $key, $blog_id );

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reachable from the pre-WP drop-in serve gate where WP_Filesystem is unavailable; a filesystem warning must not surface. The @ guards a benign missing-file check.
		if ( ! @is_file( $path ) ) {
			return false;
		}

		if ( $ttl <= 0 ) {
			$ttl = defined( 'EXTRACHILL_CACHE_TTL' ) ? EXTRACHILL_CACHE_TTL : DAY_IN_SECONDS;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reachable from the pre-WP drop-in serve gate; the @ guards a benign filemtime failure (handled via the false check).
		$mtime = @filemtime( $path );
		if ( false === $mtime || ( time() - $mtime ) > $ttl ) {
			// The file is expired and will never be served again. Delete it now
			// so stale reads do not leave dead weight behind. Reachable from the
			// pre-WP drop-in serve gate, so @-guard the unlink and avoid WP
			// filesystem helpers.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes a stale local cache file; reachable pre-WP where wp_delete_file is unavailable. The @ guards a benign unlink failure.
			@unlink( $path );
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local cache payload from the pre-WP drop-in serve gate where WP_Filesystem is unavailable. The @ guards a benign read failure (handled below).
		$raw = @file_get_contents( $path );
		if ( false === $raw || '' === $raw ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Trusted, self-written cache payload; the @ guards a benign unserialize failure (validated via is_array below).
		$data = @unserialize( $raw );
		if ( ! is_array( $data ) || empty( $data['body'] ) ) {
			return false;
		}

		return $data;
	}
}

if ( ! function_exists( 'extrachill_cache_write' ) ) {
	/**
	 * Write a payload to the cache.
	 *
	 * @param string $key     sha512 key.
	 * @param string $body    Rendered HTML.
	 * @param array  $headers Array of array( 'name' => ..., 'value' => ... ).
	 * @param int    $blog_id Blog ID.
	 * @return bool True on success.
	 */
	function extrachill_cache_write( $key, $body, $headers = array(), $blog_id = 0 ) {
		$dir = extrachill_cache_blog_dir( $blog_id );

		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					return false;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Fallback dir creation when wp_mkdir_p is unavailable (pre-WP drop-in layer); the @ guards a benign race handled by the trailing is_dir check.
			} elseif ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Self-written cache payload stored on disk; format parity with the reader (unserialize) is intentional.
		$data = serialize(
			array(
				'body'    => $body,
				'headers' => $headers,
			)
		);

		$path = extrachill_cache_file_path( $key, $blog_id );

		// Atomic-ish write via temp file + rename to avoid serving half-written
		// payloads to a concurrent request.
		$tmp = $path . '.' . getmypid() . '.tmp';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a local cache payload; reachable from the pre-WP layer where WP_Filesystem is unavailable. The @ guards a benign write failure (handled via the false check).
		if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic temp-file rename for the local cache payload; reachable pre-WP where WP_Filesystem is unavailable. The @ guards a benign rename failure (handled below).
		if ( ! @rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Cleans up the local temp file after a failed rename; reachable pre-WP where wp_delete_file is unavailable.
			@unlink( $tmp );
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'extrachill_cache_rrmdir' ) ) {
	/**
	 * Recursively delete a directory's contents (and the directory).
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	function extrachill_cache_rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Enumerates a local cache directory during purge; the @ guards a benign scandir failure (handled via the false check).
		$items = @scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				extrachill_cache_rrmdir( $path );
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes a local cache file during purge; reachable pre-WP where wp_delete_file is unavailable. The @ guards a benign unlink failure.
				@unlink( $path );
			}
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the emptied local cache directory during purge; reachable pre-WP where WP_Filesystem is unavailable. The @ guards a benign rmdir failure.
		@rmdir( $dir );
	}
}
