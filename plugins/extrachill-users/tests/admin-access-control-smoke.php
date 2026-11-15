<?php
/** Run: php tests/admin-access-control-smoke.php (no WordPress installation). */
define( 'ABSPATH', __DIR__ );
class WP_User {
	public $ID;
	public $site;
	public function __construct( $id ) { $this->ID = $id; $this->site = $GLOBALS['site']; }
}
class AdminRedirect extends RuntimeException {}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function ec_get_blog_id( $name ) { return array( 'main' => 1, 'studio' => 42 )[ $name ]; }
function get_home_url( $id, $path ) { return 'https://workspace.example.test' . $path; }
function home_url( $path ) { return 'https://local.example.test' . $path; }
function get_current_user_id() { return $GLOBALS['uid']; }
function wp_get_current_user() { return new WP_User( get_current_user_id() ); }
function ec_is_team_member( $id ) { return in_array( $id, array( 1, 2, 4 ), true ); }
function user_can( $user, $cap ) {
	return ( 3 === $user->ID && 'manage_options' === $cap )
		|| ( 2 === $user->ID && 1 === $user->site && 'edit_others_posts' === $cap )
		|| ( 4 === $user->ID && 4 === $user->site && 'edit_others_posts' === $cap );
}
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['site']; $GLOBALS['site'] = $id; }
function restore_current_blog() { $GLOBALS['site'] = array_pop( $GLOBALS['stack'] ); }
function is_admin() { return $GLOBALS['admin']; }
function wp_doing_ajax() { return $GLOBALS['ajax']; }
function show_admin_bar( $show ) { $GLOBALS['bar'] = $show; }
function wp_redirect( $url ) { throw new AdminRedirect( $url ); }
function check_access( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/inc/admin-access-control.php';
require dirname( __DIR__ ) . '/inc/team-members/role.php';
$GLOBALS['stack'] = array();
$GLOBALS['ajax'] = false;
$GLOBALS['admin'] = true;
$pagenow = 'post-new.php';
$studio = 'https://workspace.example.test/';
$admin_url = 'https://local.example.test/wp-admin/';
foreach ( array( 1, 2, 4, 12, 14 ) as $site ) {
	$GLOBALS['site'] = $site;
	foreach ( array( 1 => false, 2 => true, 3 => true, 4 => false, 5 => false ) as $uid => $allowed ) {
		$GLOBALS['uid'] = $uid;
		$GLOBALS['bar'] = true;
		check_access( $allowed === ec_users_can_access_wp_admin(), 'Wrong admin permission' );
		check_access( $site === $GLOBALS['site'] && empty( $GLOBALS['stack'] ), 'Leaked blog switch' );
		extrachill_hide_admin_bar_for_non_admins();
		check_access( $allowed === $GLOBALS['bar'], 'Wrong admin bar permission' );
		$redirect = null;
		try { extrachill_redirect_admin(); } catch ( AdminRedirect $e ) { $redirect = $e->getMessage(); }
		$destination = ec_is_team_member( $uid ) ? $studio : home_url( '/' );
		check_access( $allowed ? null === $redirect : $destination === $redirect, 'Wrong wp-admin redirect' );
		check_access( ( $allowed ? $admin_url : $destination ) === extrachill_prevent_admin_auth_redirect( $admin_url, '', new WP_User( $uid ) ), 'Wrong login redirect' );
		check_access( '/events/' === extrachill_prevent_admin_auth_redirect( '/events/', '/events/', new WP_User( $uid ) ), 'Front-end continuation broken' );
	}
}
$GLOBALS['uid'] = 1;
foreach ( array( 'ajax', 'admin-post', 'rest', 'frontend' ) as $request ) {
	$GLOBALS['admin'] = ! in_array( $request, array( 'rest', 'frontend' ), true );
	$GLOBALS['ajax'] = 'ajax' === $request;
	$pagenow = 'admin-post' === $request ? 'admin-post.php' : 'index.php';
	extrachill_redirect_admin(); // A redirect throws and fails this test.
}
// Login checks the supplied user, not the currently logged-in user's caps.
$GLOBALS['uid'] = 3;
check_access( $studio === extrachill_prevent_admin_auth_redirect( $admin_url, $admin_url, new WP_User( 1 ) ), 'Login used current admin' );
$GLOBALS['uid'] = 1;
check_access( $admin_url === extrachill_prevent_admin_auth_redirect( $admin_url, $admin_url, new WP_User( 2 ) ), 'Login lost main editor caps' );
// Exercise reuse of the real Studio check, without loading it on other sites.
require dirname( __DIR__, 2 ) . '/extrachill-studio/inc/review-queue.php';
function current_user_can( $cap ) { return user_can( wp_get_current_user(), $cap ); }
$GLOBALS['uid'] = 2;
check_access( ec_users_can_access_wp_admin(), 'Studio review helper not reused correctly' );
$caps = ec_users_get_team_role_caps();
check_access( ! isset( $caps['access_admin_bar'] ), 'Obsolete admin-bar cap remains' );
foreach ( array( 'access_studio', 'access_events_admin', 'upload_files', 'edit_posts', 'submit_for_review' ) as $cap ) {
	check_access( ! empty( $caps[ $cap ] ), 'Front-end cap removed: ' . $cap );
}
echo "Admin access control smoke tests passed.\n";
