<?php
/**
 * Bounded delegated campaign owner action.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/newsletter-post-type-registration.php';

const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION = 'extrachill-newsletter/canonical-post-campaign';
const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY = 'canonical-post-draft';
const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK   = 'extrachill_newsletter_delegated_campaign';
const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_AGENT  = 'newsletter-campaign-owner';

require_once __DIR__ . '/delegated-campaign-agent.php';
require_once __DIR__ . '/delegated-campaign-receipts.php';

add_filter( 'datamachine_delegated_operation_actions', 'extrachill_newsletter_register_delegated_campaign_action' );
add_action( 'plugins_loaded', 'extrachill_newsletter_register_delegated_campaign_task', 30 );
add_action( 'wp_agents_api_init', 'extrachill_newsletter_register_delegated_campaign_agent' );

/**
 * Register the Newsletter-owned delegated operation with Data Machine.
 *
 * @param array $actions Existing owner action definitions.
 * @return array Owner action definitions.
 */
function extrachill_newsletter_register_delegated_campaign_action( array $actions ): array {
	$actions[ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION ] = array(
		'version'         => '1',
		'normalize_input' => 'extrachill_newsletter_normalize_delegated_campaign_input',
		'authorize'       => 'extrachill_newsletter_authorize_delegated_campaign',
		'prepare'         => 'extrachill_newsletter_prepare_delegated_campaign',
		'project'         => 'extrachill_newsletter_project_delegated_campaign',
		'retry'           => 'extrachill_newsletter_retry_delegated_campaign',
	);

	return $actions;
}

/**
 * Register the bounded task used by the owner-prepared workflow.
 */
function extrachill_newsletter_register_delegated_campaign_task() {
	if ( ! class_exists( '\DataMachine\Engine\AI\System\Tasks\SystemTask' ) ) {
		return;
	}

	require_once __DIR__ . '/tasks/class-delegated-campaign-task.php';
	add_filter(
		'datamachine_tasks',
		static function ( array $tasks ): array {
			$tasks[ EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK ] = \ExtraChillNewsletter\Tasks\DelegatedCampaignTask::class;
			return $tasks;
		}
	);
}

/**
 * Normalize the owner-supported delegated campaign input.
 *
 * @param mixed $input Proposed action input.
 * @return array|WP_Error Normalized input.
 */
function extrachill_newsletter_normalize_delegated_campaign_input( $input ) {
	if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'source', 'policy' ) ) ) {
		return new WP_Error( 'newsletter_campaign_invalid_input', 'Delegated campaign input is invalid.' );
	}

	$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
	if ( array_diff( array_keys( $source ), array( 'site_id', 'post_id' ) ) ) {
		return new WP_Error( 'newsletter_campaign_invalid_source', 'The canonical source reference is invalid.' );
	}

	$site_id = isset( $source['site_id'] ) && is_int( $source['site_id'] ) ? $source['site_id'] : 0;
	$post_id = isset( $source['post_id'] ) && is_int( $source['post_id'] ) ? $source['post_id'] : 0;
	$policy  = isset( $input['policy'] ) && is_string( $input['policy'] ) ? $input['policy'] : '';

	if ( $site_id <= 0 || $post_id <= 0 ) {
		return new WP_Error( 'newsletter_campaign_invalid_source', 'The canonical source reference is invalid.' );
	}

	if ( EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY !== $policy ) {
		return new WP_Error( 'newsletter_campaign_unsupported_policy', 'The campaign policy is not supported.' );
	}

	return array(
		'source' => array(
			'site_id' => $site_id,
			'post_id' => $post_id,
		),
		'policy' => $policy,
	);
}

/**
 * Require the caller's domain owner to freshly approve the canonical source.
 *
 * Newsletter intentionally provides no administration-capability fallback.
 * The domain plugin must approve its own resource on every delegated phase.
 *
 * @param array $context Data Machine delegated operation context.
 * @return true|WP_Error Authorization result.
 */
function extrachill_newsletter_authorize_delegated_campaign( array $context ) {
	$input  = isset( $context['input'] ) && is_array( $context['input'] ) ? $context['input'] : array();
	$source = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
	if ( EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION !== ( $context['action'] ?? '' ) || ! isset( $source['site_id'], $source['post_id'] ) ) {
		return new WP_Error( 'newsletter_campaign_forbidden', 'The delegated campaign is not authorized.' );
	}

	/**
	 * Filter fresh domain authorization for a canonical campaign source.
	 *
	 * Domain owners return true only after authorizing the current actor and
	 * source resource. Newsletter administrators receive no implicit approval.
	 *
	 * @param bool|WP_Error $authorized Authorization result. Default false.
	 * @param array         $source     Canonical site/post reference.
	 * @param array         $context    Delegated operation context and actor.
	 */
	$authorized = apply_filters( 'extrachill_newsletter_authorize_delegated_campaign', false, $source, $context );
	if ( true === $authorized ) {
		return true;
	}

	return new WP_Error( 'newsletter_campaign_forbidden', 'The delegated campaign is not authorized.' );
}

