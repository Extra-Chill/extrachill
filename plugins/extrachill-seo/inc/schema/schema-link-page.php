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

	// The runtime owner reference is the only Link Page/artist binding.
	if ( ! function_exists( 'ec_get_link_page_owner' ) || ! function_exists( 'ec_get_blog_id' ) ) {
		return $graph;
	}
	$owner          = ec_get_link_page_owner( $post->ID );
	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	if ( is_wp_error( $owner ) || 'post' !== $owner['kind'] || 'artist_profile' !== $owner['subtype'] || $artist_blog_id <= 0 || $artist_blog_id !== (int) $owner['blog_id'] ) {
		return $graph;
	}
	$artist_id = (int) $owner['object_id'];

	// The artist profile lives on the Artist site, not the serving Link Pages site.
	switch_to_blog( $artist_blog_id );
	try {
		$artist_post = get_post( $artist_id );
		if ( ! $artist_post || 'artist_profile' !== $artist_post->post_type ) {
			return $graph;
		}
		$artist_permalink = get_permalink( $artist_id );
		$artist_title     = get_the_title( $artist_id );
	} finally {
		restore_current_blog();
	}

	// Build canonical extrachill.link URL from post slug
	$link_page_url = 'https://extrachill.link/' . $post->post_name . '/';

	$profile_page = array(
		'@type'      => 'ProfilePage',
		'@id'        => $link_page_url . '#profilepage',
		'url'        => $link_page_url,
		'name'       => $artist_title,
		'mainEntity' => array(
			'@id' => $artist_permalink . '#musicgroup',
		),
	);

	$graph[] = $profile_page;

	return $graph;
}
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_link_page_schema' );
