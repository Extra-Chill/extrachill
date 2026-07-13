<?php
/**
 * Event Submission Abilities
 *
 * Handles event submissions from the public form — validates input,
 * stores flyers, and executes via Data Machine ephemeral workflow.
 *
 * Captcha / human-verification (Cloudflare Turnstile) is enforced at
 * the REST route's permission_callback (see extrachill-api), not here.
 * This keeps the ability callable from non-REST contexts (CLI, admin
 * forms, scheduled re-runs) without fabricating a Turnstile token.
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
					'description'         => __( 'Process an event submission from the public form. Validates input, stores flyers, and executes an ephemeral Data Machine workflow.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'event_title', 'event_date' ),
						'properties' => array(
							'event_title'   => array(
								'type'        => 'string',
								'description' => 'Event title.',
							),
							'event_date'    => array(
								'type'        => 'string',
								'description' => 'Event date (YYYY-MM-DD).',
							),
							'event_time'    => array(
								'type'        => 'string',
								'description' => 'Event start time (HH:MM). Optional.',
							),
							'venue_name'    => array(
								'type'        => 'string',
								'description' => 'Venue name. Optional.',
							),
							'event_city'    => array(
								'type'        => 'string',
								'description' => 'City or region. Optional.',
							),
							'event_lineup'  => array(
								'type'        => 'string',
								'description' => 'Lineup or headliners. Optional.',
							),
							'event_link'    => array(
								'type'        => 'string',
								'description' => 'Ticket or info URL. Optional.',
							),
							'notes'         => array(
								'type'        => 'string',
								'description' => 'Additional details. Optional.',
							),
							'contact_name'  => array(
								'type'        => 'string',
								'description' => 'Submitter name. Required for anonymous submissions.',
							),
							'contact_email' => array(
								'type'        => 'string',
								'description' => 'Submitter email. Required for anonymous submissions.',
							),
							'system_prompt' => array(
								'type'        => 'string',
								'description' => 'Custom system prompt for AI processing step. Optional.',
							),
							'flyer'         => array(
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
							'instructions' => __( 'Public-facing ability. Creates pending events for review. Captcha verification is enforced upstream at the REST route.', 'extrachill-events' ),
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute event submission.
	 *
	 * Captcha verification is the caller's responsibility (the REST route
	 * enforces Turnstile in its permission_callback). This method only
	 * validates event fields and runs the workflow.
	 *
	 * @param array $input Submission data.
	 * @return array Result with message and job_id, or error.
	 */
	public function executeSubmitEvent( array $input ): array|\WP_Error {

		// 1. Resolve contact info (logged-in user or form fields).
		$contact = $this->resolveContact( $input );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		// 2. Validate required event fields.
		$event_title = sanitize_text_field( $input['event_title'] ?? '' );
		$event_date  = sanitize_text_field( $input['event_date'] ?? '' );

		if ( empty( $event_title ) || empty( $event_date ) ) {
			return new \WP_Error( 'missing_fields', __( 'Event title and date are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		// 3. Build sanitized submission. The claim URL is kept OUT of the
		// submission payload (which flows into the workflow engine and may be
		// logged) and threaded to notifySubmitter() separately.
		$account_claim = (string) ( $contact['account_claim'] ?? '' );

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

		// 4. Store flyer if provided.
		$flyer = $input['flyer'] ?? null;

		// 5. Execute ephemeral workflow.
		return $this->executeDirect( $submission, $flyer, sanitize_textarea_field( $input['system_prompt'] ?? '' ), $account_claim );
	}

	/**
	 * Resolve contact information from logged-in user or form input.
	 *
	 * For logged-in users, their account is the author. For anonymous submitters,
	 * this resolves (or creates) a real subscriber account keyed by email so the
	 * event is attributed honestly to the submitter rather than to the bot — see
	 * issue #207 Phase 1. Dedupe is strict on email; a brand-new account is
	 * flagged unclaimed and emailed a claim/set-password link in notifySubmitter().
	 *
	 * @param array $input Raw input.
	 * @return array|\WP_Error Contact data with user_id, contact_name, contact_email — or WP_Error.
	 */
	private function resolveContact( array $input ): array|\WP_Error {
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
			return new \WP_Error( 'missing_contact', __( 'Name and email are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		// Attribute the submission to the submitter's own account. Existing users
		// are reused (dedupe by email); new users get a locked subscriber account
		// flagged unclaimed until they set a password via the claim link. Falls
		// back to user_id=0 (the automation/bot default author) if the
		// account-creation primitive is unavailable, so submission still works.
		$resolved = $this->resolveAnonymousSubmitter( $email );

		return array(
			'user_id'       => $resolved['user_id'],
			'contact_name'  => $name,
			'contact_email' => $email,
			'account_claim' => $resolved['claim_url'],
		);
	}

	/**
	 * Resolve a real user account for an anonymous submitter, keyed by email.
	 *
	 * Dedupe: an existing user with this email is reused (no duplicate). If none
	 * exists, one is created via the `extrachill/create-user` ability — collision-
	 * safe username, random password, subscriber role, flagged ec_unclaimed=1,
	 * with registration_source='event_submission' provenance.
	 *
	 * A one-time claim/set-password URL is generated for BOTH the new- and
	 * existing-account cases so the email wording can be identical (no account-
	 * enumeration leak): a new user sets their password; an existing user can
	 * reset theirs or simply ignore the link.
	 *
	 * @param string $email Verified email address.
	 * @return array{user_id:int, claim_url:string} Resolved user id (0 if the
	 *              primitive is unavailable) and a claim URL (empty if none).
	 */
	private function resolveAnonymousSubmitter( string $email ): array {
		// Dedupe by email: reuse an existing account rather than creating a dup.
		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof \WP_User ) {
			return array(
				'user_id'   => (int) $existing->ID,
				'claim_url' => $this->buildClaimUrl( $existing ),
			);
		}

		// Create a locked subscriber account on the submitter's behalf.
		$create = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/create-user' ) : null;
		if ( ! $create ) {
			return array(
				'user_id'   => 0,
				'claim_url' => '',
			);
		}

		$username = function_exists( 'ec_generate_username_from_email' )
			? ec_generate_username_from_email( $email )
			: sanitize_title( substr( strstr( $email, '@', true ) ? strstr( $email, '@', true ) : 'user', 0, 50 ) );

		$result = $create->execute(
			array(
				'email'               => $email,
				'password'            => wp_generate_password( 24 ),
				'username'            => $username,
				'role'                => 'subscriber',
				'unclaimed'           => true,
				'registration_source' => 'event_submission',
				'registration_method' => 'standard',
			)
		);

		if ( is_wp_error( $result ) || empty( $result ) ) {
			return array(
				'user_id'   => 0,
				'claim_url' => '',
			);
		}

		$user_id = (int) $result;
		$user    = get_userdata( $user_id );

		return array(
			'user_id'   => $user_id,
			'claim_url' => $user ? $this->buildClaimUrl( $user ) : '',
		);
	}

	/**
	 * Build a one-time claim/set-password URL for a user.
	 *
	 * Generates a fresh password-reset key (works for new and existing accounts)
	 * and points it at the community site's login. The link is safe to send to
	 * any account holder regardless of whether the account pre-existed.
	 *
	 * @param \WP_User $user User object.
	 * @return string Claim URL, or empty string on key-generation failure.
	 */
	private function buildClaimUrl( \WP_User $user ): string {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		$base = function_exists( 'ec_get_site_url' )
			? ec_get_site_url( 'community' )
			: network_site_url();

		// Canonical WordPress reset-password endpoint on the community site.
		return trailingslashit( $base ) . 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login );
	}

	/**
	 * Execute submission via an ephemeral Data Machine workflow.
	 *
	 * @param array      $submission    Sanitized submission data.
	 * @param array|null $flyer         File data from $_FILES, or null.
	 * @param string     $system_prompt Custom system prompt. Optional.
	 * @return array Result.
	 */
	private function executeDirect( array $submission, ?array $flyer, string $system_prompt = '', string $account_claim = '' ): array|\WP_Error {
		$execute = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $execute ) {
			return new \WP_Error( 'dm_unavailable', __( 'Data Machine is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$stored_flyer = $this->storeFlyer( $flyer, 'direct', 'direct' );
		if ( is_wp_error( $stored_flyer ) ) {
			return $stored_flyer;
		}

		if ( ! class_exists( '\\DataMachine\\Core\\PluginSettings' ) ) {
			return new \WP_Error( 'dm_settings_unavailable', __( 'Data Machine settings unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$provider = \DataMachine\Core\PluginSettings::get( 'default_provider', 'anthropic' );
		$model    = \DataMachine\Core\PluginSettings::get( 'default_model', 'claude-sonnet-4-20250514' );
		$workflow = $this->buildWorkflow( $submission, $stored_flyer, $provider, $model, $system_prompt );

		$initial_data = array( 'submission' => $submission );
		if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
			$initial_data['image_file_path'] = $stored_flyer['stored_path'];
		}

		$result = $execute->execute(
			array(
				'workflow'     => $workflow,
				'initial_data' => $initial_data,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job_id = $result['job_id'] ?? $result['data']['job_id'] ?? 0;

		$this->notifySubmitter( $submission, $account_claim );
		$this->notifyAdmin( $submission, $job_id );

		do_action(
			'extrachill_event_submission',
			$submission,
			array(
				'flow_id' => 'direct',
				'job_id'  => $job_id,
				'mode'    => 'ephemeral',
			)
		);

		return array(
			'message' => __( 'Thanks! We queued your submission for review.', 'extrachill-events' ),
			'job_id'  => $job_id,
		);
	}

	/**
	 * Store uploaded flyer to Data Machine file storage.
	 *
	 * @param array|null $flyer       File data from $_FILES.
	 * @param int|string $flow_id     Flow ID or 'direct'.
	 * @param int|string $pipeline_id Pipeline ID or 'direct'.
	 * @return array|\WP_Error|null Stored file data, null if no flyer, or WP_Error.
	 */
	private function storeFlyer( ?array $flyer, $flow_id, $pipeline_id ) {
		if ( empty( $flyer ) || empty( $flyer['tmp_name'] ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $flyer, array( 'test_form' => false ) );
		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'upload_failed', $upload['error'], array( 'status' => 400 ) );
		}

		$storage = new \DataMachine\Core\FilesRepository\FileStorage();
		$stored  = $storage->store_file(
			$upload['file'],
			$flyer['name'],
			array(
				'pipeline_id' => $pipeline_id,
				'flow_id'     => $flow_id,
			)
		);

		if ( file_exists( $upload['file'] ) ) {
			wp_delete_file( $upload['file'] );
		}

		if ( ! $stored ) {
			return new \WP_Error( 'storage_failed', __( 'Could not save the flyer.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$file_info = wp_check_filetype( $flyer['name'] );

		return array(
			'filename'    => sanitize_file_name( $flyer['name'] ),
			'stored_path' => $stored,
			'mime_type'   => $file_info['type'] ? $file_info['type'] : 'application/octet-stream',
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
			'system_prompt' => $system_prompt ? $system_prompt : $default_prompt,
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
	 * Routes through `ec_send_email()` (the extrachill-multisite helper that
	 * wraps `datamachine/send-email`) when available, with a graceful
	 * fallback to `wp_get_ability('datamachine/send-email')` for load-order
	 * edge cases. Uses the `extrachill/branded` template — submitters get
	 * the full EC visual identity.
	 *
	 * @param array  $submission Submission data.
	 * @param string $account_claim Optional one-time claim/set-password URL for
	 *              anonymous submitters whose account was resolved or created.
	 *              Empty for logged-in submitters (no claim section rendered).
	 */
	private function notifySubmitter( array $submission, string $account_claim = '' ): void {
		$to = $submission['contact_email'] ?? '';
		if ( empty( $to ) || ! is_email( $to ) ) {
			return;
		}

		$site_name     = get_bloginfo( 'name' );
		$event_title   = (string) $submission['event_title'];
		$event_date    = (string) $submission['event_date'];
		$venue_display = '' !== ( $submission['venue_name'] ?? '' )
			? (string) $submission['venue_name']
			: __( 'Not specified', 'extrachill-events' );

		$subject = sprintf(
			/* translators: 1: site name, 2: event title. */
			__( '[%1$s] Event Submission Received: %2$s', 'extrachill-events' ),
			$site_name,
			$event_title
		);

		$preheader = sprintf(
			/* translators: %s: event title. */
			__( 'We received your submission for %s. It is in the review queue now.', 'extrachill-events' ),
			$event_title
		);

		$body_html  = '<p>' . esc_html__( 'Thanks for submitting your event!', 'extrachill-events' ) . '</p>';
		$body_html .= '<p>' . sprintf(
			/* translators: 1: event title, 2: event date, 3: venue name. */
			esc_html__( 'Event: %1$s', 'extrachill-events' ),
			'<strong>' . esc_html( $event_title ) . '</strong>'
		) . '<br>';
		$body_html .= sprintf(
			/* translators: %s: event date. */
			esc_html__( 'Date: %s', 'extrachill-events' ),
			esc_html( $event_date )
		) . '<br>';
		$body_html .= sprintf(
			/* translators: %s: venue name or "Not specified". */
			esc_html__( 'Venue: %s', 'extrachill-events' ),
			esc_html( $venue_display )
		) . '</p>';
		$body_html .= '<p>' . esc_html__( "We're processing your submission now. You'll receive another email once it's been reviewed.", 'extrachill-events' ) . '</p>';

		// Claim-account section for anonymous submitters. Wording is identical
		// whether the account was just created or already existed — a new user
		// sets their password; an existing user can reset theirs or ignore the
		// link — so there is NO account-enumeration leak (issue #207 Phase 1).
		if ( ! empty( $account_claim ) ) {
			$community_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : '';
			$body_html    .= '<hr>';
			$body_html    .= '<p>' . esc_html__( 'Your submission is credited to your Extra Chill account, so you get the credit when it goes live.', 'extrachill-events' ) . '</p>';
			$body_html    .= '<p>' . sprintf(
				/* translators: %s: community site URL. */
				esc_html__( 'Set or reset your password to log in and manage your events: %s', 'extrachill-events' ),
				'<a href="' . esc_url( $account_claim ) . '">' . esc_html__( 'Set my password', 'extrachill-events' ) . '</a>'
			) . '</p>';
			if ( $community_url ) {
				$body_html .= '<p>' . sprintf(
					/* translators: %s: community site URL. */
					esc_html__( 'Once set up, you can join the community at %s.', 'extrachill-events' ),
					'<a href="' . esc_url( $community_url ) . '">' . esc_html( $community_url ) . '</a>'
				) . '</p>';
			}
		}

		$context = array(
			'subject_html'   => esc_html( $subject ),
			'preheader'      => $preheader,
			'recipient_name' => (string) ( $submission['contact_name'] ?? '' ),
			'body_html'      => $body_html,
		);

		$this->dispatchEmail(
			array(
				'to'       => $to,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => $context,
			),
			'submitter'
		);
	}

	/**
	 * Send notification email to site admin about new event submission.
	 *
	 * Uses the `extrachill/minimal` template — internal ops alert, no
	 * marketing chrome.
	 *
	 * @param array $submission Submission data.
	 * @param int   $job_id     Data Machine job ID.
	 */
	private function notifyAdmin( array $submission, int $job_id ): void {
		$to = get_option( 'admin_email' );
		if ( empty( $to ) || ! is_email( $to ) ) {
			return;
		}

		$site_name     = get_bloginfo( 'name' );
		$event_title   = (string) $submission['event_title'];
		$event_date    = (string) $submission['event_date'];
		$venue_display = '' !== ( $submission['venue_name'] ?? '' )
			? (string) $submission['venue_name']
			: __( 'Not specified', 'extrachill-events' );

		$subject = sprintf(
			/* translators: 1: site name, 2: event title. */
			__( '[%1$s] New Event Submission: %2$s', 'extrachill-events' ),
			$site_name,
			$event_title
		);

		$preheader = sprintf(
			/* translators: 1: event title, 2: submitter name. */
			__( 'New pending submission: %1$s (by %2$s).', 'extrachill-events' ),
			$event_title,
			(string) $submission['contact_name']
		);

		$body_html  = '<p>' . esc_html__( 'A new event submission has been received:', 'extrachill-events' ) . '</p>';
		$body_html .= '<ul>';
		$body_html .= '<li>' . sprintf(
			/* translators: %s: event title. */
			esc_html__( 'Event: %s', 'extrachill-events' ),
			'<strong>' . esc_html( $event_title ) . '</strong>'
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: %s: event date. */
			esc_html__( 'Date: %s', 'extrachill-events' ),
			esc_html( $event_date )
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: %s: venue name or "Not specified". */
			esc_html__( 'Venue: %s', 'extrachill-events' ),
			esc_html( $venue_display )
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: 1: submitter name, 2: submitter email. */
			esc_html__( 'Submitted by: %1$s (%2$s)', 'extrachill-events' ),
			esc_html( (string) $submission['contact_name'] ),
			esc_html( (string) $submission['contact_email'] )
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: %d: Data Machine job ID. */
			esc_html__( 'Data Machine Job ID: %d', 'extrachill-events' ),
			(int) $job_id
		) . '</li>';
		$body_html .= '</ul>';
		$body_html .= '<p>' . esc_html__( 'The submission is being processed now. Check pending posts in a few minutes.', 'extrachill-events' ) . '</p>';

		$context = array(
			'subject_html' => esc_html( $subject ),
			'preheader'    => $preheader,
			'body_html'    => $body_html,
		);

		$pending_url = admin_url( 'edit.php?post_status=pending&post_type=post' );

		$this->dispatchEmail(
			array(
				'to'       => $to,
				'subject'  => $subject,
				'template' => 'extrachill/minimal',
				'context'  => array_merge(
					$context,
					array(
						'cta_url'   => $pending_url,
						'cta_label' => __( 'Review pending submissions', 'extrachill-events' ),
					)
				),
			),
			'admin'
		);
	}

	/**
	 * Dispatch an outgoing notification through the EC mail layer.
	 *
	 * Event submissions run in an unprivileged context (an anonymous visitor),
	 * so a bare `ec_send_email()` call hits the `datamachine/send-email`
	 * ability's capability gate and silently fails with a permissions error.
	 * `extrachill_send_registration_email()` (extrachill-users) wraps the call in
	 * PermissionHelper::run_as_authenticated() — the canonical seam for callers
	 * that have authorized a send at their own layer. We prefer it when present;
	 * otherwise we fall back to `ec_send_email()` / the raw ability. Failures are
	 * logged (never thrown) so a transient send error does not break submission.
	 *
	 * @param array  $args  Arguments forwarded to the ability.
	 * @param string $audience Tag used in log context ("submitter" | "admin").
	 */
	private function dispatchEmail( array $args, string $audience ): void {
		$result = null;

		if ( function_exists( 'extrachill_send_registration_email' ) ) {
			$result = extrachill_send_registration_email( $args );
		} elseif ( function_exists( 'ec_send_email' ) ) {
			$result = ec_send_email( $args );
		} elseif ( function_exists( 'wp_get_ability' ) ) {
			$send_ability = wp_get_ability( 'datamachine/send-email' );
			if ( $send_ability ) {
				$result = $send_ability->execute( $args );
			}
		}

		$sent = is_array( $result ) ? (bool) ( $result['success'] ?? false ) : false;

		if ( ! $sent ) {
			do_action(
				'datamachine_log',
				'warning',
				sprintf( 'EventSubmission: %s notification failed to send', $audience ),
				array(
					'audience' => $audience,
					'to'       => $args['to'] ?? '',
					'subject'  => $args['subject'] ?? '',
					'result'   => is_array( $result ) ? $result : array( 'result' => $result ),
				)
			);
		}
	}
}
