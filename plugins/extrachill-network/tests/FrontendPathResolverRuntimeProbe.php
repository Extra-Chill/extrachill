<?php
/**
 * Target-site WP-CLI probe for the worktree frontend path resolver.
 *
 * @package ExtraChillNetwork
 */

$expected_type = getenv( 'EC_FRONTEND_PATH_RESOLVER_EXPECTED_TYPE' );
if ( ! is_string( $expected_type ) || '' === $expected_type ) {
	throw new RuntimeException( 'Expected post type is required.' );
}

require_once dirname( __DIR__ ) . '/inc/core/frontend-path-resolver.php';
do_action( 'rest_api_init', rest_get_server() );

$post_ids = get_posts(
	array(
		'post_type'      => $expected_type,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
if ( empty( $post_ids ) ) {
	throw new RuntimeException( sprintf( 'No published %s post is available for the runtime probe.', esc_html( $expected_type ) ) );
}

$probe_post_id = (int) $post_ids[0];
$probe_path    = ec_normalize_frontend_path( (string) wp_parse_url( get_permalink( $probe_post_id ), PHP_URL_PATH ) );
$request       = new WP_REST_Request( 'GET', '/extrachill-network/v1/frontend-path-resolution' );
$request->set_method( 'POST' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( wp_json_encode( array( 'paths' => array( $probe_path ) ) ) );
$response = rest_do_request( $request );
$data     = $response->get_data();
$result   = $data['results'][ $probe_path ] ?? array();

if ( $response->is_error() || 'complete' !== ( $data['status'] ?? null ) || 'resolved' !== ( $result['status'] ?? null ) || (int) ( $result['candidate']['post_id'] ?? 0 ) !== $probe_post_id || ( $result['candidate']['post_type'] ?? null ) !== $expected_type ) {
	throw new RuntimeException( sprintf( 'Runtime resolver probe failed for %s: %s', esc_html( $expected_type ), esc_html( wp_json_encode( $data ) ) ) );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI output stream, not a filesystem write.
fwrite( STDOUT, sprintf( 'Resolved %s post %d at %s.\n', $expected_type, $probe_post_id, $probe_path ) );
