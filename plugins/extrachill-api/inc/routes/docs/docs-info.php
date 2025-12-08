<?php
/**
 * Docs info endpoints for documentation agents.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_docs_info_routes' );

/**
 * Registers docs-info route.
 */
function extrachill_api_register_docs_info_routes() {
    register_rest_route(
        'extrachill/v1',
        '/docs-info',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'extrachill_api_docs_info_handler',
            'permission_callback' => '__return_true',
        )
    );
}

/**
 * Builds docs-info payload for the current site.
 *
 * @return WP_REST_Response
 */
function extrachill_api_docs_info_handler() {
    $site = get_site();

    $post_types = extrachill_api_docs_info_collect_post_types();

    return rest_ensure_response(
        array(
            'site'        => array(
                'blog_id' => get_current_blog_id(),
                'domain'  => isset( $site->domain ) ? $site->domain : '',
                'path'    => isset( $site->path ) ? $site->path : '/',
                'name'    => get_bloginfo( 'name' ),
                'url'     => home_url( '/' ),
            ),
            'generated_at' => gmdate( 'c' ),
            'post_types'   => $post_types,
        )
    );
}

/**
 * Collects post type metadata including taxonomies, counts, and term usage.
 *
 * @return array
 */
function extrachill_api_docs_info_collect_post_types() {
    $public_post_types = get_post_types( array( 'public' => true ), 'objects' );
    $data              = array();

    global $wpdb;

    foreach ( $public_post_types as $post_type => $object ) {
        $counts = wp_count_posts( $post_type );
        $published_count = isset( $counts->publish ) ? (int) $counts->publish : 0;

        $tax_data   = array();
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );

        foreach ( $taxonomies as $tax_obj ) {
            // Total terms in taxonomy.
            $total_terms = (int) wp_count_terms(
                array(
                    'taxonomy'   => $tax_obj->name,
                    'hide_empty' => false,
                )
            );

            // Distinct term_taxonomy_ids assigned to posts of this post type (publish only).
            $assigned_term_tax_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT tr.term_taxonomy_id
                     FROM {$wpdb->term_relationships} tr
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.taxonomy = %s",
                    $post_type,
                    $tax_obj->name
                )
            );

            $assigned_term_count = is_array( $assigned_term_tax_ids ) ? count( $assigned_term_tax_ids ) : 0;

            // Slugs for all terms in taxonomy (non-empty list, hide_empty=false).
            $terms_all = get_terms(
                array(
                    'taxonomy'   => $tax_obj->name,
                    'hide_empty' => false,
                    'fields'     => 'slugs',
                )
            );

            // Slugs for terms actually assigned to this post type (publish posts only).
            $terms_assigned = array();

            if ( ! empty( $assigned_term_tax_ids ) ) {
                $term_tax_ids_sql = implode( ',', array_map( 'absint', $assigned_term_tax_ids ) );

                $assigned_term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ($term_tax_ids_sql)" );

                if ( ! empty( $assigned_term_ids ) ) {
                    $terms_assigned = get_terms(
                        array(
                            'taxonomy'   => $tax_obj->name,
                            'include'    => array_map( 'absint', $assigned_term_ids ),
                            'hide_empty' => false,
                            'fields'     => 'slugs',
                        )
                    );
                }
            }

            $tax_data[] = array(
                'slug'                    => $tax_obj->name,
                'label'                   => $tax_obj->label,
                'hierarchical'            => (bool) $tax_obj->hierarchical,
                'public'                  => (bool) $tax_obj->public,
                'total_term_count'        => $total_terms,
                'assigned_term_count'     => $assigned_term_count,
                'terms'                   => is_array( $terms_all ) ? array_values( $terms_all ) : array(),
                'assigned_terms'          => is_array( $terms_assigned ) ? array_values( $terms_assigned ) : array(),
            );
        }

        $data[ $post_type ] = array(
            'slug'             => $post_type,
            'label'            => $object->label,
            'public'           => (bool) $object->public,
            'has_archive'      => (bool) $object->has_archive,
            'hierarchical'     => (bool) $object->hierarchical,
            'supports'         => is_array( $object->supports ) ? array_values( $object->supports ) : array(),
            'publish_count'    => $published_count,
            'taxonomies'       => $tax_data,
        );
    }

    return $data;
}
