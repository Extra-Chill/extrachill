<?php
/**
 * Event Submission Abilities
 *
 * Handles event submissions from the public form — validates input,
 * verifies Turnstile, stores flyers, and executes via Data Machine
 * (either a pre-configured flow or an ephemeral workflow).
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventSubmissionAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'extrachill/submit-event',
				array(
					'label'               => __( 'Submit Event', 'extrachill-events' ),
					'description'         => __( 'Process an event submission from the public form. Validates input, verifies Turnstile, stores flyers, and queues for processing via Data Machine.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event_title', 'event_date' ),
						'properties' => array(
							'event_title'        => array(
								'type'        => 'string',
								'description' => 'Event title.',
							),
							'event_date'         => array(
								'type'        => 'string',
								'description' => 'Event date (YYYY-MM-DD).',
							),
							'event_time'         => array(
								'type'        => 'string',
								'description' => 'Event start time (HH:MM). Optional.',
							),
							'venue_name'         => array(
								'type'        => 'string',
								'description' => 'Venue name. Optional.',
							),
							'event_city'         => array(
								'type'        => 'string',
								'description' => 'City or region. Optional.',
							),
							'event_lineup'       => array(
								'type'        => 'string',
								'description' => 'Lineup or headliners. Optional.',
							),
							'event_link'         => array(
								'type'        => 'string',
								'description' => 'Ticket or info URL. Optional.',
							),
							'notes'              => array(
								'type'        => 'string',
								'description' => 'Additional details. Optional.',
							),
							'contact_name'       => array(
								'type'        => 'string',
								'description' => 'Submitter name. Required for anonymous submissions.',
							),
							'contact_email'      => array(
								'type'        => 'string',
								'description' => 'Submitter email. Required for anonymous submissions.',
							),
							'turnstile_response' => array(
								'type'        => 'string',
								'description' => 'Cloudflare Turnstile verification token.',
							),
							'flow_id'            => array(
								'type'        => 'integer',
								'description' => 'Data Machine flow ID. If omitted, uses ephemeral workflow.',
							),
							'system_prompt'      => array(
								'type'        => 'string',
								'description' => 'Custom system prompt for AI processing step. Optional.',
							),
							'flyer'              => array(
								'type'        => 'object',
								'description' => 'Uploaded flyer file data from $_FILES. Optional.',
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'message' => array( 'type' => 'string' ),
							'job_id'  => array( 'type' => 'integer' ),
						),
					),
					'execute_callback'    => array( $this, 'executeSubmitEvent' ),
					'permission_callback' => '__return_true',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'     => false,
							'idempotent'   => false,
							'destructive'  => false,
							'instructions' => __( 'Public-facing ability. Turnstile verification is enforced unless bypassed. Creates pending events for review.', 'extrachill-events' ),
						),
					),
				)
			);
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	/**
	 * Execute event submission.
	 *
	 * @param array $input Submission data.
	 * @return array Result with message and job_id, or error.
	 */
	public function executeSubmitEvent( array $input ): array {

		// 1. Verify Turnstile.
		$turnstile_result = $this->verifyTurnstile( $input['turnstile_response'] ?? '' );
		if ( is_array( $turnstile_result ) && isset( $turnstile_result['error'] ) ) {
			return $turnstile_result;
		}

		// 2. Resolve contact info (logged-in user or form fields).
		$contact = $this->resolveContact( $input );
		if ( isset( $contact['error'] ) ) {
			return $contact;
		}

		// 3. Validate required event fields.
		$event_title = sanitize_text_field( $input['event_title'] ?? '' );
		$event_date  = sanitize_text_field( $input['event_date'] ?? '' );

		if ( empty( $event_title ) || empty( $event_date ) ) {
			return array( 'error' => __( 'Event title and date are required.', 'extrachill-events' ) );
		}

		// 4. Build sanitized submission.
		$submission = array(
			'user_id'       => $contact['user_id'],
			'contact_name'  => $contact['contact_name'],
			'contact_email' => $contact['contact_email'],
			'event_title'   => $event_title,
			'event_date'    => $event_date,
			'event_time'    => sanitize_text_field( $input['event_time'] ?? '' ),
			'venue_name'    => sanitize_text_field( $input['venue_name'] ?? '' ),
			'event_city'    => sanitize_text_field( $input['event_city'] ?? '' ),
			'event_lineup'  => sanitize_text_field( $input['event_lineup'] ?? '' ),
			'event_link'    => esc_url_raw( $input['event_link'] ?? '' ),
			'notes'         => sanitize_textarea_field( $input['notes'] ?? '' ),
		);

		// 5. Store flyer if provided.
		$flow_id = absint( $input['flow_id'] ?? 0 );
		$flyer   = $input['flyer'] ?? null;

		// 6. Route to flow-based or direct execution.
		if ( $flow_id ) {
			return $this->executeWithFlow( $submission, $flow_id, $flyer );
		}

		return $this->executeDirect( $submission, $flyer, sanitize_textarea_field( $input['system_prompt'] ?? '' ) );
	}

	/**
	 * Verify Cloudflare Turnstile response.
	 *
	 * @param string $token Turnstile token from frontend.
	 * @return true|array True on success, error array on failure.
	 */
	private function verifyTurnstile( string $token ) {
		$is_local   = defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE;
		$is_bypass  = $is_local || (bool) apply_filters( 'extrachill_bypass_turnstile_verification', false );

		if ( $is_bypass ) {
			return true;
		}

		if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
			return array( 'error' => __( 'Security verification unavailable.', 'extrachill-events' ) );
		}

		if ( empty( $token ) || ! ec_verify_turnstile_response( $token ) ) {
			return array( 'error' => __( 'Security verification failed. Please try again.', 'extrachill-events' ) );
		}

		return true;
	}

	/**
	 * Resolve contact information from logged-in user or form input.
	 *
	 * @param array $input Raw input.
	 * @return array Contact data with user_id, contact_name, contact_email — or error.
	 */
	private function resolveContact( array $input ): array {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			$user = wp_get_current_user();
			return array(
				'user_id'       => $user_id,
				'contact_name'  => $user->display_name,
				'contact_email' => $user->user_email,
			);
		}

		$name  = sanitize_text_field( $input['contact_name'] ?? '' );
		$email = sanitize_email( $input['contact_email'] ?? '' );

		if ( empty( $name ) || empty( $email ) ) {
			return array( 'error' => __( 'Name and email are required.', 'extrachill-events' ) );
		}

		if ( ! is_email( $email ) ) {
			return array( 'error' => __( 'Enter a valid email address.', 'extrachill-events' ) );
		}

		return array(
			'user_id'       => 0,
			'contact_name'  => $name,
			'contact_email' => $email,
		);
	}

	/**
	 * Execute submission via a pre-configured Data Machine flow.
	 *
	 * @param array      $submission Sanitized submission data.
	 * @param int        $flow_id    Data Machine flow ID.
	 * @param array|null $flyer      File data from $_FILES, or null.
	 * @return array Result.
	 */
	private function executeWithFlow( array $submission, int $flow_id, ?array $flyer ): array {
		$execute = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/execute-workflow' ) : null;
		if ( ! $execute ) {
			return array( 'error' => __( 'Data Machine is unavailable.', 'extrachill-events' ) );
		}

		$stored_flyer = $this->storeFlyer( $flyer, $flow_id, 0 );
		if ( is_array( $stored_flyer ) && isset( $stored_flyer['error'] ) ) {
			return $stored_flyer;
		}

		if ( $stored_flyer ) {
			$submission['flyer'] = $stored_flyer;
		}

		$initial_data = array( 'submission' => $submission );
		if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
			$initial_data['image_file_path'] = $stored_flyer['stored_path'];
		}

		$result = $execute->execute( array(
			'flow_id'      => $flow_id,
			'initial_data' => $initial_data,
		) );

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$job_id = $result['job_id'] ?? $result['data']['job_id'] ?? 0;

		do_action( 'extrachill_event_submission', $submission, array(
			'flow_id' => $flow_id,
			'job_id'  => $job_id,
			'mode'    => 'flow',
		) );

		return array(
			'message' => __( 'Thanks! We queued your submission for review.', 'extrachill-events' ),
			'job_id'  => $job_id,
		);
	}

	/**
	 * Execute submission via an ephemeral Data Machine workflow.
	 *
	 * @param array      $submission    Sanitized submission data.
	 * @param array|null $flyer         File data from $_FILES, or null.
	 * @param string     $system_prompt Custom system prompt. Optional.
	 * @return array Result.
	 */
	private function executeDirect( array $submission, ?array $flyer, string $system_prompt = '' ): array {
		$execute = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/execute-workflow' ) : null;
		if ( ! $execute ) {
			return array( 'error' => __( 'Data Machine is unavailable.', 'extrachill-events' ) );
		}

		$stored_flyer = $this->storeFlyer( $flyer, 'direct', 'direct' );
		if ( is_array( $stored_flyer ) && isset( $stored_flyer['error'] ) ) {
			return $stored_flyer;
		}

		if ( ! class_exists( '\\DataMachine\\Core\\PluginSettings' ) ) {
			return array( 'error' => __( 'Data Machine settings unavailable.', 'extrachill-events' ) );
		}

		$provider = \DataMachine\Core\PluginSettings::get( 'default_provider', 'anthropic' );
		$model    = \DataMachine\Core\PluginSettings::get( 'default_model', 'claude-sonnet-4-20250514' );
		$workflow = $this->buildWorkflow( $submission, $stored_flyer, $provider, $model, $system_prompt );

		$initial_data = array( 'submission' => $submission );
		if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
			$initial_data['image_file_path'] = $stored_flyer['stored_path'];
		}

		$result = $execute->execute( array(
			'workflow'     => $workflow,
			'initial_data' => $initial_data,
		) );

		if ( isset( $result['error'] ) ) {
			return $result;
		}

		$job_id = $result['job_id'] ?? $result['data']['job_id'] ?? 0;

		$this->notifySubmitter( $submission );
		$this->notifyAdmin( $submission, $job_id );

		do_action( 'extrachill_event_submission', $submission, array(
			'flow_id' => 'direct',
			'job_id'  => $job_id,
			'mode'    => 'ephemeral',
		) );

		return array(
			'message' => __( 'Thanks! We queued your submission for review.', 'extrachill-events' ),
			'job_id'  => $job_id,
		);
	}

	/**
	 * Store uploaded flyer to Data Machine file storage.
	 *
	 * @param array|null     $flyer       File data from $_FILES.
	 * @param int|string     $flow_id     Flow ID or 'direct'.
	 * @param int|string     $pipeline_id Pipeline ID or 'direct'.
	 * @return array|null Stored file data, null if no flyer, or error array.
	 */
	private function storeFlyer( ?array $flyer, $flow_id, $pipeline_id ) {
		if ( empty( $flyer ) || empty( $flyer['tmp_name'] ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $flyer, array( 'test_form' => false ) );
		if ( isset( $upload['error'] ) ) {
			return array( 'error' => $upload['error'] );
		}

		$storage = new \DataMachine\Core\FilesRepository\FileStorage();
		$stored  = $storage->store_file( $upload['file'], $flyer['name'], array(
			'pipeline_id' => $pipeline_id,
			'flow_id'     => $flow_id,
		) );

		if ( file_exists( $upload['file'] ) ) {
			wp_delete_file( $upload['file'] );
		}

		if ( ! $stored ) {
			return array( 'error' => __( 'Could not save the flyer.', 'extrachill-events' ) );
		}

		$file_info = wp_check_filetype( $flyer['name'] );

		return array(
			'filename'    => sanitize_file_name( $flyer['name'] ),
			'stored_path' => $stored,
			'mime_type'   => $file_info['type'] ?: 'application/octet-stream',
		);
	}

	/**
	 * Build an ephemeral workflow for event submission.
	 *
	 * @param array      $submission    Submission data.
	 * @param array|null $stored_flyer  Stored flyer data.
	 * @param string     $provider      AI provider slug.
	 * @param string     $model         AI model identifier.
	 * @param string     $system_prompt Custom system prompt.
	 * @return array Workflow config for DM execute endpoint.
	 */
	private function buildWorkflow( array $submission, ?array $stored_flyer, string $provider, string $model, string $system_prompt = '' ): array {
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

		if ( $stored_flyer ) {
			$steps[] = array(
				'type'           => 'event_import',
				'handler_slug'   => 'event_flyer',
				'handler_config' => $handler_config,
			);
		}

		$default_prompt = 'You are processing an event submission. '
			. 'Use the upsert_event tool to create the event with the details provided. '
			. 'Do NOT ask for more information — use exactly what is given.';

		$user_message = "Create this event using the upsert_event tool:\n\n"
			. "Title: {$submission['event_title']}\n"
			. "Date: {$submission['event_date']}\n";

		if ( ! empty( $submission['event_time'] ) ) {
			$user_message .= "Time: {$submission['event_time']}\n";
		}
		if ( ! empty( $submission['venue_name'] ) ) {
			$user_message .= "Venue: {$submission['venue_name']}\n";
		}
		if ( ! empty( $submission['event_city'] ) ) {
			$user_message .= "City: {$submission['event_city']}\n";
		}
		if ( ! empty( $submission['event_lineup'] ) ) {
			$user_message .= "Lineup: {$submission['event_lineup']}\n";
		}
		if ( ! empty( $submission['event_link'] ) ) {
			$user_message .= "Ticket/Info URL: {$submission['event_link']}\n";
		}
		if ( ! empty( $submission['notes'] ) ) {
			$user_message .= "Notes: {$submission['notes']}\n";
		}

		$steps[] = array(
			'type'          => 'ai',
			'provider'      => $provider,
			'model'         => $model,
			'system_prompt' => $system_prompt ?: $default_prompt,
			'user_message'  => $user_message,
			'enabled_tools' => array( 'upsert_event' ),
		);

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
	 * Send confirmation email to the person who submitted the event.
	 *
	 * @param array $submission Submission data.
	 */
	private function notifySubmitter( array $submission ): void {
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
			"Thanks for submitting your event!\n\n"
			. "Event: %s\n"
			. "Date: %s\n"
			. "Venue: %s\n\n"
			. "We're processing your submission now. You'll receive another email once it's been reviewed.",
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
	private function notifyAdmin( array $submission, int $job_id ): void {
		$to = get_option( 'admin_email' );

		$subject = sprintf(
			'[%s] New Event Submission: %s',
			get_bloginfo( 'name' ),
			$submission['event_title']
		);

		$message = sprintf(
			"A new event submission has been received:\n\n"
			. "Event: %s\n"
			. "Date: %s\n"
			. "Venue: %s\n"
			. "Submitted by: %s (%s)\n\n"
			. "Data Machine Job ID: %d\n\n"
			. "The submission is being processed now. Check pending posts in a few minutes.",
			$submission['event_title'],
			$submission['event_date'],
			$submission['venue_name'] ?: 'Not specified',
			$submission['contact_name'],
			$submission['contact_email'],
			$job_id
		);

		wp_mail( $to, $subject, $message );
	}
}
