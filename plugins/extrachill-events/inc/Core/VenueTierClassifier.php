<?php
/**
 * Classify venues into the `_venue_tier` vocabulary from aggregate signals.
 *
 * Implements the Extra Chill side of venue tiering
 * (Extra-Chill/extrachill-events#801). The generic `_venue_tier` term meta
 * field and its closed vocabulary live upstream in data-machine-events
 * (#786); this classifier proposes a tier per venue using ONLY aggregate
 * per-venue statistics — there are no AI/LLM calls anywhere in this path.
 * The per-event signal problem documented in the issue (per-event price,
 * per-event ticketing detection) is sidestepped entirely: a venue's
 * extractor is constant per venue, so aggregate ratios are usable as
 * venue features even when individual events carry no signal.
 *
 * Signals per venue (a few set-based GROUP BY queries, no N+1):
 *   - total events (published) and events in the last 365 days
 *   - events/week rate
 *   - share of events whose block `price` attribute is non-empty
 *   - share of events with a non-empty `_datamachine_ticket_url`
 *   - share of events whose ticket URL points at a recognized ticketing
 *     platform ({@see VenueTierClassifier::TICKET_PLATFORMS})
 *   - repeat-performer rate: 1 - (distinct block `performer` values /
 *     events with a performer), i.e. how much the calendar cycles the
 *     same acts
 *   - share of titles carrying a time-range pattern ("6-9pm")
 *   - share of events starting before 19:00
 *   - venue name tokens (weak, corroborating only) and `_venue_capacity`
 *
 * Deterministic scoring: {@see VenueTierClassifier::score()}. Writes go
 * through `DataMachineEvents\Core\Venue_Taxonomy::update_venue_meta()` (the
 * human write path) only for proposals at or above the confidence
 * threshold, never overwriting an existing non-empty tier unless asked.
 * Existing `_ec_priority_venue` flags are never modified.
 *
 * The upstream tier API is newer than the deployed data-machine-events
 * release: proposing works without it (it only reads events and venues),
 * but writes require `Venue_Taxonomy::get_venue_tier_vocabulary()`
 * ({@see VenueTierClassifier::venue_tier_upstream_ready()}).
 *
 * Consumed by the `wp extrachill venues classify-tier` adapter in
 * extrachill-cli, which owns argument parsing and report formatting.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposes and writes `_venue_tier` values from aggregate per-venue
 * behavioral signals. See the file docblock for the signal list and the
 * scoring rules.
 */
class VenueTierClassifier {

	/** Term meta written by the upstream venue tier API (#786). */
	public const TIER_META_KEY = '_venue_tier';

	/** Event post type owned by data-machine-events. */
	public const POST_TYPE = 'data_machine_events';

	/** Post meta holding the extracted ticket URL. */
	private const TICKET_META_KEY = '_datamachine_ticket_url';

	/** Term meta holding venue capacity (mostly empty in practice). */
	private const CAPACITY_META_KEY = '_venue_capacity';

	/** Term meta flag for the hand-picked priority venues (#89). */
	private const PRIORITY_META_KEY = '_ec_priority_venue';

	/** Tier assigned by priority seeding (--seed-from-priority). */
	private const SEED_TIER = 'club';

	/** Confidence assigned to --seed-from-priority writes. */
	public const SEED_CONFIDENCE = 1.0;

	/** Event title pattern for embedded time ranges ("Derek Cribb 6-9pm"). */
	private const TIME_RANGE_TITLE_PATTERN = '[0-9]{1,2}(:[0-9]{2})?[[:space:]]*[-\x{2013}\x{2014}][[:space:]]*[0-9]{1,2}(:[0-9]{2})?[[:space:]]*(am|pm)';

	/**
	 * Recognized ticketing-platform URL fragments, pattern => platform slug.
	 *
	 * Used for BOTH the PHP-side detector
	 * ({@see VenueTierClassifier::detect_ticket_platform()}) and the
	 * SQL REGEXP alternation built from the same list, so the two can never
	 * drift. The generic upstream PlatformDetector classifies venue-site
	 * HTML, not ticket URLs, so this bounded list lives here.
	 *
	 * @var array<string,string>
	 */
	private const TICKET_PLATFORMS = array(
		'ticketmaster'   => 'ticketmaster',
		'livenation'     => 'livenation',
		'axs.com'        => 'axs',
		'etix.com'       => 'etix',
		'eventbrite.com' => 'eventbrite',
		'dice.fm'        => 'dice_fm',
		'seetickets'     => 'seetickets',
		'ticketweb'      => 'ticketweb',
		'prekindle.com'  => 'prekindle',
		'tixr.com'       => 'tixr',
		'showclix.com'   => 'showclix',
		'freshtix.com'   => 'freshtix',
	);

