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
const META_DELIVERY_REF = '_studio_social_delivery_ref';
const META_SOURCE_POST  = '_studio_social_source_post_id';
const META_SOURCE_URL   = '_studio_social_source_url';

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
						'type'       => 'object',
						'properties' => array(
							'url'       => array( 'type' => 'string' ),
							'source_id' => array( 'type' => 'string' ),
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
							'code'      => array( 'type' => 'string' ),
							'retryable' => array( 'type' => 'boolean' ),
							'timestamp' => array( 'type' => 'string' ),
						),
					),
				),
			),
		),
		META_DELIVERY_REF => array(
			'type'              => 'string',
			'description'       => 'Opaque Data Machine Socials delivery reference.',
			'default'           => '',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		),
		META_SOURCE_POST  => array(
			'type'              => 'integer',
			'description'       => 'Canonical main-site article post ID used for this social draft.',
			'default'           => 0,
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
		),
		META_SOURCE_URL   => array(
			'type'              => 'string',
			'description'       => 'Canonical main-site article URL used for this social draft.',
			'default'           => '',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_url',
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

	$result = enqueue_social_publish( $post );
	if ( empty( $result['success'] ) ) {
		log_publish_error( $post->ID, $result );
		return;
	}

	store_social_delivery( $post->ID, $result );
}
add_action( 'transition_post_status', __NAMESPACE__ . '\\on_publish_crosspost', 10, 3 );

/**
 * Enqueue a published Studio social draft through the Socials owner contract.
 *
 * @param \WP_Post $post Published social draft.
 * @return array Stable Socials result.
 */
function enqueue_social_publish( \WP_Post $post ): array {
	$caption = (string) get_post_meta( $post->ID, META_CAPTION, true );
	$assets  = social_asset_refs( $post->ID );
	if ( is_wp_error( $assets ) ) {
		return social_publish_error( (string) $assets->get_error_code(), $assets->get_error_message(), false );
	}

	$platforms   = get_post_meta( $post->ID, META_PLATFORMS, true );
	$media_kind  = (string) get_post_meta( $post->ID, META_MEDIA_KIND, true );
	$input       = array(
		'content_ref'     => array(
			'post_id'      => $post->ID,
			'source_url'   => get_permalink( $post ),
			'caption'      => $caption,
			'content_hash' => hash( 'sha256', $caption ),
			'asset_refs'   => $assets,
		),
		'target_policy'   => array(
			'channels'   => array_values( (array) $platforms ),
			'media_kind' => $media_kind ? $media_kind : 'image',
		),
		'idempotency_key' => social_publish_idempotency_key( $post->ID ),
	);
	$attribution = social_source_attribution( $post->ID );
	if ( is_wp_error( $attribution ) ) {
		return social_publish_error( (string) $attribution->get_error_code(), $attribution->get_error_message(), false );
	}
	if ( $attribution ) {
		$input['attribution_post'] = $attribution;
	}

	$result = execute_social_publish_ability(
		'datamachine/enqueue-social-publish',
		$input
	);

	return $result;
}

/**
 * Resolve canonical article attribution without replacing the review resource.
 *
 * @return array|null|\WP_Error Attribution, null for legacy drafts, or a stable
 *                              error when declared attribution is invalid.
 */
