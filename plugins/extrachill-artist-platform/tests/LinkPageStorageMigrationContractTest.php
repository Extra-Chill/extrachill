<?php
/**
 * Artist migration callback tests.
 *
 * @package ExtraChillArtistPlatform
 *
 * phpcs:ignoreFile Universal.Files.SeparateFunctionsFromOO.Mixed,Generic.Files.OneObjectStructurePerFile.MultipleFound -- the participant-contract fixture registry plus the test case are intentionally co-located.
 */

require_once __DIR__ . '/support/base-test-case.php';

/** Multisite-capable test registry matching the Link Pages participant contract. */
final class EcArtistMigrationTestRegistry {
	/** @var array */
	private $participants = array();

	/** Register one complete participant. */
	public function register( $name, $contract_version, $callbacks, $priority ) {
		foreach ( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ) as $operation ) {
			if ( empty( $callbacks[ $operation ] ) || ! is_callable( $callbacks[ $operation ] ) ) {
				return new WP_Error( 'invalid_link_page_migration_participant', 'Every migration participant callback is required.' );
			}
		}
		$this->participants[ $name ] = compact( 'name', 'contract_version', 'callbacks', 'priority' );
		return true;
	}

	/** Return registered participants. */
	public function snapshot() {
		return array_values( $this->participants );
	}

	/** Reset isolated fixture state. */
	public function reset() {
		$this->participants = array();
	}
}

if ( ! function_exists( 'ec_link_page_migration_participant_registry' ) ) {
	/** Return the migration test registry. */
	function ec_link_page_migration_participant_registry() {
		static $registry = null;
		if ( null === $registry ) {
			$registry = new EcArtistMigrationTestRegistry();
		}
		return $registry;
	}
}

if ( ! function_exists( 'ec_register_link_page_migration_participant' ) ) {
	/** Register a fixture participant using the current core signature. */
	function ec_register_link_page_migration_participant( $name, $contract_version, $callbacks, $priority = 10 ) {
		return ec_link_page_migration_participant_registry()->register( $name, $contract_version, $callbacks, $priority );
	}
}

/** Verify Artist behavior through the executable migration callbacks. */
final class LinkPageStorageMigrationContractTest extends EC_Artist_Platform_TestCase {
	/**
	 * Fixture IDs created in setUp().
	 *
	 * @var array<string,int>
	 */
	private $ids = array();

