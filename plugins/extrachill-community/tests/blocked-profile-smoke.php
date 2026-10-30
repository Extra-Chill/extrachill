<?php
/**
 * Standalone regression test for blocked-user public profile suppression.
 *
 * Issue #318: banning a user must remove their public profile surface —
 * the bio and _user_profile_dynamic_links backlinks — not just block login.
 *
 * Run: php tests/blocked-profile-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

// ---------------------------------------------------------------------------
// WordPress / bbPress / extrachill-users stubs
// ---------------------------------------------------------------------------

$GLOBALS['_test'] = array(
	'is_single_user'    => false,
	'displayed_user_id' => 0,
	'blocked'           => array(),
	'caps'              => array(),
	'status_headers'    => array(),
	'nocache_calls'     => 0,
	'hooks'             => array(),
	'users_with_meta'   => array(),
);

class WP_Query {
	public $is_404 = false;

	public function set_404() {
		$this->is_404 = true;
	}
}

function add_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['_test']['hooks'][ $hook ][] = array( $callback, $priority );
}

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['_test']['hooks'][ $hook ][] = array( $callback, $priority );
}

function bbp_is_single_user() {
	return $GLOBALS['_test']['is_single_user'];
}

function bbp_get_displayed_user_id() {
	return $GLOBALS['_test']['displayed_user_id'];
}

function extrachill_users_is_blocked( int $user_id ): bool {
	return in_array( $user_id, $GLOBALS['_test']['blocked'], true );
}

function extrachill_users_moderation_meta_key() {
	return 'extrachill_user_moderation';
}

function extrachill_users_legacy_ban_meta_key() {
	return 'extrachill_user_ban';
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['_test']['caps'], true );
}

function status_header( $code ) {
	$GLOBALS['_test']['status_headers'][] = $code;
}

function nocache_headers() {
	++$GLOBALS['_test']['nocache_calls'];
}

function get_users( $args = array() ) {
	// The production query asks only for users carrying a moderation record.
	return $GLOBALS['_test']['users_with_meta'];
}

require __DIR__ . '/../inc/user-profiles/blocked-profile.php';

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$failures = 0;

function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
	} else {
		echo "FAIL: {$label}\n";
		++$failures;
	}
}

function reset_state( array $overrides = array() ) {
	$GLOBALS['_test'] = array_merge(
		array(
			'is_single_user'    => false,
			'displayed_user_id' => 0,
			'blocked'           => array(),
			'caps'              => array(),
			'status_headers'    => array(),
			'nocache_calls'     => 0,
			'hooks'             => $GLOBALS['_test']['hooks'],
			'users_with_meta'   => array(),
		),
		$overrides
	);
	$GLOBALS['wp_query'] = new WP_Query();
}

// ---------------------------------------------------------------------------
// Hook registration
// ---------------------------------------------------------------------------

$template_redirect_hooks = $GLOBALS['_test']['hooks']['template_redirect'] ?? array();
check(
	'template_redirect hook registered at priority 5',
	1 === count( $template_redirect_hooks )
		&& 'extrachill_community_block_banned_profiles' === $template_redirect_hooks[0][0]
		&& 5 === $template_redirect_hooks[0][1]
);
check( 'wp_robots filter registered', isset( $GLOBALS['_test']['hooks']['wp_robots'] ) );
check( 'wp_sitemaps_users_query_args filter registered', isset( $GLOBALS['_test']['hooks']['wp_sitemaps_users_query_args'] ) );

// ---------------------------------------------------------------------------
// 1. Blocked user profile render → 404 (bio + backlinks never render)
// ---------------------------------------------------------------------------

reset_state(
	array(
		'is_single_user'    => true,
		'displayed_user_id' => 750,
		'blocked'           => array( 750 ),
	)
);
$GLOBALS['wp_query']->bbp_is_single_user         = true;
$GLOBALS['wp_query']->bbp_is_single_user_profile = true;
extrachill_community_block_banned_profiles();
check( 'blocked profile: wp_query 404 set', $GLOBALS['wp_query']->is_404 );
check(
	'blocked profile: bbPress single-user flags cleared so the profile template is not routed',
	false === $GLOBALS['wp_query']->bbp_is_single_user && false === $GLOBALS['wp_query']->bbp_is_single_user_profile
);
check( 'blocked profile: status_header(404) sent', array( 404 ) === $GLOBALS['_test']['status_headers'] );
check( 'blocked profile: nocache_headers sent', 1 === $GLOBALS['_test']['nocache_calls'] );

// ---------------------------------------------------------------------------
// 2. Active (non-blocked) user profile renders untouched
// ---------------------------------------------------------------------------

reset_state(
	array(
		'is_single_user'    => true,
		'displayed_user_id' => 42,
		'blocked'           => array( 750 ),
	)
);
extrachill_community_block_banned_profiles();
check( 'active profile: no 404', ! $GLOBALS['wp_query']->is_404 );
check( 'active profile: no status header', array() === $GLOBALS['_test']['status_headers'] );

// ---------------------------------------------------------------------------
// 3. Non-profile pages are never touched
// ---------------------------------------------------------------------------

reset_state(
	array(
		'is_single_user'    => false,
		'displayed_user_id' => 750,
		'blocked'           => array( 750 ),
	)
);
extrachill_community_block_banned_profiles();
check( 'non-profile page: no 404', ! $GLOBALS['wp_query']->is_404 );

// ---------------------------------------------------------------------------
// 4. Moderators keep access for review, but render is noindexed
// ---------------------------------------------------------------------------

reset_state(
	array(
		'is_single_user'    => true,
		'displayed_user_id' => 750,
		'blocked'           => array( 750 ),
		'caps'              => array( 'moderate' ),
	)
);
extrachill_community_block_banned_profiles();
check( 'moderator view: no 404', ! $GLOBALS['wp_query']->is_404 );

$robots = extrachill_community_blocked_profile_robots( array( 'max-image-preview' => 'large' ) );
check( 'moderator view: wp_robots adds noindex', true === ( $robots['noindex'] ?? false ) );
check( 'moderator view: wp_robots adds nofollow', true === ( $robots['nofollow'] ?? false ) );
check( 'moderator view: existing robots directives preserved', 'large' === ( $robots['max-image-preview'] ?? '' ) );

// ---------------------------------------------------------------------------
// 5. wp_robots untouched for active users
// ---------------------------------------------------------------------------

reset_state(
	array(
		'is_single_user'    => true,
		'displayed_user_id' => 42,
		'blocked'           => array( 750 ),
	)
);
$robots = extrachill_community_blocked_profile_robots( array() );
check( 'active profile: wp_robots untouched', array() === $robots );

// ---------------------------------------------------------------------------
// 6. Sitemap exclusion of blocked users
// ---------------------------------------------------------------------------

reset_state(
	array(
		// 750 and 741 banned; 99 has a moderation record but is reinstated.
		'users_with_meta' => array( 750, 741, 99 ),
		'blocked'         => array( 750, 741 ),
	)
);
$args = extrachill_community_exclude_blocked_users_from_sitemap( array( 'exclude' => array( 7 ) ) );
sort( $args['exclude'] );
check( 'sitemap: blocked users merged into exclude', array( 7, 741, 750 ) === $args['exclude'] );

$ids = extrachill_community_get_blocked_user_ids();
check( 'blocked ids: reinstated user with record not included', ! in_array( 99, $ids, true ) );
check( 'blocked ids: result cached per request (static)', $ids === extrachill_community_get_blocked_user_ids() );

// ---------------------------------------------------------------------------
// 7. Invalid user id is never blocked
// ---------------------------------------------------------------------------

check( 'user id 0 is never blocked', ! extrachill_community_user_is_blocked( 0 ) );

echo $failures ? "\n{$failures} FAILURE(S)\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );
