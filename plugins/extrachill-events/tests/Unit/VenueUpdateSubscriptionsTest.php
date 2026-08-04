<?php
/**
 * Tests for explicit private venue update subscriptions.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture shares WordPress test doubles with the festival notification tests.

require_once dirname( __DIR__ ) . '/Support/venue-update-subscription-stubs.php';
require_once dirname( __DIR__, 2 ) . '/inc/core/venue-update-subscriptions.php';
require_once dirname( __DIR__, 2 ) . '/inc/core/venue-email-sharing.php';

/** Verify archive consent UI and first-publication delivery behavior. */
final class VenueUpdateSubscriptionsTest extends WP_UnitTestCase {

	/** Prepare venue taxonomy and notification fixtures. */
	protected function setUp(): void {
		parent::setUp();
		register_post_type( DATA_MACHINE_EVENTS_POST_TYPE, array( 'public' => true ) );
		register_taxonomy( 'venue', DATA_MACHINE_EVENTS_POST_TYPE );
		$GLOBALS['festival_notification_terms']            = array( 'venue' => array() );
		$GLOBALS['festival_notification_recipients']       = array();
		$GLOBALS['festival_notification_resolutions']      = array();
		$GLOBALS['festival_notification_calls']            = array();
		$GLOBALS['festival_notification_meta']             = array();
		$GLOBALS['festival_notification_delivery_results'] = array();
		$GLOBALS['festival_notification_claim_failure']    = false;
	}

