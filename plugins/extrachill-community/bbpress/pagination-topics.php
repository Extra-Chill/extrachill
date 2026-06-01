<?php

/**
 * Pagination for pages of topics (when viewing a forum)
 *
 * Overrides the bbPress default to render pagination via the theme's
 * extrachill_pagination() helper instead of the core bbp_forum_pagination_*()
 * calls. Centralizes the pagination concern so content templates can fall
 * through to bbPress defaults.
 *
 * @package ExtraChillCommunity
 * @subpackage Theme
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

do_action( 'bbp_template_before_pagination_loop' );

global $bbp_topic_query;
if ( ! empty( $bbp_topic_query ) ) {
	extrachill_pagination( $bbp_topic_query, 'bbpress' );
}

do_action( 'bbp_template_after_pagination_loop' );
