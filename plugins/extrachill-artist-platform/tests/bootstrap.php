<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );

function plugin_dir_path( $file ) {
	return trailingslashit( dirname( $file ) );
}

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

	public function get_error_data() {
		return $this->data;
	}
}

class EcTestWpdb {
	public function prepare( $query, ...$args ) {
		return array( 'query' => $query, 'args' => $args );
	}

	public function get_var( $prepared ) {
		$query = $prepared['query'];
		$name  = (string) ( $prepared['args'][0] ?? '' );
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			$GLOBALS['ec_test']['db_lock_get_calls'][ $name ] = ( $GLOBALS['ec_test']['db_lock_get_calls'][ $name ] ?? 0 ) + 1;
			$GLOBALS['ec_test']['db_locks'][ $name ] = ( $GLOBALS['ec_test']['db_locks'][ $name ] ?? 0 ) + 1;
			return '1';
		}
		if ( isset( $GLOBALS['ec_test']['db_locks'][ $name ] ) ) {
			--$GLOBALS['ec_test']['db_locks'][ $name ];
			if ( $GLOBALS['ec_test']['db_locks'][ $name ] <= 0 ) {
				unset( $GLOBALS['ec_test']['db_locks'][ $name ] );
			}
		}
		return '1';
	}
}

$GLOBALS['wpdb'] = new EcTestWpdb();

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
	if ( 'artist' === $site && ! empty( $GLOBALS['ec_test']['artist_blog_unavailable'] ) ) {
		return 0;
	}
	return array(
		'main'   => 1,
		'artist' => 4,
		'events' => 7,
	)[ $site ] ?? 0;
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

function sanitize_email( $value ) {
	return strtolower( trim( $value ) );
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) );
}

function is_email( $value ) {
	return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}

function email_exists( $email ) {
	return $GLOBALS['ec_test']['email_users'][ strtolower( $email ) ] ?? false;
}

function wp_generate_password( $length ) {
	return substr( str_repeat( 'a', $length ), 0, $length );
}

function wp_kses_post( $value ) {
	return $value;
}

function get_post_type( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_type ?? '';
}

function get_post_status( $post_id ) {
	$post = get_post( $post_id );
	return $post->post_status ?? false;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$blog_meta = ec_test_blog_store( 'post_meta' );
	$meta      = $blog_meta[ $post_id ] ?? ( $GLOBALS['ec_test']['meta'][ $post_id ] ?? array() );
	if ( $key === '' ) {
		return $meta;
	}
	$value = $meta[ $key ] ?? ( $single ? '' : array() );
	if ( $single && in_array( $key, array( '_artist_member_ids', '_pending_invitations' ), true ) ) {
		return $value;
	}
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

function metadata_exists( $object_type, $object_id, $key ) {
	if ( 'user' === $object_type ) {
		return array_key_exists( $key, $GLOBALS['ec_test']['user_meta'][ $object_id ] ?? array() );
	}
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	return array_key_exists( $key, $GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $object_id ] ?? array() );
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	if ( $unique && metadata_exists( 'post', $post_id, $key ) ) {
		return false;
	}
	if ( ! empty( $GLOBALS['ec_test']['fail_post_meta_add'] ) ) {
		$GLOBALS['ec_test']['fail_post_meta_add'] = false;
		return false;
	}
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function update_post_meta( $post_id, $key, $value, $previous = '' ) {
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$GLOBALS['ec_test']['post_meta_update_calls'] = ( $GLOBALS['ec_test']['post_meta_update_calls'] ?? 0 ) + 1;
	if ( in_array( $GLOBALS['ec_test']['post_meta_update_calls'], $GLOBALS['ec_test']['fail_post_meta_update_on_calls'] ?? array(), true ) ) {
		return false;
	}
	if ( isset( $GLOBALS['ec_test']['fail_post_meta_update_on_call'] ) && $GLOBALS['ec_test']['post_meta_update_calls'] === $GLOBALS['ec_test']['fail_post_meta_update_on_call'] ) {
		return false;
	}
	if ( isset( $GLOBALS['ec_test']['post_meta_conflict'] ) ) {
		$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $GLOBALS['ec_test']['post_meta_conflict'];
		unset( $GLOBALS['ec_test']['post_meta_conflict'] );
		return false;
	}
	if ( ! empty( $GLOBALS['ec_test']['fail_post_meta_update'] ) ) {
		$GLOBALS['ec_test']['fail_post_meta_update'] = false;
		return false;
	}
	if ( func_num_args() >= 4 && maybe_serialize( get_post_meta( $post_id, $key, true ) ) !== maybe_serialize( $previous ) ) {
		return false;
	}
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ][ $key ] = $value;
	if ( isset( $GLOBALS['ec_test']['after_post_meta_update'] ) ) {
		$callback = $GLOBALS['ec_test']['after_post_meta_update'];
		unset( $GLOBALS['ec_test']['after_post_meta_update'] );
		$callback();
	}
	return true;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$meta = $GLOBALS['ec_test']['user_meta'][ $user_id ] ?? array();
	if ( '' === $key ) {
		return $meta;
	}
	$value = $meta[ $key ] ?? ( $single ? '' : array() );
	return $single && is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ? $value[0] : $value;
}

