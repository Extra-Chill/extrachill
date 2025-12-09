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

    $about = extrachill_api_docs_info_collect_about();

    if ( is_wp_error( $about ) ) {
        return $about;
    }

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
            'about'        => $about,
            'post_types'   => $post_types,
        )
    );
}

/**
 * Loads About page content from the main site (blog ID 1).
 *
 * @return array|WP_Error
 */
function extrachill_api_docs_info_collect_about() {
    $main_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'main' ) : null;
    if ( ! $main_blog_id ) {
        return new WP_Error( 'about_not_found', 'Main site blog ID not available.', array( 'status' => 500 ) );
    }

    try {
        switch_to_blog( $main_blog_id );

        $about = get_page_by_path( 'about' );

        if ( ! $about || 'publish' !== $about->post_status ) {
            return new WP_Error( 'about_not_found', 'About page not found on main site.', array( 'status' => 500 ) );
        }

        return array(
            'id'      => (int) $about->ID,
            'slug'    => 'about',
            'title'   => get_the_title( $about ),
            'url'     => get_permalink( $about ),
            'content' => apply_filters( 'the_content', $about->post_content ),
        );
    } finally {
        restore_current_blog();
    }
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
            $total_terms = (int) wp_count_terms(
                array(
                    'taxonomy'   => $tax_obj->name,
                    'hide_empty' => false,
                )
            );

            $terms_with_counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.term_id, t.slug, t.name, COUNT(p.ID) AS post_count
                     FROM {$wpdb->terms} t
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
                     INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                     WHERE p.post_type = %s AND p.post_status = 'publish'
                     GROUP BY t.term_id, t.slug, t.name
                     HAVING post_count > 0",
                    $tax_obj->name,
                    $post_type
                ),
                ARRAY_A
            );

            $assigned_term_count = is_array( $terms_with_counts ) ? count( $terms_with_counts ) : 0;

            $terms = array();

            foreach ( $terms_with_counts as $term_row ) {
                $terms[] = array(
                    'slug'  => $term_row['slug'],
                    'name'  => $term_row['name'],
                    'count' => (int) $term_row['post_count'],
                );
            }

            $tax_data[] = array(
                'slug'                => $tax_obj->name,
                'label'               => $tax_obj->label,
                'hierarchical'        => (bool) $tax_obj->hierarchical,
                'public'              => (bool) $tax_obj->public,
                'total_term_count'    => $total_terms,
                'assigned_term_count' => $assigned_term_count,
                'terms'               => $terms,
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