/**
 * Prepare the private Newsletter-owned workflow and stable execution owner.
 *
 * @param array $input   Normalized owner input.
 * @param array $context Data Machine delegated operation context.
 * @return array|WP_Error Private workflow descriptor.
 */
function extrachill_newsletter_prepare_delegated_campaign( array $input, array $context ) {
	$owner_user_id = extrachill_newsletter_delegated_campaign_owner_user_id();
	if ( ! $owner_user_id ) {
		return new WP_Error( 'newsletter_campaign_owner_unavailable', 'The Newsletter execution owner is unavailable.' );
	}
	$operation_ref = isset( $context['operation_ref'] ) && is_string( $context['operation_ref'] ) ? $context['operation_ref'] : '';
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return new WP_Error( 'newsletter_campaign_operation_invalid', 'The delegated operation reference is invalid.' );
	}
	$receipt = extrachill_newsletter_prepare_delegated_campaign_authorization_receipt( $operation_ref, $input, $context );
	if ( is_wp_error( $receipt ) ) {
		return $receipt;
	}
	$task_params = array_merge(
		$input,
		array(
			'owner_context' => array(
				'operation_ref' => $operation_ref,
				'attestation'   => extrachill_newsletter_delegated_campaign_attestation( $operation_ref, $input ),
			),
		)
	);
	if ( '' === $task_params['owner_context']['attestation'] ) {
		return new WP_Error( 'newsletter_campaign_owner_unavailable', 'The Newsletter owner capability is unavailable.' );
	}

	return array(
		'owner_user_id' => $owner_user_id,
		'agent_slug'    => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_AGENT,
		'label'         => 'Create canonical post newsletter campaign',
		'workflow'      => array(
			'steps' => array(
				array(
					'step_type'          => 'system_task',
					'flow_step_settings' => array(
						'task_type' => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK,
						'params'    => $task_params,
					),
				),
			),
		),
	);
}

/** Freeze the first initiating actor for fresh effect-time authorization. */
function extrachill_newsletter_prepare_delegated_campaign_authorization_receipt( $operation_ref, array $input, array $context ) {
	$actor = isset( $context['actor'] ) && is_array( $context['actor'] ) ? $context['actor'] : array();
	foreach ( array( 'user_id', 'agent_id', 'token_id' ) as $actor_key ) {
		if ( isset( $actor[ $actor_key ] ) && ( ! is_int( $actor[ $actor_key ] ) || $actor[ $actor_key ] < 0 ) ) {
			return new WP_Error( 'newsletter_campaign_actor_invalid', 'The delegated campaign actor is invalid.' );
		}
	}
	$receipt = array(
		'action'        => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION,
		'operation_ref' => $operation_ref,
		'input'         => $input,
		'actor'         => array(
			'user_id'  => $actor['user_id'] ?? 0,
			'agent_id' => $actor['agent_id'] ?? 0,
			'token_id' => $actor['token_id'] ?? 0,
		),
	);
	if ( ! $receipt['actor']['user_id'] && ! $receipt['actor']['agent_id'] ) {
		return new WP_Error( 'newsletter_campaign_actor_invalid', 'The delegated campaign actor is invalid.' );
	}

	$option = 'extrachill_newsletter_delegated_auth_' . substr( $operation_ref, 4 );
	add_site_option( $option, $receipt );
	$stored = get_site_option( $option, null );
	if (
		! is_array( $stored )
		|| ( $stored['action'] ?? null ) !== $receipt['action']
		|| ( $stored['operation_ref'] ?? null ) !== $operation_ref
		|| ( $stored['input'] ?? null ) !== $input
		|| ! is_array( $stored['actor'] ?? null )
		|| ( empty( $stored['actor']['user_id'] ) && empty( $stored['actor']['agent_id'] ) )
	) {
		return new WP_Error( 'newsletter_campaign_authorization_receipt_invalid', 'The delegated campaign authorization receipt is invalid.' );
	}

	return $stored;
}

/** Re-run domain authorization for the attested initiator immediately before effects. */
function extrachill_newsletter_authorize_delegated_campaign_effect( $operation_ref, array $input ) {
	if ( ! is_string( $operation_ref ) || ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return new WP_Error( 'newsletter_campaign_authorization_missing', 'The delegated campaign authorization receipt is unavailable.' );
	}
	$receipt = get_site_option( 'extrachill_newsletter_delegated_auth_' . substr( $operation_ref, 4 ), null );
	if ( ! is_array( $receipt ) || EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION !== ( $receipt['action'] ?? null ) || ( $receipt['operation_ref'] ?? null ) !== $operation_ref || ( $receipt['input'] ?? null ) !== $input || ! is_array( $receipt['actor'] ?? null ) ) {
		return new WP_Error( 'newsletter_campaign_authorization_missing', 'The delegated campaign authorization receipt is unavailable.' );
	}

	return extrachill_newsletter_authorize_delegated_campaign(
		array(
			'phase'         => 'execute',
			'action'        => EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION,
			'operation_ref' => $operation_ref,
			'actor'         => $receipt['actor'],
			'input'         => $input,
		)
	);
}

