<?php
/**
 * Contact submission behavior against real WordPress.
 *
 * Only the two true externals are intercepted, through core's documented
 * test-mocking seam (`wp_pre_execute_ability`): mail (`datamachine/send-email`,
 * reached through extrachill-network's ec_send_email()) and newsletter sync
 * (`extrachill/subscribe`, reached through extrachill_network_subscribe()).
 * Transients, cache locks, sanitization, referer handling, and ability
 * registration are all real.
 *
 * @package ExtraChillContact
 */

declare(strict_types=1);

class Test_Contact_Submission extends WP_UnitTestCase {

	/** @var array<int, mixed> Queued results for datamachine/send-email, in call order. */
	private array $email_results = array();

	private int $email_calls = 0;

	/** @var mixed Result returned by extrachill/subscribe. */
	private $newsletter_result;

	private int $newsletter_calls = 0;

	/** @var array<int, array<string, mixed>> Inputs received by extrachill/subscribe. */
	private array $newsletter_inputs = array();

	public function set_up(): void {
		parent::set_up();

		foreach ( array( 'datamachine/send-email', 'extrachill/subscribe', 'extrachill/contact-submit' ) as $ability ) {
			$this->assertNotNull( wp_get_ability( $ability ), "Ability {$ability} must be registered in the test runtime." );
		}

		$this->newsletter_result = array(
			'success' => true,
			'status'  => 'subscribed',
			'message' => 'Subscribed.',
		);

		add_filter( 'wp_pre_execute_ability', array( $this, 'intercept_externals' ), 10, 3 );
		add_filter( 'extrachill_bypass_turnstile_verification', '__return_true' );
		add_filter(
			'allowed_redirect_hosts',
			static function ( array $hosts ): array {
				$hosts[] = 'extrachill.com';
				return $hosts;
			}
		);
	}

