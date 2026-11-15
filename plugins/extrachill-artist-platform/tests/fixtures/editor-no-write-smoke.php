<?php
/**
 * Standalone smoke: rendering the Link Page editor entry point on GET for an
 * artist with no Link Page must report setup state and never provision.
 *
 * Exercises the live path: the extrachill/link-page-editor block (registered by
 * extrachill-link-pages) filtered by manage-link-page-routing.php.
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['writes'] = 0;
function add_action() {}
function add_filter() {}
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function ec_get_artists_for_user() { return array( 20 ); }
function get_post( $id ) { return 20 === (int) $id ? (object) array(
	'ID'          => 20,
	'post_status' => 'publish',
	'post_title'  => 'Artist',
	'post_name'   => 'artist',
) : null; }
function ec_get_link_page_for_artist() { return false; }
function ec_artist_link_page_is_published( $id ) { return (bool) $id; }
function ec_create_link_page() { ++$GLOBALS['writes'];
	return 40; }
function __( $text ) { return $text; }
function esc_html( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html_e( $text ) { echo $text; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standalone stub.
function esc_attr( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function admin_url( $path = '' ) { return 'https://artist.example/wp-admin/' . $path; }
function home_url( $path = '' ) { return 'https://artist.example' . $path; }
function wp_nonce_field() {}

require dirname( __DIR__, 2 ) . '/inc/link-pages/manage-link-page-routing.php';
$html = ec_manage_link_page_create_prompt( '<div id="editor-mount"></div>', array( 'blockName' => 'extrachill/link-page-editor' ) );

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone smoke harness output; runs outside WP.
echo json_encode(
	array(
		'writes'      => $GLOBALS['writes'],
		'setup_state' => false !== strpos( $html, 'data-link-page-setup-state="create"' ),
		'cta'         => false !== strpos( $html, 'Create Link Page for Artist' ),
	)
);
