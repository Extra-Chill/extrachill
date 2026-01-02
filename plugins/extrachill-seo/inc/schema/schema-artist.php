<?php
/**
 * MusicGroup Schema for Artist Profiles
 *
 * Outputs MusicGroup schema for singular artist_profile posts.
 * Requires extrachill-artist-platform plugin for data functions.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add MusicGroup schema to graph for artist profiles
 */
add_filter(
	'extrachill_seo_schema_graph',
	function ( $graph ) {
		if ( ! is_singular( 'artist_profile' ) ) {
			return $graph;
		}

		// Require artist platform data function
		if ( ! function_exists( 'ec_get_artist_profile_data' ) ) {
			return $graph;
		}

		$post         = get_queried_object();
		$artist_data  = ec_get_artist_profile_data( $post->ID );

		if ( empty( $artist_data ) ) {
			return $graph;
		}

		$permalink = get_permalink( $post->ID );

		$music_group = array(
			'@type' => 'MusicGroup',
			'@id'   => $permalink . '#musicgroup',
			'name'  => $artist_data['title'],
			'url'   => $permalink,
		);

		// Add description if available
		if ( ! empty( $artist_data['bio'] ) ) {
			$music_group['description'] = wp_strip_all_tags( $artist_data['bio'] );
		}

		// Add genre if available
		if ( ! empty( $artist_data['genre'] ) ) {
			$music_group['genre'] = $artist_data['genre'];
		}

		// Add profile image
		if ( ! empty( $artist_data['profile_image_url'] ) ) {
			$music_group['image'] = $artist_data['profile_image_url'];
		}

		// Build sameAs array from all available URLs
		$same_as = array();

		// Direct URL fields
		if ( ! empty( $artist_data['website_url'] ) ) {
			$same_as[] = $artist_data['website_url'];
		}
		if ( ! empty( $artist_data['spotify_url'] ) ) {
			$same_as[] = $artist_data['spotify_url'];
		}
		if ( ! empty( $artist_data['apple_music_url'] ) ) {
			$same_as[] = $artist_data['apple_music_url'];
		}
		if ( ! empty( $artist_data['bandcamp_url'] ) ) {
			$same_as[] = $artist_data['bandcamp_url'];
		}

		// Social links array
		if ( ! empty( $artist_data['social_links'] ) && is_array( $artist_data['social_links'] ) ) {
			foreach ( $artist_data['social_links'] as $social ) {
				if ( ! empty( $social['url'] ) ) {
					$same_as[] = $social['url'];
				}
			}
		}

		// Dedupe and add if we have any
		$same_as = array_unique( array_filter( $same_as ) );
		if ( ! empty( $same_as ) ) {
			$music_group['sameAs'] = array_values( $same_as );
		}

		$graph[] = $music_group;

		return $graph;
	}
);
