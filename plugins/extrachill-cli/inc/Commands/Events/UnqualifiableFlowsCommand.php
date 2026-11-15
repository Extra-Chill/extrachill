<?php
/**
 * `wp extrachill venues unqualifiable-flows` — audit zero-yield flows.
 *
 * Walks every active universal_web_scraper flow, counts its last N runs in
 * datamachine_jobs, and re-qualifies the source_url for any flow whose recent
 * runs all returned zero events. Persists the verdict so a future
 * requalify-pending can automatically resume the flow when a new extractor
 * lands.
 *
 * Thin adapter over VenueRequalifier (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\VenueRequalifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UnqualifiableFlowsCommand {

	/**
	 * Audit zero-yield universal_web_scraper flows and classify them.
	 *
	 * For each active flow whose recent runs all ended in completed_no_items
	 * (or worse), the command runs qualify against the flow's source_url and
	 * classifies the failure under the qualify v2 verdict taxonomy. Persisting
	 * the verdict lets a future requalify-pending automatically promote the
	 * flow when an extractor that fixes its fingerprint pattern ships.
	 *
	 * ## OPTIONS
	 *
	 * [--min-runs=<n>]
	 * : Minimum recent runs required before a flow is considered "stable
	 * zero-yield". Flows with fewer runs are skipped (not enough signal).
	 * ---
	 * default: 14
	 * ---
	 *
	 * [--zero-yield-only]
	 * : Only audit flows where ALL recent runs returned zero events. With
	 * this flag, any non-zero-yield run disqualifies the flow from audit.
	 *
	 * [--auto-pause]
	 * : Pause flows whose new verdict is anything other than
	 * QUALIFIED_STRUCTURED — subject to the per-verdict confirmation rules
	 * in QualifyVerdict::CONFIRMATION_RULES. Flows that haven't built up
	 * enough corroborating verdict history are left active; the verdict row
	 * persists so a future audit can hit confirmation.
	 *
	 * [--force]
	 * : Bypass confirmation entirely and pause on the first failing verdict.
	 * Use this for operator-driven manual pauses where you already know the
	 * venue is dead. Requires --auto-pause.
	 *
	 * [--dry-run]
	 * : Run bounded qualification diagnostics without persisting verdicts,
	 * pausing flows, or applying repair proposals.
	 *
	 * [--apply-repair]
	 * : Apply same-host source_url repair proposals. Requires --yes and cannot
	 * be combined with --dry-run.
	 *
	 * [--yes]
	 * : Confirm source_url repairs requested with --apply-repair.
	 *
	 * [--limit=<n>]
	 * : Maximum flows to audit.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues unqualifiable-flows --dry-run
	 *     wp extrachill venues unqualifiable-flows --zero-yield-only --min-runs=20
	 *     wp extrachill venues unqualifiable-flows --auto-pause
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$min_runs        = max( 1, (int) ( $assoc_args['min-runs'] ?? 14 ) );
		$zero_yield_only = ! empty( $assoc_args['zero-yield-only'] );
		$auto            = ! empty( $assoc_args['auto-pause'] );
		$force           = ! empty( $assoc_args['force'] );
		$dry_run         = ! empty( $assoc_args['dry-run'] );
		$apply_repair    = ! empty( $assoc_args['apply-repair'] );
		$confirmed       = ! empty( $assoc_args['yes'] );
		$limit           = max( 1, (int) ( $assoc_args['limit'] ?? 200 ) );
		$format          = $assoc_args['format'] ?? 'table';

		OwnerSiteAbility::require_service( 'events', 'Events', VenueRequalifier::class );

		$valid = VenueRequalifier::validate_audit_options( $apply_repair, $dry_run, $confirmed );
		if ( is_wp_error( $valid ) ) {
			\WP_CLI::error( $valid->get_error_message() );
			return;
		}

		$requalifier = $this->requalifier();
		$candidates  = $requalifier->zero_yield_candidates( $min_runs, $zero_yield_only, $limit );
		if ( null === $candidates ) {
			\WP_CLI::log( 'No universal_web_scraper flows found.' );
			return;
		}

		if ( empty( $candidates ) ) {
			\WP_CLI::log( 'No zero-yield flows meet the audit criteria.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Auditing %d zero-yield flow(s)%s.', count( $candidates ), $dry_run ? ' (dry run)' : '' ) );

		$progress = null;
		$total    = count( $candidates );
		$results  = $requalifier->audit_zero_yield(
			$candidates,
			array(
				'auto_pause'   => $auto,
				'force'        => $force,
				'dry_run'      => $dry_run,
				'apply_repair' => $apply_repair,
			),
			static function () use ( &$progress, $total ): void {
				if ( null === $progress ) {
					$progress = \WP_CLI\Utils\make_progress_bar( 'Auditing', $total );
				}
				$progress->tick(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements tick() at runtime.)
			}
		);
		if ( is_wp_error( $results ) ) {
			\WP_CLI::error( $results->get_error_message() );
			return;
		}
		if ( null !== $progress ) {
			$progress->finish(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements finish() at runtime.)
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			$format,
			$results,
			array( 'flow_id', 'flow_name', 'new_verdict', 'raw_extracted', 'unique_source', 'processed', 'active_claim', 'reprocess_eligible', 'selected_by_max_items', 'production_eligible', 'complete', 'identifier_source', 'diagnostic_error', 'action' )
		);

		$paused      = array_filter( $results, fn( $r ) => 'paused' === $r['action'] );
		$awaiting    = array_filter( $results, fn( $r ) => 'awaiting_confirmation' === $r['action'] );
		$recommended = array_filter( $results, fn( $r ) => 'recommend_pause' === $r['action'] );
		$unexpected  = array_filter( $results, fn( $r ) => 'unexpected_pass' === $r['action'] );

		if ( ! empty( $paused ) ) {
			\WP_CLI::success( sprintf( 'Paused %d flow(s).', count( $paused ) ) );
		}
		if ( ! empty( $awaiting ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log(
				sprintf(
					'%d flow(s) awaiting confirmation — verdict recorded but pause threshold not yet met. Re-run later, or pass --force to bypass.',
					count( $awaiting )
				)
			);
		}
		if ( ! empty( $recommended ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d flow(s) recommend pausing. Re-run with --auto-pause to apply.', count( $recommended ) ) );
		}
		if ( ! empty( $unexpected ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::warning( sprintf( '%d zero-yield flow(s) now qualify as STRUCTURED — investigate why they were yielding zero.', count( $unexpected ) ) );
		}
	}

	/**
	 * Build the re-qualification service. Overridable for tests.
	 */
	protected function requalifier(): VenueRequalifier {
		return new VenueRequalifier();
	}
}