	/** Build a real nested multisite source fixture on the Artist blog. */
	protected function setUp(): void {
		parent::setUp();

		$this->resetProviders();
		ec_link_page_migration_participant_registry()->reset();

		ec_register_link_page_owner_compatibility_provider( 'artist-platform', 'ec_artist_link_page_owner_compatibility_provider' );
		ec_register_link_page_operation_provider( 'artist-platform', 'ec_artist_link_page_operation_provider' );

		switch_to_blog( $this->artist_blog_id() );
		try {
			$this->ids['owner']   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
			$this->ids['profile'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_profile',
					'post_status' => 'publish',
					'post_title'  => 'Migration Profile',
				)
			);
			$this->ids['link_page'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_link_page',
					'post_status' => 'publish',
					'post_title'  => 'Migration Link Page',
				)
			);
			$this->ids['legacy_image'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => $this->ids['link_page'],
				)
			);
			$this->ids['background_image'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => $this->ids['profile'],
				)
			);
			$this->ids['thumbnail_image'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_parent' => $this->ids['link_page'],
				)
			);
			$this->ids['other_profile'] = (int) self::factory()->post->create(
				array(
					'post_type'   => 'artist_profile',
					'post_status' => 'publish',
					'post_title'  => 'Other Profile',
				)
			);

			add_post_meta( $this->ids['link_page'], EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['profile'] );
			add_post_meta( $this->ids['link_page'], '_associated_artist_profile_id', $this->ids['profile'] );
			add_post_meta( $this->ids['link_page'], '_link_page_profile_image_id', $this->ids['legacy_image'] );
			add_post_meta( $this->ids['link_page'], '_link_page_background_image_id', $this->ids['background_image'] );
			add_post_meta( $this->ids['profile'], '_extrch_link_page_id', $this->ids['link_page'] );
			update_post_meta( $this->ids['profile'], '_thumbnail_id', $this->ids['thumbnail_image'] );
		} finally {
			restore_current_blog();
		}

		$this->create_artist_membership( $this->ids['owner'], $this->ids['profile'] );
	}

	/** Remove fixture providers after each test. */
	protected function tearDown(): void {
		$this->resetProviders();
		ec_link_page_migration_participant_registry()->reset();
		parent::tearDown();
	}

	/** Registration is versioned, complete, and owner claiming is mandatory. */
	public function test_registration_claim_plan_validate_apply_and_rollback_callbacks_execute(): void {
		$this->authorizeOwner();
		$incomplete = array_fill_keys( array( 'plan', 'apply', 'validate', 'rollback' ), '__return_true' );
		$this->assertMigrationError( 'invalid_link_page_migration_participant', ec_register_link_page_migration_participant( 'incomplete', '1', $incomplete ) );
		$this->assertTrue( ec_artist_register_link_page_migration_adapter() );
		$participants = ec_link_page_migration_participant_registry()->snapshot();
		$this->assertCount( 1, $participants );
		$this->assertSame( 'artist-platform', $participants[0]['name'] );
		$this->assertSame( '1', $participants[0]['contract_version'] );
		$this->assertSame( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ), array_keys( $participants[0]['callbacks'] ) );

		$callbacks = $participants[0]['callbacks'];
		$this->assertTrue( call_user_func( $callbacks['claim_owner'], $this->claimContext() ) );
		$wrong_owner                  = $this->claimContext();
		$wrong_owner['owner']['kind'] = 'term';
		$this->assertFalse( call_user_func( $callbacks['claim_owner'], $wrong_owner ) );
		$plan = call_user_func( $callbacks['plan'], $this->migrationContext() );
		$this->assertIsArray( $plan );
		$this->assertTrue( call_user_func( $callbacks['validate'], $this->migrationContext() ) );
		$this->assertTrue( call_user_func( $callbacks['apply'], $this->migrationContext() ) );
		$this->assertTrue( call_user_func( $callbacks['rollback'], $this->migrationContext() ) );
		$this->assertContextRestored();
	}

	/** Plan enumerates every real attachment source without mutating the source. */
	public function test_plan_preserves_ids_parents_source_owners_and_exact_source_state(): void {
		$source_before = $this->snapshotSource();

		$plan = $this->planFromEventsBlog();

		$this->assertIsArray( $plan );
		$this->assertSame( array( $this->ids['legacy_image'], $this->ids['background_image'], $this->ids['thumbnail_image'] ), $plan['attachment_ids'] );
		$this->assertSame(
			array(
				array(
					'attachment_id'      => $this->ids['legacy_image'],
					'destination_parent' => $this->ids['link_page'],
					'owner_reference'    => 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['profile'],
					'reason'             => 'migrated-link-page-parent-preserved',
				),
				array(
					'attachment_id'      => $this->ids['background_image'],
					'destination_parent' => 0,
					'owner_reference'    => 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['profile'],
					'reason'             => 'external-owner-parent-remapped',
				),
				array(
					'attachment_id'      => $this->ids['thumbnail_image'],
					'destination_parent' => $this->ids['link_page'],
					'owner_reference'    => 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['profile'],
					'reason'             => 'migrated-link-page-parent-preserved',
				),
			),
			$plan['attachment_semantics']
		);
		$this->assertSame( 'generated-on-demand-no-persisted-attachment', $plan['qr_storage'] );
		$this->assertSame( $this->ids['profile'], $plan['profiles'][0]['profile_id'] );
		$this->assertSame( $this->ids['link_page'], $plan['profiles'][0]['link_page_id'] );
		$this->assertSame( $this->ids['link_page'], $plan['profiles'][0]['reciprocal_link_page_id'] );
		$this->assertSame( $this->artist_blog_id(), $plan['profiles'][0]['profile_remains_on_blog_id'] );
		$this->assertSame( $this->ids['legacy_image'], $plan['profiles'][0]['legacy_profile_image_id'] );
		$this->assertSame( $this->ids['background_image'], $plan['profiles'][0]['background_image_id'] );
		$this->assertSame( $this->ids['thumbnail_image'], $plan['profiles'][0]['profile_image_id'] );
		$this->assertSame( 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['profile'], $plan['profiles'][0]['owner_reference'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $plan['fingerprint'] );
		$this->assertSame( $source_before, $this->snapshotSource(), 'Source profiles, posts, and every metadata row must remain byte-for-byte equivalent.' );

		$this->assertTrue( ec_artist_link_page_migration_validate( $this->migrationContext() ) );
		$this->assertSame( $source_before, $this->snapshotSource() );
		$this->assertContextRestored();
	}

	/** Claiming and planning reject a non-Artist source blog. */
	public function test_wrong_source_blog_is_rejected(): void {
		$claim                   = $this->claimContext();
		$claim['source_blog_id'] = $this->main_blog_id();
		$this->assertFalse( ec_artist_link_page_migration_claim_owner( $claim ) );
		$context                   = $this->migrationContext();
		$context['source_blog_id'] = $this->main_blog_id();
		$this->assertMigrationError( 'artist_link_page_migration_source_mismatch', ec_artist_link_page_migration_plan( $context ) );
		$this->assertContextRestored();
	}

	/** Missing and wrong-blog attachments fail; reads stay on the source blog. */
	public function test_missing_and_wrong_blog_attachments_are_rejected_from_source_context(): void {
		switch_to_blog( $this->main_blog_id() );
		try {
			$foreign_attachment = (int) self::factory()->post->create(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
				)
			);
		} finally {
			restore_current_blog();
		}
		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->ids['link_page'], '_link_page_profile_image_id', $foreign_attachment );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_attachment_source_mismatch', $this->planFromEventsBlog() );

		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->ids['link_page'], '_link_page_profile_image_id', $this->ids['legacy_image'] );
			wp_delete_post( $this->ids['legacy_image'], true );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_attachment_source_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** A source attachment reference that is not an attachment post fails closed. */
	public function test_non_attachment_source_rows_are_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->ids['link_page'], '_link_page_background_image_id', $this->ids['other_profile'] );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_attachment_source_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** Canonical ownership must agree with the legacy profile association. */
	public function test_canonical_owner_divergence_is_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->ids['link_page'], EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $this->ids['other_profile'] );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_owner_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** Duplicate reciprocal rows are never collapsed into a valid pointer. */
	public function test_duplicate_reciprocal_meta_rows_are_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			add_post_meta( $this->ids['profile'], '_extrch_link_page_id', $this->ids['link_page'] );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_reciprocal_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** Another owner profile cannot point to the same Link Page. */
	public function test_competing_profile_pointer_is_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			add_post_meta( $this->ids['other_profile'], '_extrch_link_page_id', $this->ids['link_page'] );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_reciprocal_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** The owner profile pointer must identify the exact migrating Link Page ID. */
	public function test_stale_reciprocal_pointer_is_rejected(): void {
		switch_to_blog( $this->artist_blog_id() );
		try {
			update_post_meta( $this->ids['profile'], '_extrch_link_page_id', $this->ids['link_page'] + 1 );
		} finally {
			restore_current_blog();
		}
		$this->assertMigrationError( 'artist_link_page_migration_reciprocal_mismatch', $this->planFromEventsBlog() );
		$this->assertContextRestored();
	}

	/** Run the read-only plan from a non-Artist blog and restore the caller. */
	private function planFromEventsBlog() {
		$this->authorizeOwner();
		$events_blog_id = (int) ec_get_blog_id( 'events' );
		switch_to_blog( $events_blog_id );
		try {
			return ec_artist_link_page_migration_plan( $this->migrationContext() );
		} finally {
			restore_current_blog();
		}
	}

	/** Act as the managed owner so provider reads authorize. */
	private function authorizeOwner(): void {
		wp_set_current_user( $this->ids['owner'] );
	}

	/**
	 * Snapshot every source row the plan is allowed to read.
	 *
	 * @return array<string,mixed> Post rows, complete meta, and type counts.
	 */
	private function snapshotSource(): array {
		switch_to_blog( $this->artist_blog_id() );
		try {
			$state  = array(
				'profiles'    => wp_count_posts( 'artist_profile' )->publish,
				'link_pages'  => wp_count_posts( 'artist_link_page' )->publish,
				'attachments' => wp_count_posts( 'attachment' )->inherit,
			);
			$id_map = array(
				'profile',
				'link_page',
				'legacy_image',
				'background_image',
				'thumbnail_image',
				'other_profile',
			);
			foreach ( $id_map as $key ) {
				if ( ! isset( $this->ids[ $key ] ) ) {
					continue;
				}
				$post_id          = $this->ids[ $key ];
				$state[ 'post_' . $post_id ]     = get_post( $post_id, ARRAY_A );
				$state[ 'meta_' . $post_id ]     = get_post_meta( $post_id, '', false );
			}
			return $state;
		} finally {
			restore_current_blog();
		}
	}

	/** Build the current owner-claim context. */
	private function claimContext(): array {
		return array(
			'source_blog_id' => $this->artist_blog_id(),
			'link_page_id'   => $this->ids['link_page'],
			'owner'          => array(
				'kind'      => 'post',
				'blog_id'   => $this->artist_blog_id(),
				'subtype'   => 'artist_profile',
				'object_id' => $this->ids['profile'],
			),
		);
	}

	/** Build the participant plan/validate context supplied by Link Pages. */
	private function migrationContext(): array {
		return array(
			'mode'                => 'readiness',
			'source_blog_id'      => $this->artist_blog_id(),
			'destination_blog_id' => 13,
			'link_page_ids'       => array( $this->ids['link_page'] ),
			'attachment_map'      => array(),
			'fingerprint'         => '',
			'journal_id'          => '',
			'journal_record'      => null,
		);
	}

	/** Assert a migration error code. */
	private function assertMigrationError( $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/** Assert exact restoration of the caller blog and switch stack. */
	private function assertContextRestored(): void {
		$this->assertSame( 1, get_current_blog_id() );
		$this->assertSame( array(), $GLOBALS['_wp_switched_stack'] ?? array() );
	}

	/** Reset an append-only runtime registry for test isolation. */
	private function resetProviders(): void {
		foreach ( array(
			ec_link_page_owner_compatibility_registry(),
			ec_link_page_operation_provider_registry(),
		) as $registry ) {
			$reflection = new ReflectionObject( $registry );
			$property   = $reflection->getProperty( 'providers' );
			$property->setAccessible( true );
			$property->setValue( $registry, array() );
		}
	}
}