	/**
	 * Venue-name tokens weakly associated with bar/restaurant gigs.
	 *
	 * Deliberately WEAK: per #801, the "restaurant-sounding" venues include
	 * The Saxon Pub, Snug Harbor Jazz Bistro, The Bluebird Cafe, Cafe Du
	 * Nord, and Cactus Cafe. Tokens never determine a tier on their own —
	 * they only corroborate behavioral evidence (bar_gig rule) or anchor
	 * the listening-room rule when the business evidence is music-first.
	 *
	 * @var string[]
	 */
	private const BAR_NAME_TOKENS = array(
		'cafe',
		'coffee',
		'bar',
		'pub',
		'grill',
		'tavern',
		'brewery',
		'winery',
		'restaurant',
		'hotel',
		'patio',
		'kitchen',
		'taproom',
		'taphouse',
	);

	/**
	 * Venue-name tokens associated with purpose-built rooms.
	 *
	 * @var string[]
	 */
	private const HALL_NAME_TOKENS = array(
		'hall',
		'theatre',
		'theater',
		'arena',
		'pavilion',
		'stadium',
		'ballroom',
		'coliseum',
		'auditorium',
		'concert',
	);

	/** Confidence histogram buckets, label => [inclusive_min, exclusive_max]. */
	public const HISTOGRAM_BUCKETS = array(
		'0.0-0.5' => array( 0.0, 0.5 ),
		'0.5-0.6' => array( 0.5, 0.6 ),
		'0.6-0.7' => array( 0.6, 0.7 ),
		'0.7-0.8' => array( 0.7, 0.8 ),
		'0.8-0.9' => array( 0.8, 0.9 ),
		'0.9-1.0' => array( 0.9, 1.01 ),
	);

	/** Tier slugs in report order ('' = no proposal). */
	public const TIER_ORDER = array( 'amphitheater', 'concert_hall', 'club', 'listening_room', 'bar_gig', '' );

	/**
	 * Non-fatal degradations recorded by the last collect_signals() call.
	 *
	 * @var string[]
	 */
	private array $warnings = array();

