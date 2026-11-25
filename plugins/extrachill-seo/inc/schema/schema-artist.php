<?php
/**
 * Performer Schema for Artist Profiles
 *
 * Outputs the performer entity for singular artist_profile posts. The type
 * follows the artist's performer kind, resolved by extrachill-artist-platform
 * (`schema_type` from ec_get_artist_profile_data(): MusicGroup for music,
 * PerformingGroup for comedy). The `#musicgroup` @id fragment is stable for
 * every kind so Link Page ProfilePage references keep resolving. Genre is
 * only emitted for music acts.
 * Requires extrachill-artist-platform plugin for data functions.
 *
 * @package ExtraChill\SEO
 */

namespace ExtraChill\SEO\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the schema.org type for artist profile data.
 *
 * Accepts only the performer types the artist platform resolves to and
 * falls back to MusicGroup for data from older platform versions.
 *
 * @param array $artist_data Artist profile data.
 * @return string schema.org type.
 */
function ec_seo_artist_schema_type( array $artist_data ) {
	$type = isset( $artist_data['schema_type'] ) ? (string) $artist_data['schema_type'] : '';
	return in_array( $type, array( 'MusicGroup', 'PerformingGroup', 'Person' ), true ) ? $type : 'MusicGroup';
}

/**
 * Add the performer entity to the graph for artist profiles
 *
 * @param array $graph Current schema graph.
 * @return array Graph with the performer entity appended when applicable.
 */
function ec_seo_emit_artist_schema( $graph ) {
	if ( ! is_singular( 'artist_profile' ) ) {
		return $graph;
	}

		// Require artist platform data function
	if ( ! function_exists( 'ec_get_artist_profile_data' ) ) {
		return $graph;
	}

		$post        = get_queried_object();
		$artist_data = ec_get_artist_profile_data( $post->ID );

	if ( empty( $artist_data ) ) {
		return $graph;
	}

		$permalink = get_permalink( $post->ID );

		$music_group = array(
			'@type' => ec_seo_artist_schema_type( $artist_data ),
			'@id'   => $permalink . '#musicgroup',
			'name'  => $artist_data['title'],
			'url'   => $permalink,
		);

		// Add description if available
		if ( ! empty( $artist_data['bio'] ) ) {
			$music_group['description'] = wp_strip_all_tags( $artist_data['bio'] );
		}

		// Genre is a music vocabulary: only music acts publish it.
		$is_music = 'MusicGroup' === $music_group['@type'];
		if ( $is_music && ! empty( $artist_data['genre'] ) ) {
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
add_filter( 'extrachill_seo_schema_graph', __NAMESPACE__ . '\\ec_seo_emit_artist_schema' );
