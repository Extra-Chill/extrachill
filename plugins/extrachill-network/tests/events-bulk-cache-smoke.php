<?php
/**
 * Standalone cold bulk-cache regression test.
 *
 * @package ExtraChillNetwork
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['blog'] = 1;
$GLOBALS['transients'] = array();
$GLOBALS['fetches'] = 0;
$GLOBALS['status'] = 200;
$GLOBALS['rows'] = array(
	array( 'slug' => 'first', 'term_id' => 10, 'count' => 3, 'url' => 'https://events.test/first' ),
	array( 'slug' => 'second', 'term_id' => 11, 'count' => 5, 'url' => 'https://events.test/second' ),
);
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function add_action() {}
function ec_get_blog_id( $site ) { return 7; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
function get_transient( $key ) { return $GLOBALS['transients'][ $GLOBALS['blog'] ][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['transients'][ $GLOBALS['blog'] ][ $key ] = $value;
	$GLOBALS['ttls'][ $key ] = $ttl;
}
function apply_filters( $hook, $value ) { return $value; }
class WP_REST_Request {
	public $query;
	public function __construct( $method, $route ) {}
	public function set_query_params( $query ) { $this->query = $query; }
}
function rest_do_request( $request ) {
	check( array( 'taxonomy' => $request->query['taxonomy'] ) === $request->query, 'Bulk request must not include slug or limit.' );
	++$GLOBALS['fetches'];
	return new class() {
		public function is_error() { return 200 !== $GLOBALS['status']; }
		public function as_error() { return $this; }
		public function get_error_data() { return array( 'status' => $GLOBALS['status'] ); }
		public function get_data() { return $GLOBALS['rows']; }
	};
}
require dirname( __DIR__ ) . '/inc/cross-site-links/taxonomy-links.php';
check( 3 === extrachill_get_events_upcoming_count_via_api( 'first', 'artist' )['count'], 'Cold first term resolved.' );
check( 5 === extrachill_get_events_upcoming_count_via_api( 'second', 'artist' )['count'], 'Second term resolved from bulk.' );
check( null === extrachill_get_events_upcoming_count_via_api( 'missing', 'artist' ), 'Missing term is a warm negative.' );
check( 1 === $GLOBALS['fetches'], 'Only one bulk fetch per taxonomy.' );
check( $GLOBALS['rows'] === $GLOBALS['transients'][7]['ec_upcoming_counts_artist'], 'Warmer shape and events namespace retained.' );
check( 21600 === $GLOBALS['ttls']['ec_upcoming_counts_artist'], 'Warmer TTL retained.' );
check( 1 === get_current_blog_id(), 'Origin blog restored.' );
$GLOBALS['rows'] = array();
extrachill_get_events_upcoming_count_via_api( 'missing', 'venue' );
extrachill_get_events_upcoming_count_via_api( 'other', 'venue' );
check( 2 === $GLOBALS['fetches'], 'Empty taxonomy remains warm.' );
$GLOBALS['status'] = 429;
extrachill_get_events_upcoming_count_via_api( 'first', 'festival' );
extrachill_get_events_upcoming_count_via_api( 'second', 'festival' );
check( 3 === $GLOBALS['fetches'], 'Throttle backoff prevents another fetch.' );
check( 120 === $GLOBALS['ttls']['ec_events_loopback_backoff'], 'Backoff TTL retained.' );
require dirname( __DIR__ ) . '/inc/NetworkStats/MetricProvider.php';
require dirname( __DIR__ ) . '/inc/NetworkStats/AbstractMetricProvider.php';
require dirname( __DIR__ ) . '/inc/NetworkStats/Providers/CommunityStatsProvider.php';
function add_filter() {}
function remove_filter() {}
function is_wp_error( $value ) { return false; }
function ec_cross_site_rest_request( $site, $method, $route ) {
	++$GLOBALS['stats_fetches'];
	return array( 'users' => 42 );
}
$GLOBALS['stats_fetches'] = 0;
$provider = new class( 'members', 'Members', 600 ) extends \ExtraChillNetwork\NetworkStats\Providers\CommunityStatsProvider {
	protected function field(): string { return 'users'; }
};
check( 42 === $provider->value(), 'Community stats resolve.' );
$provider::reset_payload();
check( 42 === $provider->value(), 'Community stats survive per-request memo reset.' );
check( 1 === $GLOBALS['stats_fetches'], 'Community transient skips cross-site request.' );
check( 600 === $GLOBALS['ttls']['ec_community_stats_7'], 'Community stats TTL is ten minutes.' );
echo "events bulk and community cache smoke passed\n";
