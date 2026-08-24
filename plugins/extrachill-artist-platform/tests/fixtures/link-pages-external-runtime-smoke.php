<?php

$root = dirname( __DIR__, 2 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', $root . '/' );

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

$GLOBALS['smoke'] = array(
	'actions'                => array(),
	'registered_post_types'  => array(),
	'posts'                  => array(
		20 => (object) array( 'ID' => 20, 'post_type' => 'artist_profile' ),
		40 => (object) array( 'ID' => 40, 'post_type' => 'artist_link_page' ),
	),
	'post_meta'              => array(
		40 => array( '_associated_artist_profile_id' => 20 ),
	),
	'post_meta_write_calls'  => 0,
);

function get_option( $name, $default = false ) {
	return 'active_plugins' === $name ? array( 'extrachill-link-pages/extrachill-link-pages.php' ) : $default;
}

function get_site_option( $name, $default = false ) {
	return $default;
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['smoke']['actions'][] = array( $hook, $callback, $priority );
}

function add_filter() {
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function get_current_blog_id() {
	return 4;
}

function get_site( $blog_id ) {
	return 4 === (int) $blog_id ? (object) array( 'blog_id' => 4 ) : null;
}

function switch_to_blog() {
	return true;
}

function restore_current_blog() {
	return true;
}

function get_post_type( $post_id ) {
	return $GLOBALS['smoke']['posts'][ $post_id ]->post_type ?? false;
}

function post_type_exists( $post_type ) {
	return isset( $GLOBALS['smoke']['registered_post_types'][ $post_type ] );
}

function get_post_meta( $post_id, $key, $single = false ) {
	if ( ! array_key_exists( $key, $GLOBALS['smoke']['post_meta'][ $post_id ] ?? array() ) ) {
		return $single ? '' : array();
	}
	$value = $GLOBALS['smoke']['post_meta'][ $post_id ][ $key ];
	return $single ? $value : array( $value );
}

function update_post_meta() {
	++$GLOBALS['smoke']['post_meta_write_calls'];
	return true;
}

function get_posts( $args ) {
	if ( '_associated_artist_profile_id' === ( $args['meta_key'] ?? '' ) && '20' === (string) ( $args['meta_value'] ?? '' ) ) {
		return array( 40 );
	}
	return array();
}

function register_post_type( $post_type, $args ) {
	$GLOBALS['smoke']['registered_post_types'][ $post_type ] = $args;
	return (object) $args;
}

function __( $text ) {
	return $text;
}

function _x( $text ) {
	return $text;
}

// Simulate a standalone generic API loading after Artist Platform's handoff code.
require_once $root . '/inc/link-pages/runtime-handoff.php';
require_once __DIR__ . '/fake-external-link-pages-runtime.php';
register_post_type( 'artist_link_page', array( 'owner' => 'external-runtime' ) );

require_once $root . '/inc/core/artist-platform-post-types.php';
$first  = extrachill_artist_platform_boot_link_pages_runtime();
$second = extrachill_artist_platform_boot_link_pages_runtime();
extrachill_register_artist_profile_cpt();
extrachill_register_artist_link_page_cpt();
$owner = ec_get_link_page_owner( 40 );

echo json_encode(
	array(
		'booted'                => true === $first && true === $second,
		'post_type_constant'     => EC_LINK_PAGE_POST_TYPE,
		'owner_meta_constant'    => EC_LINK_PAGE_OWNER_META_KEY,
		'owner_providers'        => count( $GLOBALS['smoke']['owner_providers'] ),
		'operation_providers'    => count( $GLOBALS['smoke']['operation_providers'] ),
		'projection_providers'   => count( $GLOBALS['smoke']['projection_providers'] ),
		'link_page_cpt_owner'    => $GLOBALS['smoke']['registered_post_types']['artist_link_page']['owner'] ?? '',
		'artist_profile_exists'  => isset( $GLOBALS['smoke']['registered_post_types']['artist_profile'] ),
		'legacy_owner_reference'=> is_wp_error( $owner ) ? $owner->get_error_code() : $owner['reference'],
		'write_calls'            => $GLOBALS['smoke']['post_meta_write_calls'],
	)
);
