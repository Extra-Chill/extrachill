<?php
/**
 * Classify venues into the `_venue_tier` vocabulary from aggregate signals.
 *
 * Command: `wp extrachill venues classify-tier`.
 *
 * Implements the Extra Chill side of venue tiering
 * (Extra-Chill/extrachill-events#801). The generic `_venue_tier` term meta
 * field and its closed vocabulary live upstream in data-machine-events
 * (#786); this command proposes a tier per venue using ONLY aggregate
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
 *     platform ({@see \ExtraChillEvents\Core\VenueTierClassifier})
 *   - repeat-performer rate: 1 - (distinct block `performer` values /
 *     events with a performer), i.e. how much the calendar cycles the
 *     same acts
 *   - share of titles carrying a time-range pattern ("6-9pm")
 *   - share of events starting before 19:00
 *   - venue name tokens (weak, corroborating only) and `_venue_capacity`
 *
 * Deterministic scoring: {@see \ExtraChillEvents\Core\VenueTierClassifier::score()}.
 * Dry-run by default; `--apply` writes through
 * `DataMachineEvents\Core\Venue_Taxonomy::update_venue_meta()` (the human
 * write path) only for proposals at or above `--min-confidence`, never
 * overwriting an existing non-empty tier without `--overwrite`. Existing
 * `_ec_priority_venue` flags are never modified; see `--seed-from-priority`.
 *
 * The upstream tier API is newer than the deployed data-machine-events
 * release: dry-run works without it (the command only reads events and
 * venues), but `--apply` aborts unless
 * `Venue_Taxonomy::get_venue_tier_vocabulary()` exists.
 *
 * Always invoke with `--url=events.extrachill.com`.
 *
 * Thin adapter over VenueTierClassifier (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\VenueTierClassifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposes `_venue_tier` values from aggregate per-venue behavioral signals.
 *
 * Dry-run by default; --apply commits through the upstream human write path.
 * See the file docblock for the signal list and the scoring rules.
 */
class ClassifyVenueTierCommand {

