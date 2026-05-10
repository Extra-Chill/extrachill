<?php
/**
 * Transcribe Recording Ability
 *
 * Submits a WP audio attachment to the sweatpants worker on chubes.net for
 * transcription, persists a local job row, and returns the sweatpants job id
 * + initial status. Polling + draft creation happens in
 * extrachill/transcription-job-status.
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

use ExtraChillStudio\Transcription\SweatpantsClient;

/**
 * Register the transcribe-recording ability.
 *
 * @since 0.10.0
 *
 * @return void
 */
function ec_studio_register_transcribe_recording_ability(): void {
	if ( ! class_exists( 'WP_Ability' ) ) {
		return;
	}

	$register = function () {
		wp_register_ability(
			'extrachill/transcribe-recording',
			array(
				'label'               => __( 'Transcribe Recording', 'extrachill-studio' ),
				'description'         => __( 'Submit a WP audio attachment to the sweatpants worker for transcription.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'attachment_id'  => array(
							'type'        => 'integer',
							'description' => __( 'WP attachment ID for the audio file.', 'extrachill-studio' ),
						),
						'model'          => array(
							'type'        => 'string',
							'enum'        => array( 'base', 'medium', 'large' ),
							'default'     => 'medium',
							'description' => __( 'Whisper model size.', 'extrachill-studio' ),
						),
						'diarize'        => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Run PyAnnote speaker diarization.', 'extrachill-studio' ),
						),
						'remove_fillers' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Strip filler words from the transcript.', 'extrachill-studio' ),
						),
					),
					'required'   => array( 'attachment_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'ec_studio_execute_transcribe_recording',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' ) || ( function_exists( 'ec_is_team_member' ) && \ec_is_team_member() );
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	};

	if ( doing_action( 'wp_abilities_api_init' ) ) {
		$register();
	} elseif ( ! did_action( 'wp_abilities_api_init' ) ) {
		add_action( 'wp_abilities_api_init', $register );
	}
}
ec_studio_register_transcribe_recording_ability();

/**
 * Execute callback for transcribe-recording.
 *
 * @since 0.10.0
 *
 * @param array $input Validated input.
 * @return array|\WP_Error
 */
function ec_studio_execute_transcribe_recording( array $input ): array|\WP_Error {
	$attachment_id  = (int) ( $input['attachment_id'] ?? 0 );
	$model          = isset( $input['model'] ) ? (string) $input['model'] : 'medium';
	$diarize        = ! empty( $input['diarize'] );
	$remove_fillers = ! empty( $input['remove_fillers'] );

	if ( $attachment_id <= 0 ) {
		return new \WP_Error( 'invalid_attachment', __( 'attachment_id is required and must be a positive integer.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new \WP_Error( 'attachment_not_found', __( 'Attachment not found.', 'extrachill-studio' ), array( 'status' => 404 ) );
	}

	if ( ! wp_attachment_is( 'audio', $attachment ) ) {
		return new \WP_Error( 'not_audio', __( 'Attachment is not an audio file.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return new \WP_Error( 'not_logged_in', __( 'You must be logged in to transcribe recordings.', 'extrachill-studio' ), array( 'status' => 401 ) );
	}

	$attachment_url = wp_get_attachment_url( $attachment_id );
	if ( ! $attachment_url ) {
		return new \WP_Error( 'attachment_url_missing', __( 'Could not resolve a URL for the attachment.', 'extrachill-studio' ), array( 'status' => 500 ) );
	}

	$inputs = array(
		'audio_url'      => $attachment_url,
		'output_dir'     => '/var/lib/sweatpants/output/' . wp_generate_uuid4(),
		'model'          => $model,
		'diarize'        => $diarize,
		'remove_fillers' => $remove_fillers,
		'language'       => 'en',
	);

	$client   = new SweatpantsClient();
	$response = $client->submit_job( 'audio-transcription', $inputs );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$job_id = isset( $response['id'] ) ? (string) $response['id'] : '';
	if ( '' === $job_id ) {
		return new \WP_Error( 'sweatpants_bad_response', __( 'Sweatpants response is missing the job id.', 'extrachill-studio' ), array( 'status' => 502 ) );
	}

	$status = isset( $response['status'] ) ? (string) $response['status'] : 'pending';

	ec_studio_transcription_save_job(
		$job_id,
		array(
			'job_id'         => $job_id,
			'attachment_id'  => $attachment_id,
			'attachment_url' => $attachment_url,
			'user_id'        => $user_id,
			'model'          => $model,
			'diarize'        => $diarize,
			'remove_fillers' => $remove_fillers,
			'target_blog_id' => get_current_blog_id(),
			'status'         => $status,
			'created_at'     => current_time( 'c', true ),
			'completed_at'   => null,
			'draft_post_id'  => null,
			'draft_post_url' => null,
			'error'          => null,
		)
	);

	return array(
		'job_id' => $job_id,
		'status' => $status,
	);
}