	/** Create a draft canonical event with configured venue terms. */
	private function event_post(): WP_Post {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::factory()->user->create(),
				'post_type'   => DATA_MACHINE_EVENTS_POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'The Big Show',
			)
		);
		wp_set_object_terms( $post_id, $GLOBALS['festival_notification_terms']['venue'], 'venue' );
		return get_post( $post_id );
	}

	/** Navigate the test request to one canonical venue archive. */
	private function venue_archive(): WP_Term {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'venue',
				'name'     => 'The Royal American',
				'slug'     => 'the-royal-american',
			)
		);
		$term    = get_term( $term_id, 'venue' );
		$this->go_to( get_term_link( $term ) );
		return $term;
	}

	/** The producer may resolve only venue notification recipients. */
	public function test_authorizes_only_exact_venue_notification_contract(): void {
		$venue  = array(
			'entity_type' => 'venue',
			'taxonomy'    => 'venue',
		);
		$artist = array(
			'entity_type' => 'artist',
			'taxonomy'    => 'artist',
		);

		$this->assertTrue( extrachill_events_authorize_venue_update_producer( false, EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER, $venue, 'notification' ) );
		$this->assertFalse( extrachill_events_authorize_venue_update_producer( false, EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER, $venue, 'email' ) );
		$this->assertFalse( extrachill_events_authorize_venue_update_producer( false, EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER, $artist, 'notification' ) );
		$this->assertFalse( extrachill_events_authorize_venue_update_producer( false, 'untrusted', $venue, 'notification' ) );
	}

	/** Email sharing is a separate descriptor and private producer purpose. */
	public function test_registers_and_authorizes_only_exact_email_sharing_contract(): void {
		$entities = extrachill_events_register_venue_email_sharing_identity( array( 'venue' => 'venue' ) );
		$sharing  = array(
			'entity_type' => 'venue-email-sharing',
			'taxonomy'    => 'venue',
		);

		$this->assertSame( 'venue', $entities['venue-email-sharing']['taxonomy'] );
		$this->assertFalse( $entities['venue-email-sharing']['uses_notification_email_preference'] );
		$this->assertTrue( extrachill_events_authorize_venue_email_sharing_producer( false, EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER, $sharing, 'email' ) );
		$this->assertFalse( extrachill_events_authorize_venue_email_sharing_producer( false, EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER, $sharing, 'notification' ) );
		$this->assertFalse( extrachill_events_authorize_venue_email_sharing_producer( false, EXTRACHILL_EVENTS_VENUE_EMAIL_SHARING_PRODUCER, array( 'entity_type' => 'venue', 'taxonomy' => 'venue' ), 'email' ) );
	}

	/** Anonymous archives offer one compact sign-in action without mutation controls. */
	public function test_anonymous_archive_renders_sign_in_without_mutation_control(): void {
		$this->venue_archive();
		wp_set_current_user( 0 );

		ob_start();
		extrachill_events_render_venue_update_control();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'ec-action-row', $html );
		$this->assertStringContainsString( 'Sign in for venue alerts', $html );
		$this->assertStringNotContainsString( '<aside', $html );
		$this->assertStringNotContainsString( 'data-venue-update-subscription', $html );
		$this->assertStringNotContainsString( 'data-venue-email-sharing', $html );
	}

	/** Authenticated archives progressively load the exact venue identity. */
	public function test_authenticated_archive_renders_progressive_status_control(): void {
		$this->venue_archive();
		wp_set_current_user( self::factory()->user->create() );

		ob_start();
		extrachill_events_render_venue_update_control();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-venue-update-subscription', $html );
		$this->assertStringContainsString( 'Loading alerts...', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'data-slug="the-royal-american"', $html );
	}

	/** Archive preferences compose two compact actions without a standalone card. */
	public function test_archive_composes_independent_controls_in_one_action_row(): void {
		$this->venue_archive();
		wp_set_current_user( self::factory()->user->create() );

		ob_start();
		extrachill_events_render_venue_update_control();
		$html = ob_get_clean();

		$this->assertSame( 0, substr_count( $html, '<aside' ) );
		$this->assertStringContainsString( 'ec-action-row', $html );
		$this->assertStringContainsString( 'data-venue-preferences', $html );
		$this->assertStringContainsString( 'data-venue-update-subscription', $html );
		$this->assertStringContainsString( 'data-venue-email-sharing', $html );
		$this->assertStringContainsString( 'Loading email sharing...', $html );
	}

	/** First publication deduplicates subscribers across all assigned venues. */
	public function test_first_publication_notifies_unique_subscribers_across_venues(): void {
		$GLOBALS['festival_notification_terms']['venue'] = array( 'the-royal-american', 'music-farm' );
		$GLOBALS['festival_notification_recipients']     = array(
			'the-royal-american' => array( 7, 9 ),
			'music-farm'         => array( 9, 11 ),
		);
		$post = $this->event_post();

		extrachill_events_notify_venue_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 2, $GLOBALS['festival_notification_resolutions'] );
		$this->assertEqualsCanonicalizing( array( 7, 9, 11 ), $GLOBALS['festival_notification_calls'][0]['user_ids'] );
		$this->assertSame( 'venue_event_published', $GLOBALS['festival_notification_calls'][0]['data']['type'] );
		$this->assertSame( 'New venue event: The Big Show', $GLOBALS['festival_notification_calls'][0]['data']['title'] );
		$this->assertSame( EXTRACHILL_EVENTS_VENUE_UPDATE_PRODUCER, $GLOBALS['festival_notification_calls'][0]['data']['producer'] );
		$this->assertSame( 'event:' . $post->ID, $GLOBALS['festival_notification_calls'][0]['data']['idempotency_key'] );
	}

	/** Published updates and repeated hooks cannot duplicate delivery. */
	public function test_published_updates_and_repeated_hooks_do_not_duplicate_delivery(): void {
		$GLOBALS['festival_notification_terms']['venue']                   = array( 'the-royal-american' );
		$GLOBALS['festival_notification_recipients']['the-royal-american'] = array( 7 );
		$post = $this->event_post();

		extrachill_events_notify_venue_subscribers( 'publish', 'publish', $post );
		extrachill_events_notify_venue_subscribers( 'publish', 'draft', $post );
		extrachill_events_notify_venue_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 1, $GLOBALS['festival_notification_calls'] );
		$this->assertCount( 1, $GLOBALS['festival_notification_resolutions'] );
		$this->assertNotEmpty( get_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META, true ) );
	}

	/** A failed delivery releases the claim so publication can retry. */
	public function test_failed_delivery_releases_claim_for_retry(): void {
		$GLOBALS['festival_notification_terms']['venue']                   = array( 'the-royal-american' );
		$GLOBALS['festival_notification_recipients']['the-royal-american'] = array( 7 );
		$GLOBALS['festival_notification_delivery_results']                 = array( 0, 1 );
		$post = $this->event_post();

		extrachill_events_notify_venue_subscribers( 'publish', 'draft', $post );
		$this->assertSame( '', get_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META, true ) );
		extrachill_events_notify_venue_subscribers( 'publish', 'draft', $post );

		$this->assertCount( 2, $GLOBALS['festival_notification_calls'] );
		$this->assertNotEmpty( get_post_meta( $post->ID, EXTRACHILL_EVENTS_VENUE_UPDATE_SENT_META, true ) );
	}
}
