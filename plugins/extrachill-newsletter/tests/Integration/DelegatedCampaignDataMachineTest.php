<?php
/**
 * Real Data Machine delegated campaign contract coverage.
 *
 * @package ExtraChillNewsletter\Tests\Integration
 */

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\DelegatedOperations\DelegatedOperationService;
use DataMachine\Core\RunResult;
use ExtraChillNewsletter\Tasks\DelegatedCampaignTask;

if ( ! defined( 'UPLOADBLOGSDIR' ) ) {
	define( 'UPLOADBLOGSDIR', 'wp-content/blogs.dir' );
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $key ) {
		return (int) ( $GLOBALS['newsletter_integration_blogs'][ $key ] ?? 0 );
	}
}
if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url( $key ) {
		$blog_id = ec_get_blog_id( $key );
		return $blog_id ? get_site_url( $blog_id ) : '';
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		unset( $blog_id );
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		return true;
	}
}
if ( ! function_exists( 'get_main_site_id' ) ) {
	function get_main_site_id() {
		return get_current_blog_id();
	}
}
if ( ! class_exists( DelegatedOperationService::class ) ) {
	$data_machine = WP_PLUGIN_DIR . '/data-machine/data-machine.php';
	if ( is_file( $data_machine ) ) {
		require_once $data_machine;
	}
}

final class DelegatedCampaignDataMachineTest extends WP_UnitTestCase {
	private int $first_actor;
	private int $second_actor;
	private int $owner_user;
	private int $events_blog;
	private int $newsletter_blog;
	private int $source_post;
	private bool $authorized = true;
	private int $provider_calls = 0;
	private bool $registered_fake_ability = false;

