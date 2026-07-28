<?php

use PHPUnit\Framework\TestCase;

final class ArtistSubscriberConsentTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array(
			'current_blog_id' => 4,
			'blog_stack'      => array(),
		);
	}

	public function test_registers_exact_descriptor_and_authorization_boundary(): void {
		$entities = extrachill_artist_register_email_sharing_identity( array() );

		$this->assertSame(
			array(
				'taxonomy'                           => 'artist',
				'uses_notification_email_preference' => false,
			),
			$entities['artist-email-sharing']
		);
		$this->assertTrue(
			extrachill_artist_authorize_email_sharing_producer(
				false,
				'artist-platform-subscriber-list-export',
				array( 'entity_type' => 'artist-email-sharing', 'taxonomy' => 'artist', 'slug' => 'susto' ),
				'email'
			)
		);
		$this->assertFalse( extrachill_artist_authorize_email_sharing_producer( false, 'other-producer', array( 'entity_type' => 'artist-email-sharing', 'taxonomy' => 'artist' ), 'email' ) );
		$this->assertFalse( extrachill_artist_authorize_email_sharing_producer( false, 'artist-platform-subscriber-list-export', array( 'entity_type' => 'artist', 'taxonomy' => 'artist' ), 'email' ) );
		$this->assertFalse( extrachill_artist_authorize_email_sharing_producer( false, 'artist-platform-subscriber-list-export', array( 'entity_type' => 'artist-email-sharing', 'taxonomy' => 'artist' ), 'notification' ) );
	}

	public function test_mixed_sources_deduplicate_and_preserve_direct_row(): void {
		$direct = (object) array(
			'subscriber_id'    => 17,
			'subscriber_email' => ' Listener@Example.com ',
			'source'           => 'artist_subscribe_form',
			'exported'         => 0,
		);
		$canonical = (object) array(
			'subscriber_id'    => 0,
			'subscriber_email' => 'listener@example.com',
			'source'           => 'entity_subscription',
			'exported'         => 0,
		);

		$merged = extrachill_artist_merge_subscriber_sources( array( $direct ), array( $canonical ) );

		$this->assertCount( 1, $merged );
		$this->assertSame( 17, $merged[0]->subscriber_id );
		$this->assertSame( 'listener@example.com', $merged[0]->subscriber_email );
	}

	public function test_current_account_email_and_revocation_are_resolved_each_time(): void {
		$this->bind_artist( 42, 9, 'futurebirds' );
		$GLOBALS['ec_test']['users'][7] = (object) array(
			'ID'         => 7,
			'user_login' => 'listener',
			'user_email' => 'first@example.com',
		);
		$GLOBALS['ec_test']['entity_subscription_recipients']['futurebirds'] = array( 7 );

		$first = extrachill_artist_get_canonical_email_subscribers( 42 );
		$GLOBALS['ec_test']['users'][7]->user_email = 'current@example.com';
		$changed = extrachill_artist_get_canonical_email_subscribers( 42 );
		$GLOBALS['ec_test']['entity_subscription_recipients']['futurebirds'] = array();
		$revoked = extrachill_artist_get_canonical_email_subscribers( 42 );

		$this->assertSame( 'first@example.com', $first[0]->subscriber_email );
		$this->assertSame( 'current@example.com', $changed[0]->subscriber_email );
		$this->assertSame( array(), $revoked );
		$this->assertSame( 'artist-email-sharing', $GLOBALS['ec_test']['recipient_resolution_calls'][0]['entity_type'] );
	}

	public function test_anonymous_direct_rows_remain_independent(): void {
		$direct = (object) array(
			'subscriber_id'    => 23,
			'user_id'          => null,
			'subscriber_email' => 'anonymous@example.com',
			'source'           => 'artist_subscribe_form',
			'exported'         => 0,
		);

		$this->assertSame( array( $direct ), extrachill_artist_merge_subscriber_sources( array( $direct ), array() ) );
	}

	public function test_list_handler_rejects_unauthorized_artist_before_reading(): void {
		$GLOBALS['ec_test']['current_user_id'] = 7;

		$result = extrachill_artist_platform_ability_artist_list_subscribers( array( 'id' => 42 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_access_denied', $result->get_error_code() );
	}

	public function test_canonical_rows_have_no_local_export_bookkeeping_id(): void {
		$this->bind_artist( 42, 9, 'susto' );
		$GLOBALS['ec_test']['entity_subscription_recipients']['susto'] = array( 7 );

		$subscribers = extrachill_artist_get_canonical_email_subscribers( 42 );
		$direct      = (object) array( 'subscriber_id' => 31, 'exported' => 0 );

		$this->assertSame( 0, $subscribers[0]->subscriber_id );
		$this->assertSame( 'entity_subscription', $subscribers[0]->source );
		$this->assertSame( array( 31 ), extrachill_artist_subscriber_ids_to_mark_exported( array( $direct, $subscribers[0] ), false ) );
		$this->assertSame( array(), extrachill_artist_subscriber_ids_to_mark_exported( array( $direct, $subscribers[0] ), true ) );
	}

	private function bind_artist( int $profile_id, int $term_id, string $slug ): void {
		$GLOBALS['ec_test']['blogs'][4]['posts'][ $profile_id ] = (object) array(
			'ID'          => $profile_id,
			'post_type'   => 'artist_profile',
			'post_status' => 'publish',
			'post_name'   => $slug,
			'post_title'  => ucfirst( $slug ),
		);
		$GLOBALS['ec_test']['blogs'][4]['post_meta'][ $profile_id ]['_artist_term_id'] = $term_id;
		$term           = new WP_Term();
		$term->term_id  = $term_id;
		$term->taxonomy = 'artist';
		$term->slug     = $slug;
		$term->name     = ucfirst( $slug );
		$GLOBALS['ec_test']['blogs'][1]['terms'][ $term_id ] = $term;
		$GLOBALS['ec_test']['blogs'][1]['term_meta'][ $term_id ]['_artist_profile_id'] = $profile_id;
	}
}
