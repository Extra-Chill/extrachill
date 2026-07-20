<?php
/**
 * Contract tests for Local Scene REST adapters.
 *
 * @package ExtraChill\API\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies that REST adapters mirror the Users-owned scene contract.
 */
class UserLocalSceneAdaptersTest extends TestCase {

	/**
	 * Settings registration includes canonical fields and aliases.
	 */
	public function test_settings_route_exposes_canonical_and_compatibility_fields() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/users/settings.php' );

		$this->assertStringContainsString( "'local_scene'", $source );
		$this->assertStringContainsString( "'local_scene_visibility'", $source );
		$this->assertStringContainsString( "'default_event_location'", $source );
		$this->assertStringContainsString( "array( 'public', 'private' )", $source );
	}

	/**
	 * The adapter forwards every supported scene setting.
	 */
	public function test_settings_adapter_forwards_canonical_and_compatibility_fields() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/users/settings.php' );

		$this->assertStringContainsString(
			"array( 'first_name', 'last_name', 'display_name', 'local_scene', 'local_scene_visibility', 'concert_history_visibility', 'event_attendance_visibility', 'default_event_location' )",
			$source
		);
	}

	/**
	 * Public reads rely on the Users-owned privacy implementation.
	 */
	public function test_public_user_route_delegates_profile_privacy_to_users() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/users/users.php' );

		$this->assertStringContainsString( "wp_get_ability( 'extrachill/get-user-profile' )", $source );
		$this->assertStringNotContainsString( 'get_user_meta(', $source );
		$this->assertStringNotContainsString( 'extrachill_api_build_user_response', $source );
	}

	/**
	 * Existing local_city clients remain supported by profile updates.
	 */
	public function test_legacy_local_city_profile_update_remains_available() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/inc/routes/users/profile.php' );

		$this->assertStringContainsString( "'local_city'", $source );
		$this->assertStringContainsString( "\$input['local_city']", $source );
	}
}
