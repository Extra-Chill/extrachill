<?php
/** Tests for the public event publication email integration. */

use ExtraChillEvents\Providers\EventPublicationEmailProvider;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		public string $post_type = 'post';
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

require_once __DIR__ . '/Stubs/event-publication-wp-functions.php';
require_once __DIR__ . '/Stubs/event-publication-ticket-functions.php';

require_once __DIR__ . '/Stubs/submission-notification-spy.php';
require_once dirname( __DIR__, 3 ) . '/inc/Providers/EventPublicationEmailProvider.php';

/** @covers \ExtraChillEvents\Providers\EventPublicationEmailProvider */
final class EventPublicationEmailProviderTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['event_mail_post_meta'] = array();
		$GLOBALS['event_mail_user_meta'] = array();
		$GLOBALS['event_mail_users'] = array();
		$GLOBALS['event_mail_titles'] = array();
		$GLOBALS['event_mail_managers'] = array();
		$GLOBALS['event_mail_fees'] = array(
			'ticket_percent' => 0,
			'ticket_buyer_fee_per_ticket_minor' => 100,
			'ticket_buyer_fee_flat_minor' => 0,
			'ticket_buyer_fee_percent' => 0,
		);
		\DataMachineEvents\Core\SubmissionNotification::$calls = array();
	}

	private function event_post( int $id ): WP_Post {
		$post            = new WP_Post( $id );
		$post->post_type = 'data_machine_events';
		return $post;
	}

	public function test_direct_publish_of_submitted_event_sends_publish_email(): void {
		$GLOBALS['event_mail_post_meta'][501] = array( '_datamachine_submitted_by' => 7 );
		EventPublicationEmailProvider::on_direct_publish( 'publish', 'new', $this->event_post( 501 ) );
		$this->assertSame( array( array( 'publish', 'pending', 501 ) ), \DataMachineEvents\Core\SubmissionNotification::$calls );
	}

	public function test_pending_to_publish_is_left_to_data_machine_events(): void {
		$GLOBALS['event_mail_post_meta'][502] = array( '_datamachine_submitted_by' => 7 );
		EventPublicationEmailProvider::on_direct_publish( 'publish', 'pending', $this->event_post( 502 ) );
		EventPublicationEmailProvider::on_direct_publish( 'publish', 'publish', $this->event_post( 502 ) );
		$this->assertSame( array(), \DataMachineEvents\Core\SubmissionNotification::$calls );
	}

	public function test_direct_publish_without_submitter_or_wrong_type_sends_nothing(): void {
		EventPublicationEmailProvider::on_direct_publish( 'publish', 'new', $this->event_post( 503 ) );
		$GLOBALS['event_mail_post_meta'][504] = array( '_datamachine_submitted_by' => 7 );
		$page            = new WP_Post( 504 );
		$page->post_type = 'page';
		EventPublicationEmailProvider::on_direct_publish( 'publish', 'new', $page );
		$this->assertSame( array(), \DataMachineEvents\Core\SubmissionNotification::$calls );
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

	public function test_ticket_offer_links_to_recipient_host_tools_with_campaign_and_configured_fee(): void {
		$GLOBALS['event_mail_post_meta'][601] = array( '_datamachine_submitted_by' => 12 );
		$GLOBALS['event_mail_managers'][12][601] = true;
		$GLOBALS['event_mail_fees']['ticket_buyer_fee_per_ticket_minor'] = 150;
		$context = EventPublicationEmailProvider::context( array(), $this->event_post( 601 ), 'alex@example.test', 'default' );
		$this->assertStringContainsString( 'Sell tickets for this show on Extra Chill', $context['body_html'] );
		$this->assertStringContainsString( '/601/guest-list/?ec_host_tools=1', $context['body_html'] );
		$this->assertStringContainsString( '#ec-guest-list-management', $context['body_html'] );
		$this->assertStringContainsString( 'utm_campaign=submission_email_tickets', $context['body_html'] );
		$this->assertStringContainsString( 'No platform fee for you, buyers pay a $1.5 service fee', $context['body_html'] );
		$this->assertSame( array( 'kind' => 'extrachill_ticket', 'event_id' => 601 ), $GLOBALS['event_mail_fee_context'] );
	}

	public function test_ticket_offer_falls_back_to_docs_without_management_permission(): void {
		$GLOBALS['event_mail_post_meta'][602] = array( '_datamachine_submitter_email' => 'guest@example.test' );
		$context = EventPublicationEmailProvider::context( array(), $this->event_post( 602 ), 'guest@example.test', 'default' );
		$this->assertStringContainsString( 'https://docs.example/selling-event-tickets/?', $context['body_html'] );
		$this->assertStringContainsString( 'placement=submission_email_tickets', $context['body_html'] );
	}

	public function test_ticket_offer_is_omitted_when_tickets_are_enabled(): void {
		$GLOBALS['event_mail_post_meta'][603] = array(
			'_datamachine_submitted_by' => 12,
			'_extrachill_ticket_offer' => array( 'tickets_enabled' => true ),
		);
		$context = EventPublicationEmailProvider::context( array(), $this->event_post( 603 ), 'alex@example.test', 'default' );
		$this->assertStringNotContainsString( 'Sell tickets for this show', $context['body_html'] );
	}

	public function test_affiliate_destinations_suppress_offer_but_non_affiliates_do_not(): void {
		foreach ( array( 'www.ticketmaster.com', 'www.ticketweb.com', 'dice.fm' ) as $host ) {
			$GLOBALS['event_mail_post_meta'][604] = array(
				'_datamachine_submitted_by' => 12,
				'_datamachine_ticket_url' => 'https://' . $host . '/show',
			);
			$context = EventPublicationEmailProvider::context( array(), $this->event_post( 604 ), 'alex@example.test', 'default' );
			$this->assertSame( 'dice.fm' === $host, str_contains( $context['body_html'], 'Sell tickets for this show' ) );
		}
	}

	public function test_nonstandard_fee_schedule_omits_fee_promise_not_ticket_offer(): void {
		$GLOBALS['event_mail_post_meta'][605] = array( '_datamachine_submitted_by' => 12 );
		$GLOBALS['event_mail_fees']['ticket_percent'] = 5;
		$context = EventPublicationEmailProvider::context( array(), $this->event_post( 605 ), 'alex@example.test', 'default' );
		$this->assertStringContainsString( 'Sell tickets for this show', $context['body_html'] );
		$this->assertStringNotContainsString( 'No platform fee', $context['body_html'] );
	}

	public function test_posts_without_submitter_metadata_are_left_untouched(): void {
		$post = new WP_Post( 404 );
		$original = array( 'body_html' => '<p>Existing content</p>' );

		$this->assertSame( 'default', EventPublicationEmailProvider::template( 'default', $post ) );
		$this->assertSame( $original, EventPublicationEmailProvider::context( $original, $post, '', 'default' ) );
	}
}
