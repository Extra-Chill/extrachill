<?php
/**
 * Tests for private artist email-sharing preferences.
 *
 * @package ExtraChill\Users
 */

class Test_Artist_Email_Consent_Abilities extends WP_UnitTestCase {
	/** @var string */
	private $table_name;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->table_name = $wpdb->prefix . 'artist_subscribers';
		add_filter( 'extrachill_users_artist_consent_blog_id', array( $this, 'use_current_blog' ) );
		add_filter( 'extrachill_users_artist_consent_main_blog_id', array( $this, 'use_current_blog' ) );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		register_taxonomy( 'artist', 'post' );
		extrachill_users_install_entity_subscriptions_table();
		$this->create_consent_table();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
		remove_filter( 'extrachill_users_artist_consent_blog_id', array( $this, 'use_current_blog' ) );
		remove_filter( 'extrachill_users_artist_consent_main_blog_id', array( $this, 'use_current_blog' ) );
		unregister_post_type( 'artist_profile' );
		unregister_taxonomy( 'artist' );
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

	public function test_update_uses_distinct_canonical_purpose_identity(): void {
		$artist  = $this->create_bound_artist( 'phish' );
		$user_id = self::factory()->user->create( array( 'user_email' => 'listener@example.com' ) );
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_subscriptions( array( 'consented_artists' => array( $artist['profile_id'] ) ) );

		$this->assertTrue( $result['success'] );
		$this->assertTrue( extrachill_users_entity_subscription_status( $user_id, 'artist-email-sharing', 'artist', 'phish' )['subscribed'] );
		$this->assertFalse( extrachill_users_entity_subscription_status( $user_id, 'artist', 'artist', 'phish' )['subscribed'] );
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
	}

	public function test_get_enriches_canonical_rows_with_legacy_response_alias(): void {
		$artist  = $this->create_bound_artist( 'susto' );
		$user_id = self::factory()->user->create( array( 'user_email' => 'listener@example.com' ) );
		extrachill_users_subscribe_to_entity( $user_id, 'artist-email-sharing', 'artist', 'susto' );
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_get_subscriptions();

		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( $artist['profile_id'], $result['artist_email_consents'][0]['artist_id'] );
		$this->assertSame( $result['artist_email_consents'], $result['followed_artists'] );
	}

	public function test_notification_email_preference_does_not_change_email_sharing_consent(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'current@example.com' ) );
		extrachill_users_subscribe_to_entity( $user_id, 'artist-email-sharing', 'artist', 'futurebirds' );
		ec_users_set_notification_emails_disabled( $user_id, true );

		add_filter( 'extrachill_users_entity_subscription_producer_authorized', '__return_true' );
		try {
			$recipient_ids = extrachill_users_entity_subscription_recipients( 'artist-export', 'artist-email-sharing', 'artist', 'futurebirds', 'email' );
		} finally {
			remove_filter( 'extrachill_users_entity_subscription_producer_authorized', '__return_true' );
		}

		$this->assertSame( array( $user_id ), $recipient_ids );
		$this->assertSame( 'current@example.com', get_userdata( $recipient_ids[0] )->user_email );
		$this->assertTrue( extrachill_users_entity_subscription_status( $user_id, 'artist-email-sharing', 'artist', 'futurebirds' )['subscribed'] );
	}

	public function test_migration_is_dry_run_safe_idempotent_and_preserves_direct_rows(): void {
		$artist  = $this->create_bound_artist( 'kid-lake' );
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'legacy-listener',
				'user_email' => 'legacy@example.com',
			)
		);
		$this->insert_legacy_row( $user_id, $artist['profile_id'], 'platform_follow_consent', 'legacy@example.com' );
		$this->insert_legacy_row( 0, $artist['profile_id'], 'artist_subscribe_form', 'direct@example.com' );

		$dry_run = extrachill_users_migrate_artist_email_sharing_consent();
		$this->assertSame( 1, $dry_run['totals']['ready'] );
		$this->assertSame( 2, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->table_name}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.
		$this->assertFalse( extrachill_users_entity_subscription_status( $user_id, 'artist-email-sharing', 'artist', 'kid-lake' )['subscribed'] );

		$applied = extrachill_users_migrate_artist_email_sharing_consent( true );
		$this->assertSame( 1, $applied['totals']['migrated'] );
		$this->assertTrue( extrachill_users_entity_subscription_status( $user_id, 'artist-email-sharing', 'artist', 'kid-lake' )['subscribed'] );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$this->table_name} WHERE source = 'artist_subscribe_form'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture table uses the trusted WordPress prefix.

		$rerun = extrachill_users_migrate_artist_email_sharing_consent( true );
		$this->assertSame( 0, $rerun['totals']['candidates'] );
	}

	public function test_account_without_email_cannot_grant_artist_email_access(): void {
		$artist  = $this->create_bound_artist( 'empty-email-artist' );
		$user_id = self::factory()->user->create( array( 'user_email' => '' ) );
		wp_set_current_user( $user_id );

		$result = extrachill_users_ability_update_subscriptions( array( 'consented_artists' => array( $artist['profile_id'] ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'user_email_missing', $result->get_error_code() );
	}

	private function create_bound_artist( string $slug ): array {
		$term       = wp_insert_term( ucwords( str_replace( '-', ' ', $slug ) ), 'artist', array( 'slug' => $slug ) );
		$profile_id = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => ucwords( str_replace( '-', ' ', $slug ) ),
			)
		);
		update_post_meta( $profile_id, '_artist_term_id', $term['term_id'] );
		update_term_meta( $term['term_id'], '_artist_profile_id', $profile_id );

		return array(
			'profile_id' => $profile_id,
			'term_id'    => $term['term_id'],
		);
	}

	private function insert_legacy_row( int $user_id, int $artist_id, string $source, string $email ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table_name,
			array(
				'user_id'           => $user_id > 0 ? $user_id : null,
				'artist_profile_id' => $artist_id,
				'subscriber_email'  => $email,
				'username'          => $user_id ? 'legacy-listener' : null,
				'source'            => $source,
				'subscribed_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
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
