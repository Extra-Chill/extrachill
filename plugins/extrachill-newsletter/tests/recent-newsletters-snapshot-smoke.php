<?php
/**
 * Smoke coverage for the recent newsletters sidebar snapshot.
 *
 * Run with: php tests/recent-newsletters-snapshot-smoke.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['ec_blog_id']       = 1;
$GLOBALS['ec_blog_stack']    = array();
$GLOBALS['ec_switches']      = 0;
$GLOBALS['ec_site_options']  = array();
$GLOBALS['ec_queries']       = array();
$GLOBALS['ec_actions']       = array();
$GLOBALS['ec_enqueued']      = array();
$GLOBALS['ec_newsletters']   = array(
	9 => array(
		array( 'ID' => 31, 'title' => 'Issue <3> & More', 'permalink' => 'https://newsletter.extrachill.com/issue-3/', 'date' => 'May 3, 2026' ),
		array( 'ID' => 22, 'title' => 'Issue "Two"', 'permalink' => 'https://newsletter.extrachill.com/issue-2/', 'date' => 'April 2, 2026' ),
		array( 'ID' => 11, 'title' => 'Issue One', 'permalink' => 'https://newsletter.extrachill.com/issue-1/', 'date' => 'March 1, 2026' ),
	),
);

function ec_get_blog_id( $key ) {
	return 'newsletter' === $key ? 9 : 1;
}
function ec_get_site_url( $key ) {
	return 'https://newsletter.extrachill.com';
}
function get_current_blog_id() {
	return $GLOBALS['ec_blog_id'];
}
function switch_to_blog( $blog_id ) {
	++$GLOBALS['ec_switches'];
	$GLOBALS['ec_blog_stack'][] = $GLOBALS['ec_blog_id'];
	$GLOBALS['ec_blog_id']      = (int) $blog_id;
	return true;
}
function restore_current_blog() {
	$GLOBALS['ec_blog_id'] = array_pop( $GLOBALS['ec_blog_stack'] );
	return true;
}
function get_site_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['ec_site_options'] ) ? $GLOBALS['ec_site_options'][ $key ] : $default;
}
function update_site_option( $key, $value ) {
	$GLOBALS['ec_site_options'][ $key ] = $value;
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['ec_actions'][ $hook ][] = $callback;
}
function do_action( $hook, ...$args ) {
	foreach ( isset( $GLOBALS['ec_actions'][ $hook ] ) ? $GLOBALS['ec_actions'][ $hook ] : array() as $callback ) {
		$callback( ...$args );
	}
}
function ec_newsletter_fixture( $id ) {
	foreach ( isset( $GLOBALS['ec_newsletters'][ $GLOBALS['ec_blog_id'] ] ) ? $GLOBALS['ec_newsletters'][ $GLOBALS['ec_blog_id'] ] : array() as $row ) {
		if ( $row['ID'] === $id ) {
			return $row;
		}
	}
	return array( 'title' => 'WRONG BLOG', 'permalink' => 'https://wrong.example/', 'date' => 'never' );
}
function get_the_title( $id ) {
	return ec_newsletter_fixture( $id )['title'];
}
function get_permalink( $id ) {
	return ec_newsletter_fixture( $id )['permalink'];
}
function get_the_date( $format, $id ) {
	return ec_newsletter_fixture( $id )['date'];
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html_e( $text, $domain ) {
	echo esc_html( $text );
}
function wp_enqueue_script( $handle ) {
	$GLOBALS['ec_enqueued'][] = $handle;
}
function wp_enqueue_style( $handle ) {
	$GLOBALS['ec_enqueued'][] = $handle;
}

class WP_Query { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double for core.
	public $posts = array();

	public function __construct( $args ) {
		$GLOBALS['ec_queries'][] = array( 'blog' => $GLOBALS['ec_blog_id'], 'args' => $args );
		$rows = isset( $GLOBALS['ec_newsletters'][ $GLOBALS['ec_blog_id'] ] ) ? $GLOBALS['ec_newsletters'][ $GLOBALS['ec_blog_id'] ] : array();
		foreach ( array_slice( $rows, 0, $args['posts_per_page'] ) as $row ) {
			$this->posts[] = (object) array( 'ID' => $row['ID'] );
		}
	}
}

require dirname( __DIR__ ) . '/inc/core/recent-newsletters-snapshot.php';

$failures = 0;
$checks   = 0;
function check( $label, $expected, $actual ) {
	global $failures, $checks;
	++$checks;
	if ( $expected !== $actual ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n" );
	}
}
function render_sidebar() {
	ob_start();
	include dirname( __DIR__ ) . '/inc/core/templates/recent-newsletters.php';
	return ob_get_clean();
}

// Output produced by the pre-snapshot template for the same three newsletters.
$expected_html = "\t<div class=\"sidebar-card ec-surface-card recent-newsletters-widget\">\n"
	. "\t\t<h3 class=\"widget-title\">Recent Newsletters</h3>\n"
	. "\t\t<ul class=\"recent-newsletters-list\">\n"
	. "\t\t\t\t\t\t\t<li>\n"
	. "\t\t\t\t\t<a href=\"https://newsletter.extrachill.com/issue-3/\" class=\"newsletter-link\">\n"
	. "\t\t\t\t\t\t<strong>Issue &lt;3&gt; &amp; More</strong>\n"
	. "\t\t\t\t\t\t<span class=\"newsletter-date\">May 3, 2026</span>\n"
	. "\t\t\t\t\t</a>\n"
	. "\t\t\t\t</li>\n"
	. "\t\t\t\t\t\t\t<li>\n"
	. "\t\t\t\t\t<a href=\"https://newsletter.extrachill.com/issue-2/\" class=\"newsletter-link\">\n"
	. "\t\t\t\t\t\t<strong>Issue &quot;Two&quot;</strong>\n"
	. "\t\t\t\t\t\t<span class=\"newsletter-date\">April 2, 2026</span>\n"
	. "\t\t\t\t\t</a>\n"
	. "\t\t\t\t</li>\n"
	. "\t\t\t\t\t\t\t<li>\n"
	. "\t\t\t\t\t<a href=\"https://newsletter.extrachill.com/issue-1/\" class=\"newsletter-link\">\n"
	. "\t\t\t\t\t\t<strong>Issue One</strong>\n"
	. "\t\t\t\t\t\t<span class=\"newsletter-date\">March 1, 2026</span>\n"
	. "\t\t\t\t\t</a>\n"
	. "\t\t\t\t</li>\n"
	. "\t\t\t\t\t</ul>\n"
	. "\t\t<a href=\"https://newsletter.extrachill.com\" class=\"view-all-link\">\n"
	. "\t\t\tView All Newsletters \xE2\x86\x92\n"
	. "\t\t</a>\n"
	. "\t</div>\n"
	. "\t";

// 1. Missing snapshot: rebuilt once from the newsletter blog, inside a balanced switch.
$html = render_sidebar();
check( 'cold render output identical to legacy template', $expected_html, $html );
check( 'cold render switches exactly once', 1, $GLOBALS['ec_switches'] );
check( 'cold render restores the original blog', 1, $GLOBALS['ec_blog_id'] );
check( 'cold render leaves no switch on the stack', array(), $GLOBALS['ec_blog_stack'] );
check( 'cold render queried the newsletter blog', 9, $GLOBALS['ec_queries'][0]['blog'] );
check( 'cold render keeps legacy query shape', array( 'newsletter', 3, 'publish', 'date', 'DESC' ), array( $GLOBALS['ec_queries'][0]['args']['post_type'], $GLOBALS['ec_queries'][0]['args']['posts_per_page'], $GLOBALS['ec_queries'][0]['args']['post_status'], $GLOBALS['ec_queries'][0]['args']['orderby'], $GLOBALS['ec_queries'][0]['args']['order'] ) );
check(
	'cold render stored the snapshot network-wide',
	array( 'title' => 'Issue <3> & More', 'permalink' => 'https://newsletter.extrachill.com/issue-3/', 'date' => 'May 3, 2026' ),
	$GLOBALS['ec_site_options']['extrachill_newsletter_recent_snapshot'][0]
);
check( 'cold render enqueues sidebar assets', array( 'extrachill-newsletter', 'extrachill-newsletter-sidebar' ), $GLOBALS['ec_enqueued'] );

// 2. Warm snapshot: no switch, no query, identical output.
$GLOBALS['ec_switches'] = 0;
$GLOBALS['ec_queries']  = array();
$html                   = render_sidebar();
check( 'warm render output identical to legacy template', $expected_html, $html );
check( 'warm render does not switch blogs', 0, $GLOBALS['ec_switches'] );
check( 'warm render does not query', array(), $GLOBALS['ec_queries'] );

// 3. Publishing a newsletter on the newsletter blog refreshes the snapshot in place.
switch_to_blog( 9 );
array_unshift( $GLOBALS['ec_newsletters'][9], array( 'ID' => 44, 'title' => 'Issue Four', 'permalink' => 'https://newsletter.extrachill.com/issue-4/', 'date' => 'June 4, 2026' ) );
do_action( 'transition_post_status', 'publish', 'draft', (object) array( 'ID' => 44, 'post_type' => 'newsletter' ) );
restore_current_blog();
$snapshot = $GLOBALS['ec_site_options']['extrachill_newsletter_recent_snapshot'];
check( 'publish refresh puts the new issue first', 'Issue Four', $snapshot[0]['title'] );
check( 'publish refresh keeps the limit', 3, count( $snapshot ) );

$GLOBALS['ec_switches'] = 0;
$html                   = render_sidebar();
check( 'refreshed snapshot renders without switching', 0, $GLOBALS['ec_switches'] );
check( 'refreshed snapshot renders the new issue', true, false !== strpos( $html, '<strong>Issue Four</strong>' ) );
check( 'refreshed snapshot drops the oldest issue', false, strpos( $html, 'Issue One' ) );

// 4. Unrelated transitions do not rebuild.
$GLOBALS['ec_queries'] = array();
switch_to_blog( 9 );
do_action( 'transition_post_status', 'publish', 'draft', (object) array( 'ID' => 5, 'post_type' => 'post' ) );
do_action( 'transition_post_status', 'pending', 'draft', (object) array( 'ID' => 6, 'post_type' => 'newsletter' ) );
restore_current_blog();
do_action( 'transition_post_status', 'publish', 'draft', (object) array( 'ID' => 7, 'post_type' => 'newsletter' ) );
check( 'non-newsletter, unpublished, and off-blog transitions skip rebuild', array(), $GLOBALS['ec_queries'] );

// 5. Unpublishing and permanent deletion refresh too.
switch_to_blog( 9 );
array_shift( $GLOBALS['ec_newsletters'][9] );
do_action( 'transition_post_status', 'trash', 'publish', (object) array( 'ID' => 44, 'post_type' => 'newsletter' ) );
check( 'unpublish refresh removes the issue', 'Issue <3> & More', $GLOBALS['ec_site_options']['extrachill_newsletter_recent_snapshot'][0]['title'] );
array_shift( $GLOBALS['ec_newsletters'][9] );
do_action( 'deleted_post', 31, (object) array( 'ID' => 31, 'post_type' => 'newsletter' ) );
check( 'delete refresh removes the issue', 'Issue "Two"', $GLOBALS['ec_site_options']['extrachill_newsletter_recent_snapshot'][0]['title'] );
restore_current_blog();

// 6. An empty stored snapshot is a valid answer: no output, no rebuild.
$GLOBALS['ec_site_options']['extrachill_newsletter_recent_snapshot'] = array();
$GLOBALS['ec_switches']                                              = 0;
check( 'empty snapshot renders nothing', '', render_sidebar() );
check( 'empty snapshot does not switch', 0, $GLOBALS['ec_switches'] );

if ( $failures ) {
	fwrite( STDERR, "{$failures}/{$checks} checks failed\n" );
	exit( 1 );
}
echo "recent-newsletters-snapshot-smoke: {$checks} checks passed\n";
