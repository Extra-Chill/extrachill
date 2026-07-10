<?php
/**
 * Artist URL Import REST Routes
 *
 * Registers the four artist-URL-import REST routes under this plugin's own
 * `extrachill/v1` namespace (matching `extrachill/v1/event-submissions`).
 * Migrated out of data-machine-events in extrachill-events#200.
 *
 * Compatibility (extrachill-events#200): the event-submission block
 * historically called these under the data-machine-events REST namespace
 * `datamachine/v1/artist-url/*`. To avoid breaking any cached page markup
 * or third-party consumer mid-deploy, the old `datamachine/v1` routes are
 * ALSO registered here as deprecated aliases for one release. The block's
 * own render.php is repointed to the new `extrachill/v1` paths in this same
 * change; the aliases are belt-and-braces and should be removed in a
 * follow-up once no traffic hits them.
 *
 * @package ExtraChillEvents\Api
 * @since   0.35.0
 */

namespace ExtraChillEvents\Api;

use ExtraChillEvents\Api\Controllers\ArtistUrlImport;

defined( 'ABSPATH' ) || exit;

const ARTIST_URL_NAMESPACE            = 'extrachill/v1';
const ARTIST_URL_NAMESPACE_DEPRECATED = 'datamachine/v1';

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

/**
 * Register all artist-url routes on rest_api_init.
 *
 * Registers under the canonical `extrachill/v1` namespace plus the
 * deprecated `datamachine/v1` namespace (one-release compat alias).
 *
 * @return void
 */
function register_artist_url_routes(): void {
	register_artist_url_routes_for( ARTIST_URL_NAMESPACE );

	/**
	 * Filter whether to keep registering the deprecated `datamachine/v1`
	 * artist-url route aliases. Defaults to true for one release so cached
	 * page markup keeps working through a deploy. Set to false (or remove
	 * the routes in a follow-up) once no traffic hits the old paths.
	 *
	 * @param bool $enabled Whether the deprecated aliases are registered.
	 */
	if ( (bool) apply_filters( 'extrachill_events_artist_url_legacy_routes', true ) ) {
		register_artist_url_routes_for( ARTIST_URL_NAMESPACE_DEPRECATED );
	}
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_artist_url_routes' );
