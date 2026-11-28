<?php
/**
 * Section landing smoke test. Run with: php tests/section-landing-smoke.php
 *
 * @package ExtraChillDocs
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'EXTRACHILL_DOCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

class WP_Post {
	public int $post_parent      = 0;
	public int $menu_order       = 0;
	public string $post_type    = 'page';
	public string $post_status  = 'publish';
	public string $post_content = '';
	public string $post_excerpt = '';

	public function __construct( public int $ID, public string $post_name, public string $post_title, array $fields = array() ) {
		foreach ( $fields as $key => $value ) {
			$this->$key = $value;
		}
	}
}

function add_filter( $hook, $callback, $priority = 10 ) {
	$GLOBALS['hooks'][ $hook ][ $priority ][] = $callback;
}

function add_action( $hook, $callback, $priority = 10 ) {
	add_filter( $hook, $callback, $priority );
}

function apply_filters( $hook, $value ) {
	$callbacks = $GLOBALS['hooks'][ $hook ] ?? array();
	ksort( $callbacks );
	foreach ( $callbacks as $group ) {
		foreach ( $group as $callback ) {
			$value = $callback( $value );
		}
	}
	return $value;
}

function do_action( $hook ) {
	apply_filters( $hook, null );
}

function is_admin() { return false; }
function is_front_page() { return false; }
function is_main_query() { return true; }
function in_the_loop() { return true; }
function is_singular( $type ) { return $type === get_queried_object()->post_type; }
function get_queried_object() { return $GLOBALS['queried_page']; }
function get_queried_object_id() { return get_queried_object()->ID; }
function get_the_ID() { return $GLOBALS['loop_id']; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['page_meta'][ $id ][ $key ] ?? ''; }
function post_password_required( $page ) { return false; }
function get_the_title( $page ) { return $page->post_title; }
function get_permalink( $page ) { return 'https://docs.example/' . $page->post_name . '/'; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
function esc_url( $url ) { return esc_html( $url ); }
function ec_get_site_url( $site ) { return 'https://' . $site . '.example/'; }
function trailingslashit( $url ) { return rtrim( $url, '/' ) . '/'; }
function _n( $single, $plural, $count, $domain ) { return 1 === $count ? $single : $plural; }

function get_posts( $args ) {
	$posts = array_values(
		array_filter(
			$GLOBALS['pages'],
			static fn( $page ) => $args['post_type'] === $page->post_type
				&& ( $args['post_status'] ?? 'publish' ) === $page->post_status
				&& $args['post_parent'] === $page->post_parent
		)
	);
	if ( is_array( $args['orderby'] ?? null ) ) {
		usort(
			$posts,
			static function ( $left, $right ) use ( $args ) {
				$fields = array( 'menu_order' => 'menu_order', 'title' => 'post_title', 'ID' => 'ID' );
				foreach ( $args['orderby'] as $key => $order ) {
					$result = $left->{$fields[ $key ]} <=> $right->{$fields[ $key ]};
					if ( $result ) {
						return 'ASC' === $order ? $result : -$result;
					}
				}
				return 0;
			}
		);
	}
	return $posts;
}

require EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/platform-map.php';
require EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/section-landing.php';
require EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/homepage.php';
require EXTRACHILL_DOCS_PLUGIN_DIR . 'inc/core/feedback.php';

// Stand in for core's do_blocks at priority 9, including its legacy list output.
add_filter(
	'the_content',
	static fn( $content ) => preg_replace( '/<!-- wp:page-list .*? \/-->/', '<ul><li>Stored list</li></ul>', $content ),
	9
);

function render_page( WP_Post $page, ?int $loop_id = null ): string {
	$GLOBALS['queried_page'] = $page;
	$GLOBALS['loop_id']      = $loop_id ?? $page->ID;
	ob_start();
	echo apply_filters( 'the_content', $page->post_content );
	do_action( 'extrachill_after_page_content' );
	return (string) ob_get_clean();
}

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$community = new WP_Post( 1, 'community', 'Community', array( 'post_excerpt' => 'Old excerpt' ) );
$events    = new WP_Post( 2, 'events-calendar', 'Events Calendar', array( 'post_content' => '<p>Stored introduction</p><!-- wp:page-list {"parentPageID":2} /-->' ) );
$login     = new WP_Post( 3, 'login', 'Login', array( 'post_content' => '<p>Sign in</p>' ) );
$unmanaged = new WP_Post( 4, 'about', 'About', array( 'post_content' => '<p>About us</p>' ) );
$meta_only = new WP_Post( 5, 'more-help', 'More Help' );
$article   = new WP_Post( 6, 'read-first', 'Start here', array( 'post_parent' => 1, 'post_content' => '<p>Article body</p>' ) );
$empty     = new WP_Post( 16, 'search', 'Search', array( 'post_content' => '<p>No published articles yet</p>' ) );

$GLOBALS['page_meta'] = array( 3 => array( '_extrachill_docs_platform_parent' => '1' ), 5 => array( '_extrachill_docs_platform_parent' => '1' ) );
$GLOBALS['pages']     = array(
	$community,
	$events,
	$login,
	$unmanaged,
	$meta_only,
	$empty,
	new WP_Post( 7, 'last', 'Zulu', array( 'post_parent' => 1, 'menu_order' => 1 ) ),
	new WP_Post( 8, 'second', 'Alpha & Beta', array( 'post_parent' => 1, 'menu_order' => 1 ) ),
	$article,
	new WP_Post( 9, 'draft', 'Hidden draft', array( 'post_parent' => 1, 'post_status' => 'draft' ) ),
	new WP_Post( 10, 'private', 'Private article', array( 'post_parent' => 1, 'post_status' => 'private' ) ),
	new WP_Post( 11, 'nested', 'Grandchild', array( 'post_parent' => 6 ) ),
	new WP_Post( 12, 'find-shows', 'Find shows', array( 'post_parent' => 2 ) ),
	new WP_Post( 13, 'login-child', 'Login child', array( 'post_parent' => 3 ) ),
	new WP_Post( 14, 'about-child', 'About child', array( 'post_parent' => 4 ) ),
	new WP_Post( 15, 'more-help-child', 'More help child', array( 'post_parent' => 5 ) ),
);

foreach ( extrachill_docs_load_platform_map() as $entry ) {
	check( ! empty( extrachill_docs_get_section_description( $entry['parent_slug'] ) ), 'Section needs a map description: ' . $entry['parent_slug'] );
}

$description = 'Meet other music fans, share what you\'re listening to, and join the discussion.';
$output      = render_page( $community );
check( str_contains( $output, esc_html( $description ) ), 'Empty section should render its map description.' );
check( 1 === substr_count( $output, '<ul' ) && 3 === substr_count( $output, '<li>' ), 'Empty section should list only direct, published child pages.' );
check( 1 === preg_match( '~href="https://docs\.example/read-first/">Start here</a>.*href="https://docs\.example/second/">Alpha &amp; Beta</a>.*href="https://docs\.example/last/">Zulu</a>~s', $output ), 'Child links should sort by menu order then title.' );
check( ! str_contains( $output, 'docs-feedback' ), 'Empty section should not get feedback.' );

$output = render_page( $events );
check( 1 === substr_count( $output, '<ul' ) && str_contains( $output, 'href="https://docs.example/find-shows/"' ), 'Legacy section should show exactly one list, generated from its children.' );
check( 1 === substr_count( $output, 'Find shows, keep track of the ones you go to' ) && ! str_contains( $output, 'Stored' ), 'Legacy section should use only the map introduction and generated list.' );
check( ! str_contains( $output, 'docs-feedback' ), 'Legacy section should not get feedback.' );
check( str_contains( render_page( $meta_only ), 'href="https://docs.example/more-help-child/"' ), 'A flagged section is managed even without a map entry.' );
check( render_page( $login ) === $login->post_content, 'Login should be untouched, even with children and the managed marker.' );
check( render_page( $unmanaged ) === $unmanaged->post_content, 'A non-section top-level page with children should be untouched.' );
check( render_page( $empty ) === $empty->post_content, 'A mapped page with no published children should be untouched.' );
check( str_contains( render_page( $events, $login->ID ), 'Stored list' ), 'Another loop item should not be treated as the queried section.' );

$output = render_page( $article );
check( str_starts_with( $output, $article->post_content ) && ! str_contains( $output, 'docs-section-landing' ), 'Child article content should be untouched.' );
check( 1 === substr_count( $output, 'Did this help?' ), 'Child article should get feedback once.' );

ob_start();
do_action( 'extrachill_homepage_content' );
$homepage = (string) ob_get_clean();
check( str_contains( $homepage, '<p class="docs-category-description">' . esc_html( $description ) . '</p>' ), 'Homepage card should use the map description.' );
check( ! str_contains( $homepage, 'Old excerpt' ), 'Map description should take precedence over the old excerpt.' );

echo "Section landing smoke test passed.\n";
