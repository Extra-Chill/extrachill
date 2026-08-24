<?php

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['writes'] = 0;
function is_user_logged_in() { return true; }
function get_current_user_id() { return 7; }
function ec_get_artists_for_user() { return array( 20 ); }
function get_post( $id ) { return 20 === (int) $id ? (object) array( 'ID' => 20, 'post_status' => 'publish', 'post_title' => 'Artist', 'post_name' => 'artist' ) : null; }
function ec_get_link_page_for_artist() { return false; }
function ec_create_link_page() { ++$GLOBALS['writes']; return 40; }
function get_post_status() { return false; }
function esc_html__( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function site_url( $path = '' ) { return 'https://artist.example' . $path; }

ob_start();
require dirname( __DIR__, 2 ) . '/src/blocks/link-page-editor/render.php';
$html = ob_get_clean();

echo json_encode(
	array(
		'writes'      => $GLOBALS['writes'],
		'setup_state' => false !== strpos( $html, 'data-link-page-setup-state="required"' ),
		'cta'         => false !== strpos( $html, 'Complete Artist Setup' ),
	)
);
