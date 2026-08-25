<?php
/** Schedule the approved draft, cross the due-cron boundary, and retain partial delivery. */

defined( 'ABSPATH' ) || exit;

use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Abilities\StepTypeAbilities;
use DataMachine\Core\Steps\SystemTask\SystemTaskStep;
use DataMachine\Core\Steps\WorkflowConfigFactory;
use DataMachine\Core\Steps\WorkflowSpecValidator;
use DataMachine\Engine\ExecutionPlan;
use DataMachine\Engine\Tasks\TaskRegistry;
use DataMachineSocials\Operations\DelegatedCrossPostAction;

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	/** Single-site WP Codebox fixture mapping for the canonical main-site identity. */
	function ec_get_blog_id( string $site ): int {
		return 'main' === $site ? get_current_blog_id() : 0;
	}
}

datamachine_register_core_actions();
new SystemTaskStep();
datamachine_socials_bootstrap();
DataMachine\Engine\Tasks\TaskRegistry::reset();

/** Fail the deterministic journey with an actionable oracle name. */
function ec_studio_operator_assert( bool $condition, string $oracle ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Operator oracle failed: ' . $oracle );
	}
}

/** Execute the current committed direct-operation generation synchronously. */
function ec_studio_operator_execute_job( array $job ): array {
	$ability = wp_get_ability( 'datamachine/execute-step' );
	ec_studio_operator_assert( (bool) $ability, 'real execute-step ability exists' );
	$acting_user_id = get_current_user_id();
	wp_set_current_user( (int) $job['user_id'] );
	try {
		$result = $ability->execute(
			array(
				'job_id'                => (int) $job['job_id'],
				'flow_step_id'          => (string) $job['operation_step_id'],
				'operation_generation'  => (int) $job['operation_generation'],
				'operation_claim_token' => (string) $job['operation_claim_token'],
			)
		);
	} finally {
		wp_set_current_user( $acting_user_id );
	}
	ec_studio_operator_assert( ! is_wp_error( $result ), 'delegated operation executes without runtime error' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ': ' . $result->get_error_message() . ')' : '' ) );
	return is_array( $result ) ? $result : array();
}

$state = get_option( 'ec_studio_operator_state', array() );
ec_studio_operator_assert( is_array( $state ) && ! empty( $state['draft_id'] ), 'persisted setup state reloads' );
$draft_id        = (int) $state['draft_id'];
$article_id      = (int) $state['article_id'];
$gardner_id      = (int) $state['gardner_user_id'];
$ordinary_id     = (int) $state['ordinary_user_id'];
$approved_caption = (string) $state['approved_caption'];
$approved_media  = $state['media'][1];

ec_studio_operator_assert( 'pending' === get_post_status( $draft_id ), 'draft begins pending review' );
ec_studio_operator_assert( $approved_caption === get_post_meta( $draft_id, '_studio_social_caption', true ), 'pre-approval caption edit is canonical' );
ec_studio_operator_assert( array( $approved_media ) === get_post_meta( $draft_id, '_studio_social_images', true ), 'pre-approval media edit is canonical' );

$publish_input = array(
	'content_ref'     => array(
		'post_id'      => $draft_id,
		'source_url'   => get_permalink( $draft_id ),
		'caption'      => $approved_caption,
		'content_hash' => hash( 'sha256', $approved_caption ),
		'asset_refs'   => array( array( 'source_id' => $approved_media['source_id'], 'role' => 'image' ) ),
	),
	'target_policy'   => array( 'channels' => array( 'instagram', 'bluesky' ), 'media_kind' => 'image' ),
	'idempotency_key' => 'studio-social-publish:' . get_current_blog_id() . ':' . $draft_id,
	'attribution_post' => array( 'site_id' => get_current_blog_id(), 'post_id' => $article_id ),
);

