<?php
/**
 * Event Source Import REST Routes
 *
 * Registers generic event-source routes under this plugin's own
 * `extrachill/v1` namespace.
 *
 * @package ExtraChillEvents\Api
 * @since   0.35.0
 */

namespace ExtraChillEvents\Api;

use ExtraChillEvents\Api\Controllers\ArtistUrlImport;

defined( 'ABSPATH' ) || exit;

const EVENT_SOURCE_NAMESPACE = 'extrachill/v1';

/** Register the source-neutral routes. */
function register_event_source_routes_for( string $route_namespace ): void {
	$controller = new ArtistUrlImport();
	register_rest_route(
		$route_namespace,
		'/event-source/preview',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'preview_event_source' ),
			'permission_callback' => array( ArtistUrlImport::class, 'permission_logged_in' ),
			'args'                => array(
				'url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
	register_rest_route(
		$route_namespace,
		'/event-source/submit',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'submit_event_source' ),
			'permission_callback' => array( ArtistUrlImport::class, 'permission_logged_in' ),
			'args'                => array(
				'url'           => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'contact_email' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				),
				'contact_name'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
	register_rest_route(
		$route_namespace,
		'/event-source/(?P<id>\d+)/approve',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'approve_event_source' ),
			'permission_callback' => array( ArtistUrlImport::class, 'permission_admin' ),
			'args'                => array(
				'id'                => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'source_kind'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'entity_term_id'    => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'entity_name'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'venue_term_id'     => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'venue_name'        => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'artist_term_id'    => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_name'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'pipeline_id'       => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'schedule_interval' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		)
	);
	register_rest_route(
		$route_namespace,
		'/event-source/(?P<id>\d+)/reject',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'reject_event_source' ),
			'permission_callback' => array( ArtistUrlImport::class, 'permission_admin' ),
			'args'                => array(
				'id'     => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'reason' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		)
	);
}

/** Register event-source routes on rest_api_init. */
function register_event_source_routes(): void {
	register_event_source_routes_for( EVENT_SOURCE_NAMESPACE );
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_event_source_routes' );
