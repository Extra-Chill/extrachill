<?php
declare(strict_types=1);
/**
 * Handler: extrachill/admin-link-artist-relationship
 *
 * Links a user to an artist profile (admin-only).
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Link a user to an artist profile.
 *
 * @param array $input {
 *     @type int $user_id   WordPress user ID.
 *     @type int $artist_id Artist profile post ID.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_admin_link_artist_relationship( array $input ): array|WP_Error {
	if ( empty( $input['user_id'] ) || empty( $input['artist_id'] ) ) {
		return new WP_Error(
			'missing_params',
			__( 'Both user_id and artist_id are required.', 'extrachill-artist-platform' ),
			array( 'status' => 400 )
		);
	}

	$request = new WP_REST_Request( 'POST', '/extrachill/v1/admin/artist-relationships/link' );
	$request->set_param( 'user_id', (int) $input['user_id'] );
	$request->set_param( 'artist_id', (int) $input['artist_id'] );

	$response = extrachill_api_link_user_to_artist( $request );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = $response instanceof WP_REST_Response ? $response->get_data() : (array) $response;

	return is_array( $data ) ? $data : array( 'success' => true );
}
