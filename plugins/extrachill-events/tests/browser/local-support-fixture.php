<?php
/**
 * Deterministic rendered fixture for Local Support browser evidence.
 *
 * @package ExtraChillEvents\Tests
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );

// phpcs:disable -- Standalone deterministic fixture intentionally provides minimal WordPress stubs and direct assets.

function add_filter() {}
function add_action() {}
function __( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return (string) $value; }
function wp_generate_uuid4() { return '123e4567-e89b-42d3-a456-426614174000'; }
function wp_nonce_field( $action, $name = '_wpnonce' ) { printf( '<input type="hidden" name="%s" value="nonce-%s" />', esc_attr( $name ), esc_attr( $action ) ); }
function wp_get_current_user() { return (object) array( 'display_name' => 'Artist Manager', 'user_email' => 'manager@example.com' ); }
function get_option( $key, $default = false ) { return 'date_format' === $key ? 'F j, Y' : $default; }
function mysql2date( $format, $date ) { return gmdate( $format, strtotime( $date ) ); }

require_once dirname( __DIR__, 2 ) . '/inc/core/local-support-workspace.php';

$request = array( 'id' => 15, 'status' => 'open', 'version' => 2 );
$artist  = array( 'artist_term_id' => 202, 'name' => 'Managed Artist' );
$base    = array( 'request' => $request, 'event' => array( 'title' => 'Touring Band at The Room', 'venue' => 'The Room', 'permalink' => '#' ) );
$scenario = isset( $_GET['scenario'] ) ? preg_replace( '/[^a-z-]/', '', (string) $_GET['scenario'] ) : 'artist'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only deterministic fixture.

ob_start();
if ( 'organizer' === $scenario ) {
	$interests = array();
	foreach ( array( 'First Artist', 'Second Artist', 'Third Artist' ) as $index => $name ) {
		$interests[] = array( 'id' => 20 + $index, 'request_id' => 15, 'artist_term_id' => 202 + $index, 'status' => 'shortlisted', 'version' => 2, 'contact' => null, 'artist' => array( 'name' => $name, 'genre' => 'Rock', 'local_city' => 'Charleston', 'profile_image_url' => null ) );
	}
	extrachill_events_render_local_support_organizer( array_merge( $base, array( 'role' => 'organizer', 'interests' => $interests ) ) );
} elseif ( 'artist-consented' === $scenario ) {
	extrachill_events_render_local_support_artist( array_merge( $base, array( 'role' => 'artist', 'artist' => $artist, 'eligible' => false, 'interest' => array( 'id' => 20, 'request_id' => 15, 'artist_term_id' => 202, 'status' => 'declined', 'version' => 3, 'contact' => array( 'email' => 'manager@example.com' ) ) ) ) );
} elseif ( 'organizer-index' === $scenario ) {
	extrachill_events_render_local_support_index(
		array(
			array( 'id' => 901, 'title' => 'Touring Band at The Room', 'start_datetime' => '2030-08-01 20:00:00', 'venue_term_id' => 55, 'status' => 'not_seeking', 'workspace_url' => '/local-support/?event_id=901', 'permalink' => '#' ),
			array( 'id' => 902, 'title' => 'Second Show at The Room', 'start_datetime' => '2030-08-08 20:00:00', 'venue_term_id' => 55, 'status' => 'open', 'workspace_url' => '/local-support/16/', 'permalink' => '#' ),
			array( 'id' => 903, 'title' => 'Third Show at The Room', 'start_datetime' => '2030-08-15 20:00:00', 'venue_term_id' => 55, 'status' => 'filled', 'workspace_url' => '/local-support/17/', 'permalink' => '#' ),
		)
	);
} elseif ( 'unauthorized' === $scenario ) {
	extrachill_events_render_local_support_unavailable();
} elseif ( 'conflict' === $scenario ) {
	extrachill_events_local_support_notice( 'conflict' );
} else {
	extrachill_events_render_local_support_artist( array_merge( $base, array( 'role' => 'artist', 'artist' => $artist, 'eligible' => true, 'interest' => array( 'id' => 20, 'request_id' => 15, 'artist_term_id' => 202, 'status' => 'interested', 'version' => 2, 'contact' => null ) ) ) );
}
$body = ob_get_clean();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/local-support.css"><script defer src="/assets/js/local-support.js"></script><title>Local Support Evidence</title></head><body><main><section class="ec-local-support" data-local-support-workspace><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Actual render functions escape their output. ?></section></main></body></html>
