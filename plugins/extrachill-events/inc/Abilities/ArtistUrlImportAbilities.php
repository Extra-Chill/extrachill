<?php
/**
 * Artist URL Import Abilities
 *
 * Four abilities backing the URL-based artist tour import flow added in
 * extrachill-events#320 and migrated out of the generic data-machine-events
 * substrate in extrachill-events#200 (layer purity — the substrate must not
 * carry "artist" domain knowledge):
 *
 *   1. extrachill-events/preview-artist-url
 *      Non-destructive probe: scrapes the URL via the registered
 *      `universal_web_scraper` event-import handler and returns the
 *      detected format, the event count, a preview of the first few
 *      events, and a suggested artist (term ID if a fuzzy match exists,
 *      name otherwise).
 *
 *   2. extrachill-events/submit-artist-url
 *      Inserts a row into `artist_url_submissions` in
 *      `pending_review` status (or `scraping_failed` if the re-probe
 *      yields no events). Re-runs the preview server-side; never trusts
 *      client-provided detection.
 *
 *   3. extrachill-events/approve-artist-url-submission
 *      Admin-only. Resolves the artist taxonomy term (existing term, or
 *      a new term created via wp_insert_term), creates a flow on the
 *      single shared `Artist Tour Import` pipeline (find-or-create once,
 *      reused across every artist — architecture model B1), binds the
 *      artist to the flow's upsert step with `PRE_SELECTED` and leaves
 *      venue/location/festival on `AI_DECIDES`, then triggers a first
 *      run via `datamachine/run-flow`.
 *
 *   4. extrachill-events/reject-artist-url-submission
 *      Admin-only. Marks the submission row rejected with an optional
 *      reason. No side effects.
 *
 * Substrate consumption (layer purity): the preview probe consumes the
 * generic scraping primitive by its *registered handler slug*
 * (`universal_web_scraper`) through Data Machine core's public
 * `HandlerAbilities` registry — never by referencing the internal
 * `\DataMachineEvents\…\UniversalWebScraper` class. The slug is a public
 * contract; the class is data-machine-events-internal. The approve path
 * already uses only public DM abilities (`datamachine/create-pipeline`,
 * `datamachine/create-flow`, `datamachine/run-flow`).
 *
 * All four abilities use `SelectionMode` constants from Data Machine
 * core (issue #320 hard requirement — no bare strings).
 *
 * @package ExtraChillEvents\Abilities
 * @since   0.35.0
 */

namespace ExtraChillEvents\Abilities;