	/**
	 * Short-circuit the external abilities; let every other ability run.
	 *
	 * @param mixed  $pre   Sentinel, or a prior short-circuit value.
	 * @param string $name  Ability name.
	 * @param mixed  $input Raw ability input.
	 * @return mixed
	 */
	public function intercept_externals( $pre, $name, $input ) {
		if ( 'datamachine/send-email' === $name ) {
			++$this->email_calls;
			return array_shift( $this->email_results );
		}

		if ( 'extrachill/subscribe' === $name ) {
			++$this->newsletter_calls;
			$this->newsletter_inputs[] = (array) $input;
			return $this->newsletter_result;
		}

		return $pre;
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
		$this->email_results = array( $this->delivered(), $this->delivered() );

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'delivered', $result['side_effects']['administrator_delivery']['status'] );
		$this->assertSame( 'delivered', $result['side_effects']['confirmation_delivery']['status'] );
		$this->assertSame( 'subscribed', $result['side_effects']['newsletter_sync']['status'] );
	}

	public function test_confirmation_failure_is_observable_partial_failure(): void {
		$this->email_results = array(
			$this->delivered(),
			array(
				'success' => false,
				'error'   => 'SMTP rejected.',
			),
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['side_effects']['confirmation_delivery']['success'] );
		$this->assertSame( 'failed', $result['side_effects']['confirmation_delivery']['status'] );
	}

	public function test_administrator_failure_is_total_submission_failure(): void {
		$this->email_results = array(
			array(
				'success' => false,
				'error'   => 'SMTP unavailable.',
			),
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertWPError( $result );
		$this->assertSame( 'contact_administrator_delivery_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 502, $data['status'] );
		$this->assertSame( 'skipped', $data['submission']['side_effects']['confirmation_delivery']['status'] );
		$this->assertSame( 0, $this->newsletter_calls );
	}

	public function test_refused_mail_ability_is_a_failed_delivery(): void {
		// The mail ability refusing (WP_Error) is normalized by ec_send_email()
		// into the array envelope; contact must treat it as a failed delivery.
		$this->email_results = array( new WP_Error( 'ability_invalid_permissions', 'Denied.' ) );

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertWPError( $result );
		$this->assertSame( 'failed', $result->get_error_data()['submission']['side_effects']['administrator_delivery']['status'] );
	}

	public function test_provider_unavailable_is_explicit(): void {
		$status = ec_contact_normalize_email_result( new WP_Error( 'email_provider_unavailable', 'Provider missing.' ) )['status'];

		$this->assertSame( 'provider_unavailable', $status );
	}

	public function test_failed_administrator_delivery_can_retry_without_other_duplicates(): void {
		$this->email_results = array(
			array(
				'success' => false,
				'error'   => 'Temporary.',
			),
		);
		$this->assertWPError( extrachill_contact_ability_submit( $this->input() ) );

		$this->email_results = array( $this->delivered(), $this->delivered() );
		$result              = extrachill_contact_ability_submit( $this->input() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['replayed'] );
		$this->assertSame( 3, $this->email_calls );
		$this->assertSame( 1, $this->newsletter_calls );
	}

	public function test_successful_replay_does_not_repeat_any_side_effect(): void {
		$this->email_results = array( $this->delivered(), $this->delivered() );
		$this->assertIsArray( extrachill_contact_ability_submit( $this->input() ) );

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertTrue( $result['replayed'] );
		$this->assertSame( 2, $this->email_calls );
		$this->assertSame( 1, $this->newsletter_calls );
	}

	public function test_already_subscribed_is_idempotent_success(): void {
		$this->email_results     = array( $this->delivered(), $this->delivered() );
		$this->newsletter_result = array(
			'success' => false,
			'status'  => 'already_subscribed',
			'message' => 'Already subscribed.',
		);

		$result = extrachill_contact_ability_submit( $this->input() );

		$this->assertTrue( $result['side_effects']['newsletter_sync']['success'] );
		$this->assertFalse( $result['side_effects']['newsletter_sync']['retryable'] );
	}

	public function test_source_url_and_name_reach_newsletter_sync(): void {
		$this->email_results = array( $this->delivered(), $this->delivered() );

		$input               = $this->input();
		$input['source_url'] = 'https://extrachill.com/contact/';

		extrachill_contact_ability_submit( $input );

		$this->assertSame( 1, $this->newsletter_calls );
		$this->assertSame( 'listener@example.com', $this->newsletter_inputs[0]['email'] );
		$this->assertSame( 'contact', $this->newsletter_inputs[0]['context'] );
		$this->assertSame( 'https://extrachill.com/contact/', $this->newsletter_inputs[0]['source_url'] );
		$this->assertSame( 'Listener', $this->newsletter_inputs[0]['name'] );
	}

	public function test_missing_source_url_falls_back_to_referer(): void {
		$this->email_results     = array( $this->delivered(), $this->delivered() );
		$_SERVER['HTTP_REFERER'] = 'https://extrachill.com/about/';

		extrachill_contact_ability_submit( $this->input() );

		unset( $_SERVER['HTTP_REFERER'] );
		$this->assertSame( 1, $this->newsletter_calls );
		$this->assertSame( 'https://extrachill.com/about/', $this->newsletter_inputs[0]['source_url'] );
	}

	public function test_explicit_source_url_wins_over_referer(): void {
		$this->email_results     = array( $this->delivered(), $this->delivered() );
		$_SERVER['HTTP_REFERER'] = 'https://extrachill.com/other/';

		$input               = $this->input();
		$input['source_url'] = 'https://extrachill.com/contact/';

		extrachill_contact_ability_submit( $input );

		unset( $_SERVER['HTTP_REFERER'] );
		$this->assertSame( 'https://extrachill.com/contact/', $this->newsletter_inputs[0]['source_url'] );
	}

	public function test_malformed_provider_responses_are_rejected(): void {
		$this->assertSame( 'malformed_provider_response', ec_contact_normalize_email_result( null )['status'] );

		$this->newsletter_result = null;
		$this->assertSame( 'malformed_provider_response', ec_contact_sync_to_sendy( 'listener@example.com' )['status'] );
	}

	public function test_ability_schema_exposes_strict_side_effect_contract(): void {
		$ability    = wp_get_ability( 'extrachill/contact-submit' );
		$schema     = $ability->get_output_schema();
		$properties = $schema['properties']['side_effects']['properties'];

		$this->assertSame(
			array( 'administrator_delivery', 'confirmation_delivery', 'newsletter_sync' ),
			array_keys( $properties )
		);
		$this->assertTrue( $ability->get_meta()['annotations']['idempotent'] );
	}
}