	public function set_up(): void {
		parent::set_up();
		if ( function_exists( 'datamachine_activate_full_runtime' ) ) {
			datamachine_activate_full_runtime( 'newsletter-integration-test' );
		}
		extrachill_newsletter_register_delegated_campaign_task();
		$this->first_actor     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->second_actor    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->owner_user      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->events_blog     = get_current_blog_id();
		$this->newsletter_blog = get_current_blog_id();
		$GLOBALS['newsletter_integration_blogs'] = array(
			'events'     => $this->events_blog,
			'newsletter' => $this->newsletter_blog,
			'main'       => get_main_site_id(),
		);
		switch_to_blog( $this->events_blog );
		$this->source_post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Canonical event',
				'post_content' => '<p>Approved canonical details.</p>',
			)
		);
		restore_current_blog();
		add_filter( 'extrachill_newsletter_authorize_delegated_campaign', array( $this, 'authorize' ), 10, 3 );
		add_filter( 'extrachill_newsletter_delegated_campaign_owner_user_id', array( $this, 'owner_user_id' ) );
	}

	public function tear_down(): void {
		remove_filter( 'extrachill_newsletter_authorize_delegated_campaign', array( $this, 'authorize' ), 10 );
		remove_filter( 'extrachill_newsletter_delegated_campaign_owner_user_id', array( $this, 'owner_user_id' ) );
		if ( $this->registered_fake_ability ) {
			WP_Abilities_Registry::get_instance()->unregister( 'datamachine/sendy-push-campaign' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function owner_user_id(): int {
		return $this->owner_user;
	}

	public function authorize( $authorized, array $source, array $context ) {
		unset( $authorized );
		return $this->authorized
			&& $this->events_blog === $source['site_id']
			&& $this->source_post === $source['post_id']
			&& in_array( (int) ( $context['actor']['user_id'] ?? 0 ), array( $this->first_actor, $this->second_actor ), true );
	}

	public function test_real_submit_replay_drift_and_reconcile_contract(): void {
		if ( ! class_exists( DelegatedOperationService::class ) ) {
			$this->markTestSkipped( 'Data Machine is not installed in this WordPress test runtime.' );
		}
		$service   = new DelegatedOperationService();
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_set_current_user( $this->first_actor );
		$first = $service->submit( $this->submission( 'campaign-replay', $timestamp ) );
		wp_set_current_user( $this->second_actor );
		$second = $service->submit( $this->submission( 'campaign-replay', $timestamp ) );

		$this->assertTrue( $first['success'], wp_json_encode( $first ) );
		$this->assertSame( $first['operation_ref'], $second['operation_ref'] );
		$this->assertTrue( $second['replayed'] );
		$this->assertSame( 'submitted', $second['status'] );
		$this->assertSame( 'submitted', $second['projection']['classification'] );

		$drift = $this->submission( 'campaign-replay', $timestamp + 1 );
		$conflict = $service->submit( $drift );
		$this->assertFalse( $conflict['success'] );
		$this->assertSame( 'delegated_operation_conflict', $conflict['error_code'] );

		$reconciled = $service->reconcile( array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'operation_ref' => $first['operation_ref'] ) );
		$this->assertSame( 'submitted', $reconciled['status'] );
	}

	public function test_terminal_parent_and_child_envelopes_drive_exact_no_op_projection(): void {
		if ( ! class_exists( DelegatedOperationService::class ) ) {
			$this->markTestSkipped( 'Data Machine is not installed in this WordPress test runtime.' );
		}
		$service = new DelegatedOperationService();
		wp_set_current_user( $this->first_actor );
		$submitted = $service->submit( $this->submission( 'terminal-no-op', time() + HOUR_IN_SECONDS ) );
		$job       = $this->job( 'terminal-no-op' );
		$jobs      = new Jobs();
		$child_id  = $jobs->create_job(
			array(
				'pipeline_id'  => 'direct',
				'flow_id'      => 'direct',
				'source'       => 'pipeline_system_task',
				'label'        => 'Newsletter delegated campaign child',
				'parent_job_id' => (int) $job['job_id'],
			)
		);
		$child_result = RunResult::fromStepResults( array(), array( 'status' => 'completed_no_items', 'outputs' => array( 'effect_count' => 0 ) ) );
		$this->assertTrue( $jobs->store_engine_data( $child_id, array( 'run_result' => $child_result ) ) );
		$this->assertTrue( $jobs->complete_job( $child_id, 'completed_no_items' ) );
		$this->assertTrue( extrachill_newsletter_record_delegated_campaign_outcome( $submitted['operation_ref'], extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_empty_source' ) ) );
		$this->assertTrue( $jobs->complete_job( (int) $job['job_id'], 'completed_no_items' ) );

		$result = $service->reconcile( array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'no-op', $result['status'] );
		$this->assertSame( 'no-op', $result['projection']['classification'] );
		$terminal = $this->job( 'terminal-no-op' )['operation_envelope']['run_result'];
		$this->assertSame( 'completed_no_items', $terminal['status'] );
		$this->assertSame( 'completed_no_items', $terminal['child_job_envelopes'][0]['status'] );
		$this->assertIsArray( $terminal['child_job_envelopes'][0]['outputs']['counts'] );
	}

	public function test_real_task_executes_frozen_owner_workflow_and_reconciles(): void {
		if ( ! class_exists( DelegatedOperationService::class ) ) {
			$this->markTestSkipped( 'Data Machine is not installed in this WordPress test runtime.' );
		}
		$this->register_fake_sendy_ability();
		$tasks = apply_filters( 'datamachine_tasks', array() );
		$this->assertSame( DelegatedCampaignTask::class, $tasks[ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK ] ?? null );
		wp_set_current_user( $this->first_actor );
		$service   = new DelegatedOperationService();
		$submitted = $service->submit( $this->submission( 'real-owner-task', time() + HOUR_IN_SECONDS ) );
		$this->assertTrue( $submitted['success'] );

		$context = array(
			'phase'         => 'submit',
			'action'        => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION,
			'operation_id'  => 'real-owner-task',
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => $this->first_actor, 'agent_id' => 0, 'token_id' => 0 ),
			'input'         => $this->submission( 'real-owner-task', 0 )['input'],
		);
		$prepared = extrachill_newsletter_prepare_delegated_campaign( $context['input'], $context );
		$this->assertIsArray( $prepared );
		$this->assertSame( $this->owner_user, $prepared['owner_user_id'] );
		$params = $prepared['workflow']['steps'][0]['flow_step_settings']['params'];
		( new DelegatedCampaignTask() )->executeTask( (int) $this->job( 'real-owner-task' )['job_id'], $params );

		$reconciled = $service->reconcile( array( 'action' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION, 'operation_ref' => $submitted['operation_ref'] ) );
		$this->assertSame( 'executed', $reconciled['status'] );
		$this->assertSame( 1, $reconciled['projection']['effect_count'] );
		$this->assertSame( 1, $this->provider_calls );
		$this->assertArrayNotHasKey( 'newsletter_campaign', $reconciled );
	}

	public function test_provider_success_without_persisted_reference_fails_closed(): void {
		$this->register_fake_sendy_ability();
		$block_receipt = static function ( $check, $object_id, $meta_key ) {
			unset( $object_id );
			return '_sendy_campaign_id' === $meta_key ? false : $check;
		};
		add_filter( 'update_post_metadata', $block_receipt, 10, 3 );
		$result = extrachill_newsletter_execute_delegated_campaign( $this->submission( 'provider-receipt', 0 )['input'] );
		remove_filter( 'update_post_metadata', $block_receipt, 10 );

		$this->assertSame( 'newsletter_campaign_reference_missing', $result['error_code'] );
		$this->assertSame( 1, $this->provider_calls );
		$replayed = extrachill_newsletter_execute_delegated_campaign( $this->submission( 'provider-receipt', 0 )['input'] );
		$this->assertSame( 'newsletter_campaign_reconciliation_required', $replayed['error_code'] );
		$this->assertSame( 1, $this->provider_calls );
	}

	private function submission( string $operation_id, int $timestamp ): array {
		return array(
			'action'       => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION,
			'operation_id' => $operation_id,
			'input'        => array(
				'source' => array( 'site_id' => $this->events_blog, 'post_id' => $this->source_post ),
				'policy' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY,
			),
			'timestamp'    => $timestamp,
		);
	}

	private function job( string $operation_id ): array {
		$key = 'delegated:' . hash( 'sha256', "delegated-idempotency\0" . EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION . "\0" . $operation_id );
		$job = ( new Jobs() )->get_job_by_idempotency_key( $key );
		$this->assertIsArray( $job );
		return $job;
	}

	private function register_fake_sendy_ability(): void {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( 'datamachine/sendy-push-campaign' ) ) {
			$this->markTestSkipped( 'The integration environment already provides the Sendy transport ability.' );
		}
		WP_Abilities_Registry::get_instance()->register(
			'datamachine/sendy-push-campaign',
			array(
				'label'               => 'Fake Sendy campaign transport',
				'description'         => 'Integration-only provider success.',
				'category'            => 'extrachill-newsletter',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => function () {
					++$this->provider_calls;
					return array( 'success' => true, 'created' => true, 'campaign_id' => 'provider-campaign-1' );
				},
			)
		);
		$this->registered_fake_ability = true;
	}
}