use DataMachine\Core\Selection\SelectionMode;
use DataMachine\Abilities\HandlerAbilities;
use ExtraChillEvents\Core\ArtistUrlSubmissionsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlImportAbilities {

	/**
	 * Registered event-import handler slug consumed for the preview probe.
	 * This is data-machine-events' public handler contract, not an internal
	 * class reference.
	 */
	private const SCRAPER_HANDLER_SLUG = 'universal_web_scraper';

	/**
	 * Stable name of the single shared Artist Tour Import pipeline (B1).
	 * One pipeline is reused across every approved artist URL; each
	 * approval creates a NEW FLOW on it.
	 */
	private const SHARED_PIPELINE_NAME = 'Artist Tour Import';

	/**
	 * Site option key caching the shared pipeline_id for the fast path.
	 * A name-based lookup is the fallback if this option is missing/stale.
	 */
	private const SHARED_PIPELINE_OPTION = 'extrachill_events_artist_import_pipeline_id';

	/**
	 * Default schedule interval for newly approved artist flows.
	 */
	private const DEFAULT_INTERVAL = 'weekly';

	/**
	 * Allowed scheduling intervals admins can pick during approval.
	 */
	private const ALLOWED_INTERVALS = array( 'hourly', 'every_6_hours', 'twicedaily', 'daily', 'weekly', 'monthly' );

	/**
	 * Fuzzy-match threshold for suggesting an existing artist term from
	 * the auto-detected name. similar_text() percentage.
	 */
	private const ARTIST_FUZZY_MATCH_THRESHOLD = 85;

	/**
	 * Default author for events published by an approved pipeline.
	 */
	private const DEFAULT_POST_AUTHOR = 32;

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			self::$registered = true;
		}
	}

	/**
	 * Register all four abilities. Each registration is gated on
	 * `wp_abilities_api_init` so registration is idempotent regardless
	 * of when this class is instantiated.
	 */
	private function registerAbilities(): void {
		$register_callback = function () {
			$this->registerPreviewAbility();
			$this->registerSubmitAbility();
			$this->registerApproveAbility();
			$this->registerRejectAbility();
		};

		if ( did_action( 'wp_abilities_api_init' ) ) {
			$register_callback();
		} else {
			add_action( 'wp_abilities_api_init', $register_callback );
		}
	}

	// ────────────────────────────────────────────────────────────────────
	// Ability registration
	// ────────────────────────────────────────────────────────────────────

	private function registerPreviewAbility(): void {
		wp_register_ability(
			'extrachill-events/preview-artist-url',
			array(
				'label'               => __( 'Preview Artist Tour URL', 'extrachill-events' ),
				'description'         => __( 'Probe a tour/events URL via the universal web scraper. Returns detected format, event count, preview events, and a suggested artist binding. Non-destructive — no submission row is created.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => __( 'Tour/events page URL to probe.', 'extrachill-events' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                  => array( 'type' => 'boolean' ),
						'detected_format'          => array( 'type' => 'string' ),
						'events_found'             => array( 'type' => 'integer' ),
						'events_preview'           => array( 'type' => 'array' ),
						'suggested_artist_name'    => array( 'type' => 'string' ),
						'suggested_artist_term_id' => array( 'type' => array( 'integer', 'null' ) ),
						'source_metadata'          => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => array( $this, 'executePreview' ),
				'permission_callback' => array( $this, 'permissionLoggedIn' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerSubmitAbility(): void {
		wp_register_ability(
			'extrachill-events/submit-artist-url',
			array(
				'label'               => __( 'Submit Artist Tour URL', 'extrachill-events' ),
				'description'         => __( 'Submit a tour/events URL for admin review. Re-probes the URL server-side and inserts a moderation-queue row.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'           => array( 'type' => 'string', 'format' => 'uri' ),
						'contact_email' => array( 'type' => 'string' ),
						'contact_name'  => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'submission_id' => array( 'type' => 'integer' ),
						'status'        => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
						'events_found'  => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => array( $this, 'executeSubmit' ),
				'permission_callback' => array( $this, 'permissionLoggedIn' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerApproveAbility(): void {
		wp_register_ability(
			'extrachill-events/approve-artist-url-submission',
			array(
				'label'               => __( 'Approve Artist URL Submission', 'extrachill-events' ),
				'description'         => __( 'Approve a pending submission: resolves the artist term, creates a pipeline+flow with universal_web_scraper, runs the first scrape immediately.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'submission_id' ),
					'properties' => array(
						'submission_id'     => array( 'type' => 'integer' ),
						'artist_term_id'    => array( 'type' => 'integer' ),
						'artist_name'       => array( 'type' => 'string' ),
						'schedule_interval' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'                     => array( 'type' => 'boolean' ),
						'pipeline_id'                 => array( 'type' => 'integer' ),
						'flow_id'                     => array( 'type' => 'integer' ),
						'artist_term_id'              => array( 'type' => 'integer' ),
						'events_imported_immediately' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'execute_callback'    => array( $this, 'executeApprove' ),
				'permission_callback' => array( $this, 'permissionAdmin' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	private function registerRejectAbility(): void {
		wp_register_ability(
			'extrachill-events/reject-artist-url-submission',
			array(
				'label'               => __( 'Reject Artist URL Submission', 'extrachill-events' ),
				'description'         => __( 'Mark a pending submission as rejected with an optional reason.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'submission_id' ),
					'properties' => array(
						'submission_id' => array( 'type' => 'integer' ),
						'reason'        => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( $this, 'executeReject' ),
				'permission_callback' => array( $this, 'permissionAdmin' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Permission callbacks
	// ────────────────────────────────────────────────────────────────────

	public function permissionLoggedIn(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return is_user_logged_in();
	}

	public function permissionAdmin(): bool {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	// ────────────────────────────────────────────────────────────────────
	// preview-artist-url
	// ────────────────────────────────────────────────────────────────────

	public function executePreview( array $input ): array|\WP_Error {
		$raw_url = isset( $input['url'] ) ? (string) $input['url'] : '';
		$url     = esc_url_raw( $raw_url );

		if ( '' === $url ) {
			return new \WP_Error( 'invalid_url', __( 'URL is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		// Protocol whitelist — http/https only. esc_url_raw() already enforces
		// this against the default allowed protocols, but be explicit.
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_protocol', __( 'Only http and https URLs are supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$normalized = ArtistUrlSubmissionsTable::normalize_url( $url );
		if ( '' === $normalized ) {
			return new \WP_Error( 'invalid_url', __( 'URL could not be parsed.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$hash     = ArtistUrlSubmissionsTable::url_hash( $normalized );
		$existing = ArtistUrlSubmissionsTable::find_by_hash( $hash );
		if ( $existing && in_array( $existing['status'], array(
			ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			ArtistUrlSubmissionsTable::STATUS_APPROVED,
		), true ) ) {
			return new \WP_Error(
				'url_already_tracked',
				__( 'This URL is already being tracked.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'existing_status' => $existing['status'],
					'submission_id'   => (int) $existing['id'],
				)
			);
		}

		$probe = $this->probeUrl( $normalized );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}

		if ( 0 === $probe['events_found'] ) {
			return new \WP_Error(
				'no_events_found',
				__( "We couldn't extract events from that page. Try the manual form below.", 'extrachill-events' ),
				array( 'status' => 422 )
			);
		}

		$suggestion = $this->suggestArtist( $normalized, $probe );

		return array(
			'success'                  => true,
			'detected_format'          => (string) $probe['detected_format'],
			'events_found'             => (int) $probe['events_found'],
			'events_preview'           => $probe['events_preview'],
			'suggested_artist_name'    => (string) $suggestion['name'],
			'suggested_artist_term_id' => $suggestion['term_id'],
			'source_metadata'          => $probe['source_metadata'],
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// submit-artist-url
	// ────────────────────────────────────────────────────────────────────

	public function executeSubmit( array $input ): array|\WP_Error {
		$raw_url = isset( $input['url'] ) ? (string) $input['url'] : '';
		$url     = esc_url_raw( $raw_url );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_url', __( 'URL is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_protocol', __( 'Only http and https URLs are supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$normalized = ArtistUrlSubmissionsTable::normalize_url( $url );
		if ( '' === $normalized ) {
			return new \WP_Error( 'invalid_url', __( 'URL could not be parsed.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$hash     = ArtistUrlSubmissionsTable::url_hash( $normalized );
		$existing = ArtistUrlSubmissionsTable::find_by_hash( $hash );
		if ( $existing && in_array( $existing['status'], array(
			ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			ArtistUrlSubmissionsTable::STATUS_APPROVED,
		), true ) ) {
			return new \WP_Error(
				'url_already_tracked',
				__( 'This URL is already being tracked.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'existing_status' => $existing['status'],
					'submission_id'   => (int) $existing['id'],
				)
			);
		}

		// Resolve submitter identity. Logged-in users override any
		// client-provided name/email with the WP user record.
		$user_id       = get_current_user_id();
		$contact_email = isset( $input['contact_email'] ) ? sanitize_email( (string) $input['contact_email'] ) : '';
		$contact_name  = isset( $input['contact_name'] ) ? sanitize_text_field( (string) $input['contact_name'] ) : '';

		if ( $user_id > 0 ) {
			$user          = wp_get_current_user();
			$contact_email = (string) $user->user_email;
			$contact_name  = (string) ( $user->display_name ?: $user->user_login );
		} else {
			// Issue #320 says "any logged-in user" — we hard-reject
			// anonymous to match that contract.
			return new \WP_Error(
				'login_required',
				__( 'You must be logged in to submit a tour URL.', 'extrachill-events' ),
				array( 'status' => 401 )
			);
		}

		// Re-probe server-side regardless of what the preview saw.
		$probe = $this->probeUrl( $normalized );

		if ( is_wp_error( $probe ) || 0 === ( $probe['events_found'] ?? 0 ) ) {
			$submission_id = ArtistUrlSubmissionsTable::insert(
				array(
					'user_id'            => $user_id,
					'contact_email'      => $contact_email,
					'contact_name'       => $contact_name,
					'url'                => $normalized,
					'url_hash'           => $hash,
					'detected_format'    => '',
					'events_found_count' => 0,
					'status'             => ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED,
				)
			);

			if ( null !== $submission_id ) {
				$this->notifyAdminSubmission(
					array(
						'url'                   => $normalized,
						'contact_name'          => $contact_name,
						'contact_email'         => $contact_email,
						'detected_format'       => '',
						'events_found_count'    => 0,
						'suggested_artist_name' => '',
						'status'                => ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED,
					)
				);
			}

			return array(
				'success'       => false,
				'submission_id' => (int) $submission_id,
				'status'        => ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED,
				'message'       => __( "We couldn't extract events from that page. Try the manual form below.", 'extrachill-events' ),
				'events_found'  => 0,
			);
		}

		$suggestion = $this->suggestArtist( $normalized, $probe );

		$submission_id = ArtistUrlSubmissionsTable::insert(
			array(
				'user_id'                  => $user_id,
				'contact_email'            => $contact_email,
				'contact_name'             => $contact_name,
				'url'                      => $normalized,
				'url_hash'                 => $hash,
				'suggested_artist_name'    => $suggestion['name'],
				'suggested_artist_term_id' => $suggestion['term_id'],
				'detected_format'          => $probe['detected_format'],
				'events_found_count'       => (int) $probe['events_found'],
				'status'                   => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		if ( null === $submission_id ) {
			return new \WP_Error( 'insert_failed', __( 'Failed to record submission.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$this->notifyAdminSubmission(
			array(
				'url'                   => $normalized,
				'contact_name'          => $contact_name,
				'contact_email'         => $contact_email,
				'detected_format'       => $probe['detected_format'],
				'events_found_count'    => (int) $probe['events_found'],
				'suggested_artist_name' => $suggestion['name'],
				'status'                => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			)
		);

		$this->notifySubmitterConfirmation(
			array(
				'url'           => $normalized,
				'contact_name'  => $contact_name,
				'contact_email' => $contact_email,
			)
		);

		return array(
			'success'       => true,
			'submission_id' => (int) $submission_id,
			'status'        => ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW,
			'message'       => __( "Submitted for review. We'll set up automatic imports if approved.", 'extrachill-events' ),
			'events_found'  => (int) $probe['events_found'],
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// approve-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	public function executeApprove( array $input ): array|\WP_Error {
		$submission_id = (int) ( $input['submission_id'] ?? 0 );
		if ( $submission_id <= 0 ) {
			return new \WP_Error( 'invalid_submission_id', __( 'submission_id is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$submission = ArtistUrlSubmissionsTable::get( $submission_id );
		if ( ! $submission ) {
			return new \WP_Error( 'not_found', __( 'Submission not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		if ( ArtistUrlSubmissionsTable::STATUS_PENDING_REVIEW !== $submission['status'] ) {
			return new \WP_Error(
				'invalid_state',
				sprintf(
					/* translators: %s: current submission status */
					__( 'Submission is in %s state; only pending_review submissions can be approved.', 'extrachill-events' ),
					$submission['status']
				),
				array( 'status' => 409 )
			);
		}

		// Resolve artist term.
		$artist_term_id = $this->resolveArtistTerm(
			isset( $input['artist_term_id'] ) ? (int) $input['artist_term_id'] : 0,
			isset( $input['artist_name'] ) ? (string) $input['artist_name'] : '',
			isset( $submission['suggested_artist_term_id'] ) ? (int) $submission['suggested_artist_term_id'] : 0
		);

		if ( is_wp_error( $artist_term_id ) ) {
			return $artist_term_id;
		}

		$interval = isset( $input['schedule_interval'] ) ? sanitize_key( (string) $input['schedule_interval'] ) : self::DEFAULT_INTERVAL;
		if ( ! in_array( $interval, self::ALLOWED_INTERVALS, true ) ) {
			$interval = self::DEFAULT_INTERVAL;
		}

		$artist_term = get_term( $artist_term_id, 'artist' );
		$artist_name = ( $artist_term && ! is_wp_error( $artist_term ) ) ? (string) $artist_term->name : 'Artist ' . $artist_term_id;

		// 1. Resolve the single shared Artist Tour Import pipeline (B1).
		//    One pipeline is reused across every approved artist URL; each
		//    approval creates a NEW FLOW on it carrying the per-artist
		//    config (source_url + PRE_SELECTED artist term). There is no
		//    per-artist pipeline and no per-artist pipeline-level AI prompt.
		$pipeline_id = $this->resolveSharedArtistImportPipeline();
		if ( is_wp_error( $pipeline_id ) ) {
			return $pipeline_id;
		}

		// 2. Create the flow with universal_web_scraper handler and the
		//    SelectionMode-driven taxonomy bindings on the shared pipeline.
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $flow_ability ) {
			return new \WP_Error( 'missing_ability', __( 'datamachine/create-flow ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$upsert_handler_config = array(
			'post_status'                 => 'publish',
			'include_images'              => false,
			'post_author'                 => self::DEFAULT_POST_AUTHOR,
			'taxonomy_artist_selection'   => (string) $artist_term_id,
			'taxonomy_venue_selection'    => SelectionMode::AI_DECIDES,
			'taxonomy_location_selection' => SelectionMode::AI_DECIDES,
			'taxonomy_festival_selection' => SelectionMode::AI_DECIDES,
			'taxonomy_promoter_selection' => SelectionMode::SKIP,
			'taxonomy_category_selection' => SelectionMode::SKIP,
			'taxonomy_post_tag_selection' => SelectionMode::SKIP,
		);

		$import_handler_config = array(
			'source_url'       => $submission['url'],
			'search'           => '',
			'exclude_keywords' => '',
		);

		$ai_message = sprintf(
			/* translators: %s: artist name */
			__( 'Process this event from %s\'s tour page. The artist is already known and pre-selected. Identify the venue, city, and festival (if any) at extraction time.', 'extrachill-events' ),
			$artist_name
		);

		$flow_result = $flow_ability->execute(
			array(
				'pipeline_id'       => $pipeline_id,
				'flow_name'         => $artist_name . ' — Tour URL',
				'scheduling_config' => array( 'interval' => $interval ),
				'step_configs'      => array(
					'event_import' => array(
						'handler_slug'   => self::SCRAPER_HANDLER_SLUG,
						'handler_config' => $import_handler_config,
					),
					'upsert'       => array(
						'handler_slug'   => 'upsert_event',
						'handler_config' => $upsert_handler_config,
					),
					'ai'           => array(
						'user_message' => $ai_message,
					),
				),
			)
		);

		if ( empty( $flow_result['success'] ) || empty( $flow_result['flow_id'] ) ) {
			$err = $flow_result['error'] ?? 'Unknown error';
			return new \WP_Error( 'flow_creation_failed', 'Failed to create flow: ' . $err, array( 'status' => 500 ) );
		}

		$flow_id = (int) $flow_result['flow_id'];

		// Defensive patch in case create-flow's step_configs application
		// doesn't write through (matches CityAbilities' patchFlowSteps
		// belt-and-braces pattern).
		$this->patchFlowSteps(
			$flow_id,
			$import_handler_config,
			$upsert_handler_config,
			$ai_message
		);

		// 3. Update the submission row: approved + linked shared pipeline/flow.
		ArtistUrlSubmissionsTable::update(
			$submission_id,
			array(
				'status'         => ArtistUrlSubmissionsTable::STATUS_APPROVED,
				'pipeline_id'    => $pipeline_id,
				'flow_id'        => $flow_id,
				'artist_term_id' => $artist_term_id,
				'reviewed_at'    => current_time( 'mysql', true ),
				'reviewed_by'    => get_current_user_id(),
			)
		);

		// 4. Trigger first scrape immediately.
		$events_imported_immediately = null;
		$run_ability                 = wp_get_ability( 'datamachine/run-flow' );
		if ( $run_ability ) {
			$run_result                  = $run_ability->execute( array( 'flow_id' => $flow_id ) );
			$events_imported_immediately = ! empty( $run_result['success'] );
		}

		return array(
			'success'                     => true,
			'pipeline_id'                 => $pipeline_id,
			'flow_id'                     => $flow_id,
			'artist_term_id'              => $artist_term_id,
			'events_imported_immediately' => $events_imported_immediately,
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// reject-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	public function executeReject( array $input ): array|\WP_Error {
		$submission_id = (int) ( $input['submission_id'] ?? 0 );
		if ( $submission_id <= 0 ) {
			return new \WP_Error( 'invalid_submission_id', __( 'submission_id is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$submission = ArtistUrlSubmissionsTable::get( $submission_id );
		if ( ! $submission ) {
			return new \WP_Error( 'not_found', __( 'Submission not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$reason = isset( $input['reason'] ) ? sanitize_textarea_field( (string) $input['reason'] ) : '';

		ArtistUrlSubmissionsTable::update(
			$submission_id,
			array(
				'status'           => ArtistUrlSubmissionsTable::STATUS_REJECTED,
				'rejection_reason' => $reason,
				'reviewed_at'      => current_time( 'mysql', true ),
				'reviewed_by'      => get_current_user_id(),
			)
		);

		return array( 'success' => true );
	}

	// ────────────────────────────────────────────────────────────────────
	// Shared helpers
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Probe a URL through the registered `universal_web_scraper` handler
	 * and normalize the result.
	 *
	 * Layer purity: resolves the handler class via Data Machine core's
	 * public `HandlerAbilities` registry by its registered slug, never by
	 * referencing the data-machine-events-internal scraper class. The slug
	 * is the substrate's public contract.
	 *
	 * Returns an array with:
	 *   detected_format (string)
	 *   events_found (int)
	 *   events_preview (array of up to 5 event records)
	 *   source_metadata (array)
	 *   raw_first_event (array)  ← used by suggestArtist()
	 *   page_html (string)       ← used by suggestArtist()
	 *
	 * On infrastructure error returns a WP_Error.
	 *
	 * @param string $url Already-normalized URL.
	 * @return array|\WP_Error
	 */
	private function probeUrl( string $url ) {
		$results = $this->fetchScraperPackets( $url );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		if ( empty( $results ) ) {
			return array(
				'detected_format' => '',
				'events_found'    => 0,
				'events_preview'  => array(),
				'source_metadata' => array(),
				'raw_first_event' => array(),
				'page_html'       => '',
			);
		}

		$packet_entries = array();
		foreach ( $results as $packet_obj ) {
			$packet_array = $packet_obj->addTo( array() );
			$packet_entry = $packet_array[0] ?? array();
			if ( ! empty( $packet_entry ) ) {
				$packet_entries[] = $packet_entry;
			}
		}

		$detected_format = '';
		$first_meta      = $packet_entries[0]['metadata'] ?? array();
		if ( is_array( $first_meta ) ) {
			$detected_format = (string) ( $first_meta['extraction_method'] ?? $first_meta['source_type'] ?? '' );
		}

		$events_preview  = array();
		$raw_first_event = array();
		$count           = 0;
		foreach ( $packet_entries as $entry ) {
			$body    = (string) ( $entry['data']['body'] ?? '' );
			$decoded = '' !== $body ? json_decode( $body, true ) : null;
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$event = $decoded['event'] ?? null;
			if ( ! is_array( $event ) ) {
				continue;
			}
			++$count;
			if ( empty( $raw_first_event ) ) {
				$raw_first_event = $event;
			}
			if ( count( $events_preview ) < 5 ) {
				$events_preview[] = array(
					'title'     => (string) ( $event['title'] ?? '' ),
					'startDate' => (string) ( $event['startDate'] ?? '' ),
					'startTime' => (string) ( $event['startTime'] ?? '' ),
					'venue'     => (string) ( $event['venue'] ?? '' ),
					'ticketUrl' => (string) ( $event['ticketUrl'] ?? '' ),
				);
			}
		}

		return array(
			'detected_format' => $detected_format,
			'events_found'    => $count,
			'events_preview'  => $events_preview,
			'source_metadata' => $first_meta,
			'raw_first_event' => $raw_first_event,
			'page_html'       => $this->fetchPageHtml( $url ),
		);
	}

	/**
	 * Run the registered universal web scraper handler for a URL and
	 * return its DataPacket[] result.
	 *
	 * Consumes data-machine-events' generic scraping primitive by its
	 * public registered slug through Data Machine core's `HandlerAbilities`
	 * registry — the substrate-public way to obtain the handler class
	 * without naming the internal class. This is the same instantiate +
	 * `get_fetch_data()` shape Data Machine core's own
	 * `datamachine/test-handler` ability uses.
	 *
	 * NOTE (substrate follow-up): the ideal long-term surface is the
	 * `datamachine/test-handler` ability itself, but its packet summaries
	 * truncate the body to a 200-char preview, which drops the structured
	 * event JSON this preview needs (title/startDate/venue/ticketUrl +
	 * the raw first event for artist suggestion). Until that ability gains
	 * a full-body/non-truncating mode, we resolve and run the registered
	 * handler class directly. Tracked as a substrate enhancement in the
	 * #200 PR body.
	 *
	 * @param string $url Already-normalized URL.
	 * @return array|\WP_Error DataPacket[] (possibly empty) or WP_Error.
	 */
	private function fetchScraperPackets( string $url ) {
		if ( ! class_exists( '\\DataMachine\\Abilities\\HandlerAbilities' ) ) {
			return new \WP_Error(
				'scraper_unavailable',
				__( 'Data Machine handler registry is not available.', 'extrachill-events' ),
				array( 'status' => 500 )
			);
		}

		$abilities = new HandlerAbilities();
		$info      = $abilities->getHandler( self::SCRAPER_HANDLER_SLUG );
		$handler_class = is_array( $info ) ? ( $info['class'] ?? null ) : null;

		if ( ! $handler_class || ! class_exists( $handler_class ) ) {
			return new \WP_Error(
				'scraper_unavailable',
				sprintf(
					/* translators: %s: handler slug */
					__( 'The %s event-import handler is not registered.', 'extrachill-events' ),
					self::SCRAPER_HANDLER_SLUG
				),
				array( 'status' => 500 )
			);
		}

		$config = array(
			'source_url'   => $url,
			'flow_step_id' => 'preview_' . wp_generate_uuid4(),
			'flow_id'      => 'preview',
			'search'       => '',
		);

		// Fill in any handler defaults the same way test-handler does, so
		// the probe matches a real flow run's config surface.
		if ( method_exists( $abilities, 'applyDefaults' ) ) {
			$config = $abilities->applyDefaults( self::SCRAPER_HANDLER_SLUG, $config );
		}

		$handler = new $handler_class();

		try {
			$results = $handler->get_fetch_data( 'preview', $config, null );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'scraper_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Fetch the page HTML for metadata-based artist name detection.
	 * Best-effort, short-timeout, no caching — failure is non-fatal.
	 *
	 * @param string $url
	 * @return string Raw HTML body, or '' on error.
	 */
	private function fetchPageHtml( string $url ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			$host = 'localhost';
		}

		$default_user_agent = sprintf(
			'Mozilla/5.0 (compatible; ExtraChillEventsBot/1.0; +https://%s)',
			$host
		);

		/**
		 * Filter the User-Agent sent when fetching an artist's tour/events page.
		 *
		 * @param string $default_user_agent UA derived from the deploying site host.
		 * @param string $url                The page URL being fetched.
		 */
		$user_agent = (string) apply_filters( 'extrachill_events_artist_url_fetch_user_agent', $default_user_agent, $url );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 3,
				'user-agent'  => $user_agent,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Suggest an artist binding for a probed URL.
	 *
	 * Tries (in order):
	 *   1. JSON-LD `Performer` / `MusicGroup` on the first extracted event.
	 *   2. og:title / <title> / first <h1> on the fetched HTML.
	 *   3. URL domain → Title Case.
	 *
	 * Then fuzzy-matches the result against existing `artist` terms
	 * using similar_text(). Returns:
	 *   { name: string, term_id: int|null }
	 *
	 * @param string $url   Normalized URL.
	 * @param array  $probe Output from probeUrl().
	 * @return array{name:string,term_id:int|null}
	 */
	private function suggestArtist( string $url, array $probe ): array {
		$candidates = array();

		// 1. JSON-LD / structured data on the first event.
		$first = $probe['raw_first_event'] ?? array();
		if ( is_array( $first ) ) {
			$performer = $first['performer'] ?? $first['artist'] ?? '';
			if ( is_array( $performer ) ) {
				$performer = $performer['name'] ?? '';
			}
			if ( is_string( $performer ) && '' !== trim( $performer ) ) {
				$candidates[] = trim( $performer );
			}
		}

		$html = $probe['page_html'] ?? '';
		if ( '' !== $html ) {
			// 2a. og:title
			if ( preg_match( '/<meta[^>]+property=[\"\']og:title[\"\'][^>]+content=[\"\']([^\"\']+)[\"\']/i', $html, $m ) ) {
				$candidates[] = $this->stripSiteTokens( html_entity_decode( $m[1] ) );
			}
			// 2b. <title>
			if ( preg_match( '/<title>([^<]+)<\/title>/i', $html, $m ) ) {
				$candidates[] = $this->stripSiteTokens( html_entity_decode( $m[1] ) );
			}
			// 2c. first <h1>
			if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
				$candidates[] = $this->stripSiteTokens( wp_strip_all_tags( $m[1] ) );
			}
		}

		// 3. URL domain.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $host ) {
			$host = preg_replace( '/^www\./i', '', $host );
			$root = explode( '.', $host )[0];
			if ( '' !== $root ) {
				$candidates[] = $this->titleCaseFromSlug( $root );
			}
		}

		$name = '';
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate ) {
				$name = $candidate;
				break;
			}
		}

		$term_id = $this->fuzzyMatchArtistTerm( $name );

		return array(
			'name'    => $name,
			'term_id' => $term_id,
		);
	}

	/**
	 * Strip site-name / generic suffixes from a page title or h1, e.g.
	 * "Theo Katzman | Tour" → "Theo Katzman", "Tour - Theo Katzman" → "Theo Katzman".
	 *
	 * @param string $title
	 * @return string
	 */
	private function stripSiteTokens( string $title ): string {
		$title = trim( $title );
		// Split on common separators and drop any segment that's a generic token.
		$generic = array( 'tour', 'tours', 'events', 'shows', 'concerts', 'live', 'calendar', 'gigs', 'tour dates' );
		$parts   = preg_split( '/\s*[|\-–—:]\s*/u', $title );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return $title;
		}

		$kept = array();
		foreach ( $parts as $part ) {
			$normalized = strtolower( trim( $part ) );
			if ( in_array( $normalized, $generic, true ) ) {
				continue;
			}
			$kept[] = trim( $part );
		}

		if ( empty( $kept ) ) {
			return $title;
		}

		// The longest remaining segment is usually the artist name.
		usort(
			$kept,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return $kept[0];
	}

	/**
	 * Convert a URL slug to Title Case ("theokatzman" → "Theokatzman",
	 * "theo-katzman" → "Theo Katzman").
	 *
	 * @param string $slug
	 * @return string
	 */
	private function titleCaseFromSlug( string $slug ): string {
		$slug = preg_replace( '/[\-_]+/', ' ', $slug );
		return ucwords( trim( (string) $slug ) );
	}

	/**
	 * Fuzzy-match a candidate artist name against existing `artist`
	 * taxonomy terms using similar_text() percentage.
	 *
	 * Returns the closest term ID if its similarity meets
	 * ARTIST_FUZZY_MATCH_THRESHOLD, else null.
	 *
	 * @param string $name
	 * @return int|null
	 */
	private function fuzzyMatchArtistTerm( string $name ): ?int {
		$name = trim( $name );
		if ( '' === $name || ! taxonomy_exists( 'artist' ) ) {
			return null;
		}

		// Try an exact match first (cheap path, common case).
		$exact = get_term_by( 'name', $name, 'artist' );
		if ( $exact && ! is_wp_error( $exact ) ) {
			return (int) $exact->term_id;
		}

		// Fall back to slug match — handles minor casing/punctuation drift.
		$by_slug = get_term_by( 'slug', sanitize_title( $name ), 'artist' );
		if ( $by_slug && ! is_wp_error( $by_slug ) ) {
			return (int) $by_slug->term_id;
		}

		// Fuzzy scan: walk artist terms and keep the highest similar_text
		// percentage. Capped to a reasonable batch so this stays O(N) on
		// small-to-medium artist taxonomies (current site is ~1200 terms).
		$terms = get_terms(
			array(
				'taxonomy'   => 'artist',
				'hide_empty' => false,
				'number'     => 5000,
				'fields'     => 'id=>name',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$best_id  = null;
		$best_pct = 0.0;
		foreach ( $terms as $term_id => $term_name ) {
			$pct = 0.0;
			similar_text( strtolower( $name ), strtolower( (string) $term_name ), $pct );
			if ( $pct > $best_pct ) {
				$best_pct = $pct;
				$best_id  = (int) $term_id;
			}
		}

		return ( $best_pct >= self::ARTIST_FUZZY_MATCH_THRESHOLD ) ? $best_id : null;
	}

	/**
	 * Resolve the artist term ID during approval.
	 *
	 * Resolution order:
	 *   1. Explicit `artist_term_id` input.
	 *   2. Explicit `artist_name` input (looks up existing, creates if missing).
	 *   3. Submission's suggested term_id.
	 *
	 * Returns the term ID, or a WP_Error if none of the above yields a
	 * valid term.
	 *
	 * @param int    $explicit_id
	 * @param string $explicit_name
	 * @param int    $suggested_id
	 * @return int|\WP_Error
	 */
	private function resolveArtistTerm( int $explicit_id, string $explicit_name, int $suggested_id ) {
		if ( ! taxonomy_exists( 'artist' ) ) {
			return new \WP_Error( 'artist_taxonomy_missing', __( 'Artist taxonomy is not registered on this site.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		if ( $explicit_id > 0 ) {
			$term = get_term( $explicit_id, 'artist' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		$explicit_name = trim( $explicit_name );
		if ( '' !== $explicit_name ) {
			$existing = get_term_by( 'name', $explicit_name, 'artist' );
			if ( $existing && ! is_wp_error( $existing ) ) {
				return (int) $existing->term_id;
			}

			$inserted = wp_insert_term( $explicit_name, 'artist' );
			if ( is_wp_error( $inserted ) ) {
				// Slug collision — try slug lookup as a recovery.
				$by_slug = get_term_by( 'slug', sanitize_title( $explicit_name ), 'artist' );
				if ( $by_slug && ! is_wp_error( $by_slug ) ) {
					return (int) $by_slug->term_id;
				}
				return new \WP_Error( 'artist_create_failed', $inserted->get_error_message(), array( 'status' => 500 ) );
			}
			return (int) $inserted['term_id'];
		}

		if ( $suggested_id > 0 ) {
			$term = get_term( $suggested_id, 'artist' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}

		return new \WP_Error(
			'artist_required',
			__( 'Provide artist_term_id or artist_name, or have a suggested_artist_term_id on the submission.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve the single shared Artist Tour Import pipeline (architecture
	 * model B1 — one pipeline reused across all approved artist URLs).
	 *
	 * Idempotent: the shared pipeline_id is cached in a site option for
	 * the fast path, with a name-based fallback so the resolver still
	 * recovers if the option was cleared or this is the first approval
	 * after the B1 deploy. If neither yields a live pipeline, a new one
	 * is created (event_import → ai → upsert), given its artist-agnostic
	 * AI system prompt once, and its id cached.
	 *
	 * Per-artist identity never lives at the pipeline level — it lives on
	 * each flow (source_url + PRE_SELECTED artist term + per-artist
	 * user_message). That is what makes a single shared pipeline safe.
	 *
	 * @return int|\WP_Error Pipeline ID, or WP_Error on creation failure.
	 */
	private function resolveSharedArtistImportPipeline() {
		$stored_id = (int) get_option( self::SHARED_PIPELINE_OPTION, 0 );

		if ( $stored_id > 0 && $this->pipelineExists( $stored_id ) ) {
			return $stored_id;
		}

		// Fallback: locate by stable name if the option is missing/stale.
		$found_id = $this->findExistingPipeline( self::SHARED_PIPELINE_NAME );
		if ( $found_id > 0 ) {
			update_option( self::SHARED_PIPELINE_OPTION, $found_id, false );
			return $found_id;
		}

		// Create the shared pipeline scaffold (event_import → ai → upsert).
		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $pipeline_ability ) {
			return new \WP_Error( 'missing_ability', __( 'datamachine/create-pipeline ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$pipeline_result = $pipeline_ability->execute(
			array(
				'pipeline_name' => self::SHARED_PIPELINE_NAME,
				'steps'         => array(
					array( 'step_type' => 'event_import', 'label' => 'Event Import' ),
					array( 'step_type' => 'ai',           'label' => 'AI Agent' ),
					array( 'step_type' => 'upsert',       'label' => 'Upsert' ),
				),
			)
		);

		if ( empty( $pipeline_result['success'] ) || empty( $pipeline_result['pipeline_id'] ) ) {
			$err = $pipeline_result['error'] ?? 'Unknown error';
			return new \WP_Error( 'pipeline_creation_failed', 'Failed to create shared Artist Tour Import pipeline: ' . $err, array( 'status' => 500 ) );
		}

		$pipeline_id = (int) $pipeline_result['pipeline_id'];

		// Set the artist-agnostic AI system prompt ONCE at creation.
		$this->configureSharedPipelineAiStep( $pipeline_id );

		update_option( self::SHARED_PIPELINE_OPTION, $pipeline_id, false );

		return $pipeline_id;
	}

	/**
	 * Lightweight existence check for a pipeline id.
	 *
	 * @param int $pipeline_id
	 * @return bool
	 */
	private function pipelineExists( int $pipeline_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT pipeline_id FROM {$table} WHERE pipeline_id = %d LIMIT 1", $pipeline_id )
		);

		return ! empty( $found );
	}

	/**
	 * Find an existing pipeline by name.
	 *
	 * Mirrors CityAbilities::findExistingPipeline() — same table, same
	 * query shape — so the shared-pipeline name lookup is consistent with
	 * the rest of the events plugin.
	 *
	 * @param string $pipeline_name Pipeline name to search for.
	 * @return int|null Pipeline ID if found, null otherwise.
	 */
	private function findExistingPipeline( string $pipeline_name ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pipeline_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pipeline_id FROM {$table} WHERE pipeline_name = %s LIMIT 1",
				$pipeline_name
			)
		);

		return $pipeline_id ? (int) $pipeline_id : null;
	}

	/**
	 * Configure the shared pipeline's AI step with an artist-agnostic
	 * system prompt. Called ONCE at shared-pipeline creation — never per
	 * artist. Per-artist identity is carried by each flow's user_message,
	 * so the pipeline prompt must not name any specific artist (doing so
	 * on a shared pipeline would clobber every other artist's flow).
	 *
	 * Does not set a provider/model — those are resolved by AIStep from
	 * agent_config and site settings at runtime.
	 *
	 * @param int $pipeline_id
	 */
	private function configureSharedPipelineAiStep( int $pipeline_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_pipelines';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$pipeline = $wpdb->get_row(
			$wpdb->prepare( "SELECT pipeline_config FROM {$table} WHERE pipeline_id = %d", $pipeline_id ),
			ARRAY_A
		);

		if ( ! $pipeline ) {
			return;
		}

		$config = json_decode( $pipeline['pipeline_config'], true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as &$step ) {
			if ( ( $step['step_type'] ?? '' ) === 'ai' ) {
				// Do NOT write a provider/model into the pipeline AI step.
				// AIStep resolves the model/provider from agent_config and
				// site settings at runtime; baking a literal here silently
				// overrides the operator's configured model on the shared
				// artist-import pipeline.
				/**
				 * Filter the events feed name written into the AI step prompt.
				 *
				 * Defaults to the deploying site's name.
				 *
				 * @param string $feed_name Default feed name (site name).
				 */
				$feed_name = (string) apply_filters( 'extrachill_events_artist_url_feed_name', get_bloginfo( 'name' ) );

				$step['system_prompt'] = sprintf(
					'You process events from tour/events pages for the %s events feed. The artist for each flow is already pre-selected and named in the flow\'s user message — do not change the artist binding. Identify the venue, city/location, and festival (if any) for each event based on the available information. Skip WordPress categories and post tags entirely.',
					$feed_name
				);
				$step['enabled_tools'] = array();
			}
		}
		unset( $step );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'pipeline_config' => wp_json_encode( $config ) ),
			array( 'pipeline_id' => $pipeline_id )
		);
	}

	/**
	 * Belt-and-braces flow-step patch — writes handler slugs/configs and
	 * AI user_message directly to the flow_config JSON. Mirrors
	 * CityAbilities::patchFlowSteps() to harden against the same
	 * create-flow timing quirks that affected city pipelines.
	 *
	 * Patches by step_type. The shared Artist Tour Import pipeline uses
	 * the `upsert` step type (not the retired `update`), so this matches
	 * `upsert`.
	 *
	 * @param int    $flow_id
	 * @param array  $import_handler_config
	 * @param array  $upsert_handler_config
	 * @param string $ai_message
	 */
	private function patchFlowSteps( int $flow_id, array $import_handler_config, array $upsert_handler_config, string $ai_message ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'datamachine_flows';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$flow = $wpdb->get_row(
			$wpdb->prepare( "SELECT flow_config FROM {$table} WHERE flow_id = %d", $flow_id ),
			ARRAY_A
		);
		if ( ! $flow ) {
			return;
		}

		$config = json_decode( $flow['flow_config'], true );
		if ( ! is_array( $config ) ) {
			return;
		}

		foreach ( $config as &$step ) {
			$step_type = $step['step_type'] ?? '';

			if ( 'event_import' === $step_type ) {
				$step['handler_slugs']   = array( self::SCRAPER_HANDLER_SLUG );
				$step['handler_configs'] = array( self::SCRAPER_HANDLER_SLUG => $import_handler_config );
				$step['enabled']         = true;
			}

			if ( 'upsert' === $step_type ) {
				$step['handler_slugs']   = array( 'upsert_event' );
				$step['handler_configs'] = array( 'upsert_event' => $upsert_handler_config );
				$step['enabled']         = true;
			}

			if ( 'ai' === $step_type ) {
				$step['user_message'] = $ai_message;
			}
		}
		unset( $step );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'flow_config' => wp_json_encode( $config ) ),
			array( 'flow_id' => $flow_id )
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// Submission notifications (issue #210)
	// ────────────────────────────────────────────────────────────────────

	/**
	 * Send an admin notification about a new artist URL submission.
	 *
	 * Fires for both `pending_review` and `scraping_failed` statuses so the
	 * admin always knows a submission is waiting in the moderation queue.
	 * Mirrors the dispatch pattern from EventSubmissionAbilities::notifyAdmin()
	 * — uses the `extrachill/minimal` template through the EC mail layer.
	 *
	 * @param array $data Submission details: url, contact_name, contact_email,
	 *                    detected_format, events_found_count,
	 *                    suggested_artist_name, status.
	 */
	private function notifyAdminSubmission( array $data ): void {
		$to = get_option( 'admin_email' );
		if ( empty( $to ) || ! is_email( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$url       = (string) ( $data['url'] ?? '' );
		$status    = (string) ( $data['status'] ?? '' );

		$status_label = ArtistUrlSubmissionsTable::STATUS_SCRAPING_FAILED === $status
			? __( 'Scrape failed', 'extrachill-events' )
			: __( 'Pending review', 'extrachill-events' );

		$subject = sprintf(
			/* translators: 1: site name, 2: status label. */
			__( '[%1$s] New Artist URL Submission: %2$s', 'extrachill-events' ),
			$site_name,
			$status_label
		);

		$preheader = sprintf(
			/* translators: %s: submitter name. */
			__( 'Artist URL submission from %s.', 'extrachill-events' ),
			(string) ( $data['contact_name'] ?? '' )
		);

		$body_html  = '<p>' . esc_html__( 'A new artist URL submission has been received:', 'extrachill-events' ) . '</p>';
		$body_html .= '<ul>';
		$body_html .= '<li>' . sprintf(
			/* translators: %s: submitted URL. */
			esc_html__( 'URL: %s', 'extrachill-events' ),
			'<strong>' . esc_html( $url ) . '</strong>'
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: 1: submitter name, 2: submitter email. */
			esc_html__( 'Submitted by: %1$s (%2$s)', 'extrachill-events' ),
			esc_html( (string) ( $data['contact_name'] ?? '' ) ),
			esc_html( (string) ( $data['contact_email'] ?? '' ) )
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: %s: detected format. */
			esc_html__( 'Detected format: %s', 'extrachill-events' ),
			'<code>' . esc_html( (string) ( $data['detected_format'] ?? '' ) ) . '</code>'
		) . '</li>';
		$body_html .= '<li>' . sprintf(
			/* translators: %d: events found count. */
			esc_html__( 'Events found: %d', 'extrachill-events' ),
			(int) ( $data['events_found_count'] ?? 0 )
		) . '</li>';

		$suggested_artist = (string) ( $data['suggested_artist_name'] ?? '' );
		if ( '' !== $suggested_artist ) {
			$body_html .= '<li>' . sprintf(
				/* translators: %s: suggested artist name. */
				esc_html__( 'Suggested artist: %s', 'extrachill-events' ),
				'<strong>' . esc_html( $suggested_artist ) . '</strong>'
			) . '</li>';
		}

		$body_html .= '<li>' . sprintf(
			/* translators: %s: status label. */
			esc_html__( 'Status: %s', 'extrachill-events' ),
			esc_html( $status_label )
		) . '</li>';
		$body_html .= '</ul>';

		$queue_url = $this->moderationQueueUrl();

		$body_html .= '<p><a href="' . esc_url( $queue_url ) . '">' . esc_html__( 'Review artist URL submissions', 'extrachill-events' ) . '</a></p>';

		$context = array(
			'subject_html' => esc_html( $subject ),
			'preheader'    => $preheader,
			'body_html'    => $body_html,
		);

		$this->dispatchEmail(
			array(
				'to'       => $to,
				'subject'  => $subject,
				'template' => 'extrachill/minimal',
				'context'  => array_merge(
					$context,
					array(
						'cta_url'   => $queue_url,
						'cta_label' => __( 'Review artist URL submissions', 'extrachill-events' ),
					)
				),
			),
			'admin'
		);
	}

	/**
	 * Send a confirmation email to the submitter of an artist URL.
	 *
	 * Mirrors EventSubmissionAbilities::notifySubmitter() — uses the
	 * `extrachill/branded` template through the EC mail layer. Only sent
	 * for `pending_review` submissions (not for `scraping_failed`, where
	 * the API response already carries the failure message).
	 *
	 * @param array $data Submission details: url, contact_name, contact_email.
	 */
	private function notifySubmitterConfirmation( array $data ): void {
		$to = (string) ( $data['contact_email'] ?? '' );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$url       = (string) ( $data['url'] ?? '' );

		$subject = sprintf(
			/* translators: 1: site name, 2: submitted URL. */
			__( '[%1$s] Artist URL Submission Received: %2$s', 'extrachill-events' ),
			$site_name,
			$url
		);

		$preheader = sprintf(
			/* translators: %s: submitted URL. */
			__( 'We received your tour URL submission for %s.', 'extrachill-events' ),
			$url
		);

		$body_html  = '<p>' . esc_html__( 'Thanks for submitting an artist tour URL!', 'extrachill-events' ) . '</p>';
		$body_html .= '<p>' . sprintf(
			/* translators: %s: submitted URL. */
			esc_html__( 'We received your submission for: %s', 'extrachill-events' ),
			'<strong>' . esc_html( $url ) . '</strong>'
		) . '</p>';
		$body_html .= '<p>' . esc_html__( "We'll review it and set up automatic event imports if it looks good. You'll hear from us once it's been reviewed.", 'extrachill-events' ) . '</p>';

		$context = array(
			'subject_html'   => esc_html( $subject ),
			'preheader'      => $preheader,
			'recipient_name' => (string) ( $data['contact_name'] ?? '' ),
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
	 * Dispatch an outgoing notification through the EC mail layer.
	 *
	 * Mirrors EventSubmissionAbilities::dispatchEmail() — prefers
	 * `extrachill_send_registration_email()` (extrachill-users) which wraps
	 * the send in PermissionHelper::run_as_authenticated(), then falls back
	 * to `ec_send_email()` and the raw `datamachine/send-email` ability.
	 * Failures are logged (never thrown) so a transient send error does not
	 * break submission.
	 *
	 * @param array  $args     Arguments forwarded to the ability.
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
				sprintf( 'ArtistUrlImport: %s notification failed to send', $audience ),
				array(
					'audience' => $audience,
					'to'       => $args['to'] ?? '',
					'subject'  => $args['subject'] ?? '',
					'result'   => is_array( $result ) ? $result : array( 'result' => $result ),
				)
			);
		}
	}

	/**
	 * Build the admin URL for the artist URL submissions moderation queue.
	 *
	 * Resolves the events post-type slug via data-machine-events' public
	 * constant and the page slug from ArtistUrlSubmissionsAdmin when the
	 * admin class is loaded (admin context). Falls back to the known slug
	 * literal for non-admin contexts (REST/CLI) where the admin class may
	 * not be loaded.
	 *
	 * @return string
	 */
	private function moderationQueueUrl(): string {
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';

		$page_slug = class_exists( '\ExtraChillEvents\Admin\ArtistUrlSubmissionsAdmin' )
			? \ExtraChillEvents\Admin\ArtistUrlSubmissionsAdmin::PAGE_SLUG
			: 'extrachill-events-artist-url-submissions';

		return admin_url( 'edit.php?post_type=' . $post_type . '&page=' . $page_slug );
	}
}