// Ordinary team users fail at the real custom REST and durable ability boundaries.
wp_set_current_user( $ordinary_id );
$request = new WP_REST_Request( 'POST', '/datamachine/v1/socials/post' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body(
	wp_json_encode(
		array(
			'platforms' => array( 'instagram' ),
			'caption'   => 'Unauthorized direct REST attempt.',
			'images'    => array( array( 'url' => $approved_media['url'] ) ),
			'media_kind' => 'image',
		)
	)
);
$rest_response = rest_do_request( $request );
ec_studio_operator_assert( 403 === $rest_response->get_status(), 'ordinary team custom REST denial' );
$enqueue_ability = wp_get_ability( 'datamachine/enqueue-social-publish' );
ec_studio_operator_assert( (bool) $enqueue_ability, 'real durable publish ability exists' );
$permission = $enqueue_ability->check_permissions( $publish_input );
ec_studio_operator_assert( false === $permission || is_wp_error( $permission ), 'ordinary team durable ability denial' );

wp_set_current_user( $gardner_id );
ec_studio_operator_assert( true === $enqueue_ability->check_permissions( $publish_input ), 'Gardner durable ability grant' );

$future_gmt   = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
$future_local = get_date_from_gmt( $future_gmt );
wp_set_current_user( (int) $state['execution_owner_user_id'] );
$scheduled    = wp_update_post(
	array(
		'ID'            => $draft_id,
		'edit_date'     => true,
		// Core converts publish + a future date to the canonical future status.
		'post_status'   => 'publish',
		'post_date'     => $future_local,
		'post_date_gmt' => $future_gmt,
	),
	true
);
$scheduled_status = (string) get_post_status( $draft_id );
ec_studio_operator_assert(
	! is_wp_error( $scheduled ) && 'future' === $scheduled_status,
	'WordPress Core future status schedules draft (result=' . ( is_wp_error( $scheduled ) ? $scheduled->get_error_code() : (string) $scheduled ) . ', status=' . $scheduled_status . ', date=' . $future_gmt . ', now=' . gmdate( 'Y-m-d H:i:s' ) . ')'
);
ec_studio_operator_assert( (bool) wp_next_scheduled( 'publish_future_post', array( $draft_id ) ), 'Core publish_future_post event exists' );
$pre_due_calls = get_option( 'ec_studio_operator_provider_ledger', array() );
$pre_due_effects = array_filter(
	is_array( $pre_due_calls ) ? $pre_due_calls : array(),
	static fn( $entry ) => in_array( $entry['provider_call'] ?? '', array( 'instagram.publish-effect', 'bluesky.publish-effect' ), true )
);
ec_studio_operator_assert( 0 === count( $pre_due_effects ), 'zero provider effects before due time' );
ec_studio_operator_assert( '' === get_post_meta( $draft_id, '_studio_social_delivery_ref', true ), 'zero owner receipts before due time' );

// Same-status save while future must not enqueue.
wp_update_post( array( 'ID' => $draft_id, 'post_status' => 'future', 'post_title' => 'Gardner scheduled social review' ) );
$same_status_calls = get_option( 'ec_studio_operator_provider_ledger', array() );
$same_status_effects = array_filter(
	is_array( $same_status_calls ) ? $same_status_calls : array(),
	static fn( $entry ) => in_array( $entry['provider_call'] ?? '', array( 'instagram.publish-effect', 'bluesky.publish-effect' ), true )
);
ec_studio_operator_assert( 0 === count( $same_status_effects ), 'same-status schedule update has zero effects' );
wp_set_current_user( $gardner_id );

global $wpdb;
$past_gmt   = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );
$past_local = get_date_from_gmt( $past_gmt );
$wpdb->update(
	$wpdb->posts,
	array( 'post_date' => $past_local, 'post_date_gmt' => $past_gmt ),
	array( 'ID' => $draft_id ),
	array( '%s', '%s' ),
	array( '%d' )
);
clean_post_cache( $draft_id );
do_action( 'publish_future_post', $draft_id );
ec_studio_operator_assert( 'publish' === get_post_status( $draft_id ), 'due cron transitions future to publish' );
$publish_input['content_ref']['source_url'] = get_permalink( $draft_id );

