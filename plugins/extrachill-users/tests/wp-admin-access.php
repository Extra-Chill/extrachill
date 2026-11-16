<?php
/** Standalone policy tests: php tests/wp-admin-access.php */
// phpcs:disable -- Test doubles and WordPress stubs.
define( 'ABSPATH', __DIR__ );
$GLOBALS['site']   = 2;
$GLOBALS['stack']  = array();
$GLOBALS['caps']   = array();
$GLOBALS['uid']    = 1;
$GLOBALS['object'] = null;
$GLOBALS['admin']  = true;
$GLOBALS['ajax']   = false;
class WP_User {
	public $ID;
	public $caps;
	public function __construct( $id ) { $this->ID = $id;
		$this->caps                                = $GLOBALS['caps'][ $GLOBALS['site'] ][ $id ] ?? array(); }
	public function exists() { return $this->ID > 0 && $this->ID <= 5; }
	public function add_cap( $cap ) { $GLOBALS['caps'][ $GLOBALS['site'] ][ $this->ID ][ $cap ] = true; }
	public function remove_cap( $cap ) { unset( $GLOBALS['caps'][ $GLOBALS['site'] ][ $this->ID ][ $cap ] ); }
}
class WP_Post {
	public $ID        = 10;
	public $post_type = 'post';
}
class WP_Admin_Bar {
	public $nodes = array();
	public function add_node( $node ) { $this->nodes[ $node['id'] ] = (object) $node; }
	public function get_node( $id ) { return $this->nodes[ $id ] ?? false; }
	public function remove_node( $id ) { unset( $this->nodes[ $id ] ); }
	public function get_nodes() { return $this->nodes; }
}
class AdminRedirect extends RuntimeException {}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function __( $text, $domain ) { return $text; }
function ec_get_blog_id( $name ) { return array(
	'main'   => 1,
	'studio' => 42,
)[ $name ]; }
function ec_get_site_url( $name ) { return 'https://' . $name . '.test'; }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }
function get_home_url( $id, $path ) { return 'https://site' . $id . '.test' . $path; }
function home_url( $path ) { return 'https://local.test' . $path; }
function get_current_blog_id() { return $GLOBALS['site']; }
function get_current_user_id() { return $GLOBALS['uid']; }
function wp_get_current_user() { return new WP_User( get_current_user_id() ); }
function ec_is_team_member( $id ) { return in_array( $id, array( 3, 4 ), true ); }
function user_can( $user, $cap ) { return ( 1 === $user->ID && 'manage_options' === $cap ) || ( 3 === $user->ID && in_array( $cap, array( 'edit_post', 'edit_others_posts' ), true ) ); }
function current_user_can( $cap, ...$args ) { return 'edit_user' === $cap ? is_super_admin() : user_can( wp_get_current_user(), $cap ); }
function is_network_admin() { return $GLOBALS['network_admin'] ?? false; }
function is_super_admin() { return $GLOBALS['super_admin'] ?? false; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === $action; }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['site'];
	$GLOBALS['site']                                 = $id; }
