<?php
/**
 * Taxonomy Sync REST API Endpoint
 *
 * Syncs shared taxonomies from the main site to selected target sites.
 * Designed for Admin Tools UI usage.
 *
 * @endpoint POST /wp-json/extrachill/v1/admin/taxonomies/sync
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_taxonomy_sync_routes' );

function extrachill_api_register_taxonomy_sync_routes() {
    register_rest_route(
        'extrachill/v1',
        '/admin/taxonomies/sync',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'extrachill_api_taxonomy_sync',
            'permission_callback' => 'extrachill_api_taxonomy_sync_permission_check',
            'args'                => array(
                'target_sites' => array(
                    'required'          => true,
                    'type'              => 'array',
                    'sanitize_callback' => 'extrachill_api_taxonomy_sync_sanitize_site_slugs',
                ),
                'taxonomies'   => array(
                    'required'          => true,
                    'type'              => 'array',
                    'sanitize_callback' => 'extrachill_api_taxonomy_sync_sanitize_taxonomies',
                ),
            ),
        )
    );
}

function extrachill_api_taxonomy_sync_permission_check() {
    if ( ! current_user_can( 'manage_network_options' ) ) {
        return new WP_Error(
            'rest_forbidden',
            'You do not have permission to sync taxonomies.',
            array( 'status' => 403 )
        );
    }

    return true;
}

function extrachill_api_taxonomy_sync_sanitize_site_slugs( $value ) {
    if ( ! is_array( $value ) ) {
        return array();
    }

    $site_slugs = array();
    foreach ( $value as $site_slug ) {
        $site_slug = sanitize_key( $site_slug );
        if ( '' === $site_slug ) {
            continue;
        }
        $site_slugs[] = $site_slug;
    }

    return array_values( array_unique( $site_slugs ) );
}

function extrachill_api_taxonomy_sync_sanitize_taxonomies( $value ) {
    if ( ! is_array( $value ) ) {
        return array();
    }

    $valid_taxonomies = array( 'location', 'festival', 'artist', 'venue' );

    $taxonomies = array();
    foreach ( $value as $taxonomy ) {
        $taxonomy = sanitize_key( $taxonomy );
        if ( in_array( $taxonomy, $valid_taxonomies, true ) ) {
            $taxonomies[] = $taxonomy;
        }
    }

    return array_values( array_unique( $taxonomies ) );
}

/**
 * Syncs shared taxonomies from the main site to target sites.
 *
 * Wraps the extrachill/sync-taxonomies ability.
 *
 * @param WP_REST_Request $request The REST request object.
 * @return WP_REST_Response|WP_Error Response with sync report or error.
 */
function extrachill_api_taxonomy_sync( WP_REST_Request $request ) {
    $ability = wp_get_ability( 'extrachill/sync-taxonomies' );
    if ( ! $ability ) {
        return new WP_Error( 'ability_not_found', 'Taxonomy sync ability is not available.', array( 'status' => 500 ) );
    }

    $result = $ability->execute(
        array(
            'target_sites' => $request->get_param( 'target_sites' ),
            'taxonomies'   => $request->get_param( 'taxonomies' ),
        )
    );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return rest_ensure_response( $result );
}

