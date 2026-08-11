<?php
/**
 * Artist URL Import REST Routes
 *
 * Registers generic event-source routes under this plugin's own
 * `extrachill/v1` namespace. The four shipped `artist-url` routes remain
 * Phase 1 compatibility aliases and delegate through the artist ability
 * aliases to the same generic intake implementation.
 *
 * The one-release `datamachine/v1/artist-url/*` compatibility aliases that
 * shipped with the #200 migration were retired in extrachill-events#256: the
 * event-submission block was repointed to the canonical `extrachill/v1`
 * paths in the same #201 change, no in-repo consumer referenced the aliases,
 * and the compat window is closed. Only the canonical namespace is served.
 *
 * @package ExtraChillEvents\Api
 * @since   0.35.0
 */

namespace ExtraChillEvents\Api;

use ExtraChillEvents\Api\Controllers\ArtistUrlImport;

defined( 'ABSPATH' ) || exit;

const ARTIST_URL_NAMESPACE = 'extrachill/v1';

/**
 * Register the artist-url routes for a given namespace.
 *
 * @param string $route_namespace REST namespace.
 * @return void
 */
function register_artist_url_routes_for( string $route_namespace ): void {
	$controller = new ArtistUrlImport();

	register_rest_route(
		$route_namespace,
		'/artist-url/preview',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'preview' ),
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
		'/artist-url/submit',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'submit' ),
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
		'/artist-url/(?P<id>\d+)/approve',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'approve' ),
			'permission_callback' => array( ArtistUrlImport::class, 'permission_admin' ),
			'args'                => array(
				'id'                => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_term_id'    => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'artist_name'       => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
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
		'/artist-url/(?P<id>\d+)/reject',
		array(
			'methods'             => 'POST',
			'callback'            => array( $controller, 'reject' ),
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

/** Register the source-neutral Phase 1 routes. */
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

/**
 * Register all artist-url routes on rest_api_init.
 *
 * Both generic event-source routes and shipped artist compatibility routes
 * are registered in the canonical `extrachill/v1` namespace.
 *
 * @return void
 */
function register_artist_url_routes(): void {
	register_event_source_routes_for( ARTIST_URL_NAMESPACE );
	register_artist_url_routes_for( ARTIST_URL_NAMESPACE );
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_artist_url_routes' );