$owner_context = array(
	'phase'         => 'submit',
	'action'        => DelegatedCrossPostAction::ACTION_ID,
	'operation_id'  => $publish_input['idempotency_key'],
	'operation_ref' => 'dop_' . str_repeat( 'a', 64 ),
	'actor'         => array( 'user_id' => get_current_user_id(), 'agent_id' => 0 ),
);
$owner_input = DelegatedCrossPostAction::normalize_input(
	array(
		'post_id'          => $publish_input['content_ref']['post_id'],
		'source_url'       => $publish_input['content_ref']['source_url'],
		'caption'          => $publish_input['content_ref']['caption'],
		'content_hash'     => $publish_input['content_ref']['content_hash'],
		'channels'         => $publish_input['target_policy']['channels'],
		'media_kind'       => $publish_input['target_policy']['media_kind'],
		'asset_refs'       => $publish_input['content_ref']['asset_refs'],
		'attribution_post' => $publish_input['attribution_post'],
	),
	$owner_context
);
$owner_context['input'] = is_array( $owner_input ) ? $owner_input : array();
$owner_policy = is_array( $owner_input ) ? DelegatedCrossPostAction::authorize( $owner_context ) : $owner_input;
$prepared = is_array( $owner_input ) ? DelegatedCrossPostAction::prepare( $owner_input, $owner_context ) : $owner_input;
$prepared_workflow = is_array( $prepared ) ? ( $prepared['workflow'] ?? null ) : null;
$workflow_validation = WorkflowSpecValidator::validate( $prepared_workflow );
$execution_plan = array( 'valid' => false, 'first_step_id' => null, 'error' => null );
if ( ! empty( $workflow_validation['valid'] ) ) {
	try {
		$configs = WorkflowConfigFactory::buildEphemeralConfigs( $prepared_workflow );
		$execution_plan['first_step_id'] = ExecutionPlan::from_flow_config( $configs['flow_config'] )->first_step_id();
		$execution_plan['valid'] = ! empty( $execution_plan['first_step_id'] );
	} catch ( Throwable $exception ) {
		$execution_plan['error'] = $exception->getMessage();
	}
}
$diagnostic = array(
	'blog_id'                  => get_current_blog_id(),
	'multisite'                => is_multisite(),
	'acting_user_id'           => get_current_user_id(),
	'execution_owner_user_id'  => $state['execution_owner_user_id'],
	'execution_owner_agent_id' => $state['execution_owner_agent_id'],
	'source_post'              => array( 'id' => $article_id, 'status' => get_post_status( $article_id ), 'url' => get_permalink( $article_id ) ),
	'draft_post'               => array( 'id' => $draft_id, 'status' => get_post_status( $draft_id ), 'platforms' => get_post_meta( $draft_id, '_studio_social_platforms', true ), 'caption_hash' => hash( 'sha256', (string) get_post_meta( $draft_id, '_studio_social_caption', true ) ) ),
	'operation_id'             => $publish_input['idempotency_key'],
	'operation_fingerprint'    => hash( 'sha256', (string) wp_json_encode( $publish_input ) ),
	'step_types'               => array_keys( ( new StepTypeAbilities() )->getAllStepTypes() ),
	'task_handlers'            => array_keys( TaskRegistry::getHandlers() ),
	'owner_policy'             => true === $owner_policy ? 'authorized' : ( is_wp_error( $owner_policy ) ? $owner_policy->get_error_code() : 'denied' ),
	'prepared_workflow'        => $prepared_workflow,
	'workflow_validation'      => $workflow_validation,
	'execution_plan'           => $execution_plan,
	'delegated_submit_ability' => wp_get_ability( 'datamachine/submit-delegated-operation' ) ? 'available' : 'missing',
);

$delivery_ref = (string) get_post_meta( $draft_id, '_studio_social_delivery_ref', true );
$handoff_retries = array();
for ( $attempt = 1; '' === $delivery_ref && $attempt <= 3; ++$attempt ) {
	$handoff_retries[] = wp_get_ability( 'extrachill/retry-social-publish' )->execute( array( 'post_id' => $draft_id ) );
	$delivery_ref      = (string) get_post_meta( $draft_id, '_studio_social_delivery_ref', true );
}
$key  = 'delegated:' . hash( 'sha256', "delegated-idempotency\0" . DelegatedCrossPostAction::ACTION_ID . "\0studio-social-publish:" . get_current_blog_id() . ':' . $draft_id );
$jobs = new Jobs();
$job  = $jobs->get_job_by_idempotency_key( $key );
$job_diagnostic = is_array( $job )
	? array_intersect_key( $job, array_flip( array( 'job_id', 'status', 'operation_state', 'operation_generation', 'operation_action_id' ) ) )
	: null;
ec_studio_operator_assert(
	1 === preg_match( '/^dop_[a-f0-9]{64}$/', $delivery_ref ),
	'one opaque delegated receipt is stored (log=' . (string) wp_json_encode( get_post_meta( $draft_id, '_studio_social_publish_log', true ) ) . ', job=' . (string) wp_json_encode( $job_diagnostic ) . ', handoff_retries=' . (string) wp_json_encode( $handoff_retries ) . ', as=' . ( function_exists( 'as_schedule_single_action' ) ? 'loaded' : 'missing' ) . ')'
);
ec_studio_operator_assert( is_array( $job ), 'one durable delegated operation exists' );
$job_id = (int) $job['job_id'];

// Unchanged replay, rapid double-submit, and reload-equivalent reads reuse one identity.
$unchanged_one = ExtraChillStudio\enqueue_social_publish( get_post( $draft_id ) );
$unchanged_two = ExtraChillStudio\enqueue_social_publish( get_post( $draft_id ) );
ec_studio_operator_assert( ! empty( $unchanged_one['success'] ) && ! empty( $unchanged_one['delivery']['duplicate'] ), 'unchanged replay is explicit duplicate' );
ec_studio_operator_assert( $delivery_ref === ( $unchanged_one['delivery']['delivery_ref'] ?? '' ), 'unchanged replay preserves operation identity' );
ec_studio_operator_assert( $delivery_ref === ( $unchanged_two['delivery']['delivery_ref'] ?? '' ), 'rapid double-submit preserves operation identity' );
ec_studio_operator_assert( $job_id === (int) $jobs->get_job_by_idempotency_key( $key )['job_id'], 'rapid replay creates no second job' );