/**
 * Derive the private capability that binds one prepared owner workflow.
 *
 * @param string $operation_ref Opaque Data Machine operation reference.
 * @param array  $input         Normalized owner input.
 * @return string HMAC attestation.
 */
function extrachill_newsletter_delegated_campaign_attestation( $operation_ref, array $input ) {
	$payload = EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION . "\0" . $operation_ref . "\0" . wp_json_encode( $input );
	$secret  = extrachill_newsletter_delegated_campaign_secret();
	return '' === $secret ? '' : hash_hmac( 'sha256', $payload, $secret );
}

/** Resolve the stable owner secret used only for private workflow capabilities. */
function extrachill_newsletter_delegated_campaign_secret() {
	$option = 'extrachill_newsletter_delegated_campaign_secret';
	$secret = get_site_option( $option, '' );
	if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
		return $secret;
	}

	$candidate = wp_generate_password( 64, true, true );
	add_site_option( $option, $candidate );
	$secret = get_site_option( $option, '' );
	return is_string( $secret ) && strlen( $secret ) >= 32 ? $secret : '';
}

/**
 * Verify and strip the private workflow capability before owner execution.
 *
 * @param array $params System task parameters.
 * @return array|WP_Error Normalized owner input.
 */
function extrachill_newsletter_verify_delegated_campaign_task( array $params ) {
	$owner_context = isset( $params['owner_context'] ) && is_array( $params['owner_context'] ) ? $params['owner_context'] : array();
	$input         = extrachill_newsletter_normalize_delegated_campaign_input(
		array(
			'source' => $params['source'] ?? null,
			'policy' => $params['policy'] ?? null,
		)
	);
	if ( is_wp_error( $input ) ) {
		return $input;
	}

	$operation_ref = isset( $owner_context['operation_ref'] ) && is_string( $owner_context['operation_ref'] ) ? $owner_context['operation_ref'] : '';
	$attestation   = isset( $owner_context['attestation'] ) && is_string( $owner_context['attestation'] ) ? $owner_context['attestation'] : '';
	$expected      = extrachill_newsletter_delegated_campaign_attestation( $operation_ref, $input );
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) || '' === $expected || '' === $attestation || ! hash_equals( $expected, $attestation ) ) {
		return new WP_Error( 'newsletter_campaign_task_forbidden', 'The Newsletter owner task is not authorized.' );
	}

	return $input;
}

/**
 * Project canonical run truth through Newsletter's authoritative records.
 *
 * @param array $run_result Canonical Data Machine run result.
 * @param array $context    Frozen delegated operation context.
 * @return array|WP_Error Redacted public projection.
 */
