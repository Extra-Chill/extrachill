<?php
/**
 * Standalone public cross-site read cache regression tests.
 *
 * @package ExtraChillBlog
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['cache'] = array();
$GLOBALS['calls'] = 0;
$GLOBALS['error'] = false;
function add_filter() {}
function remove_filter() {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_transient( $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) {
	check( 600 === $ttl, 'Public reads use ten-minute TTL.' );
	$GLOBALS['cache'][ $key ] = $value;
}
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function ec_cross_site_rest_request( $site, $method, $route, $args ) {
	++$GLOBALS['calls'];
	return $GLOBALS['error'] ? new WP_Error() : array( 'schema_version' => '1', 'items' => array() );
}
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
require dirname( __DIR__ ) . '/inc/core/cross-site-abilities.php';
foreach ( array(
	array( 'events', 'data-machine-events/events-by-term', array( 'term_slug' => 'first' ) ),
	array( 'community', 'extrachill/community-recent-public-activity', array( 'artist_slug' => 'first' ) ),
	array( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 4 ) ),
) as $request ) {
	$before = $GLOBALS['calls'];
	$first = extrachill_blog_request_cross_site_ability( ...$request );
	check( $first === extrachill_blog_request_cross_site_ability( ...$request ), 'Cached owner response preserved.' );
	check( $before + 1 === $GLOBALS['calls'], 'Cached pillar/homepage call skips transport.' );
}
extrachill_blog_request_cross_site_ability( 'events', 'data-machine-events/events-by-term', array( 'term_slug' => 'second' ) );
check( 4 === $GLOBALS['calls'], 'Different inputs fetch separately.' );
$args = array( 'query' => array( 'slug' => 'first' ) );
extrachill_blog_cached_cross_site_request( 'artist', 'GET', '/wp/v2/artist_profile', $args );
extrachill_blog_cached_cross_site_request( 'artist', 'GET', '/wp/v2/artist_profile', $args );
check( 5 === $GLOBALS['calls'], 'Profile request is cached too.' );
$GLOBALS['error'] = true;
extrachill_blog_request_cross_site_ability( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 5 ) );
extrachill_blog_request_cross_site_ability( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 5 ) );
check( 7 === $GLOBALS['calls'], 'Errors are not cached.' );
function get_posts() { return array(); }
class WP_REST_Request {
	public function __construct( $method, $route ) {}
	public function set_query_params( $query ) {}
}
function rest_do_request( $request ) {
	++$GLOBALS['calls'];
	return new class() {
		public function is_error() { return false; }
		public function get_data() { return array( array( 'slug' => 'market', 'count' => 3 ) ); }
	};
}
require dirname( __DIR__ ) . '/inc/home/homepage-queries.php';
$query = array( 'taxonomy' => 'location', 'limit' => 8 );
$first = extrachill_blog_request_location_event_counts( $query );
check( $first === extrachill_blog_request_location_event_counts( $query ), 'Homepage location response is cached.' );
check( 8 === $GLOBALS['calls'], 'Homepage counts skip repeated REST dispatch.' );
echo "cross-site cache smoke passed\n";
