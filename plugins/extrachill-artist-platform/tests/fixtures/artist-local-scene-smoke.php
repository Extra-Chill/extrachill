<?php
/** Isolated contract smoke: php tests/fixtures/artist-local-scene-smoke.php */
define( 'ABSPATH', __DIR__ );
class WP_Error {
	public function __construct( public $code, public $message ) {}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $s, $domain = '' ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_title( $s ) { return strtolower( trim( $s ) ); }
function sanitize_text_field( $s ) { return trim( $s ); }
function ec_get_blog_id( $site ) { return 4; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
function get_post_type( $id ) { return 'artist_profile'; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); }
function ec_can_manage_artist( $user, $id ) { return 9 === $user; }
function extrachill_users_resolve_local_scene( $slug ) {
	return in_array( $slug, array( 'charleston', 'austin' ), true ) ? array( 'slug' => $slug ) : new WP_Error( 'location_not_found', 'Unknown scene' );
}
function extrachill_users_get_local_scene( $id ) { return array( 'slug' => 'charleston' ); }
function extrachill_users_resolve_legacy_local_city( $label ) {
	return 'Charleston, SC' === $label ? array( 'status' => 'matched', 'location' => array( 'slug' => 'charleston' ) ) : array( 'status' => 'unmatched' );
}
function get_posts( $args ) { return array_keys( $GLOBALS['meta'] ); }
class WP_Query {
	public $found_posts;
	public $max_num_pages = 1;
	public function __construct( $args ) {
		$GLOBALS['query'] = $args;
		$this->found_posts = count( array_filter( $GLOBALS['meta'], static function ( $meta ) use ( $args ) {
			return ! isset( $args['meta_query'] ) || ( $meta[ $args['meta_query'][0]['key'] ] ?? '' ) === $args['meta_query'][0]['value'];
		} ) );
	}
	public function have_posts() { return false; }
}
function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require __DIR__ . '/../../inc/local-support/availability.php';
require __DIR__ . '/../../inc/abilities/handlers/artists-list.php';
$GLOBALS['blog'] = 1;
$GLOBALS['meta'] = array( 1 => array(), 2 => array(), 3 => array() );
$result = extrachill_artist_platform_set_local_scene( 1, 'Austin' );
check( 'austin' === $result['slug'], 'Canonical slug is saved' );
check( 'austin' === get_post_meta( 1, '_local_support_scene', true ), 'Existing key is the sole shared scene' );
check( is_wp_error( extrachill_artist_platform_set_local_scene( 1, 'unknown' ) ), 'Unknown slugs rejected' );
check( 'austin' === get_post_meta( 1, '_local_support_scene', true ), 'Invalid write leaves scene intact' );
$result = extrachill_artist_platform_update_local_support_availability( 1, true, null, 9 );
check( 'austin' === $result['scene']['slug'], 'Artist scene wins over manager default' );
$result = extrachill_artist_platform_update_local_support_availability( 1, false, null, 9 );
check( ! $result['available'] && 'austin' === $result['scene']['slug'], 'Opt-out preserves hometown' );
$result = extrachill_artist_platform_update_local_support_availability( 2, true, null, 9 );
check( 'charleston' === $result['scene']['slug'], 'Manager scene defaults only when absent' );
$result = extrachill_artist_platform_ability_artists_list( array( 'local_scene' => 'Austin' ) );
check( 1 === $result['total'], 'Scene meta filter matches exactly one artist' );
check( EXTRACHILL_ARTIST_LOCAL_SCENE_META === $GLOBALS['query']['meta_query'][0]['key'], 'List uses shared key' );
check( is_wp_error( extrachill_artist_platform_ability_artists_list( array( 'local_scene' => 'unknown' ) ) ), 'Unknown list scene rejected' );
$GLOBALS['meta'][2] = array( '_local_city' => 'Charleston, SC' );
$GLOBALS['meta'][3] = array( '_local_city' => 'Atlantis' );
$before = $GLOBALS['meta'];
$rows = extrachill_artist_platform_backfill_local_scenes();
check( $before === $GLOBALS['meta'], 'Dry run makes no writes' );
check( 2 === count( $rows ) && 'unmatched' === $rows[1]['status'], 'Existing scenes skipped and unresolved rows listed' );
extrachill_artist_platform_backfill_local_scenes( true );
check( 'charleston' === get_post_meta( 2, EXTRACHILL_ARTIST_LOCAL_SCENE_META, true ), 'Apply writes mapped scene' );
check( 'austin' === get_post_meta( 1, EXTRACHILL_ARTIST_LOCAL_SCENE_META, true ), 'Apply preserves existing scene' );
check( '' === get_post_meta( 3, EXTRACHILL_ARTIST_LOCAL_SCENE_META, true ), 'Unresolved scene remains absent' );
check( 1 === get_current_blog_id(), 'Blog context restored' );
echo "Artist Local Scene contracts passed (save, reject, filter, support, dry-run/apply).\n";
