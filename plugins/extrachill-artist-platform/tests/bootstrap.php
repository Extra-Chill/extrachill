<?php

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
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

$GLOBALS['ec_test'] = array(
	'current_blog_id' => 4,
	'blog_stack'      => array(),
);

function ec_test_blog_store( $type ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'] ?? 4;
	return $GLOBALS['ec_test']['blogs'][ $blog_id ][ $type ] ?? array();
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['ec_test']['blog_stack'][] = $GLOBALS['ec_test']['current_blog_id'] ?? 4;
	$GLOBALS['ec_test']['current_blog_id'] = (int) $blog_id;
	return true;
}

function restore_current_blog() {
	if ( ! empty( $GLOBALS['ec_test']['blog_stack'] ) ) {
		$GLOBALS['ec_test']['current_blog_id'] = array_pop( $GLOBALS['ec_test']['blog_stack'] );
	}
	return true;
}

function ec_get_blog_id( $site ) {
	return array( 'main' => 1, 'artist' => 4 )[ $site ] ?? 0;
}

function get_current_blog_id() {
	return $GLOBALS['ec_test']['current_blog_id'] ?? 4;
}

function add_action() {
	return true;
}

function add_filter() {
	return true;
}

function remove_filter() {
	return true;
}

function __return_true() {
	return true;
}

class EcTestRegisteredAbility {
	private $args;

	public function __construct( $args ) {
		$this->args = $args;
	}

	public function check_permissions( $input ) {
		return call_user_func( $this->args['permission_callback'], $input );
	}

	public function get_meta() {
		return $this->args['meta'];
	}
}

function wp_register_ability( $name, $args ) {
	$GLOBALS['ec_test']['abilities'][ $name ] = new EcTestRegisteredAbility( $args );
}

function wp_get_ability( $name ) {
	return $GLOBALS['ec_test']['abilities'][ $name ] ?? null;
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_current_user_id() {
	return $GLOBALS['ec_test']['current_user_id'] ?? 0;
}

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['ec_test']['capabilities'][ $capability ] );
}

function did_action( $hook_name ) {
	return $GLOBALS['ec_test']['actions'][ $hook_name ] ?? 0;
}

function ec_can_manage_artist( $user_id, $artist_id ) {
	if ( ! empty( $GLOBALS['ec_test']['capabilities']['manage_options'] ) ) {
		return true;
	}

	return in_array( (int) $artist_id, $GLOBALS['ec_test']['managed_artists'][ (int) $user_id ] ?? array(), true );
}

function sanitize_text_field( $value ) {
	return trim( $value );
}

function get_post_type( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_type ?? '';
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$blog_meta = ec_test_blog_store( 'post_meta' );
	$meta      = $blog_meta[ $post_id ] ?? ( $GLOBALS['ec_test']['meta'][ $post_id ] ?? array() );
	if ( $key === '' ) {
		return $meta;
	}
	$value = $meta[ $key ] ?? ( $single ? '' : array() );
	return $single && is_array( $value ) ? ( $value[0] ?? '' ) : $value;
}

function maybe_unserialize( $value ) {
	return $value;
}

function get_post_thumbnail_id( $post_id ) {
	return $GLOBALS['ec_test']['thumbnails'][ $post_id ] ?? 0;
}

function get_the_title( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_title ?? '';
}

function get_post_field( $field, $post_id ) {
	$post = get_post( $post_id );
	return $post->{$field} ?? '';
}

function get_permalink( $post_id ) {
	return 'https://artist.example/artists/' . get_post_field( 'post_name', $post_id ) . '/';
}

function get_post( $post_id ) {
	$blog_posts = ec_test_blog_store( 'posts' );
	return $blog_posts[ $post_id ] ?? ( $GLOBALS['ec_test']['posts'][ $post_id ] ?? null );
}

function update_post_meta( $post_id, $key, $value ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key, $value = '' ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$current = $GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] ?? null;
	if ( '' === $value || (string) $current === (string) $value ) {
		unset( $GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] );
		return true;
	}
	return false;
}

