<?php
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.EscapeOutput.OutputNotEscaped, Squiz.PHP.Eval.Discouraged -- Standalone CLI test script, not plugin runtime.
/**
 * Coverage for issue #1632: the theme enables the Blocks Everywhere comment
 * editor only when the plugin is active and scopes its allowed-blocks list to
 * comments.
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

$GLOBALS['_test_filters']       = array();
$GLOBALS['_test_doing_filters'] = array();

/**
 * Record filter registrations.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 */
function add_filter( $hook, $callback ) {
	$GLOBALS['_test_filters'][ $hook ][] = $callback;
	return true;
}

/**
 * Pass values through unchanged; no third-party filters in this fixture.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}

/**
 * Report whether a filter is running.
 *
 * @param string $hook Hook name.
 * @return bool
 */
function doing_filter( $hook ) {
	return in_array( $hook, $GLOBALS['_test_doing_filters'], true );
}

$functions = (string) file_get_contents( $theme_dir . '/functions.php' );
extrachill_test_check( 'functions.php loads the Blocks Everywhere comments integration', str_contains( $functions, "'/core/editor/blocks-everywhere-comments.php'" ) );

require $theme_dir . '/inc/core/editor/blocks-everywhere-comments.php';

extrachill_test_check( 'blocks_everywhere_comments filter is registered', in_array( 'extrachill_enable_blocks_everywhere_comments', $GLOBALS['_test_filters']['blocks_everywhere_comments'] ?? array(), true ) );
extrachill_test_check( 'blocks_everywhere_allowed_blocks filter is registered', in_array( 'extrachill_blocks_everywhere_comment_allowed_blocks', $GLOBALS['_test_filters']['blocks_everywhere_allowed_blocks'] ?? array(), true ) );

$core_defaults = array( 'core/paragraph', 'core/list', 'core/code', 'core/list-item', 'core/quote' );

// Plugin inactive: everything passes through untouched.
extrachill_test_check( 'comments editor stays off when Blocks Everywhere is inactive', false === extrachill_enable_blocks_everywhere_comments( false ) );
extrachill_test_check( 'allowed blocks pass through when Blocks Everywhere is inactive', extrachill_blocks_everywhere_comment_allowed_blocks( $core_defaults, 'comments' ) === $core_defaults );

// Plugin active.
eval( 'namespace Automattic\Blocks_Everywhere; class Blocks_Everywhere {}' );

extrachill_test_check( 'comments editor turns on when Blocks Everywhere is active', true === extrachill_enable_blocks_everywhere_comments( false ) );

$expected = array( 'core/paragraph', 'core/list', 'core/list-item', 'core/quote', 'core/image', 'core/embed' );
$comments = extrachill_blocks_everywhere_comment_allowed_blocks( $core_defaults, 'comments' );
extrachill_test_check( 'comment editor gets the comment block list', $comments === $expected );
extrachill_test_check( 'comment editor excludes code and heading blocks', ! in_array( 'core/code', $comments, true ) && ! in_array( 'core/heading', $comments, true ) );

$forum = array( 'core/paragraph', 'core/heading', 'core/embed' );
extrachill_test_check( 'bbPress editor list is left to extrachill-community', extrachill_blocks_everywhere_comment_allowed_blocks( $forum, 'bbpress' ) === $forum );
extrachill_test_check( 'core type outside comment filtering is untouched', extrachill_blocks_everywhere_comment_allowed_blocks( $core_defaults, 'core' ) === $core_defaults );

$GLOBALS['_test_doing_filters'] = array( 'pre_comment_content' );
extrachill_test_check( 'core type during pre_comment_content kses uses the comment list', extrachill_blocks_everywhere_comment_allowed_blocks( $core_defaults, 'core' ) === $expected );
$GLOBALS['_test_doing_filters'] = array();

if ( $failures > 0 ) {
	exit( 1 );
}

echo "blocks everywhere comments ok\n";
