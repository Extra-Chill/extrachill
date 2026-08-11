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
 * uses only public DM abilities (`datamachine/create-pipeline`,
 * `datamachine/create-flow`, `datamachine/get-pipeline-configuration`,
 * `datamachine/update-step-configuration`, `datamachine/run-flow`).
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
use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\VenueExpansionRunner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ArtistUrlImportAbilities {
	/** Feature-owned producer for submitter moderation notifications. */
	private const NOTIFICATION_PRODUCER = 'extrachill-events-artist-url-import';

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

	/** Host-owned platform/aggregator pages are not bounded recurring entities. */
	private const UNSUPPORTED_SOURCE_HOSTS = array(
		'axs.com'         => 'platform',
		'bandsintown.com' => 'aggregator',
		'dice.fm'         => 'platform',
		'eventbrite.com'  => 'platform',
		'jambase.com'     => 'aggregator',
		'songkick.com'    => 'aggregator',
		'allevents.in'    => 'aggregator',
	);

	/**
	 * Default author for events published by an approved pipeline.
	 */
	private const DEFAULT_POST_AUTHOR = 32;

	private static bool $registered = false;

	/**
	 * Resolve the system agent context used to own pipelines/flows and to
	 * act as a fallback notification actor for non-interactive approvals.
	 *
	 * Reuses datamachine_resolve_system_agent_context(), the substrate helper
	 * that attributes system tasks to the install's default agent (events-bot
	 * in production). Falls back to the default agent user + agent_id resolution
	 * if the helper is unavailable.
	 *
	 * @return array{agent_id:int|null,user_id:int|null}
	 */
	private function resolveSystemAgentContext(): array {
		static $context = null;
		if ( null !== $context ) {
			return $context;
		}

		$context = array(
			'agent_id' => null,
			'user_id'  => null,
		);

		if ( function_exists( 'datamachine_resolve_system_agent_context' ) ) {
			$resolved = datamachine_resolve_system_agent_context();
			if ( ! empty( $resolved['agent_id'] ) ) {
				$context['agent_id'] = (int) $resolved['agent_id'];
			}
			if ( ! empty( $resolved['user_id'] ) ) {
				$context['user_id'] = (int) $resolved['user_id'];
			}
		}

		if (
			( null === $context['agent_id'] || $context['agent_id'] <= 0 || $context['user_id'] <= 0 )
			&& class_exists( '\DataMachine\Core\FilesRepository\DirectoryManager' )
		) {
			$default_user_id = (int) \DataMachine\Core\FilesRepository\DirectoryManager::get_default_agent_user_id();
			if ( $default_user_id > 0 ) {
				$context['user_id'] = $default_user_id;
				if ( function_exists( 'datamachine_resolve_or_create_agent_id' ) ) {
					$agent_id = datamachine_resolve_or_create_agent_id( $default_user_id );
					if ( $agent_id > 0 ) {
						$context['agent_id'] = $agent_id;
					}
				}
			}
		}

		return $context;
	}

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
			$this->registerGenericAbilities();
			$this->registerPreviewAbility();
			$this->registerSubmitAbility();
			$this->registerApproveAbility();
			$this->registerRejectAbility();
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/** Register the Phase 1 source-neutral contracts. */
	private function registerGenericAbilities(): void {
		$qualify_schema = array(
			'type'       => 'object',
			'required'   => array( 'url' ),
			'properties' => array(
				'url' => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);

		wp_register_ability(
			'extrachill/qualify-event-source',
			array(
				'label'               => __( 'Qualify Event Source', 'extrachill-events' ),
				'description'         => __( 'Discover and test a canonical recurring event source, classify its bounded domain identity, and recommend moderation routing.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $qualify_schema,
				'output_schema'       => $this->qualificationOutputSchema(),
				'execute_callback'    => array( $this, 'executeQualifyEventSource' ),
				'permission_callback' => array( $this, 'permissionLoggedIn' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'extrachill-events/preview-event-source',
			array(
				'label'               => __( 'Preview Event Source', 'extrachill-events' ),
				'description'         => __( 'Compatibility-shaped preview of qualified event-source intake.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $qualify_schema,
				'output_schema'       => $this->qualificationOutputSchema(),
				'execute_callback'    => array( $this, 'executePreview' ),
				'permission_callback' => array( $this, 'permissionLoggedIn' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'extrachill-events/submit-event-source',
			array(
				'label'               => __( 'Submit Event Source', 'extrachill-events' ),
				'description'         => __( 'Server-side requalify and persist an event source for moderation.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'url' ),
					'properties' => array(
						'url'           => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'contact_email' => array( 'type' => 'string' ),
						'contact_name'  => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'required'   => array( 'success', 'submission_id', 'status', 'message', 'events_found', 'source_kind' ),
					'properties' => array(
						'success'       => array( 'type' => 'boolean' ),
						'submission_id' => array( 'type' => 'integer' ),
						'status'        => array( 'type' => 'string' ),
						'message'       => array( 'type' => 'string' ),
						'events_found'  => array( 'type' => 'integer' ),
						'source_kind'   => array(
							'type' => 'string',
							'enum' => array( 'artist', 'venue' ),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeSubmit' ),
				'permission_callback' => array( $this, 'permissionLoggedIn' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		$approval_properties = array(
			'submission_id'     => array( 'type' => 'integer' ),
			'source_kind'       => array(
				'type' => 'string',
				'enum' => array( 'artist', 'venue', 'unknown' ),
			),
			'entity_term_id'    => array( 'type' => 'integer' ),
			'entity_name'       => array( 'type' => 'string' ),
			'artist_term_id'    => array( 'type' => 'integer' ),
			'artist_name'       => array( 'type' => 'string' ),
			'venue_term_id'     => array( 'type' => 'integer' ),
			'venue_name'        => array( 'type' => 'string' ),
			'pipeline_id'       => array( 'type' => 'integer' ),
			'schedule_interval' => array( 'type' => 'string' ),
		);
		wp_register_ability(
			'extrachill-events/approve-event-source-submission',
			array(
				'label'               => __( 'Approve Event Source Submission', 'extrachill-events' ),
				'description'         => __( 'Approve a moderated artist or venue source through its existing owner flow primitive.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'submission_id' ),
					'properties' => $approval_properties,
				),
				'output_schema'       => array(
					'oneOf' => array(
						array(
							'type'       => 'object',
							'required'   => array( 'success', 'pipeline_id', 'flow_id', 'source_kind', 'artist_term_id' ),
							'properties' => array(
								'success'        => array( 'type' => 'boolean' ),
								'pipeline_id'    => array( 'type' => 'integer' ),
								'flow_id'        => array( 'type' => 'integer' ),
								'source_kind'    => array(
									'type' => 'string',
									'enum' => array( 'artist' ),
								),
								'artist_term_id' => array( 'type' => 'integer' ),
								'events_imported_immediately' => array( 'type' => array( 'integer', 'null' ) ),
							),
						),
						array(
							'type'       => 'object',
							'required'   => array( 'success', 'pipeline_id', 'flow_id', 'source_kind', 'venue_term_id' ),
							'properties' => array(
								'success'       => array( 'type' => 'boolean' ),
								'pipeline_id'   => array( 'type' => 'integer' ),
								'flow_id'       => array( 'type' => 'integer' ),
								'source_kind'   => array(
									'type' => 'string',
									'enum' => array( 'venue' ),
								),
								'venue_term_id' => array( 'type' => 'integer' ),
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'executeApprove' ),
				'permission_callback' => array( $this, 'permissionAdmin' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'extrachill-events/reject-event-source-submission',
			array(
				'label'               => __( 'Reject Event Source Submission', 'extrachill-events' ),
				'description'         => __( 'Reject a moderated event source.', 'extrachill-events' ),
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
					'required'   => array( 'success' ),
					'properties' => array( 'success' => array( 'type' => 'boolean' ) ),
				),
				'execute_callback'    => array( $this, 'executeReject' ),
				'permission_callback' => array( $this, 'permissionAdmin' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);
	}

	/** JSON Schema for qualification and generic preview responses. */
	private function qualificationOutputSchema(): array {
		$binding = array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy' => array( 'type' => 'string' ),
				'term_id'  => array( 'type' => array( 'integer', 'null' ) ),
				'name'     => array( 'type' => 'string' ),
			),
		);
		return array(
			'type'       => 'object',
			'required'   => array( 'success', 'qualified', 'canonical_events_url', 'source_identity_url', 'verdict', 'events_found', 'events_preview', 'extraction_method', 'source_kind', 'classification_confidence', 'entity_candidates', 'existing_coverage', 'warnings', 'recommended_route', 'recommended_binding', 'recurring_eligible' ),
			'properties' => array(
				'success'                   => array( 'type' => 'boolean' ),
				'qualified'                 => array( 'type' => 'boolean' ),
				'canonical_events_url'      => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'source_identity_url'       => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'verdict'                   => array( 'type' => 'string' ),
				'events_found'              => array( 'type' => 'integer' ),
				'events_preview'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'title'     => array( 'type' => 'string' ),
							'startDate' => array( 'type' => 'string' ),
							'startTime' => array( 'type' => 'string' ),
							'venue'     => array( 'type' => 'string' ),
							'ticketUrl' => array( 'type' => 'string' ),
						),
					),
				),
				'extraction_method'         => array( 'type' => 'string' ),
				'source_kind'               => array(
					'type' => 'string',
					'enum' => array( 'artist', 'venue', 'unknown' ),
				),
				'classification_confidence' => array(
					'type' => 'string',
					'enum' => array( 'low', 'medium', 'high' ),
				),
				'entity_candidates'         => array(
					'type'  => 'array',
					'items' => $binding,
				),
				'existing_coverage'         => array(
					'type'       => 'object',
					'required'   => array( 'covered', 'type' ),
					'properties' => array(
						'covered'   => array( 'type' => 'boolean' ),
						'type'      => array( 'type' => 'string' ),
						'flow_id'   => array( 'type' => 'integer' ),
						'flow_name' => array( 'type' => 'string' ),
					),
				),
				'warnings'                  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'recommended_route'         => array(
					'type' => 'string',
					'enum' => array( 'moderation', 'reject_duplicate', 'explicit_review' ),
				),
				'recommended_binding'       => $binding,
				'recurring_eligible'        => array( 'type' => 'boolean' ),
				'scope_evidence'            => array(
					'type'       => 'object',
					'required'   => array( 'bounded', 'type', 'host' ),
					'properties' => array(
						'bounded'          => array( 'type' => 'boolean' ),
						'type'             => array( 'type' => 'string' ),
						'host'             => array( 'type' => 'string' ),
						'official_host'    => array( 'type' => 'string' ),
						'event_page_shape' => array( 'type' => 'string' ),
					),
				),
				'detected_format'           => array( 'type' => 'string' ),
				'source_metadata'           => array( 'type' => 'object' ),
				'suggested_artist_name'     => array( 'type' => 'string' ),
				'suggested_artist_term_id'  => array( 'type' => array( 'integer', 'null' ) ),
			),
		);
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
				'execute_callback'    => array( $this, 'executeArtistPreview' ),
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
						'url'           => array(
							'type'   => 'string',
							'format' => 'uri',
						),
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
				'execute_callback'    => array( $this, 'executeArtistSubmit' ),
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
				'execute_callback'    => array( $this, 'executeArtistApprove' ),
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
		$is_cli = defined( 'WP_CLI' ) ? wp_validate_boolean( constant( 'WP_CLI' ) ) : false;
		if ( $is_cli ) {
			return true;
		}
		return is_user_logged_in();
	}

	public function permissionAdmin(): bool {
		$is_cli = defined( 'WP_CLI' ) ? wp_validate_boolean( constant( 'WP_CLI' ) ) : false;
		if ( $is_cli ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}

	// ────────────────────────────────────────────────────────────────────
	// preview-artist-url
	// ────────────────────────────────────────────────────────────────────

	/** Artist ability compatibility alias. */
	public function executeArtistPreview( array $input ) {
		$input['compat_artist'] = true;
		return $this->executePreview( $input );
	}

	/** Artist submission compatibility alias. */
	public function executeArtistSubmit( array $input ) {
		$input['compat_artist'] = true;
		return $this->executeSubmit( $input );
	}

	/** Artist approval compatibility alias. */
	public function executeArtistApprove( array $input ) {
		$input['source_kind']   = 'artist';
		$input['compat_artist'] = true;
		return $this->executeApprove( $input );
	}

	/** Testable admission seam; mutations must always call this fresh. */
	protected function qualifyForAdmission( string $url, bool $compat_artist = false ) {
		return $this->executeQualifyEventSource(
			array(
				'url'           => $url,
				'compat_artist' => $compat_artist,
			)
		);
	}

	/**
	 * Source-neutral qualification facade over the existing venue qualifier
	 * and Data Machine Events scraper handler.
	 */
	public function executeQualifyEventSource( array $input ) {
		$url = $this->normalizeInputUrl( (string) ( $input['url'] ?? '' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$qualification = ( new VenueQualificationAbilities() )->executeQualifyVenue(
			array(
				'url'             => $url,
				'persist_verdict' => true,
			)
		);
		if ( is_wp_error( $qualification ) ) {
			return $qualification;
		}

		$events_url = $this->normalizeInputUrl( (string) ( $qualification['events_url'] ?? $url ) );
		if ( is_wp_error( $events_url ) ) {
			$events_url = $url;
		}
		$identity_url = ArtistUrlSubmissionsTable::normalize_url( $events_url );
		$probe        = $this->probeUrl( $events_url );
		if ( is_wp_error( $probe ) ) {
			return $probe;
		}

		$coverage = array(
			'covered' => false,
			'type'    => 'none',
		);
		$flow     = ( new VenueExpansionRunner() )->lookupExistingFlow( $events_url );
		if ( $flow ) {
			$coverage = array(
				'covered'   => true,
				'type'      => 'universal_scraper_flow',
				'flow_id'   => (int) ( $flow['flow_id'] ?? 0 ),
				'flow_name' => (string) ( $flow['flow_name'] ?? '' ),
			);
		}
		foreach ( (array) ( $qualification['warnings'] ?? array() ) as $warning ) {
			if ( false !== stripos( (string) $warning, 'already covered' ) ) {
				$coverage = array(
					'covered' => true,
					'type'    => 'platform_pipeline',
				);
				break;
			}
		}

		$host_scope = $this->unsupportedHostScope( $events_url );
		if ( $host_scope && empty( $coverage['covered'] ) ) {
			$coverage = array(
				'covered' => false,
				'type'    => 'unsupported_' . $host_scope,
			);
		}
		$classification = $this->classifySource( $events_url, $probe, $qualification, ! empty( $input['compat_artist'] ) );
		$warnings       = array_values( array_unique( array_merge( (array) ( $qualification['warnings'] ?? array() ), $classification['warnings'] ) ) );
		if ( ! empty( $coverage['covered'] ) ) {
			$warnings[] = __( 'This source is already covered and should not create another recurring flow.', 'extrachill-events' );
		}

		$recurring_eligible = ! empty( $qualification['qualified'] )
			&& (int) $probe['events_found'] >= 2
			&& in_array( $classification['source_kind'], array( 'artist', 'venue' ), true )
			&& empty( $coverage['covered'] );
		$extraction_method  = '' !== (string) $probe['detected_format']
			? (string) $probe['detected_format']
			: (string) ( $qualification['method'] ?? '' );

		return array(
			'success'                   => (int) $probe['events_found'] > 0,
			'qualified'                 => ! empty( $qualification['qualified'] ),
			'canonical_events_url'      => $events_url,
			'source_identity_url'       => $identity_url,
			'verdict'                   => (string) ( $qualification['verdict'] ?? '' ),
			'events_found'              => (int) $probe['events_found'],
			'events_preview'            => $probe['events_preview'],
			'extraction_method'         => $extraction_method,
			'source_kind'               => $classification['source_kind'],
			'classification_confidence' => $classification['confidence'],
			'scope_evidence'            => $classification['scope_evidence'],
			'entity_candidates'         => $classification['candidates'],
			'existing_coverage'         => $coverage,
			'warnings'                  => array_values( array_unique( $warnings ) ),
			'recommended_route'         => $recurring_eligible ? 'moderation' : ( ! empty( $coverage['covered'] ) ? 'reject_duplicate' : 'explicit_review' ),
			'recommended_binding'       => $classification['binding'],
			'recurring_eligible'        => $recurring_eligible,
			'detected_format'           => (string) $probe['detected_format'],
			'source_metadata'           => $probe['source_metadata'],
			'suggested_artist_name'     => 'artist' === $classification['source_kind'] ? (string) ( $classification['binding']['name'] ?? '' ) : '',
			'suggested_artist_term_id'  => 'artist' === $classification['source_kind'] ? ( $classification['binding']['term_id'] ?? null ) : null,
		);
	}

	/** Validate an operational http(s) source URL without changing its query. */
	private function normalizeInputUrl( string $raw_url ) {
		$url = esc_url_raw( $raw_url );
		if ( '' === $url ) {
			return new \WP_Error( 'invalid_url', __( 'URL is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new \WP_Error( 'invalid_protocol', __( 'Only http and https URLs are supported.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new \WP_Error( 'invalid_url', __( 'URL could not be parsed.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return preg_replace( '/#.*$/', '', $url );
	}

	/** Classify bounded artist/venue identity from repeated extracted events. */
	protected function classifySource( string $url, array $probe, array $qualification = array(), bool $force_artist = false ): array {
		$venues     = array();
		$performers = array();
		foreach ( (array) ( $probe['raw_events'] ?? array() ) as $event ) {
			$venue = $event['venue'] ?? '';
			if ( is_array( $venue ) ) {
				$venue = $venue['name'] ?? '';
			}
			$performer = $event['performer'] ?? $event['artist'] ?? '';
			if ( is_array( $performer ) ) {
				$performer = $performer['name'] ?? '';
			}
			if ( is_string( $venue ) && '' !== trim( $venue ) ) {
				$venues[ strtolower( trim( $venue ) ) ] = trim( $venue );
			}
			if ( is_string( $performer ) && '' !== trim( $performer ) ) {
				$performers[ strtolower( trim( $performer ) ) ] = trim( $performer );
			}
		}

		$artist     = $this->suggestArtist( $url, $probe );
		$venue_name = 1 === count( $venues ) ? (string) reset( $venues ) : '';
		$candidates = array();
		if ( '' !== $artist['name'] ) {
			$candidates[] = array(
				'source_kind' => 'artist',
				'taxonomy'    => 'artist',
				'term_id'     => $artist['term_id'],
				'name'        => $artist['name'],
			);
		}
		$venue_term_id = $this->matchTerm( $venue_name, 'venue' );
		if ( '' !== $venue_name ) {
			$candidates[] = array(
				'source_kind' => 'venue',
				'taxonomy'    => 'venue',
				'term_id'     => $venue_term_id,
				'name'        => $venue_name,
			);
		}

		$kind              = 'unknown';
		$confidence        = 'low';
		$warnings          = array();
		$scope_evidence    = array(
			'bounded'          => false,
			'type'             => 'none',
			'host'             => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
			'event_page_shape' => (string) ( $qualification['fingerprint']['structured_data']['event_page_shape'] ?? '' ),
		);
		$binding           = array(
			'taxonomy' => '',
			'term_id'  => null,
			'name'     => '',
		);
		$unsupported_scope = $this->unsupportedHostScope( $url );
		if ( $unsupported_scope ) {
			$warnings[] = sprintf(
				/* translators: %s: unsupported source scope. */
				__( 'This %s host is not a bounded artist or venue source.', 'extrachill-events' ),
				$unsupported_scope
			);
		} elseif ( (int) $probe['events_found'] < 2 ) {
			$warnings[] = __( 'A one-off event page is not enough evidence for a recurring source.', 'extrachill-events' );
		} elseif ( 1 === count( $venues ) && count( $performers ) > 1 ) {
			$ownership = $this->venueOwnershipEvidence( $venue_term_id, $url );
			if ( $ownership['bounded'] ) {
				$kind           = 'venue';
				$confidence     = 'high';
				$scope_evidence = $ownership;
				$binding        = array(
					'taxonomy' => 'venue',
					'term_id'  => $venue_term_id,
					'name'     => $venue_name,
				);
			} else {
				$warnings[] = __( 'Repeated venue data does not prove that this host is the venue’s official source.', 'extrachill-events' );
			}
		} elseif ( 1 === count( $performers ) && count( $venues ) > 1 ) {
			$performer_name = (string) reset( $performers );
			$performer_id   = $this->matchTerm( $performer_name, 'artist' );
			$kind           = 'artist';
			$confidence     = null !== $performer_id ? 'high' : 'medium';
			$scope_evidence = array(
				'bounded' => true,
				'type'    => 'single_performer_multiple_venues',
				'host'    => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
			);
			$binding        = array(
				'taxonomy' => 'artist',
				'term_id'  => $performer_id,
				'name'     => $performer_name,
			);
		} elseif ( $force_artist && count( $venues ) > 1 && null !== $artist['term_id'] ) {
			$kind           = 'artist';
			$confidence     = 'high';
			$scope_evidence = array(
				'bounded' => true,
				'type'    => 'legacy_artist_identity_multiple_venues',
				'host'    => strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
			);
			$binding        = array(
				'taxonomy' => 'artist',
				'term_id'  => $artist['term_id'],
				'name'     => $artist['name'],
			);
		} else {
			$warnings[] = __( 'The extracted events do not establish one bounded artist or venue identity.', 'extrachill-events' );
		}

		return array(
			'source_kind'    => $kind,
			'confidence'     => $confidence,
			'candidates'     => $candidates,
			'warnings'       => $warnings,
			'binding'        => $binding,
			'scope_evidence' => $scope_evidence,
		);
	}

	/** Return the unsupported scope for a known platform/aggregator host. */
	private function unsupportedHostScope( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		foreach ( self::UNSUPPORTED_SOURCE_HOSTS as $domain => $scope ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $scope;
			}
		}
		return '';
	}

	/** Prove venue ownership by matching the source to its stored official website. */
	private function venueOwnershipEvidence( ?int $venue_term_id, string $url ): array {
		$source_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$website     = '';
		if ( $venue_term_id && function_exists( 'data_machine_events_get_venue_data' ) ) {
			$data    = data_machine_events_get_venue_data( $venue_term_id );
			$website = is_array( $data ) ? (string) ( $data['website'] ?? '' ) : '';
		} elseif ( $venue_term_id ) {
			$website = (string) get_term_meta( $venue_term_id, '_venue_website', true );
		}
		$website_host = strtolower( (string) wp_parse_url( $website, PHP_URL_HOST ) );
		$matches      = '' !== $source_host && '' !== $website_host
			&& ( $source_host === $website_host || str_ends_with( $source_host, '.' . $website_host ) || str_ends_with( $website_host, '.' . $source_host ) );
		return array(
			'bounded'       => $matches,
			'type'          => $matches ? 'official_venue_website' : 'unverified_venue_host',
			'host'          => $source_host,
			'official_host' => $website_host,
		);
	}

	/** Exact taxonomy candidate lookup without creating terms during preview. */
	private function matchTerm( string $name, string $taxonomy ): ?int {
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		}
		return $term instanceof \WP_Term ? (int) $term->term_id : null;
	}

	/**
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executePreview( array $input ) {
		$normalized = $this->normalizeInputUrl( (string) ( $input['url'] ?? '' ) );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$existing = ArtistUrlSubmissionsTable::find_tracked_by_url( $normalized );
		if ( $existing ) {
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

		$result = $this->qualifyForAdmission( $normalized, ! empty( $input['compat_artist'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$canonical_row = ArtistUrlSubmissionsTable::find_tracked_by_url( (string) $result['canonical_events_url'] );
		if ( $canonical_row ) {
			return new \WP_Error(
				'url_already_tracked',
				__( 'This event source is already being tracked.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'existing_status' => $canonical_row['status'],
					'submission_id'   => (int) $canonical_row['id'],
				)
			);
		}

		if ( 0 === $result['events_found'] ) {
			return new \WP_Error(
				'no_events_found',
				__( "We couldn't extract events from that page. Try the manual form below.", 'extrachill-events' ),
				array( 'status' => 422 )
			);
		}

		return $result;
	}

	// ────────────────────────────────────────────────────────────────────
	// submit-artist-url
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeSubmit( array $input ) {
		$normalized = $this->normalizeInputUrl( (string) ( $input['url'] ?? '' ) );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$existing = ArtistUrlSubmissionsTable::find_tracked_by_url( $normalized );
		if ( $existing ) {
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
			$contact_name  = (string) ( $user->display_name ? $user->display_name : $user->user_login );
		} else {
			// Issue #320 says "any logged-in user" — we hard-reject
			// anonymous to match that contract.
			return new \WP_Error(
				'login_required',
				__( 'You must be logged in to submit an event source.', 'extrachill-events' ),
				array( 'status' => 401 )
			);
		}

		// Re-qualify server-side regardless of what the preview saw.
		$qualification = $this->qualifyForAdmission( $normalized, ! empty( $input['compat_artist'] ) );

		if ( is_wp_error( $qualification ) ) {
			return $qualification;
		}
		if ( empty( $qualification['recurring_eligible'] ) ) {
			return new \WP_Error(
				'source_not_admissible',
				__( 'This page is not eligible for a recurring event import. Submit individual events with the manual form.', 'extrachill-events' ),
				array(
					'status'        => 422,
					'qualification' => $qualification,
				)
			);
		}

		if ( ! empty( $qualification['existing_coverage']['covered'] ) ) {
			return new \WP_Error(
				'source_already_covered',
				__( 'This event source is already covered by an existing import.', 'extrachill-events' ),
				array(
					'status'            => 409,
					'existing_coverage' => $qualification['existing_coverage'],
				)
			);
		}
		$canonical_url  = (string) ( $qualification['canonical_events_url'] ?? $normalized );
		$canonical_hash = ArtistUrlSubmissionsTable::url_hash( $canonical_url );
		$canonical_row  = ArtistUrlSubmissionsTable::find_tracked_by_url( $canonical_url );
		if ( $canonical_row ) {
			return new \WP_Error(
				'url_already_tracked',
				__( 'This event source is already being tracked.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'existing_status' => $canonical_row['status'],
					'submission_id'   => (int) $canonical_row['id'],
				)
			);
		}

		$binding     = (array) ( $qualification['recommended_binding'] ?? array() );
		$source_kind = (string) ( $qualification['source_kind'] ?? 'unknown' );
		$suggestion  = array(
			'name'    => 'artist' === $source_kind ? (string) ( $binding['name'] ?? '' ) : '',
			'term_id' => 'artist' === $source_kind ? ( $binding['term_id'] ?? null ) : null,
		);

		$submission_id = ArtistUrlSubmissionsTable::insert(
			array(
				'user_id'                  => $user_id,
				'contact_email'            => $contact_email,
				'contact_name'             => $contact_name,
				'url'                      => $normalized,
				'url_hash'                 => $canonical_hash,
				'canonical_url'            => $canonical_url,
				'source_kind'              => $source_kind,
				'entity_taxonomy'          => (string) ( $binding['taxonomy'] ?? '' ),
				'entity_term_id'           => isset( $binding['term_id'] ) ? (int) $binding['term_id'] : null,
				'entity_name'              => (string) ( $binding['name'] ?? '' ),
				'qualification_verdict'    => (string) ( $qualification['verdict'] ?? '' ),
				'qualification_data'       => wp_json_encode( $qualification ),
				'suggested_artist_name'    => $suggestion['name'],
				'suggested_artist_term_id' => $suggestion['term_id'],
				'detected_format'          => $qualification['extraction_method'],
				'events_found_count'       => (int) $qualification['events_found'],
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
				'detected_format'       => $qualification['extraction_method'],
				'events_found_count'    => (int) $qualification['events_found'],
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
			'events_found'  => (int) $qualification['events_found'],
			'source_kind'   => $source_kind,
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// approve-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeApprove( array $input ) {
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

		$is_legacy     = ! empty( $submission['compatibility_legacy'] );
		$compat_artist = ! empty( $input['compat_artist'] ) || $is_legacy;
		$fresh         = $this->qualifyForAdmission( (string) ( $submission['canonical_url'] ?? $submission['url'] ), $compat_artist );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}
		$legacy_admission = false;
		if ( empty( $fresh['recurring_eligible'] ) ) {
			$legacy_check = $this->validateLegacyArtistAdmission( $submission, $fresh, $input );
			if ( is_wp_error( $legacy_check ) ) {
				return $legacy_check;
			}
			$legacy_admission = true;
		}

		$source_kind = $this->resolveApprovalKind( (string) ( $submission['source_kind'] ?? 'artist' ), $input['source_kind'] ?? null );
		if ( is_wp_error( $source_kind ) ) {
			return $source_kind;
		}
		if ( $legacy_admission && 'artist' !== $source_kind ) {
			return new \WP_Error( 'legacy_artist_only', __( 'Legacy artist submissions can only be approved as artists.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( ! $legacy_admission && $source_kind !== (string) $fresh['source_kind'] ) {
			return new \WP_Error(
				'source_kind_changed',
				__( 'Fresh qualification does not match the selected source kind.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'qualification' => $fresh,
				)
			);
		}
		$duplicate = ArtistUrlSubmissionsTable::find_tracked_by_url( (string) $fresh['canonical_events_url'], $submission_id );
		if ( $duplicate ) {
			return new \WP_Error(
				'source_already_covered',
				__( 'Another moderation record already tracks this canonical event source.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'submission_id' => (int) $duplicate['id'],
				)
			);
		}

		$fresh_binding                     = (array) ( $fresh['recommended_binding'] ?? array() );
		$submission['canonical_url']       = (string) $fresh['canonical_events_url'];
		$submission['fresh_qualification'] = $fresh;

		if ( 'venue' === $source_kind ) {
			$fresh_venue_id = (int) ( $fresh_binding['term_id'] ?? 0 );
			$selected_id    = (int) ( $input['venue_term_id'] ?? $input['entity_term_id'] ?? $fresh_venue_id );
			if ( $fresh_venue_id <= 0 || $selected_id !== $fresh_venue_id ) {
				return new \WP_Error( 'venue_identity_changed', __( 'Venue approval must use the canonical venue proven by fresh qualification.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$pipeline_check = ( new VenueAddAbilities() )->validateCityPipeline( (int) ( $input['pipeline_id'] ?? 0 ) );
			if ( is_wp_error( $pipeline_check ) ) {
				return $pipeline_check;
			}
			$input['venue_term_id'] = $fresh_venue_id;
			$input['venue_name']    = (string) ( $fresh_binding['name'] ?? '' );
			return $this->approveVenueSubmission( $submission_id, $submission, $input );
		}

		$expected_artist_id   = $legacy_admission ? (int) ( $submission['entity_term_id'] ?? $submission['suggested_artist_term_id'] ?? 0 ) : (int) ( $fresh_binding['term_id'] ?? 0 );
		$expected_artist_name = $legacy_admission ? (string) ( $submission['entity_name'] ?? $submission['suggested_artist_name'] ?? '' ) : (string) ( $fresh_binding['name'] ?? '' );
		$artist_identity      = $this->validateArtistApprovalIdentity( $expected_artist_id, $expected_artist_name, $input, $is_legacy );
		if ( is_wp_error( $artist_identity ) ) {
			return $artist_identity;
		}
		$flow_ability = wp_get_ability( 'datamachine/create-flow' );
		if ( ! $flow_ability ) {
			return new \WP_Error( 'missing_ability', __( 'datamachine/create-flow ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		// Resolve or create only the identity that passed every admission gate.
		$artist_term_id = $this->resolveArtistTerm(
			$artist_identity['term_id'],
			$artist_identity['name'],
			$expected_artist_id
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
		// One pipeline is reused across every approved artist URL; each
		// approval creates a NEW FLOW on it carrying the per-artist
		// config (source_url + PRE_SELECTED artist term). There is no
		// per-artist pipeline and no per-artist pipeline-level AI prompt.
		$pipeline_id = $this->resolveSharedArtistImportPipeline();
		if ( $pipeline_id instanceof \WP_Error ) {
			return $pipeline_id;
		}

		// 2. Create the flow with universal_web_scraper handler and the
		// SelectionMode-driven taxonomy bindings on the shared pipeline.
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
			'source_url'       => (string) ( $submission['canonical_url'] ?? $submission['url'] ),
			'search'           => '',
			'exclude_keywords' => '',
		);

		$ai_message = sprintf(
			/* translators: %s: artist name */
			__( 'Process this event from %s\'s tour page. The artist is already known and pre-selected. Identify the venue, city, and festival (if any) at extraction time.', 'extrachill-events' ),
			$artist_name
		);

		$flow_input = array(
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
		);

		$flow_agent_id = $this->resolveSystemAgentContext()['agent_id'] ?? null;
		if ( $flow_agent_id > 0 ) {
			$flow_input['agent_id'] = $flow_agent_id;
		}

		$flow_result = $flow_ability->execute( $flow_input );

		if ( empty( $flow_result['success'] ) || empty( $flow_result['flow_id'] ) ) {
			$err = $flow_result['error'] ?? 'Unknown error';
			return new \WP_Error( 'flow_creation_failed', 'Failed to create flow: ' . $err, array( 'status' => 500 ) );
		}

		$flow_id = (int) $flow_result['flow_id'];

		// Verify and apply the supported flow settings through Data Machine's
		// revisioned owner contract.
		$configured = $this->configureFlowSteps(
			$pipeline_id,
			$flow_id,
			$import_handler_config,
			$upsert_handler_config,
			$ai_message
		);
		if ( $configured instanceof \WP_Error ) {
			return $configured;
		}

		// 3. Update the submission row: approved + linked shared pipeline/flow.
		ArtistUrlSubmissionsTable::update(
			$submission_id,
			array_merge(
				$this->freshQualificationPersistence( $submission ),
				array(
					'status'          => ArtistUrlSubmissionsTable::STATUS_APPROVED,
					'pipeline_id'     => $pipeline_id,
					'flow_id'         => $flow_id,
					'artist_term_id'  => $artist_term_id,
					'source_kind'     => 'artist',
					'entity_taxonomy' => 'artist',
					'entity_term_id'  => $artist_term_id,
					'entity_name'     => $artist_name,
					'reviewed_at'     => current_time( 'mysql', true ),
					'reviewed_by'     => get_current_user_id(),
				)
			)
		);

		// Bust the rank-engine points cache so the submitter's leaderboard
		// total reflects the newly approved submission (10 points per approval).
		delete_transient( 'user_points_' . (int) $submission['user_id'] );

		// 3a. Notify the submitter that the import was approved.
		$events_found_count = isset( $submission['events_found_count'] ) ? (int) $submission['events_found_count'] : 0;
		$approve_title      = sprintf(
			/* translators: 1: artist name, 2: number of events found */
			__( 'Your tour import for %1$s was approved — %2$d events added', 'extrachill-events' ),
			$artist_name,
			$events_found_count
		);
		$artist_archive_link = get_term_link( $artist_term_id, 'artist' );
		if ( is_wp_error( $artist_archive_link ) || '' === $artist_archive_link ) {
			$artist_archive_link = home_url();
		}

		$this->notifySubmitter(
			$submission,
			'artist_import_approved',
			$approve_title,
			$artist_archive_link,
			$artist_term_id
		);

		// 4. Trigger first scrape immediately.
		// datamachine/run-flow starts the job asynchronously, so a real
		// immediate import count is not available. Returning null keeps the
		// output schema (integer|null) clean; a boolean here caused
		// validation failures (#221). If a future run result ever exposes a
		// count, use it.
		$events_imported_immediately = null;
		$run_ability                 = wp_get_ability( 'datamachine/run-flow' );
		if ( $run_ability ) {
			$run_result = $run_ability->execute( array( 'flow_id' => $flow_id ) );
			if ( isset( $run_result['events_imported'] ) && is_numeric( $run_result['events_imported'] ) ) {
				$events_imported_immediately = (int) $run_result['events_imported'];
			}
		}

		return array(
			'success'                     => true,
			'pipeline_id'                 => $pipeline_id,
			'flow_id'                     => $flow_id,
			'source_kind'                 => 'artist',
			'artist_term_id'              => $artist_term_id,
			'events_imported_immediately' => $events_imported_immediately,
		);
	}

	/** Resolve approval dispatch without silently coercing unknown sources. */
	private function resolveApprovalKind( string $stored_kind, $explicit_kind = null ) {
		$kind = null !== $explicit_kind ? sanitize_key( (string) $explicit_kind ) : sanitize_key( $stored_kind );
		if ( in_array( $kind, array( 'artist', 'venue' ), true ) ) {
			return $kind;
		}
		return new \WP_Error(
			'explicit_source_kind_required',
			__( 'Select artist or venue and a concrete entity before approving this ambiguous source.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	/** Admit only previously accepted artist rows through the legacy exception. */
	private function validateLegacyArtistAdmission( array $submission, array $fresh, array $input ) {
		if ( empty( $submission['compatibility_legacy'] ) ) {
			return new \WP_Error(
				'source_no_longer_admissible',
				__( 'Fresh qualification no longer admits this source for a recurring import.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'qualification' => $fresh,
				)
			);
		}
		$explicit_id   = (int) ( $input['artist_term_id'] ?? $input['entity_term_id'] ?? 0 );
		$explicit_name = trim( (string) ( $input['artist_name'] ?? $input['entity_name'] ?? '' ) );
		$stored_name   = trim( (string) ( $submission['entity_name'] ?? $submission['suggested_artist_name'] ?? '' ) );
		$coverage_type = (string) ( $fresh['existing_coverage']['type'] ?? 'none' );
		$verdict       = (string) ( $fresh['verdict'] ?? '' );
		$unsafe        = array(
			QualifyVerdict::QUALIFIED_FOR_FLYER,
			QualifyVerdict::UNSUPPORTED_SOURCE,
			QualifyVerdict::RESERVATION_ONLY,
			QualifyVerdict::BOT_BLOCKED,
			QualifyVerdict::UNREACHABLE,
			QualifyVerdict::COVERED_ELSEWHERE,
		);

		if ( (int) ( $submission['events_found_count'] ?? 0 ) < 1 || '' === (string) ( $submission['detected_format'] ?? '' ) || '' === $stored_name ) {
			return new \WP_Error( 'legacy_evidence_missing', __( 'This legacy row does not contain the accepted artist-source evidence required for approval.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $explicit_id <= 0 && '' === $explicit_name ) {
			return new \WP_Error( 'legacy_artist_required', __( 'Select an explicit artist identity to approve this legacy submission.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( empty( $fresh['success'] ) || (int) ( $fresh['events_found'] ?? 0 ) < 1 || ! empty( $fresh['existing_coverage']['covered'] ) || str_starts_with( $coverage_type, 'unsupported_' ) || in_array( $verdict, $unsafe, true ) || str_contains( strtolower( (string) ( $fresh['extraction_method'] ?? '' ) ), 'vision' ) ) {
			return new \WP_Error(
				'legacy_source_unsafe',
				__( 'Fresh scraper safety checks do not admit this legacy artist source.', 'extrachill-events' ),
				array(
					'status'        => 409,
					'qualification' => $fresh,
				)
			);
		}
		if ( 'venue' === (string) ( $fresh['source_kind'] ?? 'unknown' ) ) {
			return new \WP_Error( 'legacy_source_kind_changed', __( 'Fresh qualification identifies this legacy source as a venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$fresh_name = 'artist' === (string) ( $fresh['source_kind'] ?? '' ) ? trim( (string) ( $fresh['recommended_binding']['name'] ?? '' ) ) : '';
		if ( '' !== $fresh_name && ! $this->artistNamesMatch( $stored_name, $fresh_name ) ) {
			return new \WP_Error( 'artist_identity_changed', __( 'Fresh qualification identifies a different artist than the legacy submission.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return true;
	}

	/** Validate moderator identity against the artist proven by admission. */
	private function validateArtistApprovalIdentity( int $expected_id, string $expected_name, array $input, bool $require_explicit ) {
		$explicit_id   = (int) ( $input['artist_term_id'] ?? $input['entity_term_id'] ?? 0 );
		$explicit_name = trim( (string) ( $input['artist_name'] ?? $input['entity_name'] ?? '' ) );
		$expected_name = trim( $expected_name );
		$expected_term = $expected_id > 0 ? get_term( $expected_id, 'artist' ) : null;
		if ( $expected_term instanceof \WP_Term && '' === $expected_name ) {
			$expected_name = (string) $expected_term->name;
		}
		if ( $require_explicit && $explicit_id <= 0 && '' === $explicit_name ) {
			return new \WP_Error( 'legacy_artist_required', __( 'Select an explicit artist identity to approve this legacy submission.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$explicit_term = null;
		if ( $explicit_id > 0 ) {
			$explicit_term = get_term( $explicit_id, 'artist' );
			if ( ! $explicit_term instanceof \WP_Term ) {
				return new \WP_Error( 'artist_not_found', __( 'The selected artist term does not exist.', 'extrachill-events' ), array( 'status' => 404 ) );
			}
		}
		if ( $expected_id > 0 && $explicit_id > 0 && $expected_id !== $explicit_id ) {
			return new \WP_Error( 'artist_identity_changed', __( 'Artist approval must use the artist proven by fresh qualification.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( '' !== $expected_name && $explicit_term instanceof \WP_Term && ! $this->artistNamesMatch( $expected_name, (string) $explicit_term->name ) ) {
			return new \WP_Error( 'artist_identity_changed', __( 'The selected artist term does not match the artist proven by qualification.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( '' !== $expected_name && '' !== $explicit_name && ! $this->artistNamesMatch( $expected_name, $explicit_name ) ) {
			return new \WP_Error( 'artist_identity_changed', __( 'The supplied artist name does not match the artist proven by qualification.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $explicit_term instanceof \WP_Term && '' !== $explicit_name && ! $this->artistNamesMatch( (string) $explicit_term->name, $explicit_name ) ) {
			return new \WP_Error( 'artist_identity_changed', __( 'The supplied artist name does not match the selected artist term.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$resolved_name = '' !== $expected_name ? $expected_name : $explicit_name;
		if ( '' === $resolved_name && $explicit_term instanceof \WP_Term ) {
			$resolved_name = (string) $explicit_term->name;
		}
		if ( $expected_id <= 0 && $explicit_id <= 0 && '' === $resolved_name ) {
			return new \WP_Error( 'artist_required', __( 'Qualification did not prove a concrete artist identity.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'term_id' => $explicit_id > 0 ? $explicit_id : $expected_id,
			'name'    => $resolved_name,
		);
	}

	/** Compare artist labels without allowing unrelated term substitution. */
	private function artistNamesMatch( string $left, string $right ): bool {
		return '' !== trim( $left ) && sanitize_title( $left ) === sanitize_title( $right );
	}

	/** Persist fresh qualification evidence only with a successful approval. */
	private function freshQualificationPersistence( array $submission ): array {
		$fresh   = (array) ( $submission['fresh_qualification'] ?? array() );
		$binding = (array) ( $fresh['recommended_binding'] ?? array() );
		return array(
			'canonical_url'         => (string) ( $fresh['canonical_events_url'] ?? $submission['canonical_url'] ?? $submission['url'] ?? '' ),
			'qualification_verdict' => (string) ( $fresh['verdict'] ?? '' ),
			'qualification_data'    => wp_json_encode( $fresh ),
			'detected_format'       => (string) ( $fresh['extraction_method'] ?? '' ),
			'events_found_count'    => (int) ( $fresh['events_found'] ?? 0 ),
			'entity_taxonomy'       => (string) ( $binding['taxonomy'] ?? '' ),
			'entity_term_id'        => isset( $binding['term_id'] ) ? (int) $binding['term_id'] : null,
			'entity_name'           => (string) ( $binding['name'] ?? '' ),
		);
	}

	/** Approve a venue source through the existing city/venue flow owner. */
	private function approveVenueSubmission( int $submission_id, array $submission, array $input ) {
		$pipeline_id = (int) ( $input['pipeline_id'] ?? 0 );
		if ( $pipeline_id <= 0 ) {
			return new \WP_Error( 'venue_pipeline_required', __( 'A city pipeline_id is required for venue approval.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$venue_term_id = (int) ( $input['venue_term_id'] ?? $input['entity_term_id'] ?? $submission['entity_term_id'] ?? 0 );
		$venue_name    = sanitize_text_field( (string) ( $input['venue_name'] ?? $input['entity_name'] ?? $submission['entity_name'] ?? '' ) );
		if ( $venue_term_id > 0 ) {
			$term = get_term( $venue_term_id, 'venue' );
			if ( $term instanceof \WP_Term ) {
				$venue_name = (string) $term->name;
			}
		}
		if ( '' === $venue_name ) {
			return new \WP_Error( 'venue_required', __( 'Select a venue term or provide a venue name.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$interval = isset( $input['schedule_interval'] ) ? sanitize_key( (string) $input['schedule_interval'] ) : 'daily';
		$ability  = wp_get_ability( 'extrachill/add-venue' );
		if ( ! $ability ) {
			return new \WP_Error( 'missing_ability', __( 'extrachill/add-venue ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		$result = $ability->execute(
			array(
				'pipeline_id'   => $pipeline_id,
				'name'          => $venue_name,
				'venue_term_id' => $venue_term_id,
				'url'           => (string) ( $submission['canonical_url'] ?? $submission['url'] ),
				'website'       => (string) $submission['url'],
				'interval'      => $interval,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$flow_id       = (int) ( $result['flow_id'] ?? 0 );
		$venue_term_id = (int) ( $result['venue_term_id'] ?? $venue_term_id );
		ArtistUrlSubmissionsTable::update(
			$submission_id,
			array_merge(
				$this->freshQualificationPersistence( $submission ),
				array(
					'status'          => ArtistUrlSubmissionsTable::STATUS_APPROVED,
					'pipeline_id'     => $pipeline_id,
					'flow_id'         => $flow_id,
					'source_kind'     => 'venue',
					'entity_taxonomy' => 'venue',
					'entity_term_id'  => $venue_term_id,
					'entity_name'     => $venue_name,
					'reviewed_at'     => current_time( 'mysql', true ),
					'reviewed_by'     => get_current_user_id(),
				)
			)
		);

		delete_transient( 'user_points_' . (int) $submission['user_id'] );
		$link = get_term_link( $venue_term_id, 'venue' );
		$this->notifySubmitter(
			$submission,
			'event_source_approved',
			/* translators: %s: venue name. */
			sprintf( __( 'Your event source for %s was approved', 'extrachill-events' ), $venue_name ),
			is_wp_error( $link ) ? home_url() : $link,
			$venue_term_id
		);

		$run_ability = wp_get_ability( 'datamachine/run-flow' );
		if ( $run_ability && $flow_id > 0 ) {
			$run_ability->execute( array( 'flow_id' => $flow_id ) );
		}

		return array(
			'success'       => true,
			'pipeline_id'   => $pipeline_id,
			'flow_id'       => $flow_id,
			'source_kind'   => 'venue',
			'venue_term_id' => $venue_term_id,
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// reject-artist-url-submission
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function executeReject( array $input ) {
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

		// Notify the submitter that the import was rejected.
		$reject_title = __( 'Your event source submission was not approved', 'extrachill-events' );
		if ( '' !== $reason ) {
			$reject_title = sprintf(
				/* translators: %s: rejection reason */
				__( 'Your event source submission was not approved: %s', 'extrachill-events' ),
				$reason
			);
		}

		$submit_page_link = home_url( '/submit/' );

		$this->notifySubmitter(
			$submission,
			'artist_import_rejected',
			$reject_title,
			$submit_page_link,
			$submission_id
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
				'raw_events'      => array(),
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
		$raw_events      = array();
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
			$raw_events[] = $event;
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
			'raw_events'      => $raw_events,
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

		$abilities     = new HandlerAbilities();
		$info          = $abilities->getHandler( self::SCRAPER_HANDLER_SLUG );
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
		$config = $abilities->applyDefaults( self::SCRAPER_HANDLER_SLUG, $config );

		/** @var \DataMachine\Core\Steps\Fetch\Handlers\FetchHandler $handler */
		$handler = new $handler_class();

		try {
			$results = $handler->get_fetch_data( 'preview', $config, null );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'scraper_failed', $e->getMessage(), array( 'status' => 502 ) );
		}

		return $results;
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
		if ( $exact instanceof \WP_Term ) {
			return (int) $exact->term_id;
		}

		// Fall back to slug match — handles minor casing/punctuation drift.
		$by_slug = get_term_by( 'slug', sanitize_title( $name ), 'artist' );
		if ( $by_slug instanceof \WP_Term ) {
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
			if ( $term instanceof \WP_Term ) {
				return (int) $term->term_id;
			}
		}

		$explicit_name = trim( $explicit_name );
		if ( '' !== $explicit_name ) {
			$existing = get_term_by( 'name', $explicit_name, 'artist' );
			if ( $existing instanceof \WP_Term ) {
				return (int) $existing->term_id;
			}

			$inserted = wp_insert_term( $explicit_name, 'artist' );
			if ( is_wp_error( $inserted ) ) {
				// Slug collision — try slug lookup as a recovery.
				$by_slug = get_term_by( 'slug', sanitize_title( $explicit_name ), 'artist' );
				if ( $by_slug instanceof \WP_Term ) {
					return (int) $by_slug->term_id;
				}
				return new \WP_Error( 'artist_create_failed', $inserted->get_error_message(), array( 'status' => 500 ) );
			}
			return (int) $inserted['term_id'];
		}

		if ( $suggested_id > 0 ) {
			$term = get_term( $suggested_id, 'artist' );
			if ( $term instanceof \WP_Term ) {
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
	 * Idempotent: Data Machine resolves the stable exact name through its
	 * public configuration contract. If no pipeline exists, a new one is
	 * created (event_import → ai → upsert) and given its artist-agnostic
	 * AI system prompt once.
	 *
	 * Per-artist identity never lives at the pipeline level — it lives on
	 * each flow (source_url + PRE_SELECTED artist term + per-artist
	 * user_message). That is what makes a single shared pipeline safe.
	 *
	 * @return int|\WP_Error Pipeline ID, or WP_Error on creation failure.
	 */
	private function resolveSharedArtistImportPipeline() {
		$system_context = $this->resolveSystemAgentContext();
		$agent_id       = $system_context['agent_id'] ?? null;
		$configuration  = $this->getPipelineConfiguration( array( 'pipeline_name' => self::SHARED_PIPELINE_NAME ) );

		if ( ! $configuration instanceof \WP_Error ) {
			$pipeline_id = (int) ( $configuration['pipeline']['pipeline_id'] ?? 0 );
			if ( $pipeline_id <= 0 ) {
				return new \WP_Error( 'datamachine_configuration_error', __( 'Data Machine returned an invalid pipeline configuration response.', 'extrachill-events' ), array( 'status' => 502 ) );
			}
			return $pipeline_id;
		}

		if ( 'pipeline_not_found' !== $configuration->get_error_code() ) {
			return $configuration;
		}

		// Create the shared pipeline scaffold (event_import → ai → upsert).
		$pipeline_ability = wp_get_ability( 'datamachine/create-pipeline' );
		if ( ! $pipeline_ability ) {
			return new \WP_Error( 'missing_ability', __( 'datamachine/create-pipeline ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		$pipeline_input = array(
			'pipeline_name' => self::SHARED_PIPELINE_NAME,
			'steps'         => array(
				array(
					'step_type' => 'event_import',
					'label'     => 'Event Import',
				),
				array(
					'step_type' => 'ai',
					'label'     => 'AI Agent',
				),
				array(
					'step_type' => 'upsert',
					'label'     => 'Upsert',
				),
			),
		);

		if ( $agent_id > 0 ) {
			$pipeline_input['agent_id'] = $agent_id;
		} else {
			do_action(
				'datamachine_log',
				'warning',
				'ArtistUrlImportAbilities: could not resolve system agent for shared pipeline',
				array( 'pipeline_name' => self::SHARED_PIPELINE_NAME )
			);
		}

		$pipeline_result = $pipeline_ability->execute( $pipeline_input );

		if ( empty( $pipeline_result['success'] ) || empty( $pipeline_result['pipeline_id'] ) ) {
			$err = $pipeline_result['error'] ?? 'Unknown error';
			return new \WP_Error( 'pipeline_creation_failed', 'Failed to create shared Artist Tour Import pipeline: ' . $err, array( 'status' => 500 ) );
		}

		$pipeline_id = (int) $pipeline_result['pipeline_id'];

		// Set the artist-agnostic AI system prompt ONCE at creation.
		$configured = $this->configureSharedPipelineAiStep( $pipeline_id );
		if ( $configured instanceof \WP_Error ) {
			return $configured;
		}

		return $pipeline_id;
	}

	/**
	 * Read normalized pipeline configuration through Data Machine's owner contract.
	 *
	 * @param array $selector Pipeline ID or exact-name selector.
	 * @return array|\WP_Error
	 */
	private function getPipelineConfiguration( array $selector ) {
		$ability = wp_get_ability( 'datamachine/get-pipeline-configuration' );
		if ( ! $ability ) {
			return $this->configurationDependencyUnavailable();
		}

		return $this->normalizeConfigurationResult( $ability->execute( $selector ) );
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
	 * @param int $pipeline_id Pipeline ID.
	 * @return array|\WP_Error
	 */
	private function configureSharedPipelineAiStep( int $pipeline_id ) {
		$configuration = $this->getPipelineConfiguration( array( 'pipeline_id' => $pipeline_id ) );
		if ( $configuration instanceof \WP_Error ) {
			return $configuration;
		}

		/**
		 * Filter the events feed name written into the AI step prompt.
		 *
		 * @param string $feed_name Default feed name (site name).
		 */
		$feed_name = (string) apply_filters( 'extrachill_events_artist_url_feed_name', get_bloginfo( 'name' ) );
		$prompt    = sprintf(
			'You process events from tour/events pages for the %s events feed. The artist for each flow is already pre-selected and named in the flow\'s user message — do not change the artist binding. Identify the venue, city/location, and festival (if any) for each event based on the available information. Skip WordPress categories and post tags entirely.',
			$feed_name
		);

		return $this->updateStepConfiguration(
			array(
				'target'            => 'pipeline',
				'pipeline_id'       => $pipeline_id,
				'step_type'         => 'ai',
				'expected_revision' => (string) ( $configuration['pipeline']['revision'] ?? '' ),
				'configuration'     => array( 'system_prompt' => $prompt ),
			)
		);
	}

	/**
	 * Apply supported flow-step settings through Data Machine's revisioned contract.
	 *
	 * @param int    $pipeline_id          Pipeline ID.
	 * @param int    $flow_id              Flow ID.
	 * @param array  $import_handler_config Import handler settings.
	 * @param array  $upsert_handler_config Upsert handler settings.
	 * @param string $ai_message            Per-artist AI message.
	 * @return true|\WP_Error
	 */
	private function configureFlowSteps( int $pipeline_id, int $flow_id, array $import_handler_config, array $upsert_handler_config, string $ai_message ) {
		$configuration = $this->getPipelineConfiguration( array( 'pipeline_id' => $pipeline_id ) );
		if ( $configuration instanceof \WP_Error ) {
			return $configuration;
		}

		$flow = null;
		foreach ( $configuration['flows'] ?? array() as $candidate ) {
			if ( (int) ( $candidate['flow_id'] ?? 0 ) === $flow_id ) {
				$flow = $candidate;
				break;
			}
		}
		if ( null === $flow ) {
			return new \WP_Error( 'flow_not_found', __( 'Flow not found in Data Machine pipeline configuration.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$revision = (string) ( $flow['revision'] ?? '' );
		$updates  = array(
			'event_import' => array(
				'handler_slug'   => self::SCRAPER_HANDLER_SLUG,
				'handler_config' => $import_handler_config,
			),
			'upsert'       => array(
				'handler_slug'   => 'upsert_event',
				'handler_config' => $upsert_handler_config,
			),
			'ai'           => array( 'user_message' => $ai_message ),
		);

		foreach ( $updates as $step_type => $patch ) {
			$result = $this->updateStepConfiguration(
				array(
					'target'            => 'flow',
					'flow_id'           => $flow_id,
					'step_type'         => $step_type,
					'expected_revision' => $revision,
					'configuration'     => $patch,
				)
			);
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			$revision = (string) ( $result['revision'] ?? '' );
		}

		return true;
	}

	/**
	 * Update one step through Data Machine's owner contract.
	 *
	 * @param array $input Owner-contract input.
	 * @return array|\WP_Error
	 */
	private function updateStepConfiguration( array $input ) {
		$ability = wp_get_ability( 'datamachine/update-step-configuration' );
		if ( ! $ability ) {
			return $this->configurationDependencyUnavailable();
		}

		return $this->normalizeConfigurationResult( $ability->execute( $input ) );
	}

	/**
	 * Preserve Data Machine error codes, messages, and statuses for callers.
	 *
	 * @param mixed $result Ability result.
	 * @return array|\WP_Error
	 */
	private function normalizeConfigurationResult( $result ) {
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( ! is_array( $result ) ) {
			return new \WP_Error( 'datamachine_configuration_error', __( 'Data Machine returned an invalid configuration response.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				(string) ( $result['error_code'] ?? 'datamachine_configuration_error' ),
				(string) ( $result['error'] ?? __( 'Data Machine configuration request failed.', 'extrachill-events' ) ),
				array( 'status' => (int) ( $result['status'] ?? 500 ) )
			);
		}

		return $result;
	}

	/**
	 * Return an explicit dependency error instead of bypassing owner storage.
	 *
	 * @return \WP_Error
	 */
	private function configurationDependencyUnavailable(): \WP_Error {
		return new \WP_Error(
			'datamachine_configuration_unavailable',
			__( 'Data Machine pipeline configuration abilities are not available.', 'extrachill-events' ),
			array( 'status' => 503 )
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
			__( '[%1$s] New Event Source Submission: %2$s', 'extrachill-events' ),
			$site_name,
			$status_label
		);

		$preheader = sprintf(
			/* translators: %s: submitter name. */
			__( 'Event source submission from %s.', 'extrachill-events' ),
			(string) ( $data['contact_name'] ?? '' )
		);

		$body_html  = '<p>' . esc_html__( 'A new event source submission has been received:', 'extrachill-events' ) . '</p>';
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

		$body_html .= '<p><a href="' . esc_url( $queue_url ) . '">' . esc_html__( 'Review event source submissions', 'extrachill-events' ) . '</a></p>';

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
						'cta_label' => __( 'Review event source submissions', 'extrachill-events' ),
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
			__( '[%1$s] Event Source Submission Received: %2$s', 'extrachill-events' ),
			$site_name,
			$url
		);

		$preheader = sprintf(
			/* translators: %s: submitted URL. */
			__( 'We received your event source submission for %s.', 'extrachill-events' ),
			$url
		);

		$body_html  = '<p>' . esc_html__( 'Thanks for submitting a recurring event source!', 'extrachill-events' ) . '</p>';
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

	/**
	 * Fire a bell notification for the submitter after approval/rejection.
	 *
	 * Consumes the network notification substrate from extrachill-users.
	 * Failures are logged and never allowed to break the approval/rejection
	 * transaction.
	 *
	 * @param array  $submission Submission row from ArtistUrlSubmissionsTable.
	 * @param string $type       Notification type (sanitize_key'd by substrate).
	 * @param string $title      Human-readable title.
	 * @param string $link       Target URL for the notification.
	 * @param int    $item_id    Optional related object ID.
	 */
	private function notifySubmitter( array $submission, string $type, string $title, string $link, int $item_id = 0 ): void {
		if ( ! function_exists( 'ec_users_notify_with_receipts' ) ) {
			return;
		}

		$submitter_id  = isset( $submission['user_id'] ) ? (int) $submission['user_id'] : 0;
		$submission_id = isset( $submission['id'] ) ? (int) $submission['id'] : 0;
		if ( $submitter_id <= 0 || $submission_id <= 0 ) {
			return;
		}

		$actor_id = get_current_user_id();
		if ( $actor_id <= 0 ) {
			$system_context = $this->resolveSystemAgentContext();
			$actor_id       = $system_context['user_id'] ?? 0;
		}

		if ( $actor_id <= 0 || ! get_userdata( $actor_id ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'ArtistUrlImportAbilities: no valid actor for submitter notification',
				array(
					'type'    => $type,
					'user_id' => $submitter_id,
				)
			);
			return;
		}

		$data = array(
			'actor_id'        => $actor_id,
			'type'            => $type,
			'title'           => $title,
			'link'            => $link,
			'producer'        => self::NOTIFICATION_PRODUCER,
			'idempotency_key' => 'submission:' . $submission_id . ':' . $type,
		);
		if ( $item_id > 0 ) {
			$data['item_id'] = $item_id;
		}

		try {
			$receipt = ec_users_notify_with_receipts( array( $submitter_id ), $data );
		} catch ( \Throwable $e ) {
			do_action(
				'datamachine_log',
				'warning',
				'ArtistUrlImportAbilities: submitter notification threw an exception',
				array(
					'type'     => $type,
					'user_id'  => $submitter_id,
					'actor_id' => $actor_id,
					'error'    => $e->getMessage(),
				)
			);
			return;
		}

		if ( 0 < $receipt['failed'] ) {
			do_action(
				'datamachine_log',
				'warning',
				'ArtistUrlImportAbilities: submitter notification failed',
				array(
					'type'     => $type,
					'user_id'  => $submitter_id,
					'actor_id' => $actor_id,
					'receipt'  => $receipt,
				)
			);
		}
	}
}
