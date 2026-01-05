<?php
/**
 * Tag Migration REST API Endpoints
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'extrachill_api_register_tags_routes' );

/**
 * Register tag migration REST routes.
 */
function extrachill_api_register_tags_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/tags',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_tags',
			'permission_callback' => 'extrachill_api_tags_permission_check',
			'args'                => array(
				'page'   => array(
					'default' => 1,
					'type'    => 'integer',
				),
				'search' => array(
					'default' => '',
					'type'    => 'string',
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/tags/migrate',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_migrate_tags',
			'permission_callback' => 'extrachill_api_tags_permission_check',
			'args'                => array(
				'tag_ids'  => array(
					'required' => true,
					'type'     => 'array',
				),
				'taxonomy' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'festival', 'artist', 'venue' ),
				),
			),
		)
	);
}

/**
 * Permission check for tag endpoints.
 *
 * @return bool|WP_Error
 */
function extrachill_api_tags_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage tags.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Get paginated list of tags.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_api_get_tags( $request ) {
	$page     = $request->get_param( 'page' );
	$search   = $request->get_param( 'search' );
	$per_page = 100;
	$offset   = ( $page - 1 ) * $per_page;

	// Switch to main site for tag queries.
	$main_site_id = get_main_site_id();
	switch_to_blog( $main_site_id );

	$args = array(
		'taxonomy'   => 'post_tag',
		'hide_empty' => false,
		'number'     => $per_page,
		'offset'     => $offset,
		'orderby'    => 'count',
		'order'      => 'DESC',
	);

	if ( ! empty( $search ) ) {
		$args['search'] = '*' . $search . '*';
	}

	$tags       = get_terms( $args );
	$total_tags = wp_count_terms( 'post_tag', array( 'hide_empty' => false ) );

	restore_current_blog();

	$formatted_tags = array();
	foreach ( $tags as $tag ) {
		$formatted_tags[] = array(
			'term_id' => $tag->term_id,
			'name'    => $tag->name,
			'slug'    => $tag->slug,
			'count'   => $tag->count,
		);
	}

	return rest_ensure_response(
		array(
			'tags'        => $formatted_tags,
			'total'       => (int) $total_tags,
			'total_pages' => ceil( $total_tags / $per_page ),
			'page'        => $page,
		)
	);
}

/**
 * Migrate tags to a custom taxonomy.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_api_migrate_tags( $request ) {
	$tag_ids  = $request->get_param( 'tag_ids' );
	$taxonomy = $request->get_param( 'taxonomy' );

	// Switch to main site for tag operations.
	$main_site_id = get_main_site_id();
	switch_to_blog( $main_site_id );

	$report = array();

	foreach ( $tag_ids as $tag_id ) {
		$tag_id = (int) $tag_id;
		$tag    = get_term( $tag_id, 'post_tag' );

		if ( ! $tag || is_wp_error( $tag ) ) {
			continue;
		}

		// Check if term exists in target taxonomy.
		$term = term_exists( $tag->slug, $taxonomy );
		if ( ! $term ) {
			$term = wp_insert_term(
				$tag->name,
				$taxonomy,
				array( 'slug' => $tag->slug )
			);
		}

		if ( is_wp_error( $term ) ) {
			$report[] = sprintf( 'Error migrating "%s": %s', $tag->name, $term->get_error_message() );
			continue;
		}

		$term_id = is_array( $term ) ? $term['term_id'] : $term;

		// Get posts with this tag.
		$posts = get_objects_in_term( $tag_id, 'post_tag' );

		foreach ( $posts as $post_id ) {
			wp_set_object_terms( $post_id, (int) $term_id, $taxonomy, true );
			wp_remove_object_terms( $post_id, $tag_id, 'post_tag' );
		}

		// Check if tag still has posts and delete if empty.
		$updated_tag = get_term( $tag_id, 'post_tag' );
		if ( $updated_tag && 0 === $updated_tag->count ) {
			wp_delete_term( $tag_id, 'post_tag' );
			$report[] = sprintf( 'Migrated and deleted tag "%s" (slug: %s) to %s.', $tag->name, $tag->slug, $taxonomy );
		} else {
			$report[] = sprintf( 'Migrated tag "%s" (slug: %s) to %s, but it is still used elsewhere.', $tag->name, $tag->slug, $taxonomy );
		}
	}

	restore_current_blog();

	return rest_ensure_response(
		array(
			'success' => true,
			'report'  => $report,
		)
	);
}
