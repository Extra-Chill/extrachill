<?php
/**
 * Artist Dispatch integration checks against real WordPress REST controllers.
 *
 * Run only in a disposable WordPress sandbox with this plugin active:
 *   wp eval-file wp-content/plugins/extrachill-blog/tests/integration/artist-dispatch-rest.php
 *
 * The sandbox must not load Extra Chill Users or Artist Platform; this file
 * registers exact test doubles for their public ability/relationship contracts.
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $message ) { print $message . "\n"; }
		public static function success( $message ) { print 'SUCCESS: ' . $message . "\n"; }
		public static function error( $message ) { throw new RuntimeException( $message ); }
	}
}

if ( ! function_exists( 'extrachill_blog_dispatch_access' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$activated = activate_plugin( 'extrachill-blog/extrachill-blog.php' );
	if ( is_wp_error( $activated ) ) {
		WP_CLI::error( $activated->get_error_message() );
	}
}

$ec_dispatch_failures      = 0;
$ec_dispatch_access_states = array();
$ec_dispatch_artists       = array();
$ec_dispatch_created       = array();

function ec_dispatch_integration_check( $label, $condition ) {
	global $ec_dispatch_failures;
	WP_CLI::log( ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label );
	if ( ! $condition ) {
		++$ec_dispatch_failures;
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $key ) {
		return 'artist' === $key || 'main' === $key ? get_current_blog_id() : 0;
	}
}
if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
	function ec_get_artists_for_user( $user_id ) {
		global $ec_dispatch_artists;
		return isset( $ec_dispatch_artists[ $user_id ] ) ? $ec_dispatch_artists[ $user_id ] : array();
	}
}

add_filter(
	'extrachill_blog_artist_dispatch_access_state',
	function() use ( &$ec_dispatch_access_states ) {
		return isset( $ec_dispatch_access_states[ get_current_user_id() ] ) ? $ec_dispatch_access_states[ get_current_user_id() ] : array();
	}
);

register_post_type( 'artist_profile', array( 'public' => true, 'show_in_rest' => true ) );
add_role(
	EXTRACHILL_BLOG_DISPATCH_ROLE,
	'Artist Dispatch Contributor',
	array(
		'read'              => true,
		'edit_posts'        => true,
		'delete_posts'      => true,
		'submit_for_review' => true,
	)
);

$editor_id = wp_create_user( 'dispatch_editor_' . wp_generate_password( 6, false ), wp_generate_password(), 'dispatch-editor@example.test' );
$one_id    = wp_create_user( 'dispatch_one_' . wp_generate_password( 6, false ), wp_generate_password(), 'dispatch-one@example.test' );
$two_id    = wp_create_user( 'dispatch_two_' . wp_generate_password( 6, false ), wp_generate_password(), 'dispatch-two@example.test' );
$author_id = wp_create_user( 'dispatch_author_' . wp_generate_password( 6, false ), wp_generate_password(), 'dispatch-author@example.test' );
$ec_dispatch_created = array( $editor_id, $one_id, $two_id, $author_id );
( new WP_User( $editor_id ) )->set_role( 'editor' );
( new WP_User( $one_id ) )->set_role( EXTRACHILL_BLOG_DISPATCH_ROLE );
( new WP_User( $two_id ) )->set_role( EXTRACHILL_BLOG_DISPATCH_ROLE );
( new WP_User( $author_id ) )->set_role( 'author' );
( new WP_User( $author_id ) )->add_role( EXTRACHILL_BLOG_DISPATCH_ROLE );

wp_set_current_user( $editor_id );
$artist_id = wp_insert_post(
	array(
		'post_type'   => 'artist_profile',
		'post_status' => 'publish',
		'post_title'  => 'Integration Artist',
	),
	true
);
$ec_dispatch_artists[ $one_id ] = array( $artist_id );
$ec_dispatch_artists[ $two_id ] = array( $artist_id );
$approved = function( $artist ) {
	return array(
		'status'             => 'approved',
		'artist_id'          => $artist,
		'terms_acknowledged' => true,
		'terms_version'      => EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION,
		'eligibility'        => array(
			'eligible' => true,
			'policy'   => array( 'pilot_enabled' => true ),
			'criteria' => array( 'claimed_artist' => array( 'passed' => true, 'artist_ids' => array( $artist ) ) ),
			'reasons'  => array(),
		),
	);
};
$ec_dispatch_access_states[ $one_id ] = $approved( $artist_id );
$ec_dispatch_access_states[ $two_id ] = $approved( $artist_id );

$valid_content = '<!-- wp:paragraph --><p>Integration content.</p><!-- /wp:paragraph -->';
$create = function( $user_id ) use ( $valid_content ) {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	$request->set_header( EXTRACHILL_BLOG_DISPATCH_CREATE_HEADER, 'create' );
	$request->set_body_params( array( 'title' => '', 'content' => '', 'status' => 'draft' ) );
	return rest_do_request( $request );
};

wp_set_current_user( $one_id );
$unmarked_create = new WP_REST_Request( 'POST', '/wp/v2/posts' );
$unmarked_create->set_body_params( array( 'title' => 'Bypass', 'content' => $valid_content, 'status' => 'draft' ) );
ec_dispatch_integration_check( 'grant-only user cannot create an unmarked post', 403 === rest_do_request( $unmarked_create )->get_status() );

$created_response = $create( $one_id );
$created_data     = $created_response->get_data();
if ( 201 !== $created_response->get_status() ) {
	WP_CLI::log( 'CREATE DIAGNOSTIC: ' . wp_json_encode( $created_data ) );
}
$dispatch_id      = isset( $created_data['id'] ) ? (int) $created_data['id'] : 0;
ec_dispatch_integration_check( 'validated core REST creation succeeds once', 201 === $created_response->get_status() && $dispatch_id > 0 );
ec_dispatch_integration_check( 'server stamps source and real submitter', EXTRACHILL_BLOG_DISPATCH_SOURCE === get_post_meta( $dispatch_id, EXTRACHILL_BLOG_DISPATCH_SOURCE_META, true ) && $one_id === (int) get_post_meta( $dispatch_id, EXTRACHILL_BLOG_DISPATCH_SUBMITTER_META, true ) );
ec_dispatch_integration_check( 'server copies audited terms rather than client data', EXTRACHILL_BLOG_DISPATCH_TERMS_VERSION === get_post_meta( $dispatch_id, EXTRACHILL_BLOG_DISPATCH_TERMS_META, true ) );

wp_set_current_user( $editor_id );
$unmarked_id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => $one_id, 'post_title' => 'Legacy unmarked' ), true );
wp_set_current_user( $one_id );
$convert = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $unmarked_id );
$convert->set_body_params( array( 'id' => $unmarked_id, 'meta' => array( EXTRACHILL_BLOG_DISPATCH_SOURCE_META => EXTRACHILL_BLOG_DISPATCH_SOURCE ) ) );
ec_dispatch_integration_check( 'unmarked post cannot be converted with provenance', 400 === rest_do_request( $convert )->get_status() );

$autosave = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id . '/autosaves' );
$autosave->set_body_params(
	array(
		'id'      => $dispatch_id,
		'title'   => array( 'raw' => 'Autosaved Dispatch' ),
		'content' => array( 'raw' => $valid_content ),
		'excerpt' => array( 'raw' => '' ),
		'meta'    => array(),
	)
);
$autosave_response = rest_do_request( $autosave );
ec_dispatch_integration_check( 'real core autosaves controller accepts canonical raw envelopes', 201 === $autosave_response->get_status() || 200 === $autosave_response->get_status() );
foreach ( array( 'author' => $two_id, 'status' => 'pending', 'slug' => 'forged', 'featured_media' => 1 ) as $field => $value ) {
	$forbidden_autosave = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id . '/autosaves' );
	$forbidden_autosave->set_body_params( array( 'id' => $dispatch_id, 'content' => array( 'raw' => $valid_content ), $field => $value ) );
	$forbidden_response = rest_do_request( $forbidden_autosave );
	if ( 400 !== $forbidden_response->get_status() ) {
		WP_CLI::log( "AUTOSAVE {$field} DIAGNOSTIC: " . $forbidden_response->get_status() . ' ' . wp_json_encode( $forbidden_response->get_data() ) );
	}
	ec_dispatch_integration_check( "autosave rejects protected {$field} field", 400 === $forbidden_response->get_status() );
}
add_filter( 'extrachill_blog_artist_dispatch_is_autosave_request', '__return_false' );

wp_set_current_user( $two_id );
$foreign_request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $dispatch_id );
$foreign_request->set_query_params( array( 'context' => 'edit' ) );
$foreign_get = rest_do_request( $foreign_request );
ec_dispatch_integration_check( 'second contributor cannot read first contributor draft', in_array( $foreign_get->get_status(), array( 401, 403, 404 ), true ) );

$ec_dispatch_access_states[ $one_id ]['status'] = 'revoked';
wp_set_current_user( $one_id );
$revoked_update = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$revoked_update->set_body_params( array( 'id' => $dispatch_id, 'title' => 'Revoked edit' ) );
ec_dispatch_integration_check( 'revocation blocks normal updates', 403 === rest_do_request( $revoked_update )->get_status() );
ec_dispatch_integration_check( 'revocation blocks autosaves', in_array( rest_do_request( $autosave )->get_status(), array( 401, 403 ), true ) );
$ec_dispatch_access_states[ $one_id ] = $approved( $artist_id );
$ec_dispatch_access_states[ $one_id ]['eligibility']['policy']['pilot_enabled'] = false;
ec_dispatch_integration_check( 'disabled pilot blocks contributor writes', 403 === rest_do_request( $revoked_update )->get_status() );
$ec_dispatch_access_states[ $one_id ] = $approved( $artist_id );
foreach ( array( 'publish', 'future' ) as $forbidden_status ) {
	$contributor_publication = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
	$contributor_publication->set_body_params( array( 'id' => $dispatch_id, 'status' => $forbidden_status ) );
	ec_dispatch_integration_check( "contributor cannot set {$forbidden_status} status", 403 === rest_do_request( $contributor_publication )->get_status() );
}

wp_set_current_user( $editor_id );
wp_update_post( array( 'ID' => $dispatch_id, 'post_content' => '<!-- wp:image {"id":1} /-->' ) );
wp_set_current_user( $one_id );
$status_only = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$status_only->set_body_params( array( 'id' => $dispatch_id, 'status' => 'pending' ) );
ec_dispatch_integration_check( 'status-only pending transition validates stored effective content', 400 === rest_do_request( $status_only )->get_status() );
wp_set_current_user( $editor_id );
wp_update_post( array( 'ID' => $dispatch_id, 'post_content' => $valid_content ) );
wp_set_current_user( $one_id );
ec_dispatch_integration_check( 'valid draft transitions to pending through core REST', 200 === rest_do_request( $status_only )->get_status() );
$pending_edit = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$pending_edit->set_body_params( array( 'id' => $dispatch_id, 'title' => 'Too late' ) );
ec_dispatch_integration_check( 'pending Dispatch is locked to contributor edits', 403 === rest_do_request( $pending_edit )->get_status() );

wp_set_current_user( $editor_id );
$schedule_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$schedule_request->set_body_params( array( 'id' => $dispatch_id, 'status' => 'future', 'date_gmt' => gmdate( 'c', time() + HOUR_IN_SECONDS ) ) );
$scheduled = rest_do_request( $schedule_request );
ec_dispatch_integration_check( 'editor owns scheduled publication after pending review', 200 === $scheduled->get_status() && 'future' === get_post_status( $dispatch_id ) );
$return_pending = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$return_pending->set_body_params( array( 'id' => $dispatch_id, 'status' => 'pending', 'date_gmt' => gmdate( 'c' ) ) );
rest_do_request( $return_pending );
$ec_dispatch_artists[ $one_id ] = array();
$publish_request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $dispatch_id );
$publish_request->set_body_params( array( 'id' => $dispatch_id, 'status' => 'publish' ) );
$published = rest_do_request( $publish_request );
ec_dispatch_integration_check( 'editor owns final publication after pending even if relationship later disappears', 200 === $published->get_status() && 'publish' === get_post_status( $dispatch_id ) );
wp_set_current_user( 0 );
$public = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $dispatch_id ) );
$public_meta = isset( $public->get_data()['meta'] ) ? $public->get_data()['meta'] : array();
ec_dispatch_integration_check( 'anonymous public REST response omits all provenance', 200 === $public->get_status() && ! array_intersect( extrachill_blog_dispatch_provenance_keys(), array_keys( $public_meta ) ) );

wp_set_current_user( $editor_id );
$fallback = extrachill_blog_dispatch_disclosure_html( $dispatch_id );
ec_dispatch_integration_check( 'published disclosure survives removed relationship with safe fallback', false !== strpos( $fallback, 'Artist Dispatch' ) && false !== strpos( $fallback, 'artist link is no longer available' ) );

foreach ( array_keys( (array) get_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION, array() ) ) as $path ) {
	$page = get_page_by_path( $path, OBJECT, 'page' );
	if ( $page ) {
		wp_delete_post( $page->ID, true );
	}
}
delete_option( EXTRACHILL_BLOG_DISPATCH_PAGES_OPTION );
$human_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'submit', 'post_title' => 'Human Submit', 'post_content' => 'Human content' ), true );
ec_dispatch_integration_check( 'existing human page is never claimed or overwritten', false === extrachill_blog_provision_submit_pages() && 'Human content' === get_post_field( 'post_content', $human_page ) );
wp_delete_post( $human_page, true );
$fail_guidelines = function( $empty, $postarr ) {
	return isset( $postarr['post_name'] ) && 'guidelines' === $postarr['post_name'] ? true : $empty;
};
add_filter( 'wp_insert_post_empty_content', $fail_guidelines, 100, 2 );
ec_dispatch_integration_check( 'partial hierarchy failure stops provisioning', false === extrachill_blog_provision_submit_pages() );
remove_filter( 'wp_insert_post_empty_content', $fail_guidelines, 100 );
ec_dispatch_integration_check( 'failed hierarchy can retry to exact completion', true === extrachill_blog_provision_submit_pages() );

$first_lock = extrachill_blog_dispatch_acquire_lock( 'race', $one_id );
$expired_lock = $first_lock;
$expired_lock['expires'] = time() - 1;
update_option( $first_lock['key'], $expired_lock, false );
$successor_lock = extrachill_blog_dispatch_acquire_lock( 'race', $one_id );
extrachill_blog_dispatch_release_lock( $first_lock );
ec_dispatch_integration_check( 'expired lock takeover receives a distinct owner token', is_array( $successor_lock ) && $successor_lock['token'] !== $first_lock['token'] );
ec_dispatch_integration_check( 'stale owner release cannot delete successor lock', is_wp_error( extrachill_blog_dispatch_acquire_lock( 'race', $one_id ) ) );
extrachill_blog_dispatch_release_lock( $successor_lock );

wp_set_current_user( $editor_id );
foreach ( array( $dispatch_id, $unmarked_id ) as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $ec_dispatch_created as $user_id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $user_id );
}

if ( $ec_dispatch_failures ) {
	WP_CLI::error( $ec_dispatch_failures . ' Artist Dispatch integration check(s) failed.' );
}
WP_CLI::success( 'All Artist Dispatch core REST integration checks passed.' );
