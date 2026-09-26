<?php
/**
 * Socials public-contract tests.
 *
 * Ports the standalone tests/social-publish-contract.php harness to real
 * WordPress: real posts, real post meta on a dedicated Studio subsite blog,
 * real cross-site attribution reads against the main site, and registered
 * test-double abilities for the Data Machine Socials owner contract.
 *
 * @package ExtraChillStudio
 */

use const ExtraChillStudio\META_CAPTION;
use const ExtraChillStudio\META_DELIVERY_REF;
use const ExtraChillStudio\META_IMAGES;
use const ExtraChillStudio\META_MEDIA_KIND;
use const ExtraChillStudio\META_PLATFORMS;
use const ExtraChillStudio\META_PUBLISH_LOG;
use const ExtraChillStudio\META_SOURCE_POST;
use const ExtraChillStudio\META_SOURCE_URL;

/**
 * Verify the Socials publish contract against WordPress multisite primitives.
 */
class Test_Social_Publish_Contract extends WP_UnitTestCase {
	// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing

	private int $studio_blog_id;

	protected function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/inc/social-drafts.php';

		$this->studio_blog_id = self::factory()->blog->create();
		switch_to_blog( $this->studio_blog_id );

		ExtraChillStudio\register_social_meta();

		$GLOBALS['ec_studio_social_test_calls']     = array();
		$GLOBALS['ec_studio_social_test_responses'] = array();

