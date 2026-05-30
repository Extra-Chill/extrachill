<?php
declare(strict_types=1);
/**
 * Handler: extrachill/admin-list-artist-relationships
 *
 * Lists artist-user relationships (artists view or users view).
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * List artist-user relationships.
 *
 * @param array $input {
 *     @type string $view   View mode: 'artists' or 'users' (default 'artists').
 *     @type string $search Optional search term.
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_admin_list_artist_relationships( array $input ): array|WP_Error {
	$view   = isset( $input['view'] ) ? sanitize_text_field( $input['view'] ) : 'artists';
	$search = isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '';

	$request = new WP_REST_Request( 'GET', '/extrachill/v1/admin/artist-relationships' );
	$request->set_param( 'view', $view );
	$request->set_param( 'search', $search );

	$response = extrachill_api_get_artist_relationships( $request );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = $response instanceof WP_REST_Response ? $response->get_data() : (array) $response;

	return is_array( $data ) ? $data : array( 'items' => array() );
}
