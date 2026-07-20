<?php

use PHPUnit\Framework\TestCase;

final class AbilityAuthorizationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_test'] = array();
		extrachill_artist_platform_register_abilities();
	}

	public function test_artist_owned_abilities_use_target_aware_permissions(): void {
		$abilities = array(
			'extrachill/get-artist-data'            => array( 'artist_id' => 42 ),
			'extrachill/get-link-page-data'         => array( 'artist_id' => 42 ),
			'extrachill/update-artist'              => array( 'artist_id' => 42 ),
			'extrachill/save-link-page-links'       => array( 'artist_id' => 42 ),
			'extrachill/save-link-page-styles'      => array( 'artist_id' => 42 ),
			'extrachill/save-link-page-settings'    => array( 'artist_id' => 42 ),
			'extrachill/save-social-links'          => array( 'artist_id' => 42 ),
			'extrachill/artist-update-links'        => array( 'id' => 42 ),
			'extrachill/artist-get-links'           => array( 'id' => 42 ),
			'extrachill/artist-get-roster'          => array( 'id' => 42 ),
			'extrachill/artist-list-socials'        => array( 'id' => 42 ),
			'extrachill/artist-create-social'       => array( 'id' => 42 ),
			'extrachill/artist-update-social'       => array( 'id' => 42 ),
			'extrachill/artist-delete-social'       => array( 'id' => 42 ),
			'extrachill/artist-list-subscribers'    => array( 'id' => 42 ),
			'extrachill/artist-export-subscribers'  => array( 'id' => 42 ),
			'extrachill/artist-get-analytics'       => array( 'id' => 42 ),
		);

		foreach ( $abilities as $name => $input ) {
			$ability = wp_get_ability( $name );
			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an anonymous user.' );

			$GLOBALS['ec_test']['current_user_id'] = 7;
			$this->assertFalse( $ability->check_permissions( $input ), $name . ' allowed an unrelated user.' );

			$GLOBALS['ec_test']['managed_artists'][7] = array( 42 );
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied the artist owner.' );

			$GLOBALS['ec_test']['current_user_id']          = 9;
			$GLOBALS['ec_test']['capabilities']['manage_options'] = true;
			$this->assertTrue( $ability->check_permissions( $input ), $name . ' denied an administrator.' );

			$GLOBALS['ec_test']['current_user_id'] = 0;
			$GLOBALS['ec_test']['capabilities']    = array();
			$GLOBALS['ec_test']['managed_artists'] = array();
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

	public function test_artist_mutation_handlers_recheck_access_before_state_changes(): void {
		$GLOBALS['ec_test']['current_user_id'] = 7;

		$mutations = array(
			'extrachill_artist_platform_ability_create_artist' => array( 'name' => 'Band', 'user_id' => 8 ),
			'extrachill_artist_platform_ability_update_artist' => array( 'artist_id' => 42, 'name' => 'Unauthorized Rename' ),
			'extrachill_artist_platform_ability_save_link_page_links' => array( 'artist_id' => 42, 'links' => array() ),
			'extrachill_artist_platform_ability_save_social_links' => array( 'artist_id' => 42, 'social_links' => array() ),
			'extrachill_artist_platform_ability_artist_export_subscribers' => array( 'id' => 42 ),
		);

		foreach ( $mutations as $handler => $input ) {
			$result = $handler( $input );

			$this->assertInstanceOf( WP_Error::class, $result, $handler . ' did not fail closed.' );
			$this->assertSame( 'artist_access_denied', $result->get_error_code(), $handler . ' returned the wrong denial.' );
		}
	}

	public function test_subscriber_export_is_annotated_as_mutating(): void {
		$meta = wp_get_ability( 'extrachill/artist-export-subscribers' )->get_meta();

		$this->assertFalse( $meta['annotations']['readonly'] );
	}
}
