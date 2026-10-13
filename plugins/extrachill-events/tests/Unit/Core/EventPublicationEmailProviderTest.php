<?php
/** Tests for the public event publication email integration. */

use ExtraChillEvents\Providers\EventPublicationEmailProvider;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public function __construct( int $id ) { $this->ID = $id; }
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID;
		public string $user_login;
		public function __construct( int $id ) { $this->ID = $id; $this->user_login = 'member' . $id; }
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['event_mail_post_meta'][ $id ][ $key ] ?? ''; }
	function get_user_meta( $id, $key, $single = false ) { return $GLOBALS['event_mail_user_meta'][ $id ][ $key ] ?? ''; }
	function get_userdata( $id ) { return $GLOBALS['event_mail_users'][ $id ] ?? false; }
	function get_the_title( $post ) { return $GLOBALS['event_mail_titles'][ $post->ID ] ?? ''; }
	function get_permalink( $post ) { return 'https://events.example/event/' . $post->ID . '/'; }
	function get_password_reset_key( $user ) { return 'one-time-key'; }
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return (string) $url; }
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { return 'https://events.example' . $path; }
}
if ( ! function_exists( 'network_site_url' ) ) {
	function network_site_url( $path = '' ) { return 'https://network.example/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
}

require_once dirname( __DIR__, 3 ) . '/inc/Providers/EventPublicationEmailProvider.php';

/** @covers \ExtraChillEvents\Providers\EventPublicationEmailProvider */
final class EventPublicationEmailProviderTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['event_mail_post_meta'] = array();
		$GLOBALS['event_mail_user_meta'] = array();
		$GLOBALS['event_mail_users'] = array();
		$GLOBALS['event_mail_titles'] = array();
	}

	public function test_submitted_event_uses_branded_template_and_actionable_context(): void {
		$post_id = 101;
		$post = new WP_Post( $post_id );
		$GLOBALS['event_mail_titles'][ $post_id ] = 'Community Show';
		$GLOBALS['event_mail_post_meta'][ $post_id ] = array(
			'_datamachine_submitted_by' => 12,
			'_datamachine_submitter_name' => 'Alex',
			'_datamachine_submitter_email' => 'alex@example.test',
		);

		$this->assertSame( 'extrachill/branded', EventPublicationEmailProvider::template( 'default', $post ) );
		$context = EventPublicationEmailProvider::context( array(), $post, 'alex@example.test', 'extrachill/branded' );

		$this->assertStringContainsString( esc_url( get_permalink( $post ) ), $context['body_html'] );
		$this->assertStringContainsString( 'Share your event with your fans and audience', $context['body_html'] );
		$this->assertStringContainsString( 'Create or claim your artist profile', $context['body_html'] );
		$this->assertStringContainsString( 'Submit your next show', $context['body_html'] );
		$this->assertStringContainsString( 'Join the Extra Chill community', $context['body_html'] );
		$this->assertSame( 'Alex', $context['recipient_name'] );
	}

	public function test_claim_copy_is_only_included_for_unclaimed_submitter(): void {
		$user_id = 202;
		$GLOBALS['event_mail_users'][ $user_id ] = new WP_User( $user_id );
		$GLOBALS['event_mail_post_meta'][303] = array( '_datamachine_submitted_by' => $user_id );
		$post = new WP_Post( 303 );

		$claimed_context = EventPublicationEmailProvider::context( array(), $post, 'person@example.test', 'default' );
		$this->assertStringNotContainsString( 'Set or reset your password', $claimed_context['body_html'] );

		$GLOBALS['event_mail_user_meta'][ $user_id ]['ec_unclaimed'] = '1';
		$unclaimed_context = EventPublicationEmailProvider::context( array(), $post, 'person@example.test', 'default' );
		$this->assertStringContainsString( 'Set or reset your password to log in and manage your events', $unclaimed_context['body_html'] );
		$this->assertStringContainsString( 'Set my password', $unclaimed_context['body_html'] );
		$this->assertStringNotContainsString( 'already existed', $unclaimed_context['body_html'] );
	}

	public function test_posts_without_submitter_metadata_are_left_untouched(): void {
		$post = new WP_Post( 404 );
		$original = array( 'body_html' => '<p>Existing content</p>' );

		$this->assertSame( 'default', EventPublicationEmailProvider::template( 'default', $post ) );
		$this->assertSame( $original, EventPublicationEmailProvider::context( $original, $post, '', 'default' ) );
	}
}
