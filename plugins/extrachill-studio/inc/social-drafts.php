<?php
/**
 * Social Drafts
 *
 * Turns regular posts on studio.extrachill.com into a social publishing
 * workflow. Posts carry social metadata (platforms, caption, images) and
 * go through draft → pending → publish. Publishing triggers cross-post
 * via Data Machine Socials.
 *
 * @package ExtraChillStudio
 * @since   0.2.0
 */

namespace ExtraChillStudio;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta keys for social draft data.
 */
const META_PLATFORMS    = '_studio_social_platforms';
const META_CAPTION      = '_studio_social_caption';
const META_IMAGES       = '_studio_social_images';
const META_ASPECT_RATIO = '_studio_social_aspect_ratio';
const META_MEDIA_KIND   = '_studio_social_media_kind';
const META_VIDEO_URL    = '_studio_social_video_url';
const META_COVER_URL    = '_studio_social_cover_url';
const META_PUBLISH_LOG  = '_studio_social_publish_log';

/**
 * Register post meta for social drafts.
 */
function register_social_meta() {
	$meta_fields = array(
		META_PLATFORMS    => array(
			'type'         => 'array',
			'description'  => 'Target social platforms for cross-posting.',
			'default'      => array(),
			'single'       => true,
			'show_in_rest' => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		),
		META_CAPTION      => array(
			'type'              => 'string',
			'description'       => 'Social post caption text.',
			'default'           => '',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_textarea_field',
		),
		META_IMAGES       => array(
			'type'         => 'array',
			'description'  => 'Image URLs for social post.',
			'default'      => array(),
			'single'       => true,
			'show_in_rest' => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'url' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		META_ASPECT_RATIO => array(
			'type'              => 'string',
			'description'       => 'Aspect ratio for images.',
			'default'           => '4:5',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		META_MEDIA_KIND   => array(
			'type'              => 'string',
			'description'       => 'Media kind: image, carousel, reel, story.',
			'default'           => 'image',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		META_VIDEO_URL    => array(
			'type'              => 'string',
			'description'       => 'Video URL for reel/story posts.',
			'default'           => '',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_url',
		),
		META_COVER_URL    => array(
			'type'              => 'string',
			'description'       => 'Cover image URL for video posts.',
			'default'           => '',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_url',
		),
		META_PUBLISH_LOG  => array(
			'type'         => 'array',
			'description'  => 'Log of cross-post results per platform.',
			'default'      => array(),
			'single'       => true,
			'show_in_rest' => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'platform'  => array( 'type' => 'string' ),
							'success'   => array( 'type' => 'boolean' ),
							'post_id'   => array( 'type' => 'string' ),
							'url'       => array( 'type' => 'string' ),
							'error'     => array( 'type' => 'string' ),
							'timestamp' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
	);

	foreach ( $meta_fields as $key => $args ) {
		register_post_meta( 'post', $key, array_merge(
			array( 'auth_callback' => __NAMESPACE__ . '\\can_edit_social_meta' ),
			$args
		) );
	}
}
add_action( 'init', __NAMESPACE__ . '\\register_social_meta' );

/**
 * Auth callback for social meta — team members with edit_posts.
 *
 * @return bool
 */
function can_edit_social_meta(): bool {
	return current_user_can( 'edit_posts' );
}

/**
 * Fire cross-post when a post transitions to 'publish'.
 *
 * Only acts on posts that have social platform targets set.
 * Requires Data Machine Socials to be active.
 *
 * @param string   $new_status New post status.
 * @param string   $old_status Old post status.
 * @param \WP_Post $post       Post object.
 */
function on_publish_crosspost( string $new_status, string $old_status, \WP_Post $post ) {
	// Only fire on transition TO publish (not re-saves of already-published posts).
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	// Only act on regular posts.
	if ( 'post' !== $post->post_type ) {
		return;
	}

	$platforms = get_post_meta( $post->ID, META_PLATFORMS, true );
	if ( empty( $platforms ) || ! is_array( $platforms ) ) {
		return;
	}

	$caption = get_post_meta( $post->ID, META_CAPTION, true );
	if ( empty( $caption ) ) {
		return;
	}

	// Check that Data Machine Socials REST cross-post is available.
	if ( ! class_exists( 'DataMachineSocials\\RestApi' ) ) {
		log_publish_result( $post->ID, array(
			array(
				'platform'  => 'system',
				'success'   => false,
				'error'     => 'Data Machine Socials plugin not active.',
				'timestamp' => gmdate( 'c' ),
			),
		) );
		return;
	}

	$images       = get_post_meta( $post->ID, META_IMAGES, true ) ?: array();
	$aspect_ratio = get_post_meta( $post->ID, META_ASPECT_RATIO, true ) ?: '4:5';
	$media_kind   = get_post_meta( $post->ID, META_MEDIA_KIND, true ) ?: 'image';
	$video_url    = get_post_meta( $post->ID, META_VIDEO_URL, true ) ?: '';
	$cover_url    = get_post_meta( $post->ID, META_COVER_URL, true ) ?: '';

	// Build a WP_REST_Request to the cross-post endpoint.
	$request = new \WP_REST_Request( 'POST', '/datamachine-socials/v1/post' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( array(
		'platforms'     => $platforms,
		'caption'       => $caption,
		'images'        => $images,
		'aspect_ratio'  => $aspect_ratio,
		'media_kind'    => $media_kind,
		'video_url'     => $video_url,
		'cover_url'     => $cover_url,
		'post_id'       => $post->ID,
		'share_to_feed' => true,
	) ) );

	$response = rest_do_request( $request );
	$data     = $response->get_data();

	// Log results.
	$log = array();
	if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
		foreach ( $data['results'] as $result ) {
			$log[] = array(
				'platform'  => $result['platform'] ?? 'unknown',
				'success'   => $result['success'] ?? false,
				'post_id'   => $result['post_id'] ?? '',
				'url'       => $result['url'] ?? '',
				'error'     => $result['error'] ?? '',
				'timestamp' => gmdate( 'c' ),
			);
		}
	} else {
		$log[] = array(
			'platform'  => 'system',
			'success'   => false,
			'error'     => $data['error'] ?? 'Unknown cross-post error.',
			'timestamp' => gmdate( 'c' ),
		);
	}

	log_publish_result( $post->ID, $log );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\on_publish_crosspost', 10, 3 );

/**
 * Store cross-post results in post meta.
 *
 * @param int   $post_id Post ID.
 * @param array $results Array of result entries.
 */
function log_publish_result( int $post_id, array $results ) {
	$existing = get_post_meta( $post_id, META_PUBLISH_LOG, true ) ?: array();
	$merged   = array_merge( $existing, $results );
	update_post_meta( $post_id, META_PUBLISH_LOG, $merged );
}

/**
 * Add social draft columns to the posts list table in wp-admin.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function add_admin_columns( array $columns ): array {
	$columns['studio_platforms'] = __( 'Platforms', 'extrachill-studio' );
	$columns['studio_status']    = __( 'Social Status', 'extrachill-studio' );
	return $columns;
}
add_filter( 'manage_post_posts_columns', __NAMESPACE__ . '\\add_admin_columns' );

/**
 * Render social draft column content.
 *
 * @param string $column  Column name.
 * @param int    $post_id Post ID.
 */
function render_admin_columns( string $column, int $post_id ) {
	if ( 'studio_platforms' === $column ) {
		$platforms = get_post_meta( $post_id, META_PLATFORMS, true );
		if ( ! empty( $platforms ) && is_array( $platforms ) ) {
			echo esc_html( implode( ', ', $platforms ) );
		} else {
			echo '—';
		}
	}

	if ( 'studio_status' === $column ) {
		$log = get_post_meta( $post_id, META_PUBLISH_LOG, true );
		if ( ! empty( $log ) && is_array( $log ) ) {
			$success = count( array_filter( $log, function ( $entry ) {
				return ! empty( $entry['success'] );
			} ) );
			$total   = count( $log );
			/* translators: 1: success count 2: total count */
			printf( esc_html__( '%1$d / %2$d posted', 'extrachill-studio' ), $success, $total );
		} else {
			$platforms = get_post_meta( $post_id, META_PLATFORMS, true );
			if ( ! empty( $platforms ) ) {
				echo esc_html__( 'Pending', 'extrachill-studio' );
			} else {
				echo '—';
			}
		}
	}
}
add_action( 'manage_post_posts_custom_column', __NAMESPACE__ . '\\render_admin_columns', 10, 2 );