function extrachill_newsletter_project_delegated_campaign( array $run_result, array $context ) {
	if ( 'datamachine.run_result.v1' !== ( $run_result['schema_version'] ?? null ) ) {
		return new WP_Error( 'newsletter_campaign_run_result_invalid', 'The delegated campaign run result is invalid.' );
	}
	$operation_ref  = isset( $context['operation_ref'] ) && is_string( $context['operation_ref'] ) ? $context['operation_ref'] : '';
	$outcome        = extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref );
	$status         = strtolower( (string) ( $run_result['status'] ?? '' ) );
	$input          = isset( $context['input'] ) && is_array( $context['input'] ) ? $context['input'] : array();
	$source         = isset( $input['source'] ) && is_array( $input['source'] ) ? $input['source'] : array();
	$durable_record = extrachill_newsletter_get_operation_campaign_record( $operation_ref, $input );
	$canonical_noop = in_array( $status, array( 'agent_skipped', 'skipped', 'completed_no_items', 'no_items', 'no-op' ), true ) || 0 === ( $run_result['outputs']['effect_count'] ?? null );
	$canonical_fail = str_contains( $status, 'fail' ) || str_contains( $status, 'error' );

	if ( in_array( $status, array( 'submitted', 'executing', 'retrying' ), true ) ) {
		$classification = $status;
		$record         = null;
		$error_code     = null;
	} elseif ( ! empty( $durable_record['owns_effect'] ) && in_array( $durable_record['state'] ?? '', array( 'creating', 'indeterminate' ), true ) ) {
		$classification = 'failed';
		$record         = null;
		$error_code     = 'newsletter_campaign_reconciliation_required';
	} elseif ( 'cancelled' === $status ) {
		$classification = 'cancelled';
		$record         = null;
		$error_code     = null;
	} elseif ( $canonical_fail ) {
		$classification = 'failed';
		$record         = null;
		$error_code     = 'newsletter_campaign_failed';
	} elseif ( $canonical_noop ) {
		$classification = 'no-op';
		$record         = null;
		$error_code     = null;
	} elseif ( is_array( $outcome ) ) {
		$classification = in_array( $outcome['status'] ?? '', array( 'executed', 'no-op', 'failed' ), true ) ? $outcome['status'] : 'failed';
		$record         = isset( $outcome['record'] ) && is_array( $outcome['record'] ) ? $outcome['record'] : null;
		$error_code     = isset( $outcome['error_code'] ) && is_string( $outcome['error_code'] ) ? $outcome['error_code'] : null;
	} elseif ( 'completed' === ( $durable_record['state'] ?? null ) && ! empty( $durable_record['campaign_id'] ) ) {
		$classification = 'executed';
		$record         = array(
			'newsletter_post_id' => $durable_record['newsletter_post_id'],
			'campaign_id'        => $durable_record['campaign_id'],
		);
		$error_code     = null;
	} else {
		$classification = 'failed';
		$record         = null;
		$error_code     = 'newsletter_campaign_outcome_missing';
	}

	$projection = array(
		'classification' => $classification,
		'record'         => $record,
		'error_code'     => $error_code,
	);
	if ( in_array( $classification, array( 'executed', 'no-op' ), true ) ) {
		$projection['effect_count'] = 'executed' === $classification && ! empty( $record['campaign_id'] ) ? 1 : 0;
	}

	return $projection;
}

/** Prove explicit retry safe from Newsletter's durable effect receipt. */
function extrachill_newsletter_retry_delegated_campaign( array $run_result, array $context ) {
	if ( 'datamachine.run_result.v1' !== ( $run_result['schema_version'] ?? null ) || ! str_starts_with( strtolower( (string) ( $run_result['status'] ?? '' ) ), 'failed' ) ) {
		return new WP_Error( 'newsletter_campaign_retry_unsafe', 'The delegated campaign cannot be retried safely.' );
	}

	$operation_ref = isset( $context['operation_ref'] ) && is_string( $context['operation_ref'] ) ? $context['operation_ref'] : '';
	$outcome       = extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref );
	$input         = isset( $context['input'] ) && is_array( $context['input'] ) ? $context['input'] : array();
	$record        = extrachill_newsletter_get_operation_campaign_record( $operation_ref, $input );
	if ( ! empty( $record['owns_effect'] ) && in_array( $record['state'] ?? '', array( 'creating', 'indeterminate' ), true ) ) {
		return new WP_Error( 'newsletter_campaign_retry_unsafe', 'The delegated campaign cannot be retried safely.' );
	}
	if ( 'completed' === ( $record['state'] ?? null ) && ! empty( $record['campaign_id'] ) ) {
		return true;
	}
	if ( ! is_array( $outcome ) || 'failed' !== ( $outcome['status'] ?? '' ) ) {
		if ( null === $record ) {
			return true;
		}
		if ( '' === ( $record['state'] ?? null ) ) {
			return true;
		}
		return new WP_Error( 'newsletter_campaign_retry_unsafe', 'The delegated campaign cannot be retried safely.' );
	}

	$safe_codes = array(
		'newsletter_campaign_authorization_missing',
		'newsletter_campaign_forbidden',
		'newsletter_campaign_owner_runtime_unavailable',
		'newsletter_campaign_owner_unavailable',
		'newsletter_campaign_operation_binding_failed',
		'newsletter_campaign_busy',
		'newsletter_campaign_draft_create_failed',
		'newsletter_campaign_draft_identity_failed',
		'newsletter_campaign_draft_restore_failed',
		'newsletter_campaign_receipt_failed',
		'newsletter_campaign_transport_unavailable',
	);
	return in_array( $outcome['error_code'] ?? '', $safe_codes, true )
		? true
		: new WP_Error( 'newsletter_campaign_retry_unsafe', 'The delegated campaign cannot be retried safely.' );
}

/** Persist one bounded owner outcome for exact operation reconciliation. */
function extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, array $result ) {
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return false;
	}

	$result = extrachill_newsletter_sanitize_delegated_campaign_result( $result );
	if ( null === $result ) {
		return false;
	}

	$option    = 'extrachill_newsletter_delegated_result_' . substr( $operation_ref, 4 );
	$lock_name = 'ecn_result_' . substr( hash( 'sha256', $operation_ref ), 0, 53 );
	if ( ! extrachill_newsletter_acquire_delegated_campaign_lock( $lock_name ) ) {
		return false;
	}
	try {
		$current = get_site_option( $option, null );
		$current = is_array( $current ) ? extrachill_newsletter_sanitize_delegated_campaign_result( $current ) : null;
		if ( is_array( $current ) && in_array( $current['status'], array( 'executed', 'no-op' ), true ) ) {
			return true;
		}
		if ( update_site_option( $option, $result ) ) {
			return true;
		}

		return get_site_option( $option, null ) === $result;
	} finally {
		extrachill_newsletter_release_delegated_campaign_lock( $lock_name );
	}
}