function restore_current_blog() { $GLOBALS['site'] = array_pop( $GLOBALS['stack'] ); }
function ec_get_all_site_ids() { return array( 1, 2, 42 ); }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function show_admin_bar( $show ) { $GLOBALS['bar'] = $show; }
function wp_redirect( $url ) { throw new AdminRedirect( $url ); }
function get_queried_object() { return $GLOBALS['object']; }
require dirname( __DIR__ ) . '/inc/team-members/role.php';
require dirname( __DIR__ ) . '/inc/wp-admin-permissions.php';
require dirname( __DIR__ ) . '/inc/core/email-change-confirmation.php';
require dirname( __DIR__ ) . '/inc/admin-access-control.php';
$failures = 0;
function check( $label, $condition ) {
	global $failures;
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $label . "\n";
	$failures += $condition ? 0 : 1;
}
check( 'grant network-wide', array( 1, 2, 42 ) === ec_grant_wp_admin( 2 ) );
check( 'grant idempotent', array() === ec_grant_wp_admin( 2 ) );
check( 'explicit grant readable from any site', ec_user_has_wp_admin_grant( 2 ) && 2 === $GLOBALS['site'] );
$pagenow = 'index.php';
foreach ( array(
	1 => true,
	2 => true,
	3 => false,
	4 => false,
	5 => false,
) as $uid => $allowed ) {
	$GLOBALS['uid'] = $uid;
	check( "user $uid wp-admin policy", $allowed === ec_users_can_access_wp_admin() );
	$GLOBALS['bar'] = true;
	extrachill_hide_admin_bar_for_non_admins();
	check( "user $uid bar visibility", ( $allowed || ec_is_team_member( $uid ) ) === $GLOBALS['bar'] );
	$bar = new WP_Admin_Bar();
	foreach ( array( 'dashboard', 'edit', 'new-content', 'updates', 'comments', 'site-name', 'my-account', 'user-info', 'edit-profile', 'plugin-node' ) as $id ) {
		$bar->add_node( array(
			'id'   => $id,
			'href' => 'https://local.test/wp-admin/profile.php',
		) );
	}
	$bar->add_node( array(
		'id'   => 'logout',
		'href' => 'https://local.test/wp-login.php?action=logout',
	) );
	$before = $bar->nodes;
	ec_users_trim_team_admin_bar( $bar );
	if ( $allowed ) {
		check( "user $uid full bar untouched", $before === $bar->nodes );
	} elseif ( ec_is_team_member( $uid ) ) {
		check( "user $uid Studio/account/logout retained", $bar->get_node( 'studio' ) && $bar->get_node( 'logout' ) && 'https://community.test/settings' === $bar->get_node( 'my-account' )->href );
		check( "user $uid no wp-admin hrefs", ! array_filter( $bar->nodes, static function ( $node ) { return false !== strpos( $node->href, '/wp-admin' );
		} ) );
	}
	$destination = ec_is_team_member( $uid ) ? get_home_url( 42, '/' ) : home_url( '/' );
	$redirect    = null;
	try {
		extrachill_redirect_admin();
	} catch ( AdminRedirect $e ) {
		$redirect = $e->getMessage(); }
	check( "user $uid backend redirect", $allowed ? null === $redirect : $destination === $redirect );
	check( "user $uid login redirect", ( $allowed ? '/wp-admin/' : $destination ) === extrachill_prevent_admin_auth_redirect( '/wp-admin/', '', new WP_User( $uid ) ) );
	check( "user $uid front-end continuation", '/events/' === extrachill_prevent_admin_auth_redirect( '/events/', '/events/', new WP_User( $uid ) ) );
}
$GLOBALS['uid']    = 3;
$GLOBALS['site']   = 1;
$GLOBALS['object'] = new WP_Post();
$bar               = new WP_Admin_Bar();
ec_users_trim_team_admin_bar( $bar );
check( 'editable main post links to Studio', get_home_url( 42, '/' ) === $bar->get_node( 'edit' )->href );
$GLOBALS['uid']  = 4;
$GLOBALS['ajax'] = true;
extrachill_redirect_admin();
$GLOBALS['ajax'] = false;
$pagenow         = 'admin-post.php';
extrachill_redirect_admin();
check( 'AJAX and admin-post remain accessible', true );
check( 'revoke network-wide', array( 1, 2, 42 ) === ec_revoke_wp_admin( 2 ) );
check( 'revoke idempotent', array() === ec_revoke_wp_admin( 2 ) );
check( 'grant/revoke round trip denies access', ! ec_users_can_access_wp_admin( new WP_User( 2 ) ) );
check( 'blog switches balanced', 1 === $GLOBALS['site'] && array() === $GLOBALS['stack'] );
check( 'team role has no implicit admin caps', ! isset( ec_users_get_team_role_caps()['access_wp_admin'] ) && ! isset( ec_users_get_team_role_caps()['access_admin_bar'] ) );
$_POST                    = array(
	'ec_wp_admin_grant_nonce' => 'ec_wp_admin_grant_2',
	'ec_wp_admin_grant'       => '1',
);
$GLOBALS['network_admin'] = true;
ec_users_save_wp_admin_profile_field( 2 );
check( 'non-super-admin cannot save grant', ! ec_user_has_wp_admin_grant( 2 ) );
$GLOBALS['super_admin']           = true;
$_POST['ec_wp_admin_grant_nonce'] = 'invalid';
ec_users_save_wp_admin_profile_field( 2 );
check( 'invalid nonce cannot save grant', ! ec_user_has_wp_admin_grant( 2 ) );
$_POST['ec_wp_admin_grant_nonce'] = 'ec_wp_admin_grant_2';
ec_users_save_wp_admin_profile_field( 2 );
check( 'super-admin network form grants', ec_user_has_wp_admin_grant( 2 ) );
unset( $_POST['ec_wp_admin_grant'] );
ec_users_save_wp_admin_profile_field( 2 );
check( 'unchecked network form revokes', ! ec_user_has_wp_admin_grant( 2 ) );
exit( $failures ? 1 : 0 );
