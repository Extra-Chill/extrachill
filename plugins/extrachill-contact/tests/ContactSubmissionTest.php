<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContactSubmissionTest extends TestCase {
	protected function setUp(): void {
		if ( defined( 'WPINC' ) ) {
			$this->markTestSkipped( 'Provider behavior tests use isolated fakes; WordPress integration coverage is non-mutating.' );
		}

		$GLOBALS['ec_test_transients']        = array();
		$GLOBALS['ec_test_locks']             = array();
		$GLOBALS['ec_test_email_calls']       = 0;
		$GLOBALS['ec_test_newsletter_calls']  = 0;
		$GLOBALS['ec_test_email_results']     = array();
		$GLOBALS['ec_test_newsletter_result'] = array(
			'success' => true,
			'status'  => 'subscribed',
			'message' => 'Subscribed.',
		);
	}

	/** @return array<string, string> */
	private function input(): array {
		return array(
			'name'               => 'Listener',
			'email'              => 'listener@example.com',
			'subject'            => 'General Inquiry',
			'message'            => 'Hello Extra Chill.',
			'turnstile_response' => 'valid-token',
		);
	}

	/** @return array<string, mixed> */
	private function delivered(): array {
		return array(
			'success' => true,
			'message' => 'Accepted.',
		);
	}

	public function test_success_returns_all_side_effect_outcomes(): void {
		$GLOBALS['ec_test_email_results'] = array( $this->delivered(), $this->delivered() );

		$result = extrachill_contact_ability_submit( $this->input() );

		self::assertIsArray( $result );
		self::assertTrue( $result['success'] );
		self::assertSame( 'delivered', $result['side_effects']['administrator_delivery']['status'] );
		self::assertSame( 'delivered', $result['side_effects']['confirmation_delivery']['status'] );
		self::assertSame( 'subscribed', $result['side_effects']['newsletter_sync']['status'] );
	}

	public function test_confirmation_failure_is_observable_partial_failure(): void {
		$GLOBALS['ec_test_email_results'] = array(
			$this->delivered(),
			array(
				'success' => false,
				'error'   => 'SMTP rejected.',
			),
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		self::assertIsArray( $result );
		self::assertTrue( $result['success'] );
		self::assertFalse( $result['side_effects']['confirmation_delivery']['success'] );
		self::assertSame( 'failed', $result['side_effects']['confirmation_delivery']['status'] );
	}

	public function test_administrator_failure_is_total_submission_failure(): void {
		$GLOBALS['ec_test_email_results'] = array(
			array(
				'success' => false,
				'error'   => 'SMTP unavailable.',
			),
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'contact_administrator_delivery_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 502, $data['status'] );
		self::assertSame( 'skipped', $data['submission']['side_effects']['confirmation_delivery']['status'] );
		self::assertSame( 0, $GLOBALS['ec_test_newsletter_calls'] );
	}

	public function test_provider_unavailable_is_explicit(): void {
		$GLOBALS['ec_test_email_results'] = array( new WP_Error( 'email_provider_unavailable', 'Provider missing.' ) );

		$result = extrachill_contact_ability_submit( $this->input() );
		$data   = $result->get_error_data();

		self::assertSame( 'provider_unavailable', $data['submission']['side_effects']['administrator_delivery']['status'] );
	}

	public function test_failed_administrator_delivery_can_retry_without_other_duplicates(): void {
		$GLOBALS['ec_test_email_results'] = array(
			array(
				'success' => false,
				'error'   => 'Temporary.',
			),
		);
		self::assertInstanceOf( WP_Error::class, extrachill_contact_ability_submit( $this->input() ) );

		$GLOBALS['ec_test_email_results'] = array( $this->delivered(), $this->delivered() );
		$result                           = extrachill_contact_ability_submit( $this->input() );

		self::assertIsArray( $result );
		self::assertTrue( $result['replayed'] );
		self::assertSame( 3, $GLOBALS['ec_test_email_calls'] );
		self::assertSame( 1, $GLOBALS['ec_test_newsletter_calls'] );
	}

	public function test_successful_replay_does_not_repeat_any_side_effect(): void {
		$GLOBALS['ec_test_email_results'] = array( $this->delivered(), $this->delivered() );
		self::assertIsArray( extrachill_contact_ability_submit( $this->input() ) );

		$result = extrachill_contact_ability_submit( $this->input() );

		self::assertTrue( $result['replayed'] );
		self::assertSame( 2, $GLOBALS['ec_test_email_calls'] );
		self::assertSame( 1, $GLOBALS['ec_test_newsletter_calls'] );
	}

	public function test_already_subscribed_is_idempotent_success(): void {
		$GLOBALS['ec_test_email_results']     = array( $this->delivered(), $this->delivered() );
		$GLOBALS['ec_test_newsletter_result'] = array(
			'success' => false,
			'status'  => 'already_subscribed',
			'message' => 'Already subscribed.',
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		self::assertTrue( $result['side_effects']['newsletter_sync']['success'] );
		self::assertFalse( $result['side_effects']['newsletter_sync']['retryable'] );
	}

	public function test_malformed_provider_responses_are_rejected(): void {
		self::assertSame( 'malformed_provider_response', ec_contact_normalize_email_result( null )['status'] );
		$GLOBALS['ec_test_newsletter_result'] = null;
		self::assertSame( 'malformed_provider_response', ec_contact_sync_to_sendy( 'listener@example.com' )['status'] );
	}

	public function test_ability_schema_exposes_strict_side_effect_contract(): void {
		extrachill_contact_register_submit_ability();
		$definition = $GLOBALS['ec_test_abilities']['extrachill/contact-submit'];
		$properties = $definition['output_schema']['properties']['side_effects']['properties'];

		self::assertSame(
			array( 'administrator_delivery', 'confirmation_delivery', 'newsletter_sync' ),
			array_keys( $properties )
		);
		self::assertTrue( $definition['meta']['annotations']['idempotent'] );
	}
}
