<?php

require_once __DIR__ . '/support/base-test-case.php';

final class AbilityAuthorizationTest extends EC_Artist_Platform_TestCase {
	private $admin_user_id;
	private $super_admin_user_id;
	private $owner_user_id;
	private $artist_id;
	private $second_artist_id;

	/**
	 * Reflection-read permission callback names keyed by ability name.
	 *
	 * @return array<string,string>
	 */
	private function permission_callback_names(): array {
		$registry = WP_Abilities_Registry::get_instance();
		$names    = array();
		foreach ( $registry->get_all_registered() as $name => $ability ) {
			if ( 0 !== strpos( (string) $name, 'extrachill/' ) ) {
				continue;
			}
			$property = new ReflectionProperty( WP_Ability::class, 'permission_callback' );
			$property->setAccessible( true );
			$callback = $property->getValue( $ability );
			if ( is_string( $callback ) ) {
				$names[ $name ] = $callback;
			} elseif ( $callback instanceof Closure ) {
				$names[ $name ] = ( new ReflectionFunction( $callback ) )->getName();
			} else {
				$names[ $name ] = '';
			}
		}
		return $names;
	}

	/**
	 * The artist-owned ability matrix with the real owned artist ID.
	 *
	 * @return array<string,array{0:array,1:string}>
	 */
	private function artistAbilityMatrix(): array {
		$id = $this->artist_id;
		return array(
			'extrachill/get-artist-data'           => array( array( 'artist_id' => $id ), 'extrachill_artist_platform_ability_get_artist_data' ),
			'extrachill/get-link-page-data'        => array( array( 'artist_id' => $id ), 'extrachill_artist_platform_ability_get_link_page_data' ),
			'extrachill/update-artist'             => array(
				array(
					'artist_id' => $id,
					'name'      => 'Band',
				),
				'extrachill_artist_platform_ability_update_artist',
			),
			'extrachill/save-link-page-links'      => array(
				array(
					'artist_id' => $id,
					'links'     => array(),
				),
				'extrachill_artist_platform_ability_save_link_page_links',
			),
			'extrachill/save-link-page-styles'     => array(
				array(
					'artist_id' => $id,
					'css_vars'  => array(),
				),
				'extrachill_artist_platform_ability_save_link_page_styles',
			),
			'extrachill/save-link-page-settings'   => array(
				array(
					'artist_id' => $id,
					'bio'       => 'Bio',
				),
				'extrachill_artist_platform_ability_save_link_page_settings',
			),
			'extrachill/save-social-links'         => array(
				array(
					'artist_id'    => $id,
					'social_links' => array(),
				),
				'extrachill_artist_platform_ability_save_social_links',
			),
			'extrachill/artist-get-links'          => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_get_links' ),
			'extrachill/artist-get-local-support-availability' => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_get_local_support_availability' ),
			'extrachill/artist-update-local-support-availability' => array(
				array(
					'id'        => $id,
					'available' => true,
				),
				'extrachill_artist_platform_ability_update_local_support_availability',
			),
			'extrachill/artist-update-links'       => array(
				array(
					'id'    => $id,
					'links' => array(),
				),
				'extrachill_artist_platform_ability_artist_update_links',
			),
			'extrachill/artist-get-roster'         => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_get_roster' ),
			'extrachill/artist-list-socials'       => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_list_socials' ),
			'extrachill/artist-create-social'      => array(
				array(
					'id'   => $id,
					'type' => 'website',
					'url'  => 'https://example.com',
				),
				'extrachill_artist_platform_ability_artist_create_social',
			),
			'extrachill/artist-update-social'      => array(
				array(
					'id'        => $id,
					'social_id' => $id . '-social-1',
				),
				'extrachill_artist_platform_ability_artist_update_social',
			),
			'extrachill/artist-delete-social'      => array(
				array(
					'id'        => $id,
					'social_id' => $id . '-social-1',
				),
				'extrachill_artist_platform_ability_artist_delete_social',
			),
			'extrachill/artist-list-subscribers'   => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_list_subscribers' ),
			'extrachill/artist-export-subscribers' => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_export_subscribers' ),
			'extrachill/artist-get-analytics'      => array( array( 'id' => $id ), 'extrachill_artist_platform_ability_artist_get_analytics' ),
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$this->owner_user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$bound               = $this->create_bound_artist( 'Authorized Artist' );
		$this->artist_id     = $bound['profile_id'];

		$this->second_artist_id    = $this->create_artist_profile( 'Other Artist' );
		$this->admin_user_id       = $this->create_admin_user( 'admin' );
		$this->super_admin_user_id = $this->create_admin_user( 'superadmin' );
		$this->unrelated_user_id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** @var int */
	private $unrelated_user_id;

	public function test_artist_owned_abilities_use_target_aware_permissions(): void {
		foreach ( $this->artistAbilityMatrix() as $name => [ $input ] ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' is not registered.' );

			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an anonymous user.' );

			wp_set_current_user( $this->owner_user_id );
			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an unrelated user.' );

			$this->create_artist_membership( $this->owner_user_id, $this->artist_id );
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied the artist owner.' );

			wp_set_current_user( $this->super_admin_user_id );
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied a network administrator.' );

			// Reset both sides of the relationship for the next matrix row.
			wp_set_current_user( 0 );
			delete_user_meta( $this->owner_user_id, '_artist_profile_ids' );
			switch_to_blog( $this->artist_blog_id() );
			delete_post_meta( $this->artist_id, '_artist_member_ids' );
			restore_current_blog();
		}
	}

	public function test_every_sensitive_registration_uses_an_approved_permission_contract(): void {
		$public  = array(
			'extrachill/onboard-external-artist',
			'extrachill/artist-invitation',
			'extrachill/artists-list',
			'extrachill/artist-get',
			'extrachill/artist-public-projections',
			'extrachill/artist-get-permissions',
			'extrachill/artist-subscribe',
			'extrachill/artist-query-local-support-candidates',
		);
		$special = array(
			'extrachill/create-artist',
			'extrachill/get-artist-platform-stats',
			'extrachill/admin-list-artist-relationships',
			'extrachill/admin-link-artist-relationship',
			'extrachill/admin-unlink-artist-relationship',
			'extrachill/admin-list-orphan-artist-relationships',
			'extrachill/admin-cleanup-artist-relationships',
		);

		$expected_artist_abilities = array_keys( $this->artistAbilityMatrix() );
		$actual_artist_abilities   = array();
		foreach ( $this->permission_callback_names() as $name => $callback_name ) {
			if ( ! str_starts_with( $callback_name, 'extrachill_artist_platform_' ) ) {
				// Sibling plugins in the harness register their own abilities.
				continue;
			}

			if ( 'extrachill_artist_platform_ability_artist_permission' === $callback_name ) {
				$actual_artist_abilities[] = $name;
				continue;
			}

			$this->assertContains( $name, array_merge( $public, $special ), $name . ' introduced an unclassified authorization contract.' );
		}

		sort( $expected_artist_abilities );
		sort( $actual_artist_abilities );
		$this->assertSame( $expected_artist_abilities, $actual_artist_abilities );
	}

	public function test_handlers_fail_closed_when_called_directly(): void {
		wp_set_current_user( $this->owner_user_id );

		foreach ( $this->artistAbilityMatrix() as $name => [ $input, $handler ] ) {
			$result = $handler( $input );
			$this->assertInstanceOf( WP_Error::class, $result, $name . ' direct callback did not fail closed.' );
			$this->assertSame( 'artist_access_denied', $result->get_error_code(), $name . ' returned the wrong denial.' );
		}
	}

	public function test_execution_principal_overrides_ambient_session_and_enforces_ceiling(): void {
		wp_set_current_user( $this->super_admin_user_id );
		$this->create_artist_membership( $this->owner_user_id, $this->artist_id );
		$ability = wp_get_ability( 'extrachill/update-artist' );
		$input   = array(
			'artist_id' => $this->artist_id,
			'name'      => 'Band',
		);

		$unrelated = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->unrelated_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $unrelated,
			10
		);
		$this->assertFalse( $ability->check_permissions( $input ), 'Ambient administrator leaked into an unrelated principal.' );
		remove_all_filters( 'agents_api_execution_principal' );

		$restricted = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->owner_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
			capability_ceiling: new WP_Agent_Capability_Ceiling( $this->owner_user_id, array() )
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $restricted,
			10
		);
		$this->assertFalse( $ability->check_permissions( $input ), 'Restricted principal exceeded its capability ceiling.' );
		remove_all_filters( 'agents_api_execution_principal' );