		$this->register_test_abilities();
	}

	protected function tearDown(): void {
		foreach (
			array(
				'datamachine/enqueue-social-publish',
				'datamachine/retry-social-publish',
				'datamachine/get-social-publish',
			) as $name
		) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		unset( $GLOBALS['ec_studio_social_test_calls'], $GLOBALS['ec_studio_social_test_responses'] );
		if ( get_current_blog_id() === $this->studio_blog_id ) {
			restore_current_blog();
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Own the Socials owner abilities with capturing test doubles.
	 *
	 * Core's wp_register_ability() refuses registrations outside the
	 * wp_abilities_api_init action, so the doubles are registered through a
	 * re-fired action holding only this test's registrar (WP_UnitTestCase
	 * restores hooks between tests; the registry itself is process-global,
	 * so tearDown unregisters the doubles). Data Machine Socials is
	 * deliberately not a validation dependency: the doubles replace the
	 * owner contract these tests assert on.
	 */
	private function register_test_abilities(): void {
		if ( function_exists( 'wp_has_ability_category' ) && ! wp_has_ability_category( 'extrachill' ) ) {
			remove_all_actions( 'wp_abilities_api_categories_init' );
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_test_category' ) );
			do_action( 'wp_abilities_api_categories_init' );
		}

		foreach ( array( 'datamachine/enqueue-social-publish', 'datamachine/retry-social-publish', 'datamachine/get-social-publish' ) as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}

		remove_all_actions( 'wp_abilities_api_init' );
		add_action( 'wp_abilities_api_init', array( $this, 'register_test_double_abilities' ) );
		do_action( 'wp_abilities_api_init' );

		foreach ( array( 'datamachine/enqueue-social-publish', 'datamachine/retry-social-publish', 'datamachine/get-social-publish' ) as $name ) {
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
		foreach ( array( 'datamachine/enqueue-social-publish', 'datamachine/retry-social-publish', 'datamachine/get-social-publish' ) as $name ) {
			wp_register_ability(
				$name,
				array(
					'label'               => 'Test double',
					'description'         => 'Captures Studio social publish owner calls.',
					'category'            => 'extrachill',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'permission_callback' => '__return_true',
					'execute_callback'    => static function ( $input ) use ( $name ) {
						$GLOBALS['ec_studio_social_test_calls'][] = array(
							'name'  => $name,
							'input' => $input,
						);
						return array_shift( $GLOBALS['ec_studio_social_test_responses'][ $name ] );
					},
				)
			);
		}
	}

	private function social_delivery( string $status = 'queued', bool $duplicate = false ): array {
		return array(
			'success'  => true,
			'delivery' => array(
				'delivery_ref' => 'dop_' . str_repeat( 'a', 64 ),
				'status'       => $status,
				'duplicate'    => $duplicate,
				'retryable'    => 'failed' === $status,
				'deliveries'   => array(),
				'errors'       => 'failed' === $status ? array( array( 'channel' => 'instagram', 'code' => 'undelivered' ) ) : array(),
			),
		);
	}

	private function reset_social_test(): int {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$GLOBALS['ec_studio_social_test_calls']     = array();
		$GLOBALS['ec_studio_social_test_responses'] = array();

		update_post_meta( $post_id, META_PLATFORMS, array( 'instagram' ) );
		update_post_meta( $post_id, META_CAPTION, 'Approved caption.' );
		update_post_meta(
			$post_id,
			META_IMAGES,
			array(
				array(
					'url'       => 'https://extrachill.example.test/image.jpg',
					'source_id' => '1:84',
				),
			)
		);
		update_post_meta( $post_id, META_MEDIA_KIND, 'image' );
		update_post_meta( $post_id, META_PUBLISH_LOG, array() );

		return $post_id;
	}

	public function test_review_drafts_register_canonical_source_meta(): void {
		$registered = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( META_SOURCE_POST, $registered, 'Review drafts register canonical source post identity.' );
		$this->assertArrayHasKey( META_SOURCE_URL, $registered, 'Review drafts register canonical source URL.' );
	}

	public function test_missing_socials_dependency_fails_closed(): void {
		$post_id = $this->reset_social_test();
		wp_unregister_ability( 'datamachine/enqueue-social-publish' );

		// wp_get_ability() reports the missing dependency through core's
		// incorrect-usage channel; the handler must still fail closed.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$missing = ExtraChillStudio\enqueue_social_publish( get_post( $post_id ) );

		$this->assertFalse( $missing['success'], 'Missing Socials dependency fails closed.' );
		$this->assertSame( 'social_publish_capability_unavailable', $missing['error']['code'], 'Missing dependency uses stable capability code.' );
		$this->assertTrue( $missing['error']['retryable'], 'Missing dependency remains retryable.' );
	}

	public function test_transient_enqueue_failure_is_recorded_and_pre_receipt_retry_recovers(): void {
		$post_id = $this->reset_social_test();
		$GLOBALS['ec_studio_social_test_responses']['datamachine/enqueue-social-publish'] = array(
			array(
				'success' => false,
				'error'   => array(
					'code'      => 'social_publish_scheduler_unavailable',
					'message'   => 'Scheduling failed.',
					'retryable' => true,
				),
			),
			$this->social_delivery(),
		);

		ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', get_post( $post_id ) );

		$failure = get_post_meta( $post_id, META_PUBLISH_LOG, true )[0];
		$this->assertSame( 'social_publish_scheduler_unavailable', $failure['code'], 'Transient enqueue failure is recorded stably.' );
		$this->assertTrue( (bool) $failure['retryable'], 'Transient enqueue failure preserves retryability.' );

		$recovered = ExtraChillStudio\retry_social_publish( $post_id );
		$calls     = $GLOBALS['ec_studio_social_test_calls'];

		$this->assertTrue( (bool) $recovered['success'], 'Pre-receipt retry replays enqueue successfully.' );
		$this->assertSame( $calls[0]['input']['idempotency_key'], $calls[1]['input']['idempotency_key'], 'Pre-receipt retry reuses the original idempotency identity.' );
		$this->assertSame( array(), get_post_meta( $post_id, META_PUBLISH_LOG, true ), 'Successful handoff clears obsolete transient errors.' );
	}

	public function test_provider_unavailable_response_remains_owner_defined_and_stable(): void {
		$post_id = $this->reset_social_test();
		$provider_error = array(
			'success' => false,
			'error'   => array(
				'code'      => 'social_publish_provider_unavailable',
				'message'   => 'Provider unavailable.',
				'retryable' => false,
			),
		);
		$GLOBALS['ec_studio_social_test_responses']['datamachine/enqueue-social-publish'] = array( $provider_error );

		$provider_result = ExtraChillStudio\enqueue_social_publish( get_post( $post_id ) );

		$this->assertSame( $provider_error, $provider_result, 'Provider-unavailable response remains owner-defined and stable.' );
	}

	public function test_duplicate_publish_transitions_replay_enqueue_with_one_identity(): void {
		$post_id = $this->reset_social_test();
		$GLOBALS['ec_studio_social_test_responses']['datamachine/enqueue-social-publish'] = array(
			$this->social_delivery(),
			$this->social_delivery( 'queued', true ),
		);
		$post = get_post( $post_id );

		ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', $post );
		ExtraChillStudio\on_publish_crosspost( 'publish', 'pending', $post );

		$calls = $GLOBALS['ec_studio_social_test_calls'];
		$this->assertCount( 2, $calls, 'Duplicate publish transition safely replays enqueue.' );
		$this->assertSame( $calls[0]['input']['idempotency_key'], $calls[1]['input']['idempotency_key'], 'Duplicate transitions use one idempotency identity.' );
		$this->assertSame( 'studio-social-publish:' . get_current_blog_id() . ':' . $post_id, $calls[0]['input']['idempotency_key'], 'Idempotency identity is site scoped.' );
		$this->assertSame( '1:84', $calls[0]['input']['content_ref']['asset_refs'][0]['source_id'], 'Cross-site canonical media identity reaches Socials intact.' );
		$this->assertArrayNotHasKey( 'attribution_post', $calls[0]['input'], 'Ordinary review drafts omit source attribution.' );
		$this->assertSame( 'dop_' . str_repeat( 'a', 64 ), get_post_meta( $post_id, META_DELIVERY_REF, true ), 'Only opaque delivery receipt is persisted.' );
	}

	public function test_article_review_enqueue_declares_canonical_source_attribution(): void {
		$post_id = $this->reset_social_test();

		switch_to_blog( (int) ec_get_blog_id( 'main' ) );
		$source_post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$source_url     = get_permalink( $source_post_id );
		restore_current_blog();

		update_post_meta( $post_id, META_SOURCE_POST, $source_post_id );
		update_post_meta( $post_id, META_SOURCE_URL, $source_url );
		$GLOBALS['ec_studio_social_test_responses']['datamachine/enqueue-social-publish'] = array( $this->social_delivery() );

		ExtraChillStudio\enqueue_social_publish( get_post( $post_id ) );

		$article_input = $GLOBALS['ec_studio_social_test_calls'][0]['input'];
		$this->assertSame( array( 'site_id' => (int) ec_get_blog_id( 'main' ), 'post_id' => $source_post_id ), $article_input['attribution_post'], 'Article review enqueue declares canonical source attribution.' );
		$this->assertSame( $post_id, $article_input['content_ref']['post_id'], 'Article attribution does not replace the Studio review resource.' );
		$this->assertSame( get_permalink( $post_id ), $article_input['content_ref']['source_url'], 'Article attribution does not replace the review source URL.' );
		$this->assertSame( 'studio-social-publish:' . get_current_blog_id() . ':' . $post_id, $article_input['idempotency_key'], 'Article attribution preserves review idempotency identity.' );
	}

	public function test_invalid_declared_attribution_blocks_review_enqueue(): void {
		$post_id = $this->reset_social_test();
		update_post_meta( $post_id, META_SOURCE_POST, 99 );

		$invalid_attribution = ExtraChillStudio\enqueue_social_publish( get_post( $post_id ) );

		$this->assertFalse( $invalid_attribution['success'], 'Invalid declared attribution blocks review enqueue.' );
		$this->assertSame( 'social_publish_attribution_invalid', $invalid_attribution['error']['code'], 'Invalid attribution uses a stable error code.' );
		$this->assertFalse( $invalid_attribution['error']['retryable'], 'Invalid attribution is non-retryable.' );
		$this->assertSame( array(), $GLOBALS['ec_studio_social_test_calls'], 'Invalid attribution fails before calling Socials.' );
	}

	public function test_explicit_retry_delegates_to_the_socials_retry_ability(): void {
		$post_id = $this->reset_social_test();
		$ref     = 'dop_' . str_repeat( 'a', 64 );
		update_post_meta( $post_id, META_DELIVERY_REF, $ref );
		$GLOBALS['ec_studio_social_test_responses']['datamachine/retry-social-publish'] = array( $this->social_delivery( 'retrying' ) );

		$retried = ExtraChillStudio\retry_social_publish( $post_id );
		$calls   = $GLOBALS['ec_studio_social_test_calls'];

		$this->assertTrue( (bool) $retried['success'], 'Explicit retry succeeds.' );
		$this->assertSame( 'datamachine/retry-social-publish', $calls[0]['name'], 'Retry delegates to the Socials retry ability.' );
		$this->assertSame( $ref, $calls[0]['input']['delivery_ref'], 'Retry reuses the opaque delivery receipt.' );
	}

	public function test_state_reads_preserve_owner_state_without_copying_it_locally(): void {
		$post_id = $this->reset_social_test();
		update_post_meta( $post_id, META_DELIVERY_REF, 'dop_' . str_repeat( 'a', 64 ) );
		$GLOBALS['ec_studio_social_test_responses']['datamachine/get-social-publish'] = array(
			$this->social_delivery( 'queued' ),
			$this->social_delivery( 'failed' ),
		);

		$queued = ExtraChillStudio\get_social_publish_state( $post_id );
		$failed = ExtraChillStudio\get_social_publish_state( $post_id );

		$this->assertSame( 'queued', $queued['delivery']['status'], 'State reads preserve queued owner state.' );
		$this->assertSame( 'failed', $failed['delivery']['status'], 'State reads preserve retryable failed owner state.' );
		$this->assertTrue( (bool) $failed['delivery']['retryable'], 'State reads preserve retryable failed owner state.' );
		$this->assertCount( 2, $GLOBALS['ec_studio_social_test_calls'], 'State is read from Socials instead of copied locally.' );
	}
}
