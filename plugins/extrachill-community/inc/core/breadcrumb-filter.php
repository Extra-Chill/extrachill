<?php
/**
 * Community Breadcrumb Integration
 *
 * Integrates with theme's breadcrumb system to provide community-specific
 * breadcrumbs with "Community" root link and proper bbPress page detection.
 *
 * @package ExtraChillCommunity
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change breadcrumb root from "Home" to "Extra Chill → Community" on community pages
 *
 * Uses theme's extrachill_breadcrumbs_root filter to override the root link.
 * Only applies on blog ID 2 (community.extrachill.com).
 *
 * @param string $root_link Default root breadcrumb link HTML
 * @return string Modified root link
 * @since 1.0.0
 */
function extrachill_community_breadcrumb_root( $root_link ) {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || get_current_blog_id() !== $community_blog_id ) {
		return $root_link;
	}

	// On homepage, just "Extra Chill" (trail will add "Community")
	if ( is_front_page() ) {
		$main_site_url = ec_get_site_url( 'main' );
		return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a>';
	}

	// On other pages, include "Community" in root
	$main_site_url = ec_get_site_url( 'main' );
	return '<a href="' . esc_url( $main_site_url ) . '">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Community</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'extrachill_community_breadcrumb_root' );

/**
 * Override breadcrumb trail for community homepage
 *
 * Displays just "Community" (no link) on the homepage to prevent "Archives" suffix.
 * Priority 5 to run before the main breadcrumb trail function.
 *
 * @param string $custom_trail Existing custom trail from other plugins
 * @return string Breadcrumb trail HTML
 * @since 1.0.0
 */
function extrachill_community_breadcrumb_trail_homepage( $custom_trail ) {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || get_current_blog_id() !== $community_blog_id ) {
		return $custom_trail;
	}

	// Only on front page (homepage)
 	if ( is_front_page() ) {
 		return '<span class="network-dropdown-target">Community</span>';
 	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_community_breadcrumb_trail_homepage', 5 );

/**
 * Provide breadcrumb trail for community pages
 *
 * Uses theme's extrachill_breadcrumbs_override_trail filter to provide
 * complete breadcrumb trail for bbPress pages and custom page templates.
 * Only applies on blog ID 2 (community.extrachill.com).
 *
 * Breadcrumb structure:
 * - Single forum: Community › Parent Forum › ... › Forum Name
 * - Single topic: Community › Parent Forum › ... › Forum Name › Topic Name
 * - User profile: Community › Username
 * - Custom pages: Community › Page Name
 *
 * @param string $custom_trail Existing trail from other plugins
 * @return string Breadcrumb trail HTML
 * @since 1.0.0
 */
function extrachill_community_breadcrumb_trail( $custom_trail ) {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || get_current_blog_id() !== $community_blog_id ) {
		return $custom_trail;
	}

	// Don't override if on front page
	if ( is_front_page() ) {
		return $custom_trail;
	}

	// Single topic
	if ( function_exists( 'bbp_is_single_topic' ) && bbp_is_single_topic() ) {
		$topic_id = bbp_get_topic_id();
		$forum_id = bbp_get_topic_forum_id( $topic_id );
		$trail = '';

		if ( $forum_id ) {
			// Get parent forums (returns array from immediate parent to root)
			$ancestors = bbp_get_forum_ancestors( $forum_id );

			if ( ! empty( $ancestors ) ) {
				// Reverse array to go from root to immediate parent
				$ancestors = array_reverse( $ancestors );

				foreach ( $ancestors as $ancestor_id ) {
					$trail .= '<a href="' . esc_url( bbp_get_forum_permalink( $ancestor_id ) ) . '">' . esc_html( bbp_get_forum_title( $ancestor_id ) ) . '</a> › ';
				}
			}

			$trail .= '<a href="' . esc_url( bbp_get_forum_permalink( $forum_id ) ) . '">' . esc_html( bbp_get_forum_title( $forum_id ) ) . '</a>';
		}

		$trail .= ' › <span class="breadcrumb-title">' . esc_html( bbp_get_topic_title( $topic_id ) ) . '</span>';

		return $trail;
	}

	// Single reply: Community › Parent Forums › Forum Name › Topic Name
	if ( function_exists( 'bbp_is_single_reply' ) && bbp_is_single_reply() ) {
		$reply_id = bbp_get_reply_id();
		$topic_id = bbp_get_reply_topic_id( $reply_id );
		$forum_id = bbp_get_topic_forum_id( $topic_id );
		$trail    = '';

		if ( $forum_id ) {
			$ancestors = bbp_get_forum_ancestors( $forum_id );

			if ( ! empty( $ancestors ) ) {
				$ancestors = array_reverse( $ancestors );

				foreach ( $ancestors as $ancestor_id ) {
					$trail .= '<a href="' . esc_url( bbp_get_forum_permalink( $ancestor_id ) ) . '">' . esc_html( bbp_get_forum_title( $ancestor_id ) ) . '</a> › ';
				}
			}

			$trail .= '<a href="' . esc_url( bbp_get_forum_permalink( $forum_id ) ) . '">' . esc_html( bbp_get_forum_title( $forum_id ) ) . '</a>';
		}

		$trail .= ' › <span class="breadcrumb-title">' . esc_html( bbp_get_topic_title( $topic_id ) ) . '</span>';

		return $trail;
	}

	// Single forum: Community › Parent Forum › Forum Name
	if ( function_exists( 'bbp_is_single_forum' ) && bbp_is_single_forum() ) {
		$forum_id = bbp_get_forum_id();
		$trail = '';

		// Get parent forums (returns array from immediate parent to root)
		$ancestors = bbp_get_forum_ancestors( $forum_id );

		if ( ! empty( $ancestors ) ) {
			// Reverse array to go from root to immediate parent
			$ancestors = array_reverse( $ancestors );

			foreach ( $ancestors as $ancestor_id ) {
				$trail .= '<a href="' . esc_url( bbp_get_forum_permalink( $ancestor_id ) ) . '">' . esc_html( bbp_get_forum_title( $ancestor_id ) ) . '</a> › ';
			}
		}

		$trail .= '<span>' . esc_html( bbp_get_forum_title( $forum_id ) ) . '</span>';
		return $trail;
	}

	// User profile: Community › Username
	if ( function_exists( 'bbp_is_single_user' ) && bbp_is_single_user() ) {
		return '<span>' . esc_html( bbp_get_displayed_user_field( 'display_name' ) ) . '</span>';
	}

	return $custom_trail;
}
add_filter( 'extrachill_breadcrumbs_override_trail', 'extrachill_community_breadcrumb_trail' );

/**
 * Override back-to-home link label for community pages
 *
 * Changes "Back to Extra Chill" to "Back to Community" on community pages.
 * Uses theme's extrachill_back_to_home_label filter.
 * Only applies on blog ID 2 (community.extrachill.com).
 *
 * @param string $label Default back-to-home link label
 * @param string $url   Back-to-home link URL
 * @return string Modified label
 * @since 1.0.0
 */
function extrachill_community_back_to_home_label( $label, $url ) {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || get_current_blog_id() !== $community_blog_id ) {
		return $label;
	}



	// Don't override on homepage (homepage should say "Back to Extra Chill")
	if ( is_front_page() ) {
		return $label;
	}

	return '← Back to Community';
}
add_filter( 'extrachill_back_to_home_label', 'extrachill_community_back_to_home_label', 10, 2 );
