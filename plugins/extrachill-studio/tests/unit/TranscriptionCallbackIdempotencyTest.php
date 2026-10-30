<?php
/**
 * Transcription callback idempotency tests.
 *
 * Ports the standalone tests/transcription-callback-idempotency.php harness
 * to real WordPress: real options-table CAS receipts on the main site, real
 * HMAC token verification, real in-process cross-site draft creation, and
 * registered test-double abilities for the two true externals (the analytics
 * and mail abilities).
 *
 * @package ExtraChillStudio
 */

/**
 * Verify the transcription completion callback idempotency contract.
 */
class Test_Transcription_Callback_Idempotency extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private const JOB_DRAFT_META = '_studio_transcription_job_id';

	private int $user_id;
	private string $secret = 'homeboy-studio-transcription-test-secret';

	private int $analytics_count = 0;
	private int $email_attempts  = 0;
	private array $email_results = array();
	private int $fail_updates    = 0;

	private ?WP_REST_Request $nested_request = null;
	private $nested_result                   = null;

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/inc/team-experience/events.php';
		require_once dirname( __DIR__, 2 ) . '/inc/transcription/email-template.php';
		require_once dirname( __DIR__, 2 ) . '/inc/transcription/callback.php';

		$this->user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		update_site_option( 'sweatpants_signed_token_secret', $this->secret );

		$this->analytics_count = 0;
		$this->email_attempts  = 0;
		$this->email_results   = array( true );
		$this->fail_updates    = 0;
		$this->nested_request  = null;
		$this->nested_result   = null;

		$this->register_test_abilities();
	}

	protected function tearDown(): void {
		foreach ( array( 'extrachill/track-analytics-event', 'datamachine/send-email' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		delete_site_option( 'sweatpants_signed_token_secret' );
		remove_filter( 'rest_pre_insert_post', array( $this, 'dispatch_nested_callback' ), 10 );
		remove_filter( 'query', array( $this, 'fail_one_receipt_update' ) );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Own the analytics and mail abilities with capturing test doubles.
	 *
	 * The abilities registry lazily initializes on first access and fires
	 * wp_abilities_api_init with every activated plugin's registrar attached,
	 * so the real analytics ability may already exist. Core's
	 * wp_register_ability() refuses registrations outside that action, which
	 * is why the doubles are registered through a re-fired action holding
	 * only this test's registrar (WP_UnitTestCase restores hooks between
	 * tests; the registry itself is process-global, so tearDown unregisters
	 * the doubles).
	 */
	private function register_test_abilities(): void {
		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'extrachill' ) ) {
			remove_all_actions( 'wp_abilities_api_categories_init' );
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_test_category' ) );
			do_action( 'wp_abilities_api_categories_init' );
		}

		foreach ( array( 'extrachill/track-analytics-event', 'datamachine/send-email' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( $this, 'register_test_double_abilities' ) );
		do_action( 'wp_abilities_api_init' );

		foreach ( array( 'extrachill/track-analytics-event', 'datamachine/send-email' ) as $name ) {
			if ( ! wp_has_ability( $name ) ) {
				$this->fail( "Failed to register the {$name} test double." );
			}
		}
	}

	public function register_test_category(): void {
		wp_register_ability_category(
			'extrachill',
			array(
				'label'       => 'Extra Chill',
				'description' => 'Extra Chill platform tools and workflows.',
			)
		);
	}

	public function register_test_double_abilities(): void {
		$this->register_test_double_ability( 'extrachill/track-analytics-event' );
		$this->register_test_double_ability( 'datamachine/send-email' );
	}

	private function register_test_double_ability( string $name ): void {
		if ( 'datamachine/send-email' === $name ) {
			$output_schema = array( 'type' => 'object' );
			$execute       = function ( $args ) {
				unset( $args );
				++$this->email_attempts;
				$result = array_shift( $this->email_results );
				return array( 'success' => false !== $result );
			};
		} else {
			$output_schema = array( 'type' => 'integer' );
			$execute       = function ( $input ) {
				unset( $input );
				return ++$this->analytics_count;
			};
		}

		wp_register_ability(
			$name,
			array(
				'label'               => 'Test double',
				'description'         => 'Captures bounded transcription callback side effects.',
				'category'            => 'extrachill',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => $output_schema,
				'permission_callback' => '__return_true',
				'execute_callback'    => $execute,
			)
		);
	}

	private function callback_request( string $job_id = 'job-123' ): WP_REST_Request {
		$token = wp_native_auth_sign_external_token(
			array(
				'scope' => 'callback:write',
				'sub'   => $this->user_id,
				'exp'   => time() + 300,
			),
			$this->secret
		);

		$request = new WP_REST_Request( 'POST', '/extrachill/v1/transcribe/callback' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'job_id'  => $job_id,
					'status'  => 'complete',
					'files'   => array( 'transcription' => 'recording.wav.whisper.txt' ),
					'content' => array( 'transcription' => 'Test transcript.' ),
					'stats'   => array( 'segments' => 2, 'duration' => 10 ),
				)
			)
		);
		return $request;
	}

	private function receipt_key( string $job_id ): string {
		return '_ec_studio_transcription_' . hash( 'sha256', $this->user_id . "\0" . $job_id );
	}

	private function job_draft_ids( string $job_id ): array {
		switch_to_blog( (int) ec_get_blog_id( 'main' ) );
		$ids = get_posts(
			array(
				'author'         => $this->user_id,
				'fields'         => 'ids',
				'meta_key'       => self::JOB_DRAFT_META,
				'meta_value'     => $job_id,
				'no_found_rows'  => true,
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		restore_current_blog();
		return array_map( 'intval', (array) $ids );
	}

	private function age_receipt( string $job_id, int $seconds ): void {
		switch_to_blog( (int) ec_get_blog_id( 'main' ) );
		$receipt = get_option( $this->receipt_key( $job_id ), null );
		$this->assertIsArray( $receipt, 'A callback receipt must exist to age.' );
		$receipt['updated_at'] = time() - $seconds;
		update_option( $this->receipt_key( $job_id ), $receipt );
		restore_current_blog();
	}

	public function dispatch_nested_callback( $prepared_post, $request ) {
		unset( $request );
		$pending              = $this->nested_request;
		$this->nested_request = null;
		if ( $pending instanceof WP_REST_Request ) {
			$this->nested_result = ec_studio_transcription_handle_callback( $pending );
		}
		return $prepared_post;
	}

	public function fail_one_receipt_update( $query ) {
		// Anchor on the UPDATE keyword: add_option() writes receipts with an
		// INSERT ... ON DUPLICATE KEY UPDATE statement, which must pass through.
		if (
			$this->fail_updates > 0
			&& 0 === strpos( ltrim( (string) $query ), 'UPDATE' )
			&& false !== strpos( (string) $query, '_ec_studio_transcription_' )
		) {
			--$this->fail_updates;
			return 'INSERT INTO ec_studio_test_missing_table (id) VALUES (1)';
		}
		return $query;
	}

	public function test_sequential_replay_returns_the_prior_result(): void {
		$first  = ec_studio_transcription_handle_callback( $this->callback_request() );
		$second = ec_studio_transcription_handle_callback( $this->callback_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $first, 'Sequential replay returns success.' );
		$this->assertInstanceOf( WP_REST_Response::class, $second, 'Sequential replay returns success.' );
		$this->assertSame( $first->data, $second->data, 'Sequential replay returns the prior result.' );
		$this->assertCount( 1, $this->job_draft_ids( 'job-123' ), 'Sequential replay creates one draft.' );
		$this->assertSame( 1, $this->analytics_count, 'Sequential replay emits analytics once.' );
		$this->assertSame( 1, $this->email_attempts, 'Sequential replay sends one notification.' );
	}

	public function test_concurrent_replay_receives_the_retryable_conflict(): void {
		$request              = $this->callback_request( 'job-race' );
		$this->nested_request = $request;

		add_filter( 'rest_pre_insert_post', array( $this, 'dispatch_nested_callback' ), 10, 2 );
		$winner = ec_studio_transcription_handle_callback( $request );
		remove_filter( 'rest_pre_insert_post', array( $this, 'dispatch_nested_callback' ), 10 );

		$this->assertInstanceOf( WP_REST_Response::class, $winner, 'Race winner completes.' );
		$this->assertInstanceOf( WP_Error::class, $this->nested_result, 'Concurrent replay is rejected while claimed.' );
		$this->assertSame( 'callback_in_progress', $this->nested_result->get_error_code(), 'Concurrent replay receives the retryable conflict.' );
		$this->assertCount( 1, $this->job_draft_ids( 'job-race' ), 'Race executes draft creation once.' );
		$this->assertSame( 1, $this->analytics_count, 'Race executes analytics once.' );
		$this->assertSame( 1, $this->email_attempts, 'Race executes the notification once.' );
	}

	public function test_notification_failure_requests_retry_of_only_the_notification(): void {
		$this->email_results = array( false, true );

		$failed  = ec_studio_transcription_handle_callback( $this->callback_request( 'job-partial' ) );
		$retried = ec_studio_transcription_handle_callback( $this->callback_request( 'job-partial' ) );

		$this->assertWPError( $failed, 'Notification failure requests a retry.' );
		$this->assertSame( 'notification_failed', $failed->get_error_code() );
		$this->assertInstanceOf( WP_REST_Response::class, $retried, 'Partial failure retry completes.' );
		$this->assertCount( 1, $this->job_draft_ids( 'job-partial' ), 'Partial failure retry reuses the draft.' );
		$this->assertSame( 1, $this->analytics_count, 'Partial failure retry reuses the analytics event.' );
		$this->assertSame( 2, $this->email_attempts, 'Partial failure retries only the notification.' );
	}

	public function test_failure_after_draft_leaves_a_recoverable_receipt(): void {
		$this->fail_updates = 1;

		add_filter( 'query', array( $this, 'fail_one_receipt_update' ) );
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$failed   = ec_studio_transcription_handle_callback( $this->callback_request( 'job-after-draft' ) );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', array( $this, 'fail_one_receipt_update' ) );

		$this->assertWPError( $failed, 'Failure after draft leaves a recoverable receipt.' );
		$this->assertSame( 'callback_claim_lost', $failed->get_error_code() );

		$this->age_receipt( 'job-after-draft', 301 );

		$recovered = ec_studio_transcription_handle_callback( $this->callback_request( 'job-after-draft' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $recovered, 'Stale post-creation failure recovers.' );
		$this->assertCount( 1, $this->job_draft_ids( 'job-after-draft' ), 'Post-creation recovery resolves the stamped draft.' );
		$this->assertSame( 1, $this->analytics_count, 'Post-creation recovery runs analytics once.' );
		$this->assertSame( 1, $this->email_attempts, 'Post-creation recovery runs the notification once.' );
	}
}
