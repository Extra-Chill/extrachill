<?php // phpcs:disable -- Test doubles and WordPress stubs.
namespace ExtraChill\Users\Teams {
	class TeamMembershipRepository {
		const TYPES = array( 'artist', 'venue', 'promoter' );
		const STATUS_ACTIVE = 'active';
		public function get( $type, $id, $user ) { return $GLOBALS['members'][ "$type:$id:$user" ] ?? null; }
		public function term_ids( $type, $criteria ) {
			$ids = array();
			foreach ( $GLOBALS['members'] as $key => $row ) {
				list( $t, $id, $user ) = explode( ':', $key );
				if ( $type === $t && $criteria['user_id'] === (int) $user && $criteria['status'] === $row['status'] ) { $ids[] = (int) $id; }
			}
			return $ids;
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ );
	class WP_Error { public function __construct( public $code, public $message = '', public $data = array() ) {} }
	class WP_Post { public $ID; public $post_name; public $post_title; public $post_type; public $post_status; public $post_author; }
	$GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['members'] = $GLOBALS['scans'] = $GLOBALS['abilities'] = array();
	$GLOBALS['user'] = 1;
	$GLOBALS['blog'] = 1;
	$GLOBALS['stack'] = array();
	function add_action( ...$args ) {}
	function add_filter( ...$args ) {}
	function get_current_user_id() { return $GLOBALS['user']; }
	function is_super_admin() { return 99 === get_current_user_id(); }
	function ec_get_blog_id( $name ) { return 42; }
	function ec_get_site_url( $name ) { return 'https://example.link/'; }
	function get_current_blog_id() { return $GLOBALS['blog']; }
	function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
	function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
	function register_post_type( ...$args ) { check( 42 === get_current_blog_id(), 'CPT storage site' ); }
	function wp_parse_url( $url, $part ) { return parse_url( $url, $part ); }
	function trailingslashit( $url ) { return rtrim( $url, '/' ) . '/'; }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function esc_url_raw( $url ) { return $url; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
	function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
	function wp_insert_post( $data, $error ) {
		check( 42 === get_current_blog_id(), 'write on link site only' );
		$id = count( $GLOBALS['posts'] ) + 1;
		$post = new WP_Post();
		$post->ID = $id;
		foreach ( $data as $key => $value ) { if ( 'meta_input' !== $key ) { $post->$key = $value; } }
		$GLOBALS['posts'][ $id ] = $post;
		$GLOBALS['meta'][ $id ] = $data['meta_input'];
		return $id;
	}
	function wp_update_post( $data, $error ) { $GLOBALS['posts'][ $data['ID'] ]->post_title = $data['post_title']; return $data['ID']; }
	function wp_trash_post( $id ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; return true; }
	function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
	function get_posts( $args ) {
		check( 42 === get_current_blog_id(), 'read on link site only' );
		return array_values( array_filter( $GLOBALS['posts'], function ( $post ) use ( $args ) {
			if ( $args['post_type'] !== $post->post_type || $args['post_status'] !== $post->post_status ) { return false; }
			if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) { return false; }
			foreach ( $args['meta_query'] ?? array() as $condition ) { if ( (string) get_post_meta( $post->ID, $condition['key'], true ) !== (string) $condition['value'] ) { return false; } }
			return true;
		} ) );
	}
	function ec_with_link_page_lock_scope( $team, $callback ) { return $callback(); }
	function ec_with_link_page_storage_blog( $callback ) { switch_to_blog( 42 ); try { return $callback(); } finally { restore_current_blog(); } }
	function nocache_headers() { $GLOBALS['no_cache'] = true; }
	function extrachill_analytics_handle_link_click_db_write( $id, $url, $text ) { $GLOBALS['scans'][] = array( $id, $url, $text ); }
	function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; }
	function check( $ok, $message ) { if ( ! $ok ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
	require dirname( __DIR__ ) . '/inc/managed-qr/codes.php';
	$GLOBALS['members']['artist:7:1'] = array( 'status' => 'active' );
	$GLOBALS['members']['artist:7:2'] = array( 'status' => 'active' );
	$GLOBALS['members']['venue:8:3'] = array( 'status' => 'active' );
	$input = array( 'team_type' => 'artist', 'team_id' => 7, 'label' => 'Merch table', 'destination' => 'https://band.example/tickets' );
	$created = ec_managed_qr_execute( 'create', $input );
	check( ! is_wp_error( $created ), 'create' );
	$id = $created['id'];
	$code = $created['code'];
	check( 1 === get_current_blog_id(), 'restore create context' );
	$route = ec_managed_qr_public_route( null, 'q/' . $code );
	check( 302 === $route['status'] && $input['destination'] === $route['url'], 'redirect resolves' );
	check( 1 === count( $GLOBALS['scans'] ) && $GLOBALS['scans'][0][1] === $created['short_url'], 'one scan recorded with stable URL' );
	check( ! empty( $GLOBALS['no_cache'] ), 'no cached redirects' );
	$GLOBALS['user'] = 2;
	$updated = ec_managed_qr_execute( 'update', array( 'id' => $id, 'destination' => 'https://band.example/presave', 'label' => 'Stage screen' ) );
	check( ! is_wp_error( $updated ) && $code === $updated['code'] && 'Stage screen' === $updated['label'], 'same-team member edits, immutable code' );
	check( 'https://band.example/presave' === ec_managed_qr_public_route( null, 'q/' . $code )['url'], 'redirect changes after edit' );
	check( 1 === count( ec_managed_qr_execute( 'list', array() )['items'] ), 'list owning team' );
	foreach ( array( 3, 2, 0 ) as $user ) {
		$GLOBALS['user'] = $user;
		$GLOBALS['members']['artist:7:2']['status'] = 'revoked';
		foreach ( array( 'update', 'delete', 'get-stats' ) as $operation ) {
			check( is_wp_error( ec_managed_qr_execute( $operation, array( 'id' => $id, 'destination' => 'https://evil.example' ) ) ), 'other/revoked/anonymous denied ' . $operation );
		}
		if ( $user ) { check( array() === ec_managed_qr_execute( 'list', array() )['items'], 'unauthorized list empty' ); }
		check( 1 === get_current_blog_id(), 'restore denied context' );
	}
	$GLOBALS['user'] = 1;
	foreach ( array( 'javascript:alert(1)', '//example.com', 'ftp://example.com', 'not a url', 'https://' ) as $url ) {
		check( is_wp_error( ec_managed_qr_execute( 'create', array_merge( $input, array( 'destination' => $url ) ) ) ), 'invalid URL rejected' );
		check( is_wp_error( ec_managed_qr_execute( 'update', array( 'id' => $id, 'destination' => $url ) ) ), 'invalid update URL rejected' );
	}
	check( ec_managed_qr_valid_url( 'http://example.com' ), 'HTTP accepted' );
	for ( $i = 1; $i < 100; ++$i ) { check( ! is_wp_error( ec_managed_qr_execute( 'create', $input ) ), 'create up to quota' ); }
	check( 'qr_quota' === ec_managed_qr_execute( 'create', $input )->code, 'per-team quota enforced' );
	$GLOBALS['user'] = 3;
	check( ! is_wp_error( ec_managed_qr_execute( 'create', array_merge( $input, array( 'team_type' => 'venue', 'team_id' => 8 ) ) ) ), 'quota independent across teams' );
	$GLOBALS['user'] = 1;
	check( ec_managed_qr_execute( 'delete', array( 'id' => $id ) )['deleted'], 'owner team trashes' );
	$before = count( $GLOBALS['scans'] );
	foreach ( array( $code, 'unknown' ) as $missing ) {
		check( ec_get_site_url( 'link_pages' ) === ec_managed_qr_public_route( null, 'q/' . $missing )['url'], 'missing/trashed friendly fallback' );
	}
	check( $before === count( $GLOBALS['scans'] ), 'missing codes do not count' );
	check( null === ec_managed_qr_public_route( null, 'some-profile' ), 'other routes untouched' );
	ec_register_managed_qr_abilities();
	check( 5 === count( $GLOBALS['abilities'] ), 'five core abilities registered' );
	foreach ( $GLOBALS['abilities'] as $ability ) { check( $ability['meta']['show_in_rest'] && 'is_user_logged_in' === $ability['permission_callback'], 'authenticated core transport' ); }
	check( 1 === get_current_blog_id() && array() === $GLOBALS['stack'], 'all context restored' );
	echo "Managed QR smoke: passed\n";
}
