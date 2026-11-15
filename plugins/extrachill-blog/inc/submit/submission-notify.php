<?php
/**
 * Publication notifications for main-blog review submissions from any route.
 *
 * @package ExtraChillBlog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Record the author when a contributor submits a post for review.
 *
 * Existing notification sources retain ownership of their submissions so the
 * generic publish observer does not send a second notification and email.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Previous post status.
 * @param \WP_Post $post       Post being transitioned.
 */
function extrachill_blog_mark_submission( $new_status, $old_status, $post ) {
	if ( 'pending' !== $new_status || 'pending' === $old_status || ! is_main_site() || 'post' !== $post->post_type ) {
		return;
	}

	if ( ! function_exists( 'ec_users_get_publish_notify_sources' ) ) {
		return;
	}

	if ( metadata_exists( 'post', $post->ID, '_ec_blog_submission' ) || user_can( (int) $post->post_author, 'publish_posts' ) ) {
		return;
	}

	foreach ( ec_users_get_publish_notify_sources() as $context => $descriptor ) {
		if ( 'blog_submission' === $context || empty( $descriptor['meta_key'] ) ) {
			continue;
		}
		if ( metadata_exists( 'post', $post->ID, $descriptor['meta_key'] ) ) {
			return;
		}
	}

	add_post_meta(
		$post->ID,
		'_ec_blog_submission',
		array(
			'user_id'      => (int) $post->post_author,
			'submitted_at' => gmdate( 'c' ),
		),
		true
	);
}
add_action( 'transition_post_status', 'extrachill_blog_mark_submission', 10, 3 );

/**
 * Register with the shared bell notification and branded email producer.
 */
function extrachill_blog_register_submission_notification() {
	if ( function_exists( 'ec_users_register_publish_notify_source' ) ) {
		ec_users_register_publish_notify_source(
			'blog_submission',
			array(
				'meta_key'       => '_ec_blog_submission',
				'user_id_field'  => 'user_id',
				'type'           => 'blog_submission_published',
				/* translators: %s: published post title. */
				'title_template' => __( 'Your post "%s" is live on Extra Chill', 'extrachill-blog' ),
			)
		);
	}
}
add_action( 'init', 'extrachill_blog_register_submission_notification', 20 );
