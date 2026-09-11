<?php

require_once __DIR__ . '/support/base-test-case.php';

final class ArtistDirectSubscriberTest extends EC_Artist_Platform_TestCase {
	private $owner_id;
	private $profile_id;

	protected function setUp(): void {
		parent::setUp();

		$this->owner_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->profile_id = $this->create_artist_profile( 'Futurebirds' );
		$this->create_artist_membership( $this->owner_id, $this->profile_id );
		wp_set_current_user( $this->owner_id );
	}

	/**
	 * Ensure the subscriber table exists on the artist blog and enter its context.
	 */
	private function enter_artist_context(): void {
		switch_to_blog( $this->artist_blog_id() );
		extrachill_artist_create_subscribers_table();
	}

	private function subscribe( string $email ) {
		$this->enter_artist_context();
		try {
			return extrachill_artist_platform_ability_artist_subscribe(
				array(
					'id'    => $this->profile_id,
					'email' => $email,
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	public function test_anonymous_direct_submission_storage_list_and_export_work_without_recipient_resolver(): void {
		$subscribed = $this->subscribe( 'listener@example.com' );

		$this->assertIsArray( $subscribed );
		$this->assertSame( 'Thank you for subscribing!', $subscribed['message'] );

		$duplicate = $this->subscribe( 'listener@example.com' );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'already_subscribed', $duplicate->get_error_code() );

		$this->enter_artist_context();
		$list = extrachill_artist_platform_ability_artist_list_subscribers( array( 'id' => $this->profile_id ) );
		restore_current_blog();

		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 'listener@example.com', $list['subscribers'][0]->subscriber_email );

		$this->enter_artist_context();
		$export = extrachill_artist_platform_ability_artist_export_subscribers( array( 'id' => $this->profile_id ) );
		restore_current_blog();

		$this->assertSame( 1, $export['total'] );
		$this->assertSame( 1, $export['marked_count'] );
		$this->assertSame( 'listener@example.com', $export['subscribers'][0]['email'] );
	}

	public function test_direct_reader_filters_orders_paginates_and_excludes_historical_follow_consent(): void {
		$this->subscribe( 'older@example.com' );
		$this->subscribe( 'newer@example.com' );

		// A historical follow-consent row must never surface in exports.
		global $wpdb;
		$this->enter_artist_context();
		$wpdb->insert(
			$wpdb->prefix . 'artist_subscribers',
			array(
				'artist_profile_id' => $this->profile_id,
				'subscriber_email'  => 'historical@example.com',
				'username'          => 'historical',
				'source'            => 'platform_follow_consent',
				'subscribed_at'     => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			)
		);
		// Deterministic ordering: the "older" row predates the "newer" one.
		$wpdb->update( $wpdb->prefix . 'artist_subscribers', array( 'subscribed_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'subscriber_email' => 'older@example.com' ) );
		$wpdb->update( $wpdb->prefix . 'artist_subscribers', array( 'subscribed_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'subscriber_email' => 'newer@example.com' ) );

		$subscribers = extrachill_artist_get_artist_subscribers(
			$this->profile_id,
			array(
				'exported' => 0,
				'limit'    => 1,
				'offset'   => 1,
			)
		);
		restore_current_blog();

		$this->assertCount( 1, $subscribers );
		$this->assertSame( 'older@example.com', $subscribers[0]->subscriber_email );
	}

	public function test_list_handler_rejects_unauthorized_artist_before_reading(): void {
		wp_set_current_user( $this->owner_id );
		// A profile the user does not own.
		$other_profile_id = $this->create_artist_profile( 'Unowned Artist' );

		$this->enter_artist_context();
		$result = extrachill_artist_platform_ability_artist_list_subscribers( array( 'id' => $other_profile_id ) );
		restore_current_blog();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_access_denied', $result->get_error_code() );
	}
}