	/**
	 * Classify venue tiers from aggregate signals.
	 *
	 * Dry-run by default; pass --apply to commit writes through
	 * Venue_Taxonomy::update_venue_meta().
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Commit proposals at or above --min-confidence (plus
	 *   --seed-from-priority seeds). Default is dry-run.
	 *
	 * [--min-confidence=<0-1>]
	 * : Minimum confidence to write. Proposals below the threshold are
	 *   listed for human review and never written.
	 * ---
	 * default: 0.7
	 * ---
	 *
	 * [--min-events=<n>]
	 * : Venues with fewer published events are never classified (tier
	 *   proposal is empty) — signals are too thin to be meaningful.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--limit=<n>]
	 * : Consider at most n venues, ordered by total event count
	 *   descending. 0 means no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--offset=<n>]
	 * : Skip the first n venues (by the same ordering) before applying
	 *   --limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--venue=<term_id|slug>]
	 * : Inspect a single venue: print the raw aggregate signals and the
	 *   score() result. Never writes, even with --apply.
	 *
	 * [--overwrite]
	 * : Allow replacing an existing non-empty `_venue_tier`. Default off —
	 *   human decisions win over CLI proposals.
	 *
	 * [--seed-from-priority]
	 * : With --apply, seed `club` at confidence 1.0 for any
	 *   `_ec_priority_venue` venue that has no tier yet. Priority
	 *   (calendar ordering) and tier (classification) stay orthogonal;
	 *   the seed only fills the empty classification of hand-picked
	 *   concert rooms and never touches the `_ec_priority_venue` flag.
	 *
	 * [--format=<format>]
	 * : Format for the review list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues classify-tier --url=events.extrachill.com
	 *     wp extrachill venues classify-tier --url=events.extrachill.com --limit=300
	 *     wp extrachill venues classify-tier --url=events.extrachill.com --venue=the-dinghy
	 *     wp extrachill venues classify-tier --url=events.extrachill.com --apply --seed-from-priority
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		OwnerSiteAbility::require_service( 'events', 'Events', VenueTierClassifier::class );

		$apply          = ! empty( $assoc_args['apply'] );
		$overwrite      = ! empty( $assoc_args['overwrite'] );
		$seed_priority  = ! empty( $assoc_args['seed-from-priority'] );
		$min_confidence = isset( $assoc_args['min-confidence'] ) ? (float) $assoc_args['min-confidence'] : 0.7;
		$min_events     = isset( $assoc_args['min-events'] ) ? (int) $assoc_args['min-events'] : 3;
		$limit          = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$offset         = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$format         = (string) ( $assoc_args['format'] ?? 'table' );
		$venue_selector = isset( $assoc_args['venue'] ) ? (string) $assoc_args['venue'] : '';

		if ( $min_confidence < 0 || $min_confidence > 1 ) {
			\WP_CLI::error( '--min-confidence must be between 0 and 1.' );
			return;
		}

		if ( $min_events < 1 ) {
			\WP_CLI::error( '--min-events must be at least 1.' );
			return;
		}

		if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
			\WP_CLI::error( 'Unknown --format. Use table, csv, or json.' );
			return;
		}

		// Upstream version guard. Dry-run only reads events/venues and works
		// against any deployed data-machine-events; --apply writes through
		// the human write path that shipped with #786, so it requires it.
		if ( $apply && ! VenueTierClassifier::venue_tier_upstream_ready() ) {
			\WP_CLI::error(
				'The deployed data-machine-events does not expose '
				. 'Venue_Taxonomy::get_venue_tier_vocabulary() (venue tier API from data-machine-events#786). '
				. 'Upgrade data-machine-events before running with --apply. '
				. 'Dry-run (proposal only) works without the upgrade.'
			);
			return;
		}

		$classifier = new VenueTierClassifier();

		if ( '' !== $venue_selector ) {
			self::inspect_single_venue( $classifier, $venue_selector, $min_events );
			return;
		}

		// Aggregate signals for every venue, computed in a few set-based queries.
		$signals = self::collect_signals( $classifier, $min_events );

		if ( empty( $signals ) ) {
			\WP_CLI::log( 'No venue signals could be computed — nothing to classify.' );
			return;
		}

		$run = $classifier->propose( $signals, $min_confidence, $min_events, $offset, $limit );

		\WP_CLI::log(
			sprintf(
				'%s venue tier classification — venues=%d min-confidence=%.2f min-events=%d%s%s',
				$apply ? 'APPLY' : 'DRY RUN',
				$run['venues'],
				$min_confidence,
				$min_events,
				$overwrite ? ' overwrite=yes' : '',
				$seed_priority ? ' seed-from-priority=yes' : ''
			)
		);

		self::print_summary( $apply, $run['tier_counts'], $run['histogram'], $run['below_min'], $run['needs_review'] );

		self::print_review_list( $run['proposals'], $min_confidence, $format );

		// The full priority set (independent of pagination) is the
		// reconciliation sanity check.
		self::print_priority_reconciliation( $run['priority_proposals'] );

		if ( ! $apply ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Dry run — nothing was written. Re-run with --apply to commit proposals at or above the confidence threshold.' );
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Applying proposals:' );
		$applied = $classifier->apply_proposals( $run['proposals'], $min_confidence, $overwrite );
		self::print_messages( $applied['messages'] );
		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Apply complete — written: %d | skipped: %d | left for review: %d | errors: %d',
				$applied['written'],
				$applied['skipped'],
				$applied['review'],
				$applied['errors']
			)
		);

		if ( $seed_priority ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Seeding priority venues (--seed-from-priority):' );
			$seeded = $classifier->seed_priority_venues( $run['priority_proposals'], $min_confidence );
			self::print_messages( $seeded['messages'] );
			\WP_CLI::log( sprintf( 'Priority seeding complete — seeded: %d.', $seeded['seeded'] ) );
		}
	}

	/**
	 * Collect aggregate signals and surface any degradation warnings.
	 *
	 * @param VenueTierClassifier $classifier Classifier service.
	 * @param int                 $min_events Minimum events to propose.
	 * @return array<int,array>
	 */
	private static function collect_signals( VenueTierClassifier $classifier, int $min_events ): array {
		$signals = $classifier->collect_signals( $min_events );
		foreach ( $classifier->warnings() as $warning ) {
			\WP_CLI::warning( $warning );
		}
		return $signals;
	}

