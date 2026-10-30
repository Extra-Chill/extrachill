<?php
/** Isolated request fixture for location SEO count caching. */

namespace DataMachineEvents\Blocks\Calendar\Cache {
	/** Public generation API double. */
	class CalendarCache {
		public static function get_generation(): string {
			return $GLOBALS['request']['generation'] ?? 'generation-one';
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'HOUR_IN_SECONDS', 3600 );
	$GLOBALS['request'] = json_decode( $argv[1], true );
	$cache             = $GLOBALS['request']['cache'] ?? array();
	$queries           = array();
	$writes            = array();
	function add_filter( ...$args ) {}
	function get_current_blog_id() { return 7; }
	function get_transient( $key ) {
		return $GLOBALS['cache'][ $key ] ?? false;
	}
	function set_transient( $key, $value, $ttl ) {
		$GLOBALS['cache'][ $key ] = $value;
		$GLOBALS['writes'][]     = array( $key, $value, $ttl );
		return true;
	}
	function data_machine_events_query_events( $args ) {
		$GLOBALS['queries'][] = $args;
		return array( 'total' => (string) ( $GLOBALS['request']['total'] ?? 45 ) );
	}
	function extrachill_events_get_location_venues( $term_id ) {
		return array( array( 'name' => 'Venue One' ), array( 'name' => 'Venue Two' ) );
	}
	require dirname( __DIR__, 2 ) . '/inc/core/location-seo.php';
	$counts = array();
	foreach ( $GLOBALS['request']['terms'] ?? array( 42, 42, 42, 42 ) as $term_id ) {
		$counts[] = extrachill_events_get_upcoming_event_count( $term_id );
	}
	$description = extrachill_events_build_location_description( 'Charleston', 42 );
	echo json_encode( compact( 'counts', 'cache', 'queries', 'writes', 'description' ) );
}
