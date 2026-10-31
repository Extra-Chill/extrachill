<?php

use ExtraChillEvents\Abilities\PendingEventSubmissionsAbility;

if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $args ) { $GLOBALS['pending_digest_registered'][ $name ] = $args; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['pending_digest_caps'] ?? array(), true ); }
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) { $GLOBALS['pending_digest_query'] = $args; return $GLOBALS['pending_digest_posts'] ?? array(); }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['pending_digest_meta'][ $id ][ $key ] ?? ''; }
	function get_the_title( $post ) { return $post->post_title; }
	function get_post_time( $format, $gmt, $post ) { return $post->post_date; }
	function wp_timezone() { return new DateTimeZone( 'UTC' ); }
	function get_edit_post_link( $id, $context ) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
	function get_option( $name ) { return $GLOBALS['pending_digest_admin_email'] ?? ''; }
	function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
	function get_bloginfo( $show ) { return 'Test'; }
	function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
	function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES ); }
	function esc_url( $value ) { return $value; }
	function esc_html__( $value ) { return $value; }
	function ec_send_email( $args ) { ++$GLOBALS['pending_digest_email_count']; return array( 'success' => true ); }
}

require_once __DIR__ . '/PendingEventSubmissionsEventDatesTableDouble.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/PendingEventSubmissionsAbility.php';

final class PendingEventSubmissionsAbilityTest extends BookingTestCase {
	public function test_queue_selection_date_math_sort_and_flags(): void {
		$today = new DateTimeImmutable( gmdate( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
		$GLOBALS['pending_digest_posts'] = array(
			(object) array( 'ID' => 1, 'post_title' => 'Later', 'post_date' => $today->modify( '-3 days' )->format( 'Y-m-d' ) ),
			(object) array( 'ID' => 2, 'post_title' => 'Past', 'post_date' => $today->modify( '-183 days' )->format( 'Y-m-d' ) ),
			(object) array( 'ID' => 3, 'post_title' => 'Soon', 'post_date' => $today->modify( '-3 days' )->format( 'Y-m-d' ) ),
			(object) array( 'ID' => 4, 'post_title' => 'No submitter', 'post_date' => $today->format( 'Y-m-d' ) ),
		);
		$GLOBALS['pending_digest_event_dates'] = array(
			1 => (object) array( 'start_datetime' => $today->modify( '+10 days' )->format( 'Y-m-d' ) . ' 20:00:00' ),
			2 => (object) array( 'start_datetime' => $today->modify( '-1 day' )->format( 'Y-m-d' ) . ' 20:00:00' ),
			3 => (object) array( 'start_datetime' => $today->modify( '+2 days' )->format( 'Y-m-d' ) . ' 20:00:00' ),
		);
		$GLOBALS['pending_digest_meta'] = array( 1 => array( '_datamachine_submitter_email' => 'one@example.test', '_datamachine_submitter_name' => 'One' ), 2 => array( '_datamachine_submitter_email' => 'two@example.test', '_datamachine_submitter_name' => 'Two' ), 3 => array( '_datamachine_submitter_email' => 'three@example.test', '_datamachine_submitter_name' => 'Three' ) );
		$rows = ( new PendingEventSubmissionsAbility() )->getPendingSubmissions();
		$this->assertSame( 'Past', $rows[0]['title'] );
		$this->assertSame( 'Soon', $rows[1]['title'] );
		$this->assertSame( 'Later', $rows[2]['title'] );
		$this->assertSame( 183, $rows[0]['days_waiting'] );
		$this->assertSame( -1, $rows[0]['days_until_event'] );
		$this->assertTrue( $rows[0]['flagged'] );
		$this->assertSame( 2, $rows[1]['days_until_event'] );
		$this->assertTrue( $rows[1]['flagged'] );
		$this->assertFalse( $rows[2]['flagged'] );
		$this->assertSame( 'EXISTS', $GLOBALS['pending_digest_query']['meta_query'][0]['compare'] );
	}

	public function test_empty_queue_does_not_send(): void {
		$GLOBALS['pending_digest_posts'] = array();
		$GLOBALS['pending_digest_email_count'] = 0;
		$GLOBALS['pending_digest_admin_email'] = 'reviewer@example.test';
		$GLOBALS['pending_digest_meta'] = array();
		$ability = new PendingEventSubmissionsAbility();
		$ability->sendDigest();
		$this->assertSame( 0, $GLOBALS['pending_digest_email_count'] );
	}

	public function test_ability_requires_reviewer_capability(): void {
		( new PendingEventSubmissionsAbility() )->register();
		$permission = $GLOBALS['pending_digest_registered']['extrachill/pending-event-submissions']['permission_callback'];

		$GLOBALS['pending_digest_caps'] = array();
		$this->assertFalse( $permission() );

		$GLOBALS['pending_digest_caps'] = array( 'edit_others_posts' );
		$this->assertTrue( $permission() );
	}
}