	/**
	 * Print ordered service messages.
	 *
	 * @param array<int,array{level:string,message:string}> $messages Messages.
	 */
	private static function print_messages( array $messages ): void {
		foreach ( $messages as $message ) {
			if ( 'warning' === $message['level'] ) {
				\WP_CLI::warning( $message['message'] );
				continue;
			}
			\WP_CLI::log( $message['message'] );
		}
	}

	/**
	 * Print the raw signals and score() result for one venue.
	 *
	 * @param VenueTierClassifier $classifier Classifier service.
	 * @param string              $selector   Venue term ID or slug.
	 * @param int                 $min_events --min-events value for the score() call.
	 */
	private static function inspect_single_venue( VenueTierClassifier $classifier, string $selector, int $min_events ): void {
		$term = $classifier->find_venue( $selector );

		if ( null === $term ) {
			\WP_CLI::error( sprintf( 'Venue "%s" not found.', $selector ) );
			return;
		}

		$signals  = self::collect_signals( $classifier, $min_events );
		$venue_id = (int) $term->term_id;

		if ( ! isset( $signals[ $venue_id ] ) ) {
			\WP_CLI::error( sprintf( 'No aggregate signals could be computed for venue "%s" (no published events?).', $term->name ) );
			return;
		}

		$signal = $signals[ $venue_id ];
		$result = VenueTierClassifier::score( $signal );

		\WP_CLI::log( sprintf( '=== %s (term %d) ===', $term->name, $venue_id ) );
		\WP_CLI::log( '' );

		$rows = array();
		foreach ( $signal as $key => $value ) {
			if ( is_float( $value ) ) {
				$value = round( $value, 4 );
			}
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$rows[] = array(
				'signal' => $key,
				'value'  => (string) $value,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'signal', 'value' ) );

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Proposal: tier=%s confidence=%.2f%s',
				'' === $result['tier'] ? '(none — human review)' : $result['tier'],
				$result['confidence'],
				'' !== (string) $signal['existing_tier'] ? sprintf( ' (existing tier: %s)', $signal['existing_tier'] ) : ''
			)
		);
		foreach ( $result['reasons'] as $reason ) {
			\WP_CLI::log( sprintf( '  - %s', $reason ) );
		}

		if ( ! empty( $signal['is_priority'] ) ) {
			\WP_CLI::log( 'Note: this venue carries the _ec_priority_venue flag (ordering stays orthogonal to tier).' );
		}
	}

	/**
	 * Print the summary: per-tier counts, confidence histogram, review count.
	 *
	 * @param bool              $apply        Whether this is an apply run.
	 * @param array<string,int> $tier_counts  Proposal counts keyed by tier ('' = none).
	 * @param array<string,int> $histogram    Confidence bucket counts.
	 * @param int               $below_min    Venues under --min-events (counted, not listed).
	 * @param int               $needs_review Venues on the human-review path.
	 */
	private static function print_summary( bool $apply, array $tier_counts, array $histogram, int $below_min, int $needs_review ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '=== Venue tier classification report (%s) ===', $apply ? 'APPLY' : 'DRY RUN' ) );

		$tier_rows = array();
		foreach ( $tier_counts as $tier => $count ) {
			$tier_rows[] = array(
				'proposed_tier' => '' === $tier ? '(none — review)' : $tier,
				'venues'        => $count,
			);
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Per-tier proposal counts:' );
		\WP_CLI\Utils\format_items( 'table', $tier_rows, array( 'proposed_tier', 'venues' ) );

		$hist_rows = array();
		foreach ( $histogram as $bucket => $count ) {
			$hist_rows[] = array(
				'confidence' => $bucket,
				'venues'     => $count,
			);
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Confidence histogram (proposals, including empty tiers):' );
		\WP_CLI\Utils\format_items( 'table', $hist_rows, array( 'confidence', 'venues' ) );

		\WP_CLI::log( '' );
		\WP_CLI::log(
			sprintf(
				'Needs human review: %d | below min-events (not listed): %d',
				$needs_review,
				$below_min
			)
		);
	}

	/** Review-list rows shown before the display cap kicks in. */
	private const REVIEW_LIST_DISPLAY_CAP = 100;

	/**
	 * Print the review list: venues whose proposal is empty or below the
	 * confidence threshold.
	 *
	 * Display is capped at {@see ClassifyVenueTierCommand::REVIEW_LIST_DISPLAY_CAP}
	 * rows; the counts in the summary always reflect the complete set. Use
	 * --limit/--offset or --format=csv|json to consume the full list.
	 *
	 * @param array<int,array> $proposals      venue_id => proposal.
	 * @param float            $min_confidence Threshold.
	 * @param string           $format         table|csv|json.
	 */
	private static function print_review_list( array $proposals, float $min_confidence, string $format ): void {
		$rows = array();

		foreach ( $proposals as $proposal ) {
			if ( '' !== $proposal['tier'] && $proposal['confidence'] >= $min_confidence ) {
				continue;
			}

			$rows[] = array(
				'venue_id'   => $proposal['venue_id'],
				'venue'      => $proposal['name'],
				'events'     => $proposal['events'],
				'proposed'   => '' === $proposal['tier'] ? '(none)' : $proposal['tier'],
				'confidence' => sprintf( '%.2f', $proposal['confidence'] ),
				'top_reason' => '' !== $proposal['tier'] && ! empty( $proposal['reasons'] )
					? (string) $proposal['reasons'][0]
					: ( $proposal['reasons'][0] ?? '' ),
			);
		}

		\WP_CLI::log( '' );

		if ( empty( $rows ) ) {
			\WP_CLI::log( 'Review list: empty — every proposal is at or above the confidence threshold.' );
			return;
		}

		$capped   = array_slice( $rows, 0, self::REVIEW_LIST_DISPLAY_CAP );
		$cap_note = count( $rows ) > count( $capped )
			? sprintf( ' (showing first %d of %d — paginate with --limit/--offset or use --format=csv|json)', count( $capped ), count( $rows ) )
			: '';

		\WP_CLI::log( sprintf( 'Review list (%d venue(s) below the confidence threshold or without a proposal)%s:', count( $rows ), $cap_note ) );
		\WP_CLI\Utils\format_items( $format, $capped, array( 'venue_id', 'venue', 'events', 'proposed', 'confidence', 'top_reason' ) );
	}

	/**
	 * Print the priority-venue reconciliation section.
	 *
	 * Priority (ordering) and tier (classification) are deliberately kept
	 * orthogonal (#801 acceptance criterion): this section never modifies
	 * `_ec_priority_venue` — it shows every priority venue's proposed tier
	 * (scored against the full catalog regardless of --limit/--offset) as
	 * a sanity check that the two signals agree on the obvious cases.
	 *
	 * @param array<int,array> $priority_proposals venue_id => proposal for
	 *                                             every priority venue.
	 */
	private static function print_priority_reconciliation( array $priority_proposals ): void {
		\WP_CLI::log( '' );

		if ( empty( $priority_proposals ) ) {
			\WP_CLI::log( 'Priority-venue reconciliation: no _ec_priority_venue venues exist on this site.' );
			return;
		}

		\WP_CLI::log( 'Priority-venue reconciliation (flags are kept; tier is classification, not ordering):' );

		$rows = array();
		foreach ( $priority_proposals as $proposal ) {
			$rows[] = array(
				'venue'         => $proposal['name'],
				'proposed_tier' => '' === $proposal['tier'] ? '(none)' : $proposal['tier'],
				'confidence'    => sprintf( '%.2f', $proposal['confidence'] ),
				'existing_tier' => '' !== $proposal['existing'] ? $proposal['existing'] : '(none)',
				'priority_flag' => 'kept',
				'in_this_run'   => ! empty( $proposal['in_run'] ) ? 'yes' : 'no (outside --limit/--offset)',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'venue', 'proposed_tier', 'confidence', 'existing_tier', 'priority_flag', 'in_this_run' ) );
	}
}
