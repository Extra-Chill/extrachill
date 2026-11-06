<?php
/** Standalone contract smoke: php plugins/extrachill-events/tests/link-page-upcoming-shows-smoke.php */
define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error {
	public function __construct( public $code, public $message ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$GLOBALS['hooks'] = array();
$GLOBALS['blog'] = 13;
$GLOBALS['stack'] = array();
$GLOBALS['term'] = 44;
$GLOBALS['response'] = array( 'upcoming' => array() );
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function ec_get_blog_id( $site ) { return array( 'artist' => 4, 'events' => 7 )[ $site ]; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function switch_to_blog( $id ) { $GLOBALS['stack'][] = $GLOBALS['blog']; $GLOBALS['blog'] = $id; }
function restore_current_blog() { $GLOBALS['blog'] = array_pop( $GLOBALS['stack'] ); }
function ec_parse_link_page_owner_reference( $ref ) { return $GLOBALS['owner']; }
function get_post_meta( $id, $key, $single ) {
	check( 4 === get_current_blog_id() && 99 === $id && '_artist_term_id' === $key, 'read owner binding on artist site' );
	return $GLOBALS['term'];
}
function extrachill_events_ability_events_by_artist( $input ) {
	$GLOBALS['input'] = $input;
	return $GLOBALS['response'];
}
function ec_get_link_page_storage_blog_id() { return 7; }
function ec_link_page_post_type() { return 'link_page'; }
function get_posts( $args ) { $GLOBALS['refreshes'][] = 'upcoming_events'; return array(); }
function wp_next_scheduled( $hook ) { return $GLOBALS['scheduled'] ?? false; }
function wp_schedule_event( $time, $interval, $hook ) { $GLOBALS['scheduled'] = array( $time, $interval, $hook ); }
function get_post_type( $id ) { return 1 === $id ? 'data_machine_events' : 'post'; }
function check( $condition, $message ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
require dirname( __DIR__, 2 ) . '/extrachill-link-pages/inc/section-sources.php';
require dirname( __DIR__ ) . '/inc/core/link-page-upcoming-shows.php';
check( in_array( 'extrachill_events_register_upcoming_shows_source', $GLOBALS['hooks']['plugins_loaded'], true ), 'deferred bootstrap' );
extrachill_events_register_upcoming_shows_source();
$source = ec_link_page_section_source_registry()->get( 'upcoming_events' );
check( 'Upcoming Shows' === $source['label'] && is_callable( $source['resolve'] ), 'real source registry registration' );
check( 'int' === $source['config_schema']['limit']['type'], 'limit schema' );
$GLOBALS['owner'] = array( 'kind' => 'post', 'subtype' => 'artist_profile', 'blog_id' => 4, 'object_id' => 99 );
$context = array( 'owner_reference' => 'artist', 'section' => array( 'source_config' => array( 'limit' => 2 ) ) );
$GLOBALS['response']['upcoming'] = array_fill( 0, 30, array( 'title' => 'Artist', 'date_display' => 'Jul 30', 'venue_name' => 'The Room', 'permalink' => 'https://events.example/show/' ) );
$links = call_user_func( $source['resolve'], $context );
check( 2 === count( $links ), 'next N shows' );
check( 'Jul 30 — The Room — Artist' === $links[0]['link_text'], 'date venue and title' );
check( 'https://events.example/show/' === $links[0]['link_url'], 'canonical event link, no copied event record' );
check( array( 'artist_term_id' => 44, 'scope' => 'upcoming', 'limit' => 2 ) === $GLOBALS['input'], 'canonical artist upcoming adapter' );
check( 13 === get_current_blog_id() && array() === $GLOBALS['stack'], 'context restored' );
$context['section']['source_config']['limit'] = 1000;
check( 25 === count( extrachill_events_resolve_upcoming_shows( $context ) ), 'bounded limit' );
$context['section']['source_config']['limit'] = -3;
check( 1 === count( extrachill_events_resolve_upcoming_shows( $context ) ), 'positive limit' );
unset( $context['section']['source_config']['limit'] );
check( 5 === count( extrachill_events_resolve_upcoming_shows( $context ) ), 'default limit' );
$GLOBALS['response'] = array( 'upcoming' => array() );
check( array() === extrachill_events_resolve_upcoming_shows( $context ), 'empty upcoming clears snapshot' );
$error = new WP_Error( 'adapter_failed', 'offline' );
$GLOBALS['response'] = $error;
check( $error === extrachill_events_resolve_upcoming_shows( $context ), 'dependency errors preserve snapshot through registry contract' );
$GLOBALS['response'] = array();
check( is_wp_error( extrachill_events_resolve_upcoming_shows( $context ) ), 'malformed response fails closed' );
$GLOBALS['response'] = array( 'upcoming' => array( array( 'title' => 'Broken' ) ) );
check( is_wp_error( extrachill_events_resolve_upcoming_shows( $context ) ), 'malformed event fails closed' );
$GLOBALS['term'] = 0;
check( is_wp_error( extrachill_events_resolve_upcoming_shows( $context ) ) && 13 === get_current_blog_id(), 'unbound owner and restoration' );
$GLOBALS['owner']['subtype'] = 'venue';
check( is_wp_error( extrachill_events_resolve_upcoming_shows( $context ) ), 'unsupported owner rejected' );
extrachill_events_schedule_upcoming_shows_refresh();
check( empty( $GLOBALS['scheduled'] ), 'no cron on storage site' );
$GLOBALS['blog'] = 7;
extrachill_events_schedule_upcoming_shows_refresh();
check( 'hourly' === $GLOBALS['scheduled'][1], 'passing shows refreshed hourly' );
check( isset( $GLOBALS['hooks']['save_post_data_machine_events'], $GLOBALS['hooks']['deleted_post'], $GLOBALS['hooks']['set_object_terms'], $GLOBALS['hooks']['updated_post_meta'] ), 'event lifecycle hooks' );
extrachill_events_upcoming_shows_meta_changed( 1, 2 );
check( empty( $GLOBALS['hooks']['shutdown'] ), 'unrelated posts ignored' );
extrachill_events_upcoming_shows_deleted( 1, (object) array( 'post_type' => 'data_machine_events' ) );
extrachill_events_upcoming_shows_terms_changed( 1, array(), array(), 'artist' );
extrachill_events_upcoming_shows_meta_changed( 1, 1 );
check( 1 === count( $GLOBALS['hooks']['shutdown'] ), 'coalesced refresh after final event state' );
call_user_func( $GLOBALS['hooks']['shutdown'][0] );
check( array( 'upcoming_events' ) === $GLOBALS['refreshes'], 'all opted-in sections including former artists refreshed' );
echo "Upcoming shows smoke passed\n";
