<?php
/**
 * Ability-backed REST adapter error contracts.
 *
 * @package ExtraChill\API\Tests
 */

/** Verifies canonical ability errors and successful response envelopes. */
final class AbilityErrorPreservationTest extends WP_UnitTestCase {

	/** @var array<string, mixed> */
	private $ability_results = array();

	/** @var string[] */
	private $ability_names = array(
		'extrachill/complete-onboarding',
		'extrachill/get-link-page-data',
		'extrachill/approve-artist-access',
		'extrachill/generate-qr-code',
		'extrachill/push-campaign',
	);

	/** Register controlled abilities for representative adapters. */
	public function set_up() {
		parent::set_up();

		if ( ! wp_has_ability_category( 'api-error-contract-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'api-error-contract-tests',
				array(
					'label'       => 'API error contract tests',
					'description' => 'Controlled adapter error contracts.',
				)
			);
		}

		foreach ( $this->ability_names as $ability_name ) {
			$this->register_ability( $ability_name );
		}
	}

	/** Remove controlled abilities. */
	public function tear_down() {
		foreach ( $this->ability_names as $ability_name ) {
			if ( wp_has_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		if ( wp_has_ability_category( 'api-error-contract-tests' ) ) {
			wp_unregister_ability_category( 'api-error-contract-tests' );
		}

		parent::tear_down();
	}

	/** Validation errors retain every canonical error field. */
	public function test_onboarding_preserves_validation_error() {
		$error = $this->error( 'invalid_username', 'Choose another username.', 422, array( 'field' => 'username' ) );
		$this->ability_results['extrachill/complete-onboarding'] = $error;

		$this->assertSame( $error, extrachill_api_onboarding_post_handler( new WP_REST_Request( 'POST' ) ) );
	}

	/** Authorization errors are not coerced into server failures. */
	public function test_artist_links_preserve_forbidden_error() {
		$error = $this->error( 'artist_forbidden', 'Artist access denied.', 403, array( 'capability' => 'manage_artist' ) );
		$this->ability_results['extrachill/get-link-page-data'] = $error;
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'id', 42 );

		$this->assertSame( $error, extrachill_api_artist_links_get_handler( $request ) );
	}

	/** Conflict errors retain their canonical status and metadata. */
	public function test_artist_access_preserves_conflict_error() {
		$error = $this->error( 'artist_access_conflict', 'Request already resolved.', 409, array( 'state' => 'approved' ) );
		$this->ability_results['extrachill/approve-artist-access'] = $error;
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'user_id', 23 );
		$request->set_param( 'type', 'artist' );

		$this->assertSame( $error, extrachill_api_artist_access_approve( $request ) );
	}

	/** Infrastructure errors retain retry metadata instead of becoming generic 500s. */
	public function test_qr_generation_preserves_server_error() {
		$error = $this->error( 'qr_service_unavailable', 'QR service unavailable.', 503, array( 'retryable' => true ) );
		$this->ability_results['extrachill/generate-qr-code'] = $error;
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'url', 'https://extrachill.com' );

		$this->assertSame( $error, extrachill_api_generate_qr_code( $request ) );
	}

	/** QR success responses retain the public envelope. */
	public function test_qr_generation_success_envelope_is_unchanged() {
		$this->ability_results['extrachill/generate-qr-code'] = array(
			'image' => 'cG5n',
			'url'   => 'https://extrachill.com/news',
			'size'  => 640,
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'url', 'https://extrachill.com' );

		$response = extrachill_api_generate_qr_code( $request );

		$this->assertSame(
			array(
				'image_url' => 'data:image/png;base64,cG5n',
				'url'       => 'https://extrachill.com/news',
				'size'      => 640,
			),
			$response->get_data()
		);
	}

	/** Newsletter success responses retain the public envelope. */
	public function test_campaign_success_envelope_is_unchanged() {
		$this->ability_results['extrachill/push-campaign'] = array(
			'success'     => true,
			'message'     => 'Campaign queued.',
			'campaign_id' => 'campaign-151',
			'internal'    => 'not exposed',
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', 151 );

		$response = extrachill_api_newsletter_campaign_push_handler( $request );

		$this->assertSame(
			array(
				'success'     => true,
				'message'     => 'Campaign queued.',
				'campaign_id' => 'campaign-151',
			),
			$response->get_data()
		);
	}

	/** Build a multi-code error with canonical status and metadata. */
	private function error( $code, $message, $status, array $data ) {
		$error = new WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
		$error->add( 'secondary_error', 'Secondary detail.', array( 'source' => 'ability' ) );

		return $error;
	}

	/** Register an ability whose result is controlled by the test. */
	private function register_ability( $ability_name ) {
		if ( wp_has_ability( $ability_name ) ) {
			wp_unregister_ability( $ability_name );
		}

		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			$ability_name,
			array(
				'label'               => 'Test ' . $ability_name,
				'description'         => 'Returns a controlled adapter result.',
				'category'            => 'api-error-contract-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function () use ( $test, $ability_name ) {
					return $test->ability_results[ $ability_name ];
				},
			)
		);
	}
}