		$mismatched = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->owner_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
			capability_ceiling: new WP_Agent_Capability_Ceiling( $this->owner_user_id + 1, array( 'manage_artist' ) )
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $mismatched,
			10
		);
		$this->assertFalse( $ability->check_permissions( $input ), 'Mismatched ceiling identity was accepted.' );
		remove_all_filters( 'agents_api_execution_principal' );

		$authorized = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->owner_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
			capability_ceiling: new WP_Agent_Capability_Ceiling( $this->owner_user_id, array( 'manage_artist' ) )
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $authorized,
			10
		);
		$this->assertTrue( $ability->check_permissions( $input ) );
		$this->assertFalse(
			$ability->check_permissions( $input + array( 'user_id' => $this->owner_user_id + 1 ) ),
			'Spoofed user_id was accepted.'
		);
		remove_all_filters( 'agents_api_execution_principal' );
	}

	public function test_system_principal_preserves_trusted_cli_execution_only(): void {
		$ability = wp_get_ability( 'extrachill/update-artist' );
		$input   = array(
			'artist_id' => $this->artist_id,
			'name'      => 'Band',
		);

		$system = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			0,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_SYSTEM,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_CLI
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $system,
			10
		);
		$this->assertTrue( $ability->check_permissions( $input ) );
		remove_all_filters( 'agents_api_execution_principal' );

		$runtime = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			0,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_RUNTIME,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_RUNTIME
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $runtime,
			10
		);
		$this->assertFalse( $ability->check_permissions( $input ) );
		remove_all_filters( 'agents_api_execution_principal' );
	}

	public function test_missing_and_unpublished_targets_fail_canonical_authorization(): void {
		wp_set_current_user( $this->owner_user_id );
		$missing_artist_id = $this->second_artist_id + 500;
		$draft_artist_id   = $this->create_artist_profile( 'Draft Artist', array( 'post_status' => 'draft' ) );

		// The owner's reciprocal record claims ownership of the bound artist,
		// a missing artist ID, and a draft profile. Only reciprocal PUBLISHED
		// membership is manageable, so all three must be denied.
		switch_to_blog( $this->artist_blog_id() );
		delete_post_meta( $this->artist_id, '_artist_member_ids' );
		restore_current_blog();
		update_user_meta(
			$this->owner_user_id,
			'_artist_profile_ids',
			array( $this->artist_id, $missing_artist_id, $draft_artist_id )
		);

		$this->assertFalse( wp_get_ability( 'extrachill/update-artist' )->check_permissions( array( 'artist_id' => $this->artist_id ) ) );
		$this->assertFalse( wp_get_ability( 'extrachill/update-artist' )->check_permissions( array( 'artist_id' => $missing_artist_id ) ) );
		$this->assertFalse( wp_get_ability( 'extrachill/update-artist' )->check_permissions( array( 'artist_id' => $draft_artist_id ) ) );
	}

	public function test_explicit_link_page_must_map_to_the_authorized_artist(): void {
		wp_set_current_user( $this->owner_user_id );
		$this->create_artist_membership( $this->owner_user_id, $this->artist_id );

		$other_profile_id = $this->second_artist_id;
		switch_to_blog( $this->artist_blog_id() );
		$link_page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $link_page_id, '_associated_artist_profile_id', $other_profile_id );
		restore_current_blog();

		$result = extrachill_artist_platform_ability_get_link_page_data( array(
			'artist_id'    => $this->artist_id,
			'link_page_id' => $link_page_id,
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_link_page', $result->get_error_code() );
	}

	public function test_legacy_match_cannot_override_conflicting_canonical_owner(): void {
		wp_set_current_user( $this->owner_user_id );
		$this->create_artist_membership( $this->owner_user_id, $this->artist_id );

		$other_profile_id = $this->second_artist_id;
		switch_to_blog( $this->artist_blog_id() );
		$link_page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'artist_link_page',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $link_page_id, '_associated_artist_profile_id', $this->artist_id );
		update_post_meta( $link_page_id, EC_LINK_PAGE_OWNER_META_KEY, 'post:' . $this->artist_blog_id() . ':artist_profile:' . $other_profile_id );
		restore_current_blog();

		$this->assertFalse( extrachill_artist_platform_ability_link_page_belongs_to_artist( $this->artist_id, $link_page_id ) );
		$result = extrachill_artist_platform_ability_get_link_page_data( array(
			'artist_id'    => $this->artist_id,
			'link_page_id' => $link_page_id,
		) );
		$this->assertSame( 'invalid_link_page', $result->get_error_code() );
	}

	public function test_rest_exposed_sensitive_abilities_deny_unrelated_users_before_handlers(): void {
		wp_set_current_user( $this->unrelated_user_id );

		foreach ( $this->artistAbilityMatrix() as $name => [ $input ] ) {
			$ability = wp_get_ability( $name );
			$this->assertTrue( $ability->get_meta()['show_in_rest'], $name . ' is expected on the ability REST route.' );
			$this->assertSame( 'ability_invalid_permissions', $ability->execute( $input )->get_error_code(), $name . ' REST execution was not denied.' );
		}
	}

	public function test_create_artist_only_allows_self_or_administrator(): void {
		$ability = wp_get_ability( 'extrachill/create-artist' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Band' ) ) );

		wp_set_current_user( $this->owner_user_id );
		update_user_meta( $this->owner_user_id, 'user_is_artist', '1' );
		$this->assertTrue( $ability->check_permissions( array( 'name' => 'Band' ) ) );
		$this->assertTrue( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id,
		) ) );
		$this->assertFalse( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id + 1,
		) ) );

		wp_set_current_user( $this->super_admin_user_id );
		$this->assertTrue( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id,
		) ) );
	}

	public function test_create_artist_binds_user_claim_to_the_execution_principal(): void {
		wp_set_current_user( $this->super_admin_user_id );
		$ability = wp_get_ability( 'extrachill/create-artist' );

		$principal = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->owner_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
			capability_ceiling: new WP_Agent_Capability_Ceiling( $this->owner_user_id, array( 'create_artist_profile' ) )
		);
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $principal,
			10
		);
		update_user_meta( $this->owner_user_id, 'user_is_artist', '1' );
		$this->assertTrue( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id,
		) ) );
		$this->assertFalse( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id + 1,
		) ) );
		$this->assertSame(
			'artist_access_denied',
			extrachill_artist_platform_ability_create_artist( array(
				'name'    => 'Band',
				'user_id' => $this->owner_user_id + 1,
			) )->get_error_code()
		);

		$empty_ceiling = new AgentsAPI\AI\WP_Agent_Execution_Principal(
			$this->owner_user_id,
			'test-agent',
			AgentsAPI\AI\WP_Agent_Execution_Principal::AUTH_SOURCE_AGENT_TOKEN,
			AgentsAPI\AI\WP_Agent_Execution_Principal::REQUEST_CONTEXT_REST,
			capability_ceiling: new WP_Agent_Capability_Ceiling( $this->owner_user_id, array() )
		);
		remove_all_filters( 'agents_api_execution_principal' );
		$this->ec_inject_filter(
			'agents_api_execution_principal',
			static fn() => $empty_ceiling,
			10
		);
		$this->assertFalse( $ability->check_permissions( array(
			'name'    => 'Band',
			'user_id' => $this->owner_user_id,
		) ) );
	}

	public function test_create_artist_rolls_back_profile_when_membership_fails(): void {
		// Rollback deletes the profile, which the binding layer only permits
		// where canonical binding serialization is available.
		$this->ec_require_mysql_advisory_locks();
		wp_set_current_user( $this->owner_user_id );
		update_user_meta( $this->owner_user_id, 'user_is_artist', '1' );

		switch_to_blog( $this->artist_blog_id() );
		$created_before = (int) wp_count_posts( 'artist_profile' )->publish;
		restore_current_blog();

		$this->fail_meta_adds( 'post', 10, '_artist_member_ids' );

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Rollback Band' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_roster_update_failed', $result->get_error_code() );

		switch_to_blog( $this->artist_blog_id() );
		$rollback_posts = get_posts(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'any',
				'title'       => 'Rollback Band',
				'fields'      => 'ids',
			)
		);
		$profiles       = get_user_meta( $this->owner_user_id, '_artist_profile_ids', true );
		$created_after  = (int) wp_count_posts( 'artist_profile' )->publish;
		restore_current_blog();

		$this->assertSame( array(), $rollback_posts, 'The rolled-back profile must not remain.' );
		$this->assertSame( $created_before, $created_after );
		$this->assertEmpty( array_filter( (array) $profiles ) );
	}

	public function test_create_artist_reports_failed_profile_rollback(): void {
		$this->ec_require_mysql_advisory_locks();
		wp_set_current_user( $this->owner_user_id );
		update_user_meta( $this->owner_user_id, 'user_is_artist', '1' );

		$this->fail_meta_adds( 'post', 10, '_artist_member_ids' );
		$this->fail_post_deletes();

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Stranded Band' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_creation_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );

		switch_to_blog( $this->artist_blog_id() );
		$stranded = get_posts(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'any',
				'title'       => 'Stranded Band',
				'fields'      => 'ids',
			)
		);
		restore_current_blog();
		$this->assertNotEmpty( $stranded, 'The un-rolled-back profile must remain for manual repair.' );
	}

	public function test_public_artist_reads_remain_public(): void {
		$abilities = array(
			'extrachill/artists-list'              => array(),
			'extrachill/artist-get'                => array( 'id' => $this->artist_id ),
			'extrachill/artist-public-projections' => array(
				'schema_version' => '1',
				'slugs'          => array( 'test-artist' ),
			),
			'extrachill/artist-get-permissions'    => array( 'id' => $this->artist_id ),
			'extrachill/artist-subscribe'          => array(
				'id'    => $this->artist_id,
				'email' => 'fan@example.com',
			),
		);

		foreach ( $abilities as $name => $input ) {
			$this->assertTrue( wp_get_ability( $name )->check_permissions( $input ), $name . ' is intentionally public.' );
		}
	}

	public function test_subscriber_export_is_annotated_as_mutating(): void {
		$meta = wp_get_ability( 'extrachill/artist-export-subscribers' )->get_meta();

		$this->assertFalse( $meta['annotations']['readonly'] );
	}
}
