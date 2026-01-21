<?php
/**
 * Event submission endpoint proxying Data Machine flows.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_event_submission_route' );

function extrachill_api_register_event_submission_route() {
	register_rest_route( 'extrachill/v1', '/event-submissions', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_handle_event_submission',
		'permission_callback' => '__return_true',
		'args'                => array(
			'flow_id' => array(
				'required' => false,
				'type'     => 'integer',
			),
			'turnstile_response' => array(
				'required' => true,
				'type'     => 'string',
			),
			'system_prompt' => array(
				'required' => false,
				'type'     => 'string',
			),
		),
	) );
}

function extrachill_api_handle_event_submission( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error( 'turnstile_missing', __( 'Security verification unavailable.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$is_local_environment = defined( 'WP_ENVIRONMENT_TYPE' ) && WP_ENVIRONMENT_TYPE === 'local';
	$turnstile_bypass     = $is_local_environment || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );

	$turnstile_response = $request->get_param( 'turnstile_response' );
	if ( ! $turnstile_bypass && ( empty( $turnstile_response ) || ! ec_verify_turnstile_response( $turnstile_response ) ) ) {
		return new WP_Error( 'turnstile_failed', __( 'Security verification failed. Please try again.', 'extrachill-api' ), array( 'status' => 403 ) );
	}

	$flow_id = absint( $request->get_param( 'flow_id' ) );

	if ( $flow_id ) {
		return extrachill_api_execute_with_flow( $request, $flow_id );
	}

	return extrachill_api_execute_direct( $request );
}

/**
 * Execute event submission using a pre-configured Data Machine flow.
 *
 * @param WP_REST_Request $request REST request object.
 * @param int             $flow_id Data Machine flow ID.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function extrachill_api_execute_with_flow( WP_REST_Request $request, int $flow_id ) {
	$submission = extrachill_api_extract_submission_fields( $request );
	if ( is_wp_error( $submission ) ) {
		return $submission;
	}

	if ( ! class_exists( '\\DataMachine\\Core\\Database\\Flows\\Flows' ) ) {
		return new WP_Error( 'datamachine_missing', __( 'Data Machine is unavailable.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$db_flows = new \DataMachine\Core\Database\Flows\Flows();
	$flow     = $db_flows->get_flow( $flow_id );
	if ( ! $flow ) {
		return new WP_Error( 'flow_not_found', __( 'Flow not found.', 'extrachill-api' ), array( 'status' => 404 ) );
	}

	$stored_flyer = extrachill_api_store_submission_flyer( $request, $flow_id, (int) $flow['pipeline_id'] );
	if ( is_wp_error( $stored_flyer ) ) {
		return $stored_flyer;
	}

	if ( $stored_flyer ) {
		$submission['flyer'] = $stored_flyer;
	}

	$job_manager = new \DataMachine\Services\JobManager();
	$job_id      = $job_manager->create( $flow_id, (int) $flow['pipeline_id'] );
	if ( ! $job_id ) {
		return new WP_Error( 'job_creation_failed', __( 'Could not create a job for this submission.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	if ( function_exists( 'datamachine_merge_engine_data' ) ) {
		$engine_data = array( 'submission' => $submission );

		if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
			$engine_data['image_file_path'] = $stored_flyer['stored_path'];
		}

		datamachine_merge_engine_data( $job_id, $engine_data );
	}

	if ( ! function_exists( 'as_schedule_single_action' ) ) {
		return new WP_Error( 'scheduler_unavailable', __( 'Scheduler unavailable.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$action_id = as_schedule_single_action( time(), 'datamachine_run_flow_now', array( $flow_id, $job_id ), 'datamachine' );
	if ( ! $action_id ) {
		return new WP_Error( 'execution_failed', __( 'Failed to queue the flow.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	do_action( 'extrachill_event_submission', $submission, array(
		'flow_id'   => $flow_id,
		'job_id'    => $job_id,
		'action_id' => $action_id,
		'flow_name' => $flow['flow_name'] ?? '',
	) );

	return rest_ensure_response( array(
		'message' => __( 'Thanks! We queued your submission for review.', 'extrachill-api' ),
		'job_id'  => $job_id,
	) );
}

function extrachill_api_extract_submission_fields( WP_REST_Request $request ) {
	$user_id = get_current_user_id();

	if ( $user_id ) {
		$current_user  = wp_get_current_user();
		$contact_name  = $current_user->display_name;
		$contact_email = $current_user->user_email;
	} else {
		$contact_name  = sanitize_text_field( $request->get_param( 'contact_name' ) );
		$contact_email = sanitize_email( $request->get_param( 'contact_email' ) );

		if ( empty( $contact_name ) || empty( $contact_email ) ) {
			return new WP_Error( 'missing_fields', __( 'Name and email are required.', 'extrachill-api' ), array( 'status' => 400 ) );
		}

		if ( ! is_email( $contact_email ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-api' ), array( 'status' => 400 ) );
		}
	}

	$event_title = sanitize_text_field( $request->get_param( 'event_title' ) );
	$event_date  = sanitize_text_field( $request->get_param( 'event_date' ) );

	if ( empty( $event_title ) || empty( $event_date ) ) {
		return new WP_Error( 'missing_fields', __( 'Event title and date are required.', 'extrachill-api' ), array( 'status' => 400 ) );
	}

	return array(
		'user_id'       => $user_id,
		'contact_name'  => $contact_name,
		'contact_email' => $contact_email,
		'event_title'   => $event_title,
		'event_date'    => $event_date,
		'event_time'    => sanitize_text_field( $request->get_param( 'event_time' ) ),
		'venue_name'    => sanitize_text_field( $request->get_param( 'venue_name' ) ),
		'event_city'    => sanitize_text_field( $request->get_param( 'event_city' ) ),
		'event_lineup'  => sanitize_text_field( $request->get_param( 'event_lineup' ) ),
		'event_link'    => esc_url_raw( $request->get_param( 'event_link' ) ),
		'notes'         => sanitize_textarea_field( $request->get_param( 'notes' ) ),
	);
}

function extrachill_api_store_submission_flyer( WP_REST_Request $request, int $flow_id, int $pipeline_id ) {
	$files = $request->get_file_params();
	if ( empty( $files['flyer'] ) || empty( $files['flyer']['tmp_name'] ) ) {
		return null;
	}

	$flyer = $files['flyer'];

	require_once ABSPATH . 'wp-admin/includes/file.php';
	$upload = wp_handle_upload( $flyer, array( 'test_form' => false ) );
	if ( isset( $upload['error'] ) ) {
		return new WP_Error( 'flyer_upload_failed', $upload['error'], array( 'status' => 400 ) );
	}

	$storage = new \DataMachine\Core\FilesRepository\FileStorage();
	$stored  = $storage->store_file( $upload['file'], $flyer['name'], array(
		'pipeline_id' => $pipeline_id,
		'flow_id'     => $flow_id,
	) );

	// Always clean up temp file regardless of storage success
	if ( file_exists( $upload['file'] ) ) {
		wp_delete_file( $upload['file'] );
	}

	if ( ! $stored ) {
		return new WP_Error( 'flyer_store_failed', __( 'Could not save the flyer to the submission queue.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	// Use wp_check_filetype for server-validated MIME type
	$file_info = wp_check_filetype( $flyer['name'] );

	return array(
		'filename'    => sanitize_file_name( $flyer['name'] ),
		'stored_path' => $stored,
		'mime_type'   => $file_info['type'] ?: 'application/octet-stream',
	);
}

/**
 * Execute event submission using an ephemeral Data Machine workflow.
 *
 * Builds and executes a self-contained workflow without requiring
 * a pre-configured flow in the database.
 *
 * @param WP_REST_Request $request REST request object.
 * @return WP_REST_Response|WP_Error Response or error.
 */
