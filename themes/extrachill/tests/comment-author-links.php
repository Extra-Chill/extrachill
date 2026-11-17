<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped, Universal.Files.SeparateFunctionsFromOO.Mixed -- Standalone CLI test script, not plugin runtime.
/**
 * Regression coverage for issue #1567: comment author names on main-site
 * articles must link registered commenters to their community profile and
 * leave guests unlinked. The comments template previously called helpers
 * that no longer existed, so every author rendered as plain text.
 *
 * Loads the real extrachill-network entity-links helpers against minimal
 * WordPress stubs.
 *
 * @package ExtraChill
 */

define( 'ABSPATH', __DIR__ );

$theme_dir = dirname( __DIR__ );
$failures  = 0;

/**
 * Record a test assertion.
 *
 * @param string $label     Assertion label.
 * @param bool   $condition Result.
 */
function extrachill_test_check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$label}\n";
		return;
	}
	fwrite( STDERR, "FAIL: {$label}\n" );
	++$failures;
}

// Source-level guards on the template itself.
$template = (string) file_get_contents( $theme_dir . '/inc/single/comments.php' );
extrachill_test_check( 'comments template renders the author via extrachill_comment_author_html()', str_contains( $template, 'extrachill_comment_author_html( $comment )' ) );
extrachill_test_check( 'comments template no longer calls the removed ec_* comment helpers', ! preg_match( '/\bec_(should_use_multisite_comment_links|get_comment_author_link_multisite)\s*\(/', $template ) );

$functions = (string) file_get_contents( $theme_dir . '/functions.php' );
extrachill_test_check( 'functions.php loads the comment author helper', str_contains( $functions, "'/core/templates/comment-author.php'" ) );

// Minimal WordPress stubs.
$GLOBALS['_test_users'] = array(
	510 => (object) array(
		'ID'            => 510,
		'user_nicename' => 'zachary-salas',
		'user_email'    => 'zach@example.com',
	),
);

class WP_Comment {
	/** @var string */
	public $user_id = '0';
	/** @var string */
	public $comment_author = '';
	/** @var string */
	public $comment_author_email = '';
	/** @var string */
	public $comment_author_url = '';
	/** @var string */
	public $comment_date = '';
}

function add_filter() {}
function absint( $value ) {
	return abs( (int) $value );
}
function ec_get_blog_id( $key ) {
	return 'community' === $key ? 2 : 1;
}
function ec_get_site_url( $key ) {
	return 'community' === $key ? 'https://community.extrachill.com' : 'https://extrachill.com';
}
function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['_test_users'] as $user ) {
		if ( 'email' === $field && $user->user_email === $value ) {
			return $user;
		}
	}
	return false;
}
function get_userdata( $user_id ) {
	return $GLOBALS['_test_users'][ $user_id ] ?? false;
}
function esc_url( $url ) {
	return $url;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function get_comment_author( $comment ) {
	return $comment->comment_author;
}
function get_comment_author_link( $comment ) {
	if ( '' === $comment->comment_author_url ) {
		return $comment->comment_author;
	}
	return "<a href='" . esc_url( $comment->comment_author_url ) . "' class='url' rel='ugc external nofollow'>" . $comment->comment_author . '</a>';
}
function get_author_posts_url( $user_id ) {
	return 'https://extrachill.com/author/' . $user_id . '/';
}
function switch_to_blog() {}
function restore_current_blog() {}

/**
 * Build a comment fixture.
 *
 * @param int    $user_id Commenter user ID.
 * @param string $author  Display name.
 * @param string $email   Email.
 * @param string $date    Comment date.
 * @return WP_Comment
 */
function extrachill_test_comment( $user_id, $author, $email, $date = '2026-10-01 19:35:39' ) {
	$comment                       = new WP_Comment();
	$comment->user_id              = (string) $user_id;
	$comment->comment_author       = $author;
	$comment->comment_author_email = $email;
	$comment->comment_date         = $date;
	return $comment;
}

require $theme_dir . '/inc/core/templates/comment-author.php';

$registered = extrachill_test_comment( 510, 'Zachary Salas', 'zach@example.com' );
$guest      = extrachill_test_comment( 0, 'Guest Reader', 'guest@example.com' );

// Without extrachill-network the theme must not fatal and must not link profiles.
extrachill_test_check( 'without the network plugin, registered authors fall back to the default output', 'Zachary Salas' === extrachill_comment_author_html( $registered ) );

$entity_links = $theme_dir . '/../../plugins/extrachill-network/inc/cross-site-links/entity-links.php';
if ( ! file_exists( $entity_links ) ) {
	fwrite( STDERR, "Missing extrachill-network entity-links.php at {$entity_links}.\n" );
	exit( 1 );
}
require $entity_links;

$registered_html = extrachill_comment_author_html( $registered );
extrachill_test_check( 'registered author renders an <a> to the community profile', '<a href="https://community.extrachill.com/u/zachary-salas">Zachary Salas</a>' === $registered_html );

$guest_html = extrachill_comment_author_html( $guest );
extrachill_test_check( 'guest author renders plain text', 'Guest Reader' === $guest_html );
extrachill_test_check( 'guest author is never linked to a profile', ! str_contains( $guest_html, '/u/' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "comment author links ok\n";
