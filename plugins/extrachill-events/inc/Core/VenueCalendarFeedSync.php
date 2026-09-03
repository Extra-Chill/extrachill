<?php
/**
 * Venue calendar feed sync runner.
 *
 * Fetches each bound venue calendar feed on a schedule and upserts its events,
 * so a venue's calendar stays current without anyone retyping it.
 *
 * Scheduling is plain Action Scheduler. This is a cron job that fetches a URL,
 * parses ICS, and calls an existing upsert — deliberately not a Data Machine
 * flow, which is the pipeline/AI-processing substrate and would be ceremony
 * here.
 *
 * **Three scope rules keep this path separated from every other ingestion
 * path.** They are the load-bearing correctness property of the feature, not
 * defensive extras:
 *
 * 1. Only ever mutate events whose `_datamachine_event_source` is
 *    `venue_calendar_feed` AND whose stored feed venue matches the venue being
 *    synced. A feed sync must never adopt, overwrite, or unpublish a scraped
 *    event, a publicly submitted event, or another venue's event.
 * 2. Never resolve the venue by name. `Venue_Taxonomy::find_or_create_venue()`
 *    runs a name-matching cascade that exists for untrusted string input; here
 *    the venue is known with certainty from the binding, and running the
 *    cascade risks minting a near-duplicate term for a venue that already
 *    exists.
 * 3. Never touch `pending` events. Public submissions land pending for human
 *    review; a feed sync silently publishing or overwriting one would bypass
 *    that review.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VenueCalendarFeedSync {

	public const SCHEDULE_HOOK = 'extrachill_events_sync_venue_calendar_feeds';
	public const VENUE_HOOK    = 'extrachill_events_sync_venue_calendar_feed';
	public const GROUP         = 'extrachill-events';

	/** How often the sweep runs. */
	public const INTERVAL_SECONDS = 3600;

	/**
	 * Seconds between individual venue syncs within one sweep.
	 *
	 * Feeds are staggered rather than fetched in one tick so a growing venue
	 * count cannot turn the sweep into a burst of simultaneous outbound
	 * requests.
	 */
	public const STAGGER_SECONDS = 30;

	/** Meta recording which venue binding produced an event. */
	public const META_FEED_VENUE = '_venue_calendar_feed_venue_id';

	/** Consecutive failure counter, stored per venue. */
	public const META_FAILURES = '_venue_calendar_feed_failures';

	/** Upper bound on feed-owned events examined for cancellation per run. */
	public const MAX_TRACKED_EVENTS = 500;

	/** Register the recurring sweep and the per-venue worker. */
	public static function register(): void {
		add_action( self::SCHEDULE_HOOK, array( self::class, 'run_sweep' ) );
		add_action( self::VENUE_HOOK, array( self::class, 'sync_venue' ), 10, 1 );
		add_action( 'init', array( self::class, 'ensure_scheduled' ) );
	}

	/** Ensure the recurring sweep exists exactly once. */
	public static function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::SCHEDULE_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::INTERVAL_SECONDS,
			self::INTERVAL_SECONDS,
			self::SCHEDULE_HOOK,
			array(),
			self::GROUP
		);
	}

	/**
	 * Queue one staggered sync per actively bound venue.
	 *
	 * Venues parked in `error` are skipped. A dead feed must not be polled
	 * forever; an operator retries from the console, which clears the status.
	 */
	public static function run_sweep(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$venue_ids = get_terms(
			array(
				'taxonomy'   => 'venue',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => VenueCalendarFeed::META_STATUS,
						'value'   => VenueCalendarFeed::STATUS_ACTIVE,
						'compare' => '=',
					),
				),
			)
		);

		if ( is_wp_error( $venue_ids ) || empty( $venue_ids ) ) {
			return;
		}

		$offset = 0;
		foreach ( $venue_ids as $venue_id ) {
			as_schedule_single_action(
				time() + $offset,
				self::VENUE_HOOK,
				array( (int) $venue_id ),
				self::GROUP
			);
			$offset += self::STAGGER_SECONDS;
		}
	}

	/**
	 * Sync one venue's bound feed.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return array Sync outcome counts.
	 */
	public static function sync_venue( $venue_term_id ): array {
		$venue_term_id = absint( $venue_term_id );
		$binding       = VenueCalendarFeed::get( $venue_term_id );

		$result = array(
			'venue_term_id' => $venue_term_id,
			'created'       => 0,
			'updated'       => 0,
			'unchanged'     => 0,
			'cancelled'     => 0,
			'skipped'       => 0,
		);

		if ( empty( $binding['bound'] ) || VenueCalendarFeed::STATUS_ACTIVE !== $binding['status'] ) {
			return $result;
		}

		$events = self::fetch_feed_events( $binding['feed_url'] );

		if ( is_wp_error( $events ) ) {
			self::record_failure( $venue_term_id, $events->get_error_message() );
			return $result;
		}

		$term = get_term( $venue_term_id, 'venue' );
		if ( ! $term || is_wp_error( $term ) ) {
			self::record_failure( $venue_term_id, 'Venue no longer exists.' );
			return $result;
		}

		$seen = array();

		foreach ( $events as $event ) {
			$identity = (string) ( $event['occurrenceIdentity'] ?? '' );

			// No stable feed-authored identity means no safe way to converge on
			// update. Importing anyway would create a duplicate on every run.
			if ( '' === $identity ) {
				++$result['skipped'];
				continue;
			}

			$outcome = self::upsert_event( $venue_term_id, $term->name, $event, $identity );

			if ( is_wp_error( $outcome ) ) {
				++$result['skipped'];
				continue;
			}

			$seen[] = $identity;

			if ( isset( $result[ $outcome ] ) ) {
				++$result[ $outcome ];
			}
		}

		$result['cancelled'] = self::cancel_absent_events( $venue_term_id, $seen );

		VenueCalendarFeed::record_success( $venue_term_id );
		delete_term_meta( $venue_term_id, self::META_FAILURES );

		return $result;
	}

	/**
	 * Fetch and parse one feed.
	 *
	 * @param string $url Feed URL.
	 * @return array|\WP_Error Normalized events, or a fetch/parse failure.
	 */
	private static function fetch_feed_events( string $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'ExtraChill Events calendar feed sync',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'venue_calendar_feed_unreachable', 'The calendar feed could not be reached.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'venue_calendar_feed_http_error',
				sprintf( 'The calendar feed returned HTTP %d.', $code )
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > VenueCalendarFeed::MAX_FEED_BYTES ) {
			return new \WP_Error( 'venue_calendar_feed_too_large', 'The calendar feed is too large to import.' );
		}

		if ( ! class_exists( '\\DataMachineEvents\\Steps\\EventImport\\Handlers\\WebScraper\\Extractors\\IcsExtractor' ) ) {
			return new \WP_Error( 'venue_calendar_feed_extractor_missing', 'Calendar import is unavailable.' );
		}

		$extractor = new \DataMachineEvents\Steps\EventImport\Handlers\WebScraper\Extractors\IcsExtractor();

		if ( ! $extractor->canExtract( $body ) ) {
			return new \WP_Error( 'venue_calendar_feed_not_calendar', 'The address no longer returns a calendar feed.' );
		}

		return $extractor->extract( $body, $url );
	}

	/**
	 * Upsert one feed event against the bound venue.
	 *
	 * Venue geography is supplied from the bound term rather than from the feed
	 * so the upsert resolves to this venue exactly. The feed's own LOCATION is
	 * intentionally ignored — the binding is authoritative about which venue
	 * these events belong to.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $venue_name    Canonical venue term name.
	 * @param array  $event         Normalized event from IcsExtractor.
	 * @param string $identity      Stable occurrence identity.
	 * @return string|\WP_Error created|updated|unchanged, or failure.
	 */
	private static function upsert_event( int $venue_term_id, string $venue_name, array $event, string $identity ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'data-machine-events/upsert-event' ) : null;

		if ( ! $ability ) {
			return new \WP_Error( 'venue_calendar_feed_upsert_unavailable', 'Event upsert is unavailable.' );
		}

		$payload = array(
			'title'         => (string) ( $event['title'] ?? '' ),
			'description'   => (string) ( $event['description'] ?? '' ),
			'startDate'     => (string) ( $event['startDate'] ?? '' ),
			'startTime'     => (string) ( $event['startTime'] ?? '' ),
			'endDate'       => (string) ( $event['endDate'] ?? '' ),
			'endTime'       => (string) ( $event['endTime'] ?? '' ),
			'ticketUrl'     => (string) ( $event['ticketUrl'] ?? '' ),
			'venue'         => $venue_name,
			'venueAddress'  => (string) get_term_meta( $venue_term_id, '_venue_address', true ),
			'venueCity'     => (string) get_term_meta( $venue_term_id, '_venue_city', true ),
			'venueState'    => (string) get_term_meta( $venue_term_id, '_venue_state', true ),
			'venueZip'      => (string) get_term_meta( $venue_term_id, '_venue_zip', true ),
			'venueCountry'  => (string) get_term_meta( $venue_term_id, '_venue_country', true ),
			'venueTimezone' => (string) get_term_meta( $venue_term_id, '_venue_timezone', true ),
		);

		if ( '' === $payload['title'] || '' === $payload['startDate'] ) {
			return new \WP_Error( 'venue_calendar_feed_event_incomplete', 'Event is missing a title or start date.' );
		}

		$existing = self::find_owned_event( $venue_term_id, $identity );

		$input = array(
			'source'      => VenueCalendarFeed::SOURCE_NAME,
			'source_id'   => $identity,
			'post_status' => 'publish',
			'event'       => array_filter(
				$payload,
				static function ( $value ) {
					return '' !== $value;
				}
			),
		);

		if ( $existing ) {
			$input['event_id'] = $existing;
		}

		$outcome = $ability->execute( $input );

		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		$event_id = absint( $outcome['event_id'] ?? 0 );

		if ( $event_id ) {
			update_post_meta( $event_id, self::META_FEED_VENUE, $venue_term_id );
		}

		$action = (string) ( $outcome['action'] ?? '' );

		if ( 'created' === $action ) {
			return 'created';
		}

		return 'no_change' === $action ? 'unchanged' : 'updated';
	}

	/**
	 * Find an event this venue's feed already owns for a given identity.
	 *
	 * Scoped by source name, feed venue, and identity together. This is scope
	 * rule 1: without all three, a sync could adopt an event created by a
	 * scraper or by another venue's feed.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $identity      Stable occurrence identity.
	 * @return int Post ID, or 0.
	 */
	private static function find_owned_event( int $venue_term_id, string $identity ): int {
		$posts = get_posts( self::owned_event_query( $venue_term_id, $identity ) );

		if ( empty( $posts ) ) {
			return 0;
		}

		$found = reset( $posts );

		// owned_event_query() requests fields => ids; narrowed explicitly
		// because get_posts() is typed as int|WP_Post either way.
		return (int) ( is_object( $found ) ? $found->ID : $found );
	}

	/**
	 * Build the scoped lookup query for one feed-owned event.
	 *
	 * Separated from the query execution so the scope constraints can be
	 * asserted directly. `pending` is deliberately absent from the status
	 * list — that is scope rule 3.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $identity      Stable occurrence identity.
	 * @return array get_posts() arguments.
	 */
	private static function owned_event_query( int $venue_term_id, string $identity ): array {
		return array(
			'post_type'        => 'data_machine_events',
			'post_status'      => array( 'publish', 'future', 'draft', 'private' ),
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => '_datamachine_event_source',
					'value' => VenueCalendarFeed::SOURCE_NAME,
				),
				array(
					'key'   => '_datamachine_event_source_id',
					'value' => $identity,
				),
				array(
					'key'   => self::META_FEED_VENUE,
					'value' => $venue_term_id,
				),
			),
		);
	}

	/**
	 * Build the scoped query for every event this venue's feed owns.
	 *
	 * Only published events are cancellation candidates; drafts and pending
	 * posts are deliberately out of reach.
	 *
	 * @param int $venue_term_id Venue term ID.
	 * @return array get_posts() arguments.
	 */
	private static function owned_events_query( int $venue_term_id ): array {
		return array(
			'post_type'        => 'data_machine_events',
			'post_status'      => 'publish',
			// Bounded by construction: this queries only events this one
			// venue's feed created, and a venue calendar holding more than
			// this many future shows is not a real case. A hard cap is
			// preferred over unbounded pagination so a runaway feed cannot
			// stall the scheduled sweep.
			'numberposts'      => self::MAX_TRACKED_EVENTS,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => array(
				'relation' => 'AND',
				array(
					'key'   => '_datamachine_event_source',
					'value' => VenueCalendarFeed::SOURCE_NAME,
				),
				array(
					'key'   => self::META_FEED_VENUE,
					'value' => $venue_term_id,
				),
			),
		);
	}

	/**
	 * Mark events that have disappeared from the feed as cancelled.
	 *
	 * Never hard-deletes. An event removed upstream is marked cancelled and
	 * left in place, because it may already have been shared, linked, or
	 * attended. Only future events are considered — a past event dropping out
	 * of a rolling feed window is normal and must not be retroactively
	 * cancelled.
	 *
	 * Scoped identically to find_owned_event(): only this venue's own feed
	 * events are eligible.
	 *
	 * @param int   $venue_term_id Venue term ID.
	 * @param array $seen          Identities present in this sync.
	 * @return int Count cancelled.
	 */
	private static function cancel_absent_events( int $venue_term_id, array $seen ): int {
		$owned = get_posts( self::owned_events_query( $venue_term_id ) );

		if ( empty( $owned ) ) {
			return 0;
		}

		$cancelled = 0;

		// Compared as MySQL datetime strings in site time, matching
		// datamachine_get_event_timing(), which is the canonical upcoming/past
		// test for this table. Converting to timestamps here would silently
		// disagree with it by the site's UTC offset.
		$now = current_time( 'mysql' );

		foreach ( $owned as $owned_post ) {
			// owned_events_query() requests fields => ids, so these are IDs.
			// Narrowed explicitly because get_posts() is typed as returning
			// int|WP_Post regardless of the fields argument.
			$post_id = (int) ( is_object( $owned_post ) ? $owned_post->ID : $owned_post );

			$identity = (string) get_post_meta( $post_id, '_datamachine_event_source_id', true );

			if ( '' === $identity || in_array( $identity, $seen, true ) ) {
				continue;
			}

			if ( ! self::starts_after( self::event_start( $post_id ), $now ) ) {
				continue;
			}

			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				)
			);
			++$cancelled;
		}

		return $cancelled;
	}

	/**
	 * Whether an event starts in the future.
	 *
	 * @param int $post_id Event post ID.
	 * @return string MySQL datetime string, or empty when unknown.
	 */
	private static function event_start( int $post_id ): string {
		if ( ! function_exists( 'datamachine_get_event_dates' ) ) {
			return '';
		}

		$dates = datamachine_get_event_dates( $post_id );

		return is_object( $dates ) ? (string) ( $dates->start_datetime ?? '' ) : '';
	}

	/**
	 * Whether an event starts after the given moment.
	 *
	 * Compared as MySQL datetime strings in site time, matching
	 * datamachine_get_event_timing(), which is the canonical upcoming/past
	 * test for this table. Converting to timestamps would silently disagree
	 * with it by the site's UTC offset.
	 *
	 * An unknown start is never "after" — an event whose date cannot be
	 * established is not a cancellation candidate.
	 *
	 * @param string $start Event start as a MySQL datetime string.
	 * @param string $now   Current site time as a MySQL datetime string.
	 * @return bool
	 */
	private static function starts_after( string $start, string $now ): bool {
		if ( '' === $start ) {
			return false;
		}

		return $start > $now;
	}

	/**
	 * Record a sync failure and park the binding once failures accumulate.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $message       Operator-facing message.
	 */
	private static function record_failure( int $venue_term_id, string $message ): void {
		$failures = absint( get_term_meta( $venue_term_id, self::META_FAILURES, true ) ) + 1;
		update_term_meta( $venue_term_id, self::META_FAILURES, $failures );

		VenueCalendarFeed::record_failure( $venue_term_id, $message, $failures );
	}
}
