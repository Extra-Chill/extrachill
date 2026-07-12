<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $text ) {
	return $text;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

$GLOBALS['ec_test'] = array();

function sanitize_text_field( $value ) {
	return trim( $value );
}

function ec_get_artist_relationships_for_admin( $view, $search ) {
	$GLOBALS['ec_test']['list'] = array( $view, $search );
	return $GLOBALS['ec_test']['list_result'] ?? array();
}

function ec_get_orphaned_artist_relationships() {
	return $GLOBALS['ec_test']['orphan_result'] ?? array();
}

function get_userdata( $user_id ) {
	return empty( $GLOBALS['ec_test']['missing_user'] ) ? (object) array( 'ID' => $user_id ) : false;
}

function ec_add_artist_membership( $user_id, $artist_id ) {
	$GLOBALS['ec_test']['added'] = array( $user_id, $artist_id );
	return $GLOBALS['ec_test']['add_result'] ?? true;
}

function ec_remove_artist_membership( $user_id, $artist_id ) {
	$GLOBALS['ec_test']['removed'] = array( $user_id, $artist_id );
	return true;
}

require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-list-artist-relationships.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-link-artist-relationship.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-unlink-artist-relationship.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-list-orphan-artist-relationships.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-cleanup-artist-relationships.php';
