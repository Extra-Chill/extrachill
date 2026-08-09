<?php
/**
 * Tests for private, network entity subscriptions.
 *
 * @package ExtraChill\Users
 */

class Test_Entity_Subscriptions extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		extrachill_users_install_entity_subscriptions_table();
		add_filter( 'extrachill_users_entity_subscription_producer_authorized', array( $this, 'authorize_test_producer' ), 10, 4 );
		add_filter( 'extrachill_users_entity_subscription_entities', array( $this, 'register_scoped_test_identities' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'extrachill_users_entity_subscription_producer_authorized', array( $this, 'authorize_test_producer' ), 10 );
		remove_filter( 'extrachill_users_entity_subscription_entities', array( $this, 'register_scoped_test_identities' ) );
		parent::tearDown();
	}

	public function authorize_test_producer( $authorized, $producer ): bool {
		return 'test-producer' === $producer;
	}

	public function register_scoped_test_identities( array $entities ): array {
		$entities['topic']               = 'topic';
		$entities['topic-announcements'] = array(
			'taxonomy'                           => 'topic',
			'uses_notification_email_preference' => false,
		);

		return $entities;
	}

	public function test_subscribe_normalizes_and_deduplicates(): void {
		$user_id = self::factory()->user->create();

		$first  = extrachill_users_subscribe_to_entity( $user_id, 'Topic', 'topic', 'Release Notes' );
		$second = extrachill_users_subscribe_to_entity( $user_id, 'topic', 'topic', 'release-notes' );

		$this->assertTrue( $first['subscribed'] );
		$this->assertSame( 'release-notes', $first['slug'] );
		$this->assertTrue( $second['subscribed'] );
		$this->assertSame( array( $user_id ), extrachill_users_entity_subscription_recipients( 'test-producer', 'topic', 'topic', 'release-notes' ) );
	}

	public function test_abilities_include_private_recipient_resolution(): void {
		$subscribe   = wp_get_ability( 'extrachill/entity-subscribe' );
		$unsubscribe = wp_get_ability( 'extrachill/entity-unsubscribe' );

		$this->assertNotNull( $subscribe );
		$this->assertNotNull( $unsubscribe );
		$this->assertNotNull( wp_get_ability( 'extrachill/entity-subscription-status' ) );
		$this->assertNotNull( wp_get_ability( 'extrachill/list-entity-subscriptions' ) );
		$this->assertNotNull( wp_get_ability( 'extrachill/resolve-entity-subscription-recipients' ) );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$result = $subscribe->execute(
			array(
				'entity_type' => 'topic',
				'taxonomy'    => 'topic',
				'slug'        => 'release-notes',
			)
		);

		$this->assertSame( 'release-notes', $result['slug'] );
		$this->assertTrue( $result['subscribed'] );
	}

	public function test_list_is_self_only_bounded_and_preserves_purpose_identity(): void {
		$user_id       = self::factory()->user->create();
		$other_user_id = self::factory()->user->create();
		extrachill_users_subscribe_to_entity( $user_id, 'topic', 'topic', 'release-notes' );
		extrachill_users_subscribe_to_entity( $user_id, 'topic-announcements', 'topic', 'release-notes' );
		extrachill_users_subscribe_to_entity( $other_user_id, 'topic', 'topic', 'roadmap' );
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_list_entity_subscriptions(
			array(
				'page'     => 1,
				'per_page' => 1,
			)
		);

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['per_page'] );
		$this->assertSame( 2, $result['total_pages'] );
		$this->assertContains( $result['subscriptions'][0]['entity_type'], array( 'topic', 'topic-announcements' ) );
	}

	public function test_status_and_unsubscribe_are_self_contained(): void {
		$user_id = self::factory()->user->create();
		extrachill_users_subscribe_to_entity( $user_id, 'topic', 'topic', 'release-notes' );

		$this->assertTrue( extrachill_users_entity_subscription_status( $user_id, 'topic', 'topic', 'release-notes' )['subscribed'] );
		$this->assertFalse( extrachill_users_unsubscribe_from_entity( $user_id, 'topic', 'topic', 'release-notes' )['subscribed'] );
		$this->assertFalse( extrachill_users_entity_subscription_status( $user_id, 'topic', 'topic', 'release-notes' )['subscribed'] );
	}

	public function test_unsubscribe_is_idempotent_when_no_row_exists(): void {
		$user_id = self::factory()->user->create();

		$first  = extrachill_users_unsubscribe_from_entity( $user_id, 'topic', 'topic', 'release-notes' );
		$second = extrachill_users_unsubscribe_from_entity( $user_id, 'topic', 'topic', 'release-notes' );

		$this->assertFalse( $first['subscribed'] );
		$this->assertFalse( $second['subscribed'] );
	}

	public function test_unsubscribe_returns_error_on_database_failure(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$table   = extrachill_users_entity_subscriptions_table_name();
		$fail    = static function ( string $query ) use ( $table ): string {
			if ( str_contains( $query, "DELETE FROM {$table}" ) ) {
				return 'INVALID ENTITY SUBSCRIPTION DELETE';
			}

			return $query;
		};

		$previous_suppression = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = extrachill_users_unsubscribe_from_entity( $user_id, 'topic', 'topic', 'release-notes' );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $previous_suppression );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'entity_subscription_delete_failed', $result->get_error_code() );
	}

	public function test_invalid_entity_pair_is_rejected(): void {
		$user_id = self::factory()->user->create();
		$result  = extrachill_users_subscribe_to_entity( $user_id, 'topic', 'category', 'release-notes' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_entity_subscription', $result->get_error_code() );
	}

	public function test_unregistered_identity_is_rejected(): void {
		$user_id = self::factory()->user->create();
		$result  = extrachill_users_subscribe_to_entity( $user_id, 'unregistered', 'unregistered', 'release-notes' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_entity_subscription', $result->get_error_code() );
	}

	public function test_recipient_resolution_requires_producer_authorization(): void {
		$user_id = self::factory()->user->create();
		extrachill_users_subscribe_to_entity( $user_id, 'topic', 'topic', 'release-notes' );

		$denied = extrachill_users_entity_subscription_recipients( 'untrusted', 'topic', 'topic', 'release-notes' );
		$this->assertWPError( $denied );
		$this->assertSame( 'entity_subscription_producer_forbidden', $denied->get_error_code() );
	}

	public function test_direct_email_recipient_resolution_respects_digest_preference(): void {
		$enabled_user  = self::factory()->user->create();
		$disabled_user = self::factory()->user->create();
		extrachill_users_subscribe_to_entity( $enabled_user, 'topic', 'topic', 'release-notes' );
		extrachill_users_subscribe_to_entity( $disabled_user, 'topic', 'topic', 'release-notes' );
		ec_users_set_notification_emails_disabled( $disabled_user, true );

		$this->assertSame( array( $enabled_user, $disabled_user ), extrachill_users_entity_subscription_recipients( 'test-producer', 'topic', 'topic', 'release-notes' ) );
		$this->assertSame( array( $enabled_user ), extrachill_users_entity_subscription_recipients( 'test-producer', 'topic', 'topic', 'release-notes', 'email' ) );
	}
}
