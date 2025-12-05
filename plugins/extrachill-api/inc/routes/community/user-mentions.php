<?php
/**
 * REST route: /wp-json/extrachill/v1/users/search
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_user_mention_route' );

function extrachill_api_register_user_mention_route() {
    register_rest_route( 'extrachill/v1', '/users/search', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'extrachill_user_mention_search_endpoint',
        'permission_callback' => '__return_true',
        'args'                => array(
            'term' => array(
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );
}

function extrachill_user_mention_search_endpoint( WP_REST_Request $request ) {
    $term = $request->get_param( 'term' );

    if ( empty( $term ) ) {
        return new WP_Error(
            'missing_search_term',
            'Search term is required.',
            array( 'status' => 400 )
        );
    }

    $users_query = new WP_User_Query( array(
        'search'         => '*' . esc_attr( $term ) . '*',
        'search_columns' => array( 'user_login', 'user_nicename' ),
        'number'         => 10,
    ) );

    $users_data = array();

    foreach ( $users_query->get_results() as $user ) {
        $users_data[] = array(
            'username' => $user->user_login,
            'slug'     => $user->user_nicename,
        );
    }

    return rest_ensure_response( $users_data );
}