	/**
	 * Non-fatal degradations recorded by the last collect_signals() call.
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Resolve a venue term by slug or numeric term ID.
	 *
	 * @param string $selector Venue term ID or slug.
	 * @return \WP_Term|null
	 */
	public function find_venue( string $selector ): ?\WP_Term {
		$term = get_term_by( 'slug', $selector, 'venue' );
		if ( ! $term instanceof \WP_Term && ctype_digit( $selector ) ) {
			$term = get_term( (int) $selector, 'venue' );
		}

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Score a page of the catalog and the full priority-venue set.
	 *
	 * Signals are ordered by total events descending before $offset/$limit
	 * apply. Priority venues are scored against the FULL catalog, before
	 * pagination, so the reconciliation sanity check always covers every
	 * `_ec_priority_venue` even when the page excludes some of them.
	 *
	 * @param array<int,array> $signals        venue_id => signals from collect_signals().
	 * @param float            $min_confidence Writable confidence threshold.
	 * @param int              $min_events     Minimum events to propose.
	 * @param int              $offset         Venues to skip.
	 * @param int              $limit          Max venues (0 = no limit).
	 * @return array{venues:int,proposals:array<int,array>,tier_counts:array<string,int>,histogram:array<string,int>,below_min:int,needs_review:int,priority_proposals:array<int,array>}
	 */
	public function propose( array $signals, float $min_confidence, int $min_events, int $offset = 0, int $limit = 0 ): array {
		uasort(
			$signals,
			static function ( array $a, array $b ): int {
				return (int) $b['total_events'] <=> (int) $a['total_events'];
			}
		);

		$priority_signals = array_filter(
			$signals,
			static function ( array $signal ): bool {
				return ! empty( $signal['is_priority'] );
			}
		);

		if ( $offset > 0 ) {
			$signals = array_slice( $signals, $offset, null, true );
		}
		if ( $limit > 0 ) {
			$signals = array_slice( $signals, 0, $limit, true );
		}

		$proposals    = array();
		$tier_counts  = array_fill_keys( self::TIER_ORDER, 0 );
		$histogram    = array_fill_keys( array_keys( self::HISTOGRAM_BUCKETS ), 0 );
		$below_min    = 0;
		$needs_review = 0;

		foreach ( $signals as $venue_id => $signal ) {
			$result = self::score( $signal );

			$tier_counts[ $result['tier'] ] = ( $tier_counts[ $result['tier'] ] ?? 0 ) + 1;
			++$histogram[ self::confidence_bucket( $result['confidence'] ) ];

			if ( '' === $result['tier'] && (int) $signal['total_events'] < $min_events ) {
				++$below_min;
				continue;
			}

			$proposals[ $venue_id ] = array(
				'venue_id'   => (int) $venue_id,
				'name'       => (string) $signal['venue_name'],
				'events'     => (int) $signal['total_events'],
				'tier'       => $result['tier'],
				'confidence' => $result['confidence'],
				'reasons'    => $result['reasons'],
				'existing'   => (string) $signal['existing_tier'],
				'priority'   => ! empty( $signal['is_priority'] ),
			);

			if ( '' === $result['tier'] || $result['confidence'] < $min_confidence ) {
				++$needs_review;
			}
		}

		$priority_proposals = array();
		foreach ( $priority_signals as $venue_id => $signal ) {
			$priority_result = self::score( $signal );

			$priority_proposals[ $venue_id ] = array(
				'venue_id'   => (int) $venue_id,
				'name'       => (string) $signal['venue_name'],
				'events'     => (int) $signal['total_events'],
				'tier'       => $priority_result['tier'],
				'confidence' => $priority_result['confidence'],
				'reasons'    => $priority_result['reasons'],
				'existing'   => (string) $signal['existing_tier'],
				'in_run'     => isset( $proposals[ $venue_id ] ),
			);
		}

		return array(
			'venues'             => count( $signals ),
			'proposals'          => $proposals,
			'tier_counts'        => $tier_counts,
			'histogram'          => $histogram,
			'below_min'          => $below_min,
			'needs_review'       => $needs_review,
			'priority_proposals' => $priority_proposals,
		);
	}

	/**
	 * Write qualifying proposals through the upstream human write path.
	 *
	 * Writes Venue_Taxonomy::update_venue_meta( $term_id, array( 'tier' =>
	 * $slug ) ) only for proposals at or above the confidence threshold.
	 * Existing non-empty tiers are never overwritten without $overwrite
	 * (human decisions win). Every proposed slug is re-validated against
	 * the live upstream vocabulary before writing; a venue whose slug was
	 * filtered out of the active vocabulary is skipped with a warning
	 * rather than written with an out-of-vocabulary value.
	 *
	 * @param array<int,array> $proposals      venue_id => proposal.
	 * @param float            $min_confidence Threshold.
	 * @param bool             $overwrite      Allow replacing existing tiers.
	 * @return array{messages:array<int,array{level:string,message:string}>,written:int,skipped:int,review:int,errors:int}
	 */
	public function apply_proposals( array $proposals, float $min_confidence, bool $overwrite ): array {
		$messages = array();
		$written  = 0;
		$skipped  = 0;
		$review   = 0;
		$errors   = 0;

		foreach ( $proposals as $proposal ) {
			if ( '' === $proposal['tier'] || $proposal['confidence'] < $min_confidence ) {
				++$review;
				continue;
			}

			if ( '' !== $proposal['existing'] && ! $overwrite ) {
				++$skipped;
				$messages[] = self::message( 'log', sprintf( '  skip (existing tier "%s"): %s', $proposal['existing'], $proposal['name'] ) );
				continue;
			}

			// Re-validate against the live upstream vocabulary.
			$canonical = \DataMachineEvents\Core\Venue_Taxonomy::resolve_venue_tier( $proposal['tier'] );
			if ( '' === $canonical ) {
				++$skipped;
				$messages[] = self::message(
					'warning',
					sprintf( '  skip (tier "%s" not in the active vocabulary): %s', $proposal['tier'], $proposal['name'] )
				);
				continue;
			}

			$updated = \DataMachineEvents\Core\Venue_Taxonomy::update_venue_meta(
				$proposal['venue_id'],
				array( 'tier' => $canonical )
			);

			if ( ! $updated ) {
				++$errors;
				$messages[] = self::message( 'warning', sprintf( '  write FAILED: %s (term %d)', $proposal['name'], $proposal['venue_id'] ) );
				continue;
			}

			++$written;
			$messages[] = self::message(
				'log',
				sprintf(
					'  write tier=%s conf=%.2f: %s (term %d)',
					$canonical,
					$proposal['confidence'],
					$proposal['name'],
					$proposal['venue_id']
				)
			);
		}

		return array(
			'messages' => $messages,
			'written'  => $written,
			'skipped'  => $skipped,
			'review'   => $review,
			'errors'   => $errors,
		);
	}

	/**
	 * Seed `club` at confidence 1.0 into priority venues without a tier.
	 *
	 * Priority venues are hand-picked concert rooms (#89); their empty
	 * classification is filled from that editorial decision. The
	 * `_ec_priority_venue` flag itself is never touched — ordering and
	 * classification stay orthogonal (#801).
	 *
	 * A priority venue the classifier already proposes at or above the
	 * confidence threshold is skipped: its classifier tier is the better
	 * value (apply writes it when the venue falls inside the pagination
	 * window), and seeding must never stomp a fresh proposal — the
	 * proposal's pre-run "existing tier" snapshot cannot see it. Only
	 * venues the classifier could NOT classify (empty proposal or below
	 * threshold) receive the editorial `club` seed.
	 *
	 * @param array<int,array> $priority_proposals venue_id => proposal for
	 *                                             every priority venue.
	 * @param float            $min_confidence     Threshold.
	 * @return array{messages:array<int,array{level:string,message:string}>,seeded:int}
	 */
	public function seed_priority_venues( array $priority_proposals, float $min_confidence ): array {
		$messages = array();
		$seeded   = 0;

		foreach ( $priority_proposals as $proposal ) {
			if ( '' !== $proposal['existing'] ) {
				$messages[] = self::message( 'log', sprintf( '  skip (already tiered "%s"): %s', $proposal['existing'], $proposal['name'] ) );
				continue;
			}

			if ( '' !== $proposal['tier'] && $proposal['confidence'] >= $min_confidence ) {
				$messages[] = self::message(
					'log',
					sprintf(
						'  skip (classifier proposal tier=%s conf=%.2f covers this venue): %s',
						$proposal['tier'],
						$proposal['confidence'],
						$proposal['name']
					)
				);
				continue;
			}

			$canonical = \DataMachineEvents\Core\Venue_Taxonomy::resolve_venue_tier( self::SEED_TIER );
			if ( '' === $canonical ) {
				$messages[] = self::message( 'warning', '  skip: "club" is not in the active tier vocabulary.' );
				continue;
			}

			$updated = \DataMachineEvents\Core\Venue_Taxonomy::update_venue_meta(
				$proposal['venue_id'],
				array( 'tier' => $canonical )
			);

			if ( ! $updated ) {
				$messages[] = self::message( 'warning', sprintf( '  seed FAILED: %s (term %d)', $proposal['name'], $proposal['venue_id'] ) );
				continue;
			}

			++$seeded;
			$messages[] = self::message( 'log', sprintf( '  seed tier=%s conf=%.1f: %s (term %d)', $canonical, self::SEED_CONFIDENCE, $proposal['name'], $proposal['venue_id'] ) );
		}

		return array(
			'messages' => $messages,
			'seeded'   => $seeded,
		);
	}

	/**
	 * Build one ordered report message.
	 *
	 * @param string $level   log|warning.
	 * @param string $message Message text.
	 * @return array{level:string,message:string}
	 */
	private static function message( string $level, string $message ): array {
		return array(
			'level'   => $level,
			'message' => $message,
		);
	}

	/**
	 * Score one venue's aggregate signals into a tier proposal.
	 *
	 * Pure static method (no WordPress dependencies) so the rules are
	 * unit-testable in isolation.
	 *
	 * Signals contract (missing keys are treated as neutral):
	 *   - total_events           int   published events at the venue
	 *   - events_per_week        float events in last 365 days / 52
	 *   - priced_ratio           float 0-1, events with a non-empty block price
	 *   - ticketed_ratio         float 0-1, events with a non-empty ticket URL
	 *   - platform_ratio         float 0-1, events on a recognized ticketing platform
	 *   - repeat_performer_rate  float 0-1, 1 - distinct performers / events with performer
	 *   - performer_coverage     float 0-1, events with a performer attribute / total
	 *   - time_range_title_ratio float 0-1, titles with an embedded time range
	 *   - early_start_ratio      float 0-1, events starting before 19:00
	 *   - name_bar_tokens        int   bar/restaurant token count in venue name
	 *   - name_hall_tokens       int   hall/theatre/arena token count in venue name
	 *   - name_amphitheater      bool  name references an amphitheater
	 *   - name_listening         bool  name references a listening room
	 *   - capacity               int   `_venue_capacity` (0 = unknown)
	 *   - min_events             int   minimum event count required to propose
	 *
	 * Deterministic rule cascade, in priority order (first match wins):
	 *
	 *   R0 thin: fewer than min_events events -> no proposal ('' tier,
	 *       confidence 0) — signals are too thin to be meaningful.
	 *   R1 amphitheater: "amphitheath*" in name, or capacity >= 3000.
	 *       A physical large-outdoor fact outranks behavioral noise.
	 *   R2 concert_hall: hall/theatre/arena-style name PLUS business
	 *       evidence (platform >= 0.2 or capacity >= 800); or capacity
	 *       >= 1200; or platform >= 0.4 with priced >= 0.25 at low weekly
	 *       volume (<= 2/week).
	 *   R3 bar_gig: high weekly volume (>= 4/week) AND at least one
	 *       repetition signal (repeat-performer rate >= 0.5 with performer
	 *       coverage >= 0.5, time-range titles >= 0.25, or early starts
	 *       >= 0.35) AND no meaningful ticketing evidence (platform
	 *       <= 0.2 AND priced <= 0.3). This is The Dinghy profile from #91.
	 *   R4 listening_room: explicit "listening" in name; or a bar/cafe
	 *       name token PLUS music-first evidence (platform >= 0.25) at
	 *       moderate volume (<= 6/week) — the Bluebird Cafe profile.
	 *       Name tokens alone NEVER reach a tier; business evidence is
	 *       required, so "cafe" venues are not misclassified by name.
	 *   R5 club: platform >= 0.25, or priced+ticketed >= 0.3 with
	 *       rotating acts (repeat <= 0.6 and coverage >= 0.5).
	 *   R6 otherwise: no proposal — the venue lands on the human-review
	 *       list rather than being silently guessed.
	 *
	 * Confidence starts at the rule's base (0.60-0.75 depending on rule)
	 * and gains +0.05 per corroborating signal, capped per rule (0.95
	 * amphitheater/concert_hall, 0.90 bar_gig, 0.85 listening_room/club).
	 * With the default min-confidence of 0.7, every base case needs at
	 * least one or two corroborations to become writable — conservative
	 * by design.
	 *
	 * @param array $signals Aggregate signals (see contract above).
	 * @return array{tier:string,confidence:float,reasons:string[]} Tier slug
	 *         ('' = no proposal), 0-1 confidence, human-readable reasons.
	 */
	public static function score( array $signals ): array {
		$min_events = max( 1, (int) ( $signals['min_events'] ?? 3 ) );
		$total      = (int) ( $signals['total_events'] ?? 0 );

		if ( $total < $min_events ) {
			return self::proposal(
				'',
				0.0,
				array( sprintf( 'too thin: %d event(s) < min-events %d', $total, $min_events ) )
			);
		}

		$per_week    = max( 0.0, (float) ( $signals['events_per_week'] ?? 0 ) );
		$priced      = self::clamp01( (float) ( $signals['priced_ratio'] ?? 0 ) );
		$ticketed    = self::clamp01( (float) ( $signals['ticketed_ratio'] ?? 0 ) );
		$platform    = self::clamp01( (float) ( $signals['platform_ratio'] ?? 0 ) );
		$repeat      = self::clamp01( (float) ( $signals['repeat_performer_rate'] ?? 0 ) );
		$coverage    = self::clamp01( (float) ( $signals['performer_coverage'] ?? 0 ) );
		$time_range  = self::clamp01( (float) ( $signals['time_range_title_ratio'] ?? 0 ) );
		$early       = self::clamp01( (float) ( $signals['early_start_ratio'] ?? 0 ) );
		$bar_tokens  = (int) ( $signals['name_bar_tokens'] ?? 0 );
		$hall_tokens = (int) ( $signals['name_hall_tokens'] ?? 0 );
		$amphi       = ! empty( $signals['name_amphitheater'] );
		$listening   = ! empty( $signals['name_listening'] );
		$capacity    = (int) ( $signals['capacity'] ?? 0 );

		// R1 — amphitheater.
		if ( $amphi || $capacity >= 3000 ) {
			$conf    = 0.70;
			$reasons = array();
			if ( $amphi ) {
				$conf     += 0.05;
				$reasons[] = 'venue name references an amphitheater-style outdoor format';
			}
			if ( $capacity >= 3000 ) {
				$reasons[] = sprintf( 'capacity %d indicates a large-format room', $capacity );
			}
			if ( $capacity >= 8000 ) {
				$conf += 0.05;
			}
			if ( $platform >= 0.3 ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events link a recognized ticketing platform', $platform * 100 );
			}
			return self::proposal( 'amphitheater', min( 0.95, $conf ), $reasons );
		}

		// R2 — concert_hall.
		$hall_named     = $hall_tokens >= 1;
		$hall_supported = $platform >= 0.2 || $capacity >= 800;
		$big_room       = $capacity >= 1200;
		$premium_lowvol = $platform >= 0.4 && $priced >= 0.25 && $per_week <= 2.0;

		if ( ( $hall_named && $hall_supported ) || $big_room || $premium_lowvol ) {
			$conf    = 0.65;
			$reasons = array();
			if ( $hall_named ) {
				$conf     += 0.05;
				$reasons[] = 'venue name references a hall/theatre/arena-style room';
			}
			if ( $big_room ) {
				$conf     += 0.05;
				$reasons[] = sprintf( 'capacity %d indicates a large ticketed room', $capacity );
			}
			if ( $premium_lowvol ) {
				$conf     += 0.05;
				$reasons[] = 'high ticketing-platform and priced share at low weekly volume';
			}
			if ( $platform >= 0.5 ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events link a recognized ticketing platform', $platform * 100 );
			}
			return self::proposal( 'concert_hall', min( 0.95, $conf ), $reasons );
		}

		// R3 — bar_gig.
		$busy         = $per_week >= 4.0;
		$repetitive   = $coverage >= 0.5 && $repeat >= 0.5;
		$patterned    = $time_range >= 0.25;
		$afternoon    = $early >= 0.35;
		$no_ticketing = $platform <= 0.2 && $priced <= 0.3;

		if ( $busy && ( $repetitive || $patterned || $afternoon ) && $no_ticketing ) {
			$conf    = 0.60;
			$reasons = array(
				sprintf( '%.1f events/week with no ticketing evidence', $per_week ),
			);
			if ( $repetitive ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% repeat-performer rate (same acts cycle)', $repeat * 100 );
			}
			if ( $patterned ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of titles embed a time range (e.g. "6-9pm")', $time_range * 100 );
			}
			if ( $afternoon ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events start before 7pm', $early * 100 );
			}
			if ( $per_week >= 7.0 ) {
				$conf     += 0.05;
				$reasons[] = 'nightly-music volume (>= 7 events/week)';
			}
			if ( $bar_tokens >= 1 ) {
				$conf     += 0.05;
				$reasons[] = 'venue name carries a bar/restaurant token (corroborating only)';
			}
			return self::proposal( 'bar_gig', min( 0.9, $conf ), $reasons );
		}

		// R4 — listening_room.
		if ( $listening ) {
			$conf    = 0.75;
			$reasons = array( 'venue name references a listening room' );
			if ( $platform >= 0.25 ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events link a recognized ticketing platform', $platform * 100 );
			}
			if ( $per_week <= 3.0 ) {
				$conf     += 0.05;
				$reasons[] = 'moderate volume consistent with seated/attentive rooms';
			}
			return self::proposal( 'listening_room', min( 0.85, $conf ), $reasons );
		}

		if ( $bar_tokens >= 1 && $platform >= 0.25 && $per_week <= 6.0 ) {
			$conf    = 0.60;
			$reasons = array(
				'bar/cafe-style name but music-first ticketing evidence (never classified by name alone)',
			);
			if ( $platform >= 0.5 ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events link a recognized ticketing platform', $platform * 100 );
			}
			if ( $priced >= 0.4 ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events carry a price', $priced * 100 );
			}
			if ( $per_week <= 3.0 ) {
				$conf     += 0.05;
				$reasons[] = 'moderate volume consistent with seated/attentive rooms';
			}
			return self::proposal( 'listening_room', min( 0.85, $conf ), $reasons );
		}

		// R5 — club.
		$platformy       = $platform >= 0.25;
		$priced_ticketed = $priced >= 0.3 && $ticketed >= 0.3;
		$rotating_acts   = $coverage >= 0.5 && $repeat <= 0.6;

		if ( $platformy || ( $priced_ticketed && $rotating_acts ) ) {
			$conf    = 0.60;
			$reasons = array();
			if ( $platformy ) {
				$conf     += 0.05;
				$reasons[] = sprintf( '%.0f%% of events link a recognized ticketing platform', $platform * 100 );
			}
			if ( $priced_ticketed ) {
				$conf     += 0.05;
				$reasons[] = sprintf( 'priced %.0f%% / ticketed %.0f%% of events', $priced * 100, $ticketed * 100 );
			}
			if ( $rotating_acts ) {
				$conf     += 0.05;
				$reasons[] = sprintf( 'rotating acts (%.0f%% repeat-performer rate)', $repeat * 100 );
			}
			return self::proposal( 'club', min( 0.85, $conf ), $reasons );
		}

		// R6 — no proposal; human review.
		return self::proposal(
			'',
			0.0,
			array( 'signals ambiguous — no rule fired; needs human review' )
		);
	}

	/**
	 * Detect which recognized ticketing platform a ticket URL points at.
	 *
	 * Pure static method. Matches substrings against the shared platform
	 * list ({@see VenueTierClassifier::TICKET_PLATFORMS}) — the same
	 * list that builds the SQL-side REGEXP, so the single-venue display
	 * and the aggregate query cannot disagree.
	 *
	 * @param string $url Ticket URL.
	 * @return string Platform slug, or '' when unrecognized.
	 */
	public static function detect_ticket_platform( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$haystack = strtolower( $url );

		foreach ( self::TICKET_PLATFORMS as $pattern => $slug ) {
			if ( false !== strpos( $haystack, $pattern ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Extract classification tokens from a venue name.
	 *
	 * Pure static method. Returns the weak bar/restaurant and
	 * hall/theatre token counts plus the two anchored booleans. Name
	 * tokens are the weakest signals in the scorer; see the
	 * BAR_NAME_TOKENS docblock for why they must never decide a tier
	 * alone.
	 *
	 * @param string $venue_name Venue term name.
	 * @return array{name_bar_tokens:int,name_hall_tokens:int,name_amphitheater:bool,name_listening:bool}
	 */
	public static function extract_name_tokens( string $venue_name ): array {
		$normalized = strtolower( trim( $venue_name ) );
		$words      = preg_split( '/[^a-z]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
		$words      = is_array( $words ) ? $words : array();

		return array(
			'name_bar_tokens'   => count( array_intersect( $words, self::BAR_NAME_TOKENS ) ),
			'name_hall_tokens'  => count( array_intersect( $words, self::HALL_NAME_TOKENS ) ),
			// Substring, not word match: "Amphitheater"/"Amphitheatre" and
			// compounds match; "the Bluebird Cafe" never trips it.
			'name_amphitheater' => false !== strpos( $normalized, 'amphitheat' ),
			'name_listening'    => false !== strpos( $normalized, 'listening' ),
		);
	}

	/**
	 * Clamp a ratio into the 0-1 range.
	 *
	 * @param float $value Raw value.
	 * @return float
	 */
	private static function clamp01( float $value ): float {
		return max( 0.0, min( 1.0, $value ) );
	}

	/**
	 * Build the score() return shape.
	 *
	 * @param string        $tier       Tier slug or ''.
	 * @param float         $confidence 0-1.
	 * @param array<string> $reasons    Human-readable rule hits.
	 * @return array{tier:string,confidence:float,reasons:string[]}
	 */
	private static function proposal( string $tier, float $confidence, array $reasons ): array {
		return array(
			'tier'       => $tier,
			'confidence' => round( $confidence, 2 ),
			'reasons'    => $reasons,
		);
	}

	/**
	 * Histogram bucket label for a confidence value.
	 *
	 * @param float $confidence 0-1.
	 * @return string Bucket label.
	 */
	public static function confidence_bucket( float $confidence ): string {
		foreach ( self::HISTOGRAM_BUCKETS as $label => $range ) {
			if ( $confidence >= $range[0] && $confidence < $range[1] ) {
				return $label;
			}
		}

		return '0.9-1.0';
	}

	/**
	 * Compute aggregate signals for every venue with set-based SQL.
	 *
	 * Two GROUP BY queries over the whole catalog (plus two small term-meta
	 * IN() lookups), keyed by venue term ID — deliberately NOT one query
	 * per venue. The issue's premise is that per-venue aggregates over the
	 * full catalog are the usable signals; the catalog is ~123k events and
	 * ~5.3k venues on the live site.
	 *
	 * @param int $min_events Passed through to score() via each signal row.
	 * Non-fatal degradations are recorded in {@see self::warnings()}.
	 *
	 * @return array<int,array> venue_id => signals array (score() contract
	 *                          plus venue_name, existing_tier, is_priority).
	 */
	public function collect_signals( int $min_events ): array {
		$this->warnings = array();
		global $wpdb;

		$posts_table   = $wpdb->posts;
		$postmeta      = $wpdb->postmeta;
		$relationships = $wpdb->term_relationships;
		$term_taxonomy = $wpdb->term_taxonomy;
		$dates_table   = $wpdb->prefix . 'datamachine_event_dates';

		$dates_available = $this->dates_table_exists( $dates_table );
		if ( ! $dates_available ) {
			$this->warnings[] = 'datamachine_event_dates table not found — recency and start-hour signals unavailable (scoring degrades to ticketing/performer/title evidence only).';
		}

		$venue_terms = get_terms(
			array(
				'taxonomy'               => 'venue',
				'hide_empty'             => false,
				'number'                 => 0,
				'fields'                 => 'all',
				'orderby'                => 'none',
				'update_term_meta_cache' => false,
			)
		);

		if ( is_wp_error( $venue_terms ) || empty( $venue_terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $venue_terms as $venue_term ) {
			$names[ (int) $venue_term->term_id ] = $venue_term->name;
		}
		$venue_ids = array_map( 'intval', array_keys( $names ) );

		$existing_tiers = $this->fetch_term_meta_map( $venue_ids, self::TIER_META_KEY );
		$capacities     = $this->fetch_term_meta_map( $venue_ids, self::CAPACITY_META_KEY );
		$priorities     = $this->fetch_term_meta_map( $venue_ids, self::PRIORITY_META_KEY );

		$platform_regex = $this->platform_sql_regex();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Two one-shot bulk reads over the whole catalog (per-venue WP_Query would be orders of magnitude slower and the classifier runs once). Interpolated values are trusted $wpdb table properties, fixed table names, and compile-time constant patterns; no user input is interpolated.

		// Query A — content + ticket-meta derived signals (one GROUP BY over
		// the whole event catalog). The ticket-meta derived table dedupes
		// postmeta so join fanout cannot inflate COUNT/SUM; COUNT(DISTINCT
		// p.ID) keeps the denominator exact regardless.
		$content_rows = $wpdb->get_results(
			"SELECT tt.term_id AS venue_id,
				COUNT(DISTINCT p.ID) AS total_events,
				SUM(CASE WHEN p.post_content REGEXP '\"price\":\"[^\"]' THEN 1 ELSE 0 END) AS priced_events,
				SUM(CASE WHEN tm.ticket_url <> '' THEN 1 ELSE 0 END) AS ticketed_events,
				SUM(CASE WHEN tm.ticket_url REGEXP '{$platform_regex}' THEN 1 ELSE 0 END) AS platform_events,
				SUM(CASE WHEN p.post_title REGEXP '" . self::TIME_RANGE_TITLE_PATTERN . "' THEN 1 ELSE 0 END) AS time_range_events,
				SUM(CASE WHEN p.post_content LIKE '%\"performer\":\"%' THEN 1 ELSE 0 END) AS performer_events,
				COUNT(DISTINCT CASE WHEN p.post_content LIKE '%\"performer\":\"%'
					THEN LOWER(SUBSTRING(p.post_content, LOCATE('\"performer\":\"', p.post_content) + 13, 120)) END) AS distinct_performers
			FROM {$posts_table} p
			INNER JOIN {$relationships} tr ON tr.object_id = p.ID
			INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'venue'
			LEFT JOIN (
				SELECT post_id, MAX(meta_value) AS ticket_url
				FROM {$postmeta}
				WHERE meta_key = '" . self::TICKET_META_KEY . "'
				GROUP BY post_id
			) tm ON tm.post_id = p.ID
			WHERE p.post_type = '" . self::POST_TYPE . "' AND p.post_status = 'publish'
			GROUP BY tt.term_id",
			ARRAY_A
		);

		$content_signals = array();
		foreach ( (array) $content_rows as $row ) {
			$content_signals[ (int) $row['venue_id'] ] = $row;
		}

		$date_signals = array();
		if ( $dates_available ) {
			// Query B — volume, recency, and start-hour distribution from the
			// denormalized dates table (no wp_posts join at all).
			$date_rows = $wpdb->get_results(
				"SELECT tt.term_id AS venue_id,
					COUNT(*) AS dates_total,
					SUM(d.start_datetime >= DATE_SUB(NOW(), INTERVAL 365 DAY)) AS recent_events,
					SUM(HOUR(d.start_datetime) < 19) AS early_starts
				FROM {$relationships} tr
				INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'venue'
				INNER JOIN {$dates_table} d ON d.post_id = tr.object_id AND d.post_status = 'publish'
				GROUP BY tt.term_id",
				ARRAY_A
			);

			foreach ( (array) $date_rows as $row ) {
				$date_signals[ (int) $row['venue_id'] ] = $row;
			}
		}

		// phpcs:enable

		$signals = array();

		foreach ( $venue_ids as $venue_id ) {
			$content = $content_signals[ $venue_id ] ?? null;
			$dates   = $date_signals[ $venue_id ] ?? null;

			if ( null === $content && null === $dates ) {
				continue;
			}

			$total_events     = $content ? (int) $content['total_events'] : 0;
			$performer_events = $content ? (int) $content['performer_events'] : 0;
			$distinct         = $content ? (int) $content['distinct_performers'] : 0;
			$recent_events    = $dates ? (int) $dates['recent_events'] : 0;
			$dates_total      = $dates ? (int) $dates['dates_total'] : 0;

			$signals[ $venue_id ] = array_merge(
				array(
					'venue_name'             => $names[ $venue_id ],
					'total_events'           => $total_events,
					'recent_events'          => $recent_events,
					'events_per_week'        => $recent_events / 52.0,
					'priced_ratio'           => $total_events > 0 ? (int) $content['priced_events'] / $total_events : 0.0,
					'ticketed_ratio'         => $total_events > 0 ? (int) $content['ticketed_events'] / $total_events : 0.0,
					'platform_ratio'         => $total_events > 0 ? (int) $content['platform_events'] / $total_events : 0.0,
					'repeat_performer_rate'  => $performer_events > 0 ? max( 0.0, 1.0 - $distinct / $performer_events ) : 0.0,
					'performer_coverage'     => $total_events > 0 ? $performer_events / $total_events : 0.0,
					'time_range_title_ratio' => $total_events > 0 ? (int) $content['time_range_events'] / $total_events : 0.0,
					'early_start_ratio'      => $dates_total > 0 ? (int) $dates['early_starts'] / $dates_total : 0.0,
					'existing_tier'          => (string) ( $existing_tiers[ $venue_id ] ?? '' ),
					'is_priority'            => ! empty( $priorities[ $venue_id ] ),
					'capacity'               => (int) ( $capacities[ $venue_id ] ?? 0 ),
					'min_events'             => $min_events,
				),
				self::extract_name_tokens( (string) $names[ $venue_id ] )
			);
		}

		return $signals;
	}

	/**
	 * Fetch one term-meta key for a list of terms as an id => value map.
	 *
	 * Set-based IN() lookup — no per-term meta reads.
	 *
	 * @param array<int,int> $venue_ids Venue term IDs.
	 * @param string         $meta_key  Term meta key.
	 * @return array<int,string>
	 */
	private function fetch_term_meta_map( array $venue_ids, string $meta_key ): array {
		global $wpdb;

		if ( empty( $venue_ids ) ) {
			return array();
		}

		$map    = array();
		$chunks = array_chunk( $venue_ids, 1000 );

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted internal identifiers: the $wpdb->termmeta table name and an IN() placeholder list built entirely from %d tokens.
			$sql = "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s AND term_id IN ( {$placeholders} )";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot bulk read; get_term_meta per venue would be thousands of queries.
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is passed to prepare() immediately on this line.
				$wpdb->prepare( $sql, array_merge( array( $meta_key ), $chunk ) ),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$map[ (int) $row['term_id'] ] = (string) $row['meta_value'];
			}
		}

		return $map;
	}

	/**
	 * Whether the denormalized event dates table exists on this site.
	 *
	 * @param string $dates_table Fully qualified table name.
	 * @return bool
	 */
	private function dates_table_exists( string $dates_table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Schema probe in a one-shot classification run; $dates_table derives from $wpdb->prefix.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dates_table ) ) === $dates_table;
	}

	/**
	 * Whether the deployed data-machine-events ships the venue tier API.
	 *
	 * Runtime guard, not a static fact: older data-machine-events deploys do
	 * not expose Venue_Taxonomy::get_venue_tier_vocabulary() (venue tier API
	 * from data-machine-events#786), so apply runs must fail closed on them.
	 * The indirection through is_callable() with a runtime class-name keeps
	 * the check honest instead of hard-wiring the current dependency.
	 *
	 * @return bool
	 */
	public static function venue_tier_upstream_ready(): bool {
		$venue_taxonomy_class = '\DataMachineEvents\Core\Venue_Taxonomy';

		return is_callable( array( $venue_taxonomy_class, 'get_venue_tier_vocabulary' ) );
	}

	/**
	 * Build the SQL REGEXP alternation from the shared platform list.
	 *
	 * @return string Regex fragment safe to interpolate into MariaDB REGEXP.
	 */
	private function platform_sql_regex(): string {
		$patterns = array();

		foreach ( array_keys( self::TICKET_PLATFORMS ) as $pattern ) {
			$patterns[] = str_replace( '.', '\\.', $pattern );
		}

		return implode( '|', $patterns );
	}
}
