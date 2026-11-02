<?php
/**
 * Journey fixture mu-plugin: photo-contest-night.
 *
 * Mounted only when this journey is selected. Substitutes sandbox-missing
 * dependencies, never product behavior:
 * - Turnstile cannot be solved by an automated browser: the product's own
 *   test seam (`extrachill_bypass_turnstile_verification`). The submit
 *   route's Turnstile permission_callback still runs; it just accepts.
 * - No SMTP.
 * Records evidence the grade cannot otherwise see: every photo contest REST
 * answer, and what each persona saw (posted to ?ec_photo_contest_observe=1).
 *
 * @package ExtraChillNetwork
 */

add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
add_filter( 'pre_wp_mail', '__return_true' );

add_filter(
	'rest_post_dispatch',
	static function ( $response, $server, $request ) {
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/extrachill/v1/content-blocks/photo-contest/' ) && '/extrachill/v1/tools/qr-code' !== $route ) {
			return $response;
		}
		$data  = $response instanceof WP_REST_Response ? $response->get_data() : null;
		$log   = get_site_option( 'ec_photo_contest_journey_rest', array() );
		$log[] = array(
			'route'      => $route,
			'method'     => $request->get_method(),
			'status'     => $response instanceof WP_REST_Response ? (int) $response->get_status() : 0,
			'error_code' => is_array( $data ) ? ( $data['code'] ?? null ) : null,
			'message'    => is_array( $data ) ? ( $data['message'] ?? null ) : null,
			'turnstile'  => '' !== (string) $request->get_param( 'turnstile_response' ),
		);
		update_site_option( 'ec_photo_contest_journey_rest', array_slice( $log, -40 ) );
		return $response;
	},
	10,
	3
);

add_action(
	'init',
	static function () {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sandbox-only evidence sink.
		if ( ! isset( $_GET['ec_photo_contest_observe'] ) ) {
			return;
		}
		$body  = json_decode( (string) file_get_contents( 'php://input' ), true );
		$log   = get_site_option( 'ec_photo_contest_journey_observations', array() );
		$log[] = is_array( $body ) ? $body : array( 'raw' => 'unparseable' );
		update_site_option( 'ec_photo_contest_journey_observations', array_slice( $log, -60 ) );
		status_header( 204 );
		exit;
	},
	0
);