function add_user_meta( $user_id, $key, $value, $unique = false ) {
	if ( $unique && metadata_exists( 'user', $user_id, $key ) ) {
		return false;
	}
	if ( ! empty( $GLOBALS['ec_test']['fail_user_meta_add'] ) ) {
		$GLOBALS['ec_test']['fail_user_meta_add'] = false;
		return false;
	}
	$GLOBALS['ec_test']['user_meta'][ $user_id ][ $key ] = $value;
	if ( isset( $GLOBALS['ec_test']['after_user_meta_update'] ) ) {
		$callback = $GLOBALS['ec_test']['after_user_meta_update'];
		unset( $GLOBALS['ec_test']['after_user_meta_update'] );
		$callback();
	}
	return true;
}

function update_user_meta( $user_id, $key, $value, $previous = '' ) {
	if ( isset( $GLOBALS['ec_test']['user_meta_conflict'] ) ) {
		$GLOBALS['ec_test']['user_meta'][ $user_id ][ $key ] = $GLOBALS['ec_test']['user_meta_conflict'];
		unset( $GLOBALS['ec_test']['user_meta_conflict'] );
		return false;
	}
	if ( ! empty( $GLOBALS['ec_test']['fail_user_meta_update'] ) ) {
		$GLOBALS['ec_test']['fail_user_meta_update'] = false;
		return false;
	}
	if ( func_num_args() >= 4 && maybe_serialize( get_user_meta( $user_id, $key, true ) ) !== maybe_serialize( $previous ) ) {
		return false;
	}
	$GLOBALS['ec_test']['user_meta'][ $user_id ][ $key ] = $value;
	if ( isset( $GLOBALS['ec_test']['after_user_meta_update'] ) ) {
		$callback = $GLOBALS['ec_test']['after_user_meta_update'];
		unset( $GLOBALS['ec_test']['after_user_meta_update'] );
		$callback();
	}
	return true;
}

function maybe_serialize( $value ) {
	return serialize( $value );
}

function wp_insert_post( $post_data, $return_error = false ) {
	if ( ! empty( $GLOBALS['ec_test']['fail_post_insert'] ) ) {
		return $return_error ? new WP_Error( 'insert_failed', 'Insert failed.' ) : 0;
	}
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$post_id = empty( $GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'] ) ? 1 : max( array_keys( $GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'] ) ) + 1;
	$GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $post_id ] = (object) array_merge( array( 'ID' => $post_id ), $post_data );
	return $post_id;
}

function wp_delete_post( $post_id, $force_delete = false ) {
	if ( ! empty( $GLOBALS['ec_test']['fail_post_delete'] ) ) {
		return false;
	}
	$blog_id = $GLOBALS['ec_test']['current_blog_id'];
	$post    = $GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $post_id ] ?? null;
	unset( $GLOBALS['ec_test']['blogs'][ $blog_id ]['posts'][ $post_id ], $GLOBALS['ec_test']['blogs'][ $blog_id ]['post_meta'][ $post_id ] );
	$GLOBALS['ec_test']['deleted_posts'][] = $post_id;
	return $post;
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

function get_site_option( $key, $default = false ) {
	return $GLOBALS['ec_test']['site_options'][ $key ] ?? $default;
}

function update_site_option( $key, $value ) {
	$GLOBALS['ec_test']['site_options'][ $key ] = $value;
	return true;
}

function get_site_transient( $key ) {
	return $GLOBALS['ec_test']['site_transients'][ $key ] ?? false;
}

function set_site_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['ec_test']['site_transients'][ $key ] = $value;
	return true;
}

function is_admin() {
	return true;
}

function delete_site_option( $key ) {
	unset( $GLOBALS['ec_test']['site_options'][ $key ] );
	return true;
}

function get_page_by_path() {
	return null;
}

function get_super_admins() {
	return array( 'admin' );
}

function get_user_by() {
	return (object) array( 'ID' => 1 );
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
	if ( ! empty( $GLOBALS['ec_test']['missing_user'] ) || ! $user_id ) {
		return false;
	}
	return (object) array(
		'ID'           => $user_id,
		'user_login'   => 'user-' . $user_id,
		'user_email'   => $GLOBALS['ec_test']['user_emails'][ $user_id ] ?? 'user-' . $user_id . '@example.com',
		'display_name' => 'User ' . $user_id,
	);
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

require_once dirname( __DIR__ ) . '/inc/artist-profiles/admin/membership.php';
require_once dirname( __DIR__ ) . '/inc/artist-profiles/roster/roster-data-functions.php';
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
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/artist-invitation.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/save-link-page-links.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/save-social-links.php';
require_once dirname( __DIR__ ) . '/inc/abilities/handlers/artist-export-subscribers.php';
require_once dirname( __DIR__ ) . '/inc/core/actions/save.php';
require_once dirname( __DIR__ ) . '/inc/core/platform-artist-provisioning.php';
