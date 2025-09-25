<?php
/**
 * WordPress Multisite Native Authentication for Artist Management
 *
 * Replaces legacy session token system with native WordPress multisite authentication.
 * Provides simple, secure cross-domain authentication using WordPress built-in capabilities.
 */

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/check-artist-manage-access/(?P<artist_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'extrch_check_artist_manage_access_multisite_native',
        'permission_callback' => '__return_true',
        'args' => array(
            'artist_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});


/**
 * Check artist management permissions using WordPress multisite native authentication
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function extrch_check_artist_manage_access_multisite_native( $request ) {
    $artist_id = (int) $request['artist_id'];
    $can_manage = false;

    if ( is_user_logged_in() && ! empty( $artist_id ) ) {
        $can_manage = current_user_can( 'manage_artist_members', $artist_id );
    }

    return new WP_REST_Response( array(
        'canManage' => $can_manage
    ), 200 );
}




