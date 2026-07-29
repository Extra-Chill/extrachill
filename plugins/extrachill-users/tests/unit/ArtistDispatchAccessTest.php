<?php
/**
 * Artist Dispatch access lifecycle tests.
 *
 * @package ExtraChill\Users
 */

/**
 * Verify the Artist Dispatch contract against WordPress multisite primitives.
 */
class Test_Artist_Dispatch_Access extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $main_blog_id;
	private int $artist_blog_id;
	private bool $registered_fake_analytics = false;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/inc/artist-dispatch-role.php';
		require_once dirname( __DIR__, 2 ) . '/inc/artist-dispatch.php';
		require_once dirname( __DIR__, 2 ) . '/inc/core/abilities/artist-dispatch.php';

		$this->main_blog_id   = ec_users_get_artist_dispatch_blog_id();
		$this->artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		$this->assertTrue( is_multisite(), 'Artist Dispatch tests require multisite.' );
		$this->assertGreaterThan( 0, $this->artist_blog_id, 'The canonical artist-site map must be loaded.' );

		$this->ensure_blog_exists( $this->main_blog_id );
		$this->ensure_blog_exists( $this->artist_blog_id );
		delete_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION );
		wp_set_current_user( 0 );
		$GLOBALS['ec_artist_dispatch_test_analytics'] = array();
		$this->register_fake_analytics_ability();

		switch_to_blog( $this->main_blog_id );
		remove_role( EC_USERS_ARTIST_DISPATCH_ROLE );
		ec_users_register_artist_dispatch_role();
		restore_current_blog();
	}

	protected function tearDown(): void {
		delete_site_option( EC_USERS_ARTIST_DISPATCH_POLICY_OPTION );
		if ( $this->registered_fake_analytics ) {
			wp_unregister_ability( 'extrachill/track-analytics-event' );
		}
		unset( $GLOBALS['ec_artist_dispatch_test_analytics'] );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function ensure_blog_exists( int $blog_id ): void {
		while ( ! get_blog_details( $blog_id ) ) {
			$created = self::factory()->blog->create();
			if ( $created > $blog_id ) {
				$this->fail( 'Could not create expected mapped blog ID.' );
			}
		}
	}

	private function configure_policy( float $minimum_points = 10 ): void {
		ec_users_update_artist_dispatch_policy(
			array(
				'minimum_points'           => $minimum_points,
				'minimum_account_age_days' => 1,
				'pilot_enabled'            => true,
			)
		);
	}

	private function register_fake_analytics_ability(): void {
		foreach (
			array(
				'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REQUESTED' => 'artist_dispatch_access_requested',
				'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_APPROVED'  => 'artist_dispatch_access_approved',
				'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REJECTED'  => 'artist_dispatch_access_rejected',
				'EC_ANALYTICS_EVENT_ARTIST_DISPATCH_ACCESS_REVOKED'   => 'artist_dispatch_access_revoked',
			) as $constant => $value
		) {
			if ( ! defined( $constant ) ) {
				define( $constant, $value );
			}
		}
		if ( wp_has_ability( 'extrachill/track-analytics-event' ) ) {
			return;
		}
		wp_register_ability(
			'extrachill/track-analytics-event',
			array(
				'label'               => 'Test analytics',
				'description'         => 'Captures bounded Artist Dispatch events.',
				'category'            => 'extrachill-users',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'integer' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) {
					$GLOBALS['ec_artist_dispatch_test_analytics'][] = $input;
					return count( $GLOBALS['ec_artist_dispatch_test_analytics'] );
				},
			)
		);
		$this->registered_fake_analytics = true;
	}

	private function create_eligible_user(): array {
		$user_id = self::factory()->user->create(
			array(
				'role'            => '',
				'user_registered' => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ),
			)
		);
		update_user_meta( $user_id, 'onboarding_completed', '1' );
		set_transient( 'user_points_' . $user_id, 25, HOUR_IN_SECONDS );

		switch_to_blog( $this->artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		$artist_id = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'Lifecycle Artist',
			)
		);
		update_post_meta( $artist_id, '_artist_member_ids', array( $user_id ) );
		restore_current_blog();
		update_user_meta( $user_id, '_artist_profile_ids', array( $artist_id ) );

		return array( $user_id, $artist_id );
	}

	private function request_access( int $user_id, int $artist_id ): array {
		wp_set_current_user( $user_id );
		$result = ec_users_request_artist_dispatch_access(
			$user_id,
			array(
				'artist_id'       => $artist_id,
				'description'     => str_repeat( 'A focused first-person dispatch proposal. ', 3 ),
				'sample_url'      => 'https://example.com/sample',
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertNotWPError( $result );
		return $result;
	}

	private function create_network_admin(): int {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );
		return $admin_id;
	}

	public function test_policy_is_disabled_and_unconfigured_by_default(): void {
		$user_id     = self::factory()->user->create();
		$eligibility = ec_users_get_artist_dispatch_eligibility( $user_id );

		$this->assertFalse( $eligibility['eligible'] );
		$this->assertFalse( $eligibility['criteria']['policy_configured']['passed'] );
		$this->assertFalse( $eligibility['criteria']['pilot_enabled']['passed'] );
		$this->assertNull( $eligibility['criteria']['points']['minimum'] );
	}

	public function test_eligibility_reports_each_input_and_canonical_artist_relationship(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$eligibility                 = ec_users_get_artist_dispatch_eligibility( $user_id );

		$this->assertTrue( $eligibility['eligible'] );
		$this->assertSame( 25.0, $eligibility['criteria']['points']['value'] );
		$this->assertTrue( $eligibility['criteria']['onboarding']['value'] );
		$this->assertGreaterThanOrEqual( 1, $eligibility['criteria']['account_age']['value_days'] );
		$this->assertTrue( $eligibility['criteria']['claimed_account']['value'] );
		$this->assertTrue( $eligibility['criteria']['active_moderation']['value'] );
		$this->assertSame( array( $artist_id ), $eligibility['criteria']['claimed_artist']['artist_ids'] );

		update_user_meta( $user_id, '_artist_profile_ids', array() );
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['criteria']['claimed_artist']['passed'] );
	}

	public function test_canonical_artist_reads_require_valid_bidirectional_published_memberships(): void {
		$user_id = self::factory()->user->create();
		switch_to_blog( $this->artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		$valid_one  = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
			)
		);
		$valid_two  = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
			)
		);
		$one_sided  = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
			)
		);
		$wrong_type = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$private    = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'private',
			)
		);
		$deleted    = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $valid_one, '_artist_member_ids', array( $user_id ) );
		update_post_meta( $valid_two, '_artist_member_ids', array( (string) $user_id ) );
		update_post_meta( $wrong_type, '_artist_member_ids', array( $user_id ) );
		update_post_meta( $private, '_artist_member_ids', array( $user_id ) );
		update_post_meta( $deleted, '_artist_member_ids', array( $user_id ) );
		wp_delete_post( $deleted, true );
		restore_current_blog();

		update_user_meta( $user_id, '_artist_profile_ids', array( $valid_one, $one_sided, $wrong_type, $private, $deleted, $valid_two ) );
		$this->assertSame( array( $valid_one, $valid_two ), ec_get_artists_for_user( $user_id ) );
	}

	public function test_artist_admin_override_still_returns_all_published_profiles(): void {
		$admin_id = $this->create_network_admin();
		switch_to_blog( $this->artist_blog_id );
		register_post_type( 'artist_profile', array( 'public' => true ) );
		$published = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
			)
		);
		$private   = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'private',
			)
		);
		restore_current_blog();
		$result = ec_get_artists_for_user( $admin_id, true );
		$this->assertContains( $published, $result );
		$this->assertNotContains( $private, $result );
	}

	public function test_request_is_self_only_and_server_validates_selected_artist(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$other_id                    = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$invalid = extrachill_users_ability_request_artist_dispatch_access(
			array(
				'user_id'         => $other_id,
				'artist_id'       => $artist_id + 999,
				'description'     => str_repeat( 'Valid length, invalid ownership. ', 3 ),
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertWPError( $invalid );
		$this->assertSame( array(), ec_users_get_artist_dispatch_state( $other_id ) );

		$valid = extrachill_users_ability_request_artist_dispatch_access(
			array(
				'user_id'         => $other_id,
				'artist_id'       => $artist_id,
				'description'     => str_repeat( 'A valid proposal from the authenticated member. ', 3 ),
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertNotWPError( $valid );
		$this->assertSame( 'pending', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		$this->assertSame( array(), ec_users_get_artist_dispatch_state( $other_id ) );
		$this->assertArrayNotHasKey( 'description', $valid );
		$this->assertTrue( $valid['terms_acknowledged'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $valid['terms_version'] );
	}

	public function test_request_requires_and_immutably_persists_current_terms_acceptance(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$base_input                  = array(
			'artist_id'   => $artist_id,
			'description' => str_repeat( 'A proposal with an explicit affiliation disclosure. ', 3 ),
		);

		$this->assertWPError( ec_users_request_artist_dispatch_access( $user_id, $base_input ) );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => false,
						'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
					)
				)
			)
		);
		$this->assertWPError( ec_users_request_artist_dispatch_access( $user_id, array_merge( $base_input, array( 'acknowledgement' => true ) ) ) );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => true,
						'terms_version'   => 'v1',
					)
				)
			)
		);

		$request = ec_users_request_artist_dispatch_access(
			$user_id,
			array_merge(
				$base_input,
				array(
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);
		$this->assertNotWPError( $request );
		$this->assertTrue( ec_users_is_artist_dispatch_request_id( $request['request_id'] ) );
		$this->assertTrue( $request['terms_acknowledged'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $request['terms_version'] );
		$this->assertGreaterThan( 0, $request['terms_accepted_at'] );
		$events = get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $events[0]['details']['terms_version'] );

		$retry = ec_users_request_artist_dispatch_access(
			$user_id,
			array_merge(
				$base_input,
				array(
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);
		$this->assertSame( $request['request_id'], $retry['request_id'] );
		$this->assertSame( $request['terms_accepted_at'], $retry['terms_accepted_at'] );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array_merge(
					$base_input,
					array(
						'acknowledgement' => true,
						'terms_version'   => 'changed-client-value',
					)
				)
			)
		);
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, ec_users_get_artist_dispatch_state( $user_id )['terms_version'] );
	}

	public function test_all_lifecycle_analytics_payloads_match_owner_contract(): void {
		$request_id = wp_generate_uuid4();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request_id );
		foreach ( array( 'requested', 'approved', 'rejected', 'revoked' ) as $event_type ) {
			$this->assertNotSame( '', ec_users_get_artist_dispatch_analytics_event( $event_type ) );
			$payload = ec_users_get_artist_dispatch_event_payload( 12, $request_id );
			$this->assertSame( array( 'user_id', 'request_id' ), array_keys( $payload ) );
			$this->assertSame( 12, $payload['user_id'] );
			$this->assertSame( $request_id, $payload['request_id'] );
		}
		$this->assertSame( array(), ec_users_get_artist_dispatch_event_payload( 0, $request_id ) );
		$this->assertSame( array( 'user_id' => 12 ), ec_users_get_artist_dispatch_event_payload( 12, 'not-a-uuid' ) );
		$this->assertSame( array( 'user_id' => 12 ), ec_users_get_artist_dispatch_event_payload( 12, $request_id, 'free-text' ) );

		$user_id = self::factory()->user->create();
		$state   = array(
			'status'     => 'pending',
			'request_id' => $request_id,
		);
		$this->assertTrue( ec_users_write_artist_dispatch_state( $user_id, $state, array() ) );
		$lock = ec_users_acquire_artist_dispatch_lock( $user_id, $request_id );
		$this->assertNotWPError( $lock );
		foreach ( array( 'requested', 'approved', 'rejected', 'revoked' ) as $event_type ) {
			$state = ec_users_maybe_emit_artist_dispatch_event( $user_id, $event_type, $state );
			$this->assertNotWPError( $state );
		}
		$state = ec_users_maybe_emit_artist_dispatch_event( $user_id, 'requested', $state );
		ec_users_release_artist_dispatch_lock( $user_id, $lock );
		$this->assertCount( 4, $state['deliveries']['analytics'] );
		$this->assertTrue( $this->registered_fake_analytics, 'The isolated test suite must own the analytics ability.' );
		$this->assertCount( 4, $GLOBALS['ec_artist_dispatch_test_analytics'] );
		foreach ( $GLOBALS['ec_artist_dispatch_test_analytics'] as $event ) {
			$this->assertSame( array( 'user_id', 'request_id' ), array_keys( $event['event_data'] ) );
		}
	}

	public function test_ability_schemas_and_permissions_are_self_defending(): void {
		$request = wp_get_ability( 'extrachill/request-artist-dispatch-access' );
		$approve = wp_get_ability( 'extrachill/approve-artist-dispatch-access' );
		$this->assertContains( 'acknowledgement', $request->get_input_schema()['required'] );
		$this->assertContains( 'terms_version', $request->get_input_schema()['required'] );
		$this->assertNotContains( 'user_id', array_keys( $request->get_input_schema()['properties'] ) );
		wp_set_current_user( 0 );
		$this->assertFalse( $request->check_permissions( array() ) );
		$this->assertFalse( $approve->check_permissions( array() ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( $request->check_permissions( array() ) );
		$this->assertFalse( $approve->check_permissions( array() ) );
		$this->create_network_admin();
		$this->assertTrue( $approve->check_permissions( array() ) );
	}

	public function test_role_capabilities_are_exact_and_main_site_only(): void {
		$this->assertSame(
			array( 'read', 'edit_posts', 'delete_posts', 'submit_for_review' ),
			array_keys( ec_users_get_artist_dispatch_role_caps() )
		);

		$other_blog_id = self::factory()->blog->create();
		switch_to_blog( $other_blog_id );
		ec_users_register_artist_dispatch_role();
		$this->assertNull( get_role( EC_USERS_ARTIST_DISPATCH_ROLE ) );
		restore_current_blog();
	}

	public function test_approval_is_additive_and_native_post_authorization_is_bounded(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		add_user_to_blog( $this->main_blog_id, $user_id, 'subscriber' );
		$request  = $this->request_access( $user_id, $artist_id );
		$admin_id = $this->create_network_admin();
		$approved = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertNotWPError( $approved );

		switch_to_blog( $this->main_blog_id );
		$user = new WP_User( $user_id );
		$this->assertContains( 'subscriber', $user->roles );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, $user->roles );
		wp_set_current_user( $user_id );
		$own_draft  = self::factory()->post->create(
			array(
				'post_author' => $user_id,
				'post_status' => 'draft',
			)
		);
		$other      = self::factory()->user->create();
		$other_post = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);
		$this->assertTrue( current_user_can( 'edit_post', $own_draft ), 'Core autosaves use this edit_post authorization.' );
		$this->assertFalse( current_user_can( 'edit_post', $other_post ) );
		$this->assertFalse( current_user_can( 'publish_posts' ) );
		$this->assertFalse( current_user_can( 'upload_files' ) );
		$this->assertFalse( current_user_can( 'edit_others_posts' ) );
		$this->assertFalse( current_user_can( 'edit_published_posts' ) );
		$this->assertFalse( current_user_can( 'manage_categories' ) );
		restore_current_blog();
	}

	public function test_retries_do_not_duplicate_audit_or_notification_markers(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$duplicate_request           = $this->request_access( $user_id, $artist_id );
		$this->assertSame( $request['request_id'], $duplicate_request['request_id'] );
		$this->assertCount( 1, get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) );

		$admin_id = $this->create_network_admin();
		$first    = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$second   = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertCount( 2, get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ) );
		$this->assertCount( 1, $second['deliveries']['notifications'] );
		$this->assertCount( 2, $second['deliveries']['analytics'] );
	}

	public function test_lock_is_bounded_owned_and_expiry_fails_closed(): void {
		$user_id = self::factory()->user->create();
		$first   = ec_users_acquire_artist_dispatch_lock( $user_id, 'first' );
		$this->assertNotWPError( $first );
		$this->assertWPError( ec_users_acquire_artist_dispatch_lock( $user_id, 'concurrent' ) );
		$expired               = $first;
		$expired['expires_at'] = time() - 1;
		switch_to_blog( $this->main_blog_id );
		$this->assertTrue( update_option( EC_USERS_ARTIST_DISPATCH_LOCK_META . '_' . $user_id, $expired, false ) );
		restore_current_blog();
		$takeover = ec_users_acquire_artist_dispatch_lock( $user_id, 'takeover' );
		$this->assertWPError( $takeover );
		$this->assertSame( 'artist_dispatch_lock_reconciliation_required', $takeover->get_error_code() );
		$this->assertTrue( ec_users_release_artist_dispatch_lock( $user_id, $expired ) );
	}

	public function test_failed_state_writes_fail_closed_and_roll_back_role_changes(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$block_add                   = static function ( $check, $object_id, $meta_key ) {
			return EC_USERS_ARTIST_DISPATCH_STATE_META === $meta_key ? false : $check;
		};
		add_filter( 'add_user_metadata', $block_add, 10, 3 );
		$failed_request = ec_users_request_artist_dispatch_access(
			$user_id,
			array(
				'artist_id'       => $artist_id,
				'description'     => str_repeat( 'A request whose state write must fail closed. ', 3 ),
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		remove_filter( 'add_user_metadata', $block_add, 10 );
		$this->assertWPError( $failed_request );
		$this->assertSame( array(), ec_users_get_artist_dispatch_state( $user_id ) );

		$request      = $this->request_access( $user_id, $artist_id );
		$admin_id     = $this->create_network_admin();
		$block_update = static function ( $check, $object_id, $meta_key ) {
			return EC_USERS_ARTIST_DISPATCH_STATE_META === $meta_key ? false : $check;
		};
		add_filter( 'update_user_metadata', $block_update, 10, 3 );
		$failed_approval = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		remove_filter( 'update_user_metadata', $block_update, 10 );
		$this->assertWPError( $failed_approval );
		$this->assertSame( 'pending', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		switch_to_blog( $this->main_blog_id );
		$this->assertNotContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();
	}

	public function test_pending_stale_terms_are_rejected_and_approved_access_can_renew(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$admin_id                    = $this->create_network_admin();
		$stale                       = $request;
		$stale['terms_version']      = '2026-01-01';
		update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $stale );
		$this->assertWPError( ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id ) );
		switch_to_blog( $this->main_blog_id );
		$this->assertNotContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();

		update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $request );
		$approved = ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertNotWPError( $approved );
		$approved['terms_version'] = '2026-01-01';
		update_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_STATE_META, $approved );
		$safe = ec_users_get_artist_dispatch_safe_state( $user_id );
		$this->assertTrue( $safe['terms_renewal_required'] );
		$this->assertSame( '', $safe['terms_version'] );

		wp_set_current_user( $user_id );
		$renewed = extrachill_users_ability_request_artist_dispatch_access(
			array(
				'acknowledgement' => true,
				'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
			)
		);
		$this->assertNotWPError( $renewed );
		$this->assertSame( 'approved', $renewed['status'] );
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_TERMS_VERSION, $renewed['terms_version'] );
		$this->assertNotSame( $request['request_id'], $renewed['request_id'] );
		switch_to_blog( $this->main_blog_id );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();
		$this->assertContains( 'terms_renewed', wp_list_pluck( get_user_meta( $user_id, EC_USERS_ARTIST_DISPATCH_AUDIT_META, false ), 'event' ) );
	}

	public function test_failed_revocation_state_write_restores_role_and_approved_state(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$admin_id                    = $this->create_network_admin();
		$this->assertNotWPError( ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id ) );
		$block_update = static function ( $check, $object_id, $meta_key ) {
			return EC_USERS_ARTIST_DISPATCH_STATE_META === $meta_key ? false : $check;
		};
		add_filter( 'update_user_metadata', $block_update, 10, 3 );
		$failed = ec_users_revoke_artist_dispatch_access( $user_id, $request['request_id'], 'Rollback test.', $admin_id );
		remove_filter( 'update_user_metadata', $block_update, 10 );
		$this->assertWPError( $failed );
		$this->assertSame( 'approved', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		switch_to_blog( $this->main_blog_id );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();
	}

	public function test_actorless_notification_resolves_canonical_network_bot(): void {
		$bot_id    = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$filter    = static fn() => $bot_id;
		add_filter( 'extrachill_network_bot_user_id', $filter );
		$this->assertSame( $bot_id, ec_users_resolve_artist_dispatch_notification_actor( 0 ) );
		$state = array(
			'status'     => 'approved',
			'request_id' => wp_generate_uuid4(),
			'artist_id'  => 123,
		);
		$this->assertTrue( ec_users_write_artist_dispatch_state( $recipient, $state, array() ) );
		$lock = ec_users_acquire_artist_dispatch_lock( $recipient, $state['request_id'] );
		$this->assertNotWPError( $lock );
		$notified = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $state );
		$this->assertNotWPError( $notified );
		$this->assertNotEmpty( $notified['deliveries']['notifications']['approved'] );
		$retry = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $notified );
		$this->assertSame( $notified, $retry );
		$without_marker = $notified;
		unset( $without_marker['deliveries']['notifications']['approved'] );
		$this->assertNotFalse( update_user_meta( $recipient, EC_USERS_ARTIST_DISPATCH_STATE_META, $without_marker, $notified ) );
		$repaired = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $without_marker );
		$this->assertNotWPError( $repaired );
		$this->assertNotEmpty( $repaired['deliveries']['notifications']['approved'] );
		$without_any_marker = $repaired;
		unset( $without_any_marker['deliveries']['notifications']['approved'] );
		$this->assertNotFalse( update_user_meta( $recipient, EC_USERS_ARTIST_DISPATCH_STATE_META, $without_any_marker, $repaired ) );
		$this->assertTrue( ec_users_remove_artist_dispatch_delivery_receipt( $recipient, 'notification', 'approved', $state['request_id'] ) );
		$reconciled = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $without_any_marker );
		$this->assertNotWPError( $reconciled );
		$this->assertNotEmpty( $reconciled['deliveries']['notifications']['approved'] );
		ec_users_release_artist_dispatch_lock( $recipient, $lock );
		$notifications = ec_users_get_notifications( $recipient );
		$this->assertSame( 1, $notifications['total'] );
		$this->assertSame( $bot_id, $notifications['notifications'][0]['actor_id'] );
		global $wpdb;
		$table = extrachill_users_notifications_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT producer, idempotency_key FROM {$table} WHERE user_id = %d", $recipient ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted helper.
		$this->assertSame( EC_USERS_ARTIST_DISPATCH_NOTIFICATION_PRODUCER, $row['producer'] );
		$this->assertSame( sprintf( 'request:%s:event:approved', $state['request_id'] ), $row['idempotency_key'] );
		remove_filter( 'extrachill_network_bot_user_id', $filter );
	}

	public function test_failed_notification_does_not_write_delivery_marker(): void {
		$recipient = self::factory()->user->create();
		$state     = array(
			'status'     => 'approved',
			'request_id' => wp_generate_uuid4(),
		);
		$this->assertTrue( ec_users_write_artist_dispatch_state( $recipient, $state, array() ) );
		$no_bot = static fn() => 0;
		add_filter( 'extrachill_network_bot_user_id', $no_bot );
		$lock   = ec_users_acquire_artist_dispatch_lock( $recipient, $state['request_id'] );
		$this->assertNotWPError( $lock );
		$result = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $state );
		$this->assertWPError( $result );
		$this->assertArrayNotHasKey( 'deliveries', ec_users_get_artist_dispatch_state( $recipient ) );
		ec_users_release_artist_dispatch_lock( $recipient, $lock );
		remove_filter( 'extrachill_network_bot_user_id', $no_bot );
	}

	public function test_failed_notification_receipt_clears_reservation_for_retry(): void {
		global $wpdb;

		$bot_id    = self::factory()->user->create();
		$recipient = self::factory()->user->create();
		$state     = array(
			'status'     => 'approved',
			'request_id' => wp_generate_uuid4(),
		);
		$this->assertTrue( ec_users_write_artist_dispatch_state( $recipient, $state, array() ) );
		$bot_filter = static fn() => $bot_id;
		$table      = extrachill_users_notifications_table_name();
		$fail_write = static function ( $query ) use ( $table ) {
			return false !== strpos( $query, "INSERT IGNORE INTO {$table}" )
				? 'INSERT INTO ec_missing_notification_table (id) VALUES (1)'
				: $query;
		};
		add_filter( 'extrachill_network_bot_user_id', $bot_filter );
		add_filter( 'query', $fail_write );
		$previous_suppress = $wpdb->suppress_errors( true );
		$lock              = ec_users_acquire_artist_dispatch_lock( $recipient, $state['request_id'] );
		$this->assertNotWPError( $lock );
		try {
			$result = ec_users_maybe_notify_artist_dispatch_transition( $recipient, 'approved', 0, $state );
		} finally {
			ec_users_release_artist_dispatch_lock( $recipient, $lock );
			$wpdb->suppress_errors( $previous_suppress );
			remove_filter( 'query', $fail_write );
			remove_filter( 'extrachill_network_bot_user_id', $bot_filter );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'artist_dispatch_notification_failed', $result->get_error_code() );
		$this->assertEmpty( ec_users_get_artist_dispatch_delivery_receipt( $recipient, 'notification', 'approved', $state['request_id'] ) );
		$this->assertArrayNotHasKey( 'deliveries', ec_users_get_artist_dispatch_state( $recipient ) );
	}

	public function test_revocation_removes_only_product_grant_and_cleans_grant_created_membership(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		remove_user_from_blog( $user_id, $this->main_blog_id );
		$request  = $this->request_access( $user_id, $artist_id );
		$admin_id = $this->create_network_admin();
		ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );
		$this->assertTrue( is_user_member_of_blog( $user_id, $this->main_blog_id ) );

		$revoked = ec_users_revoke_artist_dispatch_access( $user_id, $request['request_id'], 'Pilot access ended.', $admin_id );
		$this->assertNotWPError( $revoked );
		$this->assertFalse( is_user_member_of_blog( $user_id, $this->main_blog_id ) );
		$this->assertSame( 'revoked', $revoked['status'] );
	}

	public function test_moderation_blocks_request_and_revokes_without_automatic_restore(): void {
		$this->configure_policy();
		update_site_option(
			EC_USERS_ARTIST_DISPATCH_POLICY_OPTION,
			array_merge( ec_users_get_artist_dispatch_policy(), array( 'require_active_moderation' => false ) )
		);
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$admin_id                    = $this->create_network_admin();
		extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'reason'     => 'Review hold',
				'acted_by'   => $admin_id,
			)
		);
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['eligible'] );
		$this->assertArrayNotHasKey( 'require_active_moderation', ec_users_get_artist_dispatch_policy() );
		$this->assertWPError(
			ec_users_request_artist_dispatch_access(
				$user_id,
				array(
					'artist_id'       => $artist_id,
					'description'     => str_repeat( 'Blocked request text. ', 4 ),
					'acknowledgement' => true,
					'terms_version'   => EC_USERS_ARTIST_DISPATCH_TERMS_VERSION,
				)
			)
		);

		extrachill_users_clear_moderation_action( $user_id );
		$request = $this->request_access( $user_id, $artist_id );
		extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'reason'     => 'Approval hold',
				'acted_by'   => $admin_id,
			)
		);
		$this->assertWPError( ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id ) );
		extrachill_users_clear_moderation_action( $user_id );
		$this->assertNotWPError( ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id ) );
		extrachill_users_apply_moderation_action(
			$user_id,
			array(
				'reason_key' => 'other',
				'reason'     => 'Second hold',
				'acted_by'   => $admin_id,
			)
		);
		$this->assertSame( 'revoked', ec_users_get_artist_dispatch_state( $user_id )['status'] );
		extrachill_users_clear_moderation_action( $user_id );
		$this->assertSame( 'revoked', ec_users_get_artist_dispatch_state( $user_id )['status'] );
	}

	public function test_threshold_change_after_approval_does_not_remove_native_access(): void {
		$this->configure_policy();
		list( $user_id, $artist_id ) = $this->create_eligible_user();
		$request                     = $this->request_access( $user_id, $artist_id );
		$admin_id                    = $this->create_network_admin();
		ec_users_approve_artist_dispatch_access( $user_id, $request['request_id'], '', $admin_id );

		ec_users_update_artist_dispatch_policy( array( 'minimum_points' => 500 ) );
		$this->assertFalse( ec_users_get_artist_dispatch_eligibility( $user_id )['eligible'] );
		switch_to_blog( $this->main_blog_id );
		$this->assertContains( EC_USERS_ARTIST_DISPATCH_ROLE, ( new WP_User( $user_id ) )->roles );
		restore_current_blog();
	}

	public function test_administrative_callbacks_self_defend(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertWPError( extrachill_users_ability_list_artist_dispatch_access_requests( array() ) );
		$this->assertWPError(
			extrachill_users_ability_approve_artist_dispatch_access(
				array(
					'user_id'    => 1,
					'request_id' => wp_generate_uuid4(),
				)
			)
		);
	}
}
