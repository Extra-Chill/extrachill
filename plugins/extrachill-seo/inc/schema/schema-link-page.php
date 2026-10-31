<?php
/**
 * ProfilePage Schema for Artist Link Pages
 *
 * Outputs ProfilePage schema for singular Link Page posts, whichever post type
 * the serving site uses (see extrachill-link-pages#34).
 * References the associated MusicGroup entity from the artist profile.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add ProfilePage schema to graph for link pages
 *
 * @param array $graph Current schema graph.
 * @return array Graph with the ProfilePage entity appended when applicable.
 */
function ec_seo_emit_link_page_schema( $graph ) {
	// The Link Page post type follows the site that serves it: legacy
	// `artist_link_page` on the artist site, `ec_link_page` on the dedicated
	// Link Pages site after cutover (extrachill-link-pages#34). Resolve it for
	// the blog rendering this request, since that is where is_singular() looks.
	$link_page_type = function_exists( 'ec_link_page_post_type' )
		? ec_link_page_post_type( get_current_blog_id() )
		: 'artist_link_page';

	if ( ! is_singular( $link_page_type ) ) {
		return $graph;
	}

		$post = get_queried_object();

		// Get associated artist profile ID
		$artist_id = get_post_meta( $post->ID, '_associated_artist_profile_id', true );

	if ( empty( $artist_id ) ) {
		return $graph;
	}

		// Verify artist profile exists
		$artist_post = get_post( $artist_id );
	if ( ! $artist_post || 'artist_profile' !== $artist_post->post_type ) {
		return $graph;
	}

		// Build canonical extrachill.link URL from post slug
		$link_page_url = 'https://extrachill.link/' . $post->post_name . '/';

		// Get artist profile permalink for mainEntity reference
		$artist_permalink = get_permalink( $artist_id );

		$profile_page = array(
			'@type'      => 'ProfilePage',
			'@id'        => $link_page_url . '#profilepage',
			'url'        => $link_page_url,
			'name'       => get_the_title( $artist_id ),
			'mainEntity' => array(
				'@id' => $artist_permalink . '#musicgroup',
			),
		);

		$graph[] = $profile_page;

		return $graph;
}
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_link_page_schema' );
