<?php
/**
 * Tests for private artist email-sharing preferences.
 *
 * @package ExtraChill\Users
 */

class Test_Artist_Email_Consent_Abilities extends WP_UnitTestCase {

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
}
