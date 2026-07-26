<?php
/**
 * Bounded delegated campaign owner action.
 *
 * @package ExtraChillNewsletter
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_ACTION = 'extrachill-newsletter/canonical-post-campaign';
const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY = 'canonical-post-draft';
const EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_TASK   = 'extrachill_newsletter_delegated_campaign';

add_filter( 'datamachine_delegated_operation_actions', 'extrachill_newsletter_register_delegated_campaign_action' );
add_action( 'plugins_loaded', 'extrachill_newsletter_register_delegated_campaign_task', 30 );

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

	if ( ! $site_id || ! $post_id ) {
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

	return is_wp_error( $authorized )
		? $authorized
		: new WP_Error( 'newsletter_campaign_forbidden', 'The delegated campaign is not authorized.' );
}

/**
 * Prepare the private Newsletter-owned workflow and stable execution owner.
 *
 * @param array $input   Normalized owner input.
 * @param array $context Data Machine delegated operation context.
 * @return array|WP_Error Private workflow descriptor.
 */
function extrachill_newsletter_prepare_delegated_campaign( array $input, array $context ) {
	$owner_user_id = absint(
		apply_filters(
			'extrachill_newsletter_delegated_campaign_owner_user_id',
			get_site_option( 'admin_user_id', 0 ),
			$input,
			$context
		)
	);
	if ( ! $owner_user_id ) {
		return new WP_Error( 'newsletter_campaign_owner_unavailable', 'The Newsletter execution owner is unavailable.' );
	}
	$operation_ref = isset( $context['operation_ref'] ) && is_string( $context['operation_ref'] ) ? $context['operation_ref'] : '';
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return new WP_Error( 'newsletter_campaign_operation_invalid', 'The delegated operation reference is invalid.' );
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
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) || ! hash_equals( $expected, $attestation ) ) {
		return new WP_Error( 'newsletter_campaign_task_forbidden', 'The Newsletter owner task is not authorized.' );
	}

	return $input;
}

/**
 * Project canonical run truth through Newsletter's authoritative records.
 *
 * @param array $run_result Canonical Data Machine run result.
 * @param array $context    Frozen delegated operation context.
 * @return array Redacted public projection.
 */
function extrachill_newsletter_project_delegated_campaign( array $run_result, array $context ): array {
	$operation_ref = isset( $context['operation_ref'] ) && is_string( $context['operation_ref'] ) ? $context['operation_ref'] : '';
	$outcome       = extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref );
	$status        = strtolower( (string) ( $run_result['status'] ?? '' ) );

	if ( is_array( $outcome ) ) {
		$classification = in_array( $outcome['status'] ?? '', array( 'executed', 'no-op', 'failed' ), true ) ? $outcome['status'] : 'failed';
		$record         = isset( $outcome['record'] ) && is_array( $outcome['record'] ) ? $outcome['record'] : null;
		$error_code     = isset( $outcome['error_code'] ) && is_string( $outcome['error_code'] ) ? $outcome['error_code'] : null;
	} elseif ( '' === $status ) {
		$classification = 'submitted';
		$record         = null;
		$error_code     = null;
	} elseif ( str_contains( $status, 'fail' ) || str_contains( $status, 'error' ) ) {
		$classification = 'failed';
		$record         = null;
		$error_code     = 'newsletter_campaign_failed';
	} else {
		$classification = 'no-op';
		$record         = null;
		$error_code     = null;
	}

	return array(
		'effect_count'   => ! empty( $record['campaign_id'] ) ? 1 : 0,
		'classification' => $classification,
		'record'         => $record,
		'error_code'     => $error_code,
	);
}

/** Persist one bounded owner outcome for exact operation reconciliation. */
function extrachill_newsletter_record_delegated_campaign_outcome( $operation_ref, array $result ) {
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return false;
	}

	$option = 'extrachill_newsletter_delegated_result_' . substr( $operation_ref, 4 );
	if ( update_site_option( $option, $result ) ) {
		return true;
	}

	return get_site_option( $option, null ) === $result;
}

