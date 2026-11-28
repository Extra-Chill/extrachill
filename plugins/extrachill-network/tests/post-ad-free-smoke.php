<?php
/**
 * Standalone tests for the per-post ad-free flag.
 *
 * @package ExtraChill\Network
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['paf_meta']    = array(
	42 => array( '_extrachill_ad_free' => '1' ),
	43 => array(),
);
$GLOBALS['paf_filters'] = array();
$GLOBALS['paf_actions'] = array();
$GLOBALS['paf_blog']    = 7;

function add_action( $hook, $cb ) {
	$GLOBALS['paf_actions'][ $hook ][] = $cb;
}
function add_filter( $hook, $cb ) {
	$GLOBALS['paf_filters'][ $hook ][] = $cb;
}
function get_current_blog_id() {
	return $GLOBALS['paf_blog'];
}
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Mirrors the core signature.
function get_post_meta( $id, $key, $single ) {
	return $GLOBALS['paf_meta'][ $id ][ $key ] ?? '';
}

require dirname( __DIR__ ) . '/inc/integrations/post-ad-free.php';

$failures = 0;
function check( string $label, bool $ok ): void {
	global $failures;
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
	$failures += $ok ? 0 : 1;
}

check( 'filter registered', in_array( 'extrachill_block_ads_on_ad_free_posts', $GLOBALS['paf_filters']['extrachill_should_block_ads'] ?? array(), true ) );
check( 'meta registered on init', in_array( 'extrachill_register_post_ad_free_meta', $GLOBALS['paf_actions']['init'] ?? array(), true ) );
check( 'flagged post blocks ads', true === extrachill_block_ads_on_ad_free_posts( false, array(
	'blog_id' => 7,
	'post_id' => 42,
) ) );
check( 'unflagged post does not', false === extrachill_block_ads_on_ad_free_posts( false, array(
	'blog_id' => 7,
	'post_id' => 43,
) ) );
check( 'no post does not', false === extrachill_block_ads_on_ad_free_posts( false, array(
	'blog_id' => 7,
	'post_id' => 0,
) ) );
check( 'missing context does not', false === extrachill_block_ads_on_ad_free_posts( false ) );
check( 'other blog is not judged', false === extrachill_block_ads_on_ad_free_posts( false, array(
	'blog_id' => 1,
	'post_id' => 42,
) ) );
check( 'earlier block is kept', true === extrachill_block_ads_on_ad_free_posts( true, array(
	'blog_id' => 7,
	'post_id' => 43,
) ) );

exit( $failures ? 1 : 0 );
