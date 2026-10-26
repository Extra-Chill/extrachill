<?php
/** Grade persisted browser effects BEFORE exercising independent API edge cases. */
$sites = get_sites( array( 'domain' => 'events.extrachill.com', 'number' => 1 ) );
if ( ! $sites ) {
	throw new RuntimeException( 'Events site missing' );
}
switch_to_blog( (int) $sites[0]->blog_id );
require_once WP_PLUGIN_DIR . '/data-machine-events/data-machine-events.php';
require_once WP_PLUGIN_DIR . '/extrachill-events/extrachill-events.php';
use DataMachineEvents\Core\EventRatings;
\DataMachineEvents\Core\Event_Post_Type::register();
EventRatings::register();
require_once WP_PLUGIN_DIR . '/extrachill-events/inc/core/concert-tracking-integration.php';
$f = get_option( 'ec_rig_show_ratings' );
if ( ! is_array( $f ) || ! isset( $f['past'], $f['inline'], $f['future'] ) ) {
	throw new RuntimeException( 'Show ratings fixture missing' );
}
$cases = array();
function ratings_case( $id, $pass, $evidence ) {
	global $cases;
	$cases[] = array( 'id' => $id, 'status' => $pass ? 'pass' : 'finding', 'evidence' => $evidence );
}
foreach ( array( 'past' => 4, 'inline' => 3 ) as $key => $expected ) {
	$review = EventRatings::get_user_review( 751, $f[ $key ] );
	$actual = $review ? EventRatings::get_comment_rating( $review->comment_ID ) : 0;
	ratings_case( 'browser-persistence-' . $key, $actual === $expected, array( 'expected' => $expected, 'actual' => $actual ) );
}
wp_set_current_user( 752 );
$gate = EventRatings::rate( 752, $f['past'], 5 );
ratings_case( 'attendee-only-api-gate', is_wp_error( $gate ) && 'rating_not_allowed' === $gate->get_error_code(), is_wp_error( $gate ) ? $gate->get_error_code() : $gate );
wp_set_current_user( 751 );
// Mark even the future event: this isolates the timing gate from attendance.
$marked = ec_users_mark_event( 751, $f['future'], get_current_blog_id() );
if ( is_wp_error( $marked ) ) {
	throw new RuntimeException( $marked->get_error_message() );
}
$future = EventRatings::rate( 751, $f['future'], 5 );
ratings_case( 'future-rejected', is_wp_error( $future ) && 'event_not_rateable' === $future->get_error_code(), is_wp_error( $future ) ? $future->get_error_code() : $future );
$first = EventRatings::rate( 751, $f['inline'], 3 );
$second = EventRatings::rate( 751, $f['inline'], 3 );
$comments = get_comments( array( 'post_id' => $f['inline'], 'user_id' => 751, 'type' => EventRatings::COMMENT_TYPE, 'status' => 'all' ) );
ratings_case( 'sequential-double-submit', ! is_wp_error( $first ) && ! is_wp_error( $second ) && count( $comments ) === 1, array( 'count' => count( $comments ), 'first' => $first, 'second' => $second ) );
$changed = EventRatings::rate( 751, $f['inline'], 5 );
$review = EventRatings::get_user_review( 751, $f['inline'] );
ratings_case( 'change-rating', ! is_wp_error( $changed ) && $review && EventRatings::get_comment_rating( $review->comment_ID ) === 5, $changed );
wp_set_current_user( 0 );
$anon = EventRatings::rate( 0, $f['inline'], 4 );
ratings_case( 'anonymous-primitive-rejected', is_wp_error( $anon ) && 'rating_not_allowed' === $anon->get_error_code(), is_wp_error( $anon ) ? $anon->get_error_code() : $anon );
$result = array( 'schema' => 'extrachill-network/journey-result/show-ratings/v1', 'cases' => $cases, 'note' => 'Browser assertions separately grade in-place unlock, public controls, count update, and REST permission. A failed browser step is never overridden by these server checks.' );
restore_current_blog();
printf( "EXTRACHILL_JOURNEY_RESULT:%s\n", base64_encode( wp_json_encode( $result ) ) );
if ( in_array( 'finding', array_column( $cases, 'status' ), true ) ) {
	throw new RuntimeException( 'Show ratings functional findings; see EXTRACHILL_JOURNEY_RESULT' );
}