/** Read one bounded owner outcome by opaque operation reference. */
function extrachill_newsletter_get_delegated_campaign_outcome( $operation_ref ) {
	if ( ! preg_match( '/^dop_[a-f0-9]{64}$/', $operation_ref ) ) {
		return null;
	}

	$outcome = get_site_option( 'extrachill_newsletter_delegated_result_' . substr( $operation_ref, 4 ), null );
	return is_array( $outcome ) ? $outcome : null;
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
function extrachill_newsletter_execute_delegated_campaign( $input ) {
	$input = extrachill_newsletter_normalize_delegated_campaign_input( $input );
	if ( is_wp_error( $input ) ) {
		return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, (string) $input->get_error_code() );
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
	try {
		$lock_name = extrachill_newsletter_delegated_campaign_lock_name( $source, $input['policy'] );
		if ( ! extrachill_newsletter_acquire_delegated_campaign_lock( $lock_name ) ) {
			return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, 'newsletter_campaign_busy' );
		}

		try {
			$newsletter_post_id = extrachill_newsletter_get_or_create_delegated_draft( $source, $input );
			if ( is_wp_error( $newsletter_post_id ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', null, null, (string) $newsletter_post_id->get_error_code() );
			}
			$campaign_id = get_post_meta( $newsletter_post_id, '_sendy_campaign_id', true );
			if ( $campaign_id ) {
				return extrachill_newsletter_delegated_campaign_result( 'executed', $newsletter_post_id, $campaign_id, null );
			}
			$state = (string) get_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', true );
			if ( in_array( $state, array( 'creating', 'indeterminate', 'completed' ), true ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_reconciliation_required' );
			}
			if ( false === update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'creating' ) ) {
				return extrachill_newsletter_delegated_campaign_result( 'failed', $newsletter_post_id, null, 'newsletter_campaign_receipt_failed' );
			}

			$campaign = extrachill_newsletter_ability_push_campaign( array( 'post_id' => $newsletter_post_id ) );
			if ( is_wp_error( $campaign ) || empty( $campaign['success'] ) ) {
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
				update_post_meta( $newsletter_post_id, '_extrachill_newsletter_delegated_campaign_state', 'indeterminate' );
				return extrachill_newsletter_delegated_campaign_result(
					'failed',
					$newsletter_post_id,
					null,
					'newsletter_campaign_reference_missing'
				);
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
		restore_current_blog();
	}
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
	$existing = extrachill_newsletter_find_delegated_campaign_draft( $source, $input['policy'] );

	if ( $existing ) {
		return $existing;
	}

	return wp_insert_post(
		array(
			'post_type'    => 'newsletter',
			'post_status'  => 'draft',
			'post_author'  => 0,
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
}

/**
 * Find the Newsletter-owned draft for one canonical source and policy.
 *
 * @param array  $source Canonical source reference.
 * @param string $policy Owner-supported campaign policy.
 * @return int Newsletter post ID, or zero when absent.
 */
function extrachill_newsletter_find_delegated_campaign_draft( array $source, $policy ) {
	if ( empty( $source['site_id'] ) || empty( $source['post_id'] ) || EXTRACHILL_NEWSLETTER_DELEGATED_CAMPAIGN_POLICY !== $policy ) {
		return 0;
	}

	$existing = get_posts(
		array(
			'post_type'              => 'newsletter',
			'post_status'            => array( 'draft', 'pending', 'publish', 'private' ),
			'posts_per_page'         => 1,
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
		)
	);

	return ! empty( $existing[0] ) ? absint( $existing[0] ) : 0;
}

/**
 * Read the safe Newsletter-owned references for public reconciliation.
 *
 * @param array  $source Canonical source reference.
 * @param string $policy Owner-supported campaign policy.
 * @return array|null Safe record references.
 */
function extrachill_newsletter_get_delegated_campaign_record( array $source, $policy ) {
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'newsletter' ) ) : 0;
	if ( ! $newsletter_blog_id ) {
		return null;
	}

	switch_to_blog( $newsletter_blog_id );
	try {
		$post_id = extrachill_newsletter_find_delegated_campaign_draft( $source, $policy );
		if ( ! $post_id ) {
			return null;
		}

		$campaign_id = get_post_meta( $post_id, '_sendy_campaign_id', true );
		return array(
			'newsletter_post_id' => $post_id,
			'campaign_id'        => $campaign_id ? (string) $campaign_id : null,
		);
	} finally {
		restore_current_blog();
	}
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
	return array(
		'schema'     => 'extrachill-newsletter.delegated-campaign-result.v1',
		'status'     => $status,
		'record'     => $newsletter_post_id ? array(
			'newsletter_post_id' => absint( $newsletter_post_id ),
			'campaign_id'        => null === $campaign_id || '' === $campaign_id ? null : (string) $campaign_id,
		) : null,
		'error_code' => $error_code ? (string) $error_code : null,
	);
}
