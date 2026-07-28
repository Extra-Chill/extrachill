<?php

use PHPUnit\Framework\TestCase;

final class AbilityAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array();
		extrachill_artist_platform_register_abilities();
	}

	private function artistAbilityMatrix(): array {
		return array(
			'extrachill/get-artist-data' => array( array( 'artist_id' => 42 ), 'extrachill_artist_platform_ability_get_artist_data' ),
			'extrachill/get-link-page-data' => array( array( 'artist_id' => 42 ), 'extrachill_artist_platform_ability_get_link_page_data' ),
			'extrachill/update-artist' => array( array( 'artist_id' => 42, 'name' => 'Band' ), 'extrachill_artist_platform_ability_update_artist' ),
			'extrachill/save-link-page-links' => array( array( 'artist_id' => 42, 'links' => array() ), 'extrachill_artist_platform_ability_save_link_page_links' ),
			'extrachill/save-link-page-styles' => array( array( 'artist_id' => 42, 'css_vars' => array() ), 'extrachill_artist_platform_ability_save_link_page_styles' ),
			'extrachill/save-link-page-settings' => array( array( 'artist_id' => 42, 'bio' => 'Bio' ), 'extrachill_artist_platform_ability_save_link_page_settings' ),
			'extrachill/save-social-links' => array( array( 'artist_id' => 42, 'social_links' => array() ), 'extrachill_artist_platform_ability_save_social_links' ),
			'extrachill/artist-get-links' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_get_links' ),
			'extrachill/artist-get-local-support-availability' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_get_local_support_availability' ),
			'extrachill/artist-update-local-support-availability' => array( array( 'id' => 42, 'available' => true ), 'extrachill_artist_platform_ability_update_local_support_availability' ),
			'extrachill/artist-update-links' => array( array( 'id' => 42, 'links' => array() ), 'extrachill_artist_platform_ability_artist_update_links' ),
			'extrachill/artist-get-roster' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_get_roster' ),
			'extrachill/artist-list-socials' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_list_socials' ),
			'extrachill/artist-create-social' => array( array( 'id' => 42, 'type' => 'website', 'url' => 'https://example.com' ), 'extrachill_artist_platform_ability_artist_create_social' ),
			'extrachill/artist-update-social' => array( array( 'id' => 42, 'social_id' => '42-social-1' ), 'extrachill_artist_platform_ability_artist_update_social' ),
			'extrachill/artist-delete-social' => array( array( 'id' => 42, 'social_id' => '42-social-1' ), 'extrachill_artist_platform_ability_artist_delete_social' ),
			'extrachill/artist-list-subscribers' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_list_subscribers' ),
			'extrachill/artist-export-subscribers' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_export_subscribers' ),
			'extrachill/artist-get-analytics' => array( array( 'id' => 42 ), 'extrachill_artist_platform_ability_artist_get_analytics' ),
		);
	}

	public function test_artist_owned_abilities_use_target_aware_permissions(): void {
		foreach ( $this->artistAbilityMatrix() as $name => [ $input ] ) {

			$ability = wp_get_ability( $name );
			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an anonymous user.' );

			$GLOBALS['ec_test']['current_user_id'] = 7;
			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an unrelated user.' );

			$GLOBALS['ec_test']['managed_artists'][7] = array( 42 );
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied the artist owner.' );

			$GLOBALS['ec_test']['current_user_id'] = 9;
			$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied an administrator.' );

			$GLOBALS['ec_test']['current_user_id'] = 0;
			$GLOBALS['ec_test']['capabilities']    = array();
			$GLOBALS['ec_test']['managed_artists'] = array();
		}
	}

	public function test_every_sensitive_registration_uses_an_approved_permission_contract(): void {
		$public = array(
			'extrachill/onboard-external-artist',
			'extrachill/artist-invitation',
			'extrachill/artists-list',
			'extrachill/artist-get',
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
		foreach ( $GLOBALS['ec_test']['abilities'] as $name => $ability ) {
			if ( 'extrachill_artist_platform_ability_artist_permission' === $ability->get_permission_callback() ) {
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
		$GLOBALS['ec_test']['current_user_id'] = 7;

		foreach ( $this->artistAbilityMatrix() as $name => [ $input, $handler ] ) {
			$result = $handler( $input );
			$this->assertInstanceOf( WP_Error::class, $result, $name . ' direct callback did not fail closed.' );
			$this->assertSame( 'artist_access_denied', $result->get_error_code(), $name . ' returned the wrong denial.' );
		}
	}

	public function test_execution_principal_overrides_ambient_session_and_enforces_ceiling(): void {
		$GLOBALS['ec_test']['current_user_id']      = 1;
		$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
		$GLOBALS['ec_test']['managed_artists'][7]   = array( 42 );
		$ability = wp_get_ability( 'extrachill/update-artist' );
		$input   = array( 'artist_id' => 42, 'name' => 'Band' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 8 );
		$this->assertFalse( $ability->check_permissions( $input ), 'Ambient administrator leaked into an unrelated principal.' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'agent_token', 'rest', new WP_Agent_Capability_Ceiling( 7, array() ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Restricted principal exceeded its capability ceiling.' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'agent_token', 'rest', new WP_Agent_Capability_Ceiling( 8, array( 'manage_artist' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ), 'Mismatched ceiling identity was accepted.' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'agent_token', 'rest', new WP_Agent_Capability_Ceiling( 7, array( 'manage_artist' ) ) );
		$this->assertTrue( $ability->check_permissions( $input ) );
		$this->assertFalse( $ability->check_permissions( $input + array( 'user_id' => 8 ) ), 'Spoofed user_id was accepted.' );
	}

	public function test_system_principal_preserves_trusted_cli_execution_only(): void {
		$ability = wp_get_ability( 'extrachill/update-artist' );
		$input   = array( 'artist_id' => 42, 'name' => 'Band' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 0, 'system', 'cli' );
		$this->assertTrue( $ability->check_permissions( $input ) );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 0, 'runtime', 'runtime' );
		$this->assertFalse( $ability->check_permissions( $input ) );
	}

	public function test_missing_and_unpublished_targets_fail_canonical_authorization(): void {
		$GLOBALS['ec_test']['current_user_id']       = 7;
		$GLOBALS['ec_test']['managed_artists'][7]    = array( 42, 43 );
		$GLOBALS['ec_test']['strict_artist_objects'] = true;
		$GLOBALS['ec_test']['blogs'][4]['posts'][43] = (object) array( 'ID' => 43, 'post_type' => 'artist_profile', 'post_status' => 'draft' );

		$this->assertFalse( wp_get_ability( 'extrachill/update-artist' )->check_permissions( array( 'artist_id' => 42 ) ) );
		$this->assertFalse( wp_get_ability( 'extrachill/update-artist' )->check_permissions( array( 'artist_id' => 43 ) ) );
	}

	public function test_explicit_link_page_must_map_to_the_authorized_artist(): void {
		$GLOBALS['ec_test']['current_user_id']    = 7;
		$GLOBALS['ec_test']['managed_artists'][7] = array( 42 );
		$GLOBALS['ec_test']['blogs'][4] = array(
			'posts' => array(
				42 => (object) array( 'ID' => 42, 'post_type' => 'artist_profile', 'post_status' => 'publish' ),
				99 => (object) array( 'ID' => 99, 'post_type' => 'artist_link_page', 'post_status' => 'publish' ),
			),
			'post_meta' => array( 99 => array( '_associated_artist_profile_id' => 84 ) ),
		);

		$result = extrachill_artist_platform_ability_get_link_page_data( array( 'artist_id' => 42, 'link_page_id' => 99 ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_link_page', $result->get_error_code() );
	}

	public function test_rest_exposed_sensitive_abilities_deny_unrelated_users_before_handlers(): void {
		$GLOBALS['ec_test']['current_user_id'] = 7;

		foreach ( $this->artistAbilityMatrix() as $name => [ $input ] ) {
			$ability = wp_get_ability( $name );
			$this->assertTrue( $ability->get_meta()['show_in_rest'], $name . ' is expected on the ability REST route.' );
			$this->assertSame( 'ability_permission_denied', $ability->execute( $input )->get_error_code(), $name . ' REST execution was not denied.' );
		}
	}

	public function test_create_artist_only_allows_self_or_administrator(): void {
		$ability = wp_get_ability( 'extrachill/create-artist' );

		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Band' ) ) );

		$GLOBALS['ec_test']['current_user_id'] = 7;
		$this->assertTrue( $ability->check_permissions( array( 'name' => 'Band' ) ) );
		$this->assertTrue( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 7 ) ) );
		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 8 ) ) );

		$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
		$this->assertTrue( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 8 ) ) );
	}

	public function test_create_artist_binds_user_claim_to_the_execution_principal(): void {
		$GLOBALS['ec_test']['current_user_id'] = 1;
		$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
		$ability = wp_get_ability( 'extrachill/create-artist' );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'agent_token', 'rest', new WP_Agent_Capability_Ceiling( 7, array( 'create_artist_profile' ) ) );
		$this->assertTrue( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 7 ) ) );
		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 8 ) ) );
		$this->assertSame( 'artist_access_denied', extrachill_artist_platform_ability_create_artist( array( 'name' => 'Band', 'user_id' => 8 ) )->get_error_code() );

		$GLOBALS['ec_test']['execution_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, 'agent_token', 'rest', new WP_Agent_Capability_Ceiling( 7, array() ) );
		$this->assertFalse( $ability->check_permissions( array( 'name' => 'Band', 'user_id' => 7 ) ) );
	}

	public function test_create_artist_rolls_back_profile_when_membership_fails(): void {
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['current_blog_id'] = 1;
		$GLOBALS['ec_test']['blog_stack']      = array();
		$GLOBALS['ec_test']['blogs'][4]        = array( 'posts' => array(), 'post_meta' => array() );
		$GLOBALS['ec_test']['fail_post_meta_add'] = true;

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Rollback Band' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_roster_update_failed', $result->get_error_code() );
		$this->assertSame( array( 1 ), $GLOBALS['ec_test']['deleted_posts'] );
		$this->assertSame( array(), $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_create_artist_reports_failed_profile_rollback(): void {
		$GLOBALS['ec_test']['current_user_id'] = 7;
		$GLOBALS['ec_test']['current_blog_id'] = 1;
		$GLOBALS['ec_test']['blog_stack']      = array();
		$GLOBALS['ec_test']['blogs'][4]        = array( 'posts' => array(), 'post_meta' => array() );
		$GLOBALS['ec_test']['fail_post_meta_add'] = true;
		$GLOBALS['ec_test']['fail_post_delete']   = true;

		$result = extrachill_artist_platform_ability_create_artist( array( 'name' => 'Stranded Band' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'artist_creation_rollback_failed', $result->get_error_code() );
		$this->assertFalse( $result->get_error_data()['retryable'] );
		$this->assertArrayHasKey( 1, $GLOBALS['ec_test']['blogs'][4]['posts'] );
	}

	public function test_public_artist_reads_remain_public(): void {
		$abilities = array(
			'extrachill/artists-list'          => array(),
			'extrachill/artist-get'            => array( 'id' => 42 ),
			'extrachill/artist-get-permissions' => array( 'id' => 42 ),
			'extrachill/artist-subscribe'      => array( 'id' => 42, 'email' => 'fan@example.com' ),
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
