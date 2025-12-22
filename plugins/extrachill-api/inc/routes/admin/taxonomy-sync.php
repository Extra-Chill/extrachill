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
    if ( ! current_user_can( 'manage_options' ) ) {
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

function extrachill_api_taxonomy_sync( WP_REST_Request $request ) {
    if ( ! function_exists( 'ec_get_blog_id' ) ) {
        return new WP_Error(
            'dependency_missing',
            'Required function ec_get_blog_id() not available.',
            array( 'status' => 500 )
        );
    }

    $target_sites = $request->get_param( 'target_sites' );
    $taxonomies   = $request->get_param( 'taxonomies' );

    if ( empty( $target_sites ) || empty( $taxonomies ) ) {
        return new WP_Error(
            'invalid_params',
            'Please select at least one target site and one taxonomy.',
            array( 'status' => 400 )
        );
    }

    $target_blog_ids = array();

    foreach ( $target_sites as $site_slug ) {
        $blog_id = ec_get_blog_id( $site_slug );
        if ( $blog_id ) {
            $target_blog_ids[] = absint( $blog_id );
        }
    }

    if ( empty( $target_blog_ids ) ) {
        return new WP_Error(
            'invalid_params',
            'No valid target sites selected.',
            array( 'status' => 400 )
        );
    }

    $report = extrachill_api_perform_taxonomy_sync( $target_blog_ids, $taxonomies );

    return rest_ensure_response( $report );
}

function extrachill_api_organize_terms_by_parent( $terms ) {
    $hierarchy = array();

    foreach ( $terms as $term ) {
        $parent_id = (int) $term->parent;

        if ( ! isset( $hierarchy[ $parent_id ] ) ) {
            $hierarchy[ $parent_id ] = array();
        }

        $hierarchy[ $parent_id ][] = $term;
    }

    return $hierarchy;
}

function extrachill_api_sync_term_recursive( $term, $taxonomy, $hierarchy, $parent_id_on_target, &$site_report, &$report ) {
    $report['total_terms_processed']++;

    $existing_term = term_exists( $term->slug, $taxonomy );
    if ( $existing_term ) {
        $site_report['skipped']++;
        $report['total_terms_skipped']++;
        $synced_term_id = is_array( $existing_term ) ? $existing_term['term_id'] : $existing_term;
    } else {
        $term_args = array(
            'slug'        => $term->slug,
            'description' => $term->description,
        );

        if ( $parent_id_on_target > 0 ) {
            $term_args['parent'] = $parent_id_on_target;
        }

        $result = wp_insert_term( $term->name, $taxonomy, $term_args );

        if ( is_wp_error( $result ) ) {
            $site_report['failed']++;
            return;
        }

        $site_report['created']++;
        $report['total_terms_created']++;
        $synced_term_id = $result['term_id'];
    }

    if ( isset( $hierarchy[ $term->term_id ] ) ) {
        foreach ( $hierarchy[ $term->term_id ] as $child_term ) {
            extrachill_api_sync_term_recursive( $child_term, $taxonomy, $hierarchy, $synced_term_id, $site_report, $report );
        }
    }
}

function extrachill_api_perform_taxonomy_sync( $target_blog_ids, $taxonomies ) {
    $source_blog_id = ec_get_blog_id( 'main' );

    if ( ! $source_blog_id ) {
        return array(
            'total_terms_processed' => 0,
            'total_terms_created'   => 0,
            'total_terms_skipped'   => 0,
            'breakdown'             => array(),
        );
    }

    $report = array(
        'total_terms_processed' => 0,
        'total_terms_created'   => 0,
        'total_terms_skipped'   => 0,
        'breakdown'             => array(),
    );

    foreach ( $taxonomies as $taxonomy ) {
        $source_terms = array();

        try {
            switch_to_blog( $source_blog_id );
            $source_terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                )
            );
        } finally {
            restore_current_blog();
        }

        if ( is_wp_error( $source_terms ) || empty( $source_terms ) ) {
            continue;
        }

        $report['breakdown'][ $taxonomy ] = array(
            'source_terms' => count( $source_terms ),
            'sites'        => array(),
        );

        $is_hierarchical = is_taxonomy_hierarchical( $taxonomy );

        foreach ( $target_blog_ids as $target_blog_id ) {
            $site_report = array(
                'created' => 0,
                'skipped' => 0,
                'failed'  => 0,
            );

            try {
                switch_to_blog( $target_blog_id );

                if ( $is_hierarchical ) {
                    $hierarchy = extrachill_api_organize_terms_by_parent( $source_terms );

                    if ( isset( $hierarchy[0] ) ) {
                        foreach ( $hierarchy[0] as $root_term ) {
                            extrachill_api_sync_term_recursive( $root_term, $taxonomy, $hierarchy, 0, $site_report, $report );
                        }
                    }
                } else {
                    foreach ( $source_terms as $term ) {
                        $report['total_terms_processed']++;

                        $existing_term = term_exists( $term->slug, $taxonomy );
                        if ( $existing_term ) {
                            $site_report['skipped']++;
                            $report['total_terms_skipped']++;
                            continue;
                        }

                        $term_args = array(
                            'slug'        => $term->slug,
                            'description' => $term->description,
                        );

                        $result = wp_insert_term( $term->name, $taxonomy, $term_args );

                        if ( is_wp_error( $result ) ) {
                            $site_report['failed']++;
                            continue;
                        }

                        $site_report['created']++;
                        $report['total_terms_created']++;
                    }
                }
            } finally {
                restore_current_blog();
            }

            $report['breakdown'][ $taxonomy ]['sites'][ (string) $target_blog_id ] = $site_report;
        }
    }

    return $report;
}
