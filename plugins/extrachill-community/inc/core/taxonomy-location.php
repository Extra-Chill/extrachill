<?php
/**
 * Location Taxonomy Registration for bbPress Forums
 *
 * Extends theme-registered location taxonomy to forums.
 * Forums become location hubs with bidirectional cross-site linking.
 *
 * @package ExtraChillCommunity
 * @since 1.2.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register location taxonomy for forums
 *
 * Hooks at priority 20 to run after theme registers the taxonomy.
 */
function extrachill_community_register_location_for_forums() {
	if ( taxonomy_exists( 'location' ) ) {
		register_taxonomy_for_object_type( 'location', 'forum' );
	}
}
add_action( 'init', 'extrachill_community_register_location_for_forums', 20 );

/**
 * Redirect location archives to the corresponding forum
 *
 * When viewing /location/charleston/ on community site, redirect
 * to the Charleston subforum (the location hub).
 */
function extrachill_community_redirect_location_to_forum() {
	if ( ! is_tax( 'location' ) ) {
		return;
	}

	$term = get_queried_object();
	if ( ! $term || ! is_a( $term, 'WP_Term' ) ) {
		return;
	}

	$forums = get_posts(
		array(
			'post_type'      => 'forum',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'location',
					'field'    => 'term_id',
					'terms'    => $term->term_id,
				),
			),
		)
	);

	if ( ! empty( $forums ) ) {
		wp_safe_redirect( get_permalink( $forums[0] ), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'extrachill_community_redirect_location_to_forum' );

/**
 * Append cross-site links to forum description
 *
 * When viewing a forum with a location term, show links to
 * location content on blog, events, and wire sites AFTER the description.
 *
 * @param string $description Forum description HTML.
 * @return string Description with cross-site links appended.
 */
function extrachill_community_append_location_links_to_description( $description ) {
	if ( ! function_exists( 'bbp_is_single_forum' ) || ! bbp_is_single_forum() ) {
		return $description;
	}

	$forum_id       = bbp_get_forum_id();
	$has_subforums  = ! empty( bbp_forum_get_subforums( $forum_id ) );
	$location_terms = get_the_terms( $forum_id, 'location' );
	$button_html    = '';

	if ( ! bbp_is_forum_category() && ! $has_subforums ) {
		$button_html = '<div class="page-content"><p class="ec-single-forum-create-topic"><a class="button-1 button-medium" href="#new-post">' . esc_html__( 'Create Topic', 'extrachill-community' ) . '</a></p></div>';
	}

	if ( empty( $location_terms ) || is_wp_error( $location_terms ) ) {
		return $description . $button_html;
	}

	$term = $location_terms[0];

	if ( ! function_exists( 'extrachill_get_cross_site_term_links' ) ) {
		return $description . $button_html;
	}

	$links = extrachill_get_cross_site_term_links( $term, 'location' );

	if ( empty( $links ) ) {
		return $description . $button_html;
	}

	ob_start();
	echo '<div class="page-content"><div class="ec-cross-site-links ec-forum-location-links">';
	foreach ( $links as $link ) {
		extrachill_cross_site_link_button( $link );
	}
	echo '</div></div>';
	$links_html = ob_get_clean();

	return $description . $button_html . $links_html;
}
add_filter( 'bbp_get_single_forum_description', 'extrachill_community_append_location_links_to_description' );