/** Read one bounded owner outcome by opaque operation reference. */
function extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref ) {
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return null;
	}

	$outcome = get_site_option( 'extrachill_newsletter_delegated_result_' . substr( $operation_ref, 4 ), null );
	return is_array( $outcome ) ? extrachill_newsletter_sanitize_delegated_campaign_result( $outcome ) : null;
}

/**
 * Execute a delegated campaign from an approved canonical WordPress post.
 *
 * Data Machine owns operation identity and replay protection. Newsletter owns
 * the draft, campaign policy, transport invocation, and redacted projection.
 *
 * @param mixed $input Delegated action input.
 * @return array Redacted owner result.
 */
function extrachill_newsletter_execute_delegated_campaign( $input, $operation_ref = '' ) {
	$input = extrachill_newsletter_normalize_delegated_campaign_input( $input );
	if ( is_wp_error( $input ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, (string) $input->get_error_code() );
	}
	$operation_record = '' !== $operation_ref ? extrachill_newsletter_get_operation_campaign_record( $operation_ref, $input ) : null;
	if ( ! empty( $operation_record['owns_effect'] ) && in_array( $operation_record['state'] ?? '', array( 'creating', 'indeterminate' ), true ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'failed', $operation_record['newsletter_post_id'], null, 'newsletter_campaign_reconciliation_required' );
	}
	if ( ! empty( $operation_record['owns_effect'] ) && 'completed' === ( $operation_record['state'] ?? null ) && ! empty( $operation_record['campaign_id'] ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'executed', $operation_record['newsletter_post_id'], $operation_record['campaign_id'], null );
	}

	$source = extrachill_newsletter_get_delegated_campaign_source( $input['source'] );
	if ( is_wp_error( $source ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, (string) $source->get_error_code() );
	}

	if ( '' === trim( wp_strip_all_tags( $source['title'] . ' ' . $source['content'] ) ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_empty_source' );
	}

	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'newsletter' ) ) : 0;
	if ( ! $newsletter_blog_id ) {
		return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, 'newsletter_campaign_owner_unavailable' );
	}

	switch_to_blog( $newsletter_blog_id );
	$runtime_registered_here = ! post_type_exists( 'newsletter' );
	try {
		$runtime = extrachill_newsletter_ensure_delegated_campaign_owner_runtime();
		if ( is_wp_error( $runtime ) ) {
			return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, (string) $runtime->get_error_code() );
		}
		$lock_name = extrachill_newsletter_delegated_campaign_lock_name( $source, $input['policy'] );
		if ( ! extrachill_newsletter_acquire_delegated_campaign_lock( $lock_name ) ) {
			return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, 'newsletter_campaign_busy' );
		}

		try {
			$newsletter_post_id = extrachill_newsletter_get_or_create_delegated_draft( $source, $input );
			if ( is_wp_error( $newsletter_post_id ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, (string) $newsletter_post_id->get_error_code() );
			}
			if ( '' !== $operation_ref && ! extrachill_newsletter_bind_delegated_campaign_record( $operation_ref, $newsletter_post_id, $input ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_operation_binding_failed' );
			}
			$effect               = extrachill_newsletter_get_delegated_campaign_effect( $newsletter_post_id );
			$effect_operation_ref = is_array( $effect ) ? (string) ( $effect['operation_ref'] ?? '' ) : '';
			$state                = is_array( $effect ) ? (string) ( $effect['state'] ?? '' ) : (string) get_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', true );
			if ( '' !== $operation_ref && '' !== $effect_operation_ref && $operation_ref !== $effect_operation_ref ) {
				return extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_already_exists' );
			}
			if ( in_array( $state, array( 'creating', 'indeterminate', 'completed' ), true ) ) {
				$campaign_id = get_post_meta( $newsletter_post_id, '_sendy_campaign_id', true );
				if ( 'completed' === $state && $campaign_id ) {
					return '' === $operation_ref || $operation_ref === $effect_operation_ref
						? extrachill_newsletter_delegated_campaign_result( 'executed', $newsletter_post_id, $campaign_id, null )
						: extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_already_exists' );
				}
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_reconciliation_required' );
			}
			$campaign_id = get_post_meta( $newsletter_post_id, '_sendy_campaign_id', true );
			if ( $campaign_id ) {
				return '' === $operation_ref || $operation_ref === $effect_operation_ref
					? extrachill_newsletter_delegated_campaign_result( 'executed', $newsletter_post_id, $campaign_id, null )
					: extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_already_exists' );
			}
			if ( '' !== $operation_ref && '' === $effect_operation_ref ) {
				if ( ! extrachill_newsletter_claim_delegated_campaign_effect( $newsletter_post_id, $operation_ref ) ) {
					return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_receipt_failed' );
				}
				$effect_operation_ref = extrachill_newsletter_get_delegated_campaign_effect_owner( $newsletter_post_id );
			}
			if ( '' !== $operation_ref && $operation_ref !== $effect_operation_ref ) {
				return extrachill_newsletter_delegated_campaign_result( 'no-op', null, null, 'newsletter_campaign_already_exists' );
			}
			if ( ! extrachill_newsletter_get_sendy_ability( 'datamachine/sendy-push-campaign' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_transport_unavailable' );
			}
			if ( '' !== $operation_ref && ! extrachill_newsletter_record_delegated_campaign_effect_state( $newsletter_post_id, $operation_ref, 'creating' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_receipt_failed' );
			}
			if ( false === update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'creating' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_receipt_failed' );
			}

			$campaign = extrachill_newsletter_ability_push_campaign( array( 'post_id' => $newsletter_post_id ) );
			if ( is_wp_error( $campaign ) || empty( $campaign['success'] ) ) {
				if ( '' !== $operation_ref ) {
					extrachill_newsletter_record_delegated_campaign_effect_state( $newsletter_post_id, $operation_ref, 'indeterminate' );
				}
				update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'indeterminate' );
				return extrachill_newsletter_delegated_campaign_result(
					'failed',
					$newsletter_post_id,
					null,
					'newsletter_campaign_push_failed'
				);
			}
			$returned_campaign_ref  = $campaign['campaign_id'] ?? null;
			$persisted_campaign_ref = get_post_meta( $newsletter_post_id, '_sendy_campaign_id', true );
			$returned_campaign_id   = is_scalar( $returned_campaign_ref ) ? (string) $returned_campaign_ref : '';
			$persisted_campaign_id  = is_scalar( $persisted_campaign_ref ) ? (string) $persisted_campaign_ref : '';
			if ( '' === $returned_campaign_id || '' === $persisted_campaign_id || ! hash_equals( $returned_campaign_id, $persisted_campaign_id ) ) {
				if ( '' !== $operation_ref ) {
					extrachill_newsletter_record_delegated_campaign_effect_state( $newsletter_post_id, $operation_ref, 'indeterminate' );
				}
				update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'indeterminate' );
				return extrachill_newsletter_delegated_campaign_result(
					'failed',
					$newsletter_post_id,
					null,
					'newsletter_campaign_reference_missing'
				);
			}
			if ( '' !== $operation_ref && ! extrachill_newsletter_record_delegated_campaign_effect_state( $newsletter_post_id, $operation_ref, 'completed' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, $persisted_campaign_id, 'newsletter_campaign_receipt_failed' );
			}
			if ( false === update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'completed' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, $persisted_campaign_id, 'newsletter_campaign_receipt_failed' );
			}

			return extrachill_newsletter_delegated_campaign_result(
				'executed',
				$newsletter_post_id,
				$persisted_campaign_id,
				null
			);
		} finally {
			extrachill_newsletter_release_delegated_campaign_lock( $lock_name );
		}
	} finally {
		if ( $runtime_registered_here ) {
			unregister_post_type( 'newsletter' );
		}
		restore_current_blog();
	}
}

