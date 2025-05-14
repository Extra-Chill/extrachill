<?php
/**
 * File to register REST API endpoint for user mentions.
 */

add_action( 'rest_api_init', function () {
	register_rest_route( 'extrachill/v1', '/users/search', array(
		'methods'  => 'GET',
		'callback' => 'extrachill_user_mention_search_endpoint',
		'permission_callback' => '__return_true', // For now, no permission check
	) );
} );

function extrachill_user_mention_search_endpoint( $request ) {
    $term = isset( $request['term'] ) ? sanitize_text_field( $request['term'] ) : '';

    if ( empty( $term ) ) {
        return new WP_REST_Response( array( 'error' => 'Search term is required.' ), 400 );
    }

    $users_query = null; // Initialize $users_query
    $users_query = new WP_User_Query( array(
        'search'         => '*' . esc_attr( $term ) . '*',
        'search_columns' => array(
            'user_login',
            'user_nicename',

        ),
        'number' => 10, // Limit to 10 results
    ) );

    $users_data = array();

    if ( ! empty( $users_query->get_results() ) ) {
        foreach ( $users_query->get_results() as $user ) {
            $users_data[] = array(
                'username' => $user->user_login,
                'slug'     => $user->user_nicename,
            );
        }
    } else {
        return new WP_REST_Response( array( 'message' => 'No users found.' ), 200 );
    }

    return rest_ensure_response( $users_data );
}