function social_source_attribution( int $review_post_id ) {
	$source_post_id = (int) get_post_meta( $review_post_id, META_SOURCE_POST, true );
	$source_url     = (string) get_post_meta( $review_post_id, META_SOURCE_URL, true );
	$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;

	if ( $source_post_id <= 0 && '' === $source_url ) {
		return null;
	}
	if ( $source_post_id <= 0 || '' === $source_url || $main_blog_id <= 0 ) {
		return social_attribution_error();
	}

	$switched = get_current_blog_id() !== $main_blog_id;
	if ( $switched ) {
		switch_to_blog( $main_blog_id );
	}

	try {
		$source    = get_post( $source_post_id );
		$canonical = $source instanceof \WP_Post ? get_permalink( $source ) : false;
		if (
			! $source instanceof \WP_Post
			|| 'publish' !== $source->post_status
			|| ! is_string( $canonical )
			|| ! hash_equals( $canonical, $source_url )
		) {
			return social_attribution_error();
		}
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return array(
		'site_id' => $main_blog_id,
		'post_id' => $source_post_id,
	);
}

/** Construct the stable fail-closed error for invalid declared attribution. */
function social_attribution_error(): \WP_Error {
	return new \WP_Error(
		'social_publish_attribution_invalid',
		__( 'The source article attribution is no longer valid.', 'extrachill-studio' )
	);
}

/**
 * Build canonical Socials asset references from NetworkMediaItem metadata.
 *
 * URL-only entries are resolved for already-persisted review drafts created
 * before Studio retained NetworkMediaItem::sourceId.
 *
 * @param int $post_id Studio post ID.
 * @return array|\WP_Error
 */
function social_asset_refs( int $post_id ) {
	$images = get_post_meta( $post_id, META_IMAGES, true );
	$images = is_array( $images ) ? $images : array();
	$refs   = array();

	foreach ( $images as $image ) {
		$source_id = is_array( $image ) ? (string) ( $image['source_id'] ?? '' ) : '';
		if ( ! preg_match( '/^[1-9][0-9]*:[1-9][0-9]*$/', $source_id ) ) {
			$source_id = resolve_legacy_social_asset( is_array( $image ) ? (string) ( $image['url'] ?? '' ) : '' );
		}
		if ( '' === $source_id ) {
			return new \WP_Error( 'social_publish_asset_unavailable', __( 'A selected social asset could not be resolved.', 'extrachill-studio' ) );
		}
		$refs[] = array(
			'source_id' => $source_id,
			'role'      => 'image',
		);
	}

	return $refs;
}

/** Resolve a pre-sourceId main-library image URL. */
function resolve_legacy_social_asset( string $url ): string {
	$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
	if ( $main_blog_id <= 0 || '' === $url ) {
		return '';
	}

	$switched = get_current_blog_id() !== $main_blog_id;
	if ( $switched ) {
		switch_to_blog( $main_blog_id );
	}

	try {
		$attachment_id = attachment_url_to_postid( $url );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	return $attachment_id > 0 ? $main_blog_id . ':' . $attachment_id : '';
}

/** Stable idempotency identity for one Studio draft publication. */
function social_publish_idempotency_key( int $post_id ): string {
	return sprintf( 'studio-social-publish:%d:%d', get_current_blog_id(), $post_id );
}

/** Execute one Socials ability without exposing dependency internals. */
function execute_social_publish_ability( string $name, array $input ): array {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return social_publish_error( 'social_publish_capability_unavailable', __( 'Social publishing is currently unavailable.', 'extrachill-studio' ), true );
	}

	$ability = wp_get_ability( $name );
	if ( ! $ability ) {
		return social_publish_error( 'social_publish_capability_unavailable', __( 'Social publishing is currently unavailable.', 'extrachill-studio' ), true );
	}

	$result = $ability->execute( $input );
	if ( is_wp_error( $result ) ) {
		return social_publish_error( (string) $result->get_error_code(), $result->get_error_message(), true );
	}

	return is_array( $result ) && array_key_exists( 'success', $result )
		? $result
		: social_publish_error( 'social_publish_response_invalid', __( 'Social publishing returned an invalid response.', 'extrachill-studio' ), true );
}

/** Construct the stable public failure shape used by Socials abilities. */
function social_publish_error( string $code, string $message, bool $retryable ): array {
	return array(
		'success' => false,
		'error'   => array(
			'code'      => sanitize_key( $code ),
			'message'   => $message,
			'retryable' => $retryable,
		),
	);
}

/** Persist only the opaque owner receipt, never a copy of Socials state. */
function store_social_delivery( int $post_id, array $result ): void {
	$delivery = is_array( $result['delivery'] ?? null ) ? $result['delivery'] : array();
	$ref      = (string) ( $delivery['delivery_ref'] ?? '' );
	if ( preg_match( '/^dop_[a-f0-9]{64}$/', $ref ) ) {
		update_post_meta( $post_id, META_DELIVERY_REF, $ref );
		clear_publish_errors( $post_id );
	}
}

/** Record a failed pre-receipt handoff so an editor can retry it. */
function log_publish_error( int $post_id, array $result ): void {
	$error = is_array( $result['error'] ?? null ) ? $result['error'] : array();
	log_publish_result(
		$post_id,
		array(
			array(
				'platform'  => 'system',
				'success'   => false,
				'code'      => (string) ( $error['code'] ?? 'social_publish_failed' ),
				'error'     => (string) ( $error['message'] ?? __( 'Social publishing failed.', 'extrachill-studio' ) ),
				'retryable' => ! empty( $error['retryable'] ),
				'timestamp' => gmdate( 'c' ),
			),
		)
	);
}

/** Remove obsolete handoff failures after an idempotent enqueue succeeds. */
function clear_publish_errors( int $post_id ): void {
	$existing = get_post_meta( $post_id, META_PUBLISH_LOG, true );
	$existing = is_array( $existing ) ? $existing : array();
	$kept     = array_values(
		array_filter(
			$existing,
			static function ( $entry ) {
				return ! is_array( $entry ) || 'system' !== ( $entry['platform'] ?? '' );
			}
		)
	);
	update_post_meta( $post_id, META_PUBLISH_LOG, $kept );
}

/**
 * Store cross-post results in post meta.
 *
 * @param int   $post_id Post ID.
 * @param array $results Array of result entries.
 */
function log_publish_result( int $post_id, array $results ) {
	$existing = get_post_meta( $post_id, META_PUBLISH_LOG, true );
	$existing = $existing ? $existing : array();
	$merged   = array_merge( $existing, $results );
	update_post_meta( $post_id, META_PUBLISH_LOG, $merged );
}

/**
 * Authorize Socials owner operations against the exact Studio post and actor.
 *
 * @param bool  $authorized Existing authorization decision.
 * @param array $context    Socials delegated operation context.
 * @return bool
 */
function authorize_social_publish( bool $authorized, array $context ): bool {
	if ( $authorized ) {
		return true;
	}

	$input    = is_array( $context['input'] ?? null ) ? $context['input'] : array();
	$actor    = is_array( $context['actor'] ?? null ) ? $context['actor'] : array();
	$post_id  = (int) ( $input['post_id'] ?? 0 );
	$user_id  = (int) ( $actor['user_id'] ?? 0 );
	$post     = $post_id > 0 ? get_post( $post_id ) : null;
	$channels = $post_id > 0 ? get_post_meta( $post_id, META_PLATFORMS, true ) : array();

	return $post instanceof \WP_Post
		&& 'post' === $post->post_type
		&& 'publish' === $post->post_status
		&& is_array( $channels )
		&& ! empty( $channels )
		&& $user_id > 0
		&& user_can( $user_id, 'edit_post', $post_id );
}
add_filter( 'datamachine_socials_delegated_cross_post_authorized', __NAMESPACE__ . '\\authorize_social_publish', 10, 2 );

/** Retrieve the current owner-projected delivery state for a Studio post. */
function get_social_publish_state( int $post_id ): array {
	$ref = (string) get_post_meta( $post_id, META_DELIVERY_REF, true );
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $ref ) ) {
		return social_publish_error( 'social_publish_delivery_pending', __( 'This social delivery has not received an owner receipt yet.', 'extrachill-studio' ), true );
	}

	return execute_social_publish_ability(
		'datamachine/get-social-publish',
		array( 'delivery_ref' => $ref )
	);
}

