<?php
/**
 * Venue calendar feed abilities.
 *
 * Bind, read, and remove the public calendar feed attached to one venue.
 *
 * Every operation is authorized with VenueAuthorization::ACTION_ACCESS_VENUE,
 * so an active venue member may manage the binding. Ownership is deliberately
 * not required: binding a public feed is routine calendar upkeep, not a
 * structural change to the venue like membership or finances.
 *
 * These are plain authenticated JSON operations, so they are exposed through
 * core's ability REST runner (`show_in_rest`) rather than a bespoke
 * `extrachill/v1` route. A route is only warranted by a transport requirement —
 * anonymous access, file upload, HTTP status/header semantics, Turnstile,
 * cross-site affinity, or a non-JSON wire format. None apply here.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\VenueAuthorization;
use ExtraChillEvents\Core\VenueCalendarFeed;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueCalendarFeedAbilities {

	private static bool $registered = false;

	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();

		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the three calendar feed binding contracts. */
	public function register(): void {
		$venue_property = array(
			'venue_term_id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Events-site venue term ID.', 'extrachill-events' ),
			),
		);

		$this->register_ability(
			'extrachill/get-venue-calendar-feed',
			__( 'Get Venue Calendar Feed', 'extrachill-events' ),
			__( 'Read the calendar feed binding and sync state for one authorized venue.', 'extrachill-events' ),
			$venue_property,
			array( 'venue_term_id' ),
			array( $this, 'get_feed' ),
			true,
			true,
			false
		);

		$this->register_ability(
			'extrachill/set-venue-calendar-feed',
			__( 'Set Venue Calendar Feed', 'extrachill-events' ),
			__( 'Bind a public calendar feed address to one authorized venue.', 'extrachill-events' ),
			array_merge(
				$venue_property,
				array(
					'feed_url' => array(
						'type'        => 'string',
						'description' => __( 'Public ICS/iCal feed address. webcal:// is accepted and normalized to https://.', 'extrachill-events' ),
					),
				)
			),
			array( 'venue_term_id', 'feed_url' ),
			array( $this, 'set_feed' ),
			false,
			true,
			false
		);

		$this->register_ability(
			'extrachill/remove-venue-calendar-feed',
			__( 'Remove Venue Calendar Feed', 'extrachill-events' ),
			__( 'Remove the calendar feed binding from one authorized venue. Imported events are retained.', 'extrachill-events' ),
			$venue_property,
			array( 'venue_term_id' ),
			array( $this, 'remove_feed' ),
			false,
			true,
			true
		);
	}

	/**
	 * Shared gate: an active member of this venue may manage its feed.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function can_access_venue( array $input ) {
		return $this->authorization->authorize(
			get_current_user_id(),
			absint( $input['venue_term_id'] ?? 0 ),
			VenueAuthorization::ACTION_ACCESS_VENUE
		);
	}

	public function get_feed( array $input ) {
		return VenueCalendarFeed::get( absint( $input['venue_term_id'] ?? 0 ) );
	}

	/**
	 * Validate, verify, and store a feed binding.
	 *
	 * The feed is fetched and parsed once, synchronously, before it is stored.
	 * Binding an unreachable or non-calendar URL and only discovering it on the
	 * next scheduled run is a bad operator experience; failing here lets the
	 * console report the event count found as immediate confirmation that the
	 * right calendar was pasted.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function set_feed( array $input ) {
		$venue_term_id = absint( $input['venue_term_id'] ?? 0 );

		$url = VenueCalendarFeed::normalize_url( (string) ( $input['feed_url'] ?? '' ) );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$verified = $this->verify_feed( $url );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$bound = VenueCalendarFeed::bind( $venue_term_id, $url );
		if ( is_wp_error( $bound ) ) {
			return $bound;
		}

		$bound['event_count'] = $verified;

		return $bound;
	}

	public function remove_feed( array $input ) {
		return VenueCalendarFeed::unbind( absint( $input['venue_term_id'] ?? 0 ) );
	}

	/**
	 * Fetch a candidate feed once and confirm it parses as a calendar.
	 *
	 * @param string $url Normalized feed URL.
	 * @return int|\WP_Error Event count found, or a specific failure.
	 */
	private function verify_feed( string $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'ExtraChill Events calendar feed verification',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'venue_calendar_feed_unreachable',
				__( 'That calendar address could not be reached.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'venue_calendar_feed_http_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'That calendar address returned HTTP %d. If the calendar is private, make it public first.', 'extrachill-events' ),
					$code
				),
				array( 'status' => 400 )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > VenueCalendarFeed::MAX_FEED_BYTES ) {
			return new \WP_Error(
				'venue_calendar_feed_too_large',
				__( 'That calendar feed is too large to import.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( '\\DataMachineEvents\\Steps\\EventImport\\Handlers\\WebScraper\\Extractors\\IcsExtractor' ) ) {
			return new \WP_Error(
				'venue_calendar_feed_extractor_missing',
				__( 'Calendar import is unavailable on this site.', 'extrachill-events' ),
				array( 'status' => 503 )
			);
		}

		$extractor = new \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor();

		if ( ! $extractor->canExtract( $body ) ) {
			return new \WP_Error(
				'venue_calendar_feed_not_calendar',
				__( 'That address did not return a calendar feed. Use the public ICS address, not the calendar web page.', 'extrachill-events' ),
				array( 'status' => 400 )
			);
		}

		return count( $extractor->extract( $body, $url ) );
	}

	/** Register one operation with shared authorization and metadata. */
	private function register_ability( string $name, string $label, string $description, array $properties, array $required, callable $execute, bool $is_readonly, bool $idempotent, bool $destructive ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => $this->feed_schema(),
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'idempotent'  => $idempotent,
						'destructive' => $destructive,
					),
				),
			)
		);
	}

	/** Shared public feed binding result shape. */
	private function feed_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'venue_term_id' => array( 'type' => 'integer' ),
				'bound'         => array( 'type' => 'boolean' ),
				'feed_url'      => array( 'type' => 'string' ),
				'status'        => array(
					'type' => 'string',
					'enum' => array_merge( VenueCalendarFeed::statuses(), array( '' ) ),
				),
				'last_synced'   => array( 'type' => 'string' ),
				'last_error'    => array( 'type' => 'string' ),
				'event_count'   => array( 'type' => 'integer' ),
			),
		);
	}
}