// A stale tab changes live meta after approval; frozen operation input must not change.
$stale_caption = 'Stale tab overwrite that must never reach a provider.';
update_post_meta( $draft_id, '_studio_social_caption', $stale_caption );
$changed = ExtraChillStudio\enqueue_social_publish( get_post( $draft_id ) );
ec_studio_operator_assert( empty( $changed['success'] ) && 'social_publish_idempotency_conflict' === ( $changed['error']['code'] ?? '' ), 'changed-input replay conflicts explicitly' );
wp_update_post( array( 'ID' => $draft_id, 'post_status' => 'publish', 'post_title' => 'Gardner published social review' ) );
ec_studio_operator_assert( $job_id === (int) $jobs->get_job_by_idempotency_key( $key )['job_id'], 'same publish status creates no duplicate operation' );

// Direct delegated-operation and forged-receipt bypasses fail at owner boundaries.
wp_set_current_user( $ordinary_id );
$direct = wp_get_ability( 'datamachine/submit-delegated-operation' )->execute(
	array(
		'action'       => DelegatedCrossPostAction::ACTION_ID,
		'operation_id' => 'ordinary-team-forged-operation',
		'input'        => array(
			'post_id'      => $draft_id,
			'source_url'   => get_permalink( $draft_id ),
			'caption'      => $approved_caption,
			'content_hash' => hash( 'sha256', $approved_caption ),
			'channels'     => array( 'instagram' ),
			'media_kind'   => 'image',
			'asset_refs'   => array( array( 'source_id' => $approved_media['source_id'], 'role' => 'image' ) ),
		),
	)
);
ec_studio_operator_assert( is_wp_error( $direct ) || empty( $direct['success'] ), 'direct delegated operation bypass denied' );
wp_set_current_user( $gardner_id );
$forged = wp_get_ability( 'datamachine/get-social-publish' )->execute( array( 'delivery_ref' => 'dop_' . str_repeat( 'a', 64 ) ) );
ec_studio_operator_assert( is_wp_error( $forged ) || empty( $forged['success'] ), 'forged receipt denied' );

$execution = ec_studio_operator_execute_job( $jobs->get_job( $job_id ) );
$job       = $jobs->get_job( $job_id );
ec_studio_operator_assert( str_starts_with( (string) $job['status'], 'failed' ), 'partial delivery reaches durable failed state' );
$partial = ExtraChillStudio\get_social_publish_state( $draft_id );
ec_studio_operator_assert( ! empty( $partial['success'] ) && 'failed' === ( $partial['delivery']['status'] ?? '' ), 'partial delivery reload is plain failed state' );
ec_studio_operator_assert( ! empty( $partial['delivery']['retryable'] ), 'partial delivery is safely retryable' );

$shares = DataMachineSocials\Tracking\SocialShareTracker::get_shares( $article_id );
ec_studio_operator_assert( 1 === count( $shares ) && 'instagram' === $shares[0]['platform'], 'partial delivery preserves Instagram exactly once' );

$transitions   = get_option( 'ec_studio_operator_transition_ledger', array() );
$transitions[] = array( 'state' => 'future', 'effects' => 0, 'core_event' => 'publish_future_post' );
$transitions[] = array( 'state' => 'queued', 'job_id' => $job_id, 'delivery_ref_hash' => hash( 'sha256', $delivery_ref ) );
$transitions[] = array( 'state' => 'partial', 'instagram' => 'delivered', 'bluesky' => 'undelivered', 'retryable' => true );
update_option( 'ec_studio_operator_transition_ledger', $transitions, false );
$state['delivery_ref'] = $delivery_ref;
$state['job_id']       = $job_id;
$state['idempotency_key'] = $key;
$state['stale_caption_hash'] = hash( 'sha256', $stale_caption );
$state['product_contract_diagnostic'] = $diagnostic;
update_option( 'ec_studio_operator_state', $state, false );

echo wp_json_encode(
	array(
		'schema'       => 'extrachill-studio/social-operator-delivery-phase/v1',
		'status'       => 'partial',
		'job_id'       => $job_id,
		'delivery_ref' => hash( 'sha256', $delivery_ref ),
		'oracles'      => array( 'future-before-due-zero-effects', 'one-operation', 'explicit-idempotency', 'partial-preserved' ),
	),
	JSON_PRETTY_PRINT
);
