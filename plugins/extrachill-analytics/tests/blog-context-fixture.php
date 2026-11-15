<?php
/**
 * Network blog-context fixture for the isolated analytics harness.
 *
 * @package ExtraChill\Analytics
 */

if ( ! function_exists( 'ec_with_blog' ) ) {
	/**
	 * Network blog-context contract fixture for the isolated analytics harness.
	 *
	 * Production loads extrachill-network/inc/core/blog-ids.php; this suite
	 * runs without sibling plugins, so it mirrors that contract: run in place
	 * when already current, otherwise switch and always restore.
	 *
	 * @param int      $blog_id  Target blog ID.
	 * @param callable $callback Callable executed inside the target blog.
	 * @return mixed Callback result.
	 */
	function ec_with_blog( int $blog_id, callable $callback ) {
		if ( 0 >= $blog_id || ! function_exists( 'switch_to_blog' ) || (int) get_current_blog_id() === $blog_id ) {
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
