<?php
/**
 * Tests for retiring account-derived email-sharing subscriptions.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify exact retired-row cleanup and removed public surfaces.
 */
class Test_Account_Email_Sharing_Retirement extends WP_UnitTestCase {

	private const MIGRATION_OPTION = 'extrachill_users_email_sharing_retirement_version';

	/**
	 * Prepare the subscription table and migration state.
	 */
	protected function setUp(): void {
		parent::setUp();
		extrachill_users_install_entity_subscriptions_table();
		delete_site_option( self::MIGRATION_OPTION );
	}

	/**
	 * Reset migration state after each test.
	 */
	protected function tearDown(): void {
		delete_site_option( self::MIGRATION_OPTION );
		parent::tearDown();
	}

	/**
	 * Retired identities and legacy abilities are no longer exposed.
	 */
	public function test_retired_descriptors_and_legacy_abilities_are_removed(): void {
		$this->assertWPError( extrachill_users_normalize_entity_subscription( 'artist-email-sharing', 'artist', 'phish' ) );
		$this->assertWPError( extrachill_users_normalize_entity_subscription( 'venue-email-sharing', 'venue', 'the-royal-american' ) );
		$this->assertNull( wp_get_ability( 'extrachill/get-subscriptions' ) );
		$this->assertNull( wp_get_ability( 'extrachill/update-subscriptions' ) );
		$this->assertFalse( function_exists( 'extrachill_users_artist_email_sharing_presentation' ) );
		$this->assertFalse( function_exists( 'extrachill_users_migrate_artist_email_sharing_consent' ) );
	}

	/**
	 * Only exact retired pairs are deleted, once.
	 */
	public function test_migration_deletes_only_exact_retired_pairs_and_is_idempotent(): void {
		global $wpdb;

		$table         = extrachill_users_entity_subscriptions_table_name();
		$user_id       = self::factory()->user->create();
		$other_user_id = self::factory()->user->create();
		$rows          = array(
			array( $user_id, 'artist-email-sharing', 'artist', 'shared-slug' ),
			array( $user_id, 'venue-email-sharing', 'venue', 'shared-slug' ),
			array( $other_user_id, 'artist-email-sharing', 'artist', 'another-artist' ),
			array( $other_user_id, 'venue-email-sharing', 'venue', 'another-venue' ),
			array( $user_id, 'artist-email-sharing', 'venue', 'shared-slug' ),
			array( $user_id, 'venue-email-sharing', 'artist', 'shared-slug' ),
			array( $user_id, 'artist', 'artist', 'shared-slug' ),
			array( $user_id, 'venue', 'venue', 'shared-slug' ),
			array( $user_id, 'festival', 'festival', 'shared-slug' ),
			array( $user_id, 'location', 'location', 'shared-slug' ),
		);

		foreach ( $rows as $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture setup.
				$table,
				array(
					'user_id'     => $row[0],
					'entity_type' => $row[1],
					'taxonomy'    => $row[2],
					'entity_slug' => $row[3],
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}

		$this->assertSame( 4, extrachill_users_maybe_purge_retired_email_sharing_subscriptions() );
		$this->assertSame( EXTRACHILL_USERS_EMAIL_SHARING_RETIREMENT_VERSION, get_site_option( self::MIGRATION_OPTION ) );
		$this->assertSame( 0, extrachill_users_maybe_purge_retired_email_sharing_subscriptions() );

		$remaining = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- assertion against test fixture rows.
			$wpdb->prepare(
				"SELECT entity_type, taxonomy, entity_slug FROM {$table} WHERE user_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
				$user_id
			),
			ARRAY_A
		);

		$this->assertSame(
			array(
				array(
					'entity_type' => 'artist-email-sharing',
					'taxonomy'    => 'venue',
					'entity_slug' => 'shared-slug',
				),
				array(
					'entity_type' => 'venue-email-sharing',
					'taxonomy'    => 'artist',
					'entity_slug' => 'shared-slug',
				),
				array(
					'entity_type' => 'artist',
					'taxonomy'    => 'artist',
					'entity_slug' => 'shared-slug',
				),
				array(
					'entity_type' => 'venue',
					'taxonomy'    => 'venue',
					'entity_slug' => 'shared-slug',
				),
				array(
					'entity_type' => 'festival',
					'taxonomy'    => 'festival',
					'entity_slug' => 'shared-slug',
				),
				array(
					'entity_type' => 'location',
					'taxonomy'    => 'location',
					'entity_slug' => 'shared-slug',
				),
			),
			$remaining
		);
		$this->assertSame(
			'0',
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- assertion against test fixture rows.
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table helper.
					$other_user_id
				)
			)
		);
	}
}