/** Register only the Newsletter-owned post type in the switched blog. */
function extrachill_newsletter_ensure_delegated_campaign_owner_runtime() {
	create_newsletter_post_type();
	return post_type_exists( 'newsletter' )
		? true
		: new WP_Error( 'newsletter_campaign_owner_runtime_unavailable', 'The Newsletter owner runtime is unavailable.' );
}

/** Build the multisite-scoped owner lock name. */
function extrachill_newsletter_delegated_campaign_lock_name( array $source, $policy ) {
	return 'ecn_' . substr( hash( 'sha256', get_current_blog_id() . ':' . $source['site_id'] . ':' . $source['post_id'] . ':' . $policy ), 0, 60 );
}

/** Acquire the owner lock before creating a draft or external campaign. */
function extrachill_newsletter_acquire_delegated_campaign_lock( $lock_name ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks have no WordPress API.
	return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 ) );
}

/** Release an owner lock held by this database connection. */
function extrachill_newsletter_release_delegated_campaign_lock( $lock_name ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- MySQL advisory locks have no WordPress API.
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
}

/**
 * Read a published canonical source without retaining caller-provided content.
 *
 * @param array $reference Canonical site/post reference.
 * @return array|WP_Error Source snapshot.
 */
function extrachill_newsletter_get_delegated_campaign_source( array $reference ) {
	switch_to_blog( $reference['site_id'] );
	try {
		$post = get_post( $reference['post_id'] );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'newsletter_campaign_source_unavailable', 'The canonical source is unavailable.' );
		}

		return array(
			'site_id' => $reference['site_id'],
			'post_id' => $reference['post_id'],
			'title'   => (string) $post->post_title,
			'content' => (string) $post->post_content,
			'excerpt' => (string) $post->post_excerpt,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Create or recover the Newsletter-owned draft for a canonical source.
 *
 * @param array $source Canonical source snapshot.
 * @param array $input  Normalized delegated input.
 * @return int|WP_Error Newsletter post ID.
 */
function extrachill_newsletter_get_or_create_delegated_draft( array $source, array $input ) {
	$post_id = extrachill_newsletter_find_delegated_campaign_draft( $source, $input['policy'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$existing    = (bool) $post_id;
	$bound       = $existing && extrachill_newsletter_get_bound_delegated_campaign_source( $input['source'], $input['policy'] ) === $post_id;
	$post_source = $existing ? extrachill_newsletter_get_delegated_campaign_post_source( $post_id ) : null;
	if ( is_array( $post_source ) && ( ( $post_source['source'] ?? null ) !== $input['source'] || ( $post_source['policy'] ?? null ) !== $input['policy'] ) ) {
		return new WP_Error( 'newsletter_campaign_draft_identity_conflict', 'The Newsletter campaign identity conflicts with an existing record.' );
	}

	if ( $post_id ) {
		if ( 'trash' === get_post_status( $post_id ) ) {
			$restored = wp_untrash_post( $post_id );
			if ( ! $restored ) {
				return new WP_Error( 'newsletter_campaign_draft_restore_failed', 'The Newsletter campaign record could not be restored.' );
			}
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return new WP_Error( 'newsletter_campaign_draft_restore_failed', 'The Newsletter campaign record could not be restored.' );
			}
		}
	} else {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'newsletter',
				'post_status'  => 'draft',
				'post_author'  => 0,
				'post_name'    => 'delegated-' . hash( 'sha256', $source['site_id'] . ':' . $source['post_id'] . ':' . $input['policy'] ),
				'post_title'   => $source['title'],
				'post_content' => $source['content'],
				'post_excerpt' => $source['excerpt'],
				'meta_input'   => array(
					'_extrachill_newsletter_source_site_id'  => $source['site_id'],
					'_extrachill_newsletter_source_post_id'  => $source['post_id'],
					'_extrachill_newsletter_campaign_policy' => $input['policy'],
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'newsletter_campaign_draft_create_failed', 'The Newsletter campaign record could not be created.' );
		}
	}

	$identity = array(
		'_extrachill_newsletter_source_site_id'  => (string) $source['site_id'],
		'_extrachill_newsletter_source_post_id'  => (string) $source['post_id'],
		'_extrachill_newsletter_campaign_policy' => $input['policy'],
		'_extrachill_newsletter_delegated_campaign_identity' => extrachill_newsletter_delegated_campaign_source_hash( $input['source'], $input['policy'] ),
	);
	foreach ( $identity as $key => $value ) {
		$current = (string) get_post_meta( $post_id, $key, true );
		if ( $current === $value ) {
			continue;
		}
		if ( $existing && ( '' !== $current || ( get_post_meta( $post_id, '_sendy_campaign_id', true ) && ! $bound ) ) ) {
			return new WP_Error( 'newsletter_campaign_draft_identity_conflict', 'The Newsletter campaign identity conflicts with an existing record.' );
		}
		update_post_meta( $post_id, $key, $value );
		if ( (string) get_post_meta( $post_id, $key, true ) !== $value ) {
			return new WP_Error( 'newsletter_campaign_draft_identity_failed', 'The Newsletter campaign identity could not be persisted.' );
		}
	}
	if ( ! extrachill_newsletter_bind_delegated_campaign_source( $post_id, $input['source'], $input['policy'] ) ) {
		return new WP_Error( 'newsletter_campaign_draft_identity_conflict', 'The Newsletter campaign identity conflicts with an existing record.' );
	}
	if ( $existing && ! get_post_meta( $post_id, '_sendy_campaign_id', true ) ) {
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $source['title'],
				'post_content' => $source['content'],
				'post_excerpt' => $source['excerpt'],
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return new WP_Error( 'newsletter_campaign_draft_update_failed', 'The Newsletter campaign record could not be updated.' );
		}
	}

	return $post_id;
}

