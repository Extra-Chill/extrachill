<?php
/**
 * Publish Router — Tag-based routing for Studio posts.
 *
 * When a Studio post is published, its tags determine the outcome:
 *
 *   "blog"   → transfer to extrachill.com (blog ID 1)
 *   "wire"   → transfer to wire.extrachill.com
 *   "social" → cross-post to social platforms via DM Socials
 *
 * Tags can be combined: "blog" + "social" = transfer + cross-post.
 * Posts without routing tags are published on studio only.
 *
 * @package ExtraChillStudio
 * @since   0.2.0
 */

namespace ExtraChillStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tag → blog ID mapping for transfer destinations.
 *
 * Filterable so other plugins can add routing targets.
 *
 * @return array<string, int> Tag slug => blog ID.
 */
function get_transfer_routes(): array {
	return apply_filters( 'extrachill_studio_transfer_routes', array(
		'blog' => 1,   // extrachill.com
		'wire' => 11,  // wire.extrachill.com
	) );
}

/**
 * Route a Studio post on publish based on its tags.
 *
 * Hooks into transition_post_status. Fires transfer and/or cross-post
 * depending on which routing tags are present.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Old post status.
 * @param \WP_Post $post       Post object.
 */
function route_on_publish( string $new_status, string $old_status, \WP_Post $post ) {
	// Only fire on transition TO publish.
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// Only act on regular posts.
	if ( 'post' !== $post->post_type ) {
		return;
	}

	// Get the post's tags as slugs.
	$tags      = wp_get_post_tags( $post->ID, array( 'fields' => 'slugs' ) );
	$tag_slugs = is_array( $tags ) ? $tags : array();

	if ( empty( $tag_slugs ) ) {
		return;
	}

	$transfer_routes = get_transfer_routes();
	$source_blog_id  = get_current_blog_id();
	$results         = array();

	// ── Handle transfers ────────────────────────────────────────────────

	foreach ( $transfer_routes as $tag => $target_blog_id ) {
		if ( ! in_array( $tag, $tag_slugs, true ) ) {
			continue;
		}

		// Don't transfer to ourselves.
		if ( $target_blog_id === $source_blog_id ) {
			continue;
		}

		$new_id = transfer_post(
			$post->ID,
			$source_blog_id,
			$target_blog_id,
			false // Don't delete source yet — we may need it for cross-posting.
		);

		if ( is_wp_error( $new_id ) ) {
			$results[] = array(
				'action'    => 'transfer',
				'target'    => $tag,
				'blog_id'   => $target_blog_id,
				'success'   => false,
				'error'     => $new_id->get_error_message(),
				'timestamp' => gmdate( 'c' ),
			);
		} else {
			$results[] = array(
				'action'    => 'transfer',
				'target'    => $tag,
				'blog_id'   => $target_blog_id,
				'new_id'    => $new_id,
				'success'   => true,
				'timestamp' => gmdate( 'c' ),
			);
		}
	}

	// ── Handle social cross-posting ─────────────────────────────────────
	// The "social" tag triggers cross-posting via social-drafts.php.
	// That hook (on_publish_crosspost) already runs on transition_post_status
	// and checks for _studio_social_platforms meta. We don't duplicate it here.
	// If "social" is tagged but no platforms meta is set, nothing happens.

	// ── Log routing results ─────────────────────────────────────────────

	if ( ! empty( $results ) ) {
		$existing_log = get_post_meta( $post->ID, '_studio_route_log', true ) ?: array();
		$merged       = array_merge( $existing_log, $results );
		update_post_meta( $post->ID, '_studio_route_log', $merged );
	}

	// ── Delete source if all transfers succeeded ────────────────────────

	$transfers     = array_filter( $results, function ( $r ) {
		return 'transfer' === $r['action'];
	} );
	$all_succeeded = ! empty( $transfers ) && count( array_filter( $transfers, function ( $r ) {
		return ! empty( $r['success'] );
	} ) ) === count( $transfers );

	// Only delete if we did at least one transfer and all succeeded.
	// Posts tagged "social" only (no transfer tags) stay on studio.
	if ( $all_succeeded ) {
		// Trash instead of force-delete for safety. Admin can clean up later.
		wp_trash_post( $post->ID );
	}
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\route_on_publish', 5, 3 );

/**
 * Register the route log meta for REST visibility.
 */
function register_route_meta() {
	register_post_meta( 'post', '_studio_route_log', array(
		'type'          => 'array',
		'description'   => 'Log of publish routing actions (transfers, cross-posts).',
		'default'       => array(),
		'single'        => true,
		'show_in_rest'  => array(
			'schema' => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'action'    => array( 'type' => 'string' ),
						'target'    => array( 'type' => 'string' ),
						'blog_id'   => array( 'type' => 'integer' ),
						'new_id'    => array( 'type' => 'integer' ),
						'success'   => array( 'type' => 'boolean' ),
						'error'     => array( 'type' => 'string' ),
						'timestamp' => array( 'type' => 'string' ),
					),
				),
			),
		),
		'auth_callback' => __NAMESPACE__ . '\\can_edit_social_meta',
	) );
}
add_action( 'init', __NAMESPACE__ . '\\register_route_meta' );
