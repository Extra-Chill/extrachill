<?php
/**
 * Handler: extrachill/artist-public-projections
 *
 * @package ExtraChillArtistPlatform
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Resolve public artist presentation from canonical subscription slugs.
 *
 * @param array $input Validated ability input.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_public_projections( array $input ): array|WP_Error {
	$blog_ids = ec_artist_binding_blog_ids();
	if ( empty( $blog_ids ) ) {
		return new WP_Error( 'artist_projection_owner_unavailable', __( 'Artist projection owner sites are unavailable.', 'extrachill-artist-platform' ) );
	}

	$items = array();
	foreach ( $input['slugs'] as $slug ) {
		$item = array(
			'slug'   => $slug,
			'status' => 'not_found',
			'name'   => '',
			'url'    => '',
		);

		switch_to_blog( $blog_ids['main'] );
		try {
			$term = get_term_by( 'slug', $slug, 'artist' );
		} finally {
			restore_current_blog();
		}

		if ( is_wp_error( $term ) ) {
			return $term;
		}
		if ( ! $term ) {
			$items[] = $item;
			continue;
		}

		$binding = ec_artist_binding_read_term( (int) $term->term_id, $blog_ids['main'] );
		if ( empty( $binding['profile_id'] ) ) {
			$items[] = $item;
			continue;
		}

		$profile = ec_artist_binding_read_profile( $binding['profile_id'], $blog_ids['artist'] );
		if ( empty( $profile ) || 'publish' !== $profile['status'] || '' === $profile['title'] || (int) $profile['term_id'] !== (int) $binding['id'] ) {
			$items[] = $item;
			continue;
		}

		switch_to_blog( $blog_ids['artist'] );
		try {
			$url = get_permalink( $profile['id'] );
		} finally {
			restore_current_blog();
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error( 'artist_projection_permalink_unavailable', __( 'The canonical artist profile URL is unavailable.', 'extrachill-artist-platform' ) );
		}

		$item['status'] = 'resolved';
		$item['name']   = $profile['title'];
		$item['url']    = $url;
		$items[]        = $item;
	}

	return array(
		'schema_version' => '1',
		'items'          => $items,
	);
}