/**
 * Find the Newsletter-owned draft for one canonical source and policy.
 *
 * @param array  $source Canonical source reference.
 * @param string $policy Owner-supported campaign policy.
 * @return int|WP_Error Newsletter post ID, zero when absent, or an ambiguity error.
 */
function extrachill_newsletter_find_delegated_campaign_draft( array $source, $policy ) {
	if ( ! is_int( $source['site_id'] ?? null ) || ! is_int( $source['post_id'] ?? null ) || $source['site_id'] <= 0 || $source['post_id'] <= 0 || EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY !== $policy ) {
		return 0;
	}

	$statuses = array_keys( get_post_stati() );
	if ( ! in_array( 'trash', $statuses, true ) ) {
		$statuses[] = 'trash';
	}
	$args              = array(
		'post_type'              => 'newsletter',
		'post_status'            => $statuses,
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'suppress_filters'       => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
		'meta_query'             => array(
			array(
				'key'   => '_extrachill_newsletter_source_site_id',
				'value' => (string) $source['site_id'],
			),
			array(
				'key'   => '_extrachill_newsletter_source_post_id',
				'value' => (string) $source['post_id'],
			),
			array(
				'key'   => '_extrachill_newsletter_campaign_policy',
				'value' => $policy,
			),
		),
	);
	$slug_args         = $args;
	$slug_args['name'] = 'delegated-' . hash( 'sha256', $source['site_id'] . ':' . $source['post_id'] . ':' . $policy );
	unset( $slug_args['meta_query'] );
	$slug_args['posts_per_page'] = -1;
	$slug_match                  = get_posts( $slug_args );
	$existing                    = get_posts( $args );
	$bound                       = extrachill_newsletter_get_bound_delegated_campaign_source(
		array(
			'site_id' => $source['site_id'],
			'post_id' => $source['post_id'],
		),
		$policy
	);
	if ( $bound && ! in_array( $bound, $existing, true ) ) {
		array_unshift( $existing, $bound );
	}
	$existing = array_values( array_unique( array_merge( $existing, $slug_match ) ) );
	if ( count( $existing ) > 1 ) {
		return new WP_Error( 'newsletter_campaign_draft_identity_conflict', 'Multiple Newsletter records claim the same campaign identity.' );
	}

	return ! empty( $existing[0] ) ? absint( $existing[0] ) : 0;
}