function extrachill_api_execute_direct( WP_REST_Request $request ) {
	$submission = extrachill_api_extract_submission_fields( $request );
	if ( is_wp_error( $submission ) ) {
		return $submission;
	}

	if ( ! class_exists( '\\DataMachine\\Core\\PluginSettings' ) ) {
		return new WP_Error( 'datamachine_missing', __( 'Data Machine is unavailable.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$stored_flyer = extrachill_api_store_submission_flyer_direct( $request );
	if ( is_wp_error( $stored_flyer ) ) {
		return $stored_flyer;
	}

	$system_prompt    = sanitize_textarea_field( $request->get_param( 'system_prompt' ) );
	$default_provider = \DataMachine\Core\PluginSettings::get( 'default_provider', 'anthropic' );
	$default_model    = \DataMachine\Core\PluginSettings::get( 'default_model', 'claude-sonnet-4-20250514' );

	$workflow = extrachill_api_build_event_submission_workflow(
		$submission,
		$stored_flyer,
		$default_provider,
		$default_model,
		$system_prompt
	);

	$initial_data = array(
		'submission' => $submission,
	);
	if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
		$initial_data['image_file_path'] = $stored_flyer['stored_path'];
	}

	$execute_request = new WP_REST_Request( 'POST', '/datamachine/v1/execute' );
	$execute_request->set_body_params( array(
		'workflow'     => $workflow,
		'initial_data' => $initial_data,
	) );

	$response = rest_do_request( $execute_request );

	if ( $response->is_error() ) {
		return $response->as_error();
	}

	$data   = $response->get_data();
	$job_id = $data['data']['job_id'] ?? 0;

	extrachill_api_notify_submitter( $submission );
	extrachill_api_notify_admin( $submission, $job_id );

	do_action( 'extrachill_event_submission', $submission, array(
		'flow_id' => 'direct',
		'job_id'  => $job_id,
		'mode'    => 'ephemeral',
	) );

	return rest_ensure_response( array(
		'message' => __( 'Thanks! We queued your submission for review.', 'extrachill-api' ),
		'job_id'  => $job_id,
	) );
}

/**
 * Build an ephemeral workflow for event submission.
 *
 * Creates a workflow structure that Data Machine can execute directly
 * without a database-stored flow configuration.
 *
 * @param array       $submission    Submission data from the form.
 * @param array|null  $stored_flyer  Stored flyer data or null if no flyer.
 * @param string      $provider      AI provider slug.
 * @param string      $model         AI model identifier.
 * @param string      $system_prompt Custom system prompt for AI processing.
 * @return array Workflow configuration for Data Machine execute endpoint.
 */
function extrachill_api_build_event_submission_workflow(
	array $submission,
	?array $stored_flyer,
	string $provider,
	string $model,
	string $system_prompt = ''
): array {
	$steps = array();

	$handler_config = array(
		'title'      => $submission['event_title'],
		'startDate'  => $submission['event_date'],
		'startTime'  => $submission['event_time'],
		'venue_name' => $submission['venue_name'],
		'venue_city' => $submission['event_city'],
		'performer'  => $submission['event_lineup'],
		'ticketUrl'  => $submission['event_link'],
	);

	// Step 1: EventFlyer handler (only if flyer uploaded)
	if ( $stored_flyer ) {
		$steps[] = array(
			'type'           => 'event_import',
			'handler_slug'   => 'event_flyer',
			'handler_config' => $handler_config,
		);
	}

	// Step 2: AI step (ALWAYS runs - validates/enriches data)
	$default_prompt = 'You are processing an event submission. ' .
		'Extract and validate event details. ' .
		'Use the upsert_event tool to create the event with accurate information.';

	$steps[] = array(
		'type'          => 'ai',
		'provider'      => $provider,
		'model'         => $model,
		'system_prompt' => $system_prompt ?: $default_prompt,
		'user_message'  => 'Process this event submission and create the event.',
		'enabled_tools' => array( 'upsert_event' ),
	);

	// Step 3: EventUpsert (creates/updates event post)
	$steps[] = array(
		'type'           => 'update',
		'handler_slug'   => 'upsert_event',
		'handler_config' => array(
			'post_status'    => 'pending',
			'include_images' => ! empty( $stored_flyer ),
		),
	);

	return array( 'steps' => $steps );
}

/**
 * Store uploaded flyer for direct/ephemeral workflow execution.
 *
 * @param WP_REST_Request $request REST request object.
 * @return array|null|WP_Error Stored flyer data, null if no flyer, or error.
 */
function extrachill_api_store_submission_flyer_direct( WP_REST_Request $request ) {
	$files = $request->get_file_params();
	if ( empty( $files['flyer'] ) || empty( $files['flyer']['tmp_name'] ) ) {
		return null;
	}

	$flyer = $files['flyer'];

	require_once ABSPATH . 'wp-admin/includes/file.php';
	$upload = wp_handle_upload( $flyer, array( 'test_form' => false ) );

	if ( isset( $upload['error'] ) ) {
		return new WP_Error( 'flyer_upload_failed', $upload['error'], array( 'status' => 400 ) );
	}

	$storage = new \DataMachine\Core\FilesRepository\FileStorage();

	$stored = $storage->store_file( $upload['file'], $flyer['name'], array(
		'pipeline_id' => 'direct',
		'flow_id'     => 'direct',
	) );

	if ( file_exists( $upload['file'] ) ) {
		wp_delete_file( $upload['file'] );
	}

	if ( ! $stored ) {
		return new WP_Error( 'flyer_store_failed', __( 'Could not save the flyer.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$file_info = wp_check_filetype( $flyer['name'] );

	return array(
		'filename'    => sanitize_file_name( $flyer['name'] ),
		'stored_path' => $stored,
		'mime_type'   => $file_info['type'] ?: 'application/octet-stream',
	);
}

/**
 * Send confirmation email to the person who submitted the event.
 *
 * @param array $submission Submission data.
 */
function extrachill_api_notify_submitter( array $submission ) {
	$to = $submission['contact_email'] ?? '';
	if ( empty( $to ) || ! is_email( $to ) ) {
		return;
	}

	$subject = sprintf(
		'[%s] Event Submission Received: %s',
		get_bloginfo( 'name' ),
		$submission['event_title']
	);

	$message = sprintf(
		"Thanks for submitting your event!\n\n" .
		"Event: %s\n" .
		"Date: %s\n" .
		"Venue: %s\n\n" .
		"We're processing your submission now. You'll receive another email once it's been reviewed.",
		$submission['event_title'],
		$submission['event_date'],
		$submission['venue_name'] ?: 'Not specified'
	);

	wp_mail( $to, $subject, $message );
}

/**
 * Send notification email to site admin about new event submission.
 *
 * @param array $submission Submission data.
 * @param int   $job_id     Data Machine job ID.
 */
function extrachill_api_notify_admin( array $submission, int $job_id ) {
	$to = get_option( 'admin_email' );

	$subject = sprintf(
		'[%s] New Event Submission: %s',
		get_bloginfo( 'name' ),
		$submission['event_title']
	);

	$message = sprintf(
		"A new event submission has been received:\n\n" .
		"Event: %s\n" .
		"Date: %s\n" .
		"Venue: %s\n" .
		"Submitted by: %s (%s)\n\n" .
		"Data Machine Job ID: %d\n\n" .
		"The submission is being processed now. Check pending posts in a few minutes.",
		$submission['event_title'],
		$submission['event_date'],
		$submission['venue_name'] ?: 'Not specified',
		$submission['contact_name'],
		$submission['contact_email'],
		$job_id
	);

	wp_mail( $to, $subject, $message );
}
