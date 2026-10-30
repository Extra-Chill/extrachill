<?php
/**
 * Blog ID resolver for editor abilities.
 *
 * Generic, content-type-agnostic helper that resolves a target blog_id from
 * ability input. Used by every editor ability across the network so the
 * "which site is this on?" handshake stays uniform regardless of content type.
 *
 * @package ExtraChillNetwork\Editor
 */

namespace ExtraChillNetwork\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogResolver {

	/**
	 * Resolve the target blog_id from ability input.
	 *
	 * Returns the explicit `blog_id` from input when present, otherwise falls
	 * back to the current blog. Callers can use the returned ID to decide
	 * whether they need to switch_to_blog().
	 *
	 * @param array    $input   Ability input.
	 * @param int|null $fallback Optional default blog_id when input has none.
	 *                          Falls back to get_current_blog_id() when null.
	 * @return int Resolved blog_id (always > 0).
	 */
	public static function resolve( array $input, ?int $fallback = null ): int {
		if ( isset( $input['blog_id'] ) ) {
			$blog_id = (int) $input['blog_id'];
			if ( 0 < $blog_id ) {
				return $blog_id;
			}
		}

		if ( null !== $fallback && 0 < $fallback ) {
			return $fallback;
		}

		return (int) get_current_blog_id();
	}

	/**
	 * Run a callable inside a target blog context, restoring the prior blog
	 * even when the callable throws.
	 *
	 * Skip the switch when the target matches the current blog so single-site
	 * paths stay zero-overhead. Matches the pattern used by
	 * NetworkMediaAbilities and the existing media upload routes.
	 *
	 * @template T
	 * @param int      $blog_id Target blog ID.
	 * @param callable $callback Callable executed inside the target blog.
	 * @return mixed Whatever the callable returns.
	 */
	public static function withBlog( int $blog_id, callable $callback ) {
		if ( 0 >= $blog_id || (int) get_current_blog_id() === $blog_id ) {
			return $callback();
		}

		switch_to_blog( $blog_id );
		try {
			return $callback();
		} finally {
			restore_current_blog();
		}
	}
}