/**
 * Build the only result shape exposed across the delegated boundary.
 *
 * @param string     $status             Owner execution classification.
 * @param int|null   $newsletter_post_id Newsletter-owned record ID.
 * @param string|int $campaign_id        Newsletter-owned campaign reference.
 * @param string     $error_code         Bounded owner error code.
 * @return array Redacted result.
 */
function extrachill_newsletter_delegated_campaign_result( $status, $newsletter_post_id = null, $campaign_id = null, $error_code = null ) {
	$result = array(
		'schema'     => 'extrachill-newsletter.delegated-campaign-result.v1',
		'status'     => $status,
		'record'     => $newsletter_post_id ? array(
			'newsletter_post_id' => $newsletter_post_id,
			'campaign_id'        => null === $campaign_id || '' === $campaign_id ? null : (string) $campaign_id,
		) : null,
		'error_code' => $error_code ? (string) $error_code : null,
	);

	return extrachill_newsletter_sanitize_delegated_campaign_result( $result ) ?? array(
		'schema'     => 'extrachill-newsletter.delegated-campaign-result.v1',
		'status'     => 'failed',
		'record'     => null,
		'error_code' => 'newsletter_campaign_failed',
	);
}

/** Keep persisted and projected owner results to bounded identifiers and codes. */
function extrachill_newsletter_sanitize_delegated_campaign_result( array $result ) {
	$status = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : '';
	if ( 'extrachill-newsletter.delegated-campaign-result.v1' !== ( $result['schema'] ?? null ) || ! in_array( $status, array( 'executed', 'no-op', 'failed' ), true ) ) {
		return null;
	}

	$record = null;
	if ( is_array( $result['record'] ?? null ) ) {
		$post_id     = $result['record']['newsletter_post_id'] ?? 0;
		$campaign_id = $result['record']['campaign_id'] ?? null;
		if ( ! is_int( $post_id ) || $post_id <= 0 || ( null !== $campaign_id && ( ! is_scalar( $campaign_id ) || ! preg_match( '/^[A-Za-z0-9._:-]{1,191}$/', (string) $campaign_id ) ) ) ) {
			return null;
		}
		$record = array(
			'newsletter_post_id' => $post_id,
			'campaign_id'        => null === $campaign_id || '' === $campaign_id ? null : (string) $campaign_id,
		);
	}

	$error_code = $result['error_code'] ?? null;
	if ( null !== $error_code && ( ! is_string( $error_code ) || ! preg_match( '/^newsletter_campaign_[a-z0-9_]{1,96}$/', $error_code ) ) ) {
		$error_code = 'newsletter_campaign_failed';
	}

	return array(
		'schema'     => 'extrachill-newsletter.delegated-campaign-result.v1',
		'status'     => $status,
		'record'     => $record,
		'error_code' => $error_code,
	);
}
