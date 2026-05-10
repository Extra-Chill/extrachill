<?php
/**
 * Transcription Job Status Ability
 *
 * Polls the sweatpants worker for a job's current state and, on the first
 * poll where sweatpants reports `completed`, fetches the results, picks the
 * appropriate transcript flavour based on the job's diarize/remove_fillers
 * flags, and creates a draft post on the target blog.
 *
 * Cached terminal states (completed/failed) short-circuit the remote call so
 * UI polling stays cheap once the job has resolved.
 *
 * @package    ExtraChillStudio
 * @subpackage Abilities
 * @since      0.10.0
 */

defined( 'ABSPATH' ) || exit;

use ExtraChillStudio\Transcription\SweatpantsClient;

/**
 * Register the transcription-job-status ability.
 *
 * @since 0.10.0
 *
 * @return void
 */
function ec_studio_register_transcription_job_status_ability(): void {
	if ( ! class_exists( 'WP_Ability' ) ) {
		return;
	}

	$register = function () {
		wp_register_ability(
			'extrachill/transcription-job-status',
			array(
				'label'               => __( 'Transcription Job Status', 'extrachill-studio' ),
				'description'         => __( 'Poll a transcription job and create a draft post once it completes.', 'extrachill-studio' ),
				'category'            => 'extrachill',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array(
							'type'        => 'string',
							'description' => __( 'Sweatpants job UUID.', 'extrachill-studio' ),
						),
					),
					'required'   => array( 'job_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'job_id'         => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'draft_post_id'  => array( 'type' => 'integer' ),
						'draft_post_url' => array( 'type' => 'string' ),
						'error'          => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => 'ec_studio_execute_transcription_job_status',
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
ec_studio_register_transcription_job_status_ability();

/**
 * Pick the appropriate transcript content flavour from a results envelope.
 *
 * @since 0.10.0
 *
 * @param array $data            results[0].data payload from sweatpants.
 * @param bool  $diarize         Whether the job was diarized.
 * @param bool  $remove_fillers  Whether filler-removal was requested.
 * @return string Transcript content (may be empty if all fallbacks miss).
 */
function ec_studio_transcription_pick_transcript_content( array $data, bool $diarize, bool $remove_fillers ): string {
	$content = isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array();

	$pick = static function ( array $bag, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $bag[ $key ] ) && is_string( $bag[ $key ] ) && '' !== trim( $bag[ $key ] ) ) {
				return (string) $bag[ $key ];
			}
		}
		return '';
	};

	if ( $diarize && $remove_fillers ) {
		return $pick( $content, array( 'combined_txt_clean', 'combined_txt', 'transcription' ) );
	}
	if ( $diarize ) {
		return $pick( $content, array( 'combined_txt', 'transcription' ) );
	}
	return $pick( $content, array( 'transcription' ) );
}

/**
 * Execute callback for transcription-job-status.
 *
 * @since 0.10.0
 *
 * @param array $input Validated input.
 * @return array|\WP_Error
 */
function ec_studio_execute_transcription_job_status( array $input ): array|\WP_Error {
	$job_id = isset( $input['job_id'] ) ? (string) $input['job_id'] : '';
	if ( '' === $job_id ) {
		return new \WP_Error( 'invalid_job_id', __( 'job_id is required.', 'extrachill-studio' ), array( 'status' => 400 ) );
	}

	$job = ec_studio_transcription_get_job( $job_id );
	if ( null === $job ) {
		return new \WP_Error( 'job_not_found', __( 'Transcription job not found.', 'extrachill-studio' ), array( 'status' => 404 ) );
	}

	$current_user = get_current_user_id();
	if ( (int) ( $job['user_id'] ?? 0 ) !== $current_user && ! current_user_can( 'manage_options' ) ) {
		return new \WP_Error( 'forbidden', __( 'You do not have permission to view this job.', 'extrachill-studio' ), array( 'status' => 403 ) );
	}

	$cached_status = (string) ( $job['status'] ?? '' );
	if ( 'completed' === $cached_status || 'failed' === $cached_status ) {
		return $job;
	}

	$client   = new SweatpantsClient();
	$response = $client->get_job( $job_id );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$remote_status = isset( $response['status'] ) ? (string) $response['status'] : $cached_status;
	$job['status'] = $remote_status;

	if ( 'completed' === $remote_status && empty( $job['draft_post_id'] ) ) {
		$results = $client->get_job_results( $job_id );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$rows = isset( $results['results'] ) && is_array( $results['results'] ) ? $results['results'] : array();
		$data = array();
		if ( isset( $rows[0]['data'] ) && is_array( $rows[0]['data'] ) ) {
			$data = $rows[0]['data'];
		}

		$transcript = ec_studio_transcription_pick_transcript_content(
			$data,
			! empty( $job['diarize'] ),
			! empty( $job['remove_fillers'] )
		);

		if ( '' === $transcript ) {
			$job['status'] = 'failed';
			$job['error']  = 'Transcript content missing from sweatpants response';
			ec_studio_transcription_save_job( $job_id, $job );
			return $job;
		}

		$draft_result = ec_studio_transcription_create_draft( $job, $transcript );
		if ( is_wp_error( $draft_result ) ) {
			$job['status'] = 'failed';
			$job['error']  = $draft_result->get_error_message();
			ec_studio_transcription_save_job( $job_id, $job );
			return $job;
		}

		$post_id        = (int) $draft_result;
		$target_blog_id = (int) ( $job['target_blog_id'] ?? 1 );

		switch_to_blog( $target_blog_id );
		$edit_url = (string) get_edit_post_link( $post_id, 'raw' );
		restore_current_blog();

		$job['draft_post_id']  = $post_id;
		$job['draft_post_url'] = $edit_url;
		$job['completed_at']   = current_time( 'c', true );
		$job['error']          = null;
	} elseif ( 'failed' === $remote_status ) {
		$remote_error = '';
		if ( isset( $response['error'] ) && is_string( $response['error'] ) ) {
			$remote_error = $response['error'];
		} elseif ( isset( $response['error']['message'] ) && is_string( $response['error']['message'] ) ) {
			$remote_error = $response['error']['message'];
		}
		$job['error'] = $remote_error !== '' ? $remote_error : 'Sweatpants reported failure with no error detail.';
	}

	ec_studio_transcription_save_job( $job_id, $job );
	return $job;
}
