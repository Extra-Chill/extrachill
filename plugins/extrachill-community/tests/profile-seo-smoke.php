<?php
/**
 * Focused tests for Community profile SEO hardening.
 *
 * Covers the rel attribute applied to user-supplied profile links and the
 * noindex condition for profiles without community participation.
 *
 * Run: php tests/profile-seo-smoke.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_is_single_user']  = true;
$GLOBALS['_test_display_user_id'] = 10;
$GLOBALS['_test_topic_counts']    = array();
$GLOBALS['_test_reply_counts']    = array();

function add_filter() {}

function bbp_is_single_user() {
	return $GLOBALS['_test_is_single_user'];
}

function bbp_get_displayed_user_id() {
	return $GLOBALS['_test_display_user_id'];
}

function bbp_get_user_topic_count( $user_id ) {
	return $GLOBALS['_test_topic_counts'][ $user_id ] ?? 0;
}

function bbp_get_user_reply_count( $user_id ) {
	return $GLOBALS['_test_reply_counts'][ $user_id ] ?? 0;
}

require __DIR__ . '/../inc/user-profiles/profile-seo.php';

$failures = 0;

function check( $label, $condition ) {
	global $failures;

	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}

	echo "FAIL: $label\n";
	++$failures;
}

// --- Rel attribute for user-supplied profile links ---

$rel       = extrachill_community_profile_link_rel();
$rel_parts = preg_split( '/\s+/', trim( $rel ) );

check( 'profile link rel contains nofollow', in_array( 'nofollow', $rel_parts, true ) );
check( 'profile link rel contains ugc', in_array( 'ugc', $rel_parts, true ) );
check( 'profile link rel contains noopener', in_array( 'noopener', $rel_parts, true ) );

$template = file_get_contents( __DIR__ . '/../bbpress/user-details.php' );
check(
	'user-details template uses extrachill_community_profile_link_rel() for the rel attribute',
	false !== strpos( $template, 'extrachill_community_profile_link_rel()' )
);
check(
	'user-details template has no hardcoded rel="noopener"-only attribute',
	false === strpos( $template, 'rel="noopener"' )
);

// --- Participation detection ---

check( 'user with no topics or replies has no participation', ! extrachill_community_user_has_participation( 10 ) );

$GLOBALS['_test_topic_counts'][10] = 1;
check( 'user with a topic has participation', extrachill_community_user_has_participation( 10 ) );

unset( $GLOBALS['_test_topic_counts'][10] );
$GLOBALS['_test_reply_counts'][10] = 3;
check( 'user with replies has participation', extrachill_community_user_has_participation( 10 ) );
unset( $GLOBALS['_test_reply_counts'][10] );

check( 'user id 0 has no participation', ! extrachill_community_user_has_participation( 0 ) );

// --- wp_robots noindex condition ---

$robots = extrachill_community_profile_robots( array( 'max-image-preview' => 'large' ) );
check( 'empty profile is noindexed', ! empty( $robots['noindex'] ) );
check( 'empty profile does not add nofollow', empty( $robots['nofollow'] ) );
check( 'existing robots directives preserved', 'large' === ( $robots['max-image-preview'] ?? null ) );

$GLOBALS['_test_topic_counts'][10] = 2;
$robots                            = extrachill_community_profile_robots( array( 'max-image-preview' => 'large' ) );
check( 'participating profile is not noindexed', empty( $robots['noindex'] ) );
unset( $GLOBALS['_test_topic_counts'][10] );

$GLOBALS['_test_is_single_user'] = false;
$robots                          = extrachill_community_profile_robots( array( 'max-image-preview' => 'large' ) );
check( 'non-profile pages are untouched', empty( $robots['noindex'] ) );
$GLOBALS['_test_is_single_user'] = true;

$GLOBALS['_test_display_user_id'] = 0;
$robots                           = extrachill_community_profile_robots( array() );
check( 'missing displayed user id leaves robots untouched', empty( $robots['noindex'] ) );
$GLOBALS['_test_display_user_id'] = 10;

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s)\n";
	exit( 1 );
}

echo "\nAll profile SEO checks passed\n";
exit( 0 );
