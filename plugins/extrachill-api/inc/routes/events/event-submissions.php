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
				'required' => true,
				'type'     => 'integer',
			),
			'turnstile_response' => array(
				'required' => true,
				'type'     => 'string',
			),
		),
	) );
}

function extrachill_api_handle_event_submission( WP_REST_Request $request ) {
	$flow_id = absint( $request->get_param( 'flow_id' ) );
	if ( ! $flow_id ) {
		return new WP_Error( 'invalid_flow_id', __( 'Valid flow ID required.', 'extrachill-api' ), array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
		return new WP_Error( 'turnstile_missing', __( 'Security verification unavailable.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	$turnstile_response = $request->get_param( 'turnstile_response' );
	if ( empty( $turnstile_response ) || ! ec_verify_turnstile_response( $turnstile_response ) ) {
		return new WP_Error( 'turnstile_failed', __( 'Security verification failed. Please try again.', 'extrachill-api' ), array( 'status' => 403 ) );
	}

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

		// Store image path directly in engine for AI vision processing
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

	if ( $stored && file_exists( $upload['file'] ) ) {
		wp_delete_file( $upload['file'] );
	}

	if ( ! $stored ) {
		return new WP_Error( 'flyer_store_failed', __( 'Could not save the flyer to the submission queue.', 'extrachill-api' ), array( 'status' => 500 ) );
	}

	return array(
		'filename'   => sanitize_file_name( $flyer['name'] ),
		'stored_path' => $stored,
		'mime_type'  => isset( $upload['type'] ) ? sanitize_mime_type( $upload['type'] ) : '',
	);
}
