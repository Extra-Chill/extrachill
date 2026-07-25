<?php
/**
 * Tests for private artist email-sharing preferences.
 *
 * @package ExtraChill\Users
 */

class Test_Artist_Email_Consent_Abilities extends WP_UnitTestCase {
	/**
	 * Artist-site subscriber table used by the fixture.
	 *
	 * @var string
	 */
	private $table_name;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->table_name = $wpdb->prefix . 'artist_subscribers';
		add_filter( 'extrachill_users_artist_consent_blog_id', array( $this, 'use_current_blog' ) );
		$this->create_consent_table();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
		remove_filter( 'extrachill_users_artist_consent_blog_id', array( $this, 'use_current_blog' ) );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	public function use_current_blog(): int {
		return get_current_blog_id();
	}

	public function test_abilities_use_email_consent_language_and_stable_contracts(): void {
		$get    = wp_get_ability( 'extrachill/get-subscriptions' );
		$update = wp_get_ability( 'extrachill/update-subscriptions' );

		$this->assertNotNull( $get );
		$this->assertNotNull( $update );
		$this->assertDoesNotMatchRegularExpression( '/\bfollow(?:er|ers|ing|s|ed)?\b/i', $get->get_description() );
		$this->assertDoesNotMatchRegularExpression( '/\bfollow(?:er|ers|ing|s|ed)?\b/i', $update->get_description() );
		$this->assertContains( 'consented_artists', $update->get_input_schema()['required'] );
	}

	public function test_get_exposes_canonical_consent_field_with_legacy_alias(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_get_subscriptions();

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertArrayHasKey( 'artist_email_consents', $result );
		$this->assertArrayHasKey( 'followed_artists', $result );
		$this->assertSame( $result['artist_email_consents'], $result['followed_artists'] );
		$this->assertArrayNotHasKey( 'subscriber_count', $result );
	}

	public function test_multiple_users_can_share_email_with_the_same_artist(): void {
		$first_user_id = self::factory()->user->create(
			array(
				'user_login' => 'first-consent-user',
				'user_email' => 'first-consent@example.com',
			)
		);
		$second_user_id = self::factory()->user->create(
			array(
				'user_login' => 'second-consent-user',
				'user_email' => 'second-consent@example.com',
			)
		);

		wp_set_current_user( $first_user_id );
		$first_result = extrachill_users_ability_update_subscriptions(
			array( 'consented_artists' => array( 42, 42 ) )
		);

		wp_set_current_user( $second_user_id );
		$second_result = extrachill_users_ability_update_subscriptions(
			array( 'consented_artists' => array( 42 ) )
		);

		$this->assertIsArray( $first_result );
		$this->assertIsArray( $second_result );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT user_id, artist_profile_id, subscriber_email, username, source FROM {$this->table_name} ORDER BY user_id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
			ARRAY_A
		);

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 42, 42 ), array_map( 'intval', array_column( $rows, 'artist_profile_id' ) ) );
		$this->assertSame( array( 'first-consent@example.com', 'second-consent@example.com' ), array_column( $rows, 'subscriber_email' ) );
		$this->assertSame( array( 'first-consent-user', 'second-consent-user' ), array_column( $rows, 'username' ) );
		$this->assertSame( array( 'platform_follow_consent', 'platform_follow_consent' ), array_column( $rows, 'source' ) );

		wp_set_current_user( $first_user_id );
		$preferences = extrachill_users_ability_get_subscriptions();
		$this->assertCount( 1, $preferences['artist_email_consents'] );
		$this->assertSame( 42, $preferences['artist_email_consents'][0]['artist_id'] );
	}

	public function test_unrelated_artist_subscription_rows_are_preserved(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'mixed-subscription-user',
				'user_email' => 'mixed-subscription@example.com',
			)
		);

		global $wpdb;
		$wpdb->insert(
			$this->table_name,
			array(
				'user_id'           => $user_id,
				'artist_profile_id' => 42,
				'subscriber_email'  => 'mixed-subscription@example.com',
				'username'          => 'mixed-subscription-user',
				'source'            => 'artist_subscribe_form',
				'subscribed_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		wp_set_current_user( $user_id );
		$result = extrachill_users_ability_update_subscriptions( array( 'consented_artists' => array() ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE source = 'artist_subscribe_form'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
	}

	public function test_insert_failure_is_returned_instead_of_reporting_success(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'failed-consent@example.com',
			)
		);

		global $wpdb;
		$wpdb->insert(
			$this->table_name,
			array(
				'user_id'           => $user_id,
				'artist_profile_id' => 42,
				'subscriber_email'  => 'failed-consent@example.com',
				'username'          => 'existing-form-subscriber',
				'source'            => 'artist_subscribe_form',
				'subscribed_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_subscriptions( array( 'consented_artists' => array( 42 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'artist_email_consent_insert_failed', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	public function test_account_without_email_cannot_grant_artist_email_access(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => '',
			)
		);
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_subscriptions( array( 'consented_artists' => array( 42 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'user_email_missing', $result->get_error_code() );
	}

	private function create_consent_table(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
		$created = $wpdb->query(
			"CREATE TABLE {$this->table_name} (
				subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT(20) UNSIGNED NULL,
				artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
				subscriber_email VARCHAR(255) NOT NULL,
				username VARCHAR(60) NULL DEFAULT NULL,
				source VARCHAR(50) NOT NULL DEFAULT 'artist_subscribe_form',
				subscribed_at DATETIME NOT NULL,
				exported TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (subscriber_id),
				UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
				KEY user_artist_source (user_id, artist_profile_id, source)
			)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture schema uses the trusted WordPress prefix.

		$this->assertNotFalse( $created, $wpdb->last_error );
	}
}
