<?php
/**
 * Comment Author Link
 *
 * Resolves the author markup shown on each comment. Registered commenters
 * (comment user_id > 0) link to their network profile through the
 * extrachill-network helpers; guests keep WordPress's default author output.
 * The network plugin is optional: without it, every comment falls back to
 * get_comment_author_link().
 *
 * @package ExtraChill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'extrachill_comment_author_html' ) ) :

	/**
	 * Get the author markup for a comment.
	 *
	 * @param WP_Comment $comment Comment object.
	 * @return string Author HTML (a profile link for registered users).
	 */
	function extrachill_comment_author_html( $comment ) {
		$fallback = (string) get_comment_author_link( $comment );

		if ( (int) $comment->user_id <= 0 ) {
			return $fallback;
		}

		if ( ! function_exists( 'extrachill_get_comment_author_link_multisite' ) ) {
			return $fallback;
		}

		if ( function_exists( 'extrachill_should_use_multisite_comment_links' ) && ! extrachill_should_use_multisite_comment_links( $comment ) ) {
			return $fallback;
		}

		return (string) extrachill_get_comment_author_link_multisite( $comment );
	}

endif;