function get_term( $term_id, $taxonomy = '' ) {
	$terms = ec_test_blog_store( 'terms' );
	$term  = $terms[ $term_id ] ?? null;
	if ( $term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ) {
		return $term;
	}
	return null;
}

function get_term_by( $field, $value, $taxonomy ) {
	foreach ( ec_test_blog_store( 'terms' ) as $term ) {
		if ( $term->taxonomy === $taxonomy && isset( $term->{$field} ) && $term->{$field} === $value ) {
			return $term;
		}
	}
	return false;
}

function get_term_meta( $term_id, $key, $single = false ) {
	$meta  = ec_test_blog_store( 'term_meta' );
	$value = $meta[ $term_id ][ $key ] ?? ( $single ? '' : array() );
	return $value;
}

function update_term_meta( $term_id, $key, $value ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['term_meta'][ $term_id ][ $key ] = $value;
	return true;
}

function delete_term_meta( $term_id, $key, $value = '' ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$current = $GLOBALS['ec_test']['blogs'][ $blog_id ]['term_meta'][ $term_id ][ $key ] ?? null;
	if ( '' === $value || (string) $current === (string) $value ) {
		unset( $GLOBALS['ec_test']['blogs'][ $blog_id ]['term_meta'][ $term_id ][ $key ] );
		return true;
	}
	return false;
}

function get_posts( $args ) {
	$ids = array();
	foreach ( ec_test_blog_store( 'posts' ) as $post_id => $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}
		if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
			continue;
		}
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] && $post->post_status !== $args['post_status'] ) {
			continue;
		}
		$ids[] = (int) $post_id;
	}
	return $ids;
}

function wp_insert_term( $title, $taxonomy, $args = array() ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$term_id = empty( $GLOBALS['ec_test']['blogs'][ $blog_id ]['terms'] ) ? 1 : max( array_keys( $GLOBALS['ec_test']['blogs'][ $blog_id ]['terms'] ) ) + 1;
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['terms'][ $term_id ] = (object) array(
		'term_id'  => $term_id,
		'taxonomy' => $taxonomy,
		'slug'     => $args['slug'] ?? $title,
	);
	return array( 'term_id' => $term_id );
}

function get_option( $key, $default = false ) {
	return $GLOBALS['ec_test']['options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['ec_test']['options'][ $key ] = $value;
	return true;
}

function ec_cross_site_rest_request( $site, $method, $route, $args = array() ) {
	$GLOBALS['ec_test']['cross_site_requests'][] = array( $site, $method, $route, $args );
	return $GLOBALS['ec_test']['cross_site_result'] ?? new WP_Error( 'missing_result', 'No test result configured.' );
}

function get_site_url( $blog_id ) {
	return 'https://site-' . (int) $blog_id . '.example';
}

function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}

function wp_get_attachment_url( $attachment_id ) {
	return 'https://artist.example/media/' . $attachment_id . '.jpg';
}

function get_the_post_thumbnail_url( $post_id, $size ) {
	$attachment_id = get_post_thumbnail_id( $post_id );
	return $attachment_id ? wp_get_attachment_url( $attachment_id ) : false;
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

function wp_unslash( $value ) {
	if ( is_string( $value ) ) {
		return stripslashes( $value );
	}

	return $value;
}

function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}

	if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
		return $color;
	}

	return null;
}

require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-list-artist-relationships.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-link-artist-relationship.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-unlink-artist-relationship.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-list-orphan-artist-relationships.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/admin-cleanup-artist-relationships.php';
require_once dirname( __DIR__ ) . '/inc/core/filters/data.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/artist-get.php';
require_once dirname( __DIR__ ) . '/inc/abilities/helpers.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-term-binding.php';
require_once dirname( __DIR__ ) . '/inc/artist-profiles/frontend/shows-section.php';
require_once dirname( __DIR__ ) . '/inc/abilities/registry.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/update-artist.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/create-artist.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/save-link-page-links.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/save-social-links.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/artist-export-subscribers.php';
