<?php
/**
 * The contact route must forward the newsletter opt-in as an explicit boolean.
 *
 * A contact message is not newsletter consent (#1572): the route forwards
 * newsletter_opt_in as true only when the client sent a truthy value, and as
 * false when it is absent or falsy.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises the REST facade against a stand-in contact ability. */
class Contact_Submit_Newsletter_Opt_In_Test extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private $ability_inputs = array();

	public function set_up() {
		parent::set_up();
		$this->ability_inputs = array();
		if ( isset( wp_get_abilities()['extrachill/contact-submit'] ) ) {
			wp_unregister_ability( 'extrachill/contact-submit' );
		}
		$this->register_contact_ability();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		if ( isset( wp_get_abilities()['extrachill/contact-submit'] ) ) {
			wp_unregister_ability( 'extrachill/contact-submit' );
		}
		if ( isset( wp_get_ability_categories()['test'] ) ) {
			wp_unregister_ability_category( 'test' );
		}
		parent::tear_down();
	}

	public function test_absent_opt_in_is_forwarded_as_false() {
		$this->submit( array() );

		$this->assertCount( 1, $this->ability_inputs );
		$this->assertFalse( $this->ability_inputs[0]['newsletter_opt_in'] );
	}

	public function test_unchecked_opt_in_is_forwarded_as_false() {
		$this->submit( array( 'newsletter_opt_in' => false ) );

		$this->assertFalse( $this->ability_inputs[0]['newsletter_opt_in'] );
	}

	public function test_checked_opt_in_is_forwarded_as_true() {
		$this->submit( array( 'newsletter_opt_in' => true ) );

		$this->assertTrue( $this->ability_inputs[0]['newsletter_opt_in'] );
	}

	/** @param array<string, mixed> $extra Extra body params. */
	private function submit( array $extra ) {
		$request = new WP_REST_Request( 'POST', '/extrachill/v1/contact/submit' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array_merge(
					array(
						'name'               => 'Listener',
						'email'              => 'listener@example.com',
						'subject'            => 'General Inquiry',
						'message'            => 'Hello Extra Chill.',
						'turnstile_response' => 'token',
					),
					$extra
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response;
	}

	private function register_contact_ability() {
		$test = $this;
		if ( ! isset( wp_get_ability_categories()['test'] ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'test',
				array(
					'label'       => 'Test',
					'description' => 'Test-only ability contracts.',
				)
			);
		}

		WP_Abilities_Registry::get_instance()->register(
			'extrachill/contact-submit',
			array(
				'label'               => 'Test contact submit',
				'description'         => 'Stand-in for the extrachill-contact ability.',
				'category'            => 'test',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->record_input( (array) $input );
					return array( 'success' => true );
				},
			)
		);
	}

	/** @param array<string, mixed> $input Ability input. */
	public function record_input( array $input ) {
		$this->ability_inputs[] = $input;
	}
}
