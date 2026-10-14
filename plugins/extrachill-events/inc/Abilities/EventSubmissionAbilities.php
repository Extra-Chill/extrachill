<?php
/**
 * Event Submission Abilities
 *
 * Handles event submissions from the public form — validates input,
 * stores flyers, and executes via Data Machine ephemeral workflow.
 *
 * Captcha / human-verification (Cloudflare Turnstile) is enforced by the
 * dedicated public REST route in extrachill-api. This ability is deliberately
 * not exposed by the generic Abilities REST endpoint so that route remains
 * the canonical public write boundary.
 *
 * Because authorization for a submission happens upstream (Turnstile at the
 * REST boundary, `show_in_rest => false` on this ability), executeDirect()
 * runs the `datamachine/execute-workflow` call through
 * `\DataMachine\Abilities\PermissionHelper::run_as_authenticated()` so
 * anonymous and non-admin submitters aren't blocked by that ability's
 * `manage_options`-class permission_callback. See executeDirect() for detail.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/SubmissionTrustPolicy.php';

class EventSubmissionAbilities {

	private static bool $registered = false;

	/**
	 * Source type stamped on every public-form submission (#964).
	 *
	 * Data Machine Events' upsert hashes `source_type` + `item_identifier`
	 * from engine data into a stable source identity and resolves an
	 * existing event that claims it before falling back to fuzzy
	 * title/venue/date duplicate detection. Giving submissions a source
	 * identity lets a resubmission update the submitter's own pending event
	 * even when the AI step rewrote the title or the venue string changed.
	 */
	public const SUBMISSION_SOURCE_TYPE = 'event_submission';

	/** Upsert's source-identity meta key (DataMachineEvents EventUpsert). */
	private const SOURCE_IDENTITY_META_KEY = '_datamachine_event_source_identity';

	/**
	 * Statuses of events that have left the review queue. A resubmission
	 * never updates these; pending and draft events are updated in place.
	 */
	private const REVIEWED_STATUSES = array( 'publish', 'future', 'private', 'trash' );

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
								'description' => 'Show start time (HH:MM), when the music starts, not doors. Optional.',
							),
							'venue_name'    => array(
								'type'        => 'string',
								'description' => 'Venue name. Optional.',
							),
							'venue_term_id' => array(
								'type'        => 'integer',
								'description' => 'ID of an existing venue term the submitter picked. Optional; when valid it wins over venue_name.',
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
						'show_in_rest' => false,
						'annotations'  => array(
							'readonly'     => false,
							'idempotent'   => false,
							'destructive'  => false,
							'instructions' => __( 'Creates pending events for review. The dedicated event-submissions REST route is the public, Turnstile-protected entry point.', 'extrachill-events' ),
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
	 * The public REST route verifies Turnstile before calling this method. The
	 * generic Abilities REST endpoint cannot invoke this ability.
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
			'authenticated' => get_current_user_id() > 0,
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

		// 3b. A venue picked from suggestions is authoritative (#965). Its
		// canonical name replaces whatever text came along with it, so the
		// AI step and the flyer handler see the real venue, and its ID is
		// handed to the upsert, which assigns that term directly.
		$submission = $this->applyChosenVenue( $submission, absint( $input['venue_term_id'] ?? 0 ) );

		// 4. Store flyer if provided.
		$flyer = $input['flyer'] ?? null;

		// 5. Execute ephemeral workflow.
		return $this->executeDirect( $submission, $flyer, $account_claim );
	}

	/**
	 * Apply the venue the submitter picked from suggestions (#965).
	 *
	 * The form's venue typeahead submits the chosen venue's term ID. A valid
	 * ID replaces the typed venue name with the term's canonical name, fills
	 * an empty city from the venue, and is recorded on the submission so
	 * executeDirect() can hand it to the upsert. An ID that is not an
	 * existing venue term is dropped and the typed name is used as before,
	 * so free text for a venue we don't know yet still works.
	 *
	 * @param array $submission    Sanitized submission.
	 * @param int   $venue_term_id Submitted venue term ID (0 when none).
	 * @return array Submission, with venue fields resolved.
	 */
	private function applyChosenVenue( array $submission, int $venue_term_id ): array {
		$submission['venue_term_id'] = 0;
		if ( $venue_term_id <= 0 ) {
			return $submission;
		}

		$term = get_term( $venue_term_id, 'venue' );
		if ( ! $term instanceof \WP_Term || 'venue' !== $term->taxonomy ) {
			return $submission;
		}

		$submission['venue_term_id'] = (int) $term->term_id;
		$submission['venue_name']    = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( '' === $submission['event_city'] ) {
			$submission['event_city'] = sanitize_text_field( (string) get_term_meta( $term->term_id, '_venue_city', true ) );
		}

		return $submission;
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

		$local_part = strstr( $email, '@', true );
		$username   = function_exists( 'ec_generate_username_from_email' )
			? ec_generate_username_from_email( $email )
			: sanitize_title( substr( false !== $local_part && '' !== $local_part ? $local_part : 'user', 0, 50 ) );

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
		// Claim-link format and key minting are owned by extrachill-users
		// (extrachill-users#451), shared with every guest/unclaimed flow.
		return function_exists( 'ec_users_build_account_claim_url' ) ? (string) ec_users_build_account_claim_url( $user ) : '';
	}

	/**
	 * Execute submission via an ephemeral Data Machine workflow.
	 *
	 * `datamachine/execute-workflow`'s permission_callback is
	 * `PermissionHelper::can_manage()` (requires a `datamachine_manage_*`
	 * capability or `manage_options`), because that ability is normally
	 * invoked by logged-in operators through the generic Abilities REST
	 * endpoint. The public event-submission form is deliberately reachable
	 * by anonymous visitors, so calling `$execute->execute()` directly here
	 * always failed permission checks for anyone but an admin (issue #910).
	 *
	 * Authorization for this specific call has already happened one layer
	 * up: extrachill-api's `/extrachill/v1/event-submissions` REST route
	 * verifies Cloudflare Turnstile before it ever reaches this method, this
	 * ability (`extrachill/submit-event`) is registered with
	 * `show_in_rest => false` so it is not independently reachable, and the
	 * workflow executed below is fully server-built (fixed steps; post_status
	 * chosen server-side by SubmissionTrustPolicy) — none of it is
	 * attacker-controlled. Given
	 * that, we run the workflow execution inside
	 * `PermissionHelper::run_as_authenticated()`, Data Machine's canonical
	 * seam for callers that authorized the action at their own layer.
	 *
	 * Data Machine also requires every direct job to have an owner (an
	 * acting user or an agent; issue #914). The job is owned by the
	 * install's system agent, resolved through the same substrate helper
	 * ArtistUrlImportAbilities uses, and runs as that agent's owner. The
	 * submitter's own account is a bare subscriber and can own neither the
	 * permission nor the job; the event itself is still attributed to the
	 * submitter through the submission payload.
	 *
	 * @param array      $submission    Sanitized submission data.
	 * @param array|null $flyer         File data from $_FILES, or null.
	 * @param string     $account_claim One-time account claim URL, if applicable.
	 * @return array Result.
	 */
	private function executeDirect( array $submission, ?array $flyer, string $account_claim = '' ): array|\WP_Error {
		$execute = wp_get_ability( 'datamachine/execute-workflow' );
		if ( ! $execute ) {
			return new \WP_Error( 'dm_unavailable', __( 'Data Machine is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		if ( ! class_exists( '\\DataMachine\\Abilities\\PermissionHelper' ) ) {
			return new \WP_Error( 'dm_unavailable', __( 'Data Machine is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$stored_flyer = $this->storeFlyer( $flyer, 'direct', 'direct' );
		if ( is_wp_error( $stored_flyer ) ) {
			return $stored_flyer;
		}

		$workflow = $this->buildWorkflow( $submission, $stored_flyer );

		$owner = $this->resolveWorkflowOwner();
		if ( $owner['agent_id'] <= 0 || $owner['user_id'] <= 0 ) {
			do_action( 'datamachine_log', 'error', 'EventSubmission: no system agent available to own the submission workflow', array( 'owner' => $owner ) );
			return new \WP_Error( 'workflow_owner_unavailable', __( 'Event submissions are temporarily unavailable. Please try again later.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$initial_data = array(
			'submission' => $submission,
			'agent_id'   => $owner['agent_id'],
			'job_source' => 'event_submission',
			'job_label'  => 'Event Submission',
		);
		if ( ! empty( $submission['venue_term_id'] ) ) {
			// Data Machine Events' upsert assigns this term as-is (#965).
			$initial_data['venue_term_id'] = (int) $submission['venue_term_id'];
		}

		$item_identifier = $this->resolveSubmissionItemIdentifier( $submission );
		if ( '' !== $item_identifier ) {
			$initial_data['source_type']     = self::SUBMISSION_SOURCE_TYPE;
			$initial_data['item_identifier'] = $item_identifier;
		}
		if ( $stored_flyer && ! empty( $stored_flyer['stored_path'] ) ) {
			$initial_data['image_file_path'] = $stored_flyer['stored_path'];
		}

		// Elevate for this single call only — see method docblock for why
		// this is safe. run_as_authenticated() resets context in a finally
		// block, so the elevation never leaks past this closure.
		$result = \DataMachine\Abilities\PermissionHelper::run_as_authenticated(
			function () use ( $execute, $workflow, $initial_data ) {
				return $execute->execute(
					array(
						'workflow'     => $workflow,
						'initial_data' => $initial_data,
					)
				);
			},
			$owner['user_id']
		);

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'EventSubmission: workflow execution failed',
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'title'   => $submission['event_title'] ?? '',
				)
			);
		}

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
	 * Build the stable item identifier for a submission (#964).
	 *
	 * The identifier is keyed on what the submitter controls and repeats when
	 * they resubmit the same show: who they are (account, or email for a
	 * contact with no account), the event date, and the title they typed into
	 * the form (normalized, before any AI rewrite). Upsert turns it into a
	 * source identity, so a resubmission resolves to the event the first
	 * submission created and updates it instead of creating a second pending
	 * post. Venue and lineup are deliberately excluded: a corrected venue or
	 * a completed lineup is exactly what a resubmission changes.
	 *
	 * Only a pending or draft claimant is updated in place. When the identity
	 * is already held by an event that has left the review queue (published,
	 * scheduled, private, or trashed), no identity is returned and the
	 * submission takes the ordinary new-submission path, so a resubmission
	 * can never claim a reviewed event through its source identity. A
	 * submission with no submitter identity also gets none, so one anonymous
	 * submitter can never update another's pending event.
	 *
	 * @param array $submission Sanitized submission.
	 * @return string Item identifier, or '' when the submission gets no source identity.
	 */
	private function resolveSubmissionItemIdentifier( array $submission ): string {
		$user_id = (int) ( $submission['user_id'] ?? 0 );
		$email   = strtolower( trim( (string) ( $submission['contact_email'] ?? '' ) ) );

		if ( $user_id > 0 ) {
			$submitter = 'user:' . $user_id;
		} elseif ( '' !== $email ) {
			$submitter = 'email:' . substr( hash( 'sha256', $email ), 0, 16 );
		} else {
			return '';
		}

		$identifier = sprintf(
			'submission:%s:%s:%s',
			$submitter,
			(string) ( $submission['event_date'] ?? '' ),
			substr( hash( 'sha256', self::normalizeSubmissionTitle( (string) ( $submission['event_title'] ?? '' ) ) ), 0, 16 )
		);

		return $this->isSubmissionIdentityReviewed( $identifier ) ? '' : $identifier;
	}

	/**
	 * Normalize a submitted title for identity matching.
	 *
	 * Case, punctuation, and whitespace differences ("Nightmare On King St"
	 * vs "nightmare on king st.") are the same submission.
	 *
	 * @param string $title Raw sanitized title.
	 * @return string Normalized key.
	 */
	public static function normalizeSubmissionTitle( string $title ): string {
		$title = strtolower( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$title = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );

		return trim( (string) preg_replace( '/\s+/', ' ', $title ) );
	}

	/**
	 * Whether an event that has left the review queue claims this identifier.
	 *
	 * Mirrors the source identity Data Machine Events' upsert derives from
	 * `source_type` + `item_identifier`. Pending and draft claimants are not
	 * counted: those are the events a resubmission should update.
	 *
	 * @param string $item_identifier Submission item identifier.
	 * @return bool True when a reviewed (non-pending, non-draft) event holds it.
	 */
	private function isSubmissionIdentityReviewed( string $item_identifier ): bool {
		$query = new \WP_Query(
			array(
				'post_type'      => defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events',
				'post_status'    => self::REVIEWED_STATUSES,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Same source-identity lookup EventUpsert performs.
					array(
						'key'   => self::SOURCE_IDENTITY_META_KEY,
						'value' => hash( 'sha256', self::SUBMISSION_SOURCE_TYPE . "\0" . $item_identifier ),
					),
				),
			)
		);

		return ! empty( $query->posts );
	}

	/**
	 * Resolve the system agent that owns submission workflows.
	 *
	 * Uses Data Machine's `datamachine_resolve_system_agent_context()`, falling
	 * back to the default agent user, the same resolution
	 * ArtistUrlImportAbilities::resolveSystemAgentContext() performs.
	 *
	 * @return array{agent_id:int,user_id:int}
	 */
	private function resolveWorkflowOwner(): array {
		$owner = array(
			'agent_id' => 0,
			'user_id'  => 0,
		);

		if ( function_exists( 'datamachine_resolve_system_agent_context' ) ) {
			$resolved          = datamachine_resolve_system_agent_context();
			$owner['agent_id'] = (int) $resolved['agent_id'];
			$owner['user_id']  = (int) $resolved['user_id'];
		}

		if ( ( $owner['agent_id'] <= 0 || $owner['user_id'] <= 0 ) && class_exists( '\\DataMachine\\Core\\FilesRepository\\DirectoryManager' ) ) {
			$default_user_id = (int) \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
			if ( $default_user_id > 0 && function_exists( 'datamachine_resolve_or_create_agent_id' ) ) {
				$owner['user_id']  = $default_user_id;
				$owner['agent_id'] = (int) datamachine_resolve_or_create_agent_id( $default_user_id );
			}
		}

		return $owner;
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

		$flyer_name = (string) ( $flyer['name'] ?? '' );
		if ( '' === $flyer_name ) {
			return new \WP_Error( 'upload_failed', __( 'The flyer upload is missing a file name.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload( $flyer, array( 'test_form' => false ) );
		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'upload_failed', $upload['error'], array( 'status' => 400 ) );
		}

		$storage = new \DataMachine\Core\FilesRepository\FileStorage();
		$stored  = $storage->store_file(
			$upload['file'],
			$flyer_name,
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

		$file_info = wp_check_filetype( $flyer_name );

		return array(
			'filename'    => sanitize_file_name( $flyer_name ),
			'stored_path' => $stored,
			'mime_type'   => $file_info['type'] ? $file_info['type'] : 'application/octet-stream',
		);
	}

	/**
	 * Build an ephemeral workflow for event submission.
	 *
	 * Step shape follows Data Machine's `WorkflowSpecValidator` contract:
	 * `step_type` (not `type`), plural `handler_slugs`/`handler_configs`
	 * keyed by handler slug (not the singular `handler_slug`/`handler_config`
	 * legacy fields, which the validator rejects), and the `upsert` step
	 * type (renamed from `update` — see issue #211, which made the same fix
	 * for tour imports). See issue #912.
	 *
	 * @param array      $submission    Submission data.
	 * @param array|null $stored_flyer  Stored flyer data.
	 * @return array Workflow config for DM execute endpoint.
	 */
	private function buildWorkflow( array $submission, ?array $stored_flyer ): array {
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
				'step_type'       => 'event_import',
				'handler_slugs'   => array( 'event_flyer' ),
				'handler_configs' => array( 'event_flyer' => $handler_config ),
			);
		}

		$default_prompt = 'You are processing an event submission. '
			. 'Use the upsert_event tool to create the event with the details provided. '
			. 'Do NOT ask for more information — use exactly what is given.';

		$user_message = "Create this event using the upsert_event tool:\n\n"
			. "Title: {$submission['event_title']}\n"
			. "Date: {$submission['event_date']}\n";

		if ( ! empty( $submission['event_time'] ) ) {
			$user_message .= "Show time (start): {$submission['event_time']}\n";
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
			'step_type'     => 'ai',
			'system_prompt' => $default_prompt,
			'user_message'  => $user_message,
			'enabled_tools' => array( 'upsert_event' ),
		);

		$steps[] = array(
			'step_type'       => 'upsert',
			'handler_slugs'   => array( 'upsert_event' ),
			'handler_configs' => array(
				'upsert_event' => array(
					'post_status'    => $this->submissionPostStatus( (int) $submission['user_id'], ! empty( $submission['authenticated'] ) ),
					'include_images' => ! empty( $stored_flyer ),
				),
			),
		);

		return array( 'steps' => $steps );
	}

	/** Choose publication status using authenticated account trust signals. */
	private function submissionPostStatus( int $user_id, bool $authenticated ): string {
		return SubmissionTrustPolicy::trusts( $user_id, $authenticated ) ? 'publish' : 'pending';
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
	 * Uses `ec_send_email()`, which sends as the system (extrachill-network#318),
	 * so the anonymous submitter's request context does not matter. Falls back
	 * to the raw ability when the network mail layer is absent. Failures are
	 * logged (never thrown) so a transient send error does not break submission.
	 *
	 * @param array  $args  Arguments forwarded to the ability.
	 * @param string $audience Tag used in log context ("submitter" | "admin").
	 */
	public static function dispatchEmail( array $args, string $audience ): void {
		$result = null;

		// The workflow job already exists when this runs. A notification
		// failure in any lower layer must never turn an accepted submission
		// into an error for the visitor (extrachill-network#316).
		try {
			if ( function_exists( 'ec_send_email' ) ) {
				$result = ec_send_email( $args );
			} elseif ( function_exists( 'wp_get_ability' ) ) {
				$send_ability = wp_get_ability( 'datamachine/send-email' );
				if ( $send_ability ) {
					$result = $send_ability->execute( $args );
				}
			}
		} catch ( \Throwable $e ) {
			$result = array(
				'success' => false,
				'error'   => get_class( $e ) . ': ' . $e->getMessage(),
			);
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
