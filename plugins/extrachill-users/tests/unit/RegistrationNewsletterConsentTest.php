<?php
/**
 * Registration newsletter consent tests.
 *
 * @package ExtraChill\Users\Tests
 */

/** Verify explicit registration consent and Newsletter delegation. */
class RegistrationNewsletterConsentTest extends WP_UnitTestCase {

	/** Install the Newsletter contract test double. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['extrachill_test_newsletter_calls']  = array();
		$GLOBALS['extrachill_test_newsletter_result'] = array(
			'success' => true,
			'status'  => 'subscribed',
			'message' => 'Subscribed.',
		);
		add_filter(
			'extrachill_users_registration_newsletter_subscriber',
			static function () {
				return static function ( $email, $context, $source_url = '' ) {
					$GLOBALS['extrachill_test_newsletter_calls'][] = array(
						'email'      => $email,
						'context'    => $context,
						'source_url' => $source_url,
					);
					return $GLOBALS['extrachill_test_newsletter_result'];
				};
			}
		);
	}

	/** Omitted consent is equivalent to explicit false and has no side effect. */
	public function test_omitted_or_false_consent_records_no_subscription(): void {
		$user_id = self::factory()->user->create();

		$receipt = extrachill_users_record_registration_newsletter_consent( $user_id, 'person@example.com', false, 'web', 'standard' );

		$this->assertFalse( $receipt['consented'] );
		$this->assertSame( 'not_requested', $receipt['delivery_status'] );
		$this->assertSame( EXTRACHILL_USERS_NEWSLETTER_CONSENT_POLICY, $receipt['policy'] );
		$this->assertNotEmpty( $receipt['recorded_at'] );
		$this->assertSame( array(), $GLOBALS['extrachill_test_newsletter_calls'] );
		$this->assertSame( $receipt, get_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, true ) );
	}

	/** Affirmative consent delegates once and repeated processing is idempotent. */
	public function test_affirmative_consent_subscribes_once_and_persists_receipt(): void {
		$user_id = self::factory()->user->create();

		$first  = extrachill_users_record_registration_newsletter_consent( $user_id, 'person@example.com', true, 'app', 'standard', 'https://extrachill.com/join/' );
		$second = extrachill_users_record_registration_newsletter_consent( $user_id, 'person@example.com', true, 'app', 'standard', 'https://extrachill.com/join/' );

		$this->assertTrue( $first['consented'] );
		$this->assertSame( 'subscribed', $first['delivery_status'] );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $GLOBALS['extrachill_test_newsletter_calls'] );
		$this->assertSame( 'registration', $GLOBALS['extrachill_test_newsletter_calls'][0]['context'] );
	}

	/** Delivery failure remains non-fatal and preserves the consent receipt. */
	public function test_delivery_failure_does_not_erase_affirmative_consent(): void {
		$user_id = self::factory()->user->create();
		$GLOBALS['extrachill_test_newsletter_result'] = array(
			'success' => false,
			'status'  => 'error',
			'message' => 'Unavailable.',
		);

		$receipt = extrachill_users_record_registration_newsletter_consent( $user_id, 'person@example.com', true );

		$this->assertTrue( $receipt['consented'] );
		$this->assertSame( 'failed', $receipt['delivery_status'] );
		$this->assertSame( $receipt, get_user_meta( $user_id, EXTRACHILL_USERS_NEWSLETTER_CONSENT_META_KEY, true ) );
	}
}
