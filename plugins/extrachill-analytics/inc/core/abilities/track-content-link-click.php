<?php
/**
 * REST-visible first-party in-article link tracking (#1436).
 *
 * Records a click on a network link inside the post body, same host included.
 * The source post is proven by the same server-rendered pageview proof the
 * pageview beacon carries, so a browser cannot attribute clicks to an
 * arbitrary post.
 *
 * @package ExtraChill\Analytics
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** Register the bounded browser adapter. */
function extrachill_analytics_register_track_content_link_click_ability(): void {
	wp_register_ability(
		'extrachill/track-content-link-click',
		array(
			'label'               => __( 'Track Content Link Click', 'extrachill-analytics' ),
			'description'         => __( 'Record a click on a network link inside a post body.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'proof'      => array(
						'type'      => 'string',
						'maxLength' => 128,
					),
					'dest_host'  => array(
						'type'      => 'string',
						'maxLength' => 253,
					),
					'dest_path'  => array(
						'type'      => 'string',
						'maxLength' => 512,
					),
					'label'      => array(
						'type'      => 'string',
						'maxLength' => 80,
					),
					'source_url' => array(
						'type'      => 'string',
						'maxLength' => 2048,
					),
				),
				'required'   => array( 'post_id', 'proof', 'dest_host', 'dest_path', 'label', 'source_url' ),
			),
			'output_schema'       => array( 'type' => 'integer' ),
			'execute_callback'    => 'extrachill_analytics_ability_track_content_link_click',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => false,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Verify the source post, normalize dimensions, and write through shared admission.
 *
 * @param array $input Browser dimensions.
 * @return int|WP_Error Event ID or admission error.
 */
function extrachill_analytics_ability_track_content_link_click( array $input ) {
	$post_id     = (int) ( $input['post_id'] ?? 0 );
	$source_url  = (string) ( $input['source_url'] ?? '' );
	$source_path = extrachill_analytics_normalize_route_path( extrachill_analytics_canonicalize_tracked_url( $source_url ) );
	$proof       = (string) ( $input['proof'] ?? '' );

	// The proof binds blog, post, host, path and route family at render time.
	$expected = extrachill_analytics_pageview_proof( $post_id, $source_path, 'singular', extrachill_analytics_public_write_source_host() );
	if ( $post_id <= 0 || '' === $source_path || '' === $proof || ! hash_equals( $expected, $proof ) ) {
		return new WP_Error( 'invalid_content_link_source', __( 'Link source does not match the rendered post.', 'extrachill-analytics' ), array( 'status' => 403 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || ! extrachill_analytics_write_post_is_published( $post ) ) {
		return new WP_Error( 'invalid_content_link_source', __( 'Link source post is not publicly viewable.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}

	$path = (string) ( $input['dest_path'] ?? '' );
	// Accept path input only, never a URL or protocol-relative authority.
	if ( '/' !== substr( $path, 0, 1 ) || '//' === substr( $path, 0, 2 ) ) {
		return new WP_Error( 'invalid_content_link', __( 'A destination path is required.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}
	$path = substr( substr( $path, 0, strcspn( $path, '?#' ) ), 0, 512 );
	// REST endpoints (ticket redirects and similar) are actions, not content.
	$rest_prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
	if ( 0 === strpos( $path, $rest_prefix ) ) {
		return new WP_Error( 'invalid_content_link', __( 'REST endpoints are not content links.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}
	$host = strtolower( trim( (string) ( $input['dest_host'] ?? '' ) ) );

	return extrachill_analytics_ability_track_event(
		array(
			'event_type' => EC_ANALYTICS_EVENT_CONTENT_LINK,
			'event_data' => array(
				'post_id'   => $post_id,
				'dest_host' => $host,
				'dest_path' => $path,
				'label'     => substr( trim( (string) preg_replace( '/\s+/', ' ', (string) ( $input['label'] ?? '' ) ) ), 0, 80 ),
				'link_kind' => extrachill_analytics_public_write_host_is_current_site( $host ) ? 'same_site' : 'network',
			),
			'source_url' => $source_url,
		)
	);
}