/** Explicitly retry either a failed delivery or its pre-receipt handoff. */
function retry_social_publish( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return social_publish_error( 'social_publish_post_unavailable', __( 'A published social draft is required.', 'extrachill-studio' ), false );
	}

	$ref = (string) get_post_meta( $post_id, META_DELIVERY_REF, true );
	if ( preg_match( '/^dop_[a-f0-9]{64}$/', $ref ) ) {
		$result = execute_social_publish_ability(
			'datamachine/retry-social-publish',
			array( 'delivery_ref' => $ref )
		);
	} else {
		$result = enqueue_social_publish( $post );
	}

	if ( ! empty( $result['success'] ) ) {
		store_social_delivery( $post_id, $result );
	}

	return $result;
}

/** Register Studio's resource-scoped state and retry operations. */
function register_social_publish_abilities(): void {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	$input_schema  = array(
		'type'                 => 'object',
		'required'             => array( 'post_id' ),
		'properties'           => array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'additionalProperties' => false,
	);
	$output_schema = array(
		'type'                 => 'object',
		'required'             => array( 'success' ),
		'properties'           => array(
			'success'  => array( 'type' => 'boolean' ),
			'delivery' => array( 'type' => 'object' ),
			'error'    => array( 'type' => 'object' ),
		),
		'additionalProperties' => false,
	);

	foreach (
		array(
			'extrachill/get-social-publish-state' => array( __( 'Get Social Publish State', 'extrachill-studio' ), __NAMESPACE__ . '\\execute_get_social_publish_state' ),
			'extrachill/retry-social-publish'     => array( __( 'Retry Social Publish', 'extrachill-studio' ), __NAMESPACE__ . '\\execute_retry_social_publish' ),
		) as $name => $definition
	) {
		wp_register_ability(
			$name,
			array(
				'label'               => $definition[0],
				'description'         => __( 'Read or retry the Socials-owned delivery for an editable Studio post.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema,
				'execute_callback'    => $definition[1],
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}
}
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_social_publish_abilities' );

/** Ability callback for owner-projected delivery state. */
function execute_get_social_publish_state( array $input ): array {
	$post_id = (int) ( $input['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return social_publish_error( 'social_publish_forbidden', __( 'You cannot inspect this social delivery.', 'extrachill-studio' ), false );
	}

	return get_social_publish_state( $post_id );
}

/** Ability callback for an explicit owner-authorized retry. */
function execute_retry_social_publish( array $input ): array {
	$post_id = (int) ( $input['post_id'] ?? 0 );
	if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return social_publish_error( 'social_publish_forbidden', __( 'You cannot retry this social delivery.', 'extrachill-studio' ), false );
	}

	return retry_social_publish( $post_id );
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
		$delivery_ref = (string) get_post_meta( $post_id, META_DELIVERY_REF, true );
		if ( preg_match( '/^dop_[a-f0-9]{64}$/', $delivery_ref ) ) {
			$state = get_social_publish_state( $post_id );
			if ( ! empty( $state['success'] ) && is_array( $state['delivery'] ?? null ) ) {
				$status = (string) ( $state['delivery']['status'] ?? 'unknown' );
				echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) );
				return;
			}
		}

		$log = get_post_meta( $post_id, META_PUBLISH_LOG, true );
		if ( ! empty( $log ) && is_array( $log ) ) {
			$retryable = array_filter( $log, static function ( $entry ) {
				return is_array( $entry ) && ! empty( $entry['retryable'] );
			} );
			if ( ! empty( $retryable ) ) {
				echo esc_html__( 'Retry needed', 'extrachill-studio' );
				return;
			}
			$success = count( array_filter( $log, function ( $entry ) {
				return ! empty( $entry['success'] );
			} ) );
			$total   = count( $log );
			/* translators: 1: success count 2: total count */
			printf( esc_html__( '%1$d / %2$d posted', 'extrachill-studio' ), absint( $success ), absint( $total ) );
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
