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
$GLOBALS['last_args'] = null;
$GLOBALS['ttls'] = array();
function add_filter() {}
function remove_filter() {}
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_transient( $key ) { return $GLOBALS['cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['ttls'][ $key ] = $ttl;
	$GLOBALS['cache'][ $key ] = $value;
}
function __( $text ) { return $text; }
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function ec_cross_site_rest_request( $site, $method, $route, $args ) {
	++$GLOBALS['calls'];
	$GLOBALS['last_args'] = $args;
	return $GLOBALS['error'] ? new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) : array( 'schema_version' => '1', 'items' => array() );
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
check( 3 === $GLOBALS['last_args']['timeout'], 'Public reads pass a short default timeout.' );
check( array( 600 ) === array_values( array_unique( $GLOBALS['ttls'] ) ), 'Successful public reads use ten-minute TTL.' );
$custom = array( 'query' => array( 'slug' => 'custom' ), 'timeout' => 9 );
extrachill_blog_cached_cross_site_request( 'artist', 'GET', '/wp/v2/artist_profile', $custom );
check( 9 === $GLOBALS['last_args']['timeout'], 'Explicit caller timeout is preserved.' );
check( 6 === $GLOBALS['calls'], 'Custom-timeout request fetched once.' );
$GLOBALS['error'] = true;
$GLOBALS['ttls']  = array();
$first_error  = extrachill_blog_request_cross_site_ability( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 5 ) );
$second_error = extrachill_blog_request_cross_site_ability( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 5 ) );
check( 7 === $GLOBALS['calls'], 'Errors are negative-cached; second call skips transport.' );
check( is_wp_error( $first_error ) && is_wp_error( $second_error ), 'Cached failure is replayed as WP_Error.' );
check( 'http_request_failed' === $second_error->get_error_code(), 'Cached failure preserves error code.' );
check( ! extrachill_blog_is_activity_projection( $second_error ), 'Cached failure is not an activity projection.' );
check( array( 60 ) === array_values( $GLOBALS['ttls'] ), 'Failures are negative-cached for sixty seconds.' );
$GLOBALS['cache'] = array();
$GLOBALS['error'] = false;
$recovered = extrachill_blog_request_cross_site_ability( 'wire', 'extrachill-news-wire/get-recent-activity', array( 'limit' => 5 ) );
check( 8 === $GLOBALS['calls'] && extrachill_blog_is_activity_projection( $recovered ), 'Expired negative cache refetches.' );
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
check( 9 === $GLOBALS['calls'], 'Homepage counts skip repeated REST dispatch.' );
echo "cross-site cache smoke passed\n";
