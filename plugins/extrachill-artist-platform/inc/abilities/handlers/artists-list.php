<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artists-list
 *
 * Lists all published artist profiles sorted by recent activity.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * List all published artist profiles.
 *
 * @param array $input {
 *     @type int    $page     Page number (default 1).
 *     @type int    $per_page Results per page (default 24, max 100).
 *     @type string $search   Optional search term to filter by title.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artists_list( array $input ): array|WP_Error {
	$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 24;
	$search   = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	$did_switch     = false;

	if ( $artist_blog_id && get_current_blog_id() !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		$did_switch = true;
	}

	$query_args = array(
		'post_type'      => 'artist_profile',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	if ( $search !== '' ) {
		$query_args['s'] = $search;
	}

	$query   = new WP_Query( $query_args );
	$artists = array();

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();
			$artist_id = get_the_ID();

			$profile_image_id  = get_post_thumbnail_id( $artist_id );
			$profile_image_url = $profile_image_id
				? wp_get_attachment_image_url( (int) $profile_image_id, 'medium' )
				: null;

			$artists[] = array(
				'id'                => (int) $artist_id,
				'name'              => get_the_title(),
				'slug'              => get_post_field( 'post_name', $artist_id ),
				'local_city'        => get_post_meta( $artist_id, '_local_city', true ) ?: null,
				'genre'             => get_post_meta( $artist_id, '_genre', true ) ?: null,
				'profile_image_url' => $profile_image_url,
			);
		}
		wp_reset_postdata();
	}

	$result = array(
		'artists'  => $artists,
		'total'    => (int) $query->found_posts,
		'page'     => $page,
		'per_page' => $per_page,
		'pages'    => (int) $query->max_num_pages,
	);

	if ( $did_switch ) {
		restore_current_blog();
	}

	return $result;
}
