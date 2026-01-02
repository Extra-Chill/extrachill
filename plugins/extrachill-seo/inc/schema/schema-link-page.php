<?php
/**
 * ProfilePage Schema for Artist Link Pages
 *
 * Outputs ProfilePage schema for singular artist_link_page posts.
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
 */
add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_singular( 'artist_link_page' ) ) {
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
		if ( ! $artist_post || $artist_post->post_type !== 'artist_profile' ) {
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
);
