<?php
/**
 * Transcription REST Routes
 *
 * Two thin REST handlers that delegate entirely to abilities. Per the platform
 * contract, the REST layer owns no business logic — it only resolves the
 * ability and calls execute().
 *
 * Routes live under `extrachill/v1/transcribe*`, which the route-affinity
 * middleware in extrachill-api forwards to the owning subsite automatically
 * for cross-site requests. No special wiring needed.
 *
 * @package    ExtraChillStudio
 * @subpackage Transcription
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the transcribe REST routes.
 *
 * @since 0.10.0
 *
 * @return void
 */
function ec_studio_transcription_register_rest_routes(): void {
	register_rest_route(
		'extrachill/v1',
		'/transcribe',
		array(
			'methods'             => 'POST',
			'callback'            => static function ( \WP_REST_Request $request ) {
				if ( ! function_exists( 'wp_get_ability' ) ) {
					return new \WP_Error( 'ability_unavailable', __( 'Abilities API is not available.', 'extrachill-studio' ), array( 'status' => 500 ) );
				}
				$ability = wp_get_ability( 'extrachill/transcribe-recording' );
				if ( ! $ability ) {
					return new \WP_Error( 'ability_unavailable', __( 'extrachill/transcribe-recording ability is not registered.', 'extrachill-studio' ), array( 'status' => 500 ) );
				}
				$params = $request->get_json_params();
				return $ability->execute( is_array( $params ) ? $params : array() );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' ) || ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() );
			},
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/transcribe/(?P<job_id>[a-f0-9-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => static function ( \WP_REST_Request $request ) {
				if ( ! function_exists( 'wp_get_ability' ) ) {
					return new \WP_Error( 'ability_unavailable', __( 'Abilities API is not available.', 'extrachill-studio' ), array( 'status' => 500 ) );
				}
				$ability = wp_get_ability( 'extrachill/transcription-job-status' );
				if ( ! $ability ) {
					return new \WP_Error( 'ability_unavailable', __( 'extrachill/transcription-job-status ability is not registered.', 'extrachill-studio' ), array( 'status' => 500 ) );
				}
				return $ability->execute( array( 'job_id' => (string) $request['job_id'] ) );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' ) || ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() );
			},
		)
	);
}
add_action( 'rest_api_init', 'ec_studio_transcription_register_rest_routes' );
