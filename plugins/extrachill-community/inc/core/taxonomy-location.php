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
 * Render cross-site links on forum pages (outbound linking)
 *
 * When viewing a forum with a location term, show links to
 * location content on blog, events, and wire sites.
 */
function extrachill_community_render_forum_location_links() {
	if ( ! function_exists( 'bbp_is_single_forum' ) || ! bbp_is_single_forum() ) {
		return;
	}

	$forum_id       = bbp_get_forum_id();
	$location_terms = get_the_terms( $forum_id, 'location' );

	if ( empty( $location_terms ) || is_wp_error( $location_terms ) ) {
		return;
	}

	$term = $location_terms[0];

	if ( ! function_exists( 'ec_get_cross_site_term_links' ) ) {
		return;
	}

	$links = ec_get_cross_site_term_links( $term, 'location' );

	if ( empty( $links ) ) {
		return;
	}

	echo '<div class="ec-cross-site-links ec-forum-location-links">';
	echo '<span class="cross-site-links-label">' . esc_html( $term->name ) . ' on Extra Chill:</span> ';

	foreach ( $links as $link ) {
		printf(
			'<a href="%s" class="button-3 button-small">%s %s (%d)</a> ',
			esc_url( $link['url'] ),
			esc_html( $link['term_name'] ),
			esc_html( $link['label'] ),
			intval( $link['count'] )
		);
	}

	echo '</div>';
}
add_action( 'bbp_template_before_single_forum', 'extrachill_community_render_forum_location_links' );